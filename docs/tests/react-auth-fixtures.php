<?php
/** CLI-only disposable accounts for manual React browser checks. Never use real accounts. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
$state_file = sys_get_temp_dir() . '/barangay-react-auth-fixtures.json';
$session_dir = sys_get_temp_dir() . '/barangay-react-auth-session';
if (!is_dir($session_dir)) {
    mkdir($session_dir, 0700);
}
session_save_path($session_dir);
require dirname(__DIR__, 2) . '/config/db.php';
require dirname(__DIR__, 2) . '/config/functions.php';
$action = $argv[1] ?? '';
$fixtures = is_file($state_file) ? json_decode(file_get_contents($state_file), true, 512, JSON_THROW_ON_ERROR) : [];

function execute_fixture(string $query, array $values): mysqli_stmt {
    global $conn;
    $stmt = $conn->prepare($query);
    $stmt->bind_param(str_repeat('s', count($values)), ...$values);
    $stmt->execute();
    return $stmt;
}

try {
    if ($action === 'create') {
        if ($fixtures) {
            throw new RuntimeException('Clean the previous fixture run first.');
        }
        // This public, test-only string belongs exclusively to these temporary rows.
        $hash = password_hash('ReactAuthTestA1', PASSWORD_DEFAULT);
        $suffix = random_int(1000000, 9999999);
        foreach ([['resident', 'resident', 0, 1], ['residentFirst', 'resident', 1, 1],
                  ['staff', 'staff', 0, 1], ['staffFirst', 'staff', 1, 0],
                  ['staffProfile', 'staff', 0, 0], ['admin', 'admin', 0, 1]] as [$label, $role, $first, $profile]) {
            $staff = $role !== 'resident';
            $number = ($staff ? 'S-' : 'R-') . '2099-' . $suffix++;
            if ($staff) {
                $stmt = execute_fixture("INSERT INTO staff (staff_number, username, full_name, first_name, password, role, is_active, is_first_login, profile_completed) VALUES (?, ?, 'React UI Fixture', 'React', ?, ?, 1, ?, ?)", [$number, $number, $hash, $role, $first, $profile]);
            } else {
                $stmt = execute_fixture("INSERT INTO residents (resident_number, first_name, last_name, password, residency_status, is_active, is_first_login) VALUES (?, 'React', 'UI Fixture', ?, 'permanent', 1, ?)", [$number, $hash, $first]);
            }
            $fixtures[$label] = ['type' => $staff ? 'staff' : 'resident', 'id' => $stmt->insert_id, 'number' => $number];
            $stmt->close();
            file_put_contents($state_file, json_encode($fixtures, JSON_THROW_ON_ERROR));
            chmod($state_file, 0600);
        }
        foreach ($fixtures as $label => $fixture) {
            echo $label . ': ' . $fixture['number'] . "\n";
        }
    } elseif (in_array($action, ['finish-password', 'finish-profile', 'deactivate', 'archive'], true)) {
        $fixture = $fixtures[$argv[2] ?? ''] ?? null;
        if (!$fixture) {
            throw new RuntimeException('Unknown fixture.');
        }
        $staff = $fixture['type'] === 'staff';
        $table = $staff ? 'staff' : 'residents';
        $key = $staff ? 'staff_id' : 'resident_id';
        $assignment = ['finish-password' => 'is_first_login = 0', 'finish-profile' => 'profile_completed = 1',
                       'deactivate' => 'is_active = 0', 'archive' => 'deleted_at = NOW()'][$action];
        if ($action === 'finish-profile' && !$staff) {
            throw new RuntimeException('Residents have no profile gate.');
        }
        execute_fixture("UPDATE $table SET $assignment WHERE $key = ?", [$fixture['id']])->close();
        echo "Fixture state updated.\n";
    } elseif ($action === 'clean') {
        foreach ($fixtures as $fixture) {
            execute_fixture('DELETE FROM login_history WHERE user_type = ? AND user_id = ?', [$fixture['type'], $fixture['id']])->close();
            $staff = $fixture['type'] === 'staff';
            $table = $staff ? 'staff' : 'residents';
            $key = $staff ? 'staff_id' : 'resident_id';
            execute_fixture("DELETE FROM $table WHERE $key = ?", [$fixture['id']])->close();
        }
        if (is_file($state_file)) {
            unlink($state_file);
        }
        echo "Removed fixture accounts and their login history.\n";
    } else {
        throw new RuntimeException('Use create, clean, or a fixture state action.');
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof mysqli_sql_exception ? "Fixture database operation failed.\n" : $error->getMessage() . "\n");
    exit(1);
} finally {
    session_unset();
    session_destroy();
    foreach (glob($session_dir . '/*') as $file) {
        unlink($file);
    }
    rmdir($session_dir);
}
