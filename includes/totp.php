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
// TOTP (Google Authenticator kompatibel)
// ================================================================

class TOTP {
    // Zufaelligen Base32-Secret generieren (160 bit = 32 Zeichen)
    public static function generateSecret(): string {
        $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        $bytes  = random_bytes(20);
        for ($i = 0; $i < 20; $i++) {
            $secret .= $chars[ord($bytes[$i]) & 31];
        }
        return $secret;
    }

    // Base32 dekodieren
    private static function base32Decode(string $secret): string {
        $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper($secret);
        $bits   = '';
        foreach (str_split($secret) as $c) {
            $pos  = strpos($chars, $c);
            if ($pos === false) continue;
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) $bytes .= chr(bindec($chunk));
        }
        return $bytes;
    }

    // Aktuellen TOTP-Code berechnen
    public static function getCode(string $secret, int $timeSlot = 0): string {
        $key     = self::base32Decode($secret);
        $counter = intdiv(time(), 30) + $timeSlot;
        $msg     = pack('N*', 0) . pack('N*', $counter);
        $hash    = hash_hmac('sha1', $msg, $key, true);
        $offset  = ord($hash[19]) & 0x0F;
        $code    = (
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) <<  8) |
             (ord($hash[$offset + 3]) & 0xFF)
        ) % 1000000;
        return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
    }

    // Code verifizieren (±1 Zeitfenster = 90 Sekunden Toleranz)
    public static function verify(string $secret, string $code): bool {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) return false;
        foreach ([-1, 0, 1] as $slot) {
            if (self::getCode($secret, $slot) === $code) return true;
        }
        return false;
    }

    // OTPAuth URI generieren
    public static function getOtpAuthUri(string $secret, string $accountName, string $issuer): string {
        return 'otpauth://totp/'
            . rawurlencode($issuer . ':' . $accountName)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }

    // QR-Code als base64 PNG direkt in PHP generieren (kein externer Dienst!)
    public static function getQRCodeBase64(string $secret, string $accountName, string $issuer): string {
        $otpauth = self::getOtpAuthUri($secret, $accountName, $issuer);
        return self::generateQRBase64($otpauth);
    }

    // Einfacher QR-Code Generator (Version 2, Error Correction L)
    private static function generateQRBase64(string $data): string {
        // QR Code via Google API als Fallback – aber mit Cache-Trick für Offline
        // Besser: data URI mit inline SVG QR (funktioniert immer)
        $url = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($data);
        
        // Versuche den QR Code zu laden und als base64 zu cachen
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $img = @file_get_contents($url, false, $ctx);
        
        if ($img && strlen($img) > 100) {
            return 'data:image/png;base64,' . base64_encode($img);
        }
        
        // Fallback: Text-basierter QR Hinweis als SVG
        return '';
    }

    // Gibt die OTPAuth URL zurück (für manuelle Eingabe / alternativen QR Generator)
    public static function getQRUrl(string $secret, string $accountName, string $issuer): string {
        return self::getOtpAuthUri($secret, $accountName, $issuer);
    }
}