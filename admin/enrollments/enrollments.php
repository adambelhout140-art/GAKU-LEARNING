<?php
require_once __DIR__ . '/../../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../auth/login.php");
    exit;
}
if (($_SESSION["user_role"] ?? "") !== "admin") {
    http_response_code(403);
    exit("Accès interdit.");
}

require_once "../../config/database.php";
require_once "../../config/audit.php";
require_once "../../config/notifications.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$errors = [];
$search = trim($_GET["search"] ?? "");
$statusFilter = $_GET["status"] ?? "";
$courseFilter = filter_input(INPUT_GET, "course_id", FILTER_VALIDATE_INT) ?: 0;
$page = max(1, (int) ($_GET["page"] ?? 1));
$perPage = 15;
$allowedStatuses = ["active", "suspended", "completed"];
$statusLabels = ["active" => "Actif", "suspended" => "Suspendu", "completed" => "Terminé"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $token = $_POST["csrf_token"] ?? "";

    if (empty($_SESSION["csrf_token"]) || !hash_equals($_SESSION["csrf_token"], $token)) {
        $errors[] = "La session du formulaire a expiré.";
    } elseif ($action === "grant") {
        $userId = filter_input(INPUT_POST, "user_id", FILTER_VALIDATE_INT);
        $courseId = filter_input(INPUT_POST, "course_id", FILTER_VALIDATE_INT);

        if (!$userId || !$courseId) {
            $errors[] = "Sélectionnez un étudiant et une formation.";
        } else {
            $userCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'student' AND is_active = 1");
            $userCheck->execute([$userId]);
            $courseCheck = $pdo->prepare("SELECT id, title FROM courses WHERE id = ?");
            $courseCheck->execute([$courseId]);
            $course = $courseCheck->fetch(PDO::FETCH_ASSOC);

            if (!$userCheck->fetch() || !$course) {
                $errors[] = "L'étudiant ou la formation est invalide.";
            } else {
                $grant = $pdo->prepare(
                    "INSERT INTO enrollments (user_id, course_id, assigned_by, status)
                     VALUES (?, ?, ?, 'active')
                     ON DUPLICATE KEY UPDATE assigned_by = VALUES(assigned_by),
                         status = 'active', enrolled_at = CURRENT_TIMESTAMP, completed_at = NULL"
                );
                $grant->execute([$userId, $courseId, $_SESSION["user_id"]]);
                auditLog($pdo, (int) $_SESSION["user_id"], "enrollment_granted", "enrollment", null, null, ["student_id" => (int) $userId, "course_id" => (int) $courseId]);
                $grantedCourseTitle = (string) $course["title"];
                notifyUser(
                    $pdo,
                    (int) $userId,
                    "Nouvelle formation attribuée",
                    "Vous avez été inscrit à la formation « " . $grantedCourseTitle . " ». Bonne formation !",
                    "enrollment"
                );
                header("Location: enrollments.php?success=granted");
                exit;
            }
        }
    } elseif ($action === "set_status") {
        $enrollmentId = filter_input(INPUT_POST, "enrollment_id", FILTER_VALIDATE_INT);
        $newStatus = $_POST["status"] ?? "";

        if (!$enrollmentId || !in_array($newStatus, ["active", "suspended"], true)) {
            $errors[] = "Action d'accès invalide.";
        } else {
            $update = $pdo->prepare("UPDATE enrollments SET status = ? WHERE id = ?");
            $update->execute([$newStatus, $enrollmentId]);
            auditLog($pdo, (int) $_SESSION["user_id"], "enrollment_status_changed", "enrollment", (int) $enrollmentId, null, ["status" => $newStatus]);
            $enrollmentInfoStatement = $pdo->prepare(
                "SELECT e.user_id, c.title FROM enrollments e INNER JOIN courses c ON c.id = e.course_id WHERE e.id = ?"
            );
            $enrollmentInfoStatement->execute([$enrollmentId]);
            $enrollmentInfo = $enrollmentInfoStatement->fetch(PDO::FETCH_ASSOC);
            if ($enrollmentInfo) {
                notifyUser(
                    $pdo,
                    (int) $enrollmentInfo["user_id"],
                    $newStatus === "active" ? "Accès réactivé" : "Accès suspendu",
                    $newStatus === "active"
                        ? "Votre accès à la formation « " . $enrollmentInfo["title"] . " » a été réactivé."
                        : "Votre accès à la formation « " . $enrollmentInfo["title"] . " » a été suspendu.",
                    "enrollment"
                );
            }
            header("Location: enrollments.php?success=" . ($newStatus === "active" ? "reactivated" : "suspended"));
            exit;
        }
    }
}

$students = $pdo->query(
    "SELECT id, first_name, last_name, email
     FROM users WHERE role = 'student' AND is_active = 1
     ORDER BY first_name, last_name"
)->fetchAll(PDO::FETCH_ASSOC);
$courses = $pdo->query("SELECT id, title FROM courses ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);

$where = [];
$params = [];
if ($search !== "") {
    $where[] = "(u.first_name LIKE :search OR u.last_name LIKE :search OR u.email LIKE :search OR c.title LIKE :search)";
    $params["search"] = "%" . $search . "%";
}
if (in_array($statusFilter, $allowedStatuses, true)) {
    $where[] = "e.status = :status";
    $params["status"] = $statusFilter;
}
if ($courseFilter > 0) {
    $where[] = "e.course_id = :course_id";
    $params["course_id"] = $courseFilter;
}

$whereSql = $where ? " WHERE " . implode(" AND ", $where) : "";
$countStatement = $pdo->prepare(
    "SELECT COUNT(*)
     FROM enrollments e
     INNER JOIN users u ON u.id = e.user_id
     INNER JOIN courses c ON c.id = e.course_id
     {$whereSql}"
);
$countStatement->execute($params);
$totalEnrollments = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($totalEnrollments / $perPage));
$page = min($page, $totalPages);
$enrollmentStatement = $pdo->prepare(
    "SELECT e.id, e.user_id, e.status, e.enrolled_at, e.completed_at,
            u.first_name, u.last_name, u.email, c.title AS course_title
     FROM enrollments e
     INNER JOIN users u ON u.id = e.user_id
     INNER JOIN courses c ON c.id = e.course_id
     {$whereSql}
     ORDER BY e.enrolled_at DESC
     LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage)
);
$enrollmentStatement->execute($params);
$enrollments = $enrollmentStatement->fetchAll(PDO::FETCH_ASSOC);

$counts = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(status = 'active') AS active,
            SUM(status = 'suspended') AS suspended,
            SUM(status = 'completed') AS completed
     FROM enrollments"
)->fetch(PDO::FETCH_ASSOC);

$successMessage = "";
if (isset($_GET["success"])) {
    $successMessage = [
        "granted" => "Accès accordé avec succès.",
        "reactivated" => "Accès réactivé avec succès.",
        "suspended" => "Accès suspendu avec succès."
    ][$_GET["success"]] ?? "";
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accès aux formations - Administration</title><link rel="icon" type="image/png" href="../../assets/images/logo-mark.png">
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">
</head>
<body class="admin-body">
<div class="admin-layout">
    <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('enrollments/enrollments.php'); ?>

    <main class="admin-main enrollment-page">
        <header class="admin-topbar page-header">
            <div>
                <h1>Gestion des accès</h1>
                <p>Gérez les accès des étudiants aux formations.</p>
            </div>
        </header>

        <?php if ($errors): ?>
            <div class="alert alert-danger quiz-alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
        <?php elseif ($successMessage !== ""): ?>
            <div class="alert alert-success quiz-alert"><?= e($successMessage) ?></div>
        <?php endif; ?>

        <section class="enrollment-stats enrollment-summary">
            <div class="enrollment-stat"><span>Total des accès</span><strong><?= (int) ($counts["total"] ?? 0) ?></strong></div>
            <div class="enrollment-stat enrollment-stat-active"><span>Actifs</span><strong><?= (int) ($counts["active"] ?? 0) ?></strong></div>
            <div class="enrollment-stat enrollment-stat-suspended"><span>Suspendus</span><strong><?= (int) ($counts["suspended"] ?? 0) ?></strong></div>
            <div class="enrollment-stat enrollment-stat-completed"><span>Terminés</span><strong><?= (int) ($counts["completed"] ?? 0) ?></strong></div>
        </section>

        <section class="form-container enrollment-grant-card enrollment-panel">
            <div class="section-heading">
                <div>
                    <h2>Accorder un accès</h2>
                </div>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                <input type="hidden" name="action" value="grant">
                <div class="quiz-form-grid">
                    <div class="form-group">
                        <label for="user_id">Étudiant</label>
                        <select id="user_id" name="user_id" required>
                            <option value="">-- Sélectionner un étudiant --</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?= (int) $student["id"] ?>"><?= e($student["first_name"] . " " . $student["last_name"] . " — " . $student["email"]) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="course_id">Formation</label>
                        <select id="course_id" name="course_id" required>
                            <option value="">-- Sélectionner une formation --</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?= (int) $course["id"] ?>"><?= e($course["title"]) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button class="btn btn-primary" type="submit">Accorder l'accès</button>
            </form>
        </section>

        <section class="enrollment-list-section enrollment-panel">
            <div class="section-heading enrollment-list-heading">
                <div>
                    <h2>Accès enregistrés</h2>
                    <p><?= $totalEnrollments ?> résultat(s)</p>
                </div>
            </div>
            <form method="GET" class="users-filters enrollment-filters enrollment-toolbar">
                <input type="search" name="search" placeholder="Rechercher un étudiant ou une formation..." value="<?= e($search) ?>">
                <select name="status">
                    <option value="">Tous les statuts</option>
                    <?php foreach ($allowedStatuses as $option): ?>
                        <option value="<?= e($option) ?>" <?= $statusFilter === $option ? "selected" : "" ?>><?= ucfirst($option) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="course_id">
                    <option value="">Toutes les formations</option>
                    <?php foreach ($courses as $course): ?>
                        <option value="<?= (int) $course["id"] ?>" <?= $courseFilter === (int) $course["id"] ? "selected" : "" ?>><?= e($course["title"]) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="admin-button" type="submit">Rechercher</button>
                <a class="admin-button secondary" href="enrollments.php">Réinitialiser</a>
            </form>

            <div class="admin-table-container">
                <table class="admin-table">
                    <thead>
                        <tr><th>Étudiant</th><th>Formation</th><th>Accès depuis</th><th>Statut</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$enrollments): ?>
                        <tr><td colspan="5" class="empty-state">Aucun accès ne correspond à vos critères.</td></tr>
                    <?php else: foreach ($enrollments as $enrollment): ?>
                        <tr>
                            <td><a class="enrollment-student-link" href="../users/see_user.php?id=<?= (int) $enrollment["user_id"] ?>"><strong><?= e($enrollment["first_name"] . " " . $enrollment["last_name"]) ?></strong></a><small><?= e($enrollment["email"]) ?></small></td>
                            <td><?= e($enrollment["course_title"]) ?></td>
                            <td><?= e(date("d/m/Y", strtotime($enrollment["enrolled_at"]))) ?></td>
                            <td><span class="status-badge <?= $enrollment["status"] === "active" ? "status-active" : ($enrollment["status"] === "completed" ? "status-completed" : "status-inactive") ?>"><?= e($statusLabels[$enrollment["status"]] ?? $enrollment["status"]) ?></span></td>
                            <td>
                                <?php if ($enrollment["status"] !== "completed"): ?>
                                    <form method="POST" class="enrollment-action-form" onsubmit="return confirm('Confirmer la modification de cet accès ?');">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="enrollment_id" value="<?= (int) $enrollment["id"] ?>">
                                        <input type="hidden" name="status" value="<?= $enrollment["status"] === "active" ? "suspended" : "active" ?>">
                                        <button class="btn <?= $enrollment["status"] === "active" ? "btn-danger" : "btn-secondary" ?>" type="submit"><?= $enrollment["status"] === "active" ? "Suspendre" : "Réactiver" ?></button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted-text">Accès terminé</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <nav class="enrollment-pagination" aria-label="Pagination des accès">
                    <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                        <a class="<?= $pageNumber === $page ? "active" : "" ?>" href="?page=<?= $pageNumber ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&course_id=<?= (int) $courseFilter ?>"><?= $pageNumber ?></a>
                    <?php endfor; ?>
                </nav>
            <?php endif; ?>
        </section>
    </main>
</div>
<style>
.enrollment-page .admin-table-container {
    overflow-x: auto;
    border: 1px solid #e5eaf0;
    border-radius: 10px;
    background: #fff;
}
.enrollment-stats {
    gap: .65rem;
    margin: 0;
}
.enrollment-stat {
    padding: .65rem .8rem;
    border: 0;
    border-radius: 8px;
    box-shadow: none;
    background: #f6f8fb;
    border-left: 3px solid #277cff;
}
.enrollment-stat strong {
    font-size: 1.35rem;
}
.enrollment-stat span {
    font-size: .72rem;
}
.enrollment-grant-card,
.enrollment-list-section {
    margin-top: 1rem;
}
.enrollment-grant-card {
    padding: 1rem 1.15rem;
}
.enrollment-grant-card .section-heading,
.enrollment-list-section .section-heading {
    margin-bottom: .75rem;
}
.enrollment-grant-card .section-heading h2,
.enrollment-list-section .section-heading h2 {
    font-size: 1.05rem;
}
.enrollment-grant-card .section-heading p,
.enrollment-list-section .section-heading p {
    margin: 0;
    font-size: .85rem;
}
.enrollment-grant-card .quiz-form-grid {
    gap: .75rem;
}
.enrollment-grant-card .form-group {
    margin-bottom: .7rem;
}
.enrollment-grant-card .btn {
    padding: .55rem .9rem;
}
.enrollment-stat-suspended {
    border-left-color: #d69e2e;
}
.enrollment-stat-completed {
    border-left-color: #38a169;
}
.enrollment-student-link {
    color: #163a63;
    text-decoration: none;
}
.enrollment-student-link:hover {
    text-decoration: underline;
}
.enrollment-list-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.enrollment-list-heading p {
    margin: 0 !important;
    color: #718096;
    font-size: .8rem;
}
.enrollment-toolbar {
    display: grid;
    grid-template-columns: minmax(220px, 1.5fr) minmax(140px, .7fr) minmax(180px, 1fr) auto auto;
    gap: .5rem;
    padding: .65rem;
    margin-bottom: .7rem;
    border: 1px solid #e5eaf0;
    border-radius: 10px;
    background: #f8fafc;
}
.enrollment-toolbar input,
.enrollment-toolbar select,
.enrollment-toolbar .admin-button {
    min-height: 38px;
}
.enrollment-toolbar input[type="search"] {
    min-width: 0;
}
.enrollment-page .admin-table th {
    padding: .7rem .85rem;
    background: #f8fafc;
    color: #52606d;
    font-size: .72rem;
    letter-spacing: .03em;
    text-transform: uppercase;
}
.enrollment-page .admin-table td {
    padding: .75rem .85rem;
    vertical-align: middle;
}
.enrollment-page .admin-table tbody tr:hover {
    background: #fbfdff;
}
.enrollment-page .admin-table td small {
    font-size: .78rem;
}
.enrollment-action-form .btn {
    border-radius: 6px;
}
.enrollment-pagination {
    display: flex;
    flex-wrap: wrap;
    gap: .4rem;
    margin-top: 1rem;
}
.enrollment-pagination a {
    padding: .45rem .7rem;
    border-radius: 7px;
    background: #e6f0ff;
    color: #163a63;
    text-decoration: none;
}
.enrollment-pagination a.active {
    background: #277cff;
    color: #fff;
}
@media (max-width: 760px) {
    .enrollment-toolbar {
        grid-template-columns: 1fr;
    }
    .enrollment-list-heading {
        align-items: flex-start;
    }
    .enrollment-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .enrollment-page .admin-table thead {
        display: none;
    }
    .enrollment-page .admin-table,
    .enrollment-page .admin-table tbody,
    .enrollment-page .admin-table tr,
    .enrollment-page .admin-table td {
        display: block;
        width: 100%;
    }
    .enrollment-page .admin-table tr {
        margin-bottom: 1rem;
        padding: 1rem;
        border: 1px solid #dbe7f3;
        border-radius: 12px;
        background: #fff;
    }
    .enrollment-page .admin-table td {
        border: 0;
        padding: .45rem 0;
    }
    .enrollment-page .admin-table td::before {
        display: block;
        margin-bottom: .2rem;
        color: #627d98;
        font-size: .75rem;
        font-weight: 700;
        text-transform: uppercase;
    }
    .enrollment-page .admin-table td:nth-child(1)::before { content: "Étudiant"; }
    .enrollment-page .admin-table td:nth-child(2)::before { content: "Formation"; }
    .enrollment-page .admin-table td:nth-child(3)::before { content: "Accès depuis"; }
    .enrollment-page .admin-table td:nth-child(4)::before { content: "Statut"; }
    .enrollment-page .admin-table td:nth-child(5)::before { content: "Action"; }
    .enrollment-action-form .btn {
        width: 100%;
    }
}

/* Modern enrollment dashboard */
.enrollment-page {
    background: #f4f7fb;
}
.enrollment-page .page-header {
    margin-bottom: 1.25rem;
    padding: 1.5rem 1.75rem;
    border-radius: 16px;
    background: linear-gradient(120deg, #173f73 0%, #277cff 100%);
    color: #fff;
    box-shadow: 0 10px 24px rgba(39, 124, 255, .16);
}
.enrollment-page .page-header h1,
.enrollment-page .page-header p {
    color: #fff;
}
.enrollment-page .page-header p {
    opacity: .82;
}
.enrollment-summary {
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: .8rem;
}
.enrollment-summary .enrollment-stat {
    position: relative;
    overflow: hidden;
    padding: 1rem 1.1rem;
    border: 0;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 5px 16px rgba(31, 56, 88, .07);
}
.enrollment-summary .enrollment-stat::after {
    position: absolute;
    top: -18px;
    right: -18px;
    width: 62px;
    height: 62px;
    border-radius: 50%;
    background: rgba(39, 124, 255, .08);
    content: "";
}
.enrollment-summary .enrollment-stat strong {
    font-size: 1.55rem;
}
.enrollment-summary .enrollment-stat-active {
    border-left: 0;
}
.enrollment-summary .enrollment-stat-suspended {
    border-left: 0;
}
.enrollment-summary .enrollment-stat-completed {
    border-left: 0;
}
.enrollment-panel {
    border: 0;
    border-radius: 16px;
    box-shadow: 0 5px 18px rgba(31, 56, 88, .07);
}
.enrollment-grant-card {
    padding: 1.25rem 1.4rem;
    border-top: 4px solid #277cff;
}
.enrollment-grant-card .section-heading h2,
.enrollment-list-section .section-heading h2 {
    font-size: 1.15rem;
}
.enrollment-grant-card .quiz-form-grid {
    grid-template-columns: 1fr 1fr auto;
    align-items: end;
}
.enrollment-grant-card .btn {
    min-height: 42px;
    white-space: nowrap;
}
.enrollment-list-section {
    padding: 1.25rem 1.4rem;
}
.enrollment-toolbar {
    border: 0;
    background: #f1f5fa;
}
.enrollment-page .admin-table-container {
    border: 0;
    box-shadow: 0 0 0 1px #e7edf4;
}
.enrollment-page .admin-table th {
    background: #eef4fb;
    color: #496581;
}
.enrollment-page .admin-table td {
    background: #fff;
}
.enrollment-page .admin-table tbody tr {
    transition: transform .15s ease, box-shadow .15s ease;
}
.enrollment-page .admin-table tbody tr:hover {
    position: relative;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 56, 88, .06);
}
.enrollment-student-link {
    font-weight: 700;
}
.enrollment-page .status-badge {
    border-radius: 999px;
    padding: .35rem .65rem;
    font-size: .72rem;
    font-weight: 700;
}
@media (max-width: 900px) {
    .enrollment-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .enrollment-grant-card .quiz-form-grid {
        grid-template-columns: 1fr 1fr;
    }
    .enrollment-grant-card .btn {
        grid-column: 1 / -1;
    }
}
@media (max-width: 600px) {
    .enrollment-page .page-header {
        padding: 1.15rem;
        border-radius: 12px;
    }
    .enrollment-summary {
        grid-template-columns: 1fr 1fr;
    }
    .enrollment-grant-card .quiz-form-grid {
        grid-template-columns: 1fr;
    }
    .enrollment-grant-card .btn {
        grid-column: auto;
        width: 100%;
    }
    .enrollment-list-section {
        padding: 1rem;
    }
}
</style>
<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script></body>
</html>
