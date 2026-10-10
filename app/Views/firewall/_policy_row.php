<?php
/**
 * Policy Row Partial View
 */
$isEnabled = (int)($rule['enabled'] ?? 1) === 1;

// Parse Sources
$sources = [];
if (!empty($rule['sources'])) {
    $decoded = json_decode($rule['sources'], true);
    $sources = is_array($decoded) ? $decoded : [$rule['sources']];
} elseif (!empty($rule['source_value'])) {
    $sources = [$rule['source_value']];
} else {
    $sources = ['all'];
}

// Parse Destinations
$destinations = [];
if (!empty($rule['destinations'])) {
    $decoded = json_decode($rule['destinations'], true);
    $destinations = is_array($decoded) ? $decoded : [$rule['destinations']];
} elseif (!empty($rule['dest_value'])) {
    $destinations = [$rule['dest_value']];
} else {
    $destinations = ['all'];
}

// Parse Services
$servicesList = [];
if (!empty($rule['services'])) {
    $decoded = json_decode($rule['services'], true);
    $servicesList = is_array($decoded) ? $decoded : [$rule['services']];
} elseif (!empty($rule['dest_port'])) {
    $servicesList = [$rule['dest_port']];
} else {
    $servicesList = ['ALL'];
}

// Parse Security Profiles
$secProfiles = [];
if (!empty($rule['security_profiles'])) {
    $decoded = json_decode($rule['security_profiles'], true);
    $secProfiles = is_array($decoded) ? $decoded : [];
}

// Format hits / bytes
$hits = (int)($rule['hit_count'] ?? 0);
$bytes = (int)($rule['bytes'] ?? 0);
$hitStr = ($hits >= 1000) ? number_format($hits / 1000, 1) . 'k' : (string)$hits;
if ($bytes >= 1048576) {
    $byteStr = number_format($bytes / 1048576, 1) . ' MB';
} elseif ($bytes >= 1024) {
    $byteStr = number_format($bytes / 1024, 1) . ' KB';
} else {
    $byteStr = $bytes . ' B';
}

$action = strtolower($rule['action'] ?? 'accept');
$isAccept = in_array($action, ['accept', 'pass', 'allow']);

// Build lookup maps for address types
$addrMap = [];
if (!empty($addresses)) {
    foreach ($addresses as $a) {
        $addrMap[$a['name']] = $a;
    }
}
$addrGrpNames = !empty($addressGroups) ? array_column($addressGroups, 'name') : [];
$svcGrpNames = !empty($serviceGroups) ? array_column($serviceGroups, 'name') : [];

// Detail maps for hover tooltips
$addrGrpMap = [];
foreach (($addressGroups ?? []) as $g) { $addrGrpMap[$g['name']] = $g; }
$svcMap = [];
foreach (($services ?? []) as $s) { $svcMap[$s['name']] = $s; }
$svcGrpMap = [];
foreach (($serviceGroups ?? []) as $g) { $svcGrpMap[$g['name']] = $g; }
$schedMap = [];
foreach (($schedules ?? []) as $s) { $schedMap[strtolower((string) $s['name'])] = $s; }
$ifaceMap = [];
foreach (($interfaces ?? []) as $i) {
    foreach ([$i['id'] ?? '', $i['name'] ?? '', $i['physical_device'] ?? ''] as $k) {
        if ($k !== '') { $ifaceMap[strtolower((string) $k)] = $i; }
    }
}
?>

<tr style="<?= !$isEnabled ? 'opacity:0.55;' : '' ?>"
    id="policy-row-<?= esc($rule['id']) ?>"
    data-policy-id="<?= esc($rule['id']) ?>"
    data-sequence="<?= esc($rule['sequence'] ?? '') ?>">
  <!-- Sequence / ID (+ drag handle when reordering is permitted) -->
  <td data-label="Seq" style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-muted); font-weight:600; white-space:nowrap; text-align:center;">
    <?php if (!empty($canReorder)): ?>
      <span class="policy-drag-handle" draggable="true" title="Drag to reorder this policy" aria-label="Drag to reorder">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <circle cx="9" cy="6" r="1.7"/><circle cx="15" cy="6" r="1.7"/>
          <circle cx="9" cy="12" r="1.7"/><circle cx="15" cy="12" r="1.7"/>
          <circle cx="9" cy="18" r="1.7"/><circle cx="15" cy="18" r="1.7"/>
        </svg>
      </span>
    <?php endif; ?>
    <span class="policy-seq">#<?= esc($rule['sequence'] ?? $rule['id']) ?></span>
  </td>

  <!-- Policy Name & Description -->
  <td data-label="Name">
    <div style="font-weight:600; color:var(--text-primary); font-size:0.875rem;">
      <?= esc($rule['name'] ?? ('Policy #' . $rule['id'])) ?>
    </div>
    <?php if (!empty($rule['description'])): ?>
      <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px;">
        <?= esc($rule['description']) ?>
      </div>
    <?php endif; ?>
  </td>

  <!-- Optional From / To for Sequence View -->
  <?php if (!empty($showInterfaces)): ?>
    <?php
      $srcName = (string) ($rule['src_interface'] ?? ($rule['interface_id'] ?? ''));
      $dstName = (string) ($rule['dst_interface'] ?? '');
      $srcTip  = isset($ifaceMap[strtolower($srcName)]) ? \App\Libraries\ObjectTip::iface($ifaceMap[strtolower($srcName)]) : '';
      $dstTip  = isset($ifaceMap[strtolower($dstName)]) ? \App\Libraries\ObjectTip::iface($ifaceMap[strtolower($dstName)]) : '';
    ?>
    <td data-label="From" style="text-align:center;">
      <span class="badge badge-info" style="font-size:0.7rem; white-space:nowrap;"<?= $srcTip ?>>
        <?= esc(strtoupper($srcName ?: 'ANY')) ?>
      </span>
    </td>
    <td data-label="To" style="text-align:center;">
      <span class="badge badge-info" style="font-size:0.7rem; white-space:nowrap;"<?= $dstTip ?>>
        <?= esc(strtoupper($dstName ?: 'ANY')) ?>
      </span>
    </td>
  <?php endif; ?>

  <!-- Sources -->
  <td data-label="Source">
    <div style="display:flex; flex-direction:column; gap:3px; align-items:flex-start;">
      <?php foreach ($sources as $s): ?>
        <?php
          $isAll = in_array($s, ['all', 'any', '0.0.0.0/0']);
          $isGrp = in_array($s, $addrGrpNames);
          $addrObj = $addrMap[$s] ?? null;
          $isFqdn = $addrObj && $addrObj['type'] === 'fqdn';
          $chipClass = $isAll ? 'mno-obj-chip-all' : ($isGrp ? 'mno-obj-chip-grp' : ($isFqdn ? 'mno-obj-chip-fqdn' : 'mno-obj-chip-addr'));
          $tip = $isGrp && isset($addrGrpMap[$s]) ? \App\Libraries\ObjectTip::addressGroup($addrGrpMap[$s], $addrMap)
               : ($addrObj ? \App\Libraries\ObjectTip::address($addrObj) : '');
        ?>
        <span class="mno-obj-chip <?= $chipClass ?>"<?= $tip ?>>
          <?php if ($isAll): ?>
            <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
          <?php elseif ($isGrp): ?>
            <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
          <?php elseif ($isFqdn): ?>
            <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
          <?php else: ?>
            <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
          <?php endif; ?>
          <span><?= esc($s) ?></span>
        </span>
      <?php endforeach; ?>
    </div>
  </td>

  <!-- Destinations -->
  <td data-label="Destination">
    <div style="display:flex; flex-direction:column; gap:3px; align-items:flex-start;">
      <?php foreach ($destinations as $d): ?>
        <?php
          $isAll = in_array($d, ['all', 'any', '0.0.0.0/0']);
          $isGrp = in_array($d, $addrGrpNames);
          $addrObj = $addrMap[$d] ?? null;
          $isFqdn = $addrObj && $addrObj['type'] === 'fqdn';
          $chipClass = $isAll ? 'mno-obj-chip-all' : ($isGrp ? 'mno-obj-chip-grp' : ($isFqdn ? 'mno-obj-chip-fqdn' : 'mno-obj-chip-addr'));
          $tip = $isGrp && isset($addrGrpMap[$d]) ? \App\Libraries\ObjectTip::addressGroup($addrGrpMap[$d], $addrMap)
               : ($addrObj ? \App\Libraries\ObjectTip::address($addrObj) : '');
        ?>
        <span class="mno-obj-chip <?= $chipClass ?>"<?= $tip ?>>
          <?php if ($isAll): ?>
            <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
          <?php elseif ($isGrp): ?>
            <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
          <?php elseif ($isFqdn): ?>
            <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
          <?php else: ?>
            <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
          <?php endif; ?>
          <span><?= esc($d) ?></span>
        </span>
      <?php endforeach; ?>
    </div>
  </td>

  <!-- Schedule -->
  <td data-label="Schedule" style="text-align:center;">
    <?php
      $isAlways = empty($rule['schedule']) || strtolower($rule['schedule']) === 'always';
      $schedKey = strtolower((string) ($rule['schedule'] ?? ''));
      $schedTip = (!$isAlways && isset($schedMap[$schedKey])) ? \App\Libraries\ObjectTip::schedule($schedMap[$schedKey]) : '';
    ?>
    <span class="mno-schedule-pill <?= !$isAlways ? 'custom-schedule' : '' ?>"<?= $schedTip ?>>
      <?php if (!$isAlways): ?>
        <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="margin-right:3px; vertical-align:middle; display:inline-block;">
          <circle cx="12" cy="12" r="10"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/>
        </svg>
      <?php endif; ?>
      <?= esc(strtoupper($rule['schedule'] ?? 'ALWAYS')) ?>
    </span>
  </td>

  <!-- Services / Port Groups -->
  <td data-label="Service">
    <div style="display:flex; flex-direction:column; gap:3px; align-items:flex-start;">
      <?php foreach ($servicesList as $svc): ?>
        <?php
          $isAll = strtoupper($svc) === 'ALL';
          $isGrp = in_array($svc, $svcGrpNames);
          $chipClass = $isAll ? 'mno-obj-chip-all' : ($isGrp ? 'mno-obj-chip-grp' : 'mno-obj-chip-svc');
          $svcTip = $isGrp && isset($svcGrpMap[$svc]) ? \App\Libraries\ObjectTip::serviceGroup($svcGrpMap[$svc], $svcMap)
                  : (isset($svcMap[$svc]) ? \App\Libraries\ObjectTip::service($svcMap[$svc]) : '');
        ?>
        <span class="mno-obj-chip <?= $chipClass ?>"<?= $svcTip ?>>
          <?php if ($isAll): ?>
            <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
          <?php elseif ($isGrp): ?>
            <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
          <?php else: ?>
            <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v6m4-6v6M8 8h8a2 2 0 0 1 2 2v3a6 6 0 0 1-12 0v-3a2 2 0 0 1 2-2zM12 17v5"></path></svg>
          <?php endif; ?>
          <span><?= esc($svc) ?></span>
        </span>
      <?php endforeach; ?>
    </div>
  </td>

  <!-- Action -->
  <td data-label="Action" style="text-align:center;">
    <?php if ($isAccept): ?>
      <span class="mno-action-accept">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
        <span>ACCEPT</span>
      </span>
    <?php else: ?>
      <span class="mno-action-deny">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        <span>DROP</span>
      </span>
    <?php endif; ?>
  </td>

  <!-- NAT -->
  <td data-label="NAT" style="text-align:center;">
    <?php if (!empty($rule['nat_enabled'])): ?>
      <span class="mno-nat-on">NAT: ON</span>
    <?php else: ?>
      <span class="mno-nat-off">—</span>
    <?php endif; ?>
  </td>

  <!-- Security Profiles -->
  <td data-label="Security" style="text-align:center;">
    <?php if (!empty($secProfiles)): ?>
      <div style="display:inline-flex; gap:3px; flex-wrap:wrap; justify-content:center;">
        <?php foreach ($secProfiles as $sp): ?>
          <span class="mno-sec-badge"><?= esc(strtoupper($sp)) ?></span>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <span style="color:var(--text-muted); font-size:0.75rem;">—</span>
    <?php endif; ?>
  </td>

  <!-- Traffic / Hit Counters -->
  <td data-label="Traffic" style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-secondary); white-space:nowrap;">
    <div><strong style="color:var(--text-primary);"><?= $hitStr ?></strong> pkts</div>
    <div style="font-size:0.68rem; color:var(--text-muted);"><?= $byteStr ?></div>
  </td>

  <!-- Status Toggle -->
  <td data-label="Status" style="text-align:center;">
    <form method="POST" action="<?= base_url('firewall/policies/' . esc($rule['id']) . '/toggle') ?>" style="display:inline;">
      <?= csrf_field() ?>
      <label class="mno-switch" title="<?= $isEnabled ? 'Disable Policy' : 'Enable Policy' ?>">
        <input type="checkbox" <?= $isEnabled ? 'checked' : '' ?> onchange="this.form.submit()">
        <span class="mno-slider"></span>
      </label>
    </form>
  </td>

  <!-- Actions -->
  <td data-label="Actions" style="text-align:right; white-space:nowrap;">
    <button type="button" class="btn btn-ghost btn-sm" onclick='editPolicy(<?= htmlspecialchars(json_encode($rule), ENT_QUOTES, "UTF-8") ?>)' title="Edit Policy" style="padding:4px 8px; font-size:0.75rem;">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125"/>
      </svg>
    </button>

    <form method="POST" action="<?= base_url('firewall/policies/' . esc($rule['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Delete firewall policy <?= esc($rule['name']) ?>?');">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-ghost btn-sm" title="Delete Policy" style="color:var(--color-red); padding:4px 8px; font-size:0.75rem;">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
        </svg>
      </button>
    </form>
  </td>
</tr>
