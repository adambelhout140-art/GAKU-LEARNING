<?php
require_once __DIR__ . '/../../config/i18n.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../auth/login.php");
    exit;
}

$userRole = $_SESSION["user_role"] ?? "";
if ($userRole !== "admin" && $userRole !== "teacher") {
    http_response_code(403);
    exit("Accès interdit.");
}

require_once "../../config/database.php";
require_once "../../config/audit.php";
require_once "../../config/content_access.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: quizzes.php");
    exit;
}

$quizId = filter_input(INPUT_POST, "quiz_id", FILTER_VALIDATE_INT);
$token = $_POST["csrf_token"] ?? "";
if (!$quizId || $quizId < 1 || empty($_SESSION["csrf_token"]) || !hash_equals($_SESSION["csrf_token"], $token)) {
    header("Location: quizzes.php?error=delete");
    exit;
}

$statement = $pdo->prepare(
    "SELECT q.id, m.id AS module_id
     FROM quizzes q
     INNER JOIN modules m ON m.id = q.module_id
     WHERE q.id = ? LIMIT 1"
);
$statement->execute([$quizId]);
$quiz = $statement->fetch(PDO::FETCH_ASSOC);

if (!$quiz || !canManageModule($pdo, (int) $quiz["module_id"], (int) $_SESSION["user_id"], $userRole)) {
    http_response_code(403);
    exit("Vous n'avez pas accès à ce quiz.");
}

$delete = $pdo->prepare("DELETE FROM quizzes WHERE id = ?");
$delete->execute([$quizId]);
auditLog($pdo, (int) $_SESSION["user_id"], "quiz_deleted", "quiz", (int) $quizId);

header("Location: quizzes.php?success=deleted");
exit;

