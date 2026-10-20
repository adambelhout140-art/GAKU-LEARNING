<?php
require_once __DIR__ . '/../../config/i18n.php';// ============================================================
// * GAKU LEARNING - COMPANY EMPLOYEES PAGE
// ============================================================
// ! RESTRICTED ACCESS: Requires an authenticated administrator.
// ? Purpose: Lists every user linked to one company, including its manager and learners.
// ? Security: The company ID is validated and the query is restricted to that company.
// ============================================================


// ------------------------------------------------------------
// * SESSION HANDLING
// ? Starts or resumes session to verify identity and retrieve credentials.
// ------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


// ------------------------------------------------------------
// * FILE DEPENDENCIES
// ! Configures database PDO connection.
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
if (!isset($_SESSION["user_role"]) || $_SESSION["user_role"] !== "admin") {
    header("Location: ../../index.php");
    exit;
}


// ------------------------------------------------------------
// * QUERY PARAMETER SANITIZATION
// ? Validates and casts target company ID parameter from GET request.
// ------------------------------------------------------------
$companyId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$companyId || $companyId < 1) {
    header("Location: companies.php");
    exit;
}


// ------------------------------------------------------------
// * DATABASE QUERY: FETCH TARGET COMPANY
// ? Verifies company existence before fetching associated employees.
// ------------------------------------------------------------
$companyStatement = $pdo->prepare("SELECT id, company_name FROM companies WHERE id = ? LIMIT 1");
$companyStatement->execute([$companyId]);
$company = $companyStatement->fetch();

if (!$company) {
    header("Location: companies.php");
    exit;
}


// ------------------------------------------------------------
// * DATABASE QUERY: FETCH COMPANY EMPLOYEES
// ? Retrieves all users linked via company_id ordered alphabetically by name.
// ------------------------------------------------------------
$employeeStatement = $pdo->prepare("SELECT id, first_name, last_name, email, role, is_active, created_at FROM users WHERE company_id = ? ORDER BY first_name ASC, last_name ASC");
$employeeStatement->execute([$companyId]);
$employees = $employeeStatement->fetchAll();

$roleLabels = [
    "student" => "Apprenant",
    "company_manager" => "Responsable entreprise",
    "teacher" => "Formateur",
    "admin" => "Administrateur"
];

?>

<!DOCTYPE html>

<html lang="fr">

<head>

    <!-- * Basic page metadata and responsive viewport settings -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Employés - <?= htmlspecialchars($company["company_name"]) ?></title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">

    <!-- * Main administrative layout stylesheet import -->
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">

</head>

<body class="admin-body">

    <div class="admin-layout">

        <!-- ==========================================================
             * ADMINISTRATIVE SIDEBAR NAVIGATION
             ========================================================== -->

        <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('companies/company_employees.php'); ?>


        <!-- ==========================================================
             * MAIN ADMINISTRATIVE CONTENT
             ========================================================== -->

        <main class="admin-main">

            <!-- ==========================================================
                 * DASHBOARD TOP BAR
                 ========================================================== -->

            <header class="admin-topbar">

                <div>
                    <h1>Employés de <?= htmlspecialchars($company["company_name"]) ?></h1>
                    <p><?= count($employees) ?> collaborateur(s) rattaché(s)</p>
                </div>

                <a href="see_company.php?id=<?= (int) $companyId ?>" class="btn btn-secondary">
                    Retour à l'entreprise
                </a>

            </header>


            <!-- ==========================================================
                 * COMPANY EMPLOYEES DATA TABLE SECTION
                 ========================================================== -->

            <section class="dashboard-section">

                <div class="admin-table-container">

                    <table class="admin-table">

                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nom</th>
                                <th>Email</th>
                                <th>Rôle</th>
                                <th>Statut</th>
                                <th>Inscrit le</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if ($employees): ?>

                                <?php foreach ($employees as $employee): ?>
                                    <tr>

                                        <!-- * User ID -->
                                        <td>#<?= (int) $employee["id"] ?></td>

                                        <!-- * User Full Name -->
                                        <td>
                                            <strong><?= htmlspecialchars(trim($employee["first_name"] . " " . $employee["last_name"])) ?></strong>
                                        </td>

                                        <!-- * Email Address -->
                                        <td><?= htmlspecialchars($employee["email"]) ?></td>

                                        <!-- * User Role -->
                                        <td><?= htmlspecialchars($roleLabels[$employee["role"]] ?? $employee["role"]) ?></td>

                                        <!-- * Account Status Badge -->
                                        <td>
                                            <span class="status-badge <?= $employee["is_active"] ? "status-active" : "status-inactive" ?>">
                                                <?= $employee["is_active"] ? "Actif" : "Inactif" ?>
                                            </span>
                                        </td>

                                        <!-- * Registration Date -->
                                        <td><?= htmlspecialchars(date("d/m/Y", strtotime($employee["created_at"]))) ?></td>

                                        <!-- * Profile Direct Action -->
                                        <td>
                                            <a href="../users/see_user.php?id=<?= (int) $employee["id"] ?>" class="table-action">Voir</a>
                                        </td>

                                    </tr>
                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="7">Aucun employé rattaché à cette entreprise.</td>
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




