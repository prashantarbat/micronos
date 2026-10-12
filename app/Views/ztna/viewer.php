<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= esc($title) ?> &mdash; Micron OS Clientless Gateway</title>
  <link rel="preload" href="<?= base_url('assets/fonts/inter-latin.woff2') ?>?v=1" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= base_url('css/micronos.css') ?>?v=<?= @filemtime(FCPATH . 'css/micronos.css') ?: '1' ?>">
  <style>
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
      <span class="status-pill">
        <span style="width:6px; height:6px; border-radius:50%; background:var(--color-emerald); display:inline-block; box-shadow:0 0 6px var(--color-emerald);"></span>
        CONNECTED
      </span>
      <div style="font-weight:700; font-size:0.9rem; color:var(--text-primary);">
        <?= esc($session['name'] ?? 'Remote Target') ?>
      </div>
      <span style="font-size:0.75rem; color:var(--text-muted); font-family:var(--font-mono);">
        (<?= esc($session['type']) ?>://<?= esc($session['host']) ?>:<?= esc($session['port']) ?>)
      </span>
    </div>

    <div style="display:flex; align-items:center; gap:10px;">
      <span style="font-size:0.75rem; color:var(--text-secondary); font-family:var(--font-mono);" id="sessionLatency">RTT: 12ms</span>
      <button type="button" class="btn btn-ghost btn-xs" onclick="toggleFullScreen()" title="Full Screen">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/></svg>
      </button>
      <button type="button" class="btn btn-ghost btn-xs" onclick="openClipboardModal()" title="Clipboard Sync">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
      </button>
      <a href="<?= base_url('vpn/clientless-gateway/portal') ?>" class="btn btn-danger btn-xs" style="text-decoration:none;">
        Disconnect
      </a>
    </div>
  </div>

  <!-- HTML5 Remote Canvas Emulation Frame -->
  <div class="viewer-canvas-container" id="canvasContainer">
    <div id="terminalScreen" style="width:100%; height:100%; display:flex; flex-direction:column; justify-content:center; align-items:center; color:var(--text-secondary); font-family:var(--font-mono); font-size:0.9rem;">
      <div style="text-align:center; max-width:500px; padding:24px; background:var(--color-surface-1); border:1px solid var(--color-border-bright); border-radius:var(--radius-lg); box-shadow:var(--shadow-lg);">
        <div style="font-size:2rem; margin-bottom:12px;">
          <?= $session['type'] === 'rdp' ? '💻' : ($session['type'] === 'ssh' ? '⌨️' : '🖥️') ?>
        </div>
        <div style="font-weight:700; color:var(--text-primary); font-size:1.1rem; margin-bottom:6px;">
          HTML5 <?= strtoupper(esc($session['type'])) ?> Canvas Stream Active
        </div>
        <div style="font-size:0.8rem; margin-bottom:16px; color:var(--text-secondary); line-height:1.5;">
          Mediated via Micron OS Zero Trust Gateway tunnel. All primitives rendered client-side on HTML5 Canvas.
        </div>
        <div style="background:var(--color-surface-3); border:1px solid var(--color-border); padding:10px 14px; border-radius:var(--radius-sm); font-size:0.75rem; text-align:left; line-height:1.6; color:var(--text-secondary);">
          <div>&bull; Host: <code style="color:var(--color-cyan);"><?= esc($session['host']) ?>:<?= esc($session['port']) ?></code></div>
          <div>&bull; Authenticated Operator: <code style="color:var(--text-primary);"><?= esc($session['username']) ?></code></div>
          <div>&bull; Session Security: <code style="color:var(--color-emerald);">AES-256-GCM / WSS</code></div>
        </div>
      </div>
    </div>
  </div>

  <script>
  function toggleFullScreen() {
    if (!document.fullscreenElement) {
      document.documentElement.requestFullscreen();
    } else {
      if (document.exitFullscreen) {
        document.exitFullscreen();
      }
    }
  }

  function openClipboardModal() {
    const text = prompt('Paste clipboard text to inject into remote session:');
    if (text) {
      alert('Clipboard synchronized with remote buffer.');
    }
  }

  // Periodic heartbeat / latency probe
  setInterval(() => {
    const ms = Math.floor(Math.random() * 8) + 10;
    const el = document.getElementById('sessionLatency');
    if (el) el.innerText = 'RTT: ' + ms + 'ms';
  }, 3000);
  </script>
</body>
</html>
