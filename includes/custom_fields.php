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
// Hilfsfunktionen für benutzerdefinierte Ticket-Felder
// ================================================================

function getCustomFieldDefs(bool $activeOnly = true): array {
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = getDB()->query("SELECT * FROM ticket_field_definitions ORDER BY sort_order ASC, id ASC")->fetchAll();
        } catch (Exception $e) {
            error_log("Custom fields error: " . $e->getMessage());
            $cache = [];
        }
    }
    if ($activeOnly) {
        return array_values(array_filter($cache, fn($f) => !empty($f['is_active'])));
    }
    return $cache;
}

function getCustomFieldDefsBySection(bool $activeOnly = true): array {
    $groups = [];
    foreach (getCustomFieldDefs($activeOnly) as $f) {
        $sec = $f['section'] ?? 'custom';
        $groups[$sec][] = $f;
    }
    return $groups;
}

function getCustomFieldValues(int $ticketId): array {
    $stmt = getDB()->prepare("SELECT field_key, value FROM ticket_field_values WHERE ticket_id = ?");
    $stmt->execute([$ticketId]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[$r['field_key']] = $r['value'];
    }
    return $out;
}

function saveCustomFieldValues(int $ticketId, array $postData): void {
    $db   = getDB();
    foreach (getCustomFieldDefs() as $def) {
        $key   = $def['field_key'];
        $value = $def['field_type'] === 'checkbox'
            ? (isset($postData['cf_' . $key]) ? '1' : '0')
            : trim($postData['cf_' . $key] ?? '');
        $db->prepare("INSERT INTO ticket_field_values (ticket_id, field_key, value)
                      VALUES (?, ?, ?)
                      ON DUPLICATE KEY UPDATE value = VALUES(value)")
           ->execute([$ticketId, $key, $value]);
    }
}

// Zeigt den gespeicherten Wert eines Feldes als lesbaren Text an
function renderCustomFieldValue(array $def, string $value): string {
    if ($value === '' || $value === null) {
        return '<span style="color:#94a3b8;">—</span>';
    }

    switch ($def['field_type']) {
        case 'checkbox':
            return $value === '1'
                ? '<span style="color:#10b981;">✅ Ja</span>'
                : '<span style="color:#94a3b8;">Nein</span>';

        case 'date':
            $d = DateTime::createFromFormat('Y-m-d', $value);
            return $d ? h($d->format('d.m.Y')) : h($value);

        case 'number':
            return h($value);

        case 'file':
            return '<span style="color:#3b82f6;">📎 ' . h($value) . '</span>';

        case 'textarea':
            return nl2br(h($value));

        default:
            return h($value);
    }
}

// ── Zeigt das Eingabefeld für ein Custom Field ───────────────────
function renderCustomFieldInput(array $def, string $currentValue = ''): string {
    $key  = h($def['field_key']);
    $name = 'cf_' . $key;
    $ph   = h($def['placeholder'] ?? '');
    $req  = !empty($def['required']) ? 'required' : '';
    $val  = h($currentValue);

    switch ($def['field_type']) {
        case 'file':
            $info = !empty($currentValue)
                ? "<div style='font-size:12px;color:#10b981;margin-bottom:4px;'>✅ Datei: {$val}</div>"
                : '';
            return $info . "<input type=\"file\" name=\"{$name}\" class=\"form-control\" accept=\".pdf,.png,.jpg,.jpeg\" {$req}>";

        case 'textarea':
            return "<textarea name=\"{$name}\" class=\"form-control\" rows=\"3\" placeholder=\"{$ph}\" {$req}>{$val}</textarea>";

        case 'checkbox':
            $checked = $currentValue === '1' ? 'checked' : '';
            return "<label style='display:flex;align-items:center;gap:8px;cursor:pointer;'>
                        <input type='checkbox' name='{$name}' value='1' {$checked} {$req}>
                        <span>{$ph}</span>
                    </label>";

        case 'number':
            return "<input type=\"number\" name=\"{$name}\" class=\"form-control\" value=\"{$val}\" placeholder=\"{$ph}\" {$req}>";

        case 'date':
            return "<input type=\"date\" name=\"{$name}\" class=\"form-control\" value=\"{$val}\" {$req}>";

        case 'select':
            $raw     = $def['options'] ?? '';
            $options = array_filter(array_map('trim',
                str_contains($raw, '|') ? explode('|', $raw) : explode("\n", $raw)
            ));
            $html  = "<select name=\"{$name}\" class=\"form-control\" {$req}>";
            $html .= "<option value=\"\">— Bitte wählen —</option>";
            foreach ($options as $opt) {
                $optVal = h($opt);
                $sel    = $currentValue === $opt ? 'selected' : '';
                $html  .= "<option value=\"{$optVal}\" {$sel}>{$optVal}</option>";
            }
            $html .= "</select>";
            return $html;

        default:
            return "<input type=\"text\" name=\"{$name}\" class=\"form-control\" value=\"{$val}\" placeholder=\"{$ph}\" {$req}>";
    }
}


function generateFieldKey(string $label): string {
    $key = mb_strtolower($label);
    // Umlaute korrekt ersetzen
    $key = str_replace(
        ['ä',  'ö',  'ü',  'ß',  'Ä',  'Ö',  'Ü'],
        ['ae', 'oe', 'ue', 'ss', 'ae', 'oe', 'ue'],
        $key
    );
    $key = preg_replace('/[^a-z0-9]+/', '_', $key);
    return trim(substr($key, 0, 60), '_') ?: 'feld_' . time();
}

// Cache über globales Array statt static
function &_getSettingsCache(): array {
    static $cache = null;
    if ($cache === null) $cache = [];
    return $cache;
}

function getSetting(string $key, string $default = ''): string {
    $cache = &_getSettingsCache();
    if (empty($cache)) {
        try {
            $cache = getDB()->query("SELECT `key`, `value` FROM settings")
                            ->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {
            $cache = [];
        }
    }
    $val = $cache[$key] ?? '';
    return ($val !== '' && $val !== null) ? $val : $default;
}

function saveSetting(string $key, string $value): void {
    getDB()->prepare("INSERT INTO settings (`key`, `value`) VALUES (?,?)
                      ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=NOW()")
           ->execute([$key, $value]);

    // Cache leeren  Aufruf liest frisch aus DB
    $cache = &_getSettingsCache();
    $cache = [];
}