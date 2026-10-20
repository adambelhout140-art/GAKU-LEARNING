<?php
require_once __DIR__ . '/../../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION["user_id"])) { header("Location: ../../auth/login.php"); exit; }
$userRole = $_SESSION["user_role"] ?? "";
if ($userRole !== "admin" && $userRole !== "teacher") { http_response_code(403); exit("Accès interdit."); }
require_once "../../config/database.php";
require_once "../../config/audit.php";
require_once "../../config/content_access.php";
if (empty($_SESSION["csrf_token"])) $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
function e($value) { return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8"); }

$courses = $userRole === "admin"
    ? $pdo->query("SELECT id, title FROM courses ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC)
    : (function () use ($pdo) { $s = $pdo->prepare("SELECT id, title FROM courses WHERE EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id = courses.id AND ct.teacher_id = ?) ORDER BY title ASC"); $s->execute([$_SESSION["user_id"]]); return $s->fetchAll(PDO::FETCH_ASSOC); })();

$title = ""; $courseId = filter_input(INPUT_GET, "course_id", FILTER_VALIDATE_INT) ?: ""; $returnToContent = ($_GET["return_to"] ?? "") === "content"; $instructions = ""; $passingScore = "70"; $maxAttempts = "1"; $duration = ""; $randomOrder = 0; $published = 0; $errors = [];
$questions = [["question_text" => "", "question_type" => "single_choice", "points" => "1", "answers" => ["", "", "", ""], "correct_answers" => [], "explanation" => ""]];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $returnToContent = ($_POST["return_to"] ?? "") === "content";
    $title = trim($_POST["title"] ?? "");
    $courseId = filter_input(INPUT_POST, "course_id", FILTER_VALIDATE_INT);
    $instructions = trim($_POST["instructions"] ?? "");
    $passingScore = trim($_POST["passing_score"] ?? "");
    $maxAttempts = trim($_POST["max_attempts"] ?? "");
    $duration = trim($_POST["duration_minutes"] ?? "");
    $randomOrder = isset($_POST["random_order"]) ? 1 : 0;
    $published = isset($_POST["published"]) ? 1 : 0;

    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) $errors[] = "La session du formulaire a expiré.";
    if ($title === "" || mb_strlen($title) > 200) $errors[] = "Le titre est obligatoire et ne doit pas dépasser 200 caractères.";
    if (!$courseId || $courseId < 1 || !canManageCourse($pdo, (int) $courseId, (int) $_SESSION["user_id"], $userRole)) $errors[] = "La formation sélectionnée est invalide.";
    if (!is_numeric($passingScore) || (float) $passingScore < 0 || (float) $passingScore > 100) $errors[] = "Le score doit être compris entre 0 et 100.";
    if (!ctype_digit($maxAttempts) || (int) $maxAttempts < 1) $errors[] = "Le nombre de tentatives doit être un entier positif.";
    if ($duration !== "" && (!ctype_digit($duration) || (int) $duration < 1)) $errors[] = "La durée doit être un entier positif.";

    $postedQuestions = $_POST["questions"] ?? [];
    $questions = [];
    if (is_array($postedQuestions)) foreach ($postedQuestions as $postedQuestion) {
        if (!is_array($postedQuestion)) continue;
        $answers = $postedQuestion["answers"] ?? [];
        $correct = $postedQuestion["correct_answers"] ?? [];
        $questions[] = [
            "question_text" => trim($postedQuestion["question_text"] ?? ""),
            "question_type" => $postedQuestion["question_type"] ?? "single_choice",
            "points" => trim($postedQuestion["points"] ?? "1"),
            "answers" => array_pad(array_map("trim", array_slice(is_array($answers) ? $answers : [], 0, 4)), 4, ""),
            "correct_answers" => array_map("intval", is_array($correct) ? $correct : []),
            "explanation" => trim($postedQuestion["explanation"] ?? "")
        ];
    }
    if (!$questions) $errors[] = "Ajoutez au moins une question.";
    foreach ($questions as $index => &$question) {
        $number = $index + 1;
        if ($question["question_text"] === "") $errors[] = "La question {$number} est obligatoire.";
        if (!in_array($question["question_type"], ["single_choice", "multiple_choice", "true_false", "short_answer"], true)) $errors[] = "Le type de la question {$number} est invalide.";
        if (!is_numeric($question["points"]) || (float) $question["points"] <= 0 || (float) $question["points"] > 100) $errors[] = "Les points de la question {$number} sont invalides.";
        if ($question["question_type"] === "true_false") {
            $question["answers"] = ["Vrai", "Faux"]; $question["correct_answers"] = array_values(array_intersect($question["correct_answers"], [0, 1]));
        } elseif ($question["question_type"] === "short_answer") {
            $expected = trim($question["answers"][0] ?? ""); $question["answers"] = [$expected, "", "", ""]; $question["correct_answers"] = $expected === "" ? [] : [0];
            if ($expected === "") $errors[] = "Saisissez la réponse attendue pour la question {$number}.";
        } else {
            if (count(array_filter($question["answers"])) < 2) $errors[] = "La question {$number} doit avoir au moins deux réponses.";
            $question["correct_answers"] = array_values(array_unique(array_filter($question["correct_answers"], static function ($i) use ($question) { return isset($question["answers"][$i]) && $question["answers"][$i] !== ""; })));
        }
        if (!$question["correct_answers"]) $errors[] = "Sélectionnez une bonne réponse pour la question {$number}.";
        if ($question["question_type"] === "single_choice" && count($question["correct_answers"]) > 1) $errors[] = "La question {$number} ne peut avoir qu'une seule bonne réponse.";
    }
    unset($question);

    if (!$errors) {
        $existing = $pdo->prepare("SELECT id FROM exams WHERE course_id = ? LIMIT 1"); $existing->execute([$courseId]);
        if ($existing->fetch()) $errors[] = "Cette formation possède déjà un examen.";
    }
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $insert = $pdo->prepare("INSERT INTO exams (course_id, title, instructions, passing_score, max_attempts, duration_minutes, random_order, is_published) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $insert->execute([$courseId, $title, $instructions !== "" ? $instructions : null, (float) $passingScore, (int) $maxAttempts, $duration !== "" ? (int) $duration : null, $randomOrder, $published]);
            $examId = (int) $pdo->lastInsertId();
            auditLog($pdo, (int) $_SESSION["user_id"], "exam_created", "exam", $examId, null, ["title" => $title]);
            $position = 0;
            $questionInsert = $pdo->prepare("INSERT INTO questions (exam_id, question_text, question_type, points, explanation, position) VALUES (?, ?, ?, ?, ?, ?)");
            $answerInsert = $pdo->prepare("INSERT INTO question_answers (question_id, answer_text, is_correct, position) VALUES (?, ?, ?, ?)");
            foreach ($questions as $question) {
                $questionInsert->execute([$examId, $question["question_text"], $question["question_type"], (float) $question["points"], $question["explanation"] !== "" ? $question["explanation"] : null, ++$position]);
                $questionId = (int) $pdo->lastInsertId();
                foreach ($question["answers"] as $answerPosition => $answer) if ($answer !== "") $answerInsert->execute([$questionId, $answer, in_array($answerPosition, $question["correct_answers"], true) ? 1 : 0, $answerPosition + 1]);
            }
            $pdo->commit(); header("Location: " . ($returnToContent && $userRole === "admin"
                ? "../content.php?course_id=" . (int) $courseId
                : "exams.php?success=created")); exit;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = "Une erreur est survenue lors de la création de l'examen.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Créer un examen</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>"><link rel="stylesheet" href="../../assets/css/style.css?v=20260925e"></head>
<body class="admin-body<?= ($_SESSION["user_role"] ?? "") === "teacher" ? " teacher-view" : "" ?>"><div class="admin-layout">
<?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('exams/exam-create.php'); ?>
<main class="admin-main quiz-page"><header class="admin-topbar page-header"><div><h1>Créer un examen</h1><p>Évaluation finale d'une formation.</p></div><a href="exams.php" class="btn btn-secondary">Retour</a></header>
<?php if ($errors): ?><div class="alert alert-danger quiz-alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<section class="form-container"><form method="POST"><input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>"><input type="hidden" name="return_to" value="<?= $returnToContent ? "content" : "" ?>">
<div class="form-group"><label for="title">Titre *</label><input id="title" name="title" value="<?= e($title) ?>" maxlength="200" required></div>
<div class="form-group"><label for="course_id">Formation *</label><select id="course_id" name="course_id" required><option value="">-- Sélectionner --</option><?php foreach ($courses as $course): ?><option value="<?= (int) $course["id"] ?>" <?= (string) $courseId === (string) $course["id"] ? "selected" : "" ?>><?= e($course["title"]) ?></option><?php endforeach; ?></select></div>
<div class="form-group"><label for="instructions">Instructions</label><textarea id="instructions" name="instructions" rows="3"><?= e($instructions) ?></textarea></div>
<div class="quiz-form-grid"><div class="form-group"><label for="passing_score">Score de réussite (%) *</label><input type="number" id="passing_score" name="passing_score" value="<?= e($passingScore) ?>" min="0" max="100" step="0.01" required></div><div class="form-group"><label for="max_attempts">Tentatives maximum *</label><input type="number" id="max_attempts" name="max_attempts" value="<?= e($maxAttempts) ?>" min="1" step="1" required></div></div>
<div class="form-group"><label for="duration_minutes">Durée en minutes</label><input type="number" id="duration_minutes" name="duration_minutes" value="<?= e($duration) ?>" min="1" step="1" placeholder="Sans limite"></div>
<div class="form-group"><label><input type="checkbox" name="random_order" value="1" <?= $randomOrder ? "checked" : "" ?>> Mélanger les questions</label></div><div class="form-group"><label><input type="checkbox" name="published" value="1" <?= $published ? "checked" : "" ?>> Publier l'examen</label></div>
<section class="quiz-builder"><div class="quiz-builder-heading"><div><h2>Questions de l'examen</h2><p>Ajoutez les réponses, bonnes réponses, points et explications.</p></div><button type="button" class="btn btn-secondary" id="add-question">+ Ajouter une question</button></div><div id="question-list">
<?php foreach ($questions as $questionIndex => $question): ?><fieldset class="quiz-builder-question"><legend>Question <span class="question-number"><?= $questionIndex + 1 ?></span></legend><div class="form-group"><label>Question *</label><textarea name="questions[<?= $questionIndex ?>][question_text]" rows="3" required><?= e($question["question_text"]) ?></textarea></div><div class="quiz-form-grid"><div class="form-group"><label>Type *</label><select name="questions[<?= $questionIndex ?>][question_type]" class="question-type"><option value="single_choice">Choix unique</option><option value="multiple_choice">Choix multiple</option><option value="true_false">Vrai / Faux</option><option value="short_answer">Réponse courte</option></select></div><div class="form-group"><label>Points *</label><input type="number" name="questions[<?= $questionIndex ?>][points]" value="<?= e($question["points"]) ?>" min="0.01" max="100" step="0.01" required></div></div><div class="question-answers"><label>Réponses et bonnes réponses *</label><p class="quiz-help answer-help">Cochez les réponses correctes.</p><?php foreach ($question["answers"] as $answerIndex => $answer): ?><div class="quiz-answer-row"><input type="checkbox" name="questions[<?= $questionIndex ?>][correct_answers][]" value="<?= $answerIndex ?>"><input type="text" name="questions[<?= $questionIndex ?>][answers][]" value="<?= e($answer) ?>" placeholder="Réponse <?= $answerIndex + 1 ?>"></div><?php endforeach; ?></div><div class="form-group"><label>Explication</label><textarea name="questions[<?= $questionIndex ?>][explanation]" rows="2"></textarea></div><button type="button" class="btn btn-danger remove-question">Supprimer cette question</button></fieldset><?php endforeach; ?></div></section>
<div class="form-actions"><a href="exams.php" class="btn btn-secondary">Annuler</a><button type="submit" class="btn btn-primary">Créer l'examen</button></div></form></section></main></div>
<script>
(function(){const list=document.getElementById("question-list"),add=document.getElementById("add-question");function setType(q){const type=q.querySelector(".question-type").value,rows=q.querySelectorAll(".quiz-answer-row"),inputs=q.querySelectorAll(".quiz-answer-row input[type=text]"),checks=q.querySelectorAll(".quiz-answer-row input[type=checkbox]"),help=q.querySelector(".answer-help");rows.forEach(function(row,i){row.style.display=type==="short_answer"?i===0?"flex":"none":type==="true_false"?i<2?"flex":"none":"flex"});if(type==="true_false"){inputs[0].value="Vrai";inputs[1].value="Faux";inputs[0].readOnly=true;inputs[1].readOnly=true;help.textContent="Cochez la bonne réponse."}else if(type==="short_answer"){inputs.forEach(function(input,i){input.readOnly=i!==0;input.placeholder=i===0?"Réponse attendue":""});checks.forEach(function(check){check.style.display="none"});help.textContent="Saisissez la réponse attendue."}else{inputs.forEach(function(input,i){input.readOnly=false;input.placeholder="Réponse "+(i+1)});checks.forEach(function(check){check.style.display="inline-block"});help.textContent="Cochez les réponses correctes."}}function refresh(){list.querySelectorAll(".quiz-builder-question").forEach(function(q,i){q.querySelector(".question-number").textContent=i+1;q.querySelectorAll("[name]").forEach(function(f){f.name=f.name.replace(/questions\[\d+\]/,"questions["+i+"]")});setType(q)});list.querySelectorAll(".remove-question").forEach(function(b){b.disabled=list.children.length===1})}add.addEventListener("click",function(){const q=list.children[0].cloneNode(true);q.querySelectorAll("input[type=text],textarea").forEach(function(f){f.value=""});q.querySelectorAll("input[type=checkbox]").forEach(function(f){f.checked=false});list.appendChild(q);refresh()});list.addEventListener("click",function(e){if(e.target.classList.contains("remove-question")&&list.children.length>1){e.target.closest(".quiz-builder-question").remove();refresh()}});list.addEventListener("change",function(e){if(e.target.classList.contains("question-type"))setType(e.target.closest(".quiz-builder-question"))});refresh()}());
</script>
<script src="../../assets/js/teacher-navigation.js?v=20260925b"></script>
<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script></body></html>


