<?php
require_once __DIR__ . '/../config/i18n.php';require_once "../config/database.php";

$token = $_GET["token"] ?? "";
$reset = null;
$resetError = "";

if ($token === "") {
    $resetError = "This reset link is missing its security token.";
} else {
    $sql = "SELECT * FROM password_resets
            WHERE token = ?
            AND used_at IS NULL
            AND expires_at > NOW()
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$token]);
    $reset = $stmt->fetch();

    if (!$reset) {
        $resetError = "This reset link is invalid or has expired.";
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Reset Password</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">

    <link rel="stylesheet" href="../assets/css/style.css?v=20260917g">

</head>

<body class="auth-login-page">

<div class="login-container">

    <div class="login-box">

        <div class="auth-logo"><img src="<?= htmlspecialchars(platformLogoUrl($pdo, "light-bg"), ENT_QUOTES, "UTF-8") ?>" alt="<?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?>" class="brand-logo" width="68" height="68"><h1><?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></h1></div>

        <h2>Reset Password</h2>

        <?php if ($resetError): ?>
            <p class="error"><?= htmlspecialchars($resetError, ENT_QUOTES, "UTF-8") ?></p>
            <p class="reset-link-intro">Please request a new password reset link to continue.</p>
            <a class="button primary-button reset-link-button" href="forgot_password.php">Request a new link</a>
            <a class="reset-link-back" href="login.php">Back to Login</a>
        <?php else: ?>
        <form action="reset_password_process.php" method="POST">

            <input
                type="hidden"
                name="token"
                value="<?= htmlspecialchars($token) ?>"
            >

            <div class="form-group">

                <label>New Password</label>

                <div class="password-field">
                    <input
                        type="password"
                        id="reset-password"
                        name="password"
                        required
                        minlength="8"
                        autocomplete="new-password"
                    >
                    <button type="button" class="password-toggle" data-password-toggle aria-controls="reset-password" aria-label="Show password">Show</button>
                </div>

            </div>

            <div class="form-group">

                <label>Confirm Password</label>

                <div class="password-field">
                    <input
                        type="password"
                        id="reset-password-confirmation"
                        name="password_confirmation"
                        required
                        minlength="8"
                        autocomplete="new-password"
                    >
                    <button type="button" class="password-toggle" data-password-toggle aria-controls="reset-password-confirmation" aria-label="Show password">Show</button>
                </div>

            </div>

            <button type="submit">
                Change Password
            </button>

        </form>
        <?php endif; ?>

    </div>

</div>

<script src="../assets/js/password-toggle.js"></script>
<?= platformContactMarkup($pdo, "platform-contact platform-contact-app") ?>
</body>

</html>


