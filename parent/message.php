<?php
require_once __DIR__ . '/../config/i18n.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";
require_once "../config/notifications.php";
require_once "../config/messaging.php";

if (!isset($_SESSION["user_id"]) || ($_SESSION["user_role"] ?? "") !== "parent") {
    http_response_code(403);
    exit("Accès interdit.");
}
$parentId = (int) $_SESSION["user_id"];
$errors = [];
$success = "";
$recipients = $pdo->query("SELECT id, first_name, last_name, email, role FROM users WHERE role IN ('admin','teacher') AND is_active = 1 ORDER BY role, last_name, first_name")->fetchAll(PDO::FETCH_ASSOC);
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $recipientId = filter_input(INPUT_POST, "recipient_id", FILTER_VALIDATE_INT);
    $subject = trim($_POST["subject"] ?? "");
    $body = trim($_POST["body"] ?? "");
    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) $errors[] = "La session du formulaire a expiré.";
    if (!$recipientId) $errors[] = "Sélectionnez un destinataire.";
    if ($subject === "" || mb_strlen($subject) > 150) $errors[] = "Le sujet est obligatoire.";
    if ($body === "" || mb_strlen($body) > 2000) $errors[] = "Le message est obligatoire.";
    if (!$errors) {
        $check = $pdo->prepare("SELECT id, role FROM users WHERE id = ? AND role IN ('admin','teacher') AND is_active = 1 LIMIT 1");
        $check->execute([$recipientId]);
        $recipient = $check->fetch(PDO::FETCH_ASSOC);
        if (!$recipient) $errors[] = "Destinataire invalide.";
        else {
            sendPlatformMessage($pdo, $parentId, $recipientId, $subject, $body);
            notifyUser($pdo, $recipientId, $subject, $body, "system");
            $success = "Message envoyé.";
        }
    }
}
function parentMessageEscape($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, "UTF-8");
}
?>
<!doctype html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contacter l'équipe</title>
    <link rel="icon" type="image/png"  href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260925e">
</head>

<body class="student-body parent-body">
    <header class="student-navbar"><a href="../index.php" class="student-brand"><img src="<?= htmlspecialchars(platformLogoUrl($pdo, "light-bg"), ENT_QUOTES, "UTF-8") ?>" alt="<?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?>" class="brand-logo" width="38" height="38"><?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></a>
        <nav><a href="dashboard.php">Mes enfants</a><a href="notifications.php">Notifications</a><a class="active" href="message.php">Contacter</a><a href="account.php">Mon compte</a><a href="../auth/logout.php">Déconnexion</a></nav>
    </header>
    <main class="student-dashboard">
        <section class="student-welcome">
            <div>
                <p class="student-eyebrow">Communication</p>
                <h1>Contacter l'équipe</h1>
                <p>Écrivez à un administrateur ou à un formateur.</p>
            </div>
        </section>
        <section class="student-section"><?php foreach ($errors as $error): ?><p class="alert alert-danger"><?= parentMessageEscape($error) ?></p><?php endforeach; ?><?php if ($success): ?><p class="alert alert-success"><?= parentMessageEscape($success) ?></p><?php endif; ?><form method="post" class="parent-message-form"><input type="hidden" name="csrf_token" value="<?= parentMessageEscape($_SESSION["csrf_token"]) ?>"><label>Destinataire<select name="recipient_id" required>
                        <option value="">Choisir</option><?php foreach ($recipients as $recipient): ?><option value="<?= (int) $recipient["id"] ?>"><?= parentMessageEscape(($recipient["role"] === "admin" ? "Administrateur" : "Formateur") . " — " . trim($recipient["first_name"] . " " . $recipient["last_name"])) ?></option><?php endforeach; ?>
                    </select></label><label>Sujet<input name="subject" maxlength="150" required></label><label>Message<textarea name="body" maxlength="2000" rows="7" required></textarea></label><button class="button primary-button" type="submit">Envoyer</button></form>
        </section>
    </main>
<?= platformContactMarkup($pdo, "platform-contact platform-contact-app") ?>
</body>

</html>