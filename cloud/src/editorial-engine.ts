/**
 * Editorial Engine — R5
 *
 * Groups ranked products into semantically coherent clusters, generates a
 * one-sentence Hebrew rationale per group, surfaces genuine promotions from
 * on_sale + real price fields, attaches substitute hints to OOS products,
 * and produces a single guiding sentence that advances the customer's decision.
 *
 * Rules:
 * - Silence beats filler: guiding_sentence is '' when nothing useful to add.
 * - Never invent a price, deal, allergen, ingredient, origin or product name.
 * - Promotions come from on_sale field only — never inferred.
 * - pa_searchkey (searchkeys[]) is authoritative over model inference for grouping.
 * - Every group rationale is one short sentence only.
 * - Allergen / dietary facts only from catalog attributes — never guessed.
 */

import { VertexAI } from '@google-cloud/vertexai';
import { config } from './config.js';
import {
  EditorialResponse,
  EDITORIAL_RESPONSE_SCHEMA,
  IntentResult,
  ProductGroup,
  RankedProduct,
} from './schemas/index.js';

export interface EditorialEngineResult {
  editorial: EditorialResponse;
  latency_ms: number;
  input_tokens: number;
  output_tokens: number;
  model: string;
}

// ── Mock editorial (no Gemini credits) ──────────────────────────────────────

function mockEditorial(products: RankedProduct[], intent: IntentResult): EditorialResponse {
  if (!products.length) {
    return { groups: [], guiding_sentence: '' };
  }

  const kws = intent.keywords.map(k => k.toLowerCase());

  const directMatch: RankedProduct[] = [];
  const onSale: RankedProduct[] = [];
  const inStockOther: RankedProduct[] = [];
  const oos: RankedProduct[] = [];

  for (const p of products) {
    const skMatch = kws.some(kw =>
      (p.searchkeys || []).some(sk =>
        sk.toLowerCase().includes(kw) || kw.includes(sk.toLowerCase())
      )
    );
    const nameMatch = kws.some(kw => p.name.toLowerCase().includes(kw));

    if (!p.in_stock) {
      oos.push(p);
    } else if (p.on_sale) {
      onSale.push(p);
    } else if (skMatch || nameMatch) {
      directMatch.push(p);
    } else {
      inStockOther.push(p);
    }
  }

  const groups: ProductGroup[] = [];

  // Group 1: direct matches (searchkey-authoritative)
  if (directMatch.length > 0) {
    const anyFromSearchkey = directMatch.some(p => (p.searchkeys || []).length > 0);
    const rationale = anyFromSearchkey
      ? `נמצא לפי שמות שהמוכר עצמו ציין — ${directMatch[0].searchkeys?.slice(0, 2).join(', ') || directMatch[0].name}.`
      : `המוצרים הרלוונטיים ביותר ל"${intent.keywords.slice(0, 2).join(', ')}".`;
    groups.push({
      label: directMatch.length === 1 ? directMatch[0].name : (intent.keywords[0] || 'תוצאות מדויקות'),
      rationale,
      products: directMatch,
      has_promotion: false,
    });
  }

  // Group 2: genuine on-sale products
  if (onSale.length > 0) {
    const names = onSale.slice(0, 2).map(p => p.name).join(' ו-');
    groups.push({
      label: 'במבצע עכשיו',
      rationale: `${names} — מחיר מוזל, מלאי זמין.`,
      products: onSale,
      has_promotion: true,
    });
  }

  // Group 3: other in-stock alternatives
  if (inStockOther.length > 0) {
    const isSubReq = intent.intent_type === 'substitute_request';
    groups.push({
      label: isSubReq ? 'חלופות מוצעות' : 'אפשרויות נוספות',
      rationale: isSubReq
        ? 'אפשרויות נוספות מאותה משפחת מוצרים שכדאי לשקול.'
        : `${inStockOther.length === 1 ? 'מוצר נוסף' : 'מוצרים נוספים'} שעשויים להתאים לחיפוש שלך.`,
      products: inStockOther,
      has_promotion: false,
    });
  }

  // Group 4: OOS products with substitute hints
  if (oos.length > 0) {
    const inStockAll = products.filter(p => p.in_stock);
    const oosEnriched = oos.map(p => ({
      ...p,
      substitutes: (p.substitutes && p.substitutes.length > 0)
        ? p.substitutes
        : inStockAll.slice(0, 2).map(s => ({
            id: s.id,
            name: s.name,
            reason: 'מוצר זמין מאותה קטגוריה חיפוש',
            differences: [],
          })),
    }));
    groups.push({
      label: 'אזל מהמלאי',
      rationale: `${oos[0].name} אינו זמין כרגע. הנה חלופות דומות שזמינות.`,
      products: oosEnriched,
      has_promotion: false,
    });
  }

  return {
    groups,
    guiding_sentence: buildMockGuidingSentence(groups, intent, products),
  };
}

function buildMockGuidingSentence(
  groups: ProductGroup[],
  intent: IntentResult,
  products: RankedProduct[],
): string {
  const totalInStock = products.filter(p => p.in_stock).length;
  const hasOos = products.some(p => !p.in_stock);
  const hasPromo = groups.some(g => g.has_promotion);

  if (intent.intent_type === 'substitute_request' && hasOos) {
    return 'המוצר המקורי אינו זמין — בחר חלופה מכאן ישירות.';
  }

  if (intent.intent_type === 'recipe_planning' && intent.context.servings) {
    return `ל-${intent.context.servings} אנשים — בחר כמות מכל מוצר ישירות מכאן.`;
  }

  if (hasPromo) {
    return 'מוצרים במבצע — המחיר המוזל מוצג ישירות על הכרטיסייה.';
  }

  if (groups.length >= 2 && totalInStock >= 2) {
    const g1 = groups[0];
    const g2 = groups[1];
    return `${g1.label} לעומת ${g2.label} — השווה והוסף לסל ישירות.`;
  }

  if (intent.intent_type === 'question_answering') {
    return '';
  }

  if (totalInStock === 1) {
    const single = products.find(p => p.in_stock);
    return single ? `${single.name} — לחץ "הוסף לסל" כדי להמשיך.` : '';
  }

  return '';
}

// ── Gemini prompt builder ──────────────────────────────────────────────────

function buildEditorialPrompt(products: RankedProduct[], intent: IntentResult): string {
  const lines = products.slice(0, 12).map((p, i) => {
    const sale = p.on_sale ? ' [במבצע]' : '';
    const oos  = p.in_stock ? '' : ' [אזל מהמלאי]';
    const keys = (p.searchkeys || []).length > 0
      ? ` (searchkeys: ${p.searchkeys!.slice(0, 4).join(', ')})`
      : '';
    return `${i + 1}. id=${p.id} "${p.name}"${sale}${oos}${keys}`;
  }).join('\n');

  return `You are the editorial intelligence layer for an Israeli online food store's AI search assistant.
Group the ranked product list into 2–4 semantically coherent clusters. Write one-sentence Hebrew rationale per group and one guiding sentence.

User query: "${intent.query_original}"
Intent type: ${intent.intent_type}
Keywords: ${intent.keywords.join(', ')}

Products (ranked, with merchant searchkeys):
${lines}

Rules:
- Groups reflect WHAT the customer is deciding between (use/style/availability), not category IDs.
- Rationale: one short, specific Hebrew sentence that earns its place. No greetings. No count of products.
- Never restate the product name in the rationale — the card already shows it.
- guiding_sentence: one line that moves the decision forward. When nothing useful to add, return "".
- Silence beats filler. Empty strings are correct.
- searchkeys = merchant-authored synonyms. A product whose searchkey matches the query is a direct match — say so.
- has_promotion: true ONLY for products marked [במבצע]. Never infer a deal.
- product_indices: 1-based indices into the product list above.
- Write in fluent Hebrew only.

Return JSON matching the schema exactly.`;
}

// ── Vertex AI adapter ─────────────────────────────────────────────────────

export async function runEditorialEngine(
  products: RankedProduct[],
  intent: IntentResult,
): Promise<EditorialEngineResult> {
  const start = Date.now();

  if (config.gemini.useMock || products.length === 0) {
    const editorial = mockEditorial(products, intent);
    return {
      editorial,
      latency_ms: 10 + Math.floor(Math.random() * 25),
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
      responseSchema: EDITORIAL_RESPONSE_SCHEMA as any,
      temperature: 0.25,
      maxOutputTokens: 512,
    },
  });

  const prompt = buildEditorialPrompt(products, intent);
  const result = await model.generateContent(prompt);
  const latency_ms = Date.now() - start;

  const candidate = result.response.candidates?.[0];
  if (!candidate) throw new Error('Gemini returned no candidates for editorial');

  const raw = candidate.content.parts[0].text ?? '';
  const parsed = JSON.parse(raw);

  // Map product_indices → actual RankedProduct objects
  const inStockAll = products.filter(p => p.in_stock);
  const groups: ProductGroup[] = (parsed.groups || []).map((g: any) => {
    const idxList: number[] = Array.isArray(g.product_indices) ? g.product_indices : [];
    const groupProducts = idxList
      .filter((idx: number) => idx >= 1 && idx <= products.length)
      .map((idx: number) => {
        const p = products[idx - 1];
        // Enrich OOS products with substitute hints if missing
        if (!p.in_stock && (!p.substitutes || p.substitutes.length === 0)) {
          return {
            ...p,
            substitutes: inStockAll.slice(0, 2).map(s => ({
              id: s.id,
              name: s.name,
              reason: 'מוצר זמין מאותה קטגוריה',
              differences: [],
            })),
          };
        }
        return p;
      });

    return {
      label: String(g.label || ''),
      rationale: String(g.rationale || ''),
      has_promotion: g.has_promotion === true,
      products: groupProducts,
    };
  }).filter((g: ProductGroup) => g.products.length > 0);

  const usage = result.response.usageMetadata;
  return {
    editorial: {
      groups,
      guiding_sentence: String(parsed.guiding_sentence || ''),
    },
    latency_ms,
    input_tokens: usage?.promptTokenCount ?? 0,
    output_tokens: usage?.candidatesTokenCount ?? 0,
    model: config.gemini.intentModel,
  };
}
