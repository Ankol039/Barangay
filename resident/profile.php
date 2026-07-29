<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_resident_login();

$page_title = 'My Profile';
$asset_path = '../';
$resident_id = $_SESSION['resident_id'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($first_name === '' || $last_name === '') {
        $error = 'First and last name are required.';
    } else {
        $stmt = $conn->prepare("
            UPDATE residents SET first_name=?, last_name=?, email=?, contact_number=?, address=?
            WHERE resident_id=?
        ");
        $stmt->bind_param('sssssi', $first_name, $last_name, $email, $contact_number, $address, $resident_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['resident_name'] = $first_name . ' ' . $last_name;
        $message = 'Profile updated successfully.';
    }
}

$stmt = $conn->prepare("SELECT * FROM residents WHERE resident_id = ?");
$stmt->bind_param('i', $resident_id);
$stmt->execute();
$resident = $stmt->get_result()->fetch_assoc();
$stmt->close();

require_once '../includes/header_resident.php';
?>
<div class="card" style="max-width:520px;margin:0 auto;">
    <h2>Update Personal Information</h2>
    <p style="color:#6b7c76;">Resident Number: <strong><?= h($resident['resident_number']) ?></strong> (cannot be changed)</p>

    <?php if ($message): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

    <form method="POST">
        <label for="first_name">First Name</label>
        <input type="text" id="first_name" name="first_name" value="<?= h($resident['first_name']) ?>" required>

        <label for="last_name">Last Name</label>
        <input type="text" id="last_name" name="last_name" value="<?= h($resident['last_name']) ?>" required>

        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= h($resident['email']) ?>">

        <label for="contact_number">Contact Number</label>
        <input type="tel" id="contact_number" name="contact_number" value="<?= h($resident['contact_number']) ?>">

        <label for="address">Address</label>
        <textarea id="address" name="address"><?= h($resident['address']) ?></textarea>

        <button type="submit">Save Changes</button>
    </form>
</div>
<?php require_once '../includes/footer.php'; ?>
