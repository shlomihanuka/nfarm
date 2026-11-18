/**
 * Assistant Engine — R4
 *
 * Generates a short Hebrew assistant message from intent + optional products.
 * Rules:
 *  - Never state an unverified fact as fact.
 *  - Allergen answers come ONLY from catalog attributes; when absent, say so.
 *  - tone: "none" and empty message when there is nothing useful to add.
 *  - Never invent product names, prices, ingredients, or origins.
 */

import { VertexAI } from '@google-cloud/vertexai';
import { config } from './config.js';
import {
  AssistantResponse,
  AssistantResponseSchema,
  ASSISTANT_RESPONSE_SCHEMA,
  IntentResult,
  RankedProduct,
} from './schemas/index.js';

// ── Types ─────────────────────────────────────────────────────────────────────

export interface AssistantEngineResult {
  assistant: AssistantResponse;
  latency_ms: number;
  input_tokens: number;
  output_tokens: number;
  model: string;
}

// ── Mock path ─────────────────────────────────────────────────────────────────

function mockAssistant(intent: IntentResult, products: RankedProduct[]): AssistantResponse {
  const { intent_type, keywords, context, filters } = intent;
  const q = intent.query_original;
  const kw = keywords.slice(0, 3).join(', ');

  if (intent_type === 'question_answering') {
    // Extract allergen / dietary info from catalog attributes
    const allergenAttrs = products.flatMap(p =>
      (p.inferred_attrs || []).filter(a =>
        typeof a.key === 'string' &&
        (a.key.includes('אלרגן') || a.key.includes('allergen') || a.key.includes('gluten') || a.key.includes('גלוטן'))
      )
    );

    if (allergenAttrs.length > 0) {
      const info = allergenAttrs.map(a => `${a.key}: ${String(a.value)}`).join('; ');
      return {
        message: `לפי נתוני הקטלוג: ${info}.`,
        tone: 'answer',
        follow_ups: ['מה עוד מכיל גלוטן?', 'הראה לי מוצרים ללא גלוטן'],
      };
    }

    // Check question keywords
    if (q.includes('גלוטן') || q.toLowerCase().includes('gluten')) {
      return {
        message: 'לא מצאתי מידע על גלוטן בנתוני הקטלוג של המוצרים שהוחזרו. מומלץ לבדוק ישירות בדף המוצר.',
        tone: 'answer',
        follow_ups: ['מוצרים ללא גלוטן', 'מוצרים ללא לקטוז'],
      };
    }

    if (q.includes('הבדל') || q.includes('שונה') || q.includes('לעומת')) {
      const names = products.slice(0, 2).map(p => p.name);
      if (names.length >= 2) {
        return {
          message: `הבאתי לך את ${names[0]} ו-${names[1]} להשוואה. ההבדלים העיקריים לא מופיעים בנתוני הקטלוג — נסה לראות את דפי המוצרים לפרטים מלאים.`,
          tone: 'answer',
          follow_ups: [`פרטים על ${names[0]}`, `פרטים על ${names[1]}`],
        };
      }
    }

    return {
      message: 'לא מצאתי תשובה ישירה בנתוני הקטלוג. ניתן לבדוק בדפי המוצרים.',
      tone: 'answer',
      follow_ups: [],
    };
  }

  if (intent_type === 'substitute_request') {
    const inStock = products.filter(p => p.in_stock);
    const outOfStock = products.filter(p => !p.in_stock);
    if (outOfStock.length > 0 && inStock.length > 0) {
      return {
        message: `${outOfStock[0].name} אינו במלאי — הבאתי לך חלופות דומות שזמינות כרגע.`,
        tone: 'suggest',
        follow_ups: ['הצג עוד חלופות', 'סנן לפי מחיר'],
      };
    }
    return {
      message: `הנה מוצרים דומים ל"${kw}" שתוכל לשקול.`,
      tone: 'suggest',
      follow_ups: [],
    };
  }

  if (intent_type === 'recipe_planning') {
    const servingText = context.servings ? ` ל-${context.servings} אנשים` : '';
    const occasionText = context.occasion === 'shabbat' ? ' לשבת' : context.occasion ? ` ל${context.occasion}` : '';
    const saleText = filters.on_sale ? ', עם דגש על מבצעים' : '';
    return {
      message: `הבנתי — ארוחה${servingText}${occasionText}${saleText}. בחרתי עבורך את המרכיבים הרלוונטיים ביותר.`,
      tone: 'suggest',
      follow_ups: context.servings ? [`כמה עלות ל-${context.servings} אנשים?`, 'מה מתאים לצמחונים?'] : ['מה מתאים לצמחונים?'],
    };
  }

  if (intent_type === 'occasion_shopping') {
    const occasion = context.occasion === 'shabbat' ? 'שבת'
      : context.occasion === 'birthday' ? 'יום הולדת'
      : context.occasion === 'holiday' ? 'חג'
      : context.occasion || '';
    return {
      message: occasion
        ? `בחרתי עבורך מוצרים מתאימים ל${occasion}.`
        : `בחרתי עבורך מוצרים לאירוע שלך.`,
      tone: 'suggest',
      follow_ups: [],
    };
  }

  if (intent_type === 'category_browse') {
    return {
      message: products.length > 0
        ? `מצאתי ${products.length} מוצרים בקטגוריה.`
        : `לא מצאתי מוצרים תואמים לחיפוש זה.`,
      tone: products.length > 0 ? 'suggest' : 'clarify',
      follow_ups: products.length === 0 ? ['נסה חיפוש רחב יותר', 'הצג הכל'] : [],
    };
  }

  if (intent_type === 'ingredient_search') {
    if (products.length === 0) {
      return {
        message: `לא מצאתי מוצרים תואמים ל"${kw}". נסה לחפש במילים אחרות.`,
        tone: 'clarify',
        follow_ups: [`חפש "${keywords[0] || q}" בקטגוריות אחרות`],
      };
    }
    if (products.length > 0 && filters.dietary?.length) {
      return {
        message: `הבאתי מוצרים שעומדים בהגבלות התזונתיות שציינת.`,
        tone: 'suggest',
        follow_ups: [],
      };
    }
    return { message: '', tone: 'none', follow_ups: [] };
  }

  if (intent_type === 'specific_product') {
    if (products.length === 0) {
      return {
        message: `לא מצאתי את "${kw}" במלאי. הצגתי מוצרים דומים.`,
        tone: 'clarify',
        follow_ups: [`חלופות ל"${keywords[0] || q}"`],
      };
    }
    return { message: '', tone: 'none', follow_ups: [] };
  }

  return { message: '', tone: 'none', follow_ups: [] };
}

// ── Gemini prompt builder ─────────────────────────────────────────────────────

function buildAssistantPrompt(intent: IntentResult, products: RankedProduct[]): string {
  const productContext = products.slice(0, 6).map(p => {
    const attrs = (p.inferred_attrs || [])
      .filter(a => a.approval_state === 'approved')
      .map(a => `${a.key}: ${String(a.value)}`)
      .join(', ');
    return `- ${p.name}${p.in_stock ? '' : ' [אזל מהמלאי]'}${attrs ? ` (${attrs})` : ''}`;
  }).join('\n');

  return `You are the voice of an Israeli online food store's AI search assistant.
Write a SHORT Hebrew response (1–2 sentences max) that adds value to the user's search.

User query: "${intent.query_original}"
Intent type: ${intent.intent_type}
Keywords: ${intent.keywords.join(', ')}
Filters: ${JSON.stringify(intent.filters)}
Context: ${JSON.stringify(intent.context)}
${productContext ? `\nTop products found:\n${productContext}` : '\nNo products found.'}

Rules:
- Write in fluent, friendly Hebrew only.
- For question_answering: answer ONLY from the product attributes listed above. If the attribute is not listed, say "לא מצאתי מידע על כך בקטלוג" — never guess.
- For substitute_request: explain what you found and why the substitutes are relevant.
- For recipe_planning / occasion_shopping: one warm, relevant sentence about what you understood.
- When there is nothing useful to say: return tone "none" and empty message.
- Never greet, never restate the product list, never state uncertain facts as facts.
- follow_ups: 0–2 short Hebrew follow-up questions the user might want to ask next.

Return JSON matching the schema exactly.`;
}

// ── Vertex AI adapter ─────────────────────────────────────────────────────────

export async function runAssistantEngine(
  intent: IntentResult,
  products: RankedProduct[] = [],
): Promise<AssistantEngineResult> {
  const start = Date.now();

  if (config.gemini.useMock) {
    const assistant = mockAssistant(intent, products);
    return {
      assistant,
      latency_ms: 20 + Math.floor(Math.random() * 40),
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
      responseSchema: ASSISTANT_RESPONSE_SCHEMA as any,
      temperature: 0.3,
      maxOutputTokens: 256,
    },
  });

  const prompt = buildAssistantPrompt(intent, products);
  const result = await model.generateContent(prompt);
  const latency_ms = Date.now() - start;

  const candidate = result.response.candidates?.[0];
  if (!candidate) throw new Error('Gemini returned no candidates for assistant');

  const raw = candidate.content.parts[0].text ?? '';
  const parsed = JSON.parse(raw);
  const assistant = AssistantResponseSchema.parse(parsed);

  const usage = result.response.usageMetadata;
  return {
    assistant,
    latency_ms,
    input_tokens: usage?.promptTokenCount ?? 0,
    output_tokens: usage?.candidatesTokenCount ?? 0,
    model: config.gemini.intentModel,
  };
}
