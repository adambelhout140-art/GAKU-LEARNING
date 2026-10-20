<?php
require_once __DIR__ . '/../../config/i18n.php';// ============================================================
// * GAKU LEARNING - ADMIN CONTACT REQUESTS INBOX
// ============================================================
// ! RESTRICTED ACCESS: Only authenticated Admins can access.
// ? Purpose: Displays visitor messages submitted from public course pages and lets admins update their status.
// ? Protection: Every status change is protected by a session CSRF token and a POST request.
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
// ! Strictly limits access to users with administrator privileges.
// ------------------------------------------------------------
if (($_SESSION["user_role"] ?? "") !== "admin") {
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
// * QUERY PARAMETER & FILTER INITIALIZATION
// ? Prepares filtering criteria and fetches submitted contact requests.
// ------------------------------------------------------------
$status = $_GET["status"] ?? "";
$type = $_GET["type"] ?? "";
$search = trim($_GET["search"] ?? "");
$courseFilter = filter_input(INPUT_GET, "course_id", FILTER_VALIDATE_INT) ?: 0;
$sort = $_GET["sort"] ?? "created_at";
$direction = strtolower($_GET["direction"] ?? "desc") === "asc" ? "ASC" : "DESC";
$page = max(1, (int) ($_GET["page"] ?? 1));
$perPage = 12;
$allowedStatuses = ["new", "accepted", "refused"];
$allowedTypes = ["access", "contact"];
$allowedSorts = ["created_at" => "contact_requests.created_at", "name" => "contact_requests.last_name", "course" => "courses.title", "status" => "contact_requests.status"];
$sortColumn = $allowedSorts[$sort] ?? $allowedSorts["created_at"];

$courses = $pdo->query("SELECT id, title FROM courses ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
$sql = "SELECT contact_requests.*, courses.title AS course_title FROM contact_requests LEFT JOIN courses ON courses.id = contact_requests.course_id WHERE 1 = 1";
$countSql = "SELECT COUNT(*) FROM contact_requests LEFT JOIN courses ON courses.id = contact_requests.course_id WHERE 1 = 1";
$params = [];

if (in_array($status, $allowedStatuses, true)) {
    $sql .= " AND contact_requests.status = :status";
    $params["status"] = $status;
}
if ($courseFilter > 0) {
    $sql .= " AND contact_requests.course_id = :course_id";
    $countSql .= " AND contact_requests.course_id = :course_id";
    $params["course_id"] = $courseFilter;
}

if ($type === "access") {
    $sql .= " AND contact_requests.subject LIKE :access_subject";
    $countSql .= " AND contact_requests.subject LIKE :access_subject";
    $params["access_subject"] = "Demande d'accès%";
} elseif ($type === "contact") {
    $sql .= " AND (contact_requests.subject IS NULL OR contact_requests.subject NOT LIKE :access_subject)";
    $countSql .= " AND (contact_requests.subject IS NULL OR contact_requests.subject NOT LIKE :access_subject)";
    $params["access_subject"] = "Demande d'accès%";
}

if ($search !== "") {
    $sql .= " AND (contact_requests.first_name LIKE :search OR contact_requests.last_name LIKE :search OR contact_requests.email LIKE :search OR contact_requests.phone LIKE :search OR contact_requests.cin LIKE :search OR contact_requests.parent_cin LIKE :search OR contact_requests.subject LIKE :search)";
    $countSql .= " AND (contact_requests.first_name LIKE :search OR contact_requests.last_name LIKE :search OR contact_requests.email LIKE :search OR contact_requests.phone LIKE :search OR contact_requests.cin LIKE :search OR contact_requests.parent_cin LIKE :search OR contact_requests.subject LIKE :search)";
    $params["search"] = "%" . $search . "%";
}

$countStatement = $pdo->prepare($countSql);
$countStatement->execute($params);
$totalRequests = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($totalRequests / $perPage));
$page = min($page, $totalPages);
$sql .= " ORDER BY " . $sortColumn . " " . $direction . " LIMIT " . $perPage . " OFFSET " . (($page - 1) * $perPage);

$statement = $pdo->prepare($sql);
$statement->execute($params);
$requests = $statement->fetchAll();

$statusLabels = ["new" => "Nouvelle", "accepted" => "Acceptée", "refused" => "Refusée"];
$typeLabels = ["access" => "Demande d'accès", "contact" => "Message de contact"];


// ------------------------------------------------------------
// * FORM PROCESSING: POST REQUEST (STATUS UPDATE)
// ------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "update_status";
    $token = $_POST["csrf_token"] ?? "";

    if (empty($_SESSION["csrf_token"]) || !hash_equals($_SESSION["csrf_token"], $token)) {
        header("Location: contact_requests.php?error=csrf");
        exit;
    }

    if ($action === "delete_all") {
        $pdo->exec("DELETE FROM contact_requests");
        header("Location: contact_requests.php?success=deleted_all");
        exit;
    }

    $requestId = filter_input(INPUT_POST, "request_id", FILTER_VALIDATE_INT);
    $newStatus = $_POST["status"] ?? "";
    $refusalReason = trim($_POST["refusal_reason"] ?? "");

    // ! Input Validation Checks & CSRF Verification
    if (!$requestId || !in_array($action, ["update_status", "confirm_accept", "activate_accounts", "delete"], true) || ($action === "update_status" && !in_array($newStatus, $allowedStatuses, true))) {
        header("Location: contact_requests.php?error=update");
        exit;
    }

    if ($action === "delete") {
        $delete = $pdo->prepare("DELETE FROM contact_requests WHERE id = ?");
        $delete->execute([$requestId]);
        header("Location: contact_requests.php?success=deleted");
        exit;
    }

    if ($action === "update_status" && $newStatus === "accepted") {
        header("Location: request_details.php?id=" . $requestId);
        exit;
    }

    if ($action === "confirm_accept") {
        $newStatus = "accepted";
    }

    if ($action === "activate_accounts") {
        $requestStatement = $pdo->prepare("SELECT created_student_id, created_parent_id FROM contact_requests WHERE id = ? LIMIT 1");
        $requestStatement->execute([$requestId]);
        $created = $requestStatement->fetch(PDO::FETCH_ASSOC);
        if (!$created || (!$created["created_student_id"] && !$created["created_parent_id"])) {
            header("Location: contact_requests.php?error=not_found");
            exit;
        }
        $activate = $pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ?");
        if (!empty($created["created_student_id"])) $activate->execute([(int) $created["created_student_id"]]);
        if (!empty($created["created_parent_id"])) $activate->execute([(int) $created["created_parent_id"]]);
        header("Location: request_details.php?id=" . $requestId . "&success=activated");
        exit;
    }

    if ($newStatus === "accepted") {
        $requestStatement = $pdo->prepare("SELECT * FROM contact_requests WHERE id = ? LIMIT 1");
        $requestStatement->execute([$requestId]);
        $accessRequest = $requestStatement->fetch(PDO::FETCH_ASSOC);
        if (!$accessRequest || ($accessRequest["request_kind"] ?? "") !== "access") {
            header("Location: contact_requests.php?error=not_access_request");
            exit;
        }

        try {
            $pdo->beginTransaction();
            $studentStatement = $pdo->prepare("SELECT id, role FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $studentStatement->execute([$accessRequest["email"]]);
            $student = $studentStatement->fetch(PDO::FETCH_ASSOC);
            if ($student) {
                throw new RuntimeException("Cette adresse email est déjà utilisée par un compte.");
            }
            if (empty($accessRequest["student_password_hash"])) {
                throw new RuntimeException("Mot de passe étudiant absent de la demande.");
            }
            $insertStudent = $pdo->prepare("INSERT INTO users (first_name, last_name, date_of_birth, cin, education, email, password, role, is_active, phone) VALUES (?, ?, ?, ?, ?, ?, ?, 'student', 0, ?)");
            $insertStudent->execute([$accessRequest["first_name"], $accessRequest["last_name"], $accessRequest["date_of_birth"] ?: null, $accessRequest["cin"] ?: null, $accessRequest["education"] ?: null, $accessRequest["email"], $accessRequest["student_password_hash"], $accessRequest["phone"] ?: null]);
            $studentId = (int) $pdo->lastInsertId();

            if (!empty($accessRequest["parent_email"])) {
                $parentStatement = $pdo->prepare("SELECT id, role FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
                $parentStatement->execute([$accessRequest["parent_email"]]);
                $parent = $parentStatement->fetch(PDO::FETCH_ASSOC);
                if ($parent) {
                    throw new RuntimeException("L'email du parent est déjà utilisé par un compte.");
                }
                if (empty($accessRequest["parent_password_hash"])) {
                    throw new RuntimeException("Mot de passe parent absent de la demande.");
                }
                $insertParent = $pdo->prepare("INSERT INTO users (first_name, last_name, date_of_birth, cin, email, password, role, is_active, phone) VALUES (?, ?, ?, ?, ?, ?, 'parent', 0, ?)");
                $insertParent->execute([$accessRequest["parent_first_name"], $accessRequest["parent_last_name"], $accessRequest["parent_date_of_birth"] ?: null, $accessRequest["parent_cin"] ?: null, $accessRequest["parent_email"], $accessRequest["parent_password_hash"], $accessRequest["parent_phone"] ?: null]);
                $parentId = (int) $pdo->lastInsertId();
                $link = $pdo->prepare("INSERT IGNORE INTO parent_student_links (parent_id, student_id) VALUES (?, ?)");
                $link->execute([$parentId, $studentId]);
            }

            if (!empty($accessRequest["course_id"])) {
                $enrollment = $pdo->prepare("INSERT INTO enrollments (user_id, course_id, status, enrolled_at) VALUES (?, ?, 'active', NOW()) ON DUPLICATE KEY UPDATE status = 'active'");
                $enrollment->execute([$studentId, (int) $accessRequest["course_id"]]);
            }
            $update = $pdo->prepare("UPDATE contact_requests SET status = 'accepted', created_student_id = ?, created_parent_id = ? WHERE id = ?");
            $update->execute([$studentId, $parentId ?? null, $requestId]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorCode = $exception instanceof RuntimeException ? "duplicate_email" : "accept_failed";
            header("Location: contact_requests.php?error=" . $errorCode);
            exit;
        }
    }

    // * Execute Status Update Query
    if ($newStatus !== "accepted") {
        if ($newStatus === "refused" && $refusalReason === "") {
            header("Location: request_details.php?id=" . $requestId . "&error=refusal_reason");
            exit;
        }
        $update = $pdo->prepare("UPDATE contact_requests SET status = ?, refusal_reason = ? WHERE id = ?");
        $update->execute([$newStatus, $newStatus === "refused" ? $refusalReason : null, $requestId]);
    }

    // ? Redirect back with success message
    header("Location: contact_requests.php?success=updated");
    exit;

}

?>

<!DOCTYPE html>

<html lang="fr">

<head>

    <!-- * Basic page metadata and responsive viewport settings -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Demandes visiteurs - Administration</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">

    <!-- * Main administrative layout stylesheet import -->
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">

</head>
<style>
.dashboard-section {
    min-width: 0;
}
.contact-requests-page .section-header {
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.1rem;
}
.contact-requests-page .section-header h2 {
    margin-bottom: .25rem;
}
.contact-requests-page .section-header p {
    margin: 0;
    color: #627d98;
}
.users-filters {
    display: flex;
    flex-wrap: wrap;
    align-items: stretch;
    gap: .75rem;
    padding: 1rem;
    border: 1px solid #dbe7f3;
    border-radius: 14px;
    background: linear-gradient(135deg, #f8fbfd, #fff);
    margin-bottom: 1.25rem;
}
.users-filters input,
.users-filters select,
.users-filters button,
.users-filters a {
    min-height: 42px;
}
.users-filters input[type="search"] {
    min-width: 240px;
    flex: 1 1 280px;
}
.users-filters select {
    min-width: 170px;
    cursor: pointer;
}
.users-filters button,
.users-filters a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: .55rem .9rem;
    border-radius: 8px;
    text-decoration: none;
}
.users-filters a.secondary {
    border: 1px solid #bcccdc;
    background: #fff;
    color: #243b53;
}
.admin-table-container {
    overflow-x: auto;
    border: 1px solid #dbe7f3;
    border-radius: 14px;
    box-shadow: 0 8px 24px rgba(16, 42, 67, .06);
}
.admin-table th a {
    color: inherit;
    text-decoration: none;
}
.admin-table th a::after {
    content: " ↕";
    color: #829ab1;
    font-size: .8em;
}
.admin-table td {
    vertical-align: top;
    line-height: 1.45;
}
.contact-requests-page .admin-table td:nth-child(1) { min-width: 170px; }
.contact-requests-page .admin-table td:nth-child(2) { min-width: 180px; }
.contact-requests-page .admin-table td:nth-child(3) { min-width: 150px; }
.contact-requests-page .admin-table td:nth-child(4) { min-width: 240px; }
.contact-requests-page .admin-table td:nth-child(6),
.contact-requests-page .admin-table td:nth-child(7) { min-width: 130px; }
.contact-requests-page .status-badge {
    display: inline-flex;
    align-items: center;
    margin-bottom: .45rem;
    white-space: nowrap;
}
.contact-requests-page .request-message {
    max-width: 360px;
    margin: .45rem 0 0;
    color: #486581;
}
.contact-requests-page .company-meta {
    display: block;
    margin-top: .2rem;
    color: #627d98;
}
.contact-requests-page td:last-child {
    white-space: nowrap;
}
.admin-table td strong,
.admin-table td p,
.admin-table td small {
    overflow-wrap: anywhere;
}
.request-status-form,
.admin-table td:last-child form {
    margin-bottom: .45rem;
}
.request-status-form select {
    width: 100%;
    min-height: 36px;
    border: 1px solid #bcccdc;
    border-radius: 7px;
    padding: .35rem .5rem;
}
.admin-button.small {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 34px;
    padding: .35rem .65rem;
    border-radius: 7px;
    font-size: .85rem;
    text-decoration: none;
}
.requests-pagination {
    flex-wrap: wrap;
    align-items: center;
}
.requests-pagination {
    display: flex;
    gap: .4rem;
    margin-top: 1rem;
}
.requests-pagination a {
    padding: .45rem .7rem;
    border-radius: 6px;
    background: #e6f0ff;
    color: #163a63;
    text-decoration: none;
}
.requests-pagination a.active {
    background: #277cff;
    color: #fff;
}
@media (max-width: 760px) {
    .users-filters {
        display: grid;
        grid-template-columns: 1fr;
        padding: .85rem;
    }
    .users-filters input[type="search"],
    .users-filters select,
    .users-filters button,
    .users-filters a {
        width: 100%;
        min-width: 0;
    }
    .admin-table-container {
        overflow: visible;
        border: 0;
        box-shadow: none;
    }
    .admin-table thead {
        display: none;
    }
    .admin-table,
    .admin-table tbody,
    .admin-table tr,
    .admin-table td {
        display: block;
        width: 100%;
    }
    .admin-table tr {
        margin-bottom: 1rem;
        padding: 1rem;
        border: 1px solid #dbe7f3;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 5px 16px rgba(16, 42, 67, .06);
    }
    .admin-table td {
        border: 0;
        padding: .45rem 0;
    }
    .contact-requests-page .request-message {
        max-width: none;
    }
    .admin-table td::before {
        display: block;
        margin-bottom: .2rem;
        color: #627d98;
        font-size: .78rem;
        font-weight: 700;
        text-transform: uppercase;
    }
    .admin-table td:nth-child(1)::before { content: "Visiteur"; }
    .admin-table td:nth-child(2)::before { content: "Contact"; }
    .admin-table td:nth-child(3)::before { content: "Formation"; }
    .admin-table td:nth-child(4)::before { content: "Type / message"; }
    .admin-table td:nth-child(5)::before { content: "Reçue le"; }
    .admin-table td:nth-child(6)::before { content: "Statut"; }
    .admin-table td:nth-child(7)::before { content: "Actions"; }
    .admin-table td:last-child .btn,
    .admin-table td:last-child .admin-button {
        width: 100%;
        margin-top: .3rem;
    }
    .contact-requests-page td:last-child {
        white-space: normal;
    }
}
</style>

<body class="admin-body contact-requests-page">

    <div class="admin-layout">

        <!-- ==========================================================
             * ADMINISTRATIVE SIDEBAR NAVIGATION
             ========================================================== -->

        <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('contact_requests/contact_requests.php'); ?>


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
                        Demandes visiteurs
                    </h1>

                    <p>
                        Gérer les demandes d'accès et les messages visiteurs
                    </p>

                </div>

            </header>


            <!-- ==========================================================
                 * INBOX & CONTACT REQUESTS MANAGEMENT SECTION
                 ========================================================== -->

            <section class="dashboard-section">

                <!-- ! Feedback Alert Banners -->
                <?php if (($_GET["success"] ?? "") === "updated"): ?>
                    <div class="alert alert-success">
                        Statut de la demande mis à jour.
                    </div>
                <?php elseif (($_GET["success"] ?? "") === "deleted"): ?>
                    <div class="alert alert-success">Demande supprimée.</div>
                <?php elseif (($_GET["success"] ?? "") === "deleted_all"): ?>
                    <div class="alert alert-success">Toutes les demandes ont été supprimées.</div>
                <?php elseif (($_GET["error"] ?? "") === "accept_failed"): ?>
                    <div class="alert alert-danger">La demande n'a pas pu être acceptée. Vérifiez les emails et les informations du parent.</div>
                <?php elseif (($_GET["error"] ?? "") === "duplicate_email"): ?>
                    <div class="alert alert-danger">Impossible de créer le compte : l'email étudiant ou parent est déjà utilisé.</div>
                <?php elseif (($_GET["error"] ?? "") === "not_access_request"): ?>
                    <div class="alert alert-danger">Cette demande n'est pas une demande d'inscription.</div>
                <?php elseif (($_GET["error"] ?? "") !== ""): ?>
                    <div class="alert alert-danger">
                        La mise à jour n'a pas pu être effectuée.
                    </div>
                <?php endif; ?>

                <!-- * Section Header & Counter -->
                <div class="section-header">

                    <div>
                        <h2>Boîte de réception</h2>
                        <p><?= count($requests) ?> demande(s) trouvée(s)</p>
                    </div>

                    <?php if ($requests): ?>
                        <form method="POST" onsubmit="return confirm('Supprimer toutes les demandes de contact ? Cette action est irréversible.');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">
                            <input type="hidden" name="action" value="delete_all">
                            <button type="submit" class="btn btn-danger">Supprimer tout</button>
                        </form>
                    <?php endif; ?>

                </div>

                <!-- * Search & Filter Controls -->
                <form method="GET" class="users-filters">

                    <input type="search" name="search" placeholder="Rechercher un visiteur ou un email..." value="<?= htmlspecialchars($search) ?>">

                    <select name="status">
                        <option value="">Tous les statuts</option>
                        <?php foreach ($statusLabels as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $status === $value ? "selected" : "" ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="type">
                        <option value="">Tous les types</option>
                        <?php foreach ($typeLabels as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $type === $value ? "selected" : "" ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="course_id">
                        <option value="">Toutes les formations</option>
                        <?php foreach ($courses as $courseOption): ?>
                            <option value="<?= (int) $courseOption["id"] ?>" <?= $courseFilter === (int) $courseOption["id"] ? "selected" : "" ?>><?= htmlspecialchars($courseOption["title"]) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <button class="admin-button" type="submit">Rechercher</button>
                    <a class="admin-button secondary" href="contact_requests.php">Réinitialiser</a>

                </form>

                <!-- * Contact Requests Data Table -->
                <div class="admin-table-container">

                    <table class="admin-table">

                        <thead>
                            <tr>
                                <th><a href="?sort=name&direction=<?= $sort === "name" && $direction === "ASC" ? "desc" : "asc" ?>">Visiteur</a></th>
                                <th>Contact</th>
                                <th><a href="?sort=course&direction=<?= $sort === "course" && $direction === "ASC" ? "desc" : "asc" ?>">Formation</a></th>
                                <th>Type / message</th>
                                <th><a href="?sort=created_at&direction=<?= $sort === "created_at" && $direction === "ASC" ? "desc" : "asc" ?>">Reçue le</a></th>
                                <th><a href="?sort=status&direction=<?= $sort === "status" && $direction === "ASC" ? "desc" : "asc" ?>">Statut</a></th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php if ($requests): ?>

                                <?php foreach ($requests as $request): ?>
                                    <tr>

                                        <!-- * Visitor Name & Company -->
                                        <td>
                                            <strong><?= htmlspecialchars(trim($request["first_name"] . " " . ($request["last_name"] ?? ""))) ?></strong>
                                            <?php if (($request["request_kind"] ?? "") === "access"): ?>
                                                <?php if (!empty($request["date_of_birth"])): ?>
                                                    <small class="company-meta">Naissance : <?= htmlspecialchars(date("d/m/Y", strtotime($request["date_of_birth"]))) ?></small>
                                                <?php endif; ?>
                                                <?php if (!empty($request["cin"])): ?>
                                                    <small class="company-meta">CIN : <?= htmlspecialchars($request["cin"]) ?></small>
                                                <?php endif; ?>
                                                <?php if (!empty($request["education"])): ?>
                                                    <small class="company-meta">Études : <?= htmlspecialchars($request["education"]) ?></small>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($request["company_name"]): ?>
                                                <small class="company-meta"><?= htmlspecialchars($request["company_name"]) ?></small>
                                            <?php endif; ?>
                                        </td>

                                        <!-- * Contact Info -->
                                        <td>
                                            <?= htmlspecialchars($request["email"]) ?>
                                            <?php if ($request["phone"]): ?>
                                                <br><?= htmlspecialchars($request["phone"]) ?>
                                            <?php endif; ?>
                                        </td>

                                        <!-- * Associated Course -->
                                        <td>
                                            <?= htmlspecialchars($request["course_title"] ?: "Demande générale") ?>
                                        </td>

                                        <!-- * Message Subject & Content -->
                                        <td>
                                            <?php $requestType = ($request["request_kind"] ?? "") === "access" ? "access" : "contact"; ?>
                                            <span class="status-badge status-<?= htmlspecialchars($request["status"]) ?>">
                                                <?= htmlspecialchars($typeLabels[$requestType]) ?>
                                            </span>
                                            <strong><?= htmlspecialchars($request["subject"] ?: "Demande d'information") ?></strong>
                                            <p class="request-message"><?= nl2br(htmlspecialchars($request["message"])) ?></p>
                                            <?php if (!empty($request["refusal_reason"])): ?><small class="company-meta">Motif du refus : <?= htmlspecialchars($request["refusal_reason"]) ?></small><?php endif; ?>
                                        </td>

                                        <!-- * Received Timestamp -->
                                        <td>
                                            <?= htmlspecialchars(date("d/m/Y à H:i", strtotime($request["created_at"]))) ?>
                                        </td>

                                        <!-- * Inline Status Change Form -->
                                        <td>

                                            <?php if (($request["request_kind"] ?? "") === "access" && $request["status"] === "new"): ?>
                                            <form method="POST" class="request-status-form">

                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">
                                                <input type="hidden" name="request_id" value="<?= (int) $request["id"] ?>">

                                                <select name="status" onchange="this.form.submit()" aria-label="Statut de la demande">
                                                    <option value="new" <?= $request["status"] === "new" ? "selected" : "" ?>>Nouvelle</option>
                                                    <option value="accepted" <?= $request["status"] === "accepted" ? "selected" : "" ?>>Acceptée</option>
                                                    <option value="refused" <?= $request["status"] === "refused" ? "selected" : "" ?>>Refusée</option>
                                                </select>

                                            </form>
                                            <a class="admin-button small" href="request_details.php?id=<?= (int) $request["id"] ?>">Voir les détails</a>
                                            <?php elseif (($request["request_kind"] ?? "") === "access" && $request["status"] === "accepted"): ?>
                                                <span class="status-badge status-inactive">Compte créé — inactif</span>
                                                <a class="admin-button small" href="request_details.php?id=<?= (int) $request["id"] ?>">Voir les détails</a>
                                            <?php elseif (($request["request_kind"] ?? "") === "access" && $request["status"] === "refused"): ?>
                                                <span class="status-badge status-refused">Refusée</span>
                                                <a class="admin-button small" href="request_details.php?id=<?= (int) $request["id"] ?>">Voir les détails</a>
                                            <?php else: ?>
                                                <span class="status-badge status-<?= htmlspecialchars($request["status"]) ?>"><?= htmlspecialchars($statusLabels[$request["status"]] ?? $request["status"]) ?></span>
                                            <?php endif; ?>

                                        </td>

                                        <td>
                                            <form method="POST" onsubmit="return confirm('Supprimer cette demande ? Cette action est irréversible.');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="request_id" value="<?= (int) $request["id"] ?>">
                                                <button type="submit" class="btn btn-danger">Supprimer</button>
                                            </form>
                                        </td>

                                    </tr>
                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="7">Aucune demande de contact trouvée.</td>
                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>
                <?php if ($totalPages > 1): ?>
                    <nav class="requests-pagination" aria-label="Pagination">
                        <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                            <a class="<?= $pageNumber === $page ? "active" : "" ?>" href="?page=<?= $pageNumber ?>&status=<?= urlencode($status) ?>&type=<?= urlencode($type) ?>&course_id=<?= $courseFilter ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>&direction=<?= strtolower($direction) ?>"><?= $pageNumber ?></a>
                        <?php endfor; ?>
                    </nav>
                <?php endif; ?>

            </section>

        </main>

    </div>

<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script></body>

</html>
