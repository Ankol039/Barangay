<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_staff_login();

$page_title = 'Resident Management';
$asset_path = '../';
$error = '';
$message = '';

// Reset a resident's password back to their resident number, and force change on next login
if (isset($_GET['reset'])) {
    $rid = (int)$_GET['reset'];
    $stmt = $conn->prepare("SELECT resident_number FROM residents WHERE resident_id = ?");
    $stmt->bind_param('i', $rid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $hash = password_hash($row['resident_number'], PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE residents SET password = ?, is_first_login = 1 WHERE resident_id = ?");
        $stmt->bind_param('si', $hash, $rid);
        $stmt->execute();
        $stmt->close();
        $message = 'Password reset to default (resident number).';
    }
}

// Add a new resident
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resident_number = trim($_POST['resident_number'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($resident_number === '' || $first_name === '' || $last_name === '') {
        $error = 'Resident Number, First Name, and Last Name are required.';
    } else {
        $stmt = $conn->prepare("SELECT 1 FROM residents WHERE resident_number = ?");
        $stmt->bind_param('s', $resident_number);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = 'A resident with that Resident Number already exists.';
        }
        $stmt->close();

        if (!$error) {
            $default_hash = password_hash($resident_number, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("
                INSERT INTO residents (resident_number, first_name, last_name, email, contact_number, address, password, is_first_login)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->bind_param('sssssss', $resident_number, $first_name, $last_name, $email, $contact_number, $address, $default_hash);
            $stmt->execute();
            $stmt->close();
            $message = 'Resident added. Default password is their Resident Number.';
        }
    }
}

$residents = $conn->query("SELECT * FROM residents ORDER BY last_name, first_name");

require_once '../includes/header_staff.php';
?>
<div class="card">
    <h2>Add Resident</h2>
    <?php if ($message): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
    <form method="POST">
        <div class="grid-2">
            <div>
                <label for="resident_number">Resident Number</label>
                <input type="text" id="resident_number" name="resident_number" required>
            </div>
            <div>
                <label for="email">Email</label>
                <input type="email" id="email" name="email">
            </div>
            <div>
                <label for="first_name">First Name</label>
                <input type="text" id="first_name" name="first_name" required>
            </div>
            <div>
                <label for="last_name">Last Name</label>
                <input type="text" id="last_name" name="last_name" required>
            </div>
            <div>
                <label for="contact_number">Contact Number</label>
                <input type="tel" id="contact_number" name="contact_number">
            </div>
            <div>
                <label for="address">Address</label>
                <input type="text" id="address" name="address">
            </div>
        </div>
        <button type="submit">Add Resident</button>
    </form>
</div>

<div class="card">
    <h2>Registered Residents (<?= $residents->num_rows ?>)</h2>
    <table>
        <tr><th>Resident No.</th><th>Name</th><th>Contact</th><th>Address</th><th>First Login Pending?</th><th></th></tr>
        <?php while ($r = $residents->fetch_assoc()): ?>
        <tr>
            <td><?= h($r['resident_number']) ?></td>
            <td><?= h($r['first_name'] . ' ' . $r['last_name']) ?></td>
            <td><?= h($r['contact_number']) ?></td>
            <td><?= h($r['address']) ?></td>
            <td><?= $r['is_first_login'] ? 'Yes' : 'No' ?></td>
            <td><a href="resident_management.php?reset=<?= (int)$r['resident_id'] ?>" data-confirm="Reset this resident's password to their Resident Number?">Reset Password</a></td>
        </tr>
        <?php endwhile; ?>
    </table>
</div>
<?php require_once '../includes/footer.php'; ?>
