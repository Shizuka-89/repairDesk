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
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

$db     = getDB();
$errors = [];

$fixedSections = [
    'device_info'    => '📱 ' . getSetting('label_device_section', 'Geräteinformationen'),
    'ticket_details' => '🎫 Ticket-Details',
];
$extraSections = $db->query(
    "SELECT DISTINCT section, section as label FROM ticket_field_definitions
     WHERE section NOT IN ('device_info','ticket_details','custom')
     ORDER BY section ASC"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$allSections = $fixedSections
    + array_map(fn($s) => '🗂️ ' . $s, $extraSections)
    + ['custom' => '🔧 Weitere Felder (Standard)'];

// ── Aktionen ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $label      = trim($_POST['label']        ?? '');
        $type       = $_POST['field_type']         ?? 'text';
        $section    = trim($_POST['section']       ?? 'custom');
        $newSection = trim($_POST['new_section']   ?? '');
        $options    = trim($_POST['options']       ?? '');
        $ph         = trim($_POST['placeholder']   ?? '');
        $required   = isset($_POST['required'])    ? 1 : 0;
        $showInList = isset($_POST['show_in_list'])? 1 : 0;

        // Neuer Abschnitt?
        if ($section === '__new__' && $newSection !== '') {
            $section = $newSection;
        } elseif ($section === '__new__') {
            $section = 'custom';
        }

        $validTypes = ['text','textarea','number','date','select','checkbox', 'file'];
        if (empty($label))                         $errors[] = 'Bezeichnung ist Pflichtfeld.';
        if (!in_array($type, $validTypes))         $errors[] = 'Ungültiger Feldtyp.';
        if ($type === 'select' && empty($options)) $errors[] = 'Bei Auswahl-Feld bitte Optionen angeben.';

        if (empty($errors)) {
            $key      = generateFieldKey($label);
            $check    = $db->prepare("SELECT COUNT(*) FROM ticket_field_definitions WHERE field_key = ?");
            $check->execute([$key]);
            if ($check->fetchColumn() > 0) $key .= '_' . time();

            $maxOrder = (int)$db->query("SELECT MAX(sort_order) FROM ticket_field_definitions")->fetchColumn();
            $stmt = $db->prepare("
                INSERT INTO ticket_field_definitions
                (field_key, label, section, field_type, options, placeholder, required, show_in_list, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$key, $label, $section, $type, $options ?: null, $ph ?: null, $required, $showInList, $maxOrder + 1]);
            flash('Feld "' . $label . '" wurde angelegt.', 'success');
            header('Location: ' . BASE_URL . '/custom_fields.php');
            exit;
        }
    }

    elseif ($action === 'delete') {
        $delId = (int)($_POST['field_id'] ?? 0);
        $stmt  = $db->prepare("SELECT field_key, label FROM ticket_field_definitions WHERE id = ?");
        $stmt->execute([$delId]);
        $row = $stmt->fetch();
        if ($row) {
            $db->prepare("DELETE FROM ticket_field_values WHERE field_key = ?")->execute([$row['field_key']]);
            $db->prepare("DELETE FROM ticket_field_definitions WHERE id = ?")->execute([$delId]);
            flash('Feld "' . $row['label'] . '" wurde geloescht.', 'success');
        }
        header('Location: ' . BASE_URL . '/custom_fields.php');
        exit;
    }

    elseif ($action === 'toggle') {
        $togId = (int)($_POST['field_id'] ?? 0);
        $db->prepare("UPDATE ticket_field_definitions SET is_active = NOT is_active WHERE id = ?")->execute([$togId]);
        header('Location: ' . BASE_URL . '/custom_fields.php');
        exit;
    }

    elseif ($action === 'reorder') {
        $order = json_decode($_POST['order'] ?? '[]', true);
        if (is_array($order)) {
            $stmt = $db->prepare("UPDATE ticket_field_definitions SET sort_order = ? WHERE id = ?");
            foreach ($order as $pos => $id) $stmt->execute([$pos, (int)$id]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    elseif ($action === 'edit') {
        $editId     = (int)($_POST['field_id']     ?? 0);
        $label      = trim($_POST['label']          ?? '');
        $section    = trim($_POST['section']        ?? 'custom');
        $newSection = trim($_POST['new_section']    ?? '');
        $options    = trim($_POST['options']        ?? '');
        $ph         = trim($_POST['placeholder']    ?? '');
        $required   = isset($_POST['required'])     ? 1 : 0;
        $showInList = isset($_POST['show_in_list'])  ? 1 : 0;

        if ($section === '__new__' && $newSection !== '') $section = $newSection;
        elseif ($section === '__new__')                   $section = 'custom';

        if (empty($label)) $errors[] = 'Bezeichnung ist Pflichtfeld.';

        if (empty($errors)) {
            $stmt = $db->prepare("
                UPDATE ticket_field_definitions
                SET label=?, section=?, options=?, placeholder=?, required=?, show_in_list=?
                WHERE id=?
            ");
            $stmt->execute([$label, $section, $options ?: null, $ph ?: null, $required, $showInList, $editId]);
            flash('Feld gespeichert.', 'success');
            header('Location: ' . BASE_URL . '/custom_fields.php');
            exit;
        }
    }
}

$pageTitle  = 'Ticket-Felder verwalten';
$activeMenu = 'custom_fields';
require_once __DIR__ . '/../includes/header.php';

$fields = getCustomFieldDefs(false);

// Felder nach Abschnitt gruppieren
$grouped = [];
foreach ($fields as $f) {
    $grouped[$f['section']][] = $f;
}

$typeLabels = [
    'text'     => ['📝', 'Einzeiliger Text'],
    'textarea' => ['📄', 'Mehrzeiliger Text'],
    'number'   => ['🔢', 'Zahl'],
    'date'     => ['📅', 'Datum'],
    'select'   => ['📋', 'Auswahlliste'],
    'checkbox' => ['☑️', 'Checkbox'],
	'file'     => ['📎', 'Datei-Upload (PDF, PNG, JPG)'],
	
];
?>

<?php if (!empty($errors)): ?>
<div class="flash error">❌ <?= implode('<br>', array_map('h', $errors)) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 360px;gap:20px;align-items:start;">

  <!-- ── Linke Spalte: Feldliste ── -->
  <div>
    <?php if (empty($fields)): ?>
    <div class="card">
      <div class="card-body" style="text-align:center;padding:40px;color:#94a3b8;">
        Noch keine eigenen Felder angelegt.<br>
        Nutze das Formular rechts um das erste Feld hinzuzufügen.
      </div>
    </div>
    <?php else: ?>

    <?php
    // Alle Abschnitte anzeigen die Felder haben
    $displaySections = array_unique(array_merge(
        array_keys($fixedSections),
        array_keys($grouped)
    ));
    foreach ($displaySections as $secKey):
        if (!isset($grouped[$secKey])) continue;
        $secLabel = $allSections[$secKey] ?? ('🗂️ ' . $secKey);
    ?>
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header">
        <h3><?= h($secLabel) ?></h3>
        <span style="font-size:12px;color:#94a3b8;">Reihenfolge per Drag &amp; Drop</span>
      </div>
      <ul class="field-list" id="list-<?= h($secKey) ?>" style="list-style:none;padding:0;margin:0;">
        <?php foreach ($grouped[$secKey] as $f): ?>
        <li data-id="<?= $f['id'] ?>"
            style="border-bottom:1px solid #f1f5f9;<?= !$f['is_active'] ? 'opacity:.45;' : '' ?>">
          <div style="display:flex;align-items:center;gap:10px;padding:11px 16px;">
            <span class="drag-handle" style="cursor:grab;color:#cbd5e1;font-size:18px;user-select:none;" title="Verschieben">⠿</span>
            <span style="font-size:18px;"><?= $typeLabels[$f['field_type']][0] ?? '📝' ?></span>
            <div style="flex:1;min-width:0;">
              <div style="font-weight:600;font-size:14px;">
                <?= h($f['label']) ?>
                <?php if ($f['required']): ?>
                  <span style="color:#ef4444;font-size:11px;margin-left:4px;">Pflicht</span>
                <?php endif; ?>
                <?php if ($f['show_in_list']): ?>
                  <span style="background:#dbeafe;color:#1e40af;font-size:11px;padding:1px 6px;border-radius:10px;margin-left:4px;">In Liste</span>
                <?php endif; ?>
                <?php if (!$f['is_active']): ?>
                  <span style="background:#f1f5f9;color:#94a3b8;font-size:11px;padding:1px 6px;border-radius:10px;margin-left:4px;">Inaktiv</span>
                <?php endif; ?>
              </div>
              <div style="font-size:12px;color:#94a3b8;margin-top:1px;">
                <?= $typeLabels[$f['field_type']][1] ?? '' ?>
                <?php if ($f['placeholder']): ?> &middot; „<?= h($f['placeholder']) ?>"<?php endif; ?>
              </div>
            </div>
            <div style="display:flex;gap:5px;flex-shrink:0;">
              <button onclick="openEdit(<?= $f['id'] ?>, <?= htmlspecialchars(json_encode($f), ENT_QUOTES) ?>)"
                      class="btn btn-secondary btn-sm" title="Bearbeiten">✏️</button>
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <input type="hidden" name="action"     value="toggle">
                <input type="hidden" name="field_id"   value="<?= $f['id'] ?>">
                <button type="submit" class="btn btn-secondary btn-sm"
                        title="<?= $f['is_active'] ? 'Deaktivieren' : 'Aktivieren' ?>">
                  <?= $f['is_active'] ? '🔕' : '✅' ?>
                </button>
              </form>
              <form method="post" style="display:inline;"
                    onsubmit="return confirm('Feld wirklich loeschen? Alle Werte in Tickets werden geloescht!')">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <input type="hidden" name="action"     value="delete">
                <input type="hidden" name="field_id"   value="<?= $f['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">🗑️</button>
              </form>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Vorschau -->
    <?php
    $activeFields = array_filter($fields, fn($f) => $f['is_active']);
    if (!empty($activeFields)):
    ?>
    <div class="card">
      <div class="card-header"><h3>👁️ Vorschau im Ticket-Formular</h3></div>
      <div class="card-body">
        <p style="font-size:13px;color:#64748b;margin-bottom:16px;">So erscheinen deine Felder beim Erstellen eines Tickets:</p>
        <?php
        $prevSec = null;
        foreach ($activeFields as $cf):
            $sec = $cf['section'];
            if ($sec !== $prevSec):
                $secLabel = $allSections[$sec] ?? ('🗂️ ' . $sec);
                if ($prevSec !== null) echo '</div>';
                echo '<p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin:16px 0 10px;">'
                     . h($secLabel) . '</p><div>';
                $prevSec = $sec;
            endif;
        ?>
        <div class="form-group">
          <label class="form-label">
            <?= h($cf['label']) ?>
            <?php if ($cf['required']): ?><span class="required">*</span><?php endif; ?>
          </label>
          <?= renderCustomFieldInput($cf, '') ?>
        </div>
        <?php endforeach; ?>
        <?php if ($prevSec !== null) echo '</div>'; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Rechte Spalte: Neues Feld ── -->
  <div>
    <div class="card" style="position:sticky;top:20px;">
      <div class="card-header"><h3>➕ Neues Feld anlegen</h3></div>
      <div class="card-body">
        <form method="post" action="">
          <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
          <input type="hidden" name="action"     value="add">

          <div class="form-group">
            <label class="form-label">Bezeichnung <span class="required">*</span></label>
            <input type="text" name="label" class="form-control"
                   placeholder="z.B. Kaufdatum, Passcode, Farbe"
                   value="<?= h($_POST['label'] ?? '') ?>" autofocus>
          </div>

          <div class="form-group">
            <label class="form-label">Feldtyp <span class="required">*</span></label>
            <select name="field_type" id="add-type" class="form-control"
                    onchange="toggleTypeHints(this.value)">
              <?php foreach ($typeLabels as $val => [$icon, $name]): ?>
              <option value="<?= $val ?>" <?= ($_POST['field_type'] ?? 'text') === $val ? 'selected' : '' ?>>
                <?= $icon ?> <?= $name ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group" id="add-options-row" style="display:none;">
            <label class="form-label">Optionen <span class="required">*</span></label>
            <textarea name="options" class="form-control" rows="3"
                      placeholder="Gut | Mittel | Schlecht&#10;(mit | trennen)"><?= h($_POST['options'] ?? '') ?></textarea>
            <p class="form-help">Einträge mit <strong>|</strong> trennen.</p>
          </div>

          <div class="form-group" id="add-ph-row">
            <label class="form-label">Platzhalter-Text</label>
            <input type="text" name="placeholder" class="form-control"
                   placeholder="z.B. TT.MM.JJJJ"
                   value="<?= h($_POST['placeholder'] ?? '') ?>">
          </div>

          <!-- Abschnitt -->
          <div class="form-group">
            <label class="form-label">Abschnitt im Ticket</label>
            <select name="section" id="add-section" class="form-control"
                    onchange="toggleNewSection(this.value, 'add-new-section')">
              <option value="device_info"    <?= ($_POST['section'] ?? '') === 'device_info'    ? 'selected' : '' ?>>📱 <?= h(getSetting('label_device_section', 'Geräteinformationen')) ?></option>
              <option value="ticket_details" <?= ($_POST['section'] ?? '') === 'ticket_details' ? 'selected' : '' ?>>🎫 Ticket-Details</option>
              <option value="custom"         <?= ($_POST['section'] ?? 'custom') === 'custom'   ? 'selected' : '' ?>>🔧 Weitere Felder (Standard)</option>
              <?php foreach ($extraSections as $sk => $sl): ?>
              <option value="<?= h($sk) ?>" <?= ($_POST['section'] ?? '') === $sk ? 'selected' : '' ?>>🗂️ <?= h($sl) ?></option>
              <?php endforeach; ?>
              <option value="__new__" <?= ($_POST['section'] ?? '') === '__new__' ? 'selected' : '' ?>>✨ Neue Kategorie anlegen…</option>
            </select>
            <p class="form-help">Wo soll das Feld im Ticket-Formular erscheinen?</p>
          </div>

          <div class="form-group" id="add-new-section" style="display:none;">
            <label class="form-label">Name der neuen Kategorie</label>
            <input type="text" name="new_section" class="form-control"
                   placeholder="z.B. Kostenvoranschlag, Reparatur-Details"
                   value="<?= h($_POST['new_section'] ?? '') ?>">
          </div>

          <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px;">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;">
              <input type="checkbox" name="required" value="1"
                     <?= isset($_POST['required']) ? 'checked' : '' ?>
                     style="width:16px;height:16px;">
              Pflichtfeld
            </label>
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;">
              <input type="checkbox" name="show_in_list" value="1"
                     <?= isset($_POST['show_in_list']) ? 'checked' : '' ?>
                     style="width:16px;height:16px;">
              In Ticket-Liste anzeigen
            </label>
          </div>

          <button type="submit" class="btn btn-primary" style="width:100%;">➕ Feld anlegen</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ── Edit-Modal ── -->
<div id="edit-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:10px;padding:28px;width:100%;max-width:500px;box-shadow:0 20px 50px rgba(0,0,0,.25);margin:20px;max-height:90vh;overflow-y:auto;">
    <h3 style="margin-bottom:20px;font-size:16px;">✏️ Feld bearbeiten</h3>
    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
      <input type="hidden" name="action"     value="edit">
      <input type="hidden" name="field_id"   id="edit-field-id">

      <div class="form-group">
        <label class="form-label">Bezeichnung <span class="required">*</span></label>
        <input type="text" name="label" id="edit-label" class="form-control" required>
      </div>

      <div class="form-group">
        <label class="form-label">Abschnitt</label>
        <select name="section" id="edit-section" class="form-control"
                onchange="toggleNewSection(this.value, 'edit-new-section')">
          <option value="device_info">📱 <?= h(getSetting('label_device_section', 'Geräteinformationen')) ?></option>
          <option value="ticket_details">🎫 Ticket-Details</option>
          <option value="custom">🔧 Weitere Felder (Standard)</option>
          <?php foreach ($extraSections as $sk => $sl): ?>
          <option value="<?= h($sk) ?>">🗂️ <?= h($sl) ?></option>
          <?php endforeach; ?>
          <option value="__new__">✨ Neue Kategorie anlegen…</option>
        </select>
      </div>

      <div class="form-group" id="edit-new-section" style="display:none;">
        <label class="form-label">Name der neuen Kategorie</label>
        <input type="text" name="new_section" id="edit-new-section-input" class="form-control"
               placeholder="z.B. Reparatur-Details">
      </div>

      <div class="form-group" id="edit-options-row">
        <label class="form-label">Optionen (mit | trennen)</label>
        <textarea name="options" id="edit-options" class="form-control" rows="3"></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Platzhalter-Text</label>
        <input type="text" name="placeholder" id="edit-placeholder" class="form-control">
      </div>

      <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:20px;">
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;">
          <input type="checkbox" name="required" id="edit-required" value="1" style="width:16px;height:16px;">
          Pflichtfeld
        </label>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;">
          <input type="checkbox" name="show_in_list" id="edit-show-list" value="1" style="width:16px;height:16px;">
          In Ticket-Liste anzeigen
        </label>
      </div>

      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="closeEdit()" class="btn btn-secondary">Abbrechen</button>
        <button type="submit" class="btn btn-primary">💾 Speichern</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleTypeHints(type) {
    document.getElementById('add-options-row').style.display = type === 'select' ? 'block' : 'none';
    const noPlaceholder = ['checkbox', 'file'];
    document.getElementById('add-ph-row').style.display = noPlaceholder.includes(type) ? 'none' : 'block';
}
function toggleNewSection(val, rowId) {
    document.getElementById(rowId).style.display = val === '__new__' ? 'block' : 'none';
}
// Init
toggleTypeHints(document.getElementById('add-type').value);
toggleNewSection(document.getElementById('add-section').value, 'add-new-section');

function openEdit(id, f) {
    document.getElementById('edit-field-id').value    = id;
    document.getElementById('edit-label').value       = f.label        || '';
    document.getElementById('edit-options').value     = f.options      || '';
    document.getElementById('edit-placeholder').value = f.placeholder  || '';
    document.getElementById('edit-required').checked  = f.required     == 1;
    document.getElementById('edit-show-list').checked = f.show_in_list == 1;
    document.getElementById('edit-options-row').style.display = f.field_type === 'select' ? 'block' : 'none';
    const secSel = document.getElementById('edit-section');
    // Set section select - check if option exists
    let found = false;
    for (let o of secSel.options) { if (o.value === f.section) { o.selected = true; found = true; break; } }
    if (!found) {
        // Custom section not in list yet - add it
        const opt = document.createElement('option');
        opt.value = f.section; opt.text = '🗂️ ' + f.section; opt.selected = true;
        secSel.insertBefore(opt, secSel.lastElementChild);
    }
    toggleNewSection(secSel.value, 'edit-new-section');
    document.getElementById('edit-modal').style.display = 'flex';
}
function closeEdit() { document.getElementById('edit-modal').style.display = 'none'; }
document.getElementById('edit-modal').addEventListener('click', e => { if (e.target === document.getElementById('edit-modal')) closeEdit(); });

// Drag & Drop pro Liste
document.querySelectorAll('.field-list').forEach(list => {
    let dragging = null;
    list.querySelectorAll('li').forEach(li => {
        li.setAttribute('draggable','true');
        li.addEventListener('dragstart', () => { dragging = li; setTimeout(() => li.style.opacity='.3', 0); });
        li.addEventListener('dragend',   () => { li.style.opacity=''; dragging=null; saveOrder(list); });
        li.addEventListener('dragover',  e => {
            e.preventDefault();
            if (!dragging || dragging===li) return;
            const mid = li.getBoundingClientRect().top + li.getBoundingClientRect().height/2;
            list.insertBefore(dragging, e.clientY < mid ? li : li.nextSibling);
        });
    });
});
function saveOrder(list) {
    const ids = [...list.querySelectorAll('li')].map(li => li.dataset.id);
    fetch('<?= BASE_URL ?>/custom_fields.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=reorder&order='+encodeURIComponent(JSON.stringify(ids))+'&csrf_token=<?= csrf() ?>'
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>