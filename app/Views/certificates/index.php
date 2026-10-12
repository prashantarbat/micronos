<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<style>
/* Page-specific Guide Cards */
.guide-card {
  background: var(--color-surface-1);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: 16px;
  height: 100%;
  transition: all var(--transition-fast);
}
.guide-card:hover {
  border-color: var(--color-cyan);
  background: var(--tone-cyan-bg);
  transform: translateY(-2px);
}
</style>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <h1 class="page-title">Certificates &amp; Public Key Infrastructure (PKI)</h1>
        <span class="badge badge-purple" style="font-size:0.75rem;">X.509 PKI ENGINE</span>
        <span class="badge badge-info" style="font-size:0.75rem;">SHA-256 / RSA 4096</span>
        <span class="badge badge-cyan" style="font-size:0.75rem;"><?= count($certs) ?> Issued Certs</span>
      </div>
      <p class="page-subtitle">Internal Certificate Authority (CA) management, Web GUI SSL/TLS, OpenVPN/IPsec keys, and Squid SSL-Bump inspection</p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
      <button type="button" class="btn btn-ghost btn-sm" onclick="openModal('importCaModal')">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4 4m4-4V4"/>
        </svg>
        Import CA
      </button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="openModal('createCaModal')">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        New Certificate Authority
      </button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="openModal('importCertModal')">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        Import Certificate
      </button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="openModal('csrModal')">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        New CSR
      </button>
      <button type="button" class="btn btn-primary btn-sm" onclick="openModal('createCertModal')">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        Issue Certificate
      </button>
      <a href="<?= base_url('vpn/portal') ?>" class="btn btn-ghost btn-sm">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
        </svg>
        VPN Portal
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

<?php if (session()->getFlashdata('warning')): ?>
  <div class="alert alert-warning animate-in" style="margin-bottom:16px;">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="margin-right:8px; flex-shrink:0;">
      <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
    </svg>
    <?= esc(session()->getFlashdata('warning')) ?>
  </div>
<?php endif; ?>

<?php
  $revokedCount = 0;
  foreach ($certs as $c) {
    if (!empty($c['revoked'])) $revokedCount++;
  }
?>

<!-- ── Summary Metric Cards ── -->
<div class="cert-stats-grid animate-in animate-in-delay-1">
  <div class="stat-card cyan">
    <div class="stat-icon cyan">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-cyan);"><?= count($certs) ?> Active</div>
    <div class="stat-label">Issued Certificates</div>
    <div class="stat-sublabel">Server, VPN, and User credentials</div>
  </div>

  <div class="stat-card purple">
    <div class="stat-icon purple">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-purple);"><?= count($cas) ?> Root CAs</div>
    <div class="stat-label">Certificate Authorities</div>
    <div class="stat-sublabel">Self-managed internal trust anchors</div>
  </div>

  <div class="stat-card emerald">
    <div class="stat-icon emerald">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-emerald);"><?= $revokedCount > 0 ? $revokedCount . ' Revoked' : 'Clean (0)' ?></div>
    <div class="stat-label">Revocation Status</div>
    <div class="stat-sublabel">CRL distribution endpoints active</div>
  </div>

  <div class="stat-card amber">
    <div class="stat-icon amber">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 004.07 7.07"/>
      </svg>
    </div>
    <div class="stat-value" style="color:var(--color-amber);">SHA-256 / RSA</div>
    <div class="stat-label">Cryptographic Suite</div>
    <div class="stat-sublabel">TLS 1.3 / Strict Cipher suites</div>
  </div>
</div>

<!-- ── Navigation Tabs ── -->
<div class="tab-bar cert-tabs animate-in animate-in-delay-1">
  <button type="button" class="tab-item cert-tab active" onclick="switchCertTab('tab-certs', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
    </svg>
    Issued Certificates (<?= count($certs) ?>)
  </button>
  <button type="button" class="tab-item cert-tab" onclick="switchCertTab('tab-cas', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
    </svg>
    Certificate Authorities (<?= count($cas) ?>)
  </button>
  <button type="button" class="tab-item cert-tab" onclick="switchCertTab('tab-portal', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
    </svg>
    Portal SSL (Web GUI)
  </button>
  <?php if ($acmeCanManage): ?>
  <button type="button" class="tab-item cert-tab" onclick="switchCertTab('tab-acme', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    ACME / Let's Encrypt<?= $acmeDue !== [] ? ' (' . count($acmeDue) . ' due)' : '' ?>
  </button>
  <?php endif; ?>
  <button type="button" class="tab-item cert-tab" onclick="switchCertTab('tab-guide', this)">
    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    Squid SSL-Bump &amp; PKI Guide
  </button>
</div>

<!-- ══════════════════════════════════════════════════════════════
     TAB 1: ISSUED CERTIFICATES
══════════════════════════════════════════════════════════════ -->
<div class="cert-panel active animate-in animate-in-delay-2" id="tab-certs">
  <div class="card" style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-md); overflow:hidden;">
    <div style="padding:14px 18px; background:var(--color-surface-3); border-bottom:1px solid var(--color-border); display:flex; justify-content:space-between; align-items:center;">
      <span style="font-size:0.875rem; font-weight:700; color:var(--text-primary); display:flex; align-items:center; gap:8px;">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        X.509 Cryptographic Certificates
      </span>
      <button type="button" class="btn btn-primary btn-sm" onclick="openModal('createCertModal')">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        Issue Certificate
      </button>
    </div>

    <div style="overflow-x:auto;">
      <table class="mno-table">
        <thead>
          <tr>
            <th>Name / Description</th>
            <th>Type</th>
            <th>Source</th>
            <th>Common Name (CN)</th>
            <th>SANs (DNS / IP)</th>
            <th>Serial</th>
            <th>Valid Until</th>
            <th>Status</th>
            <th style="text-align:right;">Downloads &amp; Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($certs)): ?>
            <?php foreach ($certs as $cert): ?>
              <?php $isCsr = empty($cert['certificate']) || ($cert['source'] ?? '') === 'csr'; ?>
              <tr>
                <td>
                  <div style="font-weight:600; color:var(--text-primary); font-size:0.8125rem;">
                    <?= esc($cert['name']) ?>
                    <?php if ($isCsr): ?><span style="font-size:0.62rem; background:rgba(245,158,11,0.18); color:#f59e0b; border:1px solid rgba(245,158,11,0.4); padding:1px 6px; border-radius:4px; margin-left:4px;">CSR</span><?php endif; ?>
                  </div>
                  <div style="font-size:0.72rem; color:var(--text-secondary);"><?= esc($cert['description'] ?? '—') ?></div>
                </td>
                <td>
                  <?php if ($cert['cert_type'] === 'server'): ?>
                    <span class="mno-obj-chip mno-obj-chip-lan">SERVER</span>
                  <?php elseif ($cert['cert_type'] === 'vpn'): ?>
                    <span class="mno-obj-chip mno-obj-chip-dmz">VPN</span>
                  <?php elseif ($cert['cert_type'] === 'client'): ?>
                    <span class="mno-obj-chip mno-obj-chip-wan">CLIENT</span>
                  <?php else: ?>
                    <span class="mno-obj-chip"><?= strtoupper(esc($cert['cert_type'])) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (($cert['source'] ?? 'generated') === 'imported'): ?>
                    <span class="mno-obj-chip mno-obj-chip-wan">IMPORTED</span>
                  <?php elseif (($cert['source'] ?? 'generated') === 'csr'): ?>
                    <span class="mno-obj-chip">CSR</span>
                  <?php else: ?>
                    <span class="mno-obj-chip mno-obj-chip-lan">GENERATED</span>
                  <?php endif; ?>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--color-cyan); font-weight:600;">
                  <?= esc($cert['common_name']) ?>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.72rem; color:var(--text-secondary); max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                  <?= esc($cert['san_dns'] ? $cert['san_dns'] : ($cert['san_ip'] ?: 'None')) ?>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.72rem; color:var(--text-muted);">
                  <?= $cert['serial_number'] !== '' && $cert['serial_number'] !== null ? '#' . esc($cert['serial_number']) : '—' ?>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-primary);">
                  <?= empty($cert['valid_until']) ? '— pending —' : esc(date('Y-m-d', strtotime($cert['valid_until']))) ?>
                </td>
                <td>
                  <?php if ($isCsr): ?>
                    <span style="font-size:0.7rem; padding:2px 8px; border-radius:4px; background:rgba(245,158,11,0.15); color:#f59e0b; border:1px solid rgba(245,158,11,0.35); font-weight:600;">CSR PENDING</span>
                  <?php elseif (!empty($cert['revoked'])): ?>
                    <span class="badge badge-danger" style="font-size:0.7rem;">REVOKED</span>
                  <?php else: ?>
                    <span class="badge badge-success" style="font-size:0.7rem;">VALID</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:right;">
                  <div style="display:inline-flex; align-items:center; gap:6px;">
                    <?php if ($isCsr): ?>
                      <!-- Pending CSR: export the request, then paste the signed cert back -->
                      <a href="<?= base_url('certificates/download-csr/' . $cert['id']) ?>" class="btn btn-ghost btn-sm" style="font-size:0.72rem; font-family:var(--font-mono);" title="Download the Certificate Signing Request (.csr)">
                        CSR
                      </a>
                      <button type="button" class="btn btn-ghost btn-sm" style="color:var(--color-cyan); font-size:0.72rem;"
                              onclick='openCompleteCsr(<?= (int) $cert['id'] ?>, <?= json_encode($cert['name'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                              title="Paste the signed certificate returned by the CA">
                        Paste signed cert
                      </button>
                    <?php else: ?>
                      <!-- Downloads -->
                      <div style="display:inline-flex; border:1px solid var(--color-border); border-radius:var(--radius-sm); overflow:hidden;">
                        <a href="<?= base_url('certificates/download/' . $cert['id'] . '/crt') ?>" class="btn btn-ghost btn-sm" style="border:none; border-radius:0; padding:4px 8px; font-size:0.72rem; font-family:var(--font-mono);" title="Download Certificate (.crt)">
                          CRT
                        </a>
                        <a href="<?= base_url('certificates/download/' . $cert['id'] . '/key') ?>" class="btn btn-ghost btn-sm" style="border:none; border-radius:0; border-left:1px solid var(--color-border); padding:4px 8px; font-size:0.72rem; font-family:var(--font-mono); color:var(--color-amber);" title="Download Private Key (.key)">
                          KEY
                        </a>
                        <a href="<?= base_url('certificates/download/' . $cert['id'] . '/pem') ?>" class="btn btn-ghost btn-sm" style="border:none; border-radius:0; border-left:1px solid var(--color-border); padding:4px 8px; font-size:0.72rem; font-family:var(--font-mono); color:var(--color-cyan);" title="Download Unified PEM Bundle (.pem)">
                          PEM
                        </a>
                      </div>

                      <?php if (empty($cert['revoked'])): ?>
                        <form method="post" action="<?= base_url('certificates/set-webgui/' . $cert['id']) ?>" style="margin:0; display:inline;">
                          <?= csrf_field() ?>
                          <button type="submit" class="btn btn-ghost btn-sm" style="font-size:0.72rem;" title="Bind this certificate to the Web GUI / portal">Bind</button>
                        </form>
                        <a href="<?= base_url('certificates/revoke/' . $cert['id']) ?>" class="btn btn-ghost btn-sm" style="color:var(--color-amber); padding:4px 8px;" onclick="return confirm('Revoke this certificate? Client connections using it will be rejected.');" title="Revoke Certificate">
                          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                          </svg>
                        </a>
                      <?php endif; ?>
                    <?php endif; ?>

                    <a href="<?= base_url('certificates/delete/' . $cert['id']) ?>" class="btn btn-ghost btn-sm" style="color:var(--color-red); padding:4px 8px;" onclick="return confirm('Permanently delete this certificate record?');" title="Delete Certificate">
                      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                      </svg>
                    </a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="9" style="text-align:center; padding:32px; color:var(--text-muted);">
                No certificates found. Use <strong>Issue Certificate</strong> to generate one, <strong>Import Certificate</strong> to install an existing one, or <strong>New CSR</strong> to request one from a public CA.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     TAB 2: CERTIFICATE AUTHORITIES (CAs)
══════════════════════════════════════════════════════════════ -->
<div class="cert-panel animate-in animate-in-delay-2" id="tab-cas">
  <div class="card" style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-md); overflow:hidden;">
    <div style="padding:14px 18px; background:var(--color-surface-3); border-bottom:1px solid var(--color-border); display:flex; justify-content:space-between; align-items:center;">
      <span style="font-size:0.875rem; font-weight:700; color:var(--text-primary); display:flex; align-items:center; gap:8px;">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-purple);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
        </svg>
        Internal Certificate Authorities (Trust Anchors)
      </span>
      <button type="button" class="btn btn-primary btn-sm" onclick="openModal('createCaModal')">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        New Certificate Authority
      </button>
    </div>

    <div style="overflow-x:auto;">
      <table class="mno-table">
        <thead>
          <tr>
            <th>CA Name</th>
            <th>Common Name (CN)</th>
            <th>Organization</th>
            <th>Key Length</th>
            <th>Digest</th>
            <th>Valid Until</th>
            <th>Role / Type</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($cas)): ?>
            <?php foreach ($cas as $ca): ?>
              <tr>
                <td>
                  <div style="font-weight:600; color:var(--text-primary); font-size:0.8125rem;"><?= esc($ca['name']) ?></div>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--color-cyan); font-weight:600;">
                  <?= esc($ca['common_name']) ?>
                </td>
                <td style="font-size:0.75rem; color:var(--text-secondary);">
                  <?= esc($ca['organization']) ?>
                </td>
                <td>
                  <span class="mno-obj-chip mno-obj-chip-dmz"><?= esc($ca['key_length']) ?>-bit RSA</span>
                </td>
                <td>
                  <span class="mno-obj-chip"><?= strtoupper(esc($ca['digest'])) ?></span>
                </td>
                <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--text-primary);">
                  <?= esc(date('Y-m-d', strtotime($ca['valid_until']))) ?>
                </td>
                <td>
                  <?php if (!empty($ca['is_default'])): ?>
                    <span class="badge badge-success" style="font-size:0.7rem;">SYSTEM ROOT CA</span>
                  <?php elseif (($ca['source'] ?? 'generated') === 'imported'): ?>
                    <span class="mno-obj-chip mno-obj-chip-wan">IMPORTED CA</span>
                  <?php else: ?>
                    <span class="badge badge-muted" style="font-size:0.7rem;">SUBORDINATE CA</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:right;">
                  <div style="display:inline-flex; align-items:center; gap:6px;">
                    <a href="<?= base_url('certificates/download-ca/' . $ca['id']) ?>" class="btn btn-ghost btn-sm" style="color:var(--color-cyan); font-size:0.75rem;">
                      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                      </svg>
                      Download CA CRT
                    </a>
                    <?php if (!empty($ca['private_key'])): ?>
                      <a href="<?= base_url('certificates/download-ca-key/' . $ca['id']) ?>" class="btn btn-ghost btn-sm" style="color:var(--color-amber); font-size:0.75rem;" title="Download the CA private key (needed to sign certificates on another node)">
                        KEY
                      </a>
                    <?php endif; ?>
                    <?php if (empty($ca['is_default'])): ?>
                      <a href="<?= base_url('certificates/delete-ca/' . $ca['id']) ?>" class="btn btn-ghost btn-sm" style="color:var(--color-red); padding:4px 8px;" onclick="return confirm('Permanently delete this Certificate Authority? All issued certs under this CA will become invalid.');" title="Delete CA">
                        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                      </a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="8" style="text-align:center; padding:32px; color:var(--text-muted);">
                No Certificate Authorities found.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     TAB 3: PORTAL SSL (WEB GUI TLS BINDING)
══════════════════════════════════════════════════════════════ -->
<div class="cert-panel animate-in animate-in-delay-2" id="tab-portal">
  <div class="card" style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-md); padding:20px; margin-bottom:18px;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
      <div>
        <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin-bottom:6px; display:flex; align-items:center; gap:8px;">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
          </svg>
          Web GUI / Portal TLS Certificate
        </h3>
        <p style="font-size:0.8rem; color:var(--text-secondary); max-width:640px; line-height:1.55; margin:0;">
          Bind an imported or internally-issued certificate to the management console and the self-service VPN portal.
          That certificate is then presented by the management console and the self-service VPN portal.
        </p>
      </div>
      <?php if ($webguiCertId > 0): ?>
        <form method="post" action="<?= base_url('certificates/clear-webgui') ?>" style="margin:0;">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-ghost btn-sm">Revert to default certificate</button>
        </form>
      <?php endif; ?>
    </div>

    <div style="margin-top:16px; padding:12px 14px; border-radius:var(--radius-sm); background:var(--color-surface-3); border:1px solid var(--color-border); display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
      <?php if ($webguiCert): ?>
        <span class="badge badge-success" style="font-size:0.7rem;">ACTIVE</span>
        <span style="font-weight:600; color:var(--text-primary); font-size:0.85rem;"><?= esc($webguiCert['name']) ?></span>
        <span class="mno-obj-chip" style="font-family:var(--font-mono);"><?= esc($webguiCert['common_name']) ?></span>
        <span style="font-size:0.75rem; color:var(--text-muted);">Expires <?= esc(date('Y-m-d', strtotime($webguiCert['valid_until']))) ?></span>
      <?php else: ?>
        <span class="badge badge-muted" style="font-size:0.7rem;">DEFAULT</span>
        <span style="font-size:0.82rem; color:var(--text-secondary);">The built-in self-signed certificate is currently presented. Bind a stored certificate below to remove browser warnings.</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="card" style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-md); overflow:hidden;">
    <div style="padding:12px 18px; background:var(--color-surface-3); border-bottom:1px solid var(--color-border); font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-secondary);">
      Eligible certificates
    </div>
    <div style="overflow-x:auto;">
      <table class="mno-table">
        <thead>
          <tr><th>Name</th><th>Common Name (CN)</th><th>Type</th><th>Source</th><th>Valid Until</th><th style="text-align:right;">Action</th></tr>
        </thead>
        <tbody>
          <?php $eligible = 0; ?>
          <?php foreach ($certs as $cert): ?>
            <?php if (empty($cert['certificate']) || ! empty($cert['revoked'])) continue; $eligible++; ?>
            <tr>
              <td style="font-weight:600; color:var(--text-primary); font-size:0.8125rem;"><?= esc($cert['name']) ?></td>
              <td style="font-family:var(--font-mono); font-size:0.75rem; color:var(--color-cyan);"><?= esc($cert['common_name']) ?></td>
              <td><span class="mno-obj-chip"><?= strtoupper(esc($cert['cert_type'])) ?></span></td>
              <td>
                <?php if (($cert['source'] ?? 'generated') === 'imported'): ?>
                  <span class="mno-obj-chip mno-obj-chip-wan">IMPORTED</span>
                <?php else: ?>
                  <span class="mno-obj-chip">GENERATED</span>
                <?php endif; ?>
              </td>
              <td style="font-family:var(--font-mono); font-size:0.75rem;"><?= esc(date('Y-m-d', strtotime($cert['valid_until']))) ?></td>
              <td style="text-align:right;">
                <?php if ((int) $cert['id'] === (int) $webguiCertId): ?>
                  <span class="badge badge-success" style="font-size:0.68rem;">BOUND</span>
                <?php else: ?>
                  <form method="post" action="<?= base_url('certificates/set-webgui/' . $cert['id']) ?>" style="margin:0; display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-cyan); font-size:0.75rem;">Bind to portal</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($eligible === 0): ?>
            <tr><td colspan="6" style="text-align:center; padding:28px; color:var(--text-muted);">
              No issued certificates yet — issue or import one first.
            </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($acmeCanManage): ?>
<!-- ══════════════════════════════════════════════════════════════
     TAB 4: ACME / LET'S ENCRYPT ISSUANCE & RENEWAL
══════════════════════════════════════════════════════════════ -->
<div class="cert-panel animate-in animate-in-delay-2" id="tab-acme">

  <?php if ($acmeDue !== []): ?>
  <div class="alert alert-warning" style="margin-bottom:16px;">
    <?= count($acmeDue) ?> publicly-trusted certificate(s) are inside the <?= (int) $acmeRenewWindow ?>-day renewal window.
    The scheduled task renews them automatically; use <strong>Renew now</strong> below to do it immediately.
  </div>
  <?php endif; ?>

  <!-- ── Account ── -->
  <div class="card" style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-md); padding:20px; margin-bottom:18px;">
    <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin-bottom:6px;">ACME Account</h3>
    <p style="font-size:0.8rem; color:var(--text-secondary); line-height:1.55; max-width:720px; margin:0 0 14px;">
      The account key is generated here and never leaves the appliance. Registering uses the address below for
      expiry notices — the CA never needs access to anything else.
    </p>

    <div style="overflow-x:auto; margin-bottom:16px;">
      <table class="mno-table">
        <thead>
          <tr><th>Directory</th><th>Status</th><th>Contact</th><th>Account URL</th></tr>
        </thead>
        <tbody>
          <?php
            $byDirectory = [];
            foreach ($acmeAccounts as $a) { $byDirectory[(string) $a['directory']] = $a; }
          ?>
          <?php foreach ($acmeDirectories as $name => $url): ?>
            <?php $account = $byDirectory[$url] ?? null; ?>
            <tr>
              <td style="font-weight:600; color:var(--text-primary); font-size:0.8125rem;"><?= esc(\App\Libraries\AcmeManager::directoryLabel($url)) ?></td>
              <td>
                <?php if ($account !== null && ($account['status'] ?? '') === 'registered'): ?>
                  <span class="badge badge-success" style="font-size:0.68rem;">REGISTERED</span>
                <?php elseif ($account !== null): ?>
                  <span class="badge badge-error" style="font-size:0.68rem;">FAILED</span>
                <?php else: ?>
                  <span class="badge badge-muted" style="font-size:0.68rem;">NOT SET UP</span>
                <?php endif; ?>
              </td>
              <td style="font-size:0.75rem;"><?= esc((string) ($account['contact_email'] ?? '—')) ?></td>
              <td style="font-family:var(--font-mono); font-size:0.7rem; color:var(--text-muted); word-break:break-all;"><?= esc((string) ($account['account_url'] ?? '—')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <form method="post" action="<?= base_url('certificates/acme/account') ?>" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin:0;">
      <?= csrf_field() ?>
      <div style="min-width:220px;">
        <label style="display:block; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); margin-bottom:4px;">Directory</label>
        <select name="directory" class="mno-input" style="width:100%;">
          <?php foreach ($acmeDirectories as $name => $url): ?>
            <option value="<?= esc($url, 'attr') ?>"><?= esc(\App\Libraries\AcmeManager::directoryLabel($url)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="flex:1 1 240px;">
        <label style="display:block; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); margin-bottom:4px;">Contact email</label>
        <input type="email" name="email" class="mno-input" style="width:100%;" placeholder="noc@example.com"
               value="<?= esc((string) ($acmeAccounts[0]['contact_email'] ?? ''), 'attr') ?>">
      </div>
      <button type="submit" class="btn btn-ghost btn-sm">Register account</button>
    </form>
  </div>

  <!-- ── Request a certificate ── -->
  <div class="card" style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-md); padding:20px; margin-bottom:18px;">
    <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin-bottom:6px;">Request a certificate</h3>
    <p style="font-size:0.8rem; color:var(--text-secondary); line-height:1.55; max-width:760px; margin:0 0 6px;">
      An issued certificate is stored in the PKI table like any other, so it can be bound to the Web GUI, an
      OpenVPN or IPsec tunnel, or Squid without a second configuration step.
    </p>
    <p style="font-size:0.8rem; color:var(--text-secondary); line-height:1.55; max-width:760px; margin:0 0 14px;">
      <strong>HTTP-01</strong> is completed by this appliance: each name must resolve here on port 80, and the
      validation file is served at <span style="font-family:var(--font-mono);">/.well-known/acme-challenge/&lt;token&gt;</span>
      for as long as the order is pending.
      <strong>DNS-01</strong> returns the TXT records for you to publish, which is the option that works when the
      name does not point at this appliance at all.
    </p>

    <form method="post" action="<?= base_url('certificates/acme/request') ?>" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; align-items:end; margin:0;">
      <?= csrf_field() ?>
      <div>
        <label style="display:block; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); margin-bottom:4px;">Directory</label>
        <select name="directory" class="mno-input" style="width:100%;">
          <?php foreach ($acmeDirectories as $name => $url): ?>
            <option value="<?= esc($url, 'attr') ?>"<?= $name === 'letsencrypt-staging' ? '' : ' selected' ?>><?= esc(\App\Libraries\AcmeManager::directoryLabel($url)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="display:block; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); margin-bottom:4px;">Challenge</label>
        <select name="challenge_type" class="mno-input" style="width:100%;">
          <option value="http-01">HTTP-01 (this appliance serves the file)</option>
          <option value="dns-01">DNS-01 (you publish the TXT record)</option>
        </select>
      </div>
      <div>
        <label style="display:block; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); margin-bottom:4px;">Type</label>
        <select name="cert_type" class="mno-input" style="width:100%;">
          <option value="server">Server (TLS)</option>
          <option value="vpn">VPN</option>
          <option value="client">Client</option>
          <option value="user">User</option>
        </select>
      </div>
      <div style="grid-column:1 / -1;">
        <label style="display:block; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); margin-bottom:4px;">Domains (comma separated; the first becomes the Common Name)</label>
        <input type="text" name="domains" class="mno-input" style="width:100%; font-family:var(--font-mono);"
               placeholder="vpn.example.com, www.vpn.example.com" required>
      </div>
      <div>
        <label style="display:block; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); margin-bottom:4px;">Label (optional)</label>
        <input type="text" name="name" class="mno-input" style="width:100%;" placeholder="VPN Portal">
      </div>
      <div>
        <button type="submit" class="btn btn-primary btn-sm" style="width:100%;">Request &amp; validate</button>
      </div>
    </form>

    <?php if ($acmePending > 0): ?>
      <p style="font-size:0.75rem; color:var(--color-amber); margin:12px 0 0;">
        <?= (int) $acmePending ?> validation file(s) are currently being served for in-flight orders.
      </p>
    <?php endif; ?>
  </div>

  <!-- ── Orders ── -->
  <div class="card" style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-md); overflow:hidden; margin-bottom:18px;">
    <div style="padding:12px 18px; background:var(--color-surface-3); border-bottom:1px solid var(--color-border); font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-secondary);">
      Orders
    </div>
    <div style="overflow-x:auto;">
      <table class="mno-table">
        <thead>
          <tr><th>#</th><th>Names</th><th>Challenge</th><th>Status</th><th>Certificate</th><th style="text-align:right;">Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($acmeOrders as $order): ?>
            <tr>
              <td style="font-family:var(--font-mono); font-size:0.75rem;"><?= (int) $order['id'] ?></td>
              <td style="font-family:var(--font-mono); font-size:0.72rem; color:var(--color-cyan); max-width:280px; word-break:break-all;"><?= esc((string) $order['domains']) ?></td>
              <td><span class="mno-obj-chip"><?= esc(strtoupper((string) $order['challenge_type'])) ?></span></td>
              <td>
                <?php $status = (string) $order['status']; ?>
                <span class="badge <?= $status === 'valid' ? 'badge-success' : ($status === 'failed' ? 'badge-error' : 'badge-muted') ?>" style="font-size:0.68rem;"><?= esc(strtoupper($status)) ?></span>
                <?php if (! empty($order['last_error'])): ?>
                  <div style="font-size:0.7rem; color:var(--text-muted); margin-top:4px; max-width:320px;"><?= esc((string) $order['last_error']) ?></div>
                <?php endif; ?>
              </td>
              <td style="font-size:0.75rem;">
                <?php if (! empty($order['cert_name'])): ?>
                  <?= esc((string) $order['cert_name']) ?>
                <?php else: ?>
                  <span style="color:var(--text-muted);">—</span>
                <?php endif; ?>
              </td>
              <td style="text-align:right;">
                <?php if ($status !== 'valid'): ?>
                  <form method="post" action="<?= base_url('certificates/acme/check/' . (int) $order['id']) ?>" style="margin:0; display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-cyan); font-size:0.75rem;">Check &amp; finish</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($acmeOrders === []): ?>
            <tr><td colspan="6" style="text-align:center; padding:28px; color:var(--text-muted);">
              No ACME orders yet — request a certificate above.
            </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Due for renewal ── -->
  <div class="card" style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-md); overflow:hidden;">
    <div style="padding:12px 18px; background:var(--color-surface-3); border-bottom:1px solid var(--color-border); font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-secondary);">
      Due for renewal (within <?= (int) $acmeRenewWindow ?> days)
    </div>
    <div style="overflow-x:auto;">
      <table class="mno-table">
        <thead>
          <tr><th>Certificate</th><th>Names</th><th>Expires</th><th>Days left</th><th style="text-align:right;">Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($acmeDue as $due): ?>
            <tr>
              <td style="font-weight:600; color:var(--text-primary); font-size:0.8125rem;"><?= esc((string) $due['name']) ?></td>
              <td style="font-family:var(--font-mono); font-size:0.72rem; color:var(--color-cyan); max-width:280px; word-break:break-all;"><?= esc((string) ($due['domains'] ?? $due['san_dns'])) ?></td>
              <td style="font-family:var(--font-mono); font-size:0.75rem;"><?= esc((string) $due['valid_until']) ?></td>
              <td style="font-size:0.75rem; color:<?= (int) $due['days_left'] < 7 ? 'var(--color-red)' : 'var(--color-amber)' ?>;"><?= (int) $due['days_left'] ?></td>
              <td style="text-align:right;">
                <form method="post" action="<?= base_url('certificates/acme/renew/' . (int) $due['id']) ?>" style="margin:0; display:inline;">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-cyan); font-size:0.75rem;">Renew now</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($acmeDue === []): ?>
            <tr><td colspan="5" style="text-align:center; padding:28px; color:var(--text-muted);">
              Nothing is inside the renewal window. The scheduled task re-checks daily.
            </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════
     TAB 5: SQUID SSL-BUMP & PKI TRUST SETUP GUIDE
══════════════════════════════════════════════════════════════ -->
<div class="cert-panel animate-in animate-in-delay-2" id="tab-guide">
  <div class="card" style="background:var(--color-surface-2); border:1px solid var(--color-border); border-radius:var(--radius-md); padding:24px;">
    <div style="margin-bottom:20px;">
      <h3 style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin-bottom:6px;">
        Enterprise Public Key Infrastructure &amp; SSL-Bump Deep Packet Inspection
      </h3>
      <p style="font-size:0.8125rem; color:var(--text-secondary); line-height:1.6; max-width:850px;">
        MicronOS provides fully autonomous cryptographic lifecycle management for enterprise edge services without requiring external cloud dependencies. Use these guides to bind trust anchors across your endpoints.
      </p>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:18px;">
      <!-- Guide 1 -->
      <div class="guide-card">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
          <div style="width:32px; height:32px; border-radius:6px; background:rgba(0, 212, 255, 0.15); border:1px solid rgba(0, 212, 255, 0.35); display:flex; align-items:center; justify-content:center; color:var(--color-cyan);">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
            </svg>
          </div>
          <h4 style="font-size:0.875rem; font-weight:700; color:var(--color-cyan); margin:0;">Squid SSL-Bump DPI</h4>
        </div>
        <p style="font-size:0.78rem; color:var(--text-secondary); line-height:1.55; margin-bottom:12px;">
          Export the <strong>MicronOS Root CA</strong> CRT certificate and distribute it via Active Directory GPO, Microsoft Intune, or Apple MDM into client <em>Trusted Root Certification Authorities</em>. This enables safe malware inspection inside encrypted TLS sessions.
        </p>
        <span class="mno-obj-chip mno-obj-chip-lan">TLS Interception Ready</span>
      </div>

      <!-- Guide 2 -->
      <div class="guide-card">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
          <div style="width:32px; height:32px; border-radius:6px; background:rgba(245, 158, 11, 0.15); border:1px solid rgba(245, 158, 11, 0.35); display:flex; align-items:center; justify-content:center; color:var(--color-amber);">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
            </svg>
          </div>
          <h4 style="font-size:0.875rem; font-weight:700; color:var(--color-amber); margin:0;">Road Warrior VPN Auth</h4>
        </div>
        <p style="font-size:0.78rem; color:var(--text-secondary); line-height:1.55; margin-bottom:12px;">
          OpenVPN and IPsec profiles automatically bundle the user's cryptographic X.509 certificate and private key inside unified client profiles. Road warriors can download them directly from the self-service portal.
        </p>
        <span class="mno-obj-chip mno-obj-chip-wan">Zero-Trust Mutual TLS</span>
      </div>

      <!-- Guide 3 -->
      <div class="guide-card">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
          <div style="width:32px; height:32px; border-radius:6px; background:rgba(16, 185, 129, 0.15); border:1px solid rgba(16, 185, 129, 0.35); display:flex; align-items:center; justify-content:center; color:var(--color-emerald);">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
          </div>
          <h4 style="font-size:0.875rem; font-weight:700; color:var(--color-emerald); margin:0;">Web GUI HTTPS Management</h4>
        </div>
        <p style="font-size:0.78rem; color:var(--text-secondary); line-height:1.55; margin-bottom:12px;">
          Bind custom wildcard or FQDN certificates to Nginx/Lighttpd to eliminate untrusted browser certificate warnings on internal administrative subnets.
        </p>
        <span class="mno-obj-chip mno-obj-chip-success">HTTPS Strict Security</span>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     MODAL: ISSUE CERTIFICATE
══════════════════════════════════════════════════════════════ -->
<div class="mno-modal-backdrop" id="createCertModal" onclick="if(event.target===this)closeModal('createCertModal')">
  <div class="mno-modal-dialog">
    <form action="<?= base_url('certificates/create') ?>" method="post">
      <?= csrf_field() ?>
      <div class="mno-modal-header">
        <div class="mno-modal-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
          </svg>
          Issue New X.509 Certificate
        </div>
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('createCertModal')" style="padding:4px 8px;">✕</button>
      </div>

      <div class="mno-modal-body">
        <div class="mno-grid-2">
          <div class="mno-form-group">
            <label class="mno-form-label">Signing Certificate Authority</label>
            <select class="mno-select" name="ca_id" required>
              <?php foreach ($cas as $ca): ?>
                <option value="<?= esc($ca['id']) ?>"><?= esc($ca['name']) ?> (<?= esc($ca['common_name']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mno-form-group">
            <label class="mno-form-label">Certificate Type</label>
            <select class="mno-select" name="cert_type">
              <option value="server">Server (HTTPS Web GUI / Reverse Proxy)</option>
              <option value="vpn">VPN Server (OpenVPN / IPsec IKEv2)</option>
              <option value="client">Client / User (Road Warrior VPN)</option>
            </select>
          </div>
        </div>

        <div class="mno-grid-2">
          <div class="mno-form-group">
            <label class="mno-form-label">Descriptive Friendly Name</label>
            <input type="text" class="mno-input" name="name" placeholder="e.g. gateway.internal.corp" required>
          </div>

          <div class="mno-form-group">
            <label class="mno-form-label">Common Name (CN / FQDN)</label>
            <input type="text" class="mno-input" style="font-family:var(--font-mono);" name="common_name" placeholder="gateway.internal.corp" required>
          </div>
        </div>

        <div class="mno-grid-2">
          <div class="mno-form-group">
            <label class="mno-form-label">SAN DNS Names (comma-separated)</label>
            <input type="text" class="mno-input" style="font-family:var(--font-mono);" name="san_dns" placeholder="gateway.local, firewall.corp">
          </div>

          <div class="mno-form-group">
            <label class="mno-form-label">SAN IP Addresses (comma-separated)</label>
            <input type="text" class="mno-input" style="font-family:var(--font-mono);" name="san_ip" placeholder="192.168.1.1, 10.0.0.1">
          </div>
        </div>

        <div class="mno-grid-2">
          <div class="mno-form-group">
            <label class="mno-form-label">RSA Key Length</label>
            <select class="mno-select" name="key_length">
              <option value="2048" selected>2048-bit (Standard RSA)</option>
              <option value="4096">4096-bit (High Security RSA)</option>
            </select>
          </div>

          <div class="mno-form-group">
            <label class="mno-form-label">Validity Period (Days)</label>
            <input type="number" class="mno-input" name="valid_days" value="825" min="30" max="3650" required>
          </div>
        </div>

        <div class="mno-form-group" style="margin-bottom:0;">
          <label class="mno-form-label">Notes / Description (Optional)</label>
          <input type="text" class="mno-input" name="description" placeholder="e.g. Primary TLS management cert for web admin console">
        </div>
      </div>

      <div class="mno-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('createCertModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
          </svg>
          Generate &amp; Sign Certificate
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     MODAL: GENERATE ROOT CA
══════════════════════════════════════════════════════════════ -->
<div class="mno-modal-backdrop" id="createCaModal" onclick="if(event.target===this)closeModal('createCaModal')">
  <div class="mno-modal-dialog">
    <form action="<?= base_url('certificates/create-ca') ?>" method="post">
      <?= csrf_field() ?>
      <div class="mno-modal-header">
        <div class="mno-modal-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-purple);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
          </svg>
          Generate Internal Certificate Authority (CA)
        </div>
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('createCaModal')" style="padding:4px 8px;">✕</button>
      </div>

      <div class="mno-modal-body">
        <div class="mno-grid-2">
          <div class="mno-form-group">
            <label class="mno-form-label">CA Friendly Name</label>
            <input type="text" class="mno-input" name="name" placeholder="e.g. Enterprise Subordinate CA" required>
          </div>

          <div class="mno-form-group">
            <label class="mno-form-label">Common Name (CN)</label>
            <input type="text" class="mno-input" style="font-family:var(--font-mono);" name="common_name" placeholder="Enterprise Root Authority" required>
          </div>
        </div>

        <div style="display:grid; grid-template-columns:2fr 1fr 1.5fr; gap:12px;" class="mno-form-group">
          <div>
            <label class="mno-form-label">Organization Name</label>
            <input type="text" class="mno-input" name="organization" value="MicronOS Enterprise">
          </div>
          <div>
            <label class="mno-form-label">Country (2-Letter)</label>
            <input type="text" class="mno-input" name="country" value="US" maxlength="2" style="text-transform:uppercase;">
          </div>
          <div>
            <label class="mno-form-label">State / Province</label>
            <input type="text" class="mno-input" name="state" value="California">
          </div>
        </div>

        <div class="mno-grid-2">
          <div class="mno-form-group">
            <label class="mno-form-label">RSA Key Length</label>
            <select class="mno-select" name="key_length">
              <option value="4096" selected>4096-bit (Recommended for CA)</option>
              <option value="2048">2048-bit</option>
            </select>
          </div>

          <div class="mno-form-group">
            <label class="mno-form-label">Validity Period (Days)</label>
            <input type="number" class="mno-input" name="valid_days" value="3650" min="365" max="7300">
          </div>
        </div>
      </div>

      <div class="mno-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('createCaModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
          </svg>
          Generate Root CA
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     MODAL: IMPORT CERTIFICATE AUTHORITY
══════════════════════════════════════════════════════════════ -->
<div class="mno-modal-backdrop" id="importCaModal" onclick="if(event.target===this)closeModal('importCaModal')">
  <div class="mno-modal-dialog">
    <form action="<?= base_url('certificates/import-ca') ?>" method="post">
      <?= csrf_field() ?>
      <div class="mno-modal-header">
        <div class="mno-modal-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-purple);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
          </svg>
          Import an Existing Certificate Authority (CA)
        </div>
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('importCaModal')" style="padding:4px 8px;">✕</button>
      </div>

      <div class="mno-modal-body">
        <p style="font-size:0.78rem; color:var(--text-secondary); line-height:1.55; margin:0 0 14px;">
          Install a CA issued elsewhere (a public CA, a corporate PKI, or a partner firewall). Paste the PEM certificate —
          and, if this firewall must also <em>sign</em> certificates under this CA, its private key.
        </p>

        <div class="mno-form-group">
          <label class="mno-form-label">Friendly Name <abbr style="font-weight:400; color:var(--text-muted);">Optional — defaults to the CN</abbr></label>
          <input type="text" class="mno-input" name="name" placeholder="e.g. DigiCert Global Root G2">
        </div>

        <div class="mno-form-group">
          <label class="mno-form-label">CA Certificate (PEM)</label>
          <textarea class="mno-input" name="certificate" rows="7" required style="font-family:var(--font-mono); font-size:0.72rem; resize:vertical;" placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----"></textarea>
        </div>

        <div class="mno-form-group" style="margin-bottom:0;">
          <label class="mno-form-label">CA Private Key (PEM) <abbr style="font-weight:400; color:var(--text-muted);">Optional — required only to sign new certificates</abbr></label>
          <textarea class="mno-input" name="private_key" rows="5" style="font-family:var(--font-mono); font-size:0.72rem; resize:vertical;" placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----"></textarea>
        </div>
      </div>

      <div class="mno-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('importCaModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Import Certificate Authority</button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     MODAL: IMPORT CERTIFICATE
══════════════════════════════════════════════════════════════ -->
<div class="mno-modal-backdrop" id="importCertModal" onclick="if(event.target===this)closeModal('importCertModal')">
  <div class="mno-modal-dialog">
    <form action="<?= base_url('certificates/import') ?>" method="post">
      <?= csrf_field() ?>
      <div class="mno-modal-header">
        <div class="mno-modal-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
          </svg>
          Import an Existing Certificate
        </div>
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('importCertModal')" style="padding:4px 8px;">✕</button>
      </div>

      <div class="mno-modal-body">
        <p style="font-size:0.78rem; color:var(--text-secondary); line-height:1.55; margin:0 0 14px;">
          Install a certificate issued by a certificate authority (public or internal) — for the Web GUI, VPN services,
          Squid SSL-Bump or user authentication. Subject, SANs and validity are read from the pasted certificate.
        </p>

        <div class="mno-grid-2">
          <div class="mno-form-group">
            <label class="mno-form-label">Friendly Name <abbr style="font-weight:400; color:var(--text-muted);">Optional — defaults to the CN</abbr></label>
            <input type="text" class="mno-input" name="name" placeholder="e.g. vpn.acme.com">
          </div>
          <div class="mno-form-group">
            <label class="mno-form-label">Certificate Type</label>
            <select class="mno-select" name="cert_type">
              <option value="server">Server (Web GUI / Reverse Proxy)</option>
              <option value="vpn">VPN Server (OpenVPN / IPsec IKEv2)</option>
              <option value="client">Client / User (Road Warrior VPN)</option>
            </select>
          </div>
        </div>

        <div class="mno-form-group">
          <label class="mno-form-label">Issuing Certificate Authority <abbr style="font-weight:400; color:var(--text-muted);">Optional — auto-detected from the issuer</abbr></label>
          <select class="mno-select" name="ca_id">
            <option value="0">Auto-detect / not in the trust store</option>
            <?php foreach ($cas as $ca): ?>
              <option value="<?= esc($ca['id']) ?>"><?= esc($ca['name']) ?> (<?= esc($ca['common_name']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mno-form-group">
          <label class="mno-form-label">Certificate (PEM)</label>
          <textarea class="mno-input" name="certificate" rows="6" required style="font-family:var(--font-mono); font-size:0.72rem; resize:vertical;" placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----"></textarea>
        </div>

        <div class="mno-form-group">
          <label class="mno-form-label">Private Key (PEM) <abbr style="font-weight:400; color:var(--text-muted);">Optional — omit for public-only / trust certificates</abbr></label>
          <textarea class="mno-input" name="private_key" rows="5" style="font-family:var(--font-mono); font-size:0.72rem; resize:vertical;" placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----"></textarea>
        </div>

        <div class="mno-form-group" style="margin-bottom:0;">
          <label class="mno-form-label">Notes / Description <abbr style="font-weight:400; color:var(--text-muted);">Optional</abbr></label>
          <input type="text" class="mno-input" name="description" placeholder="e.g. Wildcard cert for *.acme.com">
        </div>
      </div>

      <div class="mno-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('importCertModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Import Certificate</button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     MODAL: CREATE CERTIFICATE SIGNING REQUEST (CSR)
══════════════════════════════════════════════════════════════ -->
<div class="mno-modal-backdrop" id="csrModal" onclick="if(event.target===this)closeModal('csrModal')">
  <div class="mno-modal-dialog">
    <form action="<?= base_url('certificates/create-csr') ?>" method="post">
      <?= csrf_field() ?>
      <div class="mno-modal-header">
        <div class="mno-modal-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-amber);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
          </svg>
          Create a Certificate Signing Request (CSR)
        </div>
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('csrModal')" style="padding:4px 8px;">✕</button>
      </div>

      <div class="mno-modal-body">
        <p style="font-size:0.78rem; color:var(--text-secondary); line-height:1.55; margin:0 0 14px;">
          Generate a keypair and a CSR, download the request, have it signed by a public CA (or an internal CA),
          then paste the signed certificate back into the pending entry.
        </p>

        <div class="mno-grid-2">
          <div class="mno-form-group">
            <label class="mno-form-label">Descriptive Friendly Name</label>
            <input type="text" class="mno-input" name="name" placeholder="e.g. portal.acme.com" required>
          </div>
          <div class="mno-form-group">
            <label class="mno-form-label">Common Name (CN / FQDN)</label>
            <input type="text" class="mno-input" style="font-family:var(--font-mono);" name="common_name" placeholder="portal.acme.com" required>
          </div>
        </div>

        <div class="mno-form-group">
          <label class="mno-form-label">Certificate Type</label>
          <select class="mno-select" name="cert_type">
            <option value="server">Server (Web GUI / Reverse Proxy)</option>
            <option value="vpn">VPN Server (OpenVPN / IPsec IKEv2)</option>
            <option value="client">Client / User (Road Warrior VPN)</option>
          </select>
        </div>

        <div class="mno-grid-2">
          <div class="mno-form-group">
            <label class="mno-form-label">SAN DNS Names (comma-separated)</label>
            <input type="text" class="mno-input" style="font-family:var(--font-mono);" name="san_dns" placeholder="portal.acme.com, www.acme.com">
          </div>
          <div class="mno-form-group">
            <label class="mno-form-label">SAN IP Addresses (comma-separated)</label>
            <input type="text" class="mno-input" style="font-family:var(--font-mono);" name="san_ip" placeholder="192.168.1.1, 10.0.0.1">
          </div>
        </div>

        <div class="mno-grid-2">
          <div class="mno-form-group">
            <label class="mno-form-label">RSA Key Length</label>
            <select class="mno-select" name="key_length">
              <option value="2048" selected>2048-bit (Standard RSA)</option>
              <option value="4096">4096-bit (High Security RSA)</option>
            </select>
          </div>
          <div class="mno-form-group">
            <label class="mno-form-label">Notes / Description <abbr style="font-weight:400; color:var(--text-muted);">Optional</abbr></label>
            <input type="text" class="mno-input" name="description" placeholder="e.g. Public CA request for the portal">
          </div>
        </div>
      </div>

      <div class="mno-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('csrModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Generate CSR</button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     MODAL: COMPLETE CSR (paste the signed certificate)
══════════════════════════════════════════════════════════════ -->
<div class="mno-modal-backdrop" id="completeCsrModal" onclick="if(event.target===this)closeModal('completeCsrModal')">
  <div class="mno-modal-dialog">
    <form id="completeCsrForm" action="" method="post">
      <?= csrf_field() ?>
      <div class="mno-modal-header">
        <div class="mno-modal-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-emerald);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
          </svg>
          Paste Signed Certificate — <span id="completeCsrName" style="color:var(--color-cyan);"></span>
        </div>
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('completeCsrModal')" style="padding:4px 8px;">✕</button>
      </div>

      <div class="mno-modal-body">
        <p style="font-size:0.78rem; color:var(--text-secondary); line-height:1.55; margin:0 0 14px;">
          Paste the certificate returned by the signing authority. It will be matched to the private key already held for this entry.
        </p>
        <div class="mno-form-group" style="margin-bottom:0;">
          <label class="mno-form-label">Signed Certificate (PEM)</label>
          <textarea class="mno-input" name="certificate" rows="10" required style="font-family:var(--font-mono); font-size:0.72rem; resize:vertical;" placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----"></textarea>
        </div>
      </div>

      <div class="mno-modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal('completeCsrModal')">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Attach Signed Certificate</button>
      </div>
    </form>
  </div>
</div>

<!-- ── JavaScript for Tabs & Modals ── -->
<script>
function switchCertTab(tabId, el) {
  document.querySelectorAll('.cert-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.cert-panel').forEach(p => p.classList.remove('active'));

  if (el) el.classList.add('active');
  const panel = document.getElementById(tabId);
  if (panel) panel.classList.add('active');
}

function openModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.add('active');
}

function closeModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.remove('active');
}

function openCompleteCsr(id, name) {
  const form = document.getElementById('completeCsrForm');
  if (!form) return;
  form.action = '<?= base_url('certificates/complete-csr') ?>/' + encodeURIComponent(id);
  const label = document.getElementById('completeCsrName');
  if (label) label.textContent = name || '';
  openModal('completeCsrModal');
}

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.mno-modal-backdrop.active').forEach(m => m.classList.remove('active'));
  }
});
</script>

<?= $this->endSection() ?>
