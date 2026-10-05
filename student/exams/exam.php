<?php
require_once __DIR__ . '/../../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../../config/database.php";
require_once "../../config/student_access.php";
require_once "../../config/notifications.php";
require_once "../../config/platform_settings.php";
require_once "../../config/assessment_security.php";
require_once "../../config/combined_assessment_score.php";

$isParentViewer = ($_SESSION["user_role"] ?? "") === "parent";
if ($isParentViewer) {
    $studentId = filter_input(INPUT_GET, "student_id", FILTER_VALIDATE_INT);
    if (!$studentId || $studentId < 1) {
        http_response_code(400);
        exit("Étudiant invalide.");
    }
    $linkStatement = $pdo->prepare("SELECT 1 FROM parent_student_links WHERE parent_id = ? AND student_id = ? LIMIT 1");
    $linkStatement->execute([(int) $_SESSION["user_id"], $studentId]);
    if (!$linkStatement->fetchColumn()) {
        http_response_code(403);
        exit("Accès interdit.");
    }
    $userId = (int) $studentId;
} else {
    studentRequireAuth();
    studentEnsureCsrf();
    $userId = (int) $_SESSION["user_id"];
}
$examId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
$examStatement = $pdo->prepare(
    "SELECT e.*, c.id AS course_id, c.title AS course_title
     FROM exams e INNER JOIN courses c ON c.id = e.course_id
     INNER JOIN enrollments en ON en.course_id = c.id AND en.user_id = ?
     WHERE e.id = ? AND e.is_published = 1 AND c.status = 'published'
       AND en.status IN ('active', 'completed') AND (en.expires_at IS NULL OR en.expires_at >= NOW())
     LIMIT 1"
);
$examStatement->execute([$userId, $examId ?: 0]);
$exam = $examStatement->fetch(PDO::FETCH_ASSOC);
if (!$exam) {
    http_response_code(404);
    exit("Examen introuvable ou accès non autorisé.");
}

$requiredQuizStatement = $pdo->prepare(
    "SELECT q.id, q.title
     FROM quizzes q
     INNER JOIN modules m ON m.id = q.module_id
     WHERE m.course_id = ? AND q.is_published = 1
       AND NOT EXISTS (
           SELECT 1 FROM quiz_attempts qa
           WHERE qa.quiz_id = q.id AND qa.user_id = ?
             AND qa.passed = 1 AND qa.completed_at IS NOT NULL
       )
     ORDER BY m.position, q.id"
);
$requiredQuizStatement->execute([(int) $exam["course_id"], $userId]);
$requiredQuizzes = $requiredQuizStatement->fetchAll(PDO::FETCH_ASSOC);

$questionStatement = $pdo->prepare("SELECT id, question_text, question_type, points, explanation FROM questions WHERE exam_id = ? ORDER BY position, id");
$questionStatement->execute([$examId]);
$questions = $questionStatement->fetchAll(PDO::FETCH_ASSOC);
$answerStatement = $pdo->prepare("SELECT id, question_id, answer_text, is_correct FROM question_answers WHERE question_id IN (SELECT id FROM questions WHERE exam_id = ?) ORDER BY position, id");
$answerStatement->execute([$examId]);
$answersByQuestion = [];
foreach ($answerStatement->fetchAll(PDO::FETCH_ASSOC) as $answer) {
    $answersByQuestion[$answer["question_id"]][] = $answer;
}
$assessmentSessionError = null;
$assessmentSessionToken = $isParentViewer ? "" : assessmentSecurityToken($pdo, $userId, "exam", (int) $examId, $assessmentSessionError);
$assessmentDraft = $assessmentSessionToken !== "" ? assessmentSecurityDraft($pdo, $userId, "exam", (int) $examId, $assessmentSessionToken) : [];
$assessmentExpired = $assessmentSessionToken !== "" && assessmentSecurityExpired($pdo, $userId, "exam", (int) $examId, $assessmentSessionToken, (int) ($exam["duration_minutes"] ?? 0));

$attemptCountStatement = $pdo->prepare("SELECT COUNT(*) FROM exam_attempts WHERE exam_id = ? AND user_id = ?");
$attemptCountStatement->execute([$examId, $userId]);
$attemptCount = (int) $attemptCountStatement->fetchColumn();
$result = null;
$certificate = null;
$errors = [];

if (!$isParentViewer && $_SERVER["REQUEST_METHOD"] === "POST") {
    if ($assessmentSessionError) {
        $errors[] = $assessmentSessionError;
    } elseif ($assessmentExpired) {
        $errors[] = "Le temps de cet examen est écoulé.";
    } elseif (!studentCheckCsrf()) {
        $errors[] = "La session du formulaire a expiré.";
    } elseif ($requiredQuizzes) {
        $errors[] = "Vous devez réussir tous les quiz publiés de cette formation avant de passer l'examen.";
    } elseif ($attemptCount >= (int) $exam["max_attempts"]) {
        $errors[] = "Vous avez atteint le nombre maximal de tentatives.";
    } elseif (!$questions) {
        $errors[] = "Cet examen ne contient aucune question.";
    } else {
        $submitted = is_array($_POST["answers"] ?? null) ? $_POST["answers"] : [];
        $score = 0.0;
        $total = 0.0;
        $graded = [];
        foreach ($questions as $question) {
            $questionId = (int) $question["id"];
            $questionAnswers = $answersByQuestion[$questionId] ?? [];
            $correctIds = [];
            $correctTexts = [];
            foreach ($questionAnswers as $answer) {
                if ((int) $answer["is_correct"] === 1) {
                    $correctIds[] = (int) $answer["id"];
                    $correctTexts[] = mb_strtolower(trim($answer["answer_text"]));
                }
            }
            $total += (float) $question["points"];
            $value = $submitted[$questionId] ?? null;
            $answerText = is_array($value) ? "" : trim((string) $value);
            $values = $question["question_type"] === "short_answer"
                ? []
                : (is_array($value) ? array_values(array_unique(array_map("intval", $value))) : ($value === null || $value === "" ? [] : [(int) $value]));
            $values = array_values(array_intersect($values, array_map("intval", array_column($questionAnswers, "id"))));
            if ($question["question_type"] === "short_answer") {
                $isCorrect = $answerText !== "" && in_array(mb_strtolower($answerText), $correctTexts, true);
            } elseif ($question["question_type"] === "multiple_choice") {
                sort($values);
                $expected = $correctIds;
                sort($expected);
                $isCorrect = $values === $expected;
            } else {
                $isCorrect = count($values) === 1 && in_array($values[0], $correctIds, true);
            }
            $earned = $isCorrect ? (float) $question["points"] : 0.0;
            $score += $earned;
            $graded[] = ["question_id" => $questionId, "values" => $values, "answer_text" => $answerText, "correct" => $isCorrect, "points" => $earned];
        }
        $percentage = $total > 0 ? round(($score / $total) * 100, 2) : 0;
        $quizScoresStatement = $pdo->prepare(
            "SELECT q.id, COALESCE(MAX(qa.percentage), 0) AS best_percentage
             FROM quizzes q
             INNER JOIN modules m ON m.id = q.module_id
             LEFT JOIN quiz_attempts qa ON qa.quiz_id = q.id AND qa.user_id = ?
             WHERE m.course_id = ? AND q.is_published = 1
             GROUP BY q.id"
        );
        $quizScoresStatement->execute([$userId, (int) $exam["course_id"]]);
        $quizScores = array_column($quizScoresStatement->fetchAll(PDO::FETCH_ASSOC), "best_percentage");
        $combinedResult = calculateCombinedAssessmentResult(
            $quizScores,
            (float) $percentage,
            (float) $exam["passing_score"]
        );
        $passed = $percentage >= (float) $exam["passing_score"] ? 1 : 0;
        $pdo->beginTransaction();
        $insertAttempt = $pdo->prepare(
            "INSERT INTO exam_attempts
                (exam_id, user_id, attempt_number, score, total_points, percentage,
                 quiz_average_percentage, quiz_count, combined_percentage, overall_passed, passed, started_at, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $insertAttempt->execute([
            $examId,
            $userId,
            $attemptCount + 1,
            $score,
            $total,
            $percentage,
            $combinedResult["quiz_average_percentage"],
            $combinedResult["quiz_count"],
            $combinedResult["combined_percentage"],
            $combinedResult["overall_passed"] ? 1 : 0,
            $passed,
        ]);
        $attemptId = (int) $pdo->lastInsertId();
        $insertAnswer = $pdo->prepare("INSERT INTO attempt_answers (exam_attempt_id, question_id, answer_id, answer_text, is_correct, points_earned) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($graded as $answer) {
            if ($answer["values"]) {
                foreach ($answer["values"] as $answerId) {
                    $insertAnswer->execute([$attemptId, $answer["question_id"], $answerId, null, $answer["correct"] ? 1 : 0, $answer["correct"] ? $answer["points"] : 0]);
                }
            } else {
                $insertAnswer->execute([$attemptId, $answer["question_id"], null, $answer["answer_text"] ?: null, $answer["correct"] ? 1 : 0, $answer["correct"] ? $answer["points"] : 0]);
            }
        }
        if ($combinedResult["overall_passed"]) {
            $certificateNumber = platformSetting($pdo, "certificate_prefix", "FP") . "-" . date("Ymd") . "-" . strtoupper(bin2hex(random_bytes(4)));
            $certificateInsert = $pdo->prepare(
                "INSERT IGNORE INTO certificates (user_id, course_id, exam_attempt_id, certificate_number, issue_date)
                 VALUES (?, ?, ?, ?, CURDATE())"
            );
            $certificateInsert->execute([$userId, $exam["course_id"], $attemptId, $certificateNumber]);
            if ($certificateInsert->rowCount() > 0) {
                notifyUser(
                    $pdo,
                    $userId,
                    "Nouveau certificat",
                    "Félicitations ! Vous avez obtenu votre certificat pour « " . $exam["course_title"] . " » avec un résultat combiné de " . $combinedResult["combined_percentage"] . "% (n° " . $certificateNumber . ").",
                    "certificate"
                );
            }
        }
        $pdo->commit();
        $completeSession = $pdo->prepare("UPDATE assessment_sessions SET completed_at = NOW(), draft_answers = NULL WHERE user_id = ? AND assessment_type = 'exam' AND assessment_id = ? AND session_token = ?");
        $completeSession->execute([$userId, $examId, $assessmentSessionToken]);
        notifyUser(
            $pdo,
            $userId,
            "Résultat de l'examen",
            "Moyenne quiz : " . ($combinedResult["quiz_average_percentage"] === null ? "aucun quiz publié" : $combinedResult["quiz_average_percentage"] . "%")
                . ". Score de l'examen : " . $percentage . "%. Résultat combiné quiz/examen : "
                . $combinedResult["combined_percentage"] . "% (seuil : " . $exam["passing_score"] . "%) — "
                . ($combinedResult["overall_passed"] ? "formation réussie." : "seuil combiné non atteint."),
            "exam_result"
        );
        notifyParentsOfStudent(
            $pdo,
            $userId,
            "Nouveau résultat d'examen",
            "Examen « " . $exam["title"] . " » : " . $percentage . "%. Moyenne quiz : "
                . ($combinedResult["quiz_average_percentage"] === null ? "aucun quiz publié" : $combinedResult["quiz_average_percentage"] . "%")
                . ". Résultat combiné quiz/examen : "
                . $combinedResult["combined_percentage"] . "% (seuil : " . $exam["passing_score"] . "%).",
            "exam_result"
        );
        header("Location: exam.php?id=" . (int) $examId . "&attempt=" . $attemptId);
        exit;
    }
}

if (isset($_GET["attempt"]) && ctype_digit((string) $_GET["attempt"])) {
    $resultStatement = $pdo->prepare("SELECT * FROM exam_attempts WHERE id = ? AND exam_id = ? AND user_id = ? LIMIT 1");
    $resultStatement->execute([(int) $_GET["attempt"], $examId, $userId]);
    $result = $resultStatement->fetch(PDO::FETCH_ASSOC);
    if ($result && (int) $result["overall_passed"] === 1) {
        $certificateStatement = $pdo->prepare("SELECT id, certificate_number FROM certificates WHERE user_id = ? AND course_id = ? LIMIT 1");
        $certificateStatement->execute([$userId, $exam["course_id"]]);
        $certificate = $certificateStatement->fetch(PDO::FETCH_ASSOC);
    }
}
$answerReview = [];
$revealCorrectAnswers = false;
if ($result) {
    $revealCorrectAnswers = $attemptCount >= (int) $exam["max_attempts"];
    $answerReview = studentBuildAnswerReview($pdo, $questions, $answersByQuestion, (int) $result["id"], "exam_attempt_id", $revealCorrectAnswers);
}
$feedback = null;
$questionFeedback = [];
if ($result) {
    $feedbackStatement = $pdo->prepare(
        "SELECT eaf.purpose, eaf.comment, eaf.created_at, u.first_name, u.last_name
         FROM exam_attempt_feedback eaf
         INNER JOIN users u ON u.id = eaf.teacher_id
         WHERE eaf.exam_attempt_id = ? AND eaf.visibility " . ($isParentViewer ? "= 'student_parent'" : "IN ('student_parent', 'student_only')") . "
         ORDER BY eaf.updated_at DESC LIMIT 1"
    );
    $feedbackStatement->execute([(int) $result["id"]]);
    $feedback = $feedbackStatement->fetch(PDO::FETCH_ASSOC) ?: null;
    $questionFeedbackStatement = $pdo->prepare("SELECT aqf.question_id, aqf.purpose, aqf.comment FROM assessment_question_feedback aqf WHERE aqf.assessment_type = 'exam' AND aqf.attempt_id = ? AND aqf.visibility " . ($isParentViewer ? "= 'student_parent'" : "IN ('student_parent', 'student_only')"));
    $questionFeedbackStatement->execute([(int) $result["id"]]);
    foreach ($questionFeedbackStatement->fetchAll(PDO::FETCH_ASSOC) as $item) $questionFeedback[(int) $item["question_id"]] = $item;
}
if ((int) $exam["random_order"] === 1) {
    shuffle($questions);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title><?= studentEscape($exam["title"]) ?></title><link rel="icon" type="image/png" href="../../assets/images/logo-mark.png"><link rel="stylesheet" href="../../assets/css/style.css?v=20260917d"></head>
<body class="student-body"><header class="student-navbar"><a href="../../index.php" class="student-brand"><img src="../../assets/images/logo-mark.png" alt="Gaku Learning" class="brand-logo" width="42" height="42"> GAKU LEARNING</a><?php if ($isParentViewer): ?><nav><a href="../../parent/dashboard.php">Mes enfants</a><a href="../../parent/message.php">Contacter</a><a href="../../auth/logout.php">Déconnexion</a></nav><?php else: ?><nav><a href="../dashboard.php">Tableau de bord</a><a href="../courses/course.php?id=<?= (int) $exam["course_id"] ?>">Ma formation</a><a href="../certificates/certificates.php">Mes certificats</a><a href="../results.php">Mes résultats</a><a href="../bookmarks.php">Mes favoris</a><a href="../account.php">Mon compte</a><?= studentNotificationBell($pdo, $userId, "../notifications.php") ?><a href="../../auth/logout.php">Déconnexion</a></nav><?php endif; ?></header>
<main class="student-dashboard"><p class="student-eyebrow"><?php if ($isParentViewer): ?><a href="../../parent/dashboard.php">Mes enfants</a> / <?php else: ?><a href="../courses/course.php?id=<?= (int) $exam["course_id"] ?>"><?= studentEscape($exam["course_title"]) ?></a><?php endif; ?></p>
<section class="student-section assessment-page"><h1><?= studentEscape($exam["title"]) ?></h1><?php if ($exam["instructions"]): ?><p><?= nl2br(studentEscape($exam["instructions"])) ?></p><?php endif; ?><p class="student-muted">Seuil requis pour l'examen et le résultat final : <?= studentEscape($exam["passing_score"]) ?>% · Note finale = moyenne quiz/examen (50% chacun) · Tentatives : <?= $attemptCount ?> / <?= (int) $exam["max_attempts"] ?><?php if ($exam["duration_minutes"]): ?> · Durée : <?= (int) $exam["duration_minutes"] ?> min<?php endif; ?></p>
<?php if ($result): ?><?php if ($feedback): ?><?php $feedbackPurposeLabels = ["encouragement" => "Encouragement", "correction" => "Correction", "explanation" => "Explication"]; ?><section class="student-section quiz-feedback-card"><p class="student-eyebrow"><?= studentEscape($feedbackPurposeLabels[$feedback["purpose"]] ?? "Commentaire") ?></p><h2>Commentaire du formateur</h2><p><?= nl2br(studentEscape($feedback["comment"])) ?></p><small>Par <?= studentEscape(trim($feedback["first_name"] . " " . $feedback["last_name"])) ?></small></section><?php endif; ?><div class="alert <?= $result["overall_passed"] ? "alert-success" : "alert-danger" ?>"><strong>Résultat final de la formation : <?= studentEscape($result["combined_percentage"]) ?>%</strong> — <?= $result["overall_passed"] ? "Formation réussie !" : "Seuil de réussite non atteint." ?><br>Score de l'examen : <?= studentEscape($result["percentage"]) ?>% (<?= $result["passed"] ? "examen réussi" : "examen non réussi" ?>). <?php if ($result["quiz_average_percentage"] !== null): ?>Moyenne des meilleurs résultats aux quiz : <?= studentEscape($result["quiz_average_percentage"]) ?>%.<?php elseif ((int) ($result["quiz_count"] ?? -1) === 0): ?>Aucun quiz publié : le résultat final est basé sur l'examen seul.<?php else: ?>Moyenne des quiz non enregistrée pour cet ancien résultat.<?php endif; ?><?php if ($certificate && !$isParentViewer): ?> <a href="../certificates/certificate.php?id=<?= (int) $certificate["id"] ?>&download=1">Télécharger votre certificat</a><?php endif; ?></div><?php endif; ?>
<?php if ($requiredQuizzes): ?><div class="alert alert-danger">Réussissez tous les quiz avant de passer cet examen. Quiz restants : <?php foreach ($requiredQuizzes as $index => $requiredQuiz): ?><?= $index ? ", " : "" ?><?= studentEscape($requiredQuiz["title"]) ?><?php endforeach; ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><?= studentEscape(implode(" ", $errors)) ?></div><?php endif; ?>
<?php if (!$isParentViewer && (!$result || $errors) && !$requiredQuizzes && !$assessmentSessionError && !$assessmentExpired): ?><form method="post" data-assessment-form data-duration-minutes="<?= (int) ($exam["duration_minutes"] ?? 0) ?>"><input type="hidden" name="csrf_token" value="<?= studentEscape($_SESSION["csrf_token"]) ?>"><input type="hidden" name="assessment_session_token" value="<?= studentEscape($assessmentSessionToken) ?>"><input type="hidden" name="assessment_type" value="exam"><input type="hidden" name="assessment_id" value="<?= (int) $examId ?>"><input type="hidden" name="assessment_draft" value="<?= studentEscape(json_encode($assessmentDraft, JSON_UNESCAPED_UNICODE)) ?>"><?php foreach ($questions as $number => $question): ?><fieldset class="assessment-question"><legend><?= $number + 1 ?>. <?= studentEscape($question["question_text"]) ?> <small>(<?= studentEscape($question["points"]) ?> point(s))</small></legend><?php if ($question["question_type"] === "short_answer"): ?><input type="text" name="answers[<?= (int) $question["id"] ?>]" required><?php else: foreach ($answersByQuestion[$question["id"]] ?? [] as $answer): ?><label><input type="<?= $question["question_type"] === "multiple_choice" ? "checkbox" : "radio" ?>" name="answers[<?= (int) $question["id"] ?>]<?= $question["question_type"] === "multiple_choice" ? "[]" : "" ?>" value="<?= (int) $answer["id"] ?>"> <?= studentEscape($answer["answer_text"]) ?></label><?php endforeach; endif; ?></fieldset><?php endforeach; ?><button class="button primary-button" type="submit">Terminer l'examen</button></form><?php endif; ?>
<?php if ($result && $answerReview): ?>
<div class="answer-review">
    <h2>Revoir mes réponses</h2>
    <?php if (!$revealCorrectAnswers): ?><p class="student-muted">Les bonnes réponses seront affichées une fois vos tentatives épuisées (<?= $attemptCount ?> / <?= (int) $exam["max_attempts"] ?>).</p><?php endif; ?>
    <?php foreach ($answerReview as $number => $item): ?>
        <fieldset class="assessment-question review-question <?= $item["is_correct"] ? "review-correct" : "review-incorrect" ?>">
            <legend><?= $number + 1 ?>. <?= studentEscape($item["question_text"]) ?> <span class="<?= $item["is_correct"] ? "student-result-good" : "student-result-bad" ?>"><?= $item["is_correct"] ? "✓ Correct" : "✗ Incorrect" ?></span></legend>
            <?php if ($item["question_type"] === "short_answer"): ?>
                <p>Votre réponse : <strong><?= studentEscape($item["selected_text"] !== "" ? $item["selected_text"] : "(vide)") ?></strong></p>
            <?php else: ?>
                <ul class="review-options">
                <?php foreach ($item["options"] as $option): ?>
                    <li class="<?= $option["was_selected"] ? "review-selected" : "" ?> <?= $option["is_correct"] === true ? "review-answer-correct" : "" ?>">
                        <?= $option["was_selected"] ? "☑" : "☐" ?> <?= studentEscape($option["text"]) ?>
                        <?php if ($option["is_correct"] === true): ?><small>(bonne réponse)</small><?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($item["explanation"] && $revealCorrectAnswers): ?><p class="student-muted"><?= studentEscape($item["explanation"]) ?></p><?php endif; ?>
            <?php if (!empty($questionFeedback[(int) $item["question_id"]])): $itemFeedback = $questionFeedback[(int) $item["question_id"]]; ?>
                <div class="quiz-question-feedback"><strong><?= studentEscape(["encouragement" => "Encouragement", "correction" => "Correction", "explanation" => "Explication"][$itemFeedback["purpose"]] ?? "Commentaire") ?> :</strong> <?= nl2br(studentEscape($itemFeedback["comment"])) ?></div>
            <?php endif; ?>
        </fieldset>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</section></main>
<script src="../../assets/js/assessment-guard.js"></script>
</body></html>
