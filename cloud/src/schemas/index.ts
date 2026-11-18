import { z } from 'zod';

// ── IntentResult ────────────────────────────────────────────────────────────

export const IntentTypeSchema = z.enum([
  'recipe_planning',
  'ingredient_search',
  'category_browse',
  'specific_product',
  'occasion_shopping',
]);
export type IntentType = z.infer<typeof IntentTypeSchema>;

export const IntentFiltersSchema = z.object({
  on_sale: z.boolean().optional(),
  min_price: z.number().nonnegative().optional(),
  max_price: z.number().nonnegative().optional(),
  categories: z.array(z.string()).optional(),
  dietary: z.array(z.string()).optional(),
});

export const IntentContextSchema = z.object({
  servings: z.number().int().positive().optional(),
  budget: z.number().nonnegative().optional(),
  occasion: z.string().optional(),
  language: z.enum(['he', 'en', 'ar', 'ru']).default('he'),
});

export const IntentResultSchema = z.object({
  query_original: z.string(),
  intent_type: IntentTypeSchema,
  keywords: z.array(z.string()),
  filters: IntentFiltersSchema,
  context: IntentContextSchema,
  confidence: z.number().min(0).max(1),
});
export type IntentResult = z.infer<typeof IntentResultSchema>;

// ── SearchPlan ──────────────────────────────────────────────────────────────

export const RetrievalStrategySchema = z.enum([
  'vector_primary',
  'keyword_fallback',
  'hybrid',
]);

export const WcParamsSchema = z.object({
  s: z.string().optional(),
  category: z.array(z.string()).optional(),
  on_sale: z.boolean().optional(),
  min_price: z.number().optional(),
  max_price: z.number().optional(),
  per_page: z.number().int().min(1).max(48),
  page: z.number().int().min(1),
});

export const SearchPlanSchema = z.object({
  intent: IntentResultSchema,
  retrieval_strategy: RetrievalStrategySchema,
  pgvector_query: z.string(),
  wc_params: WcParamsSchema,
  provisional_ok: z.boolean(),
});
export type SearchPlan = z.infer<typeof SearchPlanSchema>;

// ── InferredAttr (product metadata never stated as fact) ───────────────────

export const InferredAttrSchema = z.object({
  key: z.string(),
  value: z.unknown(),
  source: z.enum(['gemini_inference', 'catalog_import', 'admin_verified']),
  confidence: z.number().min(0).max(1),
  updated_at: z.string().datetime(),
  approval_state: z.enum(['pending', 'approved', 'rejected']),
  review_required: z.boolean(),
});

// ── RankedProduct ───────────────────────────────────────────────────────────

export const RankedProductSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  permalink: z.string().url(),
  image: z.string().url(),
  price_html: z.string(),
  in_stock: z.boolean(),
  stock_status: z.string().optional(),
  type: z.string(),
  is_weighted: z.boolean().optional(),
  price_note: z.string().optional(),
  ai_rank: z.number().int().min(1).optional(),
  ai_reason: z.string().optional(),
  suggest_qty: z.number().int().min(1).optional(),
  inferred_attrs: z.array(InferredAttrSchema).optional(),
  add_to_cart_url: z.string().url().optional(),
  attributes: z.array(z.unknown()).optional(),
  variations: z.array(z.unknown()).optional(),
});
export type RankedProduct = z.infer<typeof RankedProductSchema>;

// ── ContextFrame ────────────────────────────────────────────────────────────

export const ContextFrameSchema = z.object({
  session_id: z.string(),
  turns: z.array(z.object({
    query_original: z.string(),
    intent_type: z.string().optional(),
    timestamp_ms: z.number().int(),
  })),
  accumulated_filters: z.object({
    dietary: z.array(z.string()).optional(),
    servings: z.number().int().optional(),
    occasion: z.string().optional(),
  }).optional(),
});
export type ContextFrame = z.infer<typeof ContextFrameSchema>;

// ── Debug trace ─────────────────────────────────────────────────────────────

export interface DebugTrace {
  trace_id: string;
  tenant_id: string;
  created_at: string;
  query_original: string;
  intent_result: IntentResult | null;
  search_plan: SearchPlan | null;
  gemini_function_calls: unknown[];
  retrieval_hits: number;
  latency_ms: {
    intent: number;
    retrieval: number;
    ranking: number;
    total: number;
  };
  token_cost: {
    input_tokens: number;
    output_tokens: number;
    model: string;
  };
}

// ── Vertex AI response schema (OpenAPI subset Gemini accepts) ───────────────
// Used as responseSchema in generateContent calls — not the same as the Zod schemas above.

export const INTENT_RESPONSE_SCHEMA = {
  type: 'OBJECT',
  required: ['query_original', 'intent_type', 'keywords', 'filters', 'context', 'confidence'],
  properties: {
    query_original: { type: 'STRING' },
    intent_type: {
      type: 'STRING',
      enum: ['recipe_planning', 'ingredient_search', 'category_browse', 'specific_product', 'occasion_shopping'],
    },
    keywords: { type: 'ARRAY', items: { type: 'STRING' } },
    filters: {
      type: 'OBJECT',
      properties: {
        on_sale: { type: 'BOOLEAN' },
        min_price: { type: 'NUMBER' },
        max_price: { type: 'NUMBER' },
        categories: { type: 'ARRAY', items: { type: 'STRING' } },
        dietary: { type: 'ARRAY', items: { type: 'STRING' } },
      },
    },
    context: {
      type: 'OBJECT',
      properties: {
        servings: { type: 'INTEGER' },
        budget: { type: 'NUMBER' },
        occasion: { type: 'STRING' },
        language: { type: 'STRING' },
      },
    },
    confidence: { type: 'NUMBER' },
  },
} as const;
