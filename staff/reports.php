<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_staff_login();

$page_title = 'Printable Report';
$asset_path = '../';

$surveys = $conn->query("SELECT survey_id, title FROM surveys ORDER BY created_at DESC");
$survey_id = (int)($_GET['survey_id'] ?? 0);
$survey = null;
$questions = [];
$total_respondents = 0;

if ($survey_id) {
    $stmt = $conn->prepare("SELECT s.*, st.full_name AS created_by_name FROM surveys s LEFT JOIN staff st ON st.staff_id = s.created_by WHERE s.survey_id = ?");
    $stmt->bind_param('i', $survey_id);
    $stmt->execute();
    $survey = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($survey) {
        $stmt = $conn->prepare("SELECT COUNT(*) c FROM responses WHERE survey_id = ?");
        $stmt->bind_param('i', $survey_id);
        $stmt->execute();
        $total_respondents = $stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        $q_result = $conn->query("SELECT * FROM survey_questions WHERE survey_id = $survey_id ORDER BY question_order ASC");
        while ($q = $q_result->fetch_assoc()) {
            $qid = $q['question_id'];
            if ($q['question_type'] === 'multiple_choice') {
                $stmt = $conn->prepare("
                    SELECT c.choice_text, COUNT(sr.answer_id) AS votes
                    FROM survey_choices c
                    LEFT JOIN survey_results sr ON sr.choice_id = c.choice_id
                    WHERE c.question_id = ? GROUP BY c.choice_id, c.choice_text
                ");
            } elseif ($q['question_type'] === 'yesno') {
                $stmt = $conn->prepare("
                    SELECT answer_text AS choice_text, COUNT(*) AS votes
                    FROM survey_results WHERE question_id = ? AND answer_text IN ('Yes','No') GROUP BY answer_text
                ");
            } elseif ($q['question_type'] === 'rating') {
                $stmt = $conn->prepare("
                    SELECT CONCAT(rating_value,' star(s)') AS choice_text, COUNT(*) AS votes
                    FROM survey_results WHERE question_id = ? AND rating_value IS NOT NULL GROUP BY rating_value ORDER BY rating_value
                ");
            } else {
                $stmt = $conn->prepare("SELECT answer_text FROM survey_results WHERE question_id = ? AND answer_text IS NOT NULL AND answer_text != ''");
            }
            $stmt->bind_param('i', $qid);
            $stmt->execute();
            $q['rows'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $questions[] = $q;
        }
    }
}

require_once '../includes/header_staff.php';
?>
<div class="card no-print">
    <form method="GET">
        <label for="survey_id">Select a Survey</label>
        <select id="survey_id" name="survey_id" onchange="this.form.submit()">
            <option value="">-- Choose a survey --</option>
            <?php while ($s = $surveys->fetch_assoc()): ?>
                <option value="<?= (int)$s['survey_id'] ?>" <?= $s['survey_id'] == $survey_id ? 'selected' : '' ?>><?= h($s['title']) ?></option>
            <?php endwhile; ?>
        </select>
    </form>
    <?php if ($survey_id): ?>
        <div style="margin-top:14px;">
            <button onclick="window.print()">Print Report</button>
            <a class="btn btn-secondary" href="export_csv.php?survey_id=<?= $survey_id ?>">Export to CSV (Excel)</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($survey): ?>
<div class="card">
    <h2 style="text-align:center;margin-bottom:0;">Barangay Health Center</h2>
    <p style="text-align:center;color:#6b7c76;margin-top:4px;">Survey Report</p>
    <hr>
    <p><strong>Survey Title:</strong> <?= h($survey['title']) ?></p>
    <p><strong>Description:</strong> <?= h($survey['description']) ?></p>
    <p><strong>Period:</strong> <?= h($survey['start_date']) ?> to <?= h($survey['end_date']) ?></p>
    <p><strong>Prepared by:</strong> <?= h($survey['created_by_name'] ?? 'N/A') ?></p>
    <p><strong>Total Respondents:</strong> <?= (int)$total_respondents ?></p>
</div>

<?php foreach ($questions as $i => $q): ?>
<div class="card">
    <h3><?= ($i + 1) . '. ' . h($q['question_text']) ?></h3>
    <?php if ($q['question_type'] === 'short_answer'): ?>
        <?php if (empty($q['rows'])): ?>
            <p style="color:#6b7c76;">No responses.</p>
        <?php else: ?>
            <ul><?php foreach ($q['rows'] as $r): ?><li><?= h($r['answer_text']) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    <?php else: ?>
        <table>
            <tr><th>Option</th><th>Count</th></tr>
            <?php foreach ($q['rows'] as $r): ?>
                <tr><td><?= h($r['choice_text']) ?></td><td><?= (int)$r['votes'] ?></td></tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
