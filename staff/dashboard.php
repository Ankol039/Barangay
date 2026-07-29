<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_staff_login();

$page_title = 'Dashboard';
$asset_path = '../';

$total_surveys = $conn->query("SELECT COUNT(*) c FROM surveys")->fetch_assoc()['c'];
$active_surveys = $conn->query("SELECT COUNT(*) c FROM surveys WHERE status='active' AND CURDATE() BETWEEN start_date AND end_date")->fetch_assoc()['c'];
$total_residents = $conn->query("SELECT COUNT(*) c FROM residents")->fetch_assoc()['c'];
$total_responses = $conn->query("SELECT COUNT(*) c FROM responses")->fetch_assoc()['c'];

$recent_surveys = $conn->query("SELECT * FROM surveys ORDER BY created_at DESC LIMIT 5");

require_once '../includes/header_staff.php';
?>
<div class="card">
    <h2>Welcome, <?= h($_SESSION['staff_name']) ?></h2>
    <p style="color:#6b7c76;">Overview of the barangay survey system.</p>
</div>

<div class="grid-3">
    <div class="stat-card"><div class="stat-number"><?= (int)$total_surveys ?></div><div class="stat-label">Total Surveys</div></div>
    <div class="stat-card"><div class="stat-number"><?= (int)$active_surveys ?></div><div class="stat-label">Currently Active</div></div>
    <div class="stat-card"><div class="stat-number"><?= (int)$total_residents ?></div><div class="stat-label">Registered Residents</div></div>
</div>
<div class="grid-3" style="margin-top:0;">
    <div class="stat-card"><div class="stat-number"><?= (int)$total_responses ?></div><div class="stat-label">Total Responses</div></div>
    <div class="stat-card"><a class="btn" href="add_survey.php" style="width:100%;">+ Create New Survey</a></div>
    <div class="stat-card"><a class="btn btn-secondary" href="resident_management.php" style="width:100%;">Manage Residents</a></div>
</div>

<div class="card">
    <h3>Recent Surveys</h3>
    <table>
        <tr><th>Title</th><th>Period</th><th>Status</th><th></th></tr>
        <?php while ($s = $recent_surveys->fetch_assoc()): ?>
        <tr>
            <td><?= h($s['title']) ?></td>
            <td><?= h($s['start_date']) ?> &ndash; <?= h($s['end_date']) ?></td>
            <td><span class="badge <?= $s['status'] === 'active' ? 'badge-active' : 'badge-inactive' ?>"><?= h(ucfirst($s['status'])) ?></span></td>
            <td><a href="results_dashboard.php?survey_id=<?= (int)$s['survey_id'] ?>">View Results</a></td>
        </tr>
        <?php endwhile; ?>
    </table>
</div>
<?php require_once '../includes/footer.php'; ?>
