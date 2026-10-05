<?php
require_once __DIR__ . '/../config/i18n.php';/**
 * // ! STUDENT PROFILE
 * The student may edit basic identity/phone information and change the
 * password only after proving they know the current password.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";
require_once "../config/student_access.php";
require_once "../config/notifications.php";
studentRequireAuth();
studentEnsureCsrf();
$userId = (int) $_SESSION["user_id"];
$errors = []; $success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!studentCheckCsrf()) { $errors[] = "La session du formulaire a expiré."; }
    else {
        $action = $_POST["action"] ?? "";
        if ($action === "profile") {
            $first = trim($_POST["first_name"] ?? ""); $last = trim($_POST["last_name"] ?? ""); $phone = trim($_POST["phone"] ?? "");
            if ($first === "" || mb_strlen($first) > 100) $errors[] = "Le prénom est obligatoire et doit contenir au maximum 100 caractères.";
            if ($last === "" || mb_strlen($last) > 100) $errors[] = "Le nom est obligatoire et doit contenir au maximum 100 caractères.";
            if (!$errors) {
                $s = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, phone = ? WHERE id = ? AND role = 'student'");
                $s->execute([$first, $last, $phone !== "" ? $phone : null, $userId]);
                $_SESSION["user_name"] = $first; $_SESSION["user_last_name"] = $last;
                $success = "Vos informations ont été mises à jour.";
            }
        } elseif ($action === "password") {
            $current = $_POST["current_password"] ?? ""; $new = $_POST["new_password"] ?? ""; $confirm = $_POST["confirm_password"] ?? "";
            $s = $pdo->prepare("SELECT password FROM users WHERE id = ? AND role = 'student' LIMIT 1"); $s->execute([$userId]); $user = $s->fetch(PDO::FETCH_ASSOC);
            if (!$user || !password_verify($current, $user["password"])) $errors[] = "Le mot de passe actuel est incorrect.";
            if (strlen($new) < 8) $errors[] = "Le nouveau mot de passe doit contenir au moins 8 caractères.";
            if ($new !== $confirm) $errors[] = "Les deux nouveaux mots de passe ne correspondent pas.";
            if (!$errors) { $hash = password_hash($new, PASSWORD_DEFAULT); $s = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?"); $s->execute([$hash, $userId]); $success = "Votre mot de passe a été changé avec succès."; }
        }
    }
}
$s = $pdo->prepare("SELECT first_name, last_name, email, phone FROM users WHERE id = ? AND role = 'student' LIMIT 1"); $s->execute([$userId]); $user = $s->fetch(PDO::FETCH_ASSOC);
if (!$user) { http_response_code(404); exit("Utilisateur introuvable."); }
$unreadCount = getUnreadNotificationCount($pdo, $userId);
?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Mon profil</title><link rel="icon" type="image/png" href="../assets/images/logo-mark.png"><link rel="stylesheet" href="../assets/css/style.css?v=20260925g"></head>
<body class="student-body profile-page"><header class="student-navbar"><a href="../index.php" class="student-brand"><img src="../assets/images/logo-mark.png" alt="Gaku Learning" class="brand-logo" width="42" height="42"> GAKU LEARNING</a><nav><a href="dashboard.php">Tableau de bord</a><a href="certificates/certificates.php">Certificats</a><a href="notifications.php">🔔 <?php if ($unreadCount): ?><span class="student-notification-count"><?= $unreadCount ?></span><?php endif; ?></a><a href="profile.php" class="active">Mon profil</a><a href="../auth/logout.php">Déconnexion</a></nav></header>
<main class="student-dashboard"><section class="student-welcome"><div><p class="student-eyebrow">Compte étudiant</p><h1>Mon profil</h1><p>Gérez vos informations et votre mot de passe.</p></div></section>
<?php if ($errors): ?><div class="alert alert-danger"><?= studentEscape(implode(" ", $errors)) ?></div><?php endif; ?><?php if ($success): ?><div class="alert alert-success"><?= studentEscape($success) ?></div><?php endif; ?>
<section class="student-profile-grid role-profile-grid"><article class="student-section"><h2>Informations personnelles</h2><form method="post"><input type="hidden" name="csrf_token" value="<?= studentEscape($_SESSION["csrf_token"]) ?>"><input type="hidden" name="action" value="profile"><div class="student-form-grid"><label>Prénom<input name="first_name" maxlength="100" required value="<?= studentEscape($user["first_name"]) ?>"></label><label>Nom<input name="last_name" maxlength="100" required value="<?= studentEscape($user["last_name"]) ?>"></label><label class="student-form-full">Email<input value="<?= studentEscape($user["email"]) ?>" readonly></label><label class="student-form-full">Téléphone<input name="phone" maxlength="30" value="<?= studentEscape($user["phone"] ?? "") ?>"></label></div><button class="button primary-button" type="submit">Enregistrer</button></form></article>
<article class="student-section"><h2>Changer le mot de passe</h2><form method="post"><input type="hidden" name="csrf_token" value="<?= studentEscape($_SESSION["csrf_token"]) ?>"><input type="hidden" name="action" value="password"><label>Mot de passe actuel<input type="password" name="current_password" required></label><label>Nouveau mot de passe<input type="password" name="new_password" minlength="8" required></label><label>Confirmer le nouveau mot de passe<input type="password" name="confirm_password" minlength="8" required></label><button class="button primary-button" type="submit">Changer le mot de passe</button></form></article></section></main></body></html>

