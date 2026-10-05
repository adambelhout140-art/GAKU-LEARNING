<?php
require_once __DIR__ . '/../../config/i18n.php';

// ============================================================
// * GAKU LEARNING - ADMIN LESSON REORDER ACTION
// ============================================================
// ! RESTRICTED ACTION: Only authenticated Admins and Teachers can trigger.
// ? Purpose: Swaps neighboring lessons inside the same module safely using a database transaction.
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
$direction = $_POST["direction"] ?? "";
$token = $_POST["csrf_token"] ?? "";

if (
    empty($_SESSION["csrf_token"]) ||
    !hash_equals($_SESSION["csrf_token"], $token) ||
    !$lessonId ||
    !in_array($direction, ["up", "down"], true)
) {
    header("Location: lessons.php?error=reorder");
    exit;
}


// ------------------------------------------------------------
// * DATABASE QUERY: FETCH TARGET LESSON
// ? Confirms lesson existence and parent module ID.
// ------------------------------------------------------------
$find = $pdo->prepare("SELECT id, module_id, position FROM lessons WHERE id = ? LIMIT 1");
$find->execute([$lessonId]);
$lesson = $find->fetch();


// ------------------------------------------------------------
// * AUTHORIZATION & PERMISSION VERIFICATION
// ! Confirms the user has active privileges to modify the parent module.
// ------------------------------------------------------------
if (!$lesson || !canManageLesson($pdo, (int) $lessonId, (int) $_SESSION["user_id"], $_SESSION["user_role"])) {
    header("Location: lessons.php?error=reorder");
    exit;
}


// ------------------------------------------------------------
// * DATABASE QUERY: LOCATE NEIGHBORING LESSON
// ? Calculates target positional index and fetches adjacent lesson ID.
// ------------------------------------------------------------
$neighborPosition = $lesson["position"] + ($direction === "up" ? -1 : 1);
$neighbor = $pdo->prepare("SELECT id FROM lessons WHERE module_id = ? AND position = ? LIMIT 1");
$neighbor->execute([$lesson["module_id"], $neighborPosition]);
$neighborId = $neighbor->fetchColumn();


// ------------------------------------------------------------
// * TRANSACTION: SWAP LESSON POSITIONS SAFELY
// ! Uses a temporary negative position to avoid unique constraint key collisions during swap.
// ------------------------------------------------------------
if ($neighborId) {

    $pdo->beginTransaction();

    try {

        // * Step 1: Assign a temporary negative position to unlock the target slot
        $pdo->prepare("UPDATE lessons SET position = ? WHERE id = ?")
            ->execute([-((int) $lessonId), $lessonId]);

        // * Step 2: Move adjacent neighbor lesson into current lesson's original slot
        $pdo->prepare("UPDATE lessons SET position = ? WHERE id = ? AND module_id = ?")
            ->execute([$lesson["position"], $neighborId, $lesson["module_id"]]);

        // * Step 3: Move target lesson from temporary value into neighbor's original slot
        $pdo->prepare("UPDATE lessons SET position = ? WHERE id = ? AND module_id = ?")
            ->execute([$neighborPosition, $lessonId, $lesson["module_id"]]);

        // * Commit changes safely to MySQL database
        $pdo->commit();

    } catch (Throwable $exception) {

        // ! Revert all pending queries if any database error occurs
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

    }

}


// ------------------------------------------------------------
// * REDIRECT RESPONSE
// ? Redirects back to parent module lesson list view.
// ------------------------------------------------------------
header("Location: lessons.php?module_id=" . $lesson["module_id"]);
exit;
