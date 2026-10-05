<?php
require_once __DIR__ . '/../../config/i18n.php';// ============================================================
// * GAKU LEARNING - READ-ONLY ADMIN COMPANY PROFILE
// ============================================================
// ! RESTRICTED ACCESS: Requires an authenticated administrator.
// ? Purpose: Shows organization details and the number of users linked through company_id.
// ? Note: This page never writes data.
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
// * DATABASE QUERY: FETCH COMPANY DETAILS & AGGREGATES
// ? Correlated employee count avoids a GROUP BY requirement under strict MySQL settings.
// ------------------------------------------------------------
$statement = $pdo->prepare("SELECT companies.*, (SELECT COUNT(*) FROM users WHERE users.company_id = companies.id) AS employee_count, (SELECT CONCAT(users.first_name, ' ', users.last_name) FROM users WHERE users.company_id = companies.id AND users.role = 'company_manager' ORDER BY users.id ASC LIMIT 1) AS manager_name, (SELECT users.email FROM users WHERE users.company_id = companies.id AND users.role = 'company_manager' ORDER BY users.id ASC LIMIT 1) AS manager_email FROM companies WHERE companies.id = ? LIMIT 1");
$statement->execute([$companyId]);
$company = $statement->fetch();

if (!$company) {
    header("Location: companies.php");
    exit;
}

?>

<!DOCTYPE html>

<html lang="fr">

<head>

    <!-- * Basic page metadata and responsive viewport settings -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Voir une entreprise - Administration</title><link rel="icon" type="image/png" href="../../assets/images/logo-mark.png">

    <!-- * Main administrative layout stylesheet import -->
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">

</head>

<body class="admin-body">

    <div class="admin-layout">

        <!-- ==========================================================
             * ADMINISTRATIVE SIDEBAR NAVIGATION
             ========================================================== -->

        <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('companies/see_company.php'); ?>


        <!-- ==========================================================
             * MAIN ADMINISTRATIVE CONTENT
             ========================================================== -->

        <main class="admin-main">

            <!-- ==========================================================
                 * DASHBOARD TOP BAR
                 ========================================================== -->

            <header class="admin-topbar">

                <div>
                    <h1>Profil entreprise</h1>
                    <p>Consulter les informations de l'entreprise</p>
                </div>

                <a href="companies.php" class="btn btn-secondary">
                    Retour aux entreprises
                </a>

            </header>


            <!-- ==========================================================
                 * COMPANY PROFILE CARD SECTION
                 ========================================================== -->

            <section class="dashboard-section">

                <div class="form-card user-profile-card company-profile-card">

                    <!-- * Company Header Information -->
                    <div class="user-profile-heading">
                        <div class="admin-avatar">🏢</div>
                        <div>
                            <h2><?= htmlspecialchars($company["company_name"]) ?></h2>
                            <p><?= htmlspecialchars($company["email"] ?: "Aucun email renseigné") ?></p>
                        </div>
                    </div>

                    <!-- * Company Details Grid -->
                    <div class="user-details-grid">

                        <div class="user-detail">
                            <span>Identifiant</span>
                            <strong>#<?= (int) $company["id"] ?></strong>
                        </div>

                        <div class="user-detail">
                            <span>Secteur d'activité</span>
                            <strong><?= htmlspecialchars($company["industry"] ?: "Non renseigné") ?></strong>
                        </div>

                        <div class="user-detail">
                            <span>Matricule fiscal</span>
                            <strong><?= htmlspecialchars($company["registration_number"] ?: "Non renseigné") ?></strong>
                        </div>

                        <div class="user-detail">
                            <span>Adresse</span>
                            <strong><?= htmlspecialchars($company["address"] ?: "Non renseignée") ?></strong>
                        </div>

                        <div class="user-detail">
                            <span>Localisation</span>
                            <strong><?= htmlspecialchars(trim(($company["city"] ?: "") . ($company["country"] ? ", " . $company["country"] : "")) ?: "Non renseignée") ?></strong>
                        </div>

                        <div class="user-detail">
                            <span>Téléphone</span>
                            <strong><?= htmlspecialchars($company["phone"] ?: "Non renseigné") ?></strong>
                        </div>

                        <div class="user-detail">
                            <span>Collaborateurs</span>
                            <strong><?= (int) $company["employee_count"] ?></strong>
                        </div>

                        <div class="user-detail">
                            <span>Responsable</span>
                            <strong>
                                <?= htmlspecialchars($company["responsible_name"] ?: $company["manager_name"] ?: "Non assigné") ?>
                                <?php if ($company["responsible_email"] || $company["manager_email"]): ?>
                                    <small><?= htmlspecialchars($company["responsible_email"] ?: $company["manager_email"]) ?></small>
                                <?php endif; ?>
                            </strong>
                        </div>

                        <div class="user-detail"><span>Téléphone responsable</span><strong><?= htmlspecialchars($company["responsible_phone"] ?: "Non renseigné") ?></strong></div>
                        <div class="user-detail"><span>Chef / directeur</span><strong><?= htmlspecialchars($company["director_name"] ?: "Non renseigné") ?><small><?= htmlspecialchars($company["director_email"] ?: "") ?></small></strong></div>
                        <div class="user-detail"><span>Téléphone directeur</span><strong><?= htmlspecialchars($company["director_phone"] ?: "Non renseigné") ?></strong></div>
                        <div class="user-detail"><span>Site web</span><strong><?= htmlspecialchars($company["website"] ?: "Non renseigné") ?></strong></div>

                        <div class="user-detail">
                            <span>Statut</span>
                            <strong class="<?= $company["is_active"] ? "profile-status-active" : "profile-status-inactive" ?>">
                                <?= $company["is_active"] ? "Active" : "Inactive" ?>
                            </strong>
                        </div>

                        <div class="user-detail">
                            <span>Créée le</span>
                            <strong><?= htmlspecialchars(date("d/m/Y à H:i", strtotime($company["created_at"]))) ?></strong>
                        </div>

                    </div>

                    <!-- * Navigation Action Buttons -->
                    <div class="form-actions">
                        <a href="companies.php" class="btn btn-secondary">Retour</a>
                        <a href="company_employees.php?id=<?= (int) $company["id"] ?>" class="btn btn-secondary">Voir les employés</a>
                        <a href="company_edit.php?id=<?= (int) $company["id"] ?>" class="btn btn-primary">Modifier l'entreprise</a>
                    </div>

                </div>

            </section>

        </main>

    </div>

<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script></body>

</html>




