<?php
require_once __DIR__ . "/../vendor/autoload.php";

use Dompdf\Dompdf;
use Dompdf\Options;

function downloadParentStudentReportsPdf(PDO $pdo, int $parentId, ?int $onlyStudentId = null): void
{
    $studentsSql =
        "SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.date_of_birth,
                u.cin, u.education, u.created_at
         FROM parent_student_links link
         INNER JOIN users u ON u.id = link.student_id AND u.role = 'student'
         WHERE link.parent_id = ?" . ($onlyStudentId === null ? "" : " AND u.id = ?")
        . " ORDER BY u.last_name, u.first_name";
    $studentsStatement = $pdo->prepare($studentsSql);
    $studentsStatement->execute($onlyStudentId === null ? [$parentId] : [$parentId, $onlyStudentId]);
    $students = $studentsStatement->fetchAll(PDO::FETCH_ASSOC);
    if (!$students) {
        http_response_code(404);
        exit("Aucun étudiant lié à ce compte parent.");
    }

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
    $duration = static function ($seconds): string {
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

    $html = '<!doctype html><html lang="fr"><head><meta charset="UTF-8"><style>
        @page { margin: 30px 32px; }
        body { font-family: DejaVu Sans, sans-serif; color: #243746; font-size: 9px; line-height: 1.45; }
        h1 { color: #143b5d; font-size: 22px; margin: 0 0 4px; }
        h2 { color: #1769aa; font-size: 14px; margin: 20px 0 8px; padding-bottom: 5px; border-bottom: 1px solid #dbe5ec; }
        h3 { color: #29485e; font-size: 10px; margin: 0 0 7px; }
        p { margin: 3px 0; }
        .meta { color: #657987; margin-bottom: 14px; }
        .identity { padding: 11px; border: 1px solid #dbe5ec; background: #f8fafc; }
        table { width: 100%; margin: 5px 0 12px; border-collapse: collapse; }
        th, td { padding: 5px; border: 1px solid #d8e0e6; text-align: left; vertical-align: top; }
        th { background: #edf4f8; color: #29485e; }
        .summary td { width: 25%; background: #f5f9fc; }
        .summary strong { display: block; color: #1769aa; font-size: 14px; }
        .small, .muted { color: #657987; font-size: 8px; }
        .good { color: #137547; font-weight: bold; }
        .bad { color: #ad3b36; font-weight: bold; }
        .response { margin: 7px 0; padding: 7px; border: 1px solid #d8e0e6; page-break-inside: avoid; }
        .page-break { page-break-before: always; }
        .student-break { page-break-before: always; }
        .platform-contact-pdf { margin-top: 16px; padding-top: 8px; border-top: 1px solid #d8e0e6; color: #657987; font-size: 8px; }
    </style></head><body>';
    $html .= "<h1>Rapport de suivi de formation</h1>";
    $html .= '<p class="meta">' . $escape(platformBranding($pdo)["platform_name"]) . ' · Généré le ' . $escape(date("d/m/Y H:i"))
        . " · " . count($students) . " étudiant(s)</p>";

    foreach ($students as $studentIndex => $student) {
        $studentId = (int) $student["id"];
        if ($studentIndex > 0) {
            $html .= '<div class="student-break"></div>';
        }
        $fullName = trim($student["first_name"] . " " . $student["last_name"]);
        $html .= '<div class="identity"><strong>' . $escape($fullName) . "</strong><br>";
        $html .= "Email : " . $escape($student["email"]) . " · Téléphone : " . $escape($student["phone"] ?: "—") . "<br>";
        $html .= "CIN : " . $escape($student["cin"] ?: "—") . " · Date de naissance : " . $escape($date($student["date_of_birth"])) . "<br>";
        $html .= "Éducation : " . $escape($student["education"] ?: "—") . " · Compte créé le : " . $escape($date($student["created_at"])) . "</div>";

        $coursesStatement = $pdo->prepare(
            "SELECT e.course_id, e.status AS enrollment_status, e.enrolled_at,
                    c.title AS course_title, COUNT(DISTINCT l.id) AS lesson_count,
                    COUNT(DISTINCT CASE WHEN lp.completed = 1 THEN l.id END) AS completed_lessons,
                    COALESCE(ROUND(AVG(COALESCE(lp.progress_percent, 0)), 0), 0) AS progress_percent,
                    COALESCE(SUM(lp.total_time_seconds), 0) AS learning_seconds
             FROM enrollments e
             INNER JOIN courses c ON c.id = e.course_id
             LEFT JOIN modules m ON m.course_id = c.id
             LEFT JOIN lessons l ON l.module_id = m.id AND l.is_published = 1
             LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.user_id = e.user_id
             WHERE e.user_id = ?
             GROUP BY e.course_id, e.status, e.enrolled_at, c.title
             ORDER BY e.enrolled_at DESC, c.title"
        );
        $coursesStatement->execute([$studentId]);
        $courses = $coursesStatement->fetchAll(PDO::FETCH_ASSOC);

        $lessonsStatement = $pdo->prepare(
            "SELECT c.title AS course_title, m.title AS module_title, l.title AS lesson_title,
                    COALESCE(lp.progress_percent, 0) AS progress_percent,
                    COALESCE(lp.completed, 0) AS completed,
                    COALESCE(lp.total_time_seconds, 0) AS total_time_seconds,
                    lp.started_at, lp.completed_at
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
                    qa.completed_at, NULL AS quiz_average_percentage,
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
                    ea.completed_at, ea.quiz_average_percentage,
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

        $quizDetailsStatement = $pdo->prepare(
            "SELECT qa.id AS attempt_id, qa.attempt_number, quiz.title AS assessment_title,
                    q.position, q.question_text, q.question_type, q.points AS question_points,
                    GROUP_CONCAT(DISTINCT chosen.answer_text ORDER BY chosen.id SEPARATOR ' | ') AS selected_options,
                    MAX(aa.answer_text) AS selected_text, MAX(aa.points_earned) AS points_earned,
                    MIN(aa.is_correct) AS is_correct,
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
             LEFT JOIN attempt_answers aa ON aa.quiz_attempt_id = qa.id AND aa.question_id = q.id
             LEFT JOIN question_answers chosen ON chosen.id = aa.answer_id
             WHERE qa.user_id = ? AND qa.completed_at IS NOT NULL
             GROUP BY qa.id, qa.attempt_number, quiz.title, q.id, q.position, q.question_text,
                      q.question_type, q.points, quiz.max_attempts
             ORDER BY qa.completed_at DESC, qa.id DESC, q.position, q.id"
        );
        $quizDetailsStatement->execute([$studentId]);
        $quizDetails = $quizDetailsStatement->fetchAll(PDO::FETCH_ASSOC);

        $examDetailsStatement = $pdo->prepare(
            "SELECT ea.id AS attempt_id, ea.attempt_number, exam.title AS assessment_title,
                    q.position, q.question_text, q.question_type, q.points AS question_points,
                    GROUP_CONCAT(DISTINCT chosen.answer_text ORDER BY chosen.id SEPARATOR ' | ') AS selected_options,
                    MAX(aa.answer_text) AS selected_text, MAX(aa.points_earned) AS points_earned,
                    MIN(aa.is_correct) AS is_correct,
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
             LEFT JOIN attempt_answers aa ON aa.exam_attempt_id = ea.id AND aa.question_id = q.id
             LEFT JOIN question_answers chosen ON chosen.id = aa.answer_id
             WHERE ea.user_id = ? AND ea.completed_at IS NOT NULL
             GROUP BY ea.id, ea.attempt_number, exam.title, q.id, q.position, q.question_text,
                      q.question_type, q.points, exam.max_attempts
             ORDER BY ea.completed_at DESC, ea.id DESC, q.position, q.id"
        );
        $examDetailsStatement->execute([$studentId]);
        $examDetails = $examDetailsStatement->fetchAll(PDO::FETCH_ASSOC);

        $totalLearningSeconds = array_sum(array_map(static function ($course): int {
            return (int) $course["learning_seconds"];
        }, $courses));
        $html .= '<table class="summary"><tr><td><strong>' . count($courses) . '</strong>Formation(s)</td>';
        $html .= '<td><strong>' . count($lessons) . '</strong>Leçon(s)</td>';
        $html .= '<td><strong>' . count($attempts) . '</strong>Tentative(s)</td>';
        $html .= '<td><strong>' . $escape($duration($totalLearningSeconds)) . "</strong>Temps suivi</td></tr></table>";

        $html .= "<h2>Formations et progression</h2>";
        if (!$courses) {
            $html .= '<p class="muted">Aucune inscription à une formation.</p>';
        } else {
            $html .= "<table><thead><tr><th>Formation</th><th>Statut</th><th>Inscrit le</th><th>Progression</th><th>Leçons</th><th>Temps suivi</th></tr></thead><tbody>";
            foreach ($courses as $course) {
                $html .= "<tr><td>" . $escape($course["course_title"]) . "</td><td>"
                    . $escape($statusLabels[$course["enrollment_status"]] ?? $course["enrollment_status"])
                    . "</td><td>" . $escape($date($course["enrolled_at"])) . "</td><td>"
                    . $escape((int) $course["progress_percent"]) . "%</td><td>"
                    . $escape((int) $course["completed_lessons"]) . " / "
                    . $escape((int) $course["lesson_count"]) . "</td><td>"
                    . $escape($duration($course["learning_seconds"])) . "</td></tr>";
            }
            $html .= "</tbody></table>";
        }

        $html .= "<h2>Détail des leçons</h2>";
        if (!$lessons) {
            $html .= '<p class="muted">Aucune leçon dans les formations.</p>';
        } else {
            $html .= "<table><thead><tr><th>Formation / module / leçon</th><th>Progression</th><th>État</th><th>Temps</th><th>Début</th><th>Fin</th></tr></thead><tbody>";
            foreach ($lessons as $lesson) {
                $completed = (int) $lesson["completed"] === 1;
                $html .= "<tr><td><strong>" . $escape($lesson["course_title"]) . "</strong><br>"
                    . '<span class="small">' . $escape($lesson["module_title"]) . " · "
                    . $escape($lesson["lesson_title"]) . "</span></td><td>"
                    . $escape(number_format((float) $lesson["progress_percent"], 0)) . "%</td><td class=\""
                    . ($completed ? "good" : "muted") . "\">" . ($completed ? "Terminée" : "En cours / non commencée")
                    . "</td><td>" . $escape($duration($lesson["total_time_seconds"])) . "</td><td>"
                    . $escape($date($lesson["started_at"])) . "</td><td>"
                    . $escape($date($lesson["completed_at"])) . "</td></tr>";
            }
            $html .= "</tbody></table>";
        }

        $html .= "<h2>Quiz et examens — toutes les tentatives</h2>";
        if (!$attempts) {
            $html .= '<p class="muted">Aucun quiz ou examen terminé.</p>';
        } else {
            $html .= "<table><thead><tr><th>Formation / évaluation</th><th>Type</th><th>Tentative</th><th>Points</th><th>Score</th><th>Statut</th><th>Terminé le</th><th>Formateur</th></tr></thead><tbody>";
            foreach ($attempts as $attempt) {
                $passed = $attempt["overall_passed"] !== null
                    ? (int) $attempt["overall_passed"] === 1
                    : (int) $attempt["passed"] === 1;
                $html .= "<tr><td><strong>" . $escape($attempt["course_title"]) . "</strong><br>"
                    . $escape($attempt["assessment_title"]) . "</td><td>"
                    . $escape($attempt["assessment_type"]) . "</td><td>"
                    . $escape((int) $attempt["attempt_number"]) . "</td><td>"
                    . $escape(number_format((float) $attempt["score"], 2, ",", " ")) . " / "
                    . $escape(number_format((float) $attempt["total_points"], 2, ",", " ")) . "</td><td>"
                    . $escape(number_format((float) $attempt["percentage"], 2, ",", " ")) . "%";
                if ($attempt["assessment_type"] === "Examen" && $attempt["combined_percentage"] !== null) {
                    $html .= "<br>Final : " . $escape(number_format((float) $attempt["combined_percentage"], 2, ",", " ")) . "%";
                }
                $html .= "</td><td class=\"" . ($passed ? "good" : "bad") . "\">"
                    . ($passed ? "Réussi" : "À revoir") . "</td><td>"
                    . $escape($date($attempt["completed_at"], true)) . "</td><td>"
                    . $escape($attempt["teacher_feedback"] ?: "—") . "</td></tr>";
            }
            $html .= "</tbody></table>";
        }

        foreach (["Quiz" => $quizDetails, "Examen" => $examDetails] as $type => $details) {
            $html .= "<h2>Réponses détaillées — " . $escape($type) . "s</h2>";
            if (!$details) {
                $html .= '<p class="muted">Aucune réponse enregistrée.</p>';
                continue;
            }
            foreach ($details as $detail) {
                $hasAnswer = trim((string) ($detail["selected_options"] ?? "")) !== ""
                    || trim((string) ($detail["selected_text"] ?? "")) !== "";
                $isCorrect = $detail["is_correct"] !== null && (int) $detail["is_correct"] === 1;
                $revealCorrect = (int) $detail["total_attempts"] >= (int) $detail["max_attempts"];
                $html .= '<div class="response"><h3>' . $escape($detail["assessment_title"])
                    . " · tentative " . $escape((int) $detail["attempt_number"]) . " · question "
                    . $escape($detail["position"]) . "</h3>";
                $html .= "<p><strong>" . $escape($detail["question_text"]) . "</strong> <span class=\"small\">("
                    . $escape($detail["question_type"]) . " · " . $escape($detail["question_points"]) . " point(s))</span></p>";
                $html .= "<p>Réponse de l'étudiant : " . $escape($hasAnswer
                    ? (($detail["selected_options"] ?? "") ?: ($detail["selected_text"] ?? ""))
                    : "Aucune réponse enregistrée") . "</p>";
                $html .= '<p class="' . ($isCorrect ? "good" : "bad") . '">'
                    . ($detail["is_correct"] === null ? "Réponse non enregistrée" : ($isCorrect ? "Correcte" : "Incorrecte"))
                    . " · Points : " . $escape(number_format((float) ($detail["points_earned"] ?? 0), 2, ",", " "))
                    . " / " . $escape(number_format((float) $detail["question_points"], 2, ",", " ")) . "</p>";
                if ($revealCorrect && trim((string) ($detail["correct_answers"] ?? "")) !== "") {
                    $html .= "<p>Réponse(s) attendue(s) : " . $escape($detail["correct_answers"]) . "</p>";
                }
                $questionFeedback = trim((string) ($detail["teacher_feedback"] ?? ""));
                if ($questionFeedback !== "") {
                    [$purpose, $comment] = array_pad(explode(": ", $questionFeedback, 2), 2, "");
                    $html .= "<p class=\"small\"><strong>Commentaire du formateur ("
                        . $escape($feedbackLabels[$purpose] ?? "Commentaire") . ") :</strong> "
                        . $escape($comment) . "</p>";
                }
                $html .= "</div>";
            }
        }

        $html .= "<h2>Certificats obtenus</h2>";
        if (!$certificates) {
            $html .= '<p class="muted">Aucun certificat obtenu.</p>';
        } else {
            $html .= "<table><thead><tr><th>Formation</th><th>Numéro</th><th>Quiz</th><th>Examen</th><th>Résultat final</th><th>Délivré le</th></tr></thead><tbody>";
            foreach ($certificates as $certificate) {
                $html .= "<tr><td>" . $escape($certificate["course_title"]) . "</td><td>"
                    . $escape($certificate["certificate_number"]) . "</td><td>"
                    . ($certificate["quiz_average_percentage"] === null ? "—" : $escape(number_format((float) $certificate["quiz_average_percentage"], 2, ",", " ")) . "%")
                    . "</td><td>" . ($certificate["exam_percentage"] === null ? "—" : $escape(number_format((float) $certificate["exam_percentage"], 2, ",", " ")) . "%")
                    . "</td><td>" . ($certificate["combined_percentage"] === null ? "—" : $escape(number_format((float) $certificate["combined_percentage"], 2, ",", " ")) . "%")
                    . "</td><td>" . $escape($date($certificate["issue_date"])) . "</td></tr>";
            }
            $html .= "</tbody></table>";
        }
    }

    $html .= platformContactMarkup($pdo, "platform-contact-pdf");
    $html .= "</body></html>";
    $options = new Options();
    $options->set("defaultFont", "DejaVu Sans");
    $options->set("isRemoteEnabled", false);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, "UTF-8");
    $dompdf->setPaper("A4", "landscape");
    $dompdf->render();
    $filename = $onlyStudentId === null
        ? "rapports-formation-etudiants-" . date("Y-m-d") . ".pdf"
        : "rapport-formation-etudiant-" . $onlyStudentId . ".pdf";
    $dompdf->stream($filename, ["Attachment" => true]);
    exit;
}
