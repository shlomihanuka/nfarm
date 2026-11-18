/**
 * Day-1 demo: run the four V3 worked examples through the intent engine
 * and print the structured search plan each produces.
 *
 * Uses mock Gemini (USE_MOCK_GEMINI=true) — no API credits consumed.
 * Run: cd cloud && pnpm demo
 */

process.env['USE_MOCK_GEMINI'] = 'true';
process.env['GEMINI_INTENT_MODEL'] = 'gemini-1.5-flash-002';

import { runIntentEngine } from '../src/intent-engine.js';
import { buildSearchPlan } from '../src/search-service.js';
import type { IntentResult, SearchPlan } from '../src/schemas/index.js';

// Four worked examples derived from V3 §18 and PM plan
const EXAMPLES = [
  {
    label: 'V3 §18 — full occasion with constraints',
    q: 'אני רוצה להכין ארוחה נחמדה למשפחה, משהו לא מסובך, רצוי שיהיה במבצע, ואנחנו חמישה אנשים',
  },
  {
    label: 'V3 PM Phase 1 — occasion product',
    q: 'עוף לשבת',
  },
  {
    label: 'Ingredient with dietary constraint',
    q: 'חלב ללא לקטוז דל שומן',
  },
  {
    label: 'Category browse — salad ingredients',
    q: 'משהו לסלט ירקות',
  },
];

async function main() {
  console.log('═══════════════════════════════════════════════════════════════');
  console.log('  ASC V3 — Intent Engine Demo  (mock Gemini, no API credits)');
  console.log('═══════════════════════════════════════════════════════════════\n');

  const results: { label: string; intent: IntentResult; plan: SearchPlan; latency_ms: number }[] = [];

  for (const example of EXAMPLES) {
    console.log(`▶  ${example.label}`);
    console.log(`   Query: "${example.q}"`);

    const start = performance.now();
    const { intent, latency_ms, model } = await runIntentEngine(example.q);
    const plan = buildSearchPlan(intent, 12, 1);
    const elapsed = Math.round(performance.now() - start);

    results.push({ label: example.label, intent, plan, latency_ms });

    console.log('\n   ── IntentResult ──────────────────────────────────────────');
    console.log(`   intent_type : ${intent.intent_type}`);
    console.log(`   keywords    : [${intent.keywords.join(', ')}]`);
    console.log(`   filters     : ${JSON.stringify(intent.filters)}`);
    console.log(`   context     : ${JSON.stringify(intent.context)}`);
    console.log(`   confidence  : ${intent.confidence}`);

    console.log('\n   ── SearchPlan ─────────────────────────────────────────────');
    console.log(`   strategy    : ${plan.retrieval_strategy}`);
    console.log(`   pgvector_q  : "${plan.pgvector_query}"`);
    console.log(`   wc_params   : ${JSON.stringify(plan.wc_params)}`);
    console.log(`   provisional : ${plan.provisional_ok}`);

    console.log(`\n   ⏱  Latency: ${latency_ms}ms (simulated) | model: ${model}`);
    console.log('\n' + '─'.repeat(65) + '\n');
  }

  console.log('═══════════════════════════════════════════════════════════════');
  console.log('  Summary');
  console.log('═══════════════════════════════════════════════════════════════');
  for (const r of results) {
    const strategyLabel = r.plan.retrieval_strategy.padEnd(18);
    const kwLabel = r.intent.keywords.slice(0, 3).join(', ');
    console.log(`  [${r.intent.intent_type.padEnd(20)}] ${strategyLabel} kw: ${kwLabel}`);
  }
  console.log('\nAll four examples parsed successfully. ✓');
  console.log('Next: wire POST /intent in Cloud Run and replace mock with real Vertex AI call.\n');
}

main().catch(err => {
  console.error('Demo failed:', err);
  process.exit(1);
});
