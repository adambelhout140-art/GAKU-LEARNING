<?php
require_once __DIR__ . '/../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";
if (!isset($_SESSION["user_id"]) || ($_SESSION["user_role"] ?? "") !== "teacher") {
    http_response_code(403);
    exit("Accès interdit.");
}

$teacherId = (int) $_SESSION["user_id"];
$search = trim($_GET["search"] ?? "");
$status = $_GET["status"] ?? "";
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET["from"] ?? "") ? $_GET["from"] : "";
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET["to"] ?? "") ? $_GET["to"] : "";
$inactiveDays = max(1, min(365, (int) ($_GET["inactive_days"] ?? 14)));
$cutoff = date("Y-m-d H:i:s", time() - ($inactiveDays * 86400));
$selectedUserId = filter_input(INPUT_GET, "user_id", FILTER_VALIDATE_INT);
$selectedUserId = $selectedUserId && $selectedUserId > 0 ? $selectedUserId : null;
$selectedCourseId = filter_input(INPUT_GET, "course_id", FILTER_VALIDATE_INT);
$selectedCourseId = $selectedCourseId && $selectedCourseId > 0 ? $selectedCourseId : null;

$courseStatement = $pdo->prepare("SELECT id, title FROM courses WHERE EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id = courses.id AND ct.teacher_id = ?) ORDER BY title");
$courseStatement->execute([$teacherId]);
$courses = $courseStatement->fetchAll(PDO::FETCH_ASSOC);
$ownedCourseIds = array_map("intval", array_column($courses, "id"));
if ($selectedCourseId && !in_array($selectedCourseId, $ownedCourseIds, true)) {
    $selectedCourseId = null;
}

$where = ["EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id = c.id AND ct.teacher_id = ?)", "u.role = 'student'", "u.is_active = 1", "e.status IN ('active', 'completed')"];
$params = [$teacherId];
if (!$ownedCourseIds) {
    $where[] = "1 = 0";
}
if ($search !== "") {
    $where[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR ex.title LIKE ? OR c.title LIKE ?)";
    $term = "%" . $search . "%";
    array_push($params, $term, $term, $term, $term, $term);
}
if (in_array($status, ["passed", "failed"], true)) {
    $where[] = "ea.passed = ?";
    $params[] = $status === "passed" ? 1 : 0;
}
if ($selectedCourseId) { $where[] = "c.id = ?"; $params[] = $selectedCourseId; }
if ($from) { $where[] = "COALESCE(ea.completed_at, ea.started_at) >= ?"; $params[] = $from . " 00:00:00"; }
if ($to) { $where[] = "COALESCE(ea.completed_at, ea.started_at) <= ?"; $params[] = $to . " 23:59:59"; }
$statement = $pdo->prepare("SELECT ea.id, u.id AS user_id, ea.attempt_number, ea.score, ea.total_points, ea.percentage, ea.passed, ea.started_at, ea.completed_at, u.first_name, u.last_name, u.email, ex.title AS exam_title, c.title AS course_title
    FROM exam_attempts ea INNER JOIN users u ON u.id = ea.user_id INNER JOIN exams ex ON ex.id = ea.exam_id INNER JOIN courses c ON c.id = ex.course_id
    INNER JOIN enrollments e ON e.user_id = u.id AND e.course_id = c.id
    WHERE " . implode(" AND ", $where) . " ORDER BY COALESCE(ea.completed_at, ea.started_at) DESC, ea.id DESC LIMIT 500");
$statement->execute($params);
$results = $statement->fetchAll(PDO::FETCH_ASSOC);

$studentWhere = ["u.role = 'student'", "u.is_active = 1", "en.status IN ('active', 'completed')", "EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id = c.id AND ct.teacher_id = ?)"];
$studentParams = [$teacherId];
if (!$ownedCourseIds) {
    $studentWhere[] = "1 = 0";
}
if ($search !== "") { $studentWhere[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)"; $term = "%" . $search . "%"; array_push($studentParams, $term, $term, $term); }
if ($selectedCourseId) { $studentWhere[] = "c.id = ?"; $studentParams[] = $selectedCourseId; }
$studentStatement = $pdo->prepare("SELECT u.id, u.first_name, u.last_name, u.email, COUNT(DISTINCT c.id) AS course_count,
    COUNT(DISTINCT CASE WHEN lp.completed = 1 THEN l.id END) AS completed_lessons, COUNT(DISTINCT lp.lesson_id) AS started_lessons,
    COUNT(DISTINCT qa.id) AS quiz_attempts, COUNT(DISTINCT ea.id) AS exam_attempts,
    COALESCE(GREATEST(MAX(lp.updated_at), MAX(qa.completed_at), MAX(ea.completed_at)), '1000-01-01') AS last_activity
    FROM users u INNER JOIN enrollments en ON en.user_id = u.id INNER JOIN courses c ON c.id = en.course_id
    LEFT JOIN modules m ON m.course_id = c.id LEFT JOIN lessons l ON l.module_id = m.id AND l.is_published = 1
    LEFT JOIN lesson_progress lp ON lp.user_id = u.id AND lp.lesson_id = l.id LEFT JOIN quizzes q ON q.module_id = m.id
    LEFT JOIN quiz_attempts qa ON qa.user_id = u.id AND qa.quiz_id = q.id LEFT JOIN exams ex ON ex.course_id = c.id
    LEFT JOIN exam_attempts ea ON ea.user_id = u.id AND ea.exam_id = ex.id WHERE " . implode(" AND ", $studentWhere) . "
    GROUP BY u.id, u.first_name, u.last_name, u.email
    ORDER BY (
        COALESCE(GREATEST(MAX(lp.updated_at), MAX(qa.completed_at), MAX(ea.completed_at)), '1000-01-01') < ?
    ),
    COALESCE(GREATEST(MAX(lp.updated_at), MAX(qa.completed_at), MAX(ea.completed_at)), '1000-01-01') DESC,
    u.last_name, u.first_name
    LIMIT 500");
$studentParams[] = $cutoff;
$studentStatement->execute($studentParams);
$students = $studentStatement->fetchAll(PDO::FETCH_ASSOC);

$selectedStudent = null; $courseProgress = []; $lessonActivity = []; $quizActivity = []; $examActivity = []; $selectedCompletedLessonCount = 0;
if ($selectedUserId) {
    $s = $pdo->prepare("SELECT u.id, u.first_name, u.last_name, u.email, u.created_at, u.last_login_at FROM users u WHERE u.id = ? AND u.role = 'student' AND u.is_active = 1 AND EXISTS (SELECT 1 FROM enrollments en INNER JOIN courses c ON c.id = en.course_id WHERE en.user_id = u.id AND en.status IN ('active','completed') AND EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id = c.id AND ct.teacher_id = ?" . ($selectedCourseId ? " AND c.id = ?" : "") . ")) LIMIT 1");
    $sp = [$selectedUserId, $teacherId]; if ($selectedCourseId) $sp[] = $selectedCourseId; $s->execute($sp); $selectedStudent = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($selectedStudent) {
        $courseProgressSql = "SELECT c.id, c.title, e.status, e.enrolled_at, e.completed_at, COUNT(DISTINCT l.id) AS lesson_count, COUNT(DISTINCT CASE WHEN lp.completed = 1 THEN l.id END) AS completed_lessons, COALESCE(ROUND(AVG(CASE WHEN l.id IS NOT NULL THEN COALESCE(lp.progress_percent, 0) END), 0), 0) AS progress_percent, COALESCE(SUM(lp.total_time_seconds), 0) AS total_time_seconds, MAX(lp.updated_at) AS last_lesson_activity, (SELECT COUNT(*) FROM quiz_attempts qa INNER JOIN quizzes q ON q.id = qa.quiz_id INNER JOIN modules qm ON qm.id = q.module_id WHERE qa.user_id = e.user_id AND qm.course_id = c.id) AS quiz_attempts, (SELECT COUNT(*) FROM exam_attempts ea INNER JOIN exams ex ON ex.id = ea.exam_id WHERE ea.user_id = e.user_id AND ex.course_id = c.id) AS exam_attempts FROM enrollments e INNER JOIN courses c ON c.id = e.course_id         AND EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id = c.id AND ct.teacher_id = ?) LEFT JOIN modules m ON m.course_id = c.id LEFT JOIN lessons l ON l.module_id = m.id AND l.is_published = 1 LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.user_id = e.user_id WHERE e.user_id = ? AND e.status IN ('active','completed')" . ($selectedCourseId ? " AND c.id = ?" : "") . " GROUP BY c.id, c.title, e.user_id, e.status, e.enrolled_at, e.completed_at ORDER BY e.enrolled_at DESC";
        $cp = [$teacherId, $selectedUserId]; if ($selectedCourseId) $cp[] = $selectedCourseId; $x = $pdo->prepare($courseProgressSql); $x->execute($cp); $courseProgress = $x->fetchAll(PDO::FETCH_ASSOC);
        $lessonSql = "SELECT c.title AS course_title, m.title AS module_title, l.title AS lesson_title, COALESCE(lp.progress_percent,0) AS progress_percent, COALESCE(lp.completed,0) AS completed, COALESCE(lp.total_time_seconds,0) AS total_time_seconds, COALESCE(lp.last_position,0) AS last_position, lp.started_at, lp.completed_at, lp.updated_at FROM lessons l INNER JOIN modules m ON m.id=l.module_id         INNER JOIN courses c ON c.id=m.course_id AND EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?) INNER JOIN enrollments e ON e.course_id=c.id AND e.user_id=? AND e.status IN ('active','completed') LEFT JOIN lesson_progress lp ON lp.lesson_id=l.id AND lp.user_id=? WHERE l.is_published=1" . ($selectedCourseId ? " AND c.id=?" : "") . ($from ? " AND lp.updated_at>=?" : "") . ($to ? " AND lp.updated_at<=?" : "") . " ORDER BY COALESCE(lp.updated_at,'1000-01-01') DESC,c.title,m.position,l.position";
        $lp = [$teacherId,$selectedUserId,$selectedUserId]; if ($selectedCourseId) $lp[]=$selectedCourseId; if($from)$lp[]=$from." 00:00:00"; if($to)$lp[]=$to." 23:59:59"; $x=$pdo->prepare($lessonSql);$x->execute($lp);$lessonActivity=$x->fetchAll(PDO::FETCH_ASSOC); foreach($lessonActivity as $lesson){if((int)$lesson['completed']===1)$selectedCompletedLessonCount++;}
        $assessmentBase = " INNER JOIN enrollments e ON e.course_id=c.id AND e.user_id=? AND e.status IN ('active','completed') WHERE qa.user_id=? AND qa.completed_at IS NOT NULL" . ($selectedCourseId ? " AND c.id=?" : "") . ($from ? " AND qa.completed_at>=?" : "") . ($to ? " AND qa.completed_at<=?" : "");
        $qp=[$teacherId,$selectedUserId,$selectedUserId]; if($selectedCourseId)$qp[]=$selectedCourseId; if($from)$qp[]=$from." 00:00:00";if($to)$qp[]=$to." 23:59:59";
        $x=$pdo->prepare("SELECT qa.id,qa.attempt_number,qa.score,qa.total_points,qa.percentage,qa.passed,qa.started_at,qa.completed_at,q.title AS quiz_title,c.title AS course_title FROM quiz_attempts qa INNER JOIN quizzes q ON q.id=qa.quiz_id INNER JOIN modules m ON m.id=q.module_id INNER JOIN courses c ON c.id=m.course_id AND EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)".$assessmentBase." ORDER BY qa.completed_at DESC,qa.id DESC");$x->execute($qp);$quizActivity=$x->fetchAll(PDO::FETCH_ASSOC);
        $ep=[$teacherId,$selectedUserId]; if($selectedCourseId)$ep[]=$selectedCourseId; if($from)$ep[]=$from." 00:00:00";if($to)$ep[]=$to." 23:59:59"; $x=$pdo->prepare("SELECT ea.id,ea.attempt_number,ea.score,ea.total_points,ea.percentage,ea.passed,ea.started_at,ea.completed_at,ex.title AS exam_title,c.title AS course_title FROM exam_attempts ea INNER JOIN exams ex ON ex.id=ea.exam_id         INNER JOIN courses c ON c.id=ex.course_id AND EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?) INNER JOIN enrollments e ON e.course_id=c.id AND e.user_id=ea.user_id AND e.status IN ('active','completed') WHERE ea.user_id=?" . ($selectedCourseId ? " AND c.id=?" : "") . ($from ? " AND ea.completed_at>=?" : "") . ($to ? " AND ea.completed_at<=?" : "") . " ORDER BY ea.completed_at DESC,ea.id DESC"); $ep=array_merge([$teacherId,$selectedUserId],array_slice($ep,2)); $x->execute($ep);$examActivity=$x->fetchAll(PDO::FETCH_ASSOC);
    }
}
if (($_GET["export"] ?? "") === "csv") {
    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"rapport-enseignant-" . date("Y-m-d") . ".csv\"");
    $out = fopen("php://output", "w");
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ["Étudiant", "Formation", "Examen", "Score", "Statut", "Date"], ";");
    foreach ($results as $row) {
        fputcsv($out, [trim($row["first_name"] . " " . $row["last_name"]), $row["course_title"], $row["exam_title"], $row["percentage"] . "%", $row["passed"] ? "Réussi" : "Échoué", $row["completed_at"] ?: $row["started_at"]], ";");
    }
    fclose($out);
    exit;
}
function adminResultsEscape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function adminResultsDate($value): string { return $value ? date('d/m/Y H:i', strtotime($value)) : "—"; }
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Résultats des examens - Administration</title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260925e">
</head>

<body class="admin-body teacher-view">
    <div class="admin-layout">
        <!-- ======================================================
         SIDEBAR
         ====================================================== -->

    <?php require_once __DIR__ . "/../admin/config/admin_sidebar.php"; renderAdminSidebar("results.php"); ?>
        <main class="admin-main">
            <header class="admin-topbar">
                <div>
                    <h1>Résultats des examens</h1>
                    <p>Consultez les tentatives et les scores de tous les étudiants.</p>
                </div>
            </header>
            <section class="admin-content">
                <form method="get" class="admin-filter-form">
                    <input type="search" name="search" placeholder="Nom ou email de l'étudiant..." value="<?= adminResultsEscape($search) ?>">
                    <select name="course_id">
                        <option value="">Toutes les formations</option>
                        <?php foreach ($courses as $course): ?>
                            <option value="<?= (int) $course["id"] ?>" <?= $selectedCourseId === (int) $course["id"] ? "selected" : "" ?>><?= adminResultsEscape($course["title"]) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status">
                        <option value="">Tous les résultats</option>
                        <option value="passed" <?= $status === "passed" ? "selected" : "" ?>>Réussis</option>
                        <option value="failed" <?= $status === "failed" ? "selected" : "" ?>>Échoués</option>
                    </select>
                    <label>Du <input type="date" name="from" value="<?= adminResultsEscape($from) ?>"></label>
                    <label>Au <input type="date" name="to" value="<?= adminResultsEscape($to) ?>"></label>
                    <label>Inactif depuis <input type="number" name="inactive_days" min="1" max="365" value="<?= $inactiveDays ?>"> jours</label>
                    <button class="admin-button" type="submit">Filtrer</button>
                    <a class="admin-button secondary" href="results.php">Réinitialiser</a>
                </form>

                <?php if ($selectedStudent): ?>
                    <div class="admin-results-student-heading">
                        <div>
                            <p class="student-eyebrow">Fiche d'activité étudiant</p>
                            <h2><?= adminResultsEscape(trim($selectedStudent["first_name"] . " " . $selectedStudent["last_name"])) ?></h2>
                            <p><?= adminResultsEscape($selectedStudent["email"]) ?> · Dernière connexion : <?= adminResultsDate($selectedStudent["last_login_at"]) ?></p>
                        </div>
                        <a class="admin-button secondary" href="report.php?student_id=<?= (int) $selectedStudent["id"] ?><?= $selectedCourseId ? "&amp;course_id=" . (int) $selectedCourseId : "" ?><?= $from ? "&amp;from=" . adminResultsEscape($from) : "" ?><?= $to ? "&amp;to=" . adminResultsEscape($to) : "" ?>">Imprimer / PDF</a>
                        <a class="admin-button secondary" href="results.php">Retour aux étudiants</a>
                    </div>

                    <div class="admin-results-stat-grid">
                        <div class="admin-results-stat"><strong><?= count($courseProgress) ?></strong><span>Formations</span></div>
                        <div class="admin-results-stat"><strong><?= $selectedCompletedLessonCount ?></strong><span>Leçons terminées</span></div>
                        <div class="admin-results-stat"><strong><?= count($quizActivity) ?></strong><span>Tentatives quiz</span></div>
                        <div class="admin-results-stat"><strong><?= count($examActivity) ?></strong><span>Tentatives examens</span></div>
                    </div>

                    <section class="admin-results-panel">
                        <div class="admin-section-heading"><div><h2>Progression par formation</h2><p>Accès, avancement des leçons et activité d'évaluation.</p></div></div>
                        <div class="admin-table-wrapper"><table class="admin-table">
                            <thead><tr><th>Formation</th><th>Accès</th><th>Leçons</th><th>Progression</th><th>Quiz</th><th>Examens</th><th>Temps</th><th>Dernière activité</th></tr></thead>
                            <tbody>
                            <?php if (!$courseProgress): ?><tr><td colspan="8">Aucune formation attribuée.</td></tr>
                            <?php else: foreach ($courseProgress as $course): ?><tr>
                                <td><strong><?= adminResultsEscape($course["title"]) ?></strong></td>
                                <td><?= adminResultsEscape(ucfirst($course["status"])) ?><br><small>Depuis <?= adminResultsDate($course["enrolled_at"]) ?></small></td>
                                <td><?= (int) $course["completed_lessons"] ?> / <?= (int) $course["lesson_count"] ?></td>
                                <td><strong><?= adminResultsEscape($course["progress_percent"]) ?>%</strong><div class="admin-results-progress"><span style="width: <?= min(100, max(0, (float) $course["progress_percent"])) ?>%"></span></div></td>
                                <td><?= (int) $course["quiz_attempts"] ?></td>
                                <td><?= (int) $course["exam_attempts"] ?></td>
                                <td><?= (int) round((int) $course["total_time_seconds"] / 60) ?> min</td>
                                <td><?= adminResultsDate($course["last_lesson_activity"]) ?></td>
                            </tr><?php endforeach; endif; ?>
                            </tbody>
                        </table></div>
                    </section>

                    <section class="admin-results-panel">
                        <div class="admin-section-heading"><div><h2>Activité des leçons</h2><p>État enregistré pour chaque leçon publiée accessible à cet étudiant.</p></div></div>
                        <div class="admin-table-wrapper"><table class="admin-table">
                            <thead><tr><th>Formation / module</th><th>Leçon</th><th>Progression</th><th>État</th><th>Temps</th><th>Position</th><th>Commencée</th><th>Dernière activité</th></tr></thead>
                            <tbody>
                            <?php if (!$lessonActivity): ?><tr><td colspan="8">Aucune leçon disponible.</td></tr>
                            <?php else: foreach ($lessonActivity as $lesson): ?><tr>
                                <td><?= adminResultsEscape($lesson["course_title"]) ?><br><small><?= adminResultsEscape($lesson["module_title"]) ?></small></td>
                                <td><strong><?= adminResultsEscape($lesson["lesson_title"]) ?></strong></td>
                                <td><?= adminResultsEscape($lesson["progress_percent"]) ?>%<div class="admin-results-progress"><span style="width: <?= min(100, max(0, (float) $lesson["progress_percent"])) ?>%"></span></div></td>
                                <td><span class="status-badge <?= $lesson["completed"] ? "status-active" : ((float) $lesson["progress_percent"] > 0 ? "status-pending" : "status-suspended") ?>"><?= $lesson["completed"] ? "Terminée" : ((float) $lesson["progress_percent"] > 0 ? "En cours" : "Non commencée") ?></span></td>
                                <td><?= (int) round((int) $lesson["total_time_seconds"] / 60) ?> min</td>
                                <td><?= (int) $lesson["last_position"] ?> s</td>
                                <td><?= adminResultsDate($lesson["started_at"]) ?></td>
                                <td><?= adminResultsDate($lesson["updated_at"]) ?></td>
                            </tr><?php endforeach; endif; ?>
                            </tbody>
                        </table></div>
                    </section>

                    <div class="admin-results-two-columns">
                        <section class="admin-results-panel">
                            <div class="admin-section-heading"><div><h2>Quiz</h2><p>Toutes les tentatives, y compris celles en cours.</p></div></div>
                            <div class="admin-table-wrapper"><table class="admin-table">
                                <thead><tr><th>Quiz / formation</th><th>Score</th><th>État</th><th>Date</th><th></th></tr></thead>
                                <tbody><?php if (!$quizActivity): ?><tr><td colspan="5">Aucune tentative.</td></tr><?php else: foreach ($quizActivity as $attempt): ?><tr>
                                    <td><strong><?= adminResultsEscape($attempt["quiz_title"]) ?></strong><br><small><?= adminResultsEscape($attempt["course_title"]) ?></small></td>
                                    <td><?= $attempt["completed_at"] ? adminResultsEscape($attempt["percentage"]) . "%" : "—" ?><br><small>#<?= (int) $attempt["attempt_number"] ?></small></td>
                                    <td><?= !$attempt["completed_at"] ? "En cours" : ($attempt["passed"] ? "Réussi" : "À revoir") ?></td>
                                    <td><?= adminResultsDate($attempt["completed_at"] ?: $attempt["started_at"]) ?></td>
                                    <td><?php if ($attempt["completed_at"]): ?><a class="admin-button small" href="attempt.php?type=quiz&amp;attempt=<?= (int) $attempt["id"] ?>">Commenter</a><?php endif; ?></td>
                                </tr><?php endforeach; endif; ?></tbody>
                            </table></div>
                        </section>
                        <section class="admin-results-panel">
                            <div class="admin-section-heading"><div><h2>Examens</h2><p>Résultats et tentatives d'examen.</p></div></div>
                            <div class="admin-table-wrapper"><table class="admin-table">
                                <thead><tr><th>Examen / formation</th><th>Score</th><th>État</th><th>Date</th></tr></thead>
                                <tbody><?php if (!$examActivity): ?><tr><td colspan="4">Aucune tentative.</td></tr><?php else: foreach ($examActivity as $attempt): ?><tr>
                                    <td><strong><?= adminResultsEscape($attempt["exam_title"]) ?></strong><br><small><?= adminResultsEscape($attempt["course_title"]) ?></small></td>
                                    <td><?= $attempt["completed_at"] ? adminResultsEscape($attempt["percentage"]) . "%" : "—" ?><br><small>#<?= (int) $attempt["attempt_number"] ?></small></td>
                                    <td><?= !$attempt["completed_at"] ? "En cours" : ($attempt["passed"] ? "Réussi" : "À revoir") ?></td>
                                    <td><?= adminResultsDate($attempt["completed_at"] ?: $attempt["started_at"]) ?></td>
                                </tr><?php endforeach; endif; ?></tbody>
                            </table></div>
                        </section>
                    </div>
                <?php else: ?>
                    <section class="admin-results-panel">
                        <div class="admin-section-heading"><div><h2>Suivi des étudiants</h2><p>Sélectionnez un étudiant pour voir tout son parcours d'apprentissage.</p></div><strong><?= count($students) ?> étudiant(s)</strong></div>
                        <div class="admin-table-wrapper"><table class="admin-table">
                            <thead><tr><th>Étudiant</th><th>Formations</th><th>Leçons</th><th>Quiz</th><th>Examens</th><th>Dernière activité</th><th>Alerte</th><th></th></tr></thead>
                            <tbody>
                            <?php if (!$students): ?><tr><td colspan="8">Aucun étudiant trouvé.</td></tr>
                            <?php else: foreach ($students as $student): $inactive = $student["last_activity"] === "1000-01-01" || $student["last_activity"] < $cutoff; ?><tr>
                                <td><a href="results.php?user_id=<?= (int) $student["id"] ?><?= $selectedCourseId ? "&amp;course_id=" . (int) $selectedCourseId : "" ?>"><strong><?= adminResultsEscape(trim($student["first_name"] . " " . $student["last_name"])) ?></strong></a><br><small><?= adminResultsEscape($student["email"]) ?></small></td>
                                <td><?= (int) $student["course_count"] ?></td>
                                <td><?= (int) $student["completed_lessons"] ?> terminées<br><small><?= (int) $student["started_lessons"] ?> commencées</small></td>
                                <td><?= (int) $student["quiz_attempts"] ?></td>
                                <td><?= (int) $student["exam_attempts"] ?></td>
                                <td><?= $student["last_activity"] === "1000-01-01" ? "Jamais" : adminResultsDate($student["last_activity"]) ?></td>
                                <td><span class="status-badge <?= $inactive ? "status-suspended" : "status-active" ?>"><?= $inactive ? "À relancer" : "Actif récemment" ?></span></td>
                                <td><a class="admin-button small" href="results.php?user_id=<?= (int) $student["id"] ?><?= $selectedCourseId ? "&amp;course_id=" . (int) $selectedCourseId : "" ?>">Voir le détail</a></td>
                            </tr><?php endforeach; endif; ?>
                            </tbody>
                        </table></div>
                    </section>

                    <section class="admin-results-panel">
                        <div class="admin-section-heading"><div><h2>Derniers résultats d'examen</h2><p>Vue rapide des évaluations récentes.</p></div></div>
                        <div class="admin-table-wrapper"><table class="admin-table">
                            <thead><tr><th>Étudiant</th><th>Formation / examen</th><th>Score</th><th>Tentative</th><th>Date</th><th>État</th><th></th></tr></thead>
                            <tbody><?php if (!$results): ?><tr><td colspan="7">Aucun résultat trouvé.</td></tr><?php else: foreach ($results as $result): ?><tr>
                                <td><a href="results.php?user_id=<?= (int) $result["user_id"] ?><?= $selectedCourseId ? "&amp;course_id=" . (int) $selectedCourseId : "" ?>"><strong><?= adminResultsEscape(trim($result["first_name"] . " " . $result["last_name"])) ?></strong></a><br><small><?= adminResultsEscape($result["email"]) ?></small></td>
                                <td><?= adminResultsEscape($result["course_title"]) ?><br><small><?= adminResultsEscape($result["exam_title"]) ?></small></td>
                                <td><?= adminResultsEscape($result["percentage"]) ?>%<br><small><?= adminResultsEscape($result["score"]) ?> / <?= adminResultsEscape($result["total_points"]) ?></small></td>
                                <td>#<?= (int) $result["attempt_number"] ?></td>
                                <td><?= adminResultsDate($result["completed_at"] ?: $result["started_at"]) ?></td>
                                <td><span class="status-badge <?= $result["passed"] ? "status-active" : "status-suspended" ?>"><?= $result["passed"] ? "Réussi" : "Échoué" ?></span></td>
                                <td><a class="admin-button small" href="attempt.php?type=exam&amp;attempt=<?= (int) $result["id"] ?>">Commenter</a></td>
                            </tr><?php endforeach; endif; ?></tbody>
                        </table></div>
                    </section>
                <?php endif; ?>
            </section>
        </main>
    </div>
<script src="../assets/js/admin-sidebar.js?v=20260925e"></script>
<script src="../assets/js/teacher-navigation.js?v=20260925b"></script>
</body>

</html>
