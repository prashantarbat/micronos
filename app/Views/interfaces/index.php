<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <h1 class="page-title" style="display:flex; align-items:center; gap:10px;">
      <svg width="26" height="26" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
      </svg>
      Network Interfaces
    </h1>
    <p class="page-subtitle">Configure physical and virtual interfaces, link addressing, and traffic parameters</p>
  </div>
  <div class="page-header-actions">
    <?php if (session()->get('is_superadmin') || in_array('interfaces.edit', session()->get('permissions') ?? [])): ?>
      <button type="button" class="btn btn-secondary" onclick="openAssignInterfacesModal()" style="display:inline-flex; align-items:center; gap:6px; background:var(--tone-amber-bg, rgba(245,158,11,0.12)); color:var(--tone-amber-fg, #f59e0b); border:1px solid var(--tone-amber-border, rgba(245,158,11,0.3));">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
        </svg>
        Assign Ports (WAN / LAN)
      </button>

      <a href="<?= base_url('interfaces/new') ?>" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
        </svg>
        Add Interface / VLAN
      </a>

      <button type="button" class="btn btn-secondary" onclick="openAddBondModal()" style="display:inline-flex; align-items:center; gap:6px; background:var(--tone-blue-bg); color:var(--tone-blue-fg); border:1px solid var(--tone-blue-border);">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656l1.5-1.5m7.156-7.156a4 4 0 00-5.656 0l-3 3a4 4 0 000 5.656l1.5 1.5"/>
        </svg>
        + New Bond (bond0)
      </button>

      <button type="button" class="btn btn-secondary" onclick="openAddBridgeModal()" style="display:inline-flex; align-items:center; gap:6px; background:var(--tone-emerald-bg, rgba(16,185,129,0.12)); color:var(--tone-emerald-fg, #10b981); border:1px solid var(--tone-emerald-border, rgba(16,185,129,0.3));">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l4-4 4 4m0 6l-4 4-4-4"/>
        </svg>
        + New Bridge (br0)
      </button>

      <?php if ($hasWireless ?? false): ?>
      <button type="button" class="btn btn-secondary" onclick="openConfigureWirelessModal()" style="display:inline-flex; align-items:center; gap:6px; background:var(--tone-rose-bg); color:var(--tone-rose-fg); border:1px solid var(--tone-rose-border);">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
        </svg>
        + Setup Wi-Fi
      </button>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (session()->get('is_superadmin') || in_array('interfaces.apply', session()->get('permissions') ?? [])): ?>
      <form method="POST" action="<?= base_url('interfaces/apply') ?>" style="margin:0;">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-success" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          Re-apply Configuration
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?= view('partials/network_result') ?>

<?php if ($isMock ?? false): ?>
  <div class="alert alert-info animate-in" style="margin-bottom:20px; background:rgba(6,182,212,0.1); border:1px solid rgba(6,182,212,0.3); border-radius:var(--radius-md); padding:12px 16px; display:flex; align-items:center; gap:12px;">
    <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan); flex-shrink:0;">
      <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <div style="font-size:0.83rem; color:var(--text-secondary);">
      Running in <strong style="color:var(--color-cyan);">Windows Simulation Mode</strong>. Live Linux network telemetry, LACP bond states, and Wi-Fi radiometrics are generated dynamically by the Micron OS IPC engine.
    </div>
  </div>
<?php endif; ?>

<!-- ── Summary Metrics Strip ── -->
<div class="metrics-grid animate-in animate-in-delay-1" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:14px; margin-bottom:24px;">
  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-emerald);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Interfaces Up</div>
    <div style="font-size:1.6rem; font-weight:700; color:var(--color-emerald); margin-top:2px; font-family:var(--font-mono);"><?= (int) ($summary['up'] ?? 0) ?></div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">Passing network traffic</div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-red);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Links Down</div>
    <div style="font-size:1.6rem; font-weight:700; color:var(--color-red); margin-top:2px; font-family:var(--font-mono);"><?= (int) ($summary['down'] ?? 0) ?></div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">Disabled or no carrier</div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-cyan);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Physical NICs</div>
    <div style="font-size:1.6rem; font-weight:700; color:var(--color-cyan); margin-top:2px; font-family:var(--font-mono);"><?= (int) ($summary['physical'] ?? 0) ?></div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">Hardware ports mapped</div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-purple);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">802.1Q VLANs</div>
    <div style="font-size:1.6rem; font-weight:700; color:var(--color-purple); margin-top:2px; font-family:var(--font-mono);"><?= (int) ($summary['vlan'] ?? 0) ?></div>
    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">Virtual tagged subnets</div>
  </div>
</div>

<!-- ============================================================
     TAB 1: STANDARD INTERFACES & VLANS
============================================================ -->
<div id="interfacesSection" class="iface-section" style="margin-bottom:32px;">

<!-- ── Detailed Interfaces Telemetry Table ── -->
<div class="card card-glass animate-in animate-in-delay-2" style="margin-bottom:30px;">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
    <span class="card-title" style="display:flex; align-items:center; gap:8px;">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
      </svg>
      Interface Telemetry & Physical Link Assignments
    </span>
    <span style="font-size:0.72rem; color:var(--text-muted); font-family:var(--font-mono);">
      <?= count($interfaces) ?> interface(s) loaded
    </span>
  </div>
  <div style="overflow-x:auto;">
    <table class="iface-table" style="width:100%;">
      <thead>
        <tr>
          <th>Interface</th>
          <th>Type</th>
          <th>Device Binding</th>
          <th>Status</th>
          <th>Admin Access</th>
          <th>IP Configuration</th>
          <th>Gateway / Route</th>
          <th>MTU / MSS</th>
          <th>Throughput (Rx / Tx)</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($interfaces as $if): ?>
          <?php $isVlan = ($if['interface_type'] ?? '') === 'vlan'; ?>
          <tr class="iface-row-item" data-type="<?= $isVlan ? 'vlan' : 'physical' ?>">
            <td>
              <div style="font-weight:700; color:var(--text-primary); font-size:0.85rem;"><?= esc($if['name']) ?></div>
              <?php if (!empty($if['description'])): ?>
                <div style="font-size:0.7rem; color:var(--text-muted);"><?= esc($if['description']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($isVlan): ?>
                <span class="badge badge-purple" style="font-size:0.68rem;">802.1Q VLAN <?= esc($if['vlan_tag']) ?></span>
              <?php else: ?>
                <span class="badge badge-cyan" style="font-size:0.68rem;">PHYSICAL</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge badge-muted" style="font-family:var(--font-mono); font-size:0.75rem;"><?= esc($if['physical_device']) ?></span>
              <?php if ($isVlan && !empty($if['vlan_parent'])): ?>
                <span style="font-size:0.7rem; color:var(--text-muted);">(&larr; <?= esc($if['vlan_parent']) ?>)</span>
              <?php endif; ?>
              <?php if (!empty($if['lldp_peer_label'])): ?>
                <div style="font-size:0.68rem; color:var(--text-muted); margin-top:3px;"
                     title="Remote chassis and port discovered over LLDP">
                  &#8627; Patched into <span style="font-family:var(--font-mono); color:var(--text-secondary);"><?= esc($if['lldp_peer_label']) ?></span>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($if['is_up']) && !empty($if['enabled'])): ?>
                <span class="badge badge-success" style="display:inline-flex; align-items:center; gap:5px; font-size:0.7rem;">
                  <span class="pulse-dot"></span> Link Up
                </span>
              <?php else: ?>
                <span class="badge badge-danger" style="font-size:0.7rem;">
                  <?= empty($if['enabled']) ? 'Disabled' : ((($if['present'] ?? true) === false) ? 'Not Present' : 'Link Down') ?>
                </span>
              <?php endif; ?>
            </td>
            <td>
              <?php
                $adminAccess = $if['admin_access'] ?? [];
                if (!is_array($adminAccess)) {
                    $adminAccess = json_decode((string)$adminAccess, true) ?: [];
                }
              ?>
              <?php if (!empty($adminAccess)): ?>
                <div style="display:flex; flex-wrap:wrap; gap:4px; max-width:200px;">
                  <?php foreach ($adminAccess as $acc): ?>
                    <?php
                      $bClass = match(strtolower($acc)) {
                        'https' => 'badge-cyan',
                        'http'  => 'badge-amber',
                        'ping'  => 'badge-emerald',
                        'ssh'   => 'badge-purple',
                        'snmp'  => 'badge-blue',
                        default => 'badge-muted'
                      };
                    ?>
                    <span class="badge <?= $bClass ?>" style="font-size:0.65rem; font-weight:700; padding:1px 6px; text-transform:uppercase;">
                      <?= esc($acc) ?>
                    </span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <span style="font-size:0.7rem; color:var(--text-muted); font-style:italic;">None</span>
              <?php endif; ?>
            </td>
            <td>
              <div style="font-family:var(--font-mono); font-size:0.82rem; color:var(--text-primary);">
                <?= esc($if['current_ip']) ?><?= !empty($if['ipv4_netmask']) ? '/' . esc($if['ipv4_netmask']) : '' ?>
              </div>
              <div style="font-size:0.68rem; color:var(--text-muted); text-transform:uppercase;">
                <?= esc($if['ip_assignment_type']) ?>
              </div>
            </td>
            <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);">
              <?= !empty($if['ipv4_gateway']) ? esc($if['ipv4_gateway']) : ($if['ip_assignment_type'] === 'dhcp' ? 'Auto (DHCP)' : '—') ?>
            </td>
            <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-secondary);">
              <div>MTU: <?= esc($if['mtu']) ?></div>
              <?php if (!empty($if['mss_clamp'])): ?>
                <div style="color:var(--color-amber);">MSS: <?= (int) $if['mss_clamp'] ?></div>
              <?php endif; ?>
            </td>
            <td style="font-family:var(--font-mono); font-size:0.75rem;">
              <span style="color:var(--color-emerald);">↓ <?= esc($if['rx_human']) ?></span> &nbsp;·&nbsp;
              <span style="color:var(--color-cyan);">↑ <?= esc($if['tx_human']) ?></span>
            </td>
            <td style="text-align:right; white-space:nowrap;">
              <?php if (session()->get('is_superadmin') || in_array('interfaces.edit', session()->get('permissions') ?? [])): ?>
                <a href="<?= base_url('interfaces/edit/' . esc($if['id'])) ?>" class="btn btn-ghost btn-sm" style="font-size:0.72rem; padding:2px 8px;">
                  Edit
                </a>

                <?php if (!in_array(strtolower($if['id']), ['wan', 'lan'], true)): ?>
                  <button type="button" class="btn btn-ghost btn-sm" style="color:var(--color-red); font-size:0.72rem; padding:2px 6px;"
                          onclick="confirmDeleteInterface('<?= esc($if['id']) ?>', '<?= esc($if['name']) ?>')" title="Delete Interface">
                    Delete
                  </button>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

<!-- ============================================================
     TAB 2: LACP LINK AGGREGATION (bond0)
============================================================ -->
<div id="bondsSection" class="iface-section" style="margin-bottom:32px;">
  <!-- Header / Action bar -->
  <div class="card card-glass animate-in" style="padding:18px 22px; margin-bottom:20px; border-left:3px solid #3b82f6;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
      <div>
        <h2 style="font-size:1.15rem; font-weight:700; color:var(--text-primary); margin:0 0 6px 0; display:flex; align-items:center; gap:8px;">
          <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:#60a5fa;">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656l1.5-1.5m7.156-7.156a4 4 0 00-5.656 0l-3 3a4 4 0 000 5.656l1.5 1.5"/>
          </svg>
          Link Aggregation Groups &amp; IEEE 802.3ad LACP
        </h2>
        <p style="font-size:0.82rem; color:var(--text-secondary); margin:0; max-width:850px; line-height:1.5;">
          Combine multiple physical Ethernet ports (e.g. <code>eth1</code> + <code>eth2</code> or <code>enp1s0</code> + <code>enp2s0</code>) into high-bandwidth virtual trunk interfaces (<code>bond0</code>) with automatic failover. Micron OS kernel bonding operates dynamically with IEEE 802.3ad LACP 1-second fast heartbeats and Layer 2+3 transmit hashing.
        </p>
      </div>
      <div>
        <?php if (session()->get('is_superadmin') || in_array('interfaces.edit', session()->get('permissions') ?? [])): ?>
          <button type="button" class="btn btn-primary" onclick="openAddBondModal()" style="display:inline-flex; align-items:center; gap:6px;">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            Create Bond Interface
          </button>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if (empty($bonds)): ?>
    <div class="card card-glass animate-in" style="padding:48px 24px; text-align:center; border:1px dashed var(--color-border-bright);">
      <div style="width:56px; height:56px; border-radius:50%; background:rgba(59,130,246,0.1); color:#60a5fa; display:flex; align-items:center; justify-content:center; margin:0 auto 16px;">
        <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656l1.5-1.5m7.156-7.156a4 4 0 00-5.656 0l-3 3a4 4 0 000 5.656l1.5 1.5"/>
        </svg>
      </div>
      <h3 style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin-bottom:8px;">No LACP Bonds Configured</h3>
      <p style="font-size:0.83rem; color:var(--text-secondary); max-width:540px; margin:0 auto 20px; line-height:1.5;">
        Link Aggregation Groups allow bundling two or more physical network adapters into a single logical channel for multi-gigabit throughput and instant link redundancy.
      </p>
      <?php if (session()->get('is_superadmin') || in_array('interfaces.edit', session()->get('permissions') ?? [])): ?>
        <button type="button" class="btn btn-primary" onclick="openAddBondModal()" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          Configure First Bond (bond0)
        </button>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <!-- Bond cards grid -->
    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(360px, 1fr)); gap:20px; margin-bottom:28px;" class="animate-in">
      <?php foreach ($bonds as $bond): ?>
        <?php
          $slaveList = is_array($bond['slaves']) ? $bond['slaves'] : json_decode((string)$bond['slaves'], true) ?? [];
          $liveBond = $bond['live'] ?? null;
          $modeLabel = match ($bond['mode']) {
            '802.3ad'       => 'IEEE 802.3ad LACP',
            'active-backup' => 'Active-Backup',
            'balance-xor'   => 'Balance-XOR (Tx Hash)',
            'balance-rr'    => 'Round-Robin',
            'broadcast'     => 'Broadcast',
            default         => strtoupper($bond['mode']),
          };
        ?>
        <div class="card stat-card blue" style="display:flex; flex-direction:column; justify-content:space-between; padding:20px;">
          <div>
            <!-- Header -->
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
              <div>
                <div style="display:flex; align-items:center; gap:8px;">
                  <span style="font-weight:700; font-size:1.2rem; color:var(--text-primary); font-family:var(--font-mono);"><?= esc($bond['id']) ?></span>
                  <span class="badge" style="background:rgba(59,130,246,0.2); color:#60a5fa; font-size:0.68rem; font-weight:600;"><?= esc($modeLabel) ?></span>
                </div>
                <div style="font-size:0.85rem; color:var(--text-secondary); margin-top:2px; font-weight:500;"><?= esc($bond['name']) ?></div>
              </div>
              <div>
                <span class="badge badge-success" style="display:inline-flex; align-items:center; gap:5px;">
                  <span class="pulse-dot"></span>
                  Active Bond
                </span>
              </div>
            </div>

            <!-- Member slaves badges -->
            <div style="margin-bottom:14px;">
              <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; margin-bottom:6px; letter-spacing:0.04em;">Member Physical Interfaces (Slaves)</div>
              <div style="display:flex; flex-wrap:wrap; gap:6px;">
                <?php foreach ($slaveList as $slave): ?>
                  <span class="badge" style="background:rgba(16,185,129,0.15); color:var(--color-emerald); border:1px solid rgba(16,185,129,0.3); font-family:var(--font-mono); font-size:0.75rem; padding:4px 8px; display:inline-flex; align-items:center; gap:5px;">
                    <span style="width:6px; height:6px; border-radius:50%; background:var(--color-emerald);"></span>
                    <?= esc($slave) ?>
                  </span>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Parameters table -->
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:6px 12px; font-size:0.75rem; color:var(--text-secondary); margin-bottom:16px; background:rgba(0,0,0,0.15); padding:10px 14px; border-radius:var(--radius-sm); border:1px solid var(--border-subtle);">
              <div>LACP Rate: <strong style="color:var(--text-primary); font-family:var(--font-mono); text-transform:uppercase;"><?= esc($bond['lacp_rate']) ?></strong></div>
              <div>Transmit Hash: <strong style="color:var(--text-primary); font-family:var(--font-mono);"><?= esc($bond['xmit_hash_policy']) ?></strong></div>
              <div>MII Polling: <strong style="color:var(--text-primary); font-family:var(--font-mono);"><?= (int) $bond['miimon'] ?> ms</strong></div>
              <div>MTU Frame: <strong style="color:var(--text-primary); font-family:var(--font-mono);"><?= (int) $bond['mtu'] ?></strong></div>
            </div>
          </div>

          <!-- Footer -->
          <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid var(--color-border); padding-top:12px; margin-top:6px;">
            <span style="font-size:0.7rem; color:var(--text-muted);">Kernel Module: <code>bonding</code></span>
            <div style="display:flex; gap:8px;">
              <?php if (session()->get('is_superadmin') || in_array('interfaces.edit', session()->get('permissions') ?? [])): ?>
                <button type="button" class="btn btn-ghost btn-sm" style="color:var(--color-red); font-size:0.72rem; padding:2px 8px;" onclick="confirmDeleteBond('<?= esc($bond['id']) ?>', '<?= esc($bond['name']) ?>')">
                  Delete
                </button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- ============================================================
     TAB: LAYER 2 BRIDGE INTERFACES (br0)
============================================================ -->
<div id="bridgesSection" class="iface-section" style="margin-bottom:32px;">
  <!-- Header / Action bar -->
  <div class="card card-glass animate-in" style="padding:18px 22px; margin-bottom:20px; border-left:3px solid var(--color-emerald);">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
      <div>
        <h2 style="font-size:1.15rem; font-weight:700; color:var(--text-primary); margin:0 0 6px 0; display:flex; align-items:center; gap:8px;">
          <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-emerald);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l4-4 4 4m0 6l-4 4-4-4"/>
          </svg>
          Network Bridge Interfaces &amp; Switch Ports (br0)
        </h2>
        <p style="font-size:0.82rem; color:var(--text-secondary); margin:0; max-width:850px; line-height:1.5;">
          Combine multiple physical Ethernet ports (e.g. <code>eth1</code> + <code>eth2</code> + <code>eth3</code>) into a unified Layer 2 switch bridge (<code>br0</code>). The primary physical interface (<code>eth0</code>) connects to WAN, while remaining ports act as a LAN switch with IEEE 802.1D Spanning Tree Protocol (STP) loop protection.
        </p>
      </div>
      <div>
        <?php if (session()->get('is_superadmin') || in_array('interfaces.edit', session()->get('permissions') ?? [])): ?>
          <button type="button" class="btn btn-primary" onclick="openAddBridgeModal()" style="display:inline-flex; align-items:center; gap:6px;">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            Create Bridge Interface
          </button>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if (empty($bridges)): ?>
    <div class="card card-glass animate-in" style="padding:48px 24px; text-align:center; border:1px dashed var(--color-border-bright);">
      <div style="width:56px; height:56px; border-radius:50%; background:rgba(16,185,129,0.1); color:var(--color-emerald); display:flex; align-items:center; justify-content:center; margin:0 auto 16px;">
        <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l4-4 4 4m0 6l-4 4-4-4"/>
        </svg>
      </div>
      <h3 style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin-bottom:8px;">No Bridge Interfaces Configured</h3>
      <p style="font-size:0.83rem; color:var(--text-secondary); max-width:540px; margin:0 auto 20px; line-height:1.5;">
        A bridge allows your Micron OS appliance to operate as a standard multi-port router switch. Bundle non-WAN interfaces into <code>br0</code> to provide wired LAN clients with unified Layer 2 switching.
      </p>
      <?php if (session()->get('is_superadmin') || in_array('interfaces.edit', session()->get('permissions') ?? [])): ?>
        <button type="button" class="btn btn-primary" onclick="openAddBridgeModal()" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          Configure Standard Bridge (br0)
        </button>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <!-- Bridges grid -->
    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(360px, 1fr)); gap:20px; margin-bottom:28px;" class="animate-in">
      <?php foreach ($bridges as $bridge): ?>
        <?php
          $slaveList = is_array($bridge['slaves']) ? $bridge['slaves'] : json_decode((string)$bridge['slaves'], true) ?? [];
          $liveBridge = $bridge['live'] ?? null;
          $stpEnabled = !empty($bridge['stp']);
        ?>
        <div class="card stat-card emerald" style="display:flex; flex-direction:column; justify-content:space-between; padding:20px;">
          <div>
            <!-- Header -->
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
              <div>
                <div style="display:flex; align-items:center; gap:8px;">
                  <span style="font-weight:700; font-size:1.2rem; color:var(--text-primary); font-family:var(--font-mono);"><?= esc($bridge['id']) ?></span>
                  <span class="badge" style="background:rgba(16,185,129,0.2); color:var(--color-emerald); font-size:0.68rem; font-weight:600;">
                    <?= $stpEnabled ? 'STP 802.1D Active' : 'STP Disabled' ?>
                  </span>
                </div>
                <div style="font-size:0.85rem; color:var(--text-secondary); margin-top:2px; font-weight:500;"><?= esc($bridge['name']) ?></div>
              </div>
              <div>
                <span class="badge badge-success" style="display:inline-flex; align-items:center; gap:5px;">
                  <span class="pulse-dot"></span>
                  Active Bridge
                </span>
              </div>
            </div>

            <!-- Member slaves badges -->
            <div style="margin-bottom:14px;">
              <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; margin-bottom:6px; letter-spacing:0.04em;">Member Switch Ports (Bridged Slaves)</div>
              <div style="display:flex; flex-wrap:wrap; gap:6px;">
                <?php foreach ($slaveList as $slave): ?>
                  <span class="badge" style="background:rgba(16,185,129,0.15); color:var(--color-emerald); border:1px solid rgba(16,185,129,0.3); font-family:var(--font-mono); font-size:0.75rem; padding:4px 8px; display:inline-flex; align-items:center; gap:5px;">
                    <span style="width:6px; height:6px; border-radius:50%; background:var(--color-emerald);"></span>
                    <?= esc($slave) ?>
                  </span>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Parameters table -->
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:6px 12px; font-size:0.75rem; color:var(--text-secondary); margin-bottom:16px; background:rgba(0,0,0,0.15); padding:10px 14px; border-radius:var(--radius-sm); border:1px solid var(--border-subtle);">
              <div>STP State: <strong style="color:var(--text-primary); font-family:var(--font-mono); text-transform:uppercase;"><?= $stpEnabled ? 'ENABLED' : 'DISABLED' ?></strong></div>
              <div>Forward Delay: <strong style="color:var(--text-primary); font-family:var(--font-mono);"><?= (int) ($bridge['forward_delay'] ?? 2) ?>s</strong></div>
              <div>MTU Frame: <strong style="color:var(--text-primary); font-family:var(--font-mono);"><?= (int) $bridge['mtu'] ?></strong></div>
              <div>Mode: <strong style="color:var(--text-primary); font-family:var(--font-mono);">Layer 2 Bridge</strong></div>
            </div>

            <?php if (!empty($bridge['description'])): ?>
              <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:12px; font-style:italic;">
                <?= esc($bridge['description']) ?>
              </div>
            <?php endif; ?>
          </div>

          <!-- Footer -->
          <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid var(--color-border); padding-top:12px; margin-top:6px;">
            <span style="font-size:0.7rem; color:var(--text-muted);">Kernel Module: <code>bridge</code></span>
            <div style="display:flex; gap:8px;">
              <a href="<?= base_url('interfaces/edit/' . esc($bridge['id'])) ?>" class="btn btn-ghost btn-sm" style="font-size:0.72rem; padding:2px 8px;">
                Edit IP Address
              </a>
              <?php if (session()->get('is_superadmin') || in_array('interfaces.edit', session()->get('permissions') ?? [])): ?>
                <button type="button" class="btn btn-ghost btn-sm" style="color:var(--color-red); font-size:0.72rem; padding:2px 8px;" onclick="confirmDeleteBridge('<?= esc($bridge['id']) ?>', '<?= esc($bridge['name']) ?>')">
                  Delete
                </button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($hasWireless ?? false): ?>
<!-- ============================================================
     TAB 3: WIRELESS LAN (shown only when a Wi-Fi adapter is installed)
============================================================ -->
<div id="wirelessSection" class="iface-section" style="margin-bottom:32px;">
  <!-- Header / Action bar -->
  <div class="card card-glass animate-in" style="padding:18px 22px; margin-bottom:20px; border-left:3px solid #ec4899;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
      <div>
        <h2 style="font-size:1.15rem; font-weight:700; color:var(--text-primary); margin:0 0 6px 0; display:flex; align-items:center; gap:8px;">
          <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:#f472b6;">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
          </svg>
          Wireless LAN Radios &amp; Access Point Controllers
        </h2>
        <p style="font-size:0.82rem; color:var(--text-secondary); margin:0; max-width:850px; line-height:1.5;">
          Configure this appliance's Wi-Fi radios (<code><?= esc(implode(', ', $wirelessRadios ?? [])) ?></code>) as an enterprise Access Point via <code>hostapd</code> with WPA2-Personal and WPA3-SAE encryption, or connect as a wireless client uplink via <code>wpa_supplicant</code>.
        </p>
      </div>
      <div>
        <?php if (session()->get('is_superadmin') || in_array('interfaces.edit', session()->get('permissions') ?? [])): ?>
          <button type="button" class="btn btn-primary" onclick="openConfigureWirelessModal()" style="display:inline-flex; align-items:center; gap:6px;">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            Setup Wi-Fi Interface
          </button>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if (empty($wireless)): ?>
    <div class="card card-glass animate-in" style="padding:48px 24px; text-align:center; border:1px dashed var(--color-border-bright);">
      <div style="width:56px; height:56px; border-radius:50%; background:rgba(236,72,153,0.1); color:#f472b6; display:flex; align-items:center; justify-content:center; margin:0 auto 16px;">
        <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
        </svg>
      </div>
      <h3 style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin-bottom:8px;">No Wireless Networks Configured</h3>
      <p style="font-size:0.83rem; color:var(--text-secondary); max-width:540px; margin:0 auto 20px; line-height:1.5;">
        Use the installed Wi-Fi adapter (<code><?= esc(implode(', ', $wirelessRadios ?? [])) ?></code>) to broadcast corporate or guest Wi-Fi SSIDs with full WPA3 security and client isolation.
      </p>
      <?php if (session()->get('is_superadmin') || in_array('interfaces.edit', session()->get('permissions') ?? [])): ?>
        <button type="button" class="btn btn-primary" onclick="openConfigureWirelessModal()" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          Configure First Wi-Fi
        </button>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <!-- Wireless cards grid -->
    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(360px, 1fr)); gap:20px; margin-bottom:28px;" class="animate-in">
      <?php foreach ($wireless as $wl): ?>
        <?php
          $liveWl = $wl['live'] ?? [];
          $clients = $liveWl['clients'] ?? [];
          $secBadge = match ($wl['security']) {
            'wpa3'      => 'WPA3-SAE (Next-Gen)',
            'wpa2_wpa3' => 'WPA2/WPA3 Mixed',
            'wpa2'      => 'WPA2-Personal (AES)',
            default     => 'Open Network',
          };
        ?>
        <div class="card stat-card pink" style="display:flex; flex-direction:column; justify-content:space-between; padding:20px;">
          <div>
            <!-- Header -->
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
              <div>
                <div style="display:flex; align-items:center; gap:8px;">
                  <span style="font-weight:700; font-size:1.2rem; color:var(--text-primary); font-family:var(--font-mono);"><?= esc($wl['interface']) ?></span>
                  <span class="badge" style="background:rgba(236,72,153,0.2); color:#f472b6; font-size:0.68rem; font-weight:600; text-transform:uppercase;"><?= esc($wl['mode']) ?> MODE</span>
                </div>
                <div style="font-size:0.85rem; color:var(--text-secondary); margin-top:2px; font-weight:500;"><?= esc($wl['name']) ?></div>
              </div>
              <div>
                <?php if (!empty($wl['enabled'])): ?>
                  <span class="badge badge-success" style="display:inline-flex; align-items:center; gap:5px;">
                    <span class="pulse-dot"></span>
                    Transmitting
                  </span>
                <?php else: ?>
                  <span class="badge badge-muted">Disabled</span>
                <?php endif; ?>
              </div>
            </div>

            <!-- SSID -->
            <div style="background:rgba(0,0,0,0.25); border:1px solid var(--border-subtle); border-radius:var(--radius-md); padding:12px 14px; margin-bottom:14px;">
              <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; margin-bottom:4px;">SSID Network Name</div>
              <div style="font-size:1.15rem; font-weight:700; color:var(--text-primary); font-family:var(--font-mono); display:flex; align-items:center; gap:8px;">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:#f472b6;">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
                </svg>
                <?= esc($wl['ssid']) ?>
              </div>
              <div style="display:flex; gap:6px; margin-top:8px; flex-wrap:wrap;">
                <span class="badge" style="background:rgba(59,130,246,0.15); color:#60a5fa; font-size:0.68rem;"><?= esc($secBadge) ?></span>
                <span class="badge badge-muted" style="font-size:0.68rem;">Channel <?= (int) $wl['channel'] ?></span>
                <?php if (!empty($wl['client_isolation'])): ?>
                  <span class="badge badge-amber" style="font-size:0.68rem;">Client Isolation</span>
                <?php endif; ?>
              </div>
            </div>

            <!-- Parameters Grid -->
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:6px 12px; font-size:0.75rem; color:var(--text-secondary); margin-bottom:16px; background:rgba(0,0,0,0.15); padding:10px 14px; border-radius:var(--radius-sm); border:1px solid var(--border-subtle);">
              <div>802.11 Mode: <strong style="color:var(--text-primary); font-family:var(--font-mono); text-transform:uppercase;">802.11<?= esc($wl['hw_mode']) ?></strong></div>
              <div>TX Power: <strong style="color:var(--text-primary); font-family:var(--font-mono);"><?= (int) $wl['tx_power'] ?> dBm</strong></div>
              <div>Country: <strong style="color:var(--text-primary); font-family:var(--font-mono);"><?= esc($wl['country_code']) ?></strong></div>
              <div>Connected Stations: <strong style="color:var(--color-emerald); font-family:var(--font-mono);"><?= count($clients) ?> clients</strong></div>
            </div>

            <!-- Connected clients if any -->
            <?php if (!empty($clients)): ?>
              <div style="margin-bottom:12px;">
                <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; margin-bottom:6px;">Connected Client Stations</div>
                <div style="max-height:110px; overflow-y:auto; border:1px solid var(--border-subtle); border-radius:var(--radius-sm); font-size:0.72rem; font-family:var(--font-mono);">
                  <?php foreach ($clients as $client): ?>
                    <div style="padding:5px 8px; border-bottom:1px solid var(--border-subtle); display:flex; justify-content:space-between; align-items:center;">
                      <span style="color:var(--text-primary);"><?= esc($client['mac'] ?? 'Unknown') ?></span>
                      <span style="color:var(--color-emerald);"><?= esc($client['signal'] ?? '-55 dBm') ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
          </div>

          <!-- Footer -->
          <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid var(--color-border); padding-top:12px; margin-top:6px;">
            <span style="font-size:0.7rem; color:var(--text-muted);">Daemon: <code><?= $wl['mode'] === 'ap' ? 'hostapd' : 'wpa_supplicant' ?></code></span>
            <div style="display:flex; gap:8px;">
              <?php if (session()->get('is_superadmin') || in_array('interfaces.edit', session()->get('permissions') ?? [])): ?>
                <button type="button" class="btn btn-ghost btn-sm" style="font-size:0.72rem; padding:2px 8px;" onclick="openConfigureWirelessModal(<?= esc(json_encode(array_diff_key($wl, ['passphrase' => 1, 'live' => 1]) + ['has_passphrase' => ($wl['passphrase'] ?? '') !== ''], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), 'attr') ?>)">
                  Configure
                </button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php endif; ?>

<!-- ============================================================
     INTERFACES MODAL STYLES & DIALOGS
============================================================ -->
<style>
/* ── Modal Backdrop & Dialog System ── */
.iface-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(7, 11, 20, 0.72);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  z-index: 9999;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 16px;
}
.iface-modal-backdrop.active {
  display: flex !important;
}

.iface-modal-dialog {
  background: var(--color-surface-1);
  border: 1px solid var(--color-border-bright);
  border-radius: var(--radius-lg);
  width: 100%;
  max-width: 680px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 25px 60px -15px rgba(0,0,0,0.65), 0 0 0 1px var(--color-border);
  overflow: hidden;
  animation: ifaceModalIn 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}
.iface-modal-dialog.sm {
  max-width: 480px;
}

@keyframes ifaceModalIn {
  from { opacity: 0; transform: translateY(-16px) scale(0.98); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}

.iface-modal-header {
  background: var(--color-surface-2);
  padding: 16px 22px;
  border-bottom: 1px solid var(--color-border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-shrink: 0;
}
.iface-modal-title {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--text-primary);
  margin: 0;
  line-height: 1.3;
}
.iface-modal-title svg {
  color: var(--color-cyan);
}
.iface-modal-close {
  background: transparent;
  border: none;
  color: var(--text-muted);
  width: 32px;
  height: 32px;
  border-radius: var(--radius-md);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all var(--transition-fast);
  padding: 0;
}
.iface-modal-close:hover {
  background: var(--color-surface-3);
  color: var(--text-primary);
}

.iface-modal-form {
  display: flex;
  flex-direction: column;
  flex: 1 1 auto;
  min-height: 0;
  margin: 0;
  overflow: hidden;
}

.iface-modal-body {
  overflow-y: auto;
  flex: 1 1 auto;
  min-height: 0;
  padding: 22px;
  overscroll-behavior: contain;
}

.iface-modal-footer {
  background: var(--color-surface-2);
  padding: 14px 22px;
  border-top: 1px solid var(--color-border);
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}

/* ── Category Choice Cards ── */
.iface-type-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-bottom: 20px;
}
.iface-type-card {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 14px 16px;
  border-radius: var(--radius-md);
  border: 1.5px solid var(--color-border);
  background: var(--color-surface-2);
  cursor: pointer;
  transition: all var(--transition-fast);
  position: relative;
  user-select: none;
}
.iface-type-card:hover {
  border-color: var(--color-border-bright);
  background: var(--color-surface-3);
}
.iface-type-card.active {
  border-color: var(--color-cyan);
  background: var(--color-cyan-dim);
  box-shadow: 0 0 0 1px var(--color-cyan);
}
.iface-type-card.vlan-card.active {
  border-color: var(--color-purple);
  background: var(--color-purple-dim);
  box-shadow: 0 0 0 1px var(--color-purple);
}
.iface-type-radio {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  border: 2px solid var(--text-muted);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  margin-top: 2px;
  transition: border-color var(--transition-fast);
}
.iface-type-card.active .iface-type-radio {
  border-color: var(--color-cyan);
}
.iface-type-card.vlan-card.active .iface-type-radio {
  border-color: var(--color-purple);
}
.iface-type-radio-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: transparent;
  transition: background var(--transition-fast);
}
.iface-type-card.active .iface-type-radio-dot {
  background: var(--color-cyan);
}
.iface-type-card.vlan-card.active .iface-type-radio-dot {
  background: var(--color-purple);
}
.iface-type-body {
  flex: 1;
  min-width: 0;
}
.iface-type-title {
  font-weight: 600;
  font-size: 0.85rem;
  color: var(--text-primary);
  line-height: 1.3;
}
.iface-type-desc {
  font-size: 0.72rem;
  color: var(--text-secondary);
  margin-top: 3px;
  line-height: 1.35;
}

/* ── Form Rows, Columns & Boxes ── */
.iface-form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-bottom: 18px;
}
.iface-form-row-custom {
  display: grid;
  grid-template-columns: 140px 1fr;
  gap: 16px;
  margin-bottom: 18px;
}
.iface-form-col {
  min-width: 0; /* Prevents overflow/blowout */
}
.iface-form-col .form-control {
  box-sizing: border-box;
}

.iface-box-card {
  padding: 16px;
  background: var(--color-surface-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  margin-bottom: 18px;
}
.iface-box-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}
.iface-box-card.vlan-box {
  background: rgba(168, 85, 247, 0.05);
  border: 1px solid rgba(168, 85, 247, 0.25);
}
.iface-vlan-preview {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 12px;
  padding-top: 10px;
  border-top: 1px dashed rgba(168, 85, 247, 0.25);
  font-size: 0.78rem;
  color: var(--text-secondary);
}

.iface-enable-card {
  padding: 14px 16px;
  background: var(--color-surface-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  margin-bottom: 4px;
}

@media (max-width: 640px) {
  .iface-type-grid { grid-template-columns: 1fr; }
  .iface-form-row { grid-template-columns: 1fr; }
  .iface-form-row-custom { grid-template-columns: 1fr; }
  .iface-vlan-grid { grid-template-columns: 1fr !important; }
}
</style>

<!-- Interface creation & editing is handled on the dedicated page: /interfaces/new & /interfaces/edit/:id -->

<!-- ============================================================
     DELETE CONFIRMATION MODAL
============================================================ -->
<div id="deleteInterfaceModal" class="iface-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="deleteInterfaceModalTitle">
  <div class="iface-modal-dialog sm">
    <div class="iface-modal-header" style="border-bottom:1px solid rgba(239,68,68,0.25);">
      <h3 class="iface-modal-title" id="deleteInterfaceModalTitle" style="color:var(--color-red);">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        Confirm Interface Deletion
      </h3>
      <button type="button" class="iface-modal-close" onclick="closeDeleteInterfaceModal()" aria-label="Close dialog">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>
    <form id="deleteInterfaceForm" method="POST" action="" class="iface-modal-form">
      <?= csrf_field() ?>
      <div class="iface-modal-body" style="padding:22px;">
        <p style="font-size:0.9rem; color:var(--text-primary); margin:0 0 14px 0;">
          Are you sure you want to permanently delete interface <strong id="deleteInterfaceName" style="color:var(--color-red); font-family:var(--font-mono);"></strong>?
        </p>
        <div style="font-size:0.78rem; color:var(--text-secondary); background:rgba(239,68,68,0.08); padding:12px 14px; border-radius:var(--radius-md); border:1px solid rgba(239,68,68,0.2); line-height:1.45;">
          <strong style="color:var(--color-red); display:block; margin-bottom:4px;">Safety Dependency Guard:</strong>
          If active firewall policies, NAT rules, child VLANs, or DHCP server instances reference this interface, deletion will be safely rejected.
        </div>
      </div>
      <div class="iface-modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeDeleteInterfaceModal()">Cancel</button>
        <button type="submit" class="btn btn-danger" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
          </svg>
          Delete Interface
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================
     ADD / CONFIGURE LACP BOND MODAL
============================================================ -->
<div id="addBondModal" class="iface-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="addBondModalTitle">
  <div class="iface-modal-dialog">
    <div class="iface-modal-header">
      <h2 class="iface-modal-title" id="addBondModalTitle">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:#60a5fa;">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656l1.5-1.5m7.156-7.156a4 4 0 00-5.656 0l-3 3a4 4 0 000 5.656l1.5 1.5"/>
        </svg>
        Configure Link Aggregation Group (bond0)
      </h2>
      <button type="button" class="iface-modal-close" onclick="closeAddBondModal()" aria-label="Close dialog">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>

    <form method="POST" action="<?= base_url('interfaces/bonds/store') ?>" class="iface-modal-form">
      <?= csrf_field() ?>
      <div class="iface-modal-body">
        <div class="iface-form-row">
          <div class="iface-form-col">
            <label class="form-label" for="bond_id">Bond Interface Device <span style="color:var(--color-red);">*</span></label>
            <input type="text" id="bond_id" name="id" class="form-control" value="bond0" required pattern="^bond[0-9]+$" placeholder="bond0" style="font-family:var(--font-mono);" autocomplete="off">
            <div class="form-hint">Kernel bonding device identifier (e.g. <code>bond0</code>, <code>bond1</code>).</div>
          </div>

          <div class="iface-form-col">
            <label class="form-label" for="bond_name">Descriptive Name</label>
            <input type="text" id="bond_name" name="name" class="form-control" placeholder="e.g. Core Switch LACP Trunk" value="Core Switch LACP Trunk" autocomplete="off">
            <div class="form-hint">Friendly label shown in firewall and routing interfaces.</div>
          </div>
        </div>

        <div style="margin-bottom:18px;">
          <label class="form-label" for="bond_mode">Aggregation Mode <span style="color:var(--color-red);">*</span></label>
          <select id="bond_mode" name="mode" class="form-control" style="font-size:0.88rem;">
            <option value="802.3ad" selected>802.3ad — IEEE 802.3ad Dynamic Link Aggregation (LACP) (Recommended)</option>
            <option value="active-backup">active-backup — High Availability Failover (Primary/Backup)</option>
            <option value="balance-xor">balance-xor — Transmit Load Balancing (Hash Policy)</option>
            <option value="balance-rr">balance-rr — Round-Robin Packet Distribution</option>
            <option value="broadcast">broadcast — Complete Fault Tolerance (All Ports)</option>
          </select>
          <div class="form-hint">IEEE 802.3ad LACP provides dynamic negotiation with connected smart/managed switches.</div>
        </div>

        <!-- Member physical slaves selection -->
        <div style="margin-bottom:18px;">
          <label class="form-label">Member Physical Interfaces (Slaves) <span style="color:var(--color-red);">*</span></label>
          <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(130px, 1fr)); gap:8px; margin-top:6px;">
            <?php 
              // Wired ports this appliance actually has; nothing is pre-ticked,
              // so a member (possibly the WAN uplink) is always a deliberate choice.
              $selectablePorts = array_values(array_filter($allPhysicalDevs ?? [], static fn ($d) => empty($hardwareNics[$d]['wireless'])));
              if ($selectablePorts === []):
            ?>
              <div class="form-hint" style="grid-column:1/-1;">No physical Ethernet ports were reported by the appliance.</div>
            <?php endif;
              foreach ($selectablePorts as $idx => $port):
                $isChecked = false;
            ?>
              <label style="display:flex; align-items:center; gap:8px; padding:8px 12px; background:var(--color-surface-2); border:1.5px solid var(--color-border); border-radius:var(--radius-md); cursor:pointer; font-family:var(--font-mono); font-size:0.83rem;">
                <input type="checkbox" name="slaves[]" value="<?= esc($port) ?>" <?= $isChecked ? 'checked' : '' ?> style="accent-color:var(--color-primary);">
                <span><?= esc($port) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="form-hint">Select at least one physical Ethernet adapter to aggregate into this bond.</div>
        </div>

        <!-- Advanced parameters -->
        <div style="background:rgba(0,0,0,0.15); border:1px solid var(--border-subtle); border-radius:var(--radius-md); padding:14px; margin-bottom:12px;">
          <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; margin-bottom:10px;">LACP Protocol &amp; Hashing Options</div>
          <div class="iface-form-row">
            <div class="iface-form-col">
              <label class="form-label" for="bond_lacp_rate">LACP Heartbeat Rate</label>
              <select id="bond_lacp_rate" name="lacp_rate" class="form-control">
                <option value="fast" selected>Fast (1s heartbeats — Fast Failure Recovery)</option>
                <option value="slow">Slow (30s heartbeats — Standard)</option>
              </select>
            </div>
            <div class="iface-form-col">
              <label class="form-label" for="bond_xmit_hash_policy">Transmit Hash Policy</label>
              <select id="bond_xmit_hash_policy" name="xmit_hash_policy" class="form-control">
                <option value="layer2+3" selected>layer2+3 (Layer 2 MAC + Layer 3 IP) — Recommended</option>
                <option value="layer3+4">layer3+4 (Layer 3 IP + Layer 4 Port)</option>
                <option value="layer2">layer2 (Layer 2 MAC only)</option>
              </select>
            </div>
          </div>

          <div class="iface-form-row" style="margin-top:10px;">
            <div class="iface-form-col">
              <label class="form-label" for="bond_miimon">MII Link Polling (ms)</label>
              <input type="number" id="bond_miimon" name="miimon" class="form-control" value="100" min="10" max="1000">
            </div>
            <div class="iface-form-col">
              <label class="form-label" for="bond_mtu">MTU Frame Size</label>
              <input type="number" id="bond_mtu" name="mtu" class="form-control" value="1500" min="576" max="9000">
            </div>
          </div>
        </div>
      </div>

      <div class="iface-modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeAddBondModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
          </svg>
          Save &amp; Apply Bond
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================
     DELETE LACP BOND CONFIRMATION MODAL
============================================================ -->
<div id="deleteBondModal" class="iface-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="deleteBondModalTitle">
  <div class="iface-modal-dialog sm">
    <div class="iface-modal-header">
      <h3 class="iface-modal-title" id="deleteBondModalTitle" style="color:var(--color-red);">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-red);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        Confirm Bond Interface Deletion
      </h3>
      <button type="button" class="iface-modal-close" onclick="closeDeleteBondModal()" aria-label="Close dialog">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>
    <form id="deleteBondForm" method="POST" action="" class="iface-modal-form">
      <?= csrf_field() ?>
      <div class="iface-modal-body" style="padding:22px;">
        <p style="font-size:0.9rem; color:var(--text-primary); margin:0 0 14px 0;">
          Are you sure you want to permanently delete aggregation link <strong id="deleteBondName" style="color:var(--color-red); font-family:var(--font-mono);"></strong>?
        </p>
        <div style="font-size:0.78rem; color:var(--text-secondary); background:rgba(239,68,68,0.08); padding:12px 14px; border-radius:var(--radius-md); border:1px solid rgba(239,68,68,0.2); line-height:1.45;">
          <strong style="color:var(--color-red); display:block; margin-bottom:4px;">Kernel Interface Notice:</strong>
          All member slave ports will be released from <code>bond0</code> back into standalone unmanaged state in the Micron OS network stack.
        </div>
      </div>
      <div class="iface-modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeDeleteBondModal()">Cancel</button>
        <button type="submit" class="btn btn-danger" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
          </svg>
          Delete Bond Link
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================
     ADD / CONFIGURE BRIDGE MODAL (br0)
============================================================ -->
<div id="addBridgeModal" class="iface-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="addBridgeModalTitle">
  <div class="iface-modal-dialog">
    <div class="iface-modal-header">
      <h2 class="iface-modal-title" id="addBridgeModalTitle">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-emerald);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l4-4 4 4m0 6l-4 4-4-4"/>
        </svg>
        Configure Layer 2 Bridge Interface (br0 Switch)
      </h2>
      <button type="button" class="iface-modal-close" onclick="closeAddBridgeModal()" aria-label="Close dialog">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>

    <form method="POST" action="<?= base_url('interfaces/bridges/store') ?>" class="iface-modal-form">
      <?= csrf_field() ?>
      <div class="iface-modal-body">
        <div class="iface-form-row">
          <div class="iface-form-col">
            <label class="form-label" for="bridge_id">Bridge Interface Device <span style="color:var(--color-red);">*</span></label>
            <input type="text" id="bridge_id" name="id" class="form-control" value="br0" required pattern="^[a-zA-Z0-9_-]{2,15}$" placeholder="br0" style="font-family:var(--font-mono);" autocomplete="off">
            <div class="form-hint">Kernel bridge device identifier (e.g. <code>br0</code>, <code>br-lan</code>).</div>
          </div>

          <div class="iface-form-col">
            <label class="form-label" for="bridge_name">Descriptive Name</label>
            <input type="text" id="bridge_name" name="name" class="form-control" placeholder="e.g. LAN Switch (br0)" value="LAN Switch (br0)" autocomplete="off">
            <div class="form-hint">Friendly label shown across firewall, routing, and DHCP interfaces.</div>
          </div>
        </div>

        <!-- Member physical slaves selection -->
        <div style="margin-bottom:18px;">
          <label class="form-label">Member Switch Ports (Bridged Slaves) <span style="color:var(--color-red);">*</span></label>
          <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(130px, 1fr)); gap:8px; margin-top:6px;">
            <?php 
              // Wired ports this appliance actually has; nothing is pre-ticked,
              // so a member (possibly the WAN uplink) is always a deliberate choice.
              $selectablePorts = array_values(array_filter($allPhysicalDevs ?? [], static fn ($d) => empty($hardwareNics[$d]['wireless'])));
              if ($selectablePorts === []):
            ?>
              <div class="form-hint" style="grid-column:1/-1;">No physical Ethernet ports were reported by the appliance.</div>
            <?php endif;
              foreach ($selectablePorts as $idx => $port):
                $isChecked = false;
            ?>
              <label style="display:flex; align-items:center; gap:8px; padding:8px 12px; background:var(--color-surface-2); border:1.5px solid var(--color-border); border-radius:var(--radius-md); cursor:pointer; font-family:var(--font-mono); font-size:0.83rem;">
                <input type="checkbox" name="slaves[]" value="<?= esc($port) ?>" <?= $isChecked ? 'checked' : '' ?> style="accent-color:var(--color-emerald);">
                <span><?= esc($port) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="form-hint">Select the physical Ethernet ports to bundle into this switch bridge. Keep the WAN port out of the bridge.</div>
        </div>

        <!-- Advanced parameters -->
        <div style="background:rgba(0,0,0,0.15); border:1px solid var(--border-subtle); border-radius:var(--radius-md); padding:14px; margin-bottom:12px;">
          <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; margin-bottom:10px;">IEEE 802.1D Spanning Tree &amp; Frame Parameters</div>
          <div class="iface-form-row">
            <div class="iface-form-col">
              <label class="form-label" for="bridge_forward_delay">Forward Delay (seconds)</label>
              <input type="number" id="bridge_forward_delay" name="forward_delay" class="form-control" value="2" min="0" max="30">
              <div class="form-hint">Default: 2s (quick port convergence)</div>
            </div>
            <div class="iface-form-col">
              <label class="form-label" for="bridge_mtu">MTU Frame Size</label>
              <input type="number" id="bridge_mtu" name="mtu" class="form-control" value="1500" min="576" max="9000">
              <div class="form-hint">Default: 1500 (standard Ethernet)</div>
            </div>
          </div>

          <div style="margin-top:12px;">
            <label style="display:flex; align-items:center; gap:10px; cursor:pointer; margin:0;">
              <input type="checkbox" id="bridge_stp" name="stp" value="1" checked style="accent-color:var(--color-emerald); width:18px; height:18px; cursor:pointer;">
              <div>
                <span style="font-weight:600; font-size:0.85rem; color:var(--text-primary);">Enable 802.1D Spanning Tree Protocol (STP)</span>
                <div style="font-size:0.73rem; color:var(--text-secondary);">Prevents network switching loops and broadcast storms across bridged ports.</div>
              </div>
            </label>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="bridge_description">Description / Notes</label>
          <input type="text" id="bridge_description" name="description" class="form-control" placeholder="e.g. Core LAN Switch Ports with STP" value="LAN Switch Ports with STP">
        </div>
      </div>

      <div class="iface-modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeAddBridgeModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
          </svg>
          Save &amp; Apply Bridge
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================
     DELETE BRIDGE CONFIRMATION MODAL
============================================================ -->
<div id="deleteBridgeModal" class="iface-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="deleteBridgeModalTitle">
  <div class="iface-modal-dialog sm">
    <div class="iface-modal-header">
      <h3 class="iface-modal-title" id="deleteBridgeModalTitle" style="color:var(--color-red);">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-red);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        Confirm Bridge Interface Deletion
      </h3>
      <button type="button" class="iface-modal-close" onclick="closeDeleteBridgeModal()" aria-label="Close dialog">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>
    <form id="deleteBridgeForm" method="POST" action="" class="iface-modal-form">
      <?= csrf_field() ?>
      <div class="iface-modal-body" style="padding:22px;">
        <p style="font-size:0.9rem; color:var(--text-primary); margin:0 0 14px 0;">
          Are you sure you want to permanently delete bridge interface <strong id="deleteBridgeName" style="color:var(--color-red); font-family:var(--font-mono);"></strong>?
        </p>
        <div style="font-size:0.78rem; color:var(--text-secondary); background:rgba(239,68,68,0.08); padding:12px 14px; border-radius:var(--radius-md); border:1px solid rgba(239,68,68,0.2); line-height:1.45;">
          <strong style="color:var(--color-red); display:block; margin-bottom:4px;">Kernel Interface Notice:</strong>
          All member slave ports will be detached from the bridge back into standalone unmanaged state in the Micron OS network stack.
        </div>
      </div>
      <div class="iface-modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeDeleteBridgeModal()">Cancel</button>
        <button type="submit" class="btn btn-danger" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
          </svg>
          Delete Bridge Interface
        </button>
      </div>
    </form>
  </div>
</div>

<?php if ($hasWireless ?? false): ?>
<!-- ============================================================
     CONFIGURE WIRELESS LAN MODAL
============================================================ -->
<div id="configureWirelessModal" class="iface-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="configureWirelessModalTitle">
  <div class="iface-modal-dialog">
    <div class="iface-modal-header">
      <h2 class="iface-modal-title" id="configureWirelessModalTitle">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:#f472b6;">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
        </svg>
        Configure Wireless LAN
      </h2>
      <button type="button" class="iface-modal-close" onclick="closeConfigureWirelessModal()" aria-label="Close dialog">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>

    <form method="POST" action="<?= base_url('interfaces/wireless/save') ?>" class="iface-modal-form">
      <?= csrf_field() ?>
      <input type="hidden" id="wlan_id" name="id" value="<?= esc($wirelessRadios[0] ?? '') ?>">
      <div class="iface-modal-body">
        <div class="iface-form-row">
          <div class="iface-form-col">
            <label class="form-label" for="wlan_interface">Wireless Device Interface <span style="color:var(--color-red);">*</span></label>
            <select id="wlan_interface" name="interface" class="form-control" required style="font-family:var(--font-mono);">
              <?php foreach (($wirelessRadios ?? []) as $__radio): ?>
                <option value="<?= esc($__radio) ?>"><?= esc(\App\Libraries\NetworkHardware::describe($hardwareNics[$__radio] ?? ['name' => $__radio, 'wireless' => true])) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-hint">Wi-Fi adapters detected on this appliance.</div>
          </div>

          <div class="iface-form-col">
            <label class="form-label" for="wlan_name">Friendly Network Name</label>
            <input type="text" id="wlan_name" name="name" class="form-control" value="Corporate Wi-Fi AP" autocomplete="off">
            <div class="form-hint">Identifier in interface assignment lists.</div>
          </div>
        </div>

        <div class="iface-form-row">
          <div class="iface-form-col">
            <label class="form-label" for="wlan_mode">Radio Operating Mode <span style="color:var(--color-red);">*</span></label>
            <select id="wlan_mode" name="mode" class="form-control">
              <option value="ap" selected>Access Point (AP — hostapd daemon)</option>
              <option value="client">Client / Station (wpa_supplicant daemon)</option>
            </select>
            <div class="form-hint">AP mode broadcasts an SSID; Client mode connects to an external Wi-Fi network.</div>
          </div>

          <div class="iface-form-col">
            <label class="form-label" for="wlan_ssid">SSID Network Name <span style="color:var(--color-red);">*</span></label>
            <input type="text" id="wlan_ssid" name="ssid" class="form-control" value="MicronOS-Corp" required autocomplete="off">
            <div class="form-hint">Broadcasted network name (1-32 characters).</div>
          </div>
        </div>

        <div class="iface-form-row">
          <div class="iface-form-col">
            <label class="form-label" for="wlan_security">Security Protocol <span style="color:var(--color-red);">*</span></label>
            <select id="wlan_security" name="security" class="form-control">
              <option value="wpa2" selected>WPA2-Personal (AES-CCMP — High Compatibility)</option>
              <option value="wpa3">WPA3-Personal (SAE — Next-Gen Robust Security)</option>
              <option value="wpa2_wpa3">WPA2 / WPA3 Mixed Transition Mode</option>
              <option value="open">Open (No Encryption)</option>
            </select>
          </div>

          <div class="iface-form-col">
            <label class="form-label" for="wlan_passphrase">Pre-Shared Key / Passphrase</label>
            <input type="text" id="wlan_passphrase" name="passphrase" class="form-control" value="micronpass2026" minlength="8" style="font-family:var(--font-mono);" autocomplete="off">
            <div class="form-hint">WPA passphrase (minimum 8 characters).</div>
          </div>
        </div>

        <!-- Radio & Channel Configuration -->
        <div style="background:rgba(0,0,0,0.15); border:1px solid var(--border-subtle); border-radius:var(--radius-md); padding:14px; margin-bottom:14px;">
          <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; margin-bottom:10px;">802.11 RF &amp; Frequency Parameters</div>
          <div class="iface-form-row">
            <div class="iface-form-col">
              <label class="form-label" for="wlan_hw_mode">802.11 Hardware Standard</label>
              <select id="wlan_hw_mode" name="hw_mode" class="form-control">
                <option value="g" selected>802.11g / n (2.4 GHz Band)</option>
                <option value="a">802.11a / n / ac (5 GHz High Speed)</option>
                <option value="ax">802.11ax (Wi-Fi 6 Ultra Band)</option>
              </select>
            </div>
            <div class="iface-form-col">
              <label class="form-label" for="wlan_channel">Operating Channel</label>
              <input type="number" id="wlan_channel" name="channel" class="form-control" value="6" min="1" max="165">
              <div class="form-hint">1-14 for 2.4 GHz; 36-165 for 5 GHz.</div>
            </div>
          </div>

          <div class="iface-form-row" style="margin-top:10px;">
            <div class="iface-form-col">
              <label class="form-label" for="wlan_country">Country Code</label>
              <input type="text" id="wlan_country" name="country_code" class="form-control" value="US" maxlength="2" style="text-transform:uppercase; font-family:var(--font-mono);">
            </div>
            <div class="iface-form-col">
              <label class="form-label" for="wlan_tx_power">TX Power (dBm)</label>
              <input type="number" id="wlan_tx_power" name="tx_power" class="form-control" value="20" min="1" max="30">
            </div>
          </div>

          <div style="display:flex; gap:16px; margin-top:14px; flex-wrap:wrap;">
            <label style="display:flex; align-items:center; gap:8px; font-size:0.83rem; cursor:pointer;">
              <input type="checkbox" id="wlan_hidden_ssid" name="hidden_ssid" value="1" style="accent-color:var(--color-primary);">
              <span>Hidden SSID (Suppress Beacon Broadcast)</span>
            </label>
            <label style="display:flex; align-items:center; gap:8px; font-size:0.83rem; cursor:pointer;">
              <input type="checkbox" id="wlan_client_isolation" name="client_isolation" value="1" style="accent-color:var(--color-primary);">
              <span>Client Isolation (Prevent Inter-Station Traffic)</span>
            </label>
          </div>
        </div>
      </div>

      <div class="iface-modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeConfigureWirelessModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
          </svg>
          Save &amp; Apply Wi-Fi
        </button>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>

<!-- ============================================================
     ASSIGN INTERFACES MODAL (Port Assignment & Auto-Swap)
============================================================ -->
<div id="assignInterfacesModal" class="iface-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="assignInterfacesModalTitle">
  <div class="iface-modal-dialog" style="max-width:580px;">
    <div class="iface-modal-header">
      <h2 class="iface-modal-title" id="assignInterfacesModalTitle">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-primary);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
        </svg>
        Interface Port Assignments
      </h2>
      <button type="button" class="iface-modal-close" onclick="closeAssignInterfacesModal()" aria-label="Close dialog">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>

    <form method="POST" action="<?= base_url('interfaces/assign') ?>" class="iface-modal-form" onsubmit="return validateAssignForm();">
      <?= csrf_field() ?>
      <div class="iface-modal-body">
        <p style="font-size:0.83rem; color:var(--text-secondary); margin-bottom:16px;">
          Map logical network roles (WAN, LAN, OPT) to physical hardware NIC ports. You can freely interchange, swap, or reassign any physical port. Existing IP addressing and firewall settings are preserved.
        </p>

        <?php
          $currentWanDev = '';
          $currentLanDev = '';
          $optIfaces = [];
          foreach ($interfaces as $item) {
              if (($item['interface_type'] ?? '') === 'physical') {
                  if ($item['id'] === 'wan') $currentWanDev = $item['physical_device'] ?? '';
                  elseif ($item['id'] === 'lan') $currentLanDev = $item['physical_device'] ?? '';
                  else $optIfaces[] = $item;
              }
          }
          $allNics = !empty($allPhysicalDevs) ? $allPhysicalDevs : array_keys($hardwareNics ?? []);
          if (empty($allNics)) {
              $allNics = array_unique(array_filter([$currentWanDev, $currentLanDev, 'eth0', 'eth1', 'eth2', 'eth3']));
          }
          if ($currentWanDev && !in_array($currentWanDev, $allNics, true)) $allNics[] = $currentWanDev;
          if ($currentLanDev && !in_array($currentLanDev, $allNics, true)) $allNics[] = $currentLanDev;
          sort($allNics);
        ?>

        <!-- WAN Assignment -->
        <div class="iface-form-row" style="margin-bottom:14px;">
          <div class="iface-form-col">
            <label class="form-label" for="assign_wan_device" style="display:flex; justify-content:space-between; align-items:center;">
              <span><strong>WAN</strong> (External / Gateway) <span style="color:var(--color-red);">*</span></span>
              <span class="badge badge-purple" style="font-size:0.68rem;">Default Route</span>
            </label>
            <select id="assign_wan_device" name="wan_device" class="form-control" required onchange="checkAssignConflict()">
              <?php foreach ($allNics as $nic): ?>
                <?php
                  $label = $nic;
                  if (isset($hardwareNics[$nic])) {
                      $label = \App\Libraries\NetworkHardware::describe($hardwareNics[$nic]);
                  }
                ?>
                <option value="<?= esc($nic) ?>" <?= $nic === $currentWanDev ? 'selected' : '' ?>>
                  <?= esc($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Quick Swap Button -->
        <div style="text-align:center; margin:-4px 0 12px 0;">
          <button type="button" class="btn btn-sm btn-secondary" onclick="swapAssignSelections()" style="gap:6px; font-size:0.75rem; padding:4px 14px; background:var(--tone-amber-bg, rgba(245,158,11,0.12)); color:var(--tone-amber-fg, #f59e0b); border:1px solid var(--tone-amber-border, rgba(245,158,11,0.3));">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/>
            </svg>
            Interchange WAN &amp; LAN Ports
          </button>
        </div>

        <!-- LAN Assignment -->
        <div class="iface-form-row" style="margin-bottom:14px;">
          <div class="iface-form-col">
            <label class="form-label" for="assign_lan_device" style="display:flex; justify-content:space-between; align-items:center;">
              <span><strong>LAN</strong> (Internal / Local) <span style="color:var(--color-red);">*</span></span>
              <span class="badge badge-cyan" style="font-size:0.68rem;">Admin Access</span>
            </label>
            <select id="assign_lan_device" name="lan_device" class="form-control" required onchange="checkAssignConflict()">
              <?php foreach ($allNics as $nic): ?>
                <?php
                  $label = $nic;
                  if (isset($hardwareNics[$nic])) {
                      $label = \App\Libraries\NetworkHardware::describe($hardwareNics[$nic]);
                  }
                ?>
                <option value="<?= esc($nic) ?>" <?= $nic === $currentLanDev ? 'selected' : '' ?>>
                  <?= esc($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <?php if (!empty($optIfaces)): ?>
        <div style="border-top:1px solid var(--border-subtle); margin:14px 0 10px 0; padding-top:10px;">
          <div style="font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-muted); margin-bottom:8px;">Optional Interfaces (OPT)</div>
          <?php foreach ($optIfaces as $opt): ?>
            <div class="iface-form-row" style="margin-bottom:10px;">
              <div class="iface-form-col">
                <label class="form-label" for="assign_opt_<?= esc($opt['id']) ?>">
                  <strong><?= esc($opt['name']) ?></strong> (<?= esc($opt['id']) ?>)
                </label>
                <select id="assign_opt_<?= esc($opt['id']) ?>" name="opt[<?= esc($opt['id']) ?>]" class="form-control" onchange="checkAssignConflict()">
                  <option value="">-- None / Unassigned --</option>
                  <?php foreach ($allNics as $nic): ?>
                    <?php
                      $label = $nic;
                      if (isset($hardwareNics[$nic])) {
                          $label = \App\Libraries\NetworkHardware::describe($hardwareNics[$nic]);
                      }
                    ?>
                    <option value="<?= esc($nic) ?>" <?= $nic === ($opt['physical_device'] ?? '') ? 'selected' : '' ?>>
                      <?= esc($label) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div id="assignConflictAlert" class="alert alert-warning" style="display:none; margin-top:12px; font-size:0.8rem; padding:10px 14px;">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
          </svg>
          <span id="assignConflictMsg"></span>
        </div>

      </div>

      <div class="iface-modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeAssignInterfacesModal()">Cancel</button>
        <button type="submit" id="btnSubmitAssign" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
          </svg>
          Save &amp; Apply Port Assignments
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openAddBridgeModal() {
  const modal = document.getElementById('addBridgeModal');
  modal.classList.add('active');
  const body = modal.querySelector('.iface-modal-body');
  if (body) body.scrollTop = 0;
  setTimeout(() => {
    document.getElementById('bridge_id')?.focus();
  }, 50);
}

function closeAddBridgeModal() {
  document.getElementById('addBridgeModal').classList.remove('active');
}

function confirmDeleteBridge(id, name) {
  document.getElementById('deleteBridgeName').textContent = name + ' (' + id + ')';
  document.getElementById('deleteBridgeForm').action = '<?= base_url('interfaces/bridges') ?>/' + encodeURIComponent(id) + '/delete';
  const modal = document.getElementById('deleteBridgeModal');
  modal.classList.add('active');
}

function closeDeleteBridgeModal() {
  document.getElementById('deleteBridgeModal').classList.remove('active');
}

function openAddBondModal() {
  const modal = document.getElementById('addBondModal');
  modal.classList.add('active');
  const body = modal.querySelector('.iface-modal-body');
  if (body) body.scrollTop = 0;
  setTimeout(() => {
    document.getElementById('bond_id')?.focus();
  }, 50);
}

function closeAddBondModal() {
  document.getElementById('addBondModal').classList.remove('active');
}

function confirmDeleteBond(id, name) {
  document.getElementById('deleteBondName').textContent = name + ' (' + id + ')';
  document.getElementById('deleteBondForm').action = '<?= base_url('interfaces/bonds') ?>/' + encodeURIComponent(id) + '/delete';
  const modal = document.getElementById('deleteBondModal');
  modal.classList.add('active');
}

function closeDeleteBondModal() {
  document.getElementById('deleteBondModal').classList.remove('active');
}

function openConfigureWirelessModal(data) {
  const modal = document.getElementById('configureWirelessModal');
  if (!modal) return;
  if (data) {
    document.getElementById('wlan_id').value = data.id || data.interface || '';
    if (data.interface) document.getElementById('wlan_interface').value = data.interface;
    document.getElementById('wlan_name').value = data.name || 'Wi-Fi AP';
    document.getElementById('wlan_mode').value = data.mode || 'ap';
    document.getElementById('wlan_ssid').value = data.ssid || 'MicronOS-Corp';
    document.getElementById('wlan_security').value = data.security || 'wpa2';
    document.getElementById('wlan_passphrase').value = '';
    document.getElementById('wlan_passphrase').placeholder = data.has_passphrase ? 'Leave blank to keep the saved passphrase' : '';
    document.getElementById('wlan_hw_mode').value = data.hw_mode || 'g';
    document.getElementById('wlan_channel').value = data.channel || 6;
    document.getElementById('wlan_country').value = data.country_code || 'US';
    document.getElementById('wlan_tx_power').value = data.tx_power || 20;
    document.getElementById('wlan_hidden_ssid').checked = !!parseInt(data.hidden_ssid);
    document.getElementById('wlan_client_isolation').checked = !!parseInt(data.client_isolation);
  }
  modal.classList.add('active');
  const body = modal.querySelector('.iface-modal-body');
  if (body) body.scrollTop = 0;
  setTimeout(() => {
    document.getElementById('wlan_ssid')?.focus();
  }, 50);
}

function closeConfigureWirelessModal() {
  document.getElementById('configureWirelessModal')?.classList.remove('active');
}

function openAddInterfaceModal() {
  window.location.href = '<?= base_url('interfaces/new') ?>';
}

function closeAddInterfaceModal() {
}

function selectInterfaceType(type) {
  const isVlan = (type === 'vlan');
  document.getElementById('radioTypePhysical').checked = !isVlan;
  document.getElementById('radioTypeVlan').checked     = isVlan;

  const cardPhysical = document.getElementById('typeCardPhysical');
  const cardVlan     = document.getElementById('typeCardVlan');
  if (isVlan) {
    cardPhysical.classList.remove('active');
    cardVlan.classList.add('active');
  } else {
    cardPhysical.classList.add('active');
    cardVlan.classList.remove('active');
  }

  document.getElementById('physicalDeviceFields').style.display = isVlan ? 'none' : 'block';
  document.getElementById('vlanFields').style.display           = isVlan ? 'block' : 'none';

  if (isVlan) {
    updateVlanDevicePreview();
  }
}

// Backward compatible alias
function toggleAddType(type) {
  selectInterfaceType(type);
}

function updateVlanDevicePreview() {
  const parent = document.getElementById('add_vlan_parent')?.value || 'eth0';
  const tag    = document.getElementById('add_vlan_tag')?.value || '100';
  const preview = document.getElementById('vlanPreviewDev');
  if (preview) {
    preview.textContent = parent + '.' + tag;
  }
}

function toggleAddIpMode(mode) {
  const isStatic = mode === 'static';
  const isPppoe  = mode === 'pppoe';
  document.getElementById('addStaticIpContainer').style.display = isStatic ? 'block' : 'none';
  document.getElementById('addPppoeContainer').style.display    = isPppoe  ? 'block' : 'none';

  const ipInput = document.getElementById('add_ipv4_address');
  if (ipInput) {
    if (isStatic) ipInput.setAttribute('required', 'required');
    else ipInput.removeAttribute('required');
  }

  const pppoeUser = document.getElementById('add_pppoe_username');
  if (pppoeUser) {
    if (isPppoe) pppoeUser.setAttribute('required', 'required');
    else pppoeUser.removeAttribute('required');
  }

  // Helpful MTU suggestion for PPPoE
  const mtuInput = document.getElementById('add_mtu');
  if (mtuInput) {
    if (isPppoe && mtuInput.value === '1500') {
      mtuInput.value = '1492';
    } else if (!isPppoe && mtuInput.value === '1492') {
      mtuInput.value = '1500';
    }
  }
}

function toggleCustomNicField(val) {
  const wrap = document.getElementById('customNicInputWrapper');
  const customInp = document.getElementById('add_custom_physical_device');
  if (!wrap) return;
  if (val === '__custom__') {
    wrap.style.display = 'block';
    if (customInp) customInp.focus();
  } else {
    wrap.style.display = 'none';
  }
}

// Auto-generate identifier slug from name unless user entered custom slug
const nameInput = document.getElementById('add_name');
const slugInput = document.getElementById('add_id');
let slugTouched = false;

if (slugInput) {
  slugInput.addEventListener('input', function() {
    slugTouched = this.value.trim().length > 0;
  });
}

if (nameInput && slugInput) {
  nameInput.addEventListener('input', function() {
    if (!slugTouched) {
      slugInput.value = this.value
        .toLowerCase()
        .replace(/[\s\-]+/g, '_')
        .replace(/[^a-z0-9_]/g, '');
    }
  });
}

function confirmDeleteInterface(id, name) {
  document.getElementById('deleteInterfaceName').textContent = name + ' (' + id + ')';
  document.getElementById('deleteInterfaceForm').action = '<?= base_url('interfaces') ?>/' + encodeURIComponent(id) + '/delete';
  const modal = document.getElementById('deleteInterfaceModal');
  modal.classList.add('active');
  const body = modal.querySelector('.iface-modal-body');
  if (body) body.scrollTop = 0;
}

function closeDeleteInterfaceModal() {
  document.getElementById('deleteInterfaceModal').classList.remove('active');
}

function openAssignInterfacesModal() {
  const modal = document.getElementById('assignInterfacesModal');
  if (!modal) return;
  modal.classList.add('active');
  const body = modal.querySelector('.iface-modal-body');
  if (body) body.scrollTop = 0;
  checkAssignConflict();
}

function closeAssignInterfacesModal() {
  document.getElementById('assignInterfacesModal')?.classList.remove('active');
}

function swapAssignSelections() {
  const wanSel = document.getElementById('assign_wan_device');
  const lanSel = document.getElementById('assign_lan_device');
  if (!wanSel || !lanSel) return;
  const temp = wanSel.value;
  wanSel.value = lanSel.value;
  lanSel.value = temp;
  checkAssignConflict();
}

function checkAssignConflict() {
  const wanSel = document.getElementById('assign_wan_device');
  const lanSel = document.getElementById('assign_lan_device');
  const alertBox = document.getElementById('assignConflictAlert');
  const msgBox = document.getElementById('assignConflictMsg');
  const submitBtn = document.getElementById('btnSubmitAssign');
  if (!wanSel || !lanSel || !alertBox || !msgBox) return;

  const used = {};
  let conflictMsg = '';

  if (wanSel.value && lanSel.value && wanSel.value === lanSel.value) {
    conflictMsg = 'Error: WAN and LAN cannot be assigned to the same physical port (' + wanSel.value + '). Please select distinct ports.';
  } else {
    // Check OPT duplicates
    const optSelects = document.querySelectorAll('select[name^="opt["]');
    for (const optSel of optSelects) {
      if (optSel.value) {
        if (optSel.value === wanSel.value) {
          conflictMsg = 'Warning: Port ' + optSel.value + ' is assigned to both WAN and an OPT interface. Reassigning will detach the OPT port.';
          break;
        } else if (optSel.value === lanSel.value) {
          conflictMsg = 'Warning: Port ' + optSel.value + ' is assigned to both LAN and an OPT interface. Reassigning will detach the OPT port.';
          break;
        }
      }
    }
  }

  if (conflictMsg) {
    alertBox.style.display = 'flex';
    msgBox.textContent = conflictMsg;
    if (submitBtn) {
      submitBtn.disabled = (wanSel.value === lanSel.value);
    }
  } else {
    alertBox.style.display = 'none';
    msgBox.textContent = '';
    if (submitBtn) submitBtn.disabled = false;
  }
}

function validateAssignForm() {
  const wanSel = document.getElementById('assign_wan_device');
  const lanSel = document.getElementById('assign_lan_device');
  if (wanSel && lanSel && wanSel.value === lanSel.value) {
    alert('WAN and LAN cannot share the same physical port (' + wanSel.value + ').');
    return false;
  }
  return true;
}

// Close modals when clicking backdrop
window.addEventListener('click', function(e) {
  const addModal = document.getElementById('addInterfaceModal');
  if (e.target === addModal) closeAddInterfaceModal();

  const delModal = document.getElementById('deleteInterfaceModal');
  if (e.target === delModal) closeDeleteInterfaceModal();

  const assignModal = document.getElementById('assignInterfacesModal');
  if (e.target === assignModal) closeAssignInterfacesModal();

  const addBondModal = document.getElementById('addBondModal');
  if (e.target === addBondModal) closeAddBondModal();

  const delBondModal = document.getElementById('deleteBondModal');
  if (e.target === delBondModal) closeDeleteBondModal();

  const addBridgeModal = document.getElementById('addBridgeModal');
  if (e.target === addBridgeModal) closeAddBridgeModal();

  const delBridgeModal = document.getElementById('deleteBridgeModal');
  if (e.target === delBridgeModal) closeDeleteBridgeModal();

  const wlanModal = document.getElementById('configureWirelessModal');
  if (e.target === wlanModal) closeConfigureWirelessModal();
});

// Close modals when pressing Escape key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeAddInterfaceModal();
    closeDeleteInterfaceModal();
    closeAssignInterfacesModal();
    closeAddBondModal();
    closeDeleteBondModal();
    closeAddBridgeModal();
    closeDeleteBridgeModal();
    closeConfigureWirelessModal();
  }
});

</script>

<?= $this->endSection() ?>
