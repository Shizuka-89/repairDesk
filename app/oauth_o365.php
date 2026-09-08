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

// ================================================================
// Office 365 – Verbinden (delegierter OAuth2-Flow, Authorization Code)
//
// Dieselbe Datei ist Start UND Callback:
//   - Aufruf ohne ?code  → leitet zur Microsoft-Anmeldung weiter
//   - Aufruf mit  ?code   → tauscht Code gegen Tokens und speichert
//                           den Refresh-Token (einmalige Anmeldung)
//
// Die URL dieser Datei muss in der Azure-App als „Redirect-URI"
// hinterlegt sein:  <BASE_URL>/oauth_o365.php
// ================================================================

require_once __DIR__ . '/../includes/functions.php';

startSession();

if (!isLoggedIn()) { http_response_code(403); die('Zugriff verweigert – bitte anmelden.'); }
if (!isAdmin())    { http_response_code(403); die('Nur Administratoren dürfen die E-Mail-Verbindung einrichten.'); }

$tenant = trim(getSetting('oauth_tenant', ''));
$client = trim(getSetting('oauth_client_id', ''));
$secret = trim(getSetting('oauth_client_secret', ''));

$redirectUri = BASE_URL . '/oauth_o365.php';
$backToMail  = BASE_URL . '/settings.php?tab=mail';

if ($tenant === '' || $client === '' || $secret === '') {
    flash('Bitte zuerst Tenant-ID, Client-ID und Client-Secret speichern – dann verbinden.', 'error');
    header('Location: ' . $backToMail); exit;
}

$code  = $_GET['code']  ?? '';
$error = $_GET['error'] ?? '';

// ── Vom Login-Server mit Fehler zurückgekommen ──
if ($error !== '') {
    $desc = $_GET['error_description'] ?? $error;
    flash('Office-365-Verbindung abgebrochen: ' . substr((string)$desc, 0, 200), 'error');
    header('Location: ' . $backToMail); exit;
}

// ── Start: zur Microsoft-Anmeldung weiterleiten ──
if ($code === '') {
    $state = bin2hex(random_bytes(16));
    $_SESSION['o365_state'] = $state;

    $loginHint = trim(getSetting('imap_user', '')) ?: trim(getSetting('smtp_user', ''));

    $params = http_build_query([
        'client_id'     => $client,
        'response_type' => 'code',
        'redirect_uri'  => $redirectUri,
        'response_mode' => 'query',
        'scope'         => o365Scopes(),
        'state'         => $state,
        'prompt'        => 'consent',
        'login_hint'    => $loginHint,
    ]);

    header('Location: https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/authorize?' . $params);
    exit;
}

// ── Callback: State prüfen ──
$state = $_GET['state'] ?? '';
if (empty($_SESSION['o365_state']) || !hash_equals((string)$_SESSION['o365_state'], (string)$state)) {
    flash('Sicherheitsprüfung fehlgeschlagen (State). Bitte erneut verbinden.', 'error');
    header('Location: ' . $backToMail); exit;
}
unset($_SESSION['o365_state']);

// ── Code gegen Tokens tauschen ──
$post = http_build_query([
    'client_id'     => $client,
    'client_secret' => $secret,
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'redirect_uri'  => $redirectUri,
    'scope'         => o365Scopes(),
]);

$resp = httpsPost(
    'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token',
    $post,
    ['Content-Type: application/x-www-form-urlencoded']
);
$data = ($resp !== null) ? json_decode($resp, true) : null;

if (!is_array($data) || empty($data['refresh_token'])) {
    $emsg = is_array($data) ? ($data['error_description'] ?? $data['error'] ?? 'Unbekannter Fehler') : 'Keine Antwort vom Login-Server';
    error_log('O365 Verbinden Fehler: ' . substr((string)$emsg, 0, 400));
    flash('Verbindung fehlgeschlagen: ' . substr((string)$emsg, 0, 200), 'error');
    header('Location: ' . $backToMail); exit;
}

// Tokens speichern
saveSetting('oauth_refresh_token', (string)$data['refresh_token']);
if (!empty($data['access_token'])) {
    saveSetting('oauth_token', (string)$data['access_token']);
    saveSetting('oauth_token_expires', (string)(time() + (int)($data['expires_in'] ?? 3600)));
}

// Verbundenes Postfach merken (fürs Statusanzeige) – aus dem konfigurierten Postfach
$connectedEmail = trim(getSetting('imap_user', '')) ?: trim(getSetting('smtp_user', ''));
saveSetting('oauth_connected_email', $connectedEmail);

flash('✅ Office 365 erfolgreich verbunden' . ($connectedEmail !== '' ? ' (' . $connectedEmail . ')' : '') . '. Versand und Empfang sind jetzt aktiv.', 'success');
header('Location: ' . $backToMail);
exit;
