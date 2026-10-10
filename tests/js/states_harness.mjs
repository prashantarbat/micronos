#!/usr/bin/env node
/**
 * States-table DOM harness — the page's table, driven in Node.
 * ============================================================================
 * `app/Views/diag/states.php` renders the Connection States table: it reads
 * `diag/states/data` and draws one row per state. The Interface cell shows the
 * name the controller resolved for the flow — a friendly interface name such as
 * `WAN`/`LAN`, or the raw device when the port is unassigned — and an em dash
 * when the flow could not be attributed to any interface at all. The PHP suite
 * pins the controller's resolution and the endpoint's answers; what PHP cannot
 * see is the cell actually being built out of that resolved value, or the dash
 * fallback for a flow with no interface.
 *
 * So this harness runs the page's own script. It reads the shipped view (never a
 * copy — a copy would pass while the shipped code rotted), gives it a `document`
 * whose nodes really nest, and a `MicronScheduler` whose answers the harness
 * controls, and drives:
 *
 *   1. **The resolved interface.** A state whose interface resolved renders that
 *      name in the cell; an unassigned port renders its raw device name — the
 *      page shows whatever the server resolved, unaltered — and a state whose
 *      interface is blank or missing renders the em dash, never `undefined`.
 *
 *   2. **The table's shape.** The row is ten cells because the header declares
 *      ten, the protocol and state cells hold the badges that style them, and a
 *      re-render *swaps* the body rather than adding to it.
 *
 *   3. **The fetch and the search.** The page reads the endpoint, renders its
 *      answer (one row per state, the count badge beside them), and a search
 *      matches the resolved interface name.
 *
 *   4. **The escaping, structurally.** Every cell is filled from the daemon's
 *      answer, so the harness feeds it values that look like markup and asserts
 *      on the nodes: the value is the cell's *text*, the cell has no element
 *      children, and nothing in the table was built from an HTML string. A quote
 *      in an address cannot break out either, because the address reaches the
 *      kill handler through a closure rather than an attribute — which the
 *      harness pins by clicking the row's own button and reading what the request
 *      carried.
 *
 * Run it directly:
 *
 *     node tests/js/states_harness.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..', '..');
const VIEW = 'app/Views/diag/states.php';

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
// (`MicronScheduler.fetch('<?= base_url('diag/states/data') ?>')`), so the echo
// is the string's contents — which is what lets the harness assert the endpoint
// the page read and the CSRF field name it sent.
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
  .replace(/<\?=[\s\S]*?\?>/g, 'x');

// ---------------------------------------------------------------------------
// A DOM just large enough for this page — and one whose nodes really nest, so a
// cell's text can be read off the node the page built rather than off a string.
// ---------------------------------------------------------------------------
function makeEl(tag) {
  let text = '';
  const el = {
    tagName: String(tag).toUpperCase(),
    className: '',
    title: '',
    type: '',
    value: '',
    // Only ever non-empty if a view assembles markup — which this one must not.
    innerHTML: '',
    children: [],
    attrs: {},
    style: {},
    classList: { add() {}, remove() {}, contains() { return false; }, toggle() {} },
    listeners: {},
    // How many times this node's children were swapped rather than added to.
    replacements: 0,
    scrollTop: 0,
    scrollHeight: 0,
    setAttribute(key, value) { this.attrs[key] = value; },
    getAttribute(key) { return Object.prototype.hasOwnProperty.call(this.attrs, key) ? this.attrs[key] : null; },
    hasAttribute(key) { return Object.prototype.hasOwnProperty.call(this.attrs, key); },
    appendChild(child) { this.children.push(child); return child; },
    // What a re-render uses: the body is swapped in one go, never appended to.
    replaceChildren(...nodes) { this.children = nodes; this.replacements += 1; },
    addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); },
    click() { for (const fn of (this.listeners.click || [])) fn({ preventDefault() {} }); },
    querySelector() { return null; },
    querySelectorAll() { return []; },
  };
  // As in a browser: reading textContent gives the node's text including its
  // descendants, and writing it replaces whatever children were there.
  Object.defineProperty(el, 'textContent', {
    get() {
      if (this.children.length) return this.children.map((child) => child.textContent).join('');
      return text;
    },
    set(value) { text = String(value); this.children = []; },
  });
  return el;
}

const listeners = {};
const byId = new Map();
const document = {
  getElementById(id) {
    if (!byId.has(id)) byId.set(id, makeEl('div'));
    return byId.get(id);
  },
  createElement(tag) { return makeEl(tag); },
  createTextNode(value) {
    const node = makeEl('#text');
    node.textContent = value;
    return node;
  },
  // The kill button's icon is a real SVG, so the page makes it in the SVG
  // namespace; the stub only cares about the tag.
  createElementNS(ns, tag) { return makeEl(tag); },
  querySelectorAll() { return []; },
  addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
  // The page registers its poll on `DOMContentLoaded`; firing it here is what
  // exercises that handler without a browser's event loop.
  fire(type) { for (const fn of (listeners[type] || [])) fn(); },
};

// ---------------------------------------------------------------------------
// What a request carried, and the daemon's answers
// ---------------------------------------------------------------------------
class RecordingFormData {
  constructor() { this.entries = []; }
  append(key, value) { this.entries.push([String(key), String(value)]); }
}

const env = {
  states: [],
  total: 1482,
  urls: [],
  kills: [],
  polls: [],
};

function answer(url, fields) {
  env.urls.push(String(url));
  if (String(url).indexOf('diag/states/kill') !== -1) {
    env.kills.push(fields);
    return { success: true };
  }
  return { states: env.states, total: env.total };
}

const sandbox = {
  document,
  FormData: RecordingFormData,
  console,
  __env: env,
  __check: check,
  // `killSingleState` asks before it acts; the harness lets the click through so
  // the request it builds can be inspected.
  confirm: () => true,
  alert: () => {},
  Math,
  // The page's requests go through the shared scheduler; here it simply answers
  // from `answer()`, and its poller is recorded but not run.
  MicronScheduler: {
    fetch: async (url, options = {}) => {
      const opts = options || {};
      const body = opts.body;
      const fields = body && Array.isArray(body.entries) ? Object.fromEntries(body.entries) : {};
      return { ok: true, json: async () => answer(url, fields) };
    },
    poll: (name) => { env.polls.push(name); return { stop() {} }; },
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
  const body = document.getElementById('statesBody');
  const badge = document.getElementById('stateCountBadge');
  const rows = () => body.children;
  const cells = (i) => (rows()[i] || { children: [] }).children;
  const texts = (i) => cells(i).map((td) => td.textContent);
  const dashed = () => rows().filter((tr) => tr.children.some((td) => td.textContent === '—')).length;
  // Every row's nth cell, read defensively: a detail line that threw while
  // reporting a failure would hide the failure it was reporting.
  const column = (n) => rows().map((tr) => ((tr.children || [])[n] || {}).textContent);
  // Every node inside the body, so a value written as markup anywhere in the
  // table — a nested badge, not just a cell — is caught.
  const markupNodes = () => {
    const found = [];
    const walk = (node) => {
      if (node.innerHTML) found.push(node);
      (node.children || []).forEach(walk);
    };
    walk(body);
    return found;
  };
  const shape = (over) => Object.assign({
    proto: 'tcp', src: '10.0.0.1', sport: 1234, dst: '1.2.3.4', dport: 443,
    state: 'ESTABLISHED', packets: 7, bytes: 700, ttl: '11s',
  }, over);

  // 1. The resolved interface, and the dash fallback.
  renderStates([shape({ iface: 'WAN' })]);
  __check('a resolved interface renders its friendly name', texts(0)[0] === 'WAN', JSON.stringify(texts(0)));
  __check('a resolved interface draws no dash fallback', dashed() === 0, JSON.stringify(texts(0)));

  // An unassigned port keeps its raw device name; the page shows what the
  // controller resolved and does not rewrite it.
  renderStates([shape({ iface: 'eth7' })]);
  __check('an unassigned port renders its raw device name', texts(0)[0] === 'eth7', JSON.stringify(texts(0)));

  renderStates([shape({ iface: '' })]);
  __check('an unresolved interface renders the dash fallback', dashed() === 1, JSON.stringify(texts(0)));

  renderStates([shape({})]);   // no iface key at all
  __check('a state with no interface key renders the dash fallback', dashed() === 1, JSON.stringify(texts(0)));
  __check('an unresolved interface never renders undefined', JSON.stringify(texts(0)).indexOf('undefined') === -1, JSON.stringify(texts(0)));

  // 2. The shape of the row: cells as nodes, badges inside the cells that style
  //    them, and a re-render that swaps rather than piles up.
  renderStates([shape({ iface: 'WAN' })]);
  __check('a row is as many cells as the header declares', cells(0).length === 10, String(cells(0).length));
  __check('the protocol cell holds the badge element that styles it',
    (cells(0)[1].children[0] || {}).className === 'proto-badge proto-tcp', JSON.stringify((cells(0)[1].children[0] || {}).className));

  renderStates([shape({ iface: 'WAN', state: 'TIME_WAIT' })]);
  __check('the state cell holds a pill classed for that state',
    (cells(0)[5].children[0] || {}).className === 'state-pill state-timewait'
      && cells(0)[5].children[0].textContent === 'TIME_WAIT',
    JSON.stringify((cells(0)[5].children[0] || {}).className));

  __check('the table is drawn through the shared builder',
    typeof MicronTable === 'object' && typeof MicronTable.render === 'function', typeof MicronTable);

  const swaps = body.replacements;
  renderStates([shape({ iface: 'WAN' })]);
  renderStates([shape({ iface: 'WAN' })]);
  __check('a re-render swaps the rows rather than adding to them', rows().length === 1, String(rows().length));
  __check('each render swaps the body in one go', body.replacements === swaps + 2, String(body.replacements - swaps));

  // 3. The fetch path.
  __env.states = [shape({ iface: 'LAN' }), shape({ iface: 'WAN', src: '10.0.0.2' }), shape({ iface: '' })];
  await fetchStates();
  await settle();
  __check('the page reads the states endpoint', __env.urls.some((u) => u.indexOf('diag/states/data') !== -1), JSON.stringify(__env.urls));
  __check('the fetched states are rendered by their resolved names',
    texts(0)[0] === 'LAN' && texts(1)[0] === 'WAN', JSON.stringify(column(0)));
  __check('the count badge reflects the fetched states', badge.textContent === '3 Active States', badge.textContent);
  __check('an unattributed fetched flow renders the dash', dashed() === 1, JSON.stringify(column(0)));

  // 4. The search matches the resolved name.
  document.getElementById('stateSearch').value = 'lan';
  document.getElementById('protoFilter').value = '';
  filterStates();
  __check('the search matches a resolved interface name',
    rows().length === 1 && texts(0)[0] === 'LAN', JSON.stringify(column(0)));
  __check('the search hides the states that do not match',
    rows().every((tr) => (tr.children[0] || {}).textContent !== 'WAN'), JSON.stringify(column(0)));

  document.getElementById('stateSearch').value = '';
  filterStates();

  // 5. The escaping, structurally: a value off the wire is a cell's text.
  const markup = '<iframe src=x>';
  const quoted = '10.0.0.1" onmouseover="alert(2)';
  renderStates([shape({ iface: markup, dst: markup, ttl: markup, state: markup, proto: markup, src: quoted, sport: quoted, dport: quoted })]);

  __check('a value that looks like an element is rendered as the cell text',
    texts(0)[0] === markup && texts(0)[2] === quoted + ':' + quoted,
    JSON.stringify(texts(0)));
  __check('an element-shaped value creates no element',
    cells(0)[0].children.length === 0, JSON.stringify(cells(0)[0].children.length));
  __check('the address cell carries no attribute for a quote to break out of',
    JSON.stringify(cells(0)[2].attrs) === '{}' && cells(0)[2].textContent === quoted + ':' + quoted,
    JSON.stringify(cells(0)[2].attrs));
  __check('the table is built from nodes, never from markup', markupNodes().length === 0,
    JSON.stringify(markupNodes().map((n) => String(n.tagName))));

  // 6. The kill button: a node bound to its own row, clicked like an operator
  //    would click it.
  renderStates([shape({ iface: 'WAN', src: quoted, sport: 4321, dst: markup, dport: 8443 })]);
  const killCell = cells(0)[9];
  const killBtn = killCell.children[0] || {};
  __check('the last cell holds the kill button as a node',
    killCell.children.length === 1 && String(killBtn.tagName).toLowerCase() === 'button'
      && killBtn.getAttribute('onclick') === null,
    JSON.stringify(String(killBtn.tagName)));

  __env.kills.length = 0;
  // Clicked only if the cell really holds a button: a build that lost it is
  // reported by the check above rather than crashing this driver, which is the
  // difference between a named failure and a stack trace.
  if (typeof killBtn.click === 'function') killBtn.click();
  await settle();
  const sent = __env.kills[0] || {};
  __check('clicking the row button kills that row, through the endpoint',
    __env.urls.some((u) => u.indexOf('diag/states/kill') !== -1), JSON.stringify(__env.urls));
  __check('the request carries the row addresses as data',
    sent.src === quoted && sent.sport === '4321' && sent.dst === markup && sent.dport === '8443' && sent.proto === 'tcp',
    JSON.stringify(sent));
  __check('the request carries the CSRF token and hash',
    sent.csrf_token_name === 'rendered-hash', JSON.stringify(sent));

  // 7. An absent list renders the one row that says so, rather than throwing.
  let threw = null;
  try { renderStates(undefined); } catch (err) { threw = err; }
  __check('a missing list renders the empty row instead of throwing',
    threw === null && rows().length === 1 && rows()[0].children[0].textContent === 'No matching connection states found.',
    threw ? String(threw) : JSON.stringify(texts(0)));
  __check('the empty row spans every column', rows()[0].children[0].getAttribute('colspan') === '10',
    String(rows()[0].children[0].getAttribute('colspan')));

  // 8. The page still registers its poll with the shared scheduler.
  document.fire('DOMContentLoaded');
  __check('the page registers its poll with the shared scheduler', __env.polls.indexOf('diag.states') !== -1, JSON.stringify(__env.polls));

  __env.done = true;
})();
`;

vm.runInContext(script + driver, sandbox, { filename: VIEW });

// The driver is asynchronous; wait for it to announce that it finished.
for (let i = 0; i < 200 && !env.done; i += 1) {
  await new Promise((resolve) => setTimeout(resolve, 0));
}
if (!env.done) {
  console.error('The states-table driver did not finish; the page script may have thrown.');
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

console.log(`ALL STATES HARNESS CHECKS PASSED (${checks.length} checks)`);
