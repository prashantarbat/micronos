<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<style>
.plug-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(340px, 1fr)); gap:16px; }
.plug-card {
  background:var(--color-surface-1); border:1px solid var(--color-border);
  border-radius:var(--radius-lg); padding:18px 18px 14px; display:flex; flex-direction:column; gap:10px;
  transition:border-color .15s ease, transform .15s ease;
}
.plug-card:hover { border-color:var(--color-border-bright); }
.plug-card.is-blocked { opacity:.72; }
.plug-head { display:flex; align-items:flex-start; gap:12px; }
.plug-icon {
  width:38px; height:38px; flex:0 0 38px; border-radius:10px; display:flex; align-items:center; justify-content:center;
  background:rgba(14,165,233,.14); color:#7dd3fc; border:1px solid rgba(14,165,233,.3);
}
.plug-name { font-weight:700; font-size:1rem; color:var(--text-primary); line-height:1.25; }
.plug-ver  { font-size:.72rem; color:var(--text-muted); font-family:var(--font-mono); }
.plug-desc { font-size:.8rem; color:var(--text-secondary); line-height:1.5; flex:1; }
.plug-meta { display:flex; flex-wrap:wrap; gap:6px; align-items:center; font-size:.72rem; color:var(--text-muted); }
.plug-chip {
  display:inline-flex; align-items:center; gap:4px; padding:2px 7px; border-radius:4px; font-family:var(--font-mono);
  font-size:.7rem; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.12); color:var(--text-secondary);
}
.plug-chip.cap { background:rgba(139,92,246,.13); border-color:rgba(139,92,246,.32); color:#c4b5fd; }
.plug-cap-list { display:flex; flex-wrap:wrap; gap:5px; }
.plug-foot { display:flex; gap:8px; flex-wrap:wrap; padding-top:10px; border-top:1px solid var(--color-border); }
.plug-blocked-note { font-size:.72rem; color:#fca5a5; background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.28); border-radius:6px; padding:6px 9px; }

/* Card / List view toggle */
.plug-viewtoggle { display:inline-flex; border:1px solid var(--color-border); border-radius:var(--radius-sm); overflow:hidden; }
.plug-vt-btn {
  background:transparent; border:none; color:var(--text-secondary); padding:6px 11px; font-size:0.78rem;
  cursor:pointer; display:inline-flex; align-items:center; gap:5px; font-family:inherit;
}
.plug-vt-btn + .plug-vt-btn { border-left:1px solid var(--color-border); }
.plug-vt-btn[aria-pressed="true"] { background:rgba(14,165,233,.15); color:var(--color-cyan, #0ea5e9); font-weight:600; }
#plugCards[hidden], #plugList[hidden] { display:none !important; }

/* List view */
.plug-list-wrap { background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-lg); overflow-x:auto; }
.plug-list { width:100%; border-collapse:separate; border-spacing:0; font-size:0.82rem; }
.plug-list th {
  background:var(--color-surface-3); color:var(--text-secondary); font-weight:600; font-size:0.72rem;
  text-transform:uppercase; letter-spacing:0.05em; padding:10px 14px; border-bottom:1px solid var(--color-border); text-align:left;
}
.plug-list td { padding:11px 14px; border-bottom:1px solid var(--color-border); vertical-align:top; }
.plug-list tbody tr:hover td { background:rgba(255,255,255,0.02); }
.plug-actions { display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end; }

.plug-modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.75); backdrop-filter:blur(4px); z-index:1000; display:none; align-items:center; justify-content:center; padding:20px; }
.plug-modal-backdrop.active { display:flex; }
.plug-modal { background:var(--color-surface-1); border:1px solid var(--color-border-bright); border-radius:var(--radius-lg); width:100%; max-width:640px; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 25px 50px -12px rgba(0,0,0,.7); overflow:hidden; }
.plug-modal-head { padding:16px 22px; border-bottom:1px solid var(--color-border); display:flex; justify-content:space-between; align-items:center; background:var(--color-surface-2); }
.plug-modal-body { padding:20px 22px; overflow-y:auto; flex:1; }
.plug-modal-foot { padding:14px 22px; border-top:1px solid var(--color-border); background:var(--color-surface-2); display:flex; justify-content:flex-end; gap:10px; }
.plug-plan { display:grid; grid-template-columns:110px 1fr; gap:4px 12px; font-size:.8rem; margin-bottom:16px; }
.plug-plan dt { color:var(--text-muted); font-weight:600; }
.plug-plan dd { color:var(--text-primary); font-family:var(--font-mono); font-size:.76rem; word-break:break-word; }
.plug-grant { display:flex; gap:10px; align-items:flex-start; padding:8px 10px; border:1px solid var(--color-border); border-radius:8px; margin-bottom:7px; background:var(--color-surface-3); }
.plug-grant input { margin-top:3px; }
.plug-grant .cap { font-family:var(--font-mono); font-size:.76rem; color:#c4b5fd; }
.plug-grant .hint { font-size:.72rem; color:var(--text-muted); }
</style>

<?php
/** Shared status badge renderer — used by both the card and the list view. */
$plugStatusBadge = static function (array $p): string {
    if ($p['installed'] && $p['enabled']) {
        return '<span class="badge badge-success" style="font-size:0.65rem;">ENABLED</span>';
    }
    if ($p['installed']) {
        return '<span class="badge badge-warning" style="font-size:0.65rem;">INSTALLED</span>';
    }
    if (! $p['plan_ok']) {
        return '<span class="badge badge-danger" style="font-size:0.65rem;">BLOCKED</span>';
    }

    return '<span class="badge badge-info" style="font-size:0.65rem;">AVAILABLE</span>';
};

/** Shared action buttons — identical behaviour in card and list layouts. */
$plugActions = static function (array $p) {
    ob_start(); ?>
    <?php if (! $p['installed']): ?>
      <button type="button" class="btn btn-primary btn-sm" <?= $p['plan_ok'] ? '' : 'disabled' ?>
              onclick="openPluginConsent('<?= esc($p['id'], 'raw') ?>')" title="Review the install plan and approve capabilities">
        Install
      </button>
    <?php else: ?>
      <form method="POST" action="<?= base_url('plugins/' . esc($p['id'], 'raw') . '/upgrade') ?>" style="display:inline;">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-ghost btn-sm" title="Upgrade the plugin packages">Update</button>
      </form>
      <form method="POST" action="<?= base_url('plugins/' . esc($p['id'], 'raw') . '/remove') ?>" style="display:inline;"
            onsubmit="return confirm('Remove <?= esc($p['name'], 'js') ?>? Its packages will be uninstalled and its data retained.');">
        <?= csrf_field() ?><input type="hidden" name="purge" value="0">
        <button type="submit" class="btn btn-ghost btn-sm" title="Uninstall the plugin (data retained)">Remove</button>
      </form>
    <?php endif; ?>
    <?php return (string) ob_get_clean();
};
?>

<div class="page-header animate-in">
  <div>
    <div style="display:flex; align-items:center; gap:10px;">
      <h1 class="page-title">Plugins &amp; Packages</h1>
      <span class="badge badge-info" style="font-size:0.75rem;"><?= count($plugins) ?> Available</span>
      <span class="badge badge-muted" style="font-size:0.75rem;"><?= (int) $installedCount ?> Installed</span>
    </div>
    <p class="page-subtitle">Signed extension bundles and their curated packages — each plugin declares the capabilities it needs, and you approve them one by one before anything is installed</p>
  </div>
  <div style="flex:1; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
    <form method="POST" action="<?= base_url('plugins/refresh') ?>" style="display:inline;">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-ghost btn-sm" title="Refresh the curated repository metadata (apt-get update)">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:inline;vertical-align:middle;margin-right:4px;">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Refresh Repositories
      </button>
    </form>
    <a href="<?= base_url('docs/plugins') ?>" class="btn btn-ghost btn-sm" title="Plugin &amp; Package Manager specification">Documentation</a>
    <div class="plug-viewtoggle" role="group" aria-label="View mode" style="margin-left:auto;">
      <button type="button" class="plug-vt-btn" data-plug-view="card" aria-pressed="false" onclick="setPluginView('card')" title="Card view">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        Card
      </button>
      <button type="button" class="plug-vt-btn" data-plug-view="list" aria-pressed="true" onclick="setPluginView('list')" title="List view">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
        List
      </button>
    </div>
  </div>
</div>

<?php
// Installed plugins that own a daemon unit get live Start / Restart / Stop
// controls (a green LED doubles as the run-state indicator), resolved by the
// daemon from the plugin id — the same per-unit pattern as DHCP relay.
$__pluginDaemons = array_values(array_filter(
    $plugins,
    static fn ($p) => ! empty($p['installed']) && ! empty($p['units']),
));
?>
<?php if ($__pluginDaemons !== []): ?>
<div class="card card-glass animate-in animate-in-delay-1" style="padding:12px 16px; margin-bottom:16px; display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
  <div style="font-weight:700; font-size:.9rem;">Plugin daemons</div>
  <div style="font-size:.74rem; color:var(--text-muted);">Start, restart or stop each installed plugin's own unit in place.</div>
  <?= \App\Libraries\ServiceStatus::pluginWidget($plugins, $pluginStates ?? []) ?>
</div>
<?php endif; ?>

<!-- ========================================================
     Card view
     ======================================================== -->
<div class="plug-grid animate-in animate-in-delay-1" id="plugCards">
  <?php foreach ($plugins as $p): ?>
    <div class="plug-card <?= $p['plan_ok'] ? '' : 'is-blocked' ?>">
      <div class="plug-head">
        <div class="plug-icon">
          <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
          </svg>
        </div>
        <div style="flex:1;">
          <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <span class="plug-name"><?= esc($p['name']) ?></span>
            <span class="badge badge-muted" style="font-size:0.65rem;">CLASS <?= esc($p['class']) ?></span>
            <?= $plugStatusBadge($p) ?>
          </div>
          <div class="plug-ver"><?= esc($p['id']) ?> · v<?= esc($p['version']) ?></div>
        </div>
      </div>

      <?php if (! empty($p['description'])): ?>
        <div class="plug-desc"><?= esc($p['description']) ?></div>
      <?php endif; ?>

      <div class="plug-meta">
        <span>Packages:</span>
        <?php if (empty($p['packages'])): ?>
          <span class="plug-chip">none</span>
        <?php else: ?>
          <?php foreach ($p['packages'] as $pkg): ?><span class="plug-chip"><?= esc($pkg) ?></span><?php endforeach; ?>
        <?php endif; ?>
      </div>
      <?php if (! empty($p['units'])): ?>
        <div class="plug-meta">
          <span>Units:</span>
          <?php foreach ($p['units'] as $unit): ?><span class="plug-chip"><?= esc($unit) ?></span><?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="plug-cap-list">
        <?php foreach ($p['capabilities'] as $cap): ?>
          <span class="plug-chip cap" title="Capability requested by the manifest"><?= esc($cap) ?></span>
        <?php endforeach; ?>
      </div>

      <?php if (! $p['plan_ok']): ?>
        <div class="plug-blocked-note">
          <?php foreach ($p['blocked'] as $reason): ?>⚠ <?= esc($reason) ?><br><?php endforeach; ?>
        </div>
        <?php if (! empty($p['preset_hint'])): ?>
          <div style="font-size:.72rem; color:var(--text-secondary); margin-top:6px;">
            <?= esc((string) $p['preset_hint']) ?>
            <a href="<?= base_url('plugins/repositories') ?>" style="margin-left:4px;">Open Repositories →</a>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <div class="plug-foot"><?= $plugActions($p) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<!-- ========================================================
     List view
     ======================================================== -->
<div class="plug-list-wrap animate-in animate-in-delay-1" id="plugList" hidden>
  <table class="plug-list">
    <thead>
      <tr>
        <th style="width:260px;">Plugin</th>
        <th style="width:140px;">Packages</th>
        <th style="width:90px;">Units</th>
        <th>Capabilities</th>
        <th style="width:190px; text-align:right;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($plugins as $p): ?>
        <tr>
          <td>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
              <span class="plug-name" style="font-size:0.9rem;" title="<?= esc($p['description'] ?? '') ?>"><?= esc($p['name']) ?></span>
              <span class="badge badge-muted" style="font-size:0.62rem;">CLASS <?= esc($p['class']) ?></span>
              <?= $plugStatusBadge($p) ?>
            </div>
            <div class="plug-ver"><?= esc($p['id']) ?> · v<?= esc($p['version']) ?></div>
            <?php if (! $p['plan_ok']): ?>
              <div class="plug-blocked-note" style="margin-top:6px;">
                <?php foreach ($p['blocked'] as $reason): ?>⚠ <?= esc($reason) ?><br><?php endforeach; ?>
              </div>
              <?php if (! empty($p['preset_hint'])): ?>
                <div style="font-size:.7rem; color:var(--text-secondary); margin-top:4px;">
                  <?= esc((string) $p['preset_hint']) ?>
                  <a href="<?= base_url('plugins/repositories') ?>" style="margin-left:4px;">Open Repositories →</a>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td>
            <div class="plug-cap-list">
              <?php if (empty($p['packages'])): ?>
                <span class="plug-chip">none</span>
              <?php else: ?>
                <?php foreach ($p['packages'] as $pkg): ?><span class="plug-chip"><?= esc($pkg) ?></span><?php endforeach; ?>
              <?php endif; ?>
            </div>
          </td>
          <td>
            <div class="plug-cap-list">
              <?php if (empty($p['units'])): ?>
                <span style="font-size:.74rem; color:var(--text-muted);">—</span>
              <?php else: ?>
                <?php foreach ($p['units'] as $unit): ?><span class="plug-chip"><?= esc($unit) ?></span><?php endforeach; ?>
              <?php endif; ?>
            </div>
          </td>
          <td>
            <div class="plug-cap-list">
              <?php foreach ($p['capabilities'] as $cap): ?>
                <span class="plug-chip cap"><?= esc($cap) ?></span>
              <?php endforeach; ?>
            </div>
          </td>
          <td><div class="plug-actions"><?= $plugActions($p) ?></div></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ========================================================
     Capability-consent dialog (install plan)
     ======================================================== -->
<div class="plug-modal-backdrop" id="pluginConsentModal">
  <div class="plug-modal">
    <div class="plug-modal-head">
      <div style="display:flex; align-items:center; gap:9px;">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-cyan);">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        <span style="font-weight:700; font-size:1.02rem; color:var(--text-primary);" id="plugConsentTitle">Install plugin</span>
      </div>
      <button type="button" onclick="closePluginConsent()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem;">&times;</button>
    </div>

    <form method="POST" id="plugConsentForm" action="">
      <?= csrf_field() ?>
      <div class="plug-modal-body">
        <p style="font-size:.82rem; color:var(--text-secondary); margin-bottom:14px;">
          Review the plan below. Nothing is installed until you approve. Capabilities are granted individually; a plugin can never control a core daemon.
        </p>

        <dl class="plug-plan">
          <dt>Packages</dt><dd id="plugPlanPackages">—</dd>
          <dt>Service units</dt><dd id="plugPlanUnits">—</dd>
          <dt id="plugPlanReposLabel">Repositories</dt><dd id="plugPlanRepos">—</dd>
        </dl>

        <div id="plugClassC" style="display:none; border:1px solid rgba(239,68,68,.45); background:rgba(239,68,68,.08); border-radius:9px; padding:12px 14px; margin-bottom:14px;">
          <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
            <span class="badge badge-danger" style="font-size:.62rem;">CLASS C</span>
            <span style="font-weight:700; font-size:.82rem; color:#fca5a5;">Third-party repository, external identity, live routing</span>
          </div>
          <div style="font-size:.76rem; color:var(--text-secondary); line-height:1.55;">
            This plugin is qualitatively different from a distribution daemon, and the difference is not the install
            steps — it is where the code and the identity come from:
            <ul style="margin:6px 0 8px 18px; padding:0;">
              <li style="margin-bottom:3px;">Its packages come from <strong>a vendor's apt repository</strong>, not the distribution. The repository is pinned below the distribution and the protected core is pinned to this appliance's release, so it can supply packages the distribution lacks but can never supply or upgrade a core dependency.</li>
              <li style="margin-bottom:3px;">The peer registers with <strong>a management service outside this appliance</strong> — the vendor's cloud, or a self-hosted one you name. Enrolling is the point at which the appliance starts talking to it.</li>
              <li>The overlay <strong>changes routing and the firewall interface group</strong> for live traffic, including traffic that is not addressed to this appliance.</li>
            </ul>
            Installing is not the same as joining: the repository must be registered, enabled and applied before installation can resolve, and the peer is not connected until you enrol it.
          </div>
          <label style="display:flex; align-items:flex-start; gap:8px; font-size:.76rem; color:var(--text-primary); margin-top:4px;">
            <input type="checkbox" id="plugClassCAck" name="class_c_ack" value="1" style="margin-top:2px;">
            <span>I understand this installs packages from a third-party repository, enrols this appliance with an external service, and changes how live traffic is routed.</span>
          </label>
        </div>

        <div style="font-size:.75rem; font-weight:600; text-transform:uppercase; letter-spacing:.05em; color:var(--text-secondary); margin-bottom:8px;">
          Capability grants
        </div>
        <div id="plugGrantList"></div>
      </div>

      <div class="plug-modal-foot">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closePluginConsent()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" id="plugConsentSubmit">Approve &amp; Install</button>
      </div>
    </form>
  </div>
</div>

<script>
const PLUGIN_PLANS = <?= json_encode($plans, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const PLUGIN_VIEW_KEY = 'micron-plugins-view-v2';

function setPluginView(mode) {
  const isList = mode === 'list';
  document.getElementById('plugCards').hidden = isList;
  document.getElementById('plugList').hidden = !isList;
  document.querySelectorAll('[data-plug-view]').forEach(btn => {
    btn.setAttribute('aria-pressed', btn.dataset.plugView === mode ? 'true' : 'false');
  });
  try { localStorage.setItem(PLUGIN_VIEW_KEY, isList ? 'list' : 'card'); } catch (e) {}
}

(function () {
  let mode = 'list';
  try { mode = localStorage.getItem(PLUGIN_VIEW_KEY) || 'list'; } catch (e) {}
  setPluginView(mode === 'card' ? 'card' : 'list');
})();

function capHint(cap) {
  if (cap.startsWith('system.service:')) return 'Start, stop and restart the unit "' + cap.slice(15) + '"';
  if (cap.startsWith('root.rpc:'))       return 'Call the daemon RPC "' + cap.slice(9) + '"';
  if (cap.startsWith('kernel.module:'))  return 'Load the kernel module "' + cap.slice(14) + '"';
  if (cap.startsWith('firewall.ifgroup:')) return 'Manage the firewall interface group "' + cap.slice(18) + '"';
  if (cap.startsWith('fs.write:'))       return 'Write to ' + cap.slice(9);
  if (cap === 'net.raw')                 return 'Create overlay interfaces and raw sockets';
  if (cap === 'dns.upstream')            return 'Change the DNS forwarders';
  if (cap === 'firewall.rules')          return 'Add firewall rules';
  return 'Capability';
}

function openPluginConsent(id) {
  const plan = PLUGIN_PLANS[id];
  if (!plan) return;

  document.getElementById('plugConsentTitle').textContent = 'Install ' + plan.name;
  document.getElementById('plugPlanPackages').textContent = plan.packages.length ? plan.packages.join(', ') : 'none';
  document.getElementById('plugPlanUnits').textContent    = plan.units.length ? plan.units.join(', ') : 'none (no daemon)';
  document.getElementById('plugConsentForm').action       = '<?= base_url('plugins') ?>/' + id + '/install';

  const list = document.getElementById('plugGrantList');
  list.innerHTML = '';
  if (!plan.capabilities.length) {
    list.innerHTML = '<div style="font-size:.8rem; color:var(--text-muted);">This plugin requests no capabilities.</div>';
  }
  plan.capabilities.forEach(cap => {
    const row = document.createElement('label');
    row.className = 'plug-grant';
    row.innerHTML = '<input type="checkbox" name="grants[]" value="' + cap + '" checked>'
      + '<span><span class="cap">' + cap + '</span><br><span class="hint">' + capHint(cap) + '</span></span>';
    list.appendChild(row);
  });

  // A Class C install carries an extra, explicit consent that the capabilities
  // do not cover (§11): the server refuses an install without it, and the dialog
  // cannot submit one either.
  const classC = document.getElementById('plugClassC');
  const ack    = document.getElementById('plugClassCAck');
  const submit = document.getElementById('plugConsentSubmit');
  const isC    = String(plan.class || '').toUpperCase() === 'C';

  classC.style.display = isC ? 'block' : 'none';
  // A Class B plugin resolves from the distribution and has no repository row
  // to show; a Class C plugin names the curated repository it needs.
  document.getElementById('plugPlanReposLabel').style.display = isC ? '' : 'none';
  document.getElementById('plugPlanRepos').style.display      = isC ? '' : 'none';
  document.getElementById('plugPlanRepos').textContent        = (plan.repos || []).join(', ');
  ack.checked = false;
  submit.disabled = isC;
  ack.onchange = () => { submit.disabled = !ack.checked; };

  document.getElementById('pluginConsentModal').classList.add('active');
}

function closePluginConsent() {
  document.getElementById('pluginConsentModal').classList.remove('active');
}
</script>

<?= $this->endSection() ?>
