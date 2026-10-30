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

.rt-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 7px;
  border-radius: var(--radius-sm);
  font-size: 0.75rem;
  font-family: var(--font-mono, monospace);
  font-weight: 500;
  background: var(--color-surface-3);
  color: var(--text-primary);
  border: 1px solid var(--color-border);
}
.rt-chip-cyan   { background: rgba(6,182,212,0.12); color: var(--color-cyan, #06b6d4); border-color: rgba(6,182,212,0.25); font-weight: 600; }
.rt-chip-blue   { background: rgba(59,130,246,0.12); color: var(--color-blue, #3b82f6); border-color: rgba(59,130,246,0.25); }
.rt-chip-purple { background: rgba(139,92,246,0.12); color: var(--color-purple, #8b5cf6); border-color: rgba(139,92,246,0.25); }
.rt-chip-emerald{ background: rgba(16,185,129,0.12); color: var(--color-emerald, #10b981); border-color: rgba(16,185,129,0.25); }

.rt-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.84rem;
}
.rt-table th {
  text-align: left;
  padding: 11px 18px;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--text-muted);
  background: var(--color-surface-2);
  border-bottom: 1px solid var(--color-border);
}
.rt-table td {
  padding: 12px 18px;
  border-bottom: 1px solid var(--color-border);
  vertical-align: middle;
}
.rt-table tbody tr:last-child td { border-bottom: none; }
.rt-table tbody tr:hover { background: rgba(255,255,255,0.018); }

/* Modal styles */
.rt-modal-backdrop {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.65);
  backdrop-filter: blur(4px);
  z-index: 1050;
  display: none;
  align-items: center; justify-content: center;
  padding: 20px;
}
.rt-modal-backdrop.open { display: flex; }
.rt-modal-dialog {
  background: var(--color-surface-1);
  border: 1px solid var(--color-border-bright);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-xl);
  width: 100%; max-width: 600px;
  display: flex; flex-direction: column;
  overflow: hidden;
  max-height: 90vh;
}
.rt-modal-header {
  padding: 16px 22px;
  border-bottom: 1px solid var(--color-border);
  display: flex; justify-content: space-between; align-items: center;
  background: var(--color-surface-2);
}
.rt-modal-body {
  padding: 22px;
  overflow-y: auto;
  display: flex; flex-direction: column; gap: 16px;
}
.rt-modal-footer {
  padding: 14px 22px;
  border-top: 1px solid var(--color-border);
  display: flex; justify-content: flex-end; gap: 10px;
  background: var(--color-surface-2);
}
.rt-field {
  display: flex; flex-direction: column; gap: 6px;
}
.rt-field label {
  font-size: 0.78rem; font-weight: 600;
  color: var(--text-secondary);
}
.rt-field input, .rt-field select, .rt-field textarea {
  background: var(--color-surface-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  color: var(--text-primary);
  padding: 8px 12px;
  font-size: 0.85rem;
  transition: border-color .15s, box-shadow .15s;
}
.rt-field input:focus, .rt-field select:focus, .rt-field textarea:focus {
  outline: none; border-color: var(--color-purple);
  box-shadow: 0 0 0 2px rgba(139,92,246,0.18);
}
.rt-field .hint {
  font-size: 0.72rem; color: var(--text-muted); line-height: 1.4; margin: 2px 0 0 0;
}
.rt-grid-2 {
  display: grid; grid-template-columns: 1fr 1fr; gap: 14px;
}
@media (max-width: 640px) {
  .rt-grid-2 { grid-template-columns: 1fr; }
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
      <span>BGP</span>
    </div>
    <div style="display:flex; align-items:center; gap:12px; margin-top:4px;">
      <div class="page-title-icon" style="background:rgba(139,92,246,0.12); color:var(--color-purple); border:1px solid rgba(139,92,246,0.25);">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
        </svg>
      </div>
      <div>
        <h1 class="page-title">BGP (Border Gateway Protocol)</h1>
        <p class="page-subtitle">Configure autonomous system routing, eBGP/iBGP peers, route redistribution, and prefix filtering</p>
      </div>
    </div>
  </div>
  <div class="page-header-actions" style="display:flex; gap:10px;">
    <a href="<?= base_url('routing/static') ?>" class="btn btn-secondary btn-sm">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
      Static Routes
    </a>
    <a href="<?= base_url('routing') ?>" class="btn btn-secondary btn-sm">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
      All Dynamic Protocols
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
    <strong>FRRouting bgpd</strong>
  </div>
  <div class="frr-badge">Version: <strong><?= esc($frrStatus['version'] ?? 'FRR 9.1') ?></strong></div>
  <div class="frr-badge">Uptime: <strong><?= esc($frrStatus['uptime'] ?? 'Active') ?></strong></div>
  <div class="frr-badge">Local AS: <strong class="rt-chip rt-chip-purple">AS<?= esc($bgpSettings['local_as'] ?? 65001) ?></strong></div>
  <div class="frr-badge">Router-ID: <strong class="rt-chip rt-chip-cyan"><?= esc(($bgpSettings['router_id'] ?? '') ?: 'auto') ?></strong></div>
  <?php if (!empty($isMock)): ?>
    <span class="badge badge-muted" style="margin-left:auto;">simulation mode</span>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Render BGP Panel ── -->
<?= $this->include('routing/bgp_panel') ?>

<script>
function openModal(id) {
  var el = document.getElementById(id);
  if (el) el.classList.add('open');
}
function closeModal(id) {
  var el = document.getElementById(id);
  if (el) el.classList.remove('open');
}
</script>

<?= $this->endSection() ?>
