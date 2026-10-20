<?php
require_once __DIR__ . '/../../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../../config/database.php";
require_once "../../config/student_access.php";
studentRequireAuth();

$userId = (int) $_SESSION["user_id"];

$statement = $pdo->prepare(
    "SELECT cert.id, cert.course_id, cert.certificate_number, cert.issue_date,
            c.title AS course_title, c.duration_hours, enrollment.enrolled_at,
            attempt.percentage AS exam_percentage,
            attempt.quiz_average_percentage, attempt.quiz_count, attempt.combined_percentage
     FROM certificates cert INNER JOIN courses c ON c.id = cert.course_id
     INNER JOIN exam_attempts attempt ON attempt.id = cert.exam_attempt_id
     LEFT JOIN enrollments enrollment
        ON enrollment.user_id = cert.user_id AND enrollment.course_id = cert.course_id
     WHERE cert.user_id = ? ORDER BY cert.issue_date DESC, cert.id DESC"
);
$statement->execute([$userId]);
$certificates = $statement->fetchAll(PDO::FETCH_ASSOC);
$notifUrl = "../notifications.php";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mes certificats - <?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
<link rel="stylesheet" href="../../assets/css/style.css?v=20260917d">
</head>
<body class="student-body">
<header class="student-navbar">
    <a href="../../index.php" class="student-brand"><img src="<?= htmlspecialchars(platformLogoUrl($pdo, "light-bg"), ENT_QUOTES, "UTF-8") ?>" alt="<?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?>" class="brand-logo" width="42" height="42"> <?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></a>
    <nav>
        <a href="../dashboard.php">Tableau de bord</a>
        <a href="certificates.php" class="active">Mes certificats</a>
        <a href="../results.php">Mes résultats</a>
        <a href="../bookmarks.php">Mes favoris</a>
        <a href="../messages.php">Messages</a>
        <a href="../account.php">Mon compte</a>
        <?= studentNotificationBell($pdo, $userId, $notifUrl) ?>
        <a href="../../auth/logout.php">Déconnexion</a>
    </nav>
</header>
<main class="student-dashboard">
    <section class="student-welcome">
        <div><p class="student-eyebrow">Réussites</p><h1>Mes certificats</h1><p>Tous les certificats obtenus, formation par formation.</p></div>
        <div class="student-summary-number"><strong><?= count($certificates) ?></strong><span>certificat(s)</span></div>
    </section>

    <section class="student-section">
        <?php if (!$certificates): ?>
            <div class="student-empty">Vos certificats apparaîtront ici après la réussite d'un examen.</div>
        <?php else: ?>
            <div class="student-course-grid">
                <?php foreach ($certificates as $certificate): ?>
                    <article class="student-course-card">
                        <div class="student-course-accent"></div>
                        <div class="student-course-content">
                            <span class="student-course-status">🏆 Réussi</span>
                            <h3><?= studentEscape($certificate["course_title"]) ?></h3>
                            <p><strong>Résultat final : <?= studentEscape(number_format((float) $certificate["combined_percentage"], 2, ",", " ")) ?>%</strong><?php if ($certificate["quiz_average_percentage"] !== null): ?><br><span class="student-muted">Quiz : <?= studentEscape(number_format((float) $certificate["quiz_average_percentage"], 2, ",", " ")) ?>% · Examen : <?= studentEscape(number_format((float) $certificate["exam_percentage"], 2, ",", " ")) ?>%</span><?php elseif ((int) ($certificate["quiz_count"] ?? -1) === 0): ?><br><span class="student-muted">Aucun quiz publié · Résultat basé sur l'examen seul</span><?php else: ?><br><span class="student-muted">Moyenne des quiz non enregistrée pour cet ancien certificat</span><?php endif; ?></p>
                            <p class="student-muted">N° <?= studentEscape($certificate["certificate_number"]) ?></p>
                            <p class="student-muted">Durée : <?= $certificate["duration_hours"] !== null ? studentEscape(rtrim(rtrim(number_format((float) $certificate["duration_hours"], 2, ",", " "), "0"), ",")) . " h" : "Non précisée" ?></p>
                            <p class="student-muted">Début : <?= !empty($certificate["enrolled_at"]) ? date("d/m/Y", strtotime($certificate["enrolled_at"])) : "Non disponible" ?></p>
                            <p class="student-muted">Délivré le <?= date("d/m/Y", strtotime($certificate["issue_date"])) ?></p>
                            <p><a class="button primary-button" href="certificate.php?id=<?= (int) $certificate["id"] ?>">Voir / télécharger</a></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<?= platformContactMarkup($pdo, "platform-contact platform-contact-app") ?>
</body>
</html>
