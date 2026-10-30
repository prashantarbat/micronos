<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= esc($title) ?> &mdash; <?= esc($settings['portal_title'] ?? 'Micron OS Clientless Gateway') ?></title>
  <link rel="preload" href="<?= base_url('assets/fonts/inter-latin.woff2') ?>?v=1" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= base_url('css/micronos.css') ?>?v=<?= @filemtime(FCPATH . 'css/micronos.css') ?: '1' ?>">
  <style>
    :root {
      --brand-primary: <?= esc($settings['brand_color'] ?? '#6366f1') ?>;
      --brand-hover: <?= esc($settings['brand_color_hover'] ?? '#4f46e5') ?>;
    }
    body, html {
      margin: 0;
      padding: 0;
      width: 100%;
      height: 100%;
      overflow: hidden;
      background: var(--color-bg, #070b14);
      font-family: var(--font-sans, 'Inter', -apple-system, sans-serif);
      color: var(--text-primary, #f1f5f9);
    }
    <?= $settings['custom_css'] ?? '' ?>
    .viewer-navbar {
      height: 48px;
      background: var(--color-surface-2, rgba(15, 23, 42, 0.95));
      border-bottom: 1px solid var(--color-border);
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0 16px;
      color: var(--text-primary);
      z-index: 100;
    }
    .viewer-canvas-container {
      width: 100%;
      height: calc(100% - 48px);
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--color-bg);
    }
    .viewer-canvas {
      width: 100%;
      height: 100%;
      border: none;
    }
    .viewer-display { outline: none; }
    .viewer-display > div { margin: 0 auto; }
    .viewer-overlay, .viewer-modal {
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(7, 11, 20, 0.72);
      z-index: 50;
    }
    .viewer-modal { position: fixed; z-index: 200; }
    .viewer-overlay[hidden], .viewer-modal[hidden] { display: none; }
    .viewer-card {
      max-width: 500px;
      padding: 24px;
      background: var(--color-surface-1);
      border: 1px solid var(--color-border-bright);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-lg);
      color: var(--text-primary);
      text-align: center;
    }
    .viewer-modal .viewer-card { text-align: left; width: min(380px, calc(100vw - 32px)); }
    .status-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; display: inline-block; }
    .status-pill[data-tone="warn"] { background: var(--tone-amber-bg, rgba(245,158,11,.12)); color: var(--tone-amber-fg, #f59e0b); border-color: var(--tone-amber-border, rgba(245,158,11,.35)); }
    .status-pill[data-tone="err"] { background: var(--tone-rose-bg, rgba(239,68,68,.12)); color: var(--tone-rose-fg, #ef4444); border-color: var(--tone-rose-border, rgba(239,68,68,.35)); }
    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 3px 9px;
      border-radius: 9999px;
      font-size: 0.72rem;
      font-weight: 700;
      background: var(--tone-emerald-bg);
      color: var(--tone-emerald-fg);
      border: 1px solid var(--tone-emerald-border);
    }
  </style>
</head>
<body>

  <!-- Top Control Bar -->
  <div class="viewer-navbar">
    <div style="display:flex; align-items:center; gap:12px;">
      <span class="status-pill" id="statusPill" data-tone="warn">
        <span class="status-dot"></span>
        <span id="statusText">CONNECTING</span>
      </span>
      <div style="font-weight:700; font-size:0.9rem; color:var(--text-primary);">
        <?= esc($session['name'] ?? 'Remote Target') ?>
      </div>
      <span style="font-size:0.75rem; color:var(--text-muted); font-family:var(--font-mono);">
        (<?= esc($session['type']) ?>://<?= esc($session['host']) ?>:<?= esc($session['port']) ?>)
      </span>
    </div>

    <div style="display:flex; align-items:center; gap:10px;">
      <span style="font-size:0.75rem; color:var(--text-secondary); font-family:var(--font-mono);" id="sessionLatency"></span>
      <button type="button" class="btn btn-ghost btn-xs" onclick="toggleFullScreen()" title="Full Screen">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/></svg>
      </button>
      <button type="button" class="btn btn-ghost btn-xs" onclick="openClipboardModal()" title="Clipboard Sync">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
      </button>
      <a href="<?= base_url('vpn/clientless-gateway/portal') ?>" class="btn btn-danger btn-xs" style="text-decoration:none;" id="disconnectBtn">
        Disconnect
      </a>
    </div>
  </div>

  <!-- Remote display, rendered by guacamole-common-js over the WebSocket tunnel -->
  <div class="viewer-canvas-container" id="canvasContainer">
    <div id="display" class="viewer-display" tabindex="0"></div>
    <div id="viewerOverlay" class="viewer-overlay">
      <div class="viewer-card">
        <div style="font-weight:700; color:var(--text-primary); font-size:1.05rem; margin-bottom:6px;" id="overlayTitle">
          Connecting to <?= esc($session['name'] ?? 'remote target') ?>&hellip;
        </div>
        <div style="font-size:0.8rem; color:var(--text-secondary); line-height:1.5;" id="overlayText">
          Opening HTML5 <?= strtoupper(esc($session['type'])) ?> session to <code><?= esc($session['host']) ?>:<?= esc($session['port']) ?></code> through the Micron OS gateway.
        </div>
        <div id="overlayActions" style="margin-top:14px; display:none;">
          <button type="button" class="btn btn-primary btn-xs" onclick="window.location.reload()">Reconnect</button>
          <a href="<?= base_url('vpn/clientless-gateway/portal') ?>" class="btn btn-ghost btn-xs" style="text-decoration:none;">Back to portal</a>
        </div>
      </div>
    </div>
  </div>

  <!-- Credentials requested by the remote server (guacd `required`) -->
  <div id="credModal" class="viewer-modal" hidden>
    <form class="viewer-card" id="credForm" autocomplete="off">
      <div style="font-weight:700; margin-bottom:10px;">Sign in to <?= esc($session['name'] ?? 'remote target') ?></div>
      <div id="credFields"></div>
      <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px;">
        <button type="submit" class="btn btn-primary btn-xs">Continue</button>
      </div>
    </form>
  </div>

  <!-- Clipboard exchange with the remote session -->
  <div id="clipModal" class="viewer-modal" hidden>
    <div class="viewer-card" style="width:min(520px, calc(100vw - 32px));">
      <div style="font-weight:700; margin-bottom:8px;">Remote clipboard</div>
      <div style="font-size:0.78rem; color:var(--text-secondary); margin-bottom:8px;">Text copied in the remote session appears here. Edit it and press Send to paste it into the remote session's clipboard.</div>
      <textarea id="clipText" rows="8" style="width:100%; box-sizing:border-box; font-family:var(--font-mono); font-size:0.8rem;"></textarea>
      <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:10px;">
        <button type="button" class="btn btn-ghost btn-xs" onclick="closeClipboardModal()">Close</button>
        <button type="button" class="btn btn-primary btn-xs" onclick="sendClipboard()">Send</button>
      </div>
    </div>
  </div>

  <script src="<?= base_url('assets/vendor/guacamole/guacamole-common.js') ?>?v=<?= @filemtime(FCPATH . 'assets/vendor/guacamole/guacamole-common.js') ?: '1' ?>"></script>
  <script>
  (function () {
    'use strict';

    var TOKEN  = <?= json_encode($token, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var TUNNEL = <?= json_encode($tunnelUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    var container = document.getElementById('canvasContainer');
    var displayEl = document.getElementById('display');
    var overlay   = document.getElementById('viewerOverlay');
    var modalOpen = false;

    function setStatus(text, tone) {
      document.getElementById('statusText').textContent = text;
      document.getElementById('statusPill').dataset.tone = tone;
    }

    function showOverlay(title, text, actions) {
      document.getElementById('overlayTitle').textContent = title;
      document.getElementById('overlayText').textContent = text;
      document.getElementById('overlayActions').style.display = actions ? '' : 'none';
      overlay.hidden = false;
    }

    if (typeof Guacamole === 'undefined') {
      setStatus('UNAVAILABLE', 'err');
      showOverlay('Viewer library missing', 'guacamole-common.js could not be loaded from this appliance.', true);
      return;
    }

    var tunnel = new Guacamole.WebSocketTunnel(TUNNEL);
    var client = new Guacamole.Client(tunnel);
    var display = client.getDisplay();
    displayEl.appendChild(display.getElement());

    // Scale the remote framebuffer to fit the window (never upscale).
    function fit() {
      var w = display.getWidth(), h = display.getHeight();
      if (!w || !h) return;
      display.scale(Math.min(container.clientWidth / w, container.clientHeight / h, 1));
    }
    display.onresize = fit;

    var resizeTimer = null;
    window.addEventListener('resize', function () {
      fit();
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () {
        client.sendSize(container.clientWidth, container.clientHeight);
      }, 300);
    });

    var STATES = ['IDLE', 'CONNECTING', 'WAITING', 'CONNECTED', 'DISCONNECTING', 'DISCONNECTED'];
    var failed = false;
    client.onstatechange = function (state) {
      var name = STATES[state] || 'UNKNOWN';
      if (state === 3) {
        setStatus('CONNECTED', 'ok');
        overlay.hidden = true;
        displayEl.focus();
        fit();
      } else if (state === 5) {
        setStatus('DISCONNECTED', 'err');
        if (!failed) showOverlay('Session ended', 'The remote session was closed.', true);
      } else {
        setStatus(name, 'warn');
      }
    };

    client.onerror = function (status) {
      failed = true;
      setStatus('ERROR', 'err');
      showOverlay('Connection failed', (status && status.message) || 'The gateway could not open the remote session.', true);
    };
    tunnel.onerror = client.onerror;

    // The remote server asked for values it was not given (e.g. RDP/VNC credentials).
    client.onrequired = function (parameters) {
      var fields = document.getElementById('credFields');
      fields.innerHTML = '';
      parameters.forEach(function (name) {
        var label = document.createElement('label');
        label.style.cssText = 'display:block; font-size:0.78rem; margin-bottom:8px;';
        label.textContent = name.charAt(0).toUpperCase() + name.slice(1);
        var input = document.createElement('input');
        input.name = name;
        input.type = /password|passphrase/.test(name) ? 'password' : 'text';
        input.style.cssText = 'display:block; width:100%; box-sizing:border-box; margin-top:4px;';
        label.appendChild(input);
        fields.appendChild(label);
      });
      openModal('credModal');
      var first = fields.querySelector('input');
      if (first) first.focus();
    };

    document.getElementById('credForm').addEventListener('submit', function (e) {
      e.preventDefault();
      this.querySelectorAll('#credFields input').forEach(function (input) {
        var writer = new Guacamole.StringWriter(client.createArgumentValueStream('text/plain', input.name));
        writer.sendText(input.value);
        writer.sendEnd();
      });
      closeModal('credModal');
      displayEl.focus();
    });

    // Remote → local clipboard.
    client.onclipboard = function (stream, mimetype) {
      if (!/^text\//.test(mimetype)) return;
      var reader = new Guacamole.StringReader(stream);
      var text = '';
      reader.ontext = function (chunk) { text += chunk; };
      reader.onend = function () {
        document.getElementById('clipText').value = text;
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(text).catch(function () {});
        }
      };
    };

    // Input
    var mouse = new Guacamole.Mouse(display.getElement());
    mouse.onEach(['mousedown', 'mousemove', 'mouseup'], function (e) {
      client.sendMouseState(e.state, true);
    });
    var touch = new Guacamole.Mouse.Touchscreen(display.getElement());
    touch.onEach(['mousedown', 'mousemove', 'mouseup'], function (e) {
      client.sendMouseState(e.state, true);
    });
    var keyboard = new Guacamole.Keyboard(document);
    keyboard.onkeydown = function (keysym) {
      if (modalOpen) return true;
      client.sendKeyEvent(1, keysym);
      return false;
    };
    keyboard.onkeyup = function (keysym) {
      if (modalOpen) return;
      client.sendKeyEvent(0, keysym);
    };

    function openModal(id) {
      modalOpen = true;
      keyboard.reset();
      document.getElementById(id).hidden = false;
    }
    function closeModal(id) {
      document.getElementById(id).hidden = true;
      modalOpen = !document.getElementById('credModal').hidden || !document.getElementById('clipModal').hidden;
    }

    window.openClipboardModal = function () {
      openModal('clipModal');
      document.getElementById('clipText').focus();
    };
    window.closeClipboardModal = function () {
      closeModal('clipModal');
      displayEl.focus();
    };
    window.sendClipboard = function () {
      var writer = new Guacamole.StringWriter(client.createClipboardStream('text/plain'));
      writer.sendText(document.getElementById('clipText').value);
      writer.sendEnd();
      window.closeClipboardModal();
    };
    window.toggleFullScreen = function () {
      if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen();
      } else if (document.exitFullscreen) {
        document.exitFullscreen();
      }
    };

    document.getElementById('disconnectBtn').addEventListener('click', function () {
      client.disconnect();
    });
    window.addEventListener('pagehide', function () { client.disconnect(); });

    var dpi = Math.round(96 * (window.devicePixelRatio || 1));
    client.connect('token=' + encodeURIComponent(TOKEN)
      + '&width=' + container.clientWidth
      + '&height=' + container.clientHeight
      + '&dpi=' + dpi);
  })();
  </script>
</body>
</html>
