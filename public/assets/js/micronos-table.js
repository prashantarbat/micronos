/*!
 * micronos-table.js — the appliance's shared table-row builder.
 *
 * Every live screen draws rows out of an answer the page did not write:
 * conntrack states, SIEM events, cron executions, SNMP log lines. Each screen
 * grew its own loop, and each loop assembled its rows as an HTML string — so
 * each one escaped the fields it remembered to escape (a device name here, a
 * rule name there) while the field it forgot became markup: an event's action,
 * a log line's level, a command's output interpolated into an attribute.
 * Escaping is the kind of thing a page gets right once and wrong on the next
 * field, and there is no way to see the difference by reading the page.
 *
 * So the assembly itself is gone. A page hands this module its container, its
 * rows, and a column spec, and gets rows back:
 *
 *   MicronTable.render(container, events, {
 *     columns: [
 *       { text: 'timestamp', style: STYLE_STAMP },
 *       { text: (ev) => ev.src + ':' + ev.sport + ' → ' + ev.dst },
 *       { node: (ev) => MicronTable.badge(ev.action, actionChip(ev.action)) },
 *       { node: (ev) => MicronTable.button('Inspect', { onClick: () => inspect(ev) }) },
 *     ],
 *     empty: { text: 'No events yet.', colspan: 7 },
 *   });
 *
 * A cell's content is either a `text` (a field name, or a function of the row,
 * set as the cell's text) or a `node` the page built. There is no third option:
 * a spec that names `html`/`innerHTML` is *refused* rather than ignored, so the
 * way to put a wire value into a cell is a way that cannot interpret it. A page
 * can therefore get a cell's content wrong — the wrong field, the wrong class —
 * but it cannot make markup out of data, whatever the data says, on any page
 * that uses this module.
 *
 * `badge` and `button` are here because every screen needs the same two: a chip
 * whose class and label are set as a node, and a row action whose handler is a
 * listener closed over its own row. That last part is the other half of the same
 * promise — a handler bound to its row cannot be read out of an attribute, so
 * no field of a row is ever written where it could be re-parsed as one.
 *
 * Loaded synchronously from the layout, before any page script that builds a
 * row.
 */
(function (global) {
  'use strict';

  // A spec naming one of these is a page asking to hand us markup. Refusing it
  // loudly is the point of the module: there is no supported way to put a
  // string into a cell as markup, so a page that tries is a page that must be
  // rewritten to build a node instead of shipping something subtle.
  var MARKUP_KEYS = ['html', 'innerHTML', 'markup', 'rawHtml'];

  function isFunction(value) {
    return typeof value === 'function';
  }

  function doc() {
    return global.document || null;
  }

  function textOf(value) {
    return value === undefined || value === null ? '' : String(value);
  }

  /** A column's field name, or the value that function returns for this row. */
  function read(source, row) {
    return isFunction(source) ? source(row) : row[source];
  }

  /**
   * Sets attributes on a node. `setAttribute` is not markup parsing: a quote in
   * the value stays in the value, which is what makes a cell's `title` safe to
   * fill from a command's output.
   */
  function setAttributes(node, attributes) {
    if (!attributes) return;

    Object.keys(attributes).forEach(function (name) {
      var value = attributes[name];
      if (value === undefined || value === null) return;

      node.setAttribute(name, String(value));
    });
  }

  /** Throws unless the column says exactly one thing about its content. */
  function checkColumn(column, index) {
    if (!column || typeof column !== 'object') {
      throw new Error('MicronTable: column ' + index + ' must be an object');
    }

    MARKUP_KEYS.forEach(function (key) {
      if (key in column) {
        throw new Error(
          'MicronTable: column ' + index + ' names `' + key +
          '`; a cell is a `text` or a `node`, never markup'
        );
      }
    });

    var sources = ['text', 'node'].filter(function (key) {
      return key in column;
    });

    if (sources.length !== 1) {
      throw new Error('MicronTable: column ' + index + ' must name exactly one of `text` or `node`');
    }
  }

  /** One cell, holding either the row's text or the node the page built. */
  function cellFor(column, row, cellTag) {
    var cell = doc().createElement(cellTag);
    var cls = isFunction(column['class']) ? column['class'](row) : column['class'];
    if (cls) cell.className = cls;
    var style = isFunction(column.style) ? column.style(row) : column.style;
    if (style) cell.style.cssText = style;
    setAttributes(cell, isFunction(column.attrs) ? column.attrs(row) : column.attrs);

    if (column.node) {
      var node = read(column.node, row);
      if (node) cell.appendChild(node);
      return cell;
    }

    var value = read(column.text, row);
    if (!value && 'fallback' in column) value = column.fallback;
    cell.textContent = textOf(value);

    return cell;
  }

  /**
   * The one row shown when there is nothing to draw: the same row and cell tags
   * the rows would have used, holding the cell that spans the table.
   */
  function emptyRow(spec) {
    var empty = spec.empty || {};
    var row = doc().createElement(spec.rowTag || 'tr');
    var cell = doc().createElement(spec.cellTag || 'td');

    if (empty.colspan) cell.setAttribute('colspan', String(empty.colspan));
    if (empty.style) cell.style.cssText = empty.style;
    cell.textContent = textOf(empty.text);
    row.appendChild(cell);

    return row;
  }

  /**
   * Draws `rows` into `container` and returns the row nodes it built — one per
   * row, each cell filled by its column.
   *
   * The whole set is swapped in at once, so a re-render — a search, a poll — can
   * neither leave rows behind nor show a half-built table.
   */
  function render(container, rows, spec) {
    if (!container) return [];
    spec = spec || {};

    if (!Array.isArray(spec.columns) || spec.columns.length === 0) {
      throw new Error('MicronTable: render needs a non-empty `columns` array');
    }
    spec.columns.forEach(checkColumn);

    var list = Array.isArray(rows) ? rows : [];

    if (list.length === 0) {
      container.replaceChildren(emptyRow(spec));
      return [];
    }

    var rowTag = spec.rowTag || 'tr';
    var cellTag = spec.cellTag || 'td';

    var built = list.map(function (row) {
      var node = doc().createElement(rowTag);
      var rowClass = isFunction(spec.rowClass) ? spec.rowClass(row) : spec.rowClass;
      if (rowClass) node.className = rowClass;

      spec.columns.forEach(function (column) {
        node.appendChild(cellFor(column, row, cellTag));
      });

      return node;
    });

    container.replaceChildren.apply(container, built);

    return built;
  }

  /**
   * A badge: a span whose class, style and label are all set as a node. An
   * `icon`, when given, sits before the label, as the appliance's badges do.
   */
  function badge(label, className, style, icon) {
    var span = doc().createElement('span');
    if (className) span.className = className;
    if (style) span.style.cssText = style;
    if (icon) span.appendChild(icon);
    // A text node, not `textContent`, so the icon survives: the label is
    // appended beside the icon rather than replacing everything.
    span.appendChild(doc().createTextNode(textOf(label)));

    return span;
  }

  /**
   * A row action. Its handler is a listener closed over its own row, so the
   * row's fields are never written into an attribute — there is no attribute to
   * break out of and nothing to quote.
   */
  function button(label, options) {
    options = options || {};

    var node = doc().createElement('button');
    node.type = 'button';
    if (options.className) node.className = options.className;
    if (options.style) node.style.cssText = options.style;
    if (options.title) node.title = options.title;
    if (options.icon) node.appendChild(options.icon);
    node.appendChild(doc().createTextNode(textOf(label)));
    if (isFunction(options.onClick)) node.addEventListener('click', options.onClick);

    return node;
  }

  var MicronTable = {
    render: render,
    badge: badge,
    button: button
  };

  global.MicronTable = MicronTable;

  // For the harness, which runs this file outside a browser.
  if (typeof module === 'object' && module && module.exports) {
    module.exports = MicronTable;
  }
}(typeof window !== 'undefined' ? window : this));
