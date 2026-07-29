<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_resident_login();

$page_title = 'Available Surveys';
$asset_path = '../';
$resident_id = $_SESSION['resident_id'];

$stmt = $conn->prepare("
    SELECT s.*,
        EXISTS(SELECT 1 FROM responses r WHERE r.survey_id = s.survey_id AND r.resident_id = ?) AS already_answered
    FROM surveys s
    WHERE s.status = 'active' AND CURDATE() BETWEEN s.start_date AND s.end_date
    ORDER BY s.start_date DESC
");
$stmt->bind_param('i', $resident_id);
$stmt->execute();
$surveys = $stmt->get_result();

require_once '../includes/header_resident.php';
?>
<div class="card">
    <h2>Available Surveys</h2>
    <?php if ($surveys->num_rows === 0): ?>
        <p style="color:#6b7c76;">There are no active surveys at the moment. Please check back later.</p>
    <?php else: ?>
        <table>
            <tr><th>Title</th><th>Description</th><th>Closes</th><th>Status</th><th></th></tr>
            <?php while ($s = $surveys->fetch_assoc()): ?>
            <tr>
                <td><?= h($s['title']) ?></td>
                <td><?= h(mb_strimwidth($s['description'] ?? '', 0, 80, '...')) ?></td>
                <td><?= h($s['end_date']) ?></td>
                <td>
                    <?php if ($s['already_answered']): ?>
                        <span class="badge badge-inactive">Answered</span>
                    <?php else: ?>
                        <span class="badge badge-active">Open</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($s['already_answered']): ?>
                        <span style="color:#6b7c76;font-size:0.85rem;">Thank you!</span>
                    <?php else: ?>
                        <a class="btn btn-small" href="survey_form.php?id=<?= (int)$s['survey_id'] ?>">Answer Survey</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
        </table>
    <?php endif; ?>
</div>
<?php require_once '../includes/footer.php'; ?>
