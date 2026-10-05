<?php
require_once __DIR__ . '/../config/i18n.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";
require_once "../config/student_access.php";
studentRequireAuth();

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST" || !studentCheckCsrf()) {
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "csrf"]);
    exit;
}

$userId = (int) $_SESSION["user_id"];
$lessonId = filter_input(INPUT_POST, "lesson_id", FILTER_VALIDATE_INT) ?: null;
$courseId = filter_input(INPUT_POST, "course_id", FILTER_VALIDATE_INT) ?: null;

if ((!$lessonId && !$courseId) || ($lessonId && $courseId)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "invalid_target"]);
    exit;
}

// Confirm the student actually has access to this lesson/course before
// letting them bookmark it.
if ($lessonId) {
    $check = $pdo->prepare(
        "SELECT l.id FROM lessons l
         INNER JOIN modules m ON m.id = l.module_id
         INNER JOIN courses c ON c.id = m.course_id
         INNER JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
         WHERE l.id = ? AND c.status = 'published' AND e.status IN ('active','completed')
           AND (e.expires_at IS NULL OR e.expires_at >= NOW()) LIMIT 1"
    );
    $check->execute([$userId, $lessonId]);
} else {
    $check = $pdo->prepare(
        "SELECT c.id FROM courses c
         INNER JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
         WHERE c.id = ? AND c.status = 'published' AND e.status IN ('active','completed')
           AND (e.expires_at IS NULL OR e.expires_at >= NOW()) LIMIT 1"
    );
    $check->execute([$userId, $courseId]);
}
if (!$check->fetchColumn()) {
    http_response_code(404);
    echo json_encode(["ok" => false, "error" => "not_found"]);
    exit;
}

$column = $lessonId ? "lesson_id" : "course_id";
$targetId = $lessonId ?: $courseId;

$existing = $pdo->prepare("SELECT id FROM bookmarks WHERE user_id = ? AND $column = ? LIMIT 1");
$existing->execute([$userId, $targetId]);
$row = $existing->fetch(PDO::FETCH_ASSOC);

if ($row) {
    $delete = $pdo->prepare("DELETE FROM bookmarks WHERE id = ?");
    $delete->execute([$row["id"]]);
    echo json_encode(["ok" => true, "bookmarked" => false]);
} else {
    $insert = $pdo->prepare("INSERT INTO bookmarks (user_id, $column, created_at) VALUES (?, ?, NOW())");
    $insert->execute([$userId, $targetId]);
    echo json_encode(["ok" => true, "bookmarked" => true]);
}
