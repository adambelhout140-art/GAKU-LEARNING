<?php
require_once __DIR__ . '/../../config/i18n.php';// ============================================================
// * GAKU LEARNING - ADMIN MODULE EDIT
// ============================================================
// ! RESTRICTED ACCESS: Only authenticated Admins and Teachers can access.
// ? Purpose: Loads a single module, verifies permissions, and updates its details or sequence position safely.
// ============================================================


// ------------------------------------------------------------
// * SESSION HANDLING
// ? Starts or resumes session to verify identity and retrieve CSRF secrets.
// ------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


// ------------------------------------------------------------
// * FILE DEPENDENCIES
// ! Configures database PDO connection and access verification helpers.
// ------------------------------------------------------------
require_once "../../config/database.php";
require_once "../../config/audit.php";
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
// ! Denies access to users who are neither admins nor teachers.
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
// ? Validates and casts mandatory module id parameter from GET request.
// ------------------------------------------------------------
$moduleId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$moduleId || $moduleId < 1) {
    header("Location: modules.php");
    exit;
}


// ------------------------------------------------------------
// * DATABASE QUERY: FETCH MODULE & PARENT COURSE TITLE
// ? Joins module record with its parent course for display and permission check.
// ------------------------------------------------------------
$statement = $pdo->prepare("SELECT modules.*, courses.title AS course_title FROM modules INNER JOIN courses ON courses.id = modules.course_id WHERE modules.id = ? LIMIT 1");
$statement->execute([$moduleId]);
$module = $statement->fetch();


// ------------------------------------------------------------
// * AUTHORIZATION & PERMISSION VERIFICATION
// ! Confirms module exists and user has rights to manage the parent course.
// ------------------------------------------------------------
if (!$module || !canManageCourse($pdo, (int) $module["course_id"], (int) $_SESSION["user_id"], $_SESSION["user_role"])) {
    header("Location: modules.php");
    exit;
}


// ------------------------------------------------------------
// * INITIALIZE FORM DATA AND ERROR STATE
// ------------------------------------------------------------
$title = $module["title"];
$description = $module["description"] ?? "";
$position = (int) $module["position"];
$error = "";


// ------------------------------------------------------------
// * FORM PROCESSING & UPDATE LOGIC
// ! Handles validation, CSRF verification, positional shifting, and database updates.
// ------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $title = trim($_POST["title"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $requestedPosition = trim($_POST["position"] ?? "");

    // * Validate Form Inputs & Anti-CSRF Token
    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        $error = "La session du formulaire a expiré.";
    } elseif ($title === "") {
        $error = "Le titre du module est obligatoire.";
    } elseif (!ctype_digit($requestedPosition) || (int) $requestedPosition < 1) {
        $error = "La position doit être un entier positif.";
    }

    // * Execute Database Update Strategy within Transaction
    if ($error === "") {

        $newPosition = (int) $requestedPosition;
        $pdo->beginTransaction();

        try {

            // ? Shift surrounding module positions if position index has changed
            if ($newPosition !== $position) {

                // Step 1: Temporarily unbind current module from position index using a negative key
                $temporary = -$moduleId;
                $pdo->prepare("UPDATE modules SET position = ? WHERE id = ?")->execute([$temporary, $moduleId]);

                if ($newPosition < $position) {
                    // Step 2a: Shift existing modules right (increment position) when moving upward
                    $shift = $pdo->prepare("UPDATE modules SET position = position + 1 WHERE course_id = ? AND position >= ? AND position < ?");
                    $shift->execute([$module["course_id"], $newPosition, $position]);
                } else {
                    // Step 2b: Shift existing modules left (decrement position) when moving downward
                    $shift = $pdo->prepare("UPDATE modules SET position = position - 1 WHERE course_id = ? AND position > ? AND position <= ?");
                    $shift->execute([$module["course_id"], $position, $newPosition]);
                }

            }

            // * Step 3: Write updated module properties to database
            $update = $pdo->prepare("UPDATE modules SET title = ?, description = ?, position = ? WHERE id = ? AND course_id = ?");
            $update->execute([$title, $description ?: null, $newPosition, $moduleId, $module["course_id"]]);
            auditLog($pdo, (int) $_SESSION["user_id"], "module_updated", "module", (int) $moduleId, null, ["title" => $title]);

            // * Commit transaction and redirect back to course modules
            $pdo->commit();
            header("Location: modules.php?course_id=" . $module["course_id"]);
            exit;

        } catch (Throwable $exception) {

            // ! Roll back all queries if database transaction fails
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = "Le module n'a pas pu être modifié.";

        }

    }

}

?>

<!DOCTYPE html>

<html lang="fr">

<head>

    <!-- * Basic page metadata and responsive viewport settings -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Modifier un module - Administration</title><link rel="icon" type="image/png" href="../../assets/images/logo-mark.png">

    <!-- * Main administrative layout stylesheet import -->
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">

</head>

<body class="admin-body<?= ($_SESSION["user_role"] ?? "") === "teacher" ? " teacher-view" : "" ?>">

    <div class="admin-layout">

        <!-- ==========================================================
             * ADMINISTRATIVE SIDEBAR NAVIGATION
             ========================================================== -->

        <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('modules/module_edit.php'); ?>


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
                        Modifier un module
                    </h1>

                    <p>
                        Formation : <?= htmlspecialchars($module["course_title"]) ?>
                    </p>

                </div>

                <a href="modules.php?course_id=<?= (int) $module["course_id"] ?>" class="btn btn-secondary">
                    Retour aux modules
                </a>

            </header>


            <!-- ==========================================================
                 * MODULE EDIT FORM SECTION
                 ========================================================== -->

            <section class="dashboard-section">

                <!-- ! ALERT MESSAGE: Display validation errors if form submission fails -->
                <?php if ($error): ?>

                    <div class="alert alert-danger">
                        <?= htmlspecialchars($error) ?>
                    </div>

                <?php endif; ?>


                <div class="form-card">

                    <div class="form-card-header">

                        <h2>
                            Informations du module
                        </h2>

                        <p>
                            Modifiez le titre, la description ou l'ordre.
                        </p>

                    </div>


                    <!-- * Edit Form -->
                    <form method="POST">

                        <!-- ? CSRF Verification Secret Input -->
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">

                        <!-- * Input Field: Module Title -->
                        <div class="form-group">

                            <label for="title">
                                Titre *
                            </label>

                            <input id="title" name="title" value="<?= htmlspecialchars($title) ?>" required>

                        </div>

                        <!-- * Input Field: Position Index -->
                        <div class="form-group">

                            <label for="position">
                                Position *
                            </label>

                            <input type="number" id="position" name="position" min="1" value="<?= (int) $position ?>" required>

                        </div>

                        <!-- * Input Field: Module Description -->
                        <div class="form-group">

                            <label for="description">
                                Description
                            </label>

                            <textarea id="description" name="description" rows="5"><?= htmlspecialchars($description) ?></textarea>

                        </div>

                        <!-- * Form Actions -->
                        <div class="form-actions">

                            <a href="modules.php?course_id=<?= (int) $module["course_id"] ?>" class="btn btn-secondary">
                                Annuler
                            </a>

                            <button type="submit" class="btn btn-primary">
                                Enregistrer
                            </button>

                        </div>

                    </form>

                </div>

            </section>

        </main>

    </div>

<script src="../../assets/js/teacher-navigation.js?v=20260925b"></script>
<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script></body>

</html>




