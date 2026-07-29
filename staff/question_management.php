<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_staff_login();

$page_title = 'Question Management';
$asset_path = '../';
$survey_id = (int)($_GET['survey_id'] ?? 0);
$error = '';

$stmt = $conn->prepare("SELECT * FROM surveys WHERE survey_id = ?");
$stmt->bind_param('i', $survey_id);
$stmt->execute();
$survey = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$survey) {
    redirect('survey_management.php');
}

// Delete a question
if (isset($_GET['delete'])) {
    $qid = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM survey_questions WHERE question_id = ? AND survey_id = ?");
    $stmt->bind_param('ii', $qid, $survey_id);
    $stmt->execute();
    $stmt->close();
    redirect('question_management.php?survey_id=' . $survey_id);
}

// Add a question
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $text = trim($_POST['question_text'] ?? '');
    $type = $_POST['question_type'] ?? 'short_answer';
    $choices = $_POST['choices'] ?? [];

    if ($text === '') {
        $error = 'Question text is required.';
    } else {
        $order_result = $conn->query("SELECT COALESCE(MAX(question_order),0)+1 AS next_order FROM survey_questions WHERE survey_id = $survey_id");
        $next_order = $order_result->fetch_assoc()['next_order'];

        $stmt = $conn->prepare("INSERT INTO survey_questions (survey_id, question_text, question_type, question_order) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('issi', $survey_id, $text, $type, $next_order);
        $stmt->execute();
        $question_id = $conn->insert_id;
        $stmt->close();

        if ($type === 'multiple_choice') {
            $c_stmt = $conn->prepare("INSERT INTO survey_choices (question_id, choice_text) VALUES (?, ?)");
            foreach ($choices as $choice_text) {
                $choice_text = trim($choice_text);
                if ($choice_text === '') continue;
                $c_stmt->bind_param('is', $question_id, $choice_text);
                $c_stmt->execute();
            }
            $c_stmt->close();
        }
        redirect('question_management.php?survey_id=' . $survey_id);
    }
}

$questions = [];
$q_result = $conn->query("SELECT * FROM survey_questions WHERE survey_id = $survey_id ORDER BY question_order ASC");
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

$type_labels = [
    'multiple_choice' => 'Multiple Choice',
    'yesno' => 'Yes/No',
    'rating' => 'Rating Scale',
    'short_answer' => 'Short Answer',
];

require_once '../includes/header_staff.php';
?>
<div class="card">
    <h2>Questions for: <?= h($survey['title']) ?></h2>
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

    <?php if (empty($questions)): ?>
        <p style="color:#6b7c76;">No questions yet. Add one below.</p>
    <?php else: ?>
        <table>
            <tr><th>#</th><th>Question</th><th>Type</th><th>Choices</th><th></th></tr>
            <?php foreach ($questions as $i => $q): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= h($q['question_text']) ?></td>
                <td><?= h($type_labels[$q['question_type']]) ?></td>
                <td><?= isset($q['choices']) ? h(implode(', ', array_column($q['choices'], 'choice_text'))) : '&mdash;' ?></td>
                <td><a href="question_management.php?survey_id=<?= $survey_id ?>&delete=<?= $q['question_id'] ?>" data-confirm="Delete this question and all related answers?">Delete</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Add Question</h3>
    <form method="POST" id="add-question-form">
        <label for="question_text">Question Text</label>
        <input type="text" id="question_text" name="question_text" required>

        <div class="question-row">
            <label for="question_type">Question Type</label>
            <select id="question_type" name="question_type" class="question-type-select">
                <option value="multiple_choice">Multiple Choice</option>
                <option value="yesno">Yes/No</option>
                <option value="rating">Rating Scale (1-5)</option>
                <option value="short_answer">Short Answer</option>
            </select>

            <div class="choices-box" style="display:none;">
                <label>Choices</label>
                <div class="choices-inputs">
                    <input type="text" name="choices[]" placeholder="Choice 1">
                    <input type="text" name="choices[]" placeholder="Choice 2">
                </div>
                <button type="button" class="add-choice-btn btn-secondary btn-small" data-target=".choices-inputs" data-name="choices">+ Add Choice</button>
            </div>
        </div>

        <button type="submit">Add Question</button>
    </form>
</div>

<a class="btn btn-secondary" href="survey_management.php">Back to Survey List</a>
<?php require_once '../includes/footer.php'; ?>
