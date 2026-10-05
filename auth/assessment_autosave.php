<?php
require_once __DIR__ . '/../config/i18n.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";
require_once "../config/audit.php";
header("Content-Type: application/json; charset=UTF-8");

if (!isset($_SESSION["user_id"]) || ($_SESSION["user_role"] ?? "") !== "student") {
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "forbidden"]);
    exit;
}
if (empty($_SESSION["csrf_token"]) || !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "csrf"]);
    exit;
}

$type = ($_POST["assessment_type"] ?? "") === "exam" ? "exam" : "quiz";
$assessmentId = filter_input(INPUT_POST, "assessment_id", FILTER_VALIDATE_INT);
$token = (string) ($_POST["session_token"] ?? "");
$answers = $_POST["answers"] ?? [];
if (!$assessmentId || !preg_match('/^[a-f0-9]{64}$/', $token) || !is_array($answers)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "invalid_request"]);
    exit;
}

$statement = $pdo->prepare(
    "UPDATE assessment_sessions SET draft_answers = ?, last_seen_at = NOW(),
     ip_address = ?, user_agent = ?
     WHERE user_id = ? AND assessment_type = ? AND assessment_id = ? AND session_token = ? AND completed_at IS NULL"
);
$statement->execute([
    json_encode($answers, JSON_UNESCAPED_UNICODE),
    $_SERVER["REMOTE_ADDR"] ?? null,
    $_SERVER["HTTP_USER_AGENT"] ?? null,
    (int) $_SESSION["user_id"],
    $type,
    $assessmentId,
    $token
]);
if ($statement->rowCount() < 1) {
    auditLog($pdo, (int) $_SESSION["user_id"], "assessment_multiple_session", $type, $assessmentId);
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "session_conflict"]);
    exit;
}
echo json_encode(["ok" => true]);

