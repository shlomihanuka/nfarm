# AI Smart Commerce Search — specification index

The authority is **`SPEC-V3-FULL.md`** (4,274 lines, ~17,000 words), supplied by the CEO
2026-11-18. It is **four documents concatenated**. Read the one that governs your work; do not
read all 4,274 lines to answer a narrow question.

`SPEC-V2-superseded.md` is the previous spec. **Do not build from it.** It is kept because its
design rulings still hold (see "Still binding" below).

| # | Document | Lines | What it is |
|---|---|---|---|
| 1 | אפיון מוצר גרסה 1.0 | 1–648 | Original product doc, sections A–N. Useful for UX intent and the AI's voice/personality (§A). |
| 2 | Technical Spec V2.0 | 649–2218 | The previous build's spec. Superseded on direction, still accurate on Google Cloud component detail (§6), API contracts (§15) and acceptance tests (§24). |
| 3 | **אפיון גרסה 3.0 — AI-First** | **2219–3353** | **THE GOVERNING DOCUMENT.** Read §1 and §17 before writing any code. |
| 4 | Back Office & Autonomous Intelligence | 3354–4274 | Agent Studio, knowledge sources, Product Knowledge Graph, Recipe Intelligence, autonomous learning. |

---

## The one thing that changed, and it changes everything

**V3 §1 (line 2231) — "AI הוא מנוע החיפוש הראשי". The CEO calls it the most important requirement
in the spec.**

The old build treated keyword search as the engine and AI as a fallback when results were thin.
That is now explicitly wrong. Gemini is the **primary** engine:

> User Input → **Gemini Understanding** → Search Plan → Catalog Retrieval → AI Ranking &
> Recommendations → Visual Results → Shopping Actions

- Every meaningful query, once the user pauses, goes through Gemini intent understanding — unless a
  valid cached decode already exists.
- The index may serve provisional results while Gemini thinks, but **those do not replace intent
  processing.**
- Keywords, synonyms, criteria and filters are **derived from** Gemini's understanding — never the
  other way round.
- The search index stays, demoted to fast **retrieval**. It is no longer the brain.

**Worked examples are at lines 2252–2290** — "משהו טעים לאכול הערב", "איטלקי לארבעה אנשים לא יקר
מדי", "גבינה שאפשר להמיס בתנור אבל לא חריפה", "משהו דומה לזה אבל יותר זול". Build against those,
not against single-word queries.

## What must change in the module that already exists — V3 §17 (line 3307)

The CEO says explicitly: **you do not have to start from scratch.** Twelve required changes:

1. Move Gemini to the primary intent-decoding layer.
2. Replace the keyword-only flow with an AI-First flow.
3. Keep the search index as fast retrieval, **not** as the intent engine.
4. Add a microphone in the search field and in the results drawer.
5. Connect Google Cloud Speech-to-Text or Gemini Audio Understanding.
6. Allow free speech and preserve context between recordings.
7. Change the placeholder so it invites free-form requests.
8. Add a real AI indicator while decoding.
9. Show short, meaningful AI responses beside products.
10. Add context-aware follow-up options.
11. Wire a genuine connection between Gemini, substitutes, promotions and the cart.
12. Add a Debugger screen showing what Gemini understood and what it invoked.

**Everything the previous spec required is retained** — semantic search, substitutes, catalog
enrichment, sync, promotions, customer service, navigation, quantities and weights, WooCommerce
integration, analytics.

## The definition of done — V3 §18 (line 3330)

Not ready if it merely lists products by name and appends an AI-generated sentence. Ready when a
customer can say or type:

> "אני רוצה להכין ארוחה נחמדה למשפחה, משהו לא מסובך, רצוי שיהיה במבצע, ואנחנו חמישה אנשים."

…and the system understands the intent through Gemini, identifies preferences and constraints,
searches the real catalogue, proposes relevant products and combinations, shows images, prices and
quantities, lets the request be changed by voice or text, offers substitutes, honours active
promotions, and adds to cart — quickly, with minimum text and maximum usefulness.

> *"זהו מנוע חיפוש מבוסס Gemini AI שמלווה את הלקוח בקנייה, ולא מנוע חיפוש רגיל עם צ'אטבוט שמחובר אליו."*

## Where to find a subject

| Subject | Where |
|---|---|
| AI voice/personality, micro-copy rules | Doc 1 §A (line 11) |
| Voice search — mic, STT, free speech, failures | Doc 3 §3 (line 2368) |
| Intent engine — responsibilities, typing latency | Doc 3 §4 (line 2480) |
| Result presentation, product card, follow-up chips | Doc 3 §5 (line 2586) |
| Catalog intelligence | Doc 3 §6 (2641), Doc 4 §5 (3662) |
| Substitutes | Doc 2 §9 (1380), Doc 3 §7 (2674) |
| Shopping companion | Doc 3 §8 (2718) |
| Promotions | Doc 3 §9 (2776) |
| Customer service | Doc 3 §10 (2812) |
| Google Cloud components (deep detail) | Doc 2 §6 (918), Doc 3 §11 (2845) |
| API contracts | Doc 2 §15 (1691), Doc 3 §12 (2941) |
| Runtime AI system prompt (binding) | Doc 3 §13 (3026) |
| Back office screens | Doc 2 §16 (1765), Doc 3 §14 (3106), Doc 4 (3354+) |
| **AI Search Debugger** | Doc 3 §14 (line 3135) |
| Agent Studio — identity, instructions, permissions, prompt versioning | Doc 4 §3 (3468) |
| Knowledge & sources | Doc 4 §4 (3589) |
| Product Knowledge Graph + DB structure | Doc 4 §6 (3756) |
| Recipe Intelligence | Doc 4 §7 (3847) |
| Autonomous learning & self-improvement | Doc 4 §8 (3893) |
| Performance targets | Doc 2 §20 (1989), Doc 3 §15 (3182) |
| Acceptance tests | Doc 2 §24 (2158), **Doc 3 §16 (3213) — AI-First, use these** |

## Still binding, from the CEO, regardless of version
1. **AI never governs price, stock or cart.** WooCommerce Store API owns commerce. The model
   proposes; the server validates and executes.
2. **Portability.** Installing on another store means swapping catalogue connection, settings and
   styling — never rewriting the engine. Nothing hard-coded to nfarm's domain, categories or products.
3. **Never invent.** No product id, price, availability, discount, ingredient, allergen, origin or
   certification that is not verified. Inferred attributes carry source, confidence and review state.
4. **Relevance before promotion.** A promoted product must never surface as a substitute when it
   does not fit the stated need.

## Pilot and environments
- Production pilot: `https://nfarm.co.il` — **do not install here yet.**
- Staging, where work happens: `https://nfarmcoil2stg.wpenginepowered.com/`
  Credentials: `../.credentials/staging-site.json` (gitignored — never paste the values anywhere).
