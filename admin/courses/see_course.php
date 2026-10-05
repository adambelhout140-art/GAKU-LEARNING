<?php
require_once __DIR__ . '/../../config/i18n.php';// ============================================================
// * ADMIN COURSE DETAIL VIEW
// ============================================================
// ! RESTRICTED ACCESS: Requires an authenticated administrator.
// ? Purpose: Displays complete course metadata, descriptions, cover image, and management actions.
// ============================================================


// ------------------------------------------------------------
// * SESSION HANDLING
// ? Starts or resumes session to verify user identity and permissions.
// ------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


// ------------------------------------------------------------
// * FILE DEPENDENCIES
// ! Configures database connection via PDO instance.
// ------------------------------------------------------------
require_once "../../config/database.php";


// ------------------------------------------------------------
// * ACCESS CONTROL: AUTHENTICATION CHECK
// ! Redirects unauthenticated users to the login screen.
// ------------------------------------------------------------
if (!isset($_SESSION["user_id"])) {
    header("Location: ../../auth/login.php");
    exit;
}


// ------------------------------------------------------------
// * ACCESS CONTROL: ROLE CHECK
// ! Restricts access strictly to administrative user roles.
// ------------------------------------------------------------
if (($_SESSION["user_role"] ?? "") !== "admin") {
    header("Location: ../../index.php");
    exit;
}


// ------------------------------------------------------------
// * REQUEST INPUT VALIDATION
// ? Validates and sanitizes the incoming integer ID parameter.
// ------------------------------------------------------------
$courseId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$courseId || $courseId < 1) {
    header("Location: courses.php");
    exit;
}


// ------------------------------------------------------------
// * DATABASE QUERY & RECORD FETCH
// ? Joins teacher metadata and retrieves single course record.
// ------------------------------------------------------------
$statement = $pdo->prepare("
    SELECT
        courses.*,
        COALESCE(
            (
                SELECT GROUP_CONCAT(
                    CONCAT(assigned.first_name, ' ', assigned.last_name)
                    ORDER BY assigned.last_name, assigned.first_name
                    SEPARATOR ', '
                )
                FROM course_teachers assigned_link
                INNER JOIN users assigned ON assigned.id = assigned_link.teacher_id
                WHERE assigned_link.course_id = courses.id
            ),
            CONCAT(users.first_name, ' ', users.last_name)
        ) AS teacher_names
    FROM courses 
    LEFT JOIN users ON users.id = courses.teacher_id 
    WHERE courses.id = ? 
    LIMIT 1
");
$statement->execute([$courseId]);
$course = $statement->fetch();

if (!$course) {
    header("Location: courses.php");
    exit;
}


// ------------------------------------------------------------
// * ENUM TRANSLATION LABELS
// ? Maps database ENUM values to user-friendly French display terms.
// ------------------------------------------------------------
$labels = [
    "draft"        => "Brouillon",
    "published"    => "Publié",
    "unpublished"  => "Dépublié",
    "beginner"     => "Débutant",
    "intermediate" => "Intermédiaire",
    "advanced"     => "Avancé",
    "online"       => "En ligne",
    "hybrid"       => "Hybride",
    "in_person"    => "Présentiel"
];

?>
<!DOCTYPE html>
<html lang="fr">

<head>

    <!-- * Basic page metadata and responsive viewport settings -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Voir une formation - Administration</title><link rel="icon" type="image/png" href="../../assets/images/logo-mark.png">

    <!-- * Main administrative layout stylesheet import -->
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">

</head>

<body class="admin-body">

    <div class="admin-layout">

        <!-- ==========================================================
             * ADMINISTRATIVE SIDEBAR NAVIGATION
             ========================================================== -->

        <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('courses/see_course.php'); ?>


        <!-- ==========================================================
             * MAIN ADMINISTRATIVE CONTENT
             ========================================================== -->

        <main class="admin-main">

            <!-- ==========================================================
                 * TOP BAR & BREADCRUMB HEADER
                 ========================================================== -->

            <header class="admin-topbar">

                <div>
                    <h1><?= htmlspecialchars($course["title"]) ?></h1>
                    <p>Fiche de la formation</p>
                </div>

                <a href="courses.php" class="btn btn-secondary">
                    Retour aux formations
                </a>
                <?php if ($course["status"] === "published"): ?>
                    <a href="../../public/course.php?slug=<?= urlencode($course["slug"]) ?>" class="btn btn-primary" target="_blank" rel="noopener">
                        Voir la page publique
                    </a>
                <?php endif; ?>

            </header>


            <!-- ==========================================================
                 * COURSE DETAILS SECTION
                 ========================================================== -->

            <section class="dashboard-section">

                <div class="form-card user-profile-card course-profile-card">

                    <!-- * Cover Image Preview (if present) -->
                    <?php if ($course["cover_image"]): ?>
                        <img 
                            class="course-cover-preview" 
                            src="../../uploads/courses/<?= htmlspecialchars($course["cover_image"]) ?>" 
                            alt="Couverture de <?= htmlspecialchars($course["title"]) ?>"
                        >
                    <?php endif; ?>


                    <!-- ==========================================================
                         * METADATA GRID
                         ========================================================== -->

                    <div class="user-details-grid">

                        <div class="user-detail">
                            <span>Statut</span>
                            <strong><?= htmlspecialchars($labels[$course["status"]] ?? $course["status"]) ?></strong>
                        </div>

                        <div class="user-detail">
                            <span>Formateur</span>
                            <strong><?= htmlspecialchars(trim($course["teacher_names"] ?? "") ?: "Non assigné") ?></strong>
                        </div>

                        <div class="user-detail">
                            <span>Slug</span>
                            <strong><?= htmlspecialchars($course["slug"]) ?></strong>
                        </div>

                        <div class="user-detail">
                            <span>Catégorie</span>
                            <strong><?= htmlspecialchars($course["category"] ?: "Non renseignée") ?></strong>
                        </div>

                        <div class="user-detail">
                            <span>Niveau</span>
                            <strong><?= htmlspecialchars($labels[$course["level"]] ?? $course["level"]) ?></strong>
                        </div>

                        <div class="user-detail">
                            <span>Format</span>
                            <strong><?= htmlspecialchars($labels[$course["format"]] ?? $course["format"]) ?></strong>
                        </div>

                        <div class="user-detail">
                            <span>Durée</span>
                            <strong><?= $course["duration_hours"] !== null ? htmlspecialchars($course["duration_hours"]) . " heures" : "Non renseignée" ?></strong>
                        </div>

                        <div class="user-detail">
                            <span>Prix</span>
                            <strong><?= htmlspecialchars(number_format((float) $course["price"], 2, ",", " ")) ?> DNT</strong>
                        </div>

                    </div>


                    <!-- ==========================================================
                         * COURSE DESCRIPTIONS & CONTENT
                         ========================================================== -->

                    <div class="course-text">

                        <h3>Résumé</h3>
                        <p><?= nl2br(htmlspecialchars($course["short_description"] ?: "Non renseigné")) ?></p>

                        <h3>Description</h3>
                        <p><?= nl2br(htmlspecialchars($course["description"] ?: "Non renseignée")) ?></p>

                        <h3>Objectifs</h3>
                        <p><?= nl2br(htmlspecialchars($course["objectives"] ?: "Non renseignés")) ?></p>

                        <h3>Prérequis</h3>
                        <p><?= nl2br(htmlspecialchars($course["prerequisites"] ?: "Aucun")) ?></p>

                    </div>


                    <!-- ==========================================================
                         * ACTION BUTTONS
                         ========================================================== -->

                    <div class="form-actions">

                        <a href="courses.php" class="btn btn-secondary">
                            Retour
                        </a>

                        <a href="../modules/modules.php?course_id=<?= (int) $courseId ?>" class="btn btn-secondary">
                            Gérer les modules
                        </a>

                        <a href="course_edit.php?id=<?= (int) $courseId ?>" class="btn btn-primary">
                            Modifier la formation
                        </a>

                        <?php if ($course["status"] !== "published"): ?>
                            <span class="form-action-note">La formation doit être publiée pour être visible sur le site.</span>
                        <?php endif; ?>

                    </div>

                </div>

            </section>

        </main>

    </div>

<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script></body>

</html>


