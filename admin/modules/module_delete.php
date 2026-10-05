<?php
require_once __DIR__ . '/../../config/i18n.php';

// ============================================================
// * GAKU LEARNING - ADMIN MODULE DELETE ACTION
// ============================================================
// ! RESTRICTED ACTION: Only authenticated Admins and Teachers can trigger.
// ? Purpose: Safely deletes a module, verifies ownership, and reorders remaining course modules in a database transaction.
// ============================================================


// ------------------------------------------------------------
// * SESSION HANDLING
// ? Starts or resumes session to verify identity and retrieve CSRF secrets.
// ------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


// ------------------------------------------------------------
// * FILE DEPENDENCIES
// ! Configures database PDO connection and access verification helpers.
// ------------------------------------------------------------
require_once "../../config/database.php";
require_once "../../config/audit.php";
require_once "../../config/content_access.php";


// ------------------------------------------------------------
// * ACCESS CONTROL: AUTHENTICATION CHECK
// ! Bounces unauthenticated guests directly to the login panel.
// ------------------------------------------------------------
if (!isset($_SESSION["user_id"])) {
    header("Location: ../../auth/login.php");
    exit;
}


// ------------------------------------------------------------
// * ACCESS CONTROL: ROLE CHECK
// ! Denies execution to users who are neither admins nor teachers.
// ------------------------------------------------------------
if (!in_array($_SESSION["user_role"] ?? "", ["admin", "teacher"], true)) {
    header("Location: ../../index.php");
    exit;
}


// ------------------------------------------------------------
// * HTTP METHOD VERIFICATION
// ! Enforces POST protocol to prevent accidental CSRF deletion via direct URL navigation.
// ------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: modules.php");
    exit;
}


// ------------------------------------------------------------
// * INPUT SANITIZATION & CSRF VALIDATION
// ? Reads incoming payload and matches the token against session using timing-safe comparisons.
// ------------------------------------------------------------
$moduleId = filter_input(INPUT_POST, "module_id", FILTER_VALIDATE_INT);
$token = $_POST["csrf_token"] ?? "";

if (
    empty($_SESSION["csrf_token"]) ||
    !hash_equals($_SESSION["csrf_token"], $token) ||
    !$moduleId ||
    $moduleId < 1
) {
    header("Location: modules.php?error=delete");
    exit;
}


// ------------------------------------------------------------
// * DATABASE QUERY: FETCH TARGET MODULE
// ? Confirms module existence, parent course ID, and current position index.
// ------------------------------------------------------------
$find = $pdo->prepare("SELECT course_id, position FROM modules WHERE id = ? LIMIT 1");
$find->execute([$moduleId]);
$module = $find->fetch();


// ------------------------------------------------------------
// * AUTHORIZATION & PERMISSION VERIFICATION
// ! Confirms the user has active privileges to manage the parent course.
// ------------------------------------------------------------
if (!$module || !canManageCourse($pdo, (int) $module["course_id"], (int) $_SESSION["user_id"], $_SESSION["user_role"])) {
    header("Location: modules.php?error=delete");
    exit;
}


// ------------------------------------------------------------
// * TRANSACTION: DELETE MODULE & REORDER POSITIONS
// ! Deletes target record and decrements higher positions to prevent sequence gaps.
// ------------------------------------------------------------
$pdo->beginTransaction();

try {

    // * Step 1: Remove module record from database
    $pdo->prepare("DELETE FROM modules WHERE id = ? AND course_id = ?")
        ->execute([$moduleId, $module["course_id"]]);
    auditLog($pdo, (int) $_SESSION["user_id"], "module_deleted", "module", (int) $moduleId);

    // * Step 2: Shift subsequent module positions left (decrement position) to plug gap
    $pdo->prepare("UPDATE modules SET position = position - 1 WHERE course_id = ? AND position > ?")
        ->execute([$module["course_id"], $module["position"]]);

    // * Commit changes safely to MySQL database
    $pdo->commit();

    // * Redirect back to course module list view
    header("Location: modules.php?course_id=" . $module["course_id"]);
    exit;

} catch (Throwable $exception) {

    // ! Revert all pending queries if any database error occurs
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // * Redirect back with error query flag on failure
    header("Location: modules.php?course_id=" . $module["course_id"] . "&error=delete");
    exit;

}
