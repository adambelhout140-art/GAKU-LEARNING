<?php
require_once __DIR__ . '/../../config/i18n.php';

// ============================================================
// * GAKU LEARNING - ADMIN LESSON DELETE ACTION
// ============================================================
// ! RESTRICTED ACTION: Only authenticated Admins and Teachers can trigger.
// ? Purpose: Deletes a lesson and re-sequences remaining positions inside a transaction.
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
// ! Enforces POST protocol to prevent accidental CSRF triggers via direct URL navigation.
// ------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: lessons.php");
    exit;
}


// ------------------------------------------------------------
// * INPUT SANITIZATION & CSRF VALIDATION
// ? Reads incoming payload and matches the token against session using timing-safe comparisons.
// ------------------------------------------------------------
$lessonId = filter_input(INPUT_POST, "lesson_id", FILTER_VALIDATE_INT);
$token = $_POST["csrf_token"] ?? "";

if (
    empty($_SESSION["csrf_token"]) ||
    !hash_equals($_SESSION["csrf_token"], $token) ||
    !$lessonId ||
    $lessonId < 1
) {
    header("Location: lessons.php?error=delete");
    exit;
}


// ------------------------------------------------------------
// * DATABASE QUERY: FETCH TARGET LESSON
// ? Confirms lesson existence, parent module ID, and current position.
// ------------------------------------------------------------
$find = $pdo->prepare("SELECT module_id, position FROM lessons WHERE id = ? LIMIT 1");
$find->execute([$lessonId]);
$lesson = $find->fetch();


// ------------------------------------------------------------
// * AUTHORIZATION & PERMISSION VERIFICATION
// ! Confirms the user has active privileges to modify the parent module/lesson.
// ------------------------------------------------------------
if (!$lesson || !canManageLesson($pdo, (int) $lessonId, (int) $_SESSION["user_id"], $_SESSION["user_role"])) {
    header("Location: lessons.php?error=delete");
    exit;
}


// ------------------------------------------------------------
// * TRANSACTION: DELETE LESSON & RE-SEQUENCE POSITIONS
// ! Deletes the lesson record and shifts subsequent lesson positions down by 1.
// ------------------------------------------------------------
$pdo->beginTransaction();

try {

    // * Step 1: Remove lesson record from database
    $pdo->prepare("DELETE FROM lessons WHERE id = ? AND module_id = ?")
        ->execute([$lessonId, $lesson["module_id"]]);
    auditLog($pdo, (int) $_SESSION["user_id"], "lesson_deleted", "lesson", (int) $lessonId);

    // * Step 2: Re-sequence positions for remaining lessons following the deleted index
    $pdo->prepare("UPDATE lessons SET position = position - 1 WHERE module_id = ? AND position > ?")
        ->execute([$lesson["module_id"], $lesson["position"]]);

    // * Commit transaction changes safely to database
    $pdo->commit();

    // ? Redirect back to parent module lesson list view
    header("Location: lessons.php?module_id=" . $lesson["module_id"]);
    exit;

} catch (Throwable $exception) {

    // ! Revert pending query changes if database error occurs
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // ? Redirect with error flag upon failure
    header("Location: lessons.php?module_id=" . $lesson["module_id"] . "&error=delete");
    exit;

}
