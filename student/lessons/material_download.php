<?php
require_once __DIR__ . '/../../config/i18n.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../../config/database.php";
require_once "../../config/student_access.php";
studentRequireAuth();

$materialId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
$statement = $pdo->prepare(
    "SELECT ma.file_path, ma.file_name, ma.mime_type
     FROM materials ma
     INNER JOIN lessons l ON l.id = ma.lesson_id
     INNER JOIN modules m ON m.id = l.module_id
     INNER JOIN enrollments e ON e.course_id = m.course_id AND e.user_id = ?
     WHERE ma.id = ? AND ma.type = 'file' AND l.is_published = 1
       AND e.status IN ('active', 'completed')
       AND (e.expires_at IS NULL OR e.expires_at >= NOW())
     LIMIT 1"
);
$statement->execute([(int) $_SESSION["user_id"], $materialId ?: 0]);
$material = $statement->fetch(PDO::FETCH_ASSOC);
$baseDirectory = realpath(__DIR__ . "/../../uploads/documents");
$file = $material && $baseDirectory ? realpath($baseDirectory . DIRECTORY_SEPARATOR . basename($material["file_path"])) : false;
if (!$material || !$file || strpos($file, $baseDirectory . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
    http_response_code(404);
    exit("Support introuvable ou accès non autorisé.");
}

$downloadName = str_replace(["\r", "\n", "\0", '"', "\\"], "", basename($material["file_name"] ?: "support"));
if ($downloadName === "") {
    $downloadName = "support";
}
header("Content-Type: " . (preg_match('/^[A-Za-z0-9.+\/-]+$/', (string) $material["mime_type"]) ? $material["mime_type"] : "application/octet-stream"));
header("Content-Length: " . filesize($file));
header("Content-Disposition: attachment; filename=\"" . $downloadName . "\"; filename*=UTF-8''" . rawurlencode($downloadName));
readfile($file);
exit;

