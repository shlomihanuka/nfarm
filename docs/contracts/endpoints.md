# V3 Endpoint Signatures

Two sets of endpoints: **WordPress proxy** (browser-facing) and **Cloud Run** (server-to-server).  
All requests and responses are `application/json`.  
Cloud Run responses include `X-Trace-Id` header for debugger correlation.

---

## WordPress proxy endpoints (browser-facing)

Base URL: `{siteUrl}/wp-json/asc/v1`  
Version: v1

### POST /asc/v1/intent

Purpose: proxy the natural-language query to Cloud Run for Gemini intent parsing.

**Auth**: none (public endpoint, rate-limited by IP)  
**Nonce**: not required (read-only intent parse)

**Request**
```json
{
  "q":          "אני רוצה להכין ארוחה נחמדה למשפחה, משהו לא מסובך, רצוי שיהיה במבצע, ואנחנו חמישה אנשים",
  "session_id": "uuid-v4",
  "context":    null
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `q` | string | YES | 1–500 chars; `sanitize_text_field()` applied |
| `session_id` | string | no | UUID; enables multi-turn context |
| `context` | ContextFrame \| null | no | Multi-turn accumulated state |

**Response 200** — `IntentResult` (see `intent-result.schema.json`)

**Response 400**
```json
{ "code": "asc_missing_q", "message": "Query required" }
```

**Response 503**
```json
{ "code": "asc_cloud_unavailable", "message": "Cloud backend unreachable" }
```

---

### POST /asc/v1/search

Purpose: return product results — either provisional (fast index) or AI-ranked (after intent).

**Auth**: none (public endpoint)

**Request — provisional mode** (Track A)
```json
{
  "q":           "עוף לשבת",
  "provisional": true,
  "per_page":    12,
  "page":        1
}
```

**Request — ranked mode** (Track B, after /intent)
```json
{
  "intent":   { },
  "per_page": 12,
  "page":     1
}
```

| Field | Type | Provisional | Ranked | Notes |
|---|---|---|---|---|
| `q` | string | REQUIRED | — | Raw query |
| `provisional` | boolean | true | — | Defaults false |
| `intent` | IntentResult | — | REQUIRED | Full IntentResult from /intent |
| `per_page` | integer | — | — | 1–48, default 12 |
| `page` | integer | — | — | ≥1, default 1 |

**Response 200**
```json
{
  "products":   [],
  "total":      24,
  "pages":      2,
  "source":     "cloud_ranked",
  "intent_id":  "...",
  "latency_ms": 234
}
```

`source` values: `cloud_ranked | cloud_provisional | local | disabled`  
`intent_id` is null for provisional results.

**Response 400**
```json
{ "code": "asc_invalid_request", "message": "Either q (provisional) or intent (ranked) is required" }
```

---

### GET /asc/v1/health

**Response 200**
```json
{
  "status":  "ok",
  "cloud":   "connected|unavailable|not_configured",
  "version": "0.2.0"
}
```

---

## Cloud Run endpoints (server-to-server)

Base URL: `cloud_api_url` plugin setting  
Auth headers on every request: `X-ASC-Project`, `X-ASC-Tenant`, `X-ASC-Version`

### GET /health

No auth required. Returns 200 when the service is accepting traffic.

**Response**
```json
{
  "status":   "ok",
  "version":  "3.0.0",
  "gemini":   "available|degraded|unavailable",
  "pgvector": "connected|unavailable",
  "model":    "gemini-1.5-flash-002"
}
```

---

### POST /intent

Receives a natural-language query, calls Gemini, returns a structured `IntentResult`.

**Request**
```json
{
  "q":         "אני רוצה להכין ארוחה נחמדה למשפחה, משהו לא מסובך, רצוי שיהיה במבצע, ואנחנו חמישה אנשים",
  "tenant_id": "nfarm",
  "context":   null
}
```

`context` is an optional `ContextFrame` for multi-turn sessions.

**Response 200** — `IntentResult` (see `intent-result.schema.json`)
```json
{
  "query_original":   "...",
  "query_normalized": "...",
  "intent_type":      "recipe_planning",
  "keywords":         ["עוף", "דגים", "ירקות"],
  "filters":          { "on_sale": true },
  "context":          { "servings": 5, "language": "he" },
  "confidence":       0.92,
  "cache_hit":        false,
  "intent_id":        "sha256-..."
}
```

**Errors**
- `400` — missing `q` or `tenant_id`
- `422` — Gemini returned malformed JSON (retried once; falls back to keyword extraction)
- `503` — Gemini unavailable (caller falls back to provisional path)

---

### POST /search

Receives an `IntentResult` (or raw `q` for the provisional path), runs retrieval, returns ranked products.

**Request**
```json
{
  "tenant_id": "nfarm",
  "intent":    { },
  "per_page":  12,
  "page":      1,
  "provisional": false
}
```

If `provisional: true`, `intent` is ignored — keyword search only (fast path, no Gemini).  
If `intent` is omitted and `provisional: false`, the endpoint calls `POST /intent` internally.

**Response 200**
```json
{
  "products":   [],
  "total":      24,
  "pages":      2,
  "source":     "cloud_ranked",
  "search_plan": { },
  "intent_id":  "sha256-...",
  "latency_ms": 450,
  "trace_id":   "tr_abc123"
}
```

`products` items conform to `ranked-product.schema.json`.

**Errors**
- `400` — missing `tenant_id`
- `503` — retrieval layer unavailable

---

### GET /debug/:trace_id

Returns the full debug trace for a prior search, including Gemini's tool calls and latency breakdown.  
**Auth**: requires `X-ASC-Debug-Key` header (admin-only token).

**Response 200**
```json
{
  "trace_id":      "tr_abc123",
  "tenant_id":     "nfarm",
  "created_at":    "2026-11-18T12:00:00Z",
  "query_original": "...",
  "intent_result": { },
  "search_plan":   { },
  "gemini_function_calls": [],
  "retrieval_hits": 24,
  "latency_ms": {
    "intent":    820,
    "retrieval": 110,
    "ranking":    45,
    "total":     975
  },
  "token_cost": {
    "input_tokens":  312,
    "output_tokens": 128,
    "model":         "gemini-1.5-flash-002"
  }
}
```

---

## pgvector schema

```sql
CREATE TABLE asc_product_embeddings (
  id           BIGSERIAL    PRIMARY KEY,
  tenant_id    TEXT         NOT NULL,
  product_id   INTEGER      NOT NULL,
  content      TEXT         NOT NULL,
  embedding    vector(768)  NOT NULL,
  keywords_he  TSVECTOR,
  keywords_en  TSVECTOR,
  updated_at   TIMESTAMPTZ  NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, product_id)
);

CREATE INDEX ON asc_product_embeddings
  USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100);
CREATE INDEX ON asc_product_embeddings (tenant_id);
CREATE INDEX ON asc_product_embeddings USING GIN (keywords_he);
CREATE INDEX ON asc_product_embeddings USING GIN (keywords_en);
```

Dimension: 768 (`text-embedding-004`).  
Tenant isolation: `tenant_id` row-level filter on every query — cross-tenant leakage impossible by schema.  
Index type: IVFFlat (switch to HNSW if p95 retrieval > 50ms under load).  
**Hebrew lexical layer (`keywords_he`) is mandatory** — pgvector ANN alone is not keyword search, and Hebrew morphological matching requires a tsvector index.  
Hebrew tokenizer: `pg_trgm` trigram similarity where a full morphological dictionary is not installed. `[SPEC PENDING: exact Hebrew dictionary]`
