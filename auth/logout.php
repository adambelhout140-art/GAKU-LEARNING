<?php
require_once __DIR__ . '/../config/i18n.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";
require_once "../config/audit.php";

if (isset($_SESSION["user_id"])) {
	auditLog($pdo, (int) $_SESSION["user_id"], "logout", "auth", (int) $_SESSION["user_id"]);
	if (($_SESSION["user_role"] ?? "") === "student") {
		$clearSession = $pdo->prepare("UPDATE users SET active_session_token = NULL WHERE id = ? AND role = 'student'");
		$clearSession->execute([(int) $_SESSION["user_id"]]);
	}
}

session_unset();

session_destroy();

header("Location: login.php");

exit;
