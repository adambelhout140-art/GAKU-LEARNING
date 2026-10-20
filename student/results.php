<?php
require_once __DIR__ . '/../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";
require_once "../config/student_access.php";
studentRequireAuth();

$userId = (int) $_SESSION["user_id"];

$quizStatement = $pdo->prepare(
    "SELECT qa.id, qa.percentage, qa.passed, qa.completed_at, qa.attempt_number,
            q.id AS quiz_id, q.title, c.title AS course_title
     FROM quiz_attempts qa
     INNER JOIN quizzes q ON q.id = qa.quiz_id
     INNER JOIN modules m ON m.id = q.module_id
     INNER JOIN courses c ON c.id = m.course_id
     INNER JOIN enrollments en ON en.course_id = c.id AND en.user_id = qa.user_id
     WHERE qa.user_id = ? AND qa.completed_at IS NOT NULL
       AND en.status IN ('active', 'completed')
     ORDER BY qa.completed_at DESC"
);
$quizStatement->execute([$userId]);
$quizAttempts = $quizStatement->fetchAll(PDO::FETCH_ASSOC);

$examStatement = $pdo->prepare(
    "SELECT ea.id, ea.percentage, ea.passed, ea.completed_at, ea.attempt_number,
            e.id AS exam_id, e.title, c.title AS course_title
     FROM exam_attempts ea
     INNER JOIN exams e ON e.id = ea.exam_id
     INNER JOIN courses c ON c.id = e.course_id
     INNER JOIN enrollments en ON en.course_id = c.id AND en.user_id = ea.user_id
     WHERE ea.user_id = ? AND ea.completed_at IS NOT NULL
       AND en.status IN ('active', 'completed')
     ORDER BY ea.completed_at DESC"
);
$examStatement->execute([$userId]);
$examAttempts = $examStatement->fetchAll(PDO::FETCH_ASSOC);
$notifUrl = "notifications.php";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mes résultats - <?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
<link rel="stylesheet" href="../assets/css/style.css?v=20260917d">
</head>
<body class="student-body">
<header class="student-navbar">
    <a href="../index.php" class="student-brand"><img src="<?= htmlspecialchars(platformLogoUrl($pdo, "light-bg"), ENT_QUOTES, "UTF-8") ?>" alt="<?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?>" class="brand-logo" width="42" height="42"> <?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></a>
    <nav>
        <a href="dashboard.php">Tableau de bord</a>
        <a href="certificates/certificates.php">Mes certificats</a>
        <a href="results.php" class="active">Mes résultats</a>
        <a href="bookmarks.php">Mes favoris</a>
        <a href="messages.php">Messages</a>
        <a href="account.php">Mon compte</a>
        <?= studentNotificationBell($pdo, $userId, $notifUrl) ?>
        <a href="../auth/logout.php">Déconnexion</a>
    </nav>
</header>
<main class="student-dashboard">
    <section class="student-welcome">
        <div><p class="student-eyebrow">Historique</p><h1>Mes résultats</h1><p>Toutes vos tentatives de quiz et d'examens, du plus récent au plus ancien.</p></div>
    </section>

    <section class="student-section">
        <h2>Quiz</h2>
        <?php if (!$quizAttempts): ?>
            <p class="student-muted">Aucun quiz terminé.</p>
        <?php else: ?>
            <table class="student-table">
                <thead><tr><th>Quiz</th><th>Formation</th><th>Tentative</th><th>Score</th><th>Résultat</th><th>Date</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($quizAttempts as $attempt): ?>
                    <tr>
                        <td><?= studentEscape($attempt["title"]) ?></td>
                        <td><?= studentEscape($attempt["course_title"]) ?></td>
                        <td>#<?= (int) $attempt["attempt_number"] ?></td>
                        <td><?= studentEscape($attempt["percentage"]) ?>%</td>
                        <td><span class="<?= $attempt["passed"] ? "student-result-good" : "student-result-bad" ?>"><?= $attempt["passed"] ? "Réussi" : "Échoué" ?></span></td>
                        <td><?= date("d/m/Y H:i", strtotime($attempt["completed_at"])) ?></td>
                        <td><a class="button secondary-button" href="quizzes/quiz.php?id=<?= (int) $attempt["quiz_id"] ?>&attempt=<?= (int) $attempt["id"] ?>">Revoir</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="student-section">
        <h2>Examens</h2>
        <?php if (!$examAttempts): ?>
            <p class="student-muted">Aucun examen terminé.</p>
        <?php else: ?>
            <table class="student-table">
                <thead><tr><th>Examen</th><th>Formation</th><th>Tentative</th><th>Score</th><th>Résultat</th><th>Date</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($examAttempts as $attempt): ?>
                    <tr>
                        <td><?= studentEscape($attempt["title"]) ?></td>
                        <td><?= studentEscape($attempt["course_title"]) ?></td>
                        <td>#<?= (int) $attempt["attempt_number"] ?></td>
                        <td><?= studentEscape($attempt["percentage"]) ?>%</td>
                        <td><span class="<?= $attempt["passed"] ? "student-result-good" : "student-result-bad" ?>"><?= $attempt["passed"] ? "Réussi" : "Échoué" ?></span></td>
                        <td><?= date("d/m/Y H:i", strtotime($attempt["completed_at"])) ?></td>
                        <td><a class="button secondary-button" href="exams/exam.php?id=<?= (int) $attempt["exam_id"] ?>&attempt=<?= (int) $attempt["id"] ?>">Revoir</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>
<?= platformContactMarkup($pdo, "platform-contact platform-contact-app") ?>
</body>
</html>



