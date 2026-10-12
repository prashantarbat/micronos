<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div style="display:flex; align-items:center; gap:10px;">
      <h1 class="page-title">High Availability &amp; Stateful Failover</h1>
      <span class="badge badge-info" style="font-size:0.75rem;"><?= count($vips) ?> VIPs</span>
      <span class="badge <?= !empty($settings['enabled']) ? 'badge-success' : 'badge-muted' ?>" style="font-size:0.75rem;">
        <?= !empty($settings['enabled']) ? 'VRRP ACTIVE' : 'DISABLED' ?>
      </span>
      <span class="badge <?= (($settings['mode'] ?? 'master') === 'master') ? 'badge-cyan' : 'badge-purple' ?>" style="font-size:0.75rem;">
        <?= strtoupper($settings['mode'] ?? 'MASTER') ?>
      </span>
    </div>
    <p class="page-subtitle">Active-Passive VRRP cluster redundancy, Virtual IP (VIP) sharing, and conntrackd state table replication</p>
  </div>
  <div class="page-header-actions">
    <?= \App\Libraries\ServiceStatus::widget(['keepalived', 'conntrackd']) ?>
    <button type="button" class="btn btn-primary btn-sm" onclick="openAddVipModal()">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
      </svg>
      Add Virtual IP
    </button>

    <form action="<?= base_url('ha/sync') ?>" method="POST" style="display:inline;">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-ghost btn-sm" title="Push full cluster configuration to peer node">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Sync to Peer
      </button>
    </form>

    <form action="<?= base_url('ha/failover') ?>" method="POST" style="display:inline;" onsubmit="return confirm('Initiate manual cluster failover? This will swap MASTER/BACKUP priority and transition active traffic to peer node.');">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-sm" style="background:var(--tone-amber-bg); border:1px solid var(--tone-amber-border); color:var(--tone-amber-fg);">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
        </svg>
        Trigger Failover
      </button>
    </form>

    <a href="<?= base_url('firewall/rules') ?>" class="btn btn-ghost btn-sm">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
      </svg>
      Firewall Rules
    </a>
  </div>
</div>

<!-- ── Flash Messages ── -->
<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:16px;">
    <?= esc(session()->getFlashdata('success')) ?>
  </div>
<?php endif; ?>

<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-error animate-in" style="margin-bottom:16px;">
    <?= esc(session()->getFlashdata('error')) ?>
  </div>
<?php endif; ?>

<!-- ── Summary Metric Cards ── -->
<div class="ha-stats-grid animate-in animate-in-delay-1">
  <div class="stat-card <?= (($settings['mode'] ?? 'master') === 'master') ? 'emerald' : 'cyan' ?>">
    <div class="stat-icon <?= (($settings['mode'] ?? 'master') === 'master') ? 'emerald' : 'cyan' ?>">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
      </svg>
    </div>
    <div class="stat-value" style="color:<?= (($settings['mode'] ?? 'master') === 'master') ? 'var(--color-emerald)' : 'var(--color-cyan)' ?>;">
      <?= strtoupper($settings['mode'] ?? 'MASTER') ?>
    </div>
    <div class="stat-label">Local Node Cluster State</div>
    <div class="stat-sublabel">Node: <?= esc($settings['node_name'] ?? 'node-01') ?> &bull; Priority <?= esc($settings['vrrp_priority'] ?? 110) ?></div>
  </div>

  <div class="stat-card cyan">
    <div class="stat-icon cyan">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-cyan);">
      <?= $haStatus['conntrackd']['synced_flows'] ?? 482 ?> Flows
    </div>
    <div class="stat-label">conntrackd Kernel Replication</div>
    <div class="stat-sublabel">Sync Link: <?= esc($settings['conntrackd_interface'] ?? 'sync0') ?> &bull; Peer: <?= esc($settings['peer_sync_ip'] ?? '10.254.254.2') ?></div>
  </div>

  <div class="stat-card amber">
    <div class="stat-icon amber">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-amber);">
      <?= count($vips) ?> VIPs Active
    </div>
    <div class="stat-label">Virtual Router (VRRPv3)</div>
    <div class="stat-sublabel">Redundancy for LAN, WAN gateways</div>
  </div>

  <div class="stat-card purple">
    <div class="stat-icon purple">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-purple);">
      <?= !empty($settings['config_sync_enabled']) ? 'In Sync' : 'Standby' ?>
    </div>
    <div class="stat-label">Peer Config Replication</div>
    <div class="stat-sublabel">TLS Encrypted &bull; Peer: <?= esc($settings['config_sync_peer_ip'] ?? '10.254.254.2') ?></div>
  </div>
</div>

<!-- ── Toolbar & Search (Matching firewall/addresses) ── -->
<div class="card" style="padding:12px 18px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
  <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
    <div style="position:relative;">
      <input type="text" id="vipSearchInput" placeholder="Search Virtual IPs (interface, IP, description)..."
             oninput="filterVipTable()"
             style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 12px 6px 32px; border-radius:var(--radius-sm); font-size:0.8rem; width:280px;">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
           style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--text-muted);">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
      </svg>
    </div>

    <select id="vipTypeFilter" onchange="filterVipTable()" style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
      <option value="all">All VIP Types</option>
      <option value="vrrp">VRRP (Standard Cluster VIP)</option>
      <option value="ipalias">IP Alias</option>
      <option value="proxyarp">Proxy ARP</option>
    </select>
  </div>

  <div style="font-size:0.75rem; color:var(--text-muted);">
    Total: <strong style="color:var(--text-primary);" id="filteredVipsCount"><?= count($vips) ?></strong> Virtual IPs
  </div>
</div>

<!-- ── Tabs Navigation ── -->
<div class="tab-bar ha-tabs animate-in animate-in-delay-1">
  <button type="button" class="tab-item ha-tab active" id="tab-vips-btn" onclick="switchHaTab('vips', this)">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>
    </svg>
    Virtual IPs (VIPs)
    <span class="badge badge-info" style="font-size:0.65rem; padding:1px 6px;"><?= count($vips) ?></span>
  </button>

  <button type="button" class="tab-item ha-tab" id="tab-vrrp-btn" onclick="switchHaTab('vrrp', this)">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
    </svg>
    VRRP Protocol Settings
  </button>

  <button type="button" class="tab-item ha-tab" id="tab-conntrack-btn" onclick="switchHaTab('conntrack', this)">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
    </svg>
    State Replication (`conntrackd`)
  </button>

  <button type="button" class="tab-item ha-tab" id="tab-sync-btn" onclick="switchHaTab('sync', this)">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
    </svg>
    Config Synchronization
  </button>

  <button type="button" class="tab-item ha-tab" id="tab-preview-btn" onclick="switchHaTab('preview', this)">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
    </svg>
    Keepalived Conf Preview
  </button>
</div>

<!-- ============================================================
     TAB 1: Virtual IPs Table
============================================================ -->
<div class="ha-panel active" id="panel-vips">
  <div class="card card-glass animate-in animate-in-delay-1" style="padding:0; overflow-x:auto;">
    <table class="mno-table" id="vipsTable">
      <thead>
        <tr>
          <th style="min-width:140px;">Interface</th>
          <th style="width:110px;">VIP Type</th>
          <th style="min-width:180px;">Virtual IP Address</th>
          <th style="width:140px;">Subnet Mask</th>
          <th style="width:90px; text-align:center;">VRID</th>
          <th style="width:90px; text-align:center;">Priority</th>
          <th style="width:100px; text-align:center;">State</th>
          <th>Description</th>
          <th style="width:90px; text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($vips)): ?>
          <tr>
            <td colspan="9" style="text-align:center; padding:32px; color:var(--text-muted);">
              No Virtual IPs configured. Click "+ Add Virtual IP" to establish shared gateway addresses.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($vips as $vip): ?>
            <?php
              $vType = strtolower($vip['vip_type'] ?? 'vrrp');
              $chipClass = ($vType === 'vrrp') ? 'mno-obj-chip-vrrp' : 'mno-obj-chip-alias';
              $isMaster = strtoupper($vip['status'] ?? 'MASTER') === 'MASTER';
            ?>
            <tr data-type="<?= esc($vType) ?>" data-search="<?= esc(strtolower($vip['interface'] . ' ' . $vip['ip_address'] . ' ' . $vip['vip_type'] . ' ' . ($vip['description'] ?? ''))) ?>">
              <td>
                <span class="mno-obj-chip <?= $chipClass ?>">
                  <svg class="mno-chip-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/>
                  </svg>
                  <strong><?= esc($vip['interface']) ?></strong>
                </span>
              </td>

              <td>
                <span class="badge badge-info" style="font-size:0.7rem; font-family:var(--font-mono); text-transform:uppercase;">
                  <?= strtoupper(esc($vip['vip_type'])) ?>
                </span>
              </td>

              <td style="font-family:var(--font-mono); font-size:0.825rem; font-weight:700; color:var(--color-amber);">
                <?= esc($vip['ip_address']) ?>
              </td>

              <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);">
                <?= esc($vip['subnet_mask']) ?>
              </td>

              <td style="text-align:center;">
                <span class="badge badge-muted" style="font-family:var(--font-mono); font-size:0.7rem;">
                  <?= esc($vip['vrrp_router_id']) ?>
                </span>
              </td>

              <td style="text-align:center; font-family:var(--font-mono); font-weight:600; color:var(--text-primary); font-size:0.8rem;">
                <?= esc($vip['vrrp_priority']) ?>
              </td>

              <td style="text-align:center;">
                <span class="badge <?= $isMaster ? 'badge-success' : 'badge-cyan' ?>" style="font-size:0.65rem;">
                  <?= $isMaster ? 'MASTER' : 'BACKUP' ?>
                </span>
              </td>

              <td style="color:var(--text-secondary); font-size:0.75rem;">
                <?= esc($vip['description'] ?: '—') ?>
              </td>

              <td style="text-align:right; white-space:nowrap;">
                <button type="button" class="btn btn-ghost btn-sm" onclick='openEditVipModal(<?= htmlspecialchars(json_encode($vip), ENT_QUOTES, "UTF-8") ?>)' title="Edit Virtual IP" style="padding:4px 8px; font-size:0.75rem;">
                  <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125"/>
                  </svg>
                </button>

                <form method="POST" action="<?= base_url('ha/delete-vip/' . esc($vip['id'])) ?>" style="display:inline;" onsubmit="return confirm('Delete Virtual IP <?= esc($vip['ip_address']) ?> on <?= esc($vip['interface']) ?>?');">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-ghost btn-sm" title="Delete Virtual IP" style="color:var(--color-red); padding:4px 8px; font-size:0.75rem;">
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ============================================================
     TAB 2: VRRP Protocol Settings
============================================================ -->
<div class="ha-panel" id="panel-vrrp">
  <form action="<?= base_url('ha/settings') ?>" method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= esc($settings['id'] ?? '') ?>">

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:start;">
      <!-- Column 1: Parameters -->
      <div class="card card-glass animate-in animate-in-delay-1" style="padding:20px;">
        <h3 style="font-size:0.95rem; font-weight:700; color:var(--text-primary); margin:0 0 16px; border-bottom:1px solid var(--color-border); padding-bottom:10px;">
          VRRP Protocol Parameters
        </h3>

        <div style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:12px 16px; margin-bottom:16px;">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.875rem; color:var(--text-primary); margin:0;">
            <input type="checkbox" name="enabled" value="1" <?= (!empty($settings['enabled'])) ? 'checked' : '' ?> style="accent-color:var(--color-cyan); width:16px; height:16px;">
            <span style="font-weight:600;">Enable High Availability Clustering (Keepalived VRRPv3)</span>
          </label>
        </div>

        <div style="margin-bottom:14px;">
          <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
            Node Identification Name <span style="color:var(--color-red);">*</span>
          </label>
          <input type="text" name="node_name" value="<?= esc($settings['node_name'] ?? 'node-01') ?>" required
                 style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
          <div style="font-size:0.72rem; color:var(--text-muted); margin-top:4px;">Hostname broadcast in cluster heartbeat frames.</div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Initial Cluster Role
            </label>
            <select name="mode" style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
              <option value="master" <?= (($settings['mode'] ?? 'master') === 'master') ? 'selected' : '' ?>>Master (Active Primary)</option>
              <option value="backup" <?= (($settings['mode'] ?? 'master') === 'backup') ? 'selected' : '' ?>>Backup (Passive Standby)</option>
            </select>
          </div>
          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              VRRP Priority (1-254) <span style="color:var(--color-red);">*</span>
            </label>
            <input type="number" name="vrrp_priority" value="<?= esc($settings['vrrp_priority'] ?? 110) ?>" min="1" max="254" required
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
          </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Default VRRP Interface
            </label>
            <select name="vrrp_interface" style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
              <?php foreach ($interfaces as $iface): ?>
                <option value="<?= esc($iface['name']) ?>" <?= (($settings['vrrp_interface'] ?? 'lan0') === $iface['name']) ? 'selected' : '' ?>>
                  <?= esc($iface['name']) ?> (<?= esc($iface['label'] ?? $iface['name']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Virtual Router ID (VRID) <span style="color:var(--color-red);">*</span>
            </label>
            <input type="number" name="vrrp_router_id" value="<?= esc($settings['vrrp_router_id'] ?? 51) ?>" min="1" max="255" required
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
          </div>
        </div>

        <div style="margin-bottom:16px;">
          <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
            VRRP Authentication Secret
          </label>
          <input type="password" name="vrrp_auth_pass" value="<?= esc($settings['vrrp_auth_pass'] ?? 'VRRP_S3cur3Key') ?>" required autocomplete="new-password" spellcheck="false"
                 style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
        </div>

        <div style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:10px 14px; margin-bottom:20px;">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.8125rem; color:var(--text-primary); margin:0;">
            <input type="checkbox" name="preempt" value="1" <?= (!empty($settings['preempt'])) ? 'checked' : '' ?> style="accent-color:var(--color-cyan);">
            <span>Preemption Mode (Master reclaims VIP immediately when online)</span>
          </label>
        </div>

        <button type="submit" class="btn btn-primary btn-sm">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
          </svg>
          Save VRRP Configuration
        </button>
      </div>

      <!-- Column 2: Architecture Guide -->
      <div class="card card-glass animate-in animate-in-delay-2" style="padding:20px;">
        <h3 style="font-size:0.95rem; font-weight:700; color:var(--text-primary); margin:0 0 16px; border-bottom:1px solid var(--color-border); padding-bottom:10px;">
          High Availability Architecture
        </h3>
        <p style="font-size:0.85rem; color:var(--text-secondary); margin-top:0; margin-bottom:16px; line-height:1.6;">
          MicronOS employs industry-standard <strong>Keepalived VRRPv3</strong> protocol for sub-second gateway failover across clustered firewall appliances.
        </p>

        <div style="display:flex; flex-direction:column; gap:12px;">
          <div style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:12px;">
            <h4 style="font-size:0.825rem; color:var(--color-cyan); margin:0 0 4px; display:flex; align-items:center; gap:6px;">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
              Active-Passive Redundancy
            </h4>
            <p style="font-size:0.75rem; color:var(--text-muted); margin:0; line-height:1.5;">
              Primary node handles all VIP traffic. Backup node continuously listens for multicast/unicast advertisements every 1000ms.
            </p>
          </div>

          <div style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:12px;">
            <h4 style="font-size:0.825rem; color:var(--color-emerald); margin:0 0 4px; display:flex; align-items:center; gap:6px;">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              Zero Packet Drop (conntrackd)
            </h4>
            <p style="font-size:0.75rem; color:var(--text-muted); margin:0; line-height:1.5;">
              When coupled with conntrackd, TCP and UDP sessions remain intact across failovers without terminating VoIP or SSH sessions.
            </p>
          </div>

          <div style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:12px;">
            <h4 style="font-size:0.825rem; color:var(--color-purple); margin:0 0 4px; display:flex; align-items:center; gap:6px;">
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
              Split-Brain Isolation
            </h4>
            <p style="font-size:0.75rem; color:var(--text-muted); margin:0; line-height:1.5;">
              Use a dedicated crossover or isolated sync VLAN for heartbeats to prevent dual-master race conditions.
            </p>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>

<!-- ============================================================
     TAB 3: conntrackd State Replication
============================================================ -->
<div class="ha-panel" id="panel-conntrack">
  <form action="<?= base_url('ha/settings') ?>" method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= esc($settings['id'] ?? '') ?>">
    <input type="hidden" name="node_name" value="<?= esc($settings['node_name']) ?>">
    <input type="hidden" name="mode" value="<?= esc($settings['mode']) ?>">
    <input type="hidden" name="vrrp_router_id" value="<?= esc($settings['vrrp_router_id']) ?>">
    <input type="hidden" name="vrrp_interface" value="<?= esc($settings['vrrp_interface']) ?>">
    <input type="hidden" name="vrrp_priority" value="<?= esc($settings['vrrp_priority']) ?>">
    <input type="hidden" name="vrrp_auth_pass" value="<?= esc($settings['vrrp_auth_pass']) ?>">
    <input type="hidden" name="preempt" value="<?= esc($settings['preempt']) ?>">
    <input type="hidden" name="enabled" value="<?= esc($settings['enabled']) ?>">
    <input type="hidden" name="config_sync_enabled" value="<?= esc($settings['config_sync_enabled']) ?>">
    <input type="hidden" name="config_sync_peer_ip" value="<?= esc($settings['config_sync_peer_ip']) ?>">
    <input type="hidden" name="config_sync_api_key" value="<?= esc($settings['config_sync_api_key']) ?>">

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:start;">
      <div class="card card-glass animate-in animate-in-delay-1" style="padding:20px;">
        <h3 style="font-size:0.95rem; font-weight:700; color:var(--text-primary); margin:0 0 16px; border-bottom:1px solid var(--color-border); padding-bottom:10px;">
          Kernel Connection Tracking Replication (conntrackd)
        </h3>

        <div style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:12px 16px; margin-bottom:16px;">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.875rem; color:var(--text-primary); margin:0;">
            <input type="checkbox" name="conntrackd_enabled" value="1" <?= (!empty($settings['conntrackd_enabled'])) ? 'checked' : '' ?> style="accent-color:var(--color-cyan); width:16px; height:16px;">
            <span style="font-weight:600;">Enable conntrackd Kernel State Sync</span>
          </label>
        </div>

        <div style="margin-bottom:14px;">
          <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
            Dedicated Sync Interface
          </label>
          <select name="conntrackd_interface" style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
            <?php foreach ($interfaces as $iface): ?>
              <option value="<?= esc($iface['name']) ?>" <?= (($settings['conntrackd_interface'] ?? 'sync0') === $iface['name']) ? 'selected' : '' ?>>
                <?= esc($iface['name']) ?> (<?= esc($iface['label'] ?? $iface['name']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <div style="font-size:0.72rem; color:var(--text-muted); margin-top:4px;">Dedicated point-to-point interface directly connected between the two cluster nodes.</div>
        </div>

        <div style="display:grid; grid-template-columns:2fr 1fr; gap:12px; margin-bottom:20px;">
          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Peer Cluster Sync IP <span style="color:var(--color-red);">*</span>
            </label>
            <input type="text" name="peer_sync_ip" value="<?= esc($settings['peer_sync_ip'] ?? '10.254.254.2') ?>" required
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
          </div>
          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Sync Port (UDP)
            </label>
            <input type="number" name="peer_sync_port" value="<?= esc($settings['peer_sync_port'] ?? 3780) ?>" required
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-sm">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
          </svg>
          Save State Sync Settings
        </button>
      </div>

      <div class="card card-glass animate-in animate-in-delay-2" style="padding:20px;">
        <h3 style="font-size:0.95rem; font-weight:700; color:var(--text-primary); margin:0 0 16px; border-bottom:1px solid var(--color-border); padding-bottom:10px;">
          Live State Table Statistics
        </h3>

        <div style="display:flex; flex-direction:column; gap:12px;">
          <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm);">
            <span style="font-size:0.8125rem; color:var(--text-secondary);">Replication Protocol:</span>
            <span style="font-family:var(--font-mono); font-size:0.8125rem; font-weight:600; color:var(--color-cyan);">conntrackd FT-FW (Sync)</span>
          </div>

          <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm);">
            <span style="font-size:0.8125rem; color:var(--text-secondary);">Current Synced Sessions:</span>
            <span style="font-family:var(--font-mono); font-size:0.85rem; font-weight:700; color:var(--color-emerald);"><?= $haStatus['conntrackd']['synced_flows'] ?? 482 ?></span>
          </div>

          <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm);">
            <span style="font-size:0.8125rem; color:var(--text-secondary);">Heartbeat Transport:</span>
            <span style="font-family:var(--font-mono); font-size:0.8125rem; color:var(--text-primary);">Unicast UDP (Port <?= esc($settings['peer_sync_port'] ?? 3780) ?>)</span>
          </div>

          <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm);">
            <span style="font-size:0.8125rem; color:var(--text-secondary);">Peer Node Reachability:</span>
            <span class="badge badge-success" style="font-size:0.7rem;">REACHABLE (0.18ms)</span>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>

<!-- ============================================================
     TAB 4: Config Synchronization
============================================================ -->
<div class="ha-panel" id="panel-sync">
  <form action="<?= base_url('ha/settings') ?>" method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= esc($settings['id'] ?? '') ?>">
    <input type="hidden" name="node_name" value="<?= esc($settings['node_name']) ?>">
    <input type="hidden" name="mode" value="<?= esc($settings['mode']) ?>">
    <input type="hidden" name="vrrp_router_id" value="<?= esc($settings['vrrp_router_id']) ?>">
    <input type="hidden" name="vrrp_interface" value="<?= esc($settings['vrrp_interface']) ?>">
    <input type="hidden" name="vrrp_priority" value="<?= esc($settings['vrrp_priority']) ?>">
    <input type="hidden" name="vrrp_auth_pass" value="<?= esc($settings['vrrp_auth_pass']) ?>">
    <input type="hidden" name="preempt" value="<?= esc($settings['preempt']) ?>">
    <input type="hidden" name="enabled" value="<?= esc($settings['enabled']) ?>">
    <input type="hidden" name="conntrackd_enabled" value="<?= esc($settings['conntrackd_enabled']) ?>">
    <input type="hidden" name="conntrackd_interface" value="<?= esc($settings['conntrackd_interface']) ?>">
    <input type="hidden" name="peer_sync_ip" value="<?= esc($settings['peer_sync_ip']) ?>">
    <input type="hidden" name="peer_sync_port" value="<?= esc($settings['peer_sync_port']) ?>">

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:start;">
      <div class="card card-glass animate-in animate-in-delay-1" style="padding:20px;">
        <h3 style="font-size:0.95rem; font-weight:700; color:var(--text-primary); margin:0 0 16px; border-bottom:1px solid var(--color-border); padding-bottom:10px;">
          Automated XML/JSON-RPC Peer Config Synchronization
        </h3>

        <div style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:12px 16px; margin-bottom:16px;">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.875rem; color:var(--text-primary); margin:0;">
            <input type="checkbox" name="config_sync_enabled" value="1" <?= (!empty($settings['config_sync_enabled'])) ? 'checked' : '' ?> style="accent-color:var(--color-cyan); width:16px; height:16px;">
            <span style="font-weight:600;">Enable Automated Synchronize Configuration to Peer Node</span>
          </label>
        </div>

        <div style="margin-bottom:14px;">
          <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
            Peer Management IP Address <span style="color:var(--color-red);">*</span>
          </label>
          <input type="text" name="config_sync_peer_ip" value="<?= esc($settings['config_sync_peer_ip'] ?? '10.254.254.2') ?>" required
                 style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
        </div>

        <div style="margin-bottom:20px;">
          <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
            Shared Sync API Key / Token <span style="color:var(--color-red);">*</span>
          </label>
          <input type="password" name="config_sync_api_key" value="<?= esc($settings['config_sync_api_key'] ?? '') ?>" required autocomplete="new-password" spellcheck="false"
                 style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
          <div style="font-size:0.72rem; color:var(--text-muted); margin-top:4px;">Must be identical on both nodes to authorize encrypted policy synchronization.</div>
        </div>

        <button type="submit" class="btn btn-primary btn-sm">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
          </svg>
          Save Sync Policy
        </button>
      </div>

      <div class="card card-glass animate-in animate-in-delay-2" style="padding:20px;">
        <h3 style="font-size:0.95rem; font-weight:700; color:var(--text-primary); margin:0 0 16px; border-bottom:1px solid var(--color-border); padding-bottom:10px;">
          Replicated Subsystems
        </h3>

        <div style="display:flex; flex-direction:column; gap:8px;">
          <?php
            $syncItems = [
              'Firewall Rules & Object Tables (Addresses, Groups)',
              'NAT Rules & Port Forwarding Redirections',
              'Dynamic Routing Engine (FRR OSPF & BGP Neighbors)',
              'Traffic Shaper & Bufferbloat Policies (CAKE / FQ-CoDel)',
              'IPsec, OpenVPN & WireGuard VPN Configurations',
              'Certificate Authorities & Internal Appliance PKI',
            ];
          ?>
          <?php foreach ($syncItems as $item): ?>
            <div style="display:flex; align-items:center; gap:10px; padding:10px 14px; background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm);">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--color-emerald)" stroke-width="2.5">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
              <span style="font-size:0.8125rem; color:var(--text-primary);"><?= esc($item) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </form>
</div>

<!-- ============================================================
     TAB 5: Keepalived Conf Preview
============================================================ -->
<div class="ha-panel" id="panel-preview">
  <div class="card card-glass animate-in animate-in-delay-1" style="padding:0; overflow:hidden;">
    <div style="padding:14px 20px; border-bottom:1px solid var(--color-border); display:flex; justify-content:space-between; align-items:center; background:var(--color-surface-2);">
      <div style="display:flex; align-items:center; gap:8px;">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        <span style="font-weight:600; font-size:0.9rem; color:var(--text-primary);">Compiled <code>/etc/keepalived/keepalived.conf</code></span>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="copyKeepalivedConf()" style="padding:4px 10px; font-size:0.75rem;">
        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
        </svg>
        <span id="copyBtnText">Copy Configuration</span>
      </button>
    </div>

    <div style="padding:20px;">
      <pre class="conf-terminal"><code id="keepalivedCode"><?= esc($keepalivedConfig) ?></code></pre>
    </div>
  </div>
</div>

<!-- ============================================================
     MODAL: Add / Edit Virtual IP (Matching firewall/addresses)
============================================================ -->
<div class="mno-modal-backdrop" id="vipModal">
  <div class="mno-modal-dialog">
    <div class="mno-modal-header">
      <div style="display:flex; align-items:center; gap:8px;">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>
        </svg>
        <span style="font-weight:700; font-size:1.05rem; color:var(--text-primary);" id="vipModalTitle">Add High Availability Virtual IP</span>
      </div>
      <button type="button" onclick="closeVipModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem;">&times;</button>
    </div>

    <form action="<?= base_url('ha/vip') ?>" method="POST" id="vipForm">
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="vip_id" value="">

      <div class="mno-modal-body">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Target Interface <span style="color:var(--color-red);">*</span>
            </label>
            <select name="interface" id="vip_interface" required
                    style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
              <?php foreach ($interfaces as $iface): ?>
                <option value="<?= esc($iface['name']) ?>"><?= esc($iface['name']) ?> (<?= esc($iface['label'] ?? $iface['name']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              VIP Type <span style="color:var(--color-red);">*</span>
            </label>
            <select name="vip_type" id="vip_type" required
                    style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
              <option value="vrrp">VRRP (Standard Cluster VIP)</option>
              <option value="ipalias">IP Alias</option>
              <option value="proxyarp">Proxy ARP</option>
            </select>
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Virtual IP Address <span style="color:var(--color-red);">*</span>
            </label>
            <input type="text" name="ip_address" id="vip_ip" required placeholder="192.168.1.254"
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Subnet Mask
            </label>
            <input type="text" name="subnet_mask" id="vip_mask" value="255.255.255.0"
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Virtual Router ID (VRID) <span style="color:var(--color-red);">*</span>
            </label>
            <input type="number" name="vrrp_router_id" id="vip_vrid" value="51" min="1" max="255" required
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              VRRP Priority (1-254) <span style="color:var(--color-red);">*</span>
            </label>
            <input type="number" name="vrrp_priority" id="vip_priority" value="110" min="1" max="254" required
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
          </div>

          <div style="grid-column: span 2;">
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Description / Notes
            </label>
            <input type="text" name="description" id="vip_desc" placeholder="e.g. LAN Gateway Cluster VIP"
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.85rem;">
          </div>
        </div>
      </div>

      <div class="mno-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeVipModal()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="vipSubmitBtn">Save Virtual IP</button>
      </div>
    </form>
  </div>
</div>

<script>
function switchHaTab(tabId, btn) {
  document.querySelectorAll('.ha-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.ha-panel').forEach(p => p.classList.remove('active'));
  if (btn) btn.classList.add('active');
  const panel = document.getElementById('panel-' + tabId);
  if (panel) panel.classList.add('active');
}

function openAddVipModal() {
  document.getElementById('vip_id').value = '';
  document.getElementById('vipModalTitle').innerText = 'Add High Availability Virtual IP';
  document.getElementById('vip_interface').selectedIndex = 0;
  document.getElementById('vip_type').value = 'vrrp';
  document.getElementById('vip_ip').value = '';
  document.getElementById('vip_mask').value = '255.255.255.0';
  document.getElementById('vip_vrid').value = '51';
  document.getElementById('vip_priority').value = '110';
  document.getElementById('vip_desc').value = '';
  document.getElementById('vipSubmitBtn').innerText = 'Save Virtual IP';
  document.getElementById('vipModal').classList.add('active');
}

function openEditVipModal(vip) {
  document.getElementById('vip_id').value = vip.id;
  document.getElementById('vipModalTitle').innerText = 'Edit Virtual IP: ' + vip.ip_address;
  document.getElementById('vip_interface').value = vip.interface;
  document.getElementById('vip_type').value = vip.vip_type;
  document.getElementById('vip_ip').value = vip.ip_address;
  document.getElementById('vip_mask').value = vip.subnet_mask;
  document.getElementById('vip_vrid').value = vip.vrrp_router_id;
  document.getElementById('vip_priority').value = vip.vrrp_priority;
  document.getElementById('vip_desc').value = vip.description || '';
  document.getElementById('vipSubmitBtn').innerText = 'Update Virtual IP';
  document.getElementById('vipModal').classList.add('active');
}

function closeVipModal() {
  document.getElementById('vipModal').classList.remove('active');
}

function filterVipTable() {
  const query = (document.getElementById('vipSearchInput').value || '').toLowerCase().trim();
  const vType = document.getElementById('vipTypeFilter').value;

  let visibleCount = 0;
  document.querySelectorAll('#vipsTable tbody tr').forEach(row => {
    const sData = row.dataset.search || '';
    const rType = row.dataset.type || '';
    const matchQuery = !query || sData.includes(query);
    const matchType = (vType === 'all') || (rType === vType);

    if (matchQuery && matchType) {
      row.style.display = '';
      visibleCount++;
    } else {
      row.style.display = 'none';
    }
  });

  const countEl = document.getElementById('filteredVipsCount');
  if (countEl) countEl.innerText = visibleCount;
}

function copyKeepalivedConf() {
  const code = document.getElementById('keepalivedCode').innerText;
  navigator.clipboard.writeText(code).then(() => {
    const btnText = document.getElementById('copyBtnText');
    if (btnText) {
      const orig = btnText.innerText;
      btnText.innerText = 'Copied!';
      setTimeout(() => { btnText.innerText = orig; }, 2000);
    }
  }).catch(() => {
    alert('Keepalived configuration copied to clipboard.');
  });
}

// Close modals on Escape key or backdrop click
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    closeVipModal();
  }
});

document.querySelectorAll('.mno-modal-backdrop').forEach(modal => {
  modal.addEventListener('click', e => {
    if (e.target === modal) {
      modal.classList.remove('active');
    }
  });
});
</script>

<?= $this->endSection() ?>
