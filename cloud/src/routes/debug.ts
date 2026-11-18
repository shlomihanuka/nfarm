/**
 * GET /debug/:trace_id — V3 §14
 *
 * Returns the full debug trace for a prior search: what Gemini understood,
 * which tools it invoked, latency breakdown, and token costs.
 *
 * Admin-only: requires X-ASC-Debug-Key header.
 * This is what the CEO asked for: "stop guessing what Gemini did."
 */

import type { FastifyInstance, FastifyRequest } from 'fastify';
import { config } from '../config.js';
import { getTrace } from '../debug-store.js';

export async function debugRoutes(app: FastifyInstance) {
  app.get(
    '/debug/:trace_id',
    async (req: FastifyRequest<{ Params: { trace_id: string } }>, reply) => {
      const debugKey = (req.headers['x-asc-debug-key'] as string) ?? '';
      if (!config.debug.key || debugKey !== config.debug.key) {
        return reply.status(403).send({ error: 'forbidden' });
      }

      const trace = getTrace(req.params.trace_id);
      if (!trace) {
        return reply.status(404).send({ error: 'trace_not_found' });
      }

      return reply.send(trace);
    },
  );
}
