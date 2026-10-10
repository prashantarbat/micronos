<?php
/**
 * A multi-select object picker's `<select>`, filled with the *first page* of its
 * object list.
 *
 * The dialog used to render every object the appliance knows and filter them in
 * the DOM. The list is now paged in by `ObjectSearch`, and this partial is the
 * page the operator gets before any script has run: with a hundred objects it is
 * the whole list, with four thousand it is the first `per` of them, and the
 * `data-picker-*` attributes tell the browser what it is looking at so the
 * picker can say "showing 200 of 4,118 — keep typing to narrow" and page on.
 *
 * Usage:
 *
 *   <?= view('partials/pick_select', [
 *       'id'       => 'polSources',
 *       'name'     => 'sources[]',
 *       'size'     => 6,
 *       'class'    => 'mno-multiselect',
 *       'pickerSentinel' => ['value' => 'all', 'label' => 'all (0.0.0.0/0)'],
 *       'source'         => 'addressObjects',
 *       'search'         => base_url('firewall/objects/search'),
 *   ]) ?>
 *
 * `search` is the endpoint those rows came from — the same URL the page hands the
 * picker as its `source` on the screens that configure one (`firewall/objects/search`
 * behind the firewall permission, `routing/objects/search` behind the routing one,
 * …). It is written into the markup as `data-picker-url` with the kinds and the page
 * size beside it, so the box can tell a page of a catalogue from the catalogue
 * itself: `data-picker-more` already says the rest did not fit, and a picker that
 * was configured as a plain local list adopts this endpoint on load rather than
 * filtering the rendered slice forever and never reaching what it left out.
 *
 * `name` is optional. The policy pickers submit their selection themselves
 * (`sources[]`, `services[]`), but the address and service-database dialogs keep
 * the picked value in a hidden field the page syncs from the box's `change`
 * event, and a list box that is not part of the form should not carry a name.
 *
 * `pickerSentinel` is the "match anything" row: it stays in the markup (it is a
 * constant, not an object) so it is always present whatever the search returns.
 * It is passed by *every* caller — `null` where the picker has no such row —
 * because `Config\View::$saveData` is on, so a variable one render leaves behind
 * would otherwise be inherited by the next picker rendered in the same request
 * and give a PBR picker a sentinel it never asked for. It is also marked with
 * `data-picker-sentinel`, which is how the client tells the two apart: a value
 * that merely *equals* the sentinel (`all` is a real address object) is an
 * ordinary object on a picker that does not declare one.
 *
 * `search` follows exactly that rule, and for a sharper reason: an endpoint
 * inherited from the picker rendered before this one would have this box searching
 * another screen's catalogue, behind another screen's permission, while its own
 * rows stayed unreachable. Every caller names its own — `null` where the box
 * declares none.
 */
$__id       = (string) $id;
$__name     = isset($name) && (string) $name !== ''
    ? ' name="' . esc((string) $name, 'attr') . '"'
    : '';
$__size     = (int) ($size ?? 6);
$__class    = isset($class) ? ' class="' . esc((string) $class, 'attr') . '"' : '';
$__style    = isset($style) ? ' style="' . esc((string) $style, 'attr') . '"' : '';
$__sentinel = is_array($pickerSentinel ?? null) ? $pickerSentinel : null;
$__any      = $__sentinel['value'] ?? null;
$__search   = isset($search) && (string) $search !== '' ? (string) $search : '';

$__source = \App\Libraries\ObjectSearch::PICKER_SOURCES[$source ?? '']
    ?? \App\Libraries\ObjectSearch::PICKER_SOURCES['addressObjects'];

$__page = \App\Libraries\ObjectSearch::search([
    'kinds' => $__source['kinds'],
    'per'   => $__source['per'],
]);

$__options = '';
$__group   = null;

foreach ($__page['items'] as $__item) {
    if ($__any !== null && $__item['value'] === $__any) {
        continue;   // the sentinel row above is this object
    }

    if ($__item['group'] !== $__group) {
        if ($__group !== null) {
            $__options .= "  </optgroup>\n";
        }
        $__group    = $__item['group'];
        $__options .= '  <optgroup label="' . esc($__group, 'attr') . "\">\n";
    }

    $__options .= '    <option value="' . esc($__item['value'], 'attr') . '">'
        . esc(($__item['icon'] !== '' ? $__item['icon'] . ' ' : '') . $__item['label'])
        . "</option>\n";
}

if ($__group !== null) {
    $__options .= "  </optgroup>\n";
}

// What the box is looking at, for the script that filters it. The endpoint is
// only declared when the page named one: a picker with nowhere to search must not
// claim to be a page of anything.
$__attrs = ' data-picker-total="' . (int) $__page['total'] . '"'
    . ($__page['more'] ? ' data-picker-more="1"' : '')
    . ($__sentinel !== null ? ' data-picker-sentinel="1"' : '')
    . ($__search !== ''
        ? ' data-picker-url="' . esc($__search, 'attr') . '"'
            . ' data-picker-kinds="' . esc(implode(',', $__source['kinds']), 'attr') . '"'
            . ' data-picker-per="' . (int) $__source['per'] . '"'
        : '');
?>
<select<?= $__name ?> id="<?= esc($__id, 'attr') ?>" multiple size="<?= $__size ?>"<?= $__class . $__style ?><?= $__attrs ?>>
<?php if ($__sentinel !== null): ?>
  <option value="<?= esc((string) $__any, 'attr') ?>" selected><?= esc((string) ($__sentinel['label'] ?? $__any)) ?></option>
<?php endif; ?>
<?= $__options ?></select>
