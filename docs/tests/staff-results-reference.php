<?php
/** CLI-only oracle: execute the unchanged original PHP with a fixture identity. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$pages = ['results' => 'results_dashboard.php', 'report' => 'reports.php', 'export' => 'export_csv.php', 'detail' => 'stat_detail.php', 'stats' => 'ajax_stats.php'];
$mode = $argv[1] ?? ''; if (!isset($pages[$mode]) && $mode !== 'resident-stats') exit(1);
$root = dirname(__DIR__, 2); session_save_path(sys_get_temp_dir());
require $root . '/config/db.php'; require $root . '/config/functions.php';
$id = (int)($argv[2] ?? 0);
if ($mode === 'resident-stats') {
    $_SESSION = ['resident_id' => $id]; chdir($root . '/resident'); require 'ajax_stats.php'; session_unset(); session_destroy(); exit;
}
 $role = $conn->query("SELECT role FROM staff WHERE staff_id = $id")->fetch_assoc()['role'] ?? 'staff';
$_SESSION = ['staff_id' => $id, 'staff_role' => $role, 'staff_name' => 'Survey Management Fixture'];
parse_str($argv[3] ?? '', $_GET); $_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['PHP_SELF'] = '/staff/' . $pages[$mode]; chdir($root . '/staff');
if (($argv[4] ?? '') === 'html' || $mode === 'export' || $mode === 'stats') require $pages[$mode];
else {
    $source = file_get_contents($pages[$mode]);
    eval('?>' . substr($source, 0, strpos($source, "require_once '../includes/header_staff.php';")));
    if ($mode === 'detail') {
        unset($view['sql']);
        $cells = array_map($view['row'], $rows); unset($view['row']);
        echo json_encode($view + ['rows' => $rows, 'cells' => $cells, 'count' => count($rows)], JSON_THROW_ON_ERROR);
    } else echo json_encode(compact('survey', 'questions', 'total_respondents'), JSON_THROW_ON_ERROR);
}
session_unset(); session_destroy();
