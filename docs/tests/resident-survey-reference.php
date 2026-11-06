<?php
/** CLI-only renderer/export of unchanged source for disposable-fixture parity. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__, 2);
session_save_path(sys_get_temp_dir());
require $root . '/config/db.php'; require $root . '/config/functions.php';
$_SESSION = ['resident_id' => (int)$argv[2], 'resident_name' => 'Survey Fixture'];
$pages = ['surveys', 'survey_form', 'history', 'view_response', 'export_response'];
if (!in_array($argv[1], $pages, true)) { exit(1); }
$_GET = ['id' => (int)($argv[3] ?? 0)];
$_SERVER['PHP_SELF'] = '/resident/' . $argv[1] . '.php';
chdir($root . '/resident');
require $argv[1] . '.php';
session_unset(); session_destroy();
