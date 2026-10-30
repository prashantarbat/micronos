<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<?= $this->include('firewall/_obj_tooltip') ?>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div style="display:flex; align-items:center; gap:10px;">
      <h1 class="page-title">Firewall Services &amp; Ports</h1>
      <span class="badge badge-info" style="font-size:0.75rem;"><?= count($services) ?> Services</span>
    </div>
    <p class="page-subtitle">Define TCP, UDP, ICMP, and IP service objects for use in firewall policy rules</p>
  </div>
  <div class="page-header-actions">
    <button type="button" class="btn btn-primary btn-sm" onclick="openCreateModal()">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
      </svg>
      Create Service
    </button>

    <a href="<?= base_url('firewall/services/export-csv') ?>" class="btn btn-ghost btn-sm" title="Export to CSV">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
      </svg>
      Export CSV
    </a>

    <button type="button" class="btn btn-ghost btn-sm" onclick="openImportModal()" title="Import from CSV">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
      </svg>
      Import CSV
    </button>

    <a href="<?= base_url('firewall/service-groups') ?>" class="btn btn-ghost btn-sm">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
      </svg>
      Service Groups
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
  <div class="alert alert-danger animate-in" style="margin-bottom:16px;">
    <?= esc(session()->getFlashdata('error')) ?>
  </div>
<?php endif; ?>

<!-- ── Toolbar / Search ── -->
<div class="card" style="padding:12px 18px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
  <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
    <div style="position:relative;">
      <input type="text" id="svcSearchInput" placeholder="Search services..."
             oninput="filterTable()"
             style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 12px 6px 32px; border-radius:var(--radius-sm); font-size:0.8rem; width:250px;">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
           style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--text-muted);">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
      </svg>
    </div>

    <select id="protocolFilter" onchange="filterTable()"
            style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
      <option value="">All Protocols</option>
      <option value="TCP">TCP</option>
      <option value="UDP">UDP</option>
      <option value="TCP/UDP">TCP/UDP</option>
      <option value="ICMP">ICMP</option>
      <option value="IP">IP</option>
    </select>
  </div>

  <div style="font-size:0.75rem; color:var(--text-muted);">
    Showing: <strong style="color:var(--text-primary);" id="filteredCount"><?= count($services) ?></strong> services
  </div>
</div>

<!-- ── Services Table ── -->
<?php
$grouped = [];
foreach ($services as $svc) {
    $cat = $svc['category'] ?: 'General';
    $grouped[$cat][] = $svc;
}
ksort($grouped);
?>

<div class="card card-glass animate-in animate-in-delay-1" style="padding:0; overflow-x:auto;">
  <table class="mno-table" id="servicesTable">
    <thead>
      <tr>
        <th style="min-width:180px;">Name</th>
        <th style="width:110px;">Protocol</th>
        <th style="width:120px;">TCP Port(s)</th>
        <th style="width:120px;">UDP Port(s)</th>
        <th style="min-width:140px;">Category</th>
        <th>Comment</th>
        <th style="width:90px; text-align:right;">Actions</th>
      </tr>
    </thead>
    <tbody id="svcTableBody">
      <?php foreach ($grouped as $category => $rows): ?>
        <tr data-cat-row="1" style="background:var(--color-surface-2);">
          <td colspan="7" style="color:var(--text-secondary); font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; padding:6px 14px;">
            <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline;vertical-align:middle;margin-right:4px;">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
            </svg>
            <?= esc($category) ?>
            <span style="font-weight:400; margin-left:4px; opacity:.6;">(<?= count($rows) ?>)</span>
          </td>
        </tr>
        <?php foreach ($rows as $svc): ?>
          <?php
            $proto = strtoupper($svc['protocol'] ?? 'TCP');
            $protoClass = 'mno-chip-ip';
            if ($proto === 'TCP')     $protoClass = 'mno-chip-tcp';
            elseif ($proto === 'UDP') $protoClass = 'mno-chip-udp';
            elseif ($proto === 'TCP/UDP') $protoClass = 'mno-chip-svc';
            elseif ($proto === 'ICMP')    $protoClass = 'mno-chip-icmp';
            $searchData = strtolower(
              $svc['name'] . ' ' . $proto . ' ' .
              ($svc['tcp_port'] ?? '') . ' ' . ($svc['udp_port'] ?? '') . ' ' .
              $category . ' ' . ($svc['comment'] ?? '')
            );
          ?>
          <tr data-search="<?= esc($searchData) ?>" data-protocol="<?= esc($proto) ?>">
            <td>
              <span class="mno-obj-chip mno-chip-svc"<?= \App\Libraries\ObjectTip::service($svc) ?>>
                <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7"/>
                </svg>
                <?= esc($svc['name']) ?>
              </span>
              <?php if (!empty($svc['is_system'])): ?>
                <span class="badge badge-muted" style="font-size:0.65rem; margin-left:4px;" title="Built-in system service">system</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="mno-obj-chip <?= $protoClass ?>"><?= esc($proto) ?></span>
            </td>
            <td style="font-family:var(--font-mono); color:var(--text-secondary); font-size:0.78rem;">
              <?= esc($svc['tcp_port'] ?: '—') ?>
            </td>
            <td style="font-family:var(--font-mono); color:var(--text-secondary); font-size:0.78rem;">
              <?= esc($svc['udp_port'] ?: '—') ?>
            </td>
            <td style="color:var(--text-secondary); font-size:0.78rem;"><?= esc($category) ?></td>
            <td style="color:var(--text-secondary); font-size:0.75rem; max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= esc($svc['comment'] ?? '') ?>">
              <?= esc($svc['comment'] ?: '—') ?>
            </td>
            <td style="text-align:right; white-space:nowrap;">
              <?php if (empty($svc['is_system'])): ?>
                <button type="button" class="btn btn-ghost btn-sm"
                        onclick='editService(<?= htmlspecialchars(json_encode($svc), ENT_QUOTES, 'UTF-8') ?>)'
                        title="Edit" style="padding:4px 8px; font-size:0.75rem;">
                  <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/>
                  </svg>
                </button>
                <form method="POST" action="<?= base_url('firewall/services/' . esc($svc['id']) . '/delete') ?>"
                      style="display:inline;"
                      onsubmit="return confirm('Delete service <?= esc($svc['name']) ?>?');">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-ghost btn-sm" title="Delete"
                          style="color:var(--color-red); padding:4px 8px; font-size:0.75rem;">
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                  </button>
                </form>
              <?php else: ?>
                <span style="font-size:0.72rem; color:var(--text-muted); padding:0 8px;">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ========================================================
     MODAL: Create / Edit Service
     ======================================================== -->
<div class="mno-modal-backdrop" id="svcModal">
  <div class="mno-modal-dialog">
    <div class="mno-modal-header">
      <div style="display:flex; align-items:center; gap:8px;">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7"/>
        </svg>
        <span style="font-weight:700; font-size:1.05rem; color:var(--text-primary);" id="svcModalTitle">New Service</span>
      </div>
      <button type="button" onclick="closeModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem;">&times;</button>
    </div>

    <form method="POST" id="svcForm" action="<?= base_url('firewall/services/store') ?>">
      <?= csrf_field() ?>
      <div class="mno-modal-body">

        <!-- Row 1: Name + Protocol -->
        <div class="mno-field-group">
          <div class="mno-field">
            <label>Name <span style="color:var(--color-red);">*</span></label>
            <input type="text" name="name" id="svcName" required placeholder="e.g. MY_APP_8443"
                   style="text-transform:uppercase;">
          </div>
          <div class="mno-field">
            <label>Protocol <span style="color:var(--color-red);">*</span></label>
            <select name="protocol" id="svcProtocol" onchange="togglePortFields()">
              <option value="TCP">TCP</option>
              <option value="UDP">UDP</option>
              <option value="TCP/UDP">TCP/UDP</option>
              <option value="ICMP">ICMP</option>
              <option value="IP">IP (Any)</option>
            </select>
          </div>
        </div>

        <!-- Row 2: TCP Port + UDP Port -->
        <div class="mno-field-group" id="portFields">
          <div class="mno-field" id="tcpPortWrap">
            <label>TCP Port(s)</label>
            <input type="text" name="tcp_port" id="svcTcpPort" placeholder="e.g. 80 or 8080-8090"
                   style="font-family:var(--font-mono);">
            <p style="font-size:0.7rem; color:var(--text-muted); margin:4px 0 0;">Single port or range (e.g. 1024-2048)</p>
          </div>
          <div class="mno-field" id="udpPortWrap">
            <label>UDP Port(s)</label>
            <input type="text" name="udp_port" id="svcUdpPort" placeholder="e.g. 53 or 67-68"
                   style="font-family:var(--font-mono);">
            <p style="font-size:0.7rem; color:var(--text-muted); margin:4px 0 0;">Single port or range</p>
          </div>
        </div>

        <!-- Row 3: Category + Comment -->
        <div class="mno-field-group">
          <div class="mno-field">
            <label>Category</label>
            <input type="text" name="category" id="svcCategory" placeholder="e.g. Web Access"
                   list="categoryList">
            <datalist id="categoryList">
              <?php
                $cats = array_unique(array_column($services, 'category'));
                sort($cats);
                foreach ($cats as $c): if ($c): ?>
                  <option value="<?= esc($c) ?>">
              <?php endif; endforeach; ?>
            </datalist>
          </div>
          <div class="mno-field">
            <label>Comment</label>
            <input type="text" name="comment" id="svcComment" placeholder="Optional description...">
          </div>
        </div>

      </div><!-- /.mno-modal-body -->

      <div class="mno-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="svcSubmitBtn">Create Service</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================
     MODAL: CSV Import
     ======================================================== -->
<div class="mno-modal-backdrop" id="csvImportModal">
  <div class="mno-modal-dialog" style="max-width:520px;">
    <div class="mno-modal-header">
      <div style="display:flex; align-items:center; gap:8px;">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
        </svg>
        <span style="font-weight:700; font-size:1.05rem; color:var(--text-primary);">Import Services (CSV)</span>
      </div>
      <button type="button" onclick="closeImportModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem;">&times;</button>
    </div>

    <form method="POST" action="<?= base_url('firewall/services/import-csv') ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="mno-modal-body" style="padding:20px;">
        <p style="font-size:0.85rem; color:var(--text-secondary); margin-bottom:16px;">
          Upload a CSV with columns: <code>name, protocol, tcp_port, udp_port, category, comment</code>
        </p>

        <div style="margin-bottom:16px;" class="mno-field">
          <label>Choose CSV File <span style="color:var(--color-red);">*</span></label>
          <input type="file" name="csv_file" accept=".csv,text/csv,text/plain" required>
        </div>

        <div style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:12px; margin-bottom:16px;">
          <label style="display:block; font-size:0.73rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:8px;">Conflict Resolution</label>
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

        <div style="font-size:0.75rem;">
          <a href="<?= base_url('firewall/services/export-csv') ?>" style="color:var(--color-cyan); text-decoration:none;">
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

<script>
// ── Filter ──
function filterTable() {
  var query    = document.getElementById('svcSearchInput').value.toLowerCase();
  var protocol = document.getElementById('protocolFilter').value.toUpperCase();
  var rows     = document.querySelectorAll('#svcTableBody tr:not([data-cat-row])');
  var visible  = 0;

  rows.forEach(function(row) {
    var text  = row.getAttribute('data-search') || '';
    var proto = row.getAttribute('data-protocol') || '';
    var matchQ = !query    || text.includes(query);
    var matchP = !protocol || proto === protocol;
    if (matchQ && matchP) { row.style.display = ''; visible++; }
    else                  { row.style.display = 'none'; }
  });

  // Hide category header rows when all their data rows are hidden
  var catRows = document.querySelectorAll('#svcTableBody tr[data-cat-row]');
  catRows.forEach(function(catRow) {
    var sibling = catRow.nextElementSibling;
    var hasVisible = false;
    while (sibling && !sibling.hasAttribute('data-cat-row')) {
      if (sibling.style.display !== 'none') { hasVisible = true; break; }
      sibling = sibling.nextElementSibling;
    }
    catRow.style.display = hasVisible ? '' : 'none';
  });

  document.getElementById('filteredCount').innerText = visible;
}

// ── Protocol field toggling ──
function togglePortFields() {
  var proto      = document.getElementById('svcProtocol').value;
  var tcpWrap    = document.getElementById('tcpPortWrap');
  var udpWrap    = document.getElementById('udpPortWrap');
  var portFields = document.getElementById('portFields');

  if (proto === 'IP' || proto === 'ICMP') {
    portFields.style.display = 'none';
  } else {
    portFields.style.display = '';
    tcpWrap.style.display = (proto === 'UDP') ? 'none' : '';
    udpWrap.style.display = (proto === 'TCP') ? 'none' : '';
  }
}

// ── Open Create Modal ──
function openCreateModal() {
  document.getElementById('svcModalTitle').innerText = 'New Service';
  document.getElementById('svcForm').action = '<?= base_url('firewall/services/store') ?>';
  document.getElementById('svcSubmitBtn').innerText = 'Create Service';
  document.getElementById('svcName').value     = '';
  document.getElementById('svcProtocol').value = 'TCP';
  document.getElementById('svcTcpPort').value  = '';
  document.getElementById('svcUdpPort').value  = '';
  document.getElementById('svcCategory').value = '';
  document.getElementById('svcComment').value  = '';
  togglePortFields();
  document.getElementById('svcModal').classList.add('active');
}

// ── Open Edit Modal ──
function editService(data) {
  document.getElementById('svcModalTitle').innerText = 'Edit Service: ' + data.name;
  document.getElementById('svcForm').action =
    '<?= base_url('firewall/services') ?>/' + encodeURIComponent(data.id) + '/update';
  document.getElementById('svcSubmitBtn').innerText = 'Save Changes';

  document.getElementById('svcName').value     = data.name     || '';
  document.getElementById('svcProtocol').value = data.protocol || 'TCP';
  document.getElementById('svcTcpPort').value  = data.tcp_port || '';
  document.getElementById('svcUdpPort').value  = data.udp_port || '';
  document.getElementById('svcCategory').value = data.category || '';
  document.getElementById('svcComment').value  = data.comment  || '';
  togglePortFields();
  document.getElementById('svcModal').classList.add('active');
}

// ── Close Modals ──
function closeModal()       { document.getElementById('svcModal').classList.remove('active'); }
function openImportModal()  { document.getElementById('csvImportModal').classList.add('active'); }
function closeImportModal() { document.getElementById('csvImportModal').classList.remove('active'); }

</script>

<?= $this->endSection() ?>
