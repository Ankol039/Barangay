<?php
/** CLI-only oracle: execute the original, unchanged PHP, never the API implementation. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__, 2);
session_save_path(sys_get_temp_dir());
require $root . '/config/db.php';
require $root . '/config/functions.php';
$mode = $argv[1] ?? 'dashboard';
$id = (int)($argv[2] ?? 0);
$row = $conn->query("SELECT role FROM staff WHERE staff_id = $id")->fetch_assoc();
$_SESSION = ['staff_id' => $id, 'staff_role' => $row['role'] ?? 'staff', 'staff_name' => 'Dashboard Fixture'];
parse_str($argv[4] ?? '', $_GET);
chdir($root . '/staff');
$_SERVER['PHP_SELF'] = '/staff/' . ($mode === 'stats' ? 'ajax_stats.php' : 'dashboard.php');
if ($mode === 'stats' || ($argv[3] ?? '') === 'html') {
    require $mode === 'stats' ? 'ajax_stats.php' : 'dashboard.php';
} else {
    $source = file_get_contents('dashboard.php');
    // Prefix contains the complete read-only dashboard queries and PHP helpers.
    eval('?>' . substr($source, 0, strpos($source, "require_once '../includes/header_staff.php';")));
    $keys = explode(' ', 'total_surveys active_surveys total_residents total_responses pending_address_requests inactive_surveys closed_surveys avg_responses responses_7 responses_prev_7 new_residents_30 permanent_residents temporary_residents eligible_residents responding_residents participation resp_dir resp_delta surveys_overview completion_rates top_responders demographics_ready is_admin_view');
    $output = [];
    foreach ($keys as $key) { $output[$key] = $$key; }
    $output['latest_response_label'] = $latest_response ? date('M j, Y', strtotime($latest_response)) : '—';
    if ($is_admin_view) {
        $output['admin'] = compact('total_staff', 'deactivated_staff', 'deactivated_residents', 'pending_deletion_requests');
        $output['admin']['login_counts'] = portal_breakdown($recent_logins);
        $output['admin']['logout_counts'] = portal_breakdown($recent_logouts);
        foreach (['recent_logins', 'recent_logouts'] as $key) {
            $output['admin'][$key] = array_map(function ($row) {
                return ['person' => $row['person'], 'type_label' => login_type_label($row),
                    'time_label' => date('M j, Y g:i A', strtotime($row['login_time']))];
            }, $$key);
        }
    }
    echo json_encode($output, JSON_THROW_ON_ERROR);
}
session_unset(); session_destroy();
