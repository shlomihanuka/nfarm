# Cloud Run Endpoint Signatures — V3 (R5)

All endpoints require `X-ASC-Tenant: <tenant_id>` header.
All requests and responses are `application/json`.
All responses include `X-Trace-Id` response header for debugger correlation.

---

## Transport — two-phase parallel fetch ("speak while it works")

The drawer fires TWO requests in parallel on every search:

```
t=0    POST /wp-json/asc/v1/assistant?q=...  → AssistantResponse  (~200–400ms)
t=0    GET  /wp-json/asc/v1/search?q=...     → products + assistant (~600–1200ms)
```

**Phase 1** (`/assistant`) returns a short Hebrew sentence based on intent only —
no product data needed. The drawer renders this in the `asc-assistant` bar
immediately, while products are still loading.

**Phase 2** (`/search`) returns ranked products. The `assistant` field in the
search response may be richer (product-aware) than phase 1; the drawer replaces
the phase-1 message with the phase-2 message when products land.

This gives the user a true AI message in ~200ms and products in ~1s — no
skeleton / spinner silence.

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

## POST /assistant

Fast endpoint: intent engine + assistant message generation only.
No product retrieval. Designed to resolve before POST /search.

**Request**
```json
{
  "q": "מה ההבדל בין פרמז'ן לגרנה פדנו?",
  "tenant_id": "nfarm",
  "context": { },
  "products": [ ]
}
```

`products` is optional. When provided (PHP can pass WC results here after
local search), the assistant can answer product-specific questions.

**Response** — `AssistantResponse` (see `assistant-response.schema.json`)
```json
{
  "assistant": {
    "message": "לא מצאתי מידע השוואתי בנתוני הקטלוג. ניתן לבדוק ישירות בדפי המוצרים.",
    "tone": "answer",
    "follow_ups": ["הצג פרמז'ן", "הצג גרנה פדנו"]
  },
  "intent": {
    "query_original": "מה ההבדל בין פרמז'ן לגרנה פדנו?",
    "intent_type": "question_answering",
    "keywords": ["פרמז'ן", "גרנה פדנו"],
    "filters": {},
    "context": { "language": "he" },
    "confidence": 0.88
  },
  "trace_id": "abc123",
  "debug": {
    "latency_ms": { "intent": 85, "assistant": 42, "total": 130 },
    "input_tokens": 0,
    "output_tokens": 0,
    "model": "mock:gemini-1.5-flash-002"
  }
}
```

**Error**
- `400` — missing `q` or `tenant_id`
- `503` — Gemini unavailable

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

Receives an `IntentResult` (or raw `q` for the provisional path), runs retrieval,
returns ranked products + an `assistant` message (product-aware).

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

If `provisional: true`, `intent` is ignored — keyword search only (fast path, no Gemini).
If `intent` is omitted and `provisional` is false, the endpoint calls `POST /intent` internally.

**Response**
```json
{
  "products": [ ],
  "total": 24,
  "pages": 2,
  "source": "cloud_v3",
  "groups": [
    {
      "label": "פרמזן מיושן",
      "rationale": "נמצא לפי שמות שהמוכר עצמו ציין — רגיאנו, פרמזן 24 חודשים.",
      "products": [ ],
      "has_promotion": false
    },
    {
      "label": "גבינות מיושנות דומות",
      "rationale": "אפשרויות דומות שכדאי לשקול.",
      "products": [ ],
      "has_promotion": true
    }
  ],
  "guiding_sentence": "פרמזן לעומת גרנה פדנו — השווה והוסף לסל ישירות.",
  "assistant": {
    "message": "",
    "tone": "none",
    "follow_ups": []
  },
  "follow_ups": [],
  "search_plan": { },
  "trace_id": "tr_abc123"
}
```

`products` is a flat list kept for backward compatibility and the provisional path.
`groups` is the R5 editorial grouping — prefer it when present for rendering.

**R5 fields:**
- `groups[].label` — short group title (e.g., "במבצע עכשיו", "אזל מהמלאי")
- `groups[].rationale` — one Hebrew sentence explaining this cluster (connective editorial text)
- `groups[].products` — full `RankedProduct` objects in this group
- `groups[].has_promotion` — true when the group contains genuine on-sale products
- `guiding_sentence` — one line advancing the customer's decision; empty string when nothing useful

**R5 per-product additions** (also added to `ranked-product.schema.json`):
- `on_sale` — genuine sale flag from WC catalogue (never inferred)
- `searchkeys` — pa_searchkey values: merchant-authored synonyms, authoritative over model inference

`products` items conform to `ranked-product.schema.json`.
Per-product fields: `price_display`, `requires_options`, `options_url`, `substitutes`, `on_sale`, `searchkeys`.

**Price display rule**: always use `price_display` (= `price_html`). `prices.price` from the
Store API is price-per-gram for weighted products and will show ₪0.19 for a ₪37.80 cheese.

**Options rule**: `requires_options === true` when `add_to_cart.text === "Select options"` on
the Store API. This is the ONLY reliable discriminator — `type`, `has_options` and `variations`
all say "simple" for weighted-option products. Render אפשרויות button + modal when true.

**Error**
- `400` — missing `tenant_id`
- `503` — retrieval layer unavailable

---

## GET /debug/:trace_id

Returns the full debug trace for a prior search.
Requires `X-ASC-Debug-Key` header (admin-only token).

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
