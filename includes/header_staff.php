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
    <div class="brand">Barangay Health Center <small>Staff / Admin Portal</small></div>
    <?php if (isset($_SESSION['staff_id'])): ?>
    <nav class="no-print">
        <a href="dashboard.php">Dashboard</a>
        <a href="survey_management.php">Surveys</a>
        <a href="resident_management.php">Residents</a>
        <a href="results_dashboard.php">Results</a>
        <a href="reports.php">Reports</a>
        <?php if (($_SESSION['staff_role'] ?? '') === 'admin'): ?>
        <a href="user_management.php">Staff Accounts</a>
        <?php endif; ?>
        <a href="logout.php">Logout</a>
    </nav>
    <?php endif; ?>
</div>
<div class="container">
