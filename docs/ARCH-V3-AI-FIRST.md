# V3 Architecture — AI-First Flow, API Contract, Function-Calling Surface, Latency Budget

**Author**: Lead-SmartSearchArchitect  
**Date**: 2026-11-18  
**Status**: PUBLISHED — other lanes build against this today  
**Branch**: `feat/v3-planning`

---

## 0. Source authority and gaps

This document is derived from:
- Task brief (quotes V3 §1 flow verbatim, §18 target query)
- PM plan `docs/PLAN-V3.md` (references §4.3, §12, §18)
- Existing plugin v0.1.1 (`includes/class-asc-*.php`, `build/index.js`)

**`docs/SPEC-V3-FULL.md` does not exist on disk.** Items that cannot be resolved from the above sources are marked `[SPEC PENDING]`. Treat those as provisional. When the spec lands, reconcile against this document and update it — do not silently deviate.

Intentional deviations from spec are listed in §10.

---

## 1. The correction that governs everything

Gemini is the **PRIMARY** search engine. Keywords and filters are **derived from** Gemini's understanding of the user's intent. They are never the reverse.

```
User Input
    │
    ▼
Gemini Understanding
    │
    ▼
Search Plan (keywords + filters extracted)
    │
    ▼
Catalog Retrieval (pgvector + WC catalog)
    │
    ▼
AI Ranking
    │
    ▼
Visual Results
    │
    ▼
Shopping Actions
```

The index (pgvector + WC local search) is a fast retrieval mechanism. Provisional index results may render while Gemini thinks — they do not replace intent processing, they bridge the latency gap.

The target query is not "cheese". It is *"אני רוצה להכין ארוחה נחמדה למשפחה, משהו לא מסובך, רצוי שיהיה במבצע, ואנחנו חמישה אנשים"* (V3 §18). The architecture must handle that query as a first-class case.

---

## 2. AI-First Request Flow

### 2.1 Two-track model

```
keystroke
    │
    │  150ms debounce
    ▼
┌───────────────────────────────────────────────────────────────┐
│ BROWSER CLIENT                                                 │
│                                                                │
│  TRACK A ─────────────────────────────────────────────────►   │
│  POST /wp-json/asc/v1/search                                   │
│  { q, provisional: true, per_page, page }                     │
│                                                                │
│  WP proxy → Cloud Run POST /search?provisional=true            │
│           OR → local_search() if cloud down                   │
│                                                                │
│  Renders provisional cards ≤300ms from debounce fire          │
│                                                                │
│  TRACK B ─────────────────────────────────────────────────►   │
│  POST /wp-json/asc/v1/intent                                   │
│  { q, session_id, context? }                                  │
│                                                                │
│  WP proxy → Cloud Run POST /intent → Gemini                   │
│           ≤2000ms P95 → IntentResult                          │
│                                                                │
│  POST /wp-json/asc/v1/search                                   │
│  { intent: IntentResult, per_page, page }                     │
│                                                                │
│  WP proxy → Cloud Run POST /search (ranked)                   │
│           ≤1000ms additional → RankedProduct[]                │
│                                                                │
│  AI-ranked results REPLACE provisional ≤3000ms total          │
└───────────────────────────────────────────────────────────────┘
```

Track A and Track B fire **concurrently** after the debounce fires. Track B depends on Track A's intent result before its `/search` call, but both fire their initial requests together.

### 2.2 Client-side state machine

```
IDLE
  → PROVISIONAL_PENDING    (Track A fired, spinner shown)
  → PROVISIONAL_SHOWN      (Track A responded, skeleton → cards)
  → GEMINI_PENDING         (Track B /intent fired, "AI refinement…" badge)
  → RANKING_PENDING        (Track B /search fired with IntentResult)
  → RANKED_SHOWN           (Track B responded, 200ms cross-fade replaces provisional)
  → TIMEOUT                (Track B exceeded 4000ms, provisional is permanent for query)
```

Transition rules:
- `TIMEOUT` is set at 4000ms client-side; the P95 target remains ≤3000ms (§5)
- A new keystroke resets to `IDLE` and cancels any in-flight requests (AbortController)
- `PROVISIONAL_SHOWN → RANKED_SHOWN` is the happy path; `TIMEOUT` is acceptable degradation

### 2.3 Provisional rendering contract

What provisional cards MUST show (renders ≤300ms):
- Product name
- Product image
- Price (price_html, formatted)
- In-stock status

What may arrive with the ranked set:
- `ai_rank`, `ai_reason` (debug-only)
- `inferred_attrs` with `review_required` caveat
- `suggest_qty`

Skeleton cards (grey placeholders) display for the interval between Track A fire and Track A response. No flash-of-no-content.

### 2.4 Cache

| Layer | Key | TTL | Location |
|---|---|---|---|
| Intent | `SHA256(normalize(q) + tenant_id)` | 5 min | Cloud Run in-memory |
| Provisional results | `SHA256(q + tenant_id)` | 60s | Cloud Run in-memory |
| Ranked results | `SHA256(intent_id + tenant_id)` | 60s | Cloud Run in-memory |

Single-word queries (no whitespace, `q.trim().split(/\s+/).length === 1`) skip Gemini. Track B fires `/search` directly with `{q, provisional: false}` — fast keyword search, no intent parsing.

### 2.5 Fallback ladder

| Failure | Behaviour |
|---|---|
| Cloud Run `/intent` unreachable | Track B silent-abandoned; provisional stays permanently |
| Cloud Run `/search` unreachable | WP returns `WP_Error`; `local_search()` called instead |
| Cloud Run entirely down | `local_search()` for both tracks |
| WooCommerce unavailable | Native WordPress `WP_Query s=` |
| Plugin `enabled: false` | Returns `{products:[], total:0, source:"disabled"}`; drawer shows nothing |
| `cloud_api_url` empty | Track B does not fire; Track A uses `local_search()` |

One switch (`enabled: false`) reverts to native WordPress search end-to-end. No partial states.

---

## 3. API Contract

### 3.1 WordPress plugin endpoints (browser-facing)

Base: `{siteUrl}/wp-json/asc/v1`  
Version: v1 (this document defines v1; breaking changes bump to v2)  
Server returns **structured data only** — never free text, never product HTML.

---

#### `POST /asc/v1/intent`

Purpose: proxy the user's natural-language query to Cloud Run for Gemini intent parsing.

**Request body** (`application/json`):
```json
{
  "q":          "אני רוצה להכין ארוחה נחמדה למשפחה",
  "session_id": "uuid-v4",
  "context":    null
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `q` | string | YES | 1–500 chars; sanitized server-side |
| `session_id` | string | no | UUID; used for multi-turn; ignored if no context |
| `context` | ContextFrame \| null | no | Multi-turn accumulated state |

**Response 200**: `IntentResult` (§4.1)

**Response 400**:
```json
{ "code": "asc_missing_q", "message": "Query required" }
```

**Response 503**:
```json
{ "code": "asc_cloud_unavailable", "message": "Cloud backend unreachable" }
```

---

#### `POST /asc/v1/search`

Purpose: return product results, either provisional (fast index) or ranked (AI-ordered).

**Request body — provisional mode**:
```json
{
  "q":           "עוף לשבת",
  "provisional": true,
  "per_page":    12,
  "page":        1
}
```

**Request body — ranked mode**:
```json
{
  "intent":   { ... },
  "per_page": 12,
  "page":     1
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `q` | string | YES in provisional mode | Raw query |
| `provisional` | boolean | no | Defaults `false` |
| `intent` | IntentResult | YES in ranked mode | Full IntentResult from `/intent` response |
| `per_page` | integer | no | 1–48, default 12 |
| `page` | integer | no | ≥1, default 1 |

**Response 200**: `SearchResponse` (§4.3)

**Response 400**:
```json
{ "code": "asc_invalid_request", "message": "Either q or intent is required" }
```

---

#### `GET /asc/v1/health`

**Response 200**:
```json
{
  "status":  "ok",
  "cloud":   "connected|unavailable|not_configured",
  "version": "0.2.0"
}
```

---

### 3.2 Cloud Run endpoints (server-to-server)

Base: value of plugin setting `cloud_api_url`  
Auth headers on every request: `X-ASC-Project`, `X-ASC-Tenant`, `X-ASC-Version`

---

#### `POST /intent`

**Request**:
```json
{
  "q":         "...",
  "tenant_id": "...",
  "context":   null
}
```

**Response 200**: `IntentResult`

---

#### `POST /search`

**Request (provisional)**:
```json
{
  "tenant_id":   "...",
  "q":           "...",
  "provisional": true,
  "per_page":    12,
  "page":        1
}
```

**Request (ranked)**:
```json
{
  "tenant_id": "...",
  "intent":    { ... },
  "per_page":  12,
  "page":      1
}
```

**Response 200**: `SearchResponse`

---

#### `GET /health`

**Response 200**:
```json
{
  "status":   "ok",
  "gemini":   "available|degraded|unavailable",
  "pgvector": "connected|unavailable"
}
```

---

## 4. Data Types

Schema files are in `docs/contracts/`. This section is the normative description.

### 4.1 IntentResult

```json
{
  "query_original":  "אני רוצה להכין ארוחה נחמדה למשפחה",
  "query_normalized": "ארוחה משפחתית",
  "intent_type":     "recipe_query",
  "keywords":        ["עוף", "חלות", "יין"],
  "filters": {
    "in_stock":    true,
    "on_sale":     false,
    "category_ids": [],
    "max_price":   null,
    "min_price":   null
  },
  "context": {
    "servings": 5,
    "budget":   null,
    "occasion": "שבת"
  },
  "confidence":  0.87,
  "cache_hit":   false,
  "intent_id":   "sha256-of-normalized-q-and-tenant"
}
```

**Constraints**:
- `intent_type` is an **enumeration**: `product_search | recipe_query | substitution | info`. Unknown values rejected with 400.
- `filters` accepts only the keys shown. Additional keys are stripped server-side.
- `confidence` ∈ [0, 1]. Values < 0.4 → server falls back to `intent_type: "product_search"`.
- `keywords` are plain strings; the retrieval layer decides how to weight them. Gemini must not embed category IDs or WooCommerce slugs here.

### 4.2 SearchPlan (Cloud Run internal — not sent to browser)

```json
{
  "intent":              { ... },
  "retrieval_strategy":  "hybrid",
  "pgvector_query":      "[768 floats]",
  "wc_params":           { "s": "...", "category": [] },
  "provisional":         false
}
```

`retrieval_strategy` values: `pgvector_ann | bm25_keyword | hybrid | wc_local`. This type is an internal Cloud Run artifact; nothing outside Cloud Run reads it.

### 4.3 SearchResponse

```json
{
  "products":   [],
  "total":      42,
  "pages":      4,
  "source":     "cloud_ranked",
  "intent_id":  "...",
  "latency_ms": 234
}
```

| Field | Notes |
|---|---|
| `source` | `cloud_ranked \| cloud_provisional \| local \| disabled` |
| `intent_id` | null for provisional results |
| `latency_ms` | Server-measured ms from request received to response serialized |

### 4.4 RankedProduct

Extends the existing `format_product` output (see `includes/class-asc-search.php:116`) with AI fields:

```json
{
  "id":           1001,
  "name":         "חזה עוף טרי",
  "permalink":    "/product/chicken-breast/",
  "image":        "https://...",
  "price_html":   "<span>₪29.90</span>",
  "in_stock":     true,
  "stock_status": "instock",
  "type":         "simple",
  "is_weighted":  true,
  "price_note":   "...",
  "add_to_cart_url": "/wp-json/wc/store/v1/cart/add-item",

  "ai_rank":   1,
  "ai_reason": "best match for occasion and group size",
  "inferred_attrs": [
    {
      "key":             "allergen_gluten",
      "value":           true,
      "source":          "product_description",
      "confidence":      0.72,
      "review_required": true
    }
  ],
  "suggest_qty": 2
}
```

**Constraints on AI fields**:
- `ai_rank`: 1-based integer. 0 = not AI-ranked (provisional source).
- `ai_reason`: ≤80 chars. Displayed only in developer/debug mode. **Never shown to shoppers.**
- `inferred_attrs[].review_required: true` → display with caveat label (e.g. "possibly contains gluten — verify before purchase"). Never presented as a confirmed fact.
- `suggest_qty`: advisory only. The user controls actual cart quantity. Must never be passed to `add-item` without user confirmation.
- Allergen / origin inferred fields must carry `review_required: true`. No exceptions.

### 4.5 ContextFrame

```json
{
  "session_id": "uuid-v4",
  "turns": [
    {
      "q":          "עוף לשבת",
      "intent_id":  "...",
      "timestamp_ms": 1234567890
    }
  ],
  "accumulated_filters": {
    "servings": 5,
    "occasion": "שבת",
    "on_sale":  true
  }
}
```

**Storage**: browser `sessionStorage` (key: `asc_context_frame`). Not `localStorage` — context must not persist across sessions.  
**Max turns**: 10. Oldest dropped when limit exceeded.  
**Sent to server**: only on Track B `/intent` calls. Never on provisional searches.

---

## 5. Latency Budget

Derived from V3 §4.3 as referenced in PM plan.

| Checkpoint | P50 target | P95 hard limit | Consequence if missed |
|---|---|---|---|
| Keystroke → debounce fires | 150ms | 150ms (fixed) | — |
| Debounce → Track A renders cards | 150ms | **300ms** | UX degradation |
| Debounce → Gemini intent returned | 800ms | **2000ms** | Acceptable, Track A covers |
| Intent → ranked results returned | 400ms | **1000ms** | Acceptable |
| Debounce → ranked results render | ≤1200ms | **3000ms** | Quality degradation |
| Client-side Track B abandon | — | 4000ms | Provisional is permanent |

**Within 300ms**: provisional product names, images, prices, stock status must render.  
**After 300ms**: AI rank, reasons, inferred attributes, quantity suggestions may arrive.

The 4000ms client-side timeout is a safety net — it is not a target. The P95 target is 3000ms end-to-end for Track B. See §10 for deviation rationale.

---

## 6. Function-Calling Surface

Gemini is called with a structured system prompt that includes exactly the tools below. The Cloud Run server **validates every tool call** before execution. No free SQL, no price mutation, no order changes, no model-invented URLs.

Schema file: `docs/contracts/function-calling-surface.json`

### 6.1 Available tools

#### `search_catalog`

Retrieve products matching structured criteria from the tenant's catalog.

```json
{
  "name": "search_catalog",
  "description": "Search the product catalog by keywords and filters. Returns raw product records for ranking. Never invent product IDs.",
  "parameters": {
    "type": "object",
    "properties": {
      "keywords": {
        "type": "array",
        "items": { "type": "string" },
        "description": "Plain-text search terms"
      },
      "filters": {
        "type": "object",
        "properties": {
          "in_stock":     { "type": "boolean" },
          "on_sale":      { "type": "boolean" },
          "category_ids": { "type": "array", "items": { "type": "integer" } },
          "max_price":    { "type": ["number", "null"] },
          "min_price":    { "type": ["number", "null"] }
        },
        "additionalProperties": false
      },
      "limit": { "type": "integer", "minimum": 1, "maximum": 48 }
    },
    "required": ["keywords"]
  }
}
```

Server validation:
- `limit` capped at 48
- `category_ids` verified against tenant's real taxonomy — model-invented IDs return empty set, not error
- Unknown filter keys stripped (not rejected — prevents prompt-injection-via-filters)

#### `get_product`

Fetch full details of a specific product by its WooCommerce ID.

```json
{
  "name": "get_product",
  "description": "Get full structured details of a specific product. Use only product IDs returned by search_catalog — never invent IDs.",
  "parameters": {
    "type": "object",
    "properties": {
      "product_id": { "type": "integer" }
    },
    "required": ["product_id"]
  }
}
```

Server validation:
- `product_id` must exist in tenant's catalog
- Invisible / draft products return 404 to the model — model must handle gracefully

#### `decompose_recipe`

Break a dish or meal name into ingredient search keywords.

```json
{
  "name": "decompose_recipe",
  "description": "Decompose a dish name into ingredient keywords. Returns keyword strings only — not prices, stock, or availability. Never invent allergen status.",
  "parameters": {
    "type": "object",
    "properties": {
      "dish_name": { "type": "string" },
      "servings":  { "type": "integer", "minimum": 1, "maximum": 100 }
    },
    "required": ["dish_name"]
  }
}
```

Server validation:
- Result feeds into `search_catalog` keywords — it is a string array, not a product list
- Allergen / origin must not be asserted in this response; those require `inferred_attrs` with `review_required: true`

#### `extract_filters`

Convert natural-language constraints into structured search filters.

```json
{
  "name": "extract_filters",
  "description": "Parse natural-language constraints into structured filters (price range, occasion, dietary, etc.).",
  "parameters": {
    "type": "object",
    "properties": {
      "text":   { "type": "string" },
      "locale": { "type": "string", "default": "he-IL" }
    },
    "required": ["text"]
  }
}
```

Server validation:
- Returns a partial `filters` object; unknown keys stripped
- Locale must be in allowed list (`he-IL`, `en-US`) — others default to `he-IL`

### 6.2 Tools NOT available to Gemini

The following must never appear in the function-calling surface. Any PR that adds them must be rejected.

| Tool class | Reason |
|---|---|
| `add_to_cart` | Commerce is WC Store API's domain; model must not trigger cart changes |
| `update_price` | Price is set by WooCommerce; AI has no write access |
| `create_order` / `modify_order` | Never |
| `get_user_data` / `get_user_history` | PII; model has no user identity context |
| `run_sql` / `execute_query` | No free database access |
| `fetch_url` / `http_get` | No model-invented URLs; prevents SSRF |
| `set_stock` / `update_inventory` | Inventory is WC-owned |

---

## 7. pgvector Schema

```sql
-- Tenant-scoped product embeddings with Hebrew lexical layer
-- (pgvector ANN alone is not keyword search; Hebrew tsvector is required)
CREATE TABLE asc_product_embeddings (
    id           BIGSERIAL    PRIMARY KEY,
    tenant_id    TEXT         NOT NULL,
    product_id   INTEGER      NOT NULL,
    updated_at   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    embedding    vector(768)  NOT NULL,         -- Gemini text-embedding-004
    keywords_he  TSVECTOR,                      -- Hebrew lexical layer (required)
    keywords_en  TSVECTOR,                      -- English/transliteration layer
    UNIQUE (tenant_id, product_id)
);

CREATE INDEX ON asc_product_embeddings
    USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100);
CREATE INDEX ON asc_product_embeddings (tenant_id);
CREATE INDEX ON asc_product_embeddings USING GIN (keywords_he);
CREATE INDEX ON asc_product_embeddings USING GIN (keywords_en);
```

Embedding model: Gemini `text-embedding-004` (768 dimensions).  
Tenant isolation: schema-level — `tenant_id` on every row + GUC `app.current_tenant` for row-level security if needed. Cross-tenant leakage is impossible by design.

**Retrieval strategies** (selected by `SearchPlan.retrieval_strategy`):
- `pgvector_ann` — approximate nearest neighbour cosine search only
- `bm25_keyword` — `keywords_he @@ to_tsquery(...)` only
- `hybrid` — reciprocal rank fusion of ANN + BM25 results (default for natural-language queries)
- `wc_local` — falls back to `wc_get_products()` when Cloud Run is unavailable

The Hebrew lexical layer is mandatory. Queries against `keywords_he` use a custom Hebrew dictionary or `pg_trgm` trigram similarity where a full morphological dictionary is not installed. `[SPEC PENDING: exact Hebrew tokenizer — pg_trgm vs custom dictionary]`

---

## 8. Fallback Switch

**Single master switch**: `ASC_Settings.enabled` (boolean, default `true`).  
Setting this to `false` via the admin panel:
1. `ASC_Search::handle_search()` returns immediately with `{products:[], total:0, source:"disabled"}`
2. The drawer JS detects `source === "disabled"` and suppresses output entirely
3. No cloud calls are made

**Cloud-only switch**: `cloud_api_url` empty string.  
- Track B does not fire (no intent endpoint)
- Track A falls back to `local_search()` (existing WC `wc_get_products()`)
- Experience degrades to keyword-only WC search, fully functional

The `enabled` switch is the final fallback that also disables `local_search()` and reverts to native WordPress search (the theme's own search form). No partial states.

---

## 9. Stubs for Other Lanes

Each lane can begin building TODAY against the stubs below.

### Dev-SmartSearchCloud

Add these stub responses to your Cloud Run service immediately (return them when env var `ASC_STUB_MODE=true`):

**`POST /intent` stub**:
```json
{
  "query_original":  "עוף לשבת",
  "query_normalized": "עוף לשבת",
  "intent_type":     "product_search",
  "keywords":        ["עוף", "שבת"],
  "filters": {
    "in_stock": true, "on_sale": false, "category_ids": [], "max_price": null, "min_price": null
  },
  "context":    { "servings": null, "budget": null, "occasion": "שבת" },
  "confidence": 0.91,
  "cache_hit":  false,
  "intent_id":  "stub-abc123"
}
```

**`POST /search` (ranked) stub**:
```json
{
  "products": [
    {
      "id": 1001, "name": "חזה עוף טרי",
      "permalink": "/product/chicken-breast/",
      "image": "https://via.placeholder.com/300x300",
      "price_html": "<span>₪29.90</span>",
      "in_stock": true, "stock_status": "instock",
      "type": "simple", "is_weighted": true,
      "price_note": "המחיר הסופי לפי משקל בעת הרכישה",
      "add_to_cart_url": "/wp-json/wc/store/v1/cart/add-item",
      "ai_rank": 1, "ai_reason": "best match for occasion",
      "inferred_attrs": [], "suggest_qty": null
    }
  ],
  "total": 1, "pages": 1,
  "source": "cloud_ranked",
  "intent_id": "stub-abc123",
  "latency_ms": 450
}
```

### Dev-SmartSearchWordPress

Extend `ascConfig` with these new fields (add to `wp_localize_script` call in `class-asc-search.php`):

```php
'intentUrl' => rest_url( 'asc/v1/intent' ),
'sessionId' => wp_create_nonce( 'asc_session' ), // temporary — replace with UUID when multi-turn lands
```

Build against the Cloud stub URL in a local `.env` or plugin setting.

### Dev-SmartSearchUX

```js
window.ascMockResults = [
  {
    id: 1001, name: "חזה עוף טרי",
    image: "https://via.placeholder.com/300x300",
    price_html: "₪29.90", in_stock: true, type: "simple", ai_rank: 1
  },
  {
    id: 1002, name: "שוקיים עוף",
    image: "https://via.placeholder.com/300x300",
    price_html: "₪24.90", in_stock: true, type: "simple", ai_rank: 2
  },
  {
    id: 1003, name: "חלה לשבת",
    image: "https://via.placeholder.com/300x300",
    price_html: "₪12.50", in_stock: true, type: "simple", ai_rank: 3
  }
];
window.ascMockIntent = {
  intent_type: "product_search",
  keywords: ["עוף", "שבת"],
  context: { occasion: "שבת" }
};
window.ascMockProvState = "PROVISIONAL_SHOWN"; // drive state machine in mock mode
```

No backend required for UX lane. Build the full visual redesign against these fixtures.

### Dev-SmartSearchVoice

STT mock response:
```json
{ "transcript": "אני רוצה עוף לשבת", "confidence": 0.95, "language": "he-IL" }
```

Extend `ascConfig` with `sttUrl` once the Cloud lane has that endpoint.

### Dev-SmartSearchKnowledge

Always-ready stub for `decompose_recipe` tool:
```json
{ "servings": 4, "ingredients": [], "occasion": null }
```

Build ingredient expansion logic behind this interface from day 1.

---

## 10. Spec-Pending Items

| Item | Spec ref | Status |
|---|---|---|
| §16 acceptance test suite | V3 §16 | SPEC file missing |
| §17 twelve required changes | V3 §17 | SPEC file missing |
| Exact Gemini model variant (Pro vs Flash) | V3 §12 | SPEC pending |
| Cloud Run service name + GCP project ID | — | CEO to provide |
| pgvector hosting (Cloud SQL vs standalone Postgres) | — | CEO to provide |
| Gemini API budget ceiling (cost per search) | — | CEO to provide |
| Hebrew tokenizer (pg_trgm vs custom dictionary) | — | Cloud lane to decide, architect to approve |
| `cloud_tenant_id` scope (per-installation vs per-site) | V3 §12 | SPEC pending |

---

## 11. Intentional Deviations from Spec

| Deviation | Reason |
|---|---|
| `ContextFrame` stored in `sessionStorage` (not spec-specified) | Multi-turn context must survive a tab refresh but must not persist across browser sessions. `localStorage` would leak context between unrelated sessions. |
| 4000ms client-side Track B abandon timeout (spec target is ≤3000ms P95) | The 3s target is a P95 latency goal, not a client-side safety net. 4s timeout ensures the user is never left in `GEMINI_PENDING` indefinitely on bad network. The P95 target still stands. |
| `ai_reason` suppressed from shopper UI | Shopper-facing display of AI reasoning is a dark pattern that erodes trust when reasoning is imperfect. Available in developer mode only. |
| Single-word query Gemini skip | Cost optimisation. A one-word query has minimal ambiguity that Gemini resolves better than the index — the latency cost is not justified. This is a deviation from "Gemini is always primary" and must be revisited if V3 §17 contradicts it. |

---

*All other lanes: when you need something this document does not specify, file a question in the project — do not invent an interface. This document is a living contract; updates are versioned in git history.*
