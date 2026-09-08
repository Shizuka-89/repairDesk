<?php
/**
 * RepairDesk – Ticket & Reparaturverwaltung
 * 
 * @copyright  2026 NORIX IT Support und Webdesign e.U. – norix.at
 * @license    Proprietär – Alle Rechte vorbehalten
 * @version    1.4.0
 * 
 * Dieses Programm ist urheberrechtlich geschützt.
 * Weitergabe, Vervielfältigung oder Modifikation ohne
 * ausdrückliche schriftliche Genehmigung ist untersagt.
 */
 
 
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$currentTech = getCurrentTechnician();
$flash       = getFlash();
$pageTitle   = $pageTitle ?? 'RepairDesk';
$activeMenu  = $activeMenu ?? '';

// Branding aus Einstellungen
$appName     = getSetting('app_name',         'RepairDesk');
$appIcon     = getSetting('app_icon',         '🔧');
$accentColor = getSetting('app_accent_color', '#2563eb');
$sidebarColor   = getSetting('app_sidebar_color','#1e293b');
$sectionColor   = getSetting('app_sidebar_section_color','#475569');
$baseFontSize   = getSetting('app_font_size','14');
$sectionFontSize= getSetting('app_sidebar_section_font','10');
$navFontSize    = getSetting('app_sidebar_nav_font','14');
$navColor       = getSetting('app_sidebar_nav_color','#94a3b8');
$navActiveColor = getSetting('app_sidebar_nav_active_color','#ffffff');
$navActiveBg    = getSetting('app_sidebar_nav_active_bg','rgba(255,255,255,.08)');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<title><?= h($pageTitle) ?> – <?= h($appName) ?></title>
<style>
:root {
  --accent:  <?= h($accentColor) ?>;
  --sidebar: <?= h($sidebarColor) ?>;
  --accent-light: <?= h($accentColor) ?>22;
  --sidebar-section: <?= h($sectionColor) ?>;
  --base-font: <?= (int)$baseFontSize ?>px;
  --sidebar-section-font: <?= (int)$sectionFontSize ?>px;
  --sidebar-nav-font: <?= (int)$navFontSize ?>px;
  --nav-color: <?= h($navColor) ?>;
  --nav-active-color: <?= h($navActiveColor) ?>;
  --nav-active-bg: <?= h($navActiveBg) ?>;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; font-size: var(--base-font); background: #f1f5f9; color: #1e293b; min-height: 100vh; }
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }

.sidebar { position: fixed; left: 0; top: 0; bottom: 0; width: 240px; background: var(--sidebar); z-index: 100; overflow-y: auto; }
.sidebar-logo { padding: 20px 20px 15px; border-bottom: 1px solid rgba(255,255,255,.08); }
.sidebar-logo h1 { color: #fff; font-size: 18px; font-weight: 700; }
.sidebar-logo p  { color: #94a3b8; font-size: 11px; margin-top: 2px; }
.sidebar-nav { padding: 10px 0; }
.sidebar-nav a { display: flex; align-items: center; gap: 10px; padding: 11px 20px; color: var(--nav-color); font-size: var(--sidebar-nav-font); transition: all 0.15s; }
.sidebar-nav a:hover, .sidebar-nav a.active { background: var(--nav-active-bg); color: var(--nav-active-color); text-decoration: none; }
.sidebar-nav a.active { border-left: 3px solid var(--accent); }
.sidebar-nav .icon { font-size: 18px; width: 22px; text-align: center; }
.sidebar-section { padding: 10px 20px 4px; font-size: var(--sidebar-section-font); text-transform: uppercase; letter-spacing: 1px; color: var(--sidebar-section); }

.main { margin-left: 240px; min-height: 100vh; }
.topbar { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
.topbar h2 { font-size: 18px; font-weight: 600; }
.topbar .user { font-size: 13px; color: #64748b; display: flex; align-items: center; gap: 10px; }
.topbar .user a { color: #ef4444; font-size: 12px; }
.content { padding: 24px; }

.flash { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; gap: 10px; }
.flash.success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
.flash.error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.flash.info    { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }

.card { background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 20px; }
.card-header { padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
.card-header h3 { font-size: 15px; font-weight: 600; }
.card-body { padding: 20px; }

.btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; border: none; transition: all 0.15s; text-decoration: none; }
.btn:hover { text-decoration: none; opacity: 0.9; }
.btn-primary   { background: var(--accent); color: #fff; }
.btn-success   { background: #10b981; color: #fff; }
.btn-warning   { background: #f59e0b; color: #fff; }
.btn-danger    { background: #ef4444; color: #fff; }
.btn-secondary { background: #e2e8f0; color: #475569; }
.btn-sm { padding: 6px 12px; font-size: 12px; }

.form-group { margin-bottom: 16px; }
.form-label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 5px; }
.form-control { width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; color: #1e293b; background: #fff; transition: border-color 0.15s; }
.form-control:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-light); }
textarea.form-control { resize: vertical; min-height: 80px; }
.form-row { display: grid; gap: 16px; }
.form-row.cols-2 { grid-template-columns: 1fr 1fr; }
.form-row.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
.form-help { font-size: 12px; color: #6b7280; margin-top: 4px; }
.required { color: #ef4444; }

.table-wrap { overflow-x: auto; }
table.data-table { width: 100%; border-collapse: collapse; font-size: 14px; }
table.data-table th { background: #f8fafc; padding: 10px 14px; text-align: left; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; border-bottom: 1px solid #e2e8f0; }
table.data-table td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
table.data-table tr:hover td { background: #f8fafc; }
table.data-table tr:last-child td { border-bottom: none; }

.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-bottom: 24px; }
.stat-card { background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; padding: 20px; }
.stat-card .label { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
.stat-card .value { font-size: 28px; font-weight: 700; color: #1e293b; }

.badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-blue { background: #dbeafe; color: #1e40af; }
.badge-gray { background: #f1f5f9; color: #475569; }

.comment { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; margin-bottom: 12px; }
.comment.internal { background: #fffbeb; border-color: #fde68a; }
.comment.customer { background: #eff6ff; border-color: #bfdbfe; }
.comment-header { display: flex; justify-content: space-between; font-size: 12px; color: #64748b; margin-bottom: 8px; }
.comment-body { font-size: 14px; line-height: 1.6; }
.price-proposal { background: #fef3c7; border: 1px solid #fbbf24; border-radius: 6px; padding: 12px; margin-top: 10px; }
.section-divider { border: none; border-top: 1px solid #e2e8f0; margin: 24px 0; }

@media (max-width: 768px) {
  .sidebar { transform: translateX(-100%); }
  .main { margin-left: 0; }
  .form-row.cols-2, .form-row.cols-3 { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<div class="sidebar">
  <div class="sidebar-logo">
    <h1><?= h($appIcon) ?> <?= h($appName) ?></h1>
    <p><?= h(getSetting('company_name', defined('COMPANY_NAME') ? COMPANY_NAME : '')) ?></p>
  </div>
  <nav class="sidebar-nav">
    <div class="sidebar-section">Übersicht</div>
    <a href="<?= BASE_URL ?>/index.php" class="<?= $activeMenu === 'dashboard' ? 'active' : '' ?>">
      <span class="icon">📊</span> Dashboard</a>

    <div class="sidebar-section">Tickets</div>
    <a href="<?= BASE_URL ?>/tickets.php" class="<?= $activeMenu === 'tickets' ? 'active' : '' ?>">
      <span class="icon">🎫</span> Alle Tickets</a>
    <a href="<?= BASE_URL ?>/ticket_new.php" class="<?= $activeMenu === 'new_ticket' ? 'active' : '' ?>">
      <span class="icon">➕</span> Neues Ticket</a>

    <div class="sidebar-section">Kunden</div>
    <a href="<?= BASE_URL ?>/customers.php" class="<?= $activeMenu === 'customers' ? 'active' : '' ?>">
      <span class="icon">👥</span> Kundenliste</a>
    <a href="<?= BASE_URL ?>/customer_new.php" class="<?= $activeMenu === 'new_customer' ? 'active' : '' ?>">
      <span class="icon">➕</span> Neuer Kunde</a>

    <?php if (isAdmin()): ?>
    <div class="sidebar-section">Verwaltung</div>
    <a href="<?= BASE_URL ?>/technicians.php" class="<?= $activeMenu === 'technicians' ? 'active' : '' ?>">
      <span class="icon">👨‍🔧</span> Techniker</a>
    <a href="<?= BASE_URL ?>/custom_fields.php" class="<?= $activeMenu === 'custom_fields' ? 'active' : '' ?>">
      <span class="icon">🔧</span> Ticket-Felder</a>
    
    <a href="<?= BASE_URL ?>/settings.php" class="<?= $activeMenu === 'settings' ? 'active' : '' ?>">
      <span class="icon">⚙️</span> Einstellungen</a>
    <a href="<?= BASE_URL ?>/license.php" class="<?= $activeMenu === 'license' ? 'active' : '' ?>">
      <span class="icon">🔑</span> Lizenz & Updates</a>
    <?php endif; ?>

    <div class="sidebar-section">Konto</div>
    <a href="<?= BASE_URL ?>/2fa_setup.php">
      <span class="icon"><?= $currentTech['totp_enabled'] ?? 0 ? '🔐' : '🔓' ?></span>
      2FA <?= $currentTech['totp_enabled'] ?? 0 ? '(aktiv)' : 'einrichten' ?></a>
    <a href="<?= BASE_URL ?>/logout.php">
      <span class="icon">🚪</span> Abmelden</a>
  </nav>
</div>

<div class="main">
  <div class="topbar">
    <h2><?= h($pageTitle) ?></h2>
    <div class="user">
      Eingeloggt als <strong><?= h($currentTech['name'] ?? '') ?></strong>
      <a href="<?= BASE_URL ?>/logout.php">Abmelden</a>
    </div>
  </div>
  <div class="content">
    <?php if ($flash): ?>
    <div class="flash <?= $flash['type'] ?>">
      <?= $flash['type'] === 'success' ? '✅' : ($flash['type'] === 'error' ? '❌' : 'ℹ️') ?>
      <?= h($flash['message']) ?>
    </div>
    <?php endif; ?>

<?php
// ── Automatischer E-Mail-Abruf im Hintergrund ──────────────────
// Läuft nur, wenn der Empfang eingeschaltet ist. Der Abruf selbst
// (inkl. Drosselung) passiert serverseitig in imap_autofetch.php.
$imapForceFetch = !empty($_SESSION['imap_fetch_pending']);
if (getSetting('imap_enabled', '0') === '1'
    && ($imapForceFetch || getSetting('imap_autofetch_enabled', '1') === '1')):
?>
<div id="imap-hinweis" style="display:none;position:fixed;right:20px;bottom:20px;z-index:9999;
     background:#065f46;color:#fff;padding:12px 16px;border-radius:8px;font-size:13px;
     box-shadow:0 4px 16px rgba(0,0,0,.25);max-width:320px;">
  <span id="imap-hinweis-text"></span>
  <a href="#" onclick="location.reload();return false;"
     style="color:#a7f3d0;text-decoration:underline;margin-left:8px;white-space:nowrap;">Seite neu laden</a>
</div>
<script>
(function () {
  var url = '<?= BASE_URL ?>/imap_autofetch.php<?= $imapForceFetch ? '?force=1' : '' ?>';

  fetch(url, { credentials: 'same-origin' })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (d) {
      if (!d || !d.ran) return;
      var text = '';
      if (d.new > 0) {
        text = '📬 ' + d.new + (d.new === 1 ? ' neue Kundenantwort' : ' neue Kundenantworten')
             + ' eingegangen.';
      } else if (d.attention > 0) {
        text = '⚠️ ' + d.attention + ' Antwort mit Ticketnummer wurde abgelehnt '
             + '(Absenderadresse passt nicht zum Kunden).';
      }
      if (!text) return;
      // Bewusst KEIN automatischer Reload: sonst gingen Eingaben in
      // geöffneten Formularen verloren. Der Hinweis bleibt stehen.
      document.getElementById('imap-hinweis-text').textContent = text;
      document.getElementById('imap-hinweis').style.display = 'block';
    })
    .catch(function () { /* Abruf still fehlschlagen lassen – nie die Seite stören */ });
})();
</script>
<?php endif; ?>