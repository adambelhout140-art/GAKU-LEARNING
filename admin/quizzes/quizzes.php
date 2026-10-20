<?php
require_once __DIR__ . '/../../config/i18n.php';// ! ================================================================
// ! QUIZ MANAGEMENT - QUIZZES LIST
// ! ================================================================
// ! This page is used by the administrator and teachers to:
// !
// ! 1. Display all quizzes.
// ! 2. Show the course and module associated with each quiz.
// ! 3. Show the passing score.
// ! 4. Show the maximum number of attempts.
// ! 5. Show whether the quiz is published or not.
// ! 6. Allow authorized users to create a quiz.
// ! 7. Allow authorized users to edit a quiz.
// ! 8. Allow authorized users to delete a quiz.
// ! 9. Allow authorized users to manage the questions.
// ! ================================================================


// ! ================================================================
// ! 1. START THE SESSION
// ! ================================================================

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


// ! ================================================================
// ! 2. CHECK IF THE USER IS LOGGED IN
// ! ================================================================

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../auth/login.php");
    exit;
}


// ! ================================================================
// ! 3. GET THE CURRENT USER ROLE
// ! ================================================================

$user_role = $_SESSION["user_role"] ?? "";


// ! ================================================================
// ! 4. CHECK THE USER ROLE
// ! ================================================================

if ($user_role !== "admin" && $user_role !== "teacher") {
    http_response_code(403);
    die("Accès interdit.");
}


// ! ================================================================
// ! 5. LOAD THE DATABASE CONNECTION
// ! ================================================================

require_once "../../config/database.php";


// ! ================================================================
// ! 6. LOAD CONTENT ACCESS FUNCTIONS
// ! ================================================================

require_once "../../config/content_access.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}


// ! ================================================================
// ! 7. GET SEARCH VALUE
// ! ================================================================

$search = trim($_GET["search"] ?? "");


// ! ================================================================
// ! 8. GET MODULE FILTER
// ! ================================================================

$module_id = filter_input(
    INPUT_GET,
    "module_id",
    FILTER_VALIDATE_INT
);

if ($module_id === false) {
    $module_id = null;
}


// ! ================================================================
// ! 9. GET PUBLISHED STATUS FILTER
// ! ================================================================

$status = $_GET["status"] ?? "";
$allowed_statuses = ["", "published", "draft"];

if (!in_array($status, $allowed_statuses, true)) {
    $status = "";
}


// ! ================================================================
// ! 10. CHECK FOR EXISTING COLUMNS IN QUIZZES TABLE
// ! ================================================================
// ! To prevent SQL exceptions if column `published` or `random_order`
// ! does not exist in your database schema yet, we check the columns first.
// ! ================================================================

$columns_stmt = $pdo->query("SHOW COLUMNS FROM quizzes");
$existing_columns = $columns_stmt->fetchAll(PDO::FETCH_COLUMN);

$published_column = in_array("is_published", $existing_columns, true)
    ? "is_published"
    : (in_array("published", $existing_columns, true) ? "published" : null);
$has_random_order = in_array("random_order", $existing_columns, true);


// ! ================================================================
// ! 11. BUILD THE SQL QUERY
// ! ================================================================

$select_published = $published_column !== null
    ? "q.{$published_column} AS published"
    : "0 AS published";
$select_random_order = $has_random_order ? "q.random_order" : "0 AS random_order";

$sql = "
    SELECT
        q.id,
        q.module_id,
        q.title,
        q.passing_score,
        q.max_attempts,
        {$select_random_order},
        {$select_published},

        m.title AS module_title,

        c.id AS course_id,
        c.title AS course_title,
        c.teacher_id

    FROM quizzes q

    INNER JOIN modules m
        ON m.id = q.module_id

    INNER JOIN courses c
        ON c.id = m.course_id

    WHERE 1 = 1
";


// ! ================================================================
// ! 12. PREPARE SQL PARAMETERS
// ! ================================================================

$params = [];


// ! ================================================================
// ! 13. TEACHER ACCESS CONTROL FILTER
// ! ================================================================
// ! Teachers must only see quizzes belonging to their own courses.
// ! Admins can see all quizzes.
// ! ================================================================

if ($user_role === "teacher") {
    $sql .= " AND EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id = c.id AND ct.teacher_id = :teacher_id) ";
    $params[":teacher_id"] = $_SESSION["user_id"];
}


// ! ================================================================
// ! 14. SEARCH FILTER
// ! ================================================================

if ($search !== "") {
    $sql .= "
        AND (
            m.title LIKE :search
            OR c.title LIKE :search
        )
    ";
    $params[":search"] = "%" . $search . "%";
}


// ! ================================================================
// ! 15. MODULE FILTER
// ! ================================================================

if ($module_id !== null) {
    $sql .= " AND q.module_id = :module_id ";
    $params[":module_id"] = $module_id;
}


// ! ================================================================
// ! 16. PUBLISHED FILTER
// ! ================================================================

if ($published_column !== null) {
    if ($status === "published") {
        $sql .= " AND q.{$published_column} = 1 ";
    } elseif ($status === "draft") {
        $sql .= " AND q.{$published_column} = 0 ";
    }
}


// ! ================================================================
// ! 17. ORDER RESULTS
// ! ================================================================

$sql .= " ORDER BY c.title ASC, m.position ASC, q.id DESC ";


// ! ================================================================
// ! 18. EXECUTE THE QUERY
// ! ================================================================

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ! ================================================================
// ! 19. GET ALL MODULES FOR THE FILTER
// ! ================================================================

if ($user_role === "admin") {
    $module_stmt = $pdo->query("
        SELECT
            m.id,
            m.title,
            c.title AS course_title
        FROM modules m
        INNER JOIN courses c
            ON c.id = m.course_id
        ORDER BY c.title ASC, m.position ASC
    ");
    $modules = $module_stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $module_stmt = $pdo->prepare("
        SELECT
            m.id,
            m.title,
            c.title AS course_title
        FROM modules m
        INNER JOIN courses c
            ON c.id = m.course_id
        WHERE EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id = c.id AND ct.teacher_id = :teacher_id)
        ORDER BY c.title ASC, m.position ASC
    ");
    $module_stmt->execute([
        ":teacher_id" => $_SESSION["user_id"]
    ]);
    $modules = $module_stmt->fetchAll(PDO::FETCH_ASSOC);
}


// ! ================================================================
// ! 20. HELPER FOR HTML OUTPUT
// ! ================================================================

function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des quiz</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">
</head>
<body class="admin-body<?= ($_SESSION["user_role"] ?? "") === "teacher" ? " teacher-view" : "" ?>">

<div class="admin-layout">

    <!-- SIDEBAR -->
    <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('quizzes/quizzes.php'); ?>

    <!-- MAIN CONTENT -->
    <main class="admin-main quiz-page">

        <!-- PAGE HEADER -->
        <header class="admin-topbar page-header">
            <div>
                <h1>Quiz</h1>
                <p>Gestion des quiz associés aux modules.</p>
            </div>
            <div>
                <a href="quiz-create.php" class="btn btn-primary">+ Nouveau quiz</a>
            </div>
        </header>

        <?php if (isset($_GET["success"])): ?>
            <div class="alert alert-success quiz-alert">
                <?php if ($_GET["success"] === "created"): ?>Quiz créé avec succès.
                <?php elseif ($_GET["success"] === "updated"): ?>Quiz modifié avec succès.
                <?php elseif ($_GET["success"] === "deleted"): ?>Quiz supprimé avec succès.
                <?php endif; ?>
            </div>
        <?php elseif (isset($_GET["error"])): ?>
            <div class="alert alert-danger quiz-alert">L'opération n'a pas pu être effectuée.</div>
        <?php endif; ?>

        <!-- SEARCH / FILTER FORM -->
        <form method="GET" class="users-filters quiz-filters">
            <div>
                <label for="search">Rechercher</label>
                <input
                    type="text"
                    id="search"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Formation ou module..."
                >
            </div>

            <div>
                <label for="module_id">Module</label>
                <select id="module_id" name="module_id">
                    <option value="">Tous les modules</option>
                    <?php foreach ($modules as $module): ?>
                        <option
                            value="<?= (int) $module["id"] ?>"
                            <?= ($module_id == $module["id"]) ? "selected" : "" ?>
                        >
                            <?= e($module["course_title"]) ?> - <?= e($module["title"]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="status">Statut</label>
                <select id="status" name="status">
                    <option value="" <?= $status === "" ? "selected" : "" ?>>Tous</option>
                    <option value="published" <?= $status === "published" ? "selected" : "" ?>>Publiés</option>
                    <option value="draft" <?= $status === "draft" ? "selected" : "" ?>>Brouillons</option>
                </select>
            </div>

            <div>
                <button type="submit" class="btn btn-primary">Filtrer</button>
            </div>
        </form>

        <!-- QUIZZES TABLE -->
        <div class="admin-table-container">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Titre</th>
                        <th>Formation</th>
                        <th>Module</th>
                        <th>Score requis</th>
                        <th>Tentatives</th>
                        <th>Ordre aléatoire</th>
                        <th>Statut</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($quizzes)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center;">
                            Aucun quiz trouvé.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($quizzes as $quiz): ?>
                        <tr>
                            <!-- Quiz ID -->
                            <td><?= (int) $quiz["id"] ?></td>

                            <!-- Course -->
                            <td><?= e($quiz["title"]) ?></td>

                            <!-- Course -->
                            <td><?= e($quiz["course_title"]) ?></td>

                            <!-- Module -->
                            <td><?= e($quiz["module_title"]) ?></td>

                            <!-- Passing score -->
                            <td><?= e($quiz["passing_score"]) ?> %</td>

                            <!-- Maximum attempts -->
                            <td>
                                <?php if ($quiz["max_attempts"] === null): ?>
                                    Illimitées
                                <?php else: ?>
                                    <?= (int) $quiz["max_attempts"] ?>
                                <?php endif; ?>
                            </td>

                            <!-- Random order -->
                            <td>
                                <?= ((int) ($quiz["random_order"] ?? 0) === 1) ? 'Oui' : 'Non' ?>
                            </td>

                            <!-- Published status -->
                            <td>
                                <?php if ((int) ($quiz["published"] ?? 0) === 1): ?>
                                    <span class="status-badge status-active">Publié</span>
                                <?php else: ?>
                                    <span class="status-badge status-inactive">Brouillon</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="action-buttons">
                                    <a href="quiz-view.php?id=<?= (int) $quiz["id"] ?>" class="btn btn-secondary">Voir</a>
                                    <a href="quiz-edit.php?id=<?= (int) $quiz["id"] ?>" class="btn btn-secondary">Modifier</a>
                                    <form method="POST" action="quiz-delete.php" class="quiz-delete-form">
                                        <input type="hidden" name="quiz_id" value="<?= (int) $quiz["id"] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                                        <button type="submit" class="btn btn-danger" onclick="return confirm('Voulez-vous vraiment supprimer ce quiz ?');">Supprimer</button>
                                    </form>
                                </div>
                            </td>

                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>

</div>

<script src="../../assets/js/teacher-navigation.js?v=20260925b"></script>
<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script></body>
</html>



