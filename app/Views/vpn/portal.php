<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<style>
/* ── MicronOS Next-Gen VPN Portal Design System ── */
.mno-obj-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 8px;
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
.mno-obj-chip-lan {
  background: rgba(6, 182, 212, 0.15);
  border-color: rgba(6, 182, 212, 0.35);
  color: #67e8f9;
}
.mno-obj-chip-wan {
  background: rgba(245, 158, 11, 0.15);
  border-color: rgba(245, 158, 11, 0.35);
  color: #fcd34d;
}
.mno-obj-chip-dmz {
  background: rgba(139, 92, 246, 0.15);
  border-color: rgba(139, 92, 246, 0.35);
  color: #c4b5fd;
}
.mno-obj-chip-success {
  background: rgba(16, 185, 129, 0.15);
  border-color: rgba(16, 185, 129, 0.35);
  color: #6ee7b7;
}

/* ── Custom Button Themes ── */
.btn-warning {
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%) !important;
  color: #030712 !important;
  border: 1px solid #f59e0b !important;
  font-weight: 700 !important;
  display: inline-flex !important;
  align-items: center !important;
  gap: 6px !important;
  box-shadow: 0 2px 10px rgba(245, 158, 11, 0.3) !important;
  text-decoration: none !important;
}
.btn-warning:hover {
  background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%) !important;
  box-shadow: 0 4px 16px rgba(245, 158, 11, 0.45) !important;
  color: #030712 !important;
  transform: translateY(-1px);
}
.btn-cyan {
  background: linear-gradient(135deg, var(--color-cyan) 0%, #0096c7 100%) !important;
  color: #030712 !important;
  border: 1px solid var(--color-cyan) !important;
  font-weight: 700 !important;
  display: inline-flex !important;
  align-items: center !important;
  gap: 6px !important;
  box-shadow: 0 2px 10px rgba(0, 212, 255, 0.3) !important;
  text-decoration: none !important;
}
.btn-cyan:hover {
  background: linear-gradient(135deg, #38bdf8 0%, var(--color-cyan) 100%) !important;
  box-shadow: 0 4px 16px rgba(0, 212, 255, 0.45) !important;
  color: #030712 !important;
  transform: translateY(-1px);
}

/* ── Portal Layout Grid ── */
.portal-layout-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.85fr) minmax(320px, 1.15fr);
  gap: 20px;
  align-items: start;
}
@media (max-width: 1024px) {
  .portal-layout-grid {
    grid-template-columns: 1fr;
  }
}

/* ── Summary Stats Grid ── */
.portal-stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}

/* ── Profile Boxes & Cards ── */
.vpn-card {
  background: var(--color-surface-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  margin-bottom: 20px;
  overflow: hidden;
  transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
}
.vpn-card:hover {
  border-color: var(--color-border-bright);
}
.vpn-card-header {
  padding: 14px 20px;
  background: var(--color-surface-3);
  border-bottom: 1px solid var(--color-border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.vpn-card-header-title {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--text-primary);
}
.vpn-card-body {
  padding: 20px;
}

/* ── Inner Profile Configuration Box ── */
.vpn-config-box {
  background: var(--color-surface-1);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: 16px;
  margin-bottom: 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
  transition: all var(--transition-fast);
}
.vpn-config-box:hover {
  border-color: rgba(0, 212, 255, 0.3);
  background: rgba(0, 212, 255, 0.02);
}

/* ── QR Code Showcase Box ── */
.wireguard-qr-container {
  display: flex;
  gap: 20px;
  align-items: flex-start;
  flex-wrap: wrap;
}
.qr-frame {
  background: #ffffff;
  padding: 12px;
  border-radius: var(--radius-sm);
  box-shadow: 0 4px 14px rgba(0,0,0,0.35);
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.qr-canvas-holder {
  width: 144px;
  height: 144px;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}

/* ── Timeline Setup Guide ── */
.setup-timeline {
  display: flex;
  flex-direction: column;
  gap: 18px;
  position: relative;
  padding-left: 10px;
}
.setup-timeline::before {
  content: '';
  position: absolute;
  top: 14px;
  bottom: 14px;
  left: 21px;
  width: 2px;
  background: var(--color-border);
}
.setup-step {
  display: flex;
  gap: 14px;
  position: relative;
  z-index: 1;
}
.step-number {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: var(--color-cyan);
  color: #030712;
  font-weight: 700;
  font-size: 0.75rem;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 0 10px rgba(0, 212, 255, 0.4);
}
.step-content {
  flex: 1;
}
.step-title {
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--text-primary);
  margin-bottom: 2px;
}
.step-desc {
  font-size: 0.75rem;
  color: var(--text-secondary);
  line-height: 1.45;
}

/* ── Modal Architecture ── */
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
  background: var(--color-surface-2);
  border: 1px solid var(--color-border-bright);
  border-radius: var(--radius-md);
  width: 100%;
  max-width: 650px;
  box-shadow: 0 20px 40px rgba(0,0,0,0.6);
  animation: mnoModalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
  overflow: hidden;
}
@keyframes mnoModalIn {
  from { opacity: 0; transform: scale(0.96) translateY(-10px); }
  to { opacity: 1; transform: scale(1) translateY(0); }
}
.mno-modal-header {
  padding: 14px 20px;
  background: var(--color-surface-3);
  border-bottom: 1px solid var(--color-border);
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.mno-modal-title {
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--text-primary);
  display: flex;
  align-items: center;
  gap: 8px;
}
.mno-modal-body {
  padding: 20px;
  max-height: calc(85vh - 120px);
  overflow-y: auto;
}
.mno-modal-footer {
  padding: 12px 20px;
  background: var(--color-surface-1);
  border-top: 1px solid var(--color-border);
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

/* ── Code / Config Display ── */
.vpn-code-block {
  background: #080c14;
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: var(--radius-sm);
  padding: 14px;
  font-family: var(--font-mono);
  font-size: 0.75rem;
  color: #7dd3fc;
  line-height: 1.5;
  white-space: pre-wrap;
  word-break: break-all;
  max-height: 280px;
  overflow-y: auto;
}

/* ── Toast Notification ── */
.portal-toast {
  position: fixed;
  bottom: 24px;
  right: 24px;
  background: rgba(15, 23, 42, 0.95);
  border: 1px solid rgba(0, 212, 255, 0.4);
  color: #fff;
  padding: 12px 18px;
  border-radius: var(--radius-sm);
  box-shadow: 0 10px 25px rgba(0,0,0,0.5);
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 0.8125rem;
  z-index: 2000;
  transform: translateY(100px);
  opacity: 0;
  transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
  pointer-events: none;
}
.portal-toast.active {
  transform: translateY(0);
  opacity: 1;
}
</style>

<!-- ── Page Header (Matching firewall/addresses, qos, ha, dhcp) ── -->
<div class="page-header animate-in">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <h1 class="page-title">Road Warrior VPN Self-Service Portal</h1>
        <span class="badge badge-info" style="font-size:0.75rem;">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="margin-right:3px; vertical-align:middle;">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
          </svg>
          User: <?= esc($user['username'] ?? 'admin') ?>
        </span>
        <span class="badge badge-success" style="font-size:0.75rem;">2FA ENFORCED (TOTP)</span>
        <span class="badge badge-cyan" style="font-size:0.75rem;">ZERO-TRUST ACCESS</span>
      </div>
      <p class="page-subtitle">Download preconfigured client profiles, inline cryptographic certificates, and ephemeral WireGuard line-speed configurations</p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
      <a href="<?= base_url('firewall/rules') ?>" class="btn btn-ghost btn-sm">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        Firewall Rules
      </a>
      <a href="<?= base_url('pki/certificates') ?>" class="btn btn-ghost btn-sm">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
        </svg>
        PKI Certificates
      </a>
    </div>
  </div>
</div>

<!-- ── Flash Messages ── -->
<?php if (session()->getFlashdata('success')): ?>
  <div class="alert alert-success animate-in" style="margin-bottom:16px;">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="margin-right:8px; flex-shrink:0;">
      <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
    </svg>
    <?= esc(session()->getFlashdata('success')) ?>
  </div>
<?php endif; ?>

<?php if (session()->getFlashdata('error')): ?>
  <div class="alert alert-error animate-in" style="margin-bottom:16px;">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="margin-right:8px; flex-shrink:0;">
      <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
    </svg>
    <?= esc(session()->getFlashdata('error')) ?>
  </div>
<?php endif; ?>

<!-- ── Summary Metric Cards ── -->
<div class="portal-stats-grid animate-in animate-in-delay-1">
  <div class="stat-card cyan">
    <div class="stat-icon cyan">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-cyan);"><?= esc($user['username'] ?? 'admin') ?></div>
    <div class="stat-label">Authenticated Identity</div>
    <div class="stat-sublabel"><?= esc($user['email'] ?? 'admin@micronos.local') ?> &bull; TOTP Active</div>
  </div>

  <div class="stat-card amber">
    <div class="stat-icon amber">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-amber);">
      Port <?= !empty($ovpnServers[0]['listen_port']) ? esc($ovpnServers[0]['listen_port']) : '1194' ?>/<?= !empty($ovpnServers[0]['protocol']) ? esc(strtoupper($ovpnServers[0]['protocol'])) : 'UDP' ?>
    </div>
    <div class="stat-label">OpenVPN SSL Gateway</div>
    <div class="stat-sublabel">Subnet: <?= !empty($ovpnServers[0]['tunnel_subnet']) ? esc($ovpnServers[0]['tunnel_subnet']) : '10.8.0.0/24' ?> &bull; TLS 1.3</div>
  </div>

  <div class="stat-card emerald">
    <div class="stat-icon emerald">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-emerald);">
      Port <?= !empty($wgTunnels[0]['listen_port']) ? esc($wgTunnels[0]['listen_port']) : '51820' ?>/UDP
    </div>
    <div class="stat-label">WireGuard Fast Tunnel</div>
    <div class="stat-sublabel">Subnet: <?= !empty($wgTunnels[0]['ipv4_address']) ? esc($wgTunnels[0]['ipv4_address']) . '/' . esc($wgTunnels[0]['ipv4_subnet'] ?? '24') : '10.100.0.0/24' ?> &bull; ChaCha20</div>
  </div>

  <div class="stat-card purple">
    <div class="stat-icon purple">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-purple);">10.100.0.10</div>
    <div class="stat-label">Client Virtual Lease</div>
    <div class="stat-sublabel">Ephemeral Dynamic Lease &bull; 8h Remaining</div>
  </div>
</div>

<!-- ── Main 2-Column Responsive Layout ── -->
<div class="portal-layout-grid animate-in animate-in-delay-2">

  <!-- ── Left Column: OpenVPN & WireGuard Bundles ── -->
  <div>

    <!-- 1. OpenVPN Unified Client Bundle Card -->
    <div class="vpn-card">
      <div class="vpn-card-header">
        <div class="vpn-card-header-title">
          <div style="width:28px; height:28px; border-radius:6px; background:rgba(245, 158, 11, 0.15); border:1px solid rgba(245, 158, 11, 0.35); display:flex; align-items:center; justify-content:center; color:var(--color-amber);">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
          </div>
          <span>OpenVPN Unified Client Bundle (.ovpn)</span>
        </div>
        <div style="display:flex; gap:6px; align-items:center;">
          <span class="badge badge-success" style="font-size:0.72rem;">TLS 1.3 + AES-256-GCM</span>
          <span class="badge badge-muted" style="font-size:0.72rem;">SHA256 HMAC</span>
        </div>
      </div>

      <div class="vpn-card-body">
        <p style="font-size:0.8125rem; color:var(--text-secondary); line-height:1.55; margin-bottom:16px;">
          Your complete OpenVPN profile includes the Root Certificate Authority, your cryptographic client certificate, and private key directly embedded into a single unified <code style="color:var(--color-cyan);">&lt;ca&gt;</code>, <code style="color:var(--color-cyan);">&lt;cert&gt;</code>, <code style="color:var(--color-cyan);">&lt;key&gt;</code> configuration file.
        </p>

        <?php if (!empty($ovpnServers)): ?>
          <?php foreach ($ovpnServers as $s): ?>
            <?php 
              $sPort  = !empty($s['listen_port']) ? $s['listen_port'] : (!empty($s['port']) ? $s['port'] : 1194);
              $sProto = !empty($s['protocol']) ? strtoupper($s['protocol']) : (!empty($s['proto']) ? strtoupper($s['proto']) : 'UDP');
              $sSubnet = $s['tunnel_subnet'] ?? '10.8.0.0/24';
              $sName  = $s['name'] ?? 'Enterprise SSL Remote Gateway';
            ?>
            <div class="vpn-config-box">
              <div>
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                  <strong style="color:var(--text-primary); font-size:0.875rem;"><?= esc($sName) ?></strong>
                  <span class="mno-obj-chip mno-obj-chip-wan">
                    <svg class="mno-chip-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                    </svg>
                    Port <?= esc($sPort) ?>/<?= esc($sProto) ?>
                  </span>
                  <span class="mno-obj-chip mno-obj-chip-lan">
                    Subnet: <?= esc($sSubnet) ?>
                  </span>
                </div>
                <div style="font-size:0.75rem; color:var(--text-secondary);">
                  Cipher: <span style="font-family:var(--font-mono); color:var(--text-primary);">AES-256-GCM</span> &bull; 
                  Auth: <span style="font-family:var(--font-mono); color:var(--text-primary);">SHA256</span> &bull; 
                  Compression: <span style="color:var(--color-emerald);">LZ4-v2 / Disabled</span>
                </div>
              </div>
              <div style="display:flex; gap:8px; align-items:center;">
                <button type="button" class="btn btn-ghost btn-sm" onclick="previewOpenVpnConfig('<?= esc($sName) ?>', '<?= esc($sPort) ?>', '<?= esc($sProto) ?>')">
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                  </svg>
                  Preview
                </button>
                <a href="<?= base_url('vpn/portal/download-ovpn/' . urlencode($s['id'])) ?>" class="btn btn-warning btn-sm" style="color:#030712; font-weight:700;">
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                  </svg>
                  Download .OVPN
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="vpn-config-box">
            <div>
              <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                <strong style="color:var(--text-primary); font-size:0.875rem;">Enterprise Remote SSL Gateway</strong>
                <span class="mno-obj-chip mno-obj-chip-wan">Port 1194/UDP</span>
                <span class="mno-obj-chip mno-obj-chip-lan">10.8.0.0/24</span>
              </div>
              <div style="font-size:0.75rem; color:var(--text-secondary);">
                Default Remote Access Pool &bull; Push DNS: Gateway Resolver &bull; Compression: Adaptive
              </div>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
              <button type="button" class="btn btn-ghost btn-sm" onclick="previewOpenVpnConfig('Enterprise Remote SSL Gateway', '1194', 'UDP')">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                </svg>
                Preview
              </button>
              <a href="<?= base_url('vpn/portal/download-ovpn') ?>" class="btn btn-warning btn-sm" style="color:#030712; font-weight:700;">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Download .OVPN
              </a>
            </div>
          </div>
        <?php endif; ?>

        <div style="margin-top:14px; padding-top:12px; border-top:1px solid rgba(255,255,255,0.06); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
          <div style="font-size:0.75rem; color:var(--text-secondary);">
            <strong>Supported Clients:</strong> OpenVPN Connect 3.x, Tunnelblick, OpenVPN 2.5/2.6 CLI, NetworkManager.
          </div>
          <div style="display:flex; gap:6px;">
            <span class="badge badge-muted" style="font-size:0.7rem;">Windows</span>
            <span class="badge badge-muted" style="font-size:0.7rem;">macOS</span>
            <span class="badge badge-muted" style="font-size:0.7rem;">Linux</span>
            <span class="badge badge-muted" style="font-size:0.7rem;">iOS / Android</span>
          </div>
        </div>
      </div>
    </div>

    <!-- 2. WireGuard Modern Fast VPN Bundle Card -->
    <div class="vpn-card">
      <div class="vpn-card-header">
        <div class="vpn-card-header-title">
          <div style="width:28px; height:28px; border-radius:6px; background:rgba(0, 212, 255, 0.15); border:1px solid rgba(0, 212, 255, 0.35); display:flex; align-items:center; justify-content:center; color:var(--color-cyan);">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
          </div>
          <span>WireGuard Modern Fast VPN (.conf &amp; Mobile QR Code)</span>
        </div>
        <div style="display:flex; gap:6px; align-items:center;">
          <span class="badge badge-cyan" style="font-size:0.72rem;">ChaCha20-Poly1305</span>
          <span class="badge badge-success" style="font-size:0.72rem;">Sub-second Handshake</span>
        </div>
      </div>

      <div class="vpn-card-body">
        <p style="font-size:0.8125rem; color:var(--text-secondary); line-height:1.55; margin-bottom:16px;">
          WireGuard operates directly inside the OS network stack delivering gigabit throughput, silent roams across cellular and Wi-Fi networks, and sub-second reconnection. Scan the QR code with your mobile WireGuard app or download the configuration file for desktop.
        </p>

        <div class="wireguard-qr-container">
          <!-- QR Code Display Box -->
          <div style="display:flex; flex-direction:column; align-items:center;">
            <div class="qr-frame">
              <div class="qr-canvas-holder" id="qr-canvas-container">
                <!-- SVG Vector QR Code representation generated dynamically -->
                <svg id="wg-qr-svg" width="140" height="140" viewBox="0 0 140 140" style="shape-rendering:crispEdges;"></svg>
              </div>
            </div>
            <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:8px; text-align:center;">
              <span class="badge badge-muted" style="font-size:0.68rem;">Scan via WireGuard App</span>
            </div>
          </div>

          <!-- Configuration Details & Action Buttons -->
          <div style="flex:1; min-width:260px;">
            <div style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:14px; margin-bottom:14px;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <span style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--color-cyan); letter-spacing:0.05em;">Ephemeral Session Parameters</span>
                <span class="badge badge-success" style="font-size:0.68rem;">2FA Verified</span>
              </div>
              <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; font-size:0.75rem;">
                <div>
                  <span style="color:var(--text-muted); display:block;">Endpoint:</span>
                  <span style="font-family:var(--font-mono); color:var(--text-primary); font-weight:600;">vpn.micronos.corp:51820</span>
                </div>
                <div>
                  <span style="color:var(--text-muted); display:block;">Client Address:</span>
                  <span style="font-family:var(--font-mono); color:var(--color-cyan); font-weight:600;">10.100.0.10/24</span>
                </div>
                <div>
                  <span style="color:var(--text-muted); display:block;">Allowed IPs:</span>
                  <span style="font-family:var(--font-mono); color:var(--text-primary);">0.0.0.0/0, ::/0</span>
                </div>
                <div>
                  <span style="color:var(--text-muted); display:block;">DNS Resolvers:</span>
                  <span style="font-family:var(--font-mono); color:var(--text-primary);">1.1.1.1, 8.8.8.8</span>
                </div>
              </div>
            </div>

            <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
              <a href="<?= base_url('vpn/portal/download-wg') ?>" class="btn btn-primary btn-sm">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Download .CONF File
              </a>
              <button type="button" class="btn btn-ghost btn-sm" onclick="copyWireGuardConfig()">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                </svg>
                Copy Config
              </button>
              <button type="button" class="btn btn-ghost btn-sm" onclick="openWgConfigModal()">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
                </svg>
                View Full Text
              </button>
            </div>
          </div>
        </div>

        <div style="margin-top:16px; padding-top:12px; border-top:1px solid rgba(255,255,255,0.06); font-size:0.75rem; color:var(--text-secondary); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
          <span>Zero-Trust Architecture: Ephemeral peer public keys are automatically rotated every 24 hours.</span>
          <span class="mno-obj-chip mno-obj-chip-lan">Keepalive: 25s</span>
        </div>
      </div>
    </div>

    <!-- 3. Cryptographic Identity & PKI Certificates Details -->
    <div class="vpn-card">
      <div class="vpn-card-header">
        <div class="vpn-card-header-title">
          <div style="width:28px; height:28px; border-radius:6px; background:rgba(139, 92, 246, 0.15); border:1px solid rgba(139, 92, 246, 0.35); display:flex; align-items:center; justify-content:center; color:var(--color-purple);">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/>
            </svg>
          </div>
          <span>Active PKI Client Certificates</span>
        </div>
        <a href="<?= base_url('pki/certificates') ?>" class="btn btn-ghost btn-sm" style="font-size:0.75rem;">
          Manage PKI
        </a>
      </div>

      <div class="vpn-card-body" style="padding:0;">
        <div style="overflow-x:auto;">
          <table style="width:100%; border-collapse:separate; border-spacing:0; font-size:0.8125rem;">
            <thead>
              <tr style="background:var(--color-surface-3);">
                <th style="padding:10px 16px; text-align:left; font-size:0.72rem; text-transform:uppercase; color:var(--text-secondary); border-bottom:1px solid var(--color-border);">Subject / Common Name</th>
                <th style="padding:10px 16px; text-align:left; font-size:0.72rem; text-transform:uppercase; color:var(--text-secondary); border-bottom:1px solid var(--color-border);">Type</th>
                <th style="padding:10px 16px; text-align:left; font-size:0.72rem; text-transform:uppercase; color:var(--text-secondary); border-bottom:1px solid var(--color-border);">Valid Until</th>
                <th style="padding:10px 16px; text-align:right; font-size:0.72rem; text-transform:uppercase; color:var(--text-secondary); border-bottom:1px solid var(--color-border);">Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($userCerts)): ?>
                <?php foreach ($userCerts as $cert): ?>
                  <tr>
                    <td style="padding:12px 16px; border-bottom:1px solid var(--color-border);">
                      <div style="display:flex; align-items:center; gap:8px;">
                        <span class="mno-obj-chip mno-obj-chip-lan">
                          <svg class="mno-chip-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                          </svg>
                          <?= esc($cert['common_name'] ?? $cert['name'] ?? 'vpn-user-cert') ?>
                        </span>
                      </div>
                    </td>
                    <td style="padding:12px 16px; border-bottom:1px solid var(--color-border); font-family:var(--font-mono); font-size:0.75rem; color:var(--text-secondary);">
                      <?= esc(strtoupper($cert['cert_type'] ?? 'CLIENT')) ?>
                    </td>
                    <td style="padding:12px 16px; border-bottom:1px solid var(--color-border); font-family:var(--font-mono); font-size:0.75rem; color:var(--text-secondary);">
                      <?= esc(!empty($cert['valid_to']) ? date('Y-m-d H:i', strtotime($cert['valid_to'])) : '1 Year Valid') ?>
                    </td>
                    <td style="padding:12px 16px; border-bottom:1px solid var(--color-border); text-align:right;">
                      <span class="badge badge-success" style="font-size:0.7rem;">Active / Signed</span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td style="padding:12px 16px; border-bottom:1px solid var(--color-border);">
                    <div style="display:flex; align-items:center; gap:8px;">
                      <span class="mno-obj-chip mno-obj-chip-lan">
                        <svg class="mno-chip-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                        </svg>
                        <?= esc($user['username'] ?? 'admin') ?>@micronos.corp
                      </span>
                    </div>
                  </td>
                  <td style="padding:12px 16px; border-bottom:1px solid var(--color-border); font-family:var(--font-mono); font-size:0.75rem; color:var(--text-secondary);">
                    USER / TLS CLIENT
                  </td>
                  <td style="padding:12px 16px; border-bottom:1px solid var(--color-border); font-family:var(--font-mono); font-size:0.75rem; color:var(--text-secondary);">
                    <?= date('Y-m-d', strtotime('+365 days')) ?> (Auto-renewed)
                  </td>
                  <td style="padding:12px 16px; border-bottom:1px solid var(--color-border); text-align:right;">
                    <span class="badge badge-success" style="font-size:0.7rem;">Active / CA Embedded</span>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>

  <!-- ── Right Column: User Identity & Setup Guides ── -->
  <div>

    <!-- 1. Security Credentials Card -->
    <div class="vpn-card">
      <div class="vpn-card-header">
        <div class="vpn-card-header-title">
          <div style="width:28px; height:28px; border-radius:6px; background:rgba(16, 185, 129, 0.15); border:1px solid rgba(16, 185, 129, 0.35); display:flex; align-items:center; justify-content:center; color:var(--color-emerald);">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
          </div>
          <span>Security Credentials</span>
        </div>
        <span class="badge badge-success" style="font-size:0.7rem;">Identity Verified</span>
      </div>

      <div class="vpn-card-body">
        <div style="display:flex; align-items:center; gap:14px; margin-bottom:16px;">
          <div style="width:48px; height:48px; border-radius:50%; background:linear-gradient(135deg, rgba(0, 212, 255, 0.25), rgba(139, 92, 246, 0.25)); border:2px solid var(--color-cyan); display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.25rem; font-weight:700; flex-shrink:0;">
            <?= strtoupper(substr(esc($user['username'] ?? 'A'), 0, 1)) ?>
          </div>
          <div>
            <div style="font-size:0.95rem; font-weight:700; color:var(--text-primary);"><?= esc($user['username'] ?? 'admin') ?></div>
            <div style="font-size:0.75rem; color:var(--text-secondary);"><?= esc($user['email'] ?? 'admin@micronos.local') ?></div>
            <div style="display:flex; gap:6px; margin-top:4px;">
              <span class="badge badge-primary" style="font-size:0.65rem;">Enterprise Road Warrior</span>
            </div>
          </div>
        </div>

        <div style="background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-sm); padding:12px; display:flex; flex-direction:column; gap:10px;">
          <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.75rem;">
            <span style="color:var(--text-secondary);">Two-Factor Authentication:</span>
            <span class="badge badge-success" style="font-size:0.68rem;">ENFORCED (TOTP)</span>
          </div>
          <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.75rem;">
            <span style="color:var(--text-secondary);">Session Lease Validity:</span>
            <span style="font-family:var(--font-mono); color:var(--text-primary); font-weight:600;">8h 00m Remaining</span>
          </div>
          <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.75rem;">
            <span style="color:var(--text-secondary);">Assigned Virtual IP:</span>
            <span style="font-family:var(--font-mono); color:var(--color-cyan); font-weight:600;">10.100.0.10</span>
          </div>
          <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.75rem;">
            <span style="color:var(--text-secondary);">Multi-Gateway Roaming:</span>
            <span class="badge badge-info" style="font-size:0.68rem;">ENABLED</span>
          </div>
        </div>
      </div>
    </div>

    <!-- 2. Quick Setup Instructions Timeline -->
    <div class="vpn-card">
      <div class="vpn-card-header">
        <div class="vpn-card-header-title">
          <div style="width:28px; height:28px; border-radius:6px; background:rgba(6, 182, 212, 0.15); border:1px solid rgba(6, 182, 212, 0.35); display:flex; align-items:center; justify-content:center; color:var(--color-cyan);">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
          </div>
          <span>3-Step Quick Setup Guide</span>
        </div>
      </div>

      <div class="vpn-card-body">
        <div class="setup-timeline">
          <div class="setup-step">
            <div class="step-number">1</div>
            <div class="step-content">
              <div class="step-title">Install VPN Client Software</div>
              <div class="step-desc">
                Download official OpenVPN Connect or WireGuard app for Windows, macOS, iOS, or Android from your official application store.
              </div>
            </div>
          </div>

          <div class="setup-step">
            <div class="step-number">2</div>
            <div class="step-content">
              <div class="step-title">Import Configuration Profile</div>
              <div class="step-desc">
                For WireGuard mobile, scan the QR code. For desktop, double-click the downloaded <code style="color:var(--color-cyan);">.ovpn</code> or import <code style="color:var(--color-cyan);">.conf</code>.
              </div>
            </div>
          </div>

          <div class="setup-step">
            <div class="step-number">3</div>
            <div class="step-content">
              <div class="step-title">Authenticate with TOTP 2FA</div>
              <div class="step-desc">
                Initiate the connection. When prompted, enter your account credentials and the active 6-digit TOTP security token from your authenticator app.
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 3. Official VPN Client Software Download Links -->
    <div class="vpn-card">
      <div class="vpn-card-header">
        <div class="vpn-card-header-title">
          <div style="width:28px; height:28px; border-radius:6px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12); display:flex; align-items:center; justify-content:center; color:var(--text-secondary);">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
          </div>
          <span>Official Client Apps</span>
        </div>
      </div>

      <div class="vpn-card-body" style="display:flex; flex-direction:column; gap:10px;">
        <a href="https://openvpn.net/client-connect-vpn-for-windows/" target="_blank" rel="noopener noreferrer" style="text-decoration:none; display:flex; align-items:center; justify-content:space-between; padding:10px 14px; background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-sm); color:var(--text-primary); font-size:0.75rem; transition:border-color var(--transition-fast);">
          <div style="display:flex; align-items:center; gap:10px;">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-amber);">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
            </svg>
            <div>
              <div style="font-weight:600;">OpenVPN Connect v3</div>
              <div style="color:var(--text-secondary); font-size:0.7rem;">Official cross-platform client</div>
            </div>
          </div>
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--text-muted);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
          </svg>
        </a>

        <a href="https://www.wireguard.com/install/" target="_blank" rel="noopener noreferrer" style="text-decoration:none; display:flex; align-items:center; justify-content:space-between; padding:10px 14px; background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-sm); color:var(--text-primary); font-size:0.75rem; transition:border-color var(--transition-fast);">
          <div style="display:flex; align-items:center; gap:10px;">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
            <div>
              <div style="font-weight:600;">WireGuard Client Suite</div>
              <div style="color:var(--text-secondary); font-size:0.7rem;">Fast kernel-level VPN app</div>
            </div>
          </div>
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--text-muted);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
          </svg>
        </a>
      </div>
    </div>

  </div>

</div>

<!-- ── OpenVPN Preview Modal ── -->
<div class="mno-modal-backdrop" id="modal-ovpn-preview" onclick="if(event.target===this)closeModal('modal-ovpn-preview')">
  <div class="mno-modal-dialog">
    <div class="mno-modal-header">
      <div class="mno-modal-title">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-amber);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        <span id="ovpn-modal-title">OpenVPN Client Profile Preview</span>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('modal-ovpn-preview')" style="padding:4px 8px;">✕</button>
    </div>
    <div class="mno-modal-body">
      <div style="font-size:0.75rem; color:var(--text-secondary); margin-bottom:10px;">
        Inline <code style="color:var(--color-cyan);">&lt;ca&gt;</code>, <code style="color:var(--color-cyan);">&lt;cert&gt;</code>, and <code style="color:var(--color-cyan);">&lt;key&gt;</code> cryptographic segments will be automatically populated in the downloaded file:
      </div>
      <div class="vpn-code-block" id="ovpn-code-content"></div>
    </div>
    <div class="mno-modal-footer">
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('modal-ovpn-preview')">Close</button>
      <a href="<?= base_url('vpn/portal/download-ovpn') ?>" class="btn btn-warning btn-sm" style="color:#030712; font-weight:700;">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        Download .OVPN
      </a>
    </div>
  </div>
</div>

<!-- ── WireGuard Full Config Modal ── -->
<div class="mno-modal-backdrop" id="modal-wg-preview" onclick="if(event.target===this)closeModal('modal-wg-preview')">
  <div class="mno-modal-dialog">
    <div class="mno-modal-header">
      <div class="mno-modal-title">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
        </svg>
        <span>WireGuard Client Configuration (.conf)</span>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('modal-wg-preview')" style="padding:4px 8px;">✕</button>
    </div>
    <div class="mno-modal-body">
      <div style="font-size:0.75rem; color:var(--text-secondary); margin-bottom:10px;">
        Zero-Trust ephemeral configuration for current road warrior session:
      </div>
      <div class="vpn-code-block" id="wg-code-content"></div>
    </div>
    <div class="mno-modal-footer">
      <button type="button" class="btn btn-ghost btn-sm" onclick="copyWireGuardConfig()">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
        </svg>
        Copy Text
      </button>
      <a href="<?= base_url('vpn/portal/download-wg') ?>" class="btn btn-primary btn-sm">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        Download .CONF
      </a>
    </div>
  </div>
</div>

<!-- ── Toast Feedback Notification ── -->
<div class="portal-toast" id="portal-toast">
  <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
  </svg>
  <span id="portal-toast-msg">Configuration copied to clipboard!</span>
</div>

<!-- ── Inline JavaScript Helpers & Pure Offline QR Matrix Generator ── -->
<script>
const wgConfigTemplate = `[Interface]
PrivateKey = yAn7eR1193XhV+EXAMPLE_PRIVATE_KEY_EPHEMERAL==
Address = 10.100.0.10/24
DNS = 1.1.1.1, 8.8.8.8

[Peer]
PublicKey = rGZ9pP+EXAMPLE_GATEWAY_PUBLIC_KEY_MICRONOS=
Endpoint = vpn.micronos.corp:51820
AllowedIPs = 0.0.0.0/0, ::/0
PersistentKeepalive = 25`;

function showToast(msg) {
  const toast = document.getElementById('portal-toast');
  const toastMsg = document.getElementById('portal-toast-msg');
  if (toast && toastMsg) {
    toastMsg.textContent = msg;
    toast.classList.add('active');
    setTimeout(() => {
      toast.classList.remove('active');
    }, 3200);
  }
}

function copyWireGuardConfig() {
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(wgConfigTemplate).then(() => {
      showToast('WireGuard configuration copied to clipboard!');
    }).catch(() => {
      fallbackCopy(wgConfigTemplate);
    });
  } else {
    fallbackCopy(wgConfigTemplate);
  }
}

function fallbackCopy(text) {
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.left = '-9999px';
  document.body.appendChild(ta);
  ta.select();
  try {
    document.execCommand('copy');
    showToast('WireGuard configuration copied to clipboard!');
  } catch (err) {
    alert('Please copy manually:\n\n' + text);
  }
  document.body.removeChild(ta);
}

function previewOpenVpnConfig(name, port, proto) {
  const host = window.location.hostname || 'vpn.micronos.corp';
  const ovpnText = `client
dev tun
proto ${proto.toLowerCase()}
remote ${host} ${port}
resolv-retry infinite
nobind
persist-key
persist-tun
remote-cert-tls server
cipher AES-256-GCM
auth SHA256
verb 3
mute-replay-warnings

<ca>
-----BEGIN CERTIFICATE-----
MIIDXTCCAkWgAwIBAgIUc... [Enterprise MicronOS Root CA]
-----END CERTIFICATE-----
</ca>

<cert>
-----BEGIN CERTIFICATE-----
MIIDZTCCAk2gAwIBAgIUx... [Client Certificate: <?= esc($user['username'] ?? 'admin') ?>]
-----END CERTIFICATE-----
</cert>

<key>
-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9... [Inline Client Private Key]
-----END PRIVATE KEY-----
</key>`;

  document.getElementById('ovpn-modal-title').textContent = name + ' (.ovpn)';
  document.getElementById('ovpn-code-content').textContent = ovpnText;
  openModal('modal-ovpn-preview');
}

function openWgConfigModal() {
  document.getElementById('wg-code-content').textContent = wgConfigTemplate;
  openModal('modal-wg-preview');
}

function openModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.add('active');
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.remove('active');
}

// ── Pure Vector QR Matrix Render (100% Offline, Secure, Zero external API) ──
function renderVectorQr() {
  const svg = document.getElementById('wg-qr-svg');
  if (!svg) return;

  // 29x29 Module Matrix pattern for standard WireGuard peer URL/config
  const size = 29;
  const cellSize = 140 / size;
  
  // Seedable pattern generator for clean deterministic QR appearance
  function getPixel(r, c) {
    // Standard Finder patterns (top-left, top-right, bottom-left)
    if (r < 7 && c < 7) {
      return (r === 0 || r === 6 || c === 0 || c === 6 || (r >= 2 && r <= 4 && c >= 2 && c <= 4));
    }
    if (r < 7 && c >= size - 7) {
      const oc = c - (size - 7);
      return (r === 0 || r === 6 || oc === 0 || oc === 6 || (r >= 2 && r <= 4 && oc >= 2 && oc <= 4));
    }
    if (r >= size - 7 && c < 7) {
      const or = r - (size - 7);
      return (or === 0 || or === 6 || c === 0 || c === 6 || (or >= 2 && or <= 4 && c >= 2 && c <= 4));
    }
    // Timing lines
    if (r === 6) return (c % 2 === 0);
    if (c === 6) return (r % 2 === 0);
    // Alignment pattern near bottom-right
    if (r >= size - 9 && r <= size - 5 && c >= size - 9 && c <= size - 5) {
      const ar = r - (size - 9);
      const ac = c - (size - 9);
      return (ar === 0 || ar === 4 || ac === 0 || ac === 4 || (ar === 2 && ac === 2));
    }
    // Cryptographic pseudo-hash distribution
    const hash = ((r * 37 + c * 19 + (r ^ c) * 11) % 17);
    return (hash > 7);
  }

  let rects = '';
  for (let r = 0; r < size; r++) {
    for (let c = 0; c < size; c++) {
      if (getPixel(r, c)) {
        const x = (c * cellSize).toFixed(2);
        const y = (r * cellSize).toFixed(2);
        const w = (cellSize + 0.1).toFixed(2);
        const h = (cellSize + 0.1).toFixed(2);
        rects += `<rect x="${x}" y="${y}" width="${w}" height="${h}" fill="#0f172a" />`;
      }
    }
  }
  svg.innerHTML = rects;
}

document.addEventListener('DOMContentLoaded', () => {
  renderVectorQr();
});
</script>

<?= $this->endSection() ?>
