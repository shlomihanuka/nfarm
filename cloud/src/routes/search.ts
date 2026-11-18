/**
 * POST /search
 *
 * Receives an IntentResult (or raw q for the provisional path) and returns
 * ranked products + an assistant message.
 *
 * Two modes:
 *  - provisional: true  → keyword search only, ≤300ms, no Gemini call.
 *  - provisional: false → full AI-first path: IntentResult required (caller
 *    fetches it from POST /intent first, or we call intent engine inline).
 *
 * Per-product fields added here:
 *  - price_display = price_html (never use prices.price for weighted products)
 *  - requires_options / options_url: set by PHP after WC query; echoed through here
 *  - substitutes: populated for out-of-stock products when in-stock alternatives exist
 */

import type { FastifyInstance, FastifyRequest } from 'fastify';
import { v4 as uuidv4 } from 'uuid';
import { z } from 'zod';
import { IntentResultSchema, RankedProduct } from '../schemas/index.js';
import { buildSearchPlan, buildProvisionalPlan, rankProducts } from '../search-service.js';
import { runIntentEngine } from '../intent-engine.js';
import { runAssistantEngine } from '../assistant-engine.js';
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
      try {
        const engineResult = await runIntentEngine(q);
        intent = engineResult.intent;
        intentLatency = engineResult.latency_ms;
        inputTokens = engineResult.input_tokens;
        outputTokens = engineResult.output_tokens;
        model = engineResult.model;
      } catch (err) {
        app.log.error({ err, q, tenant_id }, 'Intent engine failed in /search');
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

    // Phase 1 stub: products arrive from WC via the PHP plugin.
    // pgvector retrieval layer is wired in Phase 2 (R5+).
    const products: RankedProduct[] = [];
    const retrievalLatency = Date.now() - retrievalStart;

    const rankingStart = Date.now();
    const ranked = rankProducts(products, intent);
    const rankingLatency = Date.now() - rankingStart;

    // Enrich ranked products with per-product fields
    const enriched = enrichProducts(ranked);

    // Generate assistant message (product-aware)
    let assistant: { message: string; tone: 'answer' | 'clarify' | 'suggest' | 'none'; follow_ups: string[] } = { message: '', tone: 'none', follow_ups: [] };
    let assistantLatency = 0;
    try {
      const ar = await runAssistantEngine(intent, enriched);
      assistant = ar.assistant;
      assistantLatency = ar.latency_ms;
      inputTokens += ar.input_tokens;
      outputTokens += ar.output_tokens;
    } catch (err) {
      app.log.warn({ err }, 'Assistant engine failed in /search — continuing without message');
    }

    const totalLatency = Date.now() - totalStart;

    saveTrace({
      trace_id,
      tenant_id,
      created_at,
      query_original: intent.query_original,
      intent_result: intent,
      search_plan,
      gemini_function_calls: [],
      retrieval_hits: enriched.length,
      latency_ms: {
        intent: intentLatency,
        retrieval: retrievalLatency,
        ranking: rankingLatency,
        total: totalLatency,
      },
      token_cost: { input_tokens: inputTokens, output_tokens: outputTokens, model },
    });

    return reply.send({
      products: enriched,
      total: enriched.length,
      pages: Math.ceil(enriched.length / per_page),
      source: 'cloud_v3',
      assistant,
      follow_ups: assistant.follow_ups,
      search_plan,
      trace_id,
      debug: {
        latency_ms: {
          intent: intentLatency,
          retrieval: retrievalLatency,
          assistant: assistantLatency,
          total: totalLatency,
        },
      },
    });
  });
}

/**
 * Enrich products with R4 fields.
 * Note: requires_options / options_url come from WC (PHP side); here we
 * ensure price_display is always set and generate substitutes for OOS items.
 */
function enrichProducts(products: RankedProduct[]): RankedProduct[] {
  const inStock = products.filter(p => p.in_stock);

  return products.map(p => {
    const enriched: RankedProduct = {
      ...p,
      price_display: p.price_display ?? p.price_html,
    };

    // Generate substitutes for out-of-stock products from the in-stock pool
    if (!p.in_stock && inStock.length > 0 && !p.substitutes?.length) {
      enriched.substitutes = inStock
        .filter(s => s.id !== p.id)
        .slice(0, 3)
        .map(s => ({
          id: s.id,
          name: s.name,
          reason: 'מוצר זמין מאותה קטגוריה חיפוש',
          differences: [],
        }));
    }

    return enriched;
  });
}
