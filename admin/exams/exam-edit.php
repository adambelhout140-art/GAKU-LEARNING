<?php
require_once __DIR__ . '/../../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../auth/login.php");
    exit;
}

$role = $_SESSION["user_role"] ?? "";
if ($role !== "admin" && $role !== "teacher") {
    http_response_code(403);
    exit("Accès interdit.");
}

require_once "../../config/database.php";
require_once "../../config/audit.php";
require_once "../../config/content_access.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$examId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
if (!$examId || $examId < 1) {
    header("Location: exams.php");
    exit;
}

$examStatement = $pdo->prepare(
    "SELECT exams.*, courses.title AS course_title
     FROM exams
     INNER JOIN courses ON courses.id = exams.course_id
     WHERE exams.id = ? LIMIT 1"
);
$examStatement->execute([$examId]);
$exam = $examStatement->fetch(PDO::FETCH_ASSOC);

if (!$exam || !canManageCourse($pdo, (int) $exam["course_id"], (int) $_SESSION["user_id"], $role)) {
    http_response_code(403);
    exit("Vous n'avez pas accès à cet examen.");
}

$title = $exam["title"];
$instructions = $exam["instructions"] ?? "";
$passingScore = $exam["passing_score"];
$maxAttempts = $exam["max_attempts"];
$duration = $exam["duration_minutes"] ?? "";
$randomOrder = (int) $exam["random_order"];
$published = (int) $exam["is_published"];
$errors = [];

$questionStatement = $pdo->prepare(
    "SELECT id, question_text, question_type, points, explanation
     FROM questions WHERE exam_id = ? ORDER BY position, id"
);
$questionStatement->execute([$examId]);
$questions = $questionStatement->fetchAll(PDO::FETCH_ASSOC);

$answerStatement = $pdo->prepare(
    "SELECT question_id, answer_text, is_correct
     FROM question_answers
     WHERE question_id IN (SELECT id FROM questions WHERE exam_id = ?)
     ORDER BY position, id"
);
$answerStatement->execute([$examId]);
$answersByQuestion = [];
foreach ($answerStatement->fetchAll(PDO::FETCH_ASSOC) as $answer) {
    $answersByQuestion[$answer["question_id"]][] = $answer;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $questionId = filter_input(INPUT_POST, "question_id", FILTER_VALIDATE_INT);

    if ($questionId) {
        $questionText = trim($_POST["question_text"] ?? "");
        $questionType = $_POST["question_type"] ?? "";
        $questionPoints = trim($_POST["question_points"] ?? "");
        $questionExplanation = trim($_POST["question_explanation"] ?? "");
        $answerList = $_POST["answers"] ?? [];
        $answerList = array_pad(array_map("trim", array_slice(is_array($answerList) ? $answerList : [], 0, 4)), 4, "");
        $correctAnswers = $_POST["correct_answers"] ?? [];
        $correctAnswers = array_map("intval", is_array($correctAnswers) ? $correctAnswers : []);

        if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) $errors[] = "La session du formulaire a expiré.";
        if ($questionText === "") $errors[] = "La question est obligatoire.";
        if (!in_array($questionType, ["single_choice", "multiple_choice", "true_false", "short_answer"], true)) $errors[] = "Le type est invalide.";
        if (!is_numeric($questionPoints) || (float) $questionPoints <= 0) $errors[] = "Les points sont invalides.";

        if ($questionType === "true_false") {
            $answerList = ["Vrai", "Faux", "", ""];
        } elseif ($questionType === "short_answer") {
            $answerList = [trim($answerList[0]), "", "", ""];
            $correctAnswers = $answerList[0] === "" ? [] : [0];
        }

        if ($questionType !== "short_answer" && count(array_filter($answerList)) < 2) $errors[] = "Ajoutez au moins deux réponses.";
        $correctAnswers = array_values(array_unique(array_filter($correctAnswers, static function ($index) use ($answerList) {
            return isset($answerList[$index]) && $answerList[$index] !== "";
        })));
        if (!$correctAnswers) $errors[] = "Sélectionnez une bonne réponse.";
        if ($questionType === "single_choice" && count($correctAnswers) > 1) $errors[] = "Une seule bonne réponse est possible.";

        $questionCheck = $pdo->prepare("SELECT id FROM questions WHERE id = ? AND exam_id = ?");
        $questionCheck->execute([$questionId, $examId]);
        if (!$questionCheck->fetch()) $errors[] = "Question invalide.";

        if (!$errors) {
            $pdo->beginTransaction();
            $update = $pdo->prepare("UPDATE questions SET question_text = ?, question_type = ?, points = ?, explanation = ? WHERE id = ? AND exam_id = ?");
            $update->execute([$questionText, $questionType, (float) $questionPoints, $questionExplanation !== "" ? $questionExplanation : null, $questionId, $examId]);
            $pdo->prepare("DELETE FROM question_answers WHERE question_id = ?")->execute([$questionId]);
            $insertAnswer = $pdo->prepare("INSERT INTO question_answers (question_id, answer_text, is_correct, position) VALUES (?, ?, ?, ?)");
            foreach ($answerList as $position => $answer) {
                if ($answer !== "") $insertAnswer->execute([$questionId, $answer, in_array($position, $correctAnswers, true) ? 1 : 0, $position + 1]);
            }
            $pdo->commit();
            header("Location: exam-edit.php?id=" . $examId . "&success=question");
            exit;
        }
    } else {
        $title = trim($_POST["title"] ?? "");
        $instructions = trim($_POST["instructions"] ?? "");
        $passingScore = trim($_POST["passing_score"] ?? "");
        $maxAttempts = trim($_POST["max_attempts"] ?? "");
        $duration = trim($_POST["duration_minutes"] ?? "");
        $randomOrder = isset($_POST["random_order"]) ? 1 : 0;
        $published = isset($_POST["published"]) ? 1 : 0;

        if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) $errors[] = "La session du formulaire a expiré.";
        if ($title === "" || mb_strlen($title) > 200) $errors[] = "Le titre est obligatoire.";
        if (!is_numeric($passingScore) || $passingScore < 0 || $passingScore > 100) $errors[] = "Le score est invalide.";
        if (!ctype_digit($maxAttempts) || (int) $maxAttempts < 1) $errors[] = "Les tentatives sont invalides.";
        if ($duration !== "" && (!ctype_digit($duration) || (int) $duration < 1)) $errors[] = "La durée est invalide.";

        if (!$errors) {
            $update = $pdo->prepare("UPDATE exams SET title = ?, instructions = ?, passing_score = ?, max_attempts = ?, duration_minutes = ?, random_order = ?, is_published = ? WHERE id = ?");
            $update->execute([$title, $instructions !== "" ? $instructions : null, (float) $passingScore, (int) $maxAttempts, $duration !== "" ? (int) $duration : null, $randomOrder, $published, $examId]);
            auditLog($pdo, (int) $_SESSION["user_id"], "exam_updated", "exam", (int) $examId, null, ["title" => $title]);
            header("Location: exams.php?success=updated");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier l'examen</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">
</head>
<body class="admin-body<?= ($_SESSION["user_role"] ?? "") === "teacher" ? " teacher-view" : "" ?>">
<div class="admin-layout">
    <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('exams/exam-edit.php'); ?>
    <main class="admin-main quiz-page">
        <header class="admin-topbar page-header"><div><h1>Modifier l'examen</h1><p><?= e($exam["course_title"]) ?></p></div><a href="exams.php" class="btn btn-secondary">Retour</a></header>
        <?php if ($errors): ?><div class="alert alert-danger quiz-alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <?php if (($_GET["success"] ?? "") === "question"): ?><div class="alert alert-success quiz-alert">Question enregistrée avec succès.</div><?php endif; ?>

        <section class="form-container"><form method="POST"><input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>"><div class="form-group"><label>Titre *</label><input name="title" value="<?= e($title) ?>" required></div><div class="form-group"><label>Formation</label><input value="<?= e($exam["course_title"]) ?>" disabled></div><div class="form-group"><label>Instructions</label><textarea name="instructions" rows="3"><?= e($instructions) ?></textarea></div><div class="quiz-form-grid"><div class="form-group"><label>Score (%) *</label><input type="number" name="passing_score" value="<?= e($passingScore) ?>" min="0" max="100" step="0.01" required></div><div class="form-group"><label>Tentatives *</label><input type="number" name="max_attempts" value="<?= e($maxAttempts) ?>" min="1" required></div></div><div class="form-group"><label>Durée en minutes</label><input type="number" name="duration_minutes" value="<?= e($duration) ?>" min="1"></div><div class="form-group"><label><input type="checkbox" name="random_order" <?= $randomOrder ? "checked" : "" ?>> Mélanger les questions</label></div><div class="form-group"><label><input type="checkbox" name="published" <?= $published ? "checked" : "" ?>> Publier l'examen</label></div><div class="form-actions"><button class="btn btn-primary">Enregistrer les paramètres</button></div></form></section>

        <section class="form-container quiz-question-list"><h2>Questions et réponses</h2><?php foreach ($questions as $question): ?><form method="POST" class="quiz-question-card"><input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>"><input type="hidden" name="question_id" value="<?= (int) $question["id"] ?>"><div class="form-group"><label>Question</label><textarea name="question_text" rows="3" required><?= e($question["question_text"]) ?></textarea></div><div class="quiz-form-grid"><div class="form-group"><label>Type</label><select name="question_type" class="question-type"><option value="single_choice" <?= $question["question_type"] === "single_choice" ? "selected" : "" ?>>Choix unique</option><option value="multiple_choice" <?= $question["question_type"] === "multiple_choice" ? "selected" : "" ?>>Choix multiple</option><option value="true_false" <?= $question["question_type"] === "true_false" ? "selected" : "" ?>>Vrai / Faux</option><option value="short_answer" <?= $question["question_type"] === "short_answer" ? "selected" : "" ?>>Réponse courte</option></select></div><div class="form-group"><label>Points</label><input type="number" name="question_points" value="<?= e($question["points"]) ?>" min="0.01" step="0.01" required></div></div><div class="question-answers"><label>Réponses</label><p class="quiz-help answer-help">Cochez les réponses correctes.</p><?php foreach (array_pad($answersByQuestion[$question["id"]] ?? [], 4, ["answer_text" => "", "is_correct" => 0]) as $answerIndex => $answer): ?><div class="quiz-answer-row"><input type="checkbox" name="correct_answers[]" value="<?= $answerIndex ?>" <?= $answer["is_correct"] ? "checked" : "" ?>><input type="text" name="answers[]" value="<?= e($answer["answer_text"]) ?>"></div><?php endforeach; ?></div><div class="form-group"><label>Explication</label><textarea name="question_explanation" rows="2"><?= e($question["explanation"] ?? "") ?></textarea></div><button class="btn btn-primary">Enregistrer cette question</button></form><?php endforeach; ?></section>
    </main>
</div>
<script>
document.querySelectorAll(".quiz-question-card").forEach(function(card){var select=card.querySelector(".question-type"),rows=card.querySelectorAll(".quiz-answer-row"),inputs=card.querySelectorAll("input[type=text]"),checks=card.querySelectorAll("input[type=checkbox]"),help=card.querySelector(".answer-help");function update(){var type=select.value;rows.forEach(function(row,i){row.style.display=type==="short_answer"?(i===0?"flex":"none"):(type==="true_false"?(i<2?"flex":"none"):"flex")});if(type==="true_false"){inputs[0].value="Vrai";inputs[1].value="Faux";inputs[0].readOnly=true;inputs[1].readOnly=true;help.textContent="Cochez la bonne réponse."}else if(type==="short_answer"){inputs.forEach(function(input,i){input.readOnly=i!==0;input.placeholder=i===0?"Réponse attendue":""});checks.forEach(function(check){check.style.display="none"});help.textContent="Saisissez la réponse attendue."}else{inputs.forEach(function(input,i){input.readOnly=false;input.placeholder="Réponse "+(i+1)});checks.forEach(function(check){check.style.display="inline-block"});help.textContent="Cochez les réponses correctes."}}select.addEventListener("change",update);update()});
</script>
<script src="../../assets/js/teacher-navigation.js?v=20260925b"></script>
<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script></body>
</html>





