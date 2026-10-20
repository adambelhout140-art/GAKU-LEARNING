<?php
require_once __DIR__ . '/../../config/i18n.php';// ============================================================
// * ADMIN COMPANIES DIRECTORY
// ============================================================
// ! RESTRICTED ACCESS: Requires an authenticated administrator.
// ? Purpose: Lists companies with search, pagination, status filtering, and count metrics.
// ? Security: Deletion forms use CSRF validation; SQL wildcard characters are escaped.
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
// * CSRF PROTECTION INITIALIZATION
// ! One session token is shared by the destructive action forms rendered on this page.
// ------------------------------------------------------------
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}


// ------------------------------------------------------------
// * QUERY PARAMETERS & SANITIZATION
// ? Normalizes incoming search filters and handles integer pagination bounds.
// ------------------------------------------------------------
$search = trim($_GET["search"] ?? "");
$page = max(1, (int) ($_GET["page"] ?? 1));
$perPage = 10;
$status = $_GET["status"] ?? "";

// ? Escape SQL wildcards (%) and (_) in user search input to prevent search spoofing
$searchParam = "";
if ($search !== "") {
    $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
    $searchParam = "%" . $escapedSearch . "%";
}


// ------------------------------------------------------------
// * TOTAL RECORDS COUNT (PAGINATION CALCULATION)
// ? Count query executes prior to main fetch so pagination bounds match total filtered results.
// ------------------------------------------------------------
$countSql = "SELECT COUNT(*) FROM companies WHERE 1 = 1";
$countParams = [];

if ($search !== "") {
    $countSql .= " AND (company_name LIKE :search OR registration_number LIKE :search OR email LIKE :search OR city LIKE :search)";
    $countParams["search"] = $searchParam;
}

if (in_array($status, ["active", "inactive"], true)) {
    $countSql .= " AND is_active = :is_active";
    $countParams["is_active"] = ($status === "active") ? 1 : 0;
}

$countStatement = $pdo->prepare($countSql);
$countStatement->execute($countParams);
$totalCompanies = (int) $countStatement->fetchColumn();

$totalPages = max(1, (int) ceil($totalCompanies / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;


// ------------------------------------------------------------
// * MAIN DATA QUERY FETCH
// ? Correlated subqueries extract manager identity and employee totals safely across MySQL SQL modes.
// ------------------------------------------------------------
$sql = "
    SELECT
        companies.id,
        companies.company_name,
        companies.registration_number,
        companies.city,
        companies.country,
        companies.phone,
        companies.email,
        companies.created_at,
        companies.is_active,
        (
            SELECT CONCAT(users.first_name, ' ', users.last_name) 
            FROM users 
            WHERE users.company_id = companies.id AND users.role = 'company_manager' 
            ORDER BY users.id ASC 
            LIMIT 1
        ) AS manager_name,
        (
            SELECT COUNT(*) 
            FROM users 
            WHERE users.company_id = companies.id
        ) AS employee_count
    FROM companies
    WHERE 1 = 1
";
$params = [];

if ($search !== "") {
    $sql .= " AND (companies.company_name LIKE :search OR companies.registration_number LIKE :search OR companies.email LIKE :search OR companies.city LIKE :search)";
    $params["search"] = $searchParam;
}

if (in_array($status, ["active", "inactive"], true)) {
    $sql .= " AND companies.is_active = :is_active";
    $params["is_active"] = ($status === "active") ? 1 : 0;
}

$sql .= " ORDER BY companies.company_name ASC LIMIT :limit OFFSET :offset";
$statement = $pdo->prepare($sql);

foreach ($params as $name => $value) {
    $statement->bindValue(":" . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}

$statement->bindValue(":limit", $perPage, PDO::PARAM_INT);
$statement->bindValue(":offset", $offset, PDO::PARAM_INT);
$statement->execute();
$companies = $statement->fetchAll();

?>
<!DOCTYPE html>
<html lang="fr">

<head>

    <!-- * Basic page metadata and responsive viewport settings -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Gestion des entreprises - Administration</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">

    <!-- * Main administrative layout stylesheet import -->
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">

</head>

<body class="admin-body">

    <div class="admin-layout">

        <!-- ==========================================================
             * ADMINISTRATIVE SIDEBAR NAVIGATION
             ========================================================== -->

        <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('companies/companies.php'); ?>


        <!-- ==========================================================
             * MAIN ADMINISTRATIVE CONTENT
             ========================================================== -->

        <main class="admin-main">

            <!-- ==========================================================
                 * DASHBOARD TOP BAR
                 ========================================================== -->

            <header class="admin-topbar">

                <div>
                    <h1>Entreprises</h1>
                    <p>Gérer les entreprises et leurs collaborateurs</p>
                </div>

                <a href="company_create.php" class="btn btn-primary">
                    + Ajouter une entreprise
                </a>

            </header>


            <!-- ==========================================================
                 * DIRECTORY SECTION & MESSAGING
                 ========================================================== -->

            <section class="dashboard-section">

                <!-- * System Operational Alerts -->
                <?php if (($_GET["success"] ?? "") === "created"): ?>
                    <div class="alert alert-success">Entreprise créée avec succès.</div>
                <?php elseif (($_GET["success"] ?? "") === "updated"): ?>
                    <div class="alert alert-success">Entreprise modifiée avec succès.</div>
                <?php elseif (($_GET["success"] ?? "") === "deleted"): ?>
                    <div class="alert alert-success">Entreprise supprimée avec succès.</div>
                <?php elseif (($_GET["error"] ?? "") !== ""): ?>
                    <div class="alert alert-danger">Cette action n'a pas pu être effectuée.</div>
                <?php endif; ?>

                <div class="section-header company-section-header">
                    <div>
                        <h2>Liste des entreprises</h2>
                        <p><?= $totalCompanies ?> entreprise(s) trouvée(s)</p>
                    </div>
                </div>


                <!-- ==========================================================
                     * FILTERS & SEARCH FORM
                     ========================================================== -->

                <form method="GET" class="users-filters">

                    <input 
                        type="search" 
                        name="search" 
                        placeholder="Rechercher une entreprise..." 
                        value="<?= htmlspecialchars($search) ?>"
                    >

                    <select name="status">
                        <option value="">Tous les statuts</option>
                        <option value="active" <?= $status === "active" ? "selected" : "" ?>>Actives</option>
                        <option value="inactive" <?= $status === "inactive" ? "selected" : "" ?>>Inactives</option>
                    </select>

                    <button type="submit" class="admin-button">Rechercher</button>
                    <a href="companies.php" class="admin-button secondary">Réinitialiser</a>

                </form>


                <!-- ==========================================================
                     * DATA TABLE
                     ========================================================== -->

                <div class="admin-table-container">

                    <table class="admin-table">

                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Entreprise</th>
                                <th>Localisation</th>
                                <th>Contact</th>
                                <th>Collaborateurs</th>
                                <th>Statut</th>
                                <th>Responsable</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if ($companies): ?>

                                <?php foreach ($companies as $company): ?>

                                    <tr>

                                        <td>#<?= (int) $company["id"] ?></td>

                                        <td>
                                            <strong><?= htmlspecialchars($company["company_name"]) ?></strong>
                                            <?php if ($company["registration_number"]): ?>
                                                <small class="company-meta">
                                                    Matricule: <?= htmlspecialchars($company["registration_number"]) ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(trim(($company["city"] ?? "") . ($company["country"] ? ", " . $company["country"] : "")) ?: "-") ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($company["email"] ?: "-") ?><br>
                                            <?= htmlspecialchars($company["phone"] ?: "-") ?>
                                        </td>

                                        <td>
                                            <?= (int) $company["employee_count"] ?>
                                        </td>

                                        <td>
                                            <span class="status-badge <?= $company["is_active"] ? "status-active" : "status-inactive" ?>">
                                                <?= $company["is_active"] ? "Active" : "Inactive" ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($company["manager_name"] ?: "Non assigné") ?>
                                        </td>

                                        <td class="company-actions">

                                            <a href="see_company.php?id=<?= (int) $company["id"] ?>" class="table-action">
                                                Voir
                                            </a>

                                            <a href="company_edit.php?id=<?= (int) $company["id"] ?>" class="table-action">
                                                Modifier
                                            </a>

                                            <!-- * Destructive Action: CSRF-Protected Form -->
                                            <form 
                                                method="POST" 
                                                action="company_delete.php" 
                                                onsubmit="return confirm('Voulez-vous vraiment supprimer cette entreprise ?');"
                                            >
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">
                                                <input type="hidden" name="company_id" value="<?= (int) $company["id"] ?>">
                                                <button type="submit" class="table-action table-action-danger">Supprimer</button>
                                            </form>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="8">Aucune entreprise trouvée.</td>
                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>


                <!-- ==========================================================
                     * PAGINATION CONTROLS
                     ========================================================== -->

                <?php if ($totalPages > 1): ?>

                    <nav class="pagination" aria-label="Pagination des entreprises">

                        <?php if ($page > 1): ?>
                            <a href="?<?= http_build_query(array_filter(["search" => $search, "status" => $status, "page" => $page - 1])) ?>">
                                &larr; Précédente
                            </a>
                        <?php endif; ?>

                        <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                            <a 
                                href="?<?= http_build_query(array_filter(["search" => $search, "status" => $status, "page" => $pageNumber])) ?>" 
                                class="<?= $pageNumber === $page ? "active" : "" ?>"
                            >
                                <?= $pageNumber ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="?<?= http_build_query(array_filter(["search" => $search, "status" => $status, "page" => $page + 1])) ?>">
                                Suivante &rarr;
                            </a>
                        <?php endif; ?>
</nav>

                <?php endif; ?>

            </section>

        </main>

    </div>

<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script></body>

</html>




