<?php
require_once __DIR__ . "/notifications.php";

function renderRoleNotifications(PDO $pdo, string $role, string $dashboardUrl, string $assetPrefix): void
{
    if (!isset($_SESSION["user_id"]) || ($_SESSION["user_role"] ?? "") !== $role) {
        http_response_code(403);
        exit("Accès interdit.");
    }
    if (empty($_SESSION["csrf_token"])) $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    $userId = (int) $_SESSION["user_id"];
    $search = trim((string) ($_GET["search"] ?? ""));
    $filter = (string) ($_GET["filter"] ?? "");
    $page = max(1, (int) ($_GET["page"] ?? 1));
    $perPage = 15;
    if ($_SERVER["REQUEST_METHOD"] === "POST" && hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        if (($_POST["action"] ?? "") === "mark_all_read") {
            $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$userId]);
        } elseif (($_POST["action"] ?? "") === "mark_read") {
            $id = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);
            if ($id) $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
        } elseif (($_POST["action"] ?? "") === "delete") {
            $id = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);
            if ($id) $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
        } elseif (($_POST["action"] ?? "") === "clear_read") {
            $pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1")->execute([$userId]);
        }
        header("Location: notifications.php?" . http_build_query(["search" => $search, "filter" => $filter, "page" => $page]));
        exit;
    }
    $where = ["user_id = ?"];
    $params = [$userId];
    if ($search !== "") {
        $where[] = "(title LIKE ? OR message LIKE ?)";
        $params[] = "%" . $search . "%";
        $params[] = "%" . $search . "%";
    }
    if ($filter === "unread") $where[] = "is_read = 0";
    if ($filter === "read") $where[] = "is_read = 1";
    if (in_array($filter, ["enrollment", "quiz_result", "exam_result", "certificate", "reminder", "system"], true)) {
        $where[] = "type = ?";
        $params[] = $filter;
    }
    $whereSql = implode(" AND ", $where);
    $countStatement = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE {$whereSql}");
    $countStatement->execute($params);
    $total = (int) $countStatement->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $totalPages);
    $statement = $pdo->prepare("SELECT id, title, message, type, is_read, created_at FROM notifications WHERE {$whereSql} ORDER BY created_at DESC, id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage));
    $statement->execute($params);
    $notifications = $statement->fetchAll(PDO::FETCH_ASSOC);
    $types = ["enrollment" => "Inscription", "quiz_result" => "Quiz", "exam_result" => "Examen", "certificate" => "Certificat", "reminder" => "Rappel", "system" => "Système"];
    $unread = count(array_filter($notifications, static function ($item) {
        return !(int) $item["is_read"];
    }));
    $unreadCountStatement = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $unreadCountStatement->execute([$userId]);
    $unreadCount = (int) $unreadCountStatement->fetchColumn();
    $escape = "messageEscape";
    ?>
    <!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Notifications - <?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></title><link rel="icon" type="image/png" href="<?= $escape(platformLogoUrl($pdo)) ?>"><link rel="stylesheet" href="<?= $escape($assetPrefix) ?>assets/css/style.css?v=20260925e"></head>
    <body class="admin-body <?= $role === "teacher" ? "teacher-view" : "" ?>">
    <div class="admin-layout">
        <?php if ($role === "admin" || $role === "teacher"): ?>
            <?php require_once __DIR__ . "/../admin/config/admin_sidebar.php"; renderAdminSidebar("notifications.php"); ?>
        <?php else: ?>
        <aside class="admin-sidebar">
            <div class="admin-logo"><a href="<?= $escape($assetPrefix) ?>index.php"><img src="<?= $escape(platformLogoUrl($pdo, "dark-bg")) ?>" alt="<?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?>" class="brand-logo" width="34" height="34"><span class="brand-name"><?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></span></a></div>
            <nav class="admin-navigation">
                <a href="<?= $escape($dashboardUrl) ?>" class="admin-nav-link"><span>📊</span> Tableau de bord</a>
                <?php if ($role === "teacher"): ?>
                    <a href="../admin/modules/modules.php" class="admin-nav-link"><span>🧩</span> Modules</a>
                    <a href="../admin/lessons/lessons.php" class="admin-nav-link"><span>📖</span> Leçons</a>
                    <a href="../admin/quizzes/quizzes.php" class="admin-nav-link"><span>🧠</span> Quiz</a>
                    <a href="../admin/exams/exams.php" class="admin-nav-link"><span>📝</span> Examens</a>
                    <a href="results.php" class="admin-nav-link"><span>📈</span> Résultats étudiants</a>
                    <a href="messages.php" class="admin-nav-link"><span>✉️</span> Messages</a>
                    <a href="message.php" class="admin-nav-link"><span>📝</span> Écrire un message</a>
                    <a href="notifications.php" class="admin-nav-link active"><span>🔔</span> Notifications</a>
                    <a href="security_logs.php" class="admin-nav-link"><span>🔐</span> Sécurité étudiants</a>
                <?php else: ?>
                    <a href="users/users.php" class="admin-nav-link"><span>👥</span> Utilisateurs</a>
                    <a href="companies/companies.php" class="admin-nav-link"><span>🏢</span> Entreprises</a>
                    <a href="courses/courses.php" class="admin-nav-link"><span>📚</span> Formations</a>
                    <a href="modules/modules.php" class="admin-nav-link"><span>🧩</span> Modules</a>
                    <a href="quizzes/quizzes.php" class="admin-nav-link"><span>🧠</span> Quiz</a>
                    <a href="exams/exams.php" class="admin-nav-link"><span>📝</span> Examens</a>
                    <a href="enrollments/enrollments.php" class="admin-nav-link"><span>🎓</span> Inscriptions</a>
                    <a href="contact_requests/contact_requests.php" class="admin-nav-link"><span>✉️</span> Demandes de contact</a>
                    <a href="messages.php" class="admin-nav-link"><span>✉️</span> Messages</a>
                    <a href="message.php" class="admin-nav-link"><span>📝</span> Écrire un message</a>
                <?php endif; ?>
            </nav>
            <div class="admin-sidebar-bottom">
                <a href="profile.php" class="admin-nav-link"><span>👤</span> Mon profil</a>
                <a href="<?= $escape($assetPrefix) ?>index.php" class="admin-nav-link"><span>🌐</span> Voir le site</a>
                <a href="<?= $escape($assetPrefix) ?>auth/logout.php" class="admin-nav-link logout-link"><span>🚪</span> Déconnexion</a>
            </div>
        </aside>
        <?php endif; ?>
        <main class="admin-main">
            <header class="admin-topbar">
                <div><p class="student-eyebrow"><?= $role === "admin" ? "Espace administrateur" : "Espace formateur" ?></p><h1>Notifications</h1><p><?= $unreadCount ?> non lue(s)</p></div>
                <?= roleNotificationBell($pdo, $userId, "notifications.php") ?>
                <?php if ($unreadCount): ?>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= $escape($_SESSION["csrf_token"]) ?>"><input type="hidden" name="action" value="mark_all_read"><button type="submit" class="button secondary-button">Tout marquer comme lu</button></form>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('Supprimer les notifications déjà lues ?');"><input type="hidden" name="csrf_token" value="<?= $escape($_SESSION["csrf_token"]) ?>"><input type="hidden" name="action" value="clear_read"><button type="submit" class="button secondary-button">Effacer les lues</button></form>
            </header>
            <section class="student-dashboard notifications-page">
                <section class="student-section">
                    <form method="get" class="notification-filters">
                        <input type="search" name="search" placeholder="Rechercher une notification..." value="<?= $escape($search) ?>">
                        <select name="filter">
                            <option value="">Toutes</option><option value="unread" <?= $filter === "unread" ? "selected" : "" ?>>Non lues</option><option value="read" <?= $filter === "read" ? "selected" : "" ?>>Lues</option>
                            <?php foreach ($types as $typeKey => $typeLabel): ?><option value="<?= $escape($typeKey) ?>" <?= $filter === $typeKey ? "selected" : "" ?>><?= $escape($typeLabel) ?></option><?php endforeach; ?>
                        </select>
                        <button class="button secondary-button" type="submit">Filtrer</button><a class="button secondary-button" href="notifications.php">Réinitialiser</a>
                    </form>
                    <?php if (!$notifications): ?>
                        <div class="student-empty">Vous n'avez aucune notification pour le moment.</div>
                    <?php else: ?>
                        <div class="student-content-list">
                            <?php foreach ($notifications as $notification): ?>
                                <?php
                                $notificationPriority = stripos($notification["title"], "demande") !== false ? "important" : "normal";
                                $actionUrl = stripos($notification["title"], "demande") !== false && $role === "admin"
                                    ? "contact_requests/contact_requests.php"
                                    : "";
                                ?>
                                <div class="student-notification <?= (int) $notification["is_read"] ? "is-read" : "is-unread" ?> notification-priority-<?= $notificationPriority ?>">
                                    <div>
                                        <div class="student-notification-heading">
                                            <strong><?= $escape($notification["title"]) ?></strong>
                                            <span class="student-notification-type"><?= $escape($types[$notification["type"]] ?? $notification["type"]) ?></span>
                                            <span class="notification-priority"><?= $notificationPriority === "important" ? "Important" : "Normal" ?></span>
                                        </div>
                                        <small class="student-muted"><?= date("d/m/Y H:i", strtotime($notification["created_at"])) ?></small>
                                        <p><?= nl2br($escape($notification["message"])) ?></p>
                                        <?php if ($actionUrl): ?><a class="notification-action-link" href="<?= $escape($actionUrl) ?>">Voir les demandes</a><?php endif; ?>
                                    </div>
                                    <?php if (!(int) $notification["is_read"]): ?>
                                        <form method="post" class="student-notification-action"><input type="hidden" name="csrf_token" value="<?= $escape($_SESSION["csrf_token"]) ?>"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="id" value="<?= (int) $notification["id"] ?>"><button type="submit" class="button secondary-button">Marquer comme lu</button></form>
                                    <?php endif; ?>
                                    <form method="post" class="student-notification-action" onsubmit="return confirm('Supprimer cette notification ?');"><input type="hidden" name="csrf_token" value="<?= $escape($_SESSION["csrf_token"]) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $notification["id"] ?>"><button type="submit" class="button danger-button">Supprimer</button></form>
                                </div>
                                <?php if ($totalPages > 1): ?><nav class="notification-pagination"><?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?><a class="<?= $pageNumber === $page ? "active" : "" ?>" href="?<?= http_build_query(["search" => $search, "filter" => $filter, "page" => $pageNumber]) ?>"><?= $pageNumber ?></a><?php endfor; ?></nav><?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </section>
        </main>
    </div>
    <?= platformContactMarkup($pdo, "platform-contact platform-contact-app") ?>
    <script src="<?= $escape($assetPrefix) ?>assets/js/admin-sidebar.js?v=20260925e"></script>
    <?php if ($role === "teacher"): ?>
        <script src="<?= $escape($assetPrefix) ?>assets/js/teacher-navigation.js?v=20260925b"></script>
    <?php endif; ?>
    </body>
    </html>
    <?php
}
