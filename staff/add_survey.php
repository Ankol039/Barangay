<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_staff_login();

$page_title = 'Create Survey';
$asset_path = '../';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $questions = $_POST['questions'] ?? [];

    if ($title === '' || $start_date === '' || $end_date === '') {
        $error = 'Title, start date, and end date are required.';
    } elseif ($start_date > $end_date) {
        $error = 'Start date cannot be after end date.';
    } elseif (empty($questions)) {
        $error = 'Please add at least one question.';
    } else {
        $conn->begin_transaction();
        try {
            $staff_id = $_SESSION['staff_id'];
            $stmt = $conn->prepare("INSERT INTO surveys (title, description, start_date, end_date, status, created_by) VALUES (?, ?, ?, ?, 'active', ?)");
            $stmt->bind_param('ssssi', $title, $description, $start_date, $end_date, $staff_id);
            $stmt->execute();
            $survey_id = $conn->insert_id;
            $stmt->close();

            $order = 0;
            foreach ($questions as $q) {
                $text = trim($q['text'] ?? '');
                $type = $q['type'] ?? 'short_answer';
                if ($text === '') continue;
                $order++;

                $q_stmt = $conn->prepare("INSERT INTO survey_questions (survey_id, question_text, question_type, question_order) VALUES (?, ?, ?, ?)");
                $q_stmt->bind_param('issi', $survey_id, $text, $type, $order);
                $q_stmt->execute();
                $question_id = $conn->insert_id;
                $q_stmt->close();

                if ($type === 'multiple_choice' && !empty($q['choices'])) {
                    $c_stmt = $conn->prepare("INSERT INTO survey_choices (question_id, choice_text) VALUES (?, ?)");
                    foreach ($q['choices'] as $choice_text) {
                        $choice_text = trim($choice_text);
                        if ($choice_text === '') continue;
                        $c_stmt->bind_param('is', $question_id, $choice_text);
                        $c_stmt->execute();
                    }
                    $c_stmt->close();
                }
            }
            $conn->commit();
            redirect('survey_management.php');
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'An error occurred while creating the survey.';
        }
    }
}

require_once '../includes/header_staff.php';
?>
<div class="card">
    <h2>Create New Survey</h2>
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

    <form method="POST" id="survey-builder-form">
        <label for="title">Survey Title</label>
        <input type="text" id="title" name="title" required value="<?= h($_POST['title'] ?? '') ?>">

        <label for="description">Description</label>
        <textarea id="description" name="description"><?= h($_POST['description'] ?? '') ?></textarea>

        <div class="grid-2">
            <div>
                <label for="start_date">Opening Date</label>
                <input type="date" id="start_date" name="start_date" required value="<?= h($_POST['start_date'] ?? date('Y-m-d')) ?>">
            </div>
            <div>
                <label for="end_date">Closing Date</label>
                <input type="date" id="end_date" name="end_date" required value="<?= h($_POST['end_date'] ?? date('Y-m-d', strtotime('+7 days'))) ?>">
            </div>
        </div>

        <h3>Questions</h3>
        <div id="questions-container"></div>
        <button type="button" id="add-question-btn" class="btn-secondary" style="margin-bottom:20px;">+ Add Question</button>
        <br>
        <button type="submit">Save Survey</button>
        <a class="btn btn-secondary" href="survey_management.php">Cancel</a>
    </form>
</div>

<template id="question-template">
    <div class="card question-row" style="background:#fafcfb;">
        <label>Question Text</label>
        <input type="text" name="questions[__IDX__][text]" required>

        <label>Question Type</label>
        <select name="questions[__IDX__][type]" class="question-type-select">
            <option value="multiple_choice">Multiple Choice</option>
            <option value="yesno">Yes/No</option>
            <option value="rating">Rating Scale (1-5)</option>
            <option value="short_answer">Short Answer</option>
        </select>

        <div class="choices-box" style="display:none;">
            <label>Choices</label>
            <div class="choices-inputs">
                <input type="text" name="questions[__IDX__][choices][]" placeholder="Choice 1">
                <input type="text" name="questions[__IDX__][choices][]" placeholder="Choice 2">
            </div>
            <button type="button" class="add-choice-btn btn-secondary btn-small" data-target=".choices-inputs" data-name="questions[__IDX__][choices]">+ Add Choice</button>
        </div>

        <button type="button" class="remove-question-btn btn-danger btn-small" style="margin-top:10px;">Remove Question</button>
    </div>
</template>

<script>
(function () {
    var container = document.getElementById('questions-container');
    var template = document.getElementById('question-template');
    var idx = 0;

    function addQuestion() {
        var html = template.innerHTML.replaceAll('__IDX__', idx);
        var wrapper = document.createElement('div');
        wrapper.innerHTML = html;
        var node = wrapper.firstElementChild;
        container.appendChild(node);
        idx++;

        // Wire up the newly added row's behaviors (type toggle, remove, add-choice)
        var select = node.querySelector('.question-type-select');
        var choicesBox = node.querySelector('.choices-box');
        function refresh() {
            choicesBox.style.display = (select.value === 'multiple_choice') ? 'block' : 'none';
        }
        select.addEventListener('change', refresh);
        refresh();

        node.querySelector('.remove-question-btn').addEventListener('click', function () {
            node.remove();
        });

        var addChoiceBtn = node.querySelector('.add-choice-btn');
        addChoiceBtn.addEventListener('click', function () {
            var box = node.querySelector('.choices-inputs');
            var count = box.querySelectorAll('input').length;
            var input = document.createElement('input');
            input.type = 'text';
            input.name = addChoiceBtn.dataset.name + '[]';
            input.placeholder = 'Choice ' + (count + 1);
            box.appendChild(input);
        });
    }

    document.getElementById('add-question-btn').addEventListener('click', addQuestion);
    addQuestion(); // start with one question row
})();
</script>

<?php require_once '../includes/footer.php'; ?>
