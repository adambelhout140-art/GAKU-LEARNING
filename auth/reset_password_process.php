<?php
require_once __DIR__ . '/../config/i18n.php';

require_once "../config/database.php";
require_once "../config/audit.php";

$token = $_POST["token"] ?? "";
$password = $_POST["password"] ?? "";
$password_confirmation = $_POST["password_confirmation"] ?? "";

if ($token === "") {
    die("Invalid reset request.");
}

if ($password === "" || $password_confirmation === "") {
    die("Please fill in all fields.");
}

if ($password !== $password_confirmation) {
    die("Passwords do not match.");
}

if (strlen($password) < 8) {
    die("Password must contain at least 8 characters.");
}

$sql = "SELECT * FROM password_resets
        WHERE token = ?
        AND used_at IS NULL
        AND expires_at > NOW()
        LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute([$token]);

$reset = $stmt->fetch();

if (!$reset) {
    die("This reset link is invalid or has expired.");
}

$hashed_password = password_hash(
    $password,
    PASSWORD_DEFAULT
);

$sql = "UPDATE users
        SET password = ?
        WHERE id = ?";

$stmt = $pdo->prepare($sql);

$stmt->execute([
    $hashed_password,
    $reset["user_id"]
]);

$sql = "UPDATE password_resets
        SET used_at = NOW()
        WHERE id = ?";

$stmt = $pdo->prepare($sql);

$stmt->execute([
    $reset["id"]
]);

auditLog($pdo, (int) $reset["user_id"], "password_reset", "user", (int) $reset["user_id"]);

header("Location: login.php?message=Password changed successfully");

exit;
