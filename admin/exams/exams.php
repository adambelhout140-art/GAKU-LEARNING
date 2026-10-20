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

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$search = trim($_GET["search"] ?? "");
$params = [];
$sql = "SELECT e.id, e.course_id, e.title, e.passing_score, e.max_attempts,
               e.duration_minutes, e.is_published, c.title AS course_title,
               c.teacher_id
        FROM exams e
        INNER JOIN courses c ON c.id = e.course_id
        WHERE 1 = 1";

if ($userRole === "teacher") {
    $sql .= " AND EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id = c.id AND ct.teacher_id = :teacher_id)";
    $params[":teacher_id"] = $_SESSION["user_id"];
}
if ($search !== "") {
    $sql .= " AND (e.title LIKE :search OR c.title LIKE :search)";
    $params[":search"] = "%" . $search . "%";
}
$sql .= " ORDER BY c.title ASC, e.id DESC";
$statement = $pdo->prepare($sql);
$statement->execute($params);
$exams = $statement->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des examens</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">
</head>
<body class="admin-body<?= ($_SESSION["user_role"] ?? "") === "teacher" ? " teacher-view" : "" ?>">
<div class="admin-layout">
    <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('exams/exams.php'); ?>

    <main class="admin-main quiz-page">
        <header class="admin-topbar page-header">
            <div><h1>Examens</h1><p>Évaluations finales liées aux formations.</p></div>
            <a href="exam-create.php" class="btn btn-primary">+ Nouvel examen</a>
        </header>

        <?php if (isset($_GET["success"])): ?>
            <div class="alert alert-success quiz-alert">Opération effectuée avec succès.</div>
        <?php elseif (isset($_GET["error"])): ?>
            <div class="alert alert-danger quiz-alert">L'opération n'a pas pu être effectuée.</div>
        <?php endif; ?>

        <form method="GET" class="users-filters quiz-filters">
            <div><label for="search">Rechercher</label><input id="search" name="search" value="<?= e($search) ?>" placeholder="Examen ou formation..."></div>
            <div><button type="submit" class="btn btn-primary">Filtrer</button></div>
        </form>

        <div class="admin-table-container">
            <table class="admin-table">
                <thead><tr><th>Titre</th><th>Formation</th><th>Score</th><th>Tentatives</th><th>Durée</th><th>Statut</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (!$exams): ?>
                    <tr><td colspan="7" style="text-align:center;">Aucun examen trouvé.</td></tr>
                <?php else: foreach ($exams as $exam): ?>
                    <tr>
                        <td><?= e($exam["title"]) ?></td>
                        <td><?= e($exam["course_title"]) ?></td>
                        <td><?= e($exam["passing_score"]) ?>%</td>
                        <td><?= (int) $exam["max_attempts"] ?></td>
                        <td><?= $exam["duration_minutes"] ? e($exam["duration_minutes"]) . " min" : "Sans limite" ?></td>
                        <td><span class="status-badge <?= (int) $exam["is_published"] ? "status-active" : "status-inactive" ?>"><?= (int) $exam["is_published"] ? "Publié" : "Brouillon" ?></span></td>
                        <td><div class="action-buttons">
                            <a href="exam-view.php?id=<?= (int) $exam["id"] ?>" class="btn btn-secondary">Voir</a>
                            <a href="exam-edit.php?id=<?= (int) $exam["id"] ?>" class="btn btn-secondary">Modifier</a>
                            <form method="POST" action="exam-delete.php" class="quiz-delete-form">
                                <input type="hidden" name="exam_id" value="<?= (int) $exam["id"] ?>">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                                <button class="btn btn-danger" type="submit" onclick="return confirm('Supprimer cet examen ?');">Supprimer</button>
                            </form>
                        </div></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
<script src="../../assets/js/teacher-navigation.js?v=20260925b"></script>
<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script></body>
</html>




