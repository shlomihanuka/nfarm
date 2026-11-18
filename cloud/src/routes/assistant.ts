/**
 * POST /assistant
 *
 * Lightweight endpoint: runs the intent engine + generates a Hebrew assistant
 * message. Products are optional — if provided, the message can answer
 * product-specific questions from catalog attributes.
 *
 * Designed to be called in PARALLEL with POST /search so the assistant message
 * arrives before the product cards. See docs/contracts/endpoints.md §transport.
 *
 * Rules enforced here:
 *  - Every AI-generated field carries source, confidence, updated_at, approval_state
 *    (delegated to individual product fields — assistant.message is not a product field)
 *  - Never calls Gemini from the browser — server-side only.
 *  - Model IDs are config, not code.
 */

import type { FastifyInstance, FastifyRequest } from 'fastify';
import { v4 as uuidv4 } from 'uuid';
import { z } from 'zod';
import { ContextFrameSchema, RankedProductSchema } from '../schemas/index.js';
import { runIntentEngine } from '../intent-engine.js';
import { runAssistantEngine } from '../assistant-engine.js';
import { saveTrace } from '../debug-store.js';

const AssistantRequestSchema = z.object({
  q: z.string().min(1).max(500),
  tenant_id: z.string().min(1).max(100),
  context: ContextFrameSchema.optional(),
  // Optional: products already retrieved by the caller (e.g. PHP after WC search)
  products: z.array(RankedProductSchema.passthrough()).optional(),
});

export async function assistantRoutes(app: FastifyInstance) {
  app.post('/assistant', async (req: FastifyRequest, reply) => {
    const parseResult = AssistantRequestSchema.safeParse(req.body);
    if (!parseResult.success) {
      return reply.status(400).send({
        error: 'invalid_request',
        details: parseResult.error.flatten(),
      });
    }

    const { q, tenant_id, context, products = [] } = parseResult.data;
    const trace_id = uuidv4().replace(/-/g, '').slice(0, 16);
    const created_at = new Date().toISOString();
    const totalStart = Date.now();

    reply.header('X-Trace-Id', trace_id);

    // Phase 1: intent
    let engineResult;
    try {
      engineResult = await runIntentEngine(q, context);
    } catch (err) {
      app.log.error({ err, q, tenant_id }, 'Intent engine failed in /assistant');
      return reply.status(503).send({
        error: 'intent_engine_unavailable',
        trace_id,
      });
    }

    const { intent, latency_ms: intentLatency, input_tokens, output_tokens, model } = engineResult;

    // Phase 2: assistant message
    let assistantResult;
    try {
      assistantResult = await runAssistantEngine(intent, products as any);
    } catch (err) {
      app.log.warn({ err, q }, 'Assistant engine failed — returning silent response');
      assistantResult = {
        assistant: { message: '', tone: 'none' as const, follow_ups: [] },
        latency_ms: 0,
        input_tokens: 0,
        output_tokens: 0,
        model: 'fallback',
      };
    }

    const totalLatency = Date.now() - totalStart;

    saveTrace({
      trace_id,
      tenant_id,
      created_at,
      query_original: q,
      intent_result: intent,
      search_plan: null,
      gemini_function_calls: [],
      retrieval_hits: 0,
      latency_ms: { intent: intentLatency, retrieval: 0, ranking: 0, total: totalLatency },
      token_cost: {
        input_tokens: input_tokens + assistantResult.input_tokens,
        output_tokens: output_tokens + assistantResult.output_tokens,
        model,
      },
    });

    return reply.send({
      assistant: assistantResult.assistant,
      intent,
      trace_id,
      debug: {
        latency_ms: {
          intent: intentLatency,
          assistant: assistantResult.latency_ms,
          total: totalLatency,
        },
        input_tokens: input_tokens + assistantResult.input_tokens,
        output_tokens: output_tokens + assistantResult.output_tokens,
        model,
      },
    });
  });
}
