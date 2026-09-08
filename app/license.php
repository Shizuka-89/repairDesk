<?php
// ================================================================
// RepairDesk – Lizenz & Updates
// ================================================================
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if (!isAdmin()) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// ── Konfiguration ────────────────────────────────────────────────
define('CURRENT_VERSION',    '1.4.0');
define('UPDATE_CHECK_URL',   'https://ticket.norix.at/Updates/update_check.php');
define('UPDATE_REQUEST_URL', 'https://ticket.norix.at/Updates/update_request.php');
define('VALIDATE_KEY_URL',   'https://ticket.norix.at/Updates/validate_key.php');
define('UPGRADE_URL',        'https://norix.at/repairdesk/#preise');

define('PROTECTED_FILES', serialize([
    'config.php',
]));

$pdo = getDB();

$licenseKey  = getSetting('license_key')         ?? '';
$licenseType = getSetting('license_type')        ?? '';
$aboStatus   = getSetting('license_abo_status')  ?? '';
$validUntil  = getSetting('license_valid_until') ?? '';
$maxVersion  = getSetting('license_max_version') ?? '';

$message    = '';
$msgType    = 'info';
$updateInfo = null;
$updateLog  = [];

// ── 24h Cache-Validierung ─────────────────────────────────────────
$lastValidated = getSetting('license_last_validated') ?? '';
$cacheExpired  = !$lastValidated || (time() - strtotime($lastValidated)) > 86400;

if ($licenseKey && $cacheExpired) {
    $data = httpPost(VALIDATE_KEY_URL, ['key' => $licenseKey, 'domain' => $_SERVER['HTTP_HOST']], 5);
    if ($data !== null) {
        if (!empty($data['valid'])) {
            // Key gültig – Status aktualisieren
            $aboStatus   = $data['abo_status']  ?? '';
            $validUntil  = $data['valid_until'] ?? '';
            $maxVersion  = $data['max_version'] ?? '';
            $licenseType = $data['license_type'] ?? $licenseType;
            $reValidSaves = [
                'license_type'           => $licenseType,
                'license_abo_status'     => $aboStatus,
                'license_valid_until'    => $validUntil,
                'license_max_version'    => $maxVersion,
                'license_last_validated' => date('Y-m-d H:i:s'),
            ];
        } else {
            // Key ungültig/gelöscht – alles zurücksetzen
            $aboStatus   = '';
            $validUntil  = '';
            $maxVersion  = '';
            $licenseType = '';
            $licenseKey  = '';
            $reValidSaves = [
                'license_key'            => '',
                'license_type'           => '',
                'license_abo_status'     => '',
                'license_valid_until'    => '',
                'license_max_version'    => '',
                'license_last_validated' => date('Y-m-d H:i:s'),
            ];
        }
        foreach ($reValidSaves as $k => $v) {
            $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")
                ->execute([$k, $v, $v]);
        }
    }
}

// ── Hilfsfunktion: HTTP POST ──────────────────────────────────────
function httpPost(string $url, array $data, int $timeout = 15): ?array {
    $resp = @file_get_contents($url, false, stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($data),
            'timeout' => $timeout,
        ]
    ]));
    if ($resp === false) return null;
    return json_decode($resp, true) ?: null;
}

// ── Key speichern + Typ automatisch vom Server holen ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_key'])) {
    $newKey = strtoupper(trim($_POST['license_key'] ?? ''));

    if (!preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $newKey)) {
        $message = 'Ungültiges Key-Format. Erwartet: XXXX-XXXX-XXXX-XXXX';
        $msgType = 'danger';
    } else {
        $data = httpPost(VALIDATE_KEY_URL, ['key' => $newKey, 'domain' => $_SERVER['HTTP_HOST']], 8);
        if ($data === null) {
            $err = error_get_last();
            $message = 'Verbindung zum Lizenzserver fehlgeschlagen: ' . ($err['message'] ?? 'unbekannt');
            $msgType = 'danger';
        } elseif (!empty($data['valid'])) {
            $saves = [
                'license_key'           => $newKey,
                'license_type'          => $data['license_type'],
                'license_abo_status'    => $data['abo_status']  ?? '',
                'license_valid_until'   => $data['valid_until'] ?? '',
                'license_max_version'   => $data['max_version'] ?? '',
                'license_last_validated'=> date('Y-m-d H:i:s'),
            ];
            foreach ($saves as $k => $v) {
                $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")
                    ->execute([$k, $v, $v]);
            }
            $licenseKey  = $newKey;
            $licenseType = $data['license_type'];
            $aboStatus   = $data['abo_status']  ?? '';
            $validUntil  = $data['valid_until'] ?? '';
            $maxVersion  = $data['max_version'] ?? '';
            $message = 'License Key erfolgreich aktiviert!';
            $msgType = 'success';
        } else {
            $message = $data['error'] ?? 'Ungültiger License Key.';
            $msgType = 'danger';
        }
    }
}

// ── Auto-Update durchführen ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_update'])) {
    $targetVersion = trim($_POST['target_version'] ?? '');

    if (!$licenseKey) {
        $message = 'Kein License Key hinterlegt.';
        $msgType = 'danger';
    } else {

        // 1. Token anfordern
        $tokenData = httpPost(UPDATE_REQUEST_URL, [
            'key'            => $licenseKey,
            'client_version' => CURRENT_VERSION,
            'version'        => $targetVersion,
        ]);

        if ($tokenData === null) {
            $message = 'Verbindung zum Update-Server fehlgeschlagen.';
            $msgType = 'danger';
        } elseif (empty($tokenData['token'])) {
            $reasons = [
                'subscription_expired'   => 'Dein Abo ist abgelaufen. Bitte verlängere es um weitere Updates zu erhalten.',
                'lifetime_limit_reached' => 'Diese Version ist nicht in deiner Lifetime-Lizenz enthalten.',
                'invalid_key'            => 'Der License Key ist ungültig.',
            ];
            $message = $reasons[$tokenData['reason'] ?? ''] ?? ($tokenData['error'] ?? 'Zugriff verweigert.');
            $msgType = 'warning';
        } else {
            // 2. ZIP direkt auf diesen Server laden (nicht zum Browser!)
            $token      = $tokenData['token'];
            $downloadUrl = 'https://ticket.norix.at/Updates/update_download.php?token=' . urlencode($token);
            $zipContent = @file_get_contents($downloadUrl, false,
                stream_context_create(['http' => ['timeout' => 60]])
            );

            if (!$zipContent || strlen($zipContent) < 1000) {
                $message = 'Download fehlgeschlagen oder Datei zu klein.';
                $msgType = 'danger';
            } else {

                // 3. ZIP temporär speichern
                $tmpZip = sys_get_temp_dir() . '/repairdesk_update_' . time() . '.zip';
                file_put_contents($tmpZip, $zipContent);

                $rootDir      = dirname(__DIR__); // Projektwurzel (license.php liegt in /app/)
                $backupDir    = $rootDir . '/backups/update_' . date('Ymd_His') . '/';
                $protectedFiles = unserialize(PROTECTED_FILES);
                $updateLog    = [];
                $errors       = [];

                $zip = new ZipArchive();
                if ($zip->open($tmpZip) !== true) {
                    $message = 'ZIP-Datei konnte nicht geöffnet werden.';
                    $msgType = 'danger';
                    unlink($tmpZip);
                } else {

                    // 4. Backup anlegen
                    @mkdir($backupDir, 0755, true);
                    $backedUp = 0;
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $name = $zip->getNameIndex($i);
                        if (substr($name, -1) === '/') continue; // Ordner überspringen
                        $localFile = $rootDir . '/' . $name;
                        if (file_exists($localFile)) {
                            $backupFile = $backupDir . $name;
                            @mkdir(dirname($backupFile), 0755, true);
                            copy($localFile, $backupFile);
                            $backedUp++;
                        }
                    }
                    $updateLog[] = "✅ Backup erstellt: {$backedUp} Dateien → backups/update_" . date('Ymd_His');

                    // DB-Backup via PHP (kein mysqldump nötig)
                    try {
                        $dbBackupFile = $backupDir . 'database_backup.sql';
                        $sql  = "-- RepairDesk DB-Backup " . date('Y-m-d H:i:s') . "\n";
                        $sql .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

                        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($tables as $table) {
                            $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
                            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
                            $sql .= $create['Create Table'] . ";\n\n";

                            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll();
                            foreach ($rows as $row) {
                                $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), $row);
                                $sql .= "INSERT INTO `{$table}` VALUES (" . implode(',', $vals) . ");\n";
                            }
                            $sql .= "\n";
                        }
                        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
                        file_put_contents($dbBackupFile, $sql);
                        $updateLog[] = "🗄️ DB-Backup erstellt → database_backup.sql";
                    } catch (Exception $e) {
                        $updateLog[] = "⚠️ DB-Backup fehlgeschlagen: " . $e->getMessage();
                    }

                    // 5. Dateien extrahieren + überschreiben
                    $updated  = 0;
                    $skipped  = 0;
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $name = $zip->getNameIndex($i);
                        if (substr($name, -1) === '/') continue;

                        // Geschützte Dateien überspringen
                        $basename = basename($name);
                        if (in_array($basename, $protectedFiles) || in_array($name, $protectedFiles)) {
                            $updateLog[] = "⏭️ Übersprungen (geschützt): {$name}";
                            $skipped++;
                            continue;
                        }

                        $targetFile = $rootDir . '/' . $name;
                        @mkdir(dirname($targetFile), 0755, true);

                        $content = $zip->getFromIndex($i);
                        if (file_put_contents($targetFile, $content) !== false) {
                            $updateLog[] = "📄 Aktualisiert: {$name}";
                            $updated++;
                        } else {
                            $updateLog[] = "❌ Fehler bei: {$name}";
                            $errors[] = $name;
                        }
                    }
                    $zip->close();
                    unlink($tmpZip);

                    $updateLog[] = "──────────────────────────────";
                    $updateLog[] = "📦 Gesamt: {$updated} aktualisiert, {$skipped} übersprungen" . (count($errors) ? ', ' . count($errors) . ' Fehler' : '');

                    // 6. DB-Migration ausführen
                    $migrationFile = $rootDir . '/includes/migrations.php';
                    if (file_exists($migrationFile)) {
                        try {
                            $currentDbVersion = getSetting('system_version') ?? '1.0.0';
                            require_once $migrationFile;
                            if (function_exists('runMigrations')) {
                                $migResult = runMigrations($pdo, $currentDbVersion, $targetVersion);
                                $updateLog[] = "🗄️ DB-Migration: " . ($migResult ?: 'keine Änderungen nötig');
                            }
                        } catch (Exception $e) {
                            $updateLog[] = "⚠️ DB-Migration Fehler: " . $e->getMessage();
                        }
                    } else {
                        $updateLog[] = "ℹ️ Keine DB-Migration vorhanden.";
                    }

                    // 7. Version in Settings aktualisieren
                    $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES ('system_version',?) ON DUPLICATE KEY UPDATE `value`=?")
                        ->execute([$targetVersion, $targetVersion]);

                    if (empty($errors)) {
                        $message = "Update auf v{$targetVersion} erfolgreich abgeschlossen!";
                        $msgType = 'success';
                    } else {
                        $message = "Update abgeschlossen, aber {$errors} Dateien konnten nicht geschrieben werden. Prüfe die Schreibrechte.";
                        $msgType = 'warning';
                    }
                }
            }
        }
    }
}

// ── Update-Check ─────────────────────────────────────────────────
if ($licenseKey) {
    $checkResp = @file_get_contents(
        UPDATE_CHECK_URL . '?version=' . urlencode(CURRENT_VERSION) . '&license_key=' . urlencode($licenseKey),
        false, stream_context_create(['http' => ['timeout' => 5]])
    );
    if ($checkResp) {
        $updateInfo = json_decode($checkResp, true);
    }
}

// ── Anzeige-Hilfswerte ───────────────────────────────────────────
$licenseLabels = [
    ''             => ['label' => 'Free',     'color' => '#64748b'],
    'subscription' => ['label' => 'Abo',      'color' => '#3b82f6'],
    'lifetime'     => ['label' => 'Lifetime', 'color' => '#22c55e'],
];
$ld = $licenseLabels[$licenseType] ?? $licenseLabels[''];

$activeMenu = 'license';
$pageTitle  = 'Lizenz & Updates';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>🔑 Lizenz & Updates</h1>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= h($msgType) ?>" style="margin-bottom:20px;">
        <?= h($message) ?>
        <?php if ($msgType === 'warning' && str_contains($message, 'Abo')): ?>
            <a href="<?= UPGRADE_URL ?>" target="_blank" class="btn btn-sm btn-primary" style="margin-left:10px;">Abo verlängern</a>
        <?php endif ?>
    </div>
<?php endif ?>

<!-- Update-Log -->
<?php if (!empty($updateLog)): ?>
<div class="card" style="margin-bottom:24px;">
    <div class="card-header">📋 Update-Protokoll</div>
    <div class="card-body">
        <div style="background:#0f172a;border-radius:8px;padding:16px;font-family:monospace;font-size:13px;max-height:300px;overflow-y:auto;">
            <?php foreach ($updateLog as $line): ?>
                <div style="color:<?= str_starts_with($line, '❌') ? '#ef4444' : (str_starts_with($line, '⚠️') ? '#f59e0b' : '#94a3b8') ?>;padding:2px 0;">
                    <?= h($line) ?>
                </div>
            <?php endforeach ?>
        </div>
        <?php if ($msgType === 'success'): ?>
            <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary" style="margin-top:12px;">
                🔄 Seite neu laden
            </a>
        <?php endif ?>
    </div>
</div>
<?php endif ?>

<!-- Aktuelle Lizenz -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header">Aktuelle Lizenz</div>
    <div class="card-body">
        <table class="table">
            <tr>
                <td style="width:220px;color:#94a3b8;">Programmversion</td>
                <td><strong><?= CURRENT_VERSION ?></strong></td>
            </tr>
            <tr>
                <td style="color:#94a3b8;">Lizenztyp</td>
                <td>
                    <span style="display:inline-block;padding:3px 12px;border-radius:12px;font-size:13px;font-weight:600;background:<?= $ld['color'] ?>22;color:<?= $ld['color'] ?>;">
                        <?= $ld['label'] ?>
                    </span>
                </td>
            </tr>
            <?php if ($licenseKey): ?>
            <tr>
                <td style="color:#94a3b8;">License Key</td>
                <td><code><?= h(substr($licenseKey,0,4)) ?>-****-****-<?= h(substr($licenseKey,-4)) ?></code></td>
            </tr>
            <?php endif ?>
            <?php if ($licenseType === 'subscription' && $aboStatus): ?>
            <tr>
                <td style="color:#94a3b8;">Abo-Status</td>
                <td>
                    <?php if ($aboStatus === 'active'): ?>
                        <span style="color:#22c55e;">✅ Aktiv</span>
                        <?= $validUntil ? '<span style="color:#94a3b8;font-size:13px;margin-left:8px;">gültig bis ' . h($validUntil) . '</span>' : '' ?>
                    <?php else: ?>
                        <span style="color:#ef4444;">❌ Abgelaufen</span>
                        <?= $maxVersion ? '<span style="color:#94a3b8;font-size:13px;margin-left:8px;">(eingefroren auf v' . h($maxVersion) . ')</span>' : '' ?>
                        <a href="<?= UPGRADE_URL ?>" target="_blank" class="btn btn-sm btn-primary" style="margin-left:10px;">Abo verlängern</a>
                    <?php endif ?>
                </td>
            </tr>
            <?php endif ?>
            <?php if ($licenseType === 'lifetime' && $maxVersion): ?>
            <tr>
                <td style="color:#94a3b8;">Updates enthalten bis</td>
                <td>v<?= h($maxVersion) ?></td>
            </tr>
            <?php endif ?>
        </table>
        <?php if (!$licenseKey): ?>
            <div class="alert alert-info" style="margin-top:10px;">
                Du nutzt die <strong>Free-Version mit verzögerten Updates</strong> – du erhältst neue Versionen jeweils eine Version nach dem Lifetime-Release.<br>
                 <a href="<?= UPGRADE_URL ?>" target="_blank">Jetzt Lifetime-Lizenz kaufen →</a>
            </div>
        <?php endif ?>
    </div>
</div>

<!-- Update verfügbar -->
<?php if ($updateInfo && $updateInfo['update_available']): ?>
<div class="card" style="margin-bottom:24px;border-left:4px solid #3b82f6;">
    <div class="card-header">🚀 Update verfügbar</div>
    <div class="card-body">
        <p>Version <strong><?= h($updateInfo['latest_version']) ?></strong> ist verfügbar.</p>
        <?php if (!empty($updateInfo['changelog'])): ?>
            <p style="color:#94a3b8;font-size:13px;"><?= nl2br(h($updateInfo['changelog'])) ?></p>
        <?php endif ?>
        <?php if ($licenseKey): ?>
            <form method="post" onsubmit="setTimeout(function(){document.getElementById('updateBtn').textContent='⏳ Update läuft...';},50);">
                <input type="hidden" name="target_version" value="<?= h($updateInfo['latest_version']) ?>">
                <button type="submit" id="updateBtn" name="start_update" class="btn btn-primary">
                    ⬇️ Jetzt auf v<?= h($updateInfo['latest_version']) ?> updaten
                </button>
                <small style="display:block;margin-top:8px;color:#94a3b8;">
                    ⚠️ Ein Backup der aktuellen Dateien wird automatisch angelegt.
                </small>
            </form>
        <?php else: ?>
            <a href="<?= UPGRADE_URL ?>" class="btn btn-primary" target="_blank">Lizenz kaufen um zu updaten</a>
        <?php endif ?>
    </div>
</div>
<?php elseif ($licenseKey && $updateInfo): ?>
<div class="card" style="margin-bottom:24px;">
    <div class="card-body" style="color:#94a3b8;">✅ Du nutzt die aktuellste Version.</div>
</div>
<?php endif ?>

<!-- Key eingeben -->
<div class="card">
    <div class="card-header"><?= $licenseKey ? '🔄 License Key ändern' : '✅ License Key aktivieren' ?></div>
    <div class="card-body">
        <form method="post" style="max-width:460px;">
            <div class="form-group">
                <label>License Key</label>
                <input type="text" name="license_key" class="form-control"
                       value="<?= h($licenseKey) ?>"
                       placeholder="XXXX-XXXX-XXXX-XXXX"
                       style="font-family:monospace;letter-spacing:2px;text-transform:uppercase;">
                <small style="color:#94a3b8;">Den Key erhältst du nach dem Kauf per E-Mail. Der Lizenztyp wird automatisch erkannt.</small>
            </div>
            <button type="submit" name="save_key" class="btn btn-success">
                <?= $licenseKey ? '🔄 Aktualisieren' : '✅ Aktivieren' ?>
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>