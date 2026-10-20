<?php
require_once __DIR__ . '/../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";
require_once "../config/assessment_security_report.php";
require_once "../config/security_pdf.php";

if (!isset($_SESSION["user_id"]) || ($_SESSION["user_role"] ?? "") !== "admin") {
    http_response_code(403);
    exit("Accès interdit.");
}

$format = $_GET["format"] ?? "html";
$action = trim($_GET["action"] ?? "");
$role = trim($_GET["role"] ?? "");
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET["from"] ?? "") ? $_GET["from"] : "";
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET["to"] ?? "") ? $_GET["to"] : "";
$eventType = trim($_GET["event_type"] ?? "");
$period = ($_GET["period"] ?? "") === "24h" ? "24h" : "";
$archiveMode = ($_GET["view"] ?? "") === "history" ? "history" : "current";
$params = [];
$conditions = [];
if ($period === "24h") {
    $conditions[] = "l.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
} else {
    $conditions[] = "l.created_at >= ?";
    $params[] = $from !== "" ? $from . " 00:00:00" : "1900-01-01 00:00:00";
}
$conditions[] = $archiveMode === "history"
    ? "l.created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
    : "l.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
if ($action !== "") {
    $conditions[] = "l.action LIKE ?";
    $params[] = "%" . $action . "%";
}
if ($to !== "") {
    $conditions[] = "l.created_at <= ?";
    $params[] = $to . " 23:59:59";
}
if ($role !== "") {
    $conditions[] = "u.role = ?";
    $params[] = $role;
}
if ($eventType !== "" && preg_match('/^assessment_[a-z_]+$/', $eventType)) {
    $conditions[] = "l.action = ?";
    $params[] = $eventType;
}
$where = " WHERE " . implode(" AND ", $conditions);
$statement = $pdo->prepare(
    "SELECT l.*, u.first_name, u.last_name, u.email, u.role
     FROM (
         SELECT id, user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, created_at FROM audit_logs
         UNION ALL
         SELECT id, user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, created_at FROM audit_logs_history
     ) l LEFT JOIN users u ON u.id = l.user_id
     {$where} ORDER BY l.created_at DESC, l.id DESC LIMIT 1000"
);
$statement->execute($params);
$logs = $statement->fetchAll(PDO::FETCH_ASSOC);
$securityReports = assessmentSecurityReport($pdo, null, $period === "24h" ? "__last24h" : $from, $to, $role, 0, $eventType, $archiveMode);
$eventCount = count($logs);
$uniqueUsers = count(array_unique(array_filter(array_column($logs, "user_id"))));
$failedEvents = count(array_filter($logs, static function ($log): bool {
    return stripos((string) $log["action"], "fail") !== false || stripos((string) $log["action"], "error") !== false;
}));

if ($format === "pdf") {
    downloadSecurityLogsPdf($logs, "Journal de sécurité", false);
}
if ($format === "csv") {
    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=security-logs.csv");
    $output = fopen("php://output", "w");
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ["Date", "Utilisateur", "Rôle", "Action", "Entité", "ID entité", "IP", "Navigateur"], ";");
    foreach ($logs as $log) {
        fputcsv($output, [$log["created_at"], trim(($log["first_name"] ?? "") . " " . ($log["last_name"] ?? "")) ?: ($log["email"] ?? "Inconnu"), $log["role"] ?? "", assessmentSecurityEventLabel((string) $log["action"]), $log["entity_type"] ?? "", $log["entity_id"] ?? "", $log["ip_address"] ?? "", $log["user_agent"] ?? ""], ";");
    }
    fclose($output);
    exit;
}
function adminLogEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Journal de sécurité</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260925e">
    <style>
        @media print {
            .admin-sidebar, .admin-topbar a, .security-pdf-button, .security-log-filters,
            .security-log-summary, .security-dashboard-heading span { display: none !important; }
            .admin-layout, .admin-main { display: block !important; margin: 0 !important; width: 100% !important; }
            .admin-main { padding: 0 !important; }
            .dashboard-section { box-shadow: none !important; border: 0 !important; }
            .security-assessment-dashboard { break-inside: avoid; }
            .security-log-table { font-size: 9px; }
        }
    </style>
</head>

<body class="admin-body">
    <div class="admin-layout">
        <!-- ======================================================
         SIDEBAR
         ====================================================== -->

    <?php require_once __DIR__ . "/config/admin_sidebar.php"; renderAdminSidebar('security_logs.php'); ?>
        <main class="admin-main">
            <header class="admin-topbar">
                <div>
                    <h1>Journal de sécurité</h1>
                    <p><?= $archiveMode === "history" ? "Événements archivés depuis plus de 90 jours." : "Événements récents conservés pendant 90 jours." ?></p>
                </div><a class="btn btn-secondary security-pdf-button"
                    href="security_logs.php?format=pdf&amp;view=<?= urlencode($archiveMode) ?>&amp;action=<?= urlencode($action) ?>&amp;event_type=<?= urlencode($eventType) ?>&amp;role=<?= urlencode($role) ?>&amp;from=<?= urlencode($from) ?>&amp;to=<?= urlencode($to) ?>&amp;period=<?= urlencode($period) ?>">Télécharger PDF</a> <a class="btn btn-primary"
                    href="security_logs.php?format=csv&amp;view=<?= urlencode($archiveMode) ?>&amp;action=<?= urlencode($action) ?>&amp;event_type=<?= urlencode($eventType) ?>&amp;role=<?= urlencode($role) ?>&amp;from=<?= urlencode($from) ?>&amp;to=<?= urlencode($to) ?>&amp;period=<?= urlencode($period) ?>">Télécharger CSV</a> <?php if ($archiveMode === "history"): ?><a class="btn btn-secondary" href="security_logs.php?view=current">Journal actuel</a><?php else: ?><a class="btn btn-secondary" href="security_logs.php?view=history">Historique</a><?php endif; ?>
            </header>
            <section class="dashboard-section security-log-page">
                <div class="security-log-summary">
                    <article><strong><?= $eventCount ?></strong><span>Événements affichés</span></article>
                    <article><strong><?= $uniqueUsers ?></strong><span>Utilisateurs concernés</span></article>
                    <article><strong><?= $failedEvents ?></strong><span>Échecs / erreurs</span></article>
                </div>
                <form method="get" class="security-log-filters">
                    <label>Action<input name="action" value="<?= adminLogEscape($action) ?>" placeholder="login, reset_password..."></label>
                    <label>Type d'événement<select name="event_type"><option value="">Tous les événements</option><?php foreach (["assessment_tab_left" => "Onglet quitté", "assessment_fullscreen_exited" => "Plein écran quitté", "assessment_multiple_session" => "Session multiple", "assessment_suspicious_ip" => "IP suspecte", "assessment_suspicious_device" => "Appareil suspect", "assessment_time_expired" => "Temps écoulé", "assessment_copy_blocked" => "Copie bloquée"] as $value => $label): ?><option value="<?= $value ?>" <?= $eventType === $value ? "selected" : "" ?>><?= $label ?></option><?php endforeach; ?></select></label>
                    <label>Rôle<select name="role"><option value="">Tous les rôles</option><?php foreach (["admin" => "Administrateur", "teacher" => "Formateur", "student" => "Étudiant", "company_manager" => "Entreprise"] as $value => $label): ?><option value="<?= $value ?>" <?= $role === $value ? "selected" : "" ?>><?= $label ?></option><?php endforeach; ?></select></label>
                    <label>Période<select name="period"><option value="">Historique complet</option><option value="24h" <?= $period === "24h" ? "selected" : "" ?>>Dernières 24 heures</option></select></label>
                    <label>Du<input type="date" name="from" value="<?= adminLogEscape($from) ?>"></label>
                    <label>Au<input type="date" name="to" value="<?= adminLogEscape($to) ?>"></label>
                    <div><button class="admin-button" type="submit">Filtrer</button><a class="button secondary-button" href="security_logs.php">Réinitialiser</a></div>
                </form>
                <section class="security-assessment-dashboard">
                    <div class="security-dashboard-heading"><div><p class="student-eyebrow">Assessment security</p><h2>Suspicious assessment activity</h2></div><span><?= count($securityReports) ?> assessment(s)</span></div>
                    <div class="admin-table-container">
                        <table class="admin-table security-assessment-table">
                            <thead><tr><th>Student</th><th>Assessment</th><th>Events</th><th>Last event</th><th>IP</th><th>Browser / device</th><th>Violations</th><th>Risk</th></tr></thead>
                            <tbody><?php if (!$securityReports): ?><tr><td colspan="8">No suspicious assessment events found.</td></tr><?php else: foreach ($securityReports as $report): ?><tr>
                                <td><strong><?= adminLogEscape(trim($report["first_name"] . " " . $report["last_name"])) ?></strong><br><small><?= adminLogEscape($report["email"]) ?></small></td>
                                <td><?= adminLogEscape(ucfirst($report["entity_type"]) . ": " . $report["assessment_title"]) ?></td>
                                <td class="security-event-list"><?php foreach (explode(", ", (string) $report["event_types"]) as $event): ?><span><?= adminLogEscape(assessmentSecurityEventLabel($event)) ?></span><?php endforeach; ?></td>
                                <td><?= adminLogEscape($report["latest_event"]) ?></td>
                                <td><?= adminLogEscape($report["ip_addresses"] ?: "—") ?></td>
                                <td><?= adminLogEscape($report["devices"] ?: "—") ?></td>
                                <td><strong><?= (int) $report["violation_count"] ?></strong></td>
                                <td><span class="security-risk security-risk-<?= $report["risk"] ?>"><?= ucfirst($report["risk"]) ?></span></td>
                            </tr><?php endforeach; endif; ?></tbody>
                        </table>
                    </div>
                </section>
                <div class="admin-table-container">
                    <table class="admin-table security-log-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Utilisateur</th>
                                <th>Rôle</th>
                                <th>Action</th>
                                <th>Entité</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody><?php if (!$logs): ?>
                                <tr>
                                    <td colspan="6">Aucun événement enregistré.</td>
                                </tr><?php else:
                            foreach ($logs as $log): ?>
                                    <tr>
                                        <td><?= adminLogEscape($log["created_at"]) ?></td>
                                        <td><?= adminLogEscape(trim(($log["first_name"] ?? "") . " " . ($log["last_name"] ?? "")) ?: ($log["email"] ?? "Inconnu")) ?>
                                        </td>
                                        <td><?= adminLogEscape($log["role"] ?? "") ?></td>
                                        <td><strong><?= adminLogEscape(assessmentSecurityEventLabel((string) $log["action"])) ?></strong><br><small><?= adminLogEscape($log["action"]) ?></small></td>
                                        <td><?= adminLogEscape($log["entity_type"] ?? "") ?>
                                            <?= $log["entity_id"] ? "#" . (int) $log["entity_id"] : "" ?></td>
                                        <td><?= adminLogEscape($log["ip_address"] ?? "") ?></td>
                                    </tr><?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
<script src="../assets/js/admin-sidebar.js?v=20260925e"></script></body>

</html>



