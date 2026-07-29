<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_resident_login();

$resident_id = $_SESSION['resident_id'];
$survey_id = (int)($_GET['id'] ?? 0);

$stmt = $conn->prepare("SELECT * FROM surveys WHERE survey_id = ?");
$stmt->bind_param('i', $survey_id);
$stmt->execute();
$survey = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$survey || !survey_is_open($survey)) {
    redirect('surveys.php');
}

// Block resubmission
$stmt = $conn->prepare("SELECT 1 FROM responses WHERE survey_id = ? AND resident_id = ?");
$stmt->bind_param('ii', $survey_id, $resident_id);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    redirect('surveys.php');
}
$stmt->close();

$questions = [];
$q_stmt = $conn->prepare("SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY question_order ASC, question_id ASC");
$q_stmt->bind_param('i', $survey_id);
$q_stmt->execute();
$q_result = $q_stmt->get_result();
while ($q = $q_result->fetch_assoc()) {
    if ($q['question_type'] === 'multiple_choice') {
        $c_stmt = $conn->prepare("SELECT * FROM survey_choices WHERE question_id = ?");
        $c_stmt->bind_param('i', $q['question_id']);
        $c_stmt->execute();
        $q['choices'] = $c_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $c_stmt->close();
    }
    $questions[] = $q;
}
$q_stmt->close();

$page_title = h($survey['title']);
$asset_path = '../';
require_once '../includes/header_resident.php';
?>
<div class="card">
    <h2><?= h($survey['title']) ?></h2>
    <p style="color:#6b7c76;"><?= nl2br(h($survey['description'])) ?></p>

    <form method="POST" action="submit.php" id="survey-answer-form">
        <input type="hidden" name="survey_id" value="<?= $survey_id ?>">

        <?php foreach ($questions as $i => $q): $field = 'q' . $q['question_id']; ?>
        <div class="card" style="background:#fafcfb;" data-required-group="<?= $field ?>">
            <label><?= ($i + 1) . '. ' . h($q['question_text']) ?></label>

            <?php if ($q['question_type'] === 'multiple_choice'): ?>
                <?php foreach ($q['choices'] as $c): ?>
                    <label style="font-weight:400;">
                        <input type="radio" name="<?= $field ?>" value="choice_<?= $c['choice_id'] ?>" style="width:auto;display:inline-block;margin-right:8px;">
                        <?= h($c['choice_text']) ?>
                    </label>
                <?php endforeach; ?>

            <?php elseif ($q['question_type'] === 'yesno'): ?>
                <label style="font-weight:400;"><input type="radio" name="<?= $field ?>" value="Yes" style="width:auto;display:inline-block;margin-right:8px;"> Yes</label>
                <label style="font-weight:400;"><input type="radio" name="<?= $field ?>" value="No" style="width:auto;display:inline-block;margin-right:8px;"> No</label>

            <?php elseif ($q['question_type'] === 'rating'): ?>
                <div class="rating-group">
                    <?php for ($r = 1; $r <= 5; $r++): ?>
                        <label><input type="radio" name="<?= $field ?>" value="<?= $r ?>"><span><?= $r ?></span></label>
                    <?php endfor; ?>
                </div>
                <p style="font-size:0.8rem;color:#6b7c76;margin-top:-10px;">1 = Poor, 5 = Excellent</p>

            <?php else: ?>
                <textarea name="<?= $field ?>" placeholder="Type your answer here (optional)"></textarea>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <button type="submit">Submit Survey</button>
        <a class="btn btn-secondary" href="surveys.php">Cancel</a>
    </form>
</div>
<?php require_once '../includes/footer.php'; ?>
