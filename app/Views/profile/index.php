<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <a href="<?= base_url('dashboard') ?>">Dashboard</a>
      <span class="sep">/</span>
      <span>System</span>
      <span class="sep">/</span>
      <span class="current">My Profile</span>
    </div>
    <h1 class="page-title">
      <div class="page-title-icon tone-blue">
        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
        </svg>
      </div>
      My Account Settings
    </h1>
    <p class="page-subtitle">Manage personal profile details, authentication credentials, and security settings.</p>
  </div>
</div>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap:24px;" class="animate-in animate-in-delay-1">

  <!-- Left: Profile & Roles Info -->
  <div style="display:flex; flex-direction:column; gap:24px;">

    <!-- Personal Info Card -->
    <div class="card card-glass">
      <div class="card-header">
        <span class="card-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
          </svg>
          Profile Information
        </span>
      </div>
      <div class="card-body">
        <form method="POST" action="<?= base_url('profile/save') ?>">
          <?= csrf_field() ?>

          <div class="form-group">
            <label class="form-label">Username</label>
            <input type="text" class="form-control" value="<?= esc($user['username']) ?>" readonly
                   style="opacity:0.65; cursor:not-allowed; font-family:var(--font-mono);">
            <div class="form-hint">Username cannot be changed.</div>
          </div>

          <div class="form-group">
            <label class="form-label" for="full_name">Full Name <span style="color:var(--color-red);">*</span></label>
            <input type="text" id="full_name" name="full_name" class="form-control" required
                   value="<?= esc(old('full_name', $user['full_name'])) ?>">
          </div>

          <div class="form-group">
            <label class="form-label" for="email">Email Address</label>
            <input type="email" id="email" name="email" class="form-control"
                   value="<?= esc(old('email', $user['email'])) ?>" placeholder="user@example.com">
          </div>

          <button type="submit" class="btn btn-primary" style="margin-top:8px;">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
            </svg>
            Save Profile
          </button>
        </form>
      </div>
    </div>

    <!-- Active Roles Card -->
    <div class="card card-glass">
      <div class="card-header">
        <span class="card-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
          </svg>
          Assigned Roles & Privileges
        </span>
      </div>
      <div class="card-body">
        <div style="display:flex; flex-direction:column; gap:10px;">
          <?php if (empty($roles)): ?>
            <div style="color:var(--text-muted); font-size:0.85rem;">No explicit roles assigned. Default permissions apply.</div>
          <?php else: ?>
            <?php foreach ($roles as $r): ?>
              <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; background:var(--color-surface-1); border:1px solid var(--color-border); border-radius:var(--radius-md);">
                <div>
                  <div style="font-weight:600; font-size:0.85rem; color:var(--text-primary);"><?= esc($r['display_name']) ?></div>
                  <div style="font-family:var(--font-mono); font-size:0.72rem; color:var(--color-cyan);"><?= esc($r['name']) ?></div>
                </div>
                <?php if ((int)($r['is_system'] ?? 0) === 1): ?>
                  <span class="badge badge-purple" style="font-size:0.68rem;">System</span>
                <?php else: ?>
                  <span class="badge badge-info" style="font-size:0.68rem;">Custom</span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>

  <!-- Right: Password & 2FA -->
  <div style="display:flex; flex-direction:column; gap:24px;">

    <!-- Change Password Card -->
    <div class="card card-glass">
      <div class="card-header">
        <span class="card-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
          </svg>
          Change Password
        </span>
      </div>
      <div class="card-body">
        <form method="POST" action="<?= base_url('profile/password') ?>">
          <?= csrf_field() ?>

          <div class="form-group">
            <label class="form-label" for="current_password">Current Password <span style="color:var(--color-red);">*</span></label>
            <input type="password" id="current_password" name="current_password" class="form-control" required autocomplete="current-password"
                   placeholder="••••••••••••" style="font-family:var(--font-mono);">
          </div>

          <div class="form-group">
            <label class="form-label" for="new_password">New Password <span style="color:var(--color-red);">*</span></label>
            <input type="password" id="new_password" name="new_password" class="form-control" required minlength="6" autocomplete="new-password"
                   placeholder="••••••••••••" style="font-family:var(--font-mono);">
            <div class="form-hint">Minimum 6 characters.</div>
          </div>

          <div class="form-group">
            <label class="form-label" for="confirm_password">Confirm New Password <span style="color:var(--color-red);">*</span></label>
            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="6" autocomplete="new-password"
                   placeholder="••••••••••••" style="font-family:var(--font-mono);">
          </div>

          <button type="submit" class="btn btn-primary" style="margin-top:8px;">
            Update Password
          </button>
        </form>
      </div>
    </div>

    <!-- 2FA / TOTP Card -->
    <div class="card card-glass">
      <div class="card-header">
        <span class="card-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
          </svg>
          Two-Factor Authentication (TOTP)
        </span>
        <?php if ((int)($user['totp_enabled'] ?? 0) === 1): ?>
          <span class="badge badge-success">Enabled</span>
        <?php else: ?>
          <span class="badge badge-muted">Disabled</span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <p style="font-size:0.83rem; color:var(--text-secondary); line-height:1.5; margin-bottom:16px;">
          Protect your appliance administration account with time-based one-time password (TOTP) codes from apps such as Google Authenticator, Authy, or 1Password.
        </p>

        <form method="POST" action="<?= base_url('profile/totp') ?>">
          <?= csrf_field() ?>
          <?php if ((int)($user['totp_enabled'] ?? 0) === 1): ?>
            <input type="hidden" name="action" value="disable">
            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Disable two-factor authentication?');">
              Disable 2FA
            </button>
          <?php else: ?>
            <input type="hidden" name="action" value="enable">
            <button type="submit" class="btn btn-success btn-sm">
              Enable 2FA
            </button>
          <?php endif; ?>
        </form>
      </div>
    </div>

  </div>

</div>

<?= $this->endSection() ?>
