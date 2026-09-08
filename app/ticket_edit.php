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
$pageTitle = 'Ticket bearbeiten';
$activeMenu = 'tickets';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . BASE_URL . '/tickets.php'); exit; }

$stmt = $db->prepare("SELECT * FROM tickets WHERE id = ?");
$stmt->execute([$id]);
$ticket = $stmt->fetch();
if (!$ticket) { flash('Ticket nicht gefunden.', 'error'); header('Location: ' . BASE_URL . '/tickets.php'); exit; }

$customers   = $db->query("SELECT id, firstname, lastname, email FROM customers ORDER BY name ASC")->fetchAll();
$customDefs      = getCustomFieldDefs();
$customValues    = getCustomFieldValues($id);
$customBySection = getCustomFieldDefsBySection();
$technicians = $db->query("SELECT id, name FROM technicians ORDER BY name ASC")->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    
    $title = trim($_POST['title'] ?? '');
    $deviceModel = trim($_POST['device_model'] ?? '');
    $serialNumber = trim($_POST['serial_number'] ?? '');
    $conditionOnArrival = trim($_POST['condition_on_arrival'] ?? '');
    $damageDescription = trim($_POST['damage_description'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $technicianId = (int)($_POST['technician_id'] ?? 0) ?: null;
    $priority = $_POST['priority'] ?? 'normal';
    $internalNotes = trim($_POST['internal_notes'] ?? '');
    $customerId = (int)($_POST['customer_id'] ?? $ticket['customer_id']);
    
    if (empty($title)) $errors[] = 'Titel ist Pflichtfeld.';
    
    if (empty($errors)) {
        $stmt = $db->prepare("
            UPDATE tickets SET
            customer_id=?, technician_id=?, title=?, description=?, device_model=?,
            serial_number=?, condition_on_arrival=?, damage_description=?, priority=?, internal_notes=?
            WHERE id=?
        ");
        $stmt->execute([
            $customerId, $technicianId, $title, $description, $deviceModel,
            $serialNumber, $conditionOnArrival, $damageDescription, $priority, $internalNotes, $id
        ]);
        saveCustomFieldValues($id, $_POST);
        flash('Ticket wurde gespeichert.', 'success');
        header('Location: ' . BASE_URL . '/ticket_view.php?id=' . $id);
        exit;
    }
}
?>

<?php if (!empty($errors)): ?>
<div class="flash error">❌ <?= implode('<br>', array_map('h', $errors)) ?></div>
<?php endif; ?>

<div style="margin-bottom:16px;">
  <a href="<?= BASE_URL ?>/ticket_view.php?id=<?= $id ?>" style="color:#64748b;font-size:14px;">← Zurück zum Ticket <?= h($ticket['ticket_number']) ?></a>
</div>

<form method="post" action="">
  <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
  
  <div class="card">
    <div class="card-header"><h3>👤 Kunde</h3></div>
    <div class="card-body">
      <div class="form-group">
        <label class="form-label">Kunde</label>
        <select name="customer_id" class="form-control">
          <?php foreach ($customers as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $ticket['customer_id'] == $c['id'] ? 'selected' : '' ?>><?= h(customerFullName($c)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>
  
  <?php if (getSetting('show_device_info', '1') === '1'): ?>
  <div class="card">
    <div class="card-header" onclick="toggleCard('device-body')" style="cursor:pointer;user-select:none;">
      <h3>📱 <?= h(getSetting('label_device_section', 'Geräteinformationen')) ?></h3>
      <span id="device-toggle" style="color:#94a3b8;font-size:18px;transition:transform .2s;">▲</span>
    </div>
    <div id="device-body" class="card-body">
      <div class="form-row cols-2">
        <div class="form-group">
          <label class="form-label"><?= h(getSetting('label_device_model', 'Gerät / Modell')) ?></label>
          <input type="text" name="device_model" class="form-control" value="<?= h($ticket['device_model'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label"><?= h(getSetting('label_serial_number', 'Seriennummer / IMEI')) ?></label>
          <input type="text" name="serial_number" class="form-control" value="<?= h($ticket['serial_number'] ?? '') ?>">
        </div>
      </div>
      <div class="form-row cols-2">
        <div class="form-group">
          <label class="form-label"><?= h(getSetting('label_condition_on_arrival', 'Zustand bei Annahme')) ?></label>
          <input type="text" name="condition_on_arrival" class="form-control" value="<?= h($ticket['condition_on_arrival'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label"><?= h(getSetting('label_accessories', 'Zubehör mitgebracht')) ?></label>
          <input type="text" name="description" class="form-control" value="<?= h($ticket['description'] ?? '') ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label"><?= h(getSetting('label_damage_description', 'Schadensbeschreibung')) ?></label>
        <textarea name="damage_description" class="form-control" rows="3"><?= h($ticket['damage_description'] ?? '') ?></textarea>
      </div>
      <?php foreach ($customBySection['device_info'] ?? [] as $cf): ?>
      <div class="form-group">
        <label class="form-label"><?= h($cf['label']) ?><?php if ($cf['required']): ?><span class="required">*</span><?php endif; ?></label>
        <?= renderCustomFieldInput($cf, $_POST['cf_'.$cf['field_key']] ?? $customValues[$cf['field_key']] ?? '') ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header"><h3>🎫 Ticket-Einstellungen</h3></div>
    <div class="card-body">
      <div class="form-group">
        <label class="form-label">Titel <span class="required">*</span></label>
        <input type="text" name="title" class="form-control" value="<?= h($ticket['title']) ?>" required>
      </div>
      <div class="form-row cols-2">
        <div class="form-group">
          <label class="form-label">Techniker</label>
          <select name="technician_id" class="form-control">
            <option value="">— Nicht zugewiesen —</option>
            <?php foreach ($technicians as $t): ?>
            <option value="<?= $t['id'] ?>" <?= $ticket['technician_id'] == $t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Priorität</label>
          <select name="priority" class="form-control">
            <option value="niedrig" <?= $ticket['priority'] === 'niedrig' ? 'selected' : '' ?>>Niedrig</option>
            <option value="normal" <?= $ticket['priority'] === 'normal' ? 'selected' : '' ?>>Normal</option>
            <option value="hoch" <?= $ticket['priority'] === 'hoch' ? 'selected' : '' ?>>Hoch</option>
            <option value="dringend" <?= $ticket['priority'] === 'dringend' ? 'selected' : '' ?>>Dringend</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Interne Notizen</label>
        <textarea name="internal_notes" class="form-control" rows="2"><?= h($ticket['internal_notes'] ?? '') ?></textarea>
      </div>
    </div>
  </div>
  

  <!-- Benutzerdefinierte Felder  -->
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
          <?= h($cf['label']) ?><?php if ($cf['required']): ?><span class="required">*</span><?php endif; ?>
        </label>
        <?= renderCustomFieldInput($cf, $_POST['cf_'.$cf['field_key']] ?? $customValues[$cf['field_key']] ?? '') ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <div style="display:flex;gap:12px;justify-content:flex-end;">
    <a href="<?= BASE_URL ?>/ticket_view.php?id=<?= $id ?>" class="btn btn-secondary">Abbrechen</a>
    <button type="submit" class="btn btn-primary">💾 Änderungen speichern</button>
  </div>
</form>

<script>
function toggleCard(bodyId) {
    const body = document.getElementById(bodyId);
    const toggle = document.getElementById(bodyId.replace('-body','-toggle'));
    const hidden = body.style.display === 'none';
    body.style.display = hidden ? '' : 'none';
    if (toggle) toggle.style.transform = hidden ? '' : 'rotate(180deg)';
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>