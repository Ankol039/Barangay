<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($page_title) ? h($page_title) . ' - ' : '' ?>Barangay Health Center Survey System</title>
<link rel="stylesheet" href="<?= $asset_path ?? '' ?>assets/css/style.css">
</head>
<body>
<div class="top-nav">
    <div class="brand">Barangay Health Center <small>Resident Portal</small></div>
    <?php if (isset($_SESSION['resident_id'])): ?>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="surveys.php">Available Surveys</a>
        <a href="profile.php">Profile</a>
        <a href="change_password.php">Change Password</a>
        <a href="logout.php">Logout</a>
    </nav>
    <?php endif; ?>
</div>
<div class="container">
