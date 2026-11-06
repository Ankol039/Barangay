<?php
/** Render original GET pages only, as a disposable fixture staff member. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$pages = ['list' => 'survey_management.php', 'add' => 'add_survey.php', 'edit' => 'edit_survey.php', 'questions' => 'question_management.php'];
$mode = $argv[1] ?? ''; if (!isset($pages[$mode])) exit(1);
$root = dirname(__DIR__, 2); session_save_path(sys_get_temp_dir());
require $root . '/config/db.php'; require $root . '/config/functions.php';
$id = (int)($argv[2] ?? 0);
$_SESSION = ['staff_id' => $id, 'staff_role' => 'staff', 'staff_name' => 'Survey Management Fixture'];
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['PHP_SELF'] = '/staff/' . $pages[$mode];
$survey = (int)($argv[3] ?? 0); $_GET = ['id' => $survey, 'survey_id' => $survey]; $_POST = [];
if ($mode === 'add' && ($argv[4] ?? '') === 'invalid-builder') {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['title' => 'bad', 'description' => 'Retain this description after validation.',
        'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d', strtotime('+7 days')),
        'questions' => [['text' => 'Discard this unsaved question', 'type' => 'yesno'], ['text' => 'Discard the second question', 'type' => 'rating']]];
}
chdir($root . '/staff'); require $pages[$mode];
session_unset(); session_destroy();
