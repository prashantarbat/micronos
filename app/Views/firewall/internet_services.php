<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<?= $this->include('firewall/_obj_tooltip') ?>

<style>
.mno-obj-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 0.74rem;
  font-weight: 500;
  font-family: var(--font-mono);
  background: rgba(255,255,255,0.06);
  border: 1px solid rgba(255,255,255,0.12);
  color: var(--text-primary);
  white-space: nowrap;
  line-height: 1.4;
}
.mno-chip-isdb { background: rgba(14,165,233,0.13); border-color: rgba(14,165,233,0.32); color: #7dd3fc; }
.mno-chip-threat { background: rgba(239,68,68,0.12); border-color: rgba(239,68,68,0.30); color: #fca5a5; }
.mno-table { width:100%; border-collapse:separate; border-spacing:0; font-size:0.8125rem; }
.mno-table th {
  background: var(--color-surface-3); color: var(--text-secondary); font-weight:600;
  font-size:0.72rem; text-transform:uppercase; letter-spacing:0.05em;
  padding:10px 14px; border-bottom:1px solid var(--color-border); text-align:left;
}
.mno-table td { padding:9px 14px; border-bottom:1px solid var(--color-border); vertical-align:middle; }
.mno-table tbody tr:hover { background: rgba(255,255,255,0.02); }
.mno-modal-backdrop {
  position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px);
  z-index:1000; display:none; align-items:center; justify-content:center; padding:20px;
}
.mno-modal-backdrop.active { display:flex; }
.mno-modal-dialog {
  background:var(--color-surface-1); border:1px solid var(--color-border-bright);
  border-radius:var(--radius-lg); width:100%; max-width:640px; max-height:90vh;
  display:flex; flex-direction:column; box-shadow:0 25px 50px -12px rgba(0,0,0,0.7); overflow:hidden;
}
.mno-modal-header {
  padding:16px 24px; border-bottom:1px solid var(--color-border);
  display:flex; justify-content:space-between; align-items:center; background:var(--color-surface-2);
}
.mno-modal-body   { padding:24px; overflow-y:auto; flex:1; }
.mno-modal-footer {
  padding:14px 24px; border-top:1px solid var(--color-border); background:var(--color-surface-2);
  display:flex; justify-content:flex-end; gap:10px;
}
</style>

<div class="page-header animate-in">
  <div>
    <div style="display:flex; align-items:center; gap:10px;">
      <h1 class="page-title">Internet Service Database</h1>
      <span class="badge badge-info" style="font-size:0.75rem;"><?= count($services) ?> Services</span>
    </div>
    <p class="page-subtitle">Named catalogues of published IP prefixes for well-known SaaS, cloud and threat platforms — refreshed automatically from each platform's public feed, then referenced from a policy instead of hand-maintaining CIDRs</p>
  </div>
  <div style="display:flex; gap:8px; flex-wrap:wrap;">
    <button type="button" class="btn btn-primary btn-sm" onclick="openIsdbModal()" title="Create an address object from selected services">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline;vertical-align:middle;margin-right:4px;">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
      </svg>
      Create Address Object
    </button>
    <form method="POST" action="<?= base_url('firewall/internet-services/sync') ?>" style="display:inline;">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-ghost btn-sm" title="Download each service's public prefix feed now">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline;vertical-align:middle;margin-right:4px;">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Synchronize
      </button>
    </form>
    <form method="POST" action="<?= base_url('firewall/internet-services/sync-geo') ?>" style="display:inline;">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-ghost btn-sm" title="Refresh geography feeds for countries referenced by address objects">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline;vertical-align:middle;margin-right:4px;">
          <circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/>
          <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
        </svg>
        Sync Geography
      </button>
    </form>
  </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:16px;">
    <?= esc(session()->getFlashdata('success')) ?>
  </div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-danger animate-in" style="margin-bottom:16px;">
    <?= esc(session()->getFlashdata('error')) ?>
  </div>
<?php endif; ?>

<div style="display:flex; gap:12px; align-items:center; margin-bottom:14px; flex-wrap:wrap;">
  <div style="position:relative;">
    <input type="text" id="isdbSearchInput" placeholder="Search services (name, category)..."
           onkeyup="filterIsdbTable()"
           style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 12px 6px 32px; border-radius:var(--radius-sm); font-size:0.8rem; width:260px;">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
         style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--text-muted);">
      <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
    </svg>
  </div>
  <select id="isdbCategoryFilter" onchange="filterIsdbTable()" style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
    <option value="all">All Categories</option>
    <?php foreach ($categories as $cat): ?>
      <option value="<?= esc($cat) ?>"><?= esc($cat) ?></option>
    <?php endforeach; ?>
  </select>
  <div style="font-size:0.75rem; color:var(--text-muted);">
    Showing: <strong style="color:var(--text-primary);" id="isdbFilteredCount"><?= count($services) ?></strong> services
  </div>
</div>

<div class="card card-glass animate-in animate-in-delay-1" style="padding:0; overflow-x:auto;">
  <table class="mno-table" id="isdbTable">
    <thead>
      <tr>
        <th style="min-width:200px;">Service</th>
        <th style="width:150px;">Category</th>
        <th style="width:120px;">Prefixes</th>
        <th style="width:110px;">nftables Set</th>
        <th style="width:140px;">Last Synced</th>
        <th style="width:110px;">Status</th>
        <th style="width:130px; text-align:right;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($services as $svc): ?>
        <?php
          $isThreat = in_array($svc['category'], ['Threat'], true);
          $search   = strtolower($svc['name'] . ' ' . $svc['id'] . ' ' . $svc['category'] . ' ' . ($svc['description'] ?? ''));
        ?>
        <tr data-category="<?= esc($svc['category']) ?>" data-search="<?= esc($search) ?>">
          <td>
            <span class="mno-obj-chip <?= $isThreat ? 'mno-chip-threat' : 'mno-chip-isdb' ?>"<?= \App\Libraries\ObjectTip::internetService($svc) ?>>
              <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/>
                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
              </svg>
              <?= esc($svc['name']) ?>
            </span>
            <div style="font-size:0.7rem; color:var(--text-muted); margin-top:3px;"><?= esc($svc['description'] ?? '') ?></div>
          </td>
          <td><span class="badge badge-muted" style="font-size:0.7rem;"><?= esc($svc['category']) ?></span></td>
          <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--text-secondary);">
            <?= $svc['prefix_count'] > 0 ? (int) $svc['prefix_count'] . ' CIDRs' : '—' ?>
          </td>
          <td style="font-family:var(--font-mono); font-size:0.72rem; color:var(--color-cyan);">@<?= esc(\App\Libraries\InternetService::setName($svc['id'])) ?></td>
          <td style="font-size:0.74rem;">
            <?php if (!empty($svc['last_synced_at'])): ?>
              <span style="color:var(--text-secondary);"><?= esc(date('Y-m-d H:i', strtotime((string) $svc['last_synced_at']))) ?></span>
            <?php elseif (!empty($svc['has_feed'])): ?>
              <span style="color:var(--color-amber,#f59e0b);">Never synced</span>
            <?php else: ?>
              <span style="color:var(--text-muted);">Manual feed</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!empty($svc['enabled'])): ?>
              <span class="badge badge-success" style="font-size:0.68rem;">ENABLED</span>
            <?php else: ?>
              <span class="badge badge-muted" style="font-size:0.68rem;">DISABLED</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right; white-space:nowrap;">
            <button type="button" class="btn btn-ghost btn-sm" style="padding:4px 8px; font-size:0.72rem;"
                    onclick="selectService('<?= esc($svc['id'], 'raw') ?>')" title="Add this service to a new address object">Select</button>
            <form method="POST" action="<?= base_url('firewall/internet-services/' . esc($svc['id']) . '/toggle') ?>" style="display:inline;">
              <?= csrf_field() ?>
              <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 8px; font-size:0.72rem;"
                      title="<?= !empty($svc['enabled']) ? 'Disable' : 'Enable' ?> this service">
                <?= !empty($svc['enabled']) ? 'Disable' : 'Enable' ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ========================================================
     Geography feeds (geoip address objects)
     ======================================================== -->
<div class="card card-glass animate-in animate-in-delay-2" style="margin-top:16px; padding:18px 20px;">
  <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
    <div>
      <div style="font-weight:700; font-size:0.95rem; color:var(--text-primary);">Geography Feeds</div>
      <p style="margin:4px 0 0; font-size:0.78rem; color:var(--text-muted);">
        Country address objects resolve to the networks a national internet registry publishes. These feeds refresh on the same nightly schedule.
      </p>
    </div>
    <form method="POST" action="<?= base_url('firewall/internet-services/sync-geo') ?>" style="display:inline;">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-ghost btn-sm">Refresh Geography Feeds</button>
    </form>
  </div>
  <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
    <?php if (empty($geoCountries)): ?>
      <span style="font-size:0.78rem; color:var(--text-muted);">No geography address objects yet — create one from the Addresses page (type <em>Geography</em>).</span>
    <?php else: ?>
      <?php foreach ($geoCountries as $code => $name): ?>
        <span class="mno-obj-chip mno-chip-isdb" title="@geo_<?= esc(strtolower($code)) ?>">
          <?= esc($name) ?> <span style="opacity:0.7;">(<?= esc($code) ?>)</span>
        </span>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <?php if (empty($hasCurl)): ?>
    <div style="margin-top:12px; font-size:0.74rem; color:var(--color-amber,#f59e0b);">
      The PHP cURL extension is not loaded — downloads will fall back to the stream wrapper.
    </div>
  <?php endif; ?>
</div>

<!-- ========================================================
     MODAL: Create an address object from services
     ======================================================== -->
<div class="mno-modal-backdrop" id="isdbModal">
  <div class="mno-modal-dialog">
    <div class="mno-modal-header">
      <div style="display:flex; align-items:center; gap:8px;">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/>
          <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
        </svg>
        <span style="font-weight:700; font-size:1.05rem; color:var(--text-primary);">New Internet Service Address Object</span>
      </div>
      <button type="button" onclick="closeIsdbModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem;">&times;</button>
    </div>

    <form method="POST" action="<?= base_url('firewall/internet-services/create-address') ?>">
      <?= csrf_field() ?>
      <div class="mno-modal-body">
        <div style="margin-bottom:14px;">
          <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
            Address Name <span style="color:var(--color-red);">*</span>
          </label>
          <input type="text" name="name" id="isdbAddrName" required placeholder="e.g. SaaS_Allowlist"
                 style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
        </div>

        <div style="margin-bottom:14px;">
          <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
            Services <span style="color:var(--color-red);">*</span>
          </label>
          <input type="search" class="mno-pick-search" id="isdbServicesSearch"
                 placeholder="Search services…" autocomplete="off" spellcheck="false">
          <?php /* Server-rendered first page of the service catalogue; the search
                   box above pages the rest in (partials/pick_select.php). */ ?>
          <?= view('partials/pick_select', [
            'id'             => 'isdbServices',
            'size'           => 12,
            'class'          => 'mno-multiselect',
            'pickerSentinel' => null,
            'source'         => 'internetServices',
            'search'         => base_url('firewall/objects/search'),
          ]) ?>
          <div class="mno-pick-empty" id="isdbServicesEmpty" hidden>No matching services or categories</div>
          <small style="display:block; margin-top:6px; font-size:0.72rem; color:var(--text-muted);">
            Type to filter · ↑↓ highlight · Space toggle · Enter add · Hold Ctrl / Cmd to pick several services. The policy then matches traffic to any of them.
          </small>
        </div>

        <div>
          <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
            Comment / Notes
          </label>
          <input type="text" name="comment" id="isdbComment" placeholder="Optional notes for this address object..."
                 style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.85rem;">
        </div>

        <input type="hidden" name="services" id="isdbServicesValue">
      </div>

      <div class="mno-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeIsdbModal()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Save Address Object</button>
      </div>
    </form>
  </div>
</div>

<?= $this->include('partials/picker_search') ?>

<script>
function filterIsdbTable() {
  const query = document.getElementById('isdbSearchInput').value.toLowerCase();
  const category = document.getElementById('isdbCategoryFilter').value;
  const rows = document.querySelectorAll('#isdbTable tbody tr');
  let visible = 0;

  rows.forEach(row => {
    const text = row.getAttribute('data-search') || '';
    const cat  = row.getAttribute('data-category') || '';
    const ok = text.includes(query) && (category === 'all' || cat === category);
    row.style.display = ok ? '' : 'none';
    if (ok) visible++;
  });

  document.getElementById('isdbFilteredCount').innerText = visible;
}

function syncIsdbValue() {
  const codes = Array.from(document.getElementById('isdbServices').selectedOptions).map(o => o.value);
  document.getElementById('isdbServicesValue').value = codes.join(', ');
  if (!document.getElementById('isdbAddrName').value && codes.length) {
    document.getElementById('isdbAddrName').value = codes[0].toUpperCase();
  }
}

function selectService(key) {
  openIsdbModal();
  // The catalogue is paged from the server, so the key a row asks for may not be
  // among the rows on screen: the picker resolves it on the endpoint and
  // re-applies it, and its change event then syncs the hidden value (and
  // suggests a name).
  MicronPickerSearch.setSelection('isdbServices', [key]);
}

function openIsdbModal() {
  // A query left over from the last address object must not hide the options.
  MicronPickerSearch.reset(['isdbServices']);
  document.getElementById('isdbModal').classList.add('active');
}

function closeIsdbModal() {
  document.getElementById('isdbModal').classList.remove('active');
}

// ── Filterable service picker ─────────────────────────
// The catalogue is paged from the server rather than rendered into the page, so
// the box holds one page of it and the search box above asks the endpoint for
// the rest.
MicronPickerSearch.init([
  { select: 'isdbServices', search: 'isdbServicesSearch', empty: 'isdbServicesEmpty',
    source: <?= json_encode(\App\Libraries\ObjectSearch::pickerSource('internetServices', base_url('firewall/objects/search')), JSON_UNESCAPED_SLASHES) ?> },
]);

// No inline onchange: the shared picker fires `change` once a selection has
// settled, and that is when the hidden value field is brought up to date.
document.getElementById('isdbServices').addEventListener('change', syncIsdbValue);
</script>

<?= $this->endSection() ?>