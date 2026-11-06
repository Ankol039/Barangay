<?php
/** CLI-only: finish the legacy bootstrap before the test acquires a unique-key lock. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
session_save_path(sys_get_temp_dir());
require dirname(__DIR__, 2) . '/api/lib/bootstrap.php';
require dirname(__DIR__, 2) . '/api/lib/session.php';
require dirname(__DIR__, 2) . '/api/lib/auth.php';
require dirname(__DIR__, 2) . '/api/lib/resident.php';
require dirname(__DIR__, 2) . '/api/lib/surveys.php';
require dirname(__DIR__, 2) . '/api/lib/survey-submit.php';
$_SESSION = ['resident_id' => (int)$argv[1]];
$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
fwrite(STDERR, "ready\n");
$_POST = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
api_resident_survey_submit($conn);
