<?php
require_once __DIR__ . '/../../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../auth/login.php");
    exit;
}

$userRole = $_SESSION["user_role"] ?? "";
if ($userRole !== "admin" && $userRole !== "teacher") {
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

$quizId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
if (!$quizId || $quizId < 1) {
    header("Location: quizzes.php");
    exit;
}

$quizStatement = $pdo->prepare(
    "SELECT q.*, m.id AS module_id, c.title AS course_title, m.title AS module_title
     FROM quizzes q
     INNER JOIN modules m ON m.id = q.module_id
     INNER JOIN courses c ON c.id = m.course_id
     WHERE q.id = ? LIMIT 1"
);
$quizStatement->execute([$quizId]);
$quiz = $quizStatement->fetch(PDO::FETCH_ASSOC);

if (!$quiz || !canManageModule($pdo, (int) $quiz["module_id"], (int) $_SESSION["user_id"], $userRole)) {
    http_response_code(403);
    exit("Vous n'avez pas accès à ce quiz.");
}

$title = $quiz["title"];
$passingScore = $quiz["passing_score"];
$randomOrder = (int) $quiz["random_order"];
$isPublished = (int) $quiz["is_published"];
$errors = [];

$questionStatement = $pdo->prepare("SELECT id, question_text, question_type, points, explanation FROM questions WHERE quiz_id = ? ORDER BY position, id");
$questionStatement->execute([$quizId]);
$questions = $questionStatement->fetchAll(PDO::FETCH_ASSOC);
$answerStatement = $pdo->prepare("SELECT question_id, answer_text, is_correct FROM question_answers WHERE question_id IN (SELECT id FROM questions WHERE quiz_id = ?) ORDER BY position, id");
$answerStatement->execute([$quizId]);
$answersByQuestion = [];
foreach ($answerStatement->fetchAll(PDO::FETCH_ASSOC) as $answer) $answersByQuestion[$answer["question_id"]][] = $answer;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $questionId = filter_input(INPUT_POST, "question_id", FILTER_VALIDATE_INT);

    if ($questionId) {
        $questionText = trim($_POST["question_text"] ?? "");
        $questionType = $_POST["question_type"] ?? "";
        $questionPoints = trim($_POST["question_points"] ?? "");
        $questionExplanation = trim($_POST["question_explanation"] ?? "");
        $questionAnswers = array_pad(array_map("trim", array_slice(is_array($_POST["answers"] ?? null) ? $_POST["answers"] : [], 0, 4)), 4, "");
        $correctAnswers = array_map("intval", is_array($_POST["correct_answers"] ?? null) ? $_POST["correct_answers"] : []);

        if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) $errors[] = "La session du formulaire a expiré.";
        if ($questionText === "") $errors[] = "La question est obligatoire.";
        if (!in_array($questionType, ["single_choice", "multiple_choice", "true_false", "short_answer"], true)) $errors[] = "Le type de question est invalide.";
        if (!is_numeric($questionPoints) || (float) $questionPoints <= 0) $errors[] = "Les points sont invalides.";
        if ($questionType === "true_false") { $questionAnswers = ["Vrai", "Faux", "", ""]; }
        if ($questionType === "short_answer") { $questionAnswers = [trim($questionAnswers[0]), "", "", ""]; $correctAnswers = $questionAnswers[0] === "" ? [] : [0]; }
        if ($questionType !== "short_answer" && count(array_filter($questionAnswers)) < 2) $errors[] = "Ajoutez au moins deux réponses.";
        $correctAnswers = array_values(array_unique(array_filter($correctAnswers, static function ($index) use ($questionAnswers) { return isset($questionAnswers[$index]) && $questionAnswers[$index] !== ""; })));
        if (!$correctAnswers) $errors[] = "Sélectionnez une bonne réponse.";
        if ($questionType === "single_choice" && count($correctAnswers) > 1) $errors[] = "Une seule bonne réponse est possible.";

        $questionCheck = $pdo->prepare("SELECT id FROM questions WHERE id = ? AND quiz_id = ?");
        $questionCheck->execute([$questionId, $quizId]);
        if (!$questionCheck->fetch()) $errors[] = "Question invalide.";

        if (!$errors) {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE questions SET question_text = ?, question_type = ?, points = ?, explanation = ? WHERE id = ? AND quiz_id = ?")->execute([$questionText, $questionType, (float) $questionPoints, $questionExplanation !== "" ? $questionExplanation : null, $questionId, $quizId]);
            $pdo->prepare("DELETE FROM question_answers WHERE question_id = ?")->execute([$questionId]);
            $insertAnswer = $pdo->prepare("INSERT INTO question_answers (question_id, answer_text, is_correct, position) VALUES (?, ?, ?, ?)");
            foreach ($questionAnswers as $answerPosition => $answer) if ($answer !== "") $insertAnswer->execute([$questionId, $answer, in_array($answerPosition, $correctAnswers, true) ? 1 : 0, $answerPosition + 1]);
            $pdo->commit();
            header("Location: quiz-edit.php?id=" . $quizId . "&success=question");
            exit;
        }
    }

    if ($questionId) {
        $title = $quiz["title"];
        $passingScore = $quiz["passing_score"];
        $randomOrder = (int) $quiz["random_order"];
        $isPublished = (int) $quiz["is_published"];
    } else {
    $title = trim($_POST["title"] ?? "");
    $passingScore = trim($_POST["passing_score"] ?? "");
    $randomOrder = isset($_POST["random_order"]) ? 1 : 0;
    $isPublished = isset($_POST["is_published"]) ? 1 : 0;

    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        $errors[] = "La session du formulaire a expiré.";
    } elseif ($title === "" || mb_strlen($title) > 200) {
        $errors[] = "Le titre est obligatoire et ne doit pas dépasser 200 caractères.";
    } elseif (!is_numeric($passingScore) || (float) $passingScore < 0 || (float) $passingScore > 100) {
        $errors[] = "Le score doit être compris entre 0 et 100.";
    }

    if (!$errors) {
        $update = $pdo->prepare(
            "UPDATE quizzes
             SET title = ?, passing_score = ?, random_order = ?, is_published = ?
             WHERE id = ?"
        );
        $update->execute([$title, (float) $passingScore, $randomOrder, $isPublished, $quizId]);
        auditLog($pdo, (int) $_SESSION["user_id"], "quiz_updated", "quiz", (int) $quizId, null, ["title" => $title]);
        header("Location: quizzes.php?success=updated");
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
    <title>Modifier le quiz</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">
</head>
<body class="admin-body<?= ($_SESSION["user_role"] ?? "") === "teacher" ? " teacher-view" : "" ?>">
<div class="admin-layout">
    <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('quizzes/quiz-edit.php'); ?>

    <main class="admin-main quiz-page">
        <header class="admin-topbar page-header">
            <div>
                <h1>Modifier le quiz</h1>
                <p><?= e($quiz["course_title"]) ?> / <?= e($quiz["module_title"]) ?></p>
            </div>
            <a href="quizzes.php" class="btn btn-secondary">Retour</a>
        </header>

        <?php if (($_GET["success"] ?? "") === "question"): ?>
            <div class="alert alert-success quiz-alert">Question enregistrée avec succès.</div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger quiz-alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <section class="form-container">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                <div class="form-group">
                    <label for="title">Titre du quiz *</label>
                    <input type="text" id="title" name="title" value="<?= e($title) ?>" maxlength="200" required>
                </div>
                <div class="form-group">
                    <label>Module</label>
                    <input type="text" value="<?= e($quiz["module_title"]) ?>" disabled>
                </div>
                <div class="form-group">
                    <label for="passing_score">Score de réussite (%) *</label>
                    <input type="number" id="passing_score" name="passing_score" value="<?= e($passingScore) ?>" min="0" max="100" step="0.01" required>
                </div>
                <div class="form-group">
                    <label><input type="checkbox" name="random_order" value="1" <?= $randomOrder ? "checked" : "" ?>> Afficher les questions dans un ordre aléatoire</label>
                </div>
                <div class="form-group">
                    <label><input type="checkbox" name="is_published" value="1" <?= $isPublished ? "checked" : "" ?>> Publier le quiz</label>
                </div>
                <div class="form-actions">
                    <a href="quizzes.php" class="btn btn-secondary">Annuler</a>
                    <button type="submit" class="btn btn-primary">Enregistrer les modifications</button>
                </div>
            </form>
        </section>

        <section class="form-container quiz-question-list">
            <h2>Questions et réponses</h2>
            <?php foreach ($questions as $question): ?>
                <form method="POST" class="quiz-question-card">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                    <input type="hidden" name="question_id" value="<?= (int) $question["id"] ?>">
                    <div class="form-group"><label>Question</label><textarea name="question_text" rows="3" required><?= e($question["question_text"]) ?></textarea></div>
                    <div class="quiz-form-grid">
                        <div class="form-group"><label>Type</label><select name="question_type" class="question-type"><option value="single_choice" <?= $question["question_type"] === "single_choice" ? "selected" : "" ?>>Choix unique</option><option value="multiple_choice" <?= $question["question_type"] === "multiple_choice" ? "selected" : "" ?>>Choix multiple</option><option value="true_false" <?= $question["question_type"] === "true_false" ? "selected" : "" ?>>Vrai / Faux</option><option value="short_answer" <?= $question["question_type"] === "short_answer" ? "selected" : "" ?>>Réponse courte</option></select></div>
                        <div class="form-group"><label>Points</label><input type="number" name="question_points" value="<?= e($question["points"]) ?>" min="0.01" step="0.01" required></div>
                    </div>
                    <div class="question-answers"><label>Réponses</label><p class="quiz-help answer-help">Cochez les réponses correctes.</p><?php foreach (array_pad($answersByQuestion[$question["id"]] ?? [], 4, ["answer_text" => "", "is_correct" => 0]) as $answerIndex => $answer): ?><div class="quiz-answer-row"><input type="checkbox" name="correct_answers[]" value="<?= $answerIndex ?>" <?= $answer["is_correct"] ? "checked" : "" ?>><input type="text" name="answers[]" value="<?= e($answer["answer_text"]) ?>"></div><?php endforeach; ?></div>
                    <div class="form-group"><label>Explication</label><textarea name="question_explanation" rows="2"><?= e($question["explanation"] ?? "") ?></textarea></div>
                    <button class="btn btn-primary">Enregistrer cette question</button>
                </form>
            <?php endforeach; ?>
        </section>
    </main>
</div>
<script>
document.querySelectorAll(".quiz-question-card").forEach(function(card){
    var select=card.querySelector(".question-type"), rows=card.querySelectorAll(".quiz-answer-row"), inputs=card.querySelectorAll("input[type=text]"), checks=card.querySelectorAll("input[type=checkbox]"), help=card.querySelector(".answer-help");
    function update(){var type=select.value;rows.forEach(function(row,i){row.style.display=type==="short_answer"?(i===0?"flex":"none"):(type==="true_false"?(i<2?"flex":"none"):"flex")});if(type==="true_false"){inputs[0].value="Vrai";inputs[1].value="Faux";inputs[0].readOnly=true;inputs[1].readOnly=true;help.textContent="Cochez la bonne réponse."}else if(type==="short_answer"){inputs.forEach(function(input,i){input.readOnly=i!==0;input.placeholder=i===0?"Réponse attendue":""});checks.forEach(function(check){check.style.display="none"});help.textContent="Saisissez la réponse attendue."}else{inputs.forEach(function(input,i){input.readOnly=false;input.placeholder="Réponse "+(i+1)});checks.forEach(function(check){check.style.display="inline-block"});help.textContent="Cochez les réponses correctes."}}select.addEventListener("change",update);update();
});
</script>
<script src="../../assets/js/teacher-navigation.js?v=20260925b"></script>
<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script></body>
</html>





