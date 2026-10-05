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
            $options = array_values(array_filter(array_map("trim", $_POST["option_text"] ?? []), function ($o) {
                return $o !== "";
            }));
            $correctIndex = filter_input(INPUT_POST, "correct_index", FILTER_VALIDATE_INT);

            if ($questionText === "") {
                $errors[] = "La question est obligatoire.";
            } elseif (count($options) < 2) {
                $errors[] = "Ajoutez au moins deux options de réponse.";
            } elseif ($correctIndex === null || $correctIndex === false || !isset($options[$correctIndex])) {
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
                    $insertOption->execute([$questionId, $optionText, $index === $correctIndex ? 1 : 0, $index + 1]);
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
<title>Contrôle de compréhension - <?= e($lesson["title"]) ?></title><link rel="icon" type="image/png" href="../../assets/images/logo-mark.png">
<link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">
</head>
<body class="admin-body<?= ($_SESSION["user_role"] ?? "") === "teacher" ? " teacher-view" : "" ?>">
<div class="admin-layout">
<?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar("lessons/lesson_checks.php"); ?>
<main class="admin-main" style="padding:36px 40px;">
    <p><a href="lessons.php">? Retour aux leçons</a></p>
    <h1>Contrôle de compréhension</h1>
    <p class="student-muted">Leçon : <strong><?= e($lesson["title"]) ?></strong> — une ou deux questions — choix unique que l'étudiant doit réussir avant que la leçon puisse être marquée comme terminée.</p>

    <?php if ($errors): ?><div class="alert alert-danger"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <div class="form-card" style="padding:20px; margin-bottom:24px;">
        <h2>Questions existantes</h2>
        <?php if (!$questions): ?>
            <p class="student-muted">Aucune question pour l'instant. Sans question, la leçon se termine normalement (pas de contrôle requis).</p>
        <?php else: ?>
            <?php foreach ($questions as $number => $question): ?>
                <div style="border:1px solid var(--line); border-radius:8px; padding:14px; margin-bottom:12px;">
                    <strong><?= $number + 1 ?>. <?= e($question["question_text"]) ?></strong>
                    <ul>
                        <?php foreach ($optionsByQuestion[$question["id"]] ?? [] as $option): ?>
                            <li><?= e($option["option_text"]) ?> <?php if ($option["is_correct"]): ?><strong>(correcte)</strong><?php endif; ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="post" onsubmit="return confirm('Supprimer cette question ?');">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                        <input type="hidden" name="action" value="delete_question">
                        <input type="hidden" name="question_id" value="<?= (int) $question["id"] ?>">
                        <button type="submit" class="table-action table-action-danger">Supprimer</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="form-card" style="padding:20px;">
        <h2>Ajouter une question</h2>
        <form method="post" class="account-form" style="max-width:600px;">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
            <input type="hidden" name="action" value="add_question">
            <label for="question_text">Question</label>
            <textarea id="question_text" name="question_text" rows="2" required></textarea>
            <?php for ($i = 0; $i < 4; $i++): ?>
                <label>Option <?= $i + 1 ?><?= $i < 2 ? " *" : "" ?></label>
                <div style="display:flex; gap:8px; align-items:center;">
                    <input type="radio" name="correct_index" value="<?= $i ?>" <?= $i === 0 ? "required" : "" ?> title="Bonne réponse">
                    <input type="text" name="option_text[]" <?= $i < 2 ? "required" : "" ?> style="flex:1">
                </div>
            <?php endfor; ?>
            <small class="student-muted">Cochez le bouton radio à gauche de la bonne réponse.</small>
            <button type="submit" class="button primary-button">Ajouter la question</button>
        </form>
    </div>
</main>
<script src="../../assets/js/teacher-navigation.js?v=20260925b"></script>
</div>
</body>
</html>



