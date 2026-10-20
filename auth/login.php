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

    <title>Login - <?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">

    <link rel="stylesheet" href="../assets/css/style.css?v=20260917g">
</head>

<body class="auth-login-page">

<div class="login-container">

    <div class="login-box">

        <div class="auth-logo"><img src="<?= htmlspecialchars(platformLogoUrl($pdo, "light-bg"), ENT_QUOTES, "UTF-8") ?>" alt="<?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?>" class="brand-logo" width="68" height="68"><h1><?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></h1></div>

        <h2>Login</h2>

        <?php if (isset($_GET["error"])): ?>

            <p class="error">
                <?= htmlspecialchars($_GET["error"]) ?>
            </p>

        <?php endif; ?>
        <?php if (isset($_GET["inactive"])): ?>
            <p class="error">Votre compte est inactif. Contactez l'administration pour récupérer l'accès.</p>
        <?php endif; ?>

        <?php if (isset($_GET["message"])): ?>

            <p class="success">
                <?= htmlspecialchars($_GET["message"]) ?>
            </p>

        <?php endif; ?>
        

        <form action="login_process.php" method="POST">

            <div class="form-group">

                <label>Email</label>

                <input
                    type="email"
                    name="email"
                    required
                >

            </div>

            <div class="form-group">

                <label>Password</label>

                <div class="password-field">
                    <input
                        type="password"
                        id="login-password"
                        name="password"
                        required
                    >
                    <button type="button" class="password-toggle" data-password-toggle aria-controls="login-password" aria-label="Show password">Show</button>
                </div>

            </div>

            <button type="submit">
                Login
            </button>
            <br>

            <a href="forgot_password.php">
                Forgot your password?
            </a>

            <a href="register.php">
                Demander un accès
            </a>

            <div class="goback">
            <a href="../index.php">← Back to Home</a>
        </div>

        </form>

    </div>

</div>

<script src="../assets/js/password-toggle.js"></script>
<?= platformContactMarkup($pdo, "platform-contact platform-contact-app") ?>
</body>
</html>
