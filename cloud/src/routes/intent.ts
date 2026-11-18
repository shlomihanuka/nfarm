/**
 * POST /intent
 *
 * Receives a raw query + tenant_id, calls the Gemini intent engine,
 * returns an IntentResult with debug metadata.
 *
 * Server-side only — never exposed to the customer browser directly.
 */

import type { FastifyInstance, FastifyRequest } from 'fastify';
import { v4 as uuidv4 } from 'uuid';
import { z } from 'zod';
import { runIntentEngine } from '../intent-engine.js';
import { buildSearchPlan } from '../search-service.js';
import { saveTrace } from '../debug-store.js';
import { ContextFrameSchema } from '../schemas/index.js';

const IntentRequestSchema = z.object({
  q: z.string().min(1).max(500),
  tenant_id: z.string().min(1).max(100),
  per_page: z.number().int().min(1).max(48).default(12),
  page: z.number().int().min(1).default(1),
  context: ContextFrameSchema.optional(),
});

export async function intentRoutes(app: FastifyInstance) {
  app.post('/intent', async (req: FastifyRequest, reply) => {
    const parseResult = IntentRequestSchema.safeParse(req.body);
    if (!parseResult.success) {
      return reply.status(400).send({
        error: 'invalid_request',
        details: parseResult.error.flatten(),
      });
    }

    const { q, tenant_id, per_page, page, context } = parseResult.data;
    const trace_id = uuidv4().replace(/-/g, '').slice(0, 16);
    const created_at = new Date().toISOString();

    reply.header('X-Trace-Id', trace_id);

    let engineResult;
    try {
      engineResult = await runIntentEngine(q, context);
    } catch (err) {
      app.log.error({ err, q, tenant_id }, 'Intent engine failed');
      return reply.status(503).send({
        error: 'intent_engine_unavailable',
        message: 'Gemini is temporarily unavailable. Use provisional search path.',
        trace_id,
      });
    }

    const { intent, latency_ms, input_tokens, output_tokens, model } = engineResult;
    const search_plan = buildSearchPlan(intent, per_page, page);

    saveTrace({
      trace_id,
      tenant_id,
      created_at,
      query_original: q,
      intent_result: intent,
      search_plan,
      gemini_function_calls: [],
      retrieval_hits: 0,
      latency_ms: { intent: latency_ms, retrieval: 0, ranking: 0, total: latency_ms },
      token_cost: { input_tokens, output_tokens, model },
    });

    return reply.send({
      intent,
      search_plan,
      trace_id,
      debug: {
        latency_ms,
        input_tokens,
        output_tokens,
        model,
      },
    });
  });
}
