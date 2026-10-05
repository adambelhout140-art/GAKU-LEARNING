<?php
require_once __DIR__ . '/../config/i18n.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";
require_once "../config/notifications.php";
require_once "../config/audit.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}
if (($_SESSION["user_role"] ?? "") !== "teacher") {
    http_response_code(403);
    exit("Accès interdit.");
}

$teacherId = (int) $_SESSION["user_id"];
if (empty($_SESSION["csrf_token"])) $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
$attemptId = filter_input(INPUT_GET, "attempt", FILTER_VALIDATE_INT);
$type = ($_GET["type"] ?? "") === "exam" ? "exam" : "quiz";
if (!$attemptId || $attemptId < 1) {
    http_response_code(400);
    exit("Tentative invalide.");
}

if ($type === "quiz") {
    $statement = $pdo->prepare(
        "        SELECT qa.id AS attempt_id, qa.user_id, qa.percentage, qa.passed, qa.completed_at, q.title, c.title AS course_title, u.first_name, u.last_name
         FROM quiz_attempts qa INNER JOIN quizzes q ON q.id = qa.quiz_id INNER JOIN modules m ON m.id = q.module_id
         INNER JOIN courses c ON c.id = m.course_id AND EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id = c.id AND ct.teacher_id = ?) INNER JOIN enrollments en ON en.course_id = c.id AND en.user_id = qa.user_id AND en.status IN ('active','completed') INNER JOIN users u ON u.id = qa.user_id
         WHERE qa.id = ? LIMIT 1"
    );
} else {
    $statement = $pdo->prepare(
        "SELECT ea.id AS attempt_id, ea.user_id, ea.percentage, ea.passed, ea.completed_at, e.title, c.title AS course_title, u.first_name, u.last_name
         FROM exam_attempts ea INNER JOIN exams e ON e.id = ea.exam_id INNER JOIN courses c ON c.id = e.course_id AND EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id = c.id AND ct.teacher_id = ?) INNER JOIN enrollments en ON en.course_id = c.id AND en.user_id = ea.user_id AND en.status IN ('active','completed')
         INNER JOIN users u ON u.id = ea.user_id WHERE ea.id = ? LIMIT 1"
    );
}
$statement->execute([$teacherId, $attemptId]);
$attempt = $statement->fetch(PDO::FETCH_ASSOC);
if (!$attempt) {
    http_response_code(404);
    exit("Résultat introuvable.");
}

$feedbackTable = $type === "quiz" ? "quiz_attempt_feedback" : "exam_attempt_feedback";
$feedbackColumn = $type === "quiz" ? "quiz_attempt_id" : "exam_attempt_id";
$attemptColumn = $type === "quiz" ? "quiz_attempt_id" : "exam_attempt_id";
$questionColumn = $type === "quiz" ? "q.quiz_id = (SELECT quiz_id FROM quiz_attempts WHERE id = ?)" : "q.exam_id = (SELECT exam_id FROM exam_attempts WHERE id = ?)";
$feedback = null;
$feedbackStatement = $pdo->prepare("SELECT * FROM {$feedbackTable} WHERE {$feedbackColumn} = ? AND teacher_id = ? LIMIT 1");
$feedbackStatement->execute([$attemptId, $teacherId]);
$feedback = $feedbackStatement->fetch(PDO::FETCH_ASSOC) ?: null;
$feedbackErrors = [];
if ($_SERVER["REQUEST_METHOD"] === "POST" && hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
    $feedbackAction = $_POST["feedback_action"] ?? "save";
    $questionId = filter_input(INPUT_POST, "question_id", FILTER_VALIDATE_INT);
    $purpose = $_POST["purpose"] ?? "explanation";
    $visibility = $_POST["visibility"] ?? "student_parent";
    $comment = trim((string) ($_POST["comment"] ?? ""));
    if ($questionId) {
        $questionCheck = $pdo->prepare("SELECT q.question_text FROM questions q WHERE q.id = ? AND {$questionColumn} LIMIT 1");
        $questionCheck->execute([$questionId, $attemptId]);
        $questionText = (string) ($questionCheck->fetchColumn() ?: "");
        if ($questionText === "") $feedbackErrors[] = "La question sélectionnée est invalide.";
        if ($feedbackAction !== "delete") {
            if (!in_array($purpose, ["encouragement", "correction", "explanation"], true)) $feedbackErrors[] = "Le type de commentaire est invalide.";
            if (!in_array($visibility, ["student_parent", "student_only"], true)) $feedbackErrors[] = "La visibilité est invalide.";
            if ($comment === "" || mb_strlen($comment) > 2000) $feedbackErrors[] = "Le commentaire est obligatoire et limité à 2000 caractères.";
        }
        if (!$feedbackErrors) {
            if (!$questionId && $feedbackAction === "delete") {
                $deleteQuestionFeedback = $pdo->prepare("DELETE FROM assessment_question_feedback WHERE assessment_type = ? AND attempt_id = ? AND question_id = ? AND teacher_id = ?");
                $deleteQuestionFeedback->execute([$type, $attemptId, $questionId, $teacherId]);
            } else {
                $saveQuestionFeedback = $pdo->prepare(
                    "INSERT INTO assessment_question_feedback (assessment_type, attempt_id, question_id, teacher_id, purpose, visibility, comment)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE purpose = VALUES(purpose), visibility = VALUES(visibility), comment = VALUES(comment), updated_at = CURRENT_TIMESTAMP"
                );
                $saveQuestionFeedback->execute([$type, $attemptId, $questionId, $teacherId, $purpose, $visibility, $comment]);
                $notificationType = $type === "quiz" ? "quiz_result" : "exam_result";
                $purposeLabels = ["encouragement" => "Encouragement", "correction" => "Correction", "explanation" => "Explication"];
                $questionPreview = mb_strlen($questionText) > 180 ? mb_substr($questionText, 0, 177) . "..." : $questionText;
                $detailMessage = "Évaluation : " . $attempt["title"] . "\n";
                $detailMessage .= "Question : " . $questionPreview . "\n";
                $detailMessage .= "Type : " . ($purposeLabels[$purpose] ?? "Commentaire") . "\n";
                $detailMessage .= "Commentaire du formateur : " . $comment;
                notifyUser($pdo, (int) $attempt["user_id"], "Nouveau commentaire sur votre réponse", $detailMessage, $notificationType);
                if ($visibility === "student_parent") notifyParentsOfStudent($pdo, (int) $attempt["user_id"], "Nouveau commentaire sur une réponse", $detailMessage, $notificationType);
            }
            header("Location: attempt.php?type=" . urlencode($type) . "&attempt=" . $attemptId);
            exit;
        }
    }
    if ($feedbackAction !== "delete") {
        if (!in_array($purpose, ["encouragement", "correction", "explanation"], true)) $feedbackErrors[] = "Le type de commentaire est invalide.";
        if (!in_array($visibility, ["student_parent", "student_only"], true)) $feedbackErrors[] = "La visibilité est invalide.";
        if ($comment === "" || mb_strlen($comment) > 2000) $feedbackErrors[] = "Le commentaire est obligatoire et limité à 2000 caractères.";
    }
    if ($feedbackAction === "delete") {
        $deleteFeedback = $pdo->prepare("DELETE FROM {$feedbackTable} WHERE {$feedbackColumn} = ? AND teacher_id = ?");
        $deleteFeedback->execute([$attemptId, $teacherId]);
        $feedback = null;
    } elseif (!$questionId && !$feedbackErrors) {
        $saveFeedback = $pdo->prepare(
            "INSERT INTO {$feedbackTable} ({$feedbackColumn}, teacher_id, purpose, visibility, comment)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE purpose = VALUES(purpose), visibility = VALUES(visibility), comment = VALUES(comment), updated_at = CURRENT_TIMESTAMP"
        );
        $saveFeedback->execute([$attemptId, $teacherId, $purpose, $visibility, $comment]);
        $feedback = ["purpose" => $purpose, "visibility" => $visibility, "comment" => $comment];
        $assessmentLabel = $type === "quiz" ? "quiz" : "examen";
        $notificationType = $type === "quiz" ? "quiz_result" : "exam_result";
        $purposeLabels = ["encouragement" => "Encouragement", "correction" => "Correction", "explanation" => "Explication"];
        $detailMessage = "Évaluation : " . $attempt["title"] . "\n";
        $detailMessage .= "Type : " . ($purposeLabels[$purpose] ?? "Commentaire") . "\n";
        $detailMessage .= "Commentaire du formateur : " . $comment;
        notifyUser($pdo, (int) $attempt["user_id"], "Nouveau commentaire sur votre {$assessmentLabel}", $detailMessage, $notificationType);
        if ($visibility === "student_parent") {
            notifyParentsOfStudent($pdo, (int) $attempt["user_id"], "Nouveau commentaire sur un {$assessmentLabel}", $detailMessage, $notificationType);
        }
        auditLog($pdo, $teacherId, $type . "_feedback_saved", $type . "_attempt", $attemptId, null, ["purpose" => $purpose, "visibility" => $visibility]);
    }
}

$questionsStatement = $pdo->prepare("SELECT q.id, q.question_text, q.question_type, q.explanation FROM questions q WHERE {$questionColumn} ORDER BY q.position, q.id");
$questionsStatement->execute([$attemptId]);
$questions = $questionsStatement->fetchAll(PDO::FETCH_ASSOC);
$answerStatement = $pdo->prepare("SELECT aa.question_id, aa.answer_id, aa.answer_text, aa.is_correct, qa.answer_text AS option_text FROM attempt_answers aa LEFT JOIN question_answers qa ON qa.id = aa.answer_id WHERE aa.{$attemptColumn} = ? ORDER BY aa.id");
$answerStatement->execute([$attemptId]);
$answers = [];
foreach ($answerStatement->fetchAll(PDO::FETCH_ASSOC) as $answer)
    $answers[(int) $answer["question_id"]][] = $answer;
$questionFeedbackStatement = $pdo->prepare("SELECT * FROM assessment_question_feedback WHERE assessment_type = ? AND attempt_id = ? AND teacher_id = ?");
$questionFeedbackStatement->execute([$type, $attemptId, $teacherId]);
$questionFeedback = [];
foreach ($questionFeedbackStatement->fetchAll(PDO::FETCH_ASSOC) as $item) $questionFeedback[(int) $item["question_id"]] = $item;
function teacherAttemptEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détail du résultat - GAKU LEARNING</title>
    <link rel="icon" type="image/png" href="../assets/images/logo-mark.png">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260925f">
</head>

<body class="admin-body teacher-view teacher-dashboard-page">
    <div class="admin-layout">
        <?php require_once __DIR__ . "/../admin/config/admin_sidebar.php"; renderAdminSidebar("results.php"); ?>
    <main class="admin-main teacher-dashboard">
        <section class="teacher-page-heading">
            <div>
                <p class="student-eyebrow"><?= $type === "quiz" ? "Quiz" : "Examen" ?></p>
                <h1><?= teacherAttemptEscape($attempt["title"]) ?></h1>
                <p><?= teacherAttemptEscape($attempt["course_title"]) ?> ·
                    <?= teacherAttemptEscape(trim($attempt["first_name"] . " " . $attempt["last_name"])) ?>
                </p>
            </div><strong><?= teacherAttemptEscape($attempt["percentage"]) ?>%</strong>
        </section>
        <section class="teacher-results-panel">
            <?php if (in_array($type, ["quiz", "exam"], true)): ?>
                <?php foreach ($feedbackErrors as $feedbackError): ?><div class="alert alert-danger"><?= teacherAttemptEscape($feedbackError) ?></div><?php endforeach; ?>
                <form method="post" class="teacher-feedback-form">
                    <input type="hidden" name="csrf_token" value="<?= teacherAttemptEscape($_SESSION["csrf_token"]) ?>">
                    <input type="hidden" name="feedback_action" value="save">
                    <h2>Commentaire pédagogique</h2>
                    <p>Encouragez l'étudiant, corrigez une erreur ou expliquez un point.</p>
                    <label>Objectif
                        <select name="purpose">
                            <option value="encouragement" <?= ($feedback["purpose"] ?? "") === "encouragement" ? "selected" : "" ?>>Encouragement</option>
                            <option value="correction" <?= ($feedback["purpose"] ?? "") === "correction" ? "selected" : "" ?>>Correction</option>
                            <option value="explanation" <?= ($feedback["purpose"] ?? "explanation") === "explanation" ? "selected" : "" ?>>Explication</option>
                        </select>
                    </label>
                    <label>Visible par
                        <select name="visibility">
                            <option value="student_parent" <?= ($feedback["visibility"] ?? "student_parent") === "student_parent" ? "selected" : "" ?>>Étudiant et parent</option>
                            <option value="student_only" <?= ($feedback["visibility"] ?? "") === "student_only" ? "selected" : "" ?>>Étudiant uniquement</option>
                        </select>
                    </label>
                    <label>Commentaire<textarea name="comment" rows="5" maxlength="2000" required><?= teacherAttemptEscape($feedback["comment"] ?? "") ?></textarea></label>
                    <button class="button primary-button" type="submit"><?= $feedback ? "Modifier le commentaire" : "Ajouter le commentaire" ?></button>
                    <?php if ($feedback): ?><button class="button danger-button" type="submit" name="feedback_action" value="delete" onclick="return confirm('Supprimer ce commentaire ?');">Supprimer</button><?php endif; ?>
                </form>
            <?php endif; ?>
            <h2>Réponses de l'étudiant</h2><?php foreach ($questions as $number => $question): ?>
                <article class="teacher-answer-review">
                    <h3><?= $number + 1 ?>. <?= teacherAttemptEscape($question["question_text"]) ?></h3>
                    <?php foreach ($answers[(int) $question["id"]] ?? [] as $answer): ?>
                        <p class="<?= $answer["is_correct"] ? "teacher-answer-correct" : "teacher-answer-wrong" ?>">
                            <strong><?= $answer["is_correct"] ? "Correct" : "Incorrect" ?></strong>
                            <?= teacherAttemptEscape($answer["option_text"] ?: $answer["answer_text"] ?: "(vide)") ?>
                        </p>
                    <?php endforeach; ?>     <?php if ($question["explanation"]): ?>
                        <p class="teacher-answer-explanation"><strong>Explication :</strong>
                            <?= nl2br(teacherAttemptEscape($question["explanation"])) ?>
                        </p><?php endif; ?>
                    <?php $questionComment = $questionFeedback[(int) $question["id"]] ?? null; ?>
                    <form method="post" class="teacher-question-feedback-form">
                        <input type="hidden" name="csrf_token" value="<?= teacherAttemptEscape($_SESSION["csrf_token"]) ?>">
                        <input type="hidden" name="question_id" value="<?= (int) $question["id"] ?>">
                        <input type="hidden" name="feedback_action" value="save">
                        <label>Commentaire sur cette réponse
                            <textarea name="comment" rows="3" maxlength="2000" placeholder="Expliquez l'erreur ou encouragez l'étudiant..."><?= teacherAttemptEscape($questionComment["comment"] ?? "") ?></textarea>
                        </label>
                        <div class="teacher-question-feedback-options">
                            <label>Objectif <select name="purpose">
                                <option value="encouragement" <?= ($questionComment["purpose"] ?? "") === "encouragement" ? "selected" : "" ?>>Encouragement</option>
                                <option value="correction" <?= ($questionComment["purpose"] ?? "") === "correction" ? "selected" : "" ?>>Correction</option>
                                <option value="explanation" <?= ($questionComment["purpose"] ?? "explanation") === "explanation" ? "selected" : "" ?>>Explication</option>
                            </select></label>
                            <label>Visible par <select name="visibility">
                                <option value="student_parent" <?= ($questionComment["visibility"] ?? "student_parent") === "student_parent" ? "selected" : "" ?>>Étudiant et parent</option>
                                <option value="student_only" <?= ($questionComment["visibility"] ?? "") === "student_only" ? "selected" : "" ?>>Étudiant uniquement</option>
                            </select></label>
                        </div>
                        <div class="teacher-question-feedback-actions">
                            <button class="button primary-button" type="submit"><?= $questionComment ? "Modifier" : "Ajouter" ?> le commentaire</button>
                            <?php if ($questionComment): ?><button class="button danger-button" type="submit" name="feedback_action" value="delete" onclick="return confirm('Supprimer ce commentaire ?');">Supprimer</button><?php endif; ?>
                        </div>
                    </form>
                </article><?php endforeach; ?>
        </section>
    </main>
    </div>
    <script src="../assets/js/admin-sidebar.js?v=20260925e"></script>
</body>

</html>