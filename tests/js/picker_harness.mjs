#!/usr/bin/env node
/**
 * Picker DOM harness — the shared object picker, driven in Node.
 * ============================================================
 * `app/Views/partials/picker_search.php` is where every object picker on the
 * appliance gets its behaviour: type to filter, arrows to walk, Space to toggle,
 * Enter to add, Escape to clear — and, for a catalogue too long to render, asking
 * the server for one page at a time. The PHP suite pins the *wiring* on each
 * screen and the endpoint's answers; what it cannot see is the script running.
 *
 * So this harness runs it. It reads the `<script>` block out of the view (never
 * a copy — a copy would pass while the shipped code rotted), gives it a DOM just
 * large enough to hold a `<select multiple>`, and drives it against synthetic
 * catalogues. Two things are asserted:
 *
 *   1. **Behaviour.** Filtering (case-insensitive over the row's text *and* its
 *      value, with a group heading matching too), the sentinel that survives
 *      every query, the keyboard contract (↑/↓ walk only visible rows, Space
 *      toggles only once armed, Enter adds without clearing, Escape clears the
 *      query — and Enter/arrows/Escape are swallowed so they cannot submit the
 *      dialog), `change` being the one event a page syncs from, and the
 *      server-backed path: one page rendered, the debounced request carrying the
 *      right params, paging on scroll and on walking off the end, picked values
 *      resolved back into the list, and a failed request keeping them. A box
 *      configured as a *local* list is covered both ways round as well: one that
 *      holds the whole catalogue stays local and asks for nothing, and one that
 *      is really a page of a bigger catalogue takes over the endpoint the markup
 *      declares and pages from there.
 *
 *   2. **A filtering time budget.** A keystroke has to fit in a frame, and it
 *      has to stay a small, bounded number of passes over the catalogue rather
 *      than a rescan of it per row. The catalogue is synthetic and large on
 *      purpose — 5000 rows, about what a busy appliance holds — and the passes
 *      figure is measured against a reference pass on the same machine in the
 *      same run, so the budget says the same thing locally and in CI.
 *
 * The DOM is hand-written rather than jsdom: this repository ships no JavaScript
 * dependencies and installs none, and the picker touches a small, known surface
 * (`getElementById`, `children`, `options`, `classList`, `dataset`, geometry…).
 *
 * Two mirrors of server-side or view-side code live here, and both are
 * deliberate:
 *
 *   - the first page rendered into a select mirrors `partials/pick_select.php`
 *     (sentinel first, one optgroup per group, the `data-picker-*` the picker
 *     reads) — pinned against the real output by `ObjectSearchEndpointTest`;
 *   - the `fetch` stub mirrors `ObjectSearch::search()`'s contract
 *     (`{items, selected, total, page, per, more}`) — pinned against the real
 *     endpoint by `ObjectSearchEndpointTest` and `ObjectPickerSearchTest`.
 *
 * Run it directly:
 *
 *     node tests/js/picker_harness.mjs
 *
 * …or through the project's test run, which is how CI sees it:
 * `tests/PickerDomHarnessTest.php` executes this file and fails the build unless
 * it exits 0 and prints the success marker below.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { performance } from 'node:perf_hooks';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = join(HERE, '..', '..');
const PICKER_VIEW = join(ROOT, 'app', 'Views', 'partials', 'picker_search.php');

/** Printed when nothing failed; `PickerDomHarnessTest` asserts on it. */
const SUCCESS_MARKER = 'ALL PICKER DOM HARNESS CHECKS PASSED';

/** The highlight class the picker paints on the cursored row (public/css/micronos.css). */
const CURSOR = 'mno-pick-cursor';

/** The sentinel value the local fixtures declare, and the row they render for it. */
const ANY = 'all';
const SENTINEL = { value: ANY, label: 'all (0.0.0.0/0)' };

/**
 * The list box's geometry in the shim. `revealCursor()` reads the box and the
 * row rects, so the shim models a real listbox scroller: a fixed row height, a
 * viewport of `VISIBLE_ROWS` rows, and a `scrollTop` the picker moves.
 */
const ROW_HEIGHT = 18;
const VISIBLE_ROWS = 6;

/**
 * The keystroke budget.
 *
 * `rows` is the size of the synthetic catalogue; `keystrokeMs` is one 60 Hz
 * frame, which is the budget that actually matters — a keystroke that overruns
 * it drops a frame while the operator is typing. The typing sequence walks a
 * query down and back up, because the expensive direction is a *change* of
 * visibility either way (narrowing hides thousands of rows; widening shows them
 * again), and the median across every sampled keystroke is what is asserted: a
 * single scheduler hiccup cannot drag the median over the budget, while a real
 * regression moves every sample.
 *
 * The second, sharper budget is `passes`: how many passes of the catalogue's
 * per-row string work a keystroke costs. Both the keystroke and the reference
 * pass are measured on the same machine in the same run, so the ratio cancels a
 * CI runner out of the check — which is what lets it be tight enough to catch a
 * *constant-factor* regression, not only a catastrophic one. It is currently
 * under two passes; re-stringing each row per keystroke, the regression the
 * search index exists to prevent, costs about fourteen, and scanning the option
 * collection once per row costs about thirty.
 */
const BUDGET = {
  rows: 5000,
  repeats: 40,
  keystrokeMs: 16,
  passes: 6,
  queries: ['row-1234', 'row-123', 'row-12', 'row-1', 'row-1234', 'row-12', 'row-1', 'row-1234'],
};

// ---------------------------------------------------------------------------
// Reporting
// ---------------------------------------------------------------------------

let checks = 0;
const failures = [];

function check(name, ok, detail = '') {
  checks++;
  if (!ok) failures.push(name + (detail !== '' ? `  [${detail}]` : ''));
  console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${detail !== '' ? `  [${detail}]` : ''}`);
}

/** Value equality good enough for the small structures asserted here. */
function same(actual, expected) {
  return JSON.stringify(actual) === JSON.stringify(expected);
}

// ---------------------------------------------------------------------------
// The picker script, read out of the view it ships in
// ---------------------------------------------------------------------------

function loadPickerSource() {
  const php = readFileSync(PICKER_VIEW, 'utf8');

  const opens = php.split('<script').length - 1;
  const closes = php.split('</script>').length - 1;

  if (opens !== 1 || closes !== 1) {
    throw new Error(
      `${PICKER_VIEW} must hold exactly one inline <script> (found ${opens} open, ${closes} close); ` +
        'the harness drives the script in this view, so a second block would be silently ignored.'
    );
  }

  const script = /<script>([\s\S]*?)<\/script>/.exec(php);

  if (!script || script[1].trim() === '') {
    throw new Error(`${PICKER_VIEW} has no readable inline <script> body`);
  }

  if (!script[1].includes('window.MicronPickerSearch')) {
    throw new Error(`${PICKER_VIEW} no longer defines window.MicronPickerSearch`);
  }

  return script[1];
}

const PICKER_SOURCE = loadPickerSource();

// ---------------------------------------------------------------------------
// The DOM shim
// ---------------------------------------------------------------------------

class ClassList {
  constructor() {
    this._classes = new Set();
  }

  add(...names) {
    for (const name of names) this._classes.add(name);
  }

  remove(...names) {
    for (const name of names) this._classes.delete(name);
  }

  contains(name) {
    return this._classes.has(name);
  }

  /** `status()` uses the two-argument form to add *or* remove a state class. */
  toggle(name, force) {
    const on = force === undefined ? !this._classes.has(name) : !!force;
    if (on) this._classes.add(name);
    else this._classes.delete(name);

    return on;
  }

  get value() {
    return [...this._classes].join(' ');
  }
}

/**
 * The picker's `change`, `input`, `keydown` and `scroll` events. `bubbles` is
 * honoured because the page-level type-ahead is a document-delegated listener;
 * `preventDefault()` is recorded so the keyboard contract can be asserted (Enter
 * and the arrows must not reach the browser's own handling).
 */
class ShimEvent {
  constructor(type, init = {}) {
    this.type = type;
    this.bubbles = !!init.bubbles;
    this.cancelable = !!init.cancelable;
    this.defaultPrevented = false;
    this.target = null;
    this.currentTarget = null;
  }

  preventDefault() {
    this.defaultPrevented = true;
  }

  stopPropagation() {
    this._stopped = true;
  }
}

class Element {
  constructor(tagName) {
    this.tagName = tagName;
    this.parentNode = null;
    this.childNodes = [];
    this.classList = new ClassList();
    this.style = {};
    this.dataset = {};
    this.hidden = false;
    this.id = '';
    this._text = null;
    this._listeners = new Map();
    this._optionsDirty = true;
  }

  get children() {
    return this.childNodes;
  }

  get firstChild() {
    return this.childNodes[0] ?? null;
  }

  /**
   * A detached element keeps what was assigned to it; a container falls back to
   * its descendants, the way the real property does. `buildIndex()` reads an
   * option's text, and `status()` reads and writes the note's.
   */
  get textContent() {
    if (this._text !== null) return this._text;

    return this.childNodes.map((child) => child.textContent).join('');
  }

  set textContent(value) {
    this._text = String(value);
  }

  appendChild(child) {
    child.parentNode = this;
    this.childNodes.push(child);
    child._markTreeChanged();

    return child;
  }

  /** `clearResults()` empties a select by removing its first child until none is left. */
  removeChild(child) {
    const at = this.childNodes.indexOf(child);
    if (at !== -1) this.childNodes.splice(at, 1);
    child.parentNode = null;
    this._markTreeChanged();

    return child;
  }

  /**
   * Every select above this node caches its option list; a mutation anywhere in
   * the subtree has to invalidate it. A browser maintains a live
   * `HTMLOptionsCollection`; here it is rebuilt once per mutation and reused
   * until the next one, which is what keeps `.length` O(1) while the picker is
   * filtering — and so keeps the time budget measuring the filter, not the shim.
   */
  _markTreeChanged() {
    for (let node = this; node; node = node.parentNode) node._optionsDirty = true;
  }

  addEventListener(type, listener) {
    if (!this._listeners.has(type)) this._listeners.set(type, []);
    this._listeners.get(type).push(listener);
  }

  /** Fires on this node, then up the parent chain while the event bubbles. */
  dispatchEvent(event) {
    event.target = event.target ?? this;

    for (let node = this; node; node = node.parentNode) {
      event.currentTarget = node;

      for (const listener of node._listeners.get(event.type) ?? []) {
        listener.call(node, event);
      }

      if (!event.bubbles || event._stopped) break;
    }

    return !event.defaultPrevented;
  }

  /** Deep-copies attributes and descendants, but never listeners — as the DOM does. */
  cloneNode(deep = false) {
    const copy = this._newLike();

    for (const name of this.classList._classes) copy.classList.add(name);
    copy.style = { ...this.style };
    copy.dataset = { ...this.dataset };
    copy.hidden = this.hidden;
    copy._text = this._text;
    this._copyOwn(copy);

    if (deep) {
      for (const child of this.childNodes) copy.appendChild(child.cloneNode(true));
    }

    return copy;
  }

  _newLike() {
    return new Element(this.tagName);
  }

  _copyOwn() {}

  /** Geometry is the listbox model; only options and their select override it. */
  getBoundingClientRect() {
    return { top: 0, left: 0, right: 0, bottom: 0, width: 0, height: 0 };
  }
}

class OptionElement extends Element {
  constructor() {
    super('OPTION');
    this.value = '';
    this.selected = false;
  }

  _newLike() {
    return new OptionElement();
  }

  _copyOwn(copy) {
    copy.value = this.value;
    copy.selected = this.selected;
  }

  /**
   * `revealCursor()` compares the row's rect against the box's. A row's position
   * is its index in the select's own option list, offset by the scroll — the
   * model `revealCursor` was written against (and why it does not use
   * `offsetTop`, which Chromium reports against the document).
   */
  getBoundingClientRect() {
    const select = this._ownerSelect();

    if (!select) return { top: 0, left: 0, right: 0, bottom: 0, width: 0, height: 0 };

    const top = select.options.indexOf(this) * ROW_HEIGHT - select.scrollTop;

    return { top, left: 0, right: 0, bottom: top + ROW_HEIGHT, width: 0, height: ROW_HEIGHT };
  }

  _ownerSelect() {
    for (let node = this.parentNode; node; node = node.parentNode) {
      if (node instanceof SelectElement) return node;
    }

    return null;
  }
}

/**
 * A text field. It always has a `value` — an empty string until someone types —
 * because the picker reads it directly (`currentQuery` trims it, and `init`
 * filters on it), exactly as a browser's `<input>` would answer.
 */
class InputElement extends Element {
  constructor() {
    super('INPUT');
    this.value = '';
  }

  _newLike() {
    return new InputElement();
  }

  _copyOwn(copy) {
    copy.value = this.value;
  }
}

class OptgroupElement extends Element {
  constructor() {
    super('OPTGROUP');
    this.label = '';
  }

  _newLike() {
    return new OptgroupElement();
  }

  _copyOwn(copy) {
    copy.label = this.label;
  }
}

class SelectElement extends Element {
  constructor() {
    super('SELECT');
    this.scrollTop = 0;
    this._options = [];
  }

  /** `sel.options` — document order, cached between mutations. */
  get options() {
    if (this._optionsDirty) {
      this._options = collectOptions(this);
      this._optionsDirty = false;
    }

    return this._options;
  }

  get selectedOptions() {
    return this.options.filter((option) => option.selected);
  }

  get clientHeight() {
    return ROW_HEIGHT * VISIBLE_ROWS;
  }

  get scrollHeight() {
    return ROW_HEIGHT * Math.max(this.options.length, VISIBLE_ROWS);
  }

  getBoundingClientRect() {
    return { top: 0, left: 0, right: 0, bottom: this.clientHeight, width: 0, height: this.clientHeight };
  }
}

function collectOptions(root) {
  const found = [];

  for (const child of root.childNodes) {
    if (child.tagName === 'OPTION') found.push(child);
    else found.push(...collectOptions(child));
  }

  return found;
}

class Document {
  constructor() {
    this._byId = new Map();
  }

  /** The harness's stand-in for an `id` attribute; lookups stay O(1) as a browser's are. */
  register(element, id) {
    element.id = id;
    this._byId.set(id, element);

    return element;
  }

  getElementById(id) {
    return this._byId.get(id) ?? null;
  }

  createElement(tagName) {
    switch (String(tagName).toUpperCase()) {
      case 'SELECT':
        return new SelectElement();
      case 'OPTION':
        return new OptionElement();
      case 'OPTGROUP':
        return new OptgroupElement();
      case 'INPUT':
        return new InputElement();
      default:
        return new Element(String(tagName).toUpperCase());
    }
  }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/** Address-shaped rows: the label carries a value, as the object pickers' do. */
function addressRows(prefix, count, group) {
  const rows = [];

  for (let i = 0; i < count; i++) {
    const name = `${prefix}-${String(i).padStart(4, '0')}`;
    rows.push({
      value: name,
      label: `${name} (10.0.${Math.floor(i / 256)}.${i % 256}/32)`,
      icon: '🖥️',
      group,
    });
  }

  return rows;
}

/**
 * Renders a first page into a select exactly as `partials/pick_select.php` does:
 * the sentinel (a constant, never a search result) first and selected, then one
 * optgroup per group change, and the `data-picker-*` the picker reads to know
 * what it is looking at.
 */
function renderFirstPage(select, page, { sentinel = null, search = null } = {}) {
  if (sentinel) {
    const option = select.appendChild(new OptionElement());
    option.value = sentinel.value;
    option.textContent = sentinel.label;
    option.selected = true;
  }

  let currentGroup = null;
  let box = null;

  for (const item of page.items) {
    if (item.group !== currentGroup) {
      currentGroup = item.group;
      box = select.appendChild(new OptgroupElement());
      box.label = currentGroup;
    }

    const option = box.appendChild(new OptionElement());
    option.value = item.value;
    option.textContent = (item.icon ? `${item.icon} ` : '') + item.label;
  }

  select.dataset.pickerTotal = String(page.total);
  if (page.more) select.dataset.pickerMore = '1';
  if (sentinel) select.dataset.pickerSentinel = '1';

  // And, when the page named one, where those rows came from: the endpoint, the
  // kinds and the page size the partial rendered them with. A picker configured
  // without a source can take that over; a box that holds the whole catalogue has
  // nothing to take over.
  if (search) {
    select.dataset.pickerUrl   = search.url;
    select.dataset.pickerKinds = search.kinds.join(',');
    select.dataset.pickerPer   = String(search.per);
  }

  return select;
}

/**
 * A synthetic server, mirroring `ObjectSearch::search()`: the same kind order,
 * the same haystack (label, value and group heading), the same page arithmetic,
 * and `include` resolved by value. It also records every request, which is how
 * the client's request shape is asserted.
 */
function createServer({ catalogue, kinds, per = 200 }) {
  const calls = [];

  const byValue = new Map();
  for (const kind of kinds) {
    for (const row of catalogue[kind]) byValue.set(row.value, row);
  }

  const haystack = (row) => `${row.label}\u0000${row.value}\u0000${row.group}`.toLowerCase();

  /**
   * Read a request the way the endpoint would, and note it down.
   *
   * Kept apart from the answer so a fixture can record a request that then fails:
   * how many requests the box made is what several checks are about, and a dropped
   * connection is a request that happened and did not come back, not one that was
   * never made.
   */
  const record = (url) => {
    const parsed = new URL(url);
    const call = {
      url: parsed.toString(),
      kinds: parsed.searchParams.get('kinds'),
      q: parsed.searchParams.get('q'),
      page: Math.max(1, parseInt(parsed.searchParams.get('page') ?? '1', 10) || 1),
      per: Math.min(parseInt(parsed.searchParams.get('per') ?? '200', 10) || 200, 500),
      include: parsed.searchParams.getAll('include[]'),
    };

    calls.push(call);

    return call;
  };

  /** The answer that request gets from the catalogue. */
  const respond = (call) => {
    const asked = (call.kinds ?? '').split(',').filter(Boolean);
    const order = kinds.filter((kind) => asked.includes(kind));
    const query = (call.q ?? '').toLowerCase();
    const { page, per: pageSize, include } = call;

    const matches = order.flatMap((kind) =>
      catalogue[kind].filter((row) => query === '' || haystack(row).includes(query))
    );

    return Promise.resolve({
      ok: true,
      status: 200,
      json: () =>
        Promise.resolve({
          items: matches.slice((page - 1) * pageSize, (page - 1) * pageSize + pageSize),
          selected: include.map((value) => byValue.get(value)).filter(Boolean),
          total: matches.length,
          page,
          per: pageSize,
          more: page * pageSize < matches.length,
        }),
    });
  };

  const fetch = (url) => respond(record(url));

  return { fetch, record, respond, calls, fail: (message) => () => Promise.reject(new Error(message)) };
}

/**
 * A `localStorage` the harness owns: the three methods the picker uses over an
 * in-memory map, plus the two ways a real one refuses — a value too large to
 * store, and a browser that will not store anything at all (private mode).
 */
function createStorage({ refuse = () => false } = {}) {
  const entries = new Map();

  const storage = {
    writes: 0,
    /** Refuse a write the way a browser refuses one: a quota, or storage off. */
    refuse,
    getItem: (key) => (entries.has(key) ? entries.get(key) : null),
    setItem(key, value) {
      storage.writes++;

      if (storage.refuse(key, String(value))) throw new Error('QuotaExceededError');

      entries.set(key, String(value));
    },
    removeItem: (key) => entries.delete(key),
    keys: () => [...entries.keys()],
    /** The catalogue record, parsed — what the cache assertions read. */
    snapshot: () => {
      const key = [...entries.keys()].find((name) => name.startsWith('micron-pick-cat:v1:'));

      return key ? JSON.parse(entries.get(key)) : null;
    },
  };

  return storage;
}

/** The storage key the picker derives for a source — spelled out, not imported. */
const catalogueKey = (source = SERVER_SOURCE) => `micron-pick-cat:v1:${source.url}|${source.kinds.join(',')}`;

/** A `fetch` that must never be called; a local picker answers from the page. */
function noFetch() {
  const calls = [];

  return { calls, fetch: (url) => {
    calls.push(url);

    return Promise.reject(new Error('a local picker must not fetch'));
  } };
}

/**
 * A `console` that records instead of printing, so a line the picker writes for
 * the screen's author can be asserted like any other observable output. Passing
 * one into `loadPicker` shadows the process-wide console the script would
 * otherwise reach, which also keeps the harness report free of picker warnings.
 */
function createConsole() {
  const lines = [];
  const record = (kind) => (...args) => lines.push(`${kind}: ${args.join(' ')}`);

  return {
    lines,
    warn: record('warn'),
    error: record('error'),
    log: record('log'),
    warnings: () => lines.filter((line) => line.startsWith('warn: ')),
  };
}

/**
 * Loads the picker into a fresh shim and returns its handle. `setTimeout` is the
 * shim's own queue, so the debounce is driven deterministically and asserted as
 * a *pending* timer rather than by sleeping. `console` likewise stands in for the
 * page's, so what the picker says to a developer is captured, not printed.
 */
function loadPicker({
  document,
  fetch,
  timers,
  console = createConsole(),
  abortController = AbortController,
  storage = globalThis.localStorage,
}) {
  const window = { location: { href: 'http://micronos.test/firewall/rules' } };

  const factory = new Function(
    'window',
    'document',
    'fetch',
    'Event',
    'setTimeout',
    'clearTimeout',
    'console',
    'AbortController',
    'localStorage',
    PICKER_SOURCE
  );

  // `AbortController` and `localStorage` are passed in rather than reached for, so
  // a fixture can hand the picker `null` and drive it as a browser that has
  // neither. Node has no `localStorage`, so the default is "a browser without
  // one" — which is what every fixture that does not ask for one gets.
  factory(window, document, fetch, ShimEvent, timers.setTimeout, timers.clearTimeout, console, abortController, storage);

  if (typeof window.MicronPickerSearch?.init !== 'function') {
    throw new Error('the picker script ran but did not export window.MicronPickerSearch');
  }

  return window.MicronPickerSearch;
}

function createTimers() {
  const queue = new Map();
  let next = 1;

  return {
    setTimeout(fn, ms) {
      const id = next++;
      queue.set(id, { fn, ms });

      return id;
    },
    clearTimeout(id) {
      queue.delete(id);
    },
    pending: () => [...queue.values()],
    flush() {
      const due = [...queue.values()];
      queue.clear();

      for (const timer of due) timer.fn();
    },
    /**
     * Run only the timers whose delay `match` accepts, and say how many ran.
     *
     * A backoff attempt and a keystroke's debounce share this queue, and they have
     * different delays (1000ms and up against 180ms), so "the attempt the backoff
     * scheduled" is selectable. Running everything at once — `flush` — cannot tell
     * the two apart, which is exactly the distinction the backoff checks are about.
     */
    runWhere(match) {
      const chosen = [...queue.entries()].filter(([, timer]) => match(timer.ms));
      chosen.forEach(([id]) => queue.delete(id));
      chosen.forEach(([, timer]) => timer.fn());

      return chosen.length;
    },
  };
}

// ---------------------------------------------------------------------------
// Driving it, the way a page and an operator do
// ---------------------------------------------------------------------------

/** One `input` event, which is what typing produces. */
function type(input, value) {
  input.value = value;
  input.dispatchEvent(new ShimEvent('input', { bubbles: true }));
}

/** One `keydown`; the returned event records whether the picker swallowed it. */
function pressKey(input, key) {
  const event = new ShimEvent('keydown', { bubbles: true, cancelable: true });
  event.key = key;
  input.dispatchEvent(event);

  return event;
}

const visible = (select) => select.options.filter((option) => !option.hidden);

/**
 * The visible values that are *not* the sentinel. The sentinel is visible in
 * every result by design, so leaving it in would mean spelling it out in every
 * single check; the sentinel's own behaviour is asserted directly instead.
 */
const matches = (select) => visible(select).filter((option) => option.value !== ANY).map((option) => option.value);

const values = (select) => select.options.map((option) => option.value);
const selected = (select) => select.selectedOptions.map((option) => option.value);
const cursored = (select) => select.options.filter((option) => option.classList.contains(CURSOR));
const groups = (select) => select.childNodes.filter((node) => node.tagName === 'OPTGROUP');
const visibleGroups = (select) => groups(select).filter((node) => !node.hidden);
const settle = () => new Promise((resolve) => setImmediate(resolve));

/**
 * The note under the box, as the operator sees it — a hidden note is the absence
 * of one. The picker hides the element and leaves the last string inside it, so
 * reading `textContent` alone reports states nobody can see; an outage note that
 * has been dismissed and a box still showing one must not read alike.
 */
const note = (empty) => (empty.hidden ? '' : empty.textContent);

// --- local fixtures: the whole list is in the page --------------------------

const LOCAL_ROWS = [
  ...addressRows('alpha', 40, 'Alpha'),
  ...addressRows('beta', 40, 'Beta'),
  ...addressRows('gamma', 40, 'Gamma'),
  // Rows whose value the label does not contain, so matching on the value is
  // observable rather than accidentally satisfied by the text.
  { value: 'svc_web_browsing', label: 'Web Browsing', icon: '📦', group: 'Services' },
  { value: 'svc_ssh', label: 'SSH', icon: '📦', group: 'Services' },
];

function localFixture({ rows, sentinel = SENTINEL, search = null, total = null, more = false }) {
  const document = new Document();

  const input = document.register(document.createElement('input'), 'localSearch');
  const select = document.register(document.createElement('select'), 'local');
  const empty = document.register(document.createElement('div'), 'localEmpty');
  empty.textContent = 'No matching objects';
  empty.hidden = true;

  renderFirstPage(
    select,
    { items: rows, total: total ?? rows.length, more },
    { sentinel, search }
  );

  const server = noFetch();
  const logs = createConsole();
  const picker = loadPicker({ document, fetch: server.fetch, timers: createTimers(), console: logs });
  picker.init([{ select: 'local', search: 'localSearch', empty: 'localEmpty', any: ANY }]);

  return { document, input, select, empty, picker, server, logs };
}

// --- server fixtures: one page is in the page -------------------------------

const SERVER_ROWS = addressRows('served', 11, 'Individual Addresses & FQDNs');
const SERVER_CATALOGUE = { address: SERVER_ROWS };
const SERVER_KINDS = ['address'];
const SERVER_PER = 3;
const SERVER_SOURCE = { url: 'http://micronos.test/firewall/objects/search', kinds: SERVER_KINDS, per: SERVER_PER };

/**
 * `failing` is either `true` for a fetch that always fails, or an object carrying
 * a `down` flag — which lets one run drop the connection and then restore it, the
 * difference between a box that degrades and a box that degrades *and recovers*.
 */
function serverFixture({ per = SERVER_PER, failing = false, storage, rows = SERVER_ROWS, twin = false } = {}) {
  const document = new Document();
  const server = createServer({ catalogue: { address: rows }, kinds: SERVER_KINDS, per });

  const input = document.register(document.createElement('input'), 'servedSearch');
  const select = document.register(document.createElement('select'), 'served');
  const empty = document.register(document.createElement('div'), 'servedEmpty');
  empty.textContent = 'No matching addresses or groups';

  renderFirstPage(
    select,
    { items: rows.slice(0, per), total: rows.length, more: rows.length > per },
    { sentinel: SENTINEL }
  );

  const outage = typeof failing === 'object' && failing !== null ? failing : { down: !!failing };
  const fetch  = (url) => {
    // Recorded even while the connection is down, so a test can count the requests
    // a box made during an outage — which is the whole subject of the backoff.
    const call = server.record(url);

    return outage.down ? Promise.reject(new Error('boom')) : server.respond(call);
  };

  const timers = createTimers();
  const logs = createConsole();
  const picker = loadPicker({ document, fetch, timers, console: logs, storage });

  const config = [
    { select: 'served', search: 'servedSearch', empty: 'servedEmpty', any: ANY, source: SERVER_SOURCE },
  ];

  // A second picker over the same catalogue, the way the policy dialog has a
  // sources box and a destinations box over the same objects. Both are handed to
  // one `init`, which is what one page load looks like to the picker.
  if (twin) {
    const twinInput = document.register(document.createElement('input'), 'twinSearch');
    const twinSelect = document.register(document.createElement('select'), 'twin');
    const twinEmpty = document.register(document.createElement('div'), 'twinEmpty');
    twinEmpty.textContent = 'No matching addresses or groups';

    renderFirstPage(
      twinSelect,
      { items: rows.slice(0, per), total: rows.length, more: rows.length > per },
      { sentinel: SENTINEL }
    );

    config.push({ select: 'twin', search: 'twinSearch', empty: 'twinEmpty', any: ANY, source: SERVER_SOURCE });
    void twinInput;
  }

  picker.init(config);

  return { document, input, select, empty, server, timers, picker, logs, outage, storage };
}

// --- a transport the harness holds in its hand ---------------------------------

/**
 * A transport that answers when it is told to, not when it can.
 *
 * "The older answer arrives last" and "the connection drops after the next query
 * has already been answered" are *orderings*, not durations: sleeping to produce
 * them makes the check a race, and dropping the response only sometimes makes it
 * worthless. So every request stays pending until the harness delivers the real
 * answer, drops it with a connection error, or leaves it hanging forever.
 *
 * `abort` decides how the transport treats the abort the picker sends:
 *
 *   'ignored'   the signal is never noticed — a proxy that has already flushed
 *               the response, or a fetch that does not wire the signal through.
 *   'aborted'   the transfer fails with an `AbortError`, the well-behaved case.
 *   'typeerror' the transfer fails with a bare connection error, which is what
 *               some transports report for an abort their caller asked for.
 */
function flakyTransport({ per = SERVER_PER, abort = 'ignored' } = {}) {
  const server = createServer({ catalogue: SERVER_CATALOGUE, kinds: SERVER_KINDS, per });
  const requests = [];

  const fetch = (url, options = {}) => {
    const entry = {
      url,
      signal: options.signal ?? null,
      aborted: false,
      answered: false,
      // Asked at request time, so the recorded order is the client's own.
      response: server.fetch(url),
    };

    const promise = new Promise((resolve, reject) => {
      entry.resolve = resolve;
      entry.reject = reject;
    });

    requests.push(entry);

    if (entry.signal) {
      entry.signal.addEventListener('abort', () => {
        entry.aborted = true;
        if (abort === 'ignored') return;

        const failure = abort === 'aborted'
          ? Object.assign(new Error('The operation was aborted.'), { name: 'AbortError' })
          : new TypeError('Failed to fetch');

        // A real transport notices the abort after the call that caused it has
        // returned, which is the window the client's own bookkeeping has to cover.
        setImmediate(() => {
          entry.answered = true;
          entry.reject(failure);
        });
      });
    }

    return promise;
  };

  /**
   * The request at `index`, or a failed check when the box never made one.
   *
   * A check that releases a request the box did not make is a schedule that broke,
   * and the report has to say so: throwing instead would take the whole harness
   * down and lose every check after it.
   */
  const at = (index) => {
    const entry = requests[index];

    if (!entry) {
      check(`the box made request #${index} for the harness to release`, false,
        `${requests.length} request(s) made`);
      return null;
    }

    return entry;
  };

  const deliver = async (index) => {
    const entry = at(index);
    if (!entry) return;

    entry.answered = true;
    entry.resolve(await entry.response);
    await settle();
  };

  const drop = async (index, error = new TypeError('Failed to fetch')) => {
    const entry = at(index);
    if (!entry) return;

    entry.answered = true;
    entry.reject(error);
    await settle();
  };

  /**
   * Drop the newest request there is, and say whether there was one.
   *
   * A schedule that made no request at all is a failed check rather than a
   * harness crash, so the report still names the check that found the trouble.
   */
  const dropNewest = async () => {
    const entry = requests.filter((candidate) => !candidate.answered).at(-1);
    if (!entry) return false;

    await drop(requests.indexOf(entry));

    return true;
  };

  return {
    fetch,
    calls: server.calls,
    requests,
    aborted: (index) => at(index).aborted,
    signalOf: (index) => at(index).signal,
    inFlight: () => requests.filter((entry) => !entry.answered).length,
    deliver,
    drop,
    dropNewest,
  };
}

/**
 * A server-backed picker over a transport the harness controls.
 *
 * `abortSupport: false` is the browser with no `AbortController` at all — the
 * case where the picker cannot even ask for a transfer to be abandoned, so its
 * own bookkeeping is the only thing standing between the list and an older
 * answer. Every other fixture here takes abort for granted, which is exactly how
 * an ordering bug hides.
 */
function flakyFixture({ per = SERVER_PER, abort = 'ignored', abortSupport = true, storage } = {}) {
  const document = new Document();
  const net = flakyTransport({ per, abort });

  const input = document.register(document.createElement('input'), 'flakySearch');
  const select = document.register(document.createElement('select'), 'flaky');
  const empty = document.register(document.createElement('div'), 'flakyEmpty');
  empty.textContent = 'No matching addresses or groups';

  renderFirstPage(
    select,
    { items: SERVER_ROWS.slice(0, per), total: SERVER_ROWS.length, more: SERVER_ROWS.length > per },
    { sentinel: SENTINEL }
  );

  const timers = createTimers();
  const logs = createConsole();
  const picker = loadPicker({
    document,
    fetch: net.fetch,
    timers,
    console: logs,
    abortController: abortSupport ? AbortController : null,
    storage,
  });
  picker.init([{ select: 'flaky', search: 'flakySearch', empty: 'flakyEmpty', any: ANY, source: SERVER_SOURCE }]);

  return { document, input, select, empty, picker, timers, logs, net, storage };
}

// --- a local list that is really one page of a bigger catalogue -------------

/**
 * The box `partials/pick_select.php` writes for the address catalogue, rendered
 * the way the endpoint would answer page one — so the rows in the markup and the
 * rows the server would hand back are the same rows, which is what makes a picker
 * that continues from them coherent.
 */
const SLICE_SOURCE = {
  url: 'http://micronos.test/firewall/objects/search',
  kinds: SERVER_KINDS,
  per: SERVER_PER,
};

/**
 * A picker the page configured as a *local* list — no `source` in its config —
 * over a box that is one page of the catalogue. `declared` is the endpoint the
 * partial wrote into the markup, which every page that renders this box has.
 */
function sliceFixture({ declared = true, sentinel = SENTINEL, per = SERVER_PER, kinds = SERVER_KINDS } = {}) {
  const document = new Document();
  const server = createServer({ catalogue: SERVER_CATALOGUE, kinds: SERVER_KINDS, per });

  const input = document.register(document.createElement('input'), 'sliceSearch');
  const select = document.register(document.createElement('select'), 'slice');
  const empty = document.register(document.createElement('div'), 'sliceEmpty');
  empty.textContent = 'No matching addresses or groups';

  renderFirstPage(
    select,
    { items: SERVER_ROWS.slice(0, per), total: SERVER_ROWS.length, more: SERVER_ROWS.length > per },
    { sentinel, search: declared ? { url: SLICE_SOURCE.url, kinds, per } : null }
  );

  const timers = createTimers();
  const logs = createConsole();
  const picker = loadPicker({ document, fetch: server.fetch, timers, console: logs });
  picker.init([{ select: 'slice', search: 'sliceSearch', empty: 'sliceEmpty', any: ANY }]);

  return { document, input, select, empty, picker, server, timers, logs };
}

// ---------------------------------------------------------------------------
// Checks
// ---------------------------------------------------------------------------

const TESTS = [];
const test = (section, name, fn) => TESTS.push({ section, name, fn });

test('a local picker', 'opens showing every rendered row, and asks the server for nothing', () => {
  const { select, server } = localFixture({ rows: LOCAL_ROWS });

  check('init shows the whole rendered list, sentinel included',
    visible(select).length === select.options.length,
    `${visible(select).length} of ${select.options.length} visible`);
  check('a local list is never fetched', server.calls.length === 0, `${server.calls.length} request(s)`);
});

test('a local picker', 'filters case-insensitively over the row text and its value', () => {
  const { select, picker } = localFixture({ rows: LOCAL_ROWS });

  picker.filter('local', 'ALPHA-000');
  const ten = Array.from({ length: 10 }, (_, i) => `alpha-${String(i).padStart(4, '0')}`);
  check('the query matches the row text case-insensitively', same(matches(select), ten),
    JSON.stringify(matches(select)));

  // Matching is by substring, not by whole word: a shorter query keeps every row
  // it prefixes, which is what makes typing an object's name progressive.
  picker.filter('local', 'alpha-00');
  check('a partial query keeps every row it is a substring of', matches(select).length === 40,
    `${matches(select).length} rows`);

  picker.filter('local', 'svc_ssh');
  check('a row is found by its value, not only by its text', same(matches(select), ['svc_ssh']),
    JSON.stringify(matches(select)));

  picker.filter('local', 'browsing');
  check('a row is found by its label text too', same(matches(select), ['svc_web_browsing']),
    JSON.stringify(matches(select)));

  picker.filter('local', '10.0.0.7/32');
  check('and by the value the label carries, across every group that holds one',
    same(matches(select), ['alpha-0007', 'beta-0007', 'gamma-0007']),
    JSON.stringify(matches(select)));
});

test('a local picker', 'matches a group heading, and hides a group left with nothing', () => {
  const { select, picker } = localFixture({ rows: LOCAL_ROWS });

  // 'services' is in no row's own text, so this isolates the heading match.
  picker.filter('local', 'services');
  check('a heading matches, revealing every row under it',
    same(matches(select), ['svc_web_browsing', 'svc_ssh']),
    JSON.stringify(matches(select)));
  check('only the matching group is left visible',
    same(visibleGroups(select).map((group) => group.label), ['Services']),
    JSON.stringify(visibleGroups(select).map((group) => group.label)));

  picker.filter('local', 'SERVICES');
  check('a heading matches case-insensitively too', same(matches(select), ['svc_web_browsing', 'svc_ssh']),
    JSON.stringify(matches(select)));

  picker.filter('local', 'beta');
  check('a heading reveals its whole group',
    matches(select).length === 40 && matches(select).every((value) => value.startsWith('beta-')),
    `${matches(select).length} rows`);

  picker.filter('local', 'alpha-0000');
  check('a group with matches stays; a group without them is hidden',
    same(visibleGroups(select).map((group) => group.label), ['Alpha']),
    JSON.stringify(visibleGroups(select).map((group) => group.label)));
});

test('a local picker', 'keeps the sentinel whatever the query, and is honest about an empty result', () => {
  const { select, empty, picker } = localFixture({ rows: LOCAL_ROWS });

  picker.filter('local', 'no-such-row-anywhere');
  check('a query matching nothing shows the empty note', empty.hidden === false);
  check('the sentinel is never filtered away', visible(select).some((option) => option.value === ANY),
    JSON.stringify(values(select).filter((value) => visible(select).some((o) => o.value === value))));

  picker.filter('local', 'alpha-0000');
  check('a query with matches hides the empty note again', empty.hidden === true);

  picker.filter('local', 'alpha-0000');
  check('the sentinel is a constant, not a search result, so it stays visible',
    visible(select).some((option) => option.value === ANY));
});

test('a local picker', 'walks only visible rows with the arrows, moving one highlight', () => {
  const { input, select, picker } = localFixture({ rows: LOCAL_ROWS });

  picker.filter('local', 'alpha-000');
  const down = pressKey(input, 'ArrowDown');

  check('ArrowDown is swallowed, so the page does not scroll instead', down.defaultPrevented === true);
  check('the highlight is exactly one row', cursored(select).length === 1,
    `${cursored(select).length} highlighted`);
  check('the walk starts at the top of the visible list (where the sentinel sits)',
    cursored(select)[0] === visible(select)[0],
    `highlighted ${cursored(select)[0]?.value}`);

  const first = cursored(select)[0];
  pressKey(input, 'ArrowDown');

  check('the previous highlight is cleared when it moves',
    !first.classList.contains(CURSOR) && cursored(select).length === 1);
  check('the highlight is the next visible row', cursored(select)[0] === visible(select)[1],
    `highlighted ${cursored(select)[0]?.value}`);

  for (let i = 0; i < 5; i++) pressKey(input, 'ArrowDown');
  check('walking down skips every hidden row',
    visible(select).indexOf(cursored(select)[0]) === 6 && cursored(select)[0].hidden !== true,
    `index ${visible(select).indexOf(cursored(select)[0])}`);
  check('the highlight is scrolled into view rather than left below the box',
    select.scrollTop > 0,
    `scrollTop ${select.scrollTop}`);
});

test('a local picker', 'toggles on Space only once the arrows have armed the list', () => {
  const { input, select, picker } = localFixture({ rows: LOCAL_ROWS });

  const bare = pressKey(input, ' ');
  check('Space is left alone while a multi-word query is being typed', bare.defaultPrevented === false);
  check('…and nothing was toggled by it', same(selected(select), [ANY]), JSON.stringify(selected(select)));

  // Down twice: past the sentinel, onto the first ordinary row.
  pressKey(input, 'ArrowDown');
  pressKey(input, 'ArrowDown');
  const target = cursored(select)[0];

  const armed = pressKey(input, ' ');
  check('Space toggles the highlighted row once the arrows armed the list',
    armed.defaultPrevented === true && same(selected(select), [ANY, target.value]),
    JSON.stringify(selected(select)));
  check('the highlight stays where it was after the toggle', cursored(select)[0] === target);

  const again = pressKey(input, ' ');
  check('a second Space toggles the same row back off',
    again.defaultPrevented === true && same(selected(select), [ANY]),
    JSON.stringify(selected(select)));
});

test('a local picker', 'disarms again on a keystroke, so a query can contain a space', () => {
  const { input, select, picker } = localFixture({ rows: LOCAL_ROWS });

  pressKey(input, 'ArrowDown');
  type(input, 'web brow');
  const space = pressKey(input, ' ');

  check('a keystroke disarms the list, so the next Space is typed rather than toggled',
    space.defaultPrevented === false);
  check('the multi-word query survives the space', same(matches(select), ['svc_web_browsing']),
    JSON.stringify(matches(select)));
});

test('a local picker', 'adds a match on Enter without clearing what is picked, and never picks the sentinel', () => {
  const { input, select, picker } = localFixture({ rows: LOCAL_ROWS });

  picker.setSelection('local', ['alpha-0000']);
  picker.filter('local', 'beta-0000');
  const enter = pressKey(input, 'Enter');

  check('Enter is swallowed, so it cannot submit the dialog instead', enter.defaultPrevented === true);
  check('Enter adds the first match without clearing the current selection',
    same(selected(select), ['alpha-0000', 'beta-0000']),
    JSON.stringify(selected(select)));

  picker.filter('local', 'nothing-matches-this');
  pressKey(input, 'Enter');
  check('Enter never turns the field back into the sentinel',
    same(selected(select), ['alpha-0000', 'beta-0000']),
    JSON.stringify(selected(select)));
});

test('a local picker', 'clears the query on Escape so a leftover search cannot hide the list', () => {
  const { input, select, picker } = localFixture({ rows: LOCAL_ROWS });

  type(input, 'alpha-0000');
  const narrowed = visible(select).length;
  const escape = pressKey(input, 'Escape');

  check('Escape is swallowed', escape.defaultPrevented === true);
  check('Escape clears the query', input.value === '');
  check('Escape brings the whole list back', visible(select).length === select.options.length,
    `${visible(select).length} of ${select.options.length} visible`);
  check('the query really had narrowed the list first', narrowed < select.options.length,
    `${narrowed} visible before Escape`);
});

test('a local picker', 'clears the query and any stale filter on reset()', () => {
  const { input, select, picker } = localFixture({ rows: LOCAL_ROWS });

  type(input, 'gamma');
  check('typing narrowed the list', matches(select).length === 40, `${matches(select).length} rows`);

  picker.reset(['local']);

  check('reset clears the box', input.value === '');
  check('reset shows every row again', visible(select).length === select.options.length,
    `${visible(select).length} of ${select.options.length} visible`);
});

test('a local picker', 'dispatches change, which is the one event a page syncs from', () => {
  const { input, select, picker } = localFixture({ rows: LOCAL_ROWS });

  let changes = 0;
  let last = [];
  select.addEventListener('change', () => {
    changes++;
    last = selected(select);
  });

  pressKey(input, 'ArrowDown');
  pressKey(input, 'ArrowDown');
  pressKey(input, ' ');

  check('Space dispatches change on the select', changes === 1 && last.includes(ANY),
    `${changes} change event(s): ${JSON.stringify(last)}`);

  picker.filter('local', 'alpha-0000');
  pressKey(input, 'Enter');

  check('Enter dispatches change too', changes === 2, `${changes} change event(s)`);
});

test('a server-backed picker', 'opens on the page the server rendered, without asking again', () => {
  const { select, empty, server } = serverFixture();

  check('the fixture holds one page of the catalogue plus the sentinel',
    select.options.length === SERVER_PER + 1,
    `${select.options.length} rows`);
  check('the paging state is read from the markup, not fetched',
    server.calls.length === 0 && select.dataset.pickerMore === '1',
    `${server.calls.length} request(s)`);
  check('the box says how much of the catalogue it is showing',
    empty.textContent === `Showing ${SERVER_PER} of ${SERVER_ROWS.length} matches — keep typing to narrow`,
    empty.textContent);
});

test('a server-backed picker', 'debounces typing into one request that carries the whole contract', async () => {
  const { input, select, server, timers } = serverFixture();

  type(input, 'served-0001');
  type(input, 'served-000');

  check('typing does not fire a request per keystroke', server.calls.length === 0,
    `${server.calls.length} request(s) before the debounce`);
  check("one debounced request is pending, at the picker's own 180 ms",
    timers.pending().length === 1 && timers.pending()[0].ms === 180,
    JSON.stringify(timers.pending().map((timer) => timer.ms)));

  timers.flush();
  await settle();

  const request = server.calls.at(-1);
  check('the debounce asks once, for the newest query',
    server.calls.length === 1 && request.q === 'served-000',
    JSON.stringify({ requests: server.calls.length, q: request.q }));
  check('the request names the kinds, the page size and the page',
    request.kinds === SERVER_KINDS.join(',') && request.per === SERVER_PER && request.page === 1,
    JSON.stringify({ kinds: request.kinds, per: request.per, page: request.page }));
  check('a declared sentinel is not requested back as a picked value',
    request.include.length === 0,
    JSON.stringify(request.include));
  check('the answer replaces the list with the matching rows, sentinel first',
    values(select)[0] === ANY && matches(select).length === SERVER_PER &&
      matches(select).every((value) => value.startsWith('served-000')),
    JSON.stringify(values(select)));
});

test('a server-backed picker', 'says when a query matched nothing, and puts the sentinel back', async () => {
  const { input, select, empty, timers } = serverFixture();

  type(input, 'no-such-row');
  timers.flush();
  await settle();

  check("the empty note is shown in the markup's own words",
    empty.hidden === false && empty.textContent === 'No matching addresses or groups',
    JSON.stringify({ hidden: empty.hidden, text: empty.textContent }));
  check('the sentinel survives the query that matched nothing',
    values(select).includes(ANY),
    JSON.stringify(values(select)));
  check('the note carries the empty state, not the paging state',
    empty.classList.contains('mno-pick-empty') && !empty.classList.contains('mno-pick-status'));
});

test('a server-backed picker', 'pulls in the next page at the end of the loaded rows, without duplicates', async () => {
  const { select, empty, server } = serverFixture();

  const first = values(select);

  select.scrollTop = select.scrollHeight;
  select.dispatchEvent(new ShimEvent('scroll'));
  await settle();

  const after = values(select);
  const duplicates = after.filter((value, at) => after.indexOf(value) !== at);

  check('scrolling to the end fetches the page that follows', server.calls.at(-1).page === 2,
    `page ${server.calls.at(-1).page}`);
  check('the next page is appended, not substituted', same(after.slice(0, first.length), first),
    JSON.stringify(after.slice(0, first.length)));
  check('no row is rendered twice', duplicates.length === 0, JSON.stringify(duplicates));
  check('the box now reports what it holds',
    empty.textContent === `Showing ${2 * SERVER_PER} of ${SERVER_ROWS.length} matches — keep typing to narrow`,
    empty.textContent);

  check('the one group the catalogue declares is still the only heading',
    same(groups(select).map((group) => group.label), ['Individual Addresses & FQDNs']),
    JSON.stringify(groups(select).map((group) => group.label)));
});

test('a server-backed picker', 'loads the next page when the walk reaches the end of the list', async () => {
  const { input, select, server } = serverFixture();

  for (let i = 0; i < SERVER_PER + 1; i++) pressKey(input, 'ArrowDown');

  check('the highlight reached the last loaded row',
    cursored(select)[0]?.value === `served-${String(SERVER_PER - 1).padStart(4, '0')}`,
    `highlighted ${cursored(select)[0]?.value}`);

  pressKey(input, 'ArrowDown');
  await settle();

  check('one more step asks for the page that follows', server.calls.at(-1).page === 2,
    `page ${server.calls.at(-1).page}`);
  check('the highlight lands on the first row of the new page',
    cursored(select)[0]?.value === `served-${String(SERVER_PER).padStart(4, '0')}`,
    `highlighted ${cursored(select)[0]?.value}; rows ${JSON.stringify(values(select))}; ` +
      `requests ${JSON.stringify(server.calls.map((call) => ({ page: call.page, q: call.q, per: call.per })))}`);
});

test('a server-backed picker', 'resolves a picked value that is not on the page back into the list', async () => {
  const { select, server, picker } = serverFixture();

  const offPage = 'served-0009';
  picker.setSelection('served', [offPage]);
  await settle();

  check('the picked value rides along on the request as include[]',
    server.calls.at(-1).include.includes(offPage),
    JSON.stringify(server.calls.at(-1).include));
  check('the off-page value comes back as a row of its own', values(select).includes(offPage),
    JSON.stringify(values(select)));
  check('it comes back under a Selected heading',
    groups(select).some((group) => group.label === 'Selected'),
    JSON.stringify(groups(select).map((group) => group.label)));
  check('and it is selected, so the form would still submit it', same(selected(select), [offPage]),
    JSON.stringify(selected(select)));
});

test('a server-backed picker', 'keeps the picked rows when a request fails, and says so', async () => {
  const { select, empty, picker, input } = serverFixture({ failing: true });

  let notified = 0;
  select.addEventListener('change', () => notified++);

  picker.setSelection('served', ['served-0009']);
  await settle();

  check('the failure is reported instead of swallowed',
    empty.hidden === false && empty.textContent.includes('Search unavailable'),
    JSON.stringify(empty.textContent));
  check('a value the rule carries keeps a row, so a save cannot silently drop it',
    values(select).includes('served-0009'),
    JSON.stringify(values(select)));
  check('and it stays selected', selected(select).includes('served-0009'), JSON.stringify(selected(select)));
  check('the page is still told the selection settled', notified === 1, `${notified} change event(s)`);
  check('the failure carries the error state, not the empty state',
    empty.classList.contains('mno-pick-empty') && !empty.classList.contains('mno-pick-status'));
  check('the note names the failure that happened', empty.textContent.includes('boom'), empty.textContent);

  // The box is degraded, not frozen: the query that follows is filtered in the
  // page, and a row carrying a picked value stands through it even though the
  // query does not match it. The form submits from those options, so hiding one
  // would hide a choice the operator made.
  type(input, 'served-0000');

  check('a picked row stays visible under a query that does not match it',
    visible(select).some((option) => option.value === 'served-0009'),
    JSON.stringify(visible(select).map((option) => option.value)));
  check('and the query narrowed the box to its matches, plus that picked row',
    same(matches(select), ['served-0000', 'served-0009']),
    JSON.stringify(matches(select)));
});

test('a server-backed picker', 'answers from the rows it holds when a request fails', async () => {
  const outage = { down: true };
  const { input, select, empty, server, timers } = serverFixture({ failing: outage });

  // The box holds page one — three rows and the sentinel — and the connection drops.
  type(input, 'served-0000');
  timers.flush();
  await settle();

  check('the rows already on screen answer the query instead of the failed request',
    same(matches(select), ['served-0000']), JSON.stringify(matches(select)));
  check('the operator is told the search is unavailable, and which failure it was',
    empty.textContent.includes('Search unavailable') && empty.textContent.includes('boom'),
    empty.textContent);
  check('and what the box is doing with what it has',
    empty.textContent.includes('filtering the 3 objects already loaded'), empty.textContent);

  // A query while the connection is still down: the list narrows the moment the
  // operator types, without waiting on the debounce or on the network.
  const asked = server.calls.length;
  type(input, 'served-0002');

  check('typing narrows the loaded rows straight away, not after a round trip',
    same(matches(select), ['served-0002']) && server.calls.length === asked,
    JSON.stringify({ matches: matches(select), requests: server.calls.length - asked }));

  // Even when the keystroke's own debounce comes due, it asks for nothing: the
  // attempt the backoff scheduled is what will reach the server.
  timers.flush();
  await settle();

  check('and when that attempt and the keystroke\u2019s debounce both come due, only the attempt asks for anything',
    server.calls.length === asked + 1,
    `${server.calls.length - asked} request(s) from the scheduled attempt and the keystroke together`);

  type(input, 'zimbabwe');
  timers.flush();
  await settle();

  check('a query the loaded rows cannot answer says so, rather than reading as "not found"',
    empty.textContent.includes('no match among the 3 objects already loaded'), empty.textContent);
});

test('a server-backed picker', 'goes back to the catalogue when the connection returns', async () => {
  const outage = { down: true };
  const { input, select, empty, server, timers } = serverFixture({ failing: outage });

  type(input, 'served-000');
  timers.flush();
  await settle();

  check('the box is degraded while the connection is down',
    empty.textContent.includes('Search unavailable'), empty.textContent);

  outage.down = false;
  type(input, 'served-0000');
  timers.flush();
  await settle();

  check('the next query reaches the server again',
    server.calls.at(-1)?.q === 'served-0000', JSON.stringify(server.calls.map((call) => call.q)));
  check('and its answer replaces the degraded list',
    same(values(select), [ANY, 'served-0000']), JSON.stringify(values(select)));
  check('so the note is no longer about a failure', empty.hidden === true,
    JSON.stringify({ hidden: empty.hidden, text: empty.textContent }));

  // Whole again, not merely patched up: a query now waits for the server instead
  // of pre-filtering the rows the box happens to hold.
  type(input, 'served-000');

  check('a box with a working connection does not pre-filter its own rows',
    same(values(select), [ANY, 'served-0000']), JSON.stringify(values(select)));

  timers.flush();
  await settle();

  check('and the query is answered from the catalogue, not from the row on screen',
    server.calls.at(-1)?.q === 'served-000' && matches(select).length === SERVER_PER,
    JSON.stringify({ q: server.calls.at(-1)?.q, matches: matches(select) }));
});

test('a server-backed picker', 'leaves the degraded rows behind when the dialog is reopened', async () => {
  const outage = { down: true };
  const { input, select, empty, server, timers, picker } = serverFixture({ failing: outage });

  type(input, 'served-000');
  timers.flush();
  await settle();

  check('the box degraded while the connection was down',
    empty.textContent.includes('Search unavailable'), empty.textContent);

  outage.down = false;
  picker.reset(['served']);

  check('a reopened dialog opens on the page the server rendered, as it always did',
    same(values(select), [ANY, 'served-0000', 'served-0001', 'served-0002']),
    JSON.stringify(values(select)));
  check('with the paging note back, not the failure note',
    empty.textContent.includes('Showing 3 of 11 matches'), empty.textContent);

  type(input, 'served-0009');

  check('and typing waits for the server again instead of pre-filtering',
    same(values(select), [ANY, 'served-0000', 'served-0001', 'served-0002']),
    JSON.stringify(values(select)));

  timers.flush();
  await settle();

  check('the query is answered from the catalogue',
    server.calls.at(-1)?.q === 'served-0009' && values(select).includes('served-0009'),
    JSON.stringify({ q: server.calls.at(-1)?.q, values: values(select) }));
});

// --- a server that is slow, and drops what it was asked mid-flight ----------

test('a flaky server', 'abandons the query it has outrun, whatever the connection does with the answer', async () => {
  const { input, select, timers, net } = flakyFixture({ abort: 'ignored' });

  type(input, 'served-0000');
  timers.flush();

  type(input, 'served-0002');
  timers.flush();

  check('the pause after each query asks for one page of that query',
    net.calls.length === 2 && net.calls[1].q === 'served-0002',
    JSON.stringify(net.calls.map((call) => ({ q: call.q, page: call.page }))));
  check('and the request the second query superseded is abandoned, not left running',
    net.aborted(0) === true, `aborted=${net.aborted(0)}`);

  // The newer answer lands, and *then* the older one turns up: a proxy that had
  // already flushed it, or a response queued before the abort could reach it.
  await net.deliver(1);

  check('the query the operator settled on is the one on screen',
    same(matches(select), ['served-0002']), JSON.stringify(matches(select)));

  await net.deliver(0);

  check('the answer to the query they typed over arrives late and is dropped',
    same(matches(select), ['served-0002']), JSON.stringify(matches(select)));
  check('so the list never shows an older query than the box',
    values(select).includes('served-0002') && !values(select).includes('served-0000'),
    JSON.stringify(values(select)));
});

test('a flaky server', 'keeps its order even when the browser can abort nothing at all', async () => {
  const { input, select, timers, net } = flakyFixture({ abort: 'ignored', abortSupport: false });

  type(input, 'served-0004');
  timers.flush();

  type(input, 'served-0007');
  timers.flush();

  check('the request goes out with no signal, because there is no AbortController to give it one',
    net.signalOf(0) === null && net.aborted(0) === false,
    `signal=${net.signalOf(0)}, aborted=${net.aborted(0)}`);

  await net.deliver(1);

  check('the newer query is on screen', same(matches(select), ['served-0007']),
    JSON.stringify(matches(select)));

  await net.deliver(0);

  check('and the older answer arriving last still does not overwrite it',
    same(matches(select), ['served-0007']),
    JSON.stringify({ matches: matches(select), values: values(select) }));
});

test('a flaky server', 'does not report a drop that a newer query has already outrun', async () => {
  const { input, select, empty, timers, net } = flakyFixture({ abort: 'ignored' });

  type(input, 'served-0001');
  timers.flush();

  type(input, 'served-000');
  timers.flush();

  await net.deliver(1);

  const healthy = note(empty);

  check('the newer query was answered from the catalogue',
    same(matches(select), ['served-0000', 'served-0001', 'served-0002']) &&
      healthy.includes('Showing 3 of 10 matches'),
    JSON.stringify({ matches: matches(select), note: healthy }));

  // The connection the operator abandoned finally gives up on them.
  await net.drop(0);

  check('a failure from an abandoned request is not an outage the operator is told about',
    !note(empty).includes('Search unavailable'), note(empty) || '(no note)');
  check('and the paging note the answer left is still the one showing',
    note(empty) === healthy, `${JSON.stringify(note(empty))} vs ${JSON.stringify(healthy)}`);

  // The box the operator is holding still talks to the server: a degraded box
  // answers a keystroke from the rows it holds, before any request is made.
  type(input, 'served-0009');

  check('typing still waits for the server instead of pre-filtering a box that is fine',
    same(matches(select), ['served-0000', 'served-0001', 'served-0002']) &&
      timers.pending().length === 1,
    JSON.stringify({ matches: matches(select), pending: timers.pending().length }));
});

test('a flaky server', 'keeps the next-page gate shut while a newer request is still in flight', async () => {
  const { input, select, timers, net } = flakyFixture({ abort: 'ignored' });

  type(input, 'served-000');
  timers.flush();

  type(input, 'served-0009');
  timers.flush();

  // The superseded page turns up while the query that replaced it is in flight.
  await net.deliver(0);

  check('the abandoned page never reached the list',
    same(values(select), [ANY, 'served-0000', 'served-0001', 'served-0002']),
    JSON.stringify(values(select)));

  const asked = net.requests.length;

  select.scrollTop = select.scrollHeight;
  select.dispatchEvent(new ShimEvent('scroll'));
  await settle();

  check('and scrolling to the end asks for nothing, because a request is already on its way',
    net.requests.length === asked, `${net.requests.length - asked} extra request(s)`);

  await net.deliver(1);

  check('when that request lands it is the newest query that draws the list',
    same(matches(select), ['served-0009']), JSON.stringify(matches(select)));
});

test('a flaky server', 'tells its own abort apart from an outage, however the transport reports it', async () => {
  const { input, select, empty, timers, net } = flakyFixture({ abort: 'typeerror' });

  type(input, 'served-0003');
  timers.flush();

  type(input, 'served-0006');
  timers.flush();

  // Some transports report the abort *we* asked for as a bare connection error.
  await settle();
  await settle();

  check('abandoning a request is not reported to the operator as a failed search',
    !note(empty).includes('Search unavailable'), note(empty) || '(no note)');

  type(input, 'served-0010');

  check('and it does not leave the box answering from its own rows',
    same(matches(select), ['served-0000', 'served-0001', 'served-0002']),
    JSON.stringify(matches(select)));

  await net.deliver(1);

  check('the query that replaced it is answered from the catalogue, with nothing to apologise for',
    same(matches(select), ['served-0006']) && note(empty) === '',
    JSON.stringify({ matches: matches(select), note: note(empty) }));
});

test('a flaky server', 'leaves an answer that outran the abort out of a reopened dialog', async () => {
  const { input, select, empty, timers, picker, net } = flakyFixture({ abort: 'ignored' });

  type(input, 'served-0004');
  timers.flush();

  check('the query is still on its way when the next dialog opens',
    net.inFlight() === 1, `${net.inFlight()} request(s) in flight`);

  picker.reset(['flaky']);

  const opened = values(select);

  check('a reopened dialog opens on the page the server rendered, as it always did',
    same(opened, [ANY, 'served-0000', 'served-0001', 'served-0002']), JSON.stringify(opened));

  await net.deliver(0);

  check('and the query from the dialog that was closed never lands on it',
    same(values(select), opened), JSON.stringify(values(select)));
  check('the note is the one the fresh page carries, not the abandoned answer',
    note(empty).includes('Showing 3 of 11 matches'), note(empty) || '(no note)');
});

test('a flaky server', 'recovers the catalogue on the next answer, drops and reorderings behind it', async () => {
  const { input, select, empty, timers, net } = flakyFixture({ abort: 'ignored' });

  type(input, 'served-0001');
  timers.flush();

  type(input, 'served-0002');
  timers.flush();

  await net.drop(1);

  check('a drop the box is waiting on degrades it into a local list, and says so',
    note(empty).includes('Search unavailable'), note(empty) || '(no note)');

  type(input, 'served-0005');
  timers.flush();

  await net.deliver(net.requests.length - 1);

  check('the next answer puts the catalogue back rather than the note',
    same(matches(select), ['served-0005']) && !note(empty).includes('Search unavailable'),
    JSON.stringify({ matches: matches(select), note: note(empty) }));
  check('having asked the server for the query, not filtered the rows it was holding',
    net.calls.at(-1)?.q === 'served-0005' && net.calls.at(-1)?.page === 1,
    JSON.stringify({ q: net.calls.at(-1)?.q, page: net.calls.at(-1)?.page }));
});

// --- a connection that stays down -------------------------------------------

test('a connection that stays down', 'costs one request per backoff window, not one per keystroke', async () => {
  // The flaky transport counts every request even when it is dropped, which is
  // the number this test is about.
  const { input, select, empty, timers, net } = flakyFixture();

  type(input, 'served-');
  timers.flush();
  await net.drop(0);

  check('the first failure is one request, and the box says the connection is lost',
    net.calls.length === 1 && note(empty).includes('connection lost'),
    JSON.stringify({ requests: net.calls.length, note: note(empty) }));
  check('the first attempt is scheduled a second out',
    timers.pending().length === 1 && timers.pending()[0].ms === 1000,
    JSON.stringify(timers.pending().map((timer) => timer.ms)));

  // Four more queries through the outage. None of them may cost a request: this
  // is the whole difference between a backoff and a request per keystroke.
  for (const query of ['served-0', 'served-00', 'served-001', 'served-0010']) {
    type(input, query);
    await settle();
  }

  check('four queries typed through the outage cost no request at all',
    net.calls.length === 1, `${net.calls.length - 1} request(s) from typing`);
  check('and the box went on filtering the rows it holds for them',
    same(matches(select), []), JSON.stringify(matches(select)));

  // Paging is not exempt: a box whose connection is down has nothing to fetch
  // beyond the rows it is holding.
  select.scrollTop = select.scrollHeight;
  select.dispatchEvent(new ShimEvent('scroll'));
  await settle();

  check('and scrolling to the end of a degraded list asks for nothing either',
    net.calls.length === 1, `${net.calls.length - 1} request(s) from scrolling`);

  // The keystroke's own debounce, when it finally comes due, asks for nothing: the
  // backoff owns every attempt from here, so one query costs one request however
  // many keystrokes went into it.
  check('the keystrokes have collapsed into one debounce, and it is still pending',
    timers.pending().filter((timer) => timer.ms === 180).length === 1,
    JSON.stringify(timers.pending().map((timer) => timer.ms)));

  timers.runWhere((ms) => ms === 180);
  await settle();

  check('and asks for nothing at all, because the backoff owns the attempts',
    net.calls.length === 1, `${net.calls.length - 1} request(s) from the debounce`);

  // The attempt that is due is the one that reaches the server — carrying the
  // newest query in the box, not the one that failed.
  timers.runWhere((ms) => ms >= 1000);
  await settle();

  check('the scheduled attempt asks for the newest query, not the one that failed',
    net.calls.length === 2 && net.calls.at(-1).q === 'served-0010',
    JSON.stringify({ requests: net.calls.length, q: net.calls.at(-1)?.q }));
});

test('a connection that stays down', 'waits twice as long after every failure, up to a cap', async () => {
  const { input, empty, timers, net } = flakyFixture();

  type(input, 'served-');
  timers.flush();

  check('the box dropped the first attempt', await net.dropNewest(),
    `${net.calls.length} request(s) made`);
  check('so the first retry is a second out',
    timers.pending().map((timer) => timer.ms).join() === '1000',
    JSON.stringify(timers.pending().map((timer) => timer.ms)));
  check('and the note says which one it is on',
    note(empty).includes('retrying in 1s'), note(empty));

  // Only the scheduled attempts are run here, so the queue holds exactly one wait
  // at a time and each delay can be read off it.
  const waits = [];
  for (let attempt = 0; attempt < 6; attempt++) {
    waits.push(timers.pending()[0]?.ms ?? null);

    timers.runWhere((ms) => ms >= 1000);
    await settle();

    check(`attempt ${attempt + 2} was made, and the connection dropped it`,
      await net.dropNewest(), `${net.calls.length} request(s) made in total`);
  }

  check('each failure waits twice as long as the one before, then stays at the cap',
    same(waits, [1000, 2000, 4000, 8000, 15000, 15000]),
    `waits after each failure: ${JSON.stringify(waits)}`);
});

test('a connection that stays down', 'keeps the indicator true while the operator keeps typing', async () => {
  const outage = { down: true };
  const { input, empty, timers } = serverFixture({ failing: outage });

  type(input, 'served-');
  timers.flush();
  await settle();

  check('the note names the failure, the connection and the attempt it has scheduled',
    note(empty).includes('Search unavailable') &&
      note(empty).includes('connection lost') &&
      note(empty).includes('boom') &&
      note(empty).includes('retrying in 1s'),
    note(empty));
  check('and it is an offline state, not the query\u2019s own empty result and not a paging note',
    empty.classList.contains('mno-pick-offline') &&
      empty.classList.contains('mno-pick-empty') &&
      !empty.classList.contains('mno-pick-status'),
    JSON.stringify({
      offline: empty.classList.contains('mno-pick-offline'),
      empty: empty.classList.contains('mno-pick-empty'),
      status: empty.classList.contains('mno-pick-status'),
    }));

  // A query that matches rows in the box must not put the indicator away: that
  // is exactly when the operator needs to know the box is offline.
  type(input, 'served-0000');

  check('a query that matches does not hide the indicator',
    empty.hidden === false && note(empty).includes('connection lost') &&
      note(empty).includes('filtering the 3 objects already loaded'),
    JSON.stringify({ hidden: empty.hidden, note: note(empty) }));

  // A query that matches nothing is answered as "no match among these", with the
  // offline state still attached, rather than reading as a failed lookup.
  type(input, 'zimbabwe');

  check('a query that matches nothing stays in the offline note rather than becoming "not found"',
    note(empty).includes('no match among the 3 objects already loaded') &&
      note(empty).includes('connection lost'),
    note(empty));
});

test('a connection that stays down', 'shows the attempt it is making, and clears the indicator when one gets through', async () => {
  const { input, select, empty, timers, net } = flakyFixture();

  type(input, 'served-');
  timers.flush();

  await net.drop(0);

  check('the drop leaves the indicator up and names the next attempt',
    note(empty).includes('connection lost') && note(empty).includes('retrying in 1s'),
    note(empty));

  timers.flush();

  check('the attempt that is running says so, rather than looking stalled',
    note(empty).includes('retrying…') && net.inFlight() === 1,
    JSON.stringify({ note: note(empty), inFlight: net.inFlight() }));

  await net.deliver(1);

  check('an answer clears the indicator and the wait behind it',
    !note(empty).includes('connection lost') && timers.pending().length === 0,
    JSON.stringify({ note: note(empty), pending: timers.pending().length }));
  check('and the box is answered from the catalogue again',
    note(empty).includes('Showing 3 of 11 matches') && matches(select).length === SERVER_PER,
    JSON.stringify({ note: note(empty), matches: matches(select) }));
  check('with the offline state gone from the note',
    !empty.classList.contains('mno-pick-offline') &&
      !empty.classList.contains('mno-pick-empty'),
    JSON.stringify({ offline: empty.classList.contains('mno-pick-offline'), note: note(empty) }));

  const asked = net.calls.length;
  type(input, 'served-0009');
  timers.flush();

  check('so the next keystroke goes to the server again',
    net.calls.length === asked + 1 && net.calls.at(-1).q === 'served-0009',
    JSON.stringify(net.calls.slice(asked).map((call) => call.q)));

  // A query with nothing left to say hides the note, and a hidden note is in no
  // state at all: a class left behind would have the box claim a connection it
  // no longer has. This is the state a recovery usually ends in — one match, so
  // there is nothing to page and nothing to report.
  await net.deliver(asked);

  check('and a hidden note keeps no state class behind it',
    empty.hidden === true &&
      !empty.classList.contains('mno-pick-offline') &&
      !empty.classList.contains('mno-pick-empty') &&
      !empty.classList.contains('mno-pick-status'),
    JSON.stringify({
      hidden: empty.hidden,
      offline: empty.classList.contains('mno-pick-offline'),
      empty: empty.classList.contains('mno-pick-empty'),
      status: empty.classList.contains('mno-pick-status'),
    }));

  // A later outage is not charged the wait the last one earned: the schedule
  // starts over from a second, so a brief blip stays brief.
  type(input, 'served-');
  timers.flush();

  check('the connection drops again', await net.dropNewest(),
    `${net.calls.length} request(s) made in total`);
  check('and an outage after a recovery waits a second again, not the last one\u2019s cap',
    timers.pending()[0]?.ms === 1000,
    JSON.stringify(timers.pending().map((timer) => timer.ms)));
});

test('a connection that stays down', 'lets a reopened dialog try again instead of waiting out the backoff', async () => {
  const outage = { down: true };
  const { input, select, empty, server, timers, picker } = serverFixture({ failing: outage });

  type(input, 'served-');
  timers.flush();
  await settle();

  // Talk the box into a long wait, so "it tried again" cannot be confused with
  // "the backoff happened to be due".
  timers.flush();
  await settle();
  timers.flush();
  await settle();

  check('the box is several failures deep, waiting a good while',
    (timers.pending()[0]?.ms ?? 0) >= 4000,
    JSON.stringify(timers.pending().map((timer) => timer.ms)));

  outage.down = false;
  picker.reset(['served']);

  check('a reopened dialog does not inherit the wait',
    timers.pending().length === 0, JSON.stringify(timers.pending().map((timer) => timer.ms)));
  check('and it opens on the page the server rendered, with no failure note',
    same(values(select), [ANY, 'served-0000', 'served-0001', 'served-0002']) &&
      note(empty).includes('Showing 3 of 11 matches'),
    JSON.stringify({ values: values(select), note: note(empty) }));

  const asked = server.calls.length;
  type(input, 'served-0009');
  timers.flush();
  await settle();

  check('so the next query reaches the server at once',
    server.calls.length === asked + 1 && server.calls.at(-1).q === 'served-0009',
    JSON.stringify(server.calls.slice(asked).map((call) => call.q)));
});

test('a connection that stays down', 'still resolves a selection the page asks for while an attempt is pending', async () => {
  const { select, empty, picker, timers, net } = flakyFixture();

  picker.setSelection('flaky', ['served-0010']);
  await net.drop(0);

  check('the box is offline with an attempt scheduled',
    note(empty).includes('connection lost'), note(empty));

  const asked = net.calls.length;

  // The page asking for a value is not a keystroke: the form's chips and its
  // exclusivity rule are waiting on this answer, so it is not made to queue
  // behind the backoff.
  picker.setSelection('flaky', ['served-0009']);

  check('a selection the page asks for is not made to wait out the backoff',
    net.calls.length === asked + 1 && net.calls.at(-1).include.includes('served-0009'),
    JSON.stringify({ requests: net.calls.length - asked, include: net.calls.at(-1)?.include }));
  check('and it took the place of the attempt the backoff had scheduled',
    timers.pending().length === 0, JSON.stringify(timers.pending().map((timer) => timer.ms)));

  await net.deliver(asked);

  check('so the value rides back into the list, selected, while the search stays offline',
    values(select).includes('served-0009') && selected(select).includes('served-0009'),
    JSON.stringify({ values: values(select), selected: selected(select) }));
});

// --- the catalogue, kept in the browser -------------------------------------

test('a cached catalogue', 'stores the page the server rendered, without asking for anything', () => {
  const storage = createStorage();
  // One page of the catalogue, so no walk is due: this is about the free half.
  const { server } = serverFixture({ per: SERVER_ROWS.length, storage });
  const snap = storage.snapshot();

  // Every dereference below is null-safe: a cache that was never written has to
  // fail a named check, not throw before the report.
  check('every picker is cached from the page it was rendered with', !!snap,
    JSON.stringify(storage.keys()));
  check('with that page\u2019s rows, the sentinel aside',
    snap?.rows.length === SERVER_ROWS.length && !snap.rows.some((row) => row.value === ANY),
    `${snap?.rows?.length} row(s), first ${JSON.stringify(snap?.rows?.[0])}`);
  check('and the total the partial reported, so a later note can be exact about it',
    snap?.total === SERVER_ROWS.length, `${snap?.total}`);
  check('the record is versioned and stamped by the load',
    snap?.v === 1 && Date.now() - snap.at < 5000,
    JSON.stringify({ v: snap?.v, age: snap ? Date.now() - snap.at : null }));
  check('and it cost no request at all', server.calls.length === 0,
    `${server.calls.length} request(s)`);
});

test('a cached catalogue', 'walks the pages behind a page that is not the whole catalogue', async () => {
  const storage = createStorage();
  const { server } = serverFixture({ storage });   // per=3 of 11: the markup says there is more

  await settle();

  const snap = storage.snapshot();

  check('a rendered page that is one of many is walked', server.calls.length === 3,
    `${server.calls.length} request(s)`);
  check('walking pages one, two and three, in order',
    server.calls.map((call) => call.page).join() === '1,2,3',
    JSON.stringify(server.calls.map((call) => call.page)));
  check('asking for the catalogue rather than a query',
    server.calls.every((call) => call.q === null),
    JSON.stringify(server.calls.map((call) => call.q)));
  check('and everything the walk saw is stored', snap?.rows.length === 9,
    `${snap?.rows?.length} row(s)`);
});

test('a cached catalogue', 'only walks a copy that is missing or stale, and only once per load', async () => {
  const record = (at) => JSON.stringify({
    v: 1, at, total: SERVER_ROWS.length,
    rows: [{ value: 'served-0000', label: 'served-0000', icon: '', group: 'Individual Addresses & FQDNs' }],
  });

  const fresh = createStorage();
  fresh.setItem(catalogueKey(), record(Date.now() - 60000));
  const kept = serverFixture({ storage: fresh });
  await settle();

  check('a stored copy that is fresh is left alone', kept.server.calls.length === 0,
    `${kept.server.calls.length} request(s)`);

  const stale = createStorage();
  stale.setItem(catalogueKey(), record(Date.now() - 7 * 24 * 60 * 60 * 1000));
  const refreshed = serverFixture({ storage: stale });
  await settle();

  check('one that has gone stale is walked again', refreshed.server.calls.length === 3,
    `${refreshed.server.calls.length} request(s)`);
  check('and the walk keeps the rows the old record already had',
    stale.snapshot().rows.some((row) => row.value === 'served-0000'),
    JSON.stringify(stale.snapshot().rows.map((row) => row.value)));

  const shared = createStorage();
  const both = serverFixture({ storage: shared, twin: true });
  await settle();

  check('and two pickers over the same objects share the one walk',
    both.server.calls.length === 3,
    `${both.server.calls.length} request(s): ${JSON.stringify(both.server.calls.map((call) => call.page))}`);

  // A store with room for the probe but not for the record — an exhausted quota,
  // which is the case the smaller-write fallback exists for. Nothing is stored,
  // so there is no fresh record for the second picker to notice: what keeps the
  // walk from running twice is the load's own guard.
  const narrow = createStorage({ refuse: (key) => !key.endsWith('probe') });
  const cramped = serverFixture({ storage: narrow, twin: true });
  await settle();

  check('and share it even when the store swallows what the walk brings',
    cramped.server.calls.length === 3 && narrow.snapshot() === null,
    `${cramped.server.calls.length} request(s): ${JSON.stringify(cramped.server.calls.map((call) => call.page))}; ` +
      `${narrow.writes} refused write(s), ${narrow.keys().length} key(s) left`);

  const without = serverFixture();
  await settle();

  check('while a browser with nowhere to put the answer walks nothing at all',
    without.server.calls.length === 0, `${without.server.calls.length} request(s)`);
});

test('a cached catalogue', 'answers a reload with no connection from what is stored', async () => {
  const storage = createStorage();

  // What an earlier session left behind: rows the rendered page does not carry.
  storage.setItem(catalogueKey(), JSON.stringify({
    v: 1, at: Date.now() - 3 * 60 * 60 * 1000, total: SERVER_ROWS.length,
    rows: [
      { value: 'served-0009', label: 'served-0009', icon: '', group: 'Individual Addresses & FQDNs' },
      { value: 'served-0010', label: 'served-0010', icon: '', group: 'Individual Addresses & FQDNs' },
    ],
  }));

  const { input, select, empty, timers, net } = flakyFixture({ storage });

  check('a stored copy that is fresh costs nothing at load', net.calls.length === 0,
    `${net.calls.length} request(s)`);

  type(input, 'served-0010');
  timers.flush();
  await net.dropNewest();

  check('the query in flight is answered from the stored catalogue, not just the page',
    same(matches(select), ['served-0010']), JSON.stringify(matches(select)));
  check('and a row the page never carried is in the box again',
    values(select).includes('served-0009'), JSON.stringify(values(select)));
  check('with the note saying where those rows came from, and how old they are',
    note(empty).includes('connection lost') && note(empty).includes('(cached '),
    note(empty));
});

test('a cached catalogue', 'adds the rows every answer brings to what is already stored', async () => {
  const storage = createStorage();
  storage.setItem(catalogueKey(), JSON.stringify({
    v: 1, at: Date.now() - 60000, total: SERVER_ROWS.length,
    rows: [{ value: 'served-0000', label: 'served-0000', icon: '', group: 'Individual Addresses & FQDNs' }],
  }));

  const { select, timers, server } = serverFixture({ storage });
  await settle();

  check('a fresh stored copy means nothing is fetched at load', server.calls.length === 0,
    `${server.calls.length} request(s)`);

  // Paging is an answer too: the page it brings is objects the operator has now
  // seen, and therefore objects a later reload should be able to search.
  select.scrollTop = select.scrollHeight;
  select.dispatchEvent(new ShimEvent('scroll'));
  await settle();

  const snap = storage.snapshot();

  check('a page fetched for the operator is stored with the rest',
    snap?.rows.length === 6, `${snap?.rows?.length} row(s)`);
  check('without losing the row the record already had',
    !!snap && snap.rows.some((row) => row.value === 'served-0000'),
    JSON.stringify(snap?.rows?.map((row) => row.value)));
});

test('a cached catalogue', 'caps what it stores, and takes a smaller copy when the browser balks', () => {
  const many = addressRows('big', 900, 'Big Object Set');

  const roomy = createStorage();
  const { select } = serverFixture({ rows: many, per: many.length, storage: roomy });

  check('a catalogue bigger than the cap is stored up to the cap',
    roomy.snapshot()?.rows.length === 400, `${roomy.snapshot()?.rows?.length} row(s)`);
  check('and the box still holds every row the page rendered',
    values(select).length === many.length + 1, `${values(select).length} option(s)`);

  // A browser that refuses the whole record gets the smaller one rather than
  // nothing: a partial cache still answers the query the operator is typing.
  const tight = createStorage({
    refuse: (key, value) => key.startsWith('micron-pick-cat:') && value.length > 30000,
  });

  serverFixture({ rows: many, per: many.length, storage: tight });

  check('a record the browser refuses is written back smaller, not dropped',
    !!tight.snapshot() && tight.snapshot().rows.length === 200,
    JSON.stringify({ writes: tight.writes, rows: tight.snapshot()?.rows.length }));
});

test('a cached catalogue', 'ignores a stored copy it cannot trust', async () => {
  const untrusted = (record) => {
    const storage = createStorage();
    storage.setItem(catalogueKey(), JSON.stringify(record));
    return storage;
  };

  // A record from a shape this build does not know: written by a newer page, or
  // by one that stored something else under the same key.
  const versioned = untrusted({
    v: 99, at: Date.now(), total: 11, rows: [{ value: 'served-0009', label: 'nine', icon: '', group: 'G' }],
  });

  const wrongShape = flakyFixture({ storage: versioned });

  type(wrongShape.input, 'served-0009');
  wrongShape.timers.flush();
  await wrongShape.net.dropNewest();

  check('a record of an unknown version is not used to answer the box',
    !values(wrongShape.select).includes('served-0009'),
    JSON.stringify(values(wrongShape.select)));
  check('and the note does not claim a cache that was not used',
    !note(wrongShape.empty).includes('(cached '), note(wrongShape.empty));

  // A record older than the trust window: an object deleted weeks ago must not
  // come back into a policy because a browser remembered it.
  const ancient = untrusted({
    v: 1, at: Date.now() - 40 * 24 * 60 * 60 * 1000, total: 11,
    rows: [{ value: 'served-0009', label: 'nine', icon: '', group: 'G' }],
  });

  const stale = flakyFixture({ storage: ancient });

  type(stale.input, 'served-0009');
  stale.timers.flush();
  await stale.net.dropNewest();

  check('and neither is one so old that it cannot be trusted',
    !values(stale.select).includes('served-0009'),
    JSON.stringify(values(stale.select)));

  // Both are treated as absent, so the pages behind the rendered one are walked
  // again rather than being trusted to a record that was refused.
  const walker = serverFixture({ storage: untrusted({ v: 99, at: Date.now(), total: 11, rows: [{ value: 'x', label: 'x', icon: '', group: 'G' }] }) });
  await settle();

  check('a refused record is replaced by a fresh walk', walker.server.calls.length === 3,
    `${walker.server.calls.length} request(s)`);
});

test('a cached catalogue', 'behaves exactly as before when the browser stores nothing', async () => {
  const storage = createStorage({ refuse: () => true });
  const { input, select, timers, server } = serverFixture({ storage });

  await settle();

  check('a browser that refuses storage is treated as one without it',
    storage.keys().length === 0 && server.calls.length === 0,
    JSON.stringify({ keys: storage.keys(), requests: server.calls.length }));

  type(input, 'served-0000');
  timers.flush();
  await settle();

  check('and the box is untouched: the query reaches the server as it always did',
    server.calls.at(-1)?.q === 'served-0000' && matches(select).length === 1,
    JSON.stringify({ q: server.calls.at(-1)?.q, matches: matches(select) }));
});

// --- a local list that is really one page of a bigger catalogue -------------

test('a local list that is part of a catalogue', 'stays local when the box holds the whole list', () => {
  const rows = addressRows('local', 12, 'Individual Addresses & FQDNs');
  const { input, select, empty, server, logs } = localFixture({
    rows,
    search: { url: 'http://micronos.test/firewall/objects/search', kinds: ['address'], per: 200 },
  });

  type(input, 'local-0003');

  check('a catalogue that fits the box is not taken over', server.calls.length === 0,
    `${server.calls.length} request(s)`);
  check('typing filters it in the page, with no request and no debounce',
    same(matches(select), ['local-0003']), JSON.stringify(matches(select)));
  check('and the box makes no paging claim it has no reason to make',
    empty.hidden === true && select.dataset.pickerMore === undefined,
    JSON.stringify({ hidden: empty.hidden, more: select.dataset.pickerMore }));
  // Nothing is out of reach here, so a console line would be a false alarm for a
  // screen that got the markup right.
  check('and it says nothing to the console, because nothing is unreachable',
    logs.lines.length === 0, JSON.stringify(logs.lines));
});

test('a local list that is part of a catalogue', 'adopts the endpoint the markup declares, at load', () => {
  const { select, empty, server, logs } = sliceFixture();

  check('the box opens as the page it was rendered as, without asking again',
    server.calls.length === 0 && select.options.length === SERVER_PER + 1,
    `${server.calls.length} request(s), ${select.options.length} rows`);
  check('and a box it could take over is taken over, with no complaint to the console',
    logs.lines.length === 0, JSON.stringify(logs.lines));
  check('and it says how much of the catalogue it is showing',
    empty.textContent === `Showing ${SERVER_PER} of ${SERVER_ROWS.length} matches — keep typing to narrow`,
    empty.textContent);
  check('the note carries the paging state, which a local picker never has',
    empty.hidden === false && empty.classList.contains('mno-pick-status'),
    JSON.stringify({ hidden: empty.hidden, class: empty.classList.value }));
});

test('a local list that is part of a catalogue', 'pages from the endpoint it adopted', async () => {
  const { input, select, server, timers } = sliceFixture();

  type(input, 'served-000');

  check('typing goes to the server now rather than to the rendered rows',
    timers.pending().length === 1 && timers.pending()[0].ms === 180,
    JSON.stringify(timers.pending().map((timer) => timer.ms)));

  timers.flush();
  await settle();

  const request = server.calls.at(-1);
  check('the request asks for the kinds and the page size the page was rendered with',
    request.kinds === SLICE_SOURCE.kinds.join(',') && request.per === SERVER_PER,
    JSON.stringify({ kinds: request.kinds, per: request.per }));
  check('and it is a first page, because the box starts over from the markup it was given',
    request.page === 1, `page ${request.page}`);
  check('the answer replaces the list, sentinel first',
    values(select)[0] === ANY && matches(select).length === SERVER_PER,
    JSON.stringify(values(select)));

  select.scrollTop = select.scrollHeight;
  select.dispatchEvent(new ShimEvent('scroll'));
  await settle();

  const after = values(select);
  check('and the box pages on, because the scroll listener came with the endpoint',
    server.calls.at(-1).page === 2, `page ${server.calls.at(-1).page}`);
  check('appending the next page under the heading that is already there',
    after.filter((value, at) => after.indexOf(value) !== at).length === 0 &&
      same(groups(select).map((group) => group.label), ['Individual Addresses & FQDNs']),
    JSON.stringify({ groups: groups(select).map((group) => group.label), rows: after }));
});

test('a local list that is part of a catalogue', 'resolves a picked value outside the page instead of dropping it', async () => {
  const { select, server, picker } = sliceFixture();

  // Opening a saved rule: the object is not on the rendered page, so a picker
  // still filtering its own rows would quietly lose it — and the rule would save
  // without it.
  picker.setSelection('slice', ['served-0009']);
  await settle();

  check('the value the rule carries is requested back as include[]',
    server.calls.at(-1).include.includes('served-0009'),
    JSON.stringify(server.calls.at(-1).include));
  check('it comes back as a row of its own, under a Selected heading',
    values(select).includes('served-0009') &&
      groups(select).some((group) => group.label === 'Selected'),
    JSON.stringify(values(select)));
  check('and it stays selected, so the form would still submit it',
    selected(select).includes('served-0009'), JSON.stringify(selected(select)));
});

test('a local list that is part of a catalogue', 'stays local and tells the console when the markup names no endpoint', () => {
  const { input, select, empty, server, logs } = sliceFixture({ declared: false });

  // The notice is the fix-it message for whoever wrote the screen, so it has to
  // be there at load — before anyone types — and it has to say which box it is.
  const atLoad = logs.warnings();
  // Read defensively: if the notice is ever removed, the checks below have to fail
  // one by one, as assertions, rather than crash the run on an undefined line.
  const line = atLoad[0] ?? '';

  check('a slice with nowhere to ask is left exactly as it was', server.calls.length === 0,
    `${server.calls.length} request(s)`);
  check('and it does not go quietly: the console gets one line about it',
    atLoad.length === 1, JSON.stringify(logs.lines));
  check('the line is the picker speaking, so it cannot be mistaken for page output',
    line.startsWith('warn: MicronPickerSearch: '), line);
  check('it names the box that is holding objects back', line.includes('#slice'), line);
  check('and how much of the catalogue is on screen against how much there is',
    line.includes('first 4 of 11 objects'), line);
  check('and the fix: the endpoint the partial was never given',
    line.includes('no search endpoint, so the rest cannot be searched') &&
      line.includes('pass `search`'),
    line);
  check('naming the partial to give it to, so the message is actionable as it stands',
    line.includes('to the partial that rendered it (partials/pick_select.php)'), line);

  type(input, 'served-0001');

  check('it filters the rows it does hold',
    same(matches(select), ['served-0001']), JSON.stringify(matches(select)));
  check('and claims no paging state it cannot deliver on', empty.hidden === true);
  check('the notice is said once, not on every keystroke and not as an error',
    logs.warnings().length === 1 && logs.lines.length === 1, JSON.stringify(logs.lines));
});

test('a local list that is part of a catalogue', 'stays local and tells the console when the endpoint names no kinds', () => {
  // `data-picker-url` with an empty `data-picker-kinds` would be worse than no
  // endpoint at all: the request names no kind, and the server answers its
  // default address catalogue — silently, behind a list of countries.
  const { select, empty, server, logs } = sliceFixture({ kinds: [] });

  const warnings = logs.warnings();
  const line = warnings[0] ?? '';

  check('an endpoint with nothing to ask it for is not adopted either', server.calls.length === 0,
    `${server.calls.length} request(s)`);
  check('the box keeps the rows it was rendered with, rather than half-adopting a source',
    select.options.length === SERVER_PER + 1, `${select.options.length} rows`);
  check('the console gets one line naming the kinds as the missing half',
    warnings.length === 1 && line.includes('declares no kinds to search for'),
    JSON.stringify(logs.lines));
  check('saying which catalogue its requests would have answered instead',
    line.includes('default address catalogue'), line);
  check('and which argument the partial was not given',
    line.includes('pass `source`') &&
      line.includes('to the partial that rendered it (partials/pick_select.php)'),
    line);
  check('and the operator sees nothing extra, because a local list is still a working box',
    empty.hidden === true, JSON.stringify({ hidden: empty.hidden, text: empty.textContent }));
});

// ---------------------------------------------------------------------------
// The filtering time budget
// ---------------------------------------------------------------------------

/**
 * One pass over a catalogue of the string work a filter does per row: read the
 * row's text and value, join them with a NUL and look for the query in the
 * result. It is the same work the picker's own index holds, on the same number
 * of rows, measured on the same machine and in the same run as the keystrokes it
 * is the yardstick for.
 *
 * The haystacks are built outside the timed loop: stringing them inside it is
 * one of the regressions the budget is meant to catch, not part of the yardstick.
 */
function measurePass() {
  const hay = [];

  for (let i = 0; i < BUDGET.rows; i++) {
    const text = `row-${String(i).padStart(4, '0')}`;
    hay.push(`${text}\u0000row-${i}`.toLowerCase());
  }

  const samples = [];

  for (let repeat = 0; repeat < BUDGET.repeats; repeat++) {
    const query = BUDGET.queries[repeat % BUDGET.queries.length];
    const at = performance.now();
    let hits = 0;

    for (let i = 0; i < hay.length; i++) if (hay[i].indexOf(query) !== -1) hits++;

    samples.push(performance.now() - at);
  }

  samples.sort((a, b) => a - b);

  return samples[Math.floor(samples.length / 2)];
}

function measureKeystrokes() {
  const rows = addressRows('row', BUDGET.rows, 'Objects');
  const { picker } = localFixture({ rows, sentinel: null });

  // The first filter call builds the index over the whole catalogue; that
  // one-off cost is reported, but what is asserted is the steady-state
  // keystroke, which is what an operator feels while typing.
  const started = performance.now();
  picker.filter('local', BUDGET.queries[0]);
  const firstKeystrokeMs = performance.now() - started;

  const samples = [];

  for (let repeat = 0; repeat < BUDGET.repeats; repeat++) {
    for (const query of BUDGET.queries) {
      const at = performance.now();
      picker.filter('local', query);
      samples.push(performance.now() - at);
    }
  }

  samples.sort((a, b) => a - b);

  return {
    rows: BUDGET.rows,
    firstKeystrokeMs,
    samples: samples.length,
    median: samples[Math.floor(samples.length / 2)],
    p95: samples[Math.floor(samples.length * 0.95)],
    worst: samples.at(-1),
  };
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

async function main() {
  console.log('Picker DOM harness — app/Views/partials/picker_search.php');
  console.log(`node ${process.version}; the budget is measured over ${BUDGET.rows} synthetic rows`);

  let group = null;

  for (const { section, name, fn } of TESTS) {
    if (section !== group) {
      group = section;
      console.log('');
      console.log(`── ${group}`);
    }

    console.log('');
    console.log(`· ${name}`);
    await fn();
  }

  console.log('');
  console.log('── The filtering time budget');

  const timing = measureKeystrokes();
  const passMs = measurePass();
  const passes = passMs > 0 ? timing.median / passMs : Infinity;
  const perRowUs = (timing.median * 1000) / timing.rows;

  console.log(
    `catalogue ${timing.rows} rows; ${timing.samples} sampled keystrokes; ` +
      `median ${timing.median.toFixed(2)} ms (${perRowUs.toFixed(2)} µs/row), ` +
      `p95 ${timing.p95.toFixed(2)} ms, worst ${timing.worst.toFixed(2)} ms; ` +
      `first keystroke (builds the index) ${timing.firstKeystrokeMs.toFixed(2)} ms`
  );
  console.log(
    `one reference pass over those rows ${passMs.toFixed(3)} ms; ` +
      `a keystroke costs ${passes.toFixed(2)} of them (${BUDGET.passes} allowed)`
  );

  check(
    `a keystroke over ${timing.rows} rows fits in one frame (median < ${BUDGET.keystrokeMs} ms)`,
    timing.median < BUDGET.keystrokeMs,
    `median ${timing.median.toFixed(2)} ms over ${timing.samples} samples`
  );

  // The budget only means something next to a claim about *why* it is cheap: a
  // keystroke is a walk over strings indexed once, not a rescan and re-string of
  // the whole catalogue. Said in passes over the catalogue — measured here, like
  // the keystrokes, so a slow machine cancels out — that claim is checkable.
  check(
    `a keystroke stays within ${BUDGET.passes} passes over the catalogue, not a rescan per row`,
    passes <= BUDGET.passes,
    `${passes.toFixed(2)} passes (budget ${BUDGET.passes}); ` +
      `${timing.median.toFixed(2)} ms vs a ${passMs.toFixed(3)} ms reference pass`
  );

  console.log('');
  if (failures.length === 0) {
    console.log(`${SUCCESS_MARKER} (${checks} checks)`);

    return 0;
  }

  console.log(`FAILED: ${failures.length} of ${checks} checks`);
  for (const failure of failures) console.log(`  - ${failure}`);

  return 1;
}

main()
  .then((code) => {
    process.exitCode = code;
  })
  .catch((error) => {
    console.error(`the harness could not run: ${error?.stack ?? error}`);
    process.exitCode = 2;
  });
