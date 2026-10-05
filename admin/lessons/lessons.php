<?php
require_once __DIR__ . '/../../config/i18n.php';// ============================================================
// * GAKU LEARNING - ADMIN LESSONS DIRECTORY
// ============================================================
// ! RESTRICTED ACCESS: Only authenticated Admins and Teachers can access.
// ? Purpose: Lists lessons for a chosen module and handles positional reordering and management actions.
// ============================================================


// ------------------------------------------------------------
// * SESSION HANDLING
// ? Starts or resumes session to read authentication state and role data.
// ------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


// ------------------------------------------------------------
// * FILE DEPENDENCIES
// ! Configures database PDO connection and authorization helpers.
// ------------------------------------------------------------
require_once "../../config/database.php";
require_once "../../config/content_access.php";


// ------------------------------------------------------------
// * ACCESS CONTROL: AUTHENTICATION CHECK
// ! Bounces unauthenticated guests directly to the login panel.
// ------------------------------------------------------------
if (!isset($_SESSION["user_id"])) {
    header("Location: ../../auth/login.php");
    exit;
}


// ------------------------------------------------------------
// * ACCESS CONTROL: ROLE CHECK
// ! Rejects users without admin or teacher role assignments.
// ------------------------------------------------------------
if (!in_array($_SESSION["user_role"] ?? "", ["admin", "teacher"], true)) {
    header("Location: ../../index.php");
    exit;
}


// ------------------------------------------------------------
// * CSRF PROTECTION INITIALIZATION
// ? Generates a secure random token if one does not exist for POST forms.
// ------------------------------------------------------------
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}


// ------------------------------------------------------------
// * QUERY PARAMETER SANITIZATION
// ? Validates and casts optional module_id parameter from GET request.
// ------------------------------------------------------------
$moduleId = filter_input(INPUT_GET, "module_id", FILTER_VALIDATE_INT);


// ------------------------------------------------------------
// * DATABASE QUERY: FETCH PERMITTED MODULES
// ? Limits options for teachers to owned course modules, admins get all.
// ------------------------------------------------------------
$modulesStatement = ($_SESSION["user_role"] ?? "") === "teacher"
    ? $pdo->prepare("SELECT modules.id, modules.title, courses.title AS course_title FROM modules INNER JOIN courses ON courses.id = modules.course_id WHERE EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id = courses.id AND ct.teacher_id = ?) ORDER BY courses.title, modules.position")
    : $pdo->prepare("SELECT modules.id, modules.title, courses.title AS course_title FROM modules INNER JOIN courses ON courses.id = modules.course_id ORDER BY courses.title, modules.position");

if (($_SESSION["user_role"] ?? "") === "teacher") {
    $modulesStatement->execute([(int) $_SESSION["user_id"]]);
} else {
    $modulesStatement->execute();
}

$modules = $modulesStatement->fetchAll();


// ------------------------------------------------------------
// * INITIALIZE MODULE AND LESSON VARIABLES
// ------------------------------------------------------------
$module = null;
$lessons = [];


// ------------------------------------------------------------
// * DATABASE QUERY: FETCH LESSONS FOR SELECTED MODULE
// ? Validates module permission and queries lessons ordered by position.
// ------------------------------------------------------------
if ($moduleId && $moduleId > 0) {

    $moduleStatement = $pdo->prepare("SELECT modules.id, modules.title, modules.description, courses.title AS course_title FROM modules INNER JOIN courses ON courses.id = modules.course_id WHERE modules.id = ? LIMIT 1");
    $moduleStatement->execute([$moduleId]);
    $module = $moduleStatement->fetch();

    // ! Authorization Check: Verify user can manage this specific module
    if (!$module || !canManageModule($pdo, (int) $moduleId, (int) $_SESSION["user_id"], $_SESSION["user_role"])) {
        header("Location: lessons.php");
        exit;
    }

    // * Retrieve sequence-sorted lessons
    $lessonStatement = $pdo->prepare("SELECT id, title, description, estimated_minutes, is_published, position FROM lessons WHERE module_id = ? ORDER BY position ASC");
    $lessonStatement->execute([$moduleId]);
    $lessons = $lessonStatement->fetchAll();

}

?>

<!DOCTYPE html>

<html lang="fr">

<head>

    <!-- * Basic page metadata and responsive viewport settings -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Leçons - Administration</title><link rel="icon" type="image/png" href="../../assets/images/logo-mark.png">

    <!-- * Main administrative layout stylesheet import -->
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">

</head>

<body class="admin-body<?= ($_SESSION["user_role"] ?? "") === "teacher" ? " teacher-view" : "" ?>">

    <div class="admin-layout">

        <!-- ==========================================================
             * ADMINISTRATIVE SIDEBAR NAVIGATION
             ========================================================== -->

        <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('lessons/lessons.php'); ?>


        <!-- ==========================================================
             * MAIN ADMINISTRATIVE CONTENT
             ========================================================== -->

        <main class="admin-main">

            <!-- ==========================================================
                 * DASHBOARD TOP BAR
                 ========================================================== -->

            <header class="admin-topbar">

                <div>

                    <h1>
                        Leçons
                    </h1>

                    <p>
                        <?= $module ? htmlspecialchars($module["course_title"] . " / " . $module["title"]) : "Choisissez un module" ?>
                    </p>

                </div>

                <!-- ? Contextual Action: Add new lesson button when module is selected -->
                <?php if ($module): ?>

                    <a href="lesson_create.php?module_id=<?= (int) $moduleId ?>" class="btn btn-primary">
                        + Ajouter une leçon
                    </a>

                <?php endif; ?>

            </header>


            <!-- ==========================================================
                 * CONTENT MANAGEMENT SECTION
                 ========================================================== -->

            <section class="dashboard-section">

                <!-- * Filter Form: Module Selection Dropdown -->
                <form method="GET" class="users-filters">

                    <select name="module_id" required>

                        <option value="">
                            Choisir un module
                        </option>

                        <!-- * Populate module options dynamically -->
                        <?php foreach ($modules as $item): ?>

                            <option
                                value="<?= (int) $item["id"] ?>"
                                <?= (int) $moduleId === (int) $item["id"] ? "selected" : "" ?>
                            >
                                <?= htmlspecialchars($item["course_title"] . " - " . $item["title"]) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                    <button type="submit" class="admin-button">
                        Afficher les leçons
                    </button>

                </form>


                <!-- * Lessons Table: Rendered if a module has been selected -->
                <?php if ($module): ?>

                    <div class="admin-table-container">

                        <table class="admin-table">

                            <thead>

                                <tr>
                                    <th>Ordre</th>
                                    <th>Leçon</th>
                                    <th>Durée</th>
                                    <th>Statut</th>
                                    <th>Réorganiser</th>
                                    <th>Actions</th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php if ($lessons): ?>

                                    <?php foreach ($lessons as $index => $lesson): ?>

                                        <!-- * Lesson Data Row -->
                                        <tr>

                                            <!-- * Sequential Position Index -->
                                            <td>
                                                #<?= (int) $lesson["position"] ?>
                                            </td>

                                            <!-- * Sanitized Lesson Title & Description -->
                                            <td>
                                                <strong>
                                                    <?= htmlspecialchars($lesson["title"]) ?>
                                                </strong>
                                                <small class="company-meta">
                                                    <?= htmlspecialchars($lesson["description"] ?: "") ?>
                                                </small>
                                            </td>

                                            <!-- * Estimated Time Duration -->
                                            <td>
                                                <?= $lesson["estimated_minutes"] ? (int) $lesson["estimated_minutes"] . " min" : "-" ?>
                                            </td>

                                            <!-- * Publication Status Badge -->
                                            <td>
                                                <span class="status-badge <?= $lesson["is_published"] ? "status-active" : "status-inactive" ?>">
                                                    <?= $lesson["is_published"] ? "Publiée" : "Brouillon" ?>
                                                </span>
                                            </td>

                                            <!-- * Reordering Controls -->
                                            <td class="module-reorder">

                                                <!-- ? Action: Shift Position Up -->
                                                <form method="POST" action="lesson_reorder.php">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">
                                                    <input type="hidden" name="lesson_id" value="<?= (int) $lesson["id"] ?>">
                                                    <input type="hidden" name="direction" value="up">
                                                    <button type="submit" <?= $index === 0 ? "disabled" : "" ?>>↑</button>
                                                </form>

                                                <!-- ? Action: Shift Position Down -->
                                                <form method="POST" action="lesson_reorder.php">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">
                                                    <input type="hidden" name="lesson_id" value="<?= (int) $lesson["id"] ?>">
                                                    <input type="hidden" name="direction" value="down">
                                                    <button type="submit" <?= $index === count($lessons) - 1 ? "disabled" : "" ?>>↓</button>
                                                </form>

                                            </td>

                                            <!-- * Management Action Links & Forms -->
                                            <td class="company-actions">

                                                <a href="lesson_edit.php?id=<?= (int) $lesson["id"] ?>" class="table-action">
                                                    Modifier
                                                </a>

                                                <a href="lesson_checks.php?lesson_id=<?= (int) $lesson["id"] ?>" class="table-action">
                                                    Contrôle
                                                </a>

                                                <!-- ! Action: Delete Lesson Form -->
                                                <form method="POST" action="lesson_delete.php" onsubmit="return confirm('Supprimer cette leçon ?');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">
                                                    <input type="hidden" name="lesson_id" value="<?= (int) $lesson["id"] ?>">
                                                    <button type="submit" class="table-action table-action-danger">
                                                        Supprimer
                                                    </button>
                                                </form>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                <?php else: ?>

                                    <!-- ! EMPTY STATE: Returned when selected module has no lessons -->
                                    <tr>
                                        <td colspan="6">
                                            Aucune leçon pour ce module.
                                        </td>
                                    </tr>

                                <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </section>

        </main>

    </div>

<script src="../../assets/js/teacher-navigation.js?v=20260925b"></script>
<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script></body>

</html>



