<?php
require_once __DIR__ . '/../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION["user_id"]) || ($_SESSION["user_role"] ?? "") !== "admin") {
    http_response_code(403);
    exit("Accès interdit.");
}
require_once "../config/database.php";

$studentId = filter_input(INPUT_GET, "student_id", FILTER_VALIDATE_INT);
$courseId = filter_input(INPUT_GET, "course_id", FILTER_VALIDATE_INT);
if (!$studentId || $studentId < 1) {
    http_response_code(400);
    exit("Étudiant invalide.");
}
$studentStatement = $pdo->prepare("SELECT id, first_name, last_name, email FROM users WHERE id = ? AND role = 'student' LIMIT 1");
$studentStatement->execute([$studentId]);
$student = $studentStatement->fetch(PDO::FETCH_ASSOC);
if (!$student) {
    http_response_code(404);
    exit("Étudiant introuvable.");
}
$courseFilter = $courseId ? " AND c.id = ?" : "";
$lessonParams = [$studentId, $studentId];
if ($courseId) $lessonParams[] = $courseId;
$lessonStatement = $pdo->prepare(
    "SELECT c.title AS course_title, m.title AS module_title, l.title AS lesson_title,
            COALESCE(lp.progress_percent, 0) AS progress_percent, COALESCE(lp.completed, 0) AS completed,
            COALESCE(lp.total_time_seconds, 0) AS total_time_seconds, COALESCE(lp.last_position, 0) AS last_position,
            lp.updated_at
     FROM lessons l
     INNER JOIN modules m ON m.id = l.module_id
     INNER JOIN courses c ON c.id = m.course_id
     INNER JOIN enrollments en ON en.course_id = c.id AND en.user_id = ?
     LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.user_id = ?
     WHERE l.is_published = 1{$courseFilter}
     ORDER BY COALESCE(lp.updated_at, '1000-01-01') DESC, c.title, m.position, l.position"
);
$lessonStatement->execute($lessonParams);
$lessons = $lessonStatement->fetchAll(PDO::FETCH_ASSOC);
$assessmentParams = [$studentId];
if ($courseId) $assessmentParams[] = $courseId;
$assessmentStatement = $pdo->prepare(
    "SELECT 'Quiz' AS type, q.title, c.title AS course_title, qa.percentage AS score, qa.passed, qa.completed_at
     FROM quiz_attempts qa INNER JOIN quizzes q ON q.id = qa.quiz_id INNER JOIN modules m ON m.id = q.module_id
     INNER JOIN courses c ON c.id = m.course_id INNER JOIN enrollments en ON en.course_id = c.id AND en.user_id = qa.user_id
     WHERE qa.user_id = ?{$courseFilter}
     UNION ALL
     SELECT 'Examen', ex.title, c.title, ea.percentage, ea.passed, ea.completed_at
     FROM exam_attempts ea INNER JOIN exams ex ON ex.id = ea.exam_id INNER JOIN courses c ON c.id = ex.course_id
     INNER JOIN enrollments en ON en.course_id = c.id AND en.user_id = ea.user_id
     WHERE ea.user_id = ?" . ($courseId ? " AND c.id = ?" : "") . "
     ORDER BY completed_at DESC"
);
$assessmentParams[] = $studentId;
if ($courseId) $assessmentParams[] = $courseId;
$assessmentStatement->execute($assessmentParams);
$assessments = $assessmentStatement->fetchAll(PDO::FETCH_ASSOC);
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
};
$date = static function ($value): string {
    return $value ? date("d/m/Y H:i", strtotime($value)) : "—";
};
if (($_GET["download"] ?? "") === "csv") {
    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"rapport-etudiant-" . $studentId . ".csv\"");
    $out = fopen("php://output", "w");
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ["Étudiant", "Formation", "Type", "Activité", "Score / progression", "Statut", "Temps (min)", "Position (s)", "Date"], ";");
    foreach ($lessons as $row) fputcsv($out, [$student["first_name"] . " " . $student["last_name"], $row["course_title"], "Leçon", $row["lesson_title"], $row["progress_percent"] . "%", $row["completed"] ? "Terminée" : "En cours", round($row["total_time_seconds"] / 60), $row["last_position"], $row["updated_at"]], ";");
    foreach ($assessments as $row) fputcsv($out, [$student["first_name"] . " " . $student["last_name"], $row["course_title"], $row["type"], $row["title"], $row["score"] . "%", $row["passed"] ? "Réussi" : "À revoir", "", "", $row["completed_at"]], ";");
    fclose($out);
    exit;
}
?>
<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><title>Rapport étudiant - <?= $escape($student["first_name"] . " " . $student["last_name"]) ?></title><link rel="stylesheet" href="../assets/css/style.css?v=20260925e"><style>@media print{.report-actions{display:none}}.admin-student-report{background:#fff;max-width:1100px;margin:2rem auto;padding:2rem}.admin-student-report table{border-collapse:collapse;width:100%}.admin-student-report th,.admin-student-report td{border-bottom:1px solid #ddd;padding:.55rem;text-align:left}</style></head>
<body class="admin-body"><main class="admin-student-report">
<div class="report-actions"><a href="results.php">← Retour</a> <button onclick="window.print()">Imprimer / Enregistrer en PDF</button> <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ["download" => "csv"])), ENT_QUOTES, "UTF-8") ?>">Exporter CSV / Excel</a></div>
<h1>Rapport de progression</h1><p><strong><?= $escape($student["first_name"] . " " . $student["last_name"]) ?></strong> · <?= $escape($student["email"]) ?></p>
<p>Généré le <?= date("d/m/Y H:i") ?> · Leçons terminées : <?= count(array_filter($lessons, static function ($row) { return (int) $row["completed"] === 1; })) ?> / <?= count($lessons) ?> · Temps actif : <?= round(array_sum(array_column($lessons, "total_time_seconds")) / 60) ?> min</p>
<h2>Leçons</h2><table><tr><th>Formation</th><th>Module</th><th>Leçon</th><th>Progression</th><th>Temps</th><th>Position</th><th>Activité</th></tr>
<?php foreach ($lessons as $row): ?><tr><td><?= $escape($row["course_title"]) ?></td><td><?= $escape($row["module_title"]) ?></td><td><?= $escape($row["lesson_title"]) ?></td><td><?= $escape($row["progress_percent"]) ?>%</td><td><?= round($row["total_time_seconds"] / 60) ?> min</td><td><?= (int) $row["last_position"] ?> s</td><td><?= $date($row["updated_at"]) ?></td></tr><?php endforeach; ?></table>
<h2>Quiz et examens</h2><table><tr><th>Type</th><th>Formation</th><th>Évaluation</th><th>Score</th><th>Statut</th><th>Date</th></tr>
<?php foreach ($assessments as $row): ?><tr><td><?= $escape($row["type"]) ?></td><td><?= $escape($row["course_title"]) ?></td><td><?= $escape($row["title"]) ?></td><td><?= $escape($row["score"]) ?>%</td><td><?= $row["passed"] ? "Réussi" : "À revoir" ?></td><td><?= $date($row["completed_at"]) ?></td></tr><?php endforeach; ?></table>
</main></body></html>




