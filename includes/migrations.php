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
// RepairDesk – Datenbank-Migrationen
// ================================================================

function runMigrations(PDO $pdo, string $fromVersion, string $toVersion): string {
    $log = [];

    // Nur Versionen NACH $fromVersion und BIS $toVersion werden ausgeführt.

    $migrations = [

        '1.3.0' => function(PDO $pdo) use (&$log) {

            // mail_inbox_log: Deduplizierung für E-Mail-Empfang (Antworten per E-Mail)
            $tbl = $pdo->query("SHOW TABLES LIKE 'mail_inbox_log'")->fetchAll();
            if (empty($tbl)) {
                $pdo->exec("CREATE TABLE `mail_inbox_log` (
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
                $log[] = "mail_inbox_log Tabelle erstellt (E-Mail-Empfang)";
            }

            $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES ('app_version','1.3.0') ON DUPLICATE KEY UPDATE `value`='1.3.0'")->execute();
            $log[] = "app_version auf 1.3.0 gesetzt";
        },

        '1.1.0' => function(PDO $pdo) use (&$log) {

            // login_attempts sicherstellen (Bestandsinstallationen)
            $tbl = $pdo->query("SHOW TABLES LIKE 'login_attempts'")->fetchAll();
            if (empty($tbl)) {
                $pdo->exec("CREATE TABLE `login_attempts` (
                    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `ip`           VARCHAR(45)  NOT NULL,
                    `type`         VARCHAR(20)  NOT NULL DEFAULT 'password',
                    `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    INDEX `idx_ip_type_time` (`ip`, `type`, `attempted_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                $log[] = "login_attempts Tabelle erstellt";
            } else {
                $col = $pdo->query("SHOW COLUMNS FROM login_attempts LIKE 'type'")->fetchAll();
                if (empty($col)) {
                    $pdo->exec("ALTER TABLE login_attempts ADD COLUMN `type` VARCHAR(20) NOT NULL DEFAULT 'password' AFTER `ip`");
                    $log[] = "login_attempts.type Spalte hinzugefügt (2FA-Brute-Force)";
                }
            }

            // trusted_devices (Browser 30 Tage merken)
            $tbl2 = $pdo->query("SHOW TABLES LIKE 'trusted_devices'")->fetchAll();
            if (empty($tbl2)) {
                $pdo->exec("CREATE TABLE `trusted_devices` (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                $log[] = "trusted_devices Tabelle erstellt";
            }

            // Backup-Ordner anlegen + sperren
            $bdir = dirname(__DIR__) . '/backups';
            if (!is_dir($bdir)) { @mkdir($bdir, 0755, true); }
            if (is_dir($bdir) && !file_exists($bdir . '/.htaccess')) {
                @file_put_contents($bdir . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
                $log[] = "backups-Ordner angelegt + gesperrt";
            }

            $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES ('app_version','1.1.0') ON DUPLICATE KEY UPDATE `value`='1.1.0'")->execute();
            $log[] = "app_version auf 1.1.0 gesetzt";
        },

        '1.0.1-security' => function(PDO $pdo) use (&$log) {

            // login_attempts Tabelle für IP-basierten Brute-Force-Schutz
            $tables = $pdo->query("SHOW TABLES LIKE 'login_attempts'")->fetchAll();
            if (empty($tables)) {
                $pdo->exec("CREATE TABLE `login_attempts` (
                    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `ip`           VARCHAR(45)  NOT NULL,
                    `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    INDEX `idx_ip_time` (`ip`, `attempted_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                $log[] = "login_attempts Tabelle erstellt (Brute-Force-Schutz)";
            }
        },

        // ── Nächste Version hier eintragen ───────────────────────
        // '1.2.0' => function(PDO $pdo) use (&$log) {
        //     $pdo->exec("ALTER TABLE customers ADD COLUMN notes TEXT DEFAULT NULL");
        //     $log[] = "customers.notes Spalte hinzugefügt";
        // },

    ];

    // ── Migrationen ausführen ─────────────────────────────────────
    $executed = 0;
    foreach ($migrations as $version => $migrate) {
        // Nur ausführen wenn Version > fromVersion und <= toVersion
        if (version_compare($version, $fromVersion, '>') && version_compare($version, $toVersion, '<=')) {
            try {
                $migrate($pdo);
                $executed++;
                $log[] = "Migration v{$version} ausgeführt";
            } catch (Exception $e) {
                throw new Exception("Migration v{$version} fehlgeschlagen: " . $e->getMessage());
            }
        }
    }

    if ($executed === 0) {
        return 'keine Migrationen nötig';
    }

    return implode(', ', $log);
}