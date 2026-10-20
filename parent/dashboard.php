<?php
require_once __DIR__ . '/../config/i18n.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";
require_once "../config/notifications.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}
if (($_SESSION["user_role"] ?? "") !== "parent") {
    http_response_code(403);
    exit("Accès interdit.");
}

function parentEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$parentId = (int) $_SESSION["user_id"];
$studentsStatement = $pdo->prepare(
    "SELECT u.id, u.first_name, u.last_name, u.email
     FROM parent_student_links l
     INNER JOIN users u ON u.id = l.student_id AND u.role = 'student' AND u.is_active = 1
     WHERE l.parent_id = ?
     ORDER BY u.last_name, u.first_name"
);
$studentsStatement->execute([$parentId]);
$students = $studentsStatement->fetchAll(PDO::FETCH_ASSOC);
$studentIds = array_map("intval", array_column($students, "id"));
$studentData = [];

if ($studentIds) {
    $placeholders = implode(",", array_fill(0, count($studentIds), "?"));
    $courseStatement = $pdo->prepare(
        "SELECT e.user_id, c.title, c.id AS course_id,
                COUNT(DISTINCT l.id) AS lesson_count,
                COUNT(DISTINCT CASE WHEN lp.completed = 1 THEN l.id END) AS completed_lessons,
                COALESCE(ROUND(AVG(COALESCE(lp.progress_percent, 0)), 0), 0) AS progress_percent
         FROM enrollments e
         INNER JOIN courses c ON c.id = e.course_id
         LEFT JOIN modules m ON m.course_id = c.id
         LEFT JOIN lessons l ON l.module_id = m.id AND l.is_published = 1
         LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.user_id = e.user_id
         WHERE e.user_id IN ($placeholders) AND e.status IN ('active', 'completed')
         GROUP BY e.user_id, c.id, c.title
         ORDER BY e.user_id, c.title"
    );
    $courseStatement->execute($studentIds);
    foreach ($courseStatement->fetchAll(PDO::FETCH_ASSOC) as $course) {
        $studentData[(int) $course["user_id"]]["courses"][] = $course;
    }

    $activityStatement = $pdo->prepare(
        "SELECT lp.user_id, l.title, c.title AS course_title, lp.updated_at
         FROM lesson_progress lp
         INNER JOIN lessons l ON l.id = lp.lesson_id
         INNER JOIN modules m ON m.id = l.module_id
         INNER JOIN courses c ON c.id = m.course_id
         WHERE lp.user_id IN ($placeholders) AND lp.updated_at IS NOT NULL
         ORDER BY lp.updated_at DESC LIMIT 20"
    );
    $activityStatement->execute($studentIds);
    foreach ($activityStatement->fetchAll(PDO::FETCH_ASSOC) as $activity) {
        $studentData[(int) $activity["user_id"]]["activity"][] = $activity;
    }

    $scoreStatement = $pdo->prepare(
        "SELECT qa.id AS attempt_id, qa.quiz_id, NULL AS exam_id, qa.user_id, 'Quiz' AS result_type, q.title, c.title AS course_title,
                qa.percentage, qa.passed, qa.completed_at,
                (SELECT qaf.comment FROM quiz_attempt_feedback qaf
                 WHERE qaf.quiz_attempt_id = qa.id AND qaf.visibility = 'student_parent'
                 ORDER BY qaf.updated_at DESC LIMIT 1) AS teacher_feedback
         FROM quiz_attempts qa
         INNER JOIN quizzes q ON q.id = qa.quiz_id
         INNER JOIN modules m ON m.id = q.module_id
         INNER JOIN courses c ON c.id = m.course_id
         WHERE qa.user_id IN ($placeholders) AND qa.completed_at IS NOT NULL
         UNION ALL
         SELECT ea.id AS attempt_id, NULL AS quiz_id, ea.exam_id, ea.user_id, 'Examen', ex.title, c.title,
                 ea.percentage, ea.passed, ea.completed_at,
                 (SELECT eaf.comment FROM exam_attempt_feedback eaf
                  WHERE eaf.exam_attempt_id = ea.id AND eaf.visibility = 'student_parent'
                  ORDER BY eaf.updated_at DESC LIMIT 1) AS teacher_feedback
         FROM exam_attempts ea
         INNER JOIN exams ex ON ex.id = ea.exam_id
         INNER JOIN courses c ON c.id = ex.course_id
         WHERE ea.user_id IN ($placeholders) AND ea.completed_at IS NOT NULL
         ORDER BY completed_at DESC"
    );
    $scoreStatement->execute(array_merge($studentIds, $studentIds));
    foreach ($scoreStatement->fetchAll(PDO::FETCH_ASSOC) as $score) {
        $studentData[(int) $score["user_id"]]["scores"][] = $score;
    }

    $certificateStatement = $pdo->prepare(
        "SELECT cert.user_id, cert.certificate_number, cert.issue_date,
                c.title AS course_title, attempt.combined_percentage
         FROM certificates cert
         INNER JOIN courses c ON c.id = cert.course_id
         INNER JOIN exam_attempts attempt ON attempt.id = cert.exam_attempt_id
         WHERE cert.user_id IN ($placeholders)
         ORDER BY cert.issue_date DESC"
    );
    $certificateStatement->execute($studentIds);
    foreach ($certificateStatement->fetchAll(PDO::FETCH_ASSOC) as $certificate) {
        $studentData[(int) $certificate["user_id"]]["certificates"][] = $certificate;
    }

    $timeStatement = $pdo->prepare(
        "SELECT user_id, COALESCE(SUM(total_time_seconds), 0) AS total_time_seconds
         FROM lesson_progress WHERE user_id IN ($placeholders) GROUP BY user_id"
    );
    $timeStatement->execute($studentIds);
    foreach ($timeStatement->fetchAll(PDO::FETCH_ASSOC) as $time) {
        $studentData[(int) $time["user_id"]]["learning_minutes"] = (int) round((int) $time["total_time_seconds"] / 60);
    }
}

$parentName = trim(($_SESSION["user_name"] ?? "") . " " . ($_SESSION["user_last_name"] ?? ""));
if ($parentName === "") {
    $parentName = "Parent";
}
?>
<!doctype html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Espace parent - <?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></title>
    <link rel="icon" type="image/png"  href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260925e">
</head>

<body class="student-body parent-body">
    <header class="student-navbar">
        <a href="../index.php" class="student-brand"><img src="<?= htmlspecialchars(platformLogoUrl($pdo, "light-bg"), ENT_QUOTES, "UTF-8") ?>" alt="<?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?>" class="brand-logo" width="38" height="38"><?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></a>
        <nav>
            <a href="dashboard.php" class="active">Mes enfants</a>
            
            <a href="message.php">Contacter</a>
            <a href="account.php">Mon compte</a>
            <?= roleNotificationBell($pdo, $parentId, "notifications.php") ?>
            <a href="../auth/logout.php">Déconnexion</a>
        </nav>
    </header>
    <main class="student-dashboard parent-dashboard">
        <section class="student-welcome">
            <div>
                <p class="student-eyebrow">Espace parent</p>
                <h1>Bonjour, <?= parentEscape($parentName) ?></h1>
                <p>Suivez la progression et les résultats de vos enfants.</p>
            </div>
            <div class="student-summary-number"><strong><?= count($students) ?></strong><span>enfant(s) lié(s)</span></div>
        </section>
        <?php if (!$students): ?>
            <section class="student-section student-empty">Aucun enfant n'est encore lié à votre compte. Contactez l'administrateur.</section>
            <?php else: ?>
                <section class="student-section parent-children-overview" id="enfants">
                    <div class="student-section-heading">
                        <div>
                            <p class="student-eyebrow">Vue d'ensemble</p>
                            <h2>Mes enfants</h2>
                            <p class="student-muted">Sélectionnez un enfant pour consulter sa progression détaillée.</p>
                            <p><a class="button primary-button" href="student_report_selection.php">Télécharger un rapport PDF</a></p>
                        </div>
                    </div>
                    <div class="parent-children-grid">
                        <?php foreach ($students as $student): $studentOverview = $studentData[(int) $student["id"]] ?? []; ?>
                            <a class="parent-child-shortcut" href="#enfant-<?= (int) $student["id"] ?>">
                                <span class="parent-child-avatar"><?= parentEscape(strtoupper(substr($student["first_name"], 0, 1) . substr($student["last_name"], 0, 1))) ?></span>
                                <span class="parent-child-shortcut-content">
                                    <strong><?= parentEscape(trim($student["first_name"] . " " . $student["last_name"])) ?></strong>
                                    <small><?= count($studentOverview["courses"] ?? []) ?> formation(s) · <?= count($studentOverview["scores"] ?? []) ?> résultat(s)</small>
                                </span>
                                <span class="parent-child-shortcut-arrow" aria-hidden="true">→</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php foreach ($students as $student): $data = $studentData[(int) $student["id"]] ?? []; ?>
                <section class="student-section parent-child-card" id="enfant-<?= (int) $student["id"] ?>">
                    <div class="student-section-heading">
                        <div>
                            <p class="student-eyebrow">Profil étudiant</p>
                            <h2><?= parentEscape(trim($student["first_name"] . " " . $student["last_name"])) ?></h2>
                            <p class="student-muted"><?= parentEscape($student["email"]) ?></p>
                        </div>
                        <div class="parent-child-heading-actions">
                            <span class="parent-readonly-badge">Lecture seule</span>
                            <a class="parent-back-link" href="#enfants">Retour aux enfants</a>
                        </div>
                    </div>
                    <div class="student-course-grid">
                        <?php foreach (($data["courses"] ?? []) as $course): ?>
                            <article class="student-course-card">
                                <div class="student-course-content">
                                    <span class="student-course-status">Formation</span>
                                    <h3><?= parentEscape($course["title"]) ?></h3>
                                    <div class="student-progress-meta"><span>Progression</span><strong><?= (int) $course["progress_percent"] ?>%</strong></div>
                                    <div class="student-progress"><span style="width: <?= min(100, max(0, (float) $course["progress_percent"])) ?>%"></span></div>
                                    <small><?= (int) $course["completed_lessons"] ?> / <?= (int) $course["lesson_count"] ?> leçons terminées</small>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <div class="parent-summary-grid">
                        <div><strong><?= (int) ($data["learning_minutes"] ?? 0) ?></strong><span>minutes d'apprentissage</span></div>
                        <div><strong><?= count($data["scores"] ?? []) ?></strong><span>résultats enregistrés</span></div>
                        <div><strong><?= count($data["certificates"] ?? []) ?></strong><span>certificat(s)</span></div>
                    </div>
                    <div class="parent-detail-grid">
                        <div class="parent-activity-list">
                            <h3>Quiz et examens</h3><?php if (empty($data["scores"])): ?><p class="student-muted">Aucun résultat.</p><?php else: foreach (array_slice($data["scores"], 0, 6) as $score): ?><div><strong><?= parentEscape($score["title"]) ?> — <?= parentEscape($score["percentage"]) ?>%</strong><small><?= parentEscape($score["result_type"]) ?> · <?= parentEscape($score["course_title"]) ?> · <?= $score["passed"] ? "Réussi" : "À revoir" ?></small><?php if (!empty($score["teacher_feedback"])): ?><p class="parent-feedback-note"><strong>Commentaire du formateur :</strong> <?= parentEscape($score["teacher_feedback"]) ?></p><?php endif; ?><?php if ($score["result_type"] === "Quiz" && !empty($score["quiz_id"]) && !empty($score["attempt_id"])): ?><a class="parent-view-result-link" href="../student/quizzes/quiz.php?id=<?= (int) $score["quiz_id"] ?>&amp;attempt=<?= (int) $score["attempt_id"] ?>&amp;student_id=<?= (int) $student["id"] ?>">Voir le quiz et le détail</a><?php elseif ($score["result_type"] === "Examen" && !empty($score["exam_id"]) && !empty($score["attempt_id"])): ?><a class="parent-view-result-link" href="../student/exams/exam.php?id=<?= (int) $score["exam_id"] ?>&amp;attempt=<?= (int) $score["attempt_id"] ?>&amp;student_id=<?= (int) $student["id"] ?>">Voir l'examen et le détail</a><?php endif; ?></div><?php endforeach;
                                                                                                                                                                                                                                                                                                                                                                                                                                                                            endif; ?>
                        </div>
                        <div class="parent-activity-list">
                            <h3>Certificats</h3><?php if (empty($data["certificates"])): ?><p class="student-muted">Aucun certificat.</p><?php else: foreach ($data["certificates"] as $certificate): ?><div><strong><?= parentEscape($certificate["course_title"]) ?> — <?= parentEscape($certificate["combined_percentage"]) ?>%</strong><small><?= parentEscape($certificate["certificate_number"]) ?> · <?= parentEscape(date("d/m/Y", strtotime($certificate["issue_date"]))) ?></small></div><?php endforeach;
                                                                                                                                                                                                                                                                                                                                                                                                                                endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($data["activity"])): ?>
                        <div class="parent-activity-list">
                            <h3>Activité récente</h3><?php foreach (array_slice($data["activity"], 0, 5) as $activity): ?><div><strong><?= parentEscape($activity["title"]) ?></strong><small><?= parentEscape($activity["course_title"]) ?> · <?= parentEscape(date("d/m/Y H:i", strtotime($activity["updated_at"]))) ?></small></div><?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
        <?php endforeach;
        endif; ?>
    </main>
<?= platformContactMarkup($pdo, "platform-contact platform-contact-app") ?>
</body>

</html>