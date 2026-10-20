<?php
require_once __DIR__ . '/../../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION["user_id"])) { header("Location: ../../auth/login.php"); exit; }
$userRole = $_SESSION["user_role"] ?? "";
if ($userRole !== "admin" && $userRole !== "teacher") { http_response_code(403); exit("Accès interdit."); }
require_once "../../config/database.php";
require_once "../../config/content_access.php";
function e($value) { return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8"); }

$examId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
if (!$examId || $examId < 1) { header("Location: exams.php"); exit; }

$statement = $pdo->prepare(
    "SELECT e.*, c.title AS course_title, c.teacher_id
     FROM exams e INNER JOIN courses c ON c.id = e.course_id
     WHERE e.id = ? LIMIT 1"
);
$statement->execute([$examId]);
$exam = $statement->fetch(PDO::FETCH_ASSOC);
if (!$exam || !canManageCourse($pdo, (int) $exam["course_id"], (int) $_SESSION["user_id"], $userRole)) {
    http_response_code(403);
    exit("Vous n'avez pas accès à cet examen.");
}

$questionStatement = $pdo->prepare(
    "SELECT id, question_text, question_type, points, explanation
     FROM questions WHERE exam_id = ? ORDER BY position ASC, id ASC"
);
$questionStatement->execute([$examId]);
$questions = $questionStatement->fetchAll(PDO::FETCH_ASSOC);

$answerStatement = $pdo->prepare(
    "SELECT question_id, answer_text, is_correct
     FROM question_answers
     WHERE question_id IN (SELECT id FROM questions WHERE exam_id = ?)
     ORDER BY position ASC, id ASC"
);
$answerStatement->execute([$examId]);
$answersByQuestion = [];
foreach ($answerStatement->fetchAll(PDO::FETCH_ASSOC) as $answer) {
    $answersByQuestion[$answer["question_id"]][] = $answer;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voir l'examen</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">
</head>
<body class="admin-body<?= ($_SESSION["user_role"] ?? "") === "teacher" ? " teacher-view" : "" ?>">
<div class="admin-layout">
    <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('exams/exam-view.php'); ?>

    <main class="admin-main quiz-page">
        <header class="admin-topbar page-header">
            <div>
                <h1><?= e($exam["title"]) ?></h1>
                <p><?= e($exam["course_title"]) ?></p>
            </div>
            <div class="action-buttons">
                <a href="exam-edit.php?id=<?= (int) $examId ?>" class="btn btn-primary">Modifier</a>
                <a href="exams.php" class="btn btn-secondary">Retour</a>
            </div>
        </header>

        <section class="quiz-view-summary">
            <div><strong>Score requis</strong><span><?= e($exam["passing_score"]) ?>%</span></div>
            <div><strong>Tentatives</strong><span><?= e($exam["max_attempts"]) ?></span></div>
            <div><strong>Durée</strong><span><?= $exam["duration_minutes"] ? e($exam["duration_minutes"]) . " min" : "Sans limite" ?></span></div>
            <div><strong>Statut</strong><span class="status-badge <?= $exam["is_published"] ? "status-active" : "status-inactive" ?>"><?= $exam["is_published"] ? "Publié" : "Brouillon" ?></span></div>
        </section>

        <section class="quiz-question-list">
            <h2>Questions (<?= count($questions) ?>)</h2>
            <?php if (!$questions): ?>
                <p class="quiz-empty">Aucune question.</p>
            <?php else: ?>
                <?php foreach ($questions as $number => $question): ?>
                    <article class="quiz-question-card">
                        <div class="quiz-question-heading">
                            <h3><?= $number + 1 ?>. <?= e($question["question_text"]) ?></h3>
                            <span class="status-badge status-active"><?= e($question["question_type"]) ?> · <?= e($question["points"]) ?> point(s)</span>
                        </div>
                        <ul class="quiz-saved-answers">
                            <?php foreach ($answersByQuestion[$question["id"]] ?? [] as $answer): ?>
                                <li class="<?= $answer["is_correct"] ? "is-correct" : "" ?>">
                                    <?= $answer["is_correct"] ? "✓" : "○" ?> <?= e($answer["answer_text"]) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($question["explanation"]): ?>
                            <p class="quiz-explanation"><strong>Explication :</strong> <?= e($question["explanation"]) ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
</div>
<script src="../../assets/js/teacher-navigation.js?v=20260925b"></script>
<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script></body>
</html>





