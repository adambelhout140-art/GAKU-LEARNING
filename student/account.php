<?php
require_once __DIR__ . '/../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";
require_once "../config/student_access.php";
require_once "../config/audit.php";
studentRequireAuth();
studentEnsureCsrf();

$userId = (int) $_SESSION["user_id"];
$errors = [];
$success = "";

$userStatement = $pdo->prepare("SELECT first_name, last_name, email, phone FROM users WHERE id = ? LIMIT 1");
$userStatement->execute([$userId]);
$user = $userStatement->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    http_response_code(404);
    exit("Compte introuvable.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!studentCheckCsrf()) {
        $errors[] = "La session du formulaire a expiré, veuillez réessayer.";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "update_profile") {
            $phone = trim($_POST["phone"] ?? "");
            $update = $pdo->prepare("UPDATE users SET phone = ? WHERE id = ?");
            $update->execute([$phone !== "" ? $phone : null, $userId]);
            auditLog($pdo, $userId, "profile_updated", "user", $userId, null, ["phone_changed" => true]);
            $user["phone"] = $phone;
            $success = "Vos informations ont été mises à jour.";
        } elseif ($action === "change_password") {
            $currentPassword = $_POST["current_password"] ?? "";
            $newPassword = $_POST["new_password"] ?? "";
            $confirmPassword = $_POST["confirm_password"] ?? "";

            $hashStatement = $pdo->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
            $hashStatement->execute([$userId]);
            $currentHash = $hashStatement->fetchColumn();

            if ($currentPassword === "" || $newPassword === "" || $confirmPassword === "") {
                $errors[] = "Merci de remplir tous les champs du mot de passe.";
            } elseif (!password_verify($currentPassword, (string) $currentHash)) {
                $errors[] = "Le mot de passe actuel est incorrect.";
            } elseif (strlen($newPassword) < 8) {
                $errors[] = "Le nouveau mot de passe doit contenir au moins 8 caractères.";
            } elseif ($newPassword !== $confirmPassword) {
                $errors[] = "Les deux mots de passe ne correspondent pas.";
            } else {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $update = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $update->execute([$newHash, $userId]);
                auditLog($pdo, $userId, "password_changed", "user", $userId);
                $success = "Votre mot de passe a été modifié avec succès.";
            }
        }
    }
}
$notifUrl = "notifications.php";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mon compte - GAKU LEARNING</title><link rel="icon" type="image/png" href="../assets/images/logo-mark.png">
<link rel="stylesheet" href="../assets/css/style.css?v=20260910">
</head>
<body class="student-body">
<header class="student-navbar">
    <a href="../index.php" class="student-brand"><img src="../assets/images/logo-mark.png" alt="Gaku Learning" class="brand-logo" width="42" height="42"> GAKU LEARNING</a>
    <nav>
        <a href="dashboard.php">Tableau de bord</a>
        <a href="certificates/certificates.php">Mes certificats</a>
        <a href="results.php">Mes résultats</a>
        <a href="bookmarks.php">Mes favoris</a>
        <a href="account.php" class="active">Mon compte</a>
        <?= studentNotificationBell($pdo, $userId, $notifUrl) ?>
        <a href="../auth/logout.php">Déconnexion</a>
    </nav>
</header>
<main class="student-dashboard account-page">
    <section class="student-welcome">
        <div><p class="student-eyebrow">Espace étudiant</p><h1>Mon compte</h1><p>Consultez vos informations et changez votre mot de passe.</p></div>
    </section>

    <?php if ($errors): ?><div class="alert alert-danger"><ul><?php foreach ($errors as $error): ?><li><?= studentEscape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= studentEscape($success) ?></div><?php endif; ?>

    <section class="student-section">
        <h2>Informations personnelles</h2>
        <form method="post" class="account-form">
            <input type="hidden" name="csrf_token" value="<?= studentEscape($_SESSION["csrf_token"]) ?>">
            <input type="hidden" name="action" value="update_profile">
            <label>Prénom</label>
            <input type="text" value="<?= studentEscape($user["first_name"]) ?>" disabled>
            <label>Nom</label>
            <input type="text" value="<?= studentEscape($user["last_name"]) ?>" disabled>
            <label>Email</label>
            <input type="email" value="<?= studentEscape($user["email"]) ?>" disabled>
            <small class="student-muted">Le nom, prénom et email ne peuvent être modifiés que par un administrateur.</small>
            <label for="phone">Téléphone</label>
            <input type="tel" id="phone" name="phone" value="<?= studentEscape($user["phone"] ??  "") ?>"disabled>
            <button type="submit" class="button primary-button">Enregistrer</button>
        </form>
    </section>

    <section class="student-section">
        <h2>Changer le mot de passe</h2>
        <form method="post" class="account-form">
            <input type="hidden" name="csrf_token" value="<?= studentEscape($_SESSION["csrf_token"]) ?>">
            <input type="hidden" name="action" value="change_password">
            <label for="current_password">Mot de passe actuel</label>
            <div class="password-field">
                <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                <button type="button" class="password-toggle" data-password-toggle aria-controls="current_password" aria-label="Show password">Show</button>
            </div>
            <label for="new_password">Nouveau mot de passe</label>
            <div class="password-field">
                <input type="password" id="new_password" name="new_password" minlength="8" required autocomplete="new-password">
                <button type="button" class="password-toggle" data-password-toggle aria-controls="new_password" aria-label="Show password">Show</button>
            </div>
            <label for="confirm_password">Confirmer le nouveau mot de passe</label>
            <div class="password-field">
                <input type="password" id="confirm_password" name="confirm_password" minlength="8" required autocomplete="new-password">
                <button type="button" class="password-toggle" data-password-toggle aria-controls="confirm_password" aria-label="Show password">Show</button>
            </div>
            <button type="submit" class="button primary-button">Changer le mot de passe</button>
        </form>
    </section>
</main>
<script src="../assets/js/password-toggle.js"></script>
</body>
</html>




