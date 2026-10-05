<?php
require_once __DIR__ . '/../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";
require_once "../config/student_access.php";
studentRequireAuth();
studentEnsureCsrf();

$userId = (int) $_SESSION["user_id"];

$lessonStatement = $pdo->prepare(
    "SELECT b.id AS bookmark_id, l.id AS lesson_id, l.title, c.id AS course_id, c.title AS course_title
     FROM bookmarks b
     INNER JOIN lessons l ON l.id = b.lesson_id
     INNER JOIN modules m ON m.id = l.module_id
     INNER JOIN courses c ON c.id = m.course_id
     WHERE b.user_id = ? ORDER BY b.created_at DESC"
);
$lessonStatement->execute([$userId]);
$lessonBookmarks = $lessonStatement->fetchAll(PDO::FETCH_ASSOC);

$courseStatement = $pdo->prepare(
    "SELECT b.id AS bookmark_id, c.id AS course_id, c.title, c.short_description
     FROM bookmarks b INNER JOIN courses c ON c.id = b.course_id
     WHERE b.user_id = ? ORDER BY b.created_at DESC"
);
$courseStatement->execute([$userId]);
$courseBookmarks = $courseStatement->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mes favoris - GAKU LEARNING</title><link rel="icon" type="image/png" href="../assets/images/logo-mark.png">
<link rel="stylesheet" href="../assets/css/style.css?v=20260917d">
</head>
<body class="student-body">
<header class="student-navbar">
    <a href="../index.php" class="student-brand"><img src="../assets/images/logo-mark.png" alt="Gaku Learning" class="brand-logo" width="42" height="42"> GAKU LEARNING</a>
    <nav>
        <a href="dashboard.php">Tableau de bord</a>
        <a href="certificates/certificates.php">Mes certificats</a>
        <a href="results.php">Mes résultats</a>
        <a href="bookmarks.php" class="active">Mes favoris</a>
        <a href="account.php">Mon compte</a>
        <?= studentNotificationBell($pdo, $userId, "notifications.php") ?>
        <a href="../auth/logout.php">Déconnexion</a>
    </nav>
</header>
<main class="student-dashboard">
    <section class="student-welcome">
        <div><p class="student-eyebrow">Références</p><h1>Mes favoris</h1><p>Leçons et formations que vous avez marquées pour les retrouver rapidement.</p></div>
    </section>

    <section class="student-section">
        <h2>Leçons</h2>
        <?php if (!$lessonBookmarks): ?>
            <p class="student-muted">Aucune leçon en favori. Ouvrez une leçon et cliquez sur l'étoile pour l'ajouter ici.</p>
        <?php else: ?>
            <div class="student-content-list">
                <?php foreach ($lessonBookmarks as $bookmark): ?>
                    <div class="student-certificate-row">
                        <div><strong><?= studentEscape($bookmark["title"]) ?></strong><small><?= studentEscape($bookmark["course_title"]) ?></small></div>
                        <a class="button secondary-button" href="lessons/lesson.php?id=<?= (int) $bookmark["lesson_id"] ?>">Ouvrir</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="student-section">
        <h2>Formations</h2>
        <?php if (!$courseBookmarks): ?>
            <p class="student-muted">Aucune formation en favori.</p>
        <?php else: ?>
            <div class="student-content-list">
                <?php foreach ($courseBookmarks as $bookmark): ?>
                    <div class="student-certificate-row">
                        <div><strong><?= studentEscape($bookmark["title"]) ?></strong><small><?= studentEscape($bookmark["short_description"] ?? "") ?></small></div>
                        <a class="button secondary-button" href="courses/course.php?id=<?= (int) $bookmark["course_id"] ?>">Ouvrir</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>




