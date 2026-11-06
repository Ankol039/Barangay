<?php
/** CLI-only oracle. Executes the original add branch, retaining only its error/flash. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__, 2); session_save_path(sys_get_temp_dir());
require $root . '/config/db.php'; require $root . '/config/functions.php';
$id = (int)$argv[1]; $mode = $argv[2] ?? 'post';
$role = $conn->query("SELECT role FROM staff WHERE staff_id = $id")->fetch_assoc()['role'];
$_SESSION = ['staff_id' => $id, 'staff_role' => $role, 'staff_name' => 'Survey Management Fixture'];
$_GET = []; $_POST = $mode === 'post' ? json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR) + ['add_resident' => '1'] : [];
if ($mode === 'action') {
    $request = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $_GET = $request['get'] ?? []; $_POST = $request['post'] ?? [];
}
$_SERVER['REQUEST_METHOD'] = ($mode === 'post' || ($mode === 'action' && $_POST)) ? 'POST' : 'GET'; $_SERVER['PHP_SELF'] = '/staff/resident_management.php';
chdir($root . '/staff'); ob_start();
register_shutdown_function(function () use ($mode) {
    $html = ob_get_clean();
    if ($mode === 'html') {
        // Original embedded edit lookups contain hashes. Never output those scripts.
        echo preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $html);
    } else echo json_encode(['error' => $_SESSION['flash_error'] ?? ($GLOBALS['error'] ?? ''), 'message' => $_SESSION['flash_success'] ?? ''], JSON_THROW_ON_ERROR);
    session_unset(); session_destroy();
});
if ($mode === 'html') require 'resident_management.php';
else {
    $source = file_get_contents('resident_management.php');
    eval('?>' . substr($source, 0, strpos($source, "require_once '../includes/header_staff.php';")));
}
