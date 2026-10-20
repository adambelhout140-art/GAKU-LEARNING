<?php
require_once __DIR__ . '/../../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../../config/database.php";
require_once "../../config/student_access.php";
studentRequireAuth();

$userId = (int) $_SESSION["user_id"];
$courseId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
$course = $courseId ? studentCourseForUser($pdo, $courseId, $userId) : false;
if (!$course) {
    http_response_code(404);
    exit("Formation introuvable ou accès non autorisé.");
}

$moduleStatement = $pdo->prepare(
    "SELECT id, title, description, position FROM modules
     WHERE course_id = ? ORDER BY position, id"
);
$moduleStatement->execute([$courseId]);
$modules = $moduleStatement->fetchAll(PDO::FETCH_ASSOC);

$lessonStatement = $pdo->prepare(
    "SELECT l.id, l.module_id, l.title, l.description, l.estimated_minutes,
            COALESCE(lp.completed, 0) AS completed,
            COALESCE(lp.progress_percent, 0) AS progress_percent
     FROM lessons l
     LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.user_id = ?
     WHERE l.module_id IN (SELECT id FROM modules WHERE course_id = ?)
       AND l.is_published = 1
     ORDER BY l.position, l.id"
);
$lessonStatement->execute([$userId, $courseId]);
$lessonsByModule = [];
foreach ($lessonStatement->fetchAll(PDO::FETCH_ASSOC) as $lesson) {
    $lessonsByModule[$lesson["module_id"]][] = $lesson;
}

$quizStatement = $pdo->prepare(
    "SELECT q.id, q.module_id, q.title, q.passing_score, q.max_attempts,
            (SELECT percentage FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.user_id = ?
             AND qa.completed_at IS NOT NULL ORDER BY qa.completed_at DESC LIMIT 1) AS last_score
     FROM quizzes q
     WHERE q.module_id IN (SELECT id FROM modules WHERE course_id = ?) AND q.is_published = 1
     ORDER BY q.id"
);
$quizStatement->execute([$userId, $courseId]);
$quizzesByModule = [];
foreach ($quizStatement->fetchAll(PDO::FETCH_ASSOC) as $quiz) {
    $quizzesByModule[$quiz["module_id"]][] = $quiz;
}

$examStatement = $pdo->prepare(
    "SELECT e.id, e.title, e.passing_score,
            (SELECT percentage FROM exam_attempts ea WHERE ea.exam_id = e.id AND ea.user_id = ?
             AND ea.completed_at IS NOT NULL ORDER BY ea.completed_at DESC LIMIT 1) AS last_score
     FROM exams e INNER JOIN courses c ON c.id = e.course_id
     WHERE e.course_id = ? AND c.status = 'published' AND e.is_published = 1 ORDER BY e.id"
);
$examStatement->execute([$userId, $courseId]);
$exams = $examStatement->fetchAll(PDO::FETCH_ASSOC);

$progressStatement = $pdo->prepare(
    "SELECT COUNT(l.id), COUNT(CASE WHEN lp.completed = 1 THEN l.id END)
     FROM lessons l INNER JOIN modules m ON m.id = l.module_id
     LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.user_id = ?
     WHERE m.course_id = ? AND l.is_published = 1"
);
$progressStatement->execute([$userId, $courseId]);
$progress = $progressStatement->fetch(PDO::FETCH_NUM);
$progressPercent = (int) (($progress[0] ?? 0) > 0 ? round(((int) $progress[1] / (int) $progress[0]) * 100) : 0);

$bookmarkStatement = $pdo->prepare("SELECT id FROM bookmarks WHERE user_id = ? AND course_id = ? LIMIT 1");
$bookmarkStatement->execute([$userId, $courseId]);
$isBookmarked = (bool) $bookmarkStatement->fetchColumn();
studentEnsureCsrf();
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= studentEscape($course["title"]) ?> - Formation</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>"><link rel="stylesheet" href="../../assets/css/style.css?v=20260917d"></head>
<body class="student-body">
<header class="student-navbar"><a href="../../index.php" class="student-brand"><img src="<?= htmlspecialchars(platformLogoUrl($pdo, "light-bg"), ENT_QUOTES, "UTF-8") ?>" alt="<?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?>" class="brand-logo" width="42" height="42"> <?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></a><nav>
<a href="../dashboard.php">Tableau de bord</a><a href="course.php?id=<?= (int) $courseId ?>" class="active">Ma formation</a>
<a href="../certificates/certificates.php">Mes certificats</a><a href="../results.php">Mes résultats</a><a href="../bookmarks.php">Mes favoris</a><a href="../messages.php">Messages</a><a href="../account.php">Mon compte</a>
<?= studentNotificationBell($pdo, $userId, "../notifications.php") ?>
<a href="../../auth/logout.php">Déconnexion</a></nav></header>
<main class="student-dashboard">
<section class="student-welcome"><div><p class="student-eyebrow">Formation inscrite</p><h1><?= studentEscape($course["title"]) ?></h1>
<p><?= studentEscape($course["short_description"] ?: "Parcourez les leçons et validez vos évaluations.") ?></p></div>
<div><button type="button" class="student-bookmark-star" id="course-bookmark-star" data-bookmarked="<?= $isBookmarked ? 1 : 0 ?>" title="Ajouter aux favoris"><?= $isBookmarked ? "★" : "☆" ?></button></div>
<div class="student-summary-number"><strong><?= $progressPercent ?>%</strong><span>progression</span></div></section>
<div class="student-progress"><span style="width: <?= $progressPercent ?>%"></span></div>
<section class="student-learning-layout">
<div>
<?php foreach ($modules as $module): ?>
<article class="student-section student-module-card"><h2><?= studentEscape($module["title"]) ?></h2>
<?php if ($module["description"]): ?><p class="student-muted"><?= studentEscape($module["description"]) ?></p><?php endif; ?>
<?php if (empty($lessonsByModule[$module["id"]])): ?><p class="student-muted">Aucune leçon publiée.</p><?php else: ?><ul class="student-content-list">
<?php foreach ($lessonsByModule[$module["id"]] as $lesson): ?><li><div><strong><?= studentEscape($lesson["title"]) ?></strong>
<small><?= (int) $lesson["estimated_minutes"] > 0 ? (int) $lesson["estimated_minutes"] . " min · " : "" ?><?= $lesson["completed"] ? "Terminée" : "À commencer" ?></small></div>
<a class="button primary-button" href="../lessons/lesson.php?id=<?= (int) $lesson["id"] ?>">Ouvrir</a></li><?php endforeach; ?></ul><?php endif; ?>
<?php if (!empty($quizzesByModule[$module["id"]])): ?><h3>Quiz</h3><ul class="student-content-list"><?php foreach ($quizzesByModule[$module["id"]] as $quiz): ?><li><div><strong><?= studentEscape($quiz["title"]) ?></strong><small>Réussite : <?= studentEscape($quiz["passing_score"]) ?>%<?php if ($quiz["last_score"] !== null): ?> · Dernier score : <?= studentEscape($quiz["last_score"]) ?>%<?php endif; ?></small></div><a class="button secondary-button" href="../quizzes/quiz.php?id=<?= (int) $quiz["id"] ?>">Passer le quiz</a></li><?php endforeach; ?></ul><?php endif; ?>
</article>
<?php endforeach; ?>
</div>
<aside class="student-section student-result-panel"><h2>Examen final</h2><?php if (!$exams): ?><p class="student-muted">Aucun examen publié.</p><?php else: ?><?php foreach ($exams as $exam): ?><div class="student-assessment"><strong><?= studentEscape($exam["title"]) ?></strong><small>Score requis : <?= studentEscape($exam["passing_score"]) ?>%<?php if ($exam["last_score"] !== null): ?> · Dernier score : <?= studentEscape($exam["last_score"]) ?>%<?php endif; ?></small><a class="button primary-button" href="../exams/exam.php?id=<?= (int) $exam["id"] ?>">Passer l'examen</a></div><?php endforeach; ?><?php endif; ?></aside>
</section></main>
<script>
(function () {
    var star = document.getElementById("course-bookmark-star");
    if (!star) return;
    star.addEventListener("click", function () {
        var body = new URLSearchParams({
            csrf_token: <?= json_encode($_SESSION["csrf_token"]) ?>,
            course_id: <?= (int) $courseId ?>
        });
        fetch("../bookmark_toggle.php", { method: "POST", body: body, credentials: "same-origin" })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) return;
                star.dataset.bookmarked = data.bookmarked ? "1" : "0";
                star.textContent = data.bookmarked ? "★" : "☆";
            });
    });
}());
</script>
<?= platformContactMarkup($pdo, "platform-contact platform-contact-app") ?>
</body></html>


