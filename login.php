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


require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/totp.php';
startSession(); // Erst Session starten!

if (isLoggedIn()) { header('Location: ' . BASE_URL . '/index.php'); exit; }

// ── Brute-Force-Schutz ──
$ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$maxFails = 5;
$lockMin  = 15;

purgeOldLoginAttempts($lockMin);
cleanupExpiredTrustedDevices();

$pwFails    = countLoginFails($ip, 'password', $lockMin);
$pwLocked   = $pwFails   >= $maxFails;
$totpFails  = countLoginFails($ip, 'totp', $lockMin);
$totpLocked = $totpFails >= $maxFails;

$error  = '';
$notice = '';

// ── Reset-Link: 2FA-Schritt abbrechen ───────────────────────────
if (isset($_GET['reset'])) {
    unset($_SESSION['totp_pending_user']);
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (isset($_GET['logout']) && $_GET['logout'] === 'remote') {
    $notice = 'Du wurdest aus Sicherheitsgründen abgemeldet. Bitte melde dich erneut an.';
}

$step = !empty($_SESSION['totp_pending_user']) ? '2fa' : 'login';

// ── 2FA Code-Eingabe ─────────────────────────────────────────────
if ($step === '2fa' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_code'])) {
    if ($totpLocked) {
        $error = 'Zu viele 2FA-Fehlversuche. Bitte 15 Minuten warten.';
    } else {
        $pending = $_SESSION['totp_pending_user'];
        $code    = trim($_POST['totp_code'] ?? '');
        $db      = getDB();
        $stmt    = $db->prepare("SELECT * FROM technicians WHERE id = ?");
        $stmt->execute([$pending['id']]);
        $tech = $stmt->fetch();

        if ($tech && TOTP::verify($tech['totp_secret'], $code)) {
            // Optional: diesen Browser 30 Tage merken
            if (!empty($_POST['remember_device'])) {
                createTrustedDevice((int)$tech['id'], 30);
            }
            unset($_SESSION['totp_pending_user']);
            session_regenerate_id(true);
            $_SESSION['technician_id']   = $tech['id'];
            $_SESSION['technician_name'] = $tech['name'];
            $_SESSION['login_time']      = time();
            $_SESSION['imap_fetch_pending'] = true;   // E-Mail-Abruf direkt nach dem Login
            clearLoginFails($ip, 'password');
            clearLoginFails($ip, 'totp');
            header('Location: ' . BASE_URL . '/index.php');
            exit;
        } else {
            recordLoginAttempt($ip, 'totp');
            $totpFails++;
            if ($totpFails >= $maxFails) {
                $totpLocked = true;
                $error = 'Zu viele 2FA-Fehlversuche. Bitte 15 Minuten warten.';
            } else {
                $error = 'Falscher Code. Noch ' . ($maxFails - $totpFails) . ' Versuch(e).';
            }
            $step = '2fa';
        }
    }
}

// ── Passwort-Login ───────────────────────────────────────────────
if ($step !== '2fa' && !$pwLocked && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password']   ?? '';

    if ($email && $password) {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM technicians WHERE email = ?");
        $stmt->execute([$email]);
        $tech = $stmt->fetch();

        if ($tech && password_verify($password, $tech['password'])) {
            clearLoginFails($ip, 'password');
            if ($tech['totp_enabled']) {
                // Vertrauter Browser? -> 2FA überspringen
                if (trustedDeviceValid((int)$tech['id'])) {
                    session_regenerate_id(true);
                    $_SESSION['technician_id']   = $tech['id'];
                    $_SESSION['technician_name'] = $tech['name'];
                    $_SESSION['login_time']      = time();
                    $_SESSION['imap_fetch_pending'] = true;   // E-Mail-Abruf direkt nach dem Login
                    header('Location: ' . BASE_URL . '/index.php');
                    exit;
                }
                $_SESSION['totp_pending_user'] = ['id' => $tech['id'], 'name' => $tech['name']];
                $step = '2fa';
            } else {
                session_regenerate_id(true);
                $_SESSION['technician_id']   = $tech['id'];
                $_SESSION['technician_name'] = $tech['name'];
                $_SESSION['login_time']      = time();
                $_SESSION['imap_fetch_pending'] = true;   // E-Mail-Abruf direkt nach dem Login
                header('Location: ' . BASE_URL . '/index.php');
                exit;
            }
        } else {
            recordLoginAttempt($ip, 'password');
            $pwFails++;
            if ($pwFails >= $maxFails) {
                $pwLocked = true;
                $error = 'Zu viele Fehlversuche. Bitte 15 Minuten warten.';
            } else {
                $error = 'E-Mail oder Passwort falsch. Noch ' . ($maxFails - $pwFails) . ' Versuch(e).';
            }
        }
    } else {
        $error = 'Bitte E-Mail und Passwort eingeben.';
    }
} elseif ($pwLocked && $step !== '2fa') {
    $error = 'Zu viele Fehlversuche. Bitte 15 Minuten warten.';
}
$appName = 'RepairDesk';
$appIcon = '🔧';
$bgColor = '#334155';
$accent  = '#2563eb';
try {
    if (defined('DB_NAME')) {
        $appName = getSetting('app_name', 'RepairDesk');
        $appIcon = getSetting('app_icon', '🔧');
        $bgColor = getSetting('login_bg_color', '#334155');
        $accent  = getSetting('app_accent_color', '#2563eb');
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Anmelden – <?= h($appName) ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
       background: linear-gradient(135deg, #1e293b 0%, <?= h($bgColor) ?> 100%);
       min-height: 100vh; display: flex; align-items: center; justify-content: center; }
.login-box { background: #fff; border-radius: 12px; padding: 40px; width: 100%;
             max-width: 400px; box-shadow: 0 20px 40px rgba(0,0,0,0.3); }
.login-logo { text-align: center; margin-bottom: 30px; }
.login-logo .icon { font-size: 48px; display: block; margin-bottom: 8px; }
.login-logo h1 { font-size: 26px; color: #1e293b; }
.login-logo p { color: #64748b; font-size: 14px; margin-top: 4px; }
label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 5px; }
input[type=email], input[type=password], input[type=text] {
  width: 100%; padding: 11px 14px; border: 1px solid #d1d5db; border-radius: 6px;
  font-size: 14px; margin-bottom: 16px; }
input:focus { outline: none; border-color: <?= h($accent) ?>; box-shadow: 0 0 0 3px <?= h($accent) ?>22; }
.btn { width: 100%; padding: 12px; background: <?= h($accent) ?>; color: #fff; border: none;
       border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; }
.btn:hover { opacity: .9; }
.btn:disabled { opacity: .5; cursor: not-allowed; }
.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;
         border-radius: 6px; padding: 10px 14px; font-size: 14px; margin-bottom: 16px; }
.totp-hint { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px;
             padding: 14px; margin-bottom: 16px; font-size: 13px; color: #1e40af; text-align:center; }
.totp-input { font-size: 28px !important; letter-spacing: 10px !important; text-align: center !important; }
.back-link { display:block; text-align:center; margin-top:14px; font-size:13px; color:#64748b;
             text-decoration:none; }
.back-link:hover { color:#2563eb; }
</style>
</head>
<body>
<div class="login-box">
  <div class="login-logo">
    <span class="icon"><?= h($appIcon) ?></span>
    <h1><?= h($appName) ?></h1>
    <p><?= h(getSetting('company_name', defined('COMPANY_NAME') ? COMPANY_NAME : '')) ?></p>
  </div>

  <?php if ($notice): ?>
  <div class="totp-hint">ℹ️ <?= h($notice) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="error">❌ <?= h($error) ?></div>
  <?php endif; ?>

  <?php if ($step === '2fa'): ?>
  <div class="totp-hint">
    📱 Gib den 6-stelligen Code aus deiner Authenticator-App ein
  </div>
  <form method="post" action="">
    <label>Authenticator-Code</label>
    <input type="text" name="totp_code" class="totp-input" maxlength="6"
           pattern="\d{6}" placeholder="000000" autocomplete="one-time-code"
           <?= $totpLocked ? 'disabled' : 'autofocus' ?>>
    <label style="display:flex;align-items:center;gap:8px;font-weight:400;cursor:pointer;margin:4px 0 16px;">
      <input type="checkbox" name="remember_device" value="1" style="width:auto;margin:0;" <?= $totpLocked ? 'disabled' : '' ?>>
      Diesem Browser 30 Tage vertrauen (keine 2FA-Abfrage)
    </label>
    <button type="submit" class="btn" <?= $totpLocked ? 'disabled' : '' ?>>✅ Bestätigen</button>
  </form>
  <a class="back-link" href="<?= BASE_URL ?>/login.php?reset=1">← Zurück zum Login</a>

  <?php else: ?>
  <form method="post" action="">
    <label>E-Mail Adresse</label>
    <input type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>"
           required autofocus placeholder="techniker@firma.de"
           <?= $pwLocked ? 'disabled' : '' ?>>
    <label>Passwort</label>
    <input type="password" name="password" required placeholder="••••••••"
           <?= $pwLocked ? 'disabled' : '' ?>>
    <button type="submit" class="btn" <?= $pwLocked ? 'disabled' : '' ?>>Anmelden</button>
  </form>
  <?php endif; ?>

</div>
</body>
</html>