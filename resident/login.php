<?php
require_once '../config/db.php';
require_once '../config/functions.php';

if (isset($_SESSION['resident_id'])) {
    redirect('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resident_number = trim($_POST['resident_number'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($resident_number === '' || $password === '') {
        $error = 'Please enter your Resident Number and password.';
    } else {
        $stmt = $conn->prepare("SELECT * FROM residents WHERE resident_number = ?");
        $stmt->bind_param('s', $resident_number);
        $stmt->execute();
        $result = $stmt->get_result();
        $resident = $result->fetch_assoc();
        $stmt->close();

        if ($resident && password_verify($password, $resident['password'])) {
            $_SESSION['resident_id'] = $resident['resident_id'];
            $_SESSION['resident_name'] = $resident['first_name'] . ' ' . $resident['last_name'];
            log_login($conn, 'resident', $resident['resident_id']);

            if ($resident['is_first_login']) {
                redirect('change_password.php');
            }
            redirect('dashboard.php');
        } else {
            $error = 'Invalid Resident Number or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Resident Login</title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-box">
        <h1>Resident Login</h1>
        <p class="subtitle">Barangay Health Center Survey System</p>
        <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
        <form method="POST" novalidate>
            <label for="resident_number">Resident Number</label>
            <input type="text" id="resident_number" name="resident_number" required autofocus>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>

            <button type="submit" style="width:100%;">Log In</button>
        </form>
        <p style="margin-top:16px;font-size:0.85rem;color:#6b7c76;">
            Default password is your Resident Number. You will be asked to change it on first login.
        </p>
        <p style="font-size:0.85rem;"><a href="../index.php">&larr; Back to home</a> &middot; <a href="../staff/login.php">Staff login</a></p>
    </div>
</div>
</body>
</html>
