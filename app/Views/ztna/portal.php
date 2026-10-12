<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div style="max-width:1100px; margin:0 auto; padding:20px 0;">
  <!-- ── Portal Hero Banner ── -->
  <div class="card card-glass animate-in" style="padding:28px 32px; margin-bottom:28px; background:linear-gradient(135deg, rgba(99,102,241,0.08), rgba(16,185,129,0.05)); border:1px solid rgba(99,102,241,0.25);">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
      <div>
        <div style="display:inline-flex; align-items:center; gap:6px; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em; font-weight:700; color:var(--color-indigo); margin-bottom:6px;">
          <span style="width:8px; height:8px; border-radius:50%; background:var(--color-indigo); display:inline-block; box-shadow:0 0 6px var(--color-indigo);"></span>
          Zero Trust Clientless Access
        </div>
        <h1 style="margin:0 0 8px; font-size:1.6rem; font-weight:800; color:var(--text-primary);">
          <?= esc($settings['portal_title'] ?? 'Micron OS Clientless Gateway') ?>
        </h1>
        <p style="margin:0; font-size:0.85rem; color:var(--text-secondary); max-width:650px;">
          Welcome, <strong><?= esc($username) ?></strong>. Launch your authorized corporate applications, remote Windows desktops, and Linux shells directly in your browser without installing VPN software.
        </p>
      </div>
      <div>
        <span class="badge badge-emerald" style="padding:8px 14px; font-size:0.8rem;">
          Continuous Identity Verified
        </span>
      </div>
    </div>
  </div>

  <!-- ── Resources Grid ── -->
  <div style="margin-bottom:16px; display:flex; justify-content:space-between; align-items:center;">
    <h2 style="margin:0; font-size:1.1rem; font-weight:700; color:var(--text-primary);">
      Authorized Corporate Applications (<?= count($resources) ?>)
    </h2>
    <div style="font-size:0.78rem; color:var(--text-muted);">
      Single Sign-On Authenticated
    </div>
  </div>

  <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:20px;" class="animate-in animate-in-delay-1">
    <?php if (empty($resources)): ?>
      <div class="card card-glass" style="grid-column:1/-1; padding:50px 20px; text-align:center; color:var(--text-muted);">
        <svg width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin:0 auto 12px; opacity:0.4;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
        <div style="font-size:1rem; font-weight:600; color:var(--text-primary); margin-bottom:4px;">No Accessible Resources</div>
        <div style="font-size:0.82rem;">Your current user role has not been assigned permissions to published internal resources. Contact your network administrator.</div>
      </div>
    <?php else: ?>
      <?php foreach ($resources as $res): ?>
        <div class="card card-glass" style="padding:22px; display:flex; flex-direction:column; justify-content:space-between; transition:transform 0.2s ease, box-shadow 0.2s ease; border:1px solid var(--border-color);">
          <div>
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
              <div style="width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.4rem; background:rgba(99,102,241,0.12); border:1px solid rgba(99,102,241,0.25);">
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
              <span class="badge" style="background:rgba(255,255,255,0.06); color:var(--text-secondary); text-transform:uppercase; font-size:0.68rem; letter-spacing:0.04em;">
                <?= esc($res['type']) ?>
              </span>
            </div>

            <h3 style="margin:0 0 6px; font-size:1.05rem; font-weight:700; color:var(--text-primary);">
              <?= esc($res['name']) ?>
            </h3>
            <p style="margin:0 0 16px; font-size:0.8rem; color:var(--text-muted); line-height:1.5;">
              <?= esc($res['description'] ?: 'Secure clientless tunnel mediated via Micron OS ZTNA.') ?>
            </p>
          </div>

          <div>
            <div style="display:flex; justify-content:space-between; align-items:center; padding-top:14px; border-top:1px solid var(--border-color); font-size:0.75rem; color:var(--text-muted); margin-bottom:14px;">
              <span>Target: <code><?= esc($res['internal_host']) ?></code></span>
              <span>Status: <strong style="color:var(--color-emerald);">Online</strong></span>
            </div>

            <a href="<?= base_url('vpn/clientless-gateway/launch/' . esc($res['id'])) ?>" class="btn btn-primary" style="width:100%; display:flex; justify-content:center; align-items:center; gap:8px;">
              Launch Session &rarr;
            </a>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?= $this->endSection() ?>
