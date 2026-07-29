<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_admin_login();

$page_title = 'Staff Accounts';
$asset_path = '../';
$error = '';
$message = '';

// Delete a staff account (cannot delete yourself)
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id === (int)$_SESSION['staff_id']) {
        $error = 'You cannot delete your own account while logged in.';
    } else {
        $stmt = $conn->prepare("DELETE FROM staff WHERE staff_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $message = 'Staff account deleted.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'staff';

    if ($username === '' || $full_name === '' || $password === '') {
        $error = 'All fields are required.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        $stmt = $conn->prepare("SELECT 1 FROM staff WHERE username = ?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = 'That username is already taken.';
        }
        $stmt->close();

        if (!$error) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO staff (username, full_name, password, role) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('ssss', $username, $full_name, $hash, $role);
            $stmt->execute();
            $stmt->close();
            $message = 'Staff account created.';
        }
    }
}

$staff_list = $conn->query("SELECT * FROM staff ORDER BY full_name");

require_once '../includes/header_staff.php';
?>
<div class="card">
    <h2>Add Staff Account</h2>
    <?php if ($message): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
    <form method="POST">
        <div class="grid-2">
            <div>
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div>
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" required>
            </div>
            <div>
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required minlength="6">
            </div>
            <div>
                <label for="role">Role</label>
                <select id="role" name="role">
                    <option value="staff">Staff</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
        </div>
        <button type="submit">Add Staff Account</button>
    </form>
</div>

<div class="card">
    <h2>Staff Accounts</h2>
    <table>
        <tr><th>Username</th><th>Full Name</th><th>Role</th><th>Created</th><th></th></tr>
        <?php while ($s = $staff_list->fetch_assoc()): ?>
        <tr>
            <td><?= h($s['username']) ?></td>
            <td><?= h($s['full_name']) ?></td>
            <td><?= h(ucfirst($s['role'])) ?></td>
            <td><?= h($s['created_at']) ?></td>
            <td>
                <?php if ((int)$s['staff_id'] !== (int)$_SESSION['staff_id']): ?>
                    <a href="user_management.php?delete=<?= (int)$s['staff_id'] ?>" data-confirm="Delete this staff account?">Delete</a>
                <?php else: ?>
                    <span style="color:#6b7c76;">(you)</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endwhile; ?>
    </table>
</div>
<?php require_once '../includes/footer.php'; ?>
