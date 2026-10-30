<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<?= $this->include('firewall/_obj_tooltip') ?>

<!-- Flash Messages -->
<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:16px;"><?= esc(session()->getFlashdata('success')) ?></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-danger animate-in" style="margin-bottom:16px;"><?= esc(session()->getFlashdata('error')) ?></div>
<?php endif; ?>

<!-- Page Header -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div style="display:flex; align-items:center; gap:10px;">
      <h1 class="page-title">Firewall Addresses</h1>
      <span class="badge badge-info" style="font-size:0.75rem;"><?= count($addresses) ?> Objects</span>
    </div>
    <p class="page-subtitle">Configure subnets, IP ranges, and FQDN objects for granular policy matching</p>
  </div>
  <div class="page-header-actions">
    <button type="button" class="btn btn-primary btn-sm" onclick="openCreateAddressModal()">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
      </svg>
      Create Address
    </button>

    <form method="POST" action="<?= base_url('firewall/addresses/resolve') ?>" style="display:inline;">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-ghost btn-sm" title="Resolve FQDN / internet-service / geography objects into offline address feeds now">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Resolve Feeds
      </button>
    </form>

    <a href="<?= base_url('firewall/addresses/export-csv') ?>" class="btn btn-ghost btn-sm" title="Export Addresses to CSV">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
      </svg>
      Export CSV
    </a>

    <button type="button" class="btn btn-ghost btn-sm" onclick="openImportModal()" title="Import Addresses from CSV">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
      </svg>
      Import CSV
    </button>

    <a href="<?= base_url('firewall/rules') ?>" class="btn btn-ghost btn-sm">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
      </svg>
      Firewall Rules
    </a>
  </div>
</div>

<!-- Toolbar & Search -->
<div class="card" style="padding:12px 18px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
  <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
    <div style="position:relative;">
      <input type="text" id="addressSearchInput" placeholder="Search addresses (name, IP, domain)..."
             onkeyup="filterAddressTable()"
             style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 12px 6px 32px; border-radius:var(--radius-sm); font-size:0.8rem; width:260px;">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
           style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--text-muted);">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
      </svg>
    </div>

    <select id="typeFilter" onchange="filterAddressTable()" style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
      <option value="all">All Types</option>
      <option value="ipmask">Subnet / IP</option>
      <option value="iprange">IP Range</option>
      <option value="fqdn">FQDN</option>
      <option value="internet-service">Internet Service</option>
      <option value="geoip">Geography / Country</option>
    </select>
  </div>

  <div style="font-size:0.75rem; color:var(--text-muted);">
    Total: <strong style="color:var(--text-primary);" id="filteredCount"><?= count($addresses) ?></strong> addresses
  </div>
</div>

<!-- Addresses Table -->
<div class="card card-glass animate-in animate-in-delay-1" style="padding:0; overflow-x:auto;">
  <table class="mno-table" id="addressesTable">
    <thead>
      <tr>
        <th style="min-width:200px;">Object Name</th>
        <th style="width:130px;">Type</th>
        <th style="min-width:220px;">IP / Netmask / FQDN</th>
        <th style="width:130px;">Feed Status</th>
        <th style="width:110px;">Interface</th>
        <th>Comment</th>
        <th style="width:90px; text-align:right;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($addresses as $a): ?>
        <?php /* $freshness is supplied by Firewall::addresses() */ ?>
        <?php
          $isAll = $a['name'] === 'all';
          $isFqdn = $a['type'] === 'fqdn';
          $isRange = $a['type'] === 'iprange';
          $isGeo = $a['type'] === 'geoip';
          $isIsdb = $a['type'] === 'internet-service';
          $isWild = $isFqdn && \App\Libraries\Fqdn::isWildcard((string) $a['value']);
          $chipClass = $isAll ? 'mno-obj-chip-all'
            : ($isGeo ? 'mno-obj-chip-geo'
            : ($isIsdb ? 'mno-obj-chip-isdb'
            : ($isWild ? 'mno-obj-chip-wildcard'
            : ($isFqdn ? 'mno-obj-chip-fqdn'
            : ($isRange ? 'mno-obj-chip-range' : 'mno-obj-chip-addr')))));
        ?>
        <tr data-type="<?= esc($a['type']) ?>" data-search="<?= esc(strtolower($a['name'] . ' ' . $a['value'] . ' ' . ($a['comment'] ?? ''))) ?>">
          <td>
            <div style="display:flex; align-items:center; gap:6px;">
              <span class="mno-obj-chip <?= $chipClass ?>"<?= \App\Libraries\ObjectTip::address($a) ?>>
                <?php if ($isAll): ?>
                  <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                <?php elseif ($isGeo): ?>
                  <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                <?php elseif ($isFqdn): ?>
                  <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                <?php else: ?>
                  <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                <?php endif; ?>
                <span><?= esc($a['name']) ?></span>
              </span>
              <?php if (!empty($a['is_system'])): ?>
                <span class="badge badge-muted" style="font-size:0.6rem;">SYSTEM</span>
              <?php endif; ?>
              <?php if ($isWild): ?>
                <span class="badge badge-info" style="font-size:0.6rem;" title="Wildcard domain — resolved to a set of matching addresses">WILDCARD</span>
              <?php endif; ?>
            </div>
          </td>
          <td>
            <span class="badge badge-info" style="font-size:0.7rem; font-family:var(--font-mono); text-transform:uppercase;">
              <?= $isGeo ? 'GEOGRAPHY' : ($isIsdb ? 'INTERNET SERVICE' : esc($a['type'])) ?>
            </span>
          </td>
          <td style="font-family:var(--font-mono); font-size:0.8rem; color:var(--text-primary); font-weight:500;">
            <?php if ($isGeo): ?>
              <span style="font-family:var(--font-sans);"><?= esc(\App\Libraries\GeoIp::label((string) $a['value'])) ?></span>
            <?php elseif ($isIsdb): ?>
              <span style="font-family:var(--font-sans);"><?= esc(\App\Libraries\InternetService::describe((string) $a['value'])) ?></span>
            <?php else: ?>
              <?= esc($a['value']) ?>
            <?php endif; ?>
          </td>
          <td>
            <?php $fresh = $freshness[$a['id']] ?? null; ?>
            <?php if ($fresh === null || $fresh['state'] === 'literal'): ?>
              <span style="color:var(--text-muted); font-size:0.72rem;">—</span>
            <?php elseif ($fresh['state'] === 'fresh'): ?>
              <span class="badge badge-success" style="font-size:0.66rem;" title="<?= esc($fresh['label']) ?>">FRESH</span>
            <?php elseif ($fresh['state'] === 'stale'): ?>
              <span class="badge badge-warning" style="font-size:0.66rem;" title="<?= esc($fresh['label']) ?>">STALE</span>
            <?php else: ?>
              <span class="badge badge-muted" style="font-size:0.66rem;" title="<?= esc($fresh['label']) ?>">NOT RESOLVED</span>
            <?php endif; ?>
            <?php if ($fresh !== null && $fresh['label'] !== ''): ?>
              <div style="font-size:0.66rem; color:var(--text-muted); margin-top:2px;"><?= esc($fresh['label']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge badge-muted" style="font-size:0.7rem;"><?= esc(strtoupper($a['interface'] ?? 'any')) ?></span>
          </td>
          <td style="color:var(--text-secondary); font-size:0.75rem;">
            <?= esc($a['comment'] ?: '—') ?>
          </td>
          <td style="text-align:right; white-space:nowrap;">
            <button type="button" class="btn btn-ghost btn-sm" onclick='editAddress(<?= htmlspecialchars(json_encode($a), ENT_QUOTES, "UTF-8") ?>)' title="Edit Address" style="padding:4px 8px; font-size:0.75rem;">
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125"/>
              </svg>
            </button>
            <?php if (empty($a['is_system'])): ?>
              <form method="POST" action="<?= base_url('firewall/addresses/' . esc($a['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Delete address object <?= esc($a['name']) ?>?');">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-ghost btn-sm" title="Delete Address" style="color:var(--color-red); padding:4px 8px; font-size:0.75rem;">
                  <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                  </svg>
                </button>
              </form>
            <?php else: ?>
              <span style="font-size:0.7rem; color:var(--text-muted); padding:0 6px;">Protected</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ========================================================
     MODAL: Create / Edit Address
     ======================================================== -->
<div class="mno-modal-backdrop" id="addressModal">
  <div class="mno-modal-dialog">
    <div class="mno-modal-header">
      <div style="display:flex; align-items:center; gap:8px;">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <rect x="2" y="3" width="20" height="14" rx="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line>
        </svg>
        <span style="font-weight:700; font-size:1.05rem; color:var(--text-primary);" id="addressModalTitle">New Address Object</span>
      </div>
      <button type="button" onclick="closeAddressModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem;">&times;</button>
    </div>

    <form method="POST" id="addressForm" action="<?= base_url('firewall/addresses/store') ?>">
      <?= csrf_field() ?>
      <div class="mno-modal-body">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
          <div style="grid-column: span 2;">
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Address Name <span style="color:var(--color-red);">*</span>
            </label>
            <input type="text" name="name" id="addrName" required placeholder="e.g. ERP_Server or Local_Branch"
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Type <span style="color:var(--color-red);">*</span>
            </label>
            <select name="type" id="addrType" required onchange="onAddressTypeChange()"
                    style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
              <option value="ipmask">Subnet / IP (e.g. 192.168.1.0/24)</option>
              <option value="iprange">IP Range (e.g. 10.0.0.1-10.0.0.50)</option>
              <option value="fqdn">FQDN Domain (e.g. updates.cdn.com or *.example.com)</option>
              <option value="internet-service">Internet Service (e.g. Microsoft 365, Zoom)</option>
              <option value="geoip">Geography / Country (e.g. United States)</option>
            </select>
          </div>

          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Associated Interface
            </label>
            <select name="interface" id="addrInterface"
                    style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
              <option value="any">any (All Interfaces)</option>
              <?php if (!empty($physicalInterfaces)): ?>
                <optgroup label="Physical &amp; System Interfaces">
                  <?php foreach ($physicalInterfaces as $if): ?>
                    <option value="<?= esc($if['id']) ?>"><?= esc($if['name']) ?> (<?= esc($if['physical_device']) ?>)</option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
              <?php if (!empty($vpnInterfaces['wireguard'])): ?>
                <optgroup label="WireGuard VPN Tunnels">
                  <?php foreach ($vpnInterfaces['wireguard'] as $wg): ?>
                    <option value="<?= esc($wg['interface']) ?>"><?= esc($wg['name'] ?? $wg['interface']) ?> (<?= esc($wg['interface']) ?>) <?= !empty($wg['addresses']) ? '[' . esc($wg['addresses']) . ']' : '' ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
              <?php if (!empty($vpnInterfaces['openvpn'])): ?>
                <optgroup label="OpenVPN Tunnels">
                  <?php foreach ($vpnInterfaces['openvpn'] as $ovpn): ?>
                    <option value="<?= esc($ovpn['interface']) ?>"><?= esc($ovpn['name'] ?? $ovpn['interface']) ?> (<?= esc($ovpn['interface']) ?>)</option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
              <?php if (!empty($vpnInterfaces['ipsec'])): ?>
                <optgroup label="IPsec VTI Tunnels">
                  <?php foreach ($vpnInterfaces['ipsec'] as $ipsec): ?>
                    <option value="<?= esc($ipsec['interface']) ?>"><?= esc($ipsec['name'] ?? $ipsec['interface']) ?> (<?= esc($ipsec['interface']) ?>)</option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
            </select>
          </div>

          <div id="isdbWrap" style="grid-column: span 2; display:none;">
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Internet Services <span style="color:var(--color-red);">*</span>
            </label>
            <input type="search" class="mno-pick-search" id="addrServicesSearch"
                   placeholder="Search services…" autocomplete="off" spellcheck="false">
            <?php /* Server-rendered first page of the service catalogue; the search
                     box above pages the rest in (partials/pick_select.php). */ ?>
            <?= view('partials/pick_select', [
              'id'             => 'addrServices',
              'size'           => 8,
              'class'          => 'mno-multiselect',
              'pickerSentinel' => null,
              'source'         => 'internetServices',
              'search'         => base_url('firewall/objects/search'),
            ]) ?>
            <div class="mno-pick-empty" id="addrServicesEmpty" hidden>No matching services or categories</div>
            <small style="display:block; margin-top:6px; font-size:0.72rem; color:var(--text-muted);">
              Type to filter · ↑↓ highlight · Space toggle · Enter add · Hold Ctrl / Cmd to pick several services. Manage the catalogue under Firewall &rarr; Internet Services.
            </small>
          </div>

          <div id="geoipWrap" style="grid-column: span 2; display:none;">
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Countries <span style="color:var(--color-red);">*</span>
            </label>
            <input type="search" class="mno-pick-search" id="addrCountriesSearch"
                   placeholder="Search countries…" autocomplete="off" spellcheck="false">
            <?php /* 249 countries: the box holds one page of them and the search
                     box above pages the rest in (partials/pick_select.php). */ ?>
            <?= view('partials/pick_select', [
              'id'             => 'addrCountries',
              'size'           => 8,
              'class'          => 'mno-multiselect',
              'pickerSentinel' => null,
              'source'         => 'countries',
              'search'         => base_url('firewall/objects/search'),
            ]) ?>
            <div class="mno-pick-empty" id="addrCountriesEmpty" hidden>No matching countries</div>
            <small style="display:block; margin-top:6px; font-size:0.72rem; color:var(--text-muted);">
              Type to filter · ↑↓ highlight · Space toggle · Enter add · Hold Ctrl / Cmd to pick several countries. The rule matches traffic from (or to) any selected country.
            </small>
          </div>

          <div style="grid-column: span 2;">
            <label id="geoValueLabel" style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              IP / CIDR / Range / FQDN Value <span style="color:var(--color-red);">*</span>
            </label>
            <input type="text" name="value" id="addrValue" required placeholder="192.168.1.50/32 or 8.8.8.8-8.8.4.4 or *.example.com"
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem; font-family:var(--font-mono);">
            <small id="addrValueHint" style="display:none; margin-top:6px; font-size:0.72rem; color:var(--text-muted);">
              Wildcards are supported: <code>*.example.com</code> matches every sub-domain at any depth (<code>a.example.com</code> and <code>a.b.example.com</code>). The domain is resolved into a live address set at apply time.
            </small>
          </div>

          <div style="grid-column: span 2;">
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Comment / Notes
            </label>
            <input type="text" name="comment" id="addrComment" placeholder="Optional notes for this address object..."
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.85rem;">
          </div>
        </div>
      </div>

      <div class="mno-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeAddressModal()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Save Address Object</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================
     MODAL: CSV Import
     ======================================================== -->
<div class="mno-modal-backdrop" id="csvImportModal">
  <div class="mno-modal-dialog" style="max-width:540px;">
    <div class="mno-modal-header">
      <div style="display:flex; align-items:center; gap:8px;">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
        </svg>
        <span style="font-weight:700; font-size:1.05rem; color:var(--text-primary);">Import Addresses (CSV)</span>
      </div>
      <button type="button" onclick="closeImportModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem;">&times;</button>
    </div>

    <form method="POST" action="<?= base_url('firewall/addresses/import-csv') ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="mno-modal-body" style="padding:20px;">
        <p style="font-size:0.85rem; color:var(--text-secondary); margin-bottom:16px;">
          Upload a CSV file containing address objects. Supported columns: <code>name, type, value, interface, color, comment</code>.
        </p>

        <div style="margin-bottom:16px;">
          <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
            Choose CSV File <span style="color:var(--color-red);">*</span>
          </label>
          <input type="file" name="csv_file" accept=".csv,text/csv,text/plain" required
                 style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px; border-radius:var(--radius-sm); font-size:0.85rem;">
        </div>

        <div style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:12px; margin-bottom:16px;">
          <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
            Conflict Resolution
          </label>
          <div style="display:flex; gap:16px;">
            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-size:0.8125rem;">
              <input type="radio" name="mode" value="update" checked>
              <span>Update existing (by name)</span>
            </label>
            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-size:0.8125rem;">
              <input type="radio" name="mode" value="skip">
              <span>Skip existing</span>
            </label>
          </div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.75rem;">
          <a href="<?= base_url('firewall/addresses/export-csv') ?>" style="color:var(--color-cyan); text-decoration:none;">
            Download sample CSV template &darr;
          </a>
        </div>
      </div>

      <div class="mno-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeImportModal()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Upload &amp; Import</button>
      </div>
    </form>
  </div>
</div>

<?= $this->include('partials/picker_search') ?>

<script>
function filterAddressTable() {
  const query = document.getElementById('addressSearchInput').value.toLowerCase();
  const typeFilter = document.getElementById('typeFilter').value;
  const rows = document.querySelectorAll('#addressesTable tbody tr');
  let visibleCount = 0;

  rows.forEach(row => {
    const text = row.getAttribute('data-search') || '';
    const type = row.getAttribute('data-type') || '';
    const matchesSearch = text.includes(query);
    const matchesType = (typeFilter === 'all') || (type === typeFilter);

    if (matchesSearch && matchesType) {
      row.style.display = '';
      visibleCount++;
    } else {
      row.style.display = 'none';
    }
  });

  document.getElementById('filteredCount').innerText = visibleCount;
}

// ── Geography (country) helper ────────────────────────
function onAddressTypeChange() {
  const type    = document.getElementById('addrType').value;
  const wrap    = document.getElementById('geoipWrap');
  const isdbWrap = document.getElementById('isdbWrap');
  const value   = document.getElementById('addrValue');
  const label   = document.getElementById('geoValueLabel');
  const hint    = document.getElementById('addrValueHint');
  const geo     = (type === 'geoip');
  const isdb    = (type === 'internet-service');
  const fqdn    = (type === 'fqdn');

  wrap.style.display = geo ? 'block' : 'none';
  isdbWrap.style.display = isdb ? 'block' : 'none';
  value.readOnly = geo || isdb;
  value.style.opacity = (geo || isdb) ? '0.75' : '1';
  hint.style.display = fqdn ? 'block' : 'none';
  label.innerHTML = (geo
    ? 'ISO Country Codes (auto-filled from the selection above) <span style="color:var(--color-red);">*</span>'
    : (isdb
      ? 'Service Keys (auto-filled from the selection above) <span style="color:var(--color-red);">*</span>'
      : 'IP / CIDR / Range / FQDN Value <span style="color:var(--color-red);">*</span>'));
  value.placeholder = geo
    ? 'Auto-filled from the country selection, e.g. US, CA'
    : (isdb
      ? 'Auto-filled from the service selection, e.g. microsoft_365, zoom'
      : '192.168.1.50/32 or 8.8.8.8-8.8.4.4 or *.example.com');

  if (!geo) {
    document.getElementById('addrCountries').selectedIndex = -1;
  }
  if (!isdb) {
    document.getElementById('addrServices').selectedIndex = -1;
  }
}

// The value field mirrors whichever picker is in play. Both are readOnly unless
// the type matches, and a picker's change event can arrive after the type has
// moved on (the selection a dialog restores is resolved by the server, so it
// lands a moment later), so each sync only writes for its own type — otherwise
// a restored country selection would blank an IP address that was just loaded.
function syncGeoValue() {
  if (document.getElementById('addrType').value !== 'geoip') return;
  const select = document.getElementById('addrCountries');
  const codes = Array.from(select.selectedOptions).map(o => o.value);
  document.getElementById('addrValue').value = codes.join(', ');
}

function syncServiceValue() {
  if (document.getElementById('addrType').value !== 'internet-service') return;
  const select = document.getElementById('addrServices');
  const keys = Array.from(select.selectedOptions).map(o => o.value);
  document.getElementById('addrValue').value = keys.join(', ');
}

// Hand a picker the codes / keys an address object stores. Both catalogues are
// paged from the server, so a stored country or service is not guaranteed to
// have a row on screen: the picker registers the selection as pending, asks the
// endpoint to resolve it, and re-applies it — which is what keeps it in the list
// (and so in the value that gets submitted).
function setGeoSelection(value) {
  const wanted = (value || '').split(/[\s,;]+/).map(s => s.trim().toUpperCase()).filter(Boolean);
  MicronPickerSearch.setSelection('addrCountries', wanted);
}

function setServiceSelection(value) {
  const wanted = (value || '').split(/[\s,;]+/).map(s => s.trim().toLowerCase()).filter(Boolean);
  MicronPickerSearch.setSelection('addrServices', wanted);
}

function openCreateAddressModal() {
  document.getElementById('addressModalTitle').innerText = 'New Address Object';
  document.getElementById('addressForm').action = '<?= base_url('firewall/addresses/store') ?>';
  document.getElementById('addrName').value = '';
  document.getElementById('addrType').value = 'ipmask';
  document.getElementById('addrInterface').value = 'any';
  document.getElementById('addrValue').value = '';
  document.getElementById('addrComment').value = '';
  setGeoSelection('');
  setServiceSelection('');
  onAddressTypeChange();
  // A query left over from the last object must not hide the options here.
  MicronPickerSearch.reset(['addrServices', 'addrCountries']);
  document.getElementById('addressModal').classList.add('active');
}

function editAddress(data) {
  document.getElementById('addressModalTitle').innerText = 'Edit Address Object: ' + data.name;
  document.getElementById('addressForm').action = '<?= base_url('firewall/addresses') ?>/' + encodeURIComponent(data.id) + '/update';
  document.getElementById('addrName').value = data.name || '';
  const ifaceSelect = document.getElementById('addrInterface');
  const targetIface = data.interface || 'any';
  let ifaceFound = false;
  for (let i = 0; i < ifaceSelect.options.length; i++) {
    if (ifaceSelect.options[i].value.toLowerCase() === String(targetIface).toLowerCase()) {
      ifaceSelect.selectedIndex = i;
      ifaceFound = true;
      break;
    }
  }
  if (!ifaceFound && targetIface !== 'any') {
    ifaceSelect.add(new Option(targetIface + ' (Virtual/Tunnel)', targetIface, true, true));
    ifaceSelect.value = targetIface;
  } else if (!ifaceFound) {
    ifaceSelect.value = 'any';
  }
  document.getElementById('addrValue').value = data.value || '';
  document.getElementById('addrComment').value = data.comment || '';
  setGeoSelection(data.type === 'geoip' ? data.value : '');
  setServiceSelection(data.type === 'internet-service' ? data.value : '');
  onAddressTypeChange();
  MicronPickerSearch.reset(['addrServices', 'addrCountries']);
  document.getElementById('addressModal').classList.add('active');
}

function closeAddressModal() {
  document.getElementById('addressModal').classList.remove('active');
}

function openImportModal() {
  document.getElementById('csvImportModal').classList.add('active');
}

function closeImportModal() {
  document.getElementById('csvImportModal').classList.remove('active');
}

// ── Filterable object pickers ─────────────────────────
// The service and country catalogues are paged from the server rather than
// rendered into the page (partials/pick_select.php renders the first page), so
// the search box above each box asks the endpoint for the rest and the dialog
// carries a bounded list however long the catalogue is.
MicronPickerSearch.init([
  { select: 'addrServices',  search: 'addrServicesSearch',  empty: 'addrServicesEmpty',
    source: <?= json_encode(\App\Libraries\ObjectSearch::pickerSource('internetServices', base_url('firewall/objects/search')), JSON_UNESCAPED_SLASHES) ?> },
  { select: 'addrCountries', search: 'addrCountriesSearch', empty: 'addrCountriesEmpty',
    source: <?= json_encode(\App\Libraries\ObjectSearch::pickerSource('countries', base_url('firewall/objects/search')), JSON_UNESCAPED_SLASHES) ?> },
]);

// The list boxes have no inline onchange: the shared picker fires `change` once
// a selection has settled (typed, toggled, or resolved back from the endpoint),
// and that is when the hidden value field is brought up to date.
document.getElementById('addrCountries').addEventListener('change', syncGeoValue);
document.getElementById('addrServices').addEventListener('change', syncServiceValue);
</script>

<?= $this->endSection() ?>
