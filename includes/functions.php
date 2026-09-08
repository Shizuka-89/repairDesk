<?php
ob_start(); // Verhindert 'headers already sent' auf allen Seiten
require_once __DIR__ . '/../config.php';

// ================================================================
// Datenbankverbindung (PDO)
// ================================================================
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Details nur ins Server-Log, dem Besucher nur eine generische Meldung zeigen
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(503);
            die('<div style="font-family:sans-serif;padding:20px;color:#991b1b;"><strong>Dienst vorübergehend nicht verfügbar.</strong><br>Bitte versuchen Sie es in Kürze erneut.</div>');
        }
    }
    return $pdo;
}

// ================================================================
// Kundenname zusammensetzen (Vorname + Nachname)
// ================================================================
function customerFullName(array $customer): string {
    $first = trim($customer['firstname'] ?? $customer['name'] ?? '');
    $last  = trim($customer['lastname']  ?? '');
    if ($first && $last) return $first . ' ' . $last;
    return $first ?: $last ?: '(kein Name)';
}

// ================================================================
// Session
// ================================================================
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_start();
    }
}

function isLoggedIn(): bool {
    startSession();
    return isset($_SESSION['technician_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    // "Überall abmelden": Sessions vor dem Stichzeitpunkt beenden
    enforceGlobalLogout();
}

function getCurrentTechnician(): ?array {
    if (!isLoggedIn()) return null;
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM technicians WHERE id = ?");
    $stmt->execute([$_SESSION['technician_id']]);
    return $stmt->fetch() ?: null;
}

function isAdmin(): bool {
    $tech = getCurrentTechnician();
    return $tech && $tech['is_admin'];
}

// ================================================================
// Ticket-Nummer generieren
// ================================================================
function generateTicketNumber(): string {
    $db = getDB();
    $year = date('Y');
    $prefix = TICKET_PREFIX . '-' . $year . '-';

    $stmt = $db->prepare("SELECT ticket_number FROM tickets WHERE ticket_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();

    if ($last) {
        $lastNum = (int) substr($last, strlen($prefix));
        $newNum  = $lastNum + 1;
    } else {
        $newNum = 1;
    }

    return $prefix . str_pad($newNum, 4, '0', STR_PAD_LEFT);
}

// ================================================================
// Kunden-Token für E-Mail-Links
// ================================================================
function generateCustomerToken(): string {
    return bin2hex(random_bytes(32));
}

// ================================================================
// E-Mail senden
// ================================================================
function sendMail(string $to, string $toName, string $subject, string $htmlBody, string $textBody = ''): bool {
    if (empty($textBody)) {
        $textBody = strip_tags($htmlBody);
    }
    // DB-Einstellung hat Vorrang vor config.php
    $method = getSetting('mail_method', defined('MAIL_METHOD') ? MAIL_METHOD : 'php_mail');
    if ($method === 'smtp') {
        return sendMailSMTP($to, $toName, $subject, $htmlBody, $textBody);
    } else {
        return sendMailPHP($to, $toName, $subject, $htmlBody, $textBody);
    }
}

// Antwort-Adresse-Override: NUR wenn E-Mail-Empfang (IMAP) aktiv ist und eine
// gültige Postfach-Adresse hinterlegt ist, sollen Kunden-Antworten dorthin gehen.
// Sonst leeren String zurückgeben = kein Override (Versand verhält sich wie zuvor).
function getReplyToAddress(): string {
    if (getSetting('imap_enabled', '0') === '1') {
        $imapUser = trim(getSetting('imap_user', ''));
        if ($imapUser !== '' && filter_var($imapUser, FILTER_VALIDATE_EMAIL)) {
            return $imapUser;
        }
    }
    return '';
}

// ================================================================
// Office 365 OAuth2 (Modern Authentication)
// Gemeinsame App-Zugangsdaten (Tenant/Client/Secret) für Empfang
// (IMAP) und Versand (SMTP). Token wird zwischengespeichert.
// ================================================================

/** HTTPS-POST (cURL bevorzugt, Fallback über Streams) */
function httpsPost(string $url, string $body, array $headers = []): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) { error_log('httpsPost cURL: ' . $err); return null; }
        return $res;
    }
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", $headers),
        'content'       => $body,
        'timeout'       => 30,
        'ignore_errors' => true,
    ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $res = @file_get_contents($url, false, $ctx);
    return $res === false ? null : $res;
}

/** Delegierte Outlook-Scopes für IMAP + SMTP (+ offline_access für Refresh-Token) */
function o365Scopes(): string {
    return 'https://outlook.office365.com/IMAP.AccessAsUser.All '
         . 'https://outlook.office365.com/SMTP.Send '
         . 'offline_access';
}

/**
 * Access-Token für Office 365 holen – delegierter Flow.
 * Nutzt den beim einmaligen „Verbinden" gespeicherten Refresh-Token.
 * Token wird ~1 Std. zwischengespeichert. Gibt null zurück bei Fehler
 * (z. B. noch nicht verbunden).
 */
function getO365AccessToken(): ?string {
    $tenant = trim(getSetting('oauth_tenant', ''));
    $client = trim(getSetting('oauth_client_id', ''));
    $secret = trim(getSetting('oauth_client_secret', ''));
    if ($tenant === '' || $client === '' || $secret === '') return null;

    // Zwischengespeichertes Token noch gültig? (2 Min. Puffer)
    $cached = getSetting('oauth_token', '');
    $exp    = (int)getSetting('oauth_token_expires', '0');
    if ($cached !== '' && $exp > (time() + 120)) {
        return $cached;
    }

    $refresh = trim(getSetting('oauth_refresh_token', ''));
    if ($refresh === '') return null; // noch nicht mit Office 365 verbunden

    $url  = 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token';
    $post = http_build_query([
        'client_id'     => $client,
        'client_secret' => $secret,
        'grant_type'    => 'refresh_token',
        'refresh_token' => $refresh,
        'scope'         => o365Scopes(),
    ]);

    $resp = httpsPost($url, $post, ['Content-Type: application/x-www-form-urlencoded']);
    if ($resp === null) { error_log('O365 Token: keine Antwort vom Login-Server'); return null; }

    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['access_token'])) {
        $errCode = is_array($data) ? ($data['error'] ?? '') : '';
        $errMsg  = is_array($data) ? ($data['error_description'] ?? $errCode) : '';
        error_log('O365 Token Fehler: ' . substr((string)$errMsg, 0, 300));
        // Refresh-Token ungültig/abgelaufen → verwerfen, damit UI „neu verbinden" zeigt
        if ($errCode === 'invalid_grant' || $errCode === 'interaction_required') {
            saveSetting('oauth_refresh_token', '');
            saveSetting('oauth_connected_email', '');
        }
        return null;
    }

    $token = (string)$data['access_token'];
    $ttl   = (int)($data['expires_in'] ?? 3600);
    saveSetting('oauth_token', $token);
    saveSetting('oauth_token_expires', (string)(time() + $ttl));
    // Microsoft rotiert den Refresh-Token teils mit → neuen speichern
    if (!empty($data['refresh_token'])) {
        saveSetting('oauth_refresh_token', (string)$data['refresh_token']);
    }
    return $token;
}

/** SASL-XOAUTH2-Zeichenkette (base64) für IMAP/SMTP erzeugen */
function buildXOAuth2(string $user, string $token): string {
    return base64_encode("user=" . $user . "\x01auth=Bearer " . $token . "\x01\x01");
}

/** Letzte OAuth-Fehlermeldung fürs UI (nur grober Hinweis) */
function o365TokenError(): string {
    $tenant = trim(getSetting('oauth_tenant', ''));
    $client = trim(getSetting('oauth_client_id', ''));
    $secret = trim(getSetting('oauth_client_secret', ''));
    if ($tenant === '' || $client === '' || $secret === '') {
        return 'OAuth2-Zugangsdaten unvollständig (Tenant-ID, Client-ID und Client-Secret nötig).';
    }
    if (trim(getSetting('oauth_refresh_token', '')) === '') {
        return 'Noch nicht mit Office 365 verbunden – bitte in den Einstellungen auf „Mit Office 365 verbinden" klicken.';
    }
    return 'Token konnte nicht abgerufen werden – die Verbindung ist evtl. abgelaufen. Bitte erneut „Mit Office 365 verbinden".';
}

function sendMailPHP(string $to, string $toName, string $subject, string $htmlBody, string $textBody): bool {
    $fromName  = getSetting('company_name', defined('COMPANY_NAME') ? COMPANY_NAME : 'RepairDesk');
    $fromEmail = getSetting('company_email', defined('COMPANY_EMAIL') ? COMPANY_EMAIL : 'noreply@localhost');
    $replyTo   = getReplyToAddress();
    if ($replyTo === '') {
        $replyTo = $fromEmail; // Original-Verhalten (1.2.0): Reply-To = Firmen-Adresse
    }
    // Header-Injection verhindern: Zeilenumbrüche aus Name und E-Mail entfernen
    $fromName  = str_replace(["\r", "\n", "\t"], '', $fromName);
    $fromEmail = str_replace(["\r", "\n", "\t", ' '], '', $fromEmail);
    $replyTo   = str_replace(["\r", "\n", "\t", ' '], '', $replyTo);
    
    $boundary = md5(time());
    $headers  = implode("\r\n", [
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'Reply-To: ' . $replyTo,
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'X-Mailer: RepairDesk',
    ]);
    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $body .= $textBody . "\r\n\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $body .= $htmlBody . "\r\n\r\n";
    $body .= "--{$boundary}--";
    return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

function sendMailSMTP(string $to, string $toName, string $subject, string $htmlBody, string $textBody): bool {
    try {
        // Alle Werte mit defined() Check als Fallback
        $smtpHost   = getSetting('smtp_host',   defined('SMTP_HOST') ? SMTP_HOST : 'localhost');
        $smtpPort   = (int)getSetting('smtp_port', defined('SMTP_PORT') ? (string)SMTP_PORT : '587');
        $smtpSecure = getSetting('smtp_secure', defined('SMTP_SECURE') ? SMTP_SECURE : 'tls');
        $smtpUser   = getSetting('smtp_user',   defined('SMTP_USER') ? SMTP_USER : '');
        $smtpPass   = getSetting('smtp_pass',   defined('SMTP_PASS') ? SMTP_PASS : '');
        $fromEmail  = getSetting('smtp_user',   defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'noreply@localhost');
        $fromName   = getSetting('smtp_from_name', defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'RepairDesk');

        $socket = fsockopen(
            ($smtpSecure === 'ssl' ? 'ssl://' : '') . $smtpHost,
            $smtpPort, $errno, $errstr, 30
        );
        if (!$socket) { error_log("SMTP connect failed: $errstr ($errno)"); return false; }

        $read = fgets($socket, 512);
        if (substr($read, 0, 3) !== '220') return smtpClose($socket, false);

        fputs($socket, "EHLO " . gethostname() . "\r\n");
        $capabilities = '';
        while ($line = fgets($socket, 512)) { $capabilities .= $line; if ($line[3] === ' ') break; }

        if ($smtpSecure === 'tls') {
            fputs($socket, "STARTTLS\r\n");
            $read = fgets($socket, 512);
            if (substr($read, 0, 3) !== '220') return smtpClose($socket, false);
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            fputs($socket, "EHLO " . gethostname() . "\r\n");
            while ($line = fgets($socket, 512)) { if ($line[3] === ' ') break; }
        }

        $smtpAuthMethod = getSetting('smtp_auth_method', 'password');

        if ($smtpAuthMethod === 'oauth2_o365') {
            // ── Office 365 OAuth2 (XOAUTH2) ──
            $token = getO365AccessToken();
            if ($token === null) { error_log('SMTP OAuth2: kein Token'); return smtpClose($socket, false); }
            $authStr = buildXOAuth2($fromEmail, $token);
            fputs($socket, "AUTH XOAUTH2 " . $authStr . "\r\n");
            $read = fgets($socket, 1024);
            // Bei Fehler antwortet der Server mit 334 (base64-Fehler) → leere Zeile senden, finalen Status holen
            if (substr($read, 0, 3) === '334') {
                fputs($socket, "\r\n");
                $read = fgets($socket, 1024);
            }
            if (substr($read, 0, 3) !== '235') { error_log('SMTP XOAUTH2 abgelehnt: ' . trim($read)); return smtpClose($socket, false); }
        } elseif (!empty($smtpUser)) {
            fputs($socket, "AUTH LOGIN\r\n");
            $read = fgets($socket, 512);
            if (substr($read, 0, 3) !== '334') return smtpClose($socket, false);
            fputs($socket, base64_encode($smtpUser) . "\r\n");
            $read = fgets($socket, 512);
            if (substr($read, 0, 3) !== '334') return smtpClose($socket, false);
            fputs($socket, base64_encode($smtpPass) . "\r\n");
            $read = fgets($socket, 512);
            if (substr($read, 0, 3) !== '235') return smtpClose($socket, false);
        }

        fputs($socket, "MAIL FROM:<" . $fromEmail . ">\r\n");
        $read = fgets($socket, 512);
        if (substr($read, 0, 3) !== '250') return smtpClose($socket, false);

        fputs($socket, "RCPT TO:<{$to}>\r\n");
        $read = fgets($socket, 512);
        if (substr($read, 0, 3) !== '250') return smtpClose($socket, false);

        fputs($socket, "DATA\r\n");
        $read = fgets($socket, 512);
        if (substr($read, 0, 3) !== '354') return smtpClose($socket, false);

        $boundary = md5(uniqid());
        $replyTo  = str_replace(["\r", "\n", "\t", ' '], '', getReplyToAddress());
        $headers  = "From: " . $fromName . " <" . $fromEmail . ">\r\n";
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers .= "Reply-To: " . $replyTo . "\r\n";
        }
        $headers .= "To: {$toName} <{$to}>\r\n";
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $headers .= "Date: " . date('r') . "\r\n\r\n";

        $message  = "--{$boundary}\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($textBody)) . "\r\n";
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        $message .= "--{$boundary}--\r\n";

        fputs($socket, $headers . $message . "\r\n.\r\n");
        $read = fgets($socket, 512);
        fputs($socket, "QUIT\r\n");
        fclose($socket);
        return substr($read, 0, 3) === '250';

    } catch (Exception $e) {
        error_log("SMTP error: " . $e->getMessage());
        return false;
    }
}

function smtpClose($socket, bool $result): bool {
    if ($socket) { fputs($socket, "QUIT\r\n"); fclose($socket); }
    return $result;
}

// ================================================================
// E-Mail Templates
// ================================================================
function emailTemplate(string $title, string $content): string {
    $company = htmlspecialchars(getSetting('company_name', defined('COMPANY_NAME') ? COMPANY_NAME : 'RepairDesk'));
    return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:30px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
  <tr><td style="background:#2563eb;padding:25px 30px;">
    <h1 style="margin:0;color:#ffffff;font-size:22px;">{$company}</h1>
  </td></tr>
  <tr><td style="padding:30px;">
    <h2 style="margin:0 0 20px;color:#1e293b;font-size:18px;">{$title}</h2>
    {$content}
    <hr style="border:none;border-top:1px solid #e2e8f0;margin:25px 0;">
    <p style="margin:0;color:#94a3b8;font-size:12px;">Diese E-Mail wurde automatisch von {$company} gesendet.</p>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
}

function sendTicketCreatedMails(array $ticket, array $customer, ?array $technician): void {
    $ticketNum   = htmlspecialchars($ticket['ticket_number']);
    $title       = htmlspecialchars($ticket['title']);
    $company     = htmlspecialchars(getSetting('company_name', defined('COMPANY_NAME') ? COMPANY_NAME : 'RepairDesk'));
    $fullName    = htmlspecialchars(customerFullName($customer));

    $companyEmail = htmlspecialchars(getSetting('company_email', defined('COMPANY_EMAIL') ? COMPANY_EMAIL : ''));
    $showDevice  = getSetting('show_device_info', '1') === '1';
    $lblModel    = htmlspecialchars(getSetting('label_device_model',         'Gerät / Modell'));
    $lblSerial   = htmlspecialchars(getSetting('label_serial_number',         'Seriennummer / IMEI'));
    $lblCond     = htmlspecialchars(getSetting('label_condition_on_arrival',  'Zustand bei Annahme'));
    $lblDamage   = htmlspecialchars(getSetting('label_damage_description',    'Schadensbeschreibung'));

    $model     = htmlspecialchars($ticket['device_model'] ?? '');
    $serial    = htmlspecialchars($ticket['serial_number'] ?? '');
    $condition = htmlspecialchars($ticket['condition_on_arrival'] ?? '');
    $damage    = nl2br(htmlspecialchars($ticket['damage_description'] ?? ''));

    // Gerätzeilen für E-Mail (nur wenn aktiviert)
    $deviceRowsCustomer = $showDevice ? <<<HTML
  <tr style="background:#f8fafc;"><td style="padding:10px;border:1px solid #e2e8f0;">{$lblModel}</td><td style="padding:10px;border:1px solid #e2e8f0;">{$model}</td></tr>
  <tr><td style="padding:10px;border:1px solid #e2e8f0;">{$lblSerial}</td><td style="padding:10px;border:1px solid #e2e8f0;">{$serial}</td></tr>
  <tr style="background:#f8fafc;"><td style="padding:10px;border:1px solid #e2e8f0;">{$lblCond}</td><td style="padding:10px;border:1px solid #e2e8f0;">{$condition}</td></tr>
  <tr><td style="padding:10px;border:1px solid #e2e8f0;">{$lblDamage}</td><td style="padding:10px;border:1px solid #e2e8f0;">{$damage}</td></tr>
HTML : '';

    $deviceRowsAdmin = $showDevice ? <<<HTML
  <tr><td style="padding:10px;border:1px solid #e2e8f0;">{$lblModel}</td><td style="padding:10px;border:1px solid #e2e8f0;">{$model}</td></tr>
  <tr style="background:#f8fafc;"><td style="padding:10px;border:1px solid #e2e8f0;">{$lblDamage}</td><td style="padding:10px;border:1px solid #e2e8f0;">{$damage}</td></tr>
HTML : '';

    // ================================================================
    // 1. E-Mail an KUNDEN
    // ================================================================
    if (!empty($customer['email'])) {
        $customerLink = BASE_URL . '/customer/view.php?token=' . urlencode($ticket['customer_token']);
        $replyHintCreated = getSetting('imap_enabled', '0') === '1'
            ? '<p style="color:#64748b;font-size:13px;">💬 Sie können auf unsere Ticket-E-Mails auch direkt antworten – Ihre Nachricht wird automatisch dem Auftrag hinzugefügt.</p>'
            : '';
        $content = <<<HTML
<p>Hallo {$fullName},</p>
<p>Ihr Reparaturauftrag wurde erfolgreich erfasst. Hier Ihre Auftragsdetails:</p>
<table style="width:100%;border-collapse:collapse;margin:15px 0;">
  <tr style="background:#f8fafc;"><td style="padding:10px;border:1px solid #e2e8f0;font-weight:bold;width:40%;">Ticket-Nummer</td><td style="padding:10px;border:1px solid #e2e8f0;font-weight:bold;color:#2563eb;">{$ticketNum}</td></tr>
  <tr><td style="padding:10px;border:1px solid #e2e8f0;">Betreff</td><td style="padding:10px;border:1px solid #e2e8f0;">{$title}</td></tr>
  {$deviceRowsCustomer}
</table>
<p>Sie können den Status Ihres Auftrags jederzeit unter folgendem Link einsehen:</p>
<p><a href="{$customerLink}" style="background:#2563eb;color:#fff;padding:12px 24px;text-decoration:none;border-radius:5px;display:inline-block;">Auftrag online ansehen</a></p>
<p>Bei Fragen wenden Sie sich bitte an uns: <a href="mailto:{$companyEmail}">{$companyEmail}</a></p>
{$replyHintCreated}
HTML;
        sendMail($customer['email'], $fullName, "Ihr Reparaturauftrag #{$ticketNum} wurde erstellt", emailTemplate("Auftrag erstellt: #{$ticketNum}", $content));
    }

    // ================================================================
    // 2. E-Mail an TECHNIKER oder ADMIN
    // ================================================================
    // Wenn Techniker da -> Techniker Mail. Wenn kein Techniker -> Company Mail (Admin)
    $targetEmail = ($technician && !empty($technician['email'])) ? $technician['email'] : COMPANY_EMAIL;
    $targetName  = ($technician && !empty($technician['name'])) ? $technician['name'] : getSetting('company_name', COMPANY_NAME);
    $salutation  = ($technician && !empty($technician['name'])) ? "Hallo " . $technician['name'] : "Hallo Team";

    $adminLink = BASE_URL . '/ticket_view.php?id=' . $ticket['id'];
    
    $contentAdmin = <<<HTML
<p>{$salutation},</p>
<p>Ein neues Ticket wurde im System erfasst:</p>
<table style="width:100%;border-collapse:collapse;margin:15px 0;">
  <tr style="background:#f8fafc;"><td style="padding:10px;border:1px solid #e2e8f0;font-weight:bold;width:40%;">Ticket-Nummer</td><td style="padding:10px;border:1px solid #e2e8f0;font-weight:bold;color:#2563eb;">{$ticketNum}</td></tr>
  <tr><td style="padding:10px;border:1px solid #e2e8f0;">Kunde</td><td style="padding:10px;border:1px solid #e2e8f0;">{$fullName}</td></tr>
  <tr style="background:#f8fafc;"><td style="padding:10px;border:1px solid #e2e8f0;">Betreff</td><td style="padding:10px;border:1px solid #e2e8f0;">{$title}</td></tr>
  {$deviceRowsAdmin}
</table>
<p><a href="{$adminLink}" style="background:#2563eb;color:#fff;padding:12px 24px;text-decoration:none;border-radius:5px;display:inline-block;">Ticket im System öffnen</a></p>
HTML;

    sendMail($targetEmail, $targetName, "Neues Ticket: #{$ticketNum} - {$title}", emailTemplate("Neues Ticket: #{$ticketNum}", $contentAdmin));
}

function sendTicketUpdateMail(array $ticket, array $customer, array $comment): void {
    if (empty($customer['email'])) return;

    $ticketNum    = htmlspecialchars($ticket['ticket_number']);
    $message      = nl2br(htmlspecialchars($comment['message']));
    $customerLink = BASE_URL . '/customer/view.php?token=' . urlencode($ticket['customer_token']);
    $fullName     = htmlspecialchars(customerFullName($customer));

    $imapEnabled = getSetting('imap_enabled', '0') === '1';

    $priceRow = '';
    if ($comment['price_proposal'] !== null) {
        $price    = number_format($comment['price_proposal'], 2, ',', '.') . ' €';
        $priceRow = <<<HTML
<div style="background:#fef3c7;border:1px solid #fbbf24;border-radius:6px;padding:15px;margin:15px 0;">
  <strong>💰 Kostenvoranschlag: {$price}</strong>
</div>
HTML;

        // ── Ein-Klick-Buttons (Zustimmen / Ablehnen) direkt in der E-Mail ──
        if (!empty($comment['id'])) {
            $acceptLink  = BASE_URL . '/customer/action.php?token=' . urlencode($ticket['customer_token']) . '&c=' . (int)$comment['id'] . '&do=accept';
            $declineLink = BASE_URL . '/customer/action.php?token=' . urlencode($ticket['customer_token']) . '&c=' . (int)$comment['id'] . '&do=decline';
            $priceRow .= <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" style="margin:5px 0 15px;">
<tr>
  <td align="center" style="padding:8px;">
    <a href="{$acceptLink}" style="background:#10b981;color:#fff;padding:14px 28px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:bold;font-size:16px;">✅ Zustimmen</a>
    &nbsp;&nbsp;
    <a href="{$declineLink}" style="background:#ef4444;color:#fff;padding:14px 28px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:bold;font-size:16px;">❌ Ablehnen</a>
  </td>
</tr>
</table>
HTML;
        }

        // ── Hinweis: Entscheidung auch einfach per E-Mail-Antwort möglich ──
        if ($imapEnabled) {
            $priceRow .= <<<HTML
<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:12px 15px;margin:15px 0;font-size:13px;color:#1e40af;">
  💬 <strong>Noch einfacher:</strong> Antworten Sie auf diese E-Mail einfach mit
  <strong>ZUSTIMMEN</strong> oder <strong>ABLEHNEN</strong> – wir übernehmen Ihre Entscheidung automatisch.
</div>
HTML;
        }
    }

    $replyHint = $imapEnabled
        ? '<p style="color:#64748b;font-size:13px;">💬 Sie können auf diese E-Mail auch direkt antworten – Ihre Nachricht wird automatisch dem Ticket hinzugefügt.</p>'
        : '';

    $content = <<<HTML
<p>Hallo {$fullName},</p>
<p>Es gibt eine Aktualisierung zu Ihrem Ticket <strong>#{$ticketNum}</strong>:</p>
<div style="background:#f8fafc;border-left:4px solid #2563eb;padding:15px;margin:15px 0;border-radius:0 6px 6px 0;">
  {$message}
</div>
{$priceRow}
<p><a href="{$customerLink}" style="background:#2563eb;color:#fff;padding:12px 24px;text-decoration:none;border-radius:5px;display:inline-block;">Ticket ansehen &amp; antworten</a></p>
{$replyHint}
HTML;

    sendMail($customer['email'], $fullName, "Ticket-Aktualisierung: #{$ticketNum}", emailTemplate("Aktualisierung zu Ticket #{$ticketNum}", $content));
}



function sendFinalPriceMail(array $ticket, array $customer): void {
    $price       = number_format($ticket['final_price'], 2, ',', '.') . ' €';
    $subject     = "Reparatur abgeschlossen: " . $ticket['ticket_number'];
    $companyName  = htmlspecialchars(getSetting('company_name',  defined('COMPANY_NAME')  ? COMPANY_NAME  : 'RepairDesk'));
    $companyEmail = htmlspecialchars(getSetting('company_email', defined('COMPANY_EMAIL') ? COMPANY_EMAIL : ''));

    // Falls Vorname leer ist, nutzen wir den vollen Namen
    $name = !empty($customer['firstname']) ? $customer['firstname'] : customerFullName($customer);
    
    $content = <<<HTML
<p>Hallo {$name},</p>
<p>Ihr Gerät ist fertig repariert! Der finale Endpreis beträgt: <b style="font-size:18px; color:#065f46;">{$price}</b>.</p>
<p>Sie können Ihr Gerät nun zu unseren Öffnungszeiten abholen.</p>
<p>Vielen Dank für Ihr Vertrauen in {$companyName}!</p>
<p><a href="mailto:{$companyEmail}" style="color:#2563eb;">Kontakt aufnehmen</a></p>
HTML;

    // Nutzt deine bestehenden Funktionen sendMail und emailTemplate
    sendMail(
        $customer['email'], 
        customerFullName($customer), 
        $subject, 
        emailTemplate("Reparatur fertig: #{$ticket['ticket_number']}", $content)
    );
}



// ================================================================
// Hilfsfunktionen
// ================================================================
function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function statusLabel(string $status): string {
    $labels = [
        'offen'           => ['Offen',               '#3b82f6', '#eff6ff'],
        'in_bearbeitung'  => ['In Bearbeitung',       '#f59e0b', '#fffbeb'],
        'warten_auf_kunde'=> ['Wartet auf Kunden',    '#8b5cf6', '#f5f3ff'],
        'abgeschlossen'   => ['Abgeschlossen',        '#10b981', '#ecfdf5'],
        'storniert'       => ['Storniert',            '#ef4444', '#fef2f2'],
    ];
    $l = $labels[$status] ?? ['Unbekannt', '#6b7280', '#f9fafb'];
    return "<span style='background:{$l[2]};color:{$l[1]};padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;'>{$l[0]}</span>";
}

function priorityLabel(string $priority): string {
    $labels = [
        'niedrig' => ['Niedrig', '#6b7280'],
        'normal'  => ['Normal',  '#3b82f6'],
        'hoch'    => ['Hoch',    '#f59e0b'],
        'dringend'=> ['Dringend','#ef4444'],
    ];
    $l = $labels[$priority] ?? ['Normal', '#3b82f6'];
    return "<span style='color:{$l[1]};font-weight:600;'>{$l[0]}</span>";
}

function formatDate(string $date): string {
    return date('d.m.Y H:i', strtotime($date));
}

function flash(string $message, string $type = 'success'): void {
    startSession();
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function getFlash(): ?array {
    startSession();
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function csrf(): string {
    startSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    startSession();
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        die('Ungültige Anfrage (CSRF).');
    }
}

// Benutzerdefinierte Felder
require_once __DIR__ . '/custom_fields.php';

// ================================================================
// v1.1.0 – Sicherheit, Trusted Devices, Login-Versuche, Backup
// ================================================================

// ── Saubere URL-Helfer (Dateien liegen physisch in /app/, URL bleibt sauber) ──
function appUrl(string $path = ''): string {
    return BASE_URL . '/' . ltrim($path, '/');
}

// ── "Überall abmelden": globale Sitzungs-Invalidierung ──────────
function enforceGlobalLogout(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $forceAfter = (int) getSetting('force_logout_after', '0');
    } catch (\Throwable $e) {
        return;
    }
    if ($forceAfter <= 0) return;
    $loginTime = (int) ($_SESSION['login_time'] ?? 0);
    if ($loginTime <= $forceAfter) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $pp = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $pp['path'], $pp['domain'], $pp['secure'], $pp['httponly']);
        }
        @session_destroy();
        header('Location: ' . BASE_URL . '/login.php?logout=remote');
        exit;
    }
}

// ── Login-Versuche (Brute-Force – Passwort UND 2FA) ─────────────
function purgeOldLoginAttempts(int $minutes = 15): void {
    try {
        getDB()->prepare("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)")->execute([$minutes]);
    } catch (\Throwable $e) {}
}
function recordLoginAttempt(string $ip, string $type): void {
    try {
        getDB()->prepare("INSERT INTO login_attempts (ip, type, attempted_at) VALUES (?, ?, NOW())")->execute([$ip, $type]);
    } catch (\Throwable $e) {}
}
function countLoginFails(string $ip, string $type, int $minutes = 15): int {
    try {
        $stmt = getDB()->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND type = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)");
        $stmt->execute([$ip, $type, $minutes]);
        return (int) $stmt->fetchColumn();
    } catch (\Throwable $e) {
        return 0;
    }
}
function clearLoginFails(string $ip, string $type): void {
    try {
        getDB()->prepare("DELETE FROM login_attempts WHERE ip = ? AND type = ?")->execute([$ip, $type]);
    } catch (\Throwable $e) {}
}

// ── Trusted Devices (Browser 30 Tage merken) ────────────────────
function trustCookieName(): string { return 'rd_trust'; }

function cleanupExpiredTrustedDevices(): void {
    try { getDB()->exec("DELETE FROM trusted_devices WHERE expires_at < NOW()"); } catch (\Throwable $e) {}
}
function trustedDeviceValid(int $techId): bool {
    if ($techId <= 0) return false;
    $raw = $_COOKIE[trustCookieName()] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $raw)) return false;
    try {
        $db   = getDB();
        $hash = hash('sha256', $raw);
        $stmt = $db->prepare("SELECT id FROM trusted_devices WHERE technician_id = ? AND token_hash = ? AND expires_at > NOW() LIMIT 1");
        $stmt->execute([$techId, $hash]);
        $id = $stmt->fetchColumn();
        if ($id) {
            $db->prepare("UPDATE trusted_devices SET last_used_at = NOW() WHERE id = ?")->execute([$id]);
            return true;
        }
    } catch (\Throwable $e) {}
    return false;
}
function createTrustedDevice(int $techId, int $days = 30): void {
    if ($techId <= 0) return;
    try {
        $raw  = bin2hex(random_bytes(32)); // 64 Hex-Zeichen
        $hash = hash('sha256', $raw);
        $ua   = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
        $exp  = date('Y-m-d H:i:s', time() + $days * 86400);
        getDB()->prepare("INSERT INTO trusted_devices (technician_id, token_hash, user_agent, ip, expires_at, last_used_at) VALUES (?, ?, ?, ?, ?, NOW())")
               ->execute([$techId, $hash, $ua, $ip, $exp]);
        setcookie(trustCookieName(), $raw, [
            'expires'  => time() + $days * 86400,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } catch (\Throwable $e) {}
}
function clearAllTrustedDevices(): void {
    try { getDB()->exec("DELETE FROM trusted_devices"); } catch (\Throwable $e) {}
}
function countTrustedDevices(): int {
    try { return (int) getDB()->query("SELECT COUNT(*) FROM trusted_devices WHERE expires_at > NOW()")->fetchColumn(); }
    catch (\Throwable $e) { return 0; }
}

// ── Backup (reines PHP/PDO) ─────────────────────────────────────
function backupDir(): string {
    $dir = dirname(__DIR__) . '/backups';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (is_dir($dir) && !file_exists($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return $dir;
}
function createDatabaseBackup(): array {
    $dir  = backupDir();
    $name = 'db_backup_' . date('Ymd_His') . '.sql';
    $file = $dir . '/' . $name;
    try {
        $pdo  = getDB();
        $sql  = "-- RepairDesk DB-Backup " . date('Y-m-d H:i:s') . "\n";
        $sql .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $create = $pdo->query("SHOW CREATE TABLE `{$t}`")->fetch();
            $sql .= "DROP TABLE IF EXISTS `{$t}`;\n" . $create['Create Table'] . ";\n\n";
            $rows = $pdo->query("SELECT * FROM `{$t}`")->fetchAll();
            foreach ($rows as $row) {
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($row));
                $sql .= "INSERT INTO `{$t}` VALUES (" . implode(',', $vals) . ");\n";
            }
            $sql .= "\n";
        }
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        if (file_put_contents($file, $sql) === false) {
            return ['ok' => false, 'error' => 'Datei konnte nicht geschrieben werden (Schreibrechte des Ordners /backups pruefen).'];
        }
        return ['ok' => true, 'name' => $name, 'size' => filesize($file)];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
function listBackups(): array {
    $out = [];
    foreach (glob(backupDir() . '/*.sql') ?: [] as $f) {
        $out[] = ['name' => basename($f), 'size' => (int) filesize($f), 'mtime' => (int) filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}
function safeBackupPath(string $name): ?string {
    $name = basename($name);
    if (!preg_match('/^[A-Za-z0-9._-]+\.sql$/', $name)) return null;
    $base = realpath(backupDir());
    $real = realpath(backupDir() . '/' . $name);
    if ($real === false || $base === false || strpos($real, $base) !== 0) return null;
    return $real;
}
function deleteBackup(string $name): bool {
    $path = safeBackupPath($name);
    return ($path && is_file($path)) ? @unlink($path) : false;
}
function formatBytes(int $bytes): string {
    if ($bytes < 1024)    return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 2) . ' MB';
}
