<?php
require_once __DIR__ . '/../../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../auth/login.php");
    exit;
}
if (!in_array($_SESSION["user_role"] ?? "", ["admin", "teacher"], true)) {
    header("Location: ../../index.php");
    exit;
}

require_once "../../config/database.php";
require_once "../../config/content_access.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$userId = (int) $_SESSION["user_id"];
$role = (string) $_SESSION["user_role"];
$lessonId = filter_input(INPUT_GET, "lesson_id", FILTER_VALIDATE_INT);

if (!$lessonId || !canManageLesson($pdo, $lessonId, $userId, $role)) {
    http_response_code(403);
    exit("Accès interdit à cette leçon.");
}

$lessonStatement = $pdo->prepare(
    "SELECT l.id, l.title, m.course_id FROM lessons l INNER JOIN modules m ON m.id = l.module_id WHERE l.id = ?"
);
$lessonStatement->execute([$lessonId]);
$lesson = $lessonStatement->fetch(PDO::FETCH_ASSOC);
if (!$lesson) {
    http_response_code(404);
    exit("Leçon introuvable.");
}

$errors = [];
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    if (empty($_SESSION["csrf_token"]) || !hash_equals($_SESSION["csrf_token"], $token)) {
        $errors[] = "La session du formulaire a expiré.";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "add_question") {
            $questionText = trim($_POST["question_text"] ?? "");
            $submittedOptions = $_POST["option_text"] ?? [];
            $options = is_array($submittedOptions)
                ? array_map("trim", $submittedOptions)
                : [];
            $correctIndex = filter_input(INPUT_POST, "correct_index", FILTER_VALIDATE_INT);

            if ($questionText === "") {
                $errors[] = "La question est obligatoire.";
            } elseif (count(array_filter($options, function ($option) {
                return $option !== "";
            })) < 2) {
                $errors[] = "Ajoutez au moins deux options de réponse.";
            } elseif (
                $correctIndex === null
                || $correctIndex === false
                || !isset($options[$correctIndex])
                || $options[$correctIndex] === ""
            ) {
                $errors[] = "Sélectionnez l'option correcte.";
            } else {
                $positionStatement = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM lesson_check_questions WHERE lesson_id = ?");
                $positionStatement->execute([$lessonId]);
                $position = (int) $positionStatement->fetchColumn();

                $insertQuestion = $pdo->prepare(
                    "INSERT INTO lesson_check_questions (lesson_id, question_text, position) VALUES (?, ?, ?)"
                );
                $insertQuestion->execute([$lessonId, $questionText, $position]);
                $questionId = (int) $pdo->lastInsertId();

                $insertOption = $pdo->prepare(
                    "INSERT INTO lesson_check_options (question_id, option_text, is_correct, position) VALUES (?, ?, ?, ?)"
                );
                foreach ($options as $index => $optionText) {
                    if ($optionText !== "") {
                        $insertOption->execute([$questionId, $optionText, $index === $correctIndex ? 1 : 0, $index + 1]);
                    }
                }
                $success = "Question ajoutée.";
            }
        } elseif ($action === "delete_question") {
            $questionId = filter_input(INPUT_POST, "question_id", FILTER_VALIDATE_INT);
            if ($questionId) {
                $delete = $pdo->prepare("DELETE FROM lesson_check_questions WHERE id = ? AND lesson_id = ?");
                $delete->execute([$questionId, $lessonId]);
                $success = "Question supprimée.";
            }
        }
    }
}

$questionStatement = $pdo->prepare("SELECT id, question_text, position FROM lesson_check_questions WHERE lesson_id = ? ORDER BY position, id");
$questionStatement->execute([$lessonId]);
$questions = $questionStatement->fetchAll(PDO::FETCH_ASSOC);
$optionsByQuestion = [];
if ($questions) {
    $ids = array_column($questions, "id");
    $placeholders = implode(",", array_fill(0, count($ids), "?"));
    $optionStatement = $pdo->prepare("SELECT id, question_id, option_text, is_correct FROM lesson_check_options WHERE question_id IN ($placeholders) ORDER BY position, id");
    $optionStatement->execute($ids);
    foreach ($optionStatement->fetchAll(PDO::FETCH_ASSOC) as $option) {
        $optionsByQuestion[$option["question_id"]][] = $option;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Contrôle de compréhension - <?= e($lesson["title"]) ?></title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
<link rel="stylesheet" href="../../assets/css/style.css?v=20261019-lesson-checks-form">
</head>
<body class="admin-body lesson-checks-page<?= ($_SESSION["user_role"] ?? "") === "teacher" ? " teacher-view" : "" ?>">
<div class="admin-layout">
<?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar("lessons/lesson_checks.php"); ?>
<main class="admin-main lesson-checks-main">
    <a class="lesson-checks-back" href="lessons.php"><span aria-hidden="true">&larr;</span> Retour aux leçons</a>
    <header class="lesson-checks-header">
        <div>
            <span class="lesson-checks-eyebrow">ÉVALUATION DE LA LEÇON</span>
            <h1>Contrôle de compréhension</h1>
            <p>Créez un court questionnaire pour vérifier les acquis de vos étudiants.</p>
        </div>
        <a class="button primary-button lesson-checks-add-link" href="#add-question">+ Ajouter une question</a>
    </header>

    <div class="lesson-checks-context">
        <span class="lesson-checks-context-icon" aria-hidden="true">i</span>
        <p><span>Leçon sélectionnée</span><strong><?= e($lesson["title"]) ?></strong></p>
        <span class="lesson-checks-count"><?= count($questions) ?> question<?= count($questions) === 1 ? "" : "s" ?></span>
    </div>

    <?php if ($errors): ?><div class="alert alert-danger"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <div class="lesson-checks-layout">
        <section class="form-card lesson-checks-list" aria-labelledby="lesson-checks-list-title">
            <div class="lesson-checks-section-heading">
                <div>
                    <span class="lesson-checks-eyebrow">VOTRE QUESTIONNAIRE</span>
                    <h2 id="lesson-checks-list-title">Questions existantes</h2>
                </div>
                <span class="lesson-checks-count"><?= count($questions) ?></span>
            </div>
            <?php if (!$questions): ?>
                <div class="lesson-checks-empty">
                    <span class="lesson-checks-empty-icon" aria-hidden="true">?</span>
                    <h3>Pas encore de question</h3>
                    <p>Ajoutez une question pour commencer à composer le contrôle de cette leçon.</p>
                    <a href="#add-question">Créer la première question <span aria-hidden="true">&rarr;</span></a>
                </div>
            <?php else: ?>
                <div class="lesson-checks-question-list">
                    <?php foreach ($questions as $number => $question): ?>
                        <article class="lesson-checks-question">
                            <div class="lesson-checks-question-top">
                                <span class="lesson-checks-question-number"><?= str_pad((string) ($number + 1), 2, "0", STR_PAD_LEFT) ?></span>
                                <strong><?= e($question["question_text"]) ?></strong>
                                <form method="post" onsubmit="return confirm('Supprimer cette question ?');">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                                    <input type="hidden" name="action" value="delete_question">
                                    <input type="hidden" name="question_id" value="<?= (int) $question["id"] ?>">
                                    <button type="submit" class="lesson-checks-delete" aria-label="Supprimer la question <?= $number + 1 ?>" title="Supprimer cette question">&times;</button>
                                </form>
                            </div>
                            <ul class="lesson-checks-options">
                                <?php foreach ($optionsByQuestion[$question["id"]] ?? [] as $option): ?>
                                    <li class="<?= $option["is_correct"] ? "is-correct" : "" ?>">
                                        <span class="lesson-checks-option-mark" aria-hidden="true"><?= $option["is_correct"] ? "✓" : "" ?></span>
                                        <span><?= e($option["option_text"]) ?></span>
                                        <?php if ($option["is_correct"]): ?><span class="lesson-checks-correct-label">Bonne réponse</span><?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="form-card lesson-checks-editor" id="add-question" aria-labelledby="add-question-title">
            <div class="lesson-checks-section-heading">
                <div>
                    <span class="lesson-checks-eyebrow">NOUVELLE QUESTION</span>
                    <h2 id="add-question-title">Ajouter au contrôle</h2>
                </div>
                <span class="lesson-checks-editor-icon" aria-hidden="true">+</span>
            </div>
            <p class="lesson-checks-editor-help">Rédigez une question, ajoutez au moins deux réponses et indiquez la bonne.</p>
            <form method="post" class="lesson-checks-form">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                <input type="hidden" name="action" value="add_question">
                <label for="question_text">Votre question <span aria-hidden="true">*</span></label>
                <textarea id="question_text" name="question_text" rows="3" required placeholder="Saisissez la question ici…"></textarea>
                <fieldset class="lesson-checks-answer-fieldset">
                    <legend>Choix de réponse <span>— sélectionnez la bonne réponse</span></legend>
                    <div class="lesson-checks-answer-list">
                        <?php for ($i = 0; $i < 4; $i++): ?>
                            <div class="lesson-checks-answer">
                                <input id="correct-answer-<?= $i ?>" type="radio" name="correct_index" value="<?= $i ?>" <?= $i === 0 ? "required" : "" ?>>
                                <label class="lesson-checks-answer-letter" for="correct-answer-<?= $i ?>" aria-label="Définir le choix <?= $i + 1 ?> comme bonne réponse"><?= chr(65 + $i) ?></label>
                                <input id="answer-text-<?= $i ?>" type="text" name="option_text[]" <?= $i < 2 ? "required" : "" ?> aria-label="Texte de la réponse <?= $i + 1 ?>" placeholder="Réponse <?= $i + 1 ?><?= $i < 2 ? " *" : "" ?>">
                                <span class="lesson-checks-answer-correct" aria-hidden="true">Bonne</span>
                            </div>
                        <?php endfor; ?>
                    </div>
                </fieldset>
                <div class="lesson-checks-form-footer">
                    <small><span aria-hidden="true">*</span> Deux réponses minimum. Les champs supplémentaires sont facultatifs.</small>
                    <button type="submit" class="button primary-button">Ajouter la question <span aria-hidden="true">&rarr;</span></button>
                </div>
            </form>
        </section>
    </div>
    <p class="lesson-checks-completion-note">Pour terminer la leçon, les étudiants doivent réussir toutes les questions du contrôle ou consulter toutes les pages des documents PDF associés.</p>
</main>
<script src="../../assets/js/teacher-navigation.js?v=20260925b"></script>
</div>
</body>
</html>
