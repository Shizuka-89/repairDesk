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
$pageTitle  = 'Einstellungen';
$activeMenu = 'settings';
require_once __DIR__ . '/../includes/header.php';

if (!isAdmin()) {
    flash('Nur Admins koennen Einstellungen aendern.', 'error');
    header('Location: ' . BASE_URL . '/index.php'); exit;
}

$db  = getDB();
$tab = $_GET['tab'] ?? 'branding';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'branding') {
        foreach (['app_name','app_icon','app_accent_color','app_sidebar_color','app_sidebar_section_color','login_bg_color','app_font_size','app_sidebar_section_font','app_sidebar_nav_font','app_sidebar_nav_color','app_sidebar_nav_active_color','app_sidebar_nav_active_bg'] as $k) {
            saveSetting($k, trim($_POST[$k] ?? ''));
        }
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:sans-serif;padding:40px;background:#10b981;color:#fff;text-align:center;">';
        echo '<h1>✅ Branding gespeichert!</h1>';
        echo '<p style="margin-top:20px;"><a href="' . BASE_URL . '/settings.php?tab=branding" style="color:#fff;font-size:18px;">← Zurück</a></p>';
        echo '</body></html>';
        exit;
    }

    if ($action === 'company') {
        foreach (['company_name','company_email','company_phone'] as $k) {
            saveSetting($k, trim($_POST[$k] ?? ''));
        }
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:sans-serif;padding:40px;background:#10b981;color:#fff;text-align:center;">';
        echo '<h1>✅ Firmendaten gespeichert!</h1>';
        echo '<p style="margin-top:20px;"><a href="' . BASE_URL . '/settings.php?tab=company" style="color:#fff;font-size:18px;">← Zurück</a></p>';
        echo '</body></html>';
        exit;
    }

    if ($action === 'mail') {
        foreach (['mail_method','smtp_host','smtp_port','smtp_secure','smtp_user','smtp_from_name','smtp_auth_method'] as $k) {
            saveSetting($k, trim($_POST[$k] ?? ''));
        }
        // Passwort nur überschreiben, wenn ein neues eingegeben wurde
        $newPass = trim($_POST['smtp_pass'] ?? '');
        if ($newPass !== '') {
            saveSetting('smtp_pass', $newPass);
        }

        // ── Gemeinsame Office-365-OAuth2-Zugangsdaten (Empfang + Versand) ──
        $oauthChanged = false;
        foreach (['oauth_tenant','oauth_client_id'] as $k) {
            $val = trim($_POST[$k] ?? '');
            if ($val !== getSetting($k, '')) $oauthChanged = true;
            saveSetting($k, $val);
        }
        $newSecret = trim($_POST['oauth_client_secret'] ?? '');
        if ($newSecret !== '') {
            saveSetting('oauth_client_secret', $newSecret);
            $oauthChanged = true;
        }
        // Bei Änderung: zwischengespeichertes Token verwerfen → neues wird geholt
        if ($oauthChanged) {
            saveSetting('oauth_token', '');
            saveSetting('oauth_token_expires', '0');
            // Client-ID/Secret geändert → bestehende Verbindung ungültig, neu verbinden nötig
            saveSetting('oauth_refresh_token', '');
            saveSetting('oauth_connected_email', '');
        }

        // ── E-Mail-Empfang (IMAP) ──
        saveSetting('imap_enabled', isset($_POST['imap_enabled']) ? '1' : '0');
        foreach (['imap_host','imap_port','imap_secure','imap_user','imap_folder','imap_auth_method'] as $k) {
            saveSetting($k, trim($_POST[$k] ?? ''));
        }
        // Abruf-Intervall (Sekunden) für den automatischen Hintergrund-Abruf
        $interval = (int)($_POST['imap_fetch_interval'] ?? 300);
        if ($interval < 60)   $interval = 60;
        if ($interval > 3600) $interval = 3600;
        saveSetting('imap_fetch_interval', (string)$interval);
        saveSetting('imap_fetch_on_login',     isset($_POST['imap_fetch_on_login'])     ? '1' : '0');
        saveSetting('imap_autofetch_enabled',  isset($_POST['imap_autofetch_enabled'])  ? '1' : '0');
        $newImapPass = trim($_POST['imap_pass'] ?? '');
        if ($newImapPass !== '') {
            saveSetting('imap_pass', $newImapPass);
        }
        // Cron-Schlüssel einmalig erzeugen
        if (getSetting('imap_cron_key', '') === '') {
            saveSetting('imap_cron_key', bin2hex(random_bytes(24)));
        }

        flash('E-Mail-Einstellungen gespeichert. Nutze den Testversand, um die Verbindung zu prüfen.', 'success');
        header('Location: ' . BASE_URL . '/settings.php?tab=mail');
        exit;
    }

    // ── E-Mails jetzt abrufen (Test des E-Mail-Empfangs) ──
    if ($action === 'imap_fetch_now') {
        require_once __DIR__ . '/../includes/mail_inbox.php';
        $r = fetchInboxMails();
        if ($r['ok']) {
            flash('📬 ' . mailFetchSummary($r), 'success');
        } else {
            flash('❌ Abruf fehlgeschlagen: ' . $r['error'], 'error');
        }
        header('Location: ' . BASE_URL . '/settings.php?tab=mail');
        exit;
    }

    // ── Abrufzähler zurücksetzen (nur bei Problemen nötig) ──
    if ($action === 'imap_reset_uid') {
        saveSetting('imap_last_uid', '0');
        saveSetting('imap_uidvalidity', '0');
        flash('Abrufzähler zurückgesetzt. Beim nächsten Abruf werden die noch ungelesenen E-Mails '
            . 'abgearbeitet und der Zähler neu gesetzt. Bereits verarbeitete Antworten kommen nicht doppelt an.', 'success');
        header('Location: ' . BASE_URL . '/settings.php?tab=mail');
        exit;
    }

    if ($action === 'ticket') {
        saveSetting('show_device_info', isset($_POST['show_device_info']) ? '1' : '0');
        foreach ([
            'label_device_section',
            'label_device_model',
            'label_serial_number',
            'label_condition_on_arrival',
            'label_accessories',
            'label_damage_description',
        ] as $k) {
            saveSetting($k, trim($_POST[$k] ?? ''));
        }
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:sans-serif;padding:40px;background:#10b981;color:#fff;text-align:center;">';
        echo '<h1>✅ Ticket-Einstellungen gespeichert!</h1>';
        echo '<p style="margin-top:20px;"><a href="' . BASE_URL . '/settings.php?tab=ticket" style="color:#fff;font-size:18px;">← Zurück</a></p>';
        echo '</body></html>';
        exit;
    }

    if ($action === 'logout_all') {
        saveSetting('force_logout_after', (string) time());
        $_SESSION['login_time'] = time() + 1; // eigene Sitzung bleibt aktiv
        clearAllTrustedDevices();
        flash('Alle anderen Sitzungen wurden abgemeldet und alle vertrauten Geräte entfernt. Deine aktuelle Sitzung bleibt aktiv.', 'success');
        header('Location: ' . BASE_URL . '/settings.php?tab=security');
        exit;
    }

    if ($action === 'backup_create') {
        $r = createDatabaseBackup();
        if ($r['ok']) {
            flash('Backup erstellt: ' . $r['name'] . ' (' . formatBytes((int)$r['size']) . ').', 'success');
        } else {
            flash('Backup fehlgeschlagen: ' . $r['error'], 'error');
        }
        header('Location: ' . BASE_URL . '/settings.php?tab=backup');
        exit;
    }

    if ($action === 'backup_delete') {
        if (deleteBackup($_POST['file'] ?? '')) {
            flash('Backup gelöscht.', 'success');
        } else {
            flash('Backup konnte nicht gelöscht werden.', 'error');
        }
        header('Location: ' . BASE_URL . '/settings.php?tab=backup');
        exit;
    }
}

$v = [
    'app_name'          => getSetting('app_name',          'RepairDesk'),
    'app_icon'          => getSetting('app_icon',          '🔧'),
    'app_accent_color'  => getSetting('app_accent_color',  '#2563eb'),
    'app_sidebar_color' => getSetting('app_sidebar_color', '#1e293b'),
    'login_bg_color'    => getSetting('login_bg_color',    '#334155'),
    'app_sidebar_section_color' => getSetting('app_sidebar_section_color', '#475569'),
    'app_font_size'     => getSetting('app_font_size',      '14'),
    'app_sidebar_section_font' => getSetting('app_sidebar_section_font', '10'),
    'app_sidebar_nav_font'     => getSetting('app_sidebar_nav_font',     '14'),
    'app_sidebar_nav_color'        => getSetting('app_sidebar_nav_color',        '#94a3b8'),
    'app_sidebar_nav_active_color' => getSetting('app_sidebar_nav_active_color', '#ffffff'),
    'app_sidebar_nav_active_bg'    => getSetting('app_sidebar_nav_active_bg',    'rgba(255,255,255,.08)'),
    'company_name'      => getSetting('company_name',      defined('COMPANY_NAME')  ? COMPANY_NAME  : 'Mein Shop'),
    'company_email'     => getSetting('company_email',     defined('COMPANY_EMAIL') ? COMPANY_EMAIL : ''),
    'company_phone'     => getSetting('company_phone',     defined('COMPANY_PHONE') ? COMPANY_PHONE : ''),
    'mail_method'       => getSetting('mail_method',       defined('MAIL_METHOD')   ? MAIL_METHOD   : 'php_mail'),
    'smtp_host'         => getSetting('smtp_host',         defined('SMTP_HOST')     ? SMTP_HOST     : ''),
    'smtp_port'         => getSetting('smtp_port',         defined('SMTP_PORT')     ? (string)SMTP_PORT : '587'),
    'smtp_secure'       => getSetting('smtp_secure',       defined('SMTP_SECURE')   ? SMTP_SECURE   : 'tls'),
    'smtp_user'         => getSetting('smtp_user',         defined('SMTP_USER')     ? SMTP_USER     : ''),
    'smtp_from_name'    => getSetting('smtp_from_name',    defined('SMTP_FROM_NAME')? SMTP_FROM_NAME: ''),
    'smtp_pass_set'     => getSetting('smtp_pass','') !== '',
    'imap_enabled'      => getSetting('imap_enabled',  '0'),
    'imap_host'         => getSetting('imap_host',     ''),
    'imap_port'         => getSetting('imap_port',     '993'),
    'imap_secure'       => getSetting('imap_secure',   'ssl'),
    'imap_user'         => getSetting('imap_user',     ''),
    'imap_folder'       => getSetting('imap_folder',   'INBOX'),
    'imap_pass_set'      => getSetting('imap_pass','') !== '',
    'imap_cron_key'      => getSetting('imap_cron_key', ''),
    'imap_fetch_interval'=> getSetting('imap_fetch_interval', '300'),
    'imap_last_uid'      => getSetting('imap_last_uid', '0'),
    'imap_fetch_on_login'    => getSetting('imap_fetch_on_login',    '1'),
    'imap_autofetch_enabled' => getSetting('imap_autofetch_enabled', '1'),
    'smtp_auth_method'   => getSetting('smtp_auth_method', 'password'),
    'imap_auth_method'   => getSetting('imap_auth_method', 'password'),
    'oauth_tenant'       => getSetting('oauth_tenant', ''),
    'oauth_client_id'    => getSetting('oauth_client_id', ''),
    'oauth_secret_set'   => getSetting('oauth_client_secret','') !== '',
    'oauth_connected'    => getSetting('oauth_refresh_token','') !== '',
    'oauth_connected_email' => getSetting('oauth_connected_email',''),
    'show_device_info'             => getSetting('show_device_info', '1'),
    'label_device_section'         => getSetting('label_device_section',         'Geräteinformationen'),
    'label_device_model'           => getSetting('label_device_model',           'Gerät / Modell'),
    'label_serial_number'          => getSetting('label_serial_number',           'Seriennummer / IMEI'),
    'label_condition_on_arrival'   => getSetting('label_condition_on_arrival',   'Zustand bei Annahme'),
    'label_accessories'            => getSetting('label_accessories',            'Zubehör mitgebracht'),
    'label_damage_description'     => getSetting('label_damage_description',     'Schadensbeschreibung'),
];

$tabs = [
    'branding' => ['🎨','Aussehen'],
    'company'  => ['🏪','Firma'],
    'mail'     => ['📧','E-Mail'],
    'ticket'   => ['🎫','Ticket'],
    'security' => ['🔐','Sicherheit'],
    'backup'   => ['🗄️','Backup'],
];
?>

<!-- Tab-Navigation -->
<div style="display:flex;gap:4px;margin-bottom:20px;border-bottom:2px solid #e2e8f0;padding-bottom:0;">
  <?php foreach ($tabs as $key => [$icon,$label]): ?>
  <a href="?tab=<?= $key ?>"
     style="padding:10px 20px;font-size:14px;font-weight:600;border-radius:6px 6px 0 0;
            text-decoration:none;margin-bottom:-2px;border-bottom:2px solid transparent;
            <?= $tab===$key ? 'background:#fff;border-color:var(--accent);color:var(--accent);' : 'color:#64748b;' ?>">
    <?= $icon ?> <?= $label ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'branding'): ?>
<!-- ── Aussehen ── -->
<form method="post">
  <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
  <input type="hidden" name="action"     value="branding">

  <div class="card">
    <div class="card-header"><h3>🎨 App-Name & Icon</h3></div>
    <div class="card-body">
      <div class="form-row cols-2">
        <div class="form-group">
          <label class="form-label">App-Name</label>
          <input type="text" name="app_name" class="form-control"
                 value="<?= h($v['app_name']) ?>" placeholder="RepairDesk"
                 oninput="document.getElementById('prev-name').textContent=this.value">
          <p class="form-help">Erscheint in Sidebar, Browser-Tab und Login-Seite.</p>
        </div>
        <div class="form-group">
          <label class="form-label">Icon / Emoji</label>
          <input type="text" name="app_icon" class="form-control"
                 value="<?= h($v['app_icon']) ?>" placeholder="🔧" maxlength="8"
                 style="font-size:24px;text-align:center;"
                 oninput="document.getElementById('prev-icon').textContent=this.value">
          <p class="form-help">Emoji oder Text — erscheint vor dem App-Namen.</p>
        </div>
      </div>

      <!-- Live-Vorschau Sidebar -->
      <div style="background:#1e293b;border-radius:8px;padding:16px;max-width:240px;margin-top:8px;">
        <p style="color:#94a3b8;font-size:10px;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Vorschau Sidebar</p>
        <div style="display:flex;align-items:center;gap:8px;">
          <span id="prev-icon"  style="font-size:22px;"><?= h($v['app_icon']) ?></span>
          <span id="prev-name"  style="color:#fff;font-size:15px;font-weight:700;"><?= h($v['app_name']) ?></span>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>🎨 Farben</h3></div>
    <div class="card-body">
      <div class="form-row cols-3" style="margin-bottom:16px;">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Akzentfarbe (Buttons, Links)</label>
          <div style="display:flex;gap:8px;align-items:center;">
            <input type="color" name="app_accent_color" value="<?= h($v['app_accent_color']) ?>"
                   style="width:48px;height:40px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;padding:2px;">
            <input type="text"  id="accent-hex" class="form-control" style="flex:1;"
                   value="<?= h($v['app_accent_color']) ?>" placeholder="#2563eb"
                   oninput="document.querySelector('[name=app_accent_color]').value=this.value;updateSidebarPreview()">
          </div>
          <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;">
            <?php foreach (['#2563eb','#7c3aed','#059669','#dc2626','#d97706','#0891b2'] as $c): ?>
            <button type="button" onclick="setColor('app_accent_color','accent-hex','<?= $c ?>');updateSidebarPreview()"
                    style="width:28px;height:28px;background:<?= $c ?>;border:2px solid #fff;border-radius:50%;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.2);"></button>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Sidebar-Hintergrund</label>
          <div style="display:flex;gap:8px;align-items:center;">
            <input type="color" name="app_sidebar_color" value="<?= h($v['app_sidebar_color']) ?>"
                   style="width:48px;height:40px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;padding:2px;">
            <input type="text" id="sidebar-hex" class="form-control" style="flex:1;"
                   value="<?= h($v['app_sidebar_color']) ?>" placeholder="#1e293b"
                   oninput="document.querySelector('[name=app_sidebar_color]').value=this.value;updateSidebarPreview()">
          </div>
          <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;">
            <?php foreach (['#1e293b','#111827','#312e81','#064e3b','#7f1d1d','#1c1917'] as $c): ?>
            <button type="button" onclick="setColor('app_sidebar_color','sidebar-hex','<?= $c ?>');updateSidebarPreview()"
                    style="width:28px;height:28px;background:<?= $c ?>;border:2px solid #fff;border-radius:50%;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.2);"></button>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Sidebar-Section-Farbe</label>
          <div style="display:flex;gap:8px;align-items:center;">
            <input type="color" name="app_sidebar_section_color" value="<?= h($v['app_sidebar_section_color']) ?>"
                   style="width:48px;height:40px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;padding:2px;">
            <input type="text" id="section-hex" class="form-control" style="flex:1;"
                   value="<?= h($v['app_sidebar_section_color']) ?>" placeholder="#475569"
                   oninput="document.querySelector('[name=app_sidebar_section_color]').value=this.value;updateSidebarPreview()">
          </div>
          <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;">
            <?php foreach (['#475569','#94a3b8','#cbd5e1','#fff','#7dd3fc','#86efac','#fde68a','#fca5a5'] as $c): ?>
            <button type="button" onclick="setColor('app_sidebar_section_color','section-hex','<?= $c ?>');updateSidebarPreview()"
                    style="width:28px;height:28px;background:<?= $c ?>;border:2px solid #e2e8f0;border-radius:50%;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.2);"></button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="form-row cols-3" style="margin-top:16px;padding-top:16px;border-top:1px solid #e2e8f0;">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Menü-Links Farbe</label>
          <div style="display:flex;gap:8px;align-items:center;">
            <input type="color" name="app_sidebar_nav_color" value="<?= h($v['app_sidebar_nav_color']) ?>"
                   style="width:48px;height:40px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;padding:2px;">
            <input type="text" id="nav-color-hex" class="form-control" style="flex:1;"
                   value="<?= h($v['app_sidebar_nav_color']) ?>" placeholder="#94a3b8"
                   oninput="document.querySelector('[name=app_sidebar_nav_color]').value=this.value;updateSidebarPreview()">
          </div>
          <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;">
            <?php foreach (['#94a3b8','#cbd5e1','#e2e8f0','#ffffff','#7dd3fc','#86efac','#fde68a','#fca5a5'] as $c): ?>
            <button type="button" onclick="setColor('app_sidebar_nav_color','nav-color-hex','<?= $c ?>');updateSidebarPreview()"
                    style="width:28px;height:28px;background:<?= $c ?>;border:2px solid #e2e8f0;border-radius:50%;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.2);"></button>
            <?php endforeach; ?>
          </div>
          <p class="form-help">Normale Menüpunkte</p>
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Aktiv / Hover Farbe</label>
          <div style="display:flex;gap:8px;align-items:center;">
            <input type="color" name="app_sidebar_nav_active_color" value="<?= h($v['app_sidebar_nav_active_color']) ?>"
                   style="width:48px;height:40px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;padding:2px;">
            <input type="text" id="nav-active-hex" class="form-control" style="flex:1;"
                   value="<?= h($v['app_sidebar_nav_active_color']) ?>" placeholder="#ffffff"
                   oninput="document.querySelector('[name=app_sidebar_nav_active_color]').value=this.value;updateSidebarPreview()">
          </div>
          <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;">
            <?php foreach (['#ffffff','#f1f5f9','#7dd3fc','#86efac','#fde68a','#fca5a5','#e9d5ff','#fed7aa'] as $c): ?>
            <button type="button" onclick="setColor('app_sidebar_nav_active_color','nav-active-hex','<?= $c ?>');updateSidebarPreview()"
                    style="width:28px;height:28px;background:<?= $c ?>;border:2px solid #e2e8f0;border-radius:50%;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.2);"></button>
            <?php endforeach; ?>
          </div>
          <p class="form-help">Ausgewählter / Hover Menüpunkt</p>
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Aktiv Hintergrund</label>
          <div style="display:flex;gap:8px;align-items:center;">
            <input type="text" name="app_sidebar_nav_active_bg" id="nav-bg-text" class="form-control"
                   value="<?= h($v['app_sidebar_nav_active_bg']) ?>" placeholder="rgba(255,255,255,.08)"
                   oninput="updateSidebarPreview()">
          </div>
          <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;">
            <?php foreach (['rgba(255,255,255,.08)'=>'Standard','rgba(255,255,255,.15)'=>'Heller','rgba(255,255,255,.25)'=>'Sehr hell','rgba(0,0,0,.2)'=>'Dunkler'] as $val=>$lbl): ?>
            <button type="button" onclick="document.getElementById('nav-bg-text').value='<?= $val ?>';document.querySelector('[name=app_sidebar_nav_active_bg]').value='<?= $val ?>';updateSidebarPreview()"
                    style="padding:3px 8px;border:1px solid #d1d5db;border-radius:5px;cursor:pointer;font-size:11px;background:#f8fafc;"><?= $lbl ?></button>
            <?php endforeach; ?>
          </div>
          <p class="form-help">rgba() für Transparenz möglich</p>
        </div>
      </div>

      <div class="form-row cols-2" style="margin-top:16px;padding-top:16px;border-top:1px solid #e2e8f0;">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Login-Hintergrund</label>
          <div style="display:flex;gap:8px;align-items:center;">
            <input type="color" name="login_bg_color" value="<?= h($v['login_bg_color']) ?>"
                   style="width:48px;height:40px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;padding:2px;">
            <input type="text" id="login-hex" class="form-control" style="flex:1;"
                   value="<?= h($v['login_bg_color']) ?>" placeholder="#334155"
                   oninput="document.querySelector('[name=login_bg_color]').value=this.value">
          </div>
          <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;">
            <?php foreach (['#334155','#1e293b','#3730a3','#065f46','#991b1b','#78350f'] as $c): ?>
            <button type="button" onclick="setColor('login_bg_color','login-hex','<?= $c ?>')"
                    style="width:28px;height:28px;background:<?= $c ?>;border:2px solid #fff;border-radius:50%;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.2);"></button>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Live-Vorschau Sidebar -->
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Vorschau Sidebar</label>
          <div id="prev-sidebar" style="border-radius:8px;padding:12px 16px;max-width:220px;transition:background .3s;"
               data-bg="<?= h($v['app_sidebar_color']) ?>">
            <div id="prev-section-label" style="font-size:9px;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;transition:color .3s;"
                 data-color="<?= h($v['app_sidebar_section_color']) ?>">Übersicht</div>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
              <span id="prev-icon"  style="font-size:20px;"><?= h($v['app_icon']) ?></span>
              <span id="prev-name"  style="color:#fff;font-size:14px;font-weight:700;"><?= h($v['app_name']) ?></span>
            </div>
            <div id="prev-inactive-item" style="display:flex;align-items:center;gap:8px;padding:5px 8px;font-size:13px;margin-top:2px;" data-color="<?= h($v['app_sidebar_nav_color']) ?>"><span>🎫</span> <span>Alle Tickets</span></div>
            <div id="prev-active-item" style="display:flex;align-items:center;gap:8px;padding:7px 8px;border-radius:4px;font-size:13px;color:#fff;margin-top:2px;transition:border-color .3s;"
                 data-accent="<?= h($v['app_accent_color']) ?>">
              <span>📊</span> <span>Dashboard</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>🔤 Schriftgröße</h3></div>
    <div class="card-body">
      <div class="form-row cols-2">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Basis-Schriftgröße</label>
          <div style="display:flex;align-items:center;gap:12px;">
            <input type="range" name="app_font_size" id="font-range"
                   min="11" max="18" step="1"
                   value="<?= h($v['app_font_size']) ?>"
                   oninput="document.getElementById('font-val').textContent=this.value+'px';document.getElementById('font-preview').style.fontSize=this.value+'px'"
                   style="flex:1;accent-color:var(--accent);">
            <span id="font-val" style="font-size:14px;font-weight:700;color:var(--accent);min-width:36px;"><?= h($v['app_font_size']) ?>px</span>
          </div>
          <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
            <?php foreach ([12,13,14,15,16,17,18] as $fs): ?>
            <button type="button"
                    onclick="document.getElementById('font-range').value=<?= $fs ?>;document.getElementById('font-val').textContent='<?= $fs ?>px';document.getElementById('font-preview').style.fontSize='<?= $fs ?>px'"
                    style="padding:4px 10px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;font-size:<?= $fs ?>px;background:#f8fafc;">
              <?= $fs ?>px
            </button>
            <?php endforeach; ?>
          </div>
          <p class="form-help">Standard: 14px — wirkt sich auf die gesamte Oberfläche aus.</p>

          <div style="margin-top:20px;border-top:1px solid #e2e8f0;padding-top:16px;display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div>
              <label class="form-label" style="margin-bottom:6px;">Sidebar-Menü Schrift</label>
              <div style="display:flex;align-items:center;gap:10px;">
                <input type="range" name="app_sidebar_nav_font" id="nav-font-range"
                       min="10" max="18" step="1"
                       value="<?= h($v['app_sidebar_nav_font']) ?>"
                       oninput="document.getElementById('nav-font-val').textContent=this.value+'px';document.getElementById('nav-font-preview').style.fontSize=this.value+'px'"
                       style="flex:1;accent-color:var(--accent);">
                <span id="nav-font-val" style="font-size:13px;font-weight:700;color:var(--accent);min-width:34px;"><?= h($v['app_sidebar_nav_font']) ?>px</span>
              </div>
              <p class="form-help">Menüpunkte in der Sidebar. Standard: 14px</p>
            </div>
            <div>
              <label class="form-label" style="margin-bottom:6px;">Sidebar-Sections Schrift</label>
              <div style="display:flex;align-items:center;gap:10px;">
                <input type="range" name="app_sidebar_section_font" id="sec-font-range"
                       min="8" max="14" step="1"
                       value="<?= h($v['app_sidebar_section_font']) ?>"
                       oninput="document.getElementById('sec-font-val').textContent=this.value+'px';document.getElementById('sec-font-preview').style.fontSize=this.value+'px'"
                       style="flex:1;accent-color:var(--accent);">
                <span id="sec-font-val" style="font-size:13px;font-weight:700;color:var(--accent);min-width:34px;"><?= h($v['app_sidebar_section_font']) ?>px</span>
              </div>
              <p class="form-help">Kategorietitel (Übersicht, Tickets…). Standard: 10px</p>
            </div>
          </div>
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Vorschau</label>
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
            <div id="font-preview" style="padding:14px 16px;font-size:<?= h($v['app_font_size']) ?>px;transition:font-size .2s;line-height:1.6;border-bottom:1px solid #e2e8f0;">
              <strong>Ticket #TK-2025-0042</strong><br>
              Kunde: Max Mustermann<br>
              <span style="color:#64748b;">Status: In Bearbeitung</span>
            </div>
            <div style="background:#1e293b;padding:10px 14px;">
              <div id="sec-font-preview" style="font-size:<?= h($v['app_sidebar_section_font']) ?>px;text-transform:uppercase;letter-spacing:1px;color:#475569;margin-bottom:4px;transition:font-size .2s;">Tickets</div>
              <div id="nav-font-preview" style="font-size:<?= h($v['app_sidebar_nav_font']) ?>px;color:#94a3b8;transition:font-size .2s;">🎫 Alle Tickets</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div style="display:flex;justify-content:flex-end;">
    <button type="submit" class="btn btn-primary">💾 Branding speichern</button>
  </div>
</form>

<?php elseif ($tab === 'company'): ?>
<!-- ── Firma ── -->
<form method="post">
  <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
  <input type="hidden" name="action"     value="company">
  <div class="card">
    <div class="card-header"><h3>🏪 Firmendaten</h3></div>
    <div class="card-body">
      <div class="form-row cols-3">
        <div class="form-group">
          <label class="form-label">Firmenname</label>
          <input type="text" name="company_name" class="form-control"
                 value="<?= h($v['company_name']) ?>" placeholder="Mein Reparatur Shop">
          <p class="form-help">Erscheint unter dem App-Namen in der Sidebar und in E-Mails.</p>
        </div>
        <div class="form-group">
          <label class="form-label">Firmen-E-Mail</label>
          <input type="email" name="company_email" class="form-control"
                 value="<?= h($v['company_email']) ?>" placeholder="info@firma.at">
        </div>
        <div class="form-group">
          <label class="form-label">Telefon</label>
          <input type="text" name="company_phone" class="form-control"
                 value="<?= h($v['company_phone']) ?>" placeholder="+43 1 234 5678">
        </div>
      </div>
    </div>
  </div>
  <div style="display:flex;justify-content:flex-end;">
    <button type="submit" class="btn btn-primary">💾 Firmendaten speichern</button>
  </div>
</form>

<?php elseif ($tab === 'mail'): ?>
<!-- ── E-Mail ── -->
<form method="post">
  <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
  <input type="hidden" name="action"     value="mail">

  <div class="card">
    <div class="card-header"><h3>📧 Versandmethode</h3></div>
    <div class="card-body">
      <div style="display:flex;gap:12px;flex-wrap:wrap;">
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:12px 18px;
                      border:2px solid <?= $v['mail_method']==='smtp' ? 'var(--accent)' : '#e2e8f0' ?>;
                      border-radius:8px;background:<?= $v['mail_method']==='smtp' ? '#eff6ff' : '#fff' ?>">
          <input type="radio" name="mail_method" value="smtp"
                 <?= $v['mail_method']==='smtp' ? 'checked' : '' ?>
                 onchange="document.getElementById('smtp-fields').style.display='block'">
          <div><strong>SMTP</strong><div style="font-size:12px;color:#64748b;">Gmail, eigener Mailserver — empfohlen</div></div>
        </label>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:12px 18px;
                      border:2px solid <?= $v['mail_method']==='php_mail' ? 'var(--accent)' : '#e2e8f0' ?>;
                      border-radius:8px;background:<?= $v['mail_method']==='php_mail' ? '#eff6ff' : '#fff' ?>">
          <input type="radio" name="mail_method" value="php_mail"
                 <?= $v['mail_method']==='php_mail' ? 'checked' : '' ?>
                 onchange="document.getElementById('smtp-fields').style.display='none'">
          <div><strong>PHP mail()</strong><div style="font-size:12px;color:#64748b;">Nur wenn Hoster es unterstuetzt</div></div>
        </label>
      </div>
    </div>
  </div>

  <div id="smtp-fields" class="card" style="<?= $v['mail_method']!=='smtp' ? 'display:none;' : '' ?>">
    <div class="card-header"><h3>SMTP Zugangsdaten</h3></div>
    <div class="card-body">
      <div class="form-group">
        <label class="form-label">Authentifizierung</label>
        <select name="smtp_auth_method" class="form-control" style="max-width:320px;"
                onchange="document.getElementById('smtp-pass-row').style.display=this.value==='oauth2_o365'?'none':'block';toggleOAuthBlock();">
          <option value="password"     <?= $v['smtp_auth_method']==='password'?'selected':'' ?>>Passwort (Standard)</option>
          <option value="oauth2_o365"  <?= $v['smtp_auth_method']==='oauth2_o365'?'selected':'' ?>>Office 365 (OAuth2 / Modern Auth)</option>
        </select>
        <p class="form-help">Für Office-365-Postfächer ist „OAuth2" nötig – Passwort/App-Passwort wird von Microsoft nicht mehr akzeptiert.</p>
      </div>
      <div class="form-row cols-2">
        <div class="form-group">
          <label class="form-label">Absender-Name</label>
          <input type="text" name="smtp_from_name" class="form-control" value="<?= h($v['smtp_from_name']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Absender-E-Mail / Benutzername</label>
          <input type="email" name="smtp_user" class="form-control" value="<?= h($v['smtp_user']) ?>" placeholder="ihre@email.de">
        </div>
      </div>
      <div class="form-group" id="smtp-pass-row" style="<?= $v['smtp_auth_method']==='oauth2_o365' ? 'display:none;' : '' ?>">
        <label class="form-label">
          Passwort
          <?php if ($v['smtp_pass_set']): ?>
            <span style="background:#ecfdf5;color:#065f46;font-size:11px;padding:2px 8px;border-radius:10px;margin-left:6px;">✅ Gespeichert in DB</span>
          <?php endif; ?>
        </label>
        <input type="password" name="smtp_pass" class="form-control" autocomplete="new-password"
               placeholder="<?= $v['smtp_pass_set'] ? 'Leer lassen = unveraendert' : 'Passwort eingeben' ?>">
        <p class="form-help">Wird sicher in der Datenbank gespeichert — nicht in config.php.</p>
      </div>
      <div class="form-row cols-3">
        <div class="form-group">
          <label class="form-label">SMTP Server</label>
          <input type="text" name="smtp_host" class="form-control" value="<?= h($v['smtp_host']) ?>" placeholder="smtp.gmail.com">
        </div>
        <div class="form-group">
          <label class="form-label">Port</label>
          <input type="number" name="smtp_port" class="form-control" value="<?= h($v['smtp_port']) ?>" placeholder="587">
        </div>
        <div class="form-group">
          <label class="form-label">Verschluesselung</label>
          <select name="smtp_secure" class="form-control">
            <option value="tls" <?= $v['smtp_secure']==='tls'?'selected':'' ?>>TLS (Port 587)</option>
            <option value="ssl" <?= $v['smtp_secure']==='ssl'?'selected':'' ?>>SSL (Port 465)</option>
            <option value=""    <?= $v['smtp_secure']===''   ?'selected':'' ?>>Keine</option>
          </select>
        </div>
      </div>
      <!-- Schnellvorlagen -->
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px;">
        <p style="font-size:12px;font-weight:600;margin-bottom:8px;">Schnellvorlagen:</p>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
          <button type="button" class="btn btn-secondary btn-sm" onclick="setSmtp('smtp.gmail.com','587','tls')">Gmail</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="setSmtp('smtp.office365.com','587','tls')">Office 365</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="setSmtp('smtp.ionos.de','587','tls')">IONOS</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="setSmtp('smtp.hosteurope.de','587','tls')">Host Europe</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="setSmtp('mail.your-server.de','587','tls')">Hetzner</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="setSmtp('127.0.0.1','1025','')">MailHog (lokal)</button>
        </div>
    </div>
  </div>

  <!-- ── Gemeinsame Office-365-OAuth2-Zugangsdaten (Empfang + Versand) ── -->
  <div id="oauth-block" class="card" style="<?= ($v['smtp_auth_method']==='oauth2_o365' || $v['imap_auth_method']==='oauth2_o365') ? '' : 'display:none;' ?>">
    <div class="card-header"><h3>🔐 Office 365 – App-Zugangsdaten (OAuth2)</h3></div>
    <div class="card-body">
      <p style="color:#64748b;font-size:13px;margin-bottom:14px;">
        Diese Werte stammen aus der einmaligen App-Registrierung in Microsoft Entra / Azure
        (siehe beiliegende Anleitung). Sie gelten <strong>gemeinsam</strong> für Versand und Empfang –
        eine App genügt für beides.
      </p>
      <div class="form-row cols-2">
        <div class="form-group">
          <label class="form-label">Verzeichnis-ID (Tenant-ID)</label>
          <input type="text" name="oauth_tenant" class="form-control" value="<?= h($v['oauth_tenant']) ?>" placeholder="z. B. 00000000-1111-2222-3333-444444444444">
        </div>
        <div class="form-group">
          <label class="form-label">Anwendungs-ID (Client-ID)</label>
          <input type="text" name="oauth_client_id" class="form-control" value="<?= h($v['oauth_client_id']) ?>" placeholder="z. B. aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">
          Client-Secret (Geheimnis)
          <?php if ($v['oauth_secret_set']): ?>
            <span style="background:#ecfdf5;color:#065f46;font-size:11px;padding:2px 8px;border-radius:10px;margin-left:6px;">✅ Gespeichert in DB</span>
          <?php endif; ?>
        </label>
        <input type="password" name="oauth_client_secret" class="form-control" autocomplete="new-password"
               placeholder="<?= $v['oauth_secret_set'] ? 'Leer lassen = unveraendert' : 'Secret-Wert einfügen' ?>">
        <p class="form-help">Der <strong>Wert</strong> des Secrets (nicht die Secret-ID). Wird sicher in der Datenbank gespeichert.</p>
      </div>
      <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:12px 14px;font-size:12px;color:#1e40af;margin-bottom:14px;">
        ℹ️ Die App braucht die <strong>delegierten</strong> Berechtigungen
        <code>IMAP.AccessAsUser.All</code>, <code>SMTP.Send</code> und <code>offline_access</code>
        (mit Admin-Zustimmung im Portal – <strong>ohne PowerShell</strong>).
        SMTP-Server: <code>smtp.office365.com</code> (Port 587, TLS) · IMAP-Server: <code>outlook.office365.com</code> (Port 993, SSL).
      </div>

      <div class="form-group">
        <label class="form-label">Redirect-URI (in der Azure-App eintragen)</label>
        <code style="display:block;background:#0f172a;color:#fff;border-radius:4px;padding:9px 11px;font-size:12px;word-break:break-all;">
          <?= h(BASE_URL) ?>/oauth_o365.php
        </code>
        <p class="form-help">Diese Adresse in der App unter <em>Authentifizierung → Plattform „Web" → Umleitungs-URIs</em> hinterlegen.</p>
      </div>

      <div style="border-top:1px solid #e2e8f0;margin:14px 0;padding-top:14px;">
        <?php if ($v['oauth_connected']): ?>
          <div style="background:#ecfdf5;border:1px solid #10b981;border-radius:6px;padding:12px 14px;">
            <p style="font-size:13px;color:#065f46;margin:0 0 8px;">
              ✅ <strong>Mit Office 365 verbunden</strong><?= $v['oauth_connected_email'] !== '' ? ' (' . h($v['oauth_connected_email']) . ')' : '' ?>.
            </p>
            <a href="<?= h(BASE_URL) ?>/oauth_o365.php" class="btn btn-secondary btn-sm">🔄 Neu verbinden</a>
          </div>
        <?php else: ?>
          <div style="background:#fffbeb;border:1px solid #fbbf24;border-radius:6px;padding:12px 14px;">
            <p style="font-size:13px;color:#7c2d12;margin:0 0 10px;">
              ⚠️ <strong>Noch nicht verbunden.</strong> Zuerst oben speichern, dann einmalig anmelden:
            </p>
            <a href="<?= h(BASE_URL) ?>/oauth_o365.php" class="btn btn-primary">🔐 Mit Office 365 verbinden</a>
            <p class="form-help" style="margin-top:8px;">Es öffnet sich die Microsoft-Anmeldung. Melden Sie sich mit dem <strong>Ticket-Postfach</strong> an und bestätigen Sie den Zugriff.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Test-Mail -->
  <div class="card">
    <div class="card-header"><h3>🧪 Test-E-Mail</h3></div>
    <div class="card-body">
      <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <div class="form-group" style="margin:0;flex:1;max-width:300px;">
          <label class="form-label">Empfaenger</label>
          <input type="email" id="test-email" class="form-control"
                 value="<?= h($v['smtp_user']) ?>" placeholder="test@beispiel.de">
        </div>
        <button type="button" class="btn btn-secondary" onclick="sendTestMail()">📨 Test senden</button>
        <span id="test-result" style="font-size:14px;padding:9px 0;"></span>
      </div>
      <p class="form-help" style="margin-top:8px;">Erst Einstellungen speichern, dann Test senden.</p>
    </div>
  </div>

  <!-- ── E-Mail-Empfang (Antworten per E-Mail) ── -->
  <div class="card">
    <div class="card-header"><h3>📬 E-Mail-Empfang – Kunden antworten direkt per E-Mail</h3></div>
    <div class="card-body">
      <p style="color:#64748b;font-size:13px;margin-bottom:14px;">
        Wenn aktiviert, können Kunden <strong>direkt auf Ticket-E-Mails antworten</strong> –
        ohne den Link öffnen zu müssen. Antworten werden automatisch als Nachricht im Ticket gespeichert.
        Antwortet der Kunde mit <strong>ZUSTIMMEN</strong> oder <strong>ABLEHNEN</strong>, wird der
        Kostenvoranschlag automatisch bestätigt bzw. abgelehnt.<br>
        Der Abruf läuft <strong>automatisch im Hintergrund</strong>, sobald jemand im System arbeitet –
        ein Cronjob ist <strong>nicht zwingend nötig</strong>.
      </p>

      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:16px;">
        <input type="checkbox" name="imap_enabled" <?= $v['imap_enabled'] === '1' ? 'checked' : '' ?>
               style="width:18px;height:18px;accent-color:var(--accent);cursor:pointer;"
               onchange="document.getElementById('imap-fields').style.display=this.checked?'block':'none'">
        <span style="font-weight:600;">E-Mail-Empfang aktivieren</span>
      </label>

      <div id="imap-fields" style="<?= $v['imap_enabled'] !== '1' ? 'display:none;' : '' ?>">
        <div class="form-group">
          <label class="form-label">Anmeldung</label>
          <select name="imap_auth_method" class="form-control" style="max-width:320px;"
                  onchange="document.getElementById('imap-pass-row').style.display=this.value==='oauth2_o365'?'none':'block';toggleOAuthBlock();">
            <option value="password"    <?= $v['imap_auth_method']==='password'?'selected':'' ?>>Passwort (Standard)</option>
            <option value="oauth2_o365" <?= $v['imap_auth_method']==='oauth2_o365'?'selected':'' ?>>Office 365 (OAuth2 / Modern Auth)</option>
          </select>
          <p class="form-help">Office-365-Postfächer erfordern „OAuth2" – Passwort/App-Passwort funktioniert bei IMAP nicht mehr.</p>
        </div>
        <div class="form-row cols-2">
          <div class="form-group">
            <label class="form-label">Postfach-E-Mail / Benutzername</label>
            <input type="email" name="imap_user" class="form-control" value="<?= h($v['imap_user']) ?>" placeholder="tickets@ihre-firma.de">
            <p class="form-help">Antworten der Kunden gehen an diese Adresse (wird automatisch als Reply-To gesetzt).</p>
          </div>
          <div class="form-group" id="imap-pass-row" style="<?= $v['imap_auth_method']==='oauth2_o365' ? 'display:none;' : '' ?>">
            <label class="form-label">
              Passwort
              <?php if ($v['imap_pass_set']): ?>
                <span style="background:#ecfdf5;color:#065f46;font-size:11px;padding:2px 8px;border-radius:10px;margin-left:6px;">✅ Gespeichert in DB</span>
              <?php endif; ?>
            </label>
            <input type="password" name="imap_pass" class="form-control" autocomplete="new-password"
                   placeholder="<?= $v['imap_pass_set'] ? 'Leer lassen = unveraendert' : 'Passwort eingeben' ?>">
          </div>
        </div>
        <div class="form-row cols-3">
          <div class="form-group">
            <label class="form-label">IMAP Server</label>
            <input type="text" name="imap_host" class="form-control" value="<?= h($v['imap_host']) ?>" placeholder="imap.gmail.com">
          </div>
          <div class="form-group">
            <label class="form-label">Port</label>
            <input type="number" name="imap_port" class="form-control" value="<?= h($v['imap_port']) ?>" placeholder="993">
          </div>
          <div class="form-group">
            <label class="form-label">Verschluesselung</label>
            <select name="imap_secure" class="form-control">
              <option value="ssl" <?= $v['imap_secure']==='ssl'?'selected':'' ?>>SSL (Port 993)</option>
              <option value="tls" <?= $v['imap_secure']==='tls'?'selected':'' ?>>STARTTLS (Port 143)</option>
              <option value=""    <?= $v['imap_secure']===''   ?'selected':'' ?>>Keine</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Ordner</label>
          <input type="text" name="imap_folder" class="form-control" value="<?= h($v['imap_folder']) ?>" placeholder="INBOX" style="max-width:200px;">
          <p class="form-help">
            Für den Posteingang immer <strong>INBOX</strong> eintragen – auch bei deutschsprachigen Postfächern.
            „Posteingang“ ist nur der Anzeigename in Outlook und für IMAP kein gültiger Ordnername.
          </p>
        </div>

        <!-- Schnellvorlagen -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px;margin-bottom:14px;">
          <p style="font-size:12px;font-weight:600;margin-bottom:8px;">Schnellvorlagen:</p>
          <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <button type="button" class="btn btn-secondary btn-sm" onclick="setImap('imap.gmail.com','993','ssl')">Gmail</button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="setImap('outlook.office365.com','993','ssl')">Office 365</button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="setImap('imap.ionos.de','993','ssl')">IONOS</button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="setImap('imap.hosteurope.de','993','ssl')">Host Europe</button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="setImap('mail.your-server.de','993','ssl')">Hetzner</button>
          </div>
        </div>

        <!-- Wann soll abgerufen werden? -->
        <div style="background:#ecfdf5;border:1px solid #10b981;border-radius:6px;padding:14px;margin-bottom:14px;">
          <p style="font-size:13px;font-weight:600;margin-bottom:6px;color:#065f46;">Wann sollen E-Mails abgerufen werden?</p>
          <p style="font-size:12px;color:#047857;margin-bottom:12px;">
            <strong>Der Gelesen-Status spielt keine Rolle:</strong> Antworten landen auch dann im Ticket,
            wenn Sie die E-Mail vorher schon in Outlook geöffnet haben. Das System markiert Ihre
            E-Mails auch nicht selbst als gelesen.
          </p>

          <label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:10px;cursor:pointer;">
            <input type="checkbox" name="imap_fetch_on_login" style="margin-top:3px;"
                   <?= $v['imap_fetch_on_login'] === '1' ? 'checked' : '' ?>>
            <span style="font-size:12px;color:#065f46;">
              <strong>Beim Anmelden sofort abrufen</strong><br>
              <span style="color:#047857;">Empfohlen, wenn Sie nur gelegentlich und kurz im System sind.
              Der Abruf startet direkt nach dem Login im Hintergrund.</span>
            </span>
          </label>

          <label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:10px;cursor:pointer;">
            <input type="checkbox" name="imap_autofetch_enabled" id="imap-autofetch-cb" style="margin-top:3px;"
                   onchange="document.getElementById('imap-interval-box').style.display = this.checked ? 'block' : 'none';"
                   <?= $v['imap_autofetch_enabled'] === '1' ? 'checked' : '' ?>>
            <span style="font-size:12px;color:#065f46;">
              <strong>Während der Arbeit im Hintergrund abrufen</strong><br>
              <span style="color:#047857;">Ruft beim Blättern durch das System regelmäßig ab,
              solange jemand angemeldet ist.</span>
            </span>
          </label>

          <div id="imap-interval-box" class="form-group" style="margin:0 0 0 26px;<?= $v['imap_autofetch_enabled'] === '1' ? '' : 'display:none;' ?>">
            <label class="form-label" style="font-size:12px;">Dabei frühestens abrufen alle</label>
            <select name="imap_fetch_interval" class="form-control" style="max-width:200px;">
              <option value="120" <?= $v['imap_fetch_interval']==='120'?'selected':'' ?>>2 Minuten</option>
              <option value="300" <?= $v['imap_fetch_interval']==='300'?'selected':'' ?>>5 Minuten (empfohlen)</option>
              <option value="600" <?= $v['imap_fetch_interval']==='600'?'selected':'' ?>>10 Minuten</option>
            </select>
          </div>
        </div>

        <?php if ($v['imap_cron_key'] !== ''): ?>
        <!-- Cronjob-Info (optional) -->
        <div style="background:#fffbeb;border:1px solid #fbbf24;border-radius:6px;padding:14px;">
          <p style="font-size:13px;font-weight:600;margin-bottom:6px;">⏰ Optional: Cronjob für Abruf rund um die Uhr</p>
          <p style="font-size:12px;color:#64748b;margin-bottom:8px;">
            <strong>Nur nötig, wenn Antworten auch dann abgeholt werden sollen, wenn niemand eingeloggt ist</strong>
            (z. B. nachts). Dafür beim Hoster einen Cronjob auf diese URL einrichten:
          </p>
          <code style="display:block;background:#fff;border:1px solid #e2e8f0;border-radius:4px;padding:8px;font-size:12px;word-break:break-all;">
            <?= h(BASE_URL) ?>/cron/fetch_mail.php?key=<?= h($v['imap_cron_key']) ?>
          </code>
          <p style="font-size:12px;color:#64748b;margin-top:8px;">
            Alternativ per Kommandozeile: <code>php <?= h(dirname(__DIR__)) ?>/cron/fetch_mail.php</code>
          </p>
        </div>
        <?php else: ?>
        <p class="form-help">💡 Nach dem ersten Speichern erscheint hier Ihre persönliche Cronjob-URL.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div style="display:flex;justify-content:flex-end;">
    <button type="submit" class="btn btn-primary">💾 E-Mail Einstellungen speichern</button>
  </div>
</form>

<?php if ($v['imap_enabled'] === '1'): ?>
<!-- Sofort-Abruf (eigenes Formular, außerhalb des Speicher-Formulars) -->
<form method="post" style="margin-top:12px;display:flex;justify-content:flex-end;align-items:center;gap:10px;">
  <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
  <input type="hidden" name="action" value="imap_fetch_now">
  <span style="font-size:13px;color:#64748b;">Verbindung testen / neue Antworten sofort holen:</span>
  <button type="submit" class="btn btn-secondary">📬 Jetzt E-Mails abrufen</button>
</form>

<!-- Abrufstand (Diagnose) -->
<div style="margin-top:10px;display:flex;justify-content:flex-end;align-items:center;gap:10px;flex-wrap:wrap;">
  <span style="font-size:12px;color:#94a3b8;">
    <?php if ((int)$v['imap_last_uid'] > 0): ?>
      Abrufstand: Nachricht Nr. <?= h($v['imap_last_uid']) ?> – alles Neuere wird geholt.
    <?php else: ?>
      Abrufstand: noch nicht gesetzt – wird beim nächsten Abruf eingerichtet.
    <?php endif; ?>
  </span>
  <form method="post" style="margin:0;"
        onsubmit="return confirm('Abrufzähler wirklich zurücksetzen?\n\nBeim nächsten Abruf werden die noch ungelesenen E-Mails im Postfach abgearbeitet. Bereits verarbeitete Antworten kommen nicht doppelt ins Ticket.');">
    <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
    <input type="hidden" name="action" value="imap_reset_uid">
    <button type="submit" class="btn btn-secondary btn-sm" style="font-size:11px;">↻ Abrufzähler zurücksetzen</button>
  </form>
</div>
<?php endif; ?>

<script>
function setImap(host, port, secure) {
  document.querySelector('[name=imap_host]').value = host;
  document.querySelector('[name=imap_port]').value = port;
  document.querySelector('[name=imap_secure]').value = secure;
}
// Gemeinsamen OAuth-Block zeigen, sobald Empfang ODER Versand auf OAuth2 steht
function toggleOAuthBlock() {
  var smtpSel = document.querySelector('[name=smtp_auth_method]');
  var imapSel = document.querySelector('[name=imap_auth_method]');
  var wantsOAuth = (smtpSel && smtpSel.value === 'oauth2_o365') || (imapSel && imapSel.value === 'oauth2_o365');
  var block = document.getElementById('oauth-block');
  if (block) block.style.display = wantsOAuth ? 'block' : 'none';
}
</script>
<?php elseif ($tab === 'ticket'): ?>
<!-- ── Ticket-Einstellungen ── -->
<form method="post">
  <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
  <input type="hidden" name="action"     value="ticket">

  <!-- Sichtbarkeit -->
  <div class="card">
    <div class="card-header"><h3>👁️ Sichtbarkeit</h3></div>
    <div class="card-body">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
        <input type="checkbox" name="show_device_info"
               <?= $v['show_device_info'] === '1' ? 'checked' : '' ?>
               style="width:18px;height:18px;accent-color:var(--accent);cursor:pointer;">
        <span style="font-weight:600;">📱 Bereich „Geräteinformationen" anzeigen</span>
      </label>
      <p style="color:#64748b;font-size:13px;margin-top:8px;margin-left:28px;">
        Wenn deaktiviert, wird der gesamte Block (inkl. aller Felder) im Formular, in der Ticket-Ansicht
        <strong>und im E-Mail-Versand</strong> ausgeblendet.
      </p>
    </div>
  </div>

  <!-- Feldnamen umbenennen -->
  <div class="card" style="margin-top:16px;">
    <div class="card-header"><h3>✏️ Feldnamen umbenennen</h3></div>
    <div class="card-body">
      <p style="color:#64748b;font-size:13px;margin-bottom:16px;">
        Hier kannst du alle Bezeichnungen des Geräteinformationen-Bereichs anpassen.
        Leer lassen = Standardwert wird verwendet.
      </p>
      <div class="form-group">
        <label class="form-label">Abschnitts-Titel <span style="color:#94a3b8;font-weight:400;">(bisher: „Geräteinformationen")</span></label>
        <input type="text" name="label_device_section" class="form-control"
               placeholder="Geräteinformationen"
               value="<?= h($v['label_device_section']) ?>">
      </div>
      <div class="form-row cols-2" style="margin-top:12px;">
        <div class="form-group">
          <label class="form-label">Gerät / Modell</label>
          <input type="text" name="label_device_model" class="form-control"
                 placeholder="Gerät / Modell"
                 value="<?= h($v['label_device_model']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Seriennummer / IMEI</label>
          <input type="text" name="label_serial_number" class="form-control"
                 placeholder="Seriennummer / IMEI"
                 value="<?= h($v['label_serial_number']) ?>">
        </div>
      </div>
      <div class="form-row cols-2">
        <div class="form-group">
          <label class="form-label">Zustand bei Annahme</label>
          <input type="text" name="label_condition_on_arrival" class="form-control"
                 placeholder="Zustand bei Annahme"
                 value="<?= h($v['label_condition_on_arrival']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Zubehör mitgebracht</label>
          <input type="text" name="label_accessories" class="form-control"
                 placeholder="Zubehör mitgebracht"
                 value="<?= h($v['label_accessories']) ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Schadensbeschreibung</label>
        <input type="text" name="label_damage_description" class="form-control"
               placeholder="Schadensbeschreibung"
               value="<?= h($v['label_damage_description']) ?>">
      </div>
    </div>
  </div>

  <div style="margin-top:16px;">
    <button type="submit" class="btn btn-primary">💾 Speichern</button>
  </div>
</form>

<?php elseif ($tab === 'security'): ?>
<div class="card">
  <div class="card-header"><h3>🔐 Geräte &amp; Sitzungen</h3></div>
  <div class="card-body">
    <p style="color:#475569;margin-bottom:6px;">Aktuell vertraute Geräte (2FA wird übersprungen): <strong><?= countTrustedDevices() ?></strong></p>
    <?php $fla = (int) getSetting('force_logout_after','0'); if ($fla > 0): ?>
    <p style="color:#94a3b8;font-size:13px;margin-bottom:10px;">Letzte globale Abmeldung: <?= h(date('d.m.Y H:i', $fla)) ?></p>
    <?php endif; ?>
    <p style="color:#475569;margin:14px 0;">Mit „Überall abmelden" werden <strong>alle anderen</strong> Sitzungen sofort beendet und alle vertrauten Geräte entfernt – praktisch, wenn ein Gerät verloren geht. <strong>Deine aktuelle Sitzung bleibt aktiv.</strong></p>
    <form method="post" onsubmit="return confirm('Wirklich alle anderen Geräte und Sitzungen abmelden? Betroffene müssen sich neu anmelden (inkl. 2FA).');">
      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="logout_all">
      <button type="submit" class="btn btn-danger">🚪 Überall abmelden</button>
    </form>
  </div>
</div>

<?php elseif ($tab === 'backup'): ?>
<div class="card">
  <div class="card-header"><h3>🗄️ Manuelles Backup</h3></div>
  <div class="card-body">
    <p style="color:#475569;margin-bottom:16px;">Erstellt eine vollständige Sicherung der Datenbank (reines PHP/PDO) im geschützten Ordner <code>/backups</code>. Der Ordner ist vom Web-Zugriff ausgeschlossen.</p>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="backup_create">
      <button type="submit" class="btn btn-primary">💾 Backup jetzt erstellen</button>
    </form>
  </div>
</div>
<div class="card">
  <div class="card-header"><h3>📦 Vorhandene Backups</h3></div>
  <div class="card-body">
    <?php $backups = listBackups(); if (empty($backups)): ?>
    <p style="color:#94a3b8;">Noch keine Backups vorhanden.</p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Datei</th><th>Größe</th><th>Erstellt</th><th style="text-align:right;">Aktion</th></tr></thead>
        <tbody>
        <?php foreach ($backups as $b): ?>
          <tr>
            <td><?= h($b['name']) ?></td>
            <td><?= h(formatBytes($b['size'])) ?></td>
            <td><?= h(date('d.m.Y H:i', $b['mtime'])) ?></td>
            <td style="text-align:right;white-space:nowrap;">
              <a class="btn btn-sm btn-secondary" href="<?= BASE_URL ?>/backup_download.php?file=<?= urlencode($b['name']) ?>">⬇️ Download</a>
              <form method="post" style="display:inline;" onsubmit="return confirm('Backup löschen?');">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="backup_delete">
                <input type="hidden" name="file" value="<?= h($b['name']) ?>">
                <button type="submit" class="btn btn-sm btn-danger">🗑️</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<script>
function setColor(inputName, hexId, color) {
    document.querySelector('[name=' + inputName + ']').value = color;
    document.getElementById(hexId).value = color;
}
function setSmtp(host, port, secure) {
    document.querySelector('[name=smtp_host]').value   = host;
    document.querySelector('[name=smtp_port]').value   = port;
    document.querySelector('[name=smtp_secure]').value = secure;
}
function sendTestMail() {
    const email = document.getElementById('test-email').value.trim();
    const res   = document.getElementById('test-result');
    if (!email) { res.textContent='Bitte E-Mail eingeben.'; res.style.color='#ef4444'; return; }
    res.textContent='Wird gesendet…'; res.style.color='#64748b';
    fetch('<?= BASE_URL ?>/settings_test_mail.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'email='+encodeURIComponent(email)+'&csrf_token=<?= csrf() ?>'
    }).then(r=>r.json()).then(d=>{
        res.textContent = d.ok ? '✅ Gesendet!' : '❌ ' + d.error;
        res.style.color = d.ok ? '#059669' : '#ef4444';
    }).catch(()=>{ res.textContent='❌ Fehler'; res.style.color='#ef4444'; });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>