<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<?= $this->include('firewall/_obj_tooltip') ?>

<?php
// Permission flags for the row actions / right-click menu
$__perms    = session()->get('permissions') ?? [];
$__super    = (bool) session()->get('is_superadmin');
$canReorder = $__super || in_array('firewall.rules.reorder', $__perms, true);

// Interface detail tooltip lookup (id / name / device all resolve to the row)
$__ifaceMap = [];
foreach (($interfaces ?? []) as $__i) {
    foreach ([$__i['id'] ?? '', $__i['name'] ?? '', $__i['physical_device'] ?? ''] as $__k) {
        if ($__k !== '') { $__ifaceMap[strtolower((string) $__k)] = $__i; }
    }
}
$__ifaceTip = static function (string $n) use ($__ifaceMap): string {
    return isset($__ifaceMap[strtolower($n)]) ? \App\Libraries\ObjectTip::iface($__ifaceMap[strtolower($n)]) : '';
};
$canEdit    = $__super || in_array('firewall.rules.edit', $__perms, true);
$canDelete  = $__super || in_array('firewall.rules.delete', $__perms, true);

// Full policy rows for the context menu (indexed by id, JSON-safe)
$policyData = [];
foreach ($rules as $__rule) {
    $policyData[$__rule['id']] = $__rule;
}
?>

<style>
/* ============================================================
   Firewall Policies & Objects Design Styling
   ============================================================ */
.mno-kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}
.mno-kpi-card {
  background: var(--color-surface-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 16px 20px;
  display: flex;
  align-items: center;
  gap: 16px;
  transition: all var(--transition-normal);
}
.mno-kpi-card:hover {
  border-color: var(--color-border-bright);
  transform: translateY(-2px);
  box-shadow: var(--shadow-md);
}
.mno-kpi-icon {
  width: 44px;
  height: 44px;
  border-radius: var(--radius-sm);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.mno-toolbar {
  background: var(--color-surface-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 12px 18px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 14px;
  margin-bottom: 20px;
}
.mno-obj-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 0.75rem;
  font-weight: 500;
  font-family: var(--font-mono);
  background: rgba(255,255,255,0.06);
  border: 1px solid rgba(255,255,255,0.12);
  color: var(--text-primary);
  margin: 1px 0;
  white-space: nowrap;
  line-height: 1.2;
}
.mno-chip-icon {
  width: 13px;
  height: 13px;
  flex-shrink: 0;
  display: inline-block;
  vertical-align: middle;
}
.mno-schedule-pill {
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: 99px;
  font-size: 0.7rem;
  font-weight: 600;
  font-family: var(--font-mono);
  background: rgba(255, 255, 255, 0.05);
  color: var(--text-muted);
  border: 1px solid rgba(255, 255, 255, 0.08);
  letter-spacing: 0.04em;
  text-transform: uppercase;
  line-height: 1.2;
  white-space: nowrap;
}
.mno-obj-chip-addr {
  background: rgba(59, 130, 246, 0.15);
  border-color: rgba(59, 130, 246, 0.35);
  color: #93c5fd;
}
/* ── Policy right-click context menu ── */
.policy-table tbody tr.policy-menu-target {
  background: rgba(0, 212, 255, 0.07) !important;
  box-shadow: inset 3px 0 0 0 var(--color-cyan);
}
#policyContextMenu {
  position: fixed;
  z-index: 2100;
  display: none;
  min-width: 232px;
  padding: 6px;
  background: var(--color-surface-1);
  border: 1px solid var(--color-border-bright);
  border-radius: 10px;
  box-shadow: 0 18px 40px -12px rgba(0, 0, 0, 0.7);
  animation: policyMenuIn 0.12s ease-out;
}
@keyframes policyMenuIn {
  from { opacity: 0; transform: translateY(-4px) scale(0.98); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}
.policy-menu-title {
  padding: 7px 10px 8px;
  font-size: 0.72rem;
  font-weight: 700;
  color: var(--text-primary);
  border-bottom: 1px solid var(--color-border);
  margin-bottom: 5px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 260px;
}
.policy-menu-title small { display: block; font-weight: 400; color: var(--text-muted); font-size: 0.66rem; }
.policy-menu-item {
  display: flex;
  align-items: center;
  gap: 9px;
  width: 100%;
  padding: 7px 10px;
  border: none;
  background: none;
  border-radius: 6px;
  color: var(--text-secondary);
  font-size: 0.8rem;
  font-family: var(--font-sans);
  text-align: left;
  cursor: pointer;
  transition: background .12s, color .12s;
}
.policy-menu-item:hover { background: var(--color-surface-3); color: var(--text-primary); }
.policy-menu-item svg { flex-shrink: 0; }
.policy-menu-item.danger { color: var(--color-red); }
.policy-menu-item.danger:hover { background: rgba(239, 68, 68, 0.12); }
.policy-menu-sep { height: 1px; background: var(--color-border); margin: 5px 4px; }
.policy-menu-hint {
  padding: 3px 10px 4px;
  font-size: 0.62rem;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--text-muted);
}

/* ── Policy drag & drop reordering ── */
.policy-drag-handle {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 18px;
  margin-right: 6px;
  border-radius: 3px;
  color: var(--text-muted);
  cursor: grab;
  vertical-align: middle;
  transition: color .15s, background .15s;
}
.policy-drag-handle:hover { color: var(--color-cyan); background: rgba(0, 212, 255, 0.10); }
.policy-drag-handle:active { cursor: grabbing; }
.policy-table tbody tr.policy-dragging { opacity: 0.4; }
.policy-table tbody tr.policy-drag-over-top td { box-shadow: inset 0 2px 0 0 var(--color-cyan); }
.policy-table tbody tr.policy-drag-over-bottom td { box-shadow: inset 0 -2px 0 0 var(--color-cyan); }
.policy-table tbody tr.policy-row-moved { animation: policyRowFlash 0.7s ease; }
@keyframes policyRowFlash {
  from { background: rgba(0, 212, 255, 0.16); }
  to   { background: transparent; }
}
#reorderStatus {
  position: fixed;
  right: 22px;
  bottom: 22px;
  z-index: 2000;
  display: none;
  padding: 8px 14px;
  border-radius: 8px;
  font-size: 0.8rem;
  background: var(--color-surface-2);
  border: 1px solid var(--color-border-bright);
  color: var(--text-primary);
  box-shadow: var(--shadow-lg);
}
.mno-obj-chip-geo {
  background: rgba(168, 85, 247, 0.15);
  border-color: rgba(168, 85, 247, 0.35);
  color: #d8b4fe;
}
.mno-obj-chip-fqdn {
  background: rgba(6, 182, 212, 0.15);
  border-color: rgba(6, 182, 212, 0.35);
  color: #67e8f9;
}
.mno-obj-chip-grp {
  background: rgba(139, 92, 246, 0.15);
  border-color: rgba(139, 92, 246, 0.35);
  color: #c4b5fd;
}
.mno-obj-chip-svc {
  background: rgba(245, 158, 11, 0.15);
  border-color: rgba(245, 158, 11, 0.35);
  color: #fcd34d;
}
.mno-obj-chip-all {
  background: rgba(148, 163, 184, 0.15);
  border-color: rgba(148, 163, 184, 0.3);
  color: #cbd5e1;
}
.mno-schedule-pill.custom-schedule {
  background: rgba(6, 182, 212, 0.15);
  color: #06b6d4;
  border-color: rgba(6, 182, 212, 0.35);
  font-weight: 600;
}
.mno-action-accept {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 0.72rem;
  font-weight: 700;
  background: rgba(16, 185, 129, 0.2);
  color: #34d399;
  border: 1px solid rgba(16, 185, 129, 0.4);
  line-height: 1.2;
  white-space: nowrap;
}
.mno-action-deny {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 0.72rem;
  font-weight: 700;
  background: rgba(239, 68, 68, 0.2);
  color: #f87171;
  border: 1px solid rgba(239, 68, 68, 0.4);
  line-height: 1.2;
  white-space: nowrap;
}
.mno-nat-on {
  display: inline-flex;
  align-items: center;
  padding: 2px 7px;
  border-radius: 4px;
  font-size: 0.7rem;
  font-weight: 600;
  background: rgba(16, 185, 129, 0.15);
  color: #6ee7b7;
  border: 1px solid rgba(16, 185, 129, 0.3);
  line-height: 1.2;
  white-space: nowrap;
}
.mno-nat-off {
  display: inline-flex;
  align-items: center;
  padding: 2px 7px;
  border-radius: 4px;
  font-size: 0.7rem;
  font-weight: 600;
  background: rgba(148, 163, 184, 0.1);
  color: var(--text-muted);
  border: 1px solid rgba(255,255,255,0.06);
  line-height: 1.2;
  white-space: nowrap;
}
.mno-sec-badge {
  display: inline-flex;
  align-items: center;
  padding: 2px 6px;
  font-size: 0.65rem;
  font-weight: 700;
  border-radius: 3px;
  background: rgba(0, 212, 255, 0.15);
  color: var(--color-cyan);
  border: 1px solid rgba(0, 212, 255, 0.3);
  margin-right: 3px;
  line-height: 1.2;
  white-space: nowrap;
}
/* ── System row styling (Stealth & Implicit Cleanup) ── */
.system-stealth-row {
  background: rgba(99, 102, 241, 0.04) !important;
  border-bottom: 1px solid rgba(99, 102, 241, 0.22);
}
.system-stealth-row:hover {
  background: rgba(99, 102, 241, 0.08) !important;
}
.system-cleanup-row {
  background: rgba(239, 68, 68, 0.03) !important;
  border-top: 1px dashed rgba(239, 68, 68, 0.35) !important;
}
.system-cleanup-row:hover {
  background: rgba(239, 68, 68, 0.07) !important;
}
.mno-cleanup-badge {
  display: inline-flex;
  align-items: center;
  padding: 2px 7px;
  border-radius: 4px;
  font-size: 0.65rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  white-space: nowrap;
  background: rgba(239, 68, 68, 0.12);
  color: #f87171;
  border: 1px solid rgba(239, 68, 68, 0.3);
  line-height: 1.2;
}
.mno-stealth-badge {
  display: inline-flex;
  align-items: center;
  padding: 2px 7px;
  border-radius: 4px;
  font-size: 0.65rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  white-space: nowrap;
  background: rgba(99, 102, 241, 0.15);
  color: var(--color-purple, #818cf8);
  border: 1px solid rgba(99, 102, 241, 0.35);
  line-height: 1.2;
}
.mno-system-status-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  white-space: nowrap;
  background: rgba(99, 102, 241, 0.15);
  color: var(--color-purple, #818cf8);
  border: 1px solid rgba(99, 102, 241, 0.3);
  line-height: 1.2;
}
.mno-system-lock-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 7px;
  border-radius: 4px;
  font-size: 0.68rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  white-space: nowrap;
  background: rgba(255, 255, 255, 0.06);
  color: var(--text-muted);
  border: 1px solid rgba(255, 255, 255, 0.12);
  line-height: 1.2;
}
/* ── Light Mode Color Adjustments ── */
[data-mode="light"] .mno-obj-chip {
  background: rgba(0, 0, 0, 0.04);
  border-color: rgba(0, 0, 0, 0.12);
}
[data-mode="light"] .mno-obj-chip-addr {
  background: rgba(59, 130, 246, 0.12);
  border-color: rgba(59, 130, 246, 0.28);
  color: #1d4ed8;
}
[data-mode="light"] .mno-obj-chip-fqdn {
  background: rgba(6, 182, 212, 0.12);
  border-color: rgba(6, 182, 212, 0.28);
  color: #0e7490;
}
[data-mode="light"] .mno-obj-chip-grp {
  background: rgba(139, 92, 246, 0.12);
  border-color: rgba(139, 92, 246, 0.28);
  color: #6d28d9;
}
[data-mode="light"] .mno-obj-chip-svc {
  background: rgba(245, 158, 11, 0.12);
  border-color: rgba(245, 158, 11, 0.3);
  color: #b45309;
}
[data-mode="light"] .mno-obj-chip-all {
  background: rgba(148, 163, 184, 0.18);
  border-color: rgba(148, 163, 184, 0.35);
  color: #475569;
}
[data-mode="light"] .mno-schedule-pill {
  background: rgba(0, 0, 0, 0.04);
  color: var(--text-secondary);
  border-color: var(--color-border);
}
[data-mode="light"] .mno-schedule-pill.custom-schedule {
  background: rgba(6, 182, 212, 0.12);
  color: #0891b2;
  border-color: rgba(6, 182, 212, 0.35);
}
[data-mode="light"] .mno-action-accept {
  background: rgba(16, 185, 129, 0.14);
  color: #047857;
  border-color: rgba(16, 185, 129, 0.35);
}
[data-mode="light"] .mno-action-deny {
  background: rgba(239, 68, 68, 0.12);
  color: #b91c1c;
  border-color: rgba(239, 68, 68, 0.35);
}
[data-mode="light"] .mno-cleanup-badge {
  background: rgba(239, 68, 68, 0.12);
  color: #dc2626;
  border-color: rgba(239, 68, 68, 0.35);
}
[data-mode="light"] .mno-system-lock-badge {
  background: rgba(0, 0, 0, 0.04);
  color: var(--text-muted);
  border-color: var(--color-border);
}
[data-mode="light"] .system-cleanup-row {
  background: rgba(239, 68, 68, 0.04) !important;
  border-top: 1px dashed rgba(239, 68, 68, 0.35) !important;
}
[data-mode="light"] .system-stealth-row {
  background: rgba(99, 102, 241, 0.05) !important;
  border-bottom: 1px solid rgba(99, 102, 241, 0.25) !important;
}
[data-mode="light"] .mno-pair-header {
  background: var(--color-surface-2);
  border-color: var(--color-border);
}
.mno-pair-header {
  background: linear-gradient(90deg, rgba(30, 41, 59, 0.9) 0%, rgba(17, 24, 39, 0.9) 100%);
  border: 1px solid var(--color-border);
  border-left: 4px solid var(--color-cyan);
  padding: 10px 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  cursor: pointer;
  user-select: none;
  border-radius: var(--radius-sm);
  margin-top: 18px;
  margin-bottom: 2px;
  transition: all var(--transition-fast);
}
.mno-pair-header:hover {
  background: linear-gradient(90deg, rgba(40, 53, 75, 0.9) 0%, rgba(25, 34, 52, 0.9) 100%);
}
.mno-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 0.8125rem;
}
.mno-table th {
  background: var(--color-surface-3);
  color: var(--text-secondary);
  font-weight: 600;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: 10px 12px;
  border-bottom: 1px solid var(--color-border);
  text-align: left;
}
.mno-table td {
  padding: 10px 12px;
  border-bottom: 1px solid var(--color-border);
  vertical-align: middle;
}
.mno-table tbody tr:hover {
  background: rgba(255,255,255,0.02);
}
.mno-switch {
  position: relative;
  display: inline-block;
  width: 36px;
  height: 20px;
}
.mno-switch input {
  opacity: 0;
  width: 0;
  height: 0;
}
.mno-slider {
  position: absolute;
  cursor: pointer;
  top: 0; left: 0; right: 0; bottom: 0;
  background-color: #334155;
  transition: .2s;
  border-radius: 20px;
}
.mno-slider:before {
  position: absolute;
  content: "";
  height: 14px;
  width: 14px;
  left: 3px;
  bottom: 3px;
  background-color: white;
  transition: .2s;
  border-radius: 50%;
}
input:checked + .mno-slider {
  background-color: var(--color-emerald);
}
input:checked + .mno-slider:before {
  transform: translateX(16px);
}
/* Modal Styles */
.mno-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.75);
  backdrop-filter: blur(4px);
  z-index: 1000;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.mno-modal-backdrop.active {
  display: flex;
}
.mno-modal-dialog {
  background: var(--color-surface-1);
  border: 1px solid var(--color-border-bright);
  border-radius: var(--radius-lg);
  width: 100%;
  max-width: 900px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
  overflow: hidden;
  animation: modalSlideIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes modalSlideIn {
  from { opacity: 0; transform: translateY(-20px) scale(0.98); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}
.mno-modal-header {
  padding: 16px 24px;
  border-bottom: 1px solid var(--color-border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: var(--color-surface-2);
}
.mno-modal-body {
  padding: 24px;
  overflow-y: auto;
  flex: 1 1 auto;
  min-height: 0;   /* without this the body grows past the dialog and cannot scroll */
}
.mno-modal-footer {
  padding: 16px 24px;
  border-top: 1px solid var(--color-border);
  background: var(--color-surface-2);
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  flex: 0 0 auto;
}
/* The `.mno-modal-dialog > form` flex-chain fix and the modal scrollbar styling
   live in public/css/micronos.css so every view that re-declares these classes
   gets them too. */

/* Two-column modal layouts collapse on narrow screens; the inline styles on the
   grid and its children need !important to be overridden. */
@media (max-width: 780px) {
  .mno-modal-grid { grid-template-columns: 1fr !important; }
  .mno-modal-grid > * { grid-column: 1 / -1 !important; }
  .mno-modal-backdrop { padding: 10px; }
  .mno-modal-header, .mno-modal-footer { padding-left: 16px; padding-right: 16px; }
  .mno-modal-body { padding: 16px; }
}

/* The picker's list, count / Clear toolbar and selection chips are styled
   in public/css/micronos.css, next to its search box, so this dialog and the
   address, service-database and routing pickers all render from one
   implementation instead of each re-declaring these classes. */
.mno-tab-btn {
  background: transparent;
  border: none;
  color: var(--text-secondary);
  padding: 8px 16px;
  font-weight: 600;
  font-size: 0.875rem;
  border-bottom: 2px solid transparent;
  cursor: pointer;
  transition: all var(--transition-fast);
}
.mno-tab-btn.active {
  color: var(--color-cyan);
  border-bottom-color: var(--color-cyan);
}
.mno-pill-picker-box {
  background: var(--color-surface-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: 8px;
  min-height: 44px;
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
}
</style>

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
    <div class="page-breadcrumb">
      <span>Security</span>
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
      <span>Firewall Policies</span>
    </div>
    <div style="display:flex; align-items:center; gap:12px; margin-top:4px;">
      <div class="page-title-icon" style="background:var(--tone-cyan-bg); color:var(--tone-cyan-fg); border:1px solid var(--tone-cyan-border);">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
      </div>
      <div>
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
          <h1 class="page-title">Firewall Policy &amp; Objects</h1>
          <span class="badge badge-info" style="font-size:0.75rem;">Policy Engine</span>
          <?php if (!empty($isMock)): ?>
            <span class="badge badge-warning" style="font-size:0.75rem;">IPC Mock Active</span>
          <?php endif; ?>
        </div>
        <p class="page-subtitle">Stateful IPv4 policies, address objects, FQDNs, port groups &amp; atomic nftables compilation</p>
      </div>
    </div>
  </div>
  <div class="page-header-actions">
    <?= \App\Libraries\ServiceStatus::widget('nftables') ?>
    <button type="button" class="btn btn-primary btn-sm" onclick="openCreatePolicyModal()">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
      </svg>
      Create Policy
    </button>

    <!-- CSV Export / Import Buttons -->
    <a href="<?= base_url('firewall/rules/export-csv') ?>" class="btn btn-ghost btn-sm" title="Export Policies to CSV">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
      </svg>
      Export CSV
    </a>

    <button type="button" class="btn btn-ghost btn-sm" onclick="openImportModal('rules')" title="Import Policies from CSV">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
      </svg>
      Import CSV
    </button>

    <button type="button" class="btn btn-ghost btn-sm" onclick="openObjectsModal()">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
      </svg>
      Objects Catalog
    </button>

    <?php
      $canApply = true;
      try {
          if (function_exists('session') && session()->get('user_id')) {
              $canApply = session()->get('is_superadmin') || in_array('firewall.rules.apply', session()->get('permissions') ?? []);
          }
      } catch (\Throwable $e) {
          $canApply = true;
      }
    ?>
    <?php if ($canApply): ?>
      <form method="POST" action="<?= base_url('firewall/rules/apply') ?>" style="display:inline;">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-success btn-sm">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
          Apply nftables
        </button>
      </form>
      <a href="<?= base_url('firewall/rules/nftables-preview') ?>" class="btn btn-ghost btn-sm" title="Download compiled nftables.conf">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        nftables.conf
      </a>
    <?php endif; ?>
  </div>
</div>

<!-- KPI Cards -->
<div class="mno-kpi-grid animate-in animate-in-delay-1">
  <div class="mno-kpi-card">
    <div class="mno-kpi-icon" style="background: var(--tone-cyan-bg); color: var(--tone-cyan-fg); border: 1px solid var(--tone-cyan-border);">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
      </svg>
    </div>
    <div>
      <div style="font-size:1.4rem; font-weight:700; color:var(--text-primary);"><?= count($rules) ?></div>
      <div style="font-size:0.75rem; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.05em;">Firewall Policies</div>
    </div>
  </div>

  <div class="mno-kpi-card">
    <div class="mno-kpi-icon" style="background: var(--tone-emerald-bg); color: var(--tone-emerald-fg); border: 1px solid var(--tone-emerald-border);">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
      </svg>
    </div>
    <div>
      <div style="font-size:1.4rem; font-weight:700; color:var(--text-primary);">
        <?= count(array_filter($rules, fn($r) => (int)($r['enabled'] ?? 1) === 1)) ?>
      </div>
      <div style="font-size:0.75rem; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.05em;">Active Policies</div>
    </div>
  </div>

  <div class="mno-kpi-card">
    <div class="mno-kpi-icon" style="background: var(--tone-blue-bg); color: var(--tone-blue-fg); border: 1px solid var(--tone-blue-border);">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/>
      </svg>
    </div>
    <div>
      <div style="font-size:1.4rem; font-weight:700; color:var(--text-primary);">
        <?= count($addresses) ?> <span style="font-size:0.85rem; font-weight:400; color:var(--text-muted);">(+<?= count($addressGroups) ?> grps)</span>
      </div>
      <div style="font-size:0.75rem; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.05em;">Addresses &amp; FQDNs</div>
    </div>
  </div>

  <div class="mno-kpi-card">
    <div class="mno-kpi-icon" style="background: var(--tone-amber-bg); color: var(--tone-amber-fg); border: 1px solid var(--tone-amber-border);">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16m-7 6h7"/>
      </svg>
    </div>
    <div>
      <div style="font-size:1.4rem; font-weight:700; color:var(--text-primary);">
        <?= count($services) ?> <span style="font-size:0.85rem; font-weight:400; color:var(--text-muted);">(+<?= count($serviceGroups) ?> grps)</span>
      </div>
      <div style="font-size:0.75rem; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.05em;">Known Services / Ports</div>
    </div>
  </div>
</div>

<!-- Toolbar & Filters -->
<div class="mno-toolbar animate-in animate-in-delay-2">
  <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
    <!-- View Switcher -->
    <div style="display:inline-flex; background:var(--color-surface-3); border-radius:var(--radius-sm); padding:2px; border:1px solid var(--color-border);">
      <a href="<?= base_url('firewall/rules?view=pair&if=' . urlencode($selectedIface)) ?>"
         style="padding:5px 12px; border-radius:4px; font-size:0.75rem; font-weight:600; text-decoration:none; transition:all .2s; <?= $viewMode === 'pair' ? 'background:var(--color-surface-1); color:var(--color-cyan); box-shadow:var(--shadow-sm);' : 'color:var(--text-secondary);' ?>">
        By Interface Pair
      </a>
      <a href="<?= base_url('firewall/rules?view=seq&if=' . urlencode($selectedIface)) ?>"
         style="padding:5px 12px; border-radius:4px; font-size:0.75rem; font-weight:600; text-decoration:none; transition:all .2s; <?= $viewMode === 'seq' ? 'background:var(--color-surface-1); color:var(--color-cyan); box-shadow:var(--shadow-sm);' : 'color:var(--text-secondary);' ?>">
        By Sequence
      </a>
    </div>

    <!-- Interface Filter -->
    <div style="display:flex; align-items:center; gap:6px;">
      <span style="font-size:0.75rem; color:var(--text-muted);">Interface:</span>
      <select onchange="window.location.href='<?= base_url('firewall/rules?view=' . $viewMode . '&if=') ?>' + this.value"
              style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:5px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
        <option value="all" <?= $selectedIface === 'all' ? 'selected' : '' ?>>All Interfaces (Pair View)</option>
        <?php foreach ($interfaces as $if): ?>
          <option value="<?= esc($if['id']) ?>" <?= $selectedIface === $if['id'] ? 'selected' : '' ?>>
            <?= esc($if['name']) ?> (<?= esc($if['physical_device']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Compact / stacked view toggle for the wide policy table -->
    <button type="button" class="btn btn-ghost btn-sm" data-compact-toggle onclick="micronToggleCompact()" aria-pressed="false" title="Toggle compact (stacked) table view">
      <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
      <span data-compact-label>Compact view</span>
    </button>
  </div>

  <!-- Search Filter -->
  <div style="display:flex; align-items:center; gap:8px;">
    <div style="position:relative;">
      <input type="text" id="mnoSearchInput" placeholder="Filter policies (Name, IP, Port...)"
             onkeyup="filterPolicies()"
             style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 12px 6px 32px; border-radius:var(--radius-sm); font-size:0.8rem; width:260px;">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
           style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--text-muted);">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
      </svg>
    </div>
  </div>
<!-- Zero Trust Security Posture Indicator -->
<div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; padding:10px 16px; background:linear-gradient(90deg, rgba(99, 102, 241, 0.08) 0%, rgba(239, 68, 68, 0.06) 100%); border:1px solid rgba(99, 102, 241, 0.2); border-radius:var(--radius-md); margin-bottom:16px;">
  <div style="display:flex; align-items:center; gap:10px;">
    <span style="font-size:1.1rem;">🛡️</span>
    <div>
      <div style="font-size:0.8125rem; font-weight:700; color:var(--text-primary); display:flex; align-items:center; gap:8px;">
        <span>Zero Trust Posture: Deny All by Default</span>
        <span class="badge badge-purple" style="font-size:0.65rem;">Active</span>
      </div>
      <div style="font-size:0.75rem; color:var(--text-secondary); margin-top:2px;">
        Kernel nftables chains enforce <code>policy drop</code>. Top <strong>Stealth Rule</strong> shields appliance management from WAN; bottom <strong>Cleanup Rule (Policy 0)</strong> terminates and logs unmatched traffic.
      </div>
    </div>
  </div>
  <div style="display:flex; align-items:center; gap:8px;">
    <span class="badge" style="background:rgba(99,102,241,0.15); color:var(--color-purple); font-size:0.7rem; border:1px solid rgba(99,102,241,0.3);">
      Top: Stealth Shield
    </span>
    <span class="badge" style="background:rgba(239,68,68,0.15); color:var(--color-danger); font-size:0.7rem; border:1px solid rgba(239,68,68,0.3);">
      Bottom: Cleanup Rule (Drop)
    </span>
  </div>
</div>

<!-- Policies Table Container -->
<div class="card card-glass animate-in animate-in-delay-3" style="padding:0; overflow:hidden; margin-bottom:32px;">

  <?php if (empty($rules)): ?>
    <div style="text-align:center; padding:48px 24px; color:var(--text-muted);">
      <svg width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="margin-bottom:12px; color:var(--text-muted);">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
      </svg>
      <div style="font-size:1.1rem; font-weight:600; color:var(--text-secondary); margin-bottom:6px;">No Firewall Policies Configured</div>
      <p style="font-size:0.85rem; max-width:480px; margin:0 auto 16px auto;">Click <strong>Create New Policy</strong> to configure stateful filtering rules or import default objects.</p>
      <button type="button" class="btn btn-primary" onclick="openCreatePolicyModal()">+ Create Policy</button>
    </div>
  <?php else: ?>

    <?php if ($viewMode === 'pair'): ?>
      <!-- Interface Pair View -->
      <?php foreach ($policiesByPair as $pairKey => $pair): ?>
        <div class="mno-pair-section">
          <div class="mno-pair-header" onclick="togglePairSection(this)">
            <div style="display:flex; align-items:center; gap:10px;">
              <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="mno-chevron" style="transition:transform .2s;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
              </svg>
              <span style="font-weight:700; font-size:0.875rem; color:var(--text-primary); letter-spacing:0.02em;">
                <?= esc($pair['title']) ?>
              </span>
              <span class="badge badge-info" style="font-size:0.65rem;">
                <?= count($pair['rules']) ?> <?= count($pair['rules']) === 1 ? 'Policy' : 'Policies' ?>
              </span>
            </div>
            <div style="display:flex; align-items:center; gap:12px; font-size:0.75rem; color:var(--text-secondary);">
              <span>From: <strong style="color:var(--text-primary);"<?= $__ifaceTip((string) $pair['src']) ?>><?= esc(strtoupper($pair['src'])) ?></strong></span>
              <span>➔</span>
              <span>To: <strong style="color:var(--text-primary);"<?= $__ifaceTip((string) $pair['dst']) ?>><?= esc(strtoupper($pair['dst'])) ?></strong></span>
            </div>
          </div>

          <div class="mno-pair-content" style="overflow-x:auto;">
            <table class="mno-table policy-table">
              <thead>
                <tr>
                  <th style="width:50px; text-align:center;">ID</th>
                  <th style="min-width:180px;">Name</th>
                  <th style="min-width:110px;">Source</th>
                  <th style="min-width:110px;">Destination</th>
                  <th style="width:85px; text-align:center;">Schedule</th>
                  <th style="min-width:110px;">Service</th>
                  <th style="width:90px; text-align:center;">Action</th>
                  <th style="width:65px; text-align:center;">NAT</th>
                  <th style="width:75px; text-align:center;">Security</th>
                  <th style="width:100px;">Hits / Bytes</th>
                  <th style="width:65px; text-align:center;">Status</th>
                  <th style="width:85px; text-align:right;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($pair['rules'] as $rule): ?>
                  <?= view('firewall/_policy_row', ['rule' => $rule, 'canReorder' => $canReorder, 'addresses' => $addresses, 'addressGroups' => $addressGroups, 'services' => $services, 'serviceGroups' => $serviceGroups, 'schedules' => $schedules, 'interfaces' => $interfaces]) ?>
                <?php endforeach; ?>
                <!-- Implicit Deny / Cleanup Rule (Zero Trust Terminator) -->
                <tr class="system-cleanup-row">
                  <td data-label="ID" style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-muted); font-weight:600; white-space:nowrap; text-align:center;">
                    <span class="policy-seq" style="color:var(--color-danger); opacity:0.85;" title="Implicit Rulebase Terminator (Policy 0)">#0</span>
                  </td>
                  <td data-label="Name">
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:nowrap;">
                      <span style="font-size:0.875rem; color:var(--color-danger); font-weight:600; white-space:nowrap;">Implicit Deny All / Cleanup</span>
                      <span class="mno-cleanup-badge">POLICY 0</span>
                    </div>
                    <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px;">Drops unmatched packets between <?= esc(strtoupper($pair['src'])) ?> and <?= esc(strtoupper($pair['dst'])) ?> (Zero Trust)</div>
                  </td>
                  <td data-label="Source">
                    <span class="mno-obj-chip mno-obj-chip-all" title="All IPv4 / IPv6 addresses">
                      <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                      <span>all</span>
                    </span>
                  </td>
                  <td data-label="Destination">
                    <span class="mno-obj-chip mno-obj-chip-all" title="All IPv4 / IPv6 addresses">
                      <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                      <span>all</span>
                    </span>
                  </td>
                  <td data-label="Schedule" style="text-align:center;">
                    <span class="mno-schedule-pill">ALWAYS</span>
                  </td>
                  <td data-label="Service">
                    <span class="mno-obj-chip mno-obj-chip-all" title="All Protocols / Ports">
                      <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                      <span>ALL</span>
                    </span>
                  </td>
                  <td data-label="Action" style="text-align:center;">
                    <span class="mno-action-deny">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                      <span>DROP</span>
                    </span>
                  </td>
                  <td data-label="NAT" style="text-align:center;">
                    <span class="mno-nat-off">—</span>
                  </td>
                  <td data-label="Security" style="text-align:center;">
                    <span style="color:var(--text-muted); font-size:0.75rem;">—</span>
                  </td>
                  <td data-label="Hits / Bytes" style="font-family:var(--font-mono); font-size:0.75rem; white-space:nowrap;">
                    <div><strong style="color:var(--text-primary);">Kernel</strong></div>
                    <div style="font-size:0.68rem; color:var(--text-muted);">default drop</div>
                  </td>
                  <td data-label="Status" style="text-align:center; white-space:nowrap;">
                    <span class="mno-system-status-pill" title="Enforced at kernel netfilter level">ENFORCED</span>
                  </td>
                  <td data-label="Actions" style="text-align:right; white-space:nowrap;">
                    <span class="mno-system-lock-badge" title="Built-in default rule cannot be deleted or reordered">
                      <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                      <span>LOCKED</span>
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>

    <?php else: ?>
      <!-- Sequence View (Flat top-to-bottom) -->
      <div style="overflow-x:auto;">
        <table class="mno-table policy-table">
          <thead>
            <tr>
              <th style="width:50px; text-align:center;">Seq</th>
              <th style="min-width:180px;">Name</th>
              <th style="width:70px; text-align:center;">From</th>
              <th style="width:70px; text-align:center;">To</th>
              <th style="min-width:110px;">Source</th>
              <th style="min-width:110px;">Destination</th>
              <th style="width:85px; text-align:center;">Schedule</th>
              <th style="min-width:110px;">Service</th>
              <th style="width:90px; text-align:center;">Action</th>
              <th style="width:65px; text-align:center;">NAT</th>
              <th style="width:75px; text-align:center;">Security</th>
              <th style="width:100px;">Hits / Bytes</th>
              <th style="width:65px; text-align:center;">Status</th>
              <th style="width:85px; text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <!-- Top Rule: Stealth Rule (Management Plane Protection) -->
            <tr class="system-stealth-row">
              <td data-label="Seq" style="font-family:var(--font-mono); font-size:0.75rem; color:var(--color-purple); font-weight:700; white-space:nowrap; text-align:center;">
                <span title="Appliance Control Plane Protection Rule">🛡️</span>
              </td>
              <td data-label="Name">
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:nowrap;">
                  <span style="font-size:0.875rem; color:var(--text-primary); font-weight:600; white-space:nowrap;">Stealth Rule (Firewall Appliance Shield)</span>
                  <span class="mno-stealth-badge" title="Control Plane Shield">STEALTH</span>
                </div>
                <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px;">Drops external probes targeting Web GUI (80/443), SSH (22), and Telnet (23)</div>
              </td>
              <td data-label="From" style="text-align:center;">
                <span class="badge badge-info" style="font-size:0.7rem; white-space:nowrap;">WAN</span>
              </td>
              <td data-label="To" style="text-align:center;">
                <span class="badge badge-purple" style="font-size:0.68rem; white-space:nowrap;" title="Appliance Control Plane">SELF (MGMT)</span>
              </td>
              <td data-label="Source">
                <span class="mno-obj-chip mno-obj-chip-all" title="All IPv4 / IPv6 addresses">
                  <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                  <span>all</span>
                </span>
              </td>
              <td data-label="Destination">
                <span class="mno-obj-chip mno-obj-chip-grp" style="white-space:nowrap;" title="Internal Appliance IP Addresses">
                  <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                  <span>Appliance IPs</span>
                </span>
              </td>
              <td data-label="Schedule" style="text-align:center;">
                <span class="mno-schedule-pill">ALWAYS</span>
              </td>
              <td data-label="Service">
                <span class="mno-obj-chip mno-obj-chip-svc" style="white-space:nowrap;" title="Management ports: 80, 443, 8443, 22, 23">
                  <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v6m4-6v6M8 8h8a2 2 0 0 1 2 2v3a6 6 0 0 1-12 0v-3a2 2 0 0 1 2-2zM12 17v5"></path></svg>
                  <span>HTTP/S, SSH</span>
                </span>
              </td>
              <td data-label="Action" style="text-align:center;">
                <span class="mno-action-deny">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                  <span>DROP</span>
                </span>
              </td>
              <td data-label="NAT" style="text-align:center;">
                <span class="mno-nat-off">—</span>
              </td>
              <td data-label="Security" style="text-align:center;">
                <span class="mno-sec-badge" style="background:rgba(99,102,241,0.15); color:var(--color-purple, #818cf8); border-color:rgba(99,102,241,0.35);">PROTECTED</span>
              </td>
              <td data-label="Hits / Bytes" style="font-family:var(--font-mono); font-size:0.75rem; white-space:nowrap;">
                <div><strong style="color:var(--text-primary);">Kernel</strong></div>
                <div style="font-size:0.68rem; color:var(--text-muted);">input chain</div>
              </td>
              <td data-label="Status" style="text-align:center; white-space:nowrap;">
                <span class="mno-system-status-pill" title="Protected built-in control plane shield">ENFORCED</span>
              </td>
              <td data-label="Actions" style="text-align:right; white-space:nowrap;">
                <span class="mno-system-lock-badge" title="Protected system rule cannot be modified">
                  <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                  <span>LOCKED</span>
                </span>
              </td>
            </tr>

            <?php foreach ($rules as $rule): ?>
              <?= view('firewall/_policy_row', ['rule' => $rule, 'canReorder' => $canReorder, 'showInterfaces' => true, 'addresses' => $addresses, 'addressGroups' => $addressGroups, 'services' => $services, 'serviceGroups' => $serviceGroups, 'schedules' => $schedules, 'interfaces' => $interfaces]) ?>
            <?php endforeach; ?>

            <!-- Bottom Rule: Implicit Deny All / Cleanup Rule -->
            <tr class="system-cleanup-row">
              <td data-label="Seq" style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-muted); font-weight:600; white-space:nowrap; text-align:center;">
                <span class="policy-seq" style="color:var(--color-danger); opacity:0.85;" title="Implicit Rulebase Terminator (Policy 0)">#0</span>
              </td>
              <td data-label="Name">
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:nowrap;">
                  <span style="font-size:0.875rem; color:var(--color-danger); font-weight:600; white-space:nowrap;">Implicit Deny All / Cleanup Rule</span>
                  <span class="mno-cleanup-badge">POLICY 0</span>
                </div>
                <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px;">Drops and logs any packet not matched by preceding policies (Zero Trust terminator)</div>
              </td>
              <td data-label="From" style="text-align:center;">
                <span class="mno-obj-chip mno-obj-chip-all" style="font-size:0.7rem;">ALL</span>
              </td>
              <td data-label="To" style="text-align:center;">
                <span class="mno-obj-chip mno-obj-chip-all" style="font-size:0.7rem;">ALL</span>
              </td>
              <td data-label="Source">
                <span class="mno-obj-chip mno-obj-chip-all" title="All IPv4 / IPv6 addresses">
                  <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                  <span>all</span>
                </span>
              </td>
              <td data-label="Destination">
                <span class="mno-obj-chip mno-obj-chip-all" title="All IPv4 / IPv6 addresses">
                  <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                  <span>all</span>
                </span>
              </td>
              <td data-label="Schedule" style="text-align:center;">
                <span class="mno-schedule-pill">ALWAYS</span>
              </td>
              <td data-label="Service">
                <span class="mno-obj-chip mno-obj-chip-all" title="All Protocols / Ports">
                  <svg class="mno-chip-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                  <span>ALL</span>
                </span>
              </td>
              <td data-label="Action" style="text-align:center;">
                <span class="mno-action-deny">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                  <span>DROP</span>
                </span>
              </td>
              <td data-label="NAT" style="text-align:center;">
                <span class="mno-nat-off">—</span>
              </td>
              <td data-label="Security" style="text-align:center;">
                <span style="color:var(--text-muted); font-size:0.75rem;">—</span>
              </td>
              <td data-label="Hits / Bytes" style="font-family:var(--font-mono); font-size:0.75rem; white-space:nowrap;">
                <div><strong style="color:var(--text-primary);">Kernel</strong></div>
                <div style="font-size:0.68rem; color:var(--text-muted);">default drop</div>
              </td>
              <td data-label="Status" style="text-align:center; white-space:nowrap;">
                <span class="mno-system-status-pill" title="Enforced at kernel netfilter level">ENFORCED</span>
              </td>
              <td data-label="Actions" style="text-align:right; white-space:nowrap;">
                <span class="mno-system-lock-badge" title="Built-in default rule cannot be deleted or reordered">
                  <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                  <span>LOCKED</span>
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  <?php endif; ?>
</div>

<!-- ========================================================
     MODAL: Create / Edit Firewall Policy
     ======================================================== -->
<div class="mno-modal-backdrop" id="policyModal">
  <div class="mno-modal-dialog">
    <div class="mno-modal-header">
      <div style="display:flex; align-items:center; gap:8px;">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        <span style="font-weight:700; font-size:1.1rem; color:var(--text-primary);" id="policyModalTitle">New Firewall Policy</span>
      </div>
      <button type="button" onclick="closePolicyModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem;">&times;</button>
    </div>

    <form method="POST" id="policyForm" action="<?= base_url('firewall/policies/store') ?>">
      <?= csrf_field() ?>
      <input type="hidden" id="policyId" name="id" value="">

      <div class="mno-modal-body">
        <div class="mno-modal-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
          <!-- Policy Name -->
          <div style="grid-column: span 2;">
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Policy Name <span style="color:var(--color-red);">*</span>
            </label>
            <input type="text" name="name" id="polName" required placeholder="e.g. LAN to WAN Internet Access"
                   style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
          </div>

          <!-- Incoming Interface -->
          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Incoming Interface <span style="color:var(--color-red);">*</span>
            </label>
            <select name="src_interface" id="polSrcIface" required data-fw-iface
                    style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
              <?php foreach ($interfaces as $if): ?>
                <option value="<?= esc($if['id']) ?>"<?= \App\Libraries\ObjectTip::iface($if) ?>><?= esc($if['name']) ?> (<?= esc($if['physical_device']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Outgoing Interface -->
          <div>
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Outgoing Interface <span style="color:var(--color-red);">*</span>
            </label>
            <select name="dst_interface" id="polDstIface" required data-fw-iface
                    style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
              <?php foreach ($interfaces as $if): ?>
                <option value="<?= esc($if['id']) ?>"<?= \App\Libraries\ObjectTip::iface($if) ?> <?= strtoupper($if['id']) === 'WAN' ? 'selected' : '' ?>><?= esc($if['name']) ?> (<?= esc($if['physical_device']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Policy Schedule -->
          <div style="grid-column: span 2;">
            <label style="display:flex; justify-content:space-between; align-items:center; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              <span>Policy Schedule</span>
              <a href="<?= base_url('firewall/schedules') ?>" target="_blank" style="color:var(--color-cyan); text-decoration:none; font-size:0.7rem;">+ Manage Schedules</a>
            </label>
            <select name="schedule" id="polSchedule"
                    style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.875rem;">
              <?php foreach (($schedules ?? []) as $sc): ?>
                <option value="<?= esc($sc['name']) ?>">
                  <?= esc(ucwords(str_replace('_', ' ', $sc['name']))) ?> (<?= esc(\App\Models\ScheduleModel::formatSummary($sc)) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Source Address Selector -->
          <div>
            <label style="display:flex; justify-content:space-between; align-items:center; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              <span>Source Addresses / Groups</span>
              <a href="javascript:void(0)" onclick="openObjectsModal('addresses')" style="color:var(--color-cyan); text-decoration:none; font-size:0.7rem;">+ New Address</a>
            </label>
            <input type="search" class="mno-pick-search" id="polSourcesSearch"
                   placeholder="Search addresses &amp; groups…" autocomplete="off" spellcheck="false">
            <?php /* Server-rendered first page of the object list; the search box
                     pages the rest in (partials/pick_select.php). */ ?>
            <?= view('partials/pick_select', [
              'id'       => 'polSources',
              'name'     => 'sources[]',
              'size'     => 6,
              'class'    => 'mno-multiselect',
              'pickerSentinel' => ['value' => 'all', 'label' => 'all (0.0.0.0/0)'],
              'source'         => 'addressObjects',
              'search'         => base_url('firewall/objects/search'),
            ]) ?>
            <div class="mno-pick-empty" id="polSourcesEmpty" hidden>No matching addresses or groups</div>
            <div class="mno-pick-bar">
              <span class="mno-pick-count" id="polSourcesCount"></span>
              <button type="button" class="mno-pick-btn" onclick="pickClear('polSources')">Clear</button>
            </div>
            <div class="mno-chips" id="polSourcesChips"></div>
            <span style="font-size:0.7rem; color:var(--text-muted); display:block; margin-top:4px;">Type to filter · ↑↓ highlight · Space toggle · Enter add · Hold Ctrl / Cmd to multi-select · pick a 🌍 Geography object to match a country</span>
          </div>

          <!-- Destination Address Selector -->
          <div>
            <label style="display:flex; justify-content:space-between; align-items:center; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              <span>Destination Addresses / Groups</span>
              <a href="javascript:void(0)" onclick="openObjectsModal('addresses')" style="color:var(--color-cyan); text-decoration:none; font-size:0.7rem;">+ New Address</a>
            </label>
            <input type="search" class="mno-pick-search" id="polDestinationsSearch"
                   placeholder="Search addresses &amp; groups…" autocomplete="off" spellcheck="false">
            <?= view('partials/pick_select', [
              'id'       => 'polDestinations',
              'name'     => 'destinations[]',
              'size'     => 6,
              'class'    => 'mno-multiselect',
              'pickerSentinel' => ['value' => 'all', 'label' => 'all (0.0.0.0/0)'],
              'source'         => 'addressObjects',
              'search'         => base_url('firewall/objects/search'),
            ]) ?>
            <div class="mno-pick-empty" id="polDestinationsEmpty" hidden>No matching addresses or groups</div>
            <div class="mno-pick-bar">
              <span class="mno-pick-count" id="polDestinationsCount"></span>
              <button type="button" class="mno-pick-btn" onclick="pickClear('polDestinations')">Clear</button>
            </div>
            <div class="mno-chips" id="polDestinationsChips"></div>
            <span style="font-size:0.7rem; color:var(--text-muted); display:block; margin-top:4px;">Type to filter · ↑↓ highlight · Space toggle · Enter add · Hold Ctrl / Cmd to multi-select · pick a 🌍 Geography object to match a country</span>
          </div>

          <!-- Services / Port Groups Selector -->
          <div style="grid-column: span 2;">
            <label style="display:flex; justify-content:space-between; align-items:center; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              <span>Services &amp; Port Groups</span>
              <a href="javascript:void(0)" onclick="openObjectsModal('services')" style="color:var(--color-cyan); text-decoration:none; font-size:0.7rem;">+ New Service</a>
            </label>
            <input type="search" class="mno-pick-search" id="polServicesSearch"
                   placeholder="Search services &amp; port groups…" autocomplete="off" spellcheck="false">
            <?php /* The same server-rendered first page as the address pickers
                     (partials/pick_select.php): service groups, then services. */ ?>
            <?= view('partials/pick_select', [
              'id'       => 'polServices',
              'name'     => 'services[]',
              'size'     => 7,
              'class'    => 'mno-multiselect',
              'pickerSentinel' => ['value' => 'ALL', 'label' => 'ALL (Any Protocol/Port)'],
              'source'         => 'firewallServices',
              'search'         => base_url('firewall/objects/search'),
            ]) ?>
            <div class="mno-pick-empty" id="polServicesEmpty" hidden>No matching services or port groups</div>
            <div class="mno-pick-bar">
              <span class="mno-pick-count" id="polServicesCount"></span>
              <button type="button" class="mno-pick-btn" onclick="pickClear('polServices')">Clear</button>
            </div>
            <div class="mno-chips" id="polServicesChips"></div>
            <span style="font-size:0.7rem; color:var(--text-muted); display:block; margin-top:4px;">Type to filter · ↑↓ highlight · Space toggle · Enter add · Hold Ctrl / Cmd to select multiple services or groups (e.g. Web_Browsing, SSH, DNS)</span>
          </div>

          <!-- Action & NAT Options -->
          <div style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:12px;">
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:8px;">
              Action
            </label>
            <div style="display:flex; gap:16px;">
              <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                <input type="radio" name="action" value="accept" checked id="actAccept">
                <span class="mno-action-accept">✓ ACCEPT</span>
              </label>
              <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                <input type="radio" name="action" value="deny" id="actDeny">
                <span class="mno-action-deny">✕ DENY</span>
              </label>
            </div>
          </div>

          <div style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:12px;">
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:8px;">
              Network Integration (NAT)
            </label>
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
              <input type="checkbox" name="nat_enabled" value="1" id="polNat" checked>
              <span style="font-size:0.8125rem; font-weight:500;">Enable Source NAT (Masquerade via Outgoing Interface)</span>
            </label>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:12px;">
              <div>
                <label for="polDnat" style="display:flex; justify-content:space-between; align-items:center; font-size:0.7rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:4px;">
                  <span>Destination NAT</span>
                  <a href="<?= base_url('nat?tab=dnat') ?>" style="color:var(--color-cyan); text-decoration:none; font-size:0.68rem;">Manage</a>
                </label>
                <select name="dnat_name" id="polDnat" style="width:100%;">
                  <option value="">— None —</option>
                  <?php foreach (($dnatRules ?? []) as $dnat): ?>
                    <option value="<?= esc($dnat['name']) ?>"><?= esc($dnat['name']) ?> (<?= esc($dnat['external_ip']) ?> &rarr; <?= esc($dnat['mapped_ip']) ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label for="polSnatPool" style="display:flex; justify-content:space-between; align-items:center; font-size:0.7rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:4px;">
                  <span>SNAT Pool</span>
                  <a href="<?= base_url('nat?tab=snat') ?>" style="color:var(--color-cyan); text-decoration:none; font-size:0.68rem;">Manage</a>
                </label>
                <select name="snat_pool_name" id="polSnatPool" style="width:100%;">
                  <option value="">— Interface Address —</option>
                  <?php foreach (($snatPools ?? []) as $snatPool): ?>
                    <option value="<?= esc($snatPool['name']) ?>"><?= esc($snatPool['name']) ?> (<?= esc($snatPool['start_ip']) ?><?= !empty($snatPool['end_ip']) && $snatPool['end_ip'] !== $snatPool['start_ip'] ? '-' . esc($snatPool['end_ip']) : '' ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <span style="font-size:0.7rem; color:var(--text-muted); display:block; margin-top:6px;">A Destination NAT rule adds inbound DNAT; pick the SNAT Pool to translate the source address for outbound traffic.</span>
          </div>

          <!-- Security Profiles & Logging -->
          <div style="grid-column: span 2; background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:12px;">
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:8px;">
              Security Profiles &amp; Logging
            </label>
            <div style="display:flex; gap:20px; flex-wrap:wrap; align-items:center;">
              <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-size:0.8125rem;">
                <input type="checkbox" name="profile_ips" value="1" id="profIps">
                <span>Intrusion Prevention (IPS)</span>
              </label>
              <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-size:0.8125rem;">
                <input type="checkbox" name="profile_av" value="1" id="profAv">
                <span>Antivirus Inspection</span>
              </label>
              <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-size:0.8125rem;">
                <input type="checkbox" name="profile_webfilter" value="1" id="profWeb">
                <span>Web Filtering</span>
              </label>
              <div style="display:flex; align-items:center; gap:8px; margin-left:auto;">
                <span style="font-size:0.75rem; color:var(--text-muted);">Log Traffic:</span>
                <select name="log_traffic" id="polLog" style="background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:4px 8px; border-radius:var(--radius-sm); font-size:0.75rem;">
                  <option value="all">All Sessions</option>
                  <option value="utm">Security Events Only</option>
                  <option value="none">Disabled</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Description / Comments -->
          <div style="grid-column: span 2;">
            <label style="display:block; font-size:0.75rem; font-weight:600; text-transform:uppercase; color:var(--text-secondary); margin-bottom:6px;">
              Comments
            </label>
            <textarea name="description" id="polDesc" rows="2" placeholder="Optional notes for this firewall policy..."
                      style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:8px 12px; border-radius:var(--radius-sm); font-size:0.8125rem; resize:vertical;"></textarea>
          </div>
        </div>
      </div>

      <div class="mno-modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closePolicyModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" id="policySubmitBtn">Save Policy</button>
      </div>
    </form>
  </div>
</div>

<!-- ========================================================
     MODAL: Firewall Objects Management (Addresses, Services, Groups)
     ======================================================== -->
<div class="mno-modal-backdrop" id="objectsModal">
  <div class="mno-modal-dialog" style="max-width:960px;">
    <div class="mno-modal-header">
      <div style="display:flex; align-items:center; gap:10px;">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
        </svg>
        <span style="font-weight:700; font-size:1.1rem; color:var(--text-primary);">Firewall Objects Catalog</span>
      </div>
      <button type="button" onclick="closeObjectsModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem;">&times;</button>
    </div>

    <!-- Object Navigation Tabs -->
    <div style="display:flex; border-bottom:1px solid var(--color-border); background:var(--color-surface-2); padding:0 16px;">
      <button type="button" class="mno-tab-btn active" id="tabBtnAddresses" onclick="switchObjTab('addresses')">
        Addresses (<?= count($addresses) ?>)
      </button>
      <button type="button" class="mno-tab-btn" id="tabBtnAddressGroups" onclick="switchObjTab('addressGroups')">
        Address Groups (<?= count($addressGroups) ?>)
      </button>
      <button type="button" class="mno-tab-btn" id="tabBtnServices" onclick="switchObjTab('services')">
        Services / Ports (<?= count($services) ?>)
      </button>
      <button type="button" class="mno-tab-btn" id="tabBtnServiceGroups" onclick="switchObjTab('serviceGroups')">
        Service Groups (<?= count($serviceGroups) ?>)
      </button>
    </div>

    <div class="mno-modal-body" style="padding:16px 24px;">

      <!-- TAB 1: ADDRESSES -->
      <div id="objTabAddresses">
        <!-- New Address Form Collapsible -->
        <div style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:14px; margin-bottom:20px;">
          <div style="font-weight:600; font-size:0.875rem; margin-bottom:10px; color:var(--color-cyan);">+ Add New Address Object</div>
          <form method="POST" action="<?= base_url('firewall/addresses/store') ?>" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)) 100px; gap:10px; align-items:flex-end;">
            <?= csrf_field() ?>
            <div>
              <label style="font-size:0.7rem; color:var(--text-secondary); display:block; margin-bottom:4px;">Name</label>
              <input type="text" name="name" required placeholder="e.g. ERP_Server" style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
            </div>
            <div>
              <label style="font-size:0.7rem; color:var(--text-secondary); display:block; margin-bottom:4px;">Type</label>
              <select name="type" style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
                <option value="ipmask">Subnet / IP (CIDR)</option>
                <option value="iprange">IP Range (x.x.x.x-y.y.y.y)</option>
                <option value="fqdn">FQDN (Domain)</option>
                <option value="geoip">Geography / Country (ISO codes)</option>
              </select>
            </div>
            <div>
              <label style="font-size:0.7rem; color:var(--text-secondary); display:block; margin-bottom:4px;">IP / Range / FQDN / Countries</label>
              <input type="text" name="value" required placeholder="192.168.1.50/32, api.domain.com or US, CA" style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
            </div>
            <div>
              <label style="font-size:0.7rem; color:var(--text-secondary); display:block; margin-bottom:4px;">Comment</label>
              <input type="text" name="comment" placeholder="Optional notes" style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
            </div>
            <div>
              <button type="submit" class="btn btn-primary" style="width:100%; padding:6px 10px; font-size:0.8rem;">Add</button>
            </div>
          </form>
        </div>

        <table class="mno-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Type</th>
              <th>Value</th>
              <th>Interface</th>
              <th>Comment</th>
              <th style="text-align:right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($addresses as $a): ?>
              <tr>
                <td>
                  <span class="mno-obj-chip <?= $a['type'] === 'fqdn' ? 'mno-obj-chip-fqdn' : ($a['type'] === 'geoip' ? 'mno-obj-chip-geo' : 'mno-obj-chip-addr') ?>">
                    <?= $a['type'] === 'fqdn' ? '🌐' : ($a['type'] === 'geoip' ? '🌍' : '🖥️') ?> <?= esc($a['name']) ?>
                  </span>
                  <?php if (!empty($a['is_system'])): ?>
                    <span class="badge badge-muted" style="font-size:0.6rem;">SYSTEM</span>
                  <?php endif; ?>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.75rem; text-transform:uppercase;">
                  <?= $a['type'] === 'geoip' ? 'GEOGRAPHY' : esc($a['type']) ?>
                </td>
                <td style="font-family:var(--font-mono); color:var(--text-primary);">
                  <?= $a['type'] === 'geoip' ? esc(\App\Libraries\GeoIp::label((string) $a['value'])) : esc($a['value']) ?>
                </td>
                <td>
                  <span class="badge badge-muted" style="font-size:0.7rem;"><?= esc($a['interface'] ?? 'any') ?></span>
                </td>
                <td style="color:var(--text-muted); font-size:0.75rem;">
                  <?= esc($a['comment'] ?? '—') ?>
                </td>
                <td style="text-align:right;">
                  <?php if (empty($a['is_system'])): ?>
                    <form method="POST" action="<?= base_url('firewall/addresses/' . esc($a['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Delete address <?= esc($a['name']) ?>?');">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-red); padding:2px 6px;">Delete</button>
                    </form>
                  <?php else: ?>
                    <span style="font-size:0.7rem; color:var(--text-muted);">Protected</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- TAB 2: ADDRESS GROUPS -->
      <div id="objTabAddressGroups" style="display:none;">
        <div style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:14px; margin-bottom:20px;">
          <div style="font-weight:600; font-size:0.875rem; margin-bottom:10px; color:var(--color-cyan);">+ Add New Address Group</div>
          <form method="POST" action="<?= base_url('firewall/address-groups/store') ?>">
            <?= csrf_field() ?>
            <div class="mno-modal-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
              <div>
                <label style="font-size:0.7rem; color:var(--text-secondary); display:block; margin-bottom:4px;">Group Name</label>
                <input type="text" name="name" required placeholder="e.g. Database_Servers_Group" style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
              </div>
              <div>
                <label style="font-size:0.7rem; color:var(--text-secondary); display:block; margin-bottom:4px;">Comment</label>
                <input type="text" name="comment" placeholder="Optional notes" style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
              </div>
            </div>
            <div style="margin-bottom:12px;">
              <label style="font-size:0.7rem; color:var(--text-secondary); display:block; margin-bottom:4px;">Select Member Addresses</label>
              <div style="max-height:120px; overflow-y:auto; background:var(--color-surface-3); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:8px; display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:6px;">
                <?php foreach ($addresses as $a): ?>
                  <label style="display:flex; align-items:center; gap:6px; font-size:0.75rem; cursor:pointer;">
                    <input type="checkbox" name="members[]" value="<?= esc($a['name']) ?>">
                    <span style="font-family:var(--font-mono);"><?= esc($a['name']) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Create Address Group</button>
          </form>
        </div>

        <table class="mno-table">
          <thead>
            <tr>
              <th>Group Name</th>
              <th>Member Addresses</th>
              <th>Comment</th>
              <th style="text-align:right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($addressGroups as $ag): ?>
              <?php $members = is_string($ag['members']) ? json_decode($ag['members'], true) : $ag['members']; ?>
              <tr>
                <td>
                  <span class="mno-obj-chip mno-obj-chip-grp">📁 <?= esc($ag['name']) ?></span>
                </td>
                <td>
                  <?php if (!empty($members)): ?>
                    <?php foreach ($members as $m): ?>
                      <span class="mno-obj-chip mno-obj-chip-addr"><?= esc($m) ?></span>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <span style="color:var(--text-muted); font-size:0.75rem;">(Empty)</span>
                  <?php endif; ?>
                </td>
                <td style="color:var(--text-muted); font-size:0.75rem;"><?= esc($ag['comment'] ?? '—') ?></td>
                <td style="text-align:right;">
                  <form method="POST" action="<?= base_url('firewall/address-groups/' . esc($ag['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Delete address group <?= esc($ag['name']) ?>?');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-red); padding:2px 6px;">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- TAB 3: SERVICES & KNOWN PORTS -->
      <div id="objTabServices" style="display:none;">
        <div style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:14px; margin-bottom:20px;">
          <div style="font-weight:600; font-size:0.875rem; margin-bottom:10px; color:var(--color-cyan);">+ Add Custom Service / Port</div>
          <form method="POST" action="<?= base_url('firewall/services/store') ?>" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)) 100px; gap:10px; align-items:flex-end;">
            <?= csrf_field() ?>
            <div>
              <label style="font-size:0.7rem; color:var(--text-secondary); display:block; margin-bottom:4px;">Service Name</label>
              <input type="text" name="name" required placeholder="e.g. GRAFANA_3000" style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
            </div>
            <div>
              <label style="font-size:0.7rem; color:var(--text-secondary); display:block; margin-bottom:4px;">Protocol</label>
              <select name="protocol" style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
                <option value="TCP">TCP</option>
                <option value="UDP">UDP</option>
                <option value="TCP/UDP">TCP/UDP</option>
                <option value="ICMP">ICMP</option>
                <option value="IP">IP</option>
              </select>
            </div>
            <div>
              <label style="font-size:0.7rem; color:var(--text-secondary); display:block; margin-bottom:4px;">TCP Port(s)</label>
              <input type="text" name="tcp_port" placeholder="3000 or 8000-8080" style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
            </div>
            <div>
              <label style="font-size:0.7rem; color:var(--text-secondary); display:block; margin-bottom:4px;">UDP Port(s)</label>
              <input type="text" name="udp_port" placeholder="e.g. 5000" style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
            </div>
            <div>
              <label style="font-size:0.7rem; color:var(--text-secondary); display:block; margin-bottom:4px;">Category</label>
              <select name="category" style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
                <option value="General">General</option>
                <option value="Web Access">Web Access</option>
                <option value="Remote Access">Remote Access</option>
                <option value="Network Services">Network Services</option>
                <option value="Email">Email</option>
                <option value="Database">Database</option>
                <option value="Tunneling">Tunneling</option>
                <option value="VoIP">VoIP</option>
              </select>
            </div>
            <div>
              <button type="submit" class="btn btn-primary" style="width:100%; padding:6px 10px; font-size:0.8rem;">Add</button>
            </div>
          </form>
        </div>

        <div style="max-height:400px; overflow-y:auto;">
          <table class="mno-table">
            <thead>
              <tr>
                <th>Service Name</th>
                <th>Category</th>
                <th>Protocol</th>
                <th>TCP Port</th>
                <th>UDP Port</th>
                <th>Comment</th>
                <th style="text-align:right;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($services as $s): ?>
                <tr>
                  <td>
                    <span class="mno-obj-chip mno-obj-chip-svc">⚡ <?= esc($s['name']) ?></span>
                    <?php if (!empty($s['is_system'])): ?>
                      <span class="badge badge-muted" style="font-size:0.6rem;">SYSTEM</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge badge-info" style="font-size:0.68rem;"><?= esc($s['category']) ?></span>
                  </td>
                  <td style="font-family:var(--font-mono); font-weight:600;"><?= esc($s['protocol']) ?></td>
                  <td style="font-family:var(--font-mono); color:var(--text-primary);"><?= esc($s['tcp_port'] ?: '—') ?></td>
                  <td style="font-family:var(--font-mono); color:var(--text-primary);"><?= esc($s['udp_port'] ?: '—') ?></td>
                  <td style="color:var(--text-muted); font-size:0.75rem;"><?= esc($s['comment'] ?? '—') ?></td>
                  <td style="text-align:right;">
                    <?php if (empty($s['is_system'])): ?>
                      <form method="POST" action="<?= base_url('firewall/services/' . esc($s['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Delete service <?= esc($s['name']) ?>?');">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-red); padding:2px 6px;">Delete</button>
                      </form>
                    <?php else: ?>
                      <span style="font-size:0.7rem; color:var(--text-muted);">Protected</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- TAB 4: SERVICE GROUPS -->
      <div id="objTabServiceGroups" style="display:none;">
        <div style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:14px; margin-bottom:20px;">
          <div style="font-weight:600; font-size:0.875rem; margin-bottom:10px; color:var(--color-cyan);">+ Add New Service Group</div>
          <form method="POST" action="<?= base_url('firewall/service-groups/store') ?>">
            <?= csrf_field() ?>
            <div class="mno-modal-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
              <div>
                <label style="font-size:0.7rem; color:var(--text-secondary); display:block; margin-bottom:4px;">Group Name</label>
                <input type="text" name="name" required placeholder="e.g. Media_Streaming_Ports" style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
              </div>
              <div>
                <label style="font-size:0.7rem; color:var(--text-secondary); display:block; margin-bottom:4px;">Comment</label>
                <input type="text" name="comment" placeholder="Optional notes" style="width:100%; background:var(--color-surface-3); border:1px solid var(--color-border); color:var(--text-primary); padding:6px 10px; border-radius:var(--radius-sm); font-size:0.8rem;">
              </div>
            </div>
            <div style="margin-bottom:12px;">
              <label style="font-size:0.7rem; color:var(--text-secondary); display:block; margin-bottom:4px;">Select Member Services</label>
              <div style="max-height:140px; overflow-y:auto; background:var(--color-surface-3); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:8px; display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:6px;">
                <?php foreach ($services as $s): ?>
                  <?php if ($s['name'] !== 'ALL'): ?>
                    <label style="display:flex; align-items:center; gap:6px; font-size:0.75rem; cursor:pointer;">
                      <input type="checkbox" name="members[]" value="<?= esc($s['name']) ?>">
                      <span style="font-family:var(--font-mono);"><?= esc($s['name']) ?> (<?= esc($s['protocol']) ?>)</span>
                    </label>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Create Service Group</button>
          </form>
        </div>

        <table class="mno-table">
          <thead>
            <tr>
              <th>Group Name</th>
              <th>Member Services</th>
              <th>Comment</th>
              <th style="text-align:right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($serviceGroups as $sg): ?>
              <?php $members = is_string($sg['members']) ? json_decode($sg['members'], true) : $sg['members']; ?>
              <tr>
                <td>
                  <span class="mno-obj-chip mno-obj-chip-grp">📦 <?= esc($sg['name']) ?></span>
                </td>
                <td>
                  <?php if (!empty($members)): ?>
                    <?php foreach ($members as $m): ?>
                      <span class="mno-obj-chip mno-obj-chip-svc"><?= esc($m) ?></span>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <span style="color:var(--text-muted); font-size:0.75rem;">(Empty)</span>
                  <?php endif; ?>
                </td>
                <td style="color:var(--text-muted); font-size:0.75rem;"><?= esc($sg['comment'] ?? '—') ?></td>
                <td style="text-align:right;">
                  <form method="POST" action="<?= base_url('firewall/service-groups/' . esc($sg['id']) . '/delete') ?>" style="display:inline;" onsubmit="return confirm('Delete service group <?= esc($sg['name']) ?>?');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-red); padding:2px 6px;">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    </div>

    <div class="mno-modal-footer">
      <button type="button" class="btn btn-ghost" onclick="closeObjectsModal()">Close</button>
    </div>
  </div>
</div>

<!-- Auto-save indicator for drag & drop reordering -->
<div id="reorderStatus" role="status" aria-live="polite"></div>

<!-- Right-click menu for policy rows (items are built in JS) -->
<div id="policyContextMenu" role="menu" aria-label="Policy actions"></div>

<script>
// Policy rows for the context menu, keyed by id
window.MICRON_POLICIES      = <?= json_encode($policyData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.MICRON_POLICY_PERMS  = {
  edit:    <?= $canEdit ? 'true' : 'false' ?>,
  delete:  <?= $canDelete ? 'true' : 'false' ?>,
  reorder: <?= $canReorder ? 'true' : 'false' ?>
};
</script>

<?= $this->include('partials/picker_search') ?>

<script>
// ============================================================
// Policy & Objects Interactive UI Logic
// ============================================================

function togglePairSection(header) {
  const content = header.nextElementSibling;
  const chevron = header.querySelector('.mno-chevron');
  if (content.style.display === 'none') {
    content.style.display = 'block';
    chevron.style.transform = 'rotate(0deg)';
  } else {
    content.style.display = 'none';
    chevron.style.transform = 'rotate(-90deg)';
  }
}

// ============================================================
// Drag & drop policy reordering (auto-saved)
// ============================================================
let _dragRow = null;
let _dragTbody = null;

function initPolicyDragDrop() {
  document.querySelectorAll('.policy-drag-handle').forEach(handle => {
    handle.addEventListener('dragstart', e => {
      _dragRow = handle.closest('tr');
      if (!_dragRow) return;
      _dragTbody = _dragRow.closest('tbody');
      _dragRow.classList.add('policy-dragging');
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', _dragRow.dataset.policyId || ''); } catch (err) {}
    });

    handle.addEventListener('dragend', () => {
      if (_dragRow) _dragRow.classList.remove('policy-dragging');
      clearDropMarks();
      _dragRow = null;
      _dragTbody = null;
    });
  });

  // Reordering is confined to one interface-pair section at a time
  document.querySelectorAll('.policy-table tbody').forEach(tbody => {
    tbody.addEventListener('dragover', e => {
      if (!_dragRow || tbody !== _dragTbody) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';

      const row = e.target.closest('tr');
      clearDropMarks();
      if (!row || row === _dragRow || row.classList.contains('system-stealth-row') || row.classList.contains('system-cleanup-row')) return;

      const rect = row.getBoundingClientRect();
      row.classList.add((e.clientY - rect.top) > rect.height / 2
        ? 'policy-drag-over-bottom'
        : 'policy-drag-over-top');
    });

    tbody.addEventListener('drop', e => {
      if (!_dragRow || tbody !== _dragTbody) return;
      e.preventDefault();

      const row = e.target.closest('tr');
      clearDropMarks();
      if (!row || row === _dragRow || row.classList.contains('system-stealth-row') || row.classList.contains('system-cleanup-row')) return;

      const rect = row.getBoundingClientRect();
      const after = (e.clientY - rect.top) > rect.height / 2;
      tbody.insertBefore(_dragRow, after ? row.nextSibling : row);
      _dragRow.classList.add('policy-row-moved');
      savePolicyOrder();
    });
  });
}

function clearDropMarks() {
  document.querySelectorAll('.policy-drag-over-top, .policy-drag-over-bottom').forEach(r => {
    r.classList.remove('policy-drag-over-top', 'policy-drag-over-bottom');
  });
}

// Policy IDs in visual order (all sections, top to bottom)
function currentPolicyOrder() {
  return Array.from(document.querySelectorAll('.policy-table tbody tr'))
    .map(r => r.dataset.policyId)
    .filter(Boolean);
}

// The CSRF cookie is refreshed by every response, so read it rather than a
// possibly-stale form field (token regeneration is enabled).
function csrfToken() {
  const m = document.cookie.match(/(?:^|;\s*)csrf_cookie_name=([^;]+)/);
  if (m) return decodeURIComponent(m[1]);
  const f = document.querySelector('input[name="csrf_test_name"]');
  return f ? f.value : '';
}

function savePolicyOrder() {
  const body = new URLSearchParams();
  body.append('csrf_test_name', csrfToken());
  currentPolicyOrder().forEach(id => body.append('order[]', id));

  showReorderStatus('Saving order…', false);

  MicronScheduler.fetch('<?= base_url('firewall/rules/reorder') ?>', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: body.toString(),
    credentials: 'same-origin'
  })
    .then(r => r.json())
    .then(res => {
      if (!res || !res.success) throw new Error((res && res.error) || 'Reorder failed');

      // Reflect the new global sequence numbers without a reload
      document.querySelectorAll('.policy-table tbody tr').forEach(row => {
        const seq = res.sequence && res.sequence[row.dataset.policyId];
        if (seq) {
          const el = row.querySelector('.policy-seq');
          if (el) el.textContent = '#' + seq;
        }
      });

      showReorderStatus('Order saved ✓', false, true);
    })
    .catch(err => showReorderStatus('Could not save order: ' + err.message, true));
}

let _reorderStatusTimer = null;
function showReorderStatus(message, isError, autoHide) {
  const el = document.getElementById('reorderStatus');
  if (!el) return;
  el.textContent = message;
  el.style.display = 'block';
  el.style.borderColor = isError ? 'var(--color-red)' : 'var(--color-border-bright)';
  el.style.color = isError ? 'var(--color-red)' : 'var(--text-primary)';
  clearTimeout(_reorderStatusTimer);
  if (autoHide) {
    _reorderStatusTimer = setTimeout(() => { el.style.display = 'none'; }, 1800);
  }
}

document.addEventListener('DOMContentLoaded', function () {
  initPolicyDragDrop();
  initPolicyContextMenu();
  initPicks();
});

// ============================================================
// Multi-select pickers (source / destination / service)
// ============================================================
const PICK_ANY   = { polSources: 'all', polDestinations: 'all', polServices: 'ALL' };
const PICK_CHIPS = { polSources: 'polSourcesChips', polDestinations: 'polDestinationsChips', polServices: 'polServicesChips' };
const PICK_COUNT = { polSources: 'polSourcesCount', polDestinations: 'polDestinationsCount', polServices: 'polServicesCount' };

/**
 * A rule's object list, read from the JSON it was stored with. A legacy row can
 * carry nothing, so an empty result falls back to the sentinel rather than
 * leaving the picker in its "nothing selected" error state.
 */
function pickerObjects(raw, fallback) {
  let list = [];
  try { list = JSON.parse(raw || '[]'); } catch (e) { list = [raw || fallback]; }
  if (!Array.isArray(list)) list = [fallback];
  const clean = list
    .filter(v => v !== null && v !== undefined && String(v) !== '')
    .map(v => String(v));
  return clean.length ? clean : [fallback];
}

function initPicks() {
  Object.keys(PICK_ANY).forEach(id => {
    const sel = document.getElementById(id);
    if (!sel || sel.dataset.pickReady) return;
    sel.dataset.pickReady = '1';

    // "all" / "ALL" is exclusive. Without this, Ctrl-clicking a specific object
    // while "all" is still selected leaves the policy matching the whole
    // internet — the single most confusing thing about this form.
    sel.addEventListener('change', () => {
      const any    = PICK_ANY[id];
      const anyOpt = [...sel.options].find(o => o.value === any);
      const now    = [...sel.selectedOptions].map(o => o.value);
      let prev = [];
      try { prev = JSON.parse(sel.dataset.prevSel || '[]'); } catch (e) {}
      const added = now.filter(v => !prev.includes(v));

      if (anyOpt) {
        if (added.includes(any)) {
          [...sel.options].forEach(o => { o.selected = (o.value === any); });
        } else if (now.includes(any) && now.length > 1) {
          anyOpt.selected = false;
        }
      }
      renderPick(id);
    });

    renderPick(id);
  });

  // The search box, its no-matches note and the keyboard support (↑ / ↓ to walk
  // the list, Space to toggle, Enter to pick) come from the shared picker helper
  // in partials/picker_search.php, so this dialog behaves exactly like the
  // address, service-database and policy-routing pickers.
  // All three are paged from the server: the page holds only the first
  // screenful of objects, and typing asks the endpoint for the rest — so the
  // dialog stays fast however many objects or services the appliance holds.
  MicronPickerSearch.init([
    { select: 'polSources',      search: 'polSourcesSearch',      empty: 'polSourcesEmpty',      any: 'all', source: <?= json_encode(\App\Libraries\ObjectSearch::pickerSource('addressObjects', base_url('firewall/objects/search')), JSON_UNESCAPED_SLASHES) ?> },
    { select: 'polDestinations', search: 'polDestinationsSearch', empty: 'polDestinationsEmpty', any: 'all', source: <?= json_encode(\App\Libraries\ObjectSearch::pickerSource('addressObjects', base_url('firewall/objects/search')), JSON_UNESCAPED_SLASHES) ?> },
    { select: 'polServices',     search: 'polServicesSearch',     empty: 'polServicesEmpty',     any: 'ALL', source: <?= json_encode(\App\Libraries\ObjectSearch::pickerSource('firewallServices', base_url('firewall/objects/search')), JSON_UNESCAPED_SLASHES) ?> },
  ]);
}

function renderPick(id) {
  const sel = document.getElementById(id);
  if (!sel) return;

  const any    = PICK_ANY[id];
  const picked = [...sel.selectedOptions];
  const isAny  = picked.length === 1 && picked[0].value === any;
  const chips  = document.getElementById(PICK_CHIPS[id]);
  const count  = document.getElementById(PICK_COUNT[id]);

  if (count) {
    count.innerHTML = isAny
      ? 'Matching <b>any</b>'
      : picked.length
        ? '<b>' + picked.length + '</b> selected'
        : '<b style="color:var(--color-red);">nothing selected</b>';
  }

  if (chips) {
    chips.innerHTML = '';
    if (!isAny) {
      picked.forEach(opt => {
        const chip = document.createElement('span');
        chip.className = 'mno-chip';
        chip.textContent = (opt.textContent.trim().replace(/\s*\(.*\)$/, '')) || opt.value;
        chip.title = 'Remove ' + opt.textContent.trim();
        chip.addEventListener('click', () => { opt.selected = false; renderPick(id); });
        chips.appendChild(chip);
      });
    }
  }

  sel.dataset.prevSel = JSON.stringify(picked.map(o => o.value));
}

function pickClear(id) {
  const sel = document.getElementById(id);
  if (!sel) return;
  [...sel.options].forEach(o => { o.selected = false; });
  renderPick(id);
}

/** Reset a modal's scroll position. Must run *after* the dialog is displayed,
 *  since assigning scrollTop on a display:none element is a no-op. */
function scrollModalTop(modalId) {
  const modal = document.getElementById(modalId);
  const body  = modal && modal.querySelector('.mno-modal-body');
  if (body) body.scrollTop = 0;
}

/** Selecting an option makes the browser scroll that <select> to reveal it, so a
 *  reopened dialog can land mid-list. Put every picker back at the top. */
function resetPickScroll() {
  Object.keys(PICK_ANY).forEach(id => {
    const sel = document.getElementById(id);
    if (sel) sel.scrollTop = 0;
  });
}

// ============================================================
// Right-click context menu for policy rows
// ============================================================
let _menuRow = null;

function initPolicyContextMenu() {
  document.addEventListener('contextmenu', e => {
    const row = e.target.closest('.policy-table tbody tr');
    if (!row || !row.dataset.policyId) return;
    e.preventDefault();
    openPolicyMenu(row, e.clientX, e.clientY);
  });

  document.addEventListener('click', e => {
    const menu = document.getElementById('policyContextMenu');
    if (menu && menu.style.display === 'block' && !menu.contains(e.target)) closePolicyMenu();
  });

  document.addEventListener('keydown', e => { if (e.key === 'Escape') closePolicyMenu(); });
  window.addEventListener('resize', closePolicyMenu);
  window.addEventListener('scroll', closePolicyMenu, true);
}

function closePolicyMenu() {
  const menu = document.getElementById('policyContextMenu');
  if (menu) menu.style.display = 'none';
  if (_menuRow) { _menuRow.classList.remove('policy-menu-target'); _menuRow = null; }
}

function policyMenuItem(iconPath, label, handler, extraClass) {
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'policy-menu-item' + (extraClass ? ' ' + extraClass : '');
  btn.setAttribute('role', 'menuitem');
  btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
    + ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="' + iconPath + '"/></svg><span></span>';
  btn.querySelector('span').textContent = label;
  btn.addEventListener('click', ev => { ev.stopPropagation(); closePolicyMenu(); handler(); });
  return btn;
}

function policyMenuSep() {
  const d = document.createElement('div');
  d.className = 'policy-menu-sep';
  return d;
}

function policyCloneUrl(id) {
  return '<?= base_url('firewall/policies') ?>/' + encodeURIComponent(id) + '/clone';
}

function openPolicyMenu(row, x, y) {
  const menu = document.getElementById('policyContextMenu');
  if (!menu) return;

  const id    = row.dataset.policyId;
  const rule  = (window.MICRON_POLICIES || {})[id] || {};
  const perms = window.MICRON_POLICY_PERMS || {};

  closePolicyMenu();
  _menuRow = row;
  row.classList.add('policy-menu-target');
  menu.innerHTML = '';

  const title = document.createElement('div');
  title.className = 'policy-menu-title';
  const strong = document.createElement('div');
  strong.textContent = rule.name || ('Policy ' + id);
  const small = document.createElement('small');
  small.textContent = '#' + (rule.sequence || '?') + '  ·  '
    + String(rule.src_interface || 'any').toUpperCase() + ' → ' + String(rule.dst_interface || 'any').toUpperCase();
  title.appendChild(strong);
  title.appendChild(small);
  menu.appendChild(title);

  if (perms.edit) {
    menu.appendChild(policyMenuItem('M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z', 'Edit Policy…', () => editPolicy(rule)));
    menu.appendChild(policyMenuItem('M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z', 'Clone Policy', () => policyAction(policyCloneUrl(id), {})));
    menu.appendChild(policyMenuItem('M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4', 'Reverse Clone (swap source ⇄ destination)', () => policyAction(policyCloneUrl(id), { reverse: 1 })));
  }

  if (perms.reorder) {
    menu.appendChild(policyMenuSep());
    menu.appendChild(policyMenuItem('M5 10l7-7m0 0l7 7m-7-7v18', 'Move to Top', () => movePolicyRow(row, 'top')));
    menu.appendChild(policyMenuItem('M19 14l-7 7m0 0l-7-7m7 7V3', 'Move to Bottom', () => movePolicyRow(row, 'bottom')));
  }

  if (perms.edit) {
    menu.appendChild(policyMenuSep());
    const label = (parseInt(rule.enabled, 10) === 1) ? 'Disable Policy' : 'Enable Policy';
    menu.appendChild(policyMenuItem('M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636', label,
      () => policyAction('<?= base_url('firewall/policies') ?>/' + encodeURIComponent(id) + '/toggle', {})));
  }

  menu.appendChild(policyMenuSep());
  menu.appendChild(policyMenuItem('M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4', 'Copy Policy Name', () => copyPolicyName(rule.name || id)));

  if (perms.delete) {
    menu.appendChild(policyMenuItem('M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16',
      'Delete Policy…', () => {
        if (confirm('Delete firewall policy "' + (rule.name || id) + '"?')) {
          policyAction('<?= base_url('firewall/policies') ?>/' + encodeURIComponent(id) + '/delete', {});
        }
      }, 'danger'));
  }

  // Reveal, then keep the menu inside the viewport
  menu.style.display = 'block';
  menu.style.left = '0px';
  menu.style.top = '0px';
  const rect = menu.getBoundingClientRect();
  menu.style.left = Math.max(8, Math.min(x, window.innerWidth - rect.width - 8)) + 'px';
  menu.style.top  = Math.max(8, Math.min(y, window.innerHeight - rect.height - 8)) + 'px';
}

// Full-page POST with a fresh CSRF token
function policyAction(url, extra) {
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = url;
  form.style.display = 'none';

  const add = (name, value) => {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    form.appendChild(input);
  };

  add('csrf_test_name', csrfToken());
  Object.keys(extra || {}).forEach(k => add(k, extra[k]));

  document.body.appendChild(form);
  form.submit();
}

function movePolicyRow(row, where) {
  const tbody = row.closest('tbody');
  if (!tbody) return;
  const stealthRow = tbody.querySelector('.system-stealth-row');
  const cleanupRow = tbody.querySelector('.system-cleanup-row');
  if (where === 'top') {
    if (stealthRow && stealthRow.nextSibling) {
      tbody.insertBefore(row, stealthRow.nextSibling);
    } else {
      tbody.insertBefore(row, tbody.firstElementChild);
    }
  } else {
    if (cleanupRow) {
      tbody.insertBefore(row, cleanupRow);
    } else {
      tbody.appendChild(row);
    }
  }
  row.classList.add('policy-row-moved');
  savePolicyOrder();
}

function copyPolicyName(name) {
  const done  = () => showReorderStatus('Copied "' + name + '" ✓', false, true);
  const fail  = () => showReorderStatus('Copy failed — select the name and press Ctrl+C', true, true);

  // The appliance is usually served over plain HTTP, which is not a secure
  // context, so navigator.clipboard does not exist. Fall back to the legacy
  // textarea + execCommand('copy') trick in that case.
  if (window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(name).then(done).catch(fail);
    return;
  }

  try {
    const ta = document.createElement('textarea');
    ta.value = name;
    ta.setAttribute('readonly', 'readonly');
    ta.style.position = 'fixed';
    ta.style.top = '-1000px';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    ta.setSelectionRange(0, ta.value.length);
    const ok = document.execCommand('copy');
    document.body.removeChild(ta);
    ok ? done() : fail();
  } catch (e) {
    fail();
  }
}

function filterPolicies() {
  const query = document.getElementById('mnoSearchInput').value.toLowerCase();
  const rows = document.querySelectorAll('.policy-table tbody tr');

  rows.forEach(row => {
    const text = row.innerText.toLowerCase();
    row.style.display = text.includes(query) ? '' : 'none';
  });
}

function openCreatePolicyModal() {
  document.getElementById('policyModalTitle').innerText = 'New Firewall Policy';
  document.getElementById('policyForm').action = '<?= base_url('firewall/policies/store') ?>';
  document.getElementById('policyId').value = '';
  document.getElementById('polName').value = '';
  document.getElementById('actAccept').checked = true;
  document.getElementById('polNat').checked = true;
  document.getElementById('polDnat').value = '';
  document.getElementById('polSnatPool').value = '';
  document.getElementById('profIps').checked = false;
  document.getElementById('profAv').checked = false;
  document.getElementById('profWeb').checked = false;
  document.getElementById('polSchedule').value = 'always';
  document.getElementById('polDesc').value = '';

  // Reset multi-selects to default
  const srcSelect = document.getElementById('polSources');
  for (let i = 0; i < srcSelect.options.length; i++) {
    srcSelect.options[i].selected = (srcSelect.options[i].value === 'all');
  }
  const dstSelect = document.getElementById('polDestinations');
  for (let i = 0; i < dstSelect.options.length; i++) {
    dstSelect.options[i].selected = (dstSelect.options[i].value === 'all');
  }
  const svcSelect = document.getElementById('polServices');
  for (let i = 0; i < svcSelect.options.length; i++) {
    svcSelect.options[i].selected = (svcSelect.options[i].value === 'ALL');
  }

  ['polSources', 'polDestinations', 'polServices'].forEach(renderPick);

  MicronPickerSearch.reset();

  document.getElementById('policyModal').classList.add('active');
  // Both resets must come *after* the dialog is displayed; scrollTop on a
  // display:none element is silently ignored.
  scrollModalTop('policyModal');
  resetPickScroll();
}

function editPolicy(data) {
  document.getElementById('policyModalTitle').innerText = 'Edit Firewall Policy #' + (data.sequence || data.id);
  document.getElementById('policyForm').action = '<?= base_url('firewall/policies') ?>/' + encodeURIComponent(data.id) + '/update';
  document.getElementById('policyId').value = data.id;
  document.getElementById('polName').value = data.name || '';
  document.getElementById('polSrcIface').value = data.src_interface || data.interface_id || 'lan';
  document.getElementById('polDstIface').value = data.dst_interface || 'wan';
  
  if (data.action === 'accept' || data.action === 'pass') {
    document.getElementById('actAccept').checked = true;
  } else {
    document.getElementById('actDeny').checked = true;
  }

  document.getElementById('polNat').checked = parseInt(data.nat_enabled) === 1;
  document.getElementById('polDnat').value = data.dnat_name || '';
  document.getElementById('polSnatPool').value = data.snat_pool_name || '';

  // Profiles
  let secProfiles = [];
  try {
    secProfiles = JSON.parse(data.security_profiles || '[]');
  } catch(e) {}
  document.getElementById('profIps').checked = secProfiles.includes('ips');
  document.getElementById('profAv').checked = secProfiles.includes('av');
  document.getElementById('profWeb').checked = secProfiles.includes('webfilter');

  document.getElementById('polLog').value = data.log_traffic || 'all';
  document.getElementById('polSchedule').value = data.schedule || 'always';
  document.getElementById('polDesc').value = data.description || '';

  // Parse multi-selects. A legacy rule may carry no objects at all, in which
  // case the picker opens on the sentinel.
  const srcObjects = pickerObjects(data.sources,      PICK_ANY.polSources);
  const dstObjects = pickerObjects(data.destinations, PICK_ANY.polDestinations);
  const svcObjects = pickerObjects(data.services,     PICK_ANY.polServices);

  // Start from a clean page — no query left over from the last edit — then hand
  // each picker the objects this rule carries. Source and destination page from
  // the server, so those objects are usually not among the rows in the page:
  // setSelection resolves them on the endpoint and re-applies them here, which
  // is what keeps them in the form that is about to be submitted.
  MicronPickerSearch.reset();
  MicronPickerSearch.setSelection('polSources', srcObjects);
  MicronPickerSearch.setSelection('polDestinations', dstObjects);
  MicronPickerSearch.setSelection('polServices', svcObjects);

  ['polSources', 'polDestinations', 'polServices'].forEach(renderPick);

  document.getElementById('policyModal').classList.add('active');
  scrollModalTop('policyModal');
  resetPickScroll();
}

function closePolicyModal() {
  document.getElementById('policyModal').classList.remove('active');
}

function openObjectsModal(tab = 'addresses') {
  document.getElementById('objectsModal').classList.add('active');
  switchObjTab(tab);
  scrollModalTop('objectsModal');
}

function closeObjectsModal() {
  document.getElementById('objectsModal').classList.remove('active');
}

function switchObjTab(tab) {
  const tabs = ['addresses', 'addressGroups', 'services', 'serviceGroups'];
  tabs.forEach(t => {
    const el = document.getElementById('objTab' + t.charAt(0).toUpperCase() + t.slice(1));
    const btn = document.getElementById('tabBtn' + t.charAt(0).toUpperCase() + t.slice(1));
    if (el) el.style.display = (t === tab) ? 'block' : 'none';
    if (btn) {
      if (t === tab) {
        btn.classList.add('active');
      } else {
        btn.classList.remove('active');
      }
    }
  });
}

// Check URL query for tab open
window.addEventListener('DOMContentLoaded', () => {
  const urlParams = new URLSearchParams(window.location.search);
  const tab = urlParams.get('tab');
  if (tab) {
    if (tab === 'addresses') openObjectsModal('addresses');
    else if (tab === 'address-groups') openObjectsModal('addressGroups');
    else if (tab === 'services') openObjectsModal('services');
    else if (tab === 'service-groups') openObjectsModal('serviceGroups');
  }
});

// Import CSV Modal Logic
function openImportModal(type = 'rules') {
  const modal = document.getElementById('csvImportModal');
  if (modal) { modal.classList.add('active'); scrollModalTop('csvImportModal'); }
}
function closeImportModal() {
  const modal = document.getElementById('csvImportModal');
  if (modal) modal.classList.remove('active');
}
</script>

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
        <span style="font-weight:700; font-size:1.05rem; color:var(--text-primary);">Import Firewall Policies (CSV)</span>
      </div>
      <button type="button" onclick="closeImportModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem;">&times;</button>
    </div>

    <form method="POST" action="<?= base_url('firewall/rules/import-csv') ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="mno-modal-body" style="padding:20px;">
        <p style="font-size:0.85rem; color:var(--text-secondary); margin-bottom:16px;">
          Upload a CSV file containing firewall policies. Supported columns: <code>name, src_interface, dst_interface, sources, destinations, services, action, nat_enabled, schedule, log_traffic, security_profiles, enabled, description</code>.
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
              <span>Update existing policies (by name)</span>
            </label>
            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-size:0.8125rem;">
              <input type="radio" name="mode" value="skip">
              <span>Skip existing</span>
            </label>
          </div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.75rem;">
          <a href="<?= base_url('firewall/rules/export-csv') ?>" style="color:var(--color-cyan); text-decoration:none;">
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

<?= $this->endSection() ?>
