#!/usr/bin/env node
/**
 * SNMP log-list DOM harness — the page's log rows, driven in Node.
 * ============================================================================
 * `app/Views/snmp/index.php` draws the agent's log: it reads `snmp/logs` and
 * renders one `.log-row` per entry — when it was seen, which subsystem wrote it,
 * its level and the message — then narrows that list as the operator types in the
 * filter box. The PHP suite pins the endpoint and its answer; what PHP cannot see
 * is a row actually being built out of it, or the filter narrowing the rows.
 *
 * So this harness runs the page's own script. It reads the shipped view (never a
 * copy — a copy would pass while the shipped code rotted), gives it a `document`
 * whose nodes really nest, and drives:
 *
 *   1. **The row.** The answer becomes one row per entry, with the stamp,
 *      subsystem and message as the spans' text and the level in the chip classed
 *      for it — including the levels the page has always shown differently (a
 *      trap is shipped as `TRAP-OUT` and shown as `TRAP`) and an unknown level,
 *      which falls back to the plain chip. A level named like an object key is
 *      checked too, so the chip table cannot be read through the prototype.
 *
 *   2. **The escaping, structurally.** Every field is filled from the daemon's
 *      answer, and the level used to be concatenated straight into its chip — so
 *      the harness feeds the builder values that look like markup and asserts on
 *      the nodes: the value is the chip's or span's text, nothing shaped like an
 *      element became one, and no node in the list is built from an HTML string.
 *
 *   3. **The filter.** Typing in the box narrows the same list through the same
 *      builder, which the harness pins by reading the rows back.
 *
 * Run it directly:
 *
 *     node tests/js/snmp_logs_harness.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..', '..');
const VIEW = 'app/Views/snmp/index.php';

// The page draws its rows with the shared builder, loaded from the layout. The
// harness loads the same shipped file into the sandbox for the same reason it
// reads the shipped view: the page cannot build a row without it, so driving the
// page with a stub would prove nothing about what the appliance serves.
const TABLE = 'public/assets/js/micronos-table.js';
const tableSource = fs.readFileSync(path.join(ROOT, TABLE), 'utf8');

const checks = [];
function check(name, cond, detail = '') {
  checks.push({ name, cond: !!cond, detail: String(detail) });
}

// The page's <script>, with the PHP echoes reduced to inert text so the body is
// valid JavaScript. The script is otherwise byte-for-byte the shipped one. The
// values are substituted bare because the view already quotes each echo
// (`MicronScheduler.fetch('<?= base_url('snmp/logs') ?>')`).
const php = fs.readFileSync(path.join(ROOT, VIEW), 'utf8');
const scriptMatch = php.match(/<script>([\s\S]*?)<\/script>/);
if (!scriptMatch) {
  console.error(`No <script> block was found in ${VIEW}.`);
  process.exit(1);
}
const script = scriptMatch[1]
  .replace(/<\?php[\s\S]*?\?>/g, '')
  .replace(/<\?=\s*base_url\(\s*'([^']*)'\s*\)\s*\?>/g, '$1')
  .replace(/<\?=\s*base_url\(\s*\)\s*\?>/g, '/')
  .replace(/<\?=\s*csrf_token\(\s*\)\s*\?>/g, 'csrf_token_name')
  .replace(/<\?=\s*csrf_hash\(\s*\)\s*\?>/g, 'rendered-hash')
  .replace(/<\?=\s*esc\([\s\S]*?\)\s*\?>/g, 'x')
  .replace(/<\?=[\s\S]*?\?>/g, 'x');

// ---------------------------------------------------------------------------
// A DOM just large enough for this page — and one whose nodes really nest, so a
// row's text can be read off the node the page built rather than off a string.
// ---------------------------------------------------------------------------
const TEXT_NODE = 3;

function makeTextNode(text) {
  return { nodeType: TEXT_NODE, textContent: String(text), children: [], childNodes: [] };
}

function makeEl(tag) {
  let text = '';
  const el = {
    nodeType: 1,
    tagName: String(tag).toUpperCase(),
    value: '',
    checked: false,
    // Only ever non-empty if a view assembles markup — which the log rows must
    // not, though the buttons behind them still write their icons that way.
    innerHTML: '',
    children: [],
    childNodes: [],
    attrs: {},
    style: {},
    classList: { add() {}, remove() {}, contains() { return false; }, toggle() {} },
    listeners: {},
    scrollTop: 0,
    scrollHeight: 0,
    setAttribute(key, value) { this.attrs[key] = value; },
    getAttribute(key) { return Object.prototype.hasOwnProperty.call(this.attrs, key) ? this.attrs[key] : null; },
    hasAttribute(key) { return Object.prototype.hasOwnProperty.call(this.attrs, key); },
    appendChild(node) {
      this.childNodes.push(node);
      if (node.nodeType !== TEXT_NODE) this.children.push(node);
      return node;
    },
    // How many times this node's children were swapped rather than added to.
    replacements: 0,
    // What a re-render uses: the list is swapped in one go, never appended to.
    replaceChildren(...nodes) {
      this.childNodes = nodes;
      this.children = nodes.filter((node) => node.nodeType !== TEXT_NODE);
      this.replacements += 1;
    },
    addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); },
    click() { for (const fn of (this.listeners.click || [])) fn({ preventDefault() {} }); },
    querySelector() { return null; },
    querySelectorAll() { return []; },
    focus() {},
  };
  // As in a browser, these are reflected attributes: setting the property is what
  // sets the attribute, and reading it back gives the same string.
  for (const reflected of ['title', 'type', 'className']) {
    const attr = reflected === 'className' ? 'class' : reflected;
    Object.defineProperty(el, reflected, {
      get() { return Object.prototype.hasOwnProperty.call(this.attrs, attr) ? this.attrs[attr] : ''; },
      set(value) { this.attrs[attr] = String(value); },
    });
  }

  // As in a browser: reading textContent gives the node's text including its
  // descendants, and writing it replaces whatever children were there.
  const reader = {
    get() {
      if (this.childNodes.length) return this.childNodes.map((child) => child.textContent).join('');
      return text;
    },
    set(value) { text = String(value); this.childNodes = []; this.children = []; },
  };
  Object.defineProperty(el, 'textContent', reader);
  Object.defineProperty(el, 'innerText', reader);
  return el;
}

const byId = new Map();
const document = {
  getElementById(id) {
    if (!byId.has(id)) byId.set(id, makeEl('div'));
    return byId.get(id);
  },
  createElement(tag) { return makeEl(tag); },
  createElementNS(ns, tag) { return makeEl(tag); },
  createTextNode(text) { return makeTextNode(text); },
  querySelectorAll() { return []; },
  addEventListener() {},
};

// ---------------------------------------------------------------------------
// The daemon's answers, which the harness controls
// ---------------------------------------------------------------------------
const env = { logs: [], urls: [] };

function answer(url) {
  env.urls.push(String(url));
  return { logs: env.logs };
}

const sandbox = {
  document,
  console,
  alert: () => {},
  __env: env,
  __check: check,
  navigator: { clipboard: { writeText: async () => undefined } },
  MicronScheduler: {
    fetch: async (url) => ({ ok: true, json: async () => answer(url) }),
    poll: () => ({ stop() {} }),
    report: () => {},
  },
  setTimeout,
};
// A browser's `window` is the global object; the sandbox is this page's, so the
// shared scripts loaded into it find their global the same way.
sandbox.window = sandbox;
vm.createContext(sandbox);

// The layout loads the builder before any page script; the sandbox gets it in
// the same order.
vm.runInContext(tableSource, sandbox, { filename: TABLE });

// ---------------------------------------------------------------------------
// The driver, run in the page's own scope so it can reach the page's functions
// ---------------------------------------------------------------------------
const driver = `
;(async () => {
  const settle = () => new Promise((r) => setTimeout(r, 0));
  const list = document.getElementById('snmpLogsContainer');
  const rows = () => list.children;
  const spans = (i) => (rows()[i] || { children: [] }).children;
  const texts = (i) => spans(i).map((span) => span.textContent);
  // Every node inside the list, so a value written as markup anywhere in it — a
  // nested chip, not just a span — is caught.
  const markupNodes = () => {
    const found = [];
    const walk = (node) => {
      if (node.innerHTML) found.push(node);
      (node.children || []).forEach(walk);
    };
    walk(list);
    return found;
  };
  const chip = (i) => ((spans(i)[2] || {}).children || [])[0] || {};
  // Every row's nth span, read defensively: a detail line that threw while
  // reporting a failure would hide the failure it was reporting.
  const column = (n) => rows().map((tr) => ((tr.children || [])[n] || {}).textContent);
  const log = (over) => Object.assign({
    timestamp: '2026-10-08 14:20:05', subsystem: 'snmpd', level: 'QUERY',
    message: 'SNMPv2c GET 1.3.6.1.2.1.1.3.0 answered in 2 ms',
  }, over);

  // 1. The row.
  __check('the list is drawn through the shared builder',
    typeof MicronTable === 'object' && typeof MicronTable.render === 'function', typeof MicronTable);

  const swaps = list.replacements;
  renderSnmpLogs([log({})]);
  __check('drawing the list swaps it in one go', list.replacements === swaps + 1, String(list.replacements - swaps));
  __check('the answer becomes one row per entry', rows().length === 1, String(rows().length));
  __check('a row has the four fields the stylesheet paints', spans(0).length === 4, String(spans(0).length));
  __check('the stamp, subsystem and message are the spans text',
    texts(0)[0] === '2026-10-08 14:20:05' && texts(0)[1] === 'snmpd'
      && texts(0)[3].indexOf('answered in 2 ms') !== -1,
    JSON.stringify(texts(0)));
  __check('the row carries the classes the log styles expect',
    rows()[0].className === 'log-row' && spans(0)[0].className === 'log-time'
      && spans(0)[1].className === 'log-sub' && spans(0)[3].className === 'log-msg',
    JSON.stringify([rows()[0].className, (spans(0)[0] || {}).className,
      (spans(0)[1] || {}).className, (spans(0)[3] || {}).className]));

  // 2. The level chips.
  __check('a query is the lan chip', chip(0).className === 'mno-obj-chip mno-obj-chip-lan', String(chip(0).className));
  renderSnmpLogs([log({ level: 'AUTH-OK', message: 'auth' })]);
  __check('a successful auth is the success chip',
    chip(0).className === 'mno-obj-chip mno-obj-chip-success' && chip(0).textContent === 'AUTH-OK',
    String(chip(0).className));
  renderSnmpLogs([log({ level: 'TRAP-OUT', message: 'trap' })]);
  __check('a trap is shipped as TRAP-OUT and shown as TRAP on the wan chip',
    chip(0).className === 'mno-obj-chip mno-obj-chip-wan' && chip(0).textContent === 'TRAP',
    String(chip(0).className) + ' / ' + chip(0).textContent);
  renderSnmpLogs([log({ level: 'AUTH-FAIL', message: 'fail' })]);
  __check('a failure is the error badge holding the level',
    chip(0).className === 'badge badge-error' && chip(0).textContent === 'AUTH-FAIL',
    String(chip(0).className) + ' / ' + chip(0).textContent);
  renderSnmpLogs([log({ level: '', message: 'quiet' })]);
  __check('a missing level falls back to INFO on the plain chip',
    chip(0).className === 'mno-obj-chip' && chip(0).textContent === 'INFO',
    String(chip(0).className) + ' / ' + chip(0).textContent);

  // The level is looked up in a fixed table, so a level named like an object key
  // must not reach through the prototype for its chip.
  renderSnmpLogs([log({ level: 'constructor', message: 'odd' })]);
  __check('a level named like an object key falls back to the plain chip',
    chip(0).className === 'mno-obj-chip' && chip(0).textContent === 'constructor',
    String(chip(0).className) + ' / ' + chip(0).textContent);

  // 3. The escaping, structurally: a value off the wire is a node's text.
  const markup = '<img src=x onerror=alert(1)>';
  renderSnmpLogs([log({ subsystem: markup, level: markup, message: markup })]);
  __check('a subsystem that looks like an element is the span text', texts(0)[1] === markup, JSON.stringify(texts(0)[1]));
  __check('a level that looks like an element is the chip text with no element made from it',
    chip(0).textContent === markup && chip(0).children.length === 0, JSON.stringify(chip(0).textContent));
  __check('a message that looks like an element is the span text', texts(0)[3] === markup, JSON.stringify(texts(0)[3]));
  __check('the list is built from nodes, never from markup', markupNodes().length === 0,
    JSON.stringify(markupNodes().map((n) => String(n.tagName))));

  // 4. A re-render swaps the rows rather than adding to them.
  renderSnmpLogs([log({})]);
  renderSnmpLogs([log({}), log({ level: 'WARN', message: 'second' })]);
  __check('a re-render swaps the rows rather than adding to them', rows().length === 2, String(rows().length));

  // 5. An absent list renders the one line that says so.
  let threw = null;
  try { renderSnmpLogs(undefined); } catch (err) { threw = err; }
  __check('a missing list renders the empty line instead of throwing',
    threw === null && rows().length === 1 && rows()[0].textContent === 'No SNMP log entries recorded.',
    threw ? String(threw) : JSON.stringify(rows()[0].textContent));
  __check('the count badge reports the entry count',
    document.getElementById('logCountBadge').innerText === '0 entries',
    document.getElementById('logCountBadge').innerText);

  // 6. The fetch path, and the filter narrowing through the same builder.
  __env.logs = [
    log({ subsystem: 'snmpd', level: 'QUERY', message: 'GET sysUpTime answered' }),
    log({ subsystem: 'auth', level: 'AUTH-OK', message: 'community public accepted' }),
    log({ subsystem: 'trap', level: 'TRAP-OUT', message: 'linkDown from sw1' }),
  ];
  await fetchSnmpLogs();
  await settle();
  __check('the page reads the snmp logs endpoint', __env.urls.indexOf('snmp/logs') !== -1, JSON.stringify(__env.urls));
  __check('the fetched entries are rendered through the builder',
    rows().length === 3 && texts(2)[1] === 'trap', JSON.stringify(column(1)));
  __check('the count badge reports the fetched count',
    document.getElementById('logCountBadge').innerText === '3 entries',
    document.getElementById('logCountBadge').innerText);

  document.getElementById('logFilter').value = 'linkdown';
  applyLogFilter();
  __check('the filter narrows the list through the same builder',
    rows().length === 1 && texts(0)[1] === 'trap' && chip(0).textContent === 'TRAP',
    JSON.stringify(column(1)));
  document.getElementById('logFilter').value = 'AUTH-OK';
  applyLogFilter();
  __check('the filter matches the level as well',
    rows().length === 1 && chip(0).className === 'mno-obj-chip mno-obj-chip-success',
    String(chip(0).className));
  document.getElementById('logFilter').value = '';
  applyLogFilter();
  __check('clearing the filter puts the whole list back', rows().length === 3, String(rows().length));

  __env.done = true;
})();
`;

vm.runInContext(script + driver, sandbox, { filename: VIEW });

// The driver is asynchronous; wait for it to announce that it finished.
for (let i = 0; i < 200 && !env.done; i += 1) {
  await new Promise((resolve) => setTimeout(resolve, 0));
}
if (!env.done) {
  console.error('The SNMP log-list driver did not finish; the page script may have thrown.');
  process.exit(1);
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
const failures = checks.filter((c) => !c.cond);
for (const c of checks) {
  console.log(`${c.cond ? 'PASS' : 'FAIL'} ${c.name}` + (c.detail && !c.cond ? `  [${c.detail}]` : ''));
}
console.log();

if (failures.length) {
  console.log(`FAILED: ${failures.length} check(s): ` + failures.map((f) => f.name).join(', '));
  process.exit(1);
}

console.log(`ALL SNMP LOGS HARNESS CHECKS PASSED (${checks.length} checks)`);
