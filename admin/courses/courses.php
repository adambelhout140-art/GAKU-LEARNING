<?php
require_once __DIR__ . '/../../config/i18n.php';// ============================================================
// * ADMIN COURSE DIRECTORY
// ============================================================
// ! RESTRICTED ACCESS: Requires an authenticated administrator.
// ? Purpose: Directory listing for courses with search filters, status badges, and inline actions.
// ? Security: Escapes SQL wildcard search inputs and processes deletions via CSRF-protected POST requests.
// ============================================================


// ------------------------------------------------------------
// * SESSION HANDLING
// ? Starts or resumes session to verify user identity and credentials.
// ------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


// ------------------------------------------------------------
// * FILE DEPENDENCIES
// ! Configures database PDO connection instance.
// ------------------------------------------------------------
require_once "../../config/database.php";


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
// ! Denies access to users without administrator privileges.
// ------------------------------------------------------------
if (($_SESSION["user_role"] ?? "") !== "admin") {
    header("Location: ../../index.php");
    exit;
}


// ------------------------------------------------------------
// * CSRF PROTECTION INITIALIZATION
// ! Shared session token used by destructive inline POST action forms.
// ------------------------------------------------------------
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}


// ------------------------------------------------------------
// * QUERY PARAMETERS & SANITIZATION
// ? Normalizes incoming search and status filter inputs.
// ------------------------------------------------------------
$search = trim($_GET["search"] ?? "");
$status = $_GET["status"] ?? "";

// ? Escape SQL wildcards (%) and (_) in user search input to prevent search spoofing
$searchParam = "";
if ($search !== "") {
    $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
    $searchParam = "%" . $escapedSearch . "%";
}


// ------------------------------------------------------------
// * DATABASE QUERY BUILDER & EXECUTION
// ? Joins teacher metadata and retrieves matching courses ordered by latest update.
// ------------------------------------------------------------
$sql = "
    SELECT 
        courses.id, 
        courses.title, 
        courses.slug, 
        courses.category, 
        courses.level, 
        courses.duration_hours, 
        courses.price, 
        courses.status, 
        courses.cover_image, 
        COALESCE(
            (
                SELECT GROUP_CONCAT(CONCAT(assigned.first_name, ' ', assigned.last_name) ORDER BY assigned.last_name, assigned.first_name SEPARATOR ', ')
                FROM course_teachers assigned_link
                INNER JOIN users assigned ON assigned.id = assigned_link.teacher_id
                WHERE assigned_link.course_id = courses.id
            ),
            CONCAT(users.first_name, ' ', users.last_name)
        ) AS teacher_name
    FROM courses 
    LEFT JOIN users ON users.id = courses.teacher_id 
    WHERE 1 = 1
";
$params = [];

if ($search !== "") {
    $sql .= " AND (courses.title LIKE :search OR courses.category LIKE :search OR courses.slug LIKE :search)";
    $params["search"] = $searchParam;
}

if (in_array($status, ["draft", "published", "unpublished"], true)) {
    $sql .= " AND courses.status = :status";
    $params["status"] = $status;
}

$sql .= " ORDER BY courses.updated_at DESC";
$statement = $pdo->prepare($sql);
$statement->execute($params);
$courses = $statement->fetchAll();


// ------------------------------------------------------------
// * ENUM TRANSLATION LABELS
// ? Maps database ENUM values to human-readable French display terms.
// ------------------------------------------------------------
$labels = [
    "draft"        => "Brouillon",
    "published"    => "Publié",
    "unpublished"  => "Dépublié",
    "beginner"     => "Débutant",
    "intermediate" => "Intermédiaire",
    "advanced"     => "Avancé"
];

?>
<!DOCTYPE html>
<html lang="fr">

<head>

    <!-- * Basic page metadata and responsive viewport settings -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Gestion des formations - Administration</title><link rel="icon" type="image/png" href="../../assets/images/logo-mark.png">

    <!-- * Main administrative layout stylesheet import -->
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">

</head>

<body class="admin-body">

    <div class="admin-layout">

        <!-- ==========================================================
             * ADMINISTRATIVE SIDEBAR NAVIGATION
             ========================================================== -->

        <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('courses/courses.php'); ?>


        <!-- ==========================================================
             * MAIN ADMINISTRATIVE CONTENT
             ========================================================== -->

        <main class="admin-main">

            <!-- ==========================================================
                 * DASHBOARD TOP BAR
                 ========================================================== -->

            <header class="admin-topbar">

                <div>
                    <h1>Formations</h1>
                    <p>Gérer les formations et leur publication</p>
                </div>

                <a href="course_create.php" class="btn btn-primary">
                    + Ajouter une formation
                </a>

            </header>


            <!-- ==========================================================
                 * DIRECTORY SECTION & MESSAGING
                 ========================================================== -->

            <section class="dashboard-section">

                <!-- * System Operational Alerts -->
                <?php if (($_GET["success"] ?? "") === "created"): ?>
                    <div class="alert alert-success">Formation créée avec succès.</div>
                <?php elseif (($_GET["success"] ?? "") === "updated"): ?>
                    <div class="alert alert-success">Formation modifiée avec succès.</div>
                <?php elseif (($_GET["success"] ?? "") === "deleted"): ?>
                    <div class="alert alert-success">Formation supprimée avec succès.</div>
                <?php elseif (($_GET["error"] ?? "") !== ""): ?>
                    <div class="alert alert-danger">L'action n'a pas pu être effectuée.</div>
                <?php endif; ?>

                <div class="section-header">
                    <div>
                        <h2>Liste des formations</h2>
                        <p><?= count($courses) ?> formation(s) trouvée(s)</p>
                    </div>
                </div>


                <!-- ==========================================================
                     * FILTERS & SEARCH FORM
                     ========================================================== -->

                <form method="GET" class="users-filters">

                    <input 
                        type="search" 
                        name="search" 
                        placeholder="Rechercher une formation..." 
                        value="<?= htmlspecialchars($search) ?>"
                    >

                    <select name="status">
                        <option value="">Tous les statuts</option>
                        <option value="draft" <?= $status === "draft" ? "selected" : "" ?>>Brouillons</option>
                        <option value="published" <?= $status === "published" ? "selected" : "" ?>>Publiées</option>
                        <option value="unpublished" <?= $status === "unpublished" ? "selected" : "" ?>>Dépubliées</option>
                    </select>

                    <button class="admin-button" type="submit">Rechercher</button>
                    <a class="admin-button secondary" href="courses.php">Réinitialiser</a>

                </form>


                <!-- ==========================================================
                     * DATA TABLE
                     ========================================================== -->

                <div class="admin-table-container">

                    <table class="admin-table">

                        <thead>
                            <tr>
                                <th>Formation</th>
                                <th>Catégorie</th>
                                <th>Niveau</th>
                                <th>Durée</th>
                                <th>Formateur</th>
                                <th>Statut</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if ($courses): ?>

                                <?php foreach ($courses as $course): ?>

                                    <tr>

                                        <td>
                                            <strong><?= htmlspecialchars($course["title"]) ?></strong>
                                            <small class="company-meta"><?= htmlspecialchars($course["slug"]) ?></small>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($course["category"] ?: "-") ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($labels[$course["level"]] ?? $course["level"]) ?>
                                        </td>

                                        <td>
                                            <?= $course["duration_hours"] !== null ? htmlspecialchars($course["duration_hours"]) . " h" : "-" ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(trim($course["teacher_name"] ?? "") ?: "Non assigné") ?>
                                        </td>

                                        <td>
                                            <span class="status-badge course-status-<?= htmlspecialchars($course["status"]) ?>">
                                                <?= htmlspecialchars($labels[$course["status"]] ?? $course["status"]) ?>
                                            </span>
                                        </td>

                                        <td class="company-actions">

                                            <a href="see_course.php?id=<?= (int) $course["id"] ?>" class="table-action">
                                                Voir
                                            </a>

                                            <a href="course_edit.php?id=<?= (int) $course["id"] ?>" class="table-action">
                                                Modifier
                                            </a>

                                            <!-- * Destructive Action: CSRF-Protected Form -->
                                            <form 
                                                method="POST" 
                                                action="course_delete.php" 
                                                onsubmit="return confirm('Supprimer cette formation ?');"
                                            >
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">
                                                <input type="hidden" name="course_id" value="<?= (int) $course["id"] ?>">
                                                <button type="submit" class="table-action table-action-danger">Supprimer</button>
                                            </form>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="7">Aucune formation trouvée.</td>
                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </section>

        </main>

    </div>

<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script></body>

</html>



