<?php
require_once __DIR__ . '/../../config/i18n.php';

// ============================================================
// * GAKU LEARNING - ADMIN MODULE REORDER ACTION
// ============================================================
// ! RESTRICTED ACTION: Only authenticated Admins and Teachers can trigger.
// ? Purpose: Swaps neighboring modules inside the same course safely using a database transaction.
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
    header("Location: modules.php");
    exit;
}


// ------------------------------------------------------------
// * INPUT SANITIZATION & CSRF VALIDATION
// ? Reads incoming payload and matches the token against session using timing-safe comparisons.
// ------------------------------------------------------------
$moduleId = filter_input(INPUT_POST, "module_id", FILTER_VALIDATE_INT);
$direction = $_POST["direction"] ?? "";
$token = $_POST["csrf_token"] ?? "";

if (
    empty($_SESSION["csrf_token"]) ||
    !hash_equals($_SESSION["csrf_token"], $token) ||
    !$moduleId ||
    !in_array($direction, ["up", "down"], true)
) {
    header("Location: modules.php?error=reorder");
    exit;
}


// ------------------------------------------------------------
// * DATABASE QUERY: FETCH TARGET MODULE
// ? Confirms module existence and parent course ID.
// ------------------------------------------------------------
$find = $pdo->prepare("SELECT id, course_id, position FROM modules WHERE id = ? LIMIT 1");
$find->execute([$moduleId]);
$module = $find->fetch();


// ------------------------------------------------------------
// * AUTHORIZATION & PERMISSION VERIFICATION
// ! Confirms the user has active privileges to modify the parent course.
// ------------------------------------------------------------
if (!$module || !canManageCourse($pdo, (int) $module["course_id"], (int) $_SESSION["user_id"], $_SESSION["user_role"])) {
    header("Location: modules.php?error=reorder");
    exit;
}


// ------------------------------------------------------------
// * DATABASE QUERY: LOCATE NEIGHBORING MODULE
// ? Calculates target positional index and fetches adjacent module ID.
// ------------------------------------------------------------
$neighborPosition = $module["position"] + ($direction === "up" ? -1 : 1);
$neighbor = $pdo->prepare("SELECT id FROM modules WHERE course_id = ? AND position = ? LIMIT 1");
$neighbor->execute([$module["course_id"], $neighborPosition]);
$neighborId = $neighbor->fetchColumn();


// ------------------------------------------------------------
// * TRANSACTION: SWAP MODULE POSITIONS SAFELY
// ! Uses a temporary negative position to avoid unique constraint key collisions during swap.
// ------------------------------------------------------------
if ($neighborId) {

    $pdo->beginTransaction();

    try {

        // * Step 1: Assign a temporary negative position to unlock the target slot
        $temporary = -$moduleId;
        $pdo->prepare("UPDATE modules SET position = ? WHERE id = ?")
            ->execute([$temporary, $moduleId]);

        // * Step 2: Move adjacent neighbor module into current module's original slot
        $pdo->prepare("UPDATE modules SET position = ? WHERE id = ? AND course_id = ?")
            ->execute([$module["position"], $neighborId, $module["course_id"]]);

        // * Step 3: Move target module from temporary value into neighbor's original slot
        $pdo->prepare("UPDATE modules SET position = ? WHERE id = ? AND course_id = ?")
            ->execute([$neighborPosition, $moduleId, $module["course_id"]]);

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
// ? Redirects back to parent course module list view.
// ------------------------------------------------------------
header("Location: modules.php?course_id=" . $module["course_id"]);
exit;
