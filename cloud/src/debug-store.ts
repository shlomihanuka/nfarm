/**
 * Debug trace store — V3 §14
 *
 * Records everything Gemini understood and which tools it invoked for
 * every request. Surfaced via GET /debug/:trace_id (admin-only).
 *
 * Phase 1: in-memory LRU (bounded at 1000 traces, ~10MB max).
 * Phase 2: persist to Cloud SQL for cross-instance and historical access.
 */

import { DebugTrace } from './schemas/index.js';

const MAX_TRACES = 1000;
const store = new Map<string, DebugTrace>();
const insertOrder: string[] = [];

export function saveTrace(trace: DebugTrace): void {
  if (store.size >= MAX_TRACES) {
    const oldest = insertOrder.shift();
    if (oldest) store.delete(oldest);
  }
  store.set(trace.trace_id, trace);
  insertOrder.push(trace.trace_id);
}

export function getTrace(traceId: string): DebugTrace | undefined {
  return store.get(traceId);
}

export function traceCount(): number {
  return store.size;
}
