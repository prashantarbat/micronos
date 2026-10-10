#!/usr/bin/env node
/**
 * Shared table builder DOM harness — the module the live tables are drawn with.
 * ============================================================================
 * `public/assets/js/micronos-table.js` is the one place a row is assembled: a
 * page hands it a container, its rows and a column spec, and gets rows back.
 * The pages that use it (States, SIEM, cron, SNMP) each have their own harness
 * that drives the page; what none of them can check is the module's contract,
 * because they all rely on it behaving the same way for everybody.
 *
 * So this harness reads the shipped module (never a copy — a copy would pass
 * while the shipped file rotted) and drives it directly, on a `document` whose
 * nodes really nest, through the same public surface a page sees:
 *
 *   1. **A cell is text or a node, and there is no third option.** A `text`
 *      column fills its cell with the row's value as text — including a value
 *      that looks like an element, or one carrying a quote — and a `node` column
 *      appends the element the page built. A spec that names `html`/`innerHTML`,
 *      or that names both sources, or neither, is *refused*: that refusal is what
 *      makes markup out of data impossible on every page at once, rather than
 *      something each page has to remember not to do.
 *
 *   2. **The shape of a table.** A row is an element with one cell per column,
 *      the requested row and cell tags, the requested row class, cell styles,
 *      cell attributes; a re-render swaps the body rather than adding to it; an
 *      empty (or absent) list draws the one row that says so; and nothing in any
 *      of it is built from an HTML string.
 *
 *   3. **The two builders every page needs.** `badge` is a span whose class,
 *      style and label are set as a node, with its icon before the text, and
 *      `button` is a row action whose handler is a listener closed over its own
 *      row — so no field of a row is ever written into an attribute.
 *
 * The module is loaded with a `window` and nothing else, exactly as the layout
 * loads it: it must reach `document` through that global, the way it does in a
 * browser, and never through a bare identifier.
 *
 * Run it directly:
 *
 *     node tests/js/table_harness.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..', '..');
const MODULE = 'public/assets/js/micronos-table.js';

const checks = [];
function check(name, cond, detail = '') {
  checks.push({ name, cond: !!cond, detail: String(detail) });
}

// ---------------------------------------------------------------------------
// A DOM just large enough for a table — and one whose nodes really nest, so a
// cell's content can be read off the node that was built rather than off a
// string. `innerHTML` is only ever non-empty if the module assembles markup.
// ---------------------------------------------------------------------------
let created = 0;

function makeEl(tag) {
  created += 1;

  let text = '';
  const el = {
    tagName: String(tag).toUpperCase(),
    className: '',
    title: '',
    type: '',
    innerHTML: '',
    children: [],
    attrs: {},
    style: {},
    listeners: {},
    replacements: 0,
    setAttribute(key, value) { this.attrs[key] = value; },
    getAttribute(key) { return Object.prototype.hasOwnProperty.call(this.attrs, key) ? this.attrs[key] : null; },
    hasAttribute(key) { return Object.prototype.hasOwnProperty.call(this.attrs, key); },
    appendChild(child) { this.children.push(child); return child; },
    // What a re-render uses: the body is swapped in one go, never appended to.
    replaceChildren(...nodes) { this.children = nodes; this.replacements += 1; },
    addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); },
    click() { for (const fn of (this.listeners.click || [])) fn({ preventDefault() {} }); },
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

/** A text node: no element shape at all, just its characters. */
function makeText(value) {
  return {
    tagName: '#text',
    children: [],
    innerHTML: '',
    textContent: String(value),
    getAttribute() { return null; },
  };
}

const document = {
  createElement(tag) { return makeEl(tag); },
  createTextNode(value) { return makeText(value); },
};

// Loaded the way the layout loads it: a `window`, and nothing else in scope. A
// bare `document` reference anywhere in the module would throw here.
const global = { document };
new Function('window', fs.readFileSync(path.join(ROOT, MODULE), 'utf8'))(global);
const builtAtLoad = created;
const MicronTable = global.MicronTable;

const markup = '<iframe src=x>';
const quoted = '10.0.0.1" onmouseover="alert(2)';

/** Every node under `root` that was built from an HTML string. */
function markupNodes(root) {
  const found = [];
  const walk = (node) => {
    if (!node) return;
    if (node.innerHTML) found.push(node);
    (node.children || []).forEach(walk);
  };
  walk(root);

  return found;
}

const container = () => makeEl('tbody');
const cells = (row) => (row || { children: [] }).children;

// ---------------------------------------------------------------------------
// 1. The surface the module publishes
// ---------------------------------------------------------------------------
check('the module publishes itself on the global it was loaded into',
  MicronTable && typeof MicronTable === 'object', typeof MicronTable);
check('the module exposes exactly the three builders a page needs',
  MicronTable && Object.keys(MicronTable).sort().join(',') === 'badge,button,render',
  MicronTable ? Object.keys(MicronTable).sort().join(',') : 'nothing');
check('loading the module builds no cells of its own', builtAtLoad === 0, String(builtAtLoad));

// ---------------------------------------------------------------------------
// 2. A text column: the row's value, as text
// ---------------------------------------------------------------------------
{
  const body = container();
  const rows = MicronTable.render(body, [{ name: 'WAN' }, { name: 'LAN' }], {
    columns: [{ text: 'name' }],
  });

  check('a text column fills its cell with the row field',
    cells(rows[0])[0].textContent === 'WAN' && cells(rows[1])[0].textContent === 'LAN',
    JSON.stringify([rows[0] && cells(rows[0])[0].textContent, rows[1] && cells(rows[1])[0].textContent]));
  check('a row is one element per row, holding its cells',
    rows.length === 2 && String(rows[0].tagName).toLowerCase() === 'tr'
      && String(cells(rows[0])[0].tagName).toLowerCase() === 'td',
    JSON.stringify(String(rows[0] && rows[0].tagName)));
  check('a text cell is a leaf, not a wrapper around markup',
    cells(rows[0])[0].children.length === 0, JSON.stringify(cells(rows[0])[0].children.length));
}

{
  const body = container();
  const rows = MicronTable.render(body, [{ name: markup, dst: quoted }], {
    columns: [{ text: 'name' }, { text: (row) => row.dst }],
  });

  check('a value that looks like an element is the cell text',
    cells(rows[0])[0].textContent === markup, JSON.stringify(cells(rows[0])[0].textContent));
  check('an element-shaped value creates no element',
    cells(rows[0])[0].children.length === 0, JSON.stringify(cells(rows[0])[0].children.length));
  check('a quote in a value cannot break out of an attribute',
    JSON.stringify(cells(rows[0])[0].attrs) === '{}' && cells(rows[0])[1].textContent === quoted,
    JSON.stringify(cells(rows[0])[0].attrs));
  check('a text column accepts a function of the row, read per row',
    cells(rows[0])[1].textContent === quoted, JSON.stringify(cells(rows[0])[1].textContent));
  check('the table is built from nodes, never from markup',
    markupNodes(body).length === 0, JSON.stringify(markupNodes(body).map((n) => String(n.tagName))));
}

{
  const body = container();
  const rows = MicronTable.render(body, [{ name: '' }, { name: null }, {}], {
    columns: [{ text: 'name', fallback: '—' }],
  });

  check('a blank field renders the column fallback',
    cells(rows[0])[0].textContent === '—' && cells(rows[1])[0].textContent === '—',
    JSON.stringify([rows[0] && cells(rows[0])[0].textContent, rows[1] && cells(rows[1])[0].textContent]));
  check('a field the row does not carry renders the fallback, never undefined',
    cells(rows[2])[0].textContent === '—' && cells(rows[2])[0].textContent.indexOf('undefined') === -1,
    JSON.stringify(cells(rows[2])[0].textContent));
  check('a fallback is not used when the field has a value',
    cells(MicronTable.render(body, [{ name: 'eth0' }], { columns: [{ text: 'name', fallback: '—' }] })[0])[0].textContent === 'eth0',
    JSON.stringify(cells(MicronTable.render(body, [{ name: 'eth0' }], { columns: [{ text: 'name', fallback: '—' }] })[0])[0].textContent));
}

// ---------------------------------------------------------------------------
// 3. A node column: the element the page built
// ---------------------------------------------------------------------------
{
  const body = container();
  const chip = makeEl('span');
  chip.className = 'mno-obj-chip';
  chip.textContent = 'QUERY';

  const rows = MicronTable.render(body, [{ level: 'QUERY' }], {
    columns: [
      { text: 'level' },
      { node: () => chip },
      { node: (row) => MicronTable.badge(row.level, 'chip-' + row.level) },
    ],
  });

  check('a node column appends the node the page built',
    cells(rows[0])[1].children.length === 1 && cells(rows[0])[1].children[0] === chip,
    JSON.stringify(cells(rows[0])[1].children.length));
  check("a node column keeps the node's own class and text",
    cells(rows[0])[1].children[0].className === 'mno-obj-chip'
      && cells(rows[0])[1].children[0].textContent === 'QUERY',
    JSON.stringify(cells(rows[0])[1].children[0].className));
  check('a node column is a function of the row',
    cells(rows[0])[2].children[0].className === 'chip-QUERY', JSON.stringify(cells(rows[0])[2].children[0].className));
  check('a text and a node column sit in the same row',
    cells(rows[0]).length === 3 && cells(rows[0])[0].textContent === 'QUERY',
    JSON.stringify(cells(rows[0]).map((c) => c.textContent)));
}

// ---------------------------------------------------------------------------
// 4. The shape of the table
// ---------------------------------------------------------------------------
{
  const body = container();
  const spec = {
    columns: [
      { text: 'iface', class: 'log-time', style: 'color:var(--text-muted);' },
      { text: 'ttl', attrs: (row) => ({ title: row.ttl }) },
      { node: () => MicronTable.badge('OK', 'chip') },
    ],
    rowClass: 'log-row',
  };
  const rows = MicronTable.render(body, [{ iface: 'WAN', ttl: quoted }], spec);

  check('a row is as many cells as the columns declared',
    cells(rows[0]).length === 3, String(cells(rows[0]).length));
  check("a column's class reaches its cell, so a cell is styled by the page's own rules",
    cells(rows[0])[0].className === 'log-time' && cells(rows[0])[2].className === '',
    JSON.stringify([cells(rows[0])[0].className, cells(rows[0])[2].className]));
  check("a column's style reaches its cell",
    cells(rows[0])[0].style.cssText === 'color:var(--text-muted);', JSON.stringify(cells(rows[0])[0].style.cssText));
  check('a row is classed as the page asks', rows[0].className === 'log-row', rows[0].className);
  check("a column's attributes are set on its cell, quote and all",
    cells(rows[0])[1].getAttribute('title') === quoted, JSON.stringify(cells(rows[0])[1].getAttribute('title')));
  check('a cell carries no attribute the page did not ask for',
    JSON.stringify(Object.keys(cells(rows[0])[2].attrs)) === '[]', JSON.stringify(cells(rows[0])[2].attrs));

  const spans = MicronTable.render(body, [{ iface: 'WAN' }], {
    rowTag: 'div', cellTag: 'span', columns: [{ text: 'iface', style: 'min-width:65px;' }],
  });
  check('the row and cell tags are the ones the page asks for',
    String(spans[0].tagName).toLowerCase() === 'div'
      && String(cells(spans[0])[0].tagName).toLowerCase() === 'span',
    JSON.stringify([String(spans[0].tagName), String(cells(spans[0])[0].tagName)]));

  const again = MicronTable.render(body, [{ iface: 'LAN' }], spec);
  check('a re-render swaps the rows rather than adding to them',
    body.children.length === 1 && again.length === 1 && body.replacements === 3,
    JSON.stringify([body.children.length, body.replacements]));
  check('render returns the nodes it built, in row order',
    body.children[0] === again[0], String(body.children[0] === again[0]));
  check('render tolerates a container that is not there',
    JSON.stringify(MicronTable.render(null, [{ iface: 'WAN' }], spec)) === '[]', 'threw or returned rows');
}

// ---------------------------------------------------------------------------
// 5. An empty list
// ---------------------------------------------------------------------------
{
  const body = container();
  const rows = MicronTable.render(body, [], {
    columns: [{ text: 'iface' }],
    empty: { text: 'No matching states found.', colspan: 10, style: 'padding:32px;' },
  });

  check('an empty list draws the one row that says so',
    rows.length === 0 && body.children.length === 1
      && cells(body.children[0])[0].textContent === 'No matching states found.',
    JSON.stringify([body.children.length, body.children[0] && body.children[0].textContent]));
  check('the empty row spans every column',
    cells(body.children[0])[0].getAttribute('colspan') === '10',
    JSON.stringify(cells(body.children[0])[0].getAttribute('colspan')));
  check("the empty row's style reaches its cell",
    cells(body.children[0])[0].style.cssText === 'padding:32px;',
    JSON.stringify(cells(body.children[0])[0].style.cssText));

  const log = container();
  const absent = MicronTable.render(log, undefined, {
    rowTag: 'div', cellTag: 'span',
    columns: [{ text: 'message' }],
    empty: { style: 'text-align:center;', text: 'No SNMP log entries recorded.' },
  });

  check('a missing list draws the empty row instead of throwing',
    absent.length === 0 && log.children.length === 1
      && String(log.children[0].tagName).toLowerCase() === 'div'
      && String(cells(log.children[0])[0].tagName).toLowerCase() === 'span',
    JSON.stringify([log.children.length, log.children[0] && String(log.children[0].tagName)]));
  check('the empty text is a cell of the node the page asked for',
    cells(log.children[0])[0].textContent === 'No SNMP log entries recorded.',
    JSON.stringify(cells(log.children[0])[0].textContent));
}

// ---------------------------------------------------------------------------
// 6. The refusals — there is no way to hand the module markup
// ---------------------------------------------------------------------------
{
  const refused = (columns) => {
    const body = container();
    let error = null;
    try { MicronTable.render(body, [{ name: 'WAN' }], { columns }); } catch (err) { error = err; }

    return { error, body };
  };

  const asMarkup = refused([{ html: 'name' }]);
  check('a column naming html is refused',
    asMarkup.error instanceof Error, asMarkup.error ? 'accepted' : String(asMarkup.error));
  check('a column naming innerHTML is refused',
    refused([{ innerHTML: 'name' }]).error instanceof Error, 'accepted');
  check('a column naming both a text and a node is refused',
    refused([{ text: 'name', node: () => MicronTable.badge('x', 'c') }]).error instanceof Error, 'accepted');
  check('a column naming neither a text nor a node is refused',
    refused([{ style: 'x' }]).error instanceof Error, 'accepted');
  check('a column that is not an object is refused',
    refused(['name']).error instanceof Error, 'accepted');
  check('a spec with no columns is refused',
    (() => { try { MicronTable.render(container(), [], {}); return null; } catch (err) { return err; } })() instanceof Error,
    'accepted');
  check('a refusal names the module and the column it refused',
    /MicronTable/.test(asMarkup.error.message) && /0/.test(asMarkup.error.message)
      && /html/.test(asMarkup.error.message),
    asMarkup.error.message);
  check('a refused spec builds nothing at all',
    asMarkup.body.children.length === 0 && asMarkup.body.replacements === 0,
    JSON.stringify([asMarkup.body.children.length, asMarkup.body.replacements]));
}

// ---------------------------------------------------------------------------
// 7. A badge: a node, class and label, never markup
// ---------------------------------------------------------------------------
{
  const plain = MicronTable.badge(markup, 'badge badge-error', 'font-size:0.65rem;');
  check('a badge is a span with the class the page gave it',
    String(plain.tagName).toLowerCase() === 'span' && plain.className === 'badge badge-error',
    JSON.stringify(plain.className));
  check("a badge carries the page's style",
    plain.style.cssText === 'font-size:0.65rem;', JSON.stringify(plain.style.cssText));
  check('a badge label that looks like markup is the badge text',
    plain.textContent === markup && plain.children.length === 1
      && String(plain.children[0].tagName) === '#text' && markupNodes(plain).length === 0,
    JSON.stringify(String(plain.children[0] && plain.children[0].tagName)));

  const icon = makeEl('svg');
  const withIcon = MicronTable.badge('DELIVERED', 'badge badge-ok', null, icon);
  check('a badge draws its icon before its label',
    withIcon.children.length === 2 && withIcon.children[0] === icon
      && withIcon.children[1].textContent === 'DELIVERED' && withIcon.textContent === 'DELIVERED',
    JSON.stringify(withIcon.children.map((c) => String(c.tagName))));
  check('a badge with no icon holds only its label',
    plain.children.length === 1, String(plain.children.length));
  check('a badge with no label still is a badge',
    MicronTable.badge(undefined, 'chip').textContent === '' && MicronTable.badge(undefined, 'chip').className === 'chip',
    JSON.stringify(MicronTable.badge(undefined, 'chip').textContent));
}

// ---------------------------------------------------------------------------
// 8. A button: a listener closed over its own row, never an attribute
// ---------------------------------------------------------------------------
{
  const body = container();
  const clicked = [];
  const rows = MicronTable.render(body, [{ src: quoted }, { src: '10.0.0.2' }], {
    columns: [{
      node: (row) => MicronTable.button('Kill', {
        className: 'btn btn-ghost btn-sm',
        style: 'color:var(--color-red);',
        title: 'Kill State',
        icon: makeEl('svg'),
        onClick: () => clicked.push(row.src),
      }),
    }],
  });

  const first = cells(rows[0])[0].children[0];
  check('a row action is a type=button node with the class given',
    String(first.tagName).toLowerCase() === 'button' && first.type === 'button'
      && first.className === 'btn btn-ghost btn-sm',
    JSON.stringify([String(first.tagName), first.type, first.className]));
  check('a row action carries its title and style, and its icon before its label',
    first.title === 'Kill State' && first.style.cssText === 'color:var(--color-red);'
      && first.children[0].tagName === 'SVG' && first.children[1].textContent === 'Kill',
    JSON.stringify([first.title, first.children.map((c) => String(c.tagName))]));
  check('a row action writes no handler into an attribute',
    first.getAttribute('onclick') === null && JSON.stringify(first.attrs) === '{}',
    JSON.stringify(first.attrs));

  cells(rows[1])[0].children[0].click();
  check("clicking a row's button runs the handler closed over its own row",
    clicked.length === 1 && clicked[0] === '10.0.0.2', JSON.stringify(clicked));
  check('the row that was clicked is not read out of the node',
    first.getAttribute('onclick') === null && cells(rows[0])[0].children[0] === first,
    JSON.stringify(first.attrs));

  const bare = MicronTable.button('');
  check('a button with no options is still a button',
    String(bare.tagName).toLowerCase() === 'button' && bare.type === 'button' && bare.textContent === '',
    JSON.stringify(String(bare.tagName)));
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

console.log(`ALL TABLE HARNESS CHECKS PASSED (${checks.length} checks)`);
