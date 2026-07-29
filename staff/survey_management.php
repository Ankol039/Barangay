<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_staff_login();

$page_title = 'Survey Management';
$asset_path = '../';

// Toggle active/inactive
if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $stmt = $conn->prepare("UPDATE surveys SET status = IF(status='active','inactive','active') WHERE survey_id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    redirect('survey_management.php');
}

$surveys = $conn->query("
    SELECT s.*, (SELECT COUNT(*) FROM responses r WHERE r.survey_id = s.survey_id) AS response_count
    FROM surveys s ORDER BY s.created_at DESC
");

require_once '../includes/header_staff.php';
?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <h2 style="margin:0;">All Surveys</h2>
        <a class="btn" href="add_survey.php">+ Create New Survey</a>
    </div>
</div>

<div class="card">
    <table>
        <tr><th>Title</th><th>Period</th><th>Status</th><th>Responses</th><th>Actions</th></tr>
        <?php while ($s = $surveys->fetch_assoc()): ?>
        <tr>
            <td><?= h($s['title']) ?></td>
            <td><?= h($s['start_date']) ?> &ndash; <?= h($s['end_date']) ?></td>
            <td><span class="badge <?= $s['status'] === 'active' ? 'badge-active' : 'badge-inactive' ?>"><?= h(ucfirst($s['status'])) ?></span></td>
            <td><?= (int)$s['response_count'] ?></td>
            <td>
                <a href="edit_survey.php?id=<?= (int)$s['survey_id'] ?>">Edit</a> &middot;
                <a href="question_management.php?survey_id=<?= (int)$s['survey_id'] ?>">Questions</a> &middot;
                <a href="results_dashboard.php?survey_id=<?= (int)$s['survey_id'] ?>">Results</a> &middot;
                <a href="survey_management.php?toggle=<?= (int)$s['survey_id'] ?>" data-confirm="Toggle status of this survey?">
                    <?= $s['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                </a>
            </td>
        </tr>
        <?php endwhile; ?>
    </table>
</div>
<?php require_once '../includes/footer.php'; ?>
