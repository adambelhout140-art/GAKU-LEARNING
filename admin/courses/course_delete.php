<?php
require_once __DIR__ . '/../../config/i18n.php';

// ============================================================
// * ADMIN COURSE DELETE ENDPOINT
// ============================================================
// ! RESTRICTED ACCESS: Requires active administrator credentials.
// ? Purpose: Safely deletes a course record via HTTP POST only.
// ? Cleans up associated cover image assets from storage.
// ? Cascading database constraints handle dependent tables (e.g., enrollments, modules).
// ============================================================


// ------------------------------------------------------------
// * SESSION HANDLING & DATABASE CONNECTION
// ------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../../config/database.php";
require_once "../../config/audit.php";


// ------------------------------------------------------------
// * ACCESS CONTROL & REQUEST METHOD VERIFICATION
// ! Rejects unauthenticated users, non-admin roles, and non-POST methods.
// ------------------------------------------------------------
if (!isset($_SESSION["user_id"])) {
    header("Location: ../../auth/login.php");
    exit;
}

if (($_SESSION["user_role"] ?? "") !== "admin") {
    header("Location: ../../index.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: courses.php");
    exit;
}


// ------------------------------------------------------------
// * PARAMETER SANITIZATION & CSRF VALIDATION
// ------------------------------------------------------------
$courseId = filter_input(INPUT_POST, "course_id", FILTER_VALIDATE_INT);
$token    = $_POST["csrf_token"] ?? "";

if (
    empty($_SESSION["csrf_token"]) ||
    !hash_equals($_SESSION["csrf_token"], $token) ||
    !$courseId ||
    $courseId < 1
) {
    header("Location: courses.php?error=delete");
    exit;
}


// ------------------------------------------------------------
// * RECORD RESOLUTION
// ? Verifies course existence and fetches cover image path prior to deletion.
// ------------------------------------------------------------
$find = $pdo->prepare("SELECT cover_image FROM courses WHERE id = ? LIMIT 1");
$find->execute([$courseId]);
$course = $find->fetch();

if (!$course) {
    header("Location: courses.php?error=delete");
    exit;
}


// ------------------------------------------------------------
// * DATABASE DELETION & STORAGE CLEANUP
// ------------------------------------------------------------
$delete = $pdo->prepare("DELETE FROM courses WHERE id = ?");
$delete->execute([$courseId]);
auditLog($pdo, (int) $_SESSION["user_id"], "course_deleted", "course", (int) $courseId);

// ? Remove attached cover image from server disk if it exists
if (!empty($course["cover_image"])) {
    $path = "../../uploads/courses/" . basename($course["cover_image"]);
    
    if (is_file($path)) {
        unlink($path);
    }
}


// ------------------------------------------------------------
// * SUCCESS REDIRECT
// ------------------------------------------------------------
header("Location: courses.php?success=deleted");
exit;
