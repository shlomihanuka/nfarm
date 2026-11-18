/**
 * POST /search
 *
 * Receives an IntentResult (or raw q for the provisional path) and returns
 * ranked products.
 *
 * Two modes:
 *  - provisional: true  → keyword search only, ≤300ms, no Gemini call.
 *  - provisional: false → full AI-first path: IntentResult required (caller
 *    fetches it from POST /intent first, or we call intent engine inline).
 */

import type { FastifyInstance, FastifyRequest } from 'fastify';
import { v4 as uuidv4 } from 'uuid';
import { z } from 'zod';
import { IntentResultSchema, RankedProduct } from '../schemas/index.js';
import { buildSearchPlan, buildProvisionalPlan, rankProducts } from '../search-service.js';
import { runIntentEngine } from '../intent-engine.js';
import { saveTrace } from '../debug-store.js';

const SearchRequestSchema = z.object({
  tenant_id: z.string().min(1).max(100),
  q: z.string().min(1).max(500).optional(),
  intent: IntentResultSchema.optional(),
  per_page: z.number().int().min(1).max(48).default(12),
  page: z.number().int().min(1).default(1),
  provisional: z.boolean().default(false),
});

export async function searchRoutes(app: FastifyInstance) {
  app.post('/search', async (req: FastifyRequest, reply) => {
    const parseResult = SearchRequestSchema.safeParse(req.body);
    if (!parseResult.success) {
      return reply.status(400).send({
        error: 'invalid_request',
        details: parseResult.error.flatten(),
      });
    }

    const { tenant_id, q, intent: incomingIntent, per_page, page, provisional } = parseResult.data;
    const trace_id = uuidv4().replace(/-/g, '').slice(0, 16);
    const created_at = new Date().toISOString();
    const totalStart = Date.now();

    reply.header('X-Trace-Id', trace_id);

    // ── Provisional fast path ───────────────────────────────────────────────
    if (provisional) {
      if (!q) {
        return reply.status(400).send({ error: 'q is required for provisional search' });
      }
      const plan = buildProvisionalPlan(q, per_page, page);
      return reply.send({
        products: [],
        total: 0,
        pages: 0,
        source: 'cloud_v3_provisional',
        search_plan: plan,
        trace_id,
        note: 'Provisional path: products will be fetched by the WordPress plugin using wc_params.',
      });
    }

    // ── Full AI-first path ──────────────────────────────────────────────────
    let intent = incomingIntent;
    let intentLatency = 0;
    let inputTokens = 0;
    let outputTokens = 0;
    let model = '';

    if (!intent) {
      if (!q) {
        return reply.status(400).send({ error: 'Either intent or q is required' });
      }
      const intentStart = Date.now();
      try {
        const engineResult = await runIntentEngine(q);
        intent = engineResult.intent;
        intentLatency = engineResult.latency_ms;
        inputTokens = engineResult.input_tokens;
        outputTokens = engineResult.output_tokens;
        model = engineResult.model;
      } catch (err) {
        app.log.error({ err, q, tenant_id }, 'Intent engine failed in /search');
        // Fall back to provisional rather than returning an error
        const plan = buildProvisionalPlan(q, per_page, page);
        return reply.send({
          products: [],
          total: 0,
          pages: 0,
          source: 'cloud_v3_provisional_fallback',
          search_plan: plan,
          trace_id,
        });
      }
    }

    const retrievalStart = Date.now();
    const search_plan = buildSearchPlan(intent, per_page, page);

    // Phase 1 stub: products come from WC via the plugin using wc_params.
    // The pgvector retrieval layer is wired in Phase 2.
    const products: RankedProduct[] = [];
    const retrievalLatency = Date.now() - retrievalStart;

    const rankingStart = Date.now();
    const ranked = rankProducts(products, intent);
    const rankingLatency = Date.now() - rankingStart;

    const totalLatency = Date.now() - totalStart;

    saveTrace({
      trace_id,
      tenant_id,
      created_at,
      query_original: intent.query_original,
      intent_result: intent,
      search_plan,
      gemini_function_calls: [],
      retrieval_hits: ranked.length,
      latency_ms: {
        intent: intentLatency,
        retrieval: retrievalLatency,
        ranking: rankingLatency,
        total: totalLatency,
      },
      token_cost: { input_tokens: inputTokens, output_tokens: outputTokens, model },
    });

    return reply.send({
      products: ranked,
      total: ranked.length,
      pages: Math.ceil(ranked.length / per_page),
      source: 'cloud_v3',
      search_plan,
      trace_id,
      debug: {
        latency_ms: { intent: intentLatency, retrieval: retrievalLatency, total: totalLatency },
      },
    });
  });
}
