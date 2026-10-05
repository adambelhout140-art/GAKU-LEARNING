<?php
require_once __DIR__ . '/../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once "../config/database.php";
require_once "../config/student_access.php";
studentRequireAuth();
studentEnsureCsrf();

function e($value)
{
	return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$userId = (int) $_SESSION["user_id"];

// "Continue learning": the most recently touched, not-yet-completed lesson
// across any of the student's active enrollments.
$continueStatement = $pdo->prepare(
	"SELECT l.id AS lesson_id, l.title AS lesson_title, c.id AS course_id, c.title AS course_title, lp.updated_at
	 FROM lesson_progress lp
	 INNER JOIN lessons l ON l.id = lp.lesson_id AND l.is_published = 1
	 INNER JOIN modules m ON m.id = l.module_id
	 INNER JOIN courses c ON c.id = m.course_id
	 INNER JOIN enrollments e ON e.course_id = c.id AND e.user_id = lp.user_id
	 WHERE lp.user_id = ? AND lp.completed = 0 AND c.status = 'published'
	   AND e.status IN ('active', 'completed') AND (e.expires_at IS NULL OR e.expires_at >= NOW())
	 ORDER BY lp.updated_at DESC LIMIT 1"
);
$continueStatement->execute([$userId]);
$continueLesson = $continueStatement->fetch(PDO::FETCH_ASSOC);

$courseStatement = $pdo->prepare(
	"SELECT c.id, c.title, c.short_description, c.cover_image,
			e.status, e.enrolled_at,
			c.teacher_id, teacher.first_name AS teacher_first_name, teacher.last_name AS teacher_last_name,
			COUNT(DISTINCT l.id) AS lesson_count,
			COUNT(DISTINCT CASE WHEN lp.completed = 1 THEN l.id END) AS completed_lessons,
			COALESCE(ROUND(AVG(CASE WHEN lp.progress_percent IS NOT NULL THEN lp.progress_percent ELSE 0 END), 0), 0) AS progress_percent
	 FROM enrollments e
	 INNER JOIN courses c ON c.id = e.course_id
	 LEFT JOIN users teacher ON teacher.id = c.teacher_id AND teacher.role = 'teacher'
	 LEFT JOIN modules m ON m.course_id = c.id
	 LEFT JOIN lessons l ON l.module_id = m.id AND l.is_published = 1
	 LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.user_id = e.user_id
	 WHERE e.user_id = ? AND c.status = 'published' AND e.status IN ('active', 'completed')
	 GROUP BY c.id, c.title, c.short_description, c.cover_image, c.teacher_id,
			teacher.first_name, teacher.last_name, e.status, e.enrolled_at
	 ORDER BY e.enrolled_at DESC"
);
$courseStatement->execute([$userId]);
$courses = $courseStatement->fetchAll(PDO::FETCH_ASSOC);

$totalLessons = 0;
$completedLessons = 0;
$courseReviews = [];
$courseRatings = [];
$courseIds = array_values(array_filter(array_map("intval", array_column($courses, "id"))));
foreach ($courses as $course) {
	$totalLessons += (int) $course["lesson_count"];
	$completedLessons += (int) $course["completed_lessons"];
}
$overallProgress = $totalLessons > 0 ? (int) round(($completedLessons / $totalLessons) * 100) : 0;

if ($courseIds) {
	$coursePlaceholders = implode(",", array_fill(0, count($courseIds), "?"));
	$reviewStatement = $pdo->prepare(
		"SELECT course_id, rating, `comment` FROM teacher_reviews
		 WHERE student_id = ? AND course_id IN ($coursePlaceholders)"
	);
	$reviewStatement->execute(array_merge([$userId], $courseIds));
	foreach ($reviewStatement->fetchAll(PDO::FETCH_ASSOC) as $review) {
		$courseReviews[(int) $review["course_id"]] = $review;
	}

	$ratingStatement = $pdo->prepare(
		"SELECT course_id, ROUND(AVG(rating), 1) AS average_rating, COUNT(*) AS review_count
		 FROM teacher_reviews WHERE course_id IN ($coursePlaceholders) GROUP BY course_id"
	);
	$ratingStatement->execute($courseIds);
	foreach ($ratingStatement->fetchAll(PDO::FETCH_ASSOC) as $rating) {
		$courseRatings[(int) $rating["course_id"]] = $rating;
	}
}

$quizStatement = $pdo->prepare(
	"SELECT qa.id AS attempt_id, q.id AS quiz_id, qa.percentage, qa.passed, qa.completed_at, q.title, c.title AS course_title
	 FROM quiz_attempts qa
	 INNER JOIN quizzes q ON q.id = qa.quiz_id
	 INNER JOIN modules m ON m.id = q.module_id
	 INNER JOIN courses c ON c.id = m.course_id
	 INNER JOIN enrollments en ON en.course_id = c.id AND en.user_id = qa.user_id
	 WHERE qa.user_id = ? AND qa.completed_at IS NOT NULL
	   AND en.status IN ('active', 'completed')
	   AND (en.expires_at IS NULL OR en.expires_at >= NOW())
	 ORDER BY qa.completed_at DESC LIMIT 5"
);
$quizStatement->execute([$userId]);
$quizAttempts = $quizStatement->fetchAll(PDO::FETCH_ASSOC);

$examStatement = $pdo->prepare(
	"SELECT ea.id AS attempt_id, e.id AS exam_id, ea.percentage, ea.passed, ea.completed_at, e.title, c.title AS course_title
	 FROM exam_attempts ea
	 INNER JOIN exams e ON e.id = ea.exam_id
	 INNER JOIN courses c ON c.id = e.course_id
	 INNER JOIN enrollments en ON en.course_id = c.id AND en.user_id = ea.user_id
	 WHERE ea.user_id = ? AND ea.completed_at IS NOT NULL
	   AND en.status IN ('active', 'completed')
	   AND (en.expires_at IS NULL OR en.expires_at >= NOW())
	 ORDER BY ea.completed_at DESC LIMIT 5"
);
$examStatement->execute([$userId]);
$examAttempts = $examStatement->fetchAll(PDO::FETCH_ASSOC);

$pendingQuizStatement = $pdo->prepare(
	"SELECT COUNT(*) FROM quizzes q
	 INNER JOIN modules m ON m.id = q.module_id
	 INNER JOIN enrollments en ON en.course_id = m.course_id AND en.user_id = ?
	 WHERE en.status IN ('active', 'completed')
	   AND (en.expires_at IS NULL OR en.expires_at >= NOW())
	   AND NOT EXISTS (
		   SELECT 1 FROM quiz_attempts qa
		   WHERE qa.quiz_id = q.id AND qa.user_id = ? AND qa.completed_at IS NOT NULL
	   )"
);
$pendingQuizStatement->execute([$userId, $userId]);
$pendingQuizzes = (int) $pendingQuizStatement->fetchColumn();

$pendingExamStatement = $pdo->prepare(
	"SELECT COUNT(*) FROM exams ex
	 INNER JOIN enrollments en ON en.course_id = ex.course_id AND en.user_id = ?
	 WHERE en.status IN ('active', 'completed')
	   AND (en.expires_at IS NULL OR en.expires_at >= NOW())
	   AND NOT EXISTS (
		   SELECT 1 FROM exam_attempts ea
		   WHERE ea.exam_id = ex.id AND ea.user_id = ? AND ea.completed_at IS NOT NULL
	   )"
);
$pendingExamStatement->execute([$userId, $userId]);
$pendingExams = (int) $pendingExamStatement->fetchColumn();

$learningTimeStatement = $pdo->prepare(
	"SELECT COALESCE(SUM(lp.total_time_seconds), 0)
	 FROM lesson_progress lp
	 WHERE lp.user_id = ?"
);
$learningTimeStatement->execute([$userId]);
$learningMinutes = (int) round(((int) $learningTimeStatement->fetchColumn()) / 60);

$activityStatement = $pdo->prepare(
	"SELECT activity_type, activity_title, course_title, activity_date
	 FROM (
		 SELECT 'Leçon' AS activity_type, l.title AS activity_title, c.title AS course_title, lp.updated_at AS activity_date
		 FROM lesson_progress lp
		 INNER JOIN lessons l ON l.id = lp.lesson_id
		 INNER JOIN modules m ON m.id = l.module_id
		 INNER JOIN courses c ON c.id = m.course_id
		 WHERE lp.user_id = ? AND lp.updated_at IS NOT NULL
		 UNION ALL
		 SELECT 'Quiz', q.title, c.title, qa.completed_at
		 FROM quiz_attempts qa
		 INNER JOIN quizzes q ON q.id = qa.quiz_id
		 INNER JOIN modules m ON m.id = q.module_id
		 INNER JOIN courses c ON c.id = m.course_id
		 WHERE qa.user_id = ? AND qa.completed_at IS NOT NULL
		 UNION ALL
		 SELECT 'Examen', ex.title, c.title, ea.completed_at
		 FROM exam_attempts ea
		 INNER JOIN exams ex ON ex.id = ea.exam_id
		 INNER JOIN courses c ON c.id = ex.course_id
		 WHERE ea.user_id = ? AND ea.completed_at IS NOT NULL
	 ) recent_activity
	 ORDER BY activity_date DESC
	 LIMIT 6"
);
$activityStatement->execute([$userId, $userId, $userId]);
$recentActivities = $activityStatement->fetchAll(PDO::FETCH_ASSOC);

$certificateStatement = $pdo->prepare(
	"SELECT cert.id, cert.certificate_number, cert.issue_date, c.id AS course_id, c.title AS course_title
	 FROM certificates cert INNER JOIN courses c ON c.id = cert.course_id
	 WHERE cert.user_id = ? ORDER BY cert.issue_date DESC, cert.id DESC"
);
$certificateStatement->execute([$userId]);
$certificates = $certificateStatement->fetchAll(PDO::FETCH_ASSOC);
$certificateCount = count($certificates);

$userName = trim(($_SESSION["user_name"] ?? "") . " " . ($_SESSION["user_last_name"] ?? ""));
if ($userName === "") $userName = $_SESSION["user_email"] ?? "Étudiant";
$reviewStatus = $_GET["review"] ?? "";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Tableau de bord étudiant</title><link rel="icon" type="image/png" href="../assets/images/logo-mark.png">
	<link rel="stylesheet" href="../assets/css/style.css?v=20260924d">
</head>
<body class="student-body">
<header class="student-navbar">
	<a href="../index.php" class="student-brand"><img src="../assets/images/logo-mark.png" alt="Gaku Learning" class="brand-logo" width="42" height="42"> GAKU LEARNING</a>
	<nav>
		<a href="dashboard.php" class="active">Tableau de bord</a>
		
		<a href="certificates/certificates.php">Mes certificats</a>
		<a href="results.php">Mes résultats</a>
		<a href="bookmarks.php">Mes favoris</a>
		<a href="account.php">Mon compte</a>
		<?= studentNotificationBell($pdo, $userId, "notifications.php") ?>
		<a href="../auth/logout.php">Déconnexion</a>
	</nav>
</header>

<main class="student-dashboard">
	<section class="student-welcome">
		<div>
			<p class="student-eyebrow">Espace étudiant</p>
			<h1>Bonjour, <?= e($userName) ?></h1>
			<p>Continuez votre parcours de formation.</p>
		</div>
		<div class="student-summary-number"><strong><?= count($courses) ?></strong><span>formation(s) active(s)</span></div>
	</section>

	<?php if ($reviewStatus === "success"): ?><div class="alert alert-success">Votre avis sur le formateur a été enregistré.</div><?php endif; ?>
	<?php if ($reviewStatus === "rating"): ?><div class="alert alert-danger">Choisissez une note entre 1 et 5.</div><?php endif; ?>
	<?php if ($reviewStatus === "error"): ?><div class="alert alert-danger">Votre avis n'a pas pu être enregistré.</div><?php endif; ?>

	<?php if ($continueLesson): ?>
	<section class="student-section student-continue-widget">
		<div>
			<p class="student-eyebrow">Reprendre</p>
			<h2>Continuer : <?= e($continueLesson["lesson_title"]) ?></h2>
			<p class="student-muted"><?= e($continueLesson["course_title"]) ?></p>
		</div>
		<div class="student-continue-actions">
			<a class="button primary-button" href="lessons/lesson.php?id=<?= (int) $continueLesson["lesson_id"] ?>">Reprendre la leçon →</a>
			<a class="button secondary-button" href="courses/course.php?id=<?= (int) $continueLesson["course_id"] ?>">Voir la formation</a>
		</div>
	</section>
	<?php endif; ?>

	<section class="dashboard-search-panel" aria-label="Recherche avancée">
		<div class="dashboard-search-heading">
			<div><p class="student-eyebrow">Recherche avancée</p><h2>Rechercher dans mes formations</h2></div>
			<span data-search-count>0 résultat(s)</span>
		</div>
		<form data-dashboard-search class="dashboard-search-form">
			<input data-search-query type="search" placeholder="Nom, formateur ou description..." aria-label="Rechercher une formation">
			<select data-search-status aria-label="Filtrer par progression">
				<option value="">Toutes</option>
				<option value="active">En cours</option>
				<option value="completed">Terminées</option>
			</select>
			<button class="button secondary-button" type="reset">Réinitialiser</button>
		</form>
	</section>

	<section class="student-stat-grid">
		<article><span>📚</span><strong><?= count($courses) ?></strong><p>Mes formations</p></article>
		<article><span>✅</span><strong><?= $completedLessons ?></strong><p>Leçons terminées</p></article>
		<article><span>📈</span><strong><?= $overallProgress ?>%</strong><p>Progression globale</p></article>
		<article><span>⏱️</span><strong><?= $learningMinutes ?></strong><p>Minutes d'apprentissage</p></article>
		<article><span>📝</span><strong><?= $pendingQuizzes + $pendingExams ?></strong><p>Évaluations à faire</p></article>
	</section>

	<section class="student-dashboard-overview">
		<article class="student-section student-overview-card">
			<p class="student-eyebrow">À faire</p>
			<h2>Évaluations en attente</h2>
			<div class="student-overview-values">
				<div><strong><?= $pendingQuizzes ?></strong><span>Quiz</span></div>
				<div><strong><?= $pendingExams ?></strong><span>Examens</span></div>
			</div>
			<a class="button secondary-button" href="results.php">Voir mes résultats</a>
		</article>
		<article class="student-section student-overview-card">
			<p class="student-eyebrow">Temps d'étude</p>
			<h2>Votre apprentissage</h2>
			<strong class="student-learning-time"><?= $learningMinutes ?> min</strong>
			<p class="student-muted">Temps cumulé dans les leçons suivies.</p>
			<a class="button secondary-button" href="#courses">Continuer une formation</a>
		</article>
	</section>

	<section class="student-section" id="courses">
		<div class="student-section-heading"><div><p class="student-eyebrow">Apprentissage</p><h2>Mes formations</h2></div></div>
		<?php if (!$courses): ?>
			<div class="student-empty">Aucune formation ne vous est encore attribuée.<a class="button secondary-button" href="../public/courses.php">Découvrir les formations</a></div>
		<?php else: ?>
			<div class="student-course-grid">
				<?php foreach ($courses as $course): ?>
					<article class="student-course-card" data-dashboard-search-item data-search-status="<?= $course["status"] === "completed" ? "completed" : "active" ?>">
						<div class="student-course-accent"></div>
						<?php if (!empty($course["cover_image"])): ?><img class="student-course-cover" src="../uploads/courses/<?= e($course["cover_image"]) ?>" alt="Couverture de <?= e($course["title"]) ?>"><?php endif; ?>
						<div class="student-course-content">
							<span class="student-course-status"><?= $course["status"] === "completed" ? "Terminée" : "En cours" ?></span>
							<h3><a href="courses/course.php?id=<?= (int) $course["id"] ?>"><?= e($course["title"]) ?></a></h3>
							<p><?= e($course["short_description"] ?? "Formation en ligne") ?></p>
							<?php if ($course["teacher_id"]): ?>
								<p class="student-teacher"><strong>Formateur :</strong> <?= e(trim($course["teacher_first_name"] . " " . $course["teacher_last_name"])) ?></p>
							<?php endif; ?>
							<div class="student-progress-meta"><span>Progression</span><strong><?= e($course["progress_percent"]) ?>%</strong></div>
							<div class="student-progress"><span style="width: <?= min(100, max(0, (float) $course["progress_percent"])) ?>%"></span></div>
							<small><?= (int) $course["completed_lessons"] ?> / <?= (int) $course["lesson_count"] ?> leçons terminées</small>
							<p><a class="button primary-button" href="courses/course.php?id=<?= (int) $course["id"] ?>">Ouvrir la formation</a></p>
							<?php if ($course["teacher_id"]): ?>
								<?php $review = $courseReviews[(int) $course["id"]] ?? null; $rating = $courseRatings[(int) $course["id"]] ?? null; ?>
								<div class="student-teacher-review">
									<p class="student-review-summary">Évaluation du formateur : <?= $rating ? e($rating["average_rating"]) . "/5 (" . (int) $rating["review_count"] . ")" : "Aucune évaluation" ?></p>
									<form method="post" action="teacher_review.php" class="student-review-form">
										<input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
										<input type="hidden" name="course_id" value="<?= (int) $course["id"] ?>">
										<label for="rating-<?= (int) $course["id"] ?>">Votre note</label>
										<select id="rating-<?= (int) $course["id"] ?>" name="rating" required>
											<option value="">Choisir</option>
											<?php for ($score = 5; $score >= 1; $score--): ?><option value="<?= $score ?>" <?= $review && (int) $review["rating"] === $score ? "selected" : "" ?>><?= $score ?>/5</option><?php endfor; ?>
										</select>
										<label for="comment-<?= (int) $course["id"] ?>">Commentaire <span>(facultatif)</span></label>
										<textarea id="comment-<?= (int) $course["id"] ?>" name="comment" maxlength="1000" rows="3" placeholder="Comment le formateur enseigne-t-il ?"><?= e($review["comment"] ?? "") ?></textarea>
										<button class="button secondary-button" type="submit"><?= $review ? "Modifier mon avis" : "Évaluer le formateur" ?></button>
									</form>
								</div>
							<?php endif; ?>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>

	<section class="student-section" id="certificates">
		<div class="student-section-heading"><div><p class="student-eyebrow">Réussites</p><h2>Mes certificats</h2></div></div>
		<?php if (!$certificates): ?>
			<div class="student-empty">Vos certificats apparaîtront ici après la réussite d'un examen.</div>
		<?php else: ?>
			<div class="student-content-list">
				<?php foreach ($certificates as $certificate): ?>
					<div class="student-certificate-row"><div><strong><?= e($certificate["course_title"]) ?></strong><small><?= e($certificate["certificate_number"]) ?> · <?= date("d/m/Y", strtotime($certificate["issue_date"])) ?></small></div><a class="button secondary-button" href="certificates/certificate.php?id=<?= (int) $certificate["id"] ?>">Voir / télécharger</a></div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>

	<section class="student-results-grid">
		<article class="student-section student-result-panel"><h2>Derniers quiz</h2><?php if (!$quizAttempts): ?><p class="student-muted">Aucun quiz terminé.</p><?php else: ?><ul><?php foreach ($quizAttempts as $attempt): ?><li><div><strong><?= e($attempt["title"]) ?></strong><small><?= e($attempt["course_title"]) ?></small></div><span class="<?= $attempt["passed"] ? "student-result-good" : "student-result-bad" ?>"><?= e($attempt["percentage"]) ?>%</span><a class="student-result-review" href="quizzes/quiz.php?id=<?= (int) $attempt["quiz_id"] ?>&amp;attempt=<?= (int) $attempt["attempt_id"] ?>">Revoir</a></li><?php endforeach; ?></ul><?php endif; ?></article>
		<article class="student-section student-result-panel"><h2>Derniers examens</h2><?php if (!$examAttempts): ?><p class="student-muted">Aucun examen terminé.</p><?php else: ?><ul><?php foreach ($examAttempts as $attempt): ?><li><div><strong><?= e($attempt["title"]) ?></strong><small><?= e($attempt["course_title"]) ?></small></div><span class="<?= $attempt["passed"] ? "student-result-good" : "student-result-bad" ?>"><?= e($attempt["percentage"]) ?>%</span><a class="student-result-review" href="exams/exam.php?id=<?= (int) $attempt["exam_id"] ?>&amp;attempt=<?= (int) $attempt["attempt_id"] ?>">Revoir</a></li><?php endforeach; ?></ul><?php endif; ?></article>
	</section>

	<section class="student-section student-activity-panel">
		<div class="student-section-heading"><div><p class="student-eyebrow">Historique</p><h2>Activité récente</h2></div></div>
		<?php if (!$recentActivities): ?>
			<p class="student-muted">Votre activité récente apparaîtra ici.</p>
		<?php else: ?>
			<div class="student-activity-list">
				<?php foreach ($recentActivities as $activity): ?>
					<div class="student-activity-item">
						<span class="student-activity-icon"><?= $activity["activity_type"] === "Leçon" ? "📖" : ($activity["activity_type"] === "Quiz" ? "📝" : "🏁") ?></span>
						<div><strong><?= e($activity["activity_title"]) ?></strong><small><?= e($activity["activity_type"]) ?> · <?= e($activity["course_title"]) ?></small></div>
						<time datetime="<?= e($activity["activity_date"]) ?>"><?= e(date("d/m/Y H:i", strtotime($activity["activity_date"]))) ?></time>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>
</main>
<script src="../assets/js/dashboard-search.js?v=20260915a"></script>
</body>
</html>
