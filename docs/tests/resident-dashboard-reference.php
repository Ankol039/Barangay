<?php
/** CLI-only source-of-truth runner; executes the unchanged PHP before its header. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__, 2);
session_save_path(sys_get_temp_dir());
require $root . '/config/db.php';
require $root . '/config/functions.php';
$_SESSION = ['resident_id' => (int)($argv[2] ?? 0), 'resident_name' => 'Dashboard Fixture'];
$mode = $argv[1] ?? 'dashboard';
chdir($root . '/resident');
$_SERVER['PHP_SELF'] = '/resident/' . ($mode === 'dashboard' ? 'dashboard.php' : 'stat_detail.php');
if (($argv[3] ?? '') === 'html') {
    if ($mode !== 'dashboard') { $_GET['view'] = $mode; }
    require $mode === 'dashboard' ? 'dashboard.php' : 'stat_detail.php';
} elseif ($mode === 'stats') {
    require 'ajax_stats.php';
} else {
    if ($mode !== 'dashboard') { $_GET['view'] = $mode; }
    $source = file_get_contents($mode === 'dashboard' ? 'dashboard.php' : 'stat_detail.php');
    eval('?>' . substr($source, 0, strpos($source, "require_once '../includes/header_resident.php';")));
    if ($mode === 'dashboard') {
        // Cast counts as the PHP markup does. Never serialize the SELECT * resident.
        $output = compact('activity_months', 'household_rows', 'residency_months', 'residency_minimum', 'residency_pct', 'has_activity', 'has_household', 'has_residency_progress', 'is_temporary', 'residence_name', 'residence_code', 'assigned_total', 'completion_pct', 'next_deadline', 'days_left', 'upcoming', 'recent_submissions');
        $output['available_count'] = (int)$available_count;
        $output['answered_count'] = (int)$answered_count;
        $output['me'] = array_intersect_key($me, array_flip(['address', 'household_number', 'barangay', 'municipality', 'province', 'temporary_address', 'temp_barangay', 'temp_municipality', 'temp_province', 'residency_start_date']));
    } else {
        $output = ['view' => $view_key, 'count' => count($rows), 'rows' => $rows];
    }
    echo json_encode($output, JSON_THROW_ON_ERROR);
}
session_unset(); session_destroy();
