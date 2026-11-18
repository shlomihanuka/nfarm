# Cloud Run Endpoint Signatures — V3

All endpoints require `X-ASC-Tenant: <tenant_id>` header.
All requests and responses are `application/json`.
All responses include `X-Trace-Id` response header for debugger correlation.

---

## GET /health

No auth required. Returns 200 when the service is accepting traffic.

**Response**
```json
{
  "status": "ok",
  "version": "3.0.0",
  "model": "gemini-1.5-flash-002"
}
```

---

## POST /intent

Receives a natural-language query, calls Gemini, returns a structured `IntentResult`.

**Request**
```json
{
  "q": "אני רוצה להכין ארוחה נחמדה למשפחה, משהו לא מסובך, רצוי שיהיה במבצע, ואנחנו חמישה אנשים",
  "tenant_id": "nfarm",
  "context": { }
}
```

`context` is an optional `ContextFrame` for multi-turn sessions.

**Response** — `IntentResult` (see `intent-result.schema.json`)
```json
{
  "query_original": "...",
  "intent_type": "recipe_planning",
  "keywords": ["עוף", "דגים", "ירקות"],
  "filters": { "on_sale": true },
  "context": { "servings": 5, "language": "he" },
  "confidence": 0.92
}
```

**Error**
- `400` — missing `q` or `tenant_id`
- `422` — Gemini returned malformed JSON (retried once; falls back to keyword extraction)
- `503` — Gemini unavailable (caller should fall back to provisional path)

---

## POST /search

Receives an `IntentResult` (or raw `q` for the provisional path), runs retrieval, returns ranked products.

**Request**
```json
{
  "intent": { },
  "tenant_id": "nfarm",
  "per_page": 12,
  "page": 1,
  "provisional": false
}
```

If `provisional: true`, `intent` is ignored — the endpoint runs keyword search only (fast path, no Gemini).
If `intent` is omitted and `provisional` is false, the endpoint calls `POST /intent` internally and returns the full plan.

**Response**
```json
{
  "products": [ ],
  "total": 24,
  "pages": 2,
  "source": "cloud_v3",
  "search_plan": { },
  "trace_id": "tr_abc123"
}
```

`products` items conform to `ranked-product.schema.json`.

**Error**
- `400` — missing `tenant_id`
- `503` — retrieval layer unavailable

---

## GET /debug/:trace_id

Returns the full debug trace for a prior search, including what Gemini understood and which tools it invoked.
Requires `X-ASC-Debug-Key` header (admin-only token, separate from tenant token).

**Response**
```json
{
  "trace_id": "tr_abc123",
  "tenant_id": "nfarm",
  "created_at": "2026-11-18T12:00:00Z",
  "query_original": "...",
  "intent_result": { },
  "search_plan": { },
  "gemini_function_calls": [ ],
  "retrieval_hits": 24,
  "latency_ms": {
    "intent": 820,
    "retrieval": 110,
    "ranking": 45,
    "total": 975
  },
  "token_cost": {
    "input_tokens": 312,
    "output_tokens": 128,
    "model": "gemini-1.5-flash-002"
  }
}
```

---

## pgvector schema

```sql
CREATE TABLE product_embeddings (
  id          BIGSERIAL PRIMARY KEY,
  tenant_id   TEXT NOT NULL,
  product_id  INTEGER NOT NULL,
  content     TEXT NOT NULL,
  embedding   vector(768),
  updated_at  TIMESTAMPTZ DEFAULT now(),
  UNIQUE (tenant_id, product_id)
);

CREATE INDEX ON product_embeddings
  USING ivfflat (embedding vector_cosine_ops)
  WITH (lists = 100);
```

Dimension: 768 (text-embedding-004).
Tenant isolation: row-level `tenant_id` filter on every query.
Index type: IVFFlat (switch to HNSW if p95 > 50ms under load).
