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
require_once "../../config/content_access.php";

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
    "SELECT q.*, m.id AS module_id, m.title AS module_title, c.title AS course_title
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

$questionStatement = $pdo->prepare(
    "SELECT id, question_text, question_type, points, explanation
     FROM questions WHERE quiz_id = ? ORDER BY position ASC, id ASC"
);
$questionStatement->execute([$quizId]);
$questions = $questionStatement->fetchAll(PDO::FETCH_ASSOC);

$answerStatement = $pdo->prepare(
    "SELECT question_id, answer_text, is_correct
     FROM question_answers
     WHERE question_id IN (SELECT id FROM questions WHERE quiz_id = ?)
     ORDER BY position ASC, id ASC"
);
$answerStatement->execute([$quizId]);
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
    <title>Voir le quiz - <?= e($quiz["title"]) ?></title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">
</head>
<body class="admin-body<?= ($_SESSION["user_role"] ?? "") === "teacher" ? " teacher-view" : "" ?>">
<div class="admin-layout">
    <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('quizzes/quiz-view.php'); ?>

    <main class="admin-main quiz-page">
        <header class="admin-topbar page-header">
            <div>
                <h1><?= e($quiz["title"]) ?></h1>
                <p><?= e($quiz["course_title"]) ?> / <?= e($quiz["module_title"]) ?></p>
            </div>
            <div class="action-buttons">
                <a href="quiz-edit.php?id=<?= (int) $quizId ?>" class="btn btn-primary">Modifier</a>
                <a href="quizzes.php" class="btn btn-secondary">Retour</a>
            </div>
        </header>

        <section class="quiz-view-summary">
            <div><strong>Score requis</strong><span><?= e($quiz["passing_score"]) ?>%</span></div>
            <div><strong>Tentatives</strong><span>Illimitées</span></div>
            <div><strong>Statut</strong><span class="status-badge <?= (int) $quiz["is_published"] === 1 ? "status-active" : "status-inactive" ?>"><?= (int) $quiz["is_published"] === 1 ? "Publié" : "Brouillon" ?></span></div>
        </section>

        <section class="quiz-question-list">
            <h2>Questions (<?= count($questions) ?>)</h2>
            <?php if (!$questions): ?>
                <p class="quiz-empty">Aucune question dans ce quiz.</p>
            <?php else: ?>
                <?php foreach ($questions as $number => $question): ?>
                    <article class="quiz-question-card">
                        <div class="quiz-question-heading">
                            <h3><?= $number + 1 ?>. <?= e($question["question_text"]) ?></h3>
                            <span class="status-badge status-active"><?= e($question["question_type"]) ?> · <?= e($question["points"]) ?> point(s)</span>
                        </div>
                        <ul class="quiz-saved-answers">
                            <?php foreach ($answersByQuestion[$question["id"]] ?? [] as $answer): ?>
                                <li class="<?= (int) $answer["is_correct"] === 1 ? "is-correct" : "" ?>">
                                    <?= (int) $answer["is_correct"] === 1 ? "✓" : "○" ?> <?= e($answer["answer_text"]) ?>
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





