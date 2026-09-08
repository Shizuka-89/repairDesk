<?php
/**
 * Ticketsystem Installer
 */

// Installer sperren wenn bereits installiert
if (file_exists(__DIR__ . '/config.php')) {
    $cfg = file_get_contents(__DIR__ . '/config.php');
    // Prüfen ob config.php bereits echte DB-Daten hat (Marker: INSTALLER_PENDING fehlt)
    if (strpos($cfg, 'INSTALLER_PENDING') === false) {
        die('<div style="font-family:sans-serif;padding:40px;color:#ef4444;">Das System ist bereits installiert. Lösche <code>install.php</code> vom Server.</div>');
    }
}

// ================================================================
// SQL: Alle Tabellen die das System benötigt
// ================================================================
function getSetupSQL(): array {
    return [
        "customers" => "CREATE TABLE IF NOT EXISTS `customers` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `name`       VARCHAR(200) NOT NULL DEFAULT '',
            `firstname`  VARCHAR(100) NOT NULL DEFAULT '',
            `lastname`   VARCHAR(100) NOT NULL DEFAULT '',
            `company`    VARCHAR(150) DEFAULT NULL,
            `street`     VARCHAR(150) DEFAULT NULL,
            `zip`        VARCHAR(20)  DEFAULT NULL,
            `city`       VARCHAR(100) DEFAULT NULL,
            `email`      VARCHAR(255) DEFAULT NULL,
            `phone`      VARCHAR(60)  DEFAULT NULL,
            `address`    TEXT         DEFAULT NULL,
            `notes`      TEXT         DEFAULT NULL,
            `token`      VARCHAR(80)  DEFAULT NULL,
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "technicians" => "CREATE TABLE IF NOT EXISTS `technicians` (
            `id`           INT AUTO_INCREMENT PRIMARY KEY,
            `name`         VARCHAR(100) NOT NULL,
            `email`        VARCHAR(255) NOT NULL UNIQUE,
            `password`     VARCHAR(255) NOT NULL,
            `is_admin`     TINYINT(1)   NOT NULL DEFAULT 0,
            `totp_secret`  VARCHAR(64)  DEFAULT NULL,
            `totp_enabled` TINYINT(1)   NOT NULL DEFAULT 0,
            `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "tickets" => "CREATE TABLE IF NOT EXISTS `tickets` (
            `id`                   INT AUTO_INCREMENT PRIMARY KEY,
            `ticket_number`        VARCHAR(30)  NOT NULL UNIQUE,
            `customer_id`          INT          DEFAULT NULL,
            `technician_id`        INT          DEFAULT NULL,
            `title`                VARCHAR(255) NOT NULL DEFAULT '',
            `description`          TEXT         DEFAULT NULL,
            `device_model`         VARCHAR(255) DEFAULT NULL,
            `serial_number`        VARCHAR(100) DEFAULT NULL,
            `condition_on_arrival` VARCHAR(255) DEFAULT NULL,
            `damage_description`   TEXT         DEFAULT NULL,
            `status`               ENUM('offen','in_bearbeitung','warten_auf_kunde','abgeschlossen','storniert') NOT NULL DEFAULT 'offen',
            `priority`             ENUM('niedrig','normal','hoch','dringend') NOT NULL DEFAULT 'normal',
            `final_price`          DECIMAL(10,2) DEFAULT NULL,
            `internal_notes`       TEXT         DEFAULT NULL,
            `customer_token`       VARCHAR(80)  DEFAULT NULL,
            `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`customer_id`)   REFERENCES `customers`(`id`)   ON DELETE SET NULL,
            FOREIGN KEY (`technician_id`) REFERENCES `technicians`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "ticket_comments" => "CREATE TABLE IF NOT EXISTS `ticket_comments` (
            `id`             INT AUTO_INCREMENT PRIMARY KEY,
            `ticket_id`      INT          NOT NULL,
            `author_type`    ENUM('technician','customer') NOT NULL DEFAULT 'technician',
            `author_name`    VARCHAR(100) DEFAULT NULL,
            `message`        TEXT         NOT NULL,
            `is_internal`    TINYINT(1)   NOT NULL DEFAULT 0,
            `price_proposal` DECIMAL(10,2) DEFAULT NULL,
            `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "ticket_field_definitions" => "CREATE TABLE IF NOT EXISTS `ticket_field_definitions` (
            `id`           INT AUTO_INCREMENT PRIMARY KEY,
            `field_key`    VARCHAR(60)  NOT NULL UNIQUE,
            `label`        VARCHAR(100) NOT NULL,
            `section`      VARCHAR(60)  NOT NULL DEFAULT 'custom',
            `field_type`   ENUM('text','textarea','number','date','checkbox','select') NOT NULL DEFAULT 'text',
            `options`      TEXT         DEFAULT NULL,
            `placeholder`  VARCHAR(255) DEFAULT NULL,
            `required`     TINYINT(1)   NOT NULL DEFAULT 0,
            `show_in_list` TINYINT(1)   NOT NULL DEFAULT 0,
            `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
            `sort_order`   INT          NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "ticket_field_values" => "CREATE TABLE IF NOT EXISTS `ticket_field_values` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `ticket_id`  INT          NOT NULL,
            `field_key`  VARCHAR(60)  NOT NULL,
            `value`      TEXT         DEFAULT NULL,
            UNIQUE KEY `ticket_field` (`ticket_id`, `field_key`),
            FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "settings" => "CREATE TABLE IF NOT EXISTS `settings` (
            `key`        VARCHAR(80)  NOT NULL PRIMARY KEY,
            `value`      TEXT         DEFAULT NULL,
            `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "login_attempts" => "CREATE TABLE IF NOT EXISTS `login_attempts` (
            `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ip`           VARCHAR(45)  NOT NULL,
            `type`         VARCHAR(20)  NOT NULL DEFAULT 'password',
            `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_ip_type_time` (`ip`, `type`, `attempted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "trusted_devices" => "CREATE TABLE IF NOT EXISTS `trusted_devices` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `technician_id` INT          NOT NULL,
            `token_hash`    CHAR(64)     NOT NULL,
            `user_agent`    VARCHAR(255) DEFAULT NULL,
            `ip`            VARCHAR(45)  DEFAULT NULL,
            `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_used_at`  DATETIME     DEFAULT NULL,
            `expires_at`    DATETIME     NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_token` (`token_hash`),
            INDEX `idx_tech` (`technician_id`),
            FOREIGN KEY (`technician_id`) REFERENCES `technicians`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "mail_inbox_log" => "CREATE TABLE IF NOT EXISTS `mail_inbox_log` (
            `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `message_id`   VARCHAR(255) NOT NULL,
            `from_email`   VARCHAR(255) DEFAULT NULL,
            `subject`      VARCHAR(255) DEFAULT NULL,
            `ticket_id`    INT DEFAULT NULL,
            `result`       VARCHAR(50)  DEFAULT NULL,
            `processed_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_msgid` (`message_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

$error   = '';
$success = false;
$dbOk    = false;
$tablesCreated = [];
$pdo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host       = trim($_POST['db_host']       ?? 'localhost');
    $db_name       = trim($_POST['db_name']       ?? '');
    $db_user       = trim($_POST['db_user']       ?? '');
    $db_pass       = trim($_POST['db_pass']       ?? '');
    $admin_name    = trim($_POST['admin_name']    ?? '');
    $admin_email   = trim($_POST['admin_email']   ?? '');
    $admin_pass    = trim($_POST['admin_pass']    ?? '');
    $company       = trim($_POST['company']       ?? '');
    $company_email = trim($_POST['company_email'] ?? '');
    $company_phone = trim($_POST['company_phone'] ?? '');
    $base_url      = rtrim(trim($_POST['base_url'] ?? ''), '/');
    $timezone      = trim($_POST['timezone']      ?? 'Europe/Vienna');
    $ticket_prefix = trim($_POST['ticket_prefix'] ?? 'TK');

    if (!$db_name || !$db_user || !$base_url || !$company || !$admin_email || !$admin_pass || !$admin_name) {
        $error = 'Bitte alle Pflichtfelder ausfüllen.';
    } else {
        try {
            $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
            $pdo = new PDO($dsn, $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $dbOk = true;
        } catch (PDOException $e) {
            $error = 'Datenbankverbindung fehlgeschlagen: ' . htmlspecialchars($e->getMessage());
        }
    }

    if ($dbOk) {
        foreach (getSetupSQL() as $tableName => $sql) {
            try {
                $pdo->exec($sql);
                $tablesCreated[] = $tableName;
            } catch (PDOException $e) {
                $error = "Fehler beim Erstellen der Tabelle '{$tableName}': " . htmlspecialchars($e->getMessage());
                break;
            }
        }
    }

    if ($dbOk && !$error) {
        // Admin-Account anlegen (nur wenn E-Mail noch nicht vorhanden)
        $check = $pdo->prepare("SELECT COUNT(*) FROM technicians WHERE email = ?");
        $check->execute([$admin_email]);
        if ($check->fetchColumn() == 0) {
            $hash = password_hash($admin_pass, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO technicians (name, email, password, is_admin) VALUES (?, ?, ?, 1)")
                ->execute([$admin_name, $admin_email, $hash]);
        }

        // Basis-Settings in DB schreiben
        foreach (['company_name' => $company, 'company_email' => $company_email, 'company_phone' => $company_phone] as $k => $v) {
            $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
                ->execute([$k, $v]);
        }
        // System-Version setzen (neueste Version = frische Installation braucht keine Migrationen)
        $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('system_version', '1.4.0') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
            ->execute();
    }

    if ($dbOk && !$error) {
        $config = '<?php
// ================================================================
// Automatisch generiert vom Installer – ' . date('d.m.Y H:i') . '
// ================================================================

// --- Datenbank ---
define(\'DB_HOST\', ' . var_export($db_host, true) . ');
define(\'DB_NAME\', ' . var_export($db_name, true) . ');
define(\'DB_USER\', ' . var_export($db_user, true) . ');
define(\'DB_PASS\', ' . var_export($db_pass, true) . ');
define(\'DB_CHARSET\', \'utf8mb4\');

// --- Firma / Shop ---
define(\'COMPANY_NAME\', ' . var_export($company, true) . ');
define(\'COMPANY_EMAIL\', ' . var_export($company_email, true) . ');
define(\'COMPANY_PHONE\', ' . var_export($company_phone, true) . ');
define(\'BASE_URL\', ' . var_export($base_url, true) . ');

// --- E-Mail Einstellungen (Fallback – echte Werte unter Einstellungen konfigurieren) ---
define(\'MAIL_METHOD\', \'smtp\');
define(\'SMTP_HOST\',       \'\');
define(\'SMTP_PORT\',       587);
define(\'SMTP_SECURE\',     \'tls\');
define(\'SMTP_AUTH\',       false);
define(\'SMTP_USER\',       \'\');
define(\'SMTP_PASS\',       \'\');
define(\'SMTP_FROM_NAME\',  \'\');
define(\'SMTP_FROM_EMAIL\', \'\');

// --- Ticket-Nummern Format ---
define(\'TICKET_PREFIX\', ' . var_export($ticket_prefix, true) . ');

// --- Session-Sicherheit ---
define(\'SESSION_NAME\', \'repairdesk_session\');

// --- Zeitzone ---
date_default_timezone_set(' . var_export($timezone, true) . ');
';
        if (file_put_contents(__DIR__ . '/config.php', $config) === false) {
            $error = 'config.php konnte nicht geschrieben werden. Bitte Schreibrechte prüfen (chmod 644 config.php).';
        } else {
            // Backup-Ordner anlegen + per .htaccess sperren
            $bdir = __DIR__ . '/backups';
            if (!is_dir($bdir)) @mkdir($bdir, 0755, true);
            @file_put_contents($bdir . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
            $success = true;
            @unlink(__FILE__);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ticketsystem – Installation</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem; }
  .card { background: #1e293b; border-radius: 12px; padding: 2.5rem; width: 100%; max-width: 580px; box-shadow: 0 25px 50px rgba(0,0,0,.5); }
  h1 { font-size: 1.5rem; font-weight: 700; margin-bottom: .25rem; }
  .subtitle { color: #94a3b8; font-size: .875rem; margin-bottom: 2rem; line-height: 1.5; }
  .section-title { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: #64748b; margin: 1.75rem 0 .85rem; border-top: 1px solid #334155; padding-top: 1.5rem; }
  .section-title:first-of-type { margin-top: 0; border-top: none; padding-top: 0; }
  label { display: block; font-size: .875rem; color: #cbd5e1; margin-bottom: .35rem; }
  .req { color: #ef4444; }
  input, select { width: 100%; padding: .6rem .85rem; background: #0f172a; border: 1px solid #334155; border-radius: 8px; color: #f1f5f9; font-size: .9rem; outline: none; transition: border-color .2s; }
  input:focus, select:focus { border-color: #2563eb; }
  .row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  .form-group { margin-bottom: 1rem; }
  .hint { font-size: .72rem; color: #64748b; margin-top: .3rem; }
  .btn { width: 100%; padding: .8rem; background: #2563eb; color: #fff; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; margin-top: 1.5rem; transition: background .2s; }
  .btn:hover { background: #1d4ed8; }
  .error { background: #450a0a; border: 1px solid #7f1d1d; color: #fca5a5; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: .875rem; }
  .success { text-align: center; }
  .success h2 { font-size: 1.75rem; color: #4ade80; margin-bottom: .5rem; }
  .success p { color: #94a3b8; margin-bottom: .75rem; line-height: 1.6; }
  .tables { background: #0f172a; border-radius: 8px; padding: 1rem; margin: 1rem 0; text-align: left; font-size: .8rem; color: #64748b; }
  .tables div { padding: 2px 0; }
  .tables span { color: #4ade80; margin-right: .5rem; }
  .success a { display: inline-block; padding: .7rem 2.5rem; background: #16a34a; color: #fff; border-radius: 8px; text-decoration: none; font-weight: 600; margin-top: .5rem; }
  .success a:hover { background: #15803d; }
  .badge { display: inline-block; background: #0ea5e9; color: #fff; font-size: .7rem; padding: .15rem .5rem; border-radius: 999px; vertical-align: middle; margin-left: .5rem; }
  .note { background: #172033; border: 1px solid #1e3a5f; border-radius: 8px; padding: .75rem 1rem; font-size: .78rem; color: #7dd3fc; margin-bottom: 1rem; }
</style>
</head>
<body>
<div class="card">

<?php if ($success): ?>
  <div class="success">
    <h2>✓ Installation abgeschlossen!</h2>
    <p>Alle Tabellen wurden angelegt und dein Admin-Account ist bereit:</p>
    <div class="tables">
      <?php foreach ($tablesCreated as $t): ?>
        <div><span>✓</span><?= htmlspecialchars($t) ?></div>
      <?php endforeach; ?>
    </div>
    <p>Login mit: <strong><?= htmlspecialchars($admin_email ?? '') ?></strong><br>
    Die Installationsdatei wurde automatisch gelöscht.</p>
    <a href="login.php">→ Zum Login</a>
  </div>

<?php else: ?>
  <h1>🔧 Ticketsystem <span class="badge">Installer</span></h1>
  <p class="subtitle">Fülle alle Felder aus – der Installer erstellt die Datenbanktabellen und deinen Admin-Account vollautomatisch.</p>

  <?php if ($error): ?>
    <div class="error">⚠ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post" autocomplete="off">

    <div class="section-title">Datenbank</div>
    <div class="note">Die Datenbank selbst muss bereits existieren (z.B. über phpMyAdmin anlegen). Alle Tabellen erstellt der Installer automatisch.</div>
    <div class="row">
      <div class="form-group">
        <label>DB Host <span class="req">*</span></label>
        <input name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>">
      </div>
      <div class="form-group">
        <label>DB Name <span class="req">*</span></label>
        <input name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>" placeholder="ticketsystem">
      </div>
    </div>
    <div class="row">
      <div class="form-group">
        <label>DB Benutzer <span class="req">*</span></label>
        <input name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>DB Passwort</label>
        <input name="db_pass" type="password">
      </div>
    </div>

    <div class="section-title">Admin-Account <span style="color:#94a3b8;font-weight:400;text-transform:none;font-size:.8rem;">(wird automatisch angelegt)</span></div>
    <div class="form-group">
      <label>Name <span class="req">*</span></label>
      <input name="admin_name" value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>" placeholder="Max Mustermann">
    </div>
    <div class="row">
      <div class="form-group">
        <label>E-Mail <span class="req">*</span></label>
        <input name="admin_email" type="email" value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" placeholder="admin@firma.at">
      </div>
      <div class="form-group">
        <label>Passwort <span class="req">*</span></label>
        <input name="admin_pass" type="password" placeholder="Sicheres Passwort">
      </div>
    </div>

    <div class="section-title">Firma & URL</div>
    <div class="form-group">
      <label>Firmenname <span class="req">*</span></label>
      <input name="company" value="<?= htmlspecialchars($_POST['company'] ?? '') ?>" placeholder="Meine Firma GmbH">
    </div>
    <div class="row">
      <div class="form-group">
        <label>Firmen-E-Mail</label>
        <input name="company_email" type="email" value="<?= htmlspecialchars($_POST['company_email'] ?? '') ?>" placeholder="office@firma.at">
      </div>
      <div class="form-group">
        <label>Firmen-Telefon</label>
        <input name="company_phone" value="<?= htmlspecialchars($_POST['company_phone'] ?? '') ?>" placeholder="+43 1 123 456">
      </div>
    </div>
    <div class="form-group">
      <label>Base URL <span class="req">*</span></label>
      <input name="base_url" value="<?= htmlspecialchars($_POST['base_url'] ?? (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '')) ?>">
      <div class="hint">Kein Schrägstrich am Ende. Wird automatisch aus der aktuellen Domain vorausgefüllt.</div>
    </div>

    <div class="section-title">Sonstiges</div>
    <div class="row">
      <div class="form-group">
        <label>Ticket-Prefix</label>
        <input name="ticket_prefix" value="<?= htmlspecialchars($_POST['ticket_prefix'] ?? 'TK') ?>" maxlength="5">
      </div>
      <div class="form-group">
        <label>Zeitzone</label>
        <select name="timezone">
          <?php
          $zones = ['Europe/Vienna','Europe/Berlin','Europe/Zurich','Europe/London','America/New_York','America/Los_Angeles','UTC'];
          $sel = $_POST['timezone'] ?? 'Europe/Vienna';
          foreach ($zones as $z) echo "<option value=\"$z\"" . ($z===$sel?' selected':'') . ">$z</option>";
          ?>
        </select>
      </div>
    </div>

    <button class="btn" type="submit">Installation abschließen →</button>
  </form>
<?php endif; ?>

</div>
</body>
</html>