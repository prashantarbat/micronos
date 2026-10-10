<?php
/**
 * Filterable, keyboard-driven object picker — a search box above a
 * `<select multiple>`.
 *
 * The pickers on the policy, address, service-database and policy-routing
 * dialogs list every object the box holds, so without a filter the only way to
 * reach one is Ctrl-click hunting. This shared helper gives all of them the
 * same behaviour:
 *
 *   type            filter which options are *shown* (never the selection)
 *   ↑ / ↓           move a highlight through the filtered list
 *   Space           toggle the highlighted object
 *   Enter           add the first match (or the highlighted one)
 *   Escape          clear the query
 *
 * Usage — include once per page, above the script that configures it:
 *
 *   <?= $this->include('partials/picker_search') ?>
 *
 *   MicronPickerSearch.init([
 *       { select: 'addrServices', search: 'addrServicesSearch', empty: 'addrServicesEmpty' },
 *   ]);
 *
 * `search` is the input's id, `empty` the id of the "no matches" note. Typing
 * never changes the selection, so an operator can search for the next object
 * while keeping what is already picked — and Enter, Space and Ctrl / Cmd-click
 * all *add* to that selection rather than replacing it. Picking dispatches
 * `change`, so the page's own onchange handler still syncs whatever hidden field
 * it maintains.
 * `any` names a sentinel value ("all" / "ALL") that always stays visible and is
 * never chosen by Enter — Space can still select it deliberately. Call
 * `MicronPickerSearch.reset()` — with the picker's select id, or with no
 * argument for all of them — when a dialog opens, so a query left over from a
 * previous edit cannot hide the options in it.
 *
 * Space toggles only once an arrow key has been pressed ("armed"): the box is a
 * text field, and half the objects are named with spaces ("Microsoft 365",
 * "Public DMZ Web/Mail Cluster"). Hijacking Space on every keystroke would
 * silently mangle those queries, so typing disarms again.
 *
 * Server-backed pickers — pass `source` to search the object table instead of a
 * list rendered into the page:
 *
 *   { select: 'polSources', search: 'polSourcesSearch', empty: 'polSourcesEmpty',
 *     any: 'all', source: { url: '…/firewall/objects/search',
 *                          kinds: ['group', 'address'], per: 200 } }
 *
 * The view renders the first page server-side (see partials/pick_select.php) and
 * this helper asks `source.url` for the rest: typing fetches a page, the end of
 * the list fetches the next one, and rows the operator has picked are always
 * requested back so the selection (and the chips and the submitted form) can
 * never be lost to a query that does not match them.
 *
 * A request that cannot be answered must not leave the box inert: the rows it
 * already holds become a local list, the query in flight is filtered against
 * them at once, picked rows stay visible, and typing keeps narrowing them while
 * the next request is on its way. The note says the search is unavailable and
 * which rows are being filtered, rather than leaving the operator to guess
 * whether an object is missing or merely unreachable — and the first query that
 * succeeds puts the whole catalogue back.
 *
 * A picker configured *without* `source` is a local list — unless the box it was
 * handed is really one page of a catalogue too big to hold. partials/pick_select.php
 * renders such a box from that same object search and declares where the rows came
 * from (`data-picker-url`, with the kinds and the page size beside it), so a picker
 * the page left local adopts that endpoint itself at load and pages from then on.
 * The trigger is the markup's own `data-picker-more`: the partial has already
 * counted what it could not render, and a box that holds the whole catalogue is
 * left alone — a local list needs no request and no debounce.
 *
 * A truncated box whose markup named no endpoint — or named one without kinds —
 * cannot be taken over. Rather than leave a list that answers "no match" for
 * objects that are plainly there, the picker logs one line to the console naming
 * the box and the argument `partials/pick_select.php` is missing, and stays local.
 * The console is the fix-it message for the screen's author; the operator still
 * only ever sees the status note under the box.
 */
?>
<script>
window.MicronPickerSearch = (function () {
  'use strict';

  // select id => its config, so filter/reset/pick can be called by id alone.
  var PICKERS = {};

  // The highlight that ↑ / ↓ move and Space toggles. Styled in micronos.css.
  var CURSOR_CLASS = 'mno-pick-cursor';

  // How a failed search is retried while the connection is down. An appliance's
  // connection is usually a cable or a link, so an outage is either brief or
  // deliberate — but typing through one used to cost a failing request on every
  // keystroke, each with a round trip's worth of waiting behind it. One attempt
  // per window, doubling to a cap, keeps the box honest through an outage
  // without turning it into a request storm, and the operator keeps a query that
  // filters the rows already in the page while they wait.
  var RETRY_BASE = 1000;   // ms before the first retry
  var RETRY_MAX  = 15000;  // ms between attempts once the backoff has grown

  /** The wait before attempt number `failures` — 1s, 2s, 4s … capped. */
  function retryDelay(failures) {
    var delay = RETRY_BASE * Math.pow(2, Math.max(0, (failures || 1) - 1));

    return Math.min(delay, RETRY_MAX);
  }

  // The shared connection state, when the page loaded the scheduler.
  //
  // A picker is one request among many on a page that polls throughput, tails
  // logs and fires buttons; its own note is what keeps the box usable, but the
  // *fact* that the appliance is unreachable belongs to the page, not to this
  // box. So a failed search also tells the shared scheduler — raising the one
  // connection banner — a successful answer clears it, and the wait before the
  // next attempt comes from the scheduler's own schedule. Absent (a DOM harness,
  // or a page that somehow never loaded it) the local formula above stands.
  function sharedReport(ok, err) {
    if (typeof window !== 'undefined' && window.MicronScheduler
        && typeof window.MicronScheduler.report === 'function') {
      window.MicronScheduler.report(ok, err);
    }
  }

  function sharedDelay(failures) {
    if (typeof window !== 'undefined' && window.MicronScheduler
        && typeof window.MicronScheduler.backoffDelay === 'function') {
      return window.MicronScheduler.backoffDelay(failures, RETRY_BASE, RETRY_MAX);
    }

    return retryDelay(failures);
  }

  // The object catalogue, kept in the browser.
  //
  // A picker is a search box over objects that live on the appliance, and the
  // connection that is down may be the thing that is broken — an operator who
  // reloads into a dead link otherwise gets a dialog whose one query fails and
  // whose list is the single page the markup happened to carry. So the catalogue
  // is stored as it is seen: the rows the page rendered (free — no request, and
  // rewritten on every load, which keeps its timestamp honest), the rows every
  // answer brings, and one bounded walk of the pages behind the rendered one
  // when the cache is missing or stale. The record is versioned so a future shape
  // cannot feed an old box junk, and it expires: a month-old catalogue is a
  // liability, not a fallback.
  var SNAP_PREFIX    = 'micron-pick-cat:v1:';
  var SNAP_MAX_ROWS  = 400;                       // a picker is a search box, not a copy of the database
  var SNAP_MAX_PAGES = 3;                         // how far the background walk goes
  var SNAP_FRESH_MS  = 6 * 60 * 60 * 1000;        // walk again after six hours
  var SNAP_TRUST_MS  = 30 * 24 * 60 * 60 * 1000;  // past this, do not answer from it at all

  // undefined until looked at, then the store or null. Kept because the probe
  // writes, and because a browser in private mode throws rather than hinting.
  var STORE;

  /** `localStorage` when this browser has a usable one, else null. Never throws. */
  function store() {
    if (STORE !== undefined) return STORE;

    STORE = null;

    try {
      var candidate = typeof localStorage !== 'undefined' ? localStorage : null;

      if (candidate) {
        candidate.setItem(SNAP_PREFIX + 'probe', '1');
        candidate.removeItem(SNAP_PREFIX + 'probe');
        STORE = candidate;
      }
    } catch (e) {
      STORE = null;   // private mode, a full quota, or storage switched off
    }

    return STORE;
  }

  /** One catalogue per endpoint and kind set — pickers over the same objects share it. */
  function snapKey(source) {
    return SNAP_PREFIX + source.url + '|' + source.kinds.join(',');
  }

  /**
   * The stored catalogue for a source, or null when there is nothing worth
   * trusting: a record from a different version, one with no timestamp, or one
   * older than `SNAP_TRUST_MS`.
   */
  function readSnapshot(source) {
    var s = store();
    if (!s) return null;

    try {
      var snap = JSON.parse(s.getItem(snapKey(source)) || 'null');
      if (!snap || snap.v !== 1 || !Array.isArray(snap.rows) || !snap.rows.length) return null;

      var age = Date.now() - (snap.at || 0);
      if (!(age >= 0) || age > SNAP_TRUST_MS) return null;

      return snap;
    } catch (e) {
      return null;
    }
  }

  /**
   * Remember the rows an answer carried, merged into what is already stored.
   *
   * Merged by value, so a query that returns three objects does not throw away
   * the three hundred an earlier one brought back. Bounded by `SNAP_MAX_ROWS`,
   * and if the browser refuses the record — a full quota almost always — the
   * oldest half is dropped and it is written once more: a big catalogue should
   * degrade into a smaller cache, not into no cache.
   */
  function writeSnapshot(source, rows, total) {
    var s = store();
    if (!s || !rows || !rows.length) return;

    var snap  = readSnapshot(source) || { v: 1, at: 0, total: 0, rows: [] };
    var seen  = {};

    snap.rows.forEach(function (row) { seen[row.value] = true; });

    for (var i = 0; i < rows.length && snap.rows.length < SNAP_MAX_ROWS; i++) {
      var row = rows[i];
      if (!row || !row.value || seen[row.value]) continue;

      seen[row.value] = true;
      snap.rows.push({
        value: row.value,
        label: row.label,
        icon: row.icon || '',
        group: row.group || 'Objects',
      });
    }

    snap.at    = Date.now();
    snap.total = Math.max(snap.total || 0, total || 0);

    try {
      s.setItem(snapKey(source), JSON.stringify(snap));
    } catch (e) {
      try {
        snap.rows = snap.rows.slice(-Math.floor(SNAP_MAX_ROWS / 2));
        s.setItem(snapKey(source), JSON.stringify(snap));
      } catch (e2) {
        // Still too big for this browser: leave what was there. The box works
        // exactly as it did before caching existed.
      }
    }
  }

  /** "just now" / "4 minutes ago" / "3 hours ago" / "2 days ago". */
  function ageText(ms) {
    if (!(ms > 60000)) return 'just now';

    var minutes = Math.round(ms / 60000);
    if (minutes < 60) return minutes + ' minute' + (minutes === 1 ? '' : 's') + ' ago';

    var hours = Math.round(minutes / 60);
    if (hours < 24) return hours + ' hour' + (hours === 1 ? '' : 's') + ' ago';

    var days = Math.round(hours / 24);

    return days + ' day' + (days === 1 ? '' : 's') + ' ago';
  }

  /**
   * " (cached 2 hours ago)" when the box is answering from the stored catalogue.
   *
   * The note has to say where the rows came from: "no match among these" reads
   * very differently when "these" is what the browser remembers rather than what
   * the appliance just said, and the age is what lets the operator judge it.
   */
  function cachedTail(cfg) {
    if (!cfg.cached) return '';

    return ' (cached ' + ageText(Date.now() - (cfg.cached.at || 0)) + ')';
  }

  /**
   * Put the stored catalogue into a box whose request failed.
   *
   * The rows the page rendered are one page of the catalogue; this is what makes
   * the rest of what the browser has seen searchable when the endpoint cannot be
   * reached — including after a reload, where nothing survives in memory. It is
   * only ever *more* rows than the box already had: a value already present is
   * left alone, so a picked row keeps its own node and nothing is duplicated.
   */
  function seedSnapshot(id) {
    var cfg = PICKERS[id];
    var sel = document.getElementById(id);
    if (!cfg || !sel || !cfg.source) return;

    var snap = readSnapshot(cfg.source);
    if (!snap) return;

    if (!cfg.present) {
      cfg.present = new Set(Array.prototype.map.call(sel.options, function (opt) { return opt.value; }));
    }

    var added = 0;

    snap.rows.forEach(function (row) {
      if (!row || !row.value || cfg.present.has(row.value)) return;

      appendOption(sel, cfg, row, groupFor(sel, cfg, row.group || 'Objects'));
      cfg.present.add(row.value);
      added++;
    });

    // Remembered so the note can say where these rows came from and how old they
    // are: an operator deciding whether to trust a list has to know that.
    if (added) cfg.cached = { at: snap.at || 0, rows: added };
  }

  /**
   * Store the rows the server rendered into the page.
   *
   * This is the cache that costs nothing: the markup already carries the first
   * page, so every picker in the app is cached from the moment it is loaded, and
   * every load rewrites the record — which is also what keeps its age honest,
   * since those rows did come from the server a moment ago.
   */
  function harvestRendered(cfg, sel) {
    var rows = [];

    Array.prototype.forEach.call(sel.options, function (opt) {
      if (cfg.hasSentinel && opt.value === cfg.any) return;

      rows.push({
        value: opt.value,
        label: opt.textContent,
        // The markup's option text already carries the icon, so the label is
        // stored whole and re-rendered with no icon prefix: the row comes back
        // looking exactly like the one that was there.
        icon: '',
        group: opt.parentNode && opt.parentNode.tagName === 'OPTGROUP' ? opt.parentNode.label : '',
      });
    });

    writeSnapshot(cfg.source, rows, cfg.total);
  }

  /**
   * Walk the pages behind the rendered one, once, when the cache needs them.
   *
   * Only when the markup says the page is one page of a bigger catalogue, only
   * when there is a store to put the answer in, and — `walked` being the map
   * `init` hands down, one per load — only once per catalogue per page load, so
   * two pickers over the same kinds share the one walk. A prefetch with nowhere
   * to put its answer, or one that repeats on every page load, is a request for
   * nothing. Failures are silent: the box's own path is what reports a connection
   * to the operator, and a prefetch that could not run cost nothing.
   */
  function prefetchMissing(cfg, sel, walked) {
    if (!cfg.source || sel.dataset.pickerMore !== '1' || !store()) return;

    var key = snapKey(cfg.source);
    if (walked[key]) return;

    var snap = readSnapshot(cfg.source);
    if (snap && (Date.now() - (snap.at || 0)) < SNAP_FRESH_MS) return;

    walked[key] = true;

    var source = cfg.source;
    var page   = 1;

    var walk = function () {
      var url = new URL(source.url, window.location.href);
      url.searchParams.set('kinds', source.kinds.join(','));
      url.searchParams.set('per', String(source.per));
      url.searchParams.set('page', String(page));

      fetch(url.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        credentials: 'same-origin',
      })
        .then(function (response) {
          if (!response.ok) throw new Error('HTTP ' + response.status);
          return response.json();
        })
        .then(function (data) {
          writeSnapshot(source, data.items || [], data.total);

          if (data.more && page < SNAP_MAX_PAGES) {
            page++;
            walk();
          }
        })
        .catch(function () {});
    };

    walk();
  }

  function setOptionVisible(opt, visible) {
    // Both are set: Chromium and Firefox honour `hidden` on an option, an older
    // engine that ignores it still gets the display:none fallback. Written only
    // when the state actually changes: over a long catalogue one keystroke hides
    // or reveals a handful of rows and leaves the rest exactly where the last
    // one put them, so the writes — and the style recalculation each one asks
    // for — are skipped.
    var hidden = !visible;
    if (opt.hidden === hidden && (opt.style.display === 'none') === hidden) return;
    opt.hidden = hidden;
    opt.style.display = hidden ? 'none' : '';
  }

  function pickerInput(cfg) {
    return cfg ? document.getElementById(cfg.search) : null;
  }

  /** The options an operator can actually see — the ones the cursor walks. */
  function visibleOptions(sel) {
    return Array.prototype.filter.call(sel.options, function (o) { return !o.hidden; });
  }

  /**
   * Keep the cursor on screen, stepping the list box by exactly the distance the
   * row is out by.
   *
   * Deliberately not `opt.offsetTop`: Chromium reports a listbox option's offset
   * against the document, not against the `<select>`'s own scroller, so a
   * "put the row at the top" calculation from it scrolls the box to its end
   * (measured: the first option reports offsetTop 1373 in a 173px-tall box).
   * The rects, on the other hand, are comparable. An engine that reports no
   * geometry for the row leaves the scroll position alone.
   */
  function revealCursor(sel, opt) {
    var box = sel.getBoundingClientRect();
    var row = opt.getBoundingClientRect();
    if (!row.height) return;

    if (row.top < box.top) sel.scrollTop -= box.top - row.top;
    else if (row.bottom > box.bottom) sel.scrollTop += row.bottom - box.bottom;
  }

  /**
   * Move the highlight. It is only shown once an arrow key has armed the list:
   * an untyped, unarmed picker looks the same as it always did, so nothing
   * implies a keyboard state the operator has not entered.
   */
  function paintCursor(id) {
    var cfg = PICKERS[id];
    var sel = document.getElementById(id);
    if (!cfg || !sel) return;

    var show = !!(cfg.armed && cfg.cursor && !cfg.cursor.hidden);
    var next = show ? cfg.cursor : null;

    // Only the row that moved is touched. Repainting every option on each arrow
    // press — and after every keystroke, through `disarm` — is a full pass over
    // the list, which is what made the highlight cost as much as the filter.
    if (next === cfg.painted) return;

    if (cfg.painted) cfg.painted.classList.remove(CURSOR_CLASS);
    if (next) {
      next.classList.add(CURSOR_CLASS);
      revealCursor(sel, next);
    }

    cfg.painted = next;
  }

  function disarm(id) {
    var cfg = PICKERS[id];
    if (!cfg) return;
    cfg.armed = false;
    cfg.cursor = null;
    paintCursor(id);
  }

  // ------------------------------------------------------------------
  // Server-backed pickers
  // ------------------------------------------------------------------

  /**
   * The note under the box. On a local picker it only ever says "no matches";
   * on a server-backed one it also carries the paging state and any failure,
   * because with a page of a larger result set the operator cannot tell the
   * difference between "that is everything" and "there is more".
   */
  function status(id, state, message) {
    var cfg = PICKERS[id];
    var el = cfg ? document.getElementById(cfg.empty) : null;
    if (!el) return;

    if (!state) {
      el.hidden = true;
      // Nothing left to say, so none of the states apply either: a class left
      // behind would have the box still claiming it is offline — or that a page
      // is not the whole list — while it is quietly showing a good answer.
      el.classList.remove('mno-pick-empty', 'mno-pick-status', 'mno-pick-offline');
      return;
    }

    el.textContent = message;
    el.hidden = false;
    el.classList.toggle('mno-pick-empty', state === 'empty' || state === 'error' || state === 'offline');
    el.classList.toggle('mno-pick-status', state === 'note');
    // 'offline' is the note that is also an indicator: the search could not be
    // answered, the box is still usable, and an attempt is already scheduled.
    el.classList.toggle('mno-pick-offline', state === 'offline');
  }

  function findOption(sel, value) {
    for (var i = 0; i < sel.options.length; i++) {
      if (sel.options[i].value === value) return sel.options[i];
    }
    return null;
  }

  function isSentinel(cfg, item) {
    return cfg.any !== null && cfg.any !== undefined && item.value === cfg.any;
  }

  function selectedValues(sel) {
    return Array.prototype.map.call(sel.selectedOptions, function (o) { return o.value; });
  }

  function applySelection(sel, values) {
    var wanted = {};
    (values || []).forEach(function (value) { wanted[value] = true; });
    Array.prototype.forEach.call(sel.options, function (o) { o.selected = !!wanted[o.value]; });
  }

  /**
   * Every value the picker must keep a row for: what is selected now plus a
   * selection the page has just asked for. These ride along on each request as
   * `include[]`, so a query that does not match them cannot drop them from the
   * list — and so from the form that is about to be submitted.
   */
  function includeValues(cfg, sel) {
    var values = [];

    selectedValues(sel).concat(cfg.pending || []).forEach(function (value) {
      if (value === '') return;
      // The sentinel is a constant the markup already carries, so it never
      // needs fetching — but only where such a row actually exists. A picker
      // that has none (the PBR dialog, whose box has no "any" row) must be
      // able to pick the object *named* like the sentinel, so it is requested
      // back like any other value.
      if (cfg.hasSentinel && isSentinel(cfg, { value: value })) return;
      if (values.indexOf(value) === -1) values.push(value);
    });

    return values;
  }

  function notify(id) {
    var sel = document.getElementById(id);
    if (sel) sel.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function groupFor(sel, cfg, label) {
    if (!cfg.groups) cfg.groups = {};
    if (cfg.groups[label]) return cfg.groups[label];

    // The server rendered the first page complete with its headings, and this
    // picker never saw them being built, so the map above starts empty. An
    // appended page therefore adopts the heading that is already on screen
    // instead of printing a second one with the same label under it.
    for (var i = 0; i < sel.children.length; i++) {
      var node = sel.children[i];
      if (node.tagName === 'OPTGROUP' && node.label === label) {
        cfg.groups[label] = node;
        return node;
      }
    }

    var box = document.createElement('optgroup');
    box.label = label;
    sel.appendChild(box);
    cfg.groups[label] = box;

    return box;
  }

  function appendOption(sel, cfg, item, box) {
    var opt = document.createElement('option');
    opt.value = item.value;
    // textContent, never innerHTML: object names are operator-supplied.
    opt.textContent = (item.icon ? item.icon + ' ' : '') + item.label;
    (box || groupFor(sel, cfg, item.group || 'Objects')).appendChild(opt);

    return opt;
  }

  /**
   * Drop every rendered row.
   *
   * The sentinel is not preserved here: it is a constant the markup carried, so
   * the caller re-inserts it from its own snapshot. Keeping a node in place
   * would not work anyway — the sentinel on a policy picker is a plain option,
   * but on a picker whose objects are grouped it can sit inside an `<optgroup>`,
   * and removing the group would take it with it.
   */
  function clearResults(sel) {
    while (sel.firstChild) sel.removeChild(sel.firstChild);
  }

  /**
   * Put the page the server rendered back, without a request. This is what makes
   * reopening a dialog instant on a small install and cheap on a large one.
   */
  function restoreInitial(sel, cfg) {
    clearResults(sel);
    (cfg.initial || []).forEach(function (node) { sel.appendChild(node.cloneNode(true)); });
    cfg.groups  = {};
    cfg.painted = null;
    cfg.present = new Set(Array.prototype.map.call(sel.options, function (o) { return o.value; }));
  }

  /**
   * A lower-cased haystack per option, built once per `<select>` and reused for
   * every keystroke after it.
   *
   * A catalogue of a few thousand rows is cheap to *scan*, but not to re-string
   * on every character: reading `textContent`, lower-casing it and allocating
   * the result for every option, on every input event, is what made typing over
   * a long list cost a frame. The index does that work once and keeps the group
   * each row belongs to, so filtering becomes a walk over plain strings.
   *
   * Rebuilt only when the option count stops matching the index — a picker whose
   * rows are swapped underneath it — which never happens on a local picker.
   */
  function buildIndex(cfg, sel) {
    var entries = [];
    var groups  = [];

    Array.prototype.forEach.call(sel.children, function (node) {
      if (node.tagName === 'OPTGROUP') {
        var gi = groups.length;
        groups.push({ node: node, hay: String(node.label || '').toLowerCase() });

        Array.prototype.forEach.call(node.children, function (opt) {
          if (opt.tagName !== 'OPTION') return;
          entries.push({
            opt:   opt,
            gi:    gi,
            value: opt.value,
            // A NUL never occurs in a query, so text and value cannot bleed into
            // one another: `foo\u0000bar` never matches "foobar".
            hay:   (opt.textContent + '\u0000' + opt.value).toLowerCase(),
          });
        });
      } else if (node.tagName === 'OPTION') {
        entries.push({
          opt:   node,
          gi:    -1,
          value: node.value,
          hay:   (node.textContent + '\u0000' + node.value).toLowerCase(),
        });
      }
    });

    var zero = [];
    for (var k = 0; k < groups.length; k++) zero.push(0);
    cfg.groupHits = zero;
    cfg.index     = { entries: entries, groups: groups, count: sel.options.length };

    return cfg.index;
  }

  /**
   * Show only the options matching `term`. Matching is case-insensitive over the
   * option's visible text *and* its value, and a group's own label matches too,
   * so typing "cloud" reveals a whole category at once.
   *
   * Returns how many objects matched, which is what a degraded box reports: it is
   * the difference between "your query matches none of the rows here" and "the
   * rows here are all the box has". `keepPicked` is for that same degraded box —
   * see `keepPickedVisible`.
   */
  function filter(id, term, keepPicked) {
    var cfg = PICKERS[id];
    var sel = document.getElementById(id);
    if (!cfg || !sel) return;

    var index = (cfg.index && cfg.index.count === sel.options.length)
      ? cfg.index
      : buildIndex(cfg, sel);

    var q     = (term || '').trim().toLowerCase();
    var any   = cfg.any || null;
    var all   = q === '';
    var hits  = 0;
    var group = cfg.groupHits;

    for (var g = 0; g < group.length; g++) group[g] = 0;

    var entries = index.entries;
    for (var i = 0; i < entries.length; i++) {
      var e    = entries[i];
      var keep;

      if (all) {
        keep = true;
      } else if (e.gi >= 0 && index.groups[e.gi].hay.indexOf(q) !== -1) {
        keep = true;                       // the category label itself matched
      } else {
        keep = e.hay.indexOf(q) !== -1;
      }

      // The "match anything" sentinel is a constant, never a search result.
      if (!all && any !== null && e.gi < 0 && e.value === any) keep = true;

      setOptionVisible(e.opt, keep);

      if (keep) {
        if (e.gi >= 0) group[e.gi]++;
        if (!(any !== null && e.value === any)) hits++;
      }
    }

    for (var j = 0; j < index.groups.length; j++) {
      // An empty group header above nothing reads as a broken list.
      setOptionVisible(index.groups[j].node, group[j] > 0);
    }

    // After the headings, so a picked row can bring its own heading back.
    if (keepPicked) keepPickedVisible(sel);

    cfg.lastHits = hits;

    var empty = document.getElementById(cfg.empty);
    if (empty) empty.hidden = !(q !== '' && hits === 0);

    // A degraded box is playing two roles with one line of text: the query's own
    // "no match" answer and the connection-lost note. The note wins, and it is
    // rebuilt here so that typing through an outage keeps it true — otherwise the
    // indicator would vanish on the first query that matched something, which is
    // exactly when the operator is least likely to notice the box is offline.
    offlineStatus(id);

    // A new query invalidates the old highlight: it points at the previous
    // list, and the operator is typing rather than walking the options.
    disarm(id);

    return hits;
  }

  /**
   * Reveal the rows a picked value rides on, and the heading above them.
   *
   * A box answering from the rows it holds — because its last request failed —
   * keeps picked rows visible exactly as the server path does, and for the same
   * reason: the form submits from these options, and a row the operator cannot
   * see is a row they cannot tell is still chosen. A hidden `<optgroup>` hides
   * everything in it, so the heading is brought back with the row.
   */
  function keepPickedVisible(sel) {
    Array.prototype.forEach.call(sel.options, function (opt) {
      if (!opt.selected) return;

      setOptionVisible(opt, true);

      if (opt.parentNode && opt.parentNode.tagName === 'OPTGROUP') {
        setOptionVisible(opt.parentNode, true);
      }
    });
  }

  function currentQuery(id) {
    var input = pickerInput(PICKERS[id]);

    return input ? input.value.trim() : '';
  }

  function searchUrl(cfg, page) {
    var url = new URL(cfg.source.url, window.location.href);
    var sel = document.getElementById(cfg.select);

    url.searchParams.set('kinds', cfg.source.kinds.join(','));
    url.searchParams.set('per', String(cfg.source.per));
    url.searchParams.set('page', String(page));

    var q = currentQuery(cfg.select);
    if (q !== '') url.searchParams.set('q', q);

    includeValues(cfg, sel).forEach(function (value) {
      url.searchParams.append('include[]', value);
    });

    return url.toString();
  }

  /**
   * Ask the server for one page. An answer is only allowed near the list while
   * it is still the newest thing asked for: typing quickly must not leave the
   * box showing an older query than the one in it.
   *
   * Abandoning the transfer is the fast path, not the guarantee. It stops a
   * slow answer from costing anything, but it is the wrong thing to *rely* on:
   * abort() can arrive after a response is already queued to run, a proxy may
   * have flushed it already, some transports report the caller's own abort as a
   * bare connection error, and a browser without `AbortController` has no signal
   * to hand over at all. What actually decides the order is the rank every
   * request carries: an answer — or a failure — from a request that is no longer
   * the newest one is dropped on sight, whatever it says. That one rule also
   * keeps a dropped connection that surfaces after the next query was answered
   * from marking a working box as degraded, and keeps a superseded answer from
   * releasing the gate the newest request is still holding.
   */
  function fetchPage(id, page, append) {
    var cfg = PICKERS[id];
    var sel = document.getElementById(id);
    if (!cfg || !sel || !cfg.source) return Promise.resolve(0);

    if (cfg.abort) cfg.abort.abort();

    // A request that is going out now is the newest word on the box, so anything
    // the backoff had scheduled is this request's job instead — one attempt, not
    // two, and never a duplicate of the same query.
    if (cfg.retry) {
      clearTimeout(cfg.retry);
      cfg.retry = null;
    }

    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    cfg.abort    = controller;
    cfg.loading  = true;

    // This request's rank, and the test for whether it still holds the field.
    var seq   = (cfg.seq = (cfg.seq || 0) + 1);
    var stale = function () { return seq !== cfg.seq; };

    var options = {
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      credentials: 'same-origin',
    };
    if (controller) options.signal = controller.signal;

    return fetch(searchUrl(cfg, page), options)
      .then(function (response) {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
      })
      .then(function (data) {
        // Before anything else. A superseded answer that clears the loading gate
        // on its way out would let a scroll ask for a page the request that
        // replaced it is already fetching — and that second request aborts the
        // one the operator is waiting on, whose answer then lands nowhere.
        if (stale()) return 0;

        cfg.loading = false;

        // The server answered, so the box is whole again: a query searches the
        // catalogue from here, not the handful of rows it happens to hold. The
        // failure count starts over with it, so an outage later in the session
        // is retried at a second again rather than where the last one left off.
        cfg.degraded = false;
        cfg.failures = 0;
        // The appliance answered: the shared state clears with the box.
        sharedReport(true);
        cfg.offline  = '';
        cfg.retryTail = '';
        // The rows are the server's again, so nothing here came from the cache:
        // the answer replaces the list, seeded rows included.
        cfg.cached   = null;

        return render(id, data, append);
      })
      .catch(function (err) {
        // A failure from a request nobody is waiting for any more says nothing
        // about the box: the request that replaced it is what decides whether
        // search works. Checked here rather than by error name, because our own
        // abort is not reported as an `AbortError` by every transport, and
        // degrading a box that has since been answered from the server would put
        // an outage note over a working list.
        if (stale()) return 0;

        cfg.loading = false;
        if (err && err.name === 'AbortError') return 0;

        fail(id, err);

        return 0;
      });
  }

  /** Draw a page of results, keeping the sentinel and every picked value. */
  function render(id, data, append) {
    var cfg = PICKERS[id];
    var sel = document.getElementById(id);
    if (!cfg || !sel) return 0;

    var wanted = cfg.pending || null;
    var added  = 0;
    // A pending selection is the page's word on what is picked, so it replaces
    // whatever was selected before; an ordinary query only re-filters and keeps
    // every choice the operator has already made.
    var keep   = wanted !== null ? [] : selectedValues(sel);

    if (!append) {
      clearResults(sel);
      cfg.groups  = {};
      cfg.painted = null;
      // A fresh result set: the values already on screen start over, with the
      // sentinel the markup carried (if any) as the only member.
      cfg.present = new Set();
      // The sentinel goes back first: it is a constant, not a search result, and
      // it has to survive every query exactly as the markup rendered it.
      if (cfg.sentinelNode) {
        var sentinel = cfg.sentinelNode.cloneNode(true);
        sel.appendChild(sentinel);
        cfg.present.add(sentinel.value);
      }
    } else if (!cfg.present) {
      cfg.present = new Set(Array.prototype.map.call(sel.options, function (o) { return o.value; }));
    }

    (data.items || []).forEach(function (item) {
      // A row that is already there is left alone: that covers a query re-run
      // over an overlap, and the sentinel, which owns the markup's own row on a
      // picker that has one. The Set keeps this O(1) per row rather than
      // rescanning every option the picker has accumulated so far.
      if (cfg.present.has(item.value)) return;
      appendOption(sel, cfg, item);
      cfg.present.add(item.value);
      added++;
    });

    var extras = [];
    (data.selected || []).forEach(function (item) {
      if (cfg.present.has(item.value)) return;
      cfg.present.add(item.value);
      extras.push(item);
    });
    if (extras.length) {
      var box = groupFor(sel, cfg, 'Selected');
      extras.forEach(function (item) { appendOption(sel, cfg, item, box); });
    }

    applySelection(sel, keep.concat(wanted || []));

    cfg.page  = data.page || 1;
    cfg.per   = data.per || cfg.source.per;
    cfg.total = typeof data.total === 'number' ? data.total : 0;
    cfg.more  = !!data.more;
    cfg.shown = Math.min(cfg.page * cfg.per, cfg.total);

    // Every answer is a look at the catalogue, so it is worth keeping: the objects
    // the operator has actually seen — including a picked value resolved back out
    // of an off-page one — are the ones a later reload can still search.
    if (cfg.source) {
      writeSnapshot(cfg.source, (data.items || []).concat(data.selected || []), cfg.total);
    }

    report(id);

    // An appended page leaves every loaded row where it is — so it leaves the
    // highlight where it is too. Disarming here would drop the cursor the walk
    // is standing on, and `moveCursor`'s own "step onto the new page" would
    // then find nothing to step from and wrap back to the top of the list.
    if (!append) disarm(id);

    if (wanted !== null || cfg.notify) {
      cfg.pending = null;
      cfg.notify  = false;
      notify(id);
    }

    return added;
  }

  function report(id) {
    var cfg = PICKERS[id];
    if (!cfg || !cfg.source) return;

    if (cfg.total === 0) {
      status(id, 'empty', cfg.emptyText);
    } else if (cfg.more) {
      status(id, 'note', 'Showing ' + cfg.shown + ' of ' + cfg.total + ' matches — keep typing to narrow');
    } else {
      status(id, null);
    }
  }

  /**
   * The search could not be answered, so the box answers from what it holds.
   *
   * A dropped connection used to leave an error note under a list that no longer
   * responded to typing: the operator had a note and no way to narrow anything.
   * The rows already on screen are still a list worth filtering, so a failed
   * request degrades the box into a local one — the query in flight is applied to
   * those rows at once, every picked value keeps a row of its own, and typing
   * keeps narrowing them while the next request is on its way. A connection that
   * never comes back therefore costs the operator the catalogue, not the box:
   * what is loaded stays searchable, and the note says exactly that instead of
   * leaving them to guess whether the object is missing or unreachable.
   */
  function fail(id, err) {
    var cfg = PICKERS[id];
    var sel = document.getElementById(id);
    if (!cfg || !sel) return;

    cfg.degraded = true;
    // The reason, kept apart from the rest of the note so `offlineStatus` can
    // recompose the sentence around a query that is still being typed.
    cfg.offline = 'Search unavailable — connection lost (' + ((err && err.message) || 'network error') + ')';

    // The page's one connection state learns about this failure too, so the
    // banner and this box tell the same story about the same connection.
    sharedReport(false, err);

    // Anything already picked keeps a row of its own — the form submits from
    // those options, so a transient failure must not silently strip objects from
    // the policy that is being saved.
    var missing = includeValues(cfg, sel).filter(function (value) {
      return cfg.present ? !cfg.present.has(value) : !findOption(sel, value);
    });
    if (missing.length) {
      var box = groupFor(sel, cfg, 'Selected');
      missing.forEach(function (value) {
        appendOption(sel, cfg, { value: value, label: value, icon: '', group: 'Selected' }, box).selected = true;
        if (cfg.present) cfg.present.add(value);
      });
    }

    // A failed request still has to settle a selection the page asked for, or
    // the chips and the exclusivity rule would never catch up with the form.
    if (cfg.pending !== null || cfg.notify) {
      cfg.pending = null;
      cfg.notify  = false;
      notify(id);
    }

    // Before the note is composed, and before the query is filtered: the stored
    // catalogue is more objects to search than the page happened to carry, and
    // the reload that brings the operator here is the case it exists for.
    seedSnapshot(id);

    // The operator's query is answered from the rows in the box rather than from
    // an error note, and the note is the one thing that has to be exact: "no
    // match" among the rows here is a different statement from "not found".
    var query = currentQuery(id);
    filter(id, query, true);

    cfg.failures = (cfg.failures || 0) + 1;

    retryLater(id);
  }

  /**
   * The connection-lost note: what happened, what the box is doing with the rows
   * it holds for the query that is in it, and when the next attempt is due.
   *
   * Written as a function rather than once at failure time because the operator
   * keeps typing while they wait: "no match among the rows here" and "filtering
   * the rows here" are different statements, and the note has to stay true — and
   * visible — as the query changes. Nothing here is an error the operator has to
   * act on, because the box is still usable and an attempt is already scheduled.
   */
  function offlineStatus(id) {
    var cfg = PICKERS[id];
    var sel = document.getElementById(id);
    if (!cfg || !sel || !cfg.degraded) return;

    var query = currentQuery(id);
    var rows  = loadedRows(cfg, sel);

    status(id, 'offline', cfg.offline + ' — ' + (query !== '' && cfg.lastHits === 0
      ? 'no match among the ' + rows + ' objects already loaded'
      : 'filtering the ' + rows + ' objects already loaded')
      + cachedTail(cfg)
      + (cfg.retryTail ? ' — ' + cfg.retryTail : ''));
  }

  /**
   * Schedule the next attempt, and say in the note when it is due.
   *
   * One attempt per window is the whole point of the backoff: a keystroke during
   * an outage is applied to the rows in the box and starts no request of its own,
   * and the attempt already scheduled asks for whatever query the box holds when
   * it runs — so nothing the operator types is lost by waiting, and a long outage
   * costs one failing request per window instead of one per keystroke. An answer
   * that lands clears the count (see `fetchPage`), so the next outage starts
   * again at a second, and a dialog reopened in the meantime is told to try again
   * at once (see `reset`).
   */
  function retryLater(id) {
    var cfg = PICKERS[id];
    if (!cfg || !cfg.source) return;

    if (cfg.retry) clearTimeout(cfg.retry);

    var delay = sharedDelay(cfg.failures);

    cfg.retryTail = 'retrying in ' + Math.round(delay / 1000) + 's';
    offlineStatus(id);

    cfg.retry = setTimeout(function () {
      cfg.retry = null;

      var live = PICKERS[id];

      // A dialog reopened in the meantime has its own answer coming, and a box
      // that recovered has nothing left to retry.
      if (!live || !live.degraded || !live.source) return;

      live.retryTail = 'retrying…';
      offlineStatus(id);

      fetchPage(id, 1, false);
    }, delay);
  }

  /** How many objects the box itself holds — a sentinel row is not one of them. */
  function loadedRows(cfg, sel) {
    return sel.options.length - (cfg.hasSentinel ? 1 : 0);
  }

  function loadMore(id) {
    var cfg = PICKERS[id];

    // A degraded box has nothing to page: the backoff's own attempt fetches the
    // first page of the current query, and asking for the next page of a
    // connection that is not answering would only fail too.
    if (!cfg || !cfg.source || !cfg.more || cfg.loading || cfg.degraded) return Promise.resolve(0);

    return fetchPage(id, (cfg.page || 1) + 1, true);
  }

  /** Typing asks for a new first page, once the operator pauses. */
  function scheduleSearch(id) {
    var cfg = PICKERS[id];
    if (!cfg || !cfg.source) return;

    cfg.pending = null;

    if (cfg.timer) clearTimeout(cfg.timer);
    cfg.timer = setTimeout(function () {
      cfg.timer = null;

      // A degraded box does not start requests from keystrokes: it is filtering
      // its own rows, and the backoff owns every attempt from here — one per
      // window, never one per keystroke, and never a second request racing the
      // attempt already in flight. The scheduled attempt reads the query out of
      // the box when it runs, so nothing typed while waiting is lost; only a new
      // dialog (see `reset`) or a selection the page asks for tries sooner.
      if (cfg.degraded) return;

      fetchPage(id, 1, false);
    }, 180);
  }

  /** Step the highlight by `delta`, wrapping at either end of the list. */
  function moveCursor(id, delta) {
    var cfg = PICKERS[id];
    var sel = document.getElementById(id);
    if (!cfg || !sel) return;

    var options = visibleOptions(sel);

    // Walk off the end of what is loaded and the next page is pulled in, rather
    // than wrapping back to the top of a list that is not the whole list.
    if (cfg.source && cfg.more && delta > 0 && !options.length) {
      loadMore(id).then(function (added) { if (added) moveCursor(id, delta); });
      return;
    }

    if (!options.length) {
      cfg.armed = true;
      cfg.cursor = null;
      paintCursor(id);
      return;
    }

    var at = options.indexOf(cfg.cursor);

    if (cfg.source && cfg.more && delta > 0 && at === options.length - 1) {
      loadMore(id).then(function (added) {
        if (added) {
          moveCursor(id, delta);
          return;
        }

        var fresh = visibleOptions(sel);
        cfg.armed  = true;
        cfg.cursor = fresh.length ? fresh[0] : null;
        paintCursor(id);
      });
      return;
    }

    var next = at === -1
      ? (delta > 0 ? 0 : options.length - 1)
      : (at + delta + options.length) % options.length;

    cfg.armed = true;
    cfg.cursor = options[next];
    paintCursor(id);
  }

  /** Space: toggle the highlighted object, keeping the highlight where it is. */
  function toggleCursor(id) {
    var cfg = PICKERS[id];
    var sel = document.getElementById(id);
    if (!cfg || !sel || !cfg.armed || !cfg.cursor || cfg.cursor.hidden) return false;

    // A deliberate toggle supersedes any selection the page was still asking for.
    cfg.pending = null;
    cfg.notify  = false;

    cfg.cursor.selected = !cfg.cursor.selected;

    // Assigning .selected programmatically fires nothing, and the page syncs its
    // chips / hidden field from the element's own onchange handler.
    sel.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }

  /**
   * Enter: add a match to the selection. The highlighted one when the operator
   * has walked to it, otherwise the first visible match.
   *
   * Adding, not replacing: a policy normally matches several objects, and Enter
   * used to clear everything picked before it, which made building a list of
   * five sources five separate Ctrl-clicks. Nothing is deselected here — the
   * page's own change handler still applies its rules (the `all` / `ALL`
   * sentinel stays exclusive) and the browser's Ctrl / Cmd-click multi-select is
   * untouched, because this helper binds no click handlers at all.
   *
   * The sentinel is never the target, so Enter cannot turn the field back into
   * "match everything". Pressing Enter twice over an unchanged list adds the
   * same object the second time, which is a no-op rather than a toggle — Space
   * is the key that toggles.
   */
  function pickCursor(id) {
    var cfg = PICKERS[id];
    var sel = document.getElementById(id);
    if (!cfg || !sel) return;

    cfg.pending = null;
    cfg.notify  = false;

    var any = cfg.any || null;
    var target = (cfg.armed && cfg.cursor && !cfg.cursor.hidden && cfg.cursor.value !== any) ? cfg.cursor : null;

    if (!target) {
      var options = visibleOptions(sel);
      for (var i = 0; i < options.length; i++) {
        if (options[i].value !== any) { target = options[i]; break; }
      }
    }
    if (!target) return;

    target.selected = true;

    cfg.armed = false;
    cfg.cursor = null;
    paintCursor(id);

    // Assigning .selected programmatically fires nothing, and the page syncs its
    // chips / hidden field from the element's own onchange handler.
    sel.dispatchEvent(new Event('change', { bubbles: true }));
  }

  /**
   * Show a selection the page already knows about — opening a rule for editing.
   *
   * On a server-backed picker those values are not guaranteed to be in the page,
   * so the selection is registered as *pending* and fetched back: the answer
   * carries a `Selected` row for each value, which is what keeps them in the
   * form that is about to be submitted.
   */
  function setSelection(id, values) {
    var cfg = PICKERS[id];
    var sel = document.getElementById(id);
    if (!cfg || !sel) return;

    var wanted = (values || []).map(String);

    if (cfg.source) {
      cfg.pending = wanted;
      cfg.notify  = true;
      fetchPage(id, 1, false);
      return;
    }

    applySelection(sel, wanted);
    notify(id);
  }

  /**
   * Everything the box was rendered with: the sentinel it carries, the paging
   * state the partial reported, and the rows already on screen.
   *
   * A picker that declared `source` and one that was promoted to it at load both
   * start from exactly this, so the dialog opens populated and the rest of the
   * list is fetched from where the server left off rather than page one again.
   */
  function adoptRenderedPage(cfg, sel) {
    // Whether the markup carries a dedicated "match anything" row, which the
    // partial flags with `data-picker-sentinel`. It is snapshotted so it can be
    // put back after every query. The policy pickers render one
    // (`all (0.0.0.0/0)`); the PBR pickers do not — there `all` is an ordinary
    // address object that has to stay pickable and searchable, so the flag cannot
    // be inferred from the value being present.
    cfg.hasSentinel  = sel.dataset.pickerSentinel === '1'
      && cfg.any !== null && cfg.any !== undefined;
    var anyNode = cfg.hasSentinel ? findOption(sel, cfg.any) : null;
    cfg.sentinelNode = anyNode ? anyNode.cloneNode(true) : null;

    // The view rendered the first page, so the dialog opens populated and the
    // paging state is read from the markup instead of fetched.
    cfg.per      = parseInt(cfg.source.per, 10) || 200;
    cfg.page     = 1;
    cfg.total    = parseInt(sel.dataset.pickerTotal || '0', 10) || 0;
    cfg.more     = sel.dataset.pickerMore === '1';
    cfg.shown    = Math.min(cfg.per, cfg.total);

    // Kept so reopening a dialog can restore it without a request.
    cfg.initial = [];
    Array.prototype.forEach.call(sel.children, function (node) {
      cfg.initial.push(node.cloneNode(true));
    });

    // The values already on screen, so a page appended later can skip a row it
    // already has without rescanning every option.
    cfg.present = new Set(Array.prototype.map.call(sel.options, function (o) { return o.value; }));

    var empty = document.getElementById(cfg.empty);
    cfg.emptyText = empty && empty.textContent
      ? empty.textContent.trim()
      : 'No matching objects';

    report(cfg.select);

    // Reaching the end of the loaded rows asks for the next page, the same as
    // walking off the end with the arrow keys.
    sel.addEventListener('scroll', function () {
      if (sel.scrollTop + sel.clientHeight >= sel.scrollHeight - 2) loadMore(cfg.select);
    });
  }

  /**
   * Take over a local list that is really one page of something much bigger.
   *
   * partials/pick_select.php renders the first page of an object search and says
   * so in the markup — `data-picker-more` when the catalogue continues past what
   * it rendered, and, when the screen passes it, the endpoint those rows came
   * from. A picker configured without `source` would otherwise filter that slice
   * for the rest of its life and never reach a single object outside it: the
   * operator types a name, the box answers "no match", and the object is there
   * all along. So the box adopts the endpoint itself, and because every path that
   * matters — typing, scrolling, walking off the end of the loaded rows, and
   * resolving a picked value that is not on the page — already branches on
   * `cfg.source`, all of it starts working from here.
   *
   * Only a truncated catalogue is taken over. `data-picker-more` is the partial's
   * own count of what it could not render, so it is the authoritative word that
   * the box does not hold the catalogue; a box without it holds all of it and
   * stays local, which is the cheap answer — no request, no debounce, nothing to
   * page. The endpoint is required for the same reason it cannot be guessed: a
   * rendered slice with nowhere to ask must stay what it looks like, rather than
   * have the picker start filtering a page it cannot extend. That refusal is not
   * silent: `warnUnsearchable` puts it in the console, where the screen's author
   * can see which box is holding back objects and which argument it is missing.
   */
  function promote(cfg, sel) {
    if (sel.dataset.pickerMore !== '1') return false;

    var url   = sel.dataset.pickerUrl;
    var kinds = String(sel.dataset.pickerKinds || '').split(',').filter(Boolean);

    // Nowhere to ask, or nothing to ask for. Both leave a truncated box unable
    // to reach its own catalogue, and both are the markup's omission rather than
    // the operator's mistake — so the box stays local, and says so out loud.
    if (!url || kinds.length === 0) {
      warnUnsearchable(cfg, sel, Boolean(url));

      return false;
    }

    cfg.source = {
      url:   url,
      kinds: kinds,
      // The page size those rows were rendered with: the next request has to ask
      // for the same one, or the pages would not line up with what is on screen.
      per:   parseInt(sel.dataset.pickerPer, 10) || 200,
    };

    return true;
  }

  /**
   * Report a truncated box that cannot search, instead of failing silently.
   *
   * Silence is the worst way to report this: the operator types a name that is
   * demonstrably on the appliance, the box answers "no match", and nothing
   * anywhere says the list was only ever one page of something bigger. The
   * console is where the person who can fix it — the one writing the screen — is
   * looking, so the notice names the box, how much of its catalogue it holds, and
 * which argument `partials/pick_select.php` is missing. The wording names the
 * partial rather than a `view()` call, so it stays a message about the markup and
 * cannot be mistaken for one of the call sites the convention test counts.
 *
   * `hasEndpoint` splits the two omissions: no endpoint at all means the rows
   * past the page have nowhere to come from, while an endpoint with no kinds
   * would be worse than useless — the request would name no kind and the server
   * would answer its default address catalogue, behind a list of countries.
   */
  function warnUnsearchable(cfg, sel, hasEndpoint) {
    var total = parseInt(sel.dataset.pickerTotal || '0', 10) || 0;

    warn('#' + cfg.select + ' shows the first ' + sel.options.length + ' of ' + total
      + ' objects but ' + (hasEndpoint
        ? 'declares no kinds to search for, so its requests would answer the default'
          + ' address catalogue instead; pass `source` to the partial that rendered it'
          + ' (partials/pick_select.php)'
        : 'declares no search endpoint, so the rest cannot be searched; pass `search`'
          + ' to the partial that rendered it (partials/pick_select.php)'));
  }

  /**
   * One line to the console, for the developer looking at it. Guarded because the
   * picker is also driven headlessly (the Node harness in `tests/js/`), where a
   * `console` may not be in scope; the message itself is never the picker's way of
   * reporting to the operator — that is the status note under the box.
   */
  function warn(message) {
    if (typeof console !== 'undefined' && console && typeof console.warn === 'function') {
      console.warn('MicronPickerSearch: ' + message);
    }
  }

  function isSpace(e) {
    return e.key === ' ' || e.key === 'Spacebar';
  }

  function init(config) {
    // One walk per catalogue per page load, and this is where a load is: `init`
    // is called once with every picker the page has, so a dialog with a sources
    // box and a destinations box over the same objects walks them once rather
    // than twice — and the guard is a local, so it cannot outlive the load that
    // created it.
    var walked = {};

    (Array.isArray(config) ? config : [config]).forEach(function (cfg) {
      var input = document.getElementById(cfg.search);
      var sel   = document.getElementById(cfg.select);
      if (!input || !sel || PICKERS[cfg.select]) return;

      cfg.armed       = false;
      cfg.cursor      = null;
      cfg.painted     = null;
      cfg.pending     = null;
      cfg.notify      = false;
      cfg.degraded    = false;   // set when a request fails; the box then filters locally
      cfg.failures    = 0;       // consecutive failed attempts; drives the backoff
      cfg.retry       = null;    // the scheduled attempt, while one is pending
      cfg.offline     = '';      // the connection-lost reason, set on each failure
      cfg.retryTail   = '';      // what the note says about the next attempt
      cfg.lastHits    = 0;       // how the last filter answered the query in the box
      cfg.cached      = null;    // rows seeded from the stored catalogue, with its age
      cfg.groups      = {};
      cfg.hasSentinel  = false;  // both set below when the box is a paged one
      cfg.sentinelNode = null;
      PICKERS[cfg.select] = cfg;

      // A box the page configured as a plain local list, but which is really one
      // page of a bigger catalogue, takes the endpoint over itself here.
      if (!cfg.source) promote(cfg, sel);

      if (cfg.source) adoptRenderedPage(cfg, sel);

      input.addEventListener('input', function () {
        if (cfg.source) {
          // A box whose last request failed narrows the rows it holds right away,
          // rather than leaving the operator waiting on a connection that is not
          // there. The query still reaches the server — through the attempt the
          // backoff already has scheduled, which asks for whatever the box says
          // when it runs — so the box is back to the whole catalogue the moment
          // the connection is.
          if (cfg.degraded) filter(cfg.select, input.value, true);

          scheduleSearch(cfg.select);
          return;
        }

        filter(cfg.select, input.value);
      });

      input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
          e.preventDefault();
          moveCursor(cfg.select, e.key === 'ArrowDown' ? 1 : -1);
        } else if (isSpace(e)) {
          // Only once the arrows have armed the list, so multi-word queries can
          // still be typed.
          if (toggleCursor(cfg.select)) e.preventDefault();
        } else if (e.key === 'Enter') {
          // The box sits inside the dialog's form, so Enter would otherwise
          // submit and save it. Treat it as "add a match" instead.
          e.preventDefault();
          pickCursor(cfg.select);
        } else if (e.key === 'Escape') {
          e.preventDefault();
          reset([cfg.select]);
        }
      });

      // A browser that restored a value on reload should show the matching
      // options straight away, not the full list. A server-backed picker starts
      // from the page the server rendered instead.
      if (!cfg.source) {
        filter(cfg.select, input.value);
        return;
      }

      // The catalogue, for the next load. The order matters: whether the pages
      // behind this one need walking is decided on the record as it stood before
      // this load confirmed anything, because storing the rendered rows stamps
      // the record — and a record that stamps itself fresh on every load would
      // never be walked at all.
      prefetchMissing(cfg, sel, walked);
      harvestRendered(cfg, sel);
    });
  }

  /** Clear the filters again — a dialog must open showing every option. */
  function reset(ids) {
    var keys = (Array.isArray(ids) && ids.length) ? ids : Object.keys(PICKERS);

    keys.forEach(function (id) {
      var cfg = PICKERS[id];
      if (!cfg) return;

      var input = pickerInput(cfg);
      if (input && input.value !== '') input.value = '';

      if (!cfg.source) {
        // Drop the search index: a dialog that has been reshaped since it was
        // last filtered should be re-read rather than trusted to the cache.
        cfg.index = null;
        filter(id, '');
        return;
      }

      var sel = document.getElementById(id);
      if (!sel) return;

      if (cfg.timer) clearTimeout(cfg.timer);
      cfg.timer = null;

      // A selection the page has just asked for is already being fetched, and
      // that request is the newest word on this list — a reset that follows it
      // (opening an edit dialog) must not throw it away. It is about to redraw
      // the box itself.
      if (cfg.pending !== null) return;

      // Otherwise drop everything the last dialog left behind.
      if (cfg.abort) cfg.abort.abort();
      cfg.abort    = null;
      // Whatever transfer is still running is now nobody's: the list is about to
      // be redrawn from the markup above, and an answer that outran the abort
      // must not land on top of it.
      cfg.seq      = (cfg.seq || 0) + 1;
      cfg.loading  = false;
      cfg.notify   = false;
      cfg.degraded = false;   // a reopened dialog tries the server again
      // The backoff goes with it: a dialog opened now should not be made to wait
      // out a delay the last one earned.
      if (cfg.retry) clearTimeout(cfg.retry);
      cfg.retry     = null;
      cfg.failures  = 0;
      cfg.offline   = '';
      cfg.retryTail = '';
      // The seeded rows go with the rest: reset puts the rendered page back, so
      // the note must not go on claiming a cache that is no longer in the box.
      cfg.cached    = null;

      // Back to the page the server rendered — no request, so reopening a
      // dialog costs nothing however large the object table is. Whatever the
      // caller has selected is re-applied on top of it.
      var keep = selectedValues(sel);
      restoreInitial(sel, cfg);
      applySelection(sel, keep);

      cfg.page  = 1;
      cfg.total = parseInt(sel.dataset.pickerTotal || '0', 10) || 0;
      cfg.more  = sel.dataset.pickerMore === '1';
      cfg.shown = Math.min(cfg.per, cfg.total);

      report(id);
      disarm(id);
    });
  }

  return {
    init: init,
    reset: reset,
    filter: filter,
    moveCursor: moveCursor,
    setSelection: setSelection,
  };
})();
</script>
