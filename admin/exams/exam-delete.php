<?php
require_once __DIR__ . '/../../config/i18n.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION["user_id"])) { header("Location: ../../auth/login.php"); exit; }
$userRole=$_SESSION["user_role"]??"";
if ($userRole!=="admin"&&$userRole!=="teacher") { http_response_code(403); exit("Accès interdit."); }
require_once "../../config/database.php";
require_once "../../config/audit.php";
require_once "../../config/content_access.php";
if ($_SERVER["REQUEST_METHOD"]!=="POST") { header("Location: exams.php"); exit; }
$id=filter_input(INPUT_POST,"exam_id",FILTER_VALIDATE_INT); $token=$_POST["csrf_token"]??"";
if (!$id||empty($_SESSION["csrf_token"])||!hash_equals($_SESSION["csrf_token"],$token)) { header("Location: exams.php?error=delete"); exit; }
$s=$pdo->prepare("SELECT course_id FROM exams WHERE id=? LIMIT 1"); $s->execute([$id]); $exam=$s->fetch(PDO::FETCH_ASSOC);
if (!$exam||!canManageCourse($pdo,(int)$exam["course_id"],(int)$_SESSION["user_id"],$userRole)) { http_response_code(403); exit("Vous n'avez pas accès à cet examen."); }
$delete=$pdo->prepare("DELETE FROM exams WHERE id=?"); $delete->execute([$id]);
auditLog($pdo, (int) $_SESSION["user_id"], "exam_deleted", "exam", (int) $id);
header("Location: exams.php?success=deleted"); exit;

