<?php
require_once __DIR__ . "/../config/i18n.php";
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/student_access.php";
require_once __DIR__ . "/../config/messaging.php";
require_once __DIR__ . "/../config/notifications.php";
require_once __DIR__ . "/../config/audit.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}
if (($_SESSION["user_role"] ?? "") !== "student") {
    http_response_code(403);
    exit("Accès interdit.");
}

$studentId = (int) $_SESSION["user_id"];
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
$tab = in_array($_GET["tab"] ?? "inbox", ["inbox", "sent", "compose"], true)
    ? (string) ($_GET["tab"] ?? "inbox")
    : "inbox";
$errors = [];
$success = (string) ($_SESSION["student_message_success"] ?? "");
unset($_SESSION["student_message_success"]);

$teacherStatement = $pdo->prepare(
    "SELECT DISTINCT teacher.id, teacher.first_name, teacher.last_name, teacher.email,
            'teacher' AS role
     FROM users teacher
     INNER JOIN course_teachers ct ON ct.teacher_id = teacher.id
     INNER JOIN enrollments e ON e.course_id = ct.course_id
     WHERE e.user_id = ? AND e.status IN ('active', 'completed')
       AND (e.expires_at IS NULL OR e.expires_at >= NOW())
       AND teacher.role = 'teacher' AND teacher.is_active = 1
     ORDER BY teacher.first_name, teacher.last_name"
);
$teacherStatement->execute([$studentId]);
$teachers = $teacherStatement->fetchAll(PDO::FETCH_ASSOC);
$admins = $pdo->query(
    "SELECT id, first_name, last_name, email, 'admin' AS role
     FROM users WHERE role = 'admin' AND is_active = 1
     ORDER BY first_name, last_name"
)->fetchAll(PDO::FETCH_ASSOC);
$recipients = array_merge($admins, $teachers);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!hash_equals($_SESSION["csrf_token"], (string) ($_POST["csrf_token"] ?? ""))) {
        $errors[] = "La session du formulaire a expiré. Actualisez la page et réessayez.";
    } else {
        $action = (string) ($_POST["action"] ?? "");
        $messageId = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);

        if ($action === "send") {
            $recipientId = filter_input(INPUT_POST, "recipient_id", FILTER_VALIDATE_INT);
            $subject = trim((string) ($_POST["subject"] ?? ""));
            $body = trim((string) ($_POST["body"] ?? ""));

            if (!$recipientId || $recipientId === $studentId) {
                $errors[] = "Choisissez un administrateur ou un formateur de vos formations.";
            }
            if ($subject === "" || mb_strlen($subject) > 150) {
                $errors[] = "Le sujet est obligatoire et limité à 150 caractères.";
            }
            if ($body === "" || mb_strlen($body) > 5000) {
                $errors[] = "Le message est obligatoire et limité à 5 000 caractères.";
            }

            if (!$errors) {
                $recipientStatement = $pdo->prepare(
                    "SELECT recipient.id, recipient.role
                     FROM users recipient
                     WHERE recipient.id = ? AND recipient.is_active = 1
                       AND (
                         recipient.role = 'admin'
                         OR (
                           recipient.role = 'teacher'
                           AND EXISTS (
                             SELECT 1
                             FROM course_teachers ct
                             INNER JOIN enrollments e ON e.course_id = ct.course_id
                             WHERE ct.teacher_id = recipient.id AND e.user_id = ?
                               AND e.status IN ('active', 'completed')
                               AND (e.expires_at IS NULL OR e.expires_at >= NOW())
                           )
                         )
                       )
                     LIMIT 1"
                );
                $recipientStatement->execute([$recipientId, $studentId]);
                $recipient = $recipientStatement->fetch(PDO::FETCH_ASSOC);

                if (!$recipient) {
                    $errors[] = "Ce destinataire n'est pas disponible pour votre compte.";
                } else {
                    sendPlatformMessage($pdo, $studentId, (int) $recipient["id"], $subject, $body);
                    notifyUser($pdo, (int) $recipient["id"], $subject, $body, "system");
                    auditLog($pdo, $studentId, "student_message_sent", "user", (int) $recipient["id"], null, [
                        "subject" => $subject,
                    ]);
                    $_SESSION["student_message_success"] = "Votre message a été envoyé.";
                    header("Location: messages.php?tab=sent");
                    exit;
                }
            }
            $tab = "compose";
        } elseif ($action === "read" && $messageId) {
            $read = $pdo->prepare(
                "UPDATE messages SET is_read = 1, read_at = NOW()
                 WHERE id = ? AND recipient_id = ?"
            );
            $read->execute([$messageId, $studentId]);
            header("Location: messages.php?tab=inbox");
            exit;
        } elseif ($action === "delete" && $messageId) {
            $delete = $pdo->prepare(
                "DELETE FROM messages
                 WHERE id = ? AND (sender_id = ? OR recipient_id = ?)"
            );
            $delete->execute([$messageId, $studentId, $studentId]);
            header("Location: messages.php?tab=" . urlencode($tab));
            exit;
        }
    }
}

$counterpartColumn = $tab === "sent" ? "recipient_id" : "sender_id";
$messageStatement = $pdo->prepare(
    "SELECT m.id, m.subject, m.body, m.is_read, m.created_at,
            u.first_name, u.last_name, u.role
     FROM messages m
     INNER JOIN users u ON u.id = m.{$counterpartColumn}
     WHERE m." . ($tab === "sent" ? "sender_id" : "recipient_id") . " = ?
     ORDER BY m.created_at DESC, m.id DESC
     LIMIT 100"
);
$messageStatement->execute([$studentId]);
$messages = $messageStatement->fetchAll(PDO::FETCH_ASSOC);
$unreadStatement = $pdo->prepare(
    "SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND is_read = 0"
);
$unreadStatement->execute([$studentId]);
$unreadCount = (int) $unreadStatement->fetchColumn();
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes messages - <?= $escape(platformBranding($pdo)["platform_name"]) ?></title>
    <link rel="icon" type="image/png" href="<?= $escape(platformLogoUrl($pdo)) ?>">
    <link rel="stylesheet" href="../assets/css/style.css?v=20261019a">
</head>
<body class="student-body">
<header class="student-navbar">
    <a href="../index.php" class="student-brand"><img src="<?= $escape(platformLogoUrl($pdo, "light-bg")) ?>" alt="<?= $escape(platformBranding($pdo)["platform_name"]) ?>" class="brand-logo" width="42" height="42"> <?= $escape(platformBranding($pdo)["platform_name"]) ?></a>
    <nav>
        <a href="dashboard.php">Tableau de bord</a>
        <a href="certificates/certificates.php">Mes certificats</a>
        <a href="results.php">Mes résultats</a>
        <a href="bookmarks.php">Mes favoris</a>
        <a href="messages.php" class="active">Messages<?= $unreadCount > 0 ? " (" . $unreadCount . ")" : "" ?></a>
        <a href="account.php">Mon compte</a>
        <?= studentNotificationBell($pdo, $studentId, "notifications.php") ?>
        <a href="../auth/logout.php">Déconnexion</a>
    </nav>
</header>
<main class="student-dashboard">
    <section class="student-welcome">
        <div><p class="student-eyebrow">Espace étudiant</p><h1>Mes messages</h1><p>Contactez l'administration ou les formateurs de vos formations.</p></div>
    </section>
    <section class="role-message-page student-message-page">
        <nav class="message-tabs">
            <a class="<?= $tab === "inbox" ? "active" : "" ?>" href="messages.php?tab=inbox">Messages reçus<?= $unreadCount > 0 ? " (" . $unreadCount . ")" : "" ?></a>
            <a class="<?= $tab === "sent" ? "active" : "" ?>" href="messages.php?tab=sent">Messages envoyés</a>
            <a class="<?= $tab === "compose" ? "active" : "" ?>" href="messages.php?tab=compose">Écrire un message</a>
        </nav>
        <?php if ($errors): ?><div class="alert alert-danger"><?= $escape(implode(" ", $errors)) ?></div><?php endif; ?>
        <?php if ($success !== ""): ?><div class="alert alert-success"><?= $escape($success) ?></div><?php endif; ?>
        <?php if ($tab === "compose"): ?>
            <article class="role-message role-message-edit">
                <?php if (!$recipients): ?>
                    <div class="student-empty">Aucun formateur n'est disponible. Vous pouvez contacter l'administration de la plateforme.</div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION["csrf_token"]) ?>">
                    <input type="hidden" name="action" value="send">
                    <label>Destinataire
                        <select name="recipient_id" required>
                            <option value="">Choisir un destinataire</option>
                            <?php foreach ($recipients as $recipient): ?>
                                <option value="<?= (int) $recipient["id"] ?>" <?= (int) ($_POST["recipient_id"] ?? 0) === (int) $recipient["id"] ? "selected" : "" ?>>
                                    <?= $recipient["role"] === "admin" ? "Administration" : "Formateur" ?> — <?= $escape(trim($recipient["first_name"] . " " . $recipient["last_name"])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Sujet<input name="subject" maxlength="150" required value="<?= $escape($_POST["subject"] ?? "") ?>"></label>
                    <label>Message<textarea name="body" rows="8" maxlength="5000" required><?= $escape($_POST["body"] ?? "") ?></textarea></label>
                    <button class="button primary-button" type="submit">Envoyer le message</button>
                </form>
            </article>
        <?php elseif (!$messages): ?>
            <div class="student-empty">Aucun message <?= $tab === "sent" ? "envoyé" : "reçu" ?>.</div>
        <?php else: ?>
            <?php foreach ($messages as $message): ?>
                <article class="role-message <?= $tab === "inbox" && !(int) $message["is_read"] ? "is-unread" : "" ?>">
                    <div>
                        <strong><?= $escape($message["subject"]) ?></strong>
                        <small><?= $tab === "sent" ? "À" : "De" ?> <?= $escape(trim($message["first_name"] . " " . $message["last_name"])) ?> · <?= $message["role"] === "admin" ? "Administration" : "Formateur" ?> · <?= date("d/m/Y H:i", strtotime($message["created_at"])) ?></small>
                        <p><?= nl2br($escape($message["body"])) ?></p>
                    </div>
                    <div class="message-actions">
                        <?php if ($tab === "inbox" && !(int) $message["is_read"]): ?>
                            <form method="post"><input type="hidden" name="csrf_token" value="<?= $escape($_SESSION["csrf_token"]) ?>"><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int) $message["id"] ?>"><button class="button secondary-button" type="submit">Marquer comme lu</button></form>
                        <?php endif; ?>
                        <form method="post" onsubmit="return confirm('Supprimer ce message ?');"><input type="hidden" name="csrf_token" value="<?= $escape($_SESSION["csrf_token"]) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $message["id"] ?>"><button class="button danger-button" type="submit">Supprimer</button></form>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</main>
<?= platformContactMarkup($pdo, "platform-contact platform-contact-app") ?>
</body>
</html>
