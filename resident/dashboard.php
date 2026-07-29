<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_resident_login();

$page_title = 'Dashboard';
$asset_path = '../';
$resident_id = $_SESSION['resident_id'];

// Count available (open, not yet answered) surveys
$stmt = $conn->prepare("
    SELECT COUNT(*) AS cnt FROM surveys s
    WHERE s.status = 'active' AND CURDATE() BETWEEN s.start_date AND s.end_date
    AND NOT EXISTS (SELECT 1 FROM responses r WHERE r.survey_id = s.survey_id AND r.resident_id = ?)
");
$stmt->bind_param('i', $resident_id);
$stmt->execute();
$available_count = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// Count surveys already answered by this resident
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM responses WHERE resident_id = ?");
$stmt->bind_param('i', $resident_id);
$stmt->execute();
$answered_count = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

require_once '../includes/header_resident.php';
?>
<div class="card">
    <h2>Welcome, <?= h($_SESSION['resident_name']) ?>!</h2>
    <p style="color:#6b7c76;">Here's a quick look at your survey activity.</p>
</div>

<div class="grid-3">
    <div class="stat-card">
        <div class="stat-number"><?= (int)$available_count ?></div>
        <div class="stat-label">Surveys Available</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= (int)$answered_count ?></div>
        <div class="stat-label">Surveys Completed</div>
    </div>
    <div class="stat-card">
        <a class="btn" href="surveys.php" style="width:100%;">View Available Surveys</a>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
