<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_resident_login();

$page_title = 'Change Password';
$asset_path = '../';
$message = '';
$error = '';

$resident_id = $_SESSION['resident_id'];
$stmt = $conn->prepare("SELECT is_first_login FROM residents WHERE resident_id = ?");
$stmt->bind_param('i', $resident_id);
$stmt->execute();
$is_first_login = $stmt->get_result()->fetch_assoc()['is_first_login'];
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $stmt = $conn->prepare("SELECT password FROM residents WHERE resident_id = ?");
    $stmt->bind_param('i', $resident_id);
    $stmt->execute();
    $current_hash = $stmt->get_result()->fetch_assoc()['password'];
    $stmt->close();

    if (!password_verify($current, $current_hash)) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New password and confirmation do not match.';
    } else {
        $new_hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE residents SET password = ?, is_first_login = 0 WHERE resident_id = ?");
        $stmt->bind_param('si', $new_hash, $resident_id);
        $stmt->execute();
        $stmt->close();
        redirect('dashboard.php');
    }
}

require_once '../includes/header_resident.php';
?>
<div class="card" style="max-width:480px;margin:0 auto;">
    <h2>Change Password</h2>
    <?php if ($is_first_login): ?>
        <div class="alert alert-info">This is your first login. Please set a new password before continuing.</div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
    <form method="POST">
        <label for="current_password">Current Password</label>
        <input type="password" id="current_password" name="current_password" required>

        <label for="new_password">New Password</label>
        <input type="password" id="new_password" name="new_password" required minlength="6">

        <label for="confirm_password">Confirm New Password</label>
        <input type="password" id="confirm_password" name="confirm_password" required minlength="6">

        <button type="submit">Update Password</button>
    </form>
</div>
<?php require_once '../includes/footer.php'; ?>
