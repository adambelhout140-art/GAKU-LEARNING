<?php
require_once __DIR__ . '/../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";
require_once "../config/notifications.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}
if (($_SESSION["user_role"] ?? "") !== "teacher") {
    http_response_code(403);
    exit("Accès interdit.");
}

$teacherId = (int) $_SESSION["user_id"];
$courseStatement = $pdo->prepare(
    "SELECT c.id, c.title, c.status,
            COUNT(DISTINCT e.id) AS student_count,
            COUNT(DISTINCT qa.id) + COUNT(DISTINCT ea.id) AS result_count
     FROM courses c
     LEFT JOIN enrollments e ON e.course_id = c.id AND e.status IN ('active', 'completed')
     LEFT JOIN modules m ON m.course_id = c.id
     LEFT JOIN quizzes q ON q.module_id = m.id
     LEFT JOIN quiz_attempts qa ON qa.quiz_id = q.id AND qa.completed_at IS NOT NULL
     LEFT JOIN exams ex ON ex.course_id = c.id
     LEFT JOIN exam_attempts ea ON ea.exam_id = ex.id AND ea.completed_at IS NOT NULL
     WHERE EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id = c.id AND ct.teacher_id = ?)
     GROUP BY c.id, c.title, c.status
     ORDER BY c.updated_at DESC"
);
$courseStatement->execute([$teacherId]);
$courses = $courseStatement->fetchAll(PDO::FETCH_ASSOC);

$totalStudents = 0;
foreach ($courses as $course) {
    $totalStudents += (int) $course["student_count"];
}
$courseIds = array_map(static function (array $course): int {
    return (int) $course["id"];
}, $courses);
$coursePlaceholders = $courseIds ? implode(",", array_fill(0, count($courseIds), "?")) : "0";
$contentCounts = ["modules" => 0, "lessons" => 0, "quizzes" => 0, "exams" => 0];
if ($courseIds) {
    $countStatement = $pdo->prepare(
        "SELECT
            (SELECT COUNT(*) FROM modules WHERE course_id IN ($coursePlaceholders)) AS modules,
            (SELECT COUNT(*) FROM lessons WHERE module_id IN (
                SELECT id FROM modules WHERE course_id IN ($coursePlaceholders)
            )) AS lessons,
            (SELECT COUNT(*) FROM quizzes WHERE module_id IN (
                SELECT id FROM modules WHERE course_id IN ($coursePlaceholders)
            )) AS quizzes,
            (SELECT COUNT(*) FROM exams WHERE course_id IN ($coursePlaceholders)) AS exams"
    );
    $countStatement->execute(array_merge($courseIds, $courseIds, $courseIds, $courseIds));
    $contentCounts = $countStatement->fetch(PDO::FETCH_ASSOC) ?: $contentCounts;
}
$teacherName = trim(($_SESSION["user_name"] ?? "") . " " . ($_SESSION["user_last_name"] ?? ""));
if ($teacherName === "")
    $teacherName = "Formateur";
function teacherDashboardEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord formateur - <?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260925e">
</head>
<body class="admin-body teacher-view teacher-dashboard-page">
<div class="admin-layout">
    <?php require_once __DIR__ . "/../admin/config/admin_sidebar.php"; renderAdminSidebar("dashboard.php"); ?>
    <main class="admin-main teacher-dashboard">
        <header class="admin-topbar">
            <div><h1>Tableau de bord formateur</h1><p>Bienvenue, <?= teacherDashboardEscape($teacherName) ?>.</p></div>
            <?= roleNotificationBell($pdo, $teacherId, "notifications.php") ?>
            <a class="btn btn-primary" href="../admin/modules/modules.php">Gérer le contenu</a>
        </header>
        <section class="dashboard-search-panel" aria-label="Recherche avancée">
            <div class="dashboard-search-heading">
                <div><p class="student-eyebrow">Recherche avancée</p><h2>Accès rapide</h2></div>
                <span data-search-count>0 résultat(s)</span>
            </div>
            <form data-dashboard-search class="dashboard-search-form">
                <input data-search-query type="search" placeholder="Rechercher une section..." aria-label="Rechercher une section">
                <select data-search-status aria-label="Filtrer les sections">
                    <option value="">Toutes les sections</option>
                    <option value="content">Contenu</option>
                    <option value="results">Résultats</option>
                    <option value="communication">Communication</option>
                </select>
                <button class="button secondary-button" type="reset">Réinitialiser</button>
            </form>
            <div class="dashboard-search-links">
                <a data-dashboard-search-item data-search-status="content" href="../admin/modules/modules.php">🧩 Modules</a>
                <a data-dashboard-search-item data-search-status="content" href="../admin/lessons/lessons.php">📖 Leçons</a>
                <a data-dashboard-search-item data-search-status="content" href="../admin/quizzes/quizzes.php">🧠 Quiz</a>
                <a data-dashboard-search-item data-search-status="content" href="../admin/exams/exams.php">📝 Examens</a>
                <a data-dashboard-search-item data-search-status="results" href="results.php">📈 Résultats étudiants</a>
                <a data-dashboard-search-item data-search-status="communication" href="messages.php">✉️ Messages</a>
                <a data-dashboard-search-item data-search-status="communication" href="notifications.php">🔔 Notifications</a>
            </div>
        </section>
        <section class="dashboard-section">
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-icon">📚</div><div><p>Formations</p><h2><?= count($courses) ?></h2></div></div>
                <div class="stat-card"><div class="stat-icon">👥</div><div><p>Étudiants inscrits</p><h2><?= $totalStudents ?></h2></div></div>
                <div class="stat-card"><div class="stat-icon">🧩</div><div><p>Modules</p><h2><?= (int) $contentCounts["modules"] ?></h2></div></div>
                <div class="stat-card"><div class="stat-icon">📖</div><div><p>Leçons</p><h2><?= (int) $contentCounts["lessons"] ?></h2></div></div>
                <div class="stat-card"><div class="stat-icon">🧠</div><div><p>Quiz</p><h2><?= (int) $contentCounts["quizzes"] ?></h2></div></div>
                <div class="stat-card"><div class="stat-icon">📝</div><div><p>Examens</p><h2><?= (int) $contentCounts["exams"] ?></h2></div></div>
            </div>
        </section>
        <section class="teacher-results-panel">
            <div class="teacher-section-heading">
                <div>
                    <p class="student-eyebrow">Vos contenus</p>
                    <h2>Mes formations</h2>
                </div>
                <div class="teacher-section-actions"><a class="button secondary-button"
                        href="../admin/modules/modules.php">Modules</a><a class="button secondary-button"
                        href="../admin/lessons/lessons.php">Leçons</a><a class="button secondary-button"
                        href="../admin/quizzes/quizzes.php">Quiz</a><a class="button secondary-button"
                        href="../admin/exams/exams.php">Examens</a><a class="button secondary-button"
                        href="results.php">Résultats</a></div>
            </div>
            <?php if (!$courses): ?>
                <div class="student-empty">Vous n'avez encore aucune formation assignée.</div><?php else: ?>
                <div class="teacher-course-list"><?php foreach ($courses as $course): ?>
                        <article data-dashboard-search-item data-search-status="<?= $course["status"] === "published" ? "published" : "draft" ?>">
                            <div><strong><?= teacherDashboardEscape($course["title"]) ?></strong><small><?= (int) $course["student_count"] ?>
                                    étudiant(s) · <?= (int) $course["result_count"] ?> résultat(s)</small></div><span
                                class="teacher-status <?= $course["status"] === "published" ? "is-passed" : "is-draft" ?>"><?= teacherDashboardEscape($course["status"]) ?></span>
                        </article><?php endforeach; ?>
                </div><?php endif; ?>
        </section>
    </main>
</div>
<script src="../assets/js/admin-sidebar.js?v=20260925e"></script>
<script src="../assets/js/dashboard-search.js?v=20260915a"></script>
</body>
</html>
