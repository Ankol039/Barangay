<?php
/**
 * Shared helper functions.
 */
session_start();

/** Escape output to prevent XSS when echoing user data. */
function h($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Redirect helper. */
function redirect($url) {
    header("Location: $url");
    exit;
}

/** Require an active resident session; otherwise send back to login. */
function require_resident_login() {
    if (!isset($_SESSION['resident_id'])) {
        redirect('login.php');
    }
}

/** Require an active staff session; otherwise send back to login. */
function require_staff_login() {
    if (!isset($_SESSION['staff_id'])) {
        redirect('login.php');
    }
}

/** Require an active staff session with the Admin role; otherwise deny access. */
function require_admin_login() {
    require_staff_login();
    if (($_SESSION['staff_role'] ?? '') !== 'admin') {
        http_response_code(403);
        require_once __DIR__ . '/../includes/header_staff.php';
        echo '<div class="card"><div class="alert alert-error">Access denied. Only Admin accounts can manage staff accounts.</div></div>';
        require_once __DIR__ . '/../includes/footer.php';
        exit;
    }
}

/** Record a login event (optional Login History table). */
function log_login($conn, $user_type, $user_id) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $conn->prepare("INSERT INTO login_history (user_type, user_id, ip_address) VALUES (?, ?, ?)");
    $stmt->bind_param('sis', $user_type, $user_id, $ip);
    $stmt->execute();
    $stmt->close();
}

/** Compute whether a survey is currently open for responses. */
function survey_is_open($survey) {
    $today = date('Y-m-d');
    return $survey['status'] === 'active'
        && $survey['start_date'] <= $today
        && $survey['end_date'] >= $today;
}
