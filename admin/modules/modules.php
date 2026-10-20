<?php
require_once __DIR__ . '/../../config/i18n.php';// ============================================================
// * GAKU LEARNING - ADMIN MODULE DIRECTORY
// ============================================================
// ! RESTRICTED ACCESS: Only authenticated Admins and Teachers can access.
// ? Purpose: Lists modules for a chosen course and handles positional reordering.
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
// ? Validates and casts optional course_id parameter from GET request.
// ------------------------------------------------------------
$courseId = filter_input(INPUT_GET, "course_id", FILTER_VALIDATE_INT);


// ------------------------------------------------------------
// * DATABASE QUERY: FETCH PERMITTED COURSES
// ? Limits options for teachers to owned courses, admins get all.
// ------------------------------------------------------------
$courseList = ($_SESSION["user_role"] ?? "") === "teacher"
    ? $pdo->prepare("SELECT id, title FROM courses WHERE EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id = courses.id AND ct.teacher_id = ?) ORDER BY title ASC")
    : $pdo->prepare("SELECT id, title FROM courses ORDER BY title ASC");

if (($_SESSION["user_role"] ?? "") === "teacher") {
    $courseList->execute([(int) $_SESSION["user_id"]]);
} else {
    $courseList->execute();
}

$courses = $courseList->fetchAll();


// ------------------------------------------------------------
// * INITIALIZE MODULE AND COURSE VARIABLES
// ------------------------------------------------------------
$course = null;
$modules = [];


// ------------------------------------------------------------
// * DATABASE QUERY: FETCH MODULES FOR SELECTED COURSE
// ? Validates course permission and queries modules ordered by position.
// ------------------------------------------------------------
if ($courseId && $courseId > 0) {

    $courseStatement = $pdo->prepare("SELECT id, title FROM courses WHERE id = ? LIMIT 1");
    $courseStatement->execute([$courseId]);
    $course = $courseStatement->fetch();

    // ! Authorization Check: Verify user can manage this specific course
    if (!$course || !canManageCourse($pdo, (int) $courseId, (int) $_SESSION["user_id"], $_SESSION["user_role"])) {
        header("Location: modules.php");
        exit;
    }

    // * Retrieve sequence-sorted modules
    $moduleStatement = $pdo->prepare("SELECT id, title, description, position FROM modules WHERE course_id = ? ORDER BY position ASC");
    $moduleStatement->execute([$courseId]);
    $modules = $moduleStatement->fetchAll();

}

?>

<!DOCTYPE html>

<html lang="fr">

<head>

    <!-- * Basic page metadata and responsive viewport settings -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Modules - Administration</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">

    <!-- * Main administrative layout stylesheet import -->
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">

</head>

<body class="admin-body<?= ($_SESSION["user_role"] ?? "") === "teacher" ? " teacher-view" : "" ?>">

    <div class="admin-layout">

        <!-- ==========================================================
             * ADMINISTRATIVE SIDEBAR NAVIGATION
             ========================================================== -->

        <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('modules/modules.php'); ?>


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
                        Modules
                    </h1>

                    <p>
                        <?= $course ? "Structure de " . htmlspecialchars($course["title"]) : "Organiser les contenus des formations" ?>
                    </p>

                </div>

                <!-- ? Contextual Action: Add new module button when course is selected -->
                <?php if ($course): ?>

                    <a href="module_create.php?course_id=<?= (int) $courseId ?>" class="btn btn-primary">
                        + Ajouter un module
                    </a>

                <?php endif; ?>

            </header>


            <!-- ==========================================================
                 * CONTENT MANAGEMENT SECTION
                 ========================================================== -->

            <section class="dashboard-section">

                <!-- * Filter Form: Course Selection Dropdown -->
                <form method="GET" class="users-filters">

                    <select name="course_id" required>

                        <option value="">
                            Choisir une formation
                        </option>

                        <!-- * Populate course options dynamically -->
                        <?php foreach ($courses as $item): ?>

                            <option
                                value="<?= (int) $item["id"] ?>"
                                <?= (int) $courseId === (int) $item["id"] ? "selected" : "" ?>
                            >
                                <?= htmlspecialchars($item["title"]) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                    <button type="submit" class="admin-button">
                        Afficher les modules
                    </button>

                </form>


                <!-- * Modules Table: Rendered if a course has been selected -->
                <?php if ($course): ?>

                    <div class="admin-table-container">

                        <table class="admin-table">

                            <thead>

                                <tr>
                                    <th>Ordre</th>
                                    <th>Module</th>
                                    <th>Description</th>
                                    <th>Réorganiser</th>
                                    <th>Actions</th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php if ($modules): ?>

                                    <?php foreach ($modules as $index => $module): ?>

                                        <!-- * Module Data Row -->
                                        <tr>

                                            <!-- * Sequential Position Index -->
                                            <td>
                                                #<?= (int) $module["position"] ?>
                                            </td>

                                            <!-- * Sanitized Module Title -->
                                            <td>
                                                <strong>
                                                    <?= htmlspecialchars($module["title"]) ?>
                                                </strong>
                                            </td>

                                            <!-- * Description or Empty Fallback -->
                                            <td>
                                                <?= htmlspecialchars($module["description"] ?: "-") ?>
                                            </td>

                                            <!-- * Reordering Controls -->
                                            <td class="module-reorder">

                                                <!-- ? Action: Shift Position Up -->
                                                <form method="POST" action="module_reorder.php">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">
                                                    <input type="hidden" name="module_id" value="<?= (int) $module["id"] ?>">
                                                    <input type="hidden" name="direction" value="up">
                                                    <button type="submit" <?= $index === 0 ? "disabled" : "" ?> title="Monter">↑</button>
                                                </form>

                                                <!-- ? Action: Shift Position Down -->
                                                <form method="POST" action="module_reorder.php">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">
                                                    <input type="hidden" name="module_id" value="<?= (int) $module["id"] ?>">
                                                    <input type="hidden" name="direction" value="down">
                                                    <button type="submit" <?= $index === count($modules) - 1 ? "disabled" : "" ?> title="Descendre">↓</button>
                                                </form>

                                            </td>

                                            <!-- * Management Action Links & Forms -->
                                            <td class="company-actions">

                                                <a href="../lessons/lessons.php?module_id=<?= (int) $module["id"] ?>" class="table-action">
                                                    Leçons
                                                </a>

                                                <a href="module_edit.php?id=<?= (int) $module["id"] ?>" class="table-action">
                                                    Modifier
                                                </a>

                                                <!-- ! Action: Delete Module Form -->
                                                <form method="POST" action="module_delete.php" onsubmit="return confirm('Supprimer ce module ?');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">
                                                    <input type="hidden" name="module_id" value="<?= (int) $module["id"] ?>">
                                                    <button type="submit" class="table-action table-action-danger">
                                                        Supprimer
                                                    </button>
                                                </form>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                <?php else: ?>

                                    <!-- ! EMPTY STATE: Returned when selected course has no modules -->
                                    <tr>
                                        <td colspan="5">
                                            Aucun module pour cette formation.
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



