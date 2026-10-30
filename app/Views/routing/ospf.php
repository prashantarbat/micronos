<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
/* Page-specific styles */
.frr-banner {
  display: flex;
  align-items: center;
  gap: 14px;
  background: linear-gradient(135deg, rgba(6,182,212,0.08), rgba(139,92,246,0.08));
  border: 1px solid rgba(6,182,212,0.25);
  border-radius: var(--radius-lg);
  padding: 12px 20px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}
.frr-badge {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.78rem;
  color: var(--text-secondary);
}
.frr-badge strong { color: var(--text-primary); }
.frr-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  background: var(--color-emerald);
  box-shadow: 0 0 6px var(--color-emerald);
}

/* OSPF sub-tab bar */
.ospf-sub-tabs {
  display: flex;
  gap: 0;
  border-bottom: 2px solid var(--color-border);
  margin-bottom: 22px;
  overflow-x: auto;
}
.ospf-sub-tab {
  display: flex; align-items: center; gap: 5px;
  padding: 7px 15px;
  font-size: 0.78rem; font-weight: 600;
  color: var(--text-muted);
  cursor: pointer;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  transition: color .15s, border-color .15s;
  white-space: nowrap;
  background: none; border-top: none; border-left: none; border-right: none;
}
.ospf-sub-tab:hover { color: var(--text-primary); }
.ospf-sub-tab.active { color: var(--color-amber); border-bottom-color: var(--color-amber); }
.ospf-status-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; }
.ospf-status-section { min-width:0; margin:0; overflow:hidden; }
.ospf-status-title { padding:10px 14px; border-bottom:1px solid var(--color-border); color:var(--color-amber); font-size:.78rem; font-weight:700; }


.frr-form { border:1px solid var(--color-border); border-radius:var(--radius-md); overflow:hidden; margin-bottom:20px; }
.frr-section-hdr {
  background: rgba(245,158,11,0.12);
  border-bottom: 1px solid var(--color-border);
  padding: 8px 18px;
  font-size: 0.72rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.08em;
  color: var(--color-amber);
}
.frr-row {
  display: grid;
  grid-template-columns: 200px 1fr;
  border-bottom: 1px solid var(--color-border);
  min-height: 46px;
}
.frr-row:last-child { border-bottom: none; }
.frr-lbl {
  padding: 11px 18px;
  font-size: 0.82rem; font-weight: 600;
  color: var(--text-secondary);
  border-right: 1px solid var(--color-border);
  display: flex; align-items: flex-start; flex-direction: column;
  justify-content: center; gap: 2px;
}
.frr-lbl .sub { font-size: 0.68rem; font-weight: 400; color: var(--text-muted); }
.frr-val {
  padding: 9px 18px;
  display: flex; flex-direction: column; gap: 4px;
}
.frr-val .hint { font-size: 0.71rem; color: var(--text-muted); line-height: 1.4; }
.frr-input {
  background: var(--color-surface-3);
  border: 1px solid var(--color-border);
  color: var(--text-primary);
  padding: 6px 9px;
  border-radius: var(--radius-sm);
  font-size: 0.85rem;
  max-width: 400px;
  width: 100%;
}
.frr-input:focus { outline: none; border-color: var(--color-amber); }
.frr-input-sm  { max-width: 140px; }
.frr-input-md  { max-width: 260px; }
.frr-input-mono { font-family: var(--font-mono); }
.frr-select { max-width: 320px; }
.frr-chk {
  display: flex; align-items: center; gap: 8px;
  font-size: 0.83rem; color: var(--text-secondary); margin-top: 2px;
}
.frr-chk input { accent-color: var(--color-amber); width: 16px; height: 16px; }
.frr-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; max-width: 560px; }
.frr-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; max-width: 560px; }
.frr-grid-4 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 8px; max-width: 600px; }

.sr-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.8rem; margin-top: 6px; }
.sr-table th { background: var(--color-surface-3); padding: 6px 10px; text-align: left; font-size: 0.7rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; border-bottom: 1px solid var(--color-border); }
.sr-table td { padding: 5px 8px; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
.sr-table td input { background: var(--color-surface-2); border: 1px solid var(--color-border); color: var(--text-primary); padding: 4px 7px; border-radius: 4px; font-size: 0.8rem; width: 100%; }
.sr-table td input[type=checkbox] { width: 16px; height: 16px; accent-color: var(--color-amber); }
.sr-add-btn { font-size: 0.75rem; margin-top: 6px; }

.ospf-sub-panel { display: none; }
.ospf-sub-panel.active { display: block; }

@media (max-width: 640px) {
  .frr-row { grid-template-columns: 1fr; }
  .frr-lbl { border-right: none; border-bottom: 1px solid var(--color-border); padding: 8px 14px; }
}
</style>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <span>Network</span>
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <span>Routing Engine</span>
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <span>OSPF</span>
    </div>
    <div style="display:flex; align-items:center; gap:12px; margin-top:4px;">
      <div class="page-title-icon" style="background:var(--tone-amber-bg); color:var(--tone-amber-fg); border:1px solid var(--tone-amber-border);">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
        </svg>
      </div>
      <div>
        <h1 class="page-title">OSPF (Open Shortest Path First)</h1>
        <p class="page-subtitle">Configure OSPF areas, interfaces, network statements, neighbors, and route redistribution policies</p>
      </div>
    </div>
  </div>
  <div class="page-header-actions">
    <a href="<?= base_url('routing') ?>" class="btn btn-secondary btn-sm">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
      Back to Routing
    </a>
  </div>
</div>

<!-- ── Flash Messages ── -->
<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:18px;"><?= esc(session()->getFlashdata('success')) ?></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-danger animate-in" style="margin-bottom:18px;"><?= esc(session()->getFlashdata('error')) ?></div>
<?php endif; ?>

<!-- ── FRR Status Banner ── -->
<?php if (!empty($frrStatus['running'])): ?>
<div class="frr-banner animate-in">
  <div class="frr-badge">
    <span class="frr-dot"></span>
    <strong>FRRouting</strong>
  </div>
  <div class="frr-badge">Version: <strong><?= esc($frrStatus['version'] ?? '') ?></strong></div>
  <div class="frr-badge">Uptime: <strong><?= esc($frrStatus['uptime'] ?? '') ?></strong></div>
  <div class="frr-badge">Local AS: <strong class="rt-chip rt-chip-blue"><?= esc($frrStatus['bgp_as'] ?? '') ?></strong></div>
  <div class="frr-badge">Router-ID: <strong class="rt-chip rt-chip-cyan"><?= esc($frrStatus['router_id'] ?? '') ?></strong></div>
  <?php if (!empty($isMock)): ?>
    <span class="badge badge-muted" style="margin-left:auto;">simulation mode</span>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Include OSPF Panel Content ── -->
<?php
// Make sure required variables are available
$os  = $ospfSettings  ?? [];
$can = session()->get('is_superadmin') || in_array('routing.static.manage', session()->get('permissions') ?? []);

$activeSub = $_GET['sub'] ?? 'general';
$subTabs    = ['general', 'areas', 'interfaces', 'networks', 'neighbors', 'policy', 'status'];

// Include the original OSPF panel content
echo $this->include('routing/ospf_panel');
?>

<script>
// ── OSPF sub-tab switching ──
function switchOspfSub(name, btn) {
  document.querySelectorAll('.ospf-sub-panel').forEach(function(p) { p.classList.remove('active'); });
  document.querySelectorAll('.ospf-sub-tab').forEach(function(t) { t.classList.remove('active'); });
  var panel = document.getElementById('ospf-sub-' + name);
  if (panel) panel.classList.add('active');
  if (btn) btn.classList.add('active');
  var url = new URL(window.location);
  url.searchParams.set('tab', 'ospf');
  url.searchParams.set('sub', name);
  history.replaceState(null, '', url);
}

// ── Auto-activate OSPF sub-tab from ?sub= param ──
(function() {
  var sub = new URLSearchParams(window.location.search).get('sub');
  if (sub) {
    var btn = document.querySelector('.ospf-sub-tab[onclick*="' + sub + '"]');
    var p = document.getElementById('ospf-sub-' + sub);
    if (btn && p) {
      document.querySelectorAll('.ospf-sub-panel').forEach(function(el) { el.classList.remove('active'); });
      document.querySelectorAll('.ospf-sub-tab').forEach(function(el) { el.classList.remove('active'); });
      p.classList.add('active');
      btn.classList.add('active');
    }
  }
})();
</script>

<?= $this->endSection() ?>
