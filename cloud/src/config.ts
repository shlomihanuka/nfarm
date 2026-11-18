/**
 * All configuration is read from environment variables.
 * Model IDs are configuration, not code — changing a model never requires a code change.
 */

function required(key: string): string {
  const val = process.env[key];
  if (!val) throw new Error(`Required env var ${key} is not set`);
  return val;
}

function optional(key: string, fallback: string): string {
  return process.env[key] ?? fallback;
}

export const config = {
  port: parseInt(optional('PORT', '8080'), 10),
  env: optional('NODE_ENV', 'development'),
  logLevel: optional('LOG_LEVEL', 'info'),

  gcp: {
    projectId: optional('GCP_PROJECT_ID', ''),
    region: optional('GCP_REGION', 'us-central1'),
  },

  gemini: {
    intentModel: optional('GEMINI_INTENT_MODEL', 'gemini-1.5-flash-002'),
    rankingModel: optional('GEMINI_RANKING_MODEL', 'gemini-1.5-flash-002'),
    embeddingModel: optional('GEMINI_EMBEDDING_MODEL', 'text-embedding-004'),
    useMock: optional('USE_MOCK_GEMINI', 'false') === 'true',
  },

  db: {
    url: optional('DATABASE_URL', ''),
  },

  debug: {
    key: optional('ASC_DEBUG_KEY', ''),
  },
} as const;
