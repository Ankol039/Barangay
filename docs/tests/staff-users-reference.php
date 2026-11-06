<?php
/** CLI-only original-PHP oracle; uses disposable fixture IDs supplied by the runner. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__, 2); session_save_path(sys_get_temp_dir());
require $root . '/config/db.php'; require $root . '/config/functions.php';
$id = (int)$argv[1]; $page = $argv[2]; $mode = $argv[3] ?? 'post';
if (!in_array($page, ['user_management', 'complete_profile'], true)) exit(1);
$role = $conn->query("SELECT role FROM staff WHERE staff_id = $id")->fetch_assoc()['role'];
$_SESSION = ['staff_id' => $id, 'staff_role' => $role, 'staff_name' => 'Survey Management Fixture'];
$input = $mode === 'html' ? [] : (json_decode(stream_get_contents(STDIN), true) ?: []);
$_GET = $input['get'] ?? []; $_POST = $input['post'] ?? [];
$_SERVER['REQUEST_METHOD'] = $_POST ? 'POST' : 'GET'; $_SERVER['PHP_SELF'] = '/staff/' . $page . '.php';
chdir($root . '/staff'); ob_start();
register_shutdown_function(function () use ($mode) {
    $html = ob_get_clean();
    echo $mode === 'html' ? preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $html) : json_encode([
        'error' => $GLOBALS['error'] ?? '', 'outcome' => $_SESSION['flash_profile_saved'] ?? '',
        'message' => $_SESSION['flash_success'] ?? ($_SESSION['flash_success'] ?? ($GLOBALS['message'] ?? ''))]);
    session_unset(); session_destroy();
});
if ($mode === 'html') require $page . '.php';
else {
    $source = file_get_contents($page . '.php');
    eval('?>' . substr($source, 0, strpos($source, "require_once '../includes/header_staff.php';")));
}
