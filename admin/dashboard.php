<?php
require_once __DIR__ . '/../config/i18n.php';// ! ADMIN DASHBOARD
// * The dashboard summarizes platform activity and provides the main admin navigation.
// ? The role check below is server-side; hiding a link alone would not protect this page.

// ============================================================
// ADMIN DASHBOARD
// ============================================================
//
// This page is accessible ONLY to administrators.
//
// The dashboard will display important information from
// our database, such as:
//
// - Total users
// - Total students
// - Total teachers
// - Total companies
// - Total courses
// - Published courses
// - Active enrollments
//
// We will later add charts, recent users, recent courses,
// notifications and other administration features.
// ============================================================


// ------------------------------------------------------------
// Start the session.
//
// The session contains information about the currently
// logged-in user.
// ------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


// ------------------------------------------------------------
// Load the database connection.
//
// This gives us access to the $pdo object.
// ------------------------------------------------------------
require_once "../config/database.php";
require_once "../config/notifications.php";


// ============================================================
// SECURITY CHECK
// ============================================================


// ------------------------------------------------------------
// Check if the user is logged in.
//
// If there is no user_id in the session, it means that
// nobody is authenticated.
//
// We send the visitor back to the login page.
// ------------------------------------------------------------
if (!isset($_SESSION["user_id"])) {

    header("Location: ../auth/login.php");

    exit;
}


// ------------------------------------------------------------
// Check the user's role.
//
// Having a valid session is NOT enough.
//
// A teacher, student or company manager must NOT be able
// to access the admin dashboard.
//
// We therefore check the role on the SERVER.
// ------------------------------------------------------------
if (($_SESSION["user_role"] ?? "") !== "admin") {

    header("Location: ../auth/login.php");

    exit;
}


// ! DATABASE STATISTICS
// * Each counter is intentionally queried from the source table so dashboard values stay current.
$adminUserId = (int) $_SESSION["user_id"];


// ------------------------------------------------------------
// Count all users.
//
// COUNT(*) counts every row inside the users table.
// ------------------------------------------------------------
$stmt = $pdo->query(
    "SELECT COUNT(*) FROM users"
);

$total_users = $stmt->fetchColumn();


// ------------------------------------------------------------
// Count students.
//
// We only count users whose role is "student".
// ------------------------------------------------------------
$stmt = $pdo->query(
    "SELECT COUNT(*) FROM users WHERE role = 'student'"
);

$total_students = $stmt->fetchColumn();


// ------------------------------------------------------------
// Count teachers.
//
// We only count users whose role is "teacher".
// ------------------------------------------------------------
$stmt = $pdo->query(
    "SELECT COUNT(*) FROM users WHERE role = 'teacher'"
);

$total_teachers = $stmt->fetchColumn();


// ------------------------------------------------------------
// Count companies.
//
// Every row in companies represents one company.
// ------------------------------------------------------------
$stmt = $pdo->query(
    "SELECT COUNT(*) FROM companies"
);

$total_companies = $stmt->fetchColumn();


// ------------------------------------------------------------
// Count all courses.
//
// This includes draft, published and unpublished courses.
// ------------------------------------------------------------
$stmt = $pdo->query(
    "SELECT COUNT(*) FROM courses"
);

$total_courses = $stmt->fetchColumn();


// ------------------------------------------------------------
// Count published courses.
//
// These are the courses visible to visitors on the
// public website.
// ------------------------------------------------------------
$stmt = $pdo->query(
    "SELECT COUNT(*) FROM courses WHERE status = 'published'"
);

$published_courses = $stmt->fetchColumn();


// ------------------------------------------------------------
// Count active enrollments.
//
// An enrollment is active when its status is "active".
// ------------------------------------------------------------
$stmt = $pdo->query(
    "SELECT COUNT(*) FROM enrollments WHERE status = 'active'"
);

$active_enrollments = $stmt->fetchColumn();

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <!-- Character encoding -->
    <meta charset="UTF-8">

    <!-- Responsive design -->
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <!-- Browser tab title -->
    <title>Admin Dashboard - <?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">

    <!-- Main CSS file -->
    <link
        rel="stylesheet"
        href="../assets/css/style.css?v=20260925e">

</head>

<body class="admin-body">


    <!-- ! ADMIN LAYOUT
    The sidebar is shared visually with the user and company modules.
    ========================================================== -->

    <div class="admin-layout">


        <!-- ======================================================
         SIDEBAR
         ====================================================== -->

        <?php require_once __DIR__ . "/config/admin_sidebar.php"; renderAdminSidebar('dashboard.php'); ?>


        <!-- ======================================================
         MAIN CONTENT
         ====================================================== -->

        <main class="admin-main">


            <!-- ==================================================
             TOP BAR
             ================================================== -->

            <header class="admin-topbar">
                <?= roleNotificationBell($pdo, $adminUserId, "notifications.php") ?>


                <div>

                    <h1>
                        Dashboard
                    </h1>

                    <p>
                        Welcome back,
                        <?= htmlspecialchars($_SESSION["user_name"]) ?>.
                    </p>

                </div>


                <div class="admin-user">

                    <div class="admin-avatar">

                        <?= strtoupper(
                            substr($_SESSION["user_name"], 0, 1)
                        ) ?>

                    </div>

                    <div>

                        <strong>
                            <?= htmlspecialchars(
                                $_SESSION["user_name"]
                            ) ?>
                        </strong>

                        <small>
                            Administrator
                        </small>

                    </div>

                </div>


            </header>

            <section class="dashboard-search-panel" aria-label="Recherche avancée">
                <div class="dashboard-search-heading">
                    <div><p class="student-eyebrow">Recherche avancée</p><h2>Accès rapide</h2></div>
                    <span data-search-count>0 résultat(s)</span>
                </div>
                <form data-dashboard-search class="dashboard-search-form">
                    <input data-search-query type="search" placeholder="Rechercher une section..." aria-label="Rechercher une section">
                    <select data-search-status aria-label="Filtrer les sections">
                        <option value="">Toutes les sections</option>
                        <option value="management">Gestion</option>
                        <option value="content">Contenu</option>
                        <option value="communication">Communication</option>
                    </select>
                    <button class="button secondary-button" type="reset">Réinitialiser</button>
                </form>
                <div class="dashboard-search-links">
                    <a data-dashboard-search-item data-search-status="management" href="users/users.php">👥 Utilisateurs</a>
                    <a data-dashboard-search-item data-search-status="management" href="companies/companies.php">🏢 Entreprises</a>
                    <a data-dashboard-search-item data-search-status="content" href="courses/courses.php">📚 Formations</a>
                    <a data-dashboard-search-item data-search-status="content" href="modules/modules.php">🧩 Modules</a>
                    <a data-dashboard-search-item data-search-status="content" href="quizzes/quizzes.php">🧠 Quiz</a>
                    <a data-dashboard-search-item data-search-status="content" href="exams/exams.php">📝 Examens</a>
                    <a data-dashboard-search-item data-search-status="communication" href="messages.php">✉️ Messages</a>
                    <a data-dashboard-search-item data-search-status="communication" href="notifications.php">🔔 Notifications</a>
                </div>
            </section>


            <!-- ==================================================
             STATISTICS
             ================================================== -->

            <section class="dashboard-section">


                <div class="stats-grid">


                    <!-- Total users -->

                    <div class="stat-card">

                        <div class="stat-icon">
                            &#128101;
                        </div>

                        <div>

                            <p>
                                Total Users
                            </p>

                            <h2>
                                <?= $total_users ?>
                            </h2>

                        </div>

                    </div>


                    <!-- Students -->

                    <div class="stat-card">

                        <div class="stat-icon">
                            &#127891;
                        </div>

                        <div>

                            <p>
                                Students
                            </p>

                            <h2>
                                <?= $total_students ?>
                            </h2>

                        </div>

                    </div>


                    <!-- Teachers -->

                    <div class="stat-card">

                        <div class="stat-icon">
                            &#128105;
                        </div>

                        <div>

                            <p>
                                Teachers
                            </p>

                            <h2>
                                <?= $total_teachers ?>
                            </h2>

                        </div>

                    </div>


                    <!-- Companies -->

                    <div class="stat-card">

                        <div class="stat-icon">
                            &#127970;
                        </div>

                        <div>

                            <p>
                                Companies
                            </p>

                            <h2>
                                <?= $total_companies ?>
                            </h2>

                        </div>

                    </div>


                    <!-- Courses -->

                    <div class="stat-card">

                        <div class="stat-icon">
                            &#128218;
                        </div>

                        <div>

                            <p>
                                Total Courses
                            </p>

                            <h2>
                                <?= $total_courses ?>
                            </h2>

                        </div>

                    </div>


                    <!-- Published courses -->

                    <div class="stat-card">

                        <div class="stat-icon">
                            &#9989;
                        </div>

                        <div>

                            <p>
                                Published Courses
                            </p>

                            <h2>
                                <?= $published_courses ?>
                            </h2>

                        </div>

                    </div>


                    <!-- Active enrollments -->

                    <div class="stat-card">

                        <div class="stat-icon">
                            &#127919;
                        </div>

                        <div>

                            <p>
                                Active Enrollments
                            </p>

                            <h2>
                                <?= $active_enrollments ?>
                            </h2>

                        </div>

                    </div>


                </div>

            </section>


            <!-- ==================================================
             QUICK ACTIONS
             ================================================== -->

            <section class="dashboard-section">


                <div class="section-title">

                    <div>

                        <h2>
                            Quick Actions
                        </h2>

                        <p>
                            Frequently used administration actions.
                        </p>

                    </div>

                </div>


                <div class="quick-actions">


                    <a
                        href="users/user_create.php"
                        class="quick-action">

                        <span>
                            &#128100;
                        </span>

                        <strong>
                            Manage Users
                        </strong>

                        <small>
                            Add and manage students, teachers
                            and administrators.
                        </small>

                    </a>


                    <a
                        href="courses/courses.php"
                        class="quick-action">

                        <span>
                            📚
                        </span>

                        <strong>
                            Manage Courses
                        </strong>

                        <small>
                            Create, edit and publish courses.
                        </small>

                    </a>


                    <a
                        href="companies/companies.php"
                        class="quick-action">

                        <span>
                            🏢
                        </span>

                        <strong>
                            Manage Companies
                        </strong>

                        <small>
                            Manage company accounts and employees.
                        </small>

                    </a>


                </div>

            </section>


        </main>

    </div>
    <script src="../assets/js/dashboard-search.js?v=20260915a"></script>
    <script src="../assets/js/admin-sidebar.js?v=20260925e"></script>
</body>

</html>
