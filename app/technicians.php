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
$pageTitle = 'Techniker verwalten';
$activeMenu = 'technicians';
require_once __DIR__ . '/../includes/header.php';

if (!isAdmin()) {
    flash('Nur Admins können Techniker verwalten.', 'error');
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = getDB();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
        
        if (!$name) $errors[] = 'Name fehlt.';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Gültige E-Mail erforderlich.';
        if (strlen($password) < 6) $errors[] = 'Passwort mindestens 6 Zeichen.';
        
        
        if (!$errors) {
            $check = $db->prepare("SELECT id FROM technicians WHERE email = ?");
            $check->execute([$email]);
            if ($check->fetch()) $errors[] = 'Diese E-Mail ist bereits vergeben.';
        }
        
        if (!$errors) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO technicians (name, email, password, is_admin) VALUES (?, ?, ?, ?)")
               ->execute([$name, $email, $hash, $isAdmin]);
            flash('Techniker ' . $name . ' wurde angelegt.', 'success');
            header('Location: ' . BASE_URL . '/technicians.php');
            exit;
        }
    }
    
    elseif ($action === 'delete') {
        $delId = (int)$_POST['del_id'];
        if ($delId === (int)$_SESSION['technician_id']) {
            flash('Sie können sich nicht selbst löschen.', 'error');
        } else {
            $db->prepare("UPDATE tickets SET technician_id = NULL WHERE technician_id = ?")->execute([$delId]);
            $db->prepare("DELETE FROM technicians WHERE id = ?")->execute([$delId]);
            flash('Techniker gelöscht.', 'success');
        }
        header('Location: ' . BASE_URL . '/technicians.php');
        exit;
    }
    
    elseif ($action === 'change_password') {
        $techId = (int)$_POST['tech_id'];
        $newPw = $_POST['new_password'] ?? '';
        if (strlen($newPw) >= 6) {
            $hash = password_hash($newPw, PASSWORD_DEFAULT);
            $db->prepare("UPDATE technicians SET password = ? WHERE id = ?")->execute([$hash, $techId]);
            flash('Passwort geändert.', 'success');
        } else {
            flash('Passwort muss mindestens 6 Zeichen haben.', 'error');
        }
        header('Location: ' . BASE_URL . '/technicians.php');
        exit;
    }
}

$technicians = $db->query("
    SELECT t.*, COUNT(tk.id) as ticket_count
    FROM technicians t
    LEFT JOIN tickets tk ON t.id = tk.technician_id
    GROUP BY t.id
    ORDER BY t.name ASC
")->fetchAll();
?>

<?php if (!empty($errors)): ?>
<div class="flash error">❌ <?= implode('<br>', array_map('h', $errors)) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start;">
  
  <div class="card">
    <div class="card-header"><h3>👨‍🔧 Techniker-Liste</h3></div>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr><th>Name</th><th>E-Mail</th><th>Rolle</th><th>Tickets</th><th>Aktionen</th></tr>
        </thead>
        <tbody>
          <?php foreach ($technicians as $t): ?>
          <tr>
            <td>
              <strong><?= h($t['name']) ?></strong>
              <?php if ($t['id'] == $_SESSION['technician_id']): ?><span style="font-size:11px;color:#2563eb;margin-left:4px;">(Sie)</span><?php endif; ?>
            </td>
            <td><?= h($t['email']) ?></td>
            <td><?= $t['is_admin'] ? '<span style="color:#7c3aed;font-weight:600;">Admin</span>' : 'Techniker' ?></td>
            <td><?= $t['ticket_count'] ?></td>
            <td style="display:flex;gap:6px;">
              <!-- Passwort ändern -->
              <button onclick="document.getElementById('pw-<?= $t['id'] ?>').style.display='block'" class="btn btn-secondary btn-sm">🔑 PW</button>
              <?php if ($t['id'] != $_SESSION['technician_id']): ?>
              <form method="post" action="" onsubmit="return confirm('Techniker <?= h(addslashes($t['name'])) ?> wirklich löschen?')">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="del_id" value="<?= $t['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">🗑️</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <!-- Passwort-Inline-Formular -->
          <tr id="pw-<?= $t['id'] ?>" style="display:none;background:#fffbeb;">
            <td colspan="5">
              <form method="post" action="" style="display:flex;gap:8px;padding:4px 0;align-items:center;">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="tech_id" value="<?= $t['id'] ?>">
                <input type="password" name="new_password" class="form-control" placeholder="Neues Passwort (min. 6 Zeichen)" style="max-width:280px;">
                <button type="submit" class="btn btn-warning btn-sm">Speichern</button>
                <button type="button" onclick="document.getElementById('pw-<?= $t['id'] ?>').style.display='none'" class="btn btn-secondary btn-sm">Abbrechen</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  
  <!-- Neuer Techniker -->
  <div class="card">
    <div class="card-header"><h3>➕ Neuen Techniker anlegen</h3></div>
    <div class="card-body">
      <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
        <input type="hidden" name="action" value="add">
        <div class="form-group">
          <label class="form-label">Name <span class="required">*</span></label>
          <input type="text" name="name" class="form-control" placeholder="Max Mustermann" value="<?= h($_POST['name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">E-Mail <span class="required">*</span></label>
          <input type="email" name="email" class="form-control" placeholder="max@firma.de" value="<?= h($_POST['email'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Passwort <span class="required">*</span></label>
          <input type="password" name="password" class="form-control" placeholder="Mindestens 6 Zeichen">
        </div>
        <div class="form-group" style="display:flex;align-items:center;gap:8px;">
          <input type="checkbox" name="is_admin" id="is_admin" value="1">
          <label for="is_admin" style="cursor:pointer;font-size:14px;">Administrator-Rechte</label>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;">👨‍🔧 Techniker anlegen</button>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
