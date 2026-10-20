<?php
require_once __DIR__ . "/../../config/i18n.php";
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . "/../../config/student_access.php";
studentRequireAuth();

$materialId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
if (!$materialId || $materialId < 1) {
    http_response_code(400);
    exit("Support introuvable.");
}

http_response_code(403);
header("Content-Type: text/plain; charset=UTF-8");
header("Cache-Control: no-store");
exit("Le téléchargement des supports de cours est désactivé.");
