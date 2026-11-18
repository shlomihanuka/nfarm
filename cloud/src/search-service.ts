/**
 * Search orchestrator — V3 §6 / §11
 *
 * Receives an IntentResult (or runs the intent engine inline) and produces
 * a SearchPlan + ranked products.
 *
 * The provisional path skips Gemini entirely and runs keyword search only,
 * enabling the ≤300ms first render while Gemini processes in parallel.
 *
 * WooCommerce Store API owns commerce: price, stock, cart.
 * This service only reads products — it never modifies cart or stock.
 */

import { SearchPlan, IntentResult, RankedProduct } from './schemas/index.js';

// ── Search plan builder ──────────────────────────────────────────────────────

export function buildSearchPlan(
  intent: IntentResult,
  per_page: number,
  page: number,
): SearchPlan {
  const isSingleWord = intent.keywords.length === 1 && !intent.filters.on_sale;
  const strategy = isSingleWord ? 'keyword_fallback' : 'hybrid';

  const pgvectorQuery = intent.keywords.join(' ');

  const wcParams: SearchPlan['wc_params'] = {
    per_page,
    page,
    s: intent.keywords.join(' '),
  };

  if (intent.filters.on_sale) wcParams.on_sale = true;
  if (intent.filters.categories?.length) wcParams.category = intent.filters.categories;
  if (intent.filters.min_price != null) wcParams.min_price = intent.filters.min_price;
  if (intent.filters.max_price != null) wcParams.max_price = intent.filters.max_price;

  return {
    intent,
    retrieval_strategy: strategy,
    pgvector_query: pgvectorQuery,
    wc_params: wcParams,
    provisional_ok: true,
  };
}

// ── Provisional (fast) search ────────────────────────────────────────────────
// Used for the ≤300ms Track A render while Gemini runs on Track B.
// Falls back to WC local search result shape; actual WC call happens in the plugin.

export interface ProvisionalResult {
  search_plan: Omit<SearchPlan, 'intent'> & { intent: null };
  keywords: string[];
  wc_params: SearchPlan['wc_params'];
}

export function buildProvisionalPlan(
  q: string,
  per_page: number,
  page: number,
): ProvisionalResult {
  const keywords = q
    .trim()
    .split(/\s+/)
    .filter(w => w.length > 1)
    .slice(0, 8);

  return {
    search_plan: {
      intent: null,
      retrieval_strategy: 'keyword_fallback',
      pgvector_query: keywords.join(' '),
      wc_params: { s: keywords.join(' '), per_page, page },
      provisional_ok: true,
    },
    keywords,
    wc_params: { s: keywords.join(' '), per_page, page },
  };
}

// ── AI ranking ───────────────────────────────────────────────────────────────
// Re-ranks a product list against the intent.
// In this initial build, ranking is deterministic (intent match score).
// Phase 2 will add a Gemini re-ranking call for complex intents.

export function rankProducts(
  products: RankedProduct[],
  intent: IntentResult,
): RankedProduct[] {
  const servings = intent.context.servings;

  return products
    .map((p, idx) => {
      const keywordScore = intent.keywords.reduce((score, kw) => {
        const inName = p.name.toLowerCase().includes(kw.toLowerCase()) ? 2 : 0;
        return score + inName;
      }, 0);

      const saleScore = intent.filters.on_sale && p.in_stock ? 1 : 0;

      const totalScore = keywordScore + saleScore;

      const ranked: RankedProduct = {
        ...p,
        ai_rank: idx + 1,
        ai_reason: buildReason(p, intent, keywordScore > 0, saleScore > 0),
      };

      if (servings && intent.intent_type === 'recipe_planning') {
        ranked.suggest_qty = Math.ceil(servings / 4);
      }

      return { product: ranked, score: totalScore };
    })
    .sort((a, b) => b.score - a.score)
    .map((item, idx) => ({ ...item.product, ai_rank: idx + 1 }));
}

function buildReason(
  p: RankedProduct,
  intent: IntentResult,
  keywordMatch: boolean,
  onSale: boolean,
): string {
  const parts: string[] = [];
  if (keywordMatch) parts.push(`matches "${intent.keywords.slice(0, 2).join(', ')}"`);
  if (onSale) parts.push('on sale');
  if (intent.context.occasion) parts.push(`suitable for ${intent.context.occasion}`);
  return parts.length ? parts.join('; ') : 'catalog match';
}
