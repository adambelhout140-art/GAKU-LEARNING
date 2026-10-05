<?php
require_once __DIR__ . '/../../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../../config/database.php";
require_once "../../config/student_access.php";
require_once "../../config/notifications.php";
require_once "../../config/assessment_security.php";

$isParentViewer = ($_SESSION["user_role"] ?? "") === "parent";
if ($isParentViewer) {
    $studentId = filter_input(INPUT_GET, "student_id", FILTER_VALIDATE_INT);
    if (!$studentId || $studentId < 1) {
        http_response_code(400);
        exit("Étudiant invalide.");
    }
    $linkStatement = $pdo->prepare(
        "SELECT 1 FROM parent_student_links WHERE parent_id = ? AND student_id = ? LIMIT 1"
    );
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
$quizId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
if (!$quizId || $quizId < 1) {
    http_response_code(400);
    exit("Identifiant de quiz invalide.");
}

$quizStatement = $pdo->prepare(
    "SELECT q.*, m.title AS module_title, c.id AS course_id, c.title AS course_title
     FROM quizzes q INNER JOIN modules m ON m.id = q.module_id INNER JOIN courses c ON c.id = m.course_id
     INNER JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
     WHERE q.id = ? AND q.is_published = 1 AND c.status = 'published'
       AND e.status IN ('active', 'completed') AND (e.expires_at IS NULL OR e.expires_at >= NOW())
     LIMIT 1"
);
$quizStatement->execute([$userId, $quizId ?: 0]);
$quiz = $quizStatement->fetch(PDO::FETCH_ASSOC);
if (!$quiz) {
    http_response_code(404);
    exit("Quiz introuvable ou accès non autorisé.");
}

$questionStatement = $pdo->prepare(
    "SELECT id, question_text, question_type, points, explanation
     FROM questions WHERE quiz_id = ? ORDER BY position, id"
);
$questionStatement->execute([$quizId]);
$questions = $questionStatement->fetchAll(PDO::FETCH_ASSOC);
$answerStatement = $pdo->prepare(
    "SELECT id, question_id, answer_text, is_correct FROM question_answers
     WHERE question_id IN (SELECT id FROM questions WHERE quiz_id = ?) ORDER BY position, id"
);
$answerStatement->execute([$quizId]);
$answersByQuestion = [];
foreach ($answerStatement->fetchAll(PDO::FETCH_ASSOC) as $answer) {
    $answersByQuestion[$answer["question_id"]][] = $answer;
}
$assessmentSessionError = null;
$assessmentSessionToken = $isParentViewer ? "" : assessmentSecurityToken($pdo, $userId, "quiz", (int) $quizId, $assessmentSessionError);
$assessmentDraft = $assessmentSessionToken !== "" ? assessmentSecurityDraft($pdo, $userId, "quiz", (int) $quizId, $assessmentSessionToken) : [];
$assessmentExpired = $assessmentSessionToken !== "" && assessmentSecurityExpired($pdo, $userId, "quiz", (int) $quizId, $assessmentSessionToken, (int) ($quiz["duration_minutes"] ?? 0));

$attemptCountStatement = $pdo->prepare("SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = ? AND user_id = ?");
$attemptCountStatement->execute([$quizId, $userId]);
$attemptCount = (int) $attemptCountStatement->fetchColumn();
$result = null;
$errors = [];
if ($assessmentSessionError) {
    $errors[] = $assessmentSessionError;
} elseif ($assessmentExpired) {
    $errors[] = "Le temps de cette évaluation est écoulé.";
}
if (!$isParentViewer && $_SERVER["REQUEST_METHOD"] === "POST") {
    if ($assessmentSessionError) {
        $errors[] = $assessmentSessionError;
    } elseif ($assessmentExpired) {
        $errors[] = "Le temps de cette évaluation est écoulé.";
    } elseif (!studentCheckCsrf()) {
        $errors[] = "La session du formulaire a expiré.";
    } else {
        if ($quiz["max_attempts"] !== null && $attemptCount >= (int) $quiz["max_attempts"]) {
            $errors[] = "Vous avez atteint le nombre maximal de tentatives.";
        } elseif (!$questions) {
            $errors[] = "Ce quiz ne contient aucune question.";
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
                $isCorrect = false;
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
            $passed = $percentage >= (float) $quiz["passing_score"] ? 1 : 0;
            $pdo->beginTransaction();
            $attemptStatement = $pdo->prepare(
                "INSERT INTO quiz_attempts (quiz_id, user_id, attempt_number, score, total_points, percentage, passed, started_at, completed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
            );
            $attemptStatement->execute([$quizId, $userId, $attemptCount + 1, $score, $total, $percentage, $passed]);
            $attemptId = (int) $pdo->lastInsertId();
            $answerInsert = $pdo->prepare(
                "INSERT INTO attempt_answers (quiz_attempt_id, question_id, answer_id, answer_text, is_correct, points_earned)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            foreach ($graded as $answer) {
                if ($answer["values"]) {
                    foreach ($answer["values"] as $answerId) {
                        $answerInsert->execute([$attemptId, $answer["question_id"], $answerId, null, $answer["correct"] ? 1 : 0, $answer["correct"] ? $answer["points"] : 0]);
                    }
                } else {
                    $answerInsert->execute([$attemptId, $answer["question_id"], null, $answer["answer_text"] ?: null, $answer["correct"] ? 1 : 0, $answer["correct"] ? $answer["points"] : 0]);
                }
            }
            $pdo->commit();
            $completeSession = $pdo->prepare("UPDATE assessment_sessions SET completed_at = NOW(), draft_answers = NULL WHERE user_id = ? AND assessment_type = 'quiz' AND assessment_id = ? AND session_token = ?");
            $completeSession->execute([$userId, $quizId, $assessmentSessionToken]);
            notifyUser(
                $pdo,
                $userId,
                $passed ? "Quiz réussi" : "Quiz non réussi",
                "Résultat pour « " . $quiz["title"] . " » : " . $percentage . "% (" . ($passed ? "réussi" : "non réussi") . ").",
                "quiz_result"
            );
            notifyParentsOfStudent($pdo, $userId, "Nouveau résultat de quiz", "Un quiz de votre enfant est terminé : " . $quiz["title"] . " (" . $percentage . "%).", "quiz_result");
            header("Location: quiz.php?id=" . (int) $quizId . "&attempt=" . $attemptId);
            exit;
        }
    }
}

if (isset($_GET["attempt"]) && ctype_digit((string) $_GET["attempt"])) {
    $resultStatement = $pdo->prepare("SELECT * FROM quiz_attempts WHERE id = ? AND quiz_id = ? AND user_id = ? LIMIT 1");
    $resultStatement->execute([(int) $_GET["attempt"], $quizId, $userId]);
    $result = $resultStatement->fetch(PDO::FETCH_ASSOC);
}
$answerReview = [];
$revealCorrectAnswers = false;
if ($result) {
    $revealCorrectAnswers = $quiz["max_attempts"] !== null && $attemptCount >= (int) $quiz["max_attempts"];
    $answerReview = studentBuildAnswerReview($pdo, $questions, $answersByQuestion, (int) $result["id"], "quiz_attempt_id", $revealCorrectAnswers);
}
$feedback = null;
$questionFeedback = [];
if ($result) {
    $feedbackStatement = $pdo->prepare(
        "SELECT qaf.purpose, qaf.comment, qaf.created_at, u.first_name, u.last_name
         FROM quiz_attempt_feedback qaf
         INNER JOIN users u ON u.id = qaf.teacher_id
         WHERE qaf.quiz_attempt_id = ? AND qaf.visibility " . ($isParentViewer ? "= 'student_parent'" : "IN ('student_parent', 'student_only')") . "
         ORDER BY qaf.updated_at DESC LIMIT 1"
    );
    $feedbackStatement->execute([(int) $result["id"]]);
    $feedback = $feedbackStatement->fetch(PDO::FETCH_ASSOC) ?: null;
    $questionFeedbackStatement = $pdo->prepare("SELECT aqf.question_id, aqf.purpose, aqf.comment FROM assessment_question_feedback aqf WHERE aqf.assessment_type = 'quiz' AND aqf.attempt_id = ? AND aqf.visibility " . ($isParentViewer ? "= 'student_parent'" : "IN ('student_parent', 'student_only')"));
    $questionFeedbackStatement->execute([(int) $result["id"]]);
    foreach ($questionFeedbackStatement->fetchAll(PDO::FETCH_ASSOC) as $item) $questionFeedback[(int) $item["question_id"]] = $item;
}
if ((int) $quiz["random_order"] === 1) {
    shuffle($questions);
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= studentEscape($quiz["title"]) ?></title><link rel="icon" type="image/png" href="../../assets/images/logo-mark.png">
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260917d">
</head>

<body class="student-body">
    <header class="student-navbar"><a href="../../index.php" class="student-brand"><img src="../../assets/images/logo-mark.png" alt="Gaku Learning" class="brand-logo" width="42" height="42"> GAKU LEARNING</a>
        <?php if ($isParentViewer): ?>
           <nav><a href="../../parent/dashboard.php">Mes enfants</a><a href="../../parent/message.php">Contacter</a><a href="../../auth/logout.php">Déconnexion</a></nav>
        <?php else: ?>
        <nav><a href="../dashboard.php">Tableau de bord</a><a
                href="../courses/course.php?id=<?= (int) $quiz["course_id"] ?>">Ma formation</a><a
                href="../certificates/certificates.php">Mes certificats</a><a href="../results.php">Mes résultats</a><a
                href="../bookmarks.php">Mes favoris</a><a href="../account.php">Mon
                compte</a><?= studentNotificationBell($pdo, $userId, "../notifications.php") ?><a
                href="../../auth/logout.php">Déconnexion</a></nav>
       <?php endif; ?></header>
    <main class="student-dashboard">
        <p class="student-eyebrow"><?php if ($isParentViewer): ?><a href="../../parent/dashboard.php">Mes enfants</a> / <?php else: ?><a
                href="../courses/course.php?id=<?= (int) $quiz["course_id"] ?>"><?= studentEscape($quiz["course_title"]) ?></a>
            <?php endif; ?>
            / <?= studentEscape($quiz["module_title"]) ?></p>
        <section class="student-section assessment-page">
            <h1><?= studentEscape($quiz["title"]) ?></h1><?php if ($quiz["instructions"]): ?>
                <p><?= nl2br(studentEscape($quiz["instructions"])) ?></p><?php endif; ?>
            <p class="student-muted">Score requis :
                <?= studentEscape($quiz["passing_score"]) ?>%<?php if ($quiz["max_attempts"]): ?> · Tentatives restantes
                    : <?= max(0, (int) $quiz["max_attempts"] - $attemptCount) ?><?php endif; ?></p>
            <?php if ($result): ?>
                <?php if ($feedback): ?><?php $feedbackPurposeLabels = ["encouragement" => "Encouragement", "correction" => "Correction", "explanation" => "Explication"]; ?><section class="student-section quiz-feedback-card"><p class="student-eyebrow"><?= studentEscape($feedbackPurposeLabels[$feedback["purpose"]] ?? "Commentaire") ?></p><h2>Commentaire du formateur</h2><p><?= nl2br(studentEscape($feedback["comment"])) ?></p><small>Par <?= studentEscape(trim($feedback["first_name"] . " " . $feedback["last_name"])) ?></small></section><?php endif; ?>
                <div class="alert <?= $result["passed"] ? "alert-success" : "alert-danger" ?>">Résultat :
                    <strong><?= studentEscape($result["percentage"]) ?>%</strong> —
                    <?= $result["passed"] ? "Quiz réussi !" : "Quiz non réussi." ?></div><?php endif; ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger"><?= studentEscape(implode(" ", $errors)) ?></div><?php endif; ?>
            <?php if (!$isParentViewer && !$result && !$assessmentSessionError && !$assessmentExpired && (!$quiz["max_attempts"] || $attemptCount < (int) $quiz["max_attempts"])): ?>
                <form method="post" data-assessment-form data-duration-minutes="<?= (int) ($quiz["duration_minutes"] ?? 0) ?>"><input type="hidden" name="csrf_token" value="<?= studentEscape($_SESSION["csrf_token"]) ?>"><input type="hidden" name="assessment_session_token" value="<?= studentEscape($assessmentSessionToken) ?>"><input type="hidden" name="assessment_type" value="quiz"><input type="hidden" name="assessment_id" value="<?= (int) $quizId ?>"><input type="hidden" name="assessment_draft" value="<?= studentEscape(json_encode($assessmentDraft, JSON_UNESCAPED_UNICODE)) ?>"><?php foreach ($questions as $number => $question): ?>
                        <fieldset class="assessment-question">
                            <legend><?= $number + 1 ?>. <?= studentEscape($question["question_text"]) ?>
                                <small>(<?= studentEscape($question["points"]) ?> point(s))</small></legend>
                            <?php if ($question["question_type"] === "short_answer"): ?><input type="text"
                                    name="answers[<?= (int) $question["id"] ?>]" required>
                            <?php else:
                                foreach ($answersByQuestion[$question["id"]] ?? [] as $answer): ?><label><input
                                            type="<?= $question["question_type"] === "multiple_choice" ? "checkbox" : "radio" ?>"
                                            name="answers[<?= (int) $question["id"] ?>]<?= $question["question_type"] === "multiple_choice" ? "[]" : "" ?>"
                                            value="<?= (int) $answer["id"] ?>">
                                        <?= studentEscape($answer["answer_text"]) ?></label><?php endforeach; endif; ?>
                        </fieldset><?php endforeach; ?><button class="button primary-button" type="submit">Terminer le
                        quiz</button>
                </form><?php endif; ?>
            <?php if ($result && $answerReview): ?>
                <div class="answer-review">
                    <h2>Revoir mes réponses</h2>
                    <?php if (!$revealCorrectAnswers): ?>
                        <p class="student-muted">Les bonnes réponses seront affichées une fois vos tentatives épuisées.</p>
                    <?php endif; ?>
                    <?php foreach ($answerReview as $number => $item): ?>
                        <fieldset
                            class="assessment-question review-question <?= $item["is_correct"] ? "review-correct" : "review-incorrect" ?>">
                            <legend><?= $number + 1 ?>. <?= studentEscape($item["question_text"]) ?> <span
                                    class="<?= $item["is_correct"] ? "student-result-good" : "student-result-bad" ?>"><?= $item["is_correct"] ? "✓ Correct" : "✗ Incorrect" ?></span>
                            </legend>
                            <?php if ($item["question_type"] === "short_answer"): ?>
                                <p>Votre réponse :
                                    <strong><?= studentEscape($item["selected_text"] !== "" ? $item["selected_text"] : "(vide)") ?></strong>
                                </p>
                            <?php else: ?>
                                <ul class="review-options">
                                    <?php foreach ($item["options"] as $option): ?>
                                        <li
                                            class="<?= $option["was_selected"] ? "review-selected" : "" ?> <?= $option["is_correct"] === true ? "review-answer-correct" : "" ?>">
                                            <?= $option["was_selected"] ? "☑" : "☐" ?>                 <?= studentEscape($option["text"]) ?>
                                            <?php if ($option["is_correct"] === true): ?><small>(bonne réponse)</small><?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if ($item["explanation"] && (!$item["is_correct"] || $revealCorrectAnswers)): ?>
                                <p class="quiz-explanation"><strong>Explication :</strong>
                                    <?= nl2br(studentEscape($item["explanation"])) ?></p><?php endif; ?>
                            <?php if (!empty($questionFeedback[(int) $item["question_id"]])): $itemFeedback = $questionFeedback[(int) $item["question_id"]]; ?>
                                <div class="quiz-question-feedback"><strong><?= studentEscape(["encouragement" => "Encouragement", "correction" => "Correction", "explanation" => "Explication"][$itemFeedback["purpose"]] ?? "Commentaire") ?> :</strong> <?= nl2br(studentEscape($itemFeedback["comment"])) ?></div>
                            <?php endif; ?>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
<script src="../../assets/js/assessment-guard.js"></script>
</body>

</html>
