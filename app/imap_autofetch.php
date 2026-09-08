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
// Automatischer E-Mail-Abruf aus dem eingeloggten Bereich
//
// Wird von includes/header.php per JavaScript im Hintergrund
// aufgerufen. Antwortet immer mit JSON und rendert keine Seite.
//
// Zwei Betriebsarten:
//   ?force=1  → sofortiger Abruf direkt nach dem Login (einmalig,
//               nur gültig wenn die Session das Login-Flag trägt)
//   sonst     → regulärer Abruf, gedrosselt über imap_fetch_interval
//
// Ein Cronjob ist damit nur noch nötig, wenn E-Mails auch dann
// abgeholt werden sollen, wenn niemand angemeldet ist.
// ================================================================

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** Kurz und einheitlich antworten */
function autofetchReply(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Nur für angemeldete Mitarbeiter ─────────────────────────────
// Bewusst kein requireLogin(): das würde auf die Login-Seite
// umleiten und der JS-Aufruf bekäme HTML statt JSON.
if (!isLoggedIn()) {
    http_response_code(403);
    autofetchReply(['ok' => false, 'error' => 'Nicht angemeldet.']);
}

// ── Login-Abruf: Flag prüfen und sofort verbrauchen ─────────────
// Das Flag wird in login.php gesetzt. Es wird auch dann entfernt,
// wenn der Abruf danach nicht läuft – sonst würde jeder weitere
// Seitenaufruf erneut einen erzwungenen Abruf auslösen.
$force = false;
if (isset($_GET['force']) && $_GET['force'] === '1') {
    $force = !empty($_SESSION['imap_fetch_pending']);
    unset($_SESSION['imap_fetch_pending']);
}

// ── Ist der Empfang überhaupt eingeschaltet? ────────────────────
if (getSetting('imap_enabled', '0') !== '1') {
    autofetchReply(['ok' => true, 'ran' => false, 'reason' => 'empfang_aus']);
}

if ($force) {
    if (getSetting('imap_fetch_on_login', '1') !== '1') {
        autofetchReply(['ok' => true, 'ran' => false, 'reason' => 'login_abruf_aus']);
    }
} else {
    if (getSetting('imap_autofetch_enabled', '1') !== '1') {
        autofetchReply(['ok' => true, 'ran' => false, 'reason' => 'hintergrund_aus']);
    }
}

// ── Drosselung ──────────────────────────────────────────────────
// Ohne sie würde jeder Seitenaufruf jedes Mitarbeiters einen
// IMAP-Login auslösen. Der Login-Abruf umgeht die Drosselung.
$interval = (int)getSetting('imap_fetch_interval', '300');
if ($interval < 60)   $interval = 60;
if ($interval > 3600) $interval = 3600;

$last = (int)getSetting('imap_last_fetch', '0');
$now  = time();

if (!$force && ($now - $last) < $interval) {
    autofetchReply([
        'ok'      => true,
        'ran'     => false,
        'reason'  => 'gedrosselt',
        'next_in' => $interval - ($now - $last),
    ]);
}

// Zeitstempel VOR dem Abruf setzen: verhindert, dass zwei gleichzeitig
// geöffnete Browser-Tabs beide einen Abruf starten.
saveSetting('imap_last_fetch', (string)$now);

// ── Abruf ───────────────────────────────────────────────────────
// Der Browser wartet nicht auf das Ergebnis, wenn der Nutzer
// weiterklickt – der Abruf soll trotzdem zu Ende laufen.
@ignore_user_abort(true);
@set_time_limit(120);

require_once __DIR__ . '/../includes/mail_inbox.php';

try {
    $r = fetchInboxMails();
} catch (Throwable $e) {
    error_log('Autofetch-Fehler: ' . $e->getMessage());
    http_response_code(500);
    autofetchReply(['ok' => false, 'ran' => true, 'error' => 'Abruf fehlgeschlagen.']);
}

if (!$r['ok']) {
    autofetchReply(['ok' => false, 'ran' => true, 'error' => $r['error']]);
}

autofetchReply([
    'ok'        => true,
    'ran'       => true,
    'forced'    => $force,
    'new'       => (int)($r['processed'] ?? 0),
    'attention' => (int)($r['attention'] ?? 0),
    'summary'   => mailFetchSummary($r),
]);
