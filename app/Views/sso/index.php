<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<!-- ── Page Header ── -->
<div class="page-header animate-in">
  <div class="page-header-content">
    <div class="page-breadcrumb">
      <a href="<?= base_url('dashboard') ?>">Dashboard</a>
      <span class="sep">/</span>
      <span>System</span>
      <span class="sep">/</span>
      <span class="current">Single Sign-On (SSO)</span>
    </div>
    <h1 class="page-title">
      <div class="page-title-icon tone-indigo">
        <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
        </svg>
      </div>
      Centralized Single Sign-On (OIDC &amp; SAML 2.0)
    </h1>
    <p class="page-subtitle">Federate appliance authentication and administration with enterprise Identity Providers via OpenID Connect (PKCE) and SAML 2.0 Web Browser SSO.</p>
  </div>
  <div class="page-header-actions">
    <button type="button" class="btn btn-ghost" onclick="openSettingsModal()" style="display:inline-flex; align-items:center; gap:6px;">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
      </svg>
      SSO Policies
    </button>

    <button type="button" class="btn btn-primary" onclick="openProviderModal()" style="display:inline-flex; align-items:center; gap:6px;">
      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
      </svg>
      Add Identity Provider
    </button>
  </div>
</div>

<!-- ── KPI Cards ── -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:24px;" class="animate-in animate-in-delay-1">
  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-indigo, #6366f1);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Active Providers</div>
    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
      <span style="font-size:1.4rem; font-weight:700; color:var(--text-primary);">
        <?= count(array_filter($providers, fn($p) => ! empty($p['enabled']))) ?>
      </span>
      <span style="font-size:0.8rem; color:var(--text-muted);">/ <?= count($providers) ?> total</span>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">IdPs ready for sign-in</div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-cyan, #06b6d4);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">Protocols Enabled</div>
    <div style="display:flex; align-items:center; gap:10px; margin-top:6px;">
      <span class="badge" style="background:rgba(99,102,241,0.18); color:#818cf8; border:1px solid rgba(99,102,241,0.3);">OIDC (PKCE)</span>
      <span class="badge" style="background:rgba(16,185,129,0.18); color:#34d399; border:1px solid rgba(16,185,129,0.3);">SAML 2.0</span>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:6px;">Dual Web SSO Profile support</div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-amber, #f59e0b);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">SSO Policy Mode</div>
    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
      <span style="font-size:1.1rem; font-weight:700; color:var(--text-primary);">
        <?= ! empty($settings['sso_enforced']) ? 'ENFORCED (SSO Only)' : 'HYBRID (SSO + Local)' ?>
      </span>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Local fallback: <?= ! empty($settings['allow_local_fallback']) ? 'Enabled' : 'Disabled' ?></div>
  </div>

  <div class="card card-glass" style="padding:14px 18px; border-left:3px solid var(--color-emerald, #10b981);">
    <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:600; letter-spacing:0.04em;">JIT Provisioning</div>
    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
      <span style="width:10px; height:10px; border-radius:50%; background:var(--color-emerald); display:inline-block; box-shadow:0 0 8px var(--color-emerald);"></span>
      <span style="font-size:1.1rem; font-weight:700; color:var(--text-primary);">AUTOMATED</span>
    </div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Dynamic claims-to-role mapping</div>
  </div>
</div>

<!-- ── Configured Providers List ── -->
<div class="card card-glass animate-in animate-in-delay-2" style="margin-bottom:24px;">
  <div style="padding:16px 20px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
    <div>
      <h2 style="font-size:1rem; font-weight:600; margin:0; color:var(--text-primary);">Identity Providers (IdP)</h2>
      <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">Configured enterprise identity realms and federation endpoints.</div>
    </div>
    <div style="font-size:0.8rem; color:var(--text-muted);">
      ACS URL Base: <code style="background:rgba(0,0,0,0.25); padding:2px 6px; border-radius:4px;"><?= base_url('auth/sso/callback') ?></code>
    </div>
  </div>

  <div style="overflow-x:auto;">
    <table class="table" style="width:100%; border-collapse:collapse;">
      <thead>
        <tr style="border-bottom:1px solid var(--border-color); background:rgba(255,255,255,0.02); font-size:0.75rem; color:var(--text-muted); text-transform:uppercase;">
          <th style="padding:12px 16px; text-align:left;">Identity Provider</th>
          <th style="padding:12px 16px; text-align:left;">Protocol</th>
          <th style="padding:12px 16px; text-align:left;">Endpoints &amp; Entity ID</th>
          <th style="padding:12px 16px; text-align:left;">JIT &amp; Default Role</th>
          <th style="padding:12px 16px; text-align:center;">State</th>
          <th style="padding:12px 16px; text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($providers)): ?>
          <tr>
            <td colspan="6" style="padding:32px; text-align:center; color:var(--text-muted);">
              No Identity Providers configured. Click <strong>Add Identity Provider</strong> above to get started.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($providers as $p): ?>
            <tr style="border-bottom:1px solid rgba(255,255,255,0.05); transition:background 0.2s ease;">
              <td style="padding:14px 16px;">
                <div style="display:flex; align-items:center; gap:10px;">
                  <div style="width:36px; height:36px; border-radius:8px; background:rgba(99,102,241,0.15); display:flex; align-items:center; justify-content:center; color:var(--color-indigo, #818cf8);">
                    <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                  </div>
                  <div>
                    <div style="font-weight:600; color:var(--text-primary);"><?= esc($p['name']) ?></div>
                    <div style="font-size:0.75rem; color:var(--text-muted);">Slug: <code><?= esc($p['slug']) ?></code></div>
                  </div>
                </div>
              </td>
              <td style="padding:14px 16px;">
                <?php if ($p['protocol'] === 'oidc'): ?>
                  <span class="badge" style="background:rgba(99,102,241,0.18); color:#818cf8; border:1px solid rgba(99,102,241,0.3); font-weight:600;">OIDC / OAuth2</span>
                <?php else: ?>
                  <span class="badge" style="background:rgba(16,185,129,0.18); color:#34d399; border:1px solid rgba(16,185,129,0.3); font-weight:600;">SAML 2.0</span>
                <?php endif; ?>
              </td>
              <td style="padding:14px 16px; font-size:0.8rem;">
                <?php if ($p['protocol'] === 'oidc'): ?>
                  <div><span style="color:var(--text-muted);">Auth:</span> <span style="font-family:monospace;"><?= esc($p['auth_url'] ? substr($p['auth_url'], 0, 36) . '...' : 'Auto via Discovery') ?></span></div>
                  <div><span style="color:var(--text-muted);">Client ID:</span> <span style="font-family:monospace;"><?= esc(substr($p['client_id'] ?? '', 0, 24)) ?><?= strlen($p['client_id'] ?? '') > 24 ? '...' : '' ?></span></div>
                <?php else: ?>
                  <div><span style="color:var(--text-muted);">IdP SSO:</span> <span style="font-family:monospace;"><?= esc($p['idp_sso_url'] ? substr($p['idp_sso_url'], 0, 36) . '...' : 'Not Set') ?></span></div>
                  <div><a href="<?= base_url('auth/sso/metadata/' . $p['slug']) ?>" target="_blank" style="color:var(--color-indigo, #818cf8); text-decoration:none;">Download SP Metadata XML &rarr;</a></div>
                <?php endif; ?>
              </td>
              <td style="padding:14px 16px;">
                <div style="display:flex; align-items:center; gap:6px;">
                  <span class="badge badge-subtle"><?= esc($p['default_role']) ?></span>
                  <?php if (! empty($p['auto_provision'])): ?>
                    <span style="font-size:0.75rem; color:var(--color-emerald, #34d399);">JIT On</span>
                  <?php else: ?>
                    <span style="font-size:0.75rem; color:var(--text-muted);">JIT Off</span>
                  <?php endif; ?>
                </div>
              </td>
              <td style="padding:14px 16px; text-align:center;">
                <?php if (! empty($p['enabled'])): ?>
                  <span class="badge badge-success">Enabled</span>
                <?php else: ?>
                  <span class="badge badge-neutral">Disabled</span>
                <?php endif; ?>
              </td>
              <td style="padding:14px 16px; text-align:right;">
                <div style="display:flex; gap:6px; justify-content:flex-end;">
                  <a href="<?= base_url('auth/sso/login/' . $p['slug']) ?>" target="_blank" class="btn btn-sm btn-ghost" title="Test SSO Sign-in Flow">
                    Test
                  </a>
                  <button type="button" class="btn btn-sm btn-ghost" onclick='editProvider(<?= json_encode($p) ?>)' title="Edit Settings">
                    Edit
                  </button>
                  <form action="<?= base_url('system/sso/toggle/' . $p['id']) ?>" method="POST" style="display:inline;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-ghost" title="<?= ! empty($p['enabled']) ? 'Disable' : 'Enable' ?>">
                      <?= ! empty($p['enabled']) ? 'Disable' : 'Enable' ?>
                    </button>
                  </form>
                  <form action="<?= base_url('system/sso/delete/' . $p['id']) ?>" method="POST" style="display:inline;" onsubmit="return confirm('Delete this identity provider?');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-danger" title="Delete Provider">
                      &times;
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Quick-Fill Presets Card ── -->
<div class="card card-glass animate-in animate-in-delay-3" style="padding:18px 20px;">
  <h3 style="font-size:0.95rem; font-weight:600; margin:0 0 10px; color:var(--text-primary); display:flex; align-items:center; gap:8px;">
    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-amber, #f59e0b);">
      <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
    </svg>
    Supported Identity Federation Platforms
  </h3>
  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:12px;">
    <div style="padding:12px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); border-radius:8px;">
      <div style="font-weight:600; color:var(--text-primary); margin-bottom:4px;">Microsoft Entra ID (Azure AD)</div>
      <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:8px;">OpenID Connect with Tenant OpenID Auto-Discovery &amp; Security Group Mapping.</div>
      <button type="button" class="btn btn-sm btn-ghost" onclick="applyPreset('entra')">Use Preset Template</button>
    </div>

    <div style="padding:12px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); border-radius:8px;">
      <div style="font-weight:600; color:var(--text-primary); margin-bottom:4px;">Okta Workforce Identity Cloud</div>
      <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:8px;">SAML 2.0 Web Browser SSO with X.509 signature verification &amp; Group Attribute assertions.</div>
      <button type="button" class="btn btn-sm btn-ghost" onclick="applyPreset('okta')">Use Preset Template</button>
    </div>

    <div style="padding:12px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); border-radius:8px;">
      <div style="font-weight:600; color:var(--text-primary); margin-bottom:4px;">Google Workspace</div>
      <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:8px;">Google OAuth2 &amp; OpenID Connect standard flow with Hosted Domain (HD) support.</div>
      <button type="button" class="btn btn-sm btn-ghost" onclick="applyPreset('google')">Use Preset Template</button>
    </div>

    <div style="padding:12px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); border-radius:8px;">
      <div style="font-weight:600; color:var(--text-primary); margin-bottom:4px;">Keycloak IAM / Red Hat SSO</div>
      <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:8px;">Open-source enterprise IdP with Realm auto-discovery and Client Credentials.</div>
      <button type="button" class="btn btn-sm btn-ghost" onclick="applyPreset('keycloak')">Use Preset Template</button>
    </div>
  </div>
</div>

<!-- ── Modal: Provider Create / Edit ── -->
<div id="providerModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); backdrop-filter:blur(6px); z-index:999; align-items:center; justify-content:center; padding:16px;">
  <div class="card card-glass" style="max-width:760px; width:100%; max-height:90vh; overflow-y:auto; padding:24px; border:1px solid var(--border-color); box-shadow:0 10px 40px rgba(0,0,0,0.5);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <h2 id="modalTitle" style="font-size:1.15rem; font-weight:700; margin:0; color:var(--text-primary);">Configure Identity Provider</h2>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeProviderModal()">&times;</button>
    </div>

    <form id="providerForm" action="<?= base_url('system/sso/save-provider') ?>" method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="provider_id" value="">

      <!-- Basic Identity Provider Details -->
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
        <div>
          <label class="form-label" for="provider_name">Provider Name *</label>
          <input type="text" name="name" id="provider_name" class="form-control" placeholder="e.g. Microsoft Entra ID" required>
        </div>
        <div>
          <label class="form-label" for="provider_slug">URL Slug *</label>
          <input type="text" name="slug" id="provider_slug" class="form-control" placeholder="e.g. azure-ad" required>
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
        <div>
          <label class="form-label" for="provider_protocol">Protocol *</label>
          <select name="protocol" id="provider_protocol" class="form-control" onchange="toggleProtocolFields()">
            <option value="oidc">OpenID Connect (OIDC / PKCE)</option>
            <option value="saml">SAML 2.0 (Web Browser SSO)</option>
          </select>
        </div>
        <div>
          <label class="form-label" for="provider_default_role">Default Assigned Role</label>
          <select name="default_role" id="provider_default_role" class="form-control">
            <option value="Operator">Operator</option>
            <option value="Administrator">Administrator</option>
            <option value="SuperAdmin">SuperAdmin</option>
            <option value="Viewer">Viewer</option>
          </select>
        </div>
      </div>

      <!-- OIDC Section -->
      <div id="oidcFields" style="background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:8px; padding:16px; margin-bottom:16px;">
        <h4 style="font-size:0.85rem; font-weight:600; color:var(--color-indigo, #818cf8); margin:0 0 12px; text-transform:uppercase; letter-spacing:0.04em;">OIDC Endpoints &amp; Client Credentials</h4>

        <div style="margin-bottom:12px;">
          <label class="form-label" for="provider_discovery_url">OIDC Discovery URL (Well-Known)</label>
          <div style="display:flex; gap:8px;">
            <input type="url" name="discovery_url" id="provider_discovery_url" class="form-control" placeholder="https://login.microsoftonline.com/{tenant}/v2.0/.well-known/openid-configuration">
            <button type="button" class="btn btn-ghost" onclick="fetchDiscoveryEndpoints()">Auto-Discover</button>
          </div>
          <span style="font-size:0.75rem; color:var(--text-muted);">Automatically populates auth, token, and userinfo endpoints.</span>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
          <div>
            <label class="form-label" for="provider_client_id">Client ID / Application ID</label>
            <input type="text" name="client_id" id="provider_client_id" class="form-control" placeholder="e.g. 00000000-0000-0000-0000-000000000000">
          </div>
          <div>
            <label class="form-label" for="provider_client_secret">Client Secret</label>
            <input type="password" name="client_secret" id="provider_client_secret" class="form-control" placeholder="••••••••••••••••••••">
          </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
          <div>
            <label class="form-label" for="provider_auth_url">Authorization Endpoint</label>
            <input type="url" name="auth_url" id="provider_auth_url" class="form-control" placeholder="https://login.microsoftonline.com/oauth2/v2.0/authorize">
          </div>
          <div>
            <label class="form-label" for="provider_token_url">Token Endpoint</label>
            <input type="url" name="token_url" id="provider_token_url" class="form-control" placeholder="https://login.microsoftonline.com/oauth2/v2.0/token">
          </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
          <div>
            <label class="form-label" for="provider_userinfo_url">UserInfo Endpoint</label>
            <input type="url" name="userinfo_url" id="provider_userinfo_url" class="form-control" placeholder="https://graph.microsoft.com/oidc/userinfo">
          </div>
          <div>
            <label class="form-label" for="provider_scopes">Requested Scopes</label>
            <input type="text" name="scopes" id="provider_scopes" class="form-control" value="openid profile email">
          </div>
        </div>
      </div>

      <!-- SAML 2.0 Section -->
      <div id="samlFields" style="display:none; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:8px; padding:16px; margin-bottom:16px;">
        <h4 style="font-size:0.85rem; font-weight:600; color:var(--color-emerald, #34d399); margin:0 0 12px; text-transform:uppercase; letter-spacing:0.04em;">SAML 2.0 Identity Provider Attributes</h4>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
          <div>
            <label class="form-label" for="provider_idp_entity_id">IdP Entity ID (Issuer)</label>
            <input type="text" name="idp_entity_id" id="provider_idp_entity_id" class="form-control" placeholder="http://www.okta.com/exk12345678">
          </div>
          <div>
            <label class="form-label" for="provider_idp_sso_url">IdP Single Sign-On (SSO) URL</label>
            <input type="url" name="idp_sso_url" id="provider_idp_sso_url" class="form-control" placeholder="https://company.okta.com/app/sso/saml">
          </div>
        </div>

        <div style="margin-bottom:12px;">
          <label class="form-label" for="provider_idp_x509_cert">IdP Public X.509 Certificate</label>
          <textarea name="idp_x509_cert" id="provider_idp_x509_cert" class="form-control" rows="3" style="font-family:monospace; font-size:0.75rem;" placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----"></textarea>
        </div>

        <div>
          <label class="form-label" for="provider_sp_entity_id">Service Provider (SP) Entity ID</label>
          <input type="text" name="sp_entity_id" id="provider_sp_entity_id" class="form-control" placeholder="e.g. micronos-sp">
        </div>
      </div>

      <!-- Role Mapping & Provisioning -->
      <div style="background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:8px; padding:16px; margin-bottom:16px;">
        <h4 style="font-size:0.85rem; font-weight:600; color:var(--text-primary); margin:0 0 12px; text-transform:uppercase; letter-spacing:0.04em;">JIT Provisioning &amp; Group-to-Role Mapping</h4>

        <div style="display:flex; gap:20px; margin-bottom:12px;">
          <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; cursor:pointer;">
            <input type="checkbox" name="auto_provision" id="provider_auto_provision" value="1" checked>
            <span>Enable Just-in-Time (JIT) Automatic User Provisioning</span>
          </label>
          <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; cursor:pointer;">
            <input type="checkbox" name="allow_unmapped_users" id="provider_allow_unmapped_users" value="1" checked>
            <span>Allow Users Without Explicit Role Mapping (Assign Default)</span>
          </label>
        </div>

        <div>
          <label class="form-label" for="provider_role_mapping_json">IdP Claims/Groups to Micron OS Role Mapping (JSON)</label>
          <textarea name="role_mapping_json" id="provider_role_mapping_json" class="form-control" rows="3" style="font-family:monospace; font-size:0.8rem;" placeholder='{"Global Administrator": "SuperAdmin", "Network Engineers": "Administrator", "Auditors": "Viewer"}'></textarea>
        </div>
      </div>

      <div style="display:flex; align-items:center; justify-content:space-between; margin-top:20px;">
        <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; cursor:pointer;">
          <input type="checkbox" name="enabled" id="provider_enabled" value="1" checked>
          <span style="font-weight:600;">Enable this Identity Provider</span>
        </label>
        <div style="display:flex; gap:10px;">
          <button type="button" class="btn btn-ghost" onclick="closeProviderModal()">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Provider</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: Global SSO Policies ── -->
<div id="settingsModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); backdrop-filter:blur(6px); z-index:999; align-items:center; justify-content:center; padding:16px;">
  <div class="card card-glass" style="max-width:540px; width:100%; padding:24px; border:1px solid var(--border-color); box-shadow:0 10px 40px rgba(0,0,0,0.5);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
      <h2 style="font-size:1.15rem; font-weight:700; margin:0; color:var(--text-primary);">Global SSO Policies</h2>
      <button type="button" class="btn btn-ghost btn-sm" onclick="closeSettingsModal()">&times;</button>
    </div>

    <form action="<?= base_url('system/sso/save-settings') ?>" method="POST">
      <?= csrf_field() ?>

      <div style="margin-bottom:16px;">
        <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
          <input type="checkbox" name="sso_enforced" value="1" <?= ! empty($settings['sso_enforced']) ? 'checked' : '' ?> style="margin-top:3px;">
          <div>
            <div style="font-weight:600; color:var(--text-primary);">Enforce Single Sign-On (SSO Only)</div>
            <div style="font-size:0.75rem; color:var(--text-muted);">Disables username &amp; password sign-in forms on the appliance login screen. Emergency local superadmin override remains accessible.</div>
          </div>
        </label>
      </div>

      <div style="margin-bottom:16px;">
        <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
          <input type="checkbox" name="allow_local_fallback" value="1" <?= ! empty($settings['allow_local_fallback']) ? 'checked' : '' ?> style="margin-top:3px;">
          <div>
            <div style="font-weight:600; color:var(--text-primary);">Allow Local Account Fallback</div>
            <div style="font-size:0.75rem; color:var(--text-muted);">Permits local appliance users to authenticate when IdP connectivity is interrupted.</div>
          </div>
        </label>
      </div>

      <div style="margin-bottom:20px;">
        <label class="form-label" for="default_provider_id">Default Identity Provider for Auto-Redirect</label>
        <select name="default_provider_id" id="default_provider_id" class="form-control">
          <option value="">None (Display IdP selection list)</option>
          <?php foreach ($providers as $p): ?>
            <option value="<?= esc($p['id']) ?>" <?= ($settings['default_provider_id'] ?? '') === $p['id'] ? 'selected' : '' ?>>
              <?= esc($p['name']) ?> (<?= strtoupper(esc($p['protocol'])) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-ghost" onclick="closeSettingsModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Policies</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleProtocolFields() {
  const proto = document.getElementById('provider_protocol').value;
  document.getElementById('oidcFields').style.display = (proto === 'oidc') ? 'block' : 'none';
  document.getElementById('samlFields').style.display = (proto === 'saml') ? 'block' : 'none';
}

function openProviderModal() {
  document.getElementById('provider_id').value = '';
  document.getElementById('modalTitle').innerText = 'Add Identity Provider';
  document.getElementById('provider_name').value = '';
  document.getElementById('provider_slug').value = '';
  document.getElementById('provider_protocol').value = 'oidc';
  document.getElementById('provider_default_role').value = 'Operator';
  document.getElementById('provider_discovery_url').value = '';
  document.getElementById('provider_client_id').value = '';
  document.getElementById('provider_client_secret').value = '';
  document.getElementById('provider_auth_url').value = '';
  document.getElementById('provider_token_url').value = '';
  document.getElementById('provider_userinfo_url').value = '';
  document.getElementById('provider_scopes').value = 'openid profile email';
  document.getElementById('provider_idp_entity_id').value = '';
  document.getElementById('provider_idp_sso_url').value = '';
  document.getElementById('provider_idp_x509_cert').value = '';
  document.getElementById('provider_sp_entity_id').value = '';
  document.getElementById('provider_role_mapping_json').value = '';
  document.getElementById('provider_auto_provision').checked = true;
  document.getElementById('provider_allow_unmapped_users').checked = true;
  document.getElementById('provider_enabled').checked = true;
  toggleProtocolFields();
  document.getElementById('providerModal').style.display = 'flex';
}

function editProvider(p) {
  document.getElementById('provider_id').value = p.id || '';
  document.getElementById('modalTitle').innerText = 'Edit ' + p.name;
  document.getElementById('provider_name').value = p.name || '';
  document.getElementById('provider_slug').value = p.slug || '';
  document.getElementById('provider_protocol').value = p.protocol || 'oidc';
  document.getElementById('provider_default_role').value = p.default_role || 'Operator';
  document.getElementById('provider_discovery_url').value = p.discovery_url || '';
  document.getElementById('provider_client_id').value = p.client_id || '';
  document.getElementById('provider_client_secret').value = p.client_secret || '';
  document.getElementById('provider_auth_url').value = p.auth_url || '';
  document.getElementById('provider_token_url').value = p.token_url || '';
  document.getElementById('provider_userinfo_url').value = p.userinfo_url || '';
  document.getElementById('provider_scopes').value = p.scopes || 'openid profile email';
  document.getElementById('provider_idp_entity_id').value = p.idp_entity_id || '';
  document.getElementById('provider_idp_sso_url').value = p.idp_sso_url || '';
  document.getElementById('provider_idp_x509_cert').value = p.idp_x509_cert || '';
  document.getElementById('provider_sp_entity_id').value = p.sp_entity_id || '';
  document.getElementById('provider_role_mapping_json').value = p.role_mapping_json || '';
  document.getElementById('provider_auto_provision').checked = (parseInt(p.auto_provision) === 1);
  document.getElementById('provider_allow_unmapped_users').checked = (parseInt(p.allow_unmapped_users) === 1);
  document.getElementById('provider_enabled').checked = (parseInt(p.enabled) === 1);
  toggleProtocolFields();
  document.getElementById('providerModal').style.display = 'flex';
}

function closeProviderModal() {
  document.getElementById('providerModal').style.display = 'none';
}

function openSettingsModal() {
  document.getElementById('settingsModal').style.display = 'flex';
}

function closeSettingsModal() {
  document.getElementById('settingsModal').style.display = 'none';
}

function applyPreset(preset) {
  openProviderModal();
  if (preset === 'entra') {
    document.getElementById('provider_name').value = 'Microsoft Entra ID';
    document.getElementById('provider_slug').value = 'azure-ad';
    document.getElementById('provider_protocol').value = 'oidc';
    document.getElementById('provider_discovery_url').value = 'https://login.microsoftonline.com/common/v2.0/.well-known/openid-configuration';
    document.getElementById('provider_auth_url').value = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    document.getElementById('provider_token_url').value = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    document.getElementById('provider_userinfo_url').value = 'https://graph.microsoft.com/oidc/userinfo';
    document.getElementById('provider_scopes').value = 'openid profile email';
    document.getElementById('provider_role_mapping_json').value = JSON.stringify({
      "Global Administrator": "SuperAdmin",
      "Network Administrator": "Administrator"
    }, null, 2);
  } else if (preset === 'okta') {
    document.getElementById('provider_name').value = 'Corporate Okta';
    document.getElementById('provider_slug').value = 'okta';
    document.getElementById('provider_protocol').value = 'saml';
    document.getElementById('provider_sp_entity_id').value = '<?= base_url('auth/sso/metadata/okta') ?>';
    document.getElementById('provider_role_mapping_json').value = JSON.stringify({
      "Okta-SuperAdmins": "SuperAdmin",
      "Okta-NetOps": "Administrator"
    }, null, 2);
  } else if (preset === 'google') {
    document.getElementById('provider_name').value = 'Google Workspace';
    document.getElementById('provider_slug').value = 'google';
    document.getElementById('provider_protocol').value = 'oidc';
    document.getElementById('provider_discovery_url').value = 'https://accounts.google.com/.well-known/openid-configuration';
    document.getElementById('provider_auth_url').value = 'https://accounts.google.com/o/oauth2/v2/auth';
    document.getElementById('provider_token_url').value = 'https://oauth2.googleapis.com/token';
    document.getElementById('provider_userinfo_url').value = 'https://openidconnect.googleapis.com/v1/userinfo';
    document.getElementById('provider_scopes').value = 'openid profile email';
  } else if (preset === 'keycloak') {
    document.getElementById('provider_name').value = 'Keycloak IAM';
    document.getElementById('provider_slug').value = 'keycloak';
    document.getElementById('provider_protocol').value = 'oidc';
    document.getElementById('provider_discovery_url').value = 'https://keycloak.company.local/realms/master/.well-known/openid-configuration';
    document.getElementById('provider_scopes').value = 'openid profile email roles';
  }
  toggleProtocolFields();
}

function fetchDiscoveryEndpoints() {
  const url = document.getElementById('provider_discovery_url').value.trim();
  if (!url) {
    alert('Please enter an OIDC Discovery URL first.');
    return;
  }
  const formData = new FormData();
  formData.append('discovery_url', url);
  formData.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');

  MicronScheduler.fetch('<?= base_url('system/sso/discover') ?>', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(resp => {
    if (resp.status === 'success') {
      const d = resp.data;
      if (d.authorization_endpoint) document.getElementById('provider_auth_url').value = d.authorization_endpoint;
      if (d.token_endpoint) document.getElementById('provider_token_url').value = d.token_endpoint;
      if (d.userinfo_endpoint) document.getElementById('provider_userinfo_url').value = d.userinfo_endpoint;
      alert('OIDC Endpoints successfully auto-discovered!');
    } else {
      alert('Auto-discovery error: ' + (resp.message || 'Unknown error'));
    }
  })
  .catch(err => {
    alert('Network error during discovery request.');
  });
}
</script>

<?= $this->endSection() ?>
