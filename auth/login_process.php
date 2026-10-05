<?php
require_once __DIR__ . '/../config/i18n.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once "../config/database.php";
require_once "../config/audit.php";
require_once "../config/notifications.php";

$email = trim($_POST["email"] ?? "");
$password = $_POST["password"] ?? "";

if ($email === "" || $password === "") {
    header("Location: login.php?error=Please fill in all fields");
    exit;
}

$sql = "SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute([$email]);

$user = $stmt->fetch();

if (!$user) {
    auditLog($pdo, null, "login_failed", "auth");
    $failedCount = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'login_failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND ip_address = ?");
    $failedCount->execute([$_SERVER["REMOTE_ADDR"] ?? ""]);
    if ((int) $failedCount->fetchColumn() >= 5) {
        notifySecurityUsers($pdo, securityAdministratorIds($pdo), "Nombreuses connexions échouées", "Au moins 5 tentatives de connexion échouées ont été détectées depuis la même adresse IP.");
    }
    header("Location: login.php?error=Invalid email or password");
    exit;
}

if (!password_verify($password, $user["password"])) {
    auditLog($pdo, (int) $user["id"], "login_failed", "auth", (int) $user["id"]);
    $failedCount = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'login_failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND (ip_address = ? OR user_id = ?)");
    $failedCount->execute([$_SERVER["REMOTE_ADDR"] ?? "", (int) $user["id"]]);
    if ((int) $failedCount->fetchColumn() >= 5) {
        notifySecurityUsers($pdo, securityAdministratorIds($pdo), "Nombreuses connexions échouées", "Au moins 5 tentatives de connexion échouées ont été détectées pour le compte " . $user["email"] . ".");
    }
    header("Location: login.php?error=Invalid email or password");
    exit;
}

if ($user["role"] === "student") {
    // A random browser cookie identifies the device without storing the raw
    // token in the database. The cookie is HttpOnly so page scripts cannot read it.
    $deviceCookie = $_COOKIE["gaku_student_device"] ?? "";
    if (!preg_match('/^[a-f0-9]{64}$/', $deviceCookie)) {
        $deviceCookie = bin2hex(random_bytes(32));
    }

    if (!empty($user["registered_device_token"])) {
        // Once registered, only this device may log in to the paid student account.
        $deviceHash = hash("sha256", $deviceCookie);
        if (!hash_equals((string) $user["registered_device_token"], $deviceHash)) {
            // Do not reveal why access failed: silently return to the login page.
            session_unset();
            session_destroy();
            header("Location: login.php");
            exit;
        }
    } else {
        // The first successful student login claims the account for this device.
        $deviceHash = hash("sha256", $deviceCookie);
        $deviceUpdate = $pdo->prepare("UPDATE users SET registered_device_token = ? WHERE id = ? AND registered_device_token IS NULL");
        $deviceUpdate->execute([$deviceHash, (int) $user["id"]]);
    }

    // Keep the device cookie for one year and prevent JavaScript access.
    setcookie("gaku_student_device", $deviceCookie, [
        "expires" => time() + (86400 * 365),
        "path" => "/",
        "secure" => !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off",
        "httponly" => true,
        "samesite" => "Lax"
    ]);
}

$previousLogin = $pdo->prepare("SELECT ip_address, user_agent FROM audit_logs WHERE user_id = ? AND action = 'login_success' ORDER BY created_at DESC, id DESC LIMIT 1");
$previousLogin->execute([(int) $user["id"]]);
$previousLogin = $previousLogin->fetch(PDO::FETCH_ASSOC);

session_regenerate_id(true);

$_SESSION["user_id"] = $user["id"];
$_SESSION["user_role"] = $user["role"];
$_SESSION["user_name"] = $user["first_name"];
$_SESSION["user_email"] = $user["email"];

if ($user["role"] === "student") {
    // A new login replaces this token, invalidating every older student session.
    $sessionToken = bin2hex(random_bytes(32));
    $sessionUpdate = $pdo->prepare("UPDATE users SET active_session_token = ? WHERE id = ?");
    $sessionUpdate->execute([$sessionToken, (int) $user["id"]]);
    $_SESSION["student_session_token"] = $sessionToken;
}

auditLog($pdo, (int) $user["id"], "login_success", "auth", (int) $user["id"]);

if ($user["role"] === "teacher" && $previousLogin) {
    $ipChanged = ($previousLogin["ip_address"] ?? "") !== ($_SERVER["REMOTE_ADDR"] ?? "");
    $deviceChanged = ($previousLogin["user_agent"] ?? "") !== ($_SERVER["HTTP_USER_AGENT"] ?? "");
    if ($ipChanged || $deviceChanged) {
        notifySecurityUsers(
            $pdo,
            array_merge(securityAdministratorIds($pdo), [(int) $user["id"]]),
            "Nouvel appareil du formateur",
            "Le compte formateur " . trim($user["first_name"] . " " . $user["last_name"]) . " s'est connecté depuis un nouvel appareil ou une nouvelle adresse IP."
        );
    }
}

$update = $pdo->prepare(
    "UPDATE users SET last_login_at = NOW() WHERE id = ?"
);

$update->execute([$user["id"]]);

if ($user["role"] === "admin") {

    header("Location: ../admin/dashboard.php");
    exit;

}

if ($user["role"] === "teacher") {

    header("Location: ../teacher/dashboard.php");
    exit;

}

if ($user["role"] === "student") {

    header("Location: ../student/dashboard.php");
    exit;

}

if ($user["role"] === "company_manager") {

    header("Location: ../company/dashboard.php");
    exit;

}

if ($user["role"] === "parent") {

    header("Location: ../parent/dashboard.php");
    exit;

}

session_destroy();

header("Location: login.php?error=Invalid account role");
exit;
