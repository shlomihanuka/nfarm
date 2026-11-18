import type { FastifyInstance } from 'fastify';
import { config } from '../config.js';
import { traceCount } from '../debug-store.js';

export async function healthRoutes(app: FastifyInstance) {
  app.get('/health', async (_req, reply) => {
    return reply.send({
      status: 'ok',
      version: '3.0.0',
      model: config.gemini.intentModel,
      mock_mode: config.gemini.useMock,
      debug_traces_in_memory: traceCount(),
    });
  });
}
