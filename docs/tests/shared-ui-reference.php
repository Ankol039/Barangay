<?php
/** CLI-only, database-free rendering of the real PHP includes and survey markup. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__, 2);
session_save_path(sys_get_temp_dir());
require $root . '/config/functions.php';
$resident = ($argv[1] ?? 'staff') === 'resident';
$_SESSION = $resident
    ? ['resident_id' => 1, 'resident_name' => 'React UI Fixture']
    : ['staff_id' => 1, 'staff_name' => 'React UI Fixture', 'staff_role' => 'admin'];
$_SERVER['PHP_SELF'] = $resident ? '/resident/surveys.php' : '/staff/survey_management.php';
$asset_path = 'http://localhost/barangay_survey_system/';
$page_title = 'Survey Management';
// No database include or connection: no application records are read or written.
require $root . '/includes/' . ($resident ? 'header_resident.php' : 'header_staff.php');
$rows = [
    ['survey_id' => 1, 'title' => 'Health Center Services', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'status' => 'active', 'response_count' => 12],
    ['survey_id' => 2, 'title' => 'Community Wellness', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'inactive', 'response_count' => 4],
];
$surveys = new class($rows) {
    private array $rows;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function fetch_assoc() { return array_shift($this->rows); }
};
$source = file_get_contents($root . '/staff/survey_management.php');
$start = strpos($source, '<div class="page-head">');
$end = strrpos($source, "<?php require_once '../includes/footer.php'; ?>");
// Execute only the unchanged presentation segment, using synthetic rows.
eval('?>' . substr($source, $start, $end - $start));
require $root . '/includes/footer.php';
session_unset();
session_destroy();
