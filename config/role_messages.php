<?php
require_once __DIR__ . "/notifications.php";
require_once __DIR__ . "/messaging.php";
require_once __DIR__ . "/audit.php";

function renderRoleMessages(PDO $pdo, string $role, string $dashboardUrl, string $assetPrefix): void
{
    if (!isset($_SESSION["user_id"]) || ($_SESSION["user_role"] ?? "") !== $role) {
        http_response_code(403);
        exit("Accès interdit.");
    }

    $userId = (int) $_SESSION["user_id"];
    $communicationPage = in_array($role, ["admin", "teacher"], true) ? "notifications.php" : "messages.php";
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }
    $requestedTab = $_GET["tab"] ?? "all";
    $adminTabs = ["all", "inbox", "notifications", "sent", "compose"];
    $tab = in_array($role, ["admin", "teacher"], true) && in_array($requestedTab, $adminTabs, true)
        ? $requestedTab
        : (in_array($role, ["admin", "teacher"], true) ? "all" : ($requestedTab === "sent" ? "sent" : "inbox"));
    $errors = [];
    $success = "";
    $editMessage = null;

    if ($_SERVER["REQUEST_METHOD"] === "POST"
        && hash_equals($_SESSION["csrf_token"] ?? "", $_POST["csrf_token"] ?? "")) {
        $action = $_POST["action"] ?? "";
        $messageId = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);

        if ($role === "admin" && $action === "compose") {
            $recipientId = filter_input(INPUT_POST, "recipient_id", FILTER_VALIDATE_INT);
            $subject = trim((string) ($_POST["subject"] ?? ""));
            $body = trim((string) ($_POST["body"] ?? ""));
            if (!$recipientId) {
                $errors[] = "Sélectionnez un destinataire.";
            }
            if ($subject === "" || mb_strlen($subject) > 150) {
                $errors[] = "Le sujet est obligatoire et limité à 150 caractères.";
            }
            if ($body === "" || mb_strlen($body) > 5000) {
                $errors[] = "Le message est obligatoire et limité à 5000 caractères.";
            }
            if (!$errors) {
                $recipientStatement = $pdo->prepare(
                    "SELECT id, role FROM users
                     WHERE id = ? AND is_active = 1
                     AND role IN ('student', 'parent', 'teacher', 'company_manager')
                     LIMIT 1"
                );
                $recipientStatement->execute([$recipientId]);
                $recipient = $recipientStatement->fetch(PDO::FETCH_ASSOC);
                if (!$recipient) {
                    $errors[] = "Destinataire invalide.";
                } else {
                    sendPlatformMessage($pdo, $userId, $recipientId, $subject, $body);
                    notifyUser($pdo, $recipientId, $subject, $body, "system");
                    auditLog($pdo, $userId, "admin_message_sent", "user", $recipientId, null, ["subject" => $subject]);
                    $success = "Message envoyé.";
                }
            }

        }

        if (in_array($role, ["admin", "teacher"], true) && in_array($action, ["notification_read", "notification_delete"], true)) {
            $notificationId = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);
            if ($notificationId) {
                if ($action === "notification_read") {
                    $notificationUpdate = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
                    $notificationUpdate->execute([$notificationId, $userId]);
                } else {
                    $notificationDelete = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
                    $notificationDelete->execute([$notificationId, $userId]);
                }
            }
            header("Location: " . $communicationPage . "?tab=" . urlencode($tab));
            exit;
        }

        if ($messageId && $action === "delete") {
            $delete = $pdo->prepare(
                "DELETE FROM messages
                 WHERE id = ? AND (sender_id = ? OR recipient_id = ?)"
            );
            $delete->execute([$messageId, $userId, $userId]);
            header("Location: " . $communicationPage . "?tab=" . $tab);
            exit;
        }

        if ($messageId && $action === "edit") {
            $subject = trim((string) ($_POST["subject"] ?? ""));
            $body = trim((string) ($_POST["body"] ?? ""));

            if ($subject === "" || mb_strlen($subject) > 150) {
                $errors[] = "Le sujet est obligatoire et limité à 150 caractères.";
            }
            if ($body === "" || mb_strlen($body) > 5000) {
                $errors[] = "Le message est obligatoire et limité à 5000 caractères.";
            }

            if (!$errors) {
                $update = $pdo->prepare(
                    "UPDATE messages
                     SET subject = ?, body = ?
                     WHERE id = ? AND sender_id = ?"
                );
                $update->execute([$subject, $body, $messageId, $userId]);
                header("Location: " . $communicationPage . "?tab=sent");
                exit;
            }
        }

        if ($messageId && $action === "show_edit") {
            $edit = $pdo->prepare(
                "SELECT id, subject, body
                 FROM messages
                 WHERE id = ? AND sender_id = ?
                 LIMIT 1"
            );
            $edit->execute([$messageId, $userId]);
            $editMessage = $edit->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

    $messageTab = $tab === "all" || $tab === "notifications" ? "inbox" : $tab;
    $statement = $messageTab === "sent"
        ? $pdo->prepare(
            "SELECT m.*, u.first_name, u.last_name, u.email
             FROM messages m INNER JOIN users u ON u.id = m.recipient_id
             WHERE m.sender_id = ? ORDER BY m.created_at DESC, m.id DESC LIMIT 100"
        )
        : $pdo->prepare(
            "SELECT m.*, u.first_name, u.last_name, u.email
             FROM messages m INNER JOIN users u ON u.id = m.sender_id
             WHERE m.recipient_id = ? ORDER BY m.created_at DESC, m.id DESC LIMIT 100"
        );
    $statement->execute([$userId]);
    $messages = $statement->fetchAll(PDO::FETCH_ASSOC);

    $notifications = [];
    if (in_array($role, ["admin", "teacher"], true) && in_array($tab, ["all", "notifications"], true)) {
        $notificationStatement = $pdo->prepare(
            "SELECT id, title, message, type, is_read, created_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT 100"
        );
        $notificationStatement->execute([$userId]);
        $notifications = $notificationStatement->fetchAll(PDO::FETCH_ASSOC);
    }

    $recipients = [];
    if ($role === "admin") {
        $recipients = $pdo->query(
            "SELECT id, first_name, last_name, email, role, is_active
             FROM users
            WHERE is_active = 1 AND role IN ('student', 'parent', 'teacher', 'company_manager')
             ORDER BY role, first_name, last_name"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    $roleLabels = [
        "student" => "Étudiant",
        "parent" => "Parent",
        "teacher" => "Formateur",
        "company_manager" => "Responsable entreprise",
    ];

    if ($_SERVER["REQUEST_METHOD"] === "POST"
        && ($_POST["action"] ?? "") === "read"
        && hash_equals($_SESSION["csrf_token"] ?? "", $_POST["csrf_token"] ?? "")) {
        $messageId = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);
        if ($messageId) {
            $read = $pdo->prepare(
                "UPDATE messages SET is_read = 1, read_at = NOW()
                 WHERE id = ? AND recipient_id = ?"
            );
            $read->execute([$messageId, $userId]);
        }
        header("Location: " . $communicationPage . "?tab=" . $tab);
        exit;
    }

    $escape = "messageEscape";
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Messages - GAKU LEARNING</title>
        <link rel="icon" type="image/png" href="<?= $escape($assetPrefix) ?>assets/images/logo-mark.png">
        <link rel="stylesheet" href="<?= $escape($assetPrefix) ?>assets/css/style.css?v=20260925e">
    </head>
    <body class="admin-body <?= $role === "teacher" ? "teacher-view" : "" ?> <?= $role === "admin" ? "admin-messages-page" : "" ?>">
    <div class="admin-layout">
        <?php require_once __DIR__ . "/../admin/config/admin_sidebar.php"; ?>
        <?php renderAdminSidebar($role === "teacher" ? "messages.php" : "notifications.php"); ?>
        <main class="admin-main">
            <header class="admin-topbar">
                <div><p class="student-eyebrow">Communication</p><h1>Messages</h1><p>Consultez votre boîte de réception et vos messages envoyés.</p></div>
                <?= roleNotificationBell($pdo, $userId, "notifications.php") ?>
                <a class="btn btn-secondary" href="<?= $escape($dashboardUrl) ?>">Tableau de bord</a>
            </header>
            <section class="role-message-page">
                <?php if ($errors): ?>
                    <div class="alert alert-danger"><?= $escape(implode(" ", $errors)) ?></div>
                <?php endif; ?>
                <nav class="message-tabs">
                    <?php if (in_array($role, ["admin", "teacher"], true)): ?><a class="<?= $tab === "all" ? "active" : "" ?>" href="<?= $communicationPage ?>">Tout</a><?php endif; ?>
                    <a class="<?= $tab === "inbox" ? "active" : "" ?>" href="<?= $communicationPage ?>?tab=inbox">Messages reçus</a>
                    <?php if (in_array($role, ["admin", "teacher"], true)): ?><a class="<?= $tab === "notifications" ? "active" : "" ?>" href="<?= $communicationPage ?>?tab=notifications">Notifications</a><?php endif; ?>
                    <a class="<?= $tab === "sent" ? "active" : "" ?>" href="<?= $communicationPage ?>?tab=sent">Messages envoyés</a>
                    <?php if ($role === "admin"): ?>
                        <a class="<?= $tab === "compose" ? "active" : "" ?>" href="<?= $communicationPage ?>?tab=compose">Écrire un message</a>
                    <?php elseif ($role === "teacher"): ?>
                        <a href="message.php">Écrire un message</a>
                    <?php endif; ?>
                </nav>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?= $escape($success) ?></div>
                <?php endif; ?>
                <?php if ($tab === "compose"): ?>
                    <article class="role-message role-message-edit">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION["csrf_token"]) ?>">
                            <input type="hidden" name="action" value="compose">
                            <label>Destinataire
                                <select name="recipient_id" required>
                                    <option value="">Choisir un destinataire</option>
                                    <?php foreach ($recipients as $recipient): ?>
                                        <option value="<?= (int) $recipient["id"] ?>">
                                            <?= $escape($roleLabels[$recipient["role"]] ?? $recipient["role"]) ?> — <?= $escape(trim($recipient["first_name"] . " " . $recipient["last_name"])) ?> (<?= $escape($recipient["email"]) ?>)<?= !(int) $recipient["is_active"] ? " — Inactif" : "" ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Sujet<input name="subject" maxlength="150" required></label>
                            <label>Message<textarea name="body" rows="9" maxlength="5000" required></textarea></label>
                            <button class="button primary-button" type="submit">Envoyer le message</button>
                        </form>
                    </article>
                <?php elseif (!$messages && !$notifications): ?>
                    <div class="student-empty">Aucun message dans cette section.</div>
                <?php else: ?>
                    <?php foreach ($messages as $message): ?>
                        <?php if ($tab === "notifications"): break; endif; ?>
                        <?php if ($editMessage && (int) $editMessage["id"] === (int) $message["id"]): ?>
                            <article class="role-message role-message-edit">
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION["csrf_token"] ?? "") ?>">
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="id" value="<?= (int) $message["id"] ?>">
                                    <label>Sujet<input name="subject" maxlength="150" required value="<?= $escape($editMessage["subject"]) ?>"></label>
                                    <label>Message<textarea name="body" rows="6" maxlength="5000" required><?= $escape($editMessage["body"]) ?></textarea></label>
                                    <div class="message-actions">
                                        <button class="button primary-button" type="submit">Enregistrer</button>
                                        <a class="button secondary-button" href="<?= $communicationPage ?>?tab=sent">Annuler</a>
                                    </div>
                                </form>
                            </article>
                        <?php else: ?>
                        <article class="role-message <?= !(int) $message["is_read"] && $tab === "inbox" ? "is-unread" : "" ?>">
                            <div>
                                <strong><?= $escape($message["subject"]) ?></strong>
                                <small><?= $tab === "sent" ? "À" : "De" ?> <?= $escape(trim($message["first_name"] . " " . $message["last_name"])) ?> · <?= date("d/m/Y H:i", strtotime($message["created_at"])) ?></small>
                                <p><?= nl2br($escape($message["body"])) ?></p>
                            </div>
                            <div class="message-actions">
                                <?php if ($tab === "inbox" && !(int) $message["is_read"]): ?>
                                    <form method="post"><input type="hidden" name="csrf_token" value="<?= $escape($_SESSION["csrf_token"] ?? "") ?>"><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int) $message["id"] ?>"><button class="button secondary-button" type="submit">Marquer lu</button></form>
                                <?php endif; ?>
                                <?php if ($tab === "sent"): ?>
                                    <form method="post"><input type="hidden" name="csrf_token" value="<?= $escape($_SESSION["csrf_token"] ?? "") ?>"><input type="hidden" name="action" value="show_edit"><input type="hidden" name="id" value="<?= (int) $message["id"] ?>"><button class="button secondary-button" type="submit">Modifier</button></form>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('Supprimer ce message ?');"><input type="hidden" name="csrf_token" value="<?= $escape($_SESSION["csrf_token"] ?? "") ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $message["id"] ?>"><button class="button danger-button" type="submit">Supprimer</button></form>
                            </div>
                        </article>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (in_array($role, ["admin", "teacher"], true) && $notifications): ?>
                        <?php foreach ($notifications as $notification): ?>
                            <article class="role-message communication-notification <?= !(int) $notification["is_read"] ? "is-unread" : "" ?>">
                                <div>
                                    <div class="communication-item-heading"><strong><?= $escape($notification["title"]) ?></strong><span class="communication-badge"><?= $escape(ucfirst(str_replace("_", " ", $notification["type"]))) ?></span></div>
                                    <small>Notification · <?= date("d/m/Y H:i", strtotime($notification["created_at"])) ?></small>
                                    <p><?= nl2br($escape($notification["message"])) ?></p>
                                </div>
                                <div class="message-actions">
                                    <?php if (!(int) $notification["is_read"]): ?>
                                        <form method="post"><input type="hidden" name="csrf_token" value="<?= $escape($_SESSION["csrf_token"]) ?>"><input type="hidden" name="action" value="notification_read"><input type="hidden" name="id" value="<?= (int) $notification["id"] ?>"><button class="button secondary-button" type="submit">Marquer lu</button></form>
                                    <?php endif; ?>
                                    <form method="post" onsubmit="return confirm('Supprimer cette notification ?');"><input type="hidden" name="csrf_token" value="<?= $escape($_SESSION["csrf_token"]) ?>"><input type="hidden" name="action" value="notification_delete"><input type="hidden" name="id" value="<?= (int) $notification["id"] ?>"><button class="button danger-button" type="submit">Supprimer</button></form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </main>
    </div>
    <script src="<?= $escape($assetPrefix) ?>assets/js/admin-sidebar.js?v=20260925e"></script>
    <?php if ($role === "teacher"): ?>
        <script src="<?= $escape($assetPrefix) ?>assets/js/teacher-navigation.js?v=20260925b"></script>
    <?php endif; ?>
    </body>
    </html>
    <?php
}
