<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Voucher Print — <?= esc($rollId) ?></title>
  <style>
    @import url('<?= base_url('assets/fonts/fonts.css') ?>'); /* self-hosted: the portal must render with no internet */
    *, *::before, *::after { box-sizing: border-box; }
    body {
      font-family: 'Inter', sans-serif;
      background: #fff;
      color: #111;
      margin: 0;
      padding: 16px;
    }
    .print-header {
      text-align: center;
      margin-bottom: 24px;
      padding-bottom: 16px;
      border-bottom: 2px solid #e5e7eb;
    }
    .print-header h1 { font-size: 1.4rem; font-weight: 900; margin: 0 0 4px; }
    .print-header p { font-size: 0.8rem; color: #6b7280; margin: 0; }
    .cards-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 12px;
    }
    .voucher-card {
      border: 2px dashed #d1d5db;
      border-radius: 10px;
      padding: 16px 12px;
      text-align: center;
      page-break-inside: avoid;
      position: relative;
      background: #fafafa;
    }
    .voucher-card .network-name {
      font-size: 0.65rem;
      font-weight: 700;
      text-transform: uppercase;
      color: #6b7280;
      letter-spacing: 1px;
      margin-bottom: 6px;
    }
    .voucher-card .code {
      font-family: 'JetBrains Mono', 'Courier New', monospace;
      font-size: 1.3rem;
      font-weight: 900;
      letter-spacing: 3px;
      color: #111;
      background: #f3f4f6;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      padding: 10px 14px;
      margin: 8px 0;
      display: block;
    }
    .voucher-card .meta {
      font-size: 0.62rem;
      color: #6b7280;
      margin-top: 6px;
      line-height: 1.6;
    }
    .voucher-card .meta strong { color: #374151; }
    .voucher-card .cut-line {
      position: absolute;
      top: -6px; left: 50%;
      transform: translateX(-50%);
      font-size: 0.6rem;
      color: #d1d5db;
      background: #fff;
      padding: 0 4px;
      pointer-events: none;
    }
    .voucher-card .wifi-icon {
      color: #f59e0b;
      font-size: 1.2rem;
      margin-bottom: 4px;
    }
    .footer-note {
      text-align: center;
      margin-top: 24px;
      font-size: 0.7rem;
      color: #9ca3af;
    }
    @media print {
      body { padding: 8px; }
      .no-print { display: none !important; }
      .cards-grid { grid-template-columns: repeat(3, 1fr); }
    }
  </style>
</head>
<body>

<div class="print-header">
  <h1>Hotspot Voucher Batch — <?= esc($rollId) ?></h1>
  <p>Zone: <?= esc($zoneName) ?> | Generated: <?= date('Y-m-d H:i') ?> | Total: <?= count($vouchers) ?> vouchers</p>
</div>

<div class="no-print" style="text-align:center; margin-bottom:20px; display:flex; gap:10px; justify-content:center;">
  <button onclick="window.print()" style="background:#f59e0b; border:none; border-radius:6px; padding:10px 24px; font-weight:700; cursor:pointer; font-size:0.9rem;">🖨 Print Vouchers</button>
  <a href="<?= base_url('captive/vouchers') ?>" style="background:#e5e7eb; border:none; border-radius:6px; padding:10px 24px; font-weight:700; cursor:pointer; font-size:0.9rem; text-decoration:none; color:#111;">← Back to Vouchers</a>
</div>

<?php if (empty($vouchers)): ?>
  <p style="text-align:center; color:#6b7280;">No vouchers found in roll <strong><?= esc($rollId) ?></strong>.</p>
<?php else: ?>
  <div class="cards-grid">
    <?php foreach ($vouchers as $v): ?>
      <div class="voucher-card">
        <span class="cut-line">✂ - - -</span>
        <div class="wifi-icon">📶</div>
        <div class="network-name"><?= esc($zoneName) ?></div>
        <span class="code"><?= esc($v['code']) ?></span>
        <div class="meta">
          <strong>Duration:</strong> <?= (int)$v['duration_minutes'] >= 60
              ? round($v['duration_minutes'] / 60, 1) . ' hour(s)'
              : $v['duration_minutes'] . ' min' ?>
          <br>
          <strong>Data Quota:</strong> <?= (int)$v['data_quota_mb'] > 0 ? $v['data_quota_mb'] . ' MB' : 'Unlimited' ?>
          <br>
          <strong>Max Devices:</strong> <?= (int)$v['max_devices'] ?>
          <br>
          <strong>Valid Until:</strong> <?= $v['expires_at'] ? esc(substr($v['expires_at'], 0, 10)) : 'Ongoing' ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="footer-note">
  Vouchers are for one-time use. Report misuse to the network administrator. — MicronOS Hotspot Engine v0.1.31
</div>

</body>
</html>
