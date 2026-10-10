#!/usr/bin/env node
/**
 * Scheduler DOM harness — the shared request scheduler, driven in Node.
 * ====================================================================
 * `public/assets/js/micronos-scheduler.js` is the one place on the appliance
 * that knows whether the appliance can be reached. Every live screen's polling
 * and AJAX goes through it, and the PHP suite pins that *wiring* — that each
 * poller hands its work over, that the layout renders the single banner, that
 * the picker reports through it. What PHP cannot see is the scheduler running.
 *
 * So this harness runs it. It reads the shipped file (never a copy — a copy
 * would pass while the shipped code rotted), gives it a hand-written
 * `window`/`document`/`fetch`, and a clock the harness owns, and drives the
 * three things the module exists to do:
 *
 *   1. **One connection state.** A failed poll, a failed fetch and a manual
 *      report all turn the same state off-line and paint the same one banner;
 *      the first request that gets through again clears it — and only a genuine
 *      transition is announced to subscribers, so three failures in a row are
 *      not three "we are down" events.
 *
 *   2. **One backoff schedule.** A poll's retry doubles from its own interval to
 *      a ceiling, with a bounded jitter so a page full of pollers does not
 *      retry in lockstep; a success resets it, so the next outage starts over.
 *      The exported `backoffDelay` is the same arithmetic the picker quotes.
 *
 *   3. **Waking together.** A hidden tab holds every poll; coming back on-line,
 *      becoming visible again, or any single request succeeding wakes all of
 *      them at once, so one page does not sit out an outage a request that has
 *      already succeeded.
 *
 * The clock is the harness's own queue, so backoff is asserted as the delay the
 * module actually asked the browser for — a pending timer's `ms` — rather than
 * by sleeping. `Math.random` is injected so the jitter is checked at both ends
 * of its band instead of being averaged away.
 *
 * Run it directly:
 *
 *     node tests/js/scheduler_harness.mjs
 *
 * …or through the project's test run, which is how CI sees it:
 * `tests/SchedulerDomHarnessTest.php` executes this file and fails the build
 * unless it exits 0 and prints the success marker below.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = join(HERE, '..', '..');
const SCHEDULER_JS = join(ROOT, 'public', 'assets', 'js', 'micronos-scheduler.js');

/** Printed when nothing failed; `SchedulerDomHarnessTest` asserts on it. */
const SUCCESS_MARKER = 'ALL SCHEDULER DOM HARNESS CHECKS PASSED';

/** The banner id the layout renders and the module paints (app/Views/layouts/main.php). */
const BANNER_ID = 'mnoConnBanner';

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

/** Let the microtask queue drain so a resolved `run` is observed before asserting. */
const flush = () => new Promise((resolve) => setImmediate(resolve));

// ---------------------------------------------------------------------------
// Shims
// ---------------------------------------------------------------------------

function createBanner() {
  const classes = new Set();
  const el = {
    id: BANNER_ID,
    hidden: true,
    _aria: 'true',
    _text: { textContent: '' },
    classList: {
      toggle(name, force) {
        const on = force === undefined ? !classes.has(name) : !!force;
        if (on) classes.add(name); else classes.delete(name);

        return on;
      },
      contains: (name) => classes.has(name),
      _set: classes,
    },
    setAttribute(name, value) {
      if (name === 'aria-hidden') el._aria = value;
    },
    getAttribute: (name) => (name === 'aria-hidden' ? el._aria : null),
    querySelector: (sel) => (sel === '.mno-conn-text' ? el._text : null),
    text: () => el._text.textContent,
  };

  return el;
}

function createDocument({ hidden = false } = {}) {
  const banner = createBanner();
  const listeners = {};

  return {
    hidden,
    banner,
    getElementById: (id) => (id === BANNER_ID ? banner : null),
    addEventListener(type, cb) {
      (listeners[type] = listeners[type] || []).push(cb);
    },
    fire(type) {
      (listeners[type] || []).slice().forEach((cb) => cb({ type }));
    },
    has: (type) => (listeners[type] || []).length > 0,
  };
}

function createWindow({ document, fetch, clock }) {
  const listeners = {};

  return {
    document,
    fetch,
    location: { href: 'http://micronos.test/dashboard' },
    setTimeout: (fn, ms) => clock.setTimeout(fn, ms),
    clearTimeout: (id) => clock.clearTimeout(id),
    addEventListener(type, cb) {
      (listeners[type] = listeners[type] || []).push(cb);
    },
    fire(type) {
      (listeners[type] || []).slice().forEach((cb) => cb({ type }));
    },
    has: (type) => (listeners[type] || []).length > 0,
  };
}

/**
 * The clock the harness owns. `setTimeout` records the delay the module asked
 * for, which is the whole point: backoff is asserted as a number, not timed.
 */
function createClock() {
  let now = 0;
  let seq = 1;
  const queue = new Map();

  const clock = {
    setTimeout(fn, ms) {
      const id = seq++;
      const wait = Math.max(0, Number(ms) || 0);
      queue.set(id, { fn, ms: wait, at: now + wait });

      return id;
    },
    clearTimeout(id) {
      queue.delete(id);
    },
    pending: () => [...queue.values()].sort((a, b) => a.at - b.at),
    count: () => queue.size,
    dueIn() {
      const p = clock.pending();

      return p.length ? p[0].at - now : null;
    },
    /** Advance to the earliest due timer and run it. */
    advance() {
      const p = clock.pending();
      if (!p.length) return null;

      const next = p[0];
      for (const [id, rec] of queue) if (rec === next) queue.delete(id);
      now = next.at;
      next.fn();

      return next;
    },
    /** Run everything currently due, in order (bounded, so a runaway loop fails loudly). */
    runAll() {
      let n = 0;
      while (clock.pending().length && n++ < 500) clock.advance();

      return n;
    },
  };

  return clock;
}

const SCHEDULER_SOURCE = readFileSync(SCHEDULER_JS, 'utf8');

/**
 * Load the shipped scheduler into a fresh shim. Returns the module plus the
 * shims it was handed, so a fixture can fire browser events or inspect the
 * banner. `random` is injected to pin the jitter.
 */
function loadScheduler({
  document = createDocument(),
  fetch = async () => ({ ok: true, status: 200, json: async () => ({}) }),
  clock = createClock(),
  random = () => 0.5,
} = {}) {
  const window = createWindow({ document, fetch, clock });

  // `Math` is injected so `random` can be pinned; the rest is the real thing.
  const math = {
    pow: Math.pow,
    min: Math.min,
    max: Math.max,
    round: Math.round,
    random,
  };

  const factory = new Function(
    'window',
    'document',
    'fetch',
    'setTimeout',
    'clearTimeout',
    'console',
    'Math',
    SCHEDULER_SOURCE
  );

  factory(window, document, fetch, window.setTimeout, window.clearTimeout, { error() {}, warn() {}, log() {} }, math);

  if (!window.MicronScheduler) {
    throw new Error('the scheduler script ran but did not export window.MicronScheduler');
  }

  return { S: window.MicronScheduler, window, document, clock };
}

/** A poll whose `run` is scripted: each entry is the outcome of one attempt. */
function scripted(clock, outcomes) {
  let i = 0;
  const calls = { n: 0 };

  return {
    calls,
    run() {
      const outcome = outcomes[Math.min(i, outcomes.length - 1)];
      i++;
      calls.n++;

      if (outcome === 'throw') throw new Error('boom');
      if (outcome === 'false') return false;
      if (outcome === 'reject') return Promise.reject(new Error('down'));
      if (outcome === 'pending') return new Promise(() => {});

      return true;
    },
  };
}

// ---------------------------------------------------------------------------
// Checks
// ---------------------------------------------------------------------------

async function main() {
  console.log('── The shared connection state');

  const a = loadScheduler();
  check('the module is exposed and binds the browser events at load', typeof a.S === 'object' && a.S !== null);
  check('it binds browser online/offline and the tab\'s visibilitychange',
    a.window.has('online') && a.window.has('offline') && a.document.has('visibilitychange'));
  check('the state starts on-line with no failures', a.S.state().online === true && a.S.state().failures === 0);
  check('the banner exists but is hidden while on-line', a.document.banner.hidden === true);

  // One poll, whose first attempt fails.
  const failing = loadScheduler();
  const f1 = scripted(failing.clock, ['false', 'false', 'false']);
  failing.S.poll('p', { interval: 2000, run: f1.run });
  check('a poll runs its first tick at once', f1.calls.n === 0 && failing.clock.dueIn() === 0);

  failing.clock.advance();
  await flush();
  check('a first failed attempt turns the shared state off-line', failing.S.state().online === false);
  check('and shows the one banner, with the retry message',
    failing.document.banner.hidden === false &&
    failing.document.banner.classList.contains('is-offline') &&
    /retrying/i.test(failing.document.banner.text()),
    `text="${failing.document.banner.text()}"`);
  check('the banner is the layout\'s own element, painted — not a second one built in JS',
    failing.document.banner.id === BANNER_ID && failing.document.banner.getAttribute('aria-hidden') === 'false');

  console.log('');
  console.log('── The backoff schedule');

  check('the first retry waits the poll\'s own interval', failing.clock.dueIn() === 2000, `due in ${failing.clock.dueIn()}ms`);
  failing.clock.advance();
  await flush();
  check('the second waits double', failing.clock.dueIn() === 4000, `due in ${failing.clock.dueIn()}ms`);
  failing.clock.advance();
  await flush();
  check('the third waits double again', failing.clock.dueIn() === 8000, `due in ${failing.clock.dueIn()}ms`);

  // Push it to the ceiling.
  failing.clock.advance(); await flush();   // 16000
  failing.clock.advance(); await flush();   // 30000 (32000 capped)
  check('the backoff is capped at the ceiling', failing.clock.dueIn() === 30000, `due in ${failing.clock.dueIn()}ms`);
  failing.clock.advance(); await flush();
  check('and stays capped however long the outage runs', failing.clock.dueIn() === 30000, `due in ${failing.clock.dueIn()}ms`);

  // A success resets the count and wakes the poll at once.
  const recovering = loadScheduler();
  const r1 = scripted(recovering.clock, ['false', 'false', 'true']);
  recovering.S.poll('r', { interval: 2000, run: r1.run });
  recovering.clock.advance(); await flush();  // fail → 2000
  recovering.clock.advance(); await flush();  // fail → 4000
  check('two failures in a row are still one outage', recovering.S.state().failures === 2 && recovering.clock.dueIn() === 4000);
  recovering.clock.advance(); await flush();  // success
  check('a success clears the state and hides the banner',
    recovering.S.state().online === true && recovering.S.state().failures === 0 && recovering.document.banner.hidden === true);
  check('and wakes the poll immediately rather than waiting out the backoff', recovering.clock.dueIn() === 0, `due in ${recovering.clock.dueIn()}ms`);
  const beforeReset = r1.calls.n;
  recovering.clock.advance(); await flush();
  check('so the next attempt is at the poll\'s interval again',
    r1.calls.n === beforeReset + 1 && recovering.clock.dueIn() === 2000, `due in ${recovering.clock.dueIn()}ms`);

  console.log('');
  console.log('── One schedule, wherever it is quoted');

  const s = loadScheduler();
  check('backoffDelay exports the doubling the poll uses',
    s.S.backoffDelay(1, 1000, 15000) === 1000 &&
    s.S.backoffDelay(2, 1000, 15000) === 2000 &&
    s.S.backoffDelay(3, 1000, 15000) === 4000);
  check('and caps at the max it is given', s.S.backoffDelay(9, 1000, 15000) === 15000);
  check('with defaults that match the module\'s own constants',
    s.S.backoffDelay(1) === 1000 && s.S.backoffDelay(99) === 30000);

  // The jitter band, pinned at both ends.
  const low = loadScheduler({ random: () => 0 });
  const lowPoll = scripted(low.clock, ['false', 'false', 'false']);
  low.S.poll('j', { interval: 2000, run: lowPoll.run });
  low.clock.advance(); await flush();   // failures 1 → 2000
  low.clock.advance(); await flush();   // failures 2 → 4000, jittered down
  check('the retry is jittered down at the bottom of the band', low.clock.dueIn() === 3400, `due in ${low.clock.dueIn()}ms`);

  const high = loadScheduler({ random: () => 1 });
  const highPoll = scripted(high.clock, ['false', 'false', 'false']);
  high.S.poll('j', { interval: 2000, run: highPoll.run });
  high.clock.advance(); await flush();
  high.clock.advance(); await flush();
  check('and up at the top, inside ±15%', high.clock.dueIn() === 4600, `due in ${high.clock.dueIn()}ms`);

  console.log('');
  console.log('── Anything that talks to the appliance feeds the same state');

  const net = loadScheduler({ fetch: async () => ({ ok: false, status: 500, json: async () => ({}) }) });
  await net.S.fetch('/x');
  check('a fetch that answers — even a 500 — means the appliance is reachable', net.S.state().online === true);

  let mode = 'reject';
  const net2 = loadScheduler({ fetch: async () => { if (mode === 'reject') throw new TypeError('Failed to fetch'); return { ok: true, status: 200 }; } });
  let caught = false;
  await net2.S.fetch('/x').catch(() => { caught = true; });
  check('a fetch that cannot reach the appliance turns the state off-line and shows the banner',
    caught && net2.S.state().online === false && net2.document.banner.hidden === false);
  mode = 'ok';
  await net2.S.fetch('/x');
  check('and the next reachable answer clears it', net2.S.state().online === true && net2.document.banner.hidden === true);

  const rep = loadScheduler();
  rep.S.report(false, new Error('picker down'));
  check('a manual report turns the state off-line', rep.S.state().online === false);
  rep.S.report(true);
  check('and a manual success clears it', rep.S.state().online === true);

  console.log('');
  console.log('── One announcement per transition, not per failure');

  const sub = loadScheduler();
  const seen = [];
  const subPoll = scripted(sub.clock, ['false', 'false', 'false', 'true']);
  sub.S.on((st) => seen.push(st.online));
  sub.S.poll('s', { interval: 1000, run: subPoll.run });
  sub.clock.advance(); await flush();   // fail
  sub.clock.advance(); await flush();   // fail
  sub.clock.advance(); await flush();   // fail
  check('three failures announce the outage once', seen.length === 1 && seen[0] === false, `saw ${JSON.stringify(seen)}`);
  sub.clock.advance(); await flush();   // success
  check('the recovery is announced too', seen.length === 2 && seen[1] === true, `saw ${JSON.stringify(seen)}`);

  console.log('');
  console.log('── A page of pollers backs off together');

  const many = loadScheduler();
  const p1 = scripted(many.clock, ['false', 'false']);
  const p2 = scripted(many.clock, ['false', 'false']);
  many.S.poll('one', { interval: 2000, run: p1.run });
  many.S.poll('two', { interval: 5000, run: p2.run });
  // Both first ticks are due at once; run each exactly once.
  many.clock.advance(); await flush();
  many.clock.advance(); await flush();
  const dueOne = many.clock.pending().map((t) => t.ms).sort((x, y) => x - y);
  check('each poller backs off from its own interval, on the one shared failure state',
    many.S.state().failures === 2 && dueOne.length === 2 && dueOne[0] === 2000 && dueOne[1] === 5000,
    `failures ${many.S.state().failures}, due ${JSON.stringify(dueOne)}`);

  // One poller succeeding wakes the other at once: a partner held at a long
  // interval should not sit out an outage a request has already survived.
  const wake = loadScheduler();
  const w1 = scripted(wake.clock, ['false', 'true']);
  let woken = false;
  const w2 = { run() { woken = true; return true; } };
  wake.S.poll('failer', { interval: 2000, run: w1.run });            // first tick now → fails
  wake.S.poll('partner', { interval: 30000, run: w2.run, immediate: false });  // held at 30s
  wake.clock.advance(); await flush();       // failer fails; state down, partner still held
  check('a held poller waits on its interval while the outage runs', wake.clock.dueIn() === 2000, `due in ${wake.clock.dueIn()}ms`);
  wake.clock.advance(); await flush();       // failer succeeds → recovery wakes everyone
  check('a single poller succeeding wakes the others immediately', wake.clock.dueIn() === 0, `due in ${wake.clock.dueIn()}ms`);
  wake.clock.advance(); await flush();
  wake.clock.advance(); await flush();
  check('and the woken poller runs', woken === true);

  console.log('');
  console.log('── Hidden tabs and browser signals');

  const hidden = loadScheduler({ document: createDocument({ hidden: true }) });
  const hPoll = scripted(hidden.clock, ['true', 'true']);
  hidden.S.poll('h', { interval: 4000, run: hPoll.run });
  hidden.clock.advance(); await flush();
  check('a hidden tab does not spend the appliance\'s time', hPoll.calls.n === 0);
  check('but the poll is held, not lost', hidden.clock.dueIn() === 4000, `due in ${hidden.clock.dueIn()}ms`);
  hidden.document.hidden = false;
  hidden.document.fire('visibilitychange');
  check('becoming visible again wakes it at once', hidden.clock.dueIn() === 0, `due in ${hidden.clock.dueIn()}ms`);
  hidden.clock.advance(); await flush();
  check('and it runs', hPoll.calls.n === 1);

  const sig = loadScheduler();
  const sigPoll = scripted(sig.clock, ['true', 'true']);
  sig.S.poll('g', { interval: 6000, run: sigPoll.run });
  sig.clock.advance(); await flush();       // first tick, on-line
  sig.window.fire('offline');
  check('the browser going off-line turns the state off-line without a failed request',
    sig.S.state().online === false && sig.document.banner.hidden === false);
  sig.window.fire('online');
  check('and coming back on-line clears it and wakes the polls',
    sig.S.state().online === true && sig.clock.dueIn() === 0, `due in ${sig.clock.dueIn()}ms`);

  console.log('');
  console.log('── The handle');

  const h = loadScheduler();
  const hh = scripted(h.clock, ['true']);
  const handle = h.S.poll('stopme', { interval: 1000, run: hh.run });
  handle.stop();
  h.clock.advance(); await flush();
  check('stop() holds the poll and leaves no timer behind', hh.calls.n === 0 && h.clock.count() === 0);
  handle.start();
  check('start() schedules it again', h.clock.count() === 1);
  h.clock.advance(); await flush();
  check('and it runs again', hh.calls.n === 1);

  const re = loadScheduler();
  const rePoll = scripted(re.clock, ['true', 'true']);
  re.S.poll('dup', { interval: 1000, run: rePoll.run });
  re.S.poll('dup', { interval: 1000, run: rePoll.run });
  check('re-registering a name replaces the poll instead of leaking a timer', re.clock.count() === 1, `${re.clock.count()} timers`);

  const kick = loadScheduler();
  const kPoll = scripted(kick.clock, ['true', 'true']);
  const kickHandle = kick.S.poll('kick', { interval: 9000, run: kPoll.run, immediate: false });
  check('immediate:false defers the first tick to the interval', kick.clock.dueIn() === 9000, `due in ${kick.clock.dueIn()}ms`);
  kickHandle.kick();
  check('kick() runs it now', kick.clock.dueIn() === 0, `due in ${kick.clock.dueIn()}ms`);

  const retime = loadScheduler();
  const tPoll = scripted(retime.clock, ['true', 'true']);
  const tHandle = retime.S.poll('retime', { interval: 1000, run: tPoll.run });
  retime.clock.advance(); await flush();
  retime.clock.advance(); await flush();
  tHandle.setInterval(7000);
  check('setInterval() re-times the poll', retime.clock.dueIn() === 7000, `due in ${retime.clock.dueIn()}ms`);

  const rst = loadScheduler();
  rst.S.poll('z', { interval: 1000, run: () => true });
  rst.S.report(false);
  rst.S.reset();
  check('reset() clears every poll, subscriber and the state', rst.clock.count() === 0 && rst.S.state().online === true);

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
