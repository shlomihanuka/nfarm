/**
 * Gemini Intent Engine — V3 §4
 *
 * Receives a free-form natural-language query (Hebrew or other languages),
 * calls Gemini with structured output, and returns a typed IntentResult.
 *
 * Rules:
 *  - Never called from the customer browser — server-side only.
 *  - Model IDs come from config, not code.
 *  - Every field that cannot be directly observed in the query is left undefined
 *    (never invented).
 */

import { VertexAI } from '@google-cloud/vertexai';
import { config } from './config.js';
import {
  IntentResult,
  IntentResultSchema,
  INTENT_RESPONSE_SCHEMA,
  ContextFrame,
} from './schemas/index.js';

// ── Prompt template ──────────────────────────────────────────────────────────

function buildIntentPrompt(q: string, context?: ContextFrame): string {
  const priorContext = context?.accumulated_filters
    ? `\nPrior session context: ${JSON.stringify(context.accumulated_filters)}`
    : '';

  return `You are a search intent analyser for an Israeli online supermarket / food store.
Your job is to extract structured search intent from the user's natural-language query.

Rules:
- Extract only what is explicitly stated or strongly implied by the query. Do not invent.
- For recipe_planning intents, decompose the request into the core ingredient keywords.
- "במבצע" / "sale" / "offer" → on_sale: true.
- If a number of people/servings is mentioned, extract it.
- Occasion examples: שבת→"shabbat", יום הולדת→"birthday", ארוחת ערב→"dinner".
- Language detection: he for Hebrew, en for English, ar for Arabic, ru for Russian.
- Confidence: 0.9+ for specific queries, 0.6–0.9 for ambiguous, below 0.6 means keyword fallback is safer.
${priorContext}

User query: "${q}"

Return a JSON object matching the response schema exactly.`;
}

// ── Mock adapter (dev / demo — no API credits) ───────────────────────────────

function mockIntentResult(q: string): IntentResult {
  const lower = q.toLowerCase();

  // Question: contains "?" or interrogative markers
  if (
    q.includes('?') ||
    q.includes('מה ההבדל') ||
    q.includes('מה זה') ||
    q.includes('האם') ||
    (q.includes('כמה') && (q.includes('גלוטן') || q.includes('אלרגן') || q.includes('מרכיב'))) ||
    q.includes('מכיל') ||
    (q.includes('מה') && (q.includes('ההבדל') || q.includes('עדיף')))
  ) {
    return {
      query_original: q,
      intent_type: 'question_answering',
      keywords: q.split(/\s+/).filter(w => w.length > 1).slice(0, 4),
      filters: {},
      context: { language: 'he' },
      confidence: 0.88,
    };
  }

  // Substitute request: "דומה", "חלופה", "במקום", "אלטרנטיבה"
  if (q.includes('דומה') || q.includes('חלופה') || q.includes('במקום') || q.includes('אלטרנטיבה') || q.includes('תחליף')) {
    return {
      query_original: q,
      intent_type: 'substitute_request',
      keywords: q.split(/\s+/).filter(w => w.length > 1 && !['דומה','חלופה','במקום','אלטרנטיבה','תחליף'].includes(w)).slice(0, 4),
      filters: {},
      context: { language: 'he' },
      confidence: 0.9,
    };
  }

  if (q.includes('ארוחה') && q.includes('משפחה') || q.includes('חמישה')) {
    return {
      query_original: q,
      intent_type: 'recipe_planning',
      keywords: ['עוף', 'דגים', 'ירקות', 'אורז'],
      filters: { on_sale: q.includes('במבצע') || q.includes('מבצע') },
      context: {
        servings: q.match(/חמישה|5/) ? 5 : q.match(/ארבעה|4/) ? 4 : undefined,
        occasion: q.includes('שבת') ? 'shabbat' : q.includes('חג') ? 'holiday' : undefined,
        language: 'he',
      },
      confidence: 0.91,
    };
  }

  if (q.includes('עוף') && q.includes('שבת')) {
    return {
      query_original: q,
      intent_type: 'occasion_shopping',
      keywords: ['עוף', 'שבת'],
      filters: { categories: ['בשר ועוף'] },
      context: { occasion: 'shabbat', language: 'he' },
      confidence: 0.95,
    };
  }

  if (q.includes('לקטוז') || lower.includes('lactose')) {
    return {
      query_original: q,
      intent_type: 'ingredient_search',
      keywords: ['חלב', 'ללא לקטוז'],
      filters: {
        dietary: ['lactose-free'],
        categories: ['מוצרי חלב'],
      },
      context: { language: 'he' },
      confidence: 0.93,
    };
  }

  if (q.includes('סלט') || q.includes('ירקות')) {
    return {
      query_original: q,
      intent_type: 'category_browse',
      keywords: ['ירקות', 'עגבניה', 'מלפפון', 'פלפל'],
      filters: { categories: ['ירקות ופירות'] },
      context: { language: 'he' },
      confidence: 0.87,
    };
  }

  // Generic fallback
  return {
    query_original: q,
    intent_type: 'ingredient_search',
    keywords: q.split(/\s+/).filter(w => w.length > 1),
    filters: {},
    context: { language: 'he' },
    confidence: 0.65,
  };
}

// ── Vertex AI adapter ────────────────────────────────────────────────────────

export interface IntentEngineResult {
  intent: IntentResult;
  latency_ms: number;
  input_tokens: number;
  output_tokens: number;
  model: string;
}

export async function runIntentEngine(
  q: string,
  context?: ContextFrame,
): Promise<IntentEngineResult> {
  const start = Date.now();

  if (config.gemini.useMock) {
    const intent = mockIntentResult(q);
    // Simulate realistic intent latency for demo purposes
    const mockLatency = 50 + Math.floor(Math.random() * 100);
    return {
      intent,
      latency_ms: mockLatency,
      input_tokens: 0,
      output_tokens: 0,
      model: `mock:${config.gemini.intentModel}`,
    };
  }

  const vertexai = new VertexAI({
    project: config.gcp.projectId,
    location: config.gcp.region,
  });

  const model = vertexai.getGenerativeModel({
    model: config.gemini.intentModel,
    generationConfig: {
      responseMimeType: 'application/json',
      responseSchema: INTENT_RESPONSE_SCHEMA as any,
      temperature: 0.1,
      maxOutputTokens: 512,
    },
    systemInstruction: {
      role: 'system',
      parts: [{ text: 'You are a precise search intent analyser. Output only valid JSON matching the schema.' }],
    },
  });

  const prompt = buildIntentPrompt(q, context);
  const result = await model.generateContent(prompt);
  const latency_ms = Date.now() - start;

  const candidate = result.response.candidates?.[0];
  if (!candidate) throw new Error('Gemini returned no candidates');

  const raw = candidate.content.parts[0].text ?? '';
  const parsed = JSON.parse(raw);

  const intent = IntentResultSchema.parse({
    ...parsed,
    query_original: q,
    context: {
      language: parsed.context?.language ?? 'he',
      ...parsed.context,
    },
    filters: parsed.filters ?? {},
  });

  const usage = result.response.usageMetadata;
  return {
    intent,
    latency_ms,
    input_tokens: usage?.promptTokenCount ?? 0,
    output_tokens: usage?.candidatesTokenCount ?? 0,
    model: config.gemini.intentModel,
  };
}
