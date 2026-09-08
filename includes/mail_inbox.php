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
// E-Mail-Empfang (IMAP) – Kunden können direkt auf Ticket-Mails
// antworten. Antworten werden als Kommentar gespeichert,
// ZUSTIMMEN / ABLEHNEN wird als KVA-Entscheidung erkannt.
//
// Kein PHP-IMAP-Modul nötig – reiner Socket-Client.
// ================================================================

// ── Tabelle für Deduplizierung sicherstellen ────────────────────
function ensureMailInboxTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS `mail_inbox_log` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `message_id`   VARCHAR(255) NOT NULL,
        `from_email`   VARCHAR(255) DEFAULT NULL,
        `subject`      VARCHAR(255) DEFAULT NULL,
        `ticket_id`    INT DEFAULT NULL,
        `result`       VARCHAR(50)  DEFAULT NULL,
        `processed_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_msgid` (`message_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

// ================================================================
// Minimaler IMAP-Client (Sockets)
// ================================================================
class SimpleImap {
    private $socket;
    private int $tagNum = 0;
    public string $lastError = '';
    /** Wird beim SELECT gefüllt – nötig für den UID-Hochwasserstand */
    public int $uidValidity = 0;
    public int $uidNext     = 0;

    public function connect(string $host, int $port, string $secure): bool {
        $prefix = ($secure === 'ssl') ? 'ssl://' : '';
        $this->socket = @fsockopen($prefix . $host, $port, $errno, $errstr, 20);
        if (!$this->socket) {
            $this->lastError = "Verbindung fehlgeschlagen: {$errstr} ({$errno})";
            return false;
        }
        stream_set_timeout($this->socket, 30);
        $greeting = fgets($this->socket, 1024);
        if (strpos($greeting, '* OK') !== 0 && strpos($greeting, '* PREAUTH') !== 0) {
            $this->lastError = 'Server-Begrüßung ungültig: ' . trim((string)$greeting);
            return false;
        }
        // STARTTLS falls gewünscht
        if ($secure === 'tls') {
            $res = $this->command('STARTTLS');
            if (!$res['ok']) { $this->lastError = 'STARTTLS abgelehnt'; return false; }
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $this->lastError = 'TLS-Handshake fehlgeschlagen';
                return false;
            }
        }
        return true;
    }

    public function login(string $user, string $pass): bool {
        $res = $this->command('LOGIN ' . $this->quoteString($user) . ' ' . $this->quoteString($pass));
        if (!$res['ok']) { $this->lastError = 'Login fehlgeschlagen (Benutzer/Passwort prüfen)'; }
        return $res['ok'];
    }

    /** Anmeldung per OAuth2 (XOAUTH2) – für Office 365 Modern Authentication */
    public function loginOAuth2(string $user, string $token): bool {
        $tag  = $this->nextTag();
        $auth = base64_encode("user=" . $user . "\x01auth=Bearer " . $token . "\x01\x01");

        fputs($this->socket, "{$tag} AUTHENTICATE XOAUTH2\r\n");
        $line = fgets($this->socket, 8192);

        if ($line === false) { $this->lastError = 'Keine Antwort auf XOAUTH2'; return false; }

        if (strpos($line, '+') === 0) {
            // Server erwartet die Anmeldedaten
            fputs($this->socket, $auth . "\r\n");
        } elseif (strpos($line, $tag . ' OK') === 0) {
            return true; // (unüblich, aber möglich)
        } else {
            $this->lastError = 'XOAUTH2 nicht unterstützt: ' . trim($line);
            return false;
        }

        // Antwort lesen; bei Fehler kommt eine '+'-Challenge → leere Zeile senden
        while (($line = fgets($this->socket, 8192)) !== false) {
            if (strpos($line, '+') === 0) {
                fputs($this->socket, "\r\n");
                continue;
            }
            if (strpos($line, $tag . ' ') === 0) {
                if (strpos($line, $tag . ' OK') === 0) return true;
                $this->lastError = 'OAuth2-Anmeldung abgelehnt (App-Berechtigung/Postfachzugriff prüfen)';
                return false;
            }
        }
        $this->lastError = 'Keine gültige Antwort auf XOAUTH2';
        return false;
    }

    public function selectFolder(string $folder): bool {
        $this->uidValidity = 0;
        $this->uidNext     = 0;

        $res = $this->command('SELECT ' . $this->quoteString($folder));
        if (!$res['ok']) {
            // Hinweis: "INBOX" ist ein reservierter IMAP-Sondername (RFC 3501) und
            // heißt auch bei deutschsprachigen Postfächern NIE "Posteingang".
            $this->lastError = "Ordner '{$folder}' nicht gefunden"
                . (strcasecmp($folder, 'INBOX') !== 0 ? " – für den Posteingang bitte INBOX eintragen." : '');
            return false;
        }

        // Aus den untagged-Antworten: * OK [UIDVALIDITY 1234] / * OK [UIDNEXT 5678]
        foreach ($res['lines'] as $line) {
            if (preg_match('/\[UIDVALIDITY\s+(\d+)\]/i', $line, $m)) $this->uidValidity = (int)$m[1];
            if (preg_match('/\[UIDNEXT\s+(\d+)\]/i',     $line, $m)) $this->uidNext     = (int)$m[1];
        }
        return true;
    }

    /** @return int[] UIDs ungelesener Mails (nur noch für den Erstlauf nach dem Update) */
    public function searchUnseen(): array {
        return $this->parseSearchResult($this->command('UID SEARCH UNSEEN'));
    }

    /**
     * UIDs aller Mails NEUER als $lastUid – unabhängig vom Gelesen-Status.
     * Hinweis: IMAP liefert bei "x:*" immer mindestens die höchste vorhandene
     * Mail zurück, auch wenn deren UID kleiner als x ist → hart nachfiltern.
     * @return int[] aufsteigend sortiert
     */
    public function searchNewerThan(int $lastUid): array {
        $from = $lastUid + 1;
        $uids = $this->parseSearchResult($this->command("UID SEARCH UID {$from}:*"));
        $uids = array_values(array_filter($uids, function ($u) use ($lastUid) { return $u > $lastUid; }));
        sort($uids, SORT_NUMERIC);
        return $uids;
    }

    /** @return int[] */
    private function parseSearchResult(array $res): array {
        if (!$res['ok']) return [];
        foreach ($res['lines'] as $line) {
            if (preg_match('/^\* SEARCH ?(.*)$/i', trim($line), $m)) {
                $ids = trim($m[1]);
                if ($ids === '') return [];
                return array_map('intval', preg_split('/\s+/', $ids));
            }
        }
        return [];
    }

    /** Komplette Roh-Mail (Header+Body) einer UID holen, ohne sie als gelesen zu markieren */
    public function fetchMessage(int $uid): ?string {
        $tag = $this->nextTag();
        fputs($this->socket, "{$tag} UID FETCH {$uid} (BODY.PEEK[])\r\n");

        $raw = null;
        while (($line = fgets($this->socket, 8192)) !== false) {
            // Literal-Ankündigung: ... {12345}
            if ($raw === null && preg_match('/\{(\d+)\}\s*$/', $line, $m)) {
                $size = (int)$m[1];
                $raw  = '';
                while (strlen($raw) < $size) {
                    $chunk = fread($this->socket, min(8192, $size - strlen($raw)));
                    if ($chunk === false || $chunk === '') break;
                    $raw .= $chunk;
                }
                continue;
            }
            if (strpos($line, $tag . ' ') === 0) {
                return (strpos($line, $tag . ' OK') === 0) ? $raw : null;
            }
        }
        return null;
    }

    public function markSeen(int $uid): void {
        $this->command("UID STORE {$uid} +FLAGS (\\Seen)");
    }

    public function logout(): void {
        if ($this->socket) {
            $this->command('LOGOUT');
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function nextTag(): string {
        return 'A' . str_pad((string)(++$this->tagNum), 4, '0', STR_PAD_LEFT);
    }

    private function quoteString(string $s): string {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    /** Einfaches Kommando (ohne Literale in der Antwort) */
    private function command(string $cmd): array {
        $tag = $this->nextTag();
        fputs($this->socket, "{$tag} {$cmd}\r\n");
        $lines = [];
        while (($line = fgets($this->socket, 8192)) !== false) {
            if (strpos($line, $tag . ' ') === 0) {
                return ['ok' => strpos($line, $tag . ' OK') === 0, 'lines' => $lines, 'status' => trim($line)];
            }
            $lines[] = $line;
        }
        return ['ok' => false, 'lines' => $lines, 'status' => ''];
    }
}

// ================================================================
// MIME-Parsing
// ================================================================

/** MIME-kodierte Header (=?UTF-8?B?...?=) dekodieren */
function mailDecodeHeader(string $value): string {
    if (function_exists('iconv_mime_decode')) {
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($decoded !== false) return $decoded;
    }
    return $value;
}

/** Header-Block in [name => value] parsen (Fortsetzungszeilen werden zusammengeführt) */
function mailParseHeaders(string $headerBlock): array {
    $headers = [];
    $lines   = preg_split('/\r?\n/', $headerBlock);
    $current = '';
    foreach ($lines as $line) {
        if ($line === '') continue;
        if ($line[0] === ' ' || $line[0] === "\t") {
            if ($current !== '') $headers[$current] .= ' ' . trim($line);
            continue;
        }
        $pos = strpos($line, ':');
        if ($pos === false) continue;
        $current = strtolower(substr($line, 0, $pos));
        $value   = trim(substr($line, $pos + 1));
        $headers[$current] = isset($headers[$current]) ? $headers[$current] . ' ' . $value : $value;
    }
    return $headers;
}

/** Transfer-Encoding + Zeichensatz dekodieren → UTF-8 */
function mailDecodeBody(string $body, string $encoding, string $charset): string {
    $encoding = strtolower(trim($encoding));
    if ($encoding === 'base64') {
        $body = base64_decode($body) ?: '';
    } elseif ($encoding === 'quoted-printable') {
        $body = quoted_printable_decode($body);
    }
    $charset = strtoupper(trim($charset)) ?: 'UTF-8';
    if ($charset !== 'UTF-8' && $charset !== 'US-ASCII') {
        $converted = @mb_convert_encoding($body, 'UTF-8', $charset);
        if ($converted !== false && $converted !== '') $body = $converted;
    }
    return $body;
}

/**
 * Roh-Mail parsen.
 * @return array{headers: array, text: string}
 */
function mailParseMessage(string $raw): array {
    $split = preg_split('/\r?\n\r?\n/', $raw, 2);
    $headers = mailParseHeaders($split[0] ?? '');
    $body    = $split[1] ?? '';

    $text = mailExtractText($headers, $body);
    return ['headers' => $headers, 'text' => $text];
}

/** Rekursiv den besten Text-Teil aus einer (Multipart-)Mail extrahieren */
function mailExtractText(array $headers, string $body): string {
    $contentType = $headers['content-type'] ?? 'text/plain';
    $encoding    = $headers['content-transfer-encoding'] ?? '7bit';

    // Charset ermitteln
    $charset = 'UTF-8';
    if (preg_match('/charset\s*=\s*"?([A-Za-z0-9._-]+)"?/i', $contentType, $m)) {
        $charset = $m[1];
    }

    // ── Multipart: rekursiv Teile durchsuchen ──
    if (stripos($contentType, 'multipart/') !== false
        && preg_match('/boundary\s*=\s*"?([^";]+)"?/i', $contentType, $m)) {

        $boundary = $m[1];
        $parts    = explode('--' . $boundary, $body);
        $plain = ''; $html = '';

        foreach ($parts as $part) {
            $part = ltrim($part, "\r\n");
            if ($part === '' || strpos($part, '--') === 0) continue;
            $sub = preg_split('/\r?\n\r?\n/', $part, 2);
            $subHeaders = mailParseHeaders($sub[0] ?? '');
            $subBody    = $sub[1] ?? '';
            $subType    = strtolower($subHeaders['content-type'] ?? 'text/plain');

            if (strpos($subType, 'multipart/') !== false) {
                $nested = mailExtractText($subHeaders, $subBody);
                if ($nested !== '' && $plain === '') $plain = $nested;
            } elseif (strpos($subType, 'text/plain') !== false && $plain === '') {
                $plain = mailExtractText($subHeaders, $subBody);
            } elseif (strpos($subType, 'text/html') !== false && $html === '') {
                $html = mailExtractText($subHeaders, $subBody);
            }
        }
        if ($plain !== '') return $plain;
        if ($html !== '')  return trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html))));
        return '';
    }

    // ── Einfacher Teil ──
    $decoded = mailDecodeBody($body, $encoding, $charset);
    if (stripos($contentType, 'text/html') !== false) {
        $decoded = html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $decoded)));
    }
    return trim($decoded);
}

/** E-Mail-Adresse aus "Name <mail@x.de>" extrahieren */
function mailExtractAddress(string $from): string {
    if (preg_match('/<([^>]+)>/', $from, $m)) return strtolower(trim($m[1]));
    return strtolower(trim($from));
}

// ================================================================
// Antwort-Verarbeitung
// ================================================================

/** Zitierten Alt-Text und Signatur aus einer Antwort entfernen */
function mailStripQuotedText(string $text): string {
    $lines = preg_split('/\r?\n/', $text);
    $clean = [];
    foreach ($lines as $line) {
        $trim = trim($line);
        // Ab hier beginnt der zitierte Original-Text → abschneiden
        if (preg_match('/^>/', $trim)) break;
        if (preg_match('/^Am\s.+schrieb.*:?\s*$/iu', $trim)) break;
        if (preg_match('/^On\s.+wrote:?\s*$/i', $trim)) break;
        if (preg_match('/^-{2,}\s*(Ursprüngliche Nachricht|Original Message|Originalnachricht)\s*-{2,}/iu', $trim)) break;
        if (preg_match('/^(Von|From|Gesendet|Sent|An|To|Betreff|Subject):\s/iu', $trim)) break;
        if ($trim === '--') break; // Signatur-Trenner
        $clean[] = $line;
    }
    return trim(implode("\n", $clean));
}

/**
 * Erkennen, ob die Antwort eine KVA-Entscheidung ist.
 * @return string 'accepted' | 'declined' | '' (keine Entscheidung)
 */
function mailDetectDecision(string $text): string {
    // Nur die erste inhaltliche Zeile bewerten
    $firstLine = '';
    foreach (preg_split('/\r?\n/', $text) as $line) {
        $line = trim($line);
        if ($line !== '') { $firstLine = $line; break; }
    }
    if ($firstLine === '') return '';

    $norm = mb_strtolower($firstLine);
    $norm = preg_replace('/[^\p{L}\p{N}\s]/u', '', $norm); // Satzzeichen weg
    $norm = trim($norm);

    // Ablehnung zuerst prüfen ("nicht einverstanden" enthält "einverstanden")
    if (preg_match('/^(ablehnen|abgelehnt|ablehnung|nein|storno|stornieren|nicht\s+einverstanden|kein\s+interesse)\b/u', $norm)) {
        return 'declined';
    }
    if (preg_match('/^(zustimmen|zugestimmt|zustimmung|ja|ok|okay|einverstanden|akzeptieren|akzeptiert|angenommen|passt|bestätigt|bestaetigt)\b/u', $norm)) {
        return 'accepted';
    }
    return '';
}

/**
 * KVA-Entscheidung anwenden (gleiche Logik wie im Kundenportal).
 * @return bool true wenn ein offener KVA vorhanden war und beantwortet wurde
 */
function applyPriceDecision(PDO $db, array $ticket, string $customerName, string $response, string $source = 'E-Mail'): bool {
    // Neuesten offenen Kostenvoranschlag suchen
    $stmt = $db->prepare("SELECT id FROM ticket_comments
                          WHERE ticket_id = ? AND price_proposal IS NOT NULL
                            AND (customer_response IS NULL OR customer_response = '')
                          ORDER BY id DESC LIMIT 1");
    $stmt->execute([$ticket['id']]);
    $commentId = $stmt->fetchColumn();
    if (!$commentId) return false;

    $db->prepare("UPDATE ticket_comments SET customer_response = ? WHERE id = ? AND ticket_id = ?")
       ->execute([$response, $commentId, $ticket['id']]);

    if ($response === 'accepted') {
        $db->prepare("UPDATE tickets SET status = 'in_bearbeitung' WHERE id = ?")->execute([$ticket['id']]);
        $notiz = "Kunde hat Kostenvoranschlag AKZEPTIERT (per {$source}).";
    } else {
        $db->prepare("UPDATE tickets SET status = 'offen' WHERE id = ?")->execute([$ticket['id']]);
        $notiz = "Kunde hat Kostenvoranschlag ABGELEHNT (per {$source}).";
    }

    $db->prepare("INSERT INTO ticket_comments (ticket_id, author_type, author_name, message, is_internal) VALUES (?, 'customer', ?, ?, 1)")
       ->execute([$ticket['id'], $customerName, $notiz]);

    // Firma benachrichtigen
    $companyName  = getSetting('company_name',  defined('COMPANY_NAME')  ? COMPANY_NAME  : 'RepairDesk');
    $companyEmail = getSetting('company_email', defined('COMPANY_EMAIL') ? COMPANY_EMAIL : '');
    if (!empty($companyEmail)) {
        sendMail(
            $companyEmail,
            $companyName,
            "Entscheidung Ticket: " . $ticket['ticket_number'],
            "Der Kunde hat den Kostenvoranschlag per {$source} <b>" . ($response === 'accepted' ? 'AKZEPTIERT' : 'ABGELEHNT') . "</b>.<br><br>"
            . "Ticket: " . h($ticket['ticket_number']) . "<br>"
            . "<a href='" . BASE_URL . "/ticket_view.php?id=" . $ticket['id'] . "'>Zum Ticket</a>"
        );
    }
    return true;
}

/**
 * Eine eingehende Mail verarbeiten.
 * @return string Ergebnis-Code für das Log
 */
function processIncomingMail(PDO $db, array $headers, string $text): string {
    $subject   = mailDecodeHeader($headers['subject'] ?? '');
    $fromEmail = mailExtractAddress(mailDecodeHeader($headers['from'] ?? ''));

    // Automatische Mails (Abwesenheit, Bounces) ignorieren
    $autoSubmitted = strtolower($headers['auto-submitted'] ?? 'no');
    if ($autoSubmitted !== 'no' && $autoSubmitted !== '') return 'ignoriert_auto';
    if (isset($headers['x-autoreply']) || isset($headers['x-autorespond'])) return 'ignoriert_auto';

    // Ticketnummer aus dem Betreff lesen, z. B. "Re: Ticket-Aktualisierung: #RD-2026-0042"
    if (!preg_match('/#\s*([A-Za-z0-9]+-\d{4}-\d+)/', $subject, $m)) {
        return 'keine_ticketnummer';
    }
    $ticketNumber = $m[1];

    $stmt = $db->prepare("
        SELECT t.*,
               CONCAT(COALESCE(c.lastname,''), IF(c.firstname != '' AND c.lastname != '', ' ', ''), COALESCE(c.firstname,'')) as customer_name,
               c.email as customer_email
        FROM tickets t
        JOIN customers c ON t.customer_id = c.id
        WHERE t.ticket_number = ?
    ");
    $stmt->execute([$ticketNumber]);
    $ticket = $stmt->fetch();
    if (!$ticket) return 'ticket_nicht_gefunden';

    // Sicherheit: Absender muss der Kunde des Tickets sein
    if ($fromEmail === '' || strtolower(trim($ticket['customer_email'])) !== $fromEmail) {
        return 'absender_unbekannt';
    }

    $reply = mailStripQuotedText($text);
    if ($reply === '') return 'leere_antwort';
    if (mb_strlen($reply) > 5000) $reply = mb_substr($reply, 0, 5000) . "\n[... gekürzt]";

    // ── KVA-Entscheidung erkennen ──
    $decision = mailDetectDecision($reply);
    if ($decision !== '') {
        if (applyPriceDecision($db, $ticket, $ticket['customer_name'], $decision, 'E-Mail')) {
            // Bestätigung an den Kunden
            $confirmText = $decision === 'accepted'
                ? 'Sie haben den Kostenvoranschlag <b>akzeptiert</b>. Wir kümmern uns umgehend um Ihr Gerät.'
                : 'Sie haben den Kostenvoranschlag <b>abgelehnt</b>. Wir setzen uns mit Ihnen in Verbindung.';
            sendMail(
                $ticket['customer_email'],
                $ticket['customer_name'],
                "Bestätigung zu Ticket: #{$ticket['ticket_number']}",
                emailTemplate("Ihre Entscheidung wurde übernommen", "<p>Hallo " . h($ticket['customer_name']) . ",</p><p>{$confirmText}</p><p>Ticket: <strong>#" . h($ticket['ticket_number']) . "</strong></p>")
            );
            return 'kva_' . $decision;
        }
        // Kein offener KVA → als normale Nachricht speichern (Fallthrough)
    }

    // ── Als Kundennachricht speichern ──
    $db->prepare("INSERT INTO ticket_comments (ticket_id, author_type, author_name, message, is_internal) VALUES (?, 'customer', ?, ?, 0)")
       ->execute([$ticket['id'], $ticket['customer_name'], $reply]);

    // ── Firma benachrichtigen ──────────────────────────────────────
    // Bewusst OHNE den Nachrichtentext: die Kundenantwort liegt bereits als
    // E-Mail im Postfach. Diese Mail ist nur die Bestätigung, dass die
    // Antwort auch tatsächlich im Ticket gelandet ist – ohne sie bliebe ein
    // stiller Verarbeitungsausfall unbemerkt.
    $companyName  = getSetting('company_name',  defined('COMPANY_NAME')  ? COMPANY_NAME  : 'RepairDesk');
    $companyEmail = getSetting('company_email', defined('COMPANY_EMAIL') ? COMPANY_EMAIL : '');
    if (!empty($companyEmail)) {
        sendMail(
            $companyEmail,
            $companyName,
            "✓ Antwort erfasst: " . $ticket['ticket_number'],
            "Die E-Mail-Antwort von <b>" . h($ticket['customer_name']) . "</b> wurde in Ticket "
            . "<b>#" . h($ticket['ticket_number']) . "</b> gespeichert.<br><br>"
            . "<span style=\"color:#64748b;font-size:13px;\">Der Nachrichtentext steht im Ticket – "
            . "diese Mail bestätigt nur, dass die Verarbeitung geklappt hat.</span><br><br>"
            . "<a href='" . BASE_URL . "/ticket_view.php?id=" . $ticket['id'] . "'>Zum Ticket</a>"
        );
    }
    return 'kommentar';
}

// ================================================================
// Auswertung der Ergebnisse
// ================================================================

/** Ergebnisse, die eine echte Kundenreaktion darstellen (einzeln melden) */
function mailOutcomeIsRelevant(string $outcome): bool {
    return in_array($outcome, ['kommentar', 'kva_accepted', 'kva_declined'], true);
}

/** Ergebnisse, bei denen die Verarbeitung schiefgegangen ist */
function mailOutcomeIsError(string $outcome): bool {
    return in_array($outcome, ['fehler', 'abruf_fehler'], true);
}

/** Klartext für ein Verarbeitungsergebnis */
function mailOutcomeLabel(string $outcome): string {
    switch ($outcome) {
        case 'kommentar':     return 'Antwort im Ticket gespeichert';
        case 'kva_accepted':  return 'Kostenvoranschlag angenommen';
        case 'kva_declined':  return 'Kostenvoranschlag abgelehnt';
        default:              return $outcome;
    }
}

/**
 * Lesbare Zusammenfassung eines Abrufs – wird in den Einstellungen
 * und vom Cronjob verwendet.
 */
function mailFetchSummary(array $r): string {
    $flags = $r['flags'] ?? [];
    $parts = [];

    if (in_array('erstlauf_initialisiert', $flags, true)) {
        $parts[] = 'Empfang eingerichtet. Ab jetzt werden neue Antworten automatisch erkannt – '
                 . 'auch wenn die E-Mail vorher schon gelesen wurde.';
    } elseif (in_array('uidvalidity_geaendert', $flags, true)) {
        $parts[] = 'Postfach oder Ordner hat sich geändert – der Abruf wurde neu eingerichtet.';
    }

    if ((int)$r['processed'] > 0) {
        // Gleiche Ergebnisse bündeln: "2 × Antwort im Ticket gespeichert"
        $gebuendelt = [];
        foreach (array_count_values($r['details']) as $label => $anzahl) {
            $gebuendelt[] = ($anzahl > 1 ? $anzahl . ' × ' : '') . $label;
        }
        $parts[] = (int)$r['processed'] . ' Kundenantwort' . ($r['processed'] == 1 ? '' : 'en')
                 . ' übernommen (' . implode(', ', $gebuendelt) . ').';
    } else {
        $parts[] = 'Keine neuen Kundenantworten.';
    }

    if ((int)($r['errors'] ?? 0) > 0) {
        $parts[] = '⚠️ ' . (int)$r['errors'] . ' E-Mail' . ($r['errors'] == 1 ? '' : 's')
                 . ' konnte' . ($r['errors'] == 1 ? '' : 'n') . ' nicht verarbeitet werden '
                 . '(Details im Server-Fehlerprotokoll).';
    }

    if ((int)$r['attention'] > 0) {
        $parts[] = '⚠️ ' . (int)$r['attention'] . ' Antwort' . ($r['attention'] == 1 ? '' : 'en')
                 . ' mit Ticketnummer wurde' . ($r['attention'] == 1 ? '' : 'n')
                 . ' abgelehnt, weil die Absenderadresse nicht zum Kunden passt.';
    }

    if ((int)$r['skipped'] > 0) {
        $parts[] = (int)$r['skipped'] . ' E-Mail' . ($r['skipped'] == 1 ? '' : 's')
                 . ' ohne Ticketbezug übersprungen.';
    }

    if (in_array('weitere_mails_offen', $flags, true)) {
        $parts[] = 'Es sind noch weitere E-Mails offen – bitte den Abruf erneut starten.';
    }

    return implode(' ', $parts);
}

// ================================================================
// Haupt-Abruf: Postfach abrufen und alle neuen Mails verarbeiten
// ================================================================
function fetchInboxMails(): array {
    $result = [
        'ok'        => false,
        'processed' => 0,   // echte Kundenantworten (nur die interessieren)
        'details'   => [],  // Klartext-Beschreibung ebendieser Antworten
        'scanned'   => 0,   // insgesamt angesehene Mails
        'skipped'   => 0,   // ohne Ticketbezug (Newsletter, Fremdmails, ...)
        'attention' => 0,   // richtige Ticketnummer, aber fremder Absender
        'errors'    => 0,   // Mail konnte nicht gelesen/verarbeitet werden
        'flags'     => [],  // Zustandshinweise (Erstlauf, Ordnerwechsel, ...)
        'error'     => '',
    ];

    if (getSetting('imap_enabled', '0') !== '1') {
        $result['error'] = 'E-Mail-Empfang ist deaktiviert (Einstellungen → E-Mail).';
        return $result;
    }

    $host   = getSetting('imap_host', '');
    $port   = (int)getSetting('imap_port', '993');
    $secure = getSetting('imap_secure', 'ssl');
    $user   = getSetting('imap_user', '');
    $pass   = getSetting('imap_pass', '');
    $folder = getSetting('imap_folder', 'INBOX') ?: 'INBOX';
    $authMethod = getSetting('imap_auth_method', 'password');

    if ($host === '' || $user === '') {
        $result['error'] = 'IMAP-Zugangsdaten unvollständig (Server und Postfach-Adresse nötig).';
        return $result;
    }
    if ($authMethod !== 'oauth2_o365' && $pass === '') {
        $result['error'] = 'IMAP-Passwort fehlt.';
        return $result;
    }

    $db = getDB();
    ensureMailInboxTable($db);

    $imap = new SimpleImap();
    if (!$imap->connect($host, $port, $secure)) { $result['error'] = $imap->lastError; return $result; }

    if ($authMethod === 'oauth2_o365') {
        $token = getO365AccessToken();
        if ($token === null) { $result['error'] = o365TokenError(); $imap->logout(); return $result; }
        if (!$imap->loginOAuth2($user, $token)) { $result['error'] = $imap->lastError; $imap->logout(); return $result; }
    } else {
        if (!$imap->login($user, $pass)) { $result['error'] = $imap->lastError; $imap->logout(); return $result; }
    }

    if (!$imap->selectFolder($folder))          { $result['error'] = $imap->lastError; $imap->logout(); return $result; }

    // ================================================================
    // Welche Mails sind "neu"?
    //
    // NICHT mehr über den Gelesen-Status (UNSEEN): sobald jemand die
    // Mail im Outlook/Handy öffnet oder die Vorschau anzeigt, wäre sie
    // für den Abruf unsichtbar. Stattdessen ein UID-Hochwasserstand:
    // wir merken uns die höchste bereits verarbeitete UID und holen
    // alles, was danach kam – unabhängig von Flags.
    // ================================================================
    $storedValidity = (int)getSetting('imap_uidvalidity', '0');
    $lastUid        = (int)getSetting('imap_last_uid', '0');
    $currValidity   = $imap->uidValidity;

    // Postfach neu aufgebaut / anderer Ordner → alte UIDs sind wertlos
    if ($currValidity > 0 && $storedValidity > 0 && $currValidity !== $storedValidity) {
        $lastUid = 0;
        $result['flags'][] = 'uidvalidity_geaendert';
    }
    if ($currValidity > 0) {
        saveSetting('imap_uidvalidity', (string)$currValidity);
    }

    if ($lastUid > 0) {
        // Normalfall: alles neuer als der Hochwasserstand
        $uids = $imap->searchNewerThan($lastUid);
    } else {
        // Erstlauf nach dem Update (oder nach einem Reset):
        // Es wird NICHT das komplette Postfach-Archiv verarbeitet – das würde
        // jede alte Mail als Kommentar ins Ticket schreiben und Benachrichtigungen
        // auslösen. Stattdessen einmalig die noch UNGELESENEN Mails abarbeiten
        // (das sind genau die, die bisher noch nicht dran waren) und den
        // Hochwasserstand auf den aktuellen Stand setzen.
        $uids = $imap->searchUnseen();
        $result['flags'][] = 'erstlauf_initialisiert';
    }

    // Deckel gegen Timeouts bei großen Postfächern
    $maxPerRun = 50;
    $capped    = false;
    if (count($uids) > $maxPerRun) {
        $uids   = array_slice($uids, 0, $maxPerRun);
        $capped = true;
    }

    $highestUid = $lastUid;
    foreach ($uids as $uid) {
        // Der Hochwasserstand wandert immer mit – auch bei Fehlern oder
        // übersprungenen Mails. Sonst würde eine einzige defekte Mail den
        // Abruf dauerhaft blockieren. Der Dedup-Log fängt Doppler ab.
        if ($uid > $highestUid) $highestUid = $uid;

        $result['scanned']++;

        $raw = $imap->fetchMessage($uid);
        if ($raw === null) {
            $result['errors']++;
            continue;
        }

        $parsed    = mailParseMessage($raw);
        $headers   = $parsed['headers'];
        $messageId = trim($headers['message-id'] ?? '') ?: ('uid-' . $uid . '-' . md5($raw));
        $messageId = mb_substr($messageId, 0, 250);

        // Schon verarbeitet?
        $chk = $db->prepare("SELECT id FROM mail_inbox_log WHERE message_id = ?");
        $chk->execute([$messageId]);
        if ($chk->fetch()) { continue; }

        try {
            $outcome = processIncomingMail($db, $headers, $parsed['text']);
        } catch (Exception $e) {
            $outcome = 'fehler';
            error_log('Mail-Inbox Fehler: ' . $e->getMessage());
        }

        // Loggen (Dedup)
        $db->prepare("INSERT IGNORE INTO mail_inbox_log (message_id, from_email, subject, result) VALUES (?,?,?,?)")
           ->execute([
               $messageId,
               mb_substr(mailExtractAddress(mailDecodeHeader($headers['from'] ?? '')), 0, 250),
               mb_substr(mailDecodeHeader($headers['subject'] ?? ''), 0, 250),
               $outcome,
           ]);

        // Bewusst KEIN markSeen(): der Gelesen-Status gehört dem Postfach-Besitzer.
        // Gelesen wird ausschließlich per BODY.PEEK, die Flags bleiben unberührt.
        //
        // Ergebnis einsortieren: nur echte Kundenantworten werden einzeln
        // gemeldet. Alles ohne Ticketbezug (Newsletter, interne Mails, ...)
        // wird nur gezählt – im mail_inbox_log steht trotzdem jede Mail.
        if ($outcome === 'absender_unbekannt') {
            $result['attention']++;
        } elseif (mailOutcomeIsError($outcome)) {
            $result['errors']++;
        } elseif (mailOutcomeIsRelevant($outcome)) {
            $result['processed']++;
            $result['details'][] = mailOutcomeLabel($outcome);
        } else {
            $result['skipped']++;
        }
    }

    // ── Hochwasserstand sichern ─────────────────────────────────────
    $newWatermark = $highestUid;
    if ($lastUid <= 0 && $imap->uidNext > 1 && !$capped) {
        // Erstlauf: auf den aktuellen Postfach-Stand setzen, damit das
        // Archiv nicht nachträglich verarbeitet wird. Bei gedeckeltem Lauf
        // NICHT vorspulen – sonst gingen die restlichen Mails verloren.
        $newWatermark = max($newWatermark, $imap->uidNext - 1);
    }
    if ($newWatermark > $lastUid) {
        saveSetting('imap_last_uid', (string)$newWatermark);
    }

    if ($capped) {
        $result['flags'][] = 'weitere_mails_offen';
    }

    $imap->logout();
    $result['ok'] = true;
    return $result;
}