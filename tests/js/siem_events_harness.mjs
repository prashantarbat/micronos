#!/usr/bin/env node
/**
 * SIEM event-table DOM harness — the page's stream table, driven in Node.
 * ============================================================================
 * `app/Views/siem/index.php` draws the SIEM event stream: it reads `siem/logs`
 * and renders one row per shipped event — when it was seen, which subsystem
 * raised it, the action, the source and destination, the matching rule and
 * whether the forwarder delivered it. The PHP suite pins the endpoint and its
 * answer; what PHP cannot see is the row actually being built out of it.
 *
 * So this harness runs the page's own script. It reads the shipped view (never a
 * copy — a copy would pass while the shipped code rotted), gives it a `document`
 * whose nodes really nest, and drives:
 *
 *   1. **The row.** The answer becomes one row per event, with the timestamp,
 *      subsystem, route and rule as the cells' *text*, the action in the badge
 *      classed for it, and the delivery status in its badge beside the check mark.
 *
 *   2. **The escaping, structurally.** Every field is filled from the daemon's
 *      answer, and one of them — the action — used to be interpolated straight
 *      into its badge's markup. The harness feeds it values that look like markup
 *      and asserts on the nodes: the value is the badge's/cell's text, nothing
 *      shaped like an element became one, and no cell is built from an HTML string.
 *
 *   3. **The Inspect button.** It is a node bound to its own event through a
 *      closure rather than an index baked into an `onclick` attribute, which the
 *      harness pins by clicking it and reading the modal it opened.
 *
 * Run it directly:
 *
 *     node tests/js/siem_events_harness.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..', '..');
const VIEW = 'app/Views/siem/index.php';

// The shared table builder, loaded from the layout before any page script.
const TABLE = 'public/assets/js/micronos-table.js';
const tableSource = fs.readFileSync(path.join(ROOT, TABLE), 'utf8');

const checks = [];
function check(name, cond, detail = '') {
  checks.push({ name, cond: !!cond, detail: String(detail) });
}

// The page's <script>, with the PHP echoes reduced to inert text so the body is
// valid JavaScript. The script is otherwise byte-for-byte the shipped one. The
// values are substituted bare because the view already quotes each echo
// (`MicronScheduler.fetch('<?= base_url('siem/logs') ?>')`).
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
    // Only ever non-empty if a view assembles markup — which this one must not.
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
    // What a re-render uses: the body is swapped in one go, never appended to.
    replaceChildren(...nodes) {
      this.childNodes = nodes;
      this.children = nodes.filter((node) => node.nodeType !== TEXT_NODE);
      this.replacements += 1;
    },
    addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); },
    click() { for (const fn of (this.listeners.click || [])) fn({ preventDefault() {} }); },
    querySelector() { return null; },
    querySelectorAll() { return []; },
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
  // The row's check mark is a real SVG, so the page makes it in the SVG
  // namespace; the stub only cares about the tag.
  createElementNS(ns, tag) { return makeEl(tag); },
  createTextNode(text) { return makeTextNode(text); },
  querySelectorAll() { return []; },
  addEventListener() {},
};

// ---------------------------------------------------------------------------
// The daemon's answers, which the harness controls
// ---------------------------------------------------------------------------
const env = {
  events: [],
  target: 'splunk-hf.company.internal',
  format: 'CEF',
  urls: [],
};

function answer(url) {
  env.urls.push(String(url));
  return { events: env.events, target: env.target, format: env.format };
}

const sandbox = {
  document,
  console,
  alert: () => {},
  __env: env,
  __check: check,
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

// The page draws its rows with the shared builder, loaded from the layout. The
// harness loads the same shipped file into the sandbox for the same reason it
// reads the shipped view: the page cannot build a row without it.
vm.runInContext(tableSource, sandbox, { filename: TABLE });

// ---------------------------------------------------------------------------
// The driver, run in the page's own scope so it can reach the page's functions
// ---------------------------------------------------------------------------
const driver = `
;(async () => {
  const settle = () => new Promise((r) => setTimeout(r, 0));
  const body = document.getElementById('siemStreamBody');
  const rows = () => body.children;
  const cells = (i) => (rows()[i] || { children: [] }).children;
  const texts = (i) => cells(i).map((td) => td.textContent);
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
  const ev = (over) => Object.assign({
    timestamp: '2026-10-08 14:02:11', subsystem: 'firewall', action: 'PASS', src: '10.0.0.5',
    dst: '198.51.100.9', rule: 'allow-lan-wan', status: 'DELIVERED', raw: 'raw payload text',
  }, over);

  // 1. The row.
  __check('the table is drawn through the shared builder',
    typeof MicronTable === 'object' && typeof MicronTable.render === 'function', typeof MicronTable);

  const swaps = body.replacements;
  renderSiemEvents([ev({})]);
  __check('drawing the table swaps its body in one go', body.replacements === swaps + 1, String(body.replacements - swaps));
  __check('the answer becomes one row per event', rows().length === 1, String(rows().length));
  __check('a row is as many cells as the header declares', cells(0).length === 7, String(cells(0).length));
  __check('the wire fields are the cells text',
    texts(0)[0] === '2026-10-08 14:02:11' && texts(0)[1] === 'firewall' && texts(0)[4] === 'allow-lan-wan',
    JSON.stringify(texts(0)));
  __check('the source and destination share one cell, as text',
    texts(0)[3] === '10.0.0.5 → 198.51.100.9', JSON.stringify(texts(0)[3]));

  __check('the action is a badge classed for it',
    (cells(0)[2].children[0] || {}).className === 'badge badge-success'
      && cells(0)[2].textContent === 'PASS',
    JSON.stringify((cells(0)[2].children[0] || {}).className));
  renderSiemEvents([ev({ action: 'DROP' })]);
  __check('a drop is the error badge', (cells(0)[2].children[0] || {}).className === 'badge badge-error',
    JSON.stringify((cells(0)[2].children[0] || {}).className));

  __check('the delivery status is the badge text beside its check mark',
    (cells(0)[5].children[0] || {}).children.length === 1 && cells(0)[5].textContent === 'DELIVERED',
    JSON.stringify(cells(0)[5].textContent));
  renderSiemEvents([ev({ status: '' })]);
  __check('a missing status falls back to DELIVERED', cells(0)[5].textContent === 'DELIVERED',
    JSON.stringify(cells(0)[5].textContent));

  // 2. The escaping, structurally: a value off the wire is a node's text.
  const markup = '<img src=x onerror=alert(1)>';
  renderSiemEvents([ev({ subsystem: markup, action: markup, rule: markup, status: markup, src: markup, dst: markup })]);
  const row = texts(0);
  __check('a subsystem that looks like an element is the cell text', row[1] === markup, JSON.stringify(row[1]));
  __check('an action that looks like an element is the badge text',
    cells(0)[2].textContent === markup && ((cells(0)[2].children[0] || {}).children || []).length === 0,
    JSON.stringify(cells(0)[2].textContent));
  __check('the table is built from nodes, never from markup', markupNodes().length === 0,
    JSON.stringify(markupNodes().map((n) => String(n.tagName))));
  __check('the route cell keeps the markup-shaped endpoints as text',
    row[3] === markup + ' → ' + markup, JSON.stringify(row[3]));

  // 3. A re-render swaps the rows rather than adding to them.
  renderSiemEvents([ev({})]);
  renderSiemEvents([ev({}), ev({ subsystem: 'ids' })]);
  __check('a re-render swaps the rows rather than adding to them', rows().length === 2, String(rows().length));

  // 4. The Inspect button, clicked like an operator would click it.
  renderSiemEvents([ev({ subsystem: 'firewall', action: 'ALERT', raw: 'the raw event body' })]);
  const inspectCell = cells(0)[6];
  const btn = inspectCell.children[0] || {};
  __check('the last cell holds the Inspect button as a node',
    inspectCell.children.length === 1 && String(btn.tagName).toLowerCase() === 'button'
      && btn.getAttribute('onclick') === null && btn.textContent === 'Inspect',
    JSON.stringify(String(btn.tagName)));

  btn.click();
  __check('clicking Inspect opens the modal for that row event',
    document.getElementById('eventModal').style.display === 'flex'
      && document.getElementById('modalEventMeta').innerText === 'firewall | ALERT | 2026-10-08 14:02:11'
      && document.getElementById('modalEventContent').innerText === 'the raw event body',
    document.getElementById('modalEventMeta').innerText + ' / ' + document.getElementById('modalEventContent').innerText);

  // 5. An absent list renders the one row that says so.
  let threw = null;
  try { renderSiemEvents(undefined); } catch (err) { threw = err; }
  __check('a missing list renders the empty row instead of throwing',
    threw === null && rows().length === 1
      && rows()[0].children[0].textContent.indexOf('No SIEM events recorded yet') === 0,
    threw ? String(threw) : JSON.stringify(texts(0)));
  __check('the empty row spans every column', rows()[0].children[0].getAttribute('colspan') === '7',
    String(rows()[0].children[0].getAttribute('colspan')));

  // 6. The fetch path, and the single stream read the page makes.
  __env.events = [ev({ subsystem: 'ids' }), ev({ subsystem: 'vpn', action: 'AUTH' })];
  document.getElementById('panel-stream').classList.contains = () => true;
  await fetchSiemLogs();
  await settle();
  __check('the page reads the SIEM logs endpoint', __env.urls.indexOf('siem/logs') !== -1, JSON.stringify(__env.urls));
  __check('the fetched events are rendered through the builder',
    rows().length === 2 && texts(1)[1] === 'vpn', JSON.stringify(column(1)));
  __check('the stream target badge names where the events are shipped',
    document.getElementById('streamTargetBadge').innerText === 'splunk-hf.company.internal (CEF)',
    document.getElementById('streamTargetBadge').innerText);

  __env.done = true;
})();
`;

vm.runInContext(script + driver, sandbox, { filename: VIEW });

// The driver is asynchronous; wait for it to announce that it finished.
for (let i = 0; i < 200 && !env.done; i += 1) {
  await new Promise((resolve) => setTimeout(resolve, 0));
}
if (!env.done) {
  console.error('The SIEM event-table driver did not finish; the page script may have thrown.');
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

console.log(`ALL SIEM EVENTS HARNESS CHECKS PASSED (${checks.length} checks)`);
