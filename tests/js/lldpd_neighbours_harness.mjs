#!/usr/bin/env node
/**
 * lldpd neighbour-table DOM harness — the page's neighbour view, driven in Node.
 * ============================================================================
 * `app/Views/plugins/apps/lldpd.php` renders the neighbour table: it asks the
 * daemon for structured rows (`plugin.lldpd.neighbours` with `format=json`),
 * draws them as a sortable table, and falls back to the raw `lldpcli` listing
 * when no table can be built. The PHP suite pins the *wiring* — that the route
 * forwards the format, that the page carries the table markup. What PHP cannot
 * see is the table actually sorting, or the fallback actually happening.
 *
 * So this harness runs the page's own script. It reads the shipped view (never a
 * copy — a copy would pass while the shipped code rotted), gives it a
 * hand-written `document` and a `MicronScheduler` whose answers the harness
 * controls, and drives:
 *
 *   1. **The table.** Structured rows become one row per neighbour, and clicking
 *      a column sorts it — ascending, then descending, with a TTL compared as a
 *      number rather than a string, and blanks staying last in either direction.
 *
 *   2. **The fallback.** When the structured rows cannot be built (the daemon
 *      refuses, or it answers with none) the page shows the listing exactly as
 *      `lldpcli` prints it, and the toggle moves between the two.
 *
 *   3. **Untrusted input.** A neighbour's name comes off the wire, so it is
 *      rendered as text; a chassis name that looks like markup must not become
 *      markup.
 *
 * Run it directly:
 *
 *     node tests/js/lldpd_neighbours_harness.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..', '..');
const VIEW = 'app/Views/plugins/apps/lldpd.php';

const checks = [];
function check(name, cond, detail = '') {
  checks.push({ name, cond: !!cond, detail: String(detail) });
}

// The page's <script>, with the PHP echoes reduced to an inert `0` so the body
// is valid JavaScript. The script is otherwise byte-for-byte the shipped one.
const php = fs.readFileSync(path.join(ROOT, VIEW), 'utf8');
const scriptMatch = php.match(/<script>([\s\S]*?)<\/script>/);
if (!scriptMatch) {
  console.error(`No <script> block was found in ${VIEW}.`);
  process.exit(1);
}
// Every `<?= ... ?>` in this view echoes a URL or the CSRF hash, so each becomes
// a quoted placeholder — a string, which is what the page's code expects to
// concatenate a query onto.
const script = scriptMatch[1]
  .replace(/<\?php[\s\S]*?\?>/g, '')
  .replace(/<\?=[\s\S]*?\?>/g, '"x"');

// ---------------------------------------------------------------------------
// A DOM just large enough for this page
// ---------------------------------------------------------------------------
function makeEl(tag) {
  let text = '';
  const el = {
    tagName: tag,
    className: '',
    hidden: false,
    title: '',
    value: '',
    checked: false,
    innerHTML: '',
    dataset: {},
    children: [],
    attrs: {},
    appendChild(child) { this.children.push(child); return child; },
    setAttribute(key, value) { this.attrs[key] = value; },
    getAttribute(key) { return Object.prototype.hasOwnProperty.call(this.attrs, key) ? this.attrs[key] : null; },
    removeAttribute(key) { delete this.attrs[key]; },
    querySelector() { return null; },
    addEventListener() {},
  };
  // Setting textContent to '' is how the page clears a container before it
  // rebuilds it, so the stub must drop the children too.
  Object.defineProperty(el, 'textContent', {
    get() { return text; },
    set(value) { text = value; if (value === '') this.children = []; },
  });
  return el;
}

const byId = new Map();
const document = {
  getElementById(id) {
    if (!byId.has(id)) byId.set(id, makeEl('div'));
    return byId.get(id);
  },
  createElement(tag) { return makeEl(tag); },
  querySelectorAll() { return headers; },
};

// The five sortable headers, each with its caret, as the markup declares them.
const headers = ['interface', 'chassis', 'port', 'mgmt_ip', 'ttl'].map((key) => {
  const th = makeEl('th');
  th.attrs['data-key'] = key;
  const caret = makeEl('span');
  caret.className = 'ld-caret';
  th.querySelector = () => caret;
  return th;
});

// ---------------------------------------------------------------------------
// The daemon's answers, which the harness controls
// ---------------------------------------------------------------------------
const RAW_LISTING = 'LLDP neighbors:\n  eth1 -> core-sw1 Gi0/12\n';

const env = {
  mode: 'rows',
  rows: [
    { interface: 'eth1', chassis: 'core-sw1', port: 'Gi0/12', mgmt_ip: '10.0.0.2', ttl: '120', chassis_id: 'aa:bb:cc:dd:ee:ff', age: '0 day, 00:00:07', via: 'LLDP' },
    { interface: 'eth0', chassis: 'edge-sw2', port: 'Gi0/1', mgmt_ip: '10.0.0.9', ttl: '95' },
    { interface: 'eth2', chassis: 'mid-sw3', port: 'Ei1', mgmt_ip: null, ttl: '1200' },
  ],
  urls: [],
};

function answer(rawUrl) {
  const url = String(rawUrl);
  env.urls.push(url);

  if (url.includes('format=json')) {
    if (env.mode === 'fail') {
      return { ok: false, message: 'the daemon rejected the request', csrf: 'c',
               result: { success: false, warnings: ['could not read the neighbour table'] } };
    }
    if (env.mode === 'empty') {
      return { ok: true, csrf: 'c',
               result: { success: true, warnings: ['no LLDP neighbours are currently visible'], data: { ports: [], truncated: false } } };
    }
    return { ok: true, csrf: 'c', result: { success: true, warnings: [], data: { ports: env.rows, truncated: false } } };
  }

  if (url.includes('format=text')) {
    return { ok: true, csrf: 'c', result: { success: true, warnings: [], data: { neighbours: RAW_LISTING, truncated: false } } };
  }

  // Anything else is the status read the page makes on load.
  return { ok: true, csrf: 'c', result: { success: true, data: { service_state: 'running' } } };
}

const sandbox = {
  document,
  console,
  __env: env,
  __check: check,
  // The page's own requests go through the shared scheduler; here it simply
  // answers from `answer()`. `url` is the arg the page passed.
  MicronScheduler: { fetch: async (url) => ({ json: async () => answer(url) }) },
  setTimeout,
  encodeURIComponent,
};
vm.createContext(sandbox);

// ---------------------------------------------------------------------------
// The driver, run in the page's own scope so it can reach the page's functions
// ---------------------------------------------------------------------------
const driver = `
;(async () => {
  const settle = () => new Promise((r) => setTimeout(r, 0));
  await settle(); await settle();   // let the page's own load-time read finish

  const wrap = document.getElementById('ldNeighboursTableWrap');
  const rawWrap = document.getElementById('ldRawWrap');
  const body = document.getElementById('ldNeighboursBody');
  const pre = document.getElementById('ldNeighbours');
  const note = document.getElementById('ldNeighboursNote');
  const rows = () => body.children.map((tr) => tr.children.map((td) => td.textContent));
  const col = (i) => rows().map((r) => r[i]);
  const head = (key) => document.querySelectorAll('#ldNeighboursTable th').find((th) => th.getAttribute('data-key') === key);

  __check('the structured rows render one table row per neighbour', rows().length === 3, JSON.stringify(rows()));
  __check('the table is shown and the raw listing is hidden', wrap.hidden === false && rawWrap.hidden === true, 'table=' + wrap.hidden + ' raw=' + rawWrap.hidden);
  __check('the table opens sorted by local port', col(0).join(',') === 'eth0,eth1,eth2', col(0).join(','));
  __check('the sorted column is marked for assistive tech', head('interface').getAttribute('aria-sort') === 'ascending', String(head('interface').getAttribute('aria-sort')));

  ldNbSort('chassis');
  __check('clicking a header sorts by it ascending', col(1).join(',') === 'core-sw1,edge-sw2,mid-sw3', col(1).join(','));
  ldNbSort('chassis');
  __check('clicking it again reverses the order', col(1).join(',') === 'mid-sw3,edge-sw2,core-sw1', col(1).join(','));
  __check('the descending column points its caret down', head('chassis').querySelector('.ld-caret').textContent === '▼', head('chassis').querySelector('.ld-caret').textContent);

  ldNbSort('ttl');
  __check('a TTL sorts as a number, not as text', col(4).join(',') === '95,120,1200', col(4).join(','));
  ldNbSort('ttl');
  __check('the TTL sorts downwards too', col(4).join(',') === '1200,120,95', col(4).join(','));

  ldNbSort('mgmt_ip');
  __check('an interface with no management address sorts last', col(3).join(',') === '10.0.0.2,10.0.0.9,—', col(3).join(','));
  ldNbSort('mgmt_ip');
  __check('a blank stays last when the sort is reversed', col(3).join(',') === '10.0.0.9,10.0.0.2,—', col(3).join(','));

  const before = col(0).join(',');
  ldNbSort('not-a-column');
  __check('an unknown column name does not disturb the order', col(0).join(',') === before, col(0).join(','));

  __check('the structured read asked for the json format', __env.urls.some((u) => u.indexOf('format=json') !== -1), JSON.stringify(__env.urls));

  // 2. The fallback: the daemon refuses the structured read.
  __env.mode = 'fail';
  await ldNeighbours();
  __check('a refused structured read falls back to the raw listing', rawWrap.hidden === false && wrap.hidden === true, 'table=' + wrap.hidden + ' raw=' + rawWrap.hidden);
  __check('the raw listing is the lldpcli output', pre.textContent.indexOf('core-sw1') !== -1, pre.textContent);
  __check('the fallback read asked for the text format', __env.urls.filter((u) => u.indexOf('format=text') !== -1).length >= 1, JSON.stringify(__env.urls));
  __check('the daemon refusal explains the raw fallback', /showing the raw listing/.test(note.textContent), note.textContent);

  // The fallback: the table is well-formed but empty.
  __env.mode = 'empty';
  await ldNeighbours();
  __check('an empty structured table also falls back', rawWrap.hidden === false && wrap.hidden === true, 'table=' + wrap.hidden + ' raw=' + rawWrap.hidden);
  __check('the page says why the raw listing is shown', /No neighbours were parsed/.test(note.textContent), note.textContent);

  // 3. The toggle moves between the two views.
  __env.mode = 'rows';
  await ldNeighbours();
  __check('a structured read puts the table back', wrap.hidden === false && rawWrap.hidden === true, 'table=' + wrap.hidden + ' raw=' + rawWrap.hidden);
  await ldToggleNeighboursView();
  __check('the toggle reveals the raw listing', rawWrap.hidden === false && wrap.hidden === true, 'table=' + wrap.hidden + ' raw=' + rawWrap.hidden);
  await ldToggleNeighboursView();
  __check('the toggle returns to the table', wrap.hidden === false && rawWrap.hidden === true, 'table=' + wrap.hidden + ' raw=' + rawWrap.hidden);

  // 4. A neighbour name comes off the wire and must not become markup.
  __env.rows = [{ interface: 'eth9', chassis: '<img src=x onerror=alert(1)>', port: 'p9', mgmt_ip: '1.2.3.4', ttl: '1' }];
  await ldNeighbours();
  const cells = rows();
  __check('a chassis name that looks like markup is rendered as text', cells.length === 1 && cells[0][1] === '<img src=x onerror=alert(1)>', JSON.stringify(cells));
  __check('the table body is never built from markup', body.innerHTML === '', JSON.stringify(body.innerHTML));

  __env.done = true;
})();
`;

vm.runInContext(script + driver, sandbox, { filename: VIEW });

// The driver is asynchronous; wait for it to announce that it finished.
for (let i = 0; i < 200 && !env.done; i += 1) {
  await new Promise((resolve) => setTimeout(resolve, 0));
}
if (!env.done) {
  console.error('The neighbour-table driver did not finish; the page script may have thrown.');
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

console.log(`ALL LLDPD NEIGHBOUR HARNESS CHECKS PASSED (${checks.length} checks)`);
