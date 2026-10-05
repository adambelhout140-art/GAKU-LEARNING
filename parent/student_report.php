<?php
require_once __DIR__ . "/../config/i18n.php";
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}
if (($_SESSION["user_role"] ?? "") !== "parent") {
    http_response_code(403);
    exit("Accès interdit.");
}
header("Cache-Control: private, no-store, no-cache, must-revalidate");
header("Pragma: no-cache");

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/student_report_pdf.php";
require_once __DIR__ . "/../vendor/autoload.php";

use Dompdf\Dompdf;
use Dompdf\Options;

$requestedStudentId = trim((string) ($_GET["student_id"] ?? ""));
if ($requestedStudentId === "all" || ($_GET["selection"] ?? "") === "all") {
    header("Cache-Control: private, no-store, no-cache, must-revalidate");
    header("Pragma: no-cache");
    downloadParentStudentReportsPdf($pdo, (int) $_SESSION["user_id"]);
}
$studentId = filter_var($requestedStudentId, FILTER_VALIDATE_INT);
if (!$studentId || $studentId < 1) {
    http_response_code(400);
    exit("Étudiant invalide.");
}
header("Cache-Control: private, no-store, no-cache, must-revalidate");
header("Pragma: no-cache");

$studentStatement = $pdo->prepare(
    "SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.date_of_birth,
            u.cin, u.education, u.created_at
     FROM parent_student_links link
     INNER JOIN users u ON u.id = link.student_id
     WHERE link.parent_id = ? AND u.id = ? AND u.role = 'student'
     LIMIT 1"
);
$studentStatement->execute([(int) $_SESSION["user_id"], $studentId]);
$student = $studentStatement->fetch(PDO::FETCH_ASSOC);
if (!$student) {
    http_response_code(404);
    exit("Étudiant introuvable ou non lié à ce compte parent.");
}

$coursesStatement = $pdo->prepare(
    "SELECT e.course_id, e.status AS enrollment_status, e.enrolled_at,
            e.completed_at AS enrollment_completed_at, e.expires_at,
            c.title AS course_title, c.duration_hours,
            COUNT(DISTINCT CASE WHEN l.is_published = 1 THEN l.id END) AS lesson_count,
            COUNT(DISTINCT CASE WHEN l.is_published = 1 AND lp.completed = 1 THEN l.id END) AS completed_lessons,
            COALESCE(ROUND(AVG(CASE WHEN l.is_published = 1 THEN COALESCE(lp.progress_percent, 0) END), 0), 0) AS progress_percent,
            COALESCE(SUM(CASE WHEN l.is_published = 1 THEN lp.total_time_seconds ELSE 0 END), 0) AS learning_seconds
     FROM enrollments e
     INNER JOIN courses c ON c.id = e.course_id
     LEFT JOIN modules m ON m.course_id = c.id
     LEFT JOIN lessons l ON l.module_id = m.id
     LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.user_id = e.user_id
     WHERE e.user_id = ?
     GROUP BY e.course_id, e.status, e.enrolled_at, e.completed_at, e.expires_at,
              c.title, c.duration_hours
     ORDER BY e.enrolled_at DESC, c.title"
);
$coursesStatement->execute([$studentId]);
$courses = $coursesStatement->fetchAll(PDO::FETCH_ASSOC);

$lessonsStatement = $pdo->prepare(
    "SELECT c.title AS course_title, m.title AS module_title, l.title AS lesson_title,
            l.estimated_minutes, COALESCE(lp.progress_percent, 0) AS progress_percent,
            COALESCE(lp.completed, 0) AS completed,
            COALESCE(lp.total_time_seconds, 0) AS total_time_seconds,
            lp.started_at, lp.completed_at, lp.updated_at
     FROM enrollments e
     INNER JOIN courses c ON c.id = e.course_id
     INNER JOIN modules m ON m.course_id = c.id
     INNER JOIN lessons l ON l.module_id = m.id AND l.is_published = 1
     LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.user_id = e.user_id
     WHERE e.user_id = ?
     ORDER BY c.title, m.position, l.position, l.id"
);
$lessonsStatement->execute([$studentId]);
$lessons = $lessonsStatement->fetchAll(PDO::FETCH_ASSOC);

$attemptsStatement = $pdo->prepare(
    "SELECT qa.id AS attempt_id, qa.attempt_number, 'Quiz' AS assessment_type,
            q.title AS assessment_title, c.title AS course_title,
            qa.score, qa.total_points, qa.percentage, qa.passed,
            qa.started_at, qa.completed_at, NULL AS quiz_average_percentage,
            NULL AS combined_percentage, NULL AS overall_passed,
            (SELECT CONCAT(fb.purpose, ': ', fb.comment)
             FROM quiz_attempt_feedback fb
             WHERE fb.quiz_attempt_id = qa.id AND fb.visibility = 'student_parent'
             ORDER BY fb.updated_at DESC LIMIT 1) AS teacher_feedback
     FROM quiz_attempts qa
     INNER JOIN quizzes q ON q.id = qa.quiz_id
     INNER JOIN modules m ON m.id = q.module_id
     INNER JOIN courses c ON c.id = m.course_id
     WHERE qa.user_id = ? AND qa.completed_at IS NOT NULL
     UNION ALL
     SELECT ea.id AS attempt_id, ea.attempt_number, 'Examen' AS assessment_type,
            ex.title AS assessment_title, c.title AS course_title,
            ea.score, ea.total_points, ea.percentage, ea.passed,
            ea.started_at, ea.completed_at, ea.quiz_average_percentage,
            ea.combined_percentage, ea.overall_passed,
            (SELECT CONCAT(fb.purpose, ': ', fb.comment)
             FROM exam_attempt_feedback fb
             WHERE fb.exam_attempt_id = ea.id AND fb.visibility = 'student_parent'
             ORDER BY fb.updated_at DESC LIMIT 1) AS teacher_feedback
     FROM exam_attempts ea
     INNER JOIN exams ex ON ex.id = ea.exam_id
     INNER JOIN courses c ON c.id = ex.course_id
     WHERE ea.user_id = ? AND ea.completed_at IS NOT NULL
     ORDER BY completed_at DESC, attempt_id DESC"
);
$attemptsStatement->execute([$studentId, $studentId]);
$attempts = $attemptsStatement->fetchAll(PDO::FETCH_ASSOC);

$quizAnswerDetailsStatement = $pdo->prepare(
    "SELECT qa.id AS attempt_id, qa.attempt_number, qa.quiz_id AS assessment_id,
            quiz.title AS assessment_title,
            q.position, q.question_text, q.question_type, q.points AS question_points,
            (SELECT GROUP_CONCAT(DISTINCT chosen.answer_text ORDER BY chosen.id SEPARATOR ' | ')
             FROM attempt_answers aa
             INNER JOIN question_answers chosen ON chosen.id = aa.answer_id
             WHERE aa.quiz_attempt_id = qa.id AND aa.question_id = q.id) AS selected_options,
            (SELECT aa.answer_text FROM attempt_answers aa
             WHERE aa.quiz_attempt_id = qa.id AND aa.question_id = q.id
               AND aa.answer_text IS NOT NULL LIMIT 1) AS selected_text,
            (SELECT MAX(aa.points_earned) FROM attempt_answers aa
             WHERE aa.quiz_attempt_id = qa.id AND aa.question_id = q.id) AS points_earned,
            (SELECT MIN(aa.is_correct) FROM attempt_answers aa
             WHERE aa.quiz_attempt_id = qa.id AND aa.question_id = q.id) AS is_correct,
            (SELECT GROUP_CONCAT(correct.answer_text ORDER BY correct.id SEPARATOR ' | ')
             FROM question_answers correct
             WHERE correct.question_id = q.id AND correct.is_correct = 1) AS correct_answers,
            (SELECT CONCAT(feedback.purpose, ': ', feedback.comment)
             FROM assessment_question_feedback feedback
             WHERE feedback.assessment_type = 'quiz' AND feedback.attempt_id = qa.id
               AND feedback.question_id = q.id AND feedback.visibility = 'student_parent'
             ORDER BY feedback.updated_at DESC LIMIT 1) AS teacher_feedback,
            (SELECT COUNT(*) FROM quiz_attempts all_attempts
             WHERE all_attempts.quiz_id = qa.quiz_id AND all_attempts.user_id = qa.user_id) AS total_attempts,
            quiz.max_attempts
     FROM quiz_attempts qa
     INNER JOIN quizzes quiz ON quiz.id = qa.quiz_id
     INNER JOIN questions q ON q.quiz_id = qa.quiz_id
     WHERE qa.user_id = ? AND qa.completed_at IS NOT NULL
     ORDER BY qa.id DESC, q.position, q.id"
);
$quizAnswerDetailsStatement->execute([$studentId]);
$quizAnswerDetails = $quizAnswerDetailsStatement->fetchAll(PDO::FETCH_ASSOC);

$examAnswerDetailsStatement = $pdo->prepare(
    "SELECT ea.id AS attempt_id, ea.attempt_number, ea.exam_id AS assessment_id,
            exam.title AS assessment_title,
            q.position, q.question_text, q.question_type, q.points AS question_points,
            (SELECT GROUP_CONCAT(DISTINCT chosen.answer_text ORDER BY chosen.id SEPARATOR ' | ')
             FROM attempt_answers aa
             INNER JOIN question_answers chosen ON chosen.id = aa.answer_id
             WHERE aa.exam_attempt_id = ea.id AND aa.question_id = q.id) AS selected_options,
            (SELECT aa.answer_text FROM attempt_answers aa
             WHERE aa.exam_attempt_id = ea.id AND aa.question_id = q.id
               AND aa.answer_text IS NOT NULL LIMIT 1) AS selected_text,
            (SELECT MAX(aa.points_earned) FROM attempt_answers aa
             WHERE aa.exam_attempt_id = ea.id AND aa.question_id = q.id) AS points_earned,
            (SELECT MIN(aa.is_correct) FROM attempt_answers aa
             WHERE aa.exam_attempt_id = ea.id AND aa.question_id = q.id) AS is_correct,
            (SELECT GROUP_CONCAT(correct.answer_text ORDER BY correct.id SEPARATOR ' | ')
             FROM question_answers correct
             WHERE correct.question_id = q.id AND correct.is_correct = 1) AS correct_answers,
            (SELECT CONCAT(feedback.purpose, ': ', feedback.comment)
             FROM assessment_question_feedback feedback
             WHERE feedback.assessment_type = 'exam' AND feedback.attempt_id = ea.id
               AND feedback.question_id = q.id AND feedback.visibility = 'student_parent'
             ORDER BY feedback.updated_at DESC LIMIT 1) AS teacher_feedback,
            (SELECT COUNT(*) FROM exam_attempts all_attempts
             WHERE all_attempts.exam_id = ea.exam_id AND all_attempts.user_id = ea.user_id) AS total_attempts,
            exam.max_attempts
     FROM exam_attempts ea
     INNER JOIN exams exam ON exam.id = ea.exam_id
     INNER JOIN questions q ON q.exam_id = ea.exam_id
     WHERE ea.user_id = ? AND ea.completed_at IS NOT NULL
     ORDER BY ea.id DESC, q.position, q.id"
);
$examAnswerDetailsStatement->execute([$studentId]);
$examAnswerDetails = $examAnswerDetailsStatement->fetchAll(PDO::FETCH_ASSOC);

$certificatesStatement = $pdo->prepare(
    "SELECT cert.certificate_number, cert.issue_date, c.title AS course_title,
            ea.percentage AS exam_percentage, ea.quiz_average_percentage,
            ea.combined_percentage
     FROM certificates cert
     INNER JOIN courses c ON c.id = cert.course_id
     LEFT JOIN exam_attempts ea ON ea.id = cert.exam_attempt_id
     WHERE cert.user_id = ?
     ORDER BY cert.issue_date DESC, cert.id DESC"
);
$certificatesStatement->execute([$studentId]);
$certificates = $certificatesStatement->fetchAll(PDO::FETCH_ASSOC);

$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
};
$date = static function ($value, bool $withTime = false): string {
    if (!$value) {
        return "—";
    }
    $timestamp = strtotime((string) $value);
    return $timestamp === false ? "—" : date($withTime ? "d/m/Y H:i" : "d/m/Y", $timestamp);
};
$formatDuration = static function ($seconds): string {
    $minutes = (int) round((int) $seconds / 60);
    $hours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;
    return $hours > 0 ? $hours . " h " . $remainingMinutes . " min" : $minutes . " min";
};
$statusLabels = [
    "active" => "En cours",
    "completed" => "Terminée",
    "suspended" => "Suspendue",
    "cancelled" => "Annulée",
];
$feedbackLabels = [
    "encouragement" => "Encouragement",
    "correction" => "Correction",
    "explanation" => "Explication",
];
$fullName = trim($student["first_name"] . " " . $student["last_name"]);
$totalLearningSeconds = 0;
foreach ($courses as $course) {
    $totalLearningSeconds += (int) $course["learning_seconds"];
}

$html = '<!doctype html><html lang="fr"><head><meta charset="UTF-8"><style>
    @page { margin: 30px 32px; }
    body { font-family: DejaVu Sans, sans-serif; color: #243746; font-size: 9px; line-height: 1.45; }
    h1 { color: #143b5d; font-size: 22px; margin: 0 0 4px; }
    h2 { color: #1769aa; font-size: 14px; margin: 22px 0 8px; padding-bottom: 5px; border-bottom: 1px solid #dbe5ec; }
    p { margin: 3px 0; }
    .meta { color: #657987; margin-bottom: 16px; }
    .summary { width: 100%; margin: 12px 0 18px; border-collapse: separate; border-spacing: 6px 0; }
    .summary td { width: 25%; padding: 10px; border: 1px solid #dbe5ec; background: #f5f9fc; }
    .summary strong { display: block; color: #1769aa; font-size: 15px; }
    .summary span { color: #657987; font-size: 8px; }
    .identity { padding: 12px; border: 1px solid #dbe5ec; background: #f8fafc; }
    table.data { width: 100%; margin: 5px 0 12px; border-collapse: collapse; }
    .data th, .data td { padding: 6px; border: 1px solid #d8e0e6; text-align: left; vertical-align: top; }
    .data th { background: #edf4f8; color: #29485e; font-weight: bold; }
    .small { color: #657987; font-size: 8px; }
    .good { color: #137547; font-weight: bold; }
    .bad { color: #ad3b36; font-weight: bold; }
    .muted { color: #657987; }
    .response-card { margin: 12px 0; padding: 10px; border: 1px solid #d8e0e6; page-break-inside: avoid; }
    .response-card h3 { margin: 0 0 8px; color: #29485e; font-size: 10px; }
    .answer-correct { color: #137547; font-weight: bold; }
    .answer-incorrect { color: #ad3b36; font-weight: bold; }
    .page-break { page-break-before: always; }
</style></head><body>';
$html .= "<h1>Rapport de suivi de formation</h1>";
$html .= '<p class="meta">GAKU LEARNING · Généré le ' . $escape(date("d/m/Y H:i")) . "</p>";
$html .= '<div class="identity"><strong>' . $escape($fullName) . "</strong><br>";
$html .= "Email : " . $escape($student["email"]) . " · Téléphone : " . $escape($student["phone"] ?: "—") . "<br>";
$html .= "CIN : " . $escape($student["cin"] ?: "—") . " · Date de naissance : " . $escape($date($student["date_of_birth"])) . "<br>";
$html .= "Éducation : " . $escape($student["education"] ?: "—") . " · Compte créé le : " . $escape($date($student["created_at"])) . "</div>";
$html .= '<table class="summary"><tr>';
$html .= '<td><strong>' . count($courses) . '</strong><span>Formation(s) inscrite(s)</span></td>';
$html .= '<td><strong>' . count($lessons) . '</strong><span>Leçon(s) au programme</span></td>';
$html .= '<td><strong>' . count($attempts) . '</strong><span>Tentative(s) terminée(s)</span></td>';
$html .= '<td><strong>' . $escape($formatDuration($totalLearningSeconds)) . '</strong><span>Temps d’apprentissage suivi</span></td>';
$html .= "</tr></table>";

$html .= "<h2>Formations et progression</h2>";
if (!$courses) {
    $html .= '<p class="muted">Aucune inscription à une formation.</p>';
} else {
    $html .= '<table class="data"><thead><tr><th>Formation</th><th>Statut</th><th>Inscrit le</th><th>Progression</th><th>Leçons terminées</th><th>Temps suivi</th></tr></thead><tbody>';
    foreach ($courses as $course) {
        $html .= "<tr><td>" . $escape($course["course_title"]) . "</td>";
        $html .= "<td>" . $escape($statusLabels[$course["enrollment_status"]] ?? $course["enrollment_status"]) . "</td>";
        $html .= "<td>" . $escape($date($course["enrolled_at"])) . "</td>";
        $html .= "<td>" . $escape((int) $course["progress_percent"]) . "%</td>";
        $html .= "<td>" . $escape((int) $course["completed_lessons"]) . " / " . $escape((int) $course["lesson_count"]) . "</td>";
        $html .= "<td>" . $escape($formatDuration($course["learning_seconds"])) . "</td></tr>";
    }
    $html .= "</tbody></table>";
}

$html .= "<h2>Détail des leçons</h2>";
if (!$lessons) {
    $html .= '<p class="muted">Aucune leçon enregistrée dans les formations.</p>';
} else {
    $html .= '<table class="data"><thead><tr><th>Formation / module / leçon</th><th>Progression</th><th>État</th><th>Temps suivi</th><th>Commencée le</th><th>Terminée le</th></tr></thead><tbody>';
    foreach ($lessons as $lesson) {
        $completed = (int) $lesson["completed"] === 1;
        $html .= "<tr><td><strong>" . $escape($lesson["course_title"]) . "</strong><br>";
        $html .= '<span class="small">' . $escape($lesson["module_title"]) . " · " . $escape($lesson["lesson_title"]) . "</span></td>";
        $html .= "<td>" . $escape(number_format((float) $lesson["progress_percent"], 0)) . "%</td>";
        $html .= '<td class="' . ($completed ? "good" : "muted") . '">' . ($completed ? "Terminée" : "En cours / non commencée") . "</td>";
        $html .= "<td>" . $escape($formatDuration($lesson["total_time_seconds"])) . "</td>";
        $html .= "<td>" . $escape($date($lesson["started_at"])) . "</td>";
        $html .= "<td>" . $escape($date($lesson["completed_at"])) . "</td></tr>";
    }
    $html .= "</tbody></table>";
}

$html .= '<h2 class="page-break">Quiz et examens — toutes les tentatives</h2>';
if (!$attempts) {
    $html .= '<p class="muted">Aucun quiz ou examen terminé.</p>';
} else {
    $html .= '<table class="data"><thead><tr><th>Formation / évaluation</th><th>Type</th><th>Tentative</th><th>Points</th><th>Résultat</th><th>Statut</th><th>Terminé le</th><th>Commentaire du formateur</th></tr></thead><tbody>';
    foreach ($attempts as $attempt) {
        $passed = $attempt["overall_passed"] !== null
            ? (int) $attempt["overall_passed"] === 1
            : (int) $attempt["passed"] === 1;
        $html .= "<tr><td><strong>" . $escape($attempt["course_title"]) . "</strong><br>" . $escape($attempt["assessment_title"]) . "</td>";
        $html .= "<td>" . $escape($attempt["assessment_type"]) . "</td>";
        $html .= "<td>" . $escape((int) $attempt["attempt_number"]) . "</td>";
        $html .= "<td>" . $escape(number_format((float) $attempt["score"], 2, ",", " ")) . " / " . $escape(number_format((float) $attempt["total_points"], 2, ",", " ")) . "</td>";
        $html .= "<td>" . $escape(number_format((float) $attempt["percentage"], 2, ",", " ")) . "%";
        if ($attempt["assessment_type"] === "Examen" && $attempt["combined_percentage"] !== null) {
            $html .= '<br><span class="small">Final quiz/examen : ' . $escape(number_format((float) $attempt["combined_percentage"], 2, ",", " ")) . "%</span>";
        }
        $status = $attempt["assessment_type"] === "Examen"
            ? ($passed ? "Formation validée" : ((int) $attempt["passed"] === 1 ? "Examen réussi, seuil final non atteint" : "Examen à revoir"))
            : ($passed ? "Réussi" : "À revoir");
        $html .= "</td><td class=\"" . ($passed ? "good" : "bad") . '">' . $escape($status) . "</td>";
        $html .= "<td>" . $escape($date($attempt["completed_at"], true)) . "</td>";
        $feedback = trim((string) ($attempt["teacher_feedback"] ?? ""));
        if ($feedback !== "") {
            [$purpose, $comment] = array_pad(explode(": ", $feedback, 2), 2, "");
            $feedback = ($feedbackLabels[$purpose] ?? "Commentaire") . " : " . $comment;
        }
        $html .= "<td>" . $escape($feedback !== "" ? $feedback : "—") . "</td></tr>";
    }
    $html .= "</tbody></table>";
}

$html .= '<h2 class="page-break">Réponses détaillées aux quiz</h2>';
if (!$quizAnswerDetails) {
    $html .= '<p class="muted">Aucune réponse de quiz enregistrée.</p>';
} else {
    foreach ($quizAnswerDetails as $detail) {
        $html .= '<div class="response-card"><h3>Quiz · ' . $escape($detail["assessment_title"])
            . ' · tentative ' . $escape((int) $detail["attempt_number"]) . '</h3>';
        $hasAnswer = trim((string) ($detail["selected_options"] ?? "")) !== ""
            || trim((string) ($detail["selected_text"] ?? "")) !== "";
        $isCorrect = $detail["is_correct"] !== null && (int) $detail["is_correct"] === 1;
        $revealCorrect = (int) $detail["total_attempts"] >= (int) $detail["max_attempts"];
        $html .= "<p><strong>" . $escape($detail["position"]) . ". " . $escape($detail["question_text"]) . "</strong> ";
        $html .= '<span class="small">(' . $escape($detail["question_type"]) . " · " . $escape($detail["question_points"]) . " point(s))</span></p>";
        $html .= "<p>Réponse de l'étudiant : " . $escape($hasAnswer ? (($detail["selected_options"] ?? "") ?: ($detail["selected_text"] ?? "")) : "Aucune réponse enregistrée") . "</p>";
        $html .= '<p class="' . ($isCorrect ? "answer-correct" : "answer-incorrect") . '">';
        $html .= $detail["is_correct"] === null ? "Réponse non enregistrée" : ($isCorrect ? "Correcte" : "Incorrecte");
        $html .= " · Points : " . $escape(number_format((float) ($detail["points_earned"] ?? 0), 2, ",", " "))
            . " / " . $escape(number_format((float) $detail["question_points"], 2, ",", " ")) . "</p>";
        if ($revealCorrect && trim((string) ($detail["correct_answers"] ?? "")) !== "") {
            $html .= "<p>Réponse(s) attendue(s) : " . $escape($detail["correct_answers"]) . "</p>";
        }
        $feedback = trim((string) ($detail["teacher_feedback"] ?? ""));
        if ($feedback !== "") {
            [$purpose, $comment] = array_pad(explode(": ", $feedback, 2), 2, "");
            $html .= '<p class="small"><strong>Commentaire du formateur (' . $escape($feedbackLabels[$purpose] ?? "Commentaire") . ') :</strong> '
                . $escape($comment) . "</p>";
        }
        $html .= "</div>";
    }
}

$html .= '<h2 class="page-break">Réponses détaillées aux examens</h2>';
if (!$examAnswerDetails) {
    $html .= '<p class="muted">Aucune réponse d’examen enregistrée.</p>';
} else {
    foreach ($examAnswerDetails as $detail) {
        $html .= '<div class="response-card"><h3>Examen · ' . $escape($detail["assessment_title"])
            . ' · tentative ' . $escape((int) $detail["attempt_number"]) . '</h3>';
        $hasAnswer = trim((string) ($detail["selected_options"] ?? "")) !== ""
            || trim((string) ($detail["selected_text"] ?? "")) !== "";
        $isCorrect = $detail["is_correct"] !== null && (int) $detail["is_correct"] === 1;
        $revealCorrect = (int) $detail["total_attempts"] >= (int) $detail["max_attempts"];
        $html .= "<p><strong>" . $escape($detail["position"]) . ". " . $escape($detail["question_text"]) . "</strong> ";
        $html .= '<span class="small">(' . $escape($detail["question_type"]) . " · " . $escape($detail["question_points"]) . " point(s))</span></p>";
        $html .= "<p>Réponse de l'étudiant : " . $escape($hasAnswer ? (($detail["selected_options"] ?? "") ?: ($detail["selected_text"] ?? "")) : "Aucune réponse enregistrée") . "</p>";
        $html .= '<p class="' . ($isCorrect ? "answer-correct" : "answer-incorrect") . '">';
        $html .= $detail["is_correct"] === null ? "Réponse non enregistrée" : ($isCorrect ? "Correcte" : "Incorrecte");
        $html .= " · Points : " . $escape(number_format((float) ($detail["points_earned"] ?? 0), 2, ",", " "))
            . " / " . $escape(number_format((float) $detail["question_points"], 2, ",", " ")) . "</p>";
        if ($revealCorrect && trim((string) ($detail["correct_answers"] ?? "")) !== "") {
            $html .= "<p>Réponse(s) attendue(s) : " . $escape($detail["correct_answers"]) . "</p>";
        }
        $feedback = trim((string) ($detail["teacher_feedback"] ?? ""));
        if ($feedback !== "") {
            [$purpose, $comment] = array_pad(explode(": ", $feedback, 2), 2, "");
            $html .= '<p class="small"><strong>Commentaire du formateur (' . $escape($feedbackLabels[$purpose] ?? "Commentaire") . ') :</strong> '
                . $escape($comment) . "</p>";
        }
        $html .= "</div>";
    }
}

$html .= "<h2>Certificats obtenus</h2>";
if (!$certificates) {
    $html .= '<p class="muted">Aucun certificat obtenu.</p>';
} else {
    $html .= '<table class="data"><thead><tr><th>Formation</th><th>Numéro du certificat</th><th>Quiz</th><th>Examen</th><th>Résultat final</th><th>Délivré le</th></tr></thead><tbody>';
    foreach ($certificates as $certificate) {
        $html .= "<tr><td>" . $escape($certificate["course_title"]) . "</td>";
        $html .= "<td>" . $escape($certificate["certificate_number"]) . "</td>";
        $html .= "<td>" . ($certificate["quiz_average_percentage"] === null ? "—" : $escape(number_format((float) $certificate["quiz_average_percentage"], 2, ",", " ")) . "%") . "</td>";
        $html .= "<td>" . ($certificate["exam_percentage"] === null ? "—" : $escape(number_format((float) $certificate["exam_percentage"], 2, ",", " ")) . "%") . "</td>";
        $html .= "<td>" . ($certificate["combined_percentage"] === null ? "—" : $escape(number_format((float) $certificate["combined_percentage"], 2, ",", " ")) . "%") . "</td>";
        $html .= "<td>" . $escape($date($certificate["issue_date"])) . "</td></tr>";
    }
    $html .= "</tbody></table>";
}
$html .= "</body></html>";

$options = new Options();
$options->set("defaultFont", "DejaVu Sans");
$options->set("isRemoteEnabled", false);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, "UTF-8");
$dompdf->setPaper("A4", "landscape");
$dompdf->render();
$dompdf->stream("rapport-formation-etudiant-" . (int) $student["id"] . ".pdf", ["Attachment" => true]);
exit;
