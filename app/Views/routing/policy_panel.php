<?php
/**
 * Routing Policy Panel (Access Lists, Prefix Lists, Route Maps, Protocol Bindings)
 * Included in routing/index.php and standalone routing/policy page.
 */
$pls = $prefixLists ?? [];
$rms = $routeMaps   ?? [];
$als = $accessLists ?? [];
$can = session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? []);

$activePolicySub = $_GET['sub'] ?? 'access-lists';
if (!in_array($activePolicySub, ['access-lists', 'prefix-lists', 'route-maps', 'associations'], true)) {
    $activePolicySub = 'access-lists';
}

$policyRefs = $policyRefs ?? [
    'accessLists' => [],
    'prefixLists' => $prefixListRefs ?? [],
    'routeMaps'   => $routeMapRefs ?? [],
];
?>

<style>
/* Policy Sub-Tabs */
.policy-sub-tabs {
  display: flex;
  gap: 0;
  border-bottom: 2px solid var(--color-border);
  margin-bottom: 20px;
  overflow-x: auto;
}
.policy-sub-tab {
  display: flex; align-items: center; gap: 6px;
  padding: 8px 16px;
  font-size: 0.8rem; font-weight: 600;
  color: var(--text-muted);
  cursor: pointer;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  transition: color .15s, border-color .15s;
  white-space: nowrap;
  background: none; border-top: none; border-left: none; border-right: none;
}
.policy-sub-tab:hover { color: var(--text-primary); }
.policy-sub-tab.active { color: var(--color-cyan); border-bottom-color: var(--color-cyan); }

.policy-sub-panel { display: none; }
.policy-sub-panel.active { display: block; }

/* Rule rows builder */
.builder-table {
  width: 100%; border-collapse: collapse; font-size: 0.8rem; margin: 8px 0;
}
.builder-table th {
  padding: 8px 10px; background: var(--color-surface-3); color: var(--text-muted);
  font-size: 0.7rem; text-transform: uppercase; font-weight: 700; border-bottom: 1px solid var(--color-border);
  text-align: left;
}
.builder-table td {
  padding: 6px 8px; border-bottom: 1px solid var(--color-border); vertical-align: middle;
}
.builder-table input, .builder-table select {
  width: 100%; padding: 5px 8px; font-size: 0.8rem; background: var(--color-surface-2);
  border: 1px solid var(--color-border); border-radius: var(--radius-sm); color: var(--text-primary);
}
.builder-table input:focus, .builder-table select:focus {
  outline: none; border-color: var(--color-cyan);
}
</style>

<!-- ── Policy Sub-Tab Bar ── -->
<div class="policy-sub-tabs">
  <button type="button" class="policy-sub-tab <?= $activePolicySub === 'access-lists' ? 'active' : '' ?>"
          onclick="switchPolicySub('access-lists', this)">
    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
    </svg>
    Access Lists (ACLs)
    <span class="rt-tab-count"><?= count($als) ?></span>
  </button>
  <button type="button" class="policy-sub-tab <?= $activePolicySub === 'prefix-lists' ? 'active' : '' ?>"
          onclick="switchPolicySub('prefix-lists', this)">
    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
    </svg>
    Prefix Lists
    <span class="rt-tab-count"><?= count($pls) ?></span>
  </button>
  <button type="button" class="policy-sub-tab <?= $activePolicySub === 'route-maps' ? 'active' : '' ?>"
          onclick="switchPolicySub('route-maps', this)">
    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
    </svg>
    Route Maps
    <span class="rt-tab-count"><?= count($rms) ?></span>
  </button>
  <button type="button" class="policy-sub-tab <?= $activePolicySub === 'associations' ? 'active' : '' ?>"
          onclick="switchPolicySub('associations', this)">
    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
    </svg>
    Protocol Associations
  </button>
</div>

<!-- ================================================================
     SUB-PANEL 1: Access Lists (ACLs)
     ================================================================ -->
<div class="policy-sub-panel <?= $activePolicySub === 'access-lists' ? 'active' : '' ?>" id="policy-sub-access-lists">
  <div class="card" style="padding:0; overflow-x:auto; margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 18px; border-bottom:1px solid var(--color-border); background:var(--color-surface-2);">
      <div>
        <span style="font-weight:700; font-size:0.88rem; color:var(--text-primary);">Configured Access Lists (Standard &amp; Extended)</span>
        <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">
          Filter networks by IP prefix or host address. Used in route-maps and distribute-lists.
        </div>
      </div>
      <?php if ($can): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="openCreateAccessList()">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Add Access List
        </button>
      <?php endif; ?>
    </div>
    <table class="rt-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Type</th>
          <th>Rules Summary</th>
          <th>Applied In / Associated With</th>
          <th>Description</th>
          <th>Status</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($als)): ?>
          <tr><td colspan="7" style="text-align:center; padding:32px; color:var(--text-muted);">No access lists configured. Click &ldquo;Add Access List&rdquo; to create one.</td></tr>
        <?php else: ?>
          <?php foreach ($als as $al): ?>
            <?php
              $rules = json_decode($al['rules'] ?? '[]', true) ?: [];
              $refs  = $policyRefs['accessLists'][$al['name']] ?? [];
            ?>
            <tr>
              <td><span class="rt-chip rt-chip-purple"><?= esc($al['name']) ?></span></td>
              <td><span class="rt-chip"><?= esc(ucfirst($al['type'] ?? 'standard')) ?></span></td>
              <td>
                <span class="badge badge-neutral" style="font-size:0.75rem;"><?= count($rules) ?> rules</span>
                <span style="font-size:0.74rem; color:var(--text-secondary); margin-left:6px; font-family:var(--font-mono);">
                  <?php
                    $summaries = array_map(static fn($r) => ($r['action'] ?? 'permit') . ' ' . ($r['prefix'] ?? ''), array_slice($rules, 0, 2));
                    echo esc(implode(', ', $summaries)) . (count($rules) > 2 ? ' ...' : '');
                  ?>
                </span>
              </td>
              <td>
                <?php if ($refs === []): ?>
                  <span style="color:var(--text-muted); font-size:0.75rem;">— none —</span>
                <?php else: ?>
                  <?php foreach ($refs as $rf): ?>
                    <span class="rt-chip rt-chip-cyan" style="font-size:0.7rem; margin:1px;"><?= esc($rf) ?></span>
                  <?php endforeach; ?>
                <?php endif; ?>
              </td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($al['description'] ?: '—') ?></td>
              <td><?= !empty($al['enabled']) ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Disabled</span>' ?></td>
              <td style="text-align:right; white-space:nowrap;">
                <?php if ($can): ?>
                  <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px;" onclick='openEditAccessList(<?= htmlspecialchars(json_encode($al), ENT_QUOTES, "UTF-8") ?>)' title="Edit Access List">
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                  </button>
                  <form method="POST" action="<?= base_url('routing/policy/access-lists/' . esc($al['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove access-list <?= esc(addslashes($al['name'])) ?>?')"><?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:var(--color-red);" title="Delete Access List">
                      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ================================================================
     SUB-PANEL 2: Prefix Lists
     ================================================================ -->
<div class="policy-sub-panel <?= $activePolicySub === 'prefix-lists' ? 'active' : '' ?>" id="policy-sub-prefix-lists">
  <div class="card" style="padding:0; overflow-x:auto; margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 18px; border-bottom:1px solid var(--color-border); background:var(--color-surface-2);">
      <div>
        <span style="font-weight:700; font-size:0.88rem; color:var(--text-primary);">Configured Prefix Lists</span>
        <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">
          Match IP and IPv6 prefixes with ge / le length filters for routing protocol policies and route-maps.
        </div>
      </div>
      <?php if ($can): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="openCreatePrefixList('policy')">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Add Prefix List
        </button>
      <?php endif; ?>
    </div>
    <table class="rt-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Rules Summary</th>
          <th>Applied In / Associated With</th>
          <th>Description</th>
          <th>Status</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($pls)): ?>
          <tr><td colspan="6" style="text-align:center; padding:32px; color:var(--text-muted);">No prefix-lists configured.</td></tr>
        <?php else: ?>
          <?php foreach ($pls as $pl): ?>
            <?php
              $rules = json_decode($pl['rules'] ?? '[]', true) ?: [];
              $refs  = $policyRefs['prefixLists'][$pl['name']] ?? [];
            ?>
            <tr>
              <td><span class="rt-chip rt-chip-cyan"><?= esc($pl['name']) ?></span></td>
              <td>
                <span class="badge badge-neutral" style="font-size:0.75rem;"><?= count($rules) ?> rules</span>
                <span style="font-size:0.74rem; color:var(--text-secondary); margin-left:6px; font-family:var(--font-mono);">
                  <?php
                    $summaries = array_map(static fn($r) => ($r['action'] ?? 'permit') . ' ' . ($r['prefix'] ?? '') . (!empty($r['ge']) ? ' ge ' . $r['ge'] : '') . (!empty($r['le']) ? ' le ' . $r['le'] : ''), array_slice($rules, 0, 2));
                    echo esc(implode(', ', $summaries)) . (count($rules) > 2 ? ' ...' : '');
                  ?>
                </span>
              </td>
              <td>
                <?php if ($refs === []): ?>
                  <span style="color:var(--text-muted); font-size:0.75rem;">— unreferenced —</span>
                <?php else: ?>
                  <?php foreach ($refs as $rf): ?>
                    <span class="rt-chip rt-chip-blue" style="font-size:0.7rem; margin:1px;"><?= esc($rf) ?></span>
                  <?php endforeach; ?>
                <?php endif; ?>
              </td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($pl['description'] ?: '—') ?></td>
              <td><?= !empty($pl['enabled']) ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Disabled</span>' ?></td>
              <td style="text-align:right; white-space:nowrap;">
                <?php if ($can): ?>
                  <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px;" onclick='openEditPrefixList(<?= htmlspecialchars(json_encode($pl), ENT_QUOTES, "UTF-8") ?>, "policy")' title="Edit Prefix List">
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                  </button>
                  <form method="POST" action="<?= base_url('routing/policy/prefix-lists/' . esc($pl['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove prefix-list <?= esc(addslashes($pl['name'])) ?>?')"><?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:var(--color-red);" title="Delete Prefix List">
                      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ================================================================
     SUB-PANEL 3: Route Maps
     ================================================================ -->
<div class="policy-sub-panel <?= $activePolicySub === 'route-maps' ? 'active' : '' ?>" id="policy-sub-route-maps">
  <div class="card" style="padding:0; overflow-x:auto; margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 18px; border-bottom:1px solid var(--color-border); background:var(--color-surface-2);">
      <div>
        <span style="font-weight:700; font-size:0.88rem; color:var(--text-primary);">Configured Route Maps</span>
        <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">
          Set routing policies, metrics, local preferences, and path prepend for BGP, OSPF, and route redistribution.
        </div>
      </div>
      <?php if ($can): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="openCreateRouteMap('policy')">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Add Route Map
        </button>
      <?php endif; ?>
    </div>
    <table class="rt-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Sequences</th>
          <th>Applied In / Associated With</th>
          <th>Description</th>
          <th>Status</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rms)): ?>
          <tr><td colspan="6" style="text-align:center; padding:32px; color:var(--text-muted);">No route-maps configured.</td></tr>
        <?php else: ?>
          <?php foreach ($rms as $rm): ?>
            <?php
              $rules = json_decode($rm['rules'] ?? '[]', true) ?: [];
              $refs  = $policyRefs['routeMaps'][$rm['name']] ?? [];
            ?>
            <tr>
              <td><span class="rt-chip rt-chip-blue"><?= esc($rm['name']) ?></span></td>
              <td>
                <span class="badge badge-neutral" style="font-size:0.75rem;"><?= count($rules) ?> sequences</span>
                <div style="font-size:0.74rem; color:var(--text-secondary); margin-top:4px;">
                  <?php foreach (array_slice($rules, 0, 3) as $r): ?>
                    <div>
                      <strong>seq <?= (int)($r['seq']??10) ?>:</strong> <?= esc($r['action']??'permit') ?>
                      <?php if (!empty($r['match_prefix_list'])): ?> &bull; match prefix-list <?= esc($r['match_prefix_list']) ?><?php endif; ?>
                      <?php if (!empty($r['match_access_list'])): ?> &bull; match acl <?= esc($r['match_access_list']) ?><?php endif; ?>
                      <?php if (!empty($r['set_metric'])): ?> &bull; set metric <?= esc($r['set_metric']) ?><?php endif; ?>
                      <?php if (!empty($r['set_local_preference'])): ?> &bull; set locpref <?= esc($r['set_local_preference']) ?><?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                  <?php if (count($rules) > 3): ?><em>+<?= count($rules) - 3 ?> more...</em><?php endif; ?>
                </div>
              </td>
              <td>
                <?php if ($refs === []): ?>
                  <span style="color:var(--text-muted); font-size:0.75rem;">— unreferenced —</span>
                <?php else: ?>
                  <?php foreach ($refs as $rf): ?>
                    <span class="rt-chip rt-chip-cyan" style="font-size:0.7rem; margin:1px;"><?= esc($rf) ?></span>
                  <?php endforeach; ?>
                <?php endif; ?>
              </td>
              <td style="font-size:0.78rem; color:var(--text-secondary);"><?= esc($rm['description'] ?: '—') ?></td>
              <td><?= !empty($rm['enabled']) ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Disabled</span>' ?></td>
              <td style="text-align:right; white-space:nowrap;">
                <?php if ($can): ?>
                  <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 7px;" onclick='openEditRouteMap(<?= htmlspecialchars(json_encode($rm), ENT_QUOTES, "UTF-8") ?>, "policy")' title="Edit Route Map">
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                  </button>
                  <form method="POST" action="<?= base_url('routing/policy/route-maps/' . esc($rm['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Remove route-map <?= esc(addslashes($rm['name'])) ?>?')"><?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 7px; color:var(--color-red);" title="Delete Route Map">
                      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ================================================================
     SUB-PANEL 4: Protocol Associations
     ================================================================ -->
<div class="policy-sub-panel <?= $activePolicySub === 'associations' ? 'active' : '' ?>" id="policy-sub-associations">
  <div class="card" style="margin-bottom:20px; padding:20px;">
    <h3 style="font-size:0.95rem; font-weight:700; margin-bottom:6px; color:var(--text-primary);">Routing Protocol Policy Bindings</h3>
    <p style="font-size:0.78rem; color:var(--text-muted); margin-bottom:18px;">
      How Access Lists, Prefix Lists, and Route Maps are currently linked to dynamic routing daemons and neighbors.
    </p>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:18px;">
      
      <!-- BGP Associations -->
      <div style="border:1px solid var(--color-border); border-radius:var(--radius-md); padding:16px; background:var(--color-surface-2);">
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
          <span class="rt-chip rt-chip-purple" style="font-weight:700;">BGP</span>
          <span style="font-weight:700; font-size:0.85rem; color:var(--text-primary);">BGP Peering &amp; Redistribution</span>
        </div>
        <div style="font-size:0.78rem; display:flex; flex-direction:column; gap:8px;">
          <div>
            <strong>Peer Inbound/Outbound Policies:</strong>
            <?php
              $bgpBound = 0;
              foreach (($bgpNeighbors ?? []) as $nb) {
                if (!empty($nb['route_map_in']) || !empty($nb['route_map_out']) || !empty($nb['prefix_list_in']) || !empty($nb['prefix_list_out'])) {
                  $bgpBound++;
                  echo '<div style="margin-top:4px; padding-left:8px; border-left:2px solid var(--color-purple);">';
                  echo 'Neighbor ' . esc($nb['neighbor_ip']) . ' (AS' . esc($nb['remote_as']) . '): ';
                  if (!empty($nb['route_map_in'])) echo '<span class="rt-chip rt-chip-cyan">RM In: ' . esc($nb['route_map_in']) . '</span> ';
                  if (!empty($nb['route_map_out'])) echo '<span class="rt-chip rt-chip-blue">RM Out: ' . esc($nb['route_map_out']) . '</span> ';
                  if (!empty($nb['prefix_list_in'])) echo '<span class="rt-chip rt-chip-emerald">PL In: ' . esc($nb['prefix_list_in']) . '</span> ';
                  if (!empty($nb['prefix_list_out'])) echo '<span class="rt-chip rt-chip-emerald">PL Out: ' . esc($nb['prefix_list_out']) . '</span> ';
                  echo '</div>';
                }
              }
              if ($bgpBound === 0) echo ' <span style="color:var(--text-muted);">None configured</span>';
            ?>
          </div>
          <div style="margin-top:4px;">
            <strong>Redistribution Route-Maps:</strong>
            <div style="margin-top:4px; padding-left:8px; border-left:2px solid var(--color-purple);">
              Connected: <?= !empty($bgpSettings['redistribute_connected_rm']) ? '<span class="rt-chip rt-chip-cyan">' . esc($bgpSettings['redistribute_connected_rm']) . '</span>' : '<span style="color:var(--text-muted);">default</span>' ?><br>
              Static: <?= !empty($bgpSettings['redistribute_static_rm']) ? '<span class="rt-chip rt-chip-cyan">' . esc($bgpSettings['redistribute_static_rm']) . '</span>' : '<span style="color:var(--text-muted);">default</span>' ?><br>
              OSPF: <?= !empty($bgpSettings['redistribute_ospf_rm']) ? '<span class="rt-chip rt-chip-cyan">' . esc($bgpSettings['redistribute_ospf_rm']) . '</span>' : '<span style="color:var(--text-muted);">default</span>' ?>
            </div>
          </div>
          <div style="margin-top:6px;">
            <a href="<?= base_url('bgp') ?>" class="btn btn-secondary btn-sm" style="font-size:0.72rem; padding:3px 8px;">Configure in BGP &rarr;</a>
          </div>
        </div>
      </div>

      <!-- OSPF Associations -->
      <div style="border:1px solid var(--color-border); border-radius:var(--radius-md); padding:16px; background:var(--color-surface-2);">
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
          <span class="rt-chip rt-chip-amber" style="font-weight:700;">OSPF</span>
          <span style="font-weight:700; font-size:0.85rem; color:var(--text-primary);">OSPF Filters &amp; Redistribution</span>
        </div>
        <div style="font-size:0.78rem; display:flex; flex-direction:column; gap:8px;">
          <div>
            <strong>Distribute Lists:</strong>
            <?php if (empty($ospfDistributeLists)): ?>
              <span style="color:var(--text-muted);">None configured</span>
            <?php else: ?>
              <?php foreach ($ospfDistributeLists as $dl): ?>
                <div style="margin-top:3px; padding-left:8px; border-left:2px solid var(--color-amber);">
                  <?= esc(strtoupper($dl['direction'])) ?>: prefix-list <span class="rt-chip rt-chip-cyan"><?= esc($dl['prefix_list']) ?></span>
                  <?= !empty($dl['interface']) ? ' on ' . esc($dl['interface']) : '' ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <div style="margin-top:4px;">
            <strong>Area Filter Lists:</strong>
            <?php if (empty($ospfAreaFilters)): ?>
              <span style="color:var(--text-muted);">None configured</span>
            <?php else: ?>
              <?php foreach ($ospfAreaFilters as $af): ?>
                <div style="margin-top:3px; padding-left:8px; border-left:2px solid var(--color-amber);">
                  Area <?= esc($af['area_id']) ?> (<?= esc(strtoupper($af['direction'])) ?>): prefix-list <span class="rt-chip rt-chip-cyan"><?= esc($af['prefix_list']) ?></span>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <div style="margin-top:4px;">
            <strong>Redistribution Route-Maps:</strong>
            <div style="margin-top:4px; padding-left:8px; border-left:2px solid var(--color-amber);">
              Connected: <?= !empty($ospfSettings['redistribute_connected_rm']) ? '<span class="rt-chip rt-chip-cyan">' . esc($ospfSettings['redistribute_connected_rm']) . '</span>' : '<span style="color:var(--text-muted);">default</span>' ?><br>
              Static: <?= !empty($ospfSettings['redistribute_static_rm']) ? '<span class="rt-chip rt-chip-cyan">' . esc($ospfSettings['redistribute_static_rm']) . '</span>' : '<span style="color:var(--text-muted);">default</span>' ?><br>
              BGP: <?= !empty($ospfSettings['redistribute_bgp_rm']) ? '<span class="rt-chip rt-chip-cyan">' . esc($ospfSettings['redistribute_bgp_rm']) . '</span>' : '<span style="color:var(--text-muted);">default</span>' ?>
            </div>
          </div>
          <div style="margin-top:6px;">
            <a href="<?= base_url('ospf') ?>" class="btn btn-secondary btn-sm" style="font-size:0.72rem; padding:3px 8px;">Configure in OSPF &rarr;</a>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ================================================================
     MODAL: Access List Builder
     ================================================================ -->
<div class="rt-modal-backdrop" id="accessListModal">
  <div class="rt-modal-dialog" style="max-width:680px;">
    <div class="rt-modal-header">
      <span style="font-weight:700; font-size:1rem; color:var(--text-primary);" id="aclModalTitle">Add Access List</span>
      <button type="button" onclick="closeModal('accessListModal')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:1.2rem;">&times;</button>
    </div>
    <form method="POST" id="aclForm" action="<?= base_url('routing/policy/access-lists/store') ?>" style="display:flex; flex-direction:column; flex:1; overflow:hidden; min-height:0;">
      <?= csrf_field() ?>
      <div class="rt-modal-body">
        <div class="rt-grid-2">
          <div class="rt-field">
            <label>Access List Name <span class="badge-req">Required</span></label>
            <input type="text" name="name" id="aclName" required placeholder="ACL_INTERNAL_NETS" pattern="[A-Za-z0-9_.-]+" spellcheck="false">
            <p class="hint">Alphanumeric, dot, dash, underscore only (e.g. ACL_WAN_IN or 10)</p>
          </div>
          <div class="rt-field">
            <label>Type</label>
            <select name="type" id="aclType">
              <option value="standard">Standard (IPv4)</option>
              <option value="extended">Extended (IPv4)</option>
              <option value="ipv6">IPv6 Access List</option>
            </select>
          </div>
        </div>
        <div class="rt-field">
          <label>Description</label>
          <input type="text" name="description" id="aclDesc" placeholder="e.g. Permit local corporate subnets">
        </div>

        <div>
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
            <label style="font-size:0.8rem; font-weight:700; color:var(--text-primary);">Rules (evaluated in sequence order)</label>
            <button type="button" class="btn btn-secondary btn-sm" onclick="addAclRow(null, 'permit', '', '')" style="font-size:0.75rem; padding:3px 8px;">
              + Add Rule
            </button>
          </div>
          <table class="builder-table">
            <thead>
              <tr>
                <th style="width:70px;">Seq</th>
                <th style="width:110px;">Action</th>
                <th>Network Prefix / Host / Any</th>
                <th>Comment</th>
                <th style="width:36px;"></th>
              </tr>
            </thead>
            <tbody id="aclRulesBody">
              <!-- populated by JS -->
            </tbody>
          </table>
        </div>
      </div>
      <div class="rt-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('accessListModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="aclSubmit">Save Access List</button>
      </div>
    </form>
  </div>
</div>

<script>
function switchPolicySub(name, btn) {
  document.querySelectorAll('.policy-sub-tab').forEach(function(t) { t.classList.remove('active'); });
  document.querySelectorAll('.policy-sub-panel').forEach(function(p) { p.classList.remove('active'); });
  if (btn) btn.classList.add('active');
  var target = document.getElementById('policy-sub-' + name);
  if (target) target.classList.add('active');
}

function openCreateAccessList() {
  document.getElementById('aclModalTitle').textContent = 'Add Access List';
  document.getElementById('aclForm').action = '<?= base_url('routing/policy/access-lists/store') ?>';
  document.getElementById('aclName').value = '';
  document.getElementById('aclName').readOnly = false;
  document.getElementById('aclType').value = 'standard';
  document.getElementById('aclDesc').value = '';
  document.getElementById('aclSubmit').textContent = 'Create Access List';

  var tbody = document.getElementById('aclRulesBody');
  tbody.innerHTML = '';
  addAclRow(10, 'permit', '10.0.0.0/8', 'Internal network');
  addAclRow(20, 'permit', '192.168.0.0/16', 'Branch offices');
  addAclRow(30, 'deny', 'any', 'Catch all drop');
  openModal('accessListModal');
}

function openEditAccessList(al) {
  document.getElementById('aclModalTitle').textContent = 'Edit Access List: ' + al.name;
  document.getElementById('aclForm').action = '<?= base_url('routing/policy/access-lists') ?>/' + al.id + '/update';
  document.getElementById('aclName').value = al.name || '';
  document.getElementById('aclName').readOnly = true;
  document.getElementById('aclType').value = al.type || 'standard';
  document.getElementById('aclDesc').value = al.description || '';
  document.getElementById('aclSubmit').textContent = 'Save Changes';

  var tbody = document.getElementById('aclRulesBody');
  tbody.innerHTML = '';
  var rules = [];
  try { rules = JSON.parse(al.rules || '[]'); } catch (e) { rules = []; }
  if (rules.length === 0) {
    addAclRow(10, 'permit', '', '');
  } else {
    rules.forEach(function(r) {
      addAclRow(r.seq, r.action, r.prefix, r.description);
    });
  }
  openModal('accessListModal');
}

function addAclRow(seq, action, prefix, desc) {
  var tbody = document.getElementById('aclRulesBody');
  var rowCount = tbody.querySelectorAll('tr').length;
  if (!seq) seq = (rowCount + 1) * 10;
  action = action || 'permit';
  prefix = prefix || '';
  desc   = desc || '';

  var tr = document.createElement('tr');
  tr.innerHTML = '<td><input type="number" name="seq[]" value="' + seq + '" min="1" max="4294967295" required style="width:65px;"></td>' +
    '<td><select name="action[]"><option value="permit"' + (action === 'permit' ? ' selected' : '') + '>permit</option><option value="deny"' + (action === 'deny' ? ' selected' : '') + '>deny</option></select></td>' +
    '<td><input type="text" name="prefix[]" value="' + (prefix.replace(/"/g, '&quot;')) + '" required placeholder="10.0.0.0/8 or host 1.2.3.4 or any" spellcheck="false" style="font-family:var(--font-mono);"></td>' +
    '<td><input type="text" name="rule_description[]" value="' + (desc.replace(/"/g, '&quot;')) + '" placeholder="optional note"></td>' +
    '<td><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest(\'tr\').remove()" style="color:var(--color-red); padding:2px 6px;">&times;</button></td>';
  tbody.appendChild(tr);
}
</script>
