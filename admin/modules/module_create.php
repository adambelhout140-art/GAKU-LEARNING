<?php
require_once __DIR__ . '/../../config/i18n.php';// ============================================================
// * GAKU LEARNING - ADMIN MODULE CREATION
// ============================================================
// ! RESTRICTED ACCESS: Only authenticated Admins and Teachers can access.
// ? Purpose: Creates a new module for an existing course and handles position sequence assignment safely.
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
// ? Validates and casts mandatory course_id parameter from GET request.
// ------------------------------------------------------------
$courseId = filter_input(INPUT_GET, "course_id", FILTER_VALIDATE_INT);
$returnToContent = ($_GET["return_to"] ?? "") === "content";

if (!$courseId || $courseId < 1) {
    header("Location: modules.php");
    exit;
}


// ------------------------------------------------------------
// * DATABASE QUERY: FETCH PARENT COURSE DETAILS
// ? Verifies existence of parent course prior to module creation.
// ------------------------------------------------------------
$courseStatement = $pdo->prepare("SELECT id, title FROM courses WHERE id = ? LIMIT 1");
$courseStatement->execute([$courseId]);
$course = $courseStatement->fetch();


// ------------------------------------------------------------
// * AUTHORIZATION & PERMISSION VERIFICATION
// ! Confirms course exists and user has rights to manage it.
// ------------------------------------------------------------
if (!$course || !canManageCourse($pdo, (int) $courseId, (int) $_SESSION["user_id"], $_SESSION["user_role"])) {
    header("Location: modules.php");
    exit;
}


// ------------------------------------------------------------
// * INITIALIZE FORM DATA AND ERROR STATE
// ------------------------------------------------------------
$title = "";
$description = "";
$position = "";
$error = "";


// ------------------------------------------------------------
// * FORM PROCESSING & CREATION LOGIC
// ! Handles validation, CSRF verification, auto-positioning, and module insertion.
// ------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $returnToContent = ($_POST["return_to"] ?? "") === "content";

    $title = trim($_POST["title"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $position = trim($_POST["position"] ?? "");

    // * Validate Form Inputs & Anti-CSRF Token
    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        $error = "La session du formulaire a expiré.";
    } elseif ($title === "") {
        $error = "Le titre du module est obligatoire.";
    } elseif ($position !== "" && (!ctype_digit($position) || (int) $position < 1)) {
        $error = "La position doit être un entier positif.";
    }

    // * Execute Database Insertion Strategy within Transaction
    if ($error === "") {

        // ? Auto-calculate sequence position index if left empty by user
        $position = $position === ""
            ? ((int) $pdo->query("SELECT COALESCE(MAX(position), 0) + 1 FROM modules WHERE course_id = " . (int) $courseId)->fetchColumn())
            : (int) $position;

        $pdo->beginTransaction();

        try {

            // * Step 1: Shift higher/equal existing module positions to preserve unique sequence
            $shift = $pdo->prepare("UPDATE modules SET position = position + 1 WHERE course_id = ? AND position >= ?");
            $shift->execute([$courseId, $position]);

            // * Step 2: Insert new module record into database
            $insert = $pdo->prepare("INSERT INTO modules (course_id, title, description, position) VALUES (?, ?, ?, ?)");
            $insert->execute([$courseId, $title, $description ?: null, $position]);
            auditLog($pdo, (int) $_SESSION["user_id"], "module_created", "module", (int) $pdo->lastInsertId(), null, ["title" => $title, "course_id" => $courseId]);

            // * Commit transaction and redirect back to course module list
            $pdo->commit();
            header("Location: " . ($returnToContent && ($_SESSION["user_role"] ?? "") === "admin"
                ? "../content.php?course_id=" . $courseId
                : "modules.php?course_id=" . $courseId));
            exit;

        } catch (Throwable $exception) {

            // ! Roll back all queries if database transaction fails
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = "Le module n'a pas pu être créé.";

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

    <title>Ajouter un module - Administration</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">

    <!-- * Main administrative layout stylesheet import -->
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">

</head>

<body class="admin-body<?= ($_SESSION["user_role"] ?? "") === "teacher" ? " teacher-view" : "" ?>">

    <div class="admin-layout">

        <!-- ==========================================================
             * ADMINISTRATIVE SIDEBAR NAVIGATION
             ========================================================== -->

        <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('modules/module_create.php'); ?>


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
                        Ajouter un module
                    </h1>

                    <p>
                        Formation : <?= htmlspecialchars($course["title"]) ?>
                    </p>

                </div>

                <a href="modules.php?course_id=<?= (int) $courseId ?>" class="btn btn-secondary">
                    Retour aux modules
                </a>

            </header>


            <!-- ==========================================================
                 * MODULE CREATION FORM SECTION
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
                            La position détermine l'ordre d'affichage.
                        </p>

                    </div>


                    <!-- * Creation Form -->
                    <form method="POST">
                        <input type="hidden" name="return_to" value="<?= $returnToContent ? "content" : "" ?>">

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
                                Position
                            </label>

                            <input type="number" id="position" name="position" min="1" value="<?= htmlspecialchars($position) ?>">

                            <small>
                                Laissez vide pour placer le module à la fin.
                            </small>

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

                            <a href="modules.php?course_id=<?= (int) $courseId ?>" class="btn btn-secondary">
                                Annuler
                            </a>

                            <button type="submit" class="btn btn-primary">
                                Créer le module
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


