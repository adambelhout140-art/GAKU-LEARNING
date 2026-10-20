<?php
require_once __DIR__ . '/../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";
require_once "../config/student_access.php";
studentRequireAuth();
studentEnsureCsrf();

$userId = (int) $_SESSION["user_id"];

if ($_SERVER["REQUEST_METHOD"] === "POST" && studentCheckCsrf()) {
    $action = $_POST["action"] ?? "";
    if ($action === "mark_read") {
        $id = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);
        if ($id) {
            $statement = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $statement->execute([$id, $userId]);
        }
    } elseif ($action === "mark_all_read") {
        $statement = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $statement->execute([$userId]);
    }
    header("Location: notifications.php");
    exit;
}

$typeLabels = [
    "enrollment" => "Inscription",
    "quiz_result" => "Quiz",
    "exam_result" => "Examen",
    "certificate" => "Certificat",
    "reminder" => "Rappel",
    "system" => "Système",
];

$statement = $pdo->prepare(
    "SELECT id, title, message, type, is_read, created_at FROM notifications
     WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 100"
);
$statement->execute([$userId]);
$notifications = $statement->fetchAll(PDO::FETCH_ASSOC);
$unreadCount = 0;
foreach ($notifications as $notification) {
    if (!(int) $notification["is_read"]) $unreadCount++;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications - <?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
<link rel="stylesheet" href="../assets/css/style.css?v=20260917d">
</head>
<body class="student-body">
<header class="student-navbar">
    <a href="../index.php" class="student-brand"><img src="<?= htmlspecialchars(platformLogoUrl($pdo, "light-bg"), ENT_QUOTES, "UTF-8") ?>" alt="<?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?>" class="brand-logo" width="42" height="42"> <?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></a>
    <nav>
        <a href="dashboard.php">Tableau de bord</a>
        <a href="certificates/certificates.php">Mes certificats</a>
        <a href="results.php">Mes résultats</a>
        <a href="bookmarks.php">Mes favoris</a>
        <a href="messages.php">Messages</a>
        <a href="account.php">Mon compte</a>
        <a href="notifications.php" class="active student-notif-bell">🔔<?php if ($unreadCount): ?><span class="student-notif-badge"><?= $unreadCount > 9 ? "9+" : $unreadCount ?></span><?php endif; ?></a>
        <a href="../auth/logout.php">Déconnexion</a>
    </nav>
</header>
<main class="student-dashboard notifications-page">
    <section class="student-section-heading">
        <div><p class="student-eyebrow">Espace étudiant</p><h1>Notifications</h1></div>
        <?php if ($unreadCount): ?>
        <form method="post"><input type="hidden" name="csrf_token" value="<?= studentEscape($_SESSION["csrf_token"]) ?>"><input type="hidden" name="action" value="mark_all_read"><button type="submit" class="button secondary-button">Tout marquer comme lu</button></form>
        <?php endif; ?>
    </section>

    <section class="student-section">
        <?php if (!$notifications): ?>
            <div class="student-empty">Vous n'avez aucune notification pour le moment.</div>
        <?php else: ?>
            <div class="student-content-list">
                <?php foreach ($notifications as $notification): ?>
                    <div class="student-notification <?= (int) $notification["is_read"] ? "is-read" : "is-unread" ?>">
                        <div>
                            <div class="student-notification-heading">
                                <strong><?= studentEscape($notification["title"]) ?></strong>
                                <span class="student-notification-type"><?= studentEscape($typeLabels[$notification["type"]] ?? $notification["type"]) ?></span>
                            </div>
                            <small class="student-muted"><?= date("d/m/Y H:i", strtotime($notification["created_at"])) ?></small>
                            <p><?= nl2br(studentEscape($notification["message"])) ?></p>
                        </div>
                        <?php if (!(int) $notification["is_read"]): ?>
                        <form method="post" class="student-notification-action"><input type="hidden" name="csrf_token" value="<?= studentEscape($_SESSION["csrf_token"]) ?>"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="id" value="<?= (int) $notification["id"] ?>"><button type="submit" class="button secondary-button">Marquer comme lu</button></form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<?= platformContactMarkup($pdo, "platform-contact platform-contact-app") ?>
</body>
</html>



