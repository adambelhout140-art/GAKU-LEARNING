<?php
require_once __DIR__ . "/../../config/i18n.php";
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../config/student_access.php";
studentRequireAuth();

$viewerRequest = $_SERVER["HTTP_X_REQUESTED_WITH"] ?? "";
if ($viewerRequest !== "XMLHttpRequest") {
    http_response_code(403);
    header("Content-Type: text/plain; charset=UTF-8");
    header("Cache-Control: no-store");
    exit("Ouvrez ce document depuis la leçon.");
}

$materialId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
$statement = $pdo->prepare(
    "SELECT ma.file_path, ma.file_name, ma.mime_type
     FROM materials ma
     INNER JOIN lessons l ON l.id = ma.lesson_id
     INNER JOIN modules m ON m.id = l.module_id
     INNER JOIN courses c ON c.id = m.course_id
     INNER JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
     WHERE ma.id = ? AND ma.type = 'file'
       AND l.is_published = 1 AND c.status = 'published'
       AND e.status IN ('active', 'completed')
       AND (e.expires_at IS NULL OR e.expires_at >= NOW())
     LIMIT 1"
);
$statement->execute([(int) $_SESSION["user_id"], $materialId ?: 0]);
$material = $statement->fetch(PDO::FETCH_ASSOC);

$baseDirectory = realpath(__DIR__ . "/../../uploads/documents");
$file = $material && $baseDirectory
    ? realpath($baseDirectory . DIRECTORY_SEPARATOR . basename((string) $material["file_path"]))
    : false;
if (
    !$material
    || !$file
    || strpos($file, $baseDirectory . DIRECTORY_SEPARATOR) !== 0
    || !is_file($file)
    || strtolower((string) $material["mime_type"]) !== "application/pdf"
) {
    http_response_code(404);
    exit("Document introuvable ou accès non autorisé.");
}

$fileInfo = new finfo(FILEINFO_MIME_TYPE);
if ($fileInfo->file($file) !== "application/pdf") {
    http_response_code(415);
    exit("Le document ne peut pas être affiché.");
}

$opened = $pdo->prepare(
    "INSERT INTO lesson_material_views (user_id, material_id, opened_at)
     VALUES (?, ?, NOW())
     ON DUPLICATE KEY UPDATE opened_at = NOW()"
);
$opened->execute([(int) $_SESSION["user_id"], (int) $materialId]);

$filename = basename((string) $material["file_name"]);
$asciiFilename = preg_replace('/[^A-Za-z0-9._-]/', "_", $filename);
$asciiFilename = str_replace(["\r", "\n", '"', "\\"], "", $asciiFilename);
if ($asciiFilename === "") {
    $asciiFilename = "document.pdf";
}

header("Content-Type: application/pdf");
header("Content-Length: " . filesize($file));
header("Content-Disposition: inline; filename=\"" . $asciiFilename . "\"; filename*=UTF-8''" . rawurlencode($filename));
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Content-Security-Policy: frame-ancestors 'self'");
header("Cache-Control: private, no-store, max-age=0");
readfile($file);
exit;
