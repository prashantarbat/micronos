#!/usr/bin/env node
/**
 * Diagnostics terminal DOM harness — the streamed probe runner, driven in Node.
 * ============================================================================
 * `app/Views/diag/index.php` owns the appliance's one Diagnostic Terminal: the
 * ping and traceroute tools do not run a command and wait, they open a *session*
 * (`diag/ping/start`, `diag/traceroute/start`), poll it (`diag/stream`) for the
 * bytes produced since the previous poll, and close it early on request
 * (`diag/stream/stop`). One terminal is shared with the live packet capture, so
 * only one stream may run at a time. The PHP suite pins the endpoints and their
 * answers; what PHP cannot see is the console actually being *appended* to, poll
 * by poll, or the session being closed when the operator hits stop.
 *
 * So this harness runs the page's own script. It reads the shipped view (never a
 * copy — a copy would pass while the shipped code rotted), gives it a
 * hand-written `document`, a `FormData` that records what a request carried, and
 * a `MicronScheduler` whose answers the harness queues. It then drives:
 *
 *   1. **The streamed probe.** The ping form is posted to the start endpoint
 *      with the fields the controller validates and the CSRF token; the command
 *      the daemon ran and its first output land on the terminal; the start
 *      registers the stream poll; each poll reads `diag/stream` for that session
 *      and *appends* only the bytes it brought — an empty chunk adds nothing —
 *      and the answer that reports the run finished completes the probe, stops
 *      the poll and puts the stop button away.
 *
 *   2. **The CSRF hand-off.** Regeneration is on and the cookie is `HttpOnly`, so
 *      the hash the page rendered is spent by the first request; the harness pins
 *      that the next hash out of a response is what the *next* request carries,
 *      which is what stops every probe after the first from being refused.
 *
 *   3. **Traceroute, and stopping.** The traceroute form posts to its own
 *      endpoint with its own fields, and the stop button posts the session to the
 *      close endpoint, shows the daemon's closing output and stands the terminal
 *      down.
 *
 *   4. **The one-shot and the mock.** An answer with no session is a run that
 *      came back whole: it is rendered at once, no poll is registered, and a
 *      simulated one says so.
 *
 *   5. **The refusals.** A daemon that turns the probe down, an HTML error page
 *      from the permission filter, and a transport that never answered are three
 *      different failures, and none of them may leave a stream pretending to run.
 *
 *   6. **The outage.** A poll whose request fails resolves `false` — the shared
 *      scheduler's signal to back off — and leaves the probe running, whereas a
 *      poll the appliance *refuses* ends it quietly, without dumping the error
 *      into the console.
 *
 *   7. **One stream at a time, and the console controls.** Starting a probe over
 *      a live capture (or over another probe) closes what was running first, and
 *      clearing the console ends the stream and resets the status badge.
 *
 * Run it directly:
 *
 *     node tests/js/diagnostics_terminal_harness.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..', '..');
const VIEW = 'app/Views/diag/index.php';

const checks = [];
function check(name, cond, detail = '') {
  checks.push({ name, cond: !!cond, detail: String(detail) });
}

// The page's <script>, with the three PHP echoes reduced to inert text so the
// body is valid JavaScript. The script is otherwise byte-for-byte the shipped
// one. Each echo is given a value the harness can recognise: the base URL is a
// bare root so a request URL is the route itself, and the CSRF pair is a named
// token and a rendered hash so adoption of the replacement is assertable. The
// values are substituted bare because the view already quotes each echo (
// `const BASE_URL = '<?= base_url() ?>'`), so the echo is the string's contents.
const php = fs.readFileSync(path.join(ROOT, VIEW), 'utf8');
const scriptMatch = php.match(/<script>([\s\S]*?)<\/script>/);
if (!scriptMatch) {
  console.error(`No <script> block was found in ${VIEW}.`);
  process.exit(1);
}
const script = scriptMatch[1]
  .replace(/<\?php[\s\S]*?\?>/g, '')
  .replace(/<\?=\s*base_url\(\s*'([^']*)'\s*\)\s*\?>/g, '"$1"')
  .replace(/<\?=\s*base_url\(\s*\)\s*\?>/g, '/')
  .replace(/<\?=\s*csrf_token\(\s*\)\s*\?>/g, 'csrf_token_name')
  .replace(/<\?=\s*csrf_hash\(\s*\)\s*\?>/g, 'rendered-hash')
  .replace(/<\?=[\s\S]*?\?>/g, '"x"');

// ---------------------------------------------------------------------------
// A DOM just large enough for this page
// ---------------------------------------------------------------------------
function makeEl(tag) {
  let text = '';
  const el = {
    tagName: String(tag).toUpperCase(),
    id: '',
    className: '',
    value: '',
    checked: false,
    dataset: {},
    style: {},
    attrs: {},
    listeners: {},
    scrollTop: 0,
    scrollHeight: 0,
    offsetTop: 0,
    classList: { add() {}, remove() {}, contains() { return false; }, toggle() {} },
    setAttribute(key, value) { this.attrs[key] = value; },
    getAttribute(key) { return Object.prototype.hasOwnProperty.call(this.attrs, key) ? this.attrs[key] : null; },
    addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); },
    // The page's keydown handler clicks the button a field belongs to; the stub
    // dispatches to whatever the page bound, which is what the driver uses too.
    click() { for (const fn of (this.listeners.click || [])) fn({ preventDefault() {} }); },
    fire(type) { for (const fn of (this.listeners[type] || [])) fn({ preventDefault() {} }); },
    querySelector() { return null; },
    querySelectorAll() { return []; },
    appendChild() {},
    removeChild() {},
  };
  Object.defineProperty(el, 'textContent', {
    get() { return text; },
    set(value) { text = String(value); },
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
  querySelector() { return null; },
  querySelectorAll() { return []; },
  addEventListener() {},
};

// ---------------------------------------------------------------------------
// What a request carried, and the answers the harness hands back
// ---------------------------------------------------------------------------
class RecordingFormData {
  constructor() { this.entries = []; }
  append(key, value) { this.entries.push([String(key), String(value)]); }
  has(key) { return this.entries.some(([k]) => k === key); }
  get(key) { const found = this.entries.find(([k]) => k === key); return found ? found[1] : undefined; }
}

const env = {
  script: [],       // queued answers, shifted one per request
  requests: [],
  polls: [],
  pollStops: [],
  clipboard: null,
};

function answer(spec) { env.script.push(spec); }

const sandbox = {
  document,
  FormData: RecordingFormData,
  console,
  __env: env,
  __check: check,
  __answer: answer,
  performance: { now: () => Date.now() },
  // The copy button writes the console to the clipboard; recording the text is
  // what lets the harness pin that it copies what is on screen.
  navigator: { clipboard: { writeText: async (text) => { env.clipboard = String(text); return undefined; } } },
  // The page's own requests go through the shared scheduler. Here each one is
  // recorded, then answered from the queue; a spec that only throws is a
  // transport that never answered at all.
  MicronScheduler: {
    fetch: async (url, options = {}) => {
      const opts = options || {};
      const body = opts.body;
      const fields = body && Array.isArray(body.entries) ? Object.fromEntries(body.entries) : {};
      env.requests.push({ url: String(url), method: opts.method || 'GET', fields });

      const spec = env.script.shift();
      if (!spec) throw new Error('the harness queued no answer for ' + url);
      if (spec.throws) throw new Error(spec.throws);

      const text = spec.text !== undefined ? spec.text : JSON.stringify(spec.json || {});
      return {
        ok: spec.ok !== false,
        status: spec.status || 200,
        text: async () => text,
        json: async () => JSON.parse(text),
      };
    },
    poll: (name, options = {}) => {
      const handle = {
        name,
        interval: options.interval,
        run: options.run,
        stopped: false,
        stop() { handle.stopped = true; env.pollStops.push(name); },
      };
      env.polls.push(handle);
      return handle;
    },
    report: () => {},
  },
  setTimeout,
};
vm.createContext(sandbox);

// ---------------------------------------------------------------------------
// The driver, run in the page's own scope so it can reach the page's functions
// ---------------------------------------------------------------------------
const driver = `
;(async () => {
  // Every await in the page's probe path is a microtask except the transport, so
  // a handful of macrotasks flush each step without a real clock.
  const flush = async () => { for (let i = 0; i < 5; i += 1) await new Promise((r) => setTimeout(r, 0)); };
  const reset = () => {
    __env.script.length = 0;
    __env.requests.length = 0;
    __env.polls.length = 0;
    __env.pollStops.length = 0;
    __env.clipboard = null;
  };
  const req = (i) => __env.requests[i];
  const lastReq = () => __env.requests[__env.requests.length - 1];
  const askedFor = (fragment) => __env.requests.filter((r) => r.url.indexOf(fragment) !== -1);
  const term = () => document.getElementById('diagTerminal').textContent;
  const termClass = () => document.getElementById('diagTerminal').className;
  const badge = () => document.getElementById('termStatusBadge').textContent;
  const stopBtn = () => document.getElementById('btnStopTerminal').style.display;
  const livePoll = () => __env.polls[__env.polls.length - 1];
  const answer = __answer;
  const fill = (id, value) => { document.getElementById(id).value = value; };
  const click = (id) => { document.getElementById(id).click(); };
  const count = (haystack, needle) => haystack.split(needle).length - 1;

  // ---- 1. The streamed ping probe --------------------------------------
  reset();
  fill('pingHost', '203.0.113.7');
  fill('pingProto', 'ipv4');
  fill('pingSource', '');
  fill('pingCount', '4');
  fill('pingWait', '2');

  answer({ json: {
    cmd: 'ping -c 4 203.0.113.7',
    output: 'PING 203.0.113.7\\n64 bytes: ttl=57\\n',
    session: 'probe-1', running: true, csrf: 'hash-2',
  } });
  click('btnRunPing');
  await flush();

  const start = req(0) || { url: '', method: '', fields: {} };
  __check('the ping form is posted to the stream start endpoint', start.url === '/diag/ping/start', JSON.stringify(__env.requests));
  __check('the start is a POST', start.method === 'POST', start.method);
  __check('the start carries the ping fields the controller validates',
    start.fields.host === '203.0.113.7' && start.fields.ipproto === 'ipv4'
      && start.fields.sourceip === '' && start.fields.count === '4' && start.fields.wait === '2',
    JSON.stringify(start.fields));
  __check('the start carries the CSRF token and the rendered hash',
    start.fields.csrf_token_name === 'rendered-hash', JSON.stringify(start.fields));
  __check('the terminal shows the command the daemon ran', term().indexOf('$ ping -c 4 203.0.113.7') !== -1, term());
  __check('the daemon first output reaches the terminal', term().indexOf('ttl=57') !== -1, term());
  __check('the start registers the stream poll with the shared scheduler',
    livePoll() && livePoll().name === 'diag.probe' && livePoll().interval === 400,
    JSON.stringify(__env.polls.map((p) => p.name + '@' + p.interval)));

  // ---- 2. The poll appends only what it brought ------------------------
  const handle = livePoll();
  const before = term();
  answer({ json: { output: '', running: true, csrf: 'hash-3' } });
  await handle.run();
  __check('a poll reads the stream endpoint for that session',
    lastReq().url === '/diag/stream?session=probe-1', JSON.stringify(__env.requests));
  __check('an empty chunk appends nothing at all', term() === before, term());

  answer({ json: { output: '64 bytes: ttl=57\\n', running: true, csrf: 'hash-3' } });
  await handle.run();
  __check('a poll appends the bytes it brought to what is already there',
    count(term(), 'ttl=57') === 2 && term().indexOf('PING 203.0.113.7') !== -1, term());

  // ---- 3. The run finishes ---------------------------------------------
  answer({ json: { output: '--- 203.0.113.7 ping statistics ---\\n', running: false, csrf: 'hash-4' } });
  await handle.run();
  __check('the answer that reports the run finished completes the probe',
    badge() === 'COMPLETED' && termClass().indexOf('success') !== -1, badge() + ' / ' + termClass());
  __check('a finished stream stops the poll and puts the stop button away',
    handle.stopped === true && stopBtn() === 'none', handle.stopped + ' / ' + stopBtn());

  // ---- 4. The CSRF hand-off --------------------------------------------
  // The hash the poll adopted is what the *next* request must carry, and the stop
  // button is a POST — the only one left that has a body to inspect.
  reset();
  answer({ json: { cmd: 'ping -c 4 203.0.113.8', output: 'one\\n', session: 'probe-9', running: true, csrf: 'adopted-hash' } });
  click('btnRunPing');
  await flush();
  answer({ json: { output: 'stopped.\\n', csrf: 'adopted-hash-2' } });
  click('btnStopTerminal');
  await flush();
  const stopReq = askedFor('diag/stream/stop')[0] || { url: '', method: '', fields: {} };
  __check('stopping posts the session to the stream stop endpoint',
    stopReq.url === '/diag/stream/stop' && stopReq.method === 'POST' && stopReq.fields.session === 'probe-9',
    JSON.stringify(stopReq));
  __check('the hash the last answer carried is what the next request sends',
    stopReq.fields.csrf_token_name === 'adopted-hash', JSON.stringify(stopReq.fields));
  __check('stopping shows the daemon closing output and stands the terminal down',
    term().indexOf('stopped.') !== -1 && badge() === 'STOPPED' && stopBtn() === 'none',
    term() + ' / ' + badge() + ' / ' + stopBtn());

  // ---- 5. Traceroute posts its own fields ------------------------------
  reset();
  fill('traceHost', '198.51.100.9');
  fill('traceProto', 'ipv4');
  fill('traceSource', '');
  fill('traceTtl', '30');
  document.getElementById('traceResolve').checked = false;
  document.getElementById('traceIcmp').checked = true;
  answer({ json: { cmd: 'traceroute 198.51.100.9', output: 'traceroute to 198.51.100.9\\n', session: 'trace-1', running: true } });
  click('btnRunTrace');
  await flush();
  const trace = req(0) || { url: '', fields: {} };
  __check('traceroute posts to its own stream start endpoint', trace.url === '/diag/traceroute/start', trace.url);
  __check('traceroute carries its own fields, including the two checkboxes',
    trace.fields.host === '198.51.100.9' && trace.fields.ttl === '30'
      && trace.fields.useicmp === 'yes' && trace.fields.resolve === '',
    JSON.stringify(trace.fields));

  // ---- 6. A one-shot answer is a run that came back whole --------------
  reset();
  await stopProbe(true);
  reset();
  answer({ json: { output: 'whole run, at once\\n', csrf: 'm1' } });
  click('btnRunPing');
  await flush();
  __check('an answer with no session is rendered at once and registers no poll',
    term().indexOf('whole run, at once') !== -1 && __env.polls.length === 0,
    term() + ' / ' + JSON.stringify(__env.polls.map((p) => p.name)));
  __check('a run that came back whole is marked COMPLETED', badge() === 'COMPLETED', badge());

  // ---- 7. A simulated answer says so -----------------------------------
  reset();
  answer({ json: { output: 'simulated ping\\n', mock: true, csrf: 'm2' } });
  click('btnRunPing');
  await flush();
  __check('a simulated run is labelled as simulated',
    term().indexOf('simulated ping') !== -1 && term().indexOf('Development Prototyping Mode') !== -1, term());

  // ---- 8. The three refusals -------------------------------------------
  reset();
  answer({ json: { error: 'host must be an address, not an option' } });
  click('btnRunPing');
  await flush();
  __check('a probe the daemon turns down shows its reason and leaves nothing running',
    term().indexOf('not an option') !== -1 && badge() === 'REJECTED'
      && __env.polls.length === 0 && stopBtn() === 'none',
    term() + ' / ' + badge() + ' / ' + stopBtn());

  reset();
  answer({ ok: false, status: 403, text: '<html><body><h1>403 Forbidden</h1></body></html>' });
  click('btnRunPing');
  await flush();
  __check('an HTML refusal is reported as a refusal, not parsed as an answer',
    badge() === 'REJECTED' && term().indexOf('refused (HTTP 403)') !== -1 && term().indexOf('<html') === -1,
    term() + ' / ' + badge());

  reset();
  answer({ throws: 'connect ECONNREFUSED' });
  click('btnRunPing');
  await flush();
  __check('a transport that never answered fails the probe',
    badge() === 'FAILED' && term().indexOf('Could not run the ICMP Ping probe') !== -1, term() + ' / ' + badge());

  // ---- 9. An outage during the stream ----------------------------------
  reset();
  answer({ json: { output: 'a\\n', session: 'probe-outage', running: true } });
  click('btnRunPing');
  await flush();
  const outage = livePoll();
  answer({ throws: 'network down' });
  const reported = await outage.run();
  __check('a failed poll resolves false so the shared scheduler backs off', reported === false, String(reported));
  __check('a failed poll leaves the probe running',
    badge() === 'RUNNING' && probeSession === 'probe-outage'
      && outage.stopped === false && stopBtn() === 'inline-flex',
    badge() + ' / ' + probeSession + ' / ' + outage.stopped + ' / ' + stopBtn());

  // ---- 10. A poll the appliance refuses ends the probe quietly ---------
  const beforeRefusal = term();
  answer({ ok: false, status: 500, text: 'the stream died' });
  const refusedPoll = await outage.run();
  __check('a refused poll ends the probe quietly',
    refusedPoll === true && badge() === 'STOPPED' && probeSession === null && outage.stopped === true,
    String(refusedPoll) + ' / ' + badge() + ' / ' + probeSession);
  __check('a refused poll does not dump its error into the console',
    term() === beforeRefusal && term().indexOf('the stream died') === -1, term());

  // ---- 11. One stream at a time ----------------------------------------
  reset();
  fill('pcapIface', 'eth0');
  fill('pcapFilter', 'tcp port 443');
  fill('pcapCount', '50');
  answer({ json: { cmd: 'timeout 120 tcpdump -i eth0', output: 'listening\\n', session: 'cap-1' } });
  click('btnRunPcap');
  await flush();
  __check('the capture opens its session and polls it',
    (req(0) || {}).url === '/diag/packet-capture/start' && livePoll().name === 'diag.pcap',
    JSON.stringify(__env.requests.map((r) => r.url)) + ' / ' + JSON.stringify(__env.polls.map((p) => p.name)));

  // A probe started over the live capture must close it first, then open its own
  // session — and the capture's poll must go with it.
  reset();
  answer({ json: {} });   // the capture's own stop
  answer({ json: { cmd: 'ping -c 4 192.0.2.1', output: 'go\\n', session: 'probe-2', running: true } });
  click('btnRunPing');
  await flush();
  const captureStop = askedFor('diag/packet-capture/stop')[0] || { fields: {} };
  __check('a probe started over a live capture closes the capture first',
    captureStop.fields.session === 'cap-1', JSON.stringify(__env.requests.map((r) => r.url + ' ' + JSON.stringify(r.fields))));

  // And a probe started over another probe closes the first one.
  reset();
  answer({ json: {} });   // the first probe's own stop
  answer({ json: { cmd: 'ping -c 4 192.0.2.2', output: 'go\\n', session: 'probe-3', running: true } });
  click('btnRunPing');
  await flush();
  const probeStop = askedFor('diag/stream/stop')[0] || { fields: {} };
  __check('a probe started over another probe closes the one already running',
    probeStop.fields.session === 'probe-2' && probeSession === 'probe-3',
    JSON.stringify(probeStop.fields) + ' / ' + probeSession);

  // ---- 12. Clearing the console ----------------------------------------
  reset();
  answer({ json: {} });   // the running probe's own stop
  click('btnClearTerminal');
  await flush();
  __check('clearing the console ends the running stream',
    (askedFor('diag/stream/stop')[0] || { fields: {} }).fields.session === 'probe-3' && probeSession === null,
    JSON.stringify(__env.requests.map((r) => r.url)));
  __check('clearing the console resets the status badge to idle',
    term().indexOf('Console cleared') !== -1 && badge() === 'IDLE'
      && document.getElementById('termStatusBadge').className === 'mno-obj-chip'
      && document.getElementById('termLatencyBadge').style.display === 'none',
    term() + ' / ' + badge() + ' / ' + document.getElementById('termStatusBadge').className);

  // ---- 13. Copy puts the console on the clipboard ----------------------
  click('btnCopyTerminal');
  await flush();
  __check('copying hands the console text to the clipboard',
    __env.clipboard === term(), String(__env.clipboard).slice(0, 60));

  __env.done = true;
})();
`;

vm.runInContext(script + driver, sandbox, { filename: VIEW });

// The driver is asynchronous; wait for it to announce that it finished.
for (let i = 0; i < 400 && !env.done; i += 1) {
  await new Promise((resolve) => setTimeout(resolve, 0));
}
if (!env.done) {
  console.error('The diagnostics-terminal driver did not finish; the page script may have thrown.');
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

console.log(`ALL DIAGNOSTICS TERMINAL HARNESS CHECKS PASSED (${checks.length} checks)`);
