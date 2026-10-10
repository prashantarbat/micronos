/*!
 * micronos-scheduler.js — the appliance's shared request scheduler.
 *
 * Every live screen on the appliance polls: the dashboard samples interface
 * throughput, the diagnostics pages tail firewall and conntrack state, the
 * service buttons refresh their LEDs, SIEM and SNMP and the web-proxy log tick
 * on their own timers. Each one grew its own `setInterval`, its own `try/catch`,
 * and its own silent `console.error` — so an appliance that goes away for a
 * minute makes every page fail N times, at N different moments, with N different
 * (or absent) messages, and none of them slow down while it is gone.
 *
 * This module is the one place that knows whether the appliance is reachable.
 * A page hands its work to it two ways:
 *
 *   MicronScheduler.poll(name, { interval, run })   // a recurring poll
 *   MicronScheduler.fetch(url, options)             // a one-shot AJAX call
 *
 * Both feed the same health state. The first network failure turns the shared
 * state off-line, paints the layout's one connection banner, and starts backing
 * every poll off — first attempt after the poll's own interval, then doubling to
 * a ceiling, with a little jitter so a page full of pollers does not retry in
 * lockstep. The first request that gets through again (a poll's own attempt, a
 * button, anything) clears the state, hides the banner, and wakes the other
 * pollers immediately.
 *
 * `poll`'s `run` contract: resolve normally when the request succeeded; resolve
 * `false` or reject when it failed. A poller that is deliberately idle (a paused
 * stream, a hidden tab) should resolve normally — that is not an outage. The
 * scheduler already pauses every poll while the tab is hidden and resumes on
 * `visibilitychange`, so a poller rarely needs its own check.
 *
 * The object picker keeps its own in-dialog note, but it reports through
 * `report()` and takes its retry delay from `backoffDelay()`, so the whole page
 * — the banner and the box — tells the same story about the same connection.
 *
 * Loaded synchronously from the layout, before any page script, so a poller
 * started at parse time finds it already there.
 */
(function (global) {
  'use strict';

  var BASE_DELAY = 1000;   // first step of a backed-off poll (its own interval)
  var MAX_DELAY  = 30000;  // ceiling once the backoff has grown
  var JITTER     = 0.15;   // ±15%, so several pollers do not fire together

  var polls     = {};       // name -> poll record
  var listeners = [];       // state subscribers
  var bound     = false;    // one set of global listeners per page

  var state = {
    online:   true,
    failures: 0,
    since:    0,
    error:    null,
    lastOk:   0
  };

  function now() { return Date.now(); }

  function doc() { return global.document || null; }

  function banner() {
    var d = doc();
    return d ? d.getElementById('mnoConnBanner') : null;
  }

  /* The layout's single banner. Everything the page owns repaints through here,
     so there is exactly one place a connection-lost state becomes visible. */
  function paint() {
    var el = banner();
    if (!el) return;

    var text = el.querySelector ? el.querySelector('.mno-conn-text') : null;
    if (text) {
      text.textContent = state.online
        ? ''
        : 'Connection lost — retrying automatically';
    }

    el.classList.toggle('is-offline', !state.online);
    el.hidden = state.online;
    el.setAttribute('aria-hidden', state.online ? 'true' : 'false');
  }

  function snapshot() {
    return {
      online:   state.online,
      failures: state.failures,
      since:    state.since,
      error:    state.error,
      lastOk:   state.lastOk
    };
  }

  function emit() {
    var snap = snapshot();
    listeners.slice().forEach(function (cb) {
      try { cb(snap); } catch (e) { /* a listener must not break the scheduler */ }
    });
  }

  /* The appliance answered. Only a transition back to on-line is worth telling
     the page about, and only then do the other pollers need waking. */
  function markUp() {
    var wasDown = !state.online;

    state.online   = true;
    state.failures = 0;
    state.error    = null;
    state.lastOk   = now();

    paint();
    if (wasDown) {
      emit();
      resumeAll();
    }
  }

  /* The appliance could not be reached (or a browser reported itself off-line).
     Every failure counts while down so the banner can say how long it has been,
     but only the first one is a transition the page needs to hear about. */
  function markDown(err) {
    var wasUp = state.online;

    if (wasUp) state.since = now();
    state.online = false;
    state.failures += 1;
    if (err) state.error = err;

    paint();
    if (wasUp) emit();
  }

  /**
   * The wait before retry number `failures` (1-based): `base`, then doubled,
   * capped at `max`. Exposed because the object picker's in-dialog note has to
   * name the same number the scheduler will use — one schedule, wherever it is
   * quoted.
   */
  function backoffDelay(failures, base, max) {
    var b = base > 0 ? base : BASE_DELAY;
    var m = max > 0 ? max : MAX_DELAY;
    var n = Math.max(1, failures || 1);

    return Math.min(b * Math.pow(2, n - 1), m);
  }

  function delay(rec) {
    var d = rec.failures > 0 ? backoffDelay(rec.failures, rec.interval, MAX_DELAY) : rec.interval;
    var j = d * JITTER;

    return Math.round(d - j + Math.random() * j * 2);
  }

  function clear(rec) {
    if (rec.timer !== null) {
      global.clearTimeout(rec.timer);
      rec.timer = null;
    }
  }

  function schedule(rec, soon) {
    if (rec.stopped) return;
    clear(rec);

    var ms = soon ? 0 : delay(rec);
    rec.timer = global.setTimeout(function () { fire(rec); }, ms);
  }

  /* One attempt has settled. The count is updated *before* the retry is armed,
     so the delay reflects the failure that just happened rather than the one
     before it; and the recovery is announced *after* arming, because a recovery
     wakes every poll — including this one — at once, and that must win. */
  function settle(rec, ok, err) {
    rec.running = false;

    if (ok) rec.failures = 0;
    else rec.failures += 1;

    schedule(rec);

    if (ok) markUp(); else markDown(err);
  }

  function fire(rec) {
    rec.timer = null;
    if (rec.stopped || rec.running) return;

    // A hidden tab is nobody's screen: hold the cadence rather than spend the
    // appliance's time on a page no one is looking at. Wake happens below.
    if (doc() && doc().hidden) { schedule(rec); return; }

    rec.running = true;

    var out;
    try {
      out = rec.run(rec);
    } catch (err) {
      out = Promise.reject(err);
    }

    Promise.resolve(out).then(function (ok) {
      settle(rec, ok !== false, null);
    }, function (err) {
      settle(rec, false, err);
    });
  }

  function resumeAll() {
    Object.keys(polls).forEach(function (name) {
      schedule(polls[name], true);
    });
  }

  function handle(rec) {
    return {
      name: rec.name,
      stop: function () { rec.stopped = true; clear(rec); return this; },
      start: function () { rec.stopped = false; schedule(rec, true); return this; },
      kick: function () { rec.stopped = false; schedule(rec, true); return this; },
      setInterval: function (ms) {
        if (ms > 0) rec.interval = ms;
        schedule(rec);
        return this;
      },
      isRunning: function () { return !rec.stopped; }
    };
  }

  var MicronScheduler = {
    /**
     * Register a recurring poll. `run` is called on the poll's own interval while
     * the appliance is reachable, backing off (doubling, capped, jittered) while
     * it is not, and the moment it comes back. Returns a handle to stop, start,
     * kick or re-time it.
     */
    poll: function (name, opts) {
      opts = opts || {};

      var rec = {
        name:     name,
        interval: Math.max(50, opts.interval || 5000),
        run:      opts.run || function () {},
        failures: 0,
        timer:    null,
        stopped:  false,
        running:  false
      };

      // A page that re-registers a name (a second service widget, a page that
      // re-mounts a panel) replaces the old poll rather than leaking its timer.
      if (polls[name]) clear(polls[name]);
      polls[name] = rec;

      if (opts.immediate === false) schedule(rec);
      else schedule(rec, true);

      return handle(rec);
    },

    /**
     * A fetch that feeds the shared state: any response — even a 500 — means the
     * appliance answered, so only a network/timeout failure marks it down.
     */
    fetch: function (url, options) {
      var out;
      try {
        out = global.fetch(url, options);
      } catch (err) {
        markDown(err);
        return Promise.reject(err);
      }

      return Promise.resolve(out).then(function (res) {
        markUp();
        return res;
      }, function (err) {
        markDown(err);
        throw err;
      });
    },

    /** Report the outcome of a request the scheduler did not itself make. */
    report: function (ok, err) {
      if (ok) markUp(); else markDown(err);
      return snapshot();
    },

    /** The shared backoff schedule, for a caller that quotes its own delay. */
    backoffDelay: backoffDelay,

    /** Subscribe to connection-state transitions; returns an unsubscribe. */
    on: function (cb) {
      if (typeof cb === 'function') listeners.push(cb);
      return function () {
        var i = listeners.indexOf(cb);
        if (i !== -1) listeners.splice(i, 1);
      };
    },

    state: snapshot,

    /** Test seam — drop every poll and subscriber without touching globals. */
    reset: function () {
      Object.keys(polls).forEach(function (name) { clear(polls[name]); });
      polls = {};
      listeners = [];
      state = { online: true, failures: 0, since: 0, error: null, lastOk: 0 };
      paint();
    }
  };

  /* The browser's own view of the link, plus the tab's: coming back on-line (a
     cable replugged, a VPN re-established) should not wait out a backoff. */
  function bind() {
    if (bound || typeof global.addEventListener !== 'function') return;
    bound = true;

    global.addEventListener('online', function () {
      markUp();
      resumeAll();
    });

    global.addEventListener('offline', function () {
      markDown(new Error('browser is offline'));
    });

    if (doc() && typeof doc().addEventListener === 'function') {
      doc().addEventListener('visibilitychange', function () {
        if (!doc().hidden) resumeAll();
      });
    }

    if (doc() && typeof doc().addEventListener === 'function') {
      doc().addEventListener('DOMContentLoaded', paint);
    }
  }

  // Bind once, at load: the browser's online/offline signal and the tab's own
  // visibility are page-wide facts, so every page gets them whether or not it
  // has a poller yet.
  bind();

  global.MicronScheduler = MicronScheduler;

  if (typeof module === 'object' && module && module.exports) {
    module.exports = MicronScheduler;
  }
}(typeof window !== 'undefined' ? window : this));
