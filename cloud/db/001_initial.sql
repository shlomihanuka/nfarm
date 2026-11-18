-- ASC Cloud — initial schema
-- Run against Cloud SQL Postgres 15 (pgvector extension enabled via instance flag)

CREATE EXTENSION IF NOT EXISTS vector;

-- Product embeddings (tenant-isolated, 768-dim text-embedding-004)
CREATE TABLE IF NOT EXISTS product_embeddings (
  id          BIGSERIAL PRIMARY KEY,
  tenant_id   TEXT NOT NULL,
  product_id  INTEGER NOT NULL,
  content     TEXT NOT NULL,          -- denormalised product text used to build the embedding
  embedding   vector(768),
  updated_at  TIMESTAMPTZ DEFAULT now(),
  UNIQUE (tenant_id, product_id)
);

-- IVFFlat index for cosine similarity search
-- lists=100 is appropriate for catalogs up to ~100k products per tenant.
-- Raise to 200+ and switch to HNSW if p95 retrieval > 50ms under load.
CREATE INDEX IF NOT EXISTS product_embeddings_vec_idx
  ON product_embeddings
  USING ivfflat (embedding vector_cosine_ops)
  WITH (lists = 100);

-- Query helper: returns top-k most similar products for a given embedding
-- Called from the search service with parameterised $1 (tenant_id), $2 (query vector), $3 (limit)
CREATE OR REPLACE FUNCTION search_products(
  p_tenant_id TEXT,
  p_query     vector(768),
  p_limit     INT DEFAULT 20
)
RETURNS TABLE (product_id INT, similarity FLOAT) AS $$
  SELECT product_id, 1 - (embedding <=> p_query) AS similarity
  FROM   product_embeddings
  WHERE  tenant_id = p_tenant_id
  ORDER  BY embedding <=> p_query
  LIMIT  p_limit;
$$ LANGUAGE sql STABLE;

-- Debug traces (Phase 2 persistence — Phase 1 uses in-memory store)
CREATE TABLE IF NOT EXISTS debug_traces (
  trace_id       TEXT PRIMARY KEY,
  tenant_id      TEXT NOT NULL,
  created_at     TIMESTAMPTZ DEFAULT now(),
  query_original TEXT NOT NULL,
  intent_result  JSONB,
  search_plan    JSONB,
  fn_calls       JSONB DEFAULT '[]',
  retrieval_hits INTEGER DEFAULT 0,
  latency_ms     JSONB,
  token_cost     JSONB
);

CREATE INDEX IF NOT EXISTS debug_traces_tenant_idx ON debug_traces (tenant_id, created_at DESC);
