/**
 * Compile a GNU .po file to a binary .mo file.
 * Usage: node compile-mo.js <input.po> <output.mo>
 *
 * Implements the GNU MO file format:
 * https://www.gnu.org/software/gettext/manual/html_node/MO-Files.html
 */
'use strict';

const fs   = require('fs');
const path = require('path');

const inFile  = process.argv[2];
const outFile = process.argv[3];

if (!inFile || !outFile) {
  console.error('Usage: node compile-mo.js input.po output.mo');
  process.exit(1);
}

// ---- Parse .po ----
function parsePo(src) {
  const entries = [];
  let msgid = null;
  let msgstr = null;

  function flush() {
    if (msgid !== null && msgstr !== null) {
      entries.push({ id: unescape(msgid), str: unescape(msgstr) });
    }
    msgid = null;
    msgstr = null;
  }

  const lines = src.split(/\r?\n/);
  let inMsgid  = false;
  let inMsgstr = false;

  for (const line of lines) {
    if (line.startsWith('msgid ')) {
      if (inMsgstr) flush();
      inMsgid  = true;
      inMsgstr = false;
      msgid    = extractString(line.slice('msgid '.length));
    } else if (line.startsWith('msgstr ')) {
      inMsgid  = false;
      inMsgstr = true;
      msgstr   = extractString(line.slice('msgstr '.length));
    } else if (line.startsWith('"') && inMsgid) {
      msgid  += extractString(line);
    } else if (line.startsWith('"') && inMsgstr) {
      msgstr += extractString(line);
    } else if (!line.trim() || line.startsWith('#')) {
      if (inMsgstr) flush();
      inMsgid  = false;
      inMsgstr = false;
    }
  }
  if (inMsgstr) flush();

  // Filter: skip header (empty msgid) and entries with empty msgstr.
  return entries.filter(e => e.id !== '' && e.str !== '');
}

function extractString(s) {
  s = s.trim();
  if (s.startsWith('"') && s.endsWith('"')) {
    return s.slice(1, -1);
  }
  return s;
}

function unescape(s) {
  return s
    .replace(/\\n/g, '\n')
    .replace(/\\t/g, '\t')
    .replace(/\\r/g, '\r')
    .replace(/\\"/g, '"')
    .replace(/\\\\/g, '\\');
}

// ---- Write .mo ----
function writeMo(entries) {
  // Sort entries by msgid (required by GNU MO spec for binary search).
  entries.sort((a, b) => (a.id < b.id ? -1 : a.id > b.id ? 1 : 0));

  const n = entries.length;

  // Encode all strings as UTF-8 buffers.
  const originals = entries.map(e => Buffer.from(e.id,  'utf8'));
  const translats = entries.map(e => Buffer.from(e.str, 'utf8'));

  const headerSize   = 28;         // bytes in the fixed header
  const tableEntries = n * 2 * 8; // two tables × n entries × 8 bytes each

  let offset = headerSize + tableEntries;

  // Build offsets for original strings.
  const origOffsets = originals.map(buf => {
    const o = { len: buf.length, off: offset };
    offset += buf.length + 1; // +1 for NUL
    return o;
  });

  // Build offsets for translated strings.
  const transOffsets = translats.map(buf => {
    const o = { len: buf.length, off: offset };
    offset += buf.length + 1;
    return o;
  });

  const totalSize = offset;
  const out = Buffer.alloc(totalSize, 0);
  let pos = 0;

  const write32 = (v) => { out.writeUInt32LE(v, pos); pos += 4; };

  // Magic number.
  write32(0x950412de);
  // Revision.
  write32(0);
  // Number of strings.
  write32(n);
  // Offset of original strings table.
  write32(28); // right after header
  // Offset of translated strings table.
  write32(28 + n * 8);
  // Size of hash table (0 = no hash).
  write32(0);
  // Offset of hash table.
  write32(28 + n * 16);

  // Original strings table.
  for (const o of origOffsets) {
    write32(o.len);
    write32(o.off);
  }

  // Translated strings table.
  for (const o of transOffsets) {
    write32(o.len);
    write32(o.off);
  }

  // String data.
  for (const buf of originals) {
    buf.copy(out, pos);
    pos += buf.length + 1; // NUL-terminated
  }
  for (const buf of translats) {
    buf.copy(out, pos);
    pos += buf.length + 1;
  }

  return out;
}

const src     = fs.readFileSync(inFile, 'utf8');
const entries = parsePo(src);
console.log('Parsed', entries.length, 'translations from', inFile);

const moBuffer = writeMo(entries);
fs.writeFileSync(outFile, moBuffer);
console.log('Written', moBuffer.length, 'bytes to', outFile);
