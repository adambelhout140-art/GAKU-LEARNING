<?php
require_once __DIR__ . '/../config/i18n.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";
require_once "../config/notifications.php";

if (!isset($_SESSION["user_id"]) || ($_SESSION["user_role"] ?? "") !== "parent") {
    http_response_code(403);
    exit("Accès interdit.");
}

$parentId = (int) $_SESSION["user_id"];
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["mark_all_read"])) {
    $statement = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $statement->execute([$parentId]);
    header("Location: notifications.php");
    exit;
}
$statement = $pdo->prepare("SELECT title, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100");
$statement->execute([$parentId]);
$notifications = $statement->fetchAll(PDO::FETCH_ASSOC);
function parentNotificationEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!doctype html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications parent</title>
    <link rel="icon" type="image/png"  href="../assets/images/logo-mark.png">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260925e">
</head>

<body class="student-body parent-body">
    <header class="student-navbar"><a href="../index.php" class="student-brand"><img src="../assets/images/logo-mark.png" alt="Gaku Learning" class="brand-logo" width="38" height="38">GAKU LEARNING</a>
        <nav><a href="dashboard.php">Mes enfants</a><a class="active" href="notifications.php">Notifications</a><a href="message.php">Contacter</a><a href="account.php">Mon compte</a><a href="../auth/logout.php">Déconnexion</a></nav>
    </header>
    <main class="student-dashboard">
        <section class="student-welcome">
            <div>
                <p class="student-eyebrow">Espace parent</p>
                <h1>Notifications</h1>
                <p>Suivez les informations importantes concernant vos enfants.</p>
            </div>
        </section>
        <section class="student-section">
            <form method="post"><button class="button secondary-button" name="mark_all_read" value="1" type="submit">Tout marquer comme lu</button></form>
            <div class="parent-activity-list"><?php if (!$notifications): ?><p class="student-muted">Aucune notification.</p><?php else: foreach ($notifications as $notification): ?><div class="<?= (int) $notification["is_read"] ? "" : "is-unread" ?>"><strong><?= parentNotificationEscape($notification["title"]) ?></strong><small><?= parentNotificationEscape($notification["message"]) ?> · <?= parentNotificationEscape(date("d/m/Y H:i", strtotime($notification["created_at"]))) ?></small></div><?php endforeach;
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        endif; ?></div>
        </section>
    </main>
</body>

</html>