<?php
require_once '../config/db.php';
require_once '../config/functions.php';
require_resident_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('surveys.php');
}

$resident_id = $_SESSION['resident_id'];
$survey_id = (int)($_POST['survey_id'] ?? 0);

$stmt = $conn->prepare("SELECT * FROM surveys WHERE survey_id = ?");
$stmt->bind_param('i', $survey_id);
$stmt->execute();
$survey = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$survey || !survey_is_open($survey)) {
    redirect('surveys.php');
}

// Prevent duplicate submissions (also enforced by a UNIQUE key in the database)
$stmt = $conn->prepare("SELECT 1 FROM responses WHERE survey_id = ? AND resident_id = ?");
$stmt->bind_param('ii', $survey_id, $resident_id);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    redirect('surveys.php');
}
$stmt->close();

$conn->begin_transaction();
try {
    $stmt = $conn->prepare("INSERT INTO responses (survey_id, resident_id) VALUES (?, ?)");
    $stmt->bind_param('ii', $survey_id, $resident_id);
    $stmt->execute();
    $response_id = $conn->insert_id;
    $stmt->close();

    $q_stmt = $conn->prepare("SELECT * FROM survey_questions WHERE survey_id = ?");
    $q_stmt->bind_param('i', $survey_id);
    $q_stmt->execute();
    $questions = $q_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $q_stmt->close();

    $insert = $conn->prepare("
        INSERT INTO survey_results (response_id, question_id, choice_id, rating_value, answer_text)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($questions as $q) {
        $field = 'q' . $q['question_id'];
        $raw = $_POST[$field] ?? null;

        $choice_id = null;
        $rating_value = null;
        $answer_text = null;

        if ($q['question_type'] === 'multiple_choice' && $raw && strpos($raw, 'choice_') === 0) {
            $choice_id = (int)str_replace('choice_', '', $raw);
        } elseif ($q['question_type'] === 'yesno') {
            $answer_text = $raw;
        } elseif ($q['question_type'] === 'rating') {
            $rating_value = $raw !== null && $raw !== '' ? (int)$raw : null;
        } else {
            $answer_text = trim($raw ?? '');
        }

        // Skip entirely unanswered optional questions
        if ($choice_id === null && $rating_value === null && ($answer_text === null || $answer_text === '')) {
            continue;
        }

        $insert->bind_param('iiiis', $response_id, $q['question_id'], $choice_id, $rating_value, $answer_text);
        $insert->execute();
    }
    $insert->close();

    $conn->commit();
    redirect('confirmation.php?survey_id=' . $survey_id);
} catch (Exception $e) {
    $conn->rollback();
    die('An error occurred while submitting your survey. Please try again.');
}
