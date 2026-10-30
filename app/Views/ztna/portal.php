<?= $this->extend(! empty($gatewayMode) ? 'layouts/gateway' : 'layouts/main') ?>
<?= $this->section('content') ?>

<!-- Custom Corporate Branding Stylesheet Injection -->
<style>
:root {
  --brand-primary: <?= esc($settings['brand_color'] ?? '#6366f1') ?>;
  --brand-hover: <?= esc($settings['brand_color_hover'] ?? '#4f46e5') ?>;
  --brand-accent: <?= esc($settings['brand_accent_color'] ?? '#10b981') ?>;
}
.portal-brand-btn {
  background: var(--brand-primary) !important;
  color: #ffffff !important;
  border: 1px solid rgba(255,255,255,0.12) !important;
  transition: all 0.2s ease !important;
}
.portal-brand-btn:hover {
  background: var(--brand-hover) !important;
  box-shadow: 0 4px 14px rgba(0,0,0,0.3) !important;
  transform: translateY(-1px) !important;
}
.filter-pill.active {
  background: var(--brand-primary) !important;
  color: #ffffff !important;
}
.view-mode-btn.active {
  background: var(--brand-primary) !important;
  color: #ffffff !important;
}

/* ── List Mode Layout ── */
#portalResourceContainer.list-mode {
  display: flex !important;
  flex-direction: column !important;
  gap: 10px !important;
}
#portalResourceContainer.list-mode .portal-resource-card {
  flex-direction: row !important;
  align-items: center !important;
  justify-content: space-between !important;
  padding: 14px 20px !important;
  gap: 16px !important;
}
#portalResourceContainer.list-mode .portal-resource-card .card-main-meta {
  display: flex !important;
  align-items: center !important;
  gap: 16px !important;
  flex: 1 !important;
  min-width: 0 !important;
}
#portalResourceContainer.list-mode .portal-resource-card .card-icon-box {
  width: 40px !important;
  height: 40px !important;
  font-size: 1.3rem !important;
  margin-bottom: 0 !important;
  flex-shrink: 0 !important;
}
#portalResourceContainer.list-mode .portal-resource-card .card-title-group {
  min-width: 0 !important;
}
#portalResourceContainer.list-mode .portal-resource-card .card-title {
  margin: 0 !important;
  font-size: 0.95rem !important;
  display: flex !important;
  align-items: center !important;
  gap: 8px !important;
}
#portalResourceContainer.list-mode .portal-resource-card .card-desc {
  margin: 3px 0 0 !important;
  font-size: 0.75rem !important;
  white-space: nowrap !important;
  overflow: hidden !important;
  text-overflow: ellipsis !important;
  max-width: 380px !important;
}
#portalResourceContainer.list-mode .portal-resource-card .card-action-group {
  display: flex !important;
  align-items: center !important;
  gap: 20px !important;
  flex-shrink: 0 !important;
}
#portalResourceContainer.list-mode .portal-resource-card .card-target-info {
  border-top: none !important;
  padding-top: 0 !important;
  margin-bottom: 0 !important;
  display: flex !important;
  align-items: center !important;
  gap: 14px !important;
}
#portalResourceContainer.list-mode .portal-resource-card .card-launch-btn {
  width: auto !important;
  padding: 8px 18px !important;
}

@media (max-width: 768px) {
  #portalResourceContainer.list-mode .portal-resource-card {
    flex-direction: column !important;
    align-items: stretch !important;
  }
  #portalResourceContainer.list-mode .portal-resource-card .card-action-group {
    flex-direction: column !important;
    align-items: stretch !important;
    gap: 10px !important;
  }
  #portalResourceContainer.list-mode .portal-resource-card .card-launch-btn {
    width: 100% !important;
  }
}

/* ── Portal Page Background Theme (Light / Gray / Dark) ── */
<?php $portalTheme = $settings['portal_theme'] ?? 'light'; ?>
<?php if ($portalTheme === 'light'): ?>
  body, .main-content, #pageMain {
    background: #f8fafc !important;
  }
  .portal-hero-box {
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    box-shadow: 0 4px 20px rgba(0,0,0,0.04) !important;
  }
  .portal-hero-box h1 {
    color: #0f172a !important;
  }
  .portal-hero-box p {
    color: #475569 !important;
  }
  .portal-resource-card {
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.04) !important;
  }
  .portal-resource-card:hover {
    border-color: var(--brand-primary) !important;
    box-shadow: 0 8px 24px rgba(0,0,0,0.08) !important;
  }
  .portal-resource-card .card-title {
    color: #0f172a !important;
  }
  .portal-resource-card .card-desc {
    color: #475569 !important;
  }
  .portal-resource-card .card-target-info {
    border-color: #f1f5f9 !important;
    color: #64748b !important;
  }
  .portal-resource-card .card-target-info code {
    background: #f1f5f9 !important;
    color: #0f172a !important;
  }
  .portal-resource-card .card-icon-box {
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
  }
  .portal-resource-card .card-badge {
    background: #f1f5f9 !important;
    color: #475569 !important;
    border: 1px solid #e2e8f0 !important;
  }
<?php elseif ($portalTheme === 'gray'): ?>
  body, .main-content, #pageMain {
    background: #f1f5f9 !important;
  }
  .portal-hero-box {
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    box-shadow: 0 4px 20px rgba(0,0,0,0.05) !important;
  }
  .portal-hero-box h1 {
    color: #0f172a !important;
  }
  .portal-hero-box p {
    color: #475569 !important;
  }
  .portal-resource-card {
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.04) !important;
  }
  .portal-resource-card:hover {
    border-color: var(--brand-primary) !important;
    box-shadow: 0 8px 24px rgba(0,0,0,0.08) !important;
  }
  .portal-resource-card .card-title {
    color: #0f172a !important;
  }
  .portal-resource-card .card-desc {
    color: #475569 !important;
  }
  .portal-resource-card .card-target-info {
    border-color: #e2e8f0 !important;
    color: #64748b !important;
  }
  .portal-resource-card .card-target-info code {
    background: #e2e8f0 !important;
    color: #0f172a !important;
  }
  .portal-resource-card .card-icon-box {
    background: #f8fafc !important;
    border: 1px solid #cbd5e1 !important;
  }
  .portal-resource-card .card-badge {
    background: #f1f5f9 !important;
    color: #475569 !important;
    border: 1px solid #cbd5e1 !important;
  }
<?php else: ?>
  /* Dark Midnight Navy */
  body, .main-content, #pageMain {
    background: #070b14 !important;
  }
  .portal-hero-box {
    background: linear-gradient(135deg, rgba(255,255,255,0.03), rgba(0,0,0,0.2)) !important;
    border: 1px solid var(--border-color) !important;
  }
  .portal-resource-card {
    background: rgba(255,255,255,0.03) !important;
    border: 1px solid var(--border-color) !important;
  }
  .portal-resource-card .card-title {
    color: #f8fafc !important;
  }
  .portal-resource-card .card-desc {
    color: #94a3b8 !important;
  }
<?php endif; ?>

<?= $settings['custom_css'] ?? '' ?>
</style>

<div style="max-width:1150px; margin:0 auto; padding:24px 0 60px;">

  <!-- ── Notice / Announcement Banner ── -->
  <?php if (!empty($settings['notice_banner'])): ?>
    <?php
      $noticeType = $settings['notice_banner_type'] ?? 'info';
      $bg = 'rgba(59,130,246,0.12)';
      $fg = '#60a5fa';
      $border = 'rgba(59,130,246,0.25)';
      $icon = '📢';
      if ($noticeType === 'warning') {
          $bg = 'rgba(245,158,11,0.12)';
          $fg = '#fbbf24';
          $border = 'rgba(245,158,11,0.3)';
          $icon = '⚠️';
      } elseif ($noticeType === 'danger') {
          $bg = 'rgba(239,68,68,0.12)';
          $fg = '#f87171';
          $border = 'rgba(239,68,68,0.3)';
          $icon = '🛑';
      } elseif ($noticeType === 'success') {
          $bg = 'rgba(16,185,129,0.12)';
          $fg = '#34d399';
          $border = 'rgba(16,185,129,0.3)';
          $icon = '✅';
      }
    ?>
    <div class="card animate-in" style="margin-bottom:20px; padding:12px 18px; display:flex; align-items:center; gap:12px; background:<?= $bg ?>; color:<?= $fg ?>; border:1px solid <?= $border ?>; border-radius:10px;">
      <span style="font-size:1.15rem;"><?= $icon ?></span>
      <div style="font-size:0.85rem; font-weight:600; line-height:1.4;">
        <?= esc($settings['notice_banner']) ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- ── Corporate Hero Banner ── -->
  <div class="card card-glass portal-hero-box animate-in" style="padding:32px 36px; margin-bottom:28px; position:relative; overflow:hidden;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:20px;">
      <div style="flex:1; min-width:320px;">
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">
          <?php if (!empty($settings['logo_url'])): ?>
            <img src="<?= esc($settings['logo_url']) ?>" alt="<?= esc($settings['company_name'] ?? 'Company Logo') ?>" style="max-height:44px; max-width:180px; object-fit:contain;" onerror="this.style.display='none'; document.getElementById('heroFallbackCrest').style.display='flex';">
            <div id="heroFallbackCrest" style="display:none; width:38px; height:38px; border-radius:8px; background:var(--brand-primary); align-items:center; justify-content:center; color:#fff; font-weight:800; font-size:1.1rem;">
              <?= strtoupper(substr(esc($settings['company_name'] ?? 'M'), 0, 1)) ?>
            </div>
          <?php else: ?>
            <div style="width:38px; height:38px; border-radius:8px; background:var(--brand-primary); display:flex; align-items:center; justify-content:center; color:#fff; font-weight:800; font-size:1.1rem; box-shadow:0 4px 12px rgba(0,0,0,0.3);">
              <?= strtoupper(substr(esc($settings['company_name'] ?? 'M'), 0, 1)) ?>
            </div>
          <?php endif; ?>

          <div>
            <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em; font-weight:700; color:var(--brand-primary);">
              <?= esc($settings['company_name'] ?? 'Micron Security') ?>
            </div>
            <div style="font-size:0.72rem; color:var(--text-muted);">
              Zero Trust Clientless Access
            </div>
          </div>
        </div>

        <h1 style="margin:0 0 10px; font-size:1.65rem; font-weight:800; color:var(--text-primary); letter-spacing:-0.02em;">
          <?= esc($settings['welcome_heading'] ?? 'Zero-Trust Clientless Access Portal') ?>
        </h1>
        <p style="margin:0; font-size:0.88rem; color:var(--text-secondary); max-width:680px; line-height:1.55;">
          <?= esc($settings['welcome_subtitle'] ?? 'Secure, zero-install in-browser access to internal desktop workspaces, terminals, and corporate web applications.') ?>
        </p>
      </div>

      <div style="display:flex; flex-direction:column; align-items:flex-end; gap:8px;">
        <span class="badge badge-emerald" style="padding:6px 14px; font-size:0.78rem; display:inline-flex; align-items:center; gap:6px;">
          <span style="width:6px; height:6px; border-radius:50%; background:var(--color-emerald); display:inline-block;"></span>
          Identity Verified: <?= esc($username) ?>
        </span>
        <div style="font-size:0.72rem; color:var(--text-muted); font-family:monospace;">
          Domain: <?= esc($settings['external_domain'] ?? 'gateway.micronos.local') ?>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Resource Section Header ── -->
  <div style="margin-bottom:14px; display:flex; justify-content:space-between; align-items:center;">
    <h2 style="margin:0; font-size:1.1rem; font-weight:700; color:var(--text-primary);">
      Authorized Corporate Applications (<?= count($resources) ?>)
    </h2>
    <div style="font-size:0.75rem; color:var(--text-muted);">
      Single Sign-On Authenticated
    </div>
  </div>

  <?php
    $protocolCounts = [
      'web' => 0,
      'rdp' => 0,
      'ssh' => 0,
      'vnc' => 0,
    ];
    foreach ($resources as $res) {
        $proto = strtolower($res['type'] ?? 'web');
        if (isset($protocolCounts[$proto])) {
            $protocolCounts[$proto]++;
        }
    }
    $activeProtocols = array_filter($protocolCounts, fn($cnt) => $cnt > 0);
  ?>

  <?php if (!empty($resources)): ?>
  <!-- ── Resource Search, Protocol Filters & View Mode Switcher ── -->
  <div style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;" class="animate-in animate-in-delay-1">
    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
      <?php if (count($activeProtocols) > 1): ?>
        <button type="button" class="btn btn-xs filter-pill active" onclick="filterProtocol('all', this)">All Workspaces (<?= count($resources) ?>)</button>
      <?php endif; ?>
      <?php if (!empty($protocolCounts['web'])): ?>
        <button type="button" class="btn btn-xs filter-pill <?= count($activeProtocols) === 1 ? 'active' : 'btn-ghost' ?>" onclick="filterProtocol('web', this)">🌐 Web Apps<?= count($activeProtocols) > 1 ? ' (' . $protocolCounts['web'] . ')' : '' ?></button>
      <?php endif; ?>
      <?php if (!empty($protocolCounts['rdp'])): ?>
        <button type="button" class="btn btn-xs filter-pill <?= count($activeProtocols) === 1 ? 'active' : 'btn-ghost' ?>" onclick="filterProtocol('rdp', this)">💻 Windows Desktops<?= count($activeProtocols) > 1 ? ' (' . $protocolCounts['rdp'] . ')' : '' ?></button>
      <?php endif; ?>
      <?php if (!empty($protocolCounts['ssh'])): ?>
        <button type="button" class="btn btn-xs filter-pill <?= count($activeProtocols) === 1 ? 'active' : 'btn-ghost' ?>" onclick="filterProtocol('ssh', this)">⌨️ Linux Shells<?= count($activeProtocols) > 1 ? ' (' . $protocolCounts['ssh'] . ')' : '' ?></button>
      <?php endif; ?>
      <?php if (!empty($protocolCounts['vnc'])): ?>
        <button type="button" class="btn btn-xs filter-pill <?= count($activeProtocols) === 1 ? 'active' : 'btn-ghost' ?>" onclick="filterProtocol('vnc', this)">🖥️ Virtual Consoles<?= count($activeProtocols) > 1 ? ' (' . $protocolCounts['vnc'] . ')' : '' ?></button>
      <?php endif; ?>
    </div>

    <div style="display:flex; align-items:center; gap:10px;">
      <div style="width:230px;">
        <input type="text" id="portalSearchInput" class="form-control" style="font-size:0.8rem; padding:6px 12px;" placeholder="Search applications..." oninput="filterCards()">
      </div>

      <!-- Card / List Mode Switcher -->
      <div style="display:inline-flex; background:rgba(0,0,0,0.3); border:1px solid var(--border-color); border-radius:8px; padding:2px; gap:2px;">
        <button type="button" class="btn btn-xs view-mode-btn active" id="btnModeCard" onclick="setViewMode('card')" title="Card Grid Mode" style="padding:4px 8px; display:inline-flex; align-items:center; gap:4px; border-radius:6px;">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
          <span style="font-size:0.7rem; font-weight:600;">Cards</span>
        </button>
        <button type="button" class="btn btn-xs view-mode-btn btn-ghost" id="btnModeList" onclick="setViewMode('list')" title="Compact List Mode" style="padding:4px 8px; display:inline-flex; align-items:center; gap:4px; border-radius:6px;">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
          <span style="font-size:0.7rem; font-weight:600;">List</span>
        </button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Resources Container (Grid or List) ── -->
  <div id="portalResourceContainer" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(330px, 1fr)); gap:20px;" class="animate-in animate-in-delay-2">
    <?php if (empty($resources)): ?>
      <div class="card card-glass" style="grid-column:1/-1; padding:60px 20px; text-align:center; color:var(--text-muted);">
        <div style="font-size:3rem; margin-bottom:12px; opacity:0.5;">🔒</div>
        <div style="font-size:1.1rem; font-weight:700; color:var(--text-primary); margin-bottom:6px;">No Accessible Corporate Workspaces</div>
        <div style="font-size:0.85rem; max-width:480px; margin:0 auto; line-height:1.5;">Your user account does not currently have permissions assigned to published internal resources. Contact your corporate network security administrator.</div>
      </div>
    <?php else: ?>
      <?php foreach ($resources as $res): ?>
        <div class="card card-glass portal-resource-card" data-protocol="<?= esc($res['type']) ?>" data-name="<?= strtolower(esc($res['name'])) ?>" data-desc="<?= strtolower(esc($res['description'] ?? '')) ?>" style="padding:22px; display:flex; flex-direction:column; justify-content:space-between; transition:transform 0.2s ease, box-shadow 0.2s ease; border:1px solid var(--border-color); border-radius:12px;">

          <!-- Main Meta (Left in list mode, Top in card mode) -->
          <div class="card-main-meta">
            <div class="card-icon-box" style="width:46px; height:46px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.5rem; background:rgba(255,255,255,0.04); border:1px solid var(--border-color); margin-bottom:14px;">
              <?php if ($res['type'] === 'rdp'): ?>
                💻
              <?php elseif ($res['type'] === 'ssh'): ?>
                ⌨️
              <?php elseif ($res['type'] === 'vnc'): ?>
                🖥️
              <?php else: ?>
                🌐
              <?php endif; ?>
            </div>

            <div class="card-title-group">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                <h3 class="card-title" style="margin:0; font-size:1.05rem; font-weight:700; color:var(--text-primary);">
                  <?= esc($res['name']) ?>
                </h3>
                <span class="badge card-badge" style="background:rgba(255,255,255,0.06); color:var(--text-secondary); text-transform:uppercase; font-size:0.68rem; letter-spacing:0.04em;">
                  <?= esc($res['type']) ?>
                </span>
              </div>
              <p class="card-desc" style="margin:0 0 16px; font-size:0.8rem; color:var(--text-muted); line-height:1.5;">
                <?= esc($res['description'] ?: 'Secure clientless workstation mediated via Micron OS Zero Trust Gateway.') ?>
              </p>
            </div>
          </div>

          <!-- Action & Target Group (Right in list mode, Bottom in card mode) -->
          <div class="card-action-group">
            <div class="card-target-info" style="display:flex; justify-content:space-between; align-items:center; padding-top:12px; border-top:1px solid var(--border-color); font-size:0.75rem; color:var(--text-muted); margin-bottom:14px;">
              <span>Target: <code style="font-size:0.75rem;"><?= esc($res['internal_host']) ?></code></span>
              <span style="display:flex; align-items:center; gap:4px;">
                <span style="width:6px; height:6px; border-radius:50%; background:var(--color-emerald); display:inline-block;"></span>
                <strong style="color:var(--color-emerald);">Ready</strong>
              </span>
            </div>

            <a href="<?= base_url('vpn/clientless-gateway/launch/' . esc($res['id'])) ?>" class="btn portal-brand-btn card-launch-btn" style="width:100%; display:flex; justify-content:center; align-items:center; gap:8px; font-weight:600; padding:10px 16px; border-radius:8px;">
              Launch Workspace &rarr;
            </a>
          </div>

        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- ── Corporate Footer ── -->
  <div style="margin-top:40px; padding-top:20px; border-top:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px; font-size:0.78rem; color:var(--text-muted);">
    <div>
      <?= esc($settings['footer_text'] ?? '© ' . date('Y') . ' Micron OS Zero-Trust Network Access. All rights reserved.') ?>
    </div>
    <?php if (!empty($settings['help_link'])): ?>
      <div>
        <a href="<?= esc($settings['help_link']) ?>" target="_blank" style="color:var(--brand-primary); text-decoration:underline; font-weight:600;">
          <?= esc($settings['help_link_text'] ?? 'Contact Helpdesk') ?> &rarr;
        </a>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
let currentProtocol = 'all';

// ── View Mode Switcher (Card vs List) ──
function setViewMode(mode) {
  const container = document.getElementById('portalResourceContainer');
  const btnCard = document.getElementById('btnModeCard');
  const btnList = document.getElementById('btnModeList');

  if (mode === 'list') {
    container.classList.add('list-mode');
    btnList.classList.add('active');
    btnList.classList.remove('btn-ghost');
    btnCard.classList.remove('active');
    btnCard.classList.add('btn-ghost');
  } else {
    container.classList.remove('list-mode');
    btnCard.classList.add('active');
    btnCard.classList.remove('btn-ghost');
    btnList.classList.remove('active');
    btnList.classList.add('btn-ghost');
  }

  try {
    localStorage.setItem('micron_ztna_view_mode', mode);
  } catch(e) {}
}

// Restore saved preference on load
(function() {
  try {
    const saved = localStorage.getItem('micron_ztna_view_mode');
    if (saved === 'list') {
      setViewMode('list');
    }
  } catch(e) {}
})();

// ── Protocol Filter ──
function filterProtocol(proto, btn) {
  currentProtocol = proto;
  document.querySelectorAll('.filter-pill').forEach(b => {
    b.classList.remove('active');
    b.classList.add('btn-ghost');
  });
  btn.classList.add('active');
  btn.classList.remove('btn-ghost');
  filterCards();
}

// ── Search Filter ──
function filterCards() {
  const query = (document.getElementById('portalSearchInput').value || '').toLowerCase().trim();
  document.querySelectorAll('.portal-resource-card').forEach(card => {
    const proto = card.dataset.protocol;
    const name = card.dataset.name || '';
    const desc = card.dataset.desc || '';

    const matchesProto = (currentProtocol === 'all' || proto === currentProtocol);
    const matchesQuery = (!query || name.includes(query) || desc.includes(query));

    if (matchesProto && matchesQuery) {
      card.style.display = 'flex';
    } else {
      card.style.display = 'none';
    }
  });
}
</script>

<?= $this->endSection() ?>
