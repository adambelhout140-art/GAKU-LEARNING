<?php
require_once __DIR__ . '/../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . "/../config/database.php";

if (isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Forgot Password - <?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">

    <link rel="stylesheet" href="../assets/css/style.css?v=20260917g">

</head>

<body class="auth-login-page">

<div class="login-container">

    <div class="login-box">

        <div class="auth-logo"><img src="<?= htmlspecialchars(platformLogoUrl($pdo, "light-bg"), ENT_QUOTES, "UTF-8") ?>" alt="<?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?>" class="brand-logo" width="68" height="68"><h1><?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></h1></div>

        <h2>Forgot Password</h2>

        <?php if (isset($_GET["message"])): ?>

            <p class="success">
                <?= htmlspecialchars($_GET["message"]) ?>
            </p>

        <?php endif; ?>

        <?php if (isset($_GET["error"])): ?>

            <p class="error">
                <?= htmlspecialchars($_GET["error"]) ?>
            </p>

        <?php endif; ?>

        <p>
            Enter your email address and we will send you a password reset link.
        </p>

        <br>

        <form action="forgot_password_process.php" method="POST">

            <div class="form-group">

                <label>Email</label>

                <input
                    type="email"
                    name="email"
                    required
                >

            </div>

            <button type="submit">
                Send Reset Link
            </button>

        </form>

        <br>

        <a href="login.php">
            Back to Login
        </a>

    </div>

</div>

<?= platformContactMarkup($pdo, "platform-contact platform-contact-app") ?>
</body>

</html>
//!One thing for our final version

Right now, because you're working on WAMP locally, the reset link is displayed directly on the page.

For the professional version, we'll replace that with real email sending using SMTP.

We'll also add:

password strength validation
rate limiting
token cleanup
session invalidation after password change
CSRF protection
generic responses so attackers can't discover whether an email exists

So yes — Forgot Password should absolutely be part of our authentication module, and now it's included.

