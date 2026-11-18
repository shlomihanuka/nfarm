import Fastify from 'fastify';
import { config } from './config.js';
import { healthRoutes } from './routes/health.js';
import { intentRoutes } from './routes/intent.js';
import { searchRoutes } from './routes/search.js';
import { debugRoutes } from './routes/debug.js';

const app = Fastify({
  logger: {
    level: config.logLevel,
    ...(config.env === 'development' ? { transport: { target: 'pino-pretty' } } : {}),
  },
});

// Middleware: inject tenant validation
app.addHook('preHandler', async (req, reply) => {
  // /health is public
  if (req.url === '/health') return;
  const tenant = req.headers['x-asc-tenant'] as string;
  if (!tenant) {
    return reply.status(400).send({ error: 'X-ASC-Tenant header required' });
  }
});

await app.register(healthRoutes);
await app.register(intentRoutes);
await app.register(searchRoutes);
await app.register(debugRoutes);

try {
  await app.listen({ port: config.port, host: '0.0.0.0' });
  app.log.info(`ASC Cloud v3 running on port ${config.port}`);
} catch (err) {
  app.log.error(err);
  process.exit(1);
}
