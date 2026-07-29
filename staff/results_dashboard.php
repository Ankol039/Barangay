<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_staff_login();

$page_title = 'Results Dashboard';
$asset_path = '../';

$surveys = $conn->query("SELECT survey_id, title FROM surveys ORDER BY created_at DESC");
$survey_id = (int)($_GET['survey_id'] ?? 0);

$survey = null;
$questions = [];
$total_respondents = 0;

if ($survey_id) {
    $stmt = $conn->prepare("SELECT * FROM surveys WHERE survey_id = ?");
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
                    WHERE c.question_id = ?
                    GROUP BY c.choice_id, c.choice_text
                ");
                $stmt->bind_param('i', $qid);
                $stmt->execute();
                $q['breakdown'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

            } elseif ($q['question_type'] === 'yesno') {
                $stmt = $conn->prepare("
                    SELECT answer_text AS choice_text, COUNT(*) AS votes
                    FROM survey_results WHERE question_id = ? AND answer_text IN ('Yes','No')
                    GROUP BY answer_text
                ");
                $stmt->bind_param('i', $qid);
                $stmt->execute();
                $q['breakdown'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

            } elseif ($q['question_type'] === 'rating') {
                $stmt = $conn->prepare("SELECT AVG(rating_value) AS avg_rating, COUNT(rating_value) AS n FROM survey_results WHERE question_id = ? AND rating_value IS NOT NULL");
                $stmt->bind_param('i', $qid);
                $stmt->execute();
                $agg = $stmt->get_result()->fetch_assoc();
                $q['avg_rating'] = $agg['avg_rating'] ? round($agg['avg_rating'], 2) : 0;
                $q['rating_n'] = $agg['n'];
                $stmt->close();

                $stmt = $conn->prepare("SELECT rating_value, COUNT(*) AS votes FROM survey_results WHERE question_id = ? AND rating_value IS NOT NULL GROUP BY rating_value ORDER BY rating_value");
                $stmt->bind_param('i', $qid);
                $stmt->execute();
                $q['breakdown'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

            } else { // short_answer
                $stmt = $conn->prepare("SELECT answer_text FROM survey_results WHERE question_id = ? AND answer_text IS NOT NULL AND answer_text != '' ORDER BY answer_id DESC LIMIT 20");
                $stmt->bind_param('i', $qid);
                $stmt->execute();
                $q['answers'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }
            $questions[] = $q;
        }
    }
}

require_once '../includes/header_staff.php';
?>
<div class="card no-print">
    <h2>Results Dashboard</h2>
    <form method="GET">
        <label for="survey_id">Select a Survey</label>
        <select id="survey_id" name="survey_id" onchange="this.form.submit()">
            <option value="">-- Choose a survey --</option>
            <?php $surveys->data_seek(0); while ($s = $surveys->fetch_assoc()): ?>
                <option value="<?= (int)$s['survey_id'] ?>" <?= $s['survey_id'] == $survey_id ? 'selected' : '' ?>><?= h($s['title']) ?></option>
            <?php endwhile; ?>
        </select>
    </form>
</div>

<?php if ($survey): ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <div>
            <h2 style="margin-bottom:4px;"><?= h($survey['title']) ?></h2>
            <p style="color:#6b7c76;margin-top:0;"><?= (int)$total_respondents ?> respondent(s) &middot; <?= h($survey['start_date']) ?> to <?= h($survey['end_date']) ?></p>
        </div>
        <div class="no-print">
            <a class="btn btn-small" href="reports.php?survey_id=<?= $survey_id ?>">Printable Report</a>
        </div>
    </div>
</div>

<?php foreach ($questions as $i => $q): ?>
<div class="card">
    <h3><?= ($i + 1) . '. ' . h($q['question_text']) ?></h3>

    <?php if (in_array($q['question_type'], ['multiple_choice', 'yesno'])): ?>
        <?php
            $max_votes = 1;
            foreach ($q['breakdown'] as $b) { $max_votes = max($max_votes, (int)$b['votes']); }
            $total_votes = array_sum(array_column($q['breakdown'], 'votes'));
        ?>
        <?php foreach ($q['breakdown'] as $b): $pct = $total_votes ? round($b['votes'] / $total_votes * 100) : 0; ?>
            <div class="bar-row">
                <div class="bar-label"><?= h($b['choice_text']) ?></div>
                <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;"></div></div>
                <div class="bar-value"><?= (int)$b['votes'] ?> (<?= $pct ?>%)</div>
            </div>
        <?php endforeach; ?>

    <?php elseif ($q['question_type'] === 'rating'): ?>
        <p><strong>Average Rating: <?= $q['avg_rating'] ?> / 5</strong> (<?= (int)$q['rating_n'] ?> ratings)</p>
        <?php
            $total_votes = array_sum(array_column($q['breakdown'], 'votes'));
        ?>
        <?php foreach ($q['breakdown'] as $b): $pct = $total_votes ? round($b['votes'] / $total_votes * 100) : 0; ?>
            <div class="bar-row">
                <div class="bar-label"><?= (int)$b['rating_value'] ?> star(s)</div>
                <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;"></div></div>
                <div class="bar-value"><?= (int)$b['votes'] ?> (<?= $pct ?>%)</div>
            </div>
        <?php endforeach; ?>

    <?php else: ?>
        <?php if (empty($q['answers'])): ?>
            <p style="color:#6b7c76;">No text responses yet.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($q['answers'] as $a): ?>
                    <li><?= h($a['answer_text']) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php elseif ($survey_id): ?>
    <div class="alert alert-error">Survey not found.</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
