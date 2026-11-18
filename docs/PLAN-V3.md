# Smart Search V3 — PM Plan
*PM-SmartSearch — 2026-11-18*

---

## ⚠️ BLOCKER: SPEC FILE MISSING

`docs/SPEC-V3-FULL.md` and `docs/SPEC-INDEX.md` do not exist on disk.
The plan below is built from the task brief (which quotes V3 §1 and §18 verbatim) plus
a full read of the existing module. §16 (acceptance tests) and §17 (the twelve
required changes) cannot be read until the spec is placed at the stated path.

**Action required from CEO**: place the spec file, or tell me the correct path.
Everything in Phase 0 and the unambiguous parts of Phase 1 proceed regardless.

---

## 1. What exists — measured against V3 §1

The current module is `ai-smart-commerce-search` v0.1.1.
Six PHP files, one vanilla JS bundle (~437 lines), two CSS files, translations.

V3 §1 flow: **Input → Gemini Understanding → Search Plan → Catalog Retrieval → AI Ranking → Visual Results → Shopping Actions**

| Requirement | Current state | Notes |
|---|---|---|
| Input capture (drawer opens on search focus) | ✅ Present | MutationObserver intercepts late-inserted fields too |
| Gemini intent extraction | ❌ **Absent** | `ASC_Cloud::search()` proxies raw `q` as a query param — Gemini is never called |
| Search plan returned to plugin | ❌ **Absent** | Cloud returns opaque JSON blob; no intent metadata field |
| Provisional index results during Gemini wait | ❌ **Absent** | Single-track fetch; no two-track pattern |
| AI ranking (re-rank after Gemini) | ❌ **Absent** | WC returns its own relevance order |
| Visual results — card UI | ⚠️ **Needs redesign** | CEO: "לא נראה טוב" — cards exist but no visual QA has passed |
| RTL layout | ✅ Present | `direction: rtl` in CSS + `dir="rtl"` on input/drawer |
| Shopping actions — Add to cart | ✅ Present | WC Store API v1 `/cart/add-item` correctly wired |
| Variations / weighted products | ✅ Present | Variable product selects + weight-note label |
| Voice / microphone input | ❌ **Absent** | No mic button, no STT |
| Multi-turn context ("cooking for 5") | ❌ **Absent** | Each search is stateless |
| Knowledge graph / recipe mode | ❌ **Absent** | No ingredient or recipe layer |
| Quantity reasoning for group cooking | ❌ **Absent** | No "5 people → multiply packs" logic |
| Allergen / origin safety | ❌ **Absent** | No inferred-attribute store |

**Summary**: the drawer skeleton, cart integration, RTL baseline, and WC product formatter are reusable.
The search _engine_ is fully replaced — the existing cloud connector becomes a thin proxy behind a new
Gemini orchestration layer. The UI needs a visual overhaul. Everything else is new.

---

## 2. Phases

### Phase 0 — Contracts (Lead-SmartSearchArchitect)
**What ships**: signed-off JSON schemas for all inter-lane interfaces.
**Who**: Lead-SmartSearchArchitect alone.
**Duration target**: 1–2 days.
**Unblocks**: every other lane can build behind stubs immediately after.

Contracts to define:
- `IntentResult` — Gemini's parsed understanding of a natural-language query
  `{ query_original, intent_type, keywords[], filters{}, context{servings,budget,occasion}, confidence }`
- `SearchPlan` — what the cloud orchestrator produces before hitting the index
  `{ intent, retrieval_strategy, pgvector_query, wc_params, provisional_ok }`
- `RankedProduct` — product card as returned from cloud, extended with AI fields
  `{ ...existing format_product fields, ai_rank, ai_reason, inferred_attrs[] }`
- `ContextFrame` — multi-turn state passed across searches (Voice / Knowledge lanes need this)
  `{ session_id, turns[], accumulated_filters{} }`
- Cloud Run endpoint signatures: `POST /intent`, `POST /search`, `GET /health`
- pgvector schema: table, index type, dimension, tenant isolation strategy

Acceptance: schema files committed to `docs/contracts/`; each lane lead signs off.

---

### Phase 1 — AI-First Core  *(parallel: Cloud + WordPress + UX)*
**What ships**: a working AI-first search flow on staging — Gemini interprets the query,
provisional index results render immediately, Gemini-ranked results replace them.
No voice, no knowledge graph yet.

**Acceptance test (from §18 shape)**: type "עוף לשבת" → provisional cards appear within 300ms →
Gemini intent fires → ranked results replace provisional set within 3s → cards look right on mobile.

#### Lane: Dev-SmartSearchCloud
- Build `POST /intent` on Cloud Run: receives `q + tenant_id`, calls Gemini, returns `IntentResult`
- Build `POST /search`: receives `IntentResult + tenant_id`, queries pgvector + WC catalog, returns `RankedProduct[]`
- Provisional path: same search endpoint accepts `provisional: true` to skip Gemini and return fast index results
- Behind Phase 0 `IntentResult` / `SearchPlan` stubs from day 1

#### Lane: Dev-SmartSearchWordPress
- Rewrite `ASC_Cloud::search()` as a two-track fetch:
  - Track A: immediate call to `POST /search?provisional=true` → renders in ≤300ms
  - Track B: `POST /intent` → `POST /search` → replaces Track A results when ready
- Extend `ascConfig` with `intentUrl` field
- Extend `format_product` to pass through `ai_rank`, `ai_reason` from cloud response
- No new PHP class needed — refactor inside existing `class-asc-cloud.php` and `class-asc-search.php`

#### Lane: Dev-SmartSearchUX
- Full visual redesign of the drawer (this lane owns "לא נראה טוב")
- Design brief:
  - Cards: larger image, name prominent, price below, stock badge, clean CTA button
  - Provisional state: subtle shimmer / skeleton cards while Gemini runs
  - Loading states: spinner in header during provisional, "AI refinement…" badge when Gemini is active
  - Mobile: drawer goes full-height on viewports <480px, search input sticky at top
  - RTL: all layout must pass RTL visual QA on Chrome + Safari iOS
  - Theme isolation: test against nfarm staging — fix z-index conflicts and font bleed
- Deliverable: redesigned `drawer.css` + updated card HTML in `build/index.js`

**Parallelism**: all three lanes work concurrently behind Phase 0 stubs.
Dev-SmartSearchWordPress needs the Cloud endpoint URL (can use a stub URL in env var).
Dev-SmartSearchUX needs no backend — works against mock `ascConfig` data.

---

### Phase 2 — Voice + Knowledge  *(parallel: Voice + Knowledge)*
**What ships**: microphone button in drawer (fires STT → populates search); catalog
intelligence layer (recipes, quantities, allergens surfaced from inferred attributes).
Begins after Phase 1 is on staging and passing.

**Acceptance test**: tap mic → say "אני רוצה להכין ארוחה נחמדה, חמישה אנשים, במבצע" → transcript
appears in search field → Gemini intent fired → products shown with quantity guidance for 5 servings.

#### Lane: Dev-SmartSearchVoice
- Add mic button to drawer header (right of search input, RTL-aware)
- Use Google Cloud Speech-to-Text (Hebrew `he-IL` model)
- Show transcript for user confirmation before firing search (editable, 3s auto-confirm)
- Multi-turn: accumulate `ContextFrame` across voice turns in session storage
- Graceful degradation: mic button hidden if `MediaRecorder` API unavailable

#### Lane: Dev-SmartSearchKnowledge
- Extend `IntentResult` with `ingredients[]`, `occasion`, `servings`, `sale_preference` fields
- Cloud Run: recipe decomposition (Gemini: "ארוחת שבת → עוף/דגים/חלות/יין")
- Inferred attributes: confidence + source tag, never displayed as fact without both
- Quantity adjustment: `servings * base_pack_size → suggest_qty` passed to card
- **Safety constraint**: allergens / origin inferred fields are marked `review_required: true` and displayed with a caveat label — never presented as confirmed

---

### Phase 3 — Integration QA  *(QA-SmartSearch)*
**What ships**: §16 acceptance tests passing on staging; performance benchmarks met.

**When**: after Phase 2 merges to staging.

Scope:
- Full §18 scenario end-to-end (once spec file is available to read §16 precisely)
- Latency: provisional ≤300ms; Gemini full result ≤3s (§4.3 target)
- Cost audit: Gemini API call count per session, cache hit rate
- RTL visual regression: screenshot comparison on Chrome + Safari iOS
- Cart: add-to-cart for simple, variable, and weighted products
- Voice: Hebrew STT accuracy on 10 reference queries

---

## 3. Parallelism map

```
Day 1–2:   Phase 0 ─────────────────────── Lead-SmartSearchArchitect
                                                     │
                              ┌──────────────────────┼──────────────────┐
Day 2–8:   Phase 1          Cloud              WordPress                UX
           (all parallel)   Dev-SmartSearchCloud  Dev-SmartSearchWordPress  Dev-SmartSearchUX
                              └──────────────────────┴──────────────────┘
                                                     │ (staging deploy)
                              ┌──────────────────────┴──────────────────┐
Day 9–14:  Phase 2          Voice                                   Knowledge
           (both parallel)  Dev-SmartSearchVoice              Dev-SmartSearchKnowledge
                              └──────────────────────┬──────────────────┘
                                                     │ (staging merge)
Day 15–17: Phase 3            QA-SmartSearch (§16 acceptance suite)
```

**Interface stubs so no lane sits idle:**
- Dev-SmartSearchWordPress builds against a mock `POST /intent` that returns a canned `IntentResult` JSON
- Dev-SmartSearchUX builds against hardcoded `window.ascMockResults` array — no backend needed
- Dev-SmartSearchVoice builds against a mock STT endpoint returning a fixed Hebrew transcript
- Dev-SmartSearchKnowledge builds behind a stub that always returns `servings: 4, ingredients: []`

---

## 4. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Hebrew STT accuracy | Medium | Show editable transcript before search fires; user corrects if wrong |
| Gemini latency > 3s | Medium | Two-track pattern (provisional index at 300ms) covers the gap; Gemini failure is non-blocking |
| Cost per search at volume | Medium | Intent cache by query hash, 5-min TTL; skip Gemini for single-word queries (they go straight to index) |
| Theme fighting the drawer | High (nfarm observed) | Dev-SmartSearchUX tests against staging from day 1; CSS scoped with `.asc-` prefix throughout; Shadow DOM as escalation if bleed is severe |
| §17 twelve items unknown | High | SPEC file missing — plan may need adjustment once readable. Treat as Phase 0 blocker for Lead-SmartSearchArchitect |
| Allergen/origin hallucination | High (safety) | Knowledge lane must enforce `review_required` flag and caveat label; inferred fields never presented as fact |
| pgvector cold-start latency | Low | Vector index warm on Cloud Run; fallback to WC local search if cold |

---

## 5. What I need from the CEO before building

**Short list — ask once, clearly:**

1. **SPEC file**: place `docs/SPEC-V3-FULL.md` and `docs/SPEC-INDEX.md` at the stated path.
   I cannot read §16 (acceptance tests) or §17 (twelve required changes) without it.
   Phase 0 contracts and Phase 1 unambiguous work begin now regardless.

2. **Gemini API budget ceiling**: what is the maximum cost-per-search we are designing for?
   This determines whether the intent cache is mandatory or optional.

3. **GCP project + Cloud Run service name** for Dev-SmartSearchCloud to deploy to.
   (I know the existing settings store `cloud_project_id` and `cloud_api_url` but those are the
   plugin's settings for connecting to an already-running service, not the service itself.)

4. **pgvector hosting**: is it a Cloud SQL Postgres instance in the same GCP project, or a
   separate Postgres? Dev-SmartSearchCloud needs to know before designing the retrieval layer.

---

## Constraints (non-negotiable, written into every dispatch)

- AI never governs price, stock or cart. WooCommerce Store API owns commerce. AI proposes; server validates.
- Never invent a product, price, availability, ingredient, allergen or origin.
- Inferred attributes carry source, confidence, and `review_required` state.
- Nothing hard-coded to nfarm domain, categories, or products.
- Relevance before promotion.
