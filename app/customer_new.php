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
$pageTitle = 'Neuer Kunde';
$activeMenu = 'new_customer';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
$errors = [];

$editId = (int)($_GET['id'] ?? 0);
$isEdit = $editId > 0;

$customer = [
    'firstname' => '', 'lastname' => '', 'company' => '',
    'street' => '', 'zip' => '', 'city' => '',
    'phone' => '', 'email' => '', 'notes' => ''
];

if ($isEdit) {
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$editId]);
    $existing = $stmt->fetch();
    if (!$existing) { 
        flash('Kunde nicht gefunden.', 'error'); 
        header('Location: ' . BASE_URL . '/customers.php'); 
        exit; 
    }
    $customer = $existing;
    $pageTitle = 'Kunde bearbeiten: ' . h($customer['firstname'] . ' ' . $customer['lastname']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    
    $data = [
        'firstname' => trim($_POST['firstname'] ?? ''),
        'lastname'  => trim($_POST['lastname']  ?? ''),
        'company'   => trim($_POST['company']   ?? ''),
        'street'    => trim($_POST['street']    ?? ''),
        'zip'       => trim($_POST['zip']       ?? ''),
        'city'      => trim($_POST['city']      ?? ''),
        'phone'     => trim($_POST['phone']     ?? ''),
        'email'     => trim($_POST['email']     ?? ''),
        'notes'     => trim($_POST['notes']     ?? ''),
    ];
    
    if (empty($data['lastname'])) $errors[] = 'Nachname ist ein Pflichtfeld.';
    if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Ungültige E-Mail-Adresse.';
    
    if (empty($errors)) {
        if ($isEdit) {
            try {
                $stmt = $db->prepare("UPDATE customers SET name=?, firstname=?, lastname=?, company=?, street=?, zip=?, city=?, phone=?, email=?, notes=? WHERE id=?");
                $fullName = trim($data['firstname'] . ' ' . $data['lastname']);
                $stmt->execute([$fullName, $data['firstname'], $data['lastname'], $data['company'], $data['street'], $data['zip'], $data['city'], $data['phone'], $data['email'], $data['notes'], $editId]);
                
                flash('Kunde erfolgreich gespeichert.', 'success');
                echo "<div style='background:green;color:white;padding:20px;margin:20px;'>";
                echo "<h2>✅ Kunde erfolgreich gespeichert!</h2>";
                echo "<a href='" . BASE_URL . "/customer_view.php?id=$editId' style='color:white;text-decoration:underline;'>Zur Kundenansicht</a>";
                echo "</div>";
                
                exit;
            } catch (Exception $e) {
                $errors[] = 'Fehler beim Speichern: ' . $e->getMessage();
            }
        } else {
            $fullName = trim($data['firstname'] . ' ' . $data['lastname']);
            $stmt = $db->prepare("INSERT INTO customers (name, firstname, lastname, company, street, zip, city, phone, email, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$fullName, $data['firstname'], $data['lastname'], $data['company'], $data['street'], $data['zip'], $data['city'], $data['phone'], $data['email'], $data['notes']]);
            $newId = $db->lastInsertId();
            flash($data['firstname'] . ' ' . $data['lastname'] . ' erfolgreich angelegt!', 'success');
            echo "<div style='background:green;color:white;padding:20px;margin:20px;'>";
            echo "<h2>✅ Kunde erfolgreich angelegt!</h2>";
            echo "<a href='" . BASE_URL . "/customer_view.php?id=$newId' style='color:white;text-decoration:underline;'>Zur Kundenansicht</a>";
            echo "</div>";
            
																																											   
															
																																											   
								  
            exit;
        }
    }
    
    $customer = array_merge($customer, $data);
}
?>

<?php if (!empty($errors)): ?>
<div class="flash error">❌ <?= implode('<br>', array_map('h', $errors)) ?></div>
<?php endif; ?>

<form method="post" action="">
  <input type="hidden" name="csrf_token" value="<?= csrf() ?>">

  <div class="card">
    <div class="card-header"><h3>👤 Persönliche Daten</h3></div>
    <div class="card-body">
      <div class="form-row cols-2">
        <div class="form-group">
          <label class="form-label">Vorname</label>
          <input type="text" name="firstname" class="form-control" value="<?= h($customer['firstname']) ?>" placeholder="Max" autofocus>
        </div>
        <div class="form-group">
          <label class="form-label">Nachname <span class="required">*</span></label>
          <input type="text" name="lastname" class="form-control" value="<?= h($customer['lastname']) ?>" placeholder="Mustermann" required>
        </div>
      </div>
      <div class="form-row cols-2">
        <div class="form-group">
          <label class="form-label">Firma / Unternehmen</label>
          <input type="text" name="company" class="form-control" value="<?= h($customer['company'] ?? '') ?>" placeholder="Muster GmbH (optional)">
        </div>
        <div class="form-group">
          <label class="form-label">Telefon</label>
          <input type="text" name="phone" class="form-control" value="<?= h($customer['phone'] ?? '') ?>" placeholder="+43 664 1234567">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">E-Mail Adresse</label>
        <input type="email" name="email" class="form-control" value="<?= h($customer['email'] ?? '') ?>" placeholder="max@beispiel.at">
        <p class="form-help">Wird für Ticket-Benachrichtigungen verwendet.</p>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>📍 Adresse</h3></div>
    <div class="card-body">
      <div class="form-group">
        <label class="form-label">Straße & Hausnummer</label>
        <input type="text" name="street" class="form-control" value="<?= h($customer['street'] ?? '') ?>" placeholder="Hauptstraße 123">
      </div>
      <div class="form-row cols-2">
        <div class="form-group">
          <label class="form-label">PLZ</label>
          <input type="text" name="zip" class="form-control" value="<?= h($customer['zip'] ?? '') ?>" placeholder="1010" style="max-width:120px;">
        </div>
        <div class="form-group">
          <label class="form-label">Ort / Stadt</label>
          <input type="text" name="city" class="form-control" value="<?= h($customer['city'] ?? '') ?>" placeholder="Wien">
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>📝 Interne Notizen</h3></div>
    <div class="card-body">
      <div class="form-group">
        <textarea name="notes" class="form-control" rows="4" placeholder="Interne Notizen zum Kunden (nur für Mitarbeiter sichtbar)..."><?= h($customer['notes'] ?? '') ?></textarea>
        <p class="form-help">Diese Notizen sind nicht für den Kunden sichtbar.</p>
      </div>
    </div>
  </div>

  <div style="display:flex;gap:12px;justify-content:flex-end;">
    <a href="<?= BASE_URL ?>/customers.php" class="btn btn-secondary">Abbrechen</a>
    <button type="submit" class="btn btn-primary">💾 <?= $isEdit ? 'Änderungen speichern' : 'Kunden anlegen' ?></button>
  </div>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
