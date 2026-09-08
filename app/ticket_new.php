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
$pageTitle = 'Neues Ticket';
$activeMenu = 'new_ticket';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
$errors = [];

$technicians     = $db->query("SELECT id, name FROM technicians ORDER BY name ASC")->fetchAll();
$customBySection = getCustomFieldDefsBySection(); // früh laden – wird in mehreren Cards gebraucht

$preCustomer = null;
if (!empty($_GET['customer_id'])) {
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([(int)$_GET['customer_id']]);
    $preCustomer = $stmt->fetch() ?: null;
}

// Formular verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $customerId        = (int)($_POST['customer_id'] ?? 0);
    $title             = trim($_POST['title']              ?? '');
    $description       = trim($_POST['description']        ?? '');
    $deviceModel       = trim($_POST['device_model']       ?? '');
    $serialNumber      = trim($_POST['serial_number']      ?? '');
    $conditionOnArrival= trim($_POST['condition_on_arrival']?? '');
    $damageDescription = trim($_POST['damage_description'] ?? '');
    $technicianId      = (int)($_POST['technician_id']     ?? 0) ?: null;
    $priority          = $_POST['priority'] ?? 'normal';
    $internalNotes     = trim($_POST['internal_notes']     ?? '');

    // Schnell-Neukunde
    if ($customerId === 0 && !empty($_POST['new_customer_lastname'])) {
        $newFirst   = trim($_POST['new_customer_firstname'] ?? '');
        $newLast    = trim($_POST['new_customer_lastname']  ?? '');
        $newEmail   = trim($_POST['new_customer_email']     ?? '');
        $newPhone   = trim($_POST['new_customer_phone']     ?? '');
        $newCompany = trim($_POST['new_customer_company']   ?? '');
        $newStreet  = trim($_POST['new_customer_street']    ?? '');
        $newZip     = trim($_POST['new_customer_zip']       ?? '');
        $newCity    = trim($_POST['new_customer_city']      ?? '');
        $newNotes   = trim($_POST['new_customer_notes']     ?? '');

        if (empty($newLast)) {
            $errors[] = 'Nachname des neuen Kunden ist Pflichtfeld.';
        } else {
            $fullName = trim($newFirst . ' ' . $newLast);
            $stmt = $db->prepare("INSERT INTO customers (name, firstname, lastname, company, street, zip, city, phone, email, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$fullName, $newFirst, $newLast, $newCompany, $newStreet, $newZip, $newCity, $newPhone, $newEmail, $newNotes]);
            $customerId = (int)$db->lastInsertId();
        }
    }

    if (!$customerId) $errors[] = 'Bitte einen Kunden auswählen oder neuen Kunden anlegen.';
    if (empty($title))  $errors[] = 'Titel ist ein Pflichtfeld.';

    if (empty($errors)) {
        $ticketNumber  = generateTicketNumber();
        $customerToken = generateCustomerToken();

        $stmt = $db->prepare("
            INSERT INTO tickets
            (ticket_number, customer_id, technician_id, title, description, device_model,
             serial_number, condition_on_arrival, damage_description, priority, internal_notes, customer_token)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $ticketNumber, $customerId, $technicianId, $title, $description,
            $deviceModel, $serialNumber, $conditionOnArrival, $damageDescription,
            $priority, $internalNotes, $customerToken
        ]);
        $ticketId = (int)$db->lastInsertId();

        saveCustomFieldValues($ticketId, $_POST);

        $stmtT = $db->prepare("SELECT * FROM tickets WHERE id = ?");
        $stmtT->execute([$ticketId]);
        $ticket = $stmtT->fetch();

        $stmtC = $db->prepare("SELECT * FROM customers WHERE id = ?");
        $stmtC->execute([$customerId]);
        $customer = $stmtC->fetch();

        $technician = null;
        if ($technicianId) {
            $stmtTech = $db->prepare("SELECT * FROM technicians WHERE id = ?");
            $stmtTech->execute([$technicianId]);
            $technician = $stmtTech->fetch();
        }

        sendTicketCreatedMails($ticket, $customer, $technician);

        echo "<div style='background:#10b981;color:white;padding:20px;margin:20px;'>";
        echo "<h2>✅ Ticket erfolgreich erstellt!</h2>";
        echo "<p style='margin:10px 0;'>Das Ticket #{$ticketNumber} wurde erfolgreich erstellt.</p>";
        echo "<a href='" . BASE_URL . "/tickets.php' style='color:white;text-decoration:underline;display:inline-block;margin-top:10px;'>Zur Ticketliste</a>";
        echo "</div>";
        
        exit;
    }
}
?>

<?php if (!empty($errors)): ?>
<div class="flash error">❌ <?= implode('<br>', array_map('h', $errors)) ?></div>
<?php endif; ?>

<style>
/* ── Kunden-Suche ── */
.cs-wrap { position: relative; }
.cs-input-row { display: flex; gap: 8px; align-items: center; }
.cs-clear { background: none; border: none; font-size: 18px; cursor: pointer; color: #94a3b8; padding: 4px; line-height:1; }
.cs-clear:hover { color: #ef4444; }
#cs-results {
  position: absolute; top: calc(100% + 4px); left: 0; right: 0;
  background: #fff; border: 1px solid #d1d5db; border-radius: 8px;
  box-shadow: 0 8px 24px rgba(0,0,0,.12); z-index: 999;
  max-height: 280px; overflow-y: auto; display: none;
}
.cs-item {
  padding: 10px 14px; cursor: pointer; display: flex; align-items: center;
  gap: 12px; border-bottom: 1px solid #f1f5f9; font-size: 14px;
}
.cs-item:last-child { border-bottom: none; }
.cs-item:hover, .cs-item.cs-active { background: #eff6ff; }
.cs-item .cs-lastname  { font-weight: 700; color: #1e293b; }
.cs-item .cs-firstname { color: #64748b; }
.cs-item .cs-meta      { font-size: 12px; color: #94a3b8; margin-left: auto; white-space: nowrap; }
.cs-item.cs-new        { color: #2563eb; font-weight: 600; border-top: 1px solid #e2e8f0; }
.cs-no-results         { padding: 14px; color: #94a3b8; font-size: 14px; text-align: center; }
.cs-selected-badge {
  display: inline-flex; align-items: center; gap: 8px;
  background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px;
  padding: 8px 14px; font-size: 14px; font-weight: 600; color: #1e40af;
  margin-top: 6px;
}
.cs-selected-badge button { background: none; border: none; cursor: pointer; color: #94a3b8; font-size: 16px; padding: 0 0 0 4px; }
.cs-selected-badge button:hover { color: #ef4444; }
</style>

<form method="post" action="" id="ticketForm">
  <input type="hidden" name="csrf_token" value="<?= csrf() ?>">

  <!-- ── Kundenauswahl ── -->
  <div class="card">
    <div class="card-header">
      <h3>👤 Kunde auswählen</h3>
    </div>
    <div class="card-body">

      <input type="hidden" name="customer_id" id="customer_id_field"
             value="<?= $preCustomer ? $preCustomer['id'] : '' ?>">

      <?php if ($preCustomer): ?>
      <!-- Vorausgewählt (von Kundenprofil) -->
      <div id="cs-selected-display">
        <div class="cs-selected-badge">
          👤 <?= h(customerFullName($preCustomer)) ?>
          <?= $preCustomer['email'] ? '<span style="font-weight:400;color:#64748b;margin-left:6px;">' . h($preCustomer['email']) . '</span>' : '' ?>
          <button type="button" onclick="clearCustomerSelection()" title="Anderen Kunden wählen">✕</button>
        </div>
      </div>
      <div id="cs-search-area" style="display:none;">
      <?php else: ?>
      <div id="cs-selected-display" style="display:none;"></div>
      <div id="cs-search-area">
      <?php endif; ?>

        <div class="cs-wrap">
          <label class="form-label">Nach Nachname suchen</label>
          <div class="cs-input-row">
            <input type="text" id="cs-input" class="form-control" autocomplete="off"
                   placeholder="Nachname eingeben… (mind. 1 Zeichen)"
                   style="max-width:380px;">
            <button type="button" class="cs-clear" id="cs-clear-btn" onclick="clearSearch()" title="Suche leeren" style="display:none;">✕</button>
            <a href="<?= BASE_URL ?>/customer_new.php" class="btn btn-secondary btn-sm" target="_blank">
              👥 Vollständig anlegen
            </a>
          </div>
          <div id="cs-results"></div>
        </div>

      </div><!-- /#cs-search-area -->

      <!-- Schnell-Neukunde -->
      <div id="newCustomerFields"
           style="display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:16px;margin-top:14px;">
        <h4 style="margin-bottom:14px;font-size:14px;color:#374151;font-weight:600;">
          ➕ Neuen Kunden anlegen
        </h4>

        <p style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin-bottom:10px;">Persönliche Daten</p>
        <div class="form-row cols-2">
          <div class="form-group">
            <label class="form-label">Vorname</label>
            <input type="text" name="new_customer_firstname" class="form-control"
                   placeholder="Max" value="<?= h($_POST['new_customer_firstname'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Nachname <span class="required">*</span></label>
            <input type="text" name="new_customer_lastname" class="form-control"
                   placeholder="Mustermann" value="<?= h($_POST['new_customer_lastname'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Firma / Unternehmen</label>
            <input type="text" name="new_customer_company" class="form-control"
                   placeholder="Muster GmbH (optional)" value="<?= h($_POST['new_customer_company'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Telefon</label>
            <input type="text" name="new_customer_phone" class="form-control"
                   placeholder="+43 664 …" value="<?= h($_POST['new_customer_phone'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">E-Mail</label>
          <input type="email" name="new_customer_email" class="form-control"
                 placeholder="max@beispiel.at" value="<?= h($_POST['new_customer_email'] ?? '') ?>">
        </div>

        <p style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin:14px 0 10px;">Adresse</p>
        <div class="form-group">
          <label class="form-label">Straße &amp; Hausnummer</label>
          <input type="text" name="new_customer_street" class="form-control"
                 placeholder="Hauptstraße 123" value="<?= h($_POST['new_customer_street'] ?? '') ?>">
        </div>
        <div class="form-row cols-2">
          <div class="form-group">
            <label class="form-label">PLZ</label>
            <input type="text" name="new_customer_zip" class="form-control"
                   placeholder="1010" style="max-width:120px;" value="<?= h($_POST['new_customer_zip'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Ort / Stadt</label>
            <input type="text" name="new_customer_city" class="form-control"
                   placeholder="Wien" value="<?= h($_POST['new_customer_city'] ?? '') ?>">
          </div>
        </div>

        <p style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin:14px 0 10px;">Interne Notizen</p>
        <div class="form-group">
          <textarea name="new_customer_notes" class="form-control" rows="3"
                    placeholder="Interne Notizen zum Kunden (nur für Mitarbeiter sichtbar)..."><?= h($_POST['new_customer_notes'] ?? '') ?></textarea>
        </div>

        <div style="display:flex;gap:10px;margin-top:6px;">
          <button type="button" class="btn btn-secondary btn-sm" onclick="cancelNewCustomer()">
            Abbrechen
          </button>
          <button type="submit" class="btn btn-primary btn-sm">
            👤 Kunden anlegen &amp; Ticket erstellen
          </button>
        </div>
      </div>

    </div>
  </div>

  <!-- ── Gerätedaten  ── -->
  <?php if (getSetting('show_device_info', '1') === '1'): ?>
  <div class="card">
    <div class="card-header" onclick="toggleCard('device-body')"
         style="cursor:pointer;user-select:none;">
      <h3>📱 <?= h(getSetting('label_device_section', 'Geräteinformationen')) ?></h3>
      <span id="device-toggle" style="color:#94a3b8;font-size:18px;transition:transform .2s;">▲</span>
    </div>
    <div id="device-body" class="card-body">
      <div class="form-row cols-2">
        <div class="form-group">
          <label class="form-label"><?= h(getSetting('label_device_model', 'Gerät / Modell')) ?></label>
          <input type="text" name="device_model" class="form-control"
                 placeholder="z.B. iPhone 14 Pro, Samsung Galaxy S23"
                 value="<?= h($_POST['device_model'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label"><?= h(getSetting('label_serial_number', 'Seriennummer / IMEI')) ?></label>
          <input type="text" name="serial_number" class="form-control"
                 placeholder="z.B. 353012345678901"
                 value="<?= h($_POST['serial_number'] ?? '') ?>">
        </div>
      </div>
      <div class="form-row cols-2">
        <div class="form-group">
          <label class="form-label"><?= h(getSetting('label_condition_on_arrival', 'Zustand bei Annahme')) ?></label>
          <input type="text" name="condition_on_arrival" class="form-control"
                 placeholder="z.B. leichte Kratzer, Display intakt"
                 value="<?= h($_POST['condition_on_arrival'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label"><?= h(getSetting('label_accessories', 'Zubehör mitgebracht')) ?></label>
          <input type="text" name="description" class="form-control"
                 placeholder="z.B. Ladekabel, Hülle"
                 value="<?= h($_POST['description'] ?? '') ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label"><?= h(getSetting('label_damage_description', 'Schadensbeschreibung')) ?></label>
        <textarea name="damage_description" class="form-control" rows="3"
                  placeholder="Detaillierte Beschreibung des Schadens oder Problems..."><?= h($_POST['damage_description'] ?? '') ?></textarea>
      </div>
      <?php foreach ($customBySection['device_info'] ?? [] as $cf): ?>
      <div class="form-group">
        <label class="form-label">
          <?= h($cf['label']) ?><?php if ($cf['required']): ?><span class="required">*</span><?php endif; ?>
        </label>
        <?= renderCustomFieldInput($cf, $_POST['cf_' . $cf['field_key']] ?? '') ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Ticket-Details ── -->
  <div class="card">
    <div class="card-header"><h3>🎫 Ticket-Details</h3></div>
    <div class="card-body">
      <div class="form-group">
        <label class="form-label">Titel / Betreff <span class="required">*</span></label>
        <input type="text" name="title" class="form-control"
               placeholder="z.B. Display-Reparatur, Akku-Tausch"
               value="<?= h($_POST['title'] ?? '') ?>" required>
      </div>
      <div class="form-row cols-2">
        <div class="form-group">
          <label class="form-label">Zugewiesener Techniker</label>
          <select name="technician_id" class="form-control">
            <option value="">— Nicht zugewiesen —</option>
            <?php foreach ($technicians as $t): ?>
            <option value="<?= $t['id'] ?>"
              <?= ((isset($_POST['technician_id']) && $_POST['technician_id'] == $t['id'])
                   || (!isset($_POST['technician_id']) && $currentTech && $currentTech['id'] == $t['id']))
                  ? 'selected' : '' ?>>
              <?= h($t['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Priorität</label>
          <select name="priority" class="form-control">
            <option value="niedrig" <?= ($_POST['priority'] ?? '') === 'niedrig' ? 'selected' : '' ?>>🔵 Niedrig</option>
            <option value="normal"  <?= ($_POST['priority'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>⚪ Normal</option>
            <option value="hoch"    <?= ($_POST['priority'] ?? '') === 'hoch'    ? 'selected' : '' ?>>🟡 Hoch</option>
            <option value="dringend"<?= ($_POST['priority'] ?? '') === 'dringend'? 'selected' : '' ?>>🔴 Dringend</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Interne Notizen
          <span style="font-weight:400;color:#6b7280;">(nicht fuer Kunden sichtbar)</span>
        </label>
        <textarea name="internal_notes" class="form-control" rows="2"
                  placeholder="Interne Hinweise fuer das Team..."><?= h($_POST['internal_notes'] ?? '') ?></textarea>
      </div>
      <?php foreach ($customBySection['ticket_details'] ?? [] as $cf): ?>
      <div class="form-group">
        <label class="form-label">
          <?= h($cf['label']) ?><?php if ($cf['required']): ?><span class="required">*</span><?php endif; ?>
        </label>
        <?= renderCustomFieldInput($cf, $_POST['cf_' . $cf['field_key']] ?? '') ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>


  <!-- ── Benutzerdefinierte Felder  ── -->
  <?php
  foreach ($customBySection as $secKey => $secFields):
      if ($secKey === 'device_info' || $secKey === 'ticket_details') continue;
      $secTitle = $secKey === 'custom' ? '🔧 Weitere Felder' : '🗂️ ' . $secKey;
  ?>
  <div class="card">
    <div class="card-header"><h3><?= h($secTitle) ?></h3></div>
    <div class="card-body">
      <?php foreach ($secFields as $cf): ?>
      <div class="form-group">
        <label class="form-label">
          <?= h($cf['label']) ?>
          <?php if ($cf['required']): ?><span class="required">*</span><?php endif; ?>
        </label>
        <?= renderCustomFieldInput($cf, $_POST['cf_' . $cf['field_key']] ?? '') ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <div style="display:flex;gap:12px;justify-content:flex-end;">
    <a href="<?= BASE_URL ?>/tickets.php" class="btn btn-secondary">Abbrechen</a>
    <button type="submit" class="btn btn-primary">🎫 Ticket erstellen &amp; E-Mail senden</button>
  </div>
</form>

<script>
// ── Kunden-Suche ─────────────────────────────────────────────
const csInput      = document.getElementById('cs-input');
const csResults    = document.getElementById('cs-results');
const csClearBtn   = document.getElementById('cs-clear-btn');
const csIdField    = document.getElementById('customer_id_field');
const csSelectedDisplay = document.getElementById('cs-selected-display');
const csSearchArea = document.getElementById('cs-search-area');
const newFields    = document.getElementById('newCustomerFields');

let searchTimer = null;
let activeIndex = -1;

csInput.addEventListener('input', function () {
    const q = this.value.trim();
    csClearBtn.style.display = q ? 'inline-block' : 'none';
    clearTimeout(searchTimer);
    if (q.length === 0) { csResults.style.display = 'none'; return; }
    searchTimer = setTimeout(() => doSearch(q), 220);
});

csInput.addEventListener('keydown', function (e) {
    const items = csResults.querySelectorAll('.cs-item');
    if (!items.length) return;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        activeIndex = Math.min(activeIndex + 1, items.length - 1);
        updateActive(items);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        activeIndex = Math.max(activeIndex - 1, 0);
        updateActive(items);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (activeIndex >= 0) items[activeIndex].click();
    } else if (e.key === 'Escape') {
        csResults.style.display = 'none';
    }
});

function updateActive(items) {
    items.forEach((el, i) => el.classList.toggle('cs-active', i === activeIndex));
    if (activeIndex >= 0) items[activeIndex].scrollIntoView({ block: 'nearest' });
}

function doSearch(q) {
    fetch('<?= BASE_URL ?>/customer_search.php?q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => renderResults(data, q));
}

function renderResults(customers, q) {
    activeIndex = -1;
    if (customers.length === 0) {
        csResults.innerHTML = '<div class="cs-no-results">Keine Kunden gefunden für „' + escHtml(q) + '"</div>'
            + '<div class="cs-item cs-new" onclick="showNewCustomerForm()">➕ Neuen Kunden anlegen</div>';
    } else {
        let html = customers.map(c => {
            const last  = highlight(escHtml(c.lastname),  q);
            const first = escHtml(c.firstname);
            const meta  = c.phone || c.email || '';
            return `<div class="cs-item" onclick="selectCustomer(${c.id}, '${c.firstname + ' ' + c.lastname}')">
                <span class="cs-lastname">${last}</span>
                <span class="cs-firstname">${first}</span>
                <span class="cs-meta">${escHtml(meta)}</span>
            </div>`;
        }).join('');
        html += '<div class="cs-item cs-new" onclick="showNewCustomerForm()">➕ Neuen Kunden anlegen</div>';
        csResults.innerHTML = html;
    }
    csResults.style.display = 'block';
}

function highlight(text, q) {
    const idx = text.toLowerCase().indexOf(q.toLowerCase());
    if (idx === -1) return text;
    return text.slice(0, idx)
        + '<mark style="background:#fef08a;border-radius:2px;">' + text.slice(idx, idx + q.length) + '</mark>'
        + text.slice(idx + q.length);
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function selectCustomer(id, name) {
    csIdField.value = id;
    csResults.style.display = 'none';
    newFields.style.display  = 'none';
    // Show selected badge
    csSelectedDisplay.innerHTML = `<div class="cs-selected-badge">
        👤 <strong>${escHtml(name.trim())}</strong>
        <button type="button" onclick="clearCustomerSelection()" title="Anderen Kunden wählen">✕</button>
    </div>`;
    csSelectedDisplay.style.display = 'block';
    csSearchArea.style.display = 'none';
}

function clearCustomerSelection() {
    csIdField.value = '';
    csInput.value   = '';
    csClearBtn.style.display = 'none';
    csSelectedDisplay.style.display = 'none';
    csSearchArea.style.display = 'block';
    newFields.style.display = 'none';
    csInput.focus();
}

function showNewCustomerForm() {
    csResults.style.display = 'none';
    csIdField.value = '0';
    newFields.style.display = 'block';
    newFields.querySelector('input').focus();
}

function cancelNewCustomer() {
    newFields.style.display = 'none';
    csIdField.value = '';
    csInput.value   = '';
}

function clearSearch() {
    csInput.value = '';
    csClearBtn.style.display = 'none';
    csResults.style.display  = 'none';
    csInput.focus();
}

// Close dropdown when clicking outside
document.addEventListener('click', function (e) {
    if (!e.target.closest('.cs-wrap') && !e.target.closest('#newCustomerFields')) {
        csResults.style.display = 'none';
    }
});

function toggleCard(bodyId) {
    const body   = document.getElementById(bodyId);
    const toggle = document.getElementById(bodyId.replace('-body','-toggle'));
    const hidden = body.style.display === 'none';
    body.style.display   = hidden ? '' : 'none';
    if (toggle) toggle.style.transform = hidden ? '' : 'rotate(180deg)';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>