<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="page-header animate-in">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
    <div>
      <h1 class="page-title">Add New User</h1>
      <p class="page-subtitle">Provision a unified Web GUI and Micron OS user account</p>
    </div>
    <a href="<?= base_url('users') ?>" class="btn btn-ghost">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
      </svg>
      Back to Users
    </a>
  </div>
</div>

<form method="POST" action="<?= base_url('users/store') ?>" class="animate-in animate-in-delay-1">
  <?= csrf_field() ?>

  <div style="display:grid; grid-template-columns: 1fr 1fr; gap:24px;">

    <!-- User Information -->
    <div class="card card-glass">
      <div class="card-header">
        <span class="card-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
          </svg>
          Account Credentials & OS Parameters
        </span>
      </div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label" for="username">Username <span style="color:var(--color-red);">*</span></label>
          <input type="text" id="username" name="username" class="form-control" required
                 pattern="[a-zA-Z0-9_\-]+" placeholder="e.g. jdoe" value="<?= esc(old('username')) ?>"
                 style="font-family:var(--font-mono);">
          <div class="form-hint">Unified identifier for Web Console, SSH, and CLI shell access.</div>
        </div>

        <div class="form-group">
          <label class="form-label" for="full_name">Full Name <span style="color:var(--color-red);">*</span></label>
          <input type="text" id="full_name" name="full_name" class="form-control" required
                 placeholder="e.g. John Doe" value="<?= esc(old('full_name')) ?>">
          <div class="form-hint">Stored in database and Linux GECOS field.</div>
        </div>

        <div class="form-group">
          <label class="form-label" for="email">Email Address</label>
          <input type="email" id="email" name="email" class="form-control"
                 placeholder="e.g. jdoe@example.com" value="<?= esc(old('email')) ?>">
          <div class="form-hint">For system notifications and security alerts.</div>
        </div>

        <div class="form-group">
          <label class="form-label" for="auth_source">Authentication Source</label>
          <select id="auth_source" name="auth_source" class="form-control form-select" onchange="toggleAuthSourceFields(this.value)">
            <option value="local" <?= old('auth_source') === 'local' ? 'selected' : '' ?>>Local Database & Micron OS (Unified)</option>
            <option value="ldap" <?= old('auth_source') === 'ldap' ? 'selected' : '' ?>>LDAP / Microsoft Active Directory</option>
            <option value="radius" <?= old('auth_source') === 'radius' ? 'selected' : '' ?>>RADIUS Authentication Server</option>
          </select>
          <div class="form-hint">Specifies which authority validates operator sign-in credentials.</div>
        </div>

        <div class="form-group" id="group_ldap_dn" style="display:none;">
          <label class="form-label" for="ldap_dn">Directory Distinguished Name (DN)</label>
          <input type="text" id="ldap_dn" name="ldap_dn" class="form-control mono"
                 placeholder="CN=John Doe,OU=Users,DC=corp,DC=example,DC=local" value="<?= esc(old('ldap_dn'))?>" spellcheck="false">
          <div class="form-hint">Optional: Target user DN inside Active Directory / LDAP tree.</div>
        </div>

        <div class="form-group" id="group_external_group" style="display:none;">
          <label class="form-label" for="external_group" id="external_group_label">External Security Group / Attribute</label>
          <input type="text" id="external_group" name="external_group" class="form-control mono"
                 placeholder="e.g. Domain Admins or Filter-Id value" value="<?= esc(old('external_group'))?>" spellcheck="false">
          <div class="form-hint" id="external_group_hint">Directory group membership or RADIUS Filter-Id string for dynamic role resolution.</div>
        </div>

        <div class="form-group" id="group_shell">
          <label class="form-label" for="shell">Micron OS Login Shell</label>
          <select id="shell" name="shell" class="form-control form-select" style="font-family:var(--font-mono); font-size:0.85rem;">
            <option value="/bin/bash" <?= (old('shell', '/bin/bash') === '/bin/bash') ? 'selected' : '' ?>>/bin/bash (Default Interactive Shell)</option>
            <option value="/usr/local/bin/microctl" <?= (old('shell') === '/usr/local/bin/microctl') ? 'selected' : '' ?>>/usr/local/bin/microctl (Appliance Console Manager)</option>
            <option value="/bin/sh" <?= (old('shell') === '/bin/sh') ? 'selected' : '' ?>>/bin/sh (POSIX Shell)</option>
            <option value="/usr/sbin/nologin" <?= (old('shell') === '/usr/sbin/nologin') ? 'selected' : '' ?>>/usr/sbin/nologin (Web GUI Only / No SSH Access)</option>
          </select>
          <div class="form-hint">Assigned system login shell in /etc/passwd.</div>
        </div>

        <div class="form-group">
          <label class="form-label" for="ssh_keys">SSH Authorized Keys</label>
          <textarea id="ssh_keys" name="ssh_keys" class="form-control mono" rows="3"
                    placeholder="ssh-ed25519 AAAAC3NzaC... operator@workstation"
                    style="font-size:0.75rem; line-height:1.4; resize:vertical;"><?= esc(old('ssh_keys')) ?></textarea>
          <div class="form-hint">Optional: Public keys automatically provisioned to ~/.ssh/authorized_keys for passwordless SSH.</div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label" for="password" id="password_label">Password <span style="color:var(--color-red);" id="password_req">*</span></label>
          <input type="password" id="password" name="password" class="form-control" minlength="6" autocomplete="new-password"
                 placeholder="••••••••••••" style="font-family:var(--font-mono);">
          <div class="form-hint" id="password_hint">Synchronized to both Web GUI hash and Micron OS account.</div>
        </div>
      </div>
    </div>

    <!-- Assigned Roles -->
    <div class="card card-glass">
      <div class="card-header">
        <span class="card-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
          </svg>
          Assigned Security Roles & Linux Privileges
        </span>
      </div>
      <div class="card-body">
        <p style="font-size:0.8rem; color:var(--text-secondary); margin-bottom:16px;">
          Assigning an administrative role (e.g. <strong>Super Administrator</strong>) automatically grants Micron OS <code>sudo</code> privileges and membership in the system <code>sudo</code> group.
        </p>

        <div style="display:flex; flex-direction:column; gap:12px;">
          <?php foreach ($roles as $r): ?>
            <label style="display:flex; align-items:flex-start; gap:12px; padding:12px; background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-md); cursor:pointer;">
              <input type="checkbox" name="roles[]" value="<?= esc($r['id']) ?>"
                     style="accent-color:var(--color-cyan); width:18px; height:18px; margin-top:2px; cursor:pointer;">
              <div style="flex:1;">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                  <span style="font-weight:600; font-size:0.88rem; color:var(--text-primary);">
                    <?= esc($r['display_name']) ?>
                  </span>
                  <?php if ((int)($r['is_system'] ?? 0) === 1): ?>
                    <span class="badge badge-purple" style="font-size:0.65rem;">System</span>
                  <?php else: ?>
                    <span class="badge badge-info" style="font-size:0.65rem;">Custom</span>
                  <?php endif; ?>
                </div>
                <div style="font-family:var(--font-mono); font-size:0.73rem; color:var(--color-cyan); margin-top:2px;">
                  <?= esc($r['name']) ?> &nbsp;·&nbsp; <?= (int)($r['permission_count'] ?? 0) ?> perms
                  <?php if (in_array(strtolower($r['name']), ['superadmin', 'admin', 'administrator'])): ?>
                    &nbsp;·&nbsp; <span style="color:var(--color-amber);">Grants Linux sudo</span>
                  <?php endif; ?>
                </div>
                <?php if (!empty($r['description'])): ?>
                  <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">
                    <?= esc($r['description']) ?>
                  </div>
                <?php endif; ?>
              </div>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div>

  <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:24px; margin-bottom:40px;">
    <a href="<?= base_url('users') ?>" class="btn btn-ghost">Cancel</a>
    <button type="submit" class="btn btn-primary" style="min-width:140px;">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
      </svg>
      Create User
    </button>
  </div>
</form>

<script>
function toggleAuthSourceFields(src) {
  const ldapDnGrp = document.getElementById('group_ldap_dn');
  const extGrp = document.getElementById('group_external_group');
  const pwdReq = document.getElementById('password_req');
  const pwdHint = document.getElementById('password_hint');
  const pwdInp = document.getElementById('password');

  if (src === 'ldap') {
    ldapDnGrp.style.display = 'block';
    extGrp.style.display = 'block';
    document.getElementById('external_group_label').textContent = 'Active Directory / LDAP Group';
    document.getElementById('external_group_hint').textContent = 'e.g. Domain Admins or CN=NetworkAdmins,OU=Groups,DC=corp,DC=local';
    pwdReq.style.display = 'none';
    pwdInp.required = false;
    pwdHint.textContent = 'Optional for LDAP users. If blank, credentials are authenticated directly by the directory server.';
  } else if (src === 'radius') {
    ldapDnGrp.style.display = 'none';
    extGrp.style.display = 'block';
    document.getElementById('external_group_label').textContent = 'Expected RADIUS Filter-Id / Class Attribute';
    document.getElementById('external_group_hint').textContent = 'e.g. network_superadmins (matches configured RADIUS attribute mapping)';
    pwdReq.style.display = 'none';
    pwdInp.required = false;
    pwdHint.textContent = 'Optional for RADIUS users. Authentication will be delegated to the RADIUS server.';
  } else {
    ldapDnGrp.style.display = 'none';
    extGrp.style.display = 'none';
    pwdReq.style.display = 'inline';
    pwdInp.required = true;
    pwdHint.textContent = 'Synchronized to both Web GUI hash and Micron OS account.';
  }
}
toggleAuthSourceFields(document.getElementById('auth_source').value);
</script>

<?= $this->endSection() ?>
