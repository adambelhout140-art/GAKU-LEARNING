<?php
require_once __DIR__ . '/../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once "../config/database.php";

$email = trim($_POST["email"] ?? "");

if ($email === "") {
    header("Location: forgot_password.php?error=Please enter your email");
    exit;
}

$sql = "SELECT id, email FROM users WHERE email = ? AND is_active = 1 LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute([$email]);

$user = $stmt->fetch();

if (!$user) {
    header("Location: forgot_password.php?message=If this email exists, a reset link has been sent");
    exit;
}

$token = bin2hex(random_bytes(32));

$sql = "INSERT INTO password_resets (user_id, token, expires_at)
        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))";

$stmt = $pdo->prepare($sql);

$stmt->execute([
    $user["id"],
    $token
]);

$reset_link = "http://localhost/formation-platform/auth/reset_password.php?token=" . $token;

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Password Reset</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260917g">

</head>

<body class="auth-login-page">

<div class="login-container">
    <div class="login-box reset-link-page">
        <div class="auth-logo"><img src="<?= htmlspecialchars(platformLogoUrl($pdo, "light-bg"), ENT_QUOTES, "UTF-8") ?>" alt="<?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?>" class="brand-logo" width="68" height="68"><h1><?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></h1></div>
        <h2>Password Reset Link</h2>
        <p class="reset-link-intro">Your password reset link is ready.</p>
        <a class="button primary-button reset-link-button" href="<?= htmlspecialchars($reset_link) ?>">Reset your password</a>
        <p class="reset-link-expiry">This link expires in 1 hour.</p>
        <a class="reset-link-back" href="login.php">Back to Login</a>
    </div>
</div>

<?= platformContactMarkup($pdo, "platform-contact platform-contact-app") ?>
</body>

</html>


