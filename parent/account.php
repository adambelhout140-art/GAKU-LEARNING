<?php
require_once __DIR__ . '/../config/i18n.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";
if (!isset($_SESSION["user_id"]) || ($_SESSION["user_role"] ?? "") !== "parent") {
    http_response_code(403);
    exit("Accès interdit.");
}
$id = (int) $_SESSION["user_id"];
$message = "";
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $first = trim($_POST["first_name"] ?? "");
    $last = trim($_POST["last_name"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirmation = $_POST["password_confirmation"] ?? "";
    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        $message = "La session du formulaire a expiré.";
    } elseif ($first === "" || $last === "") {
        $message = "Le prénom et le nom sont obligatoires.";
    } elseif ($password !== "" && (strlen($password) < 8 || $password !== $confirmation)) {
        $message = "Le nouveau mot de passe est invalide ou ne correspond pas.";
    } else {
        $s = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, phone = ?" . ($password !== "" ? ", password = ?" : "") . " WHERE id = ? AND role = 'parent'");
        $params = [$first, $last, $phone];
        if ($password !== "") $params[] = password_hash($password, PASSWORD_DEFAULT);
        $params[] = $id;
        $s->execute($params);
        $_SESSION["user_name"] = $first;
        $_SESSION["user_last_name"] = $last;
        $message = "Profil mis à jour.";
    }
}
$s = $pdo->prepare("SELECT first_name, last_name, email, phone FROM users WHERE id = ? AND role = 'parent' LIMIT 1");
$s->execute([$id]);
$user = $s->fetch(PDO::FETCH_ASSOC);
function parentAccountEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!doctype html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon compte parent</title>
    <link rel="icon" type="image/png"  href="../assets/images/logo-mark.png">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260925g">
</head>

<body class="student-body parent-body profile-page">
    <header class="student-navbar"><a href="../index.php" class="student-brand"><img src="../assets/images/logo-mark.png" alt="Gaku Learning" class="brand-logo" width="38" height="38">GAKU LEARNING</a>
        <nav><a href="dashboard.php">Mes enfants</a><a href="notifications.php">Notifications</a><a href="message.php">Contacter</a><a class="active" href="account.php">Mon compte</a><a href="../auth/logout.php">Déconnexion</a></nav>
    </header>
    <main class="student-dashboard account-page parent-account-page">
        <section class="student-welcome">
            <div>
                <p class="student-eyebrow">Espace parent</p>
                <h1>Mon compte</h1>
                <p>Gérez vos informations personnelles.</p>
            </div>
        </section>
        <section class="student-section">
            <?php if ($message): ?><p class="alert <?= strpos($message, "expiré") !== false || strpos($message, "invalide") !== false ? "alert-danger" : "alert-success" ?>"><?= parentAccountEscape($message) ?></p><?php endif; ?>
            <form method="post" class="student-form-grid"><input type="hidden" name="csrf_token" value="<?= parentAccountEscape($_SESSION["csrf_token"]) ?>"><label>Prénom<input name="first_name" value="<?= parentAccountEscape($user["first_name"] ?? "") ?>" required></label><label>Nom<input name="last_name" value="<?= parentAccountEscape($user["last_name"] ?? "") ?>" required></label><label>Email<input value="<?= parentAccountEscape($user["email"] ?? "") ?>" disabled></label><label>Téléphone<input name="phone" value="<?= parentAccountEscape($user["phone"] ?? "") ?>"></label><label>Nouveau mot de passe<input type="password" name="password" minlength="8" autocomplete="new-password"></label><label>Confirmer le mot de passe<input type="password" name="password_confirmation" minlength="8" autocomplete="new-password"></label><button class="button primary-button" type="submit">Enregistrer</button></form>
        </section>
    </main>
</body>

</html>