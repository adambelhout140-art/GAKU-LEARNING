<?php
require_once __DIR__ . '/../config/i18n.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";
require_once "../config/audit.php";
require_once "../config/notifications.php";

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_SESSION["user_id"]) || !in_array($_SESSION["user_role"] ?? "", ["student", "teacher", "admin"], true)) {
    http_response_code(403);
    echo json_encode(["ok" => false]);
    exit;
}

$csrfToken = $_POST["csrf_token"] ?? "";
if (empty($_SESSION["csrf_token"]) || !hash_equals($_SESSION["csrf_token"], $csrfToken)) {
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "csrf"]);
    exit;
}

$allowedActions = [
    "assessment_started",
    "assessment_copy_blocked",
    "assessment_cut_blocked",
    "assessment_paste_blocked",
    "assessment_contextmenu_blocked",
    "assessment_shortcut_blocked",
    "assessment_tab_left",
    "assessment_fullscreen_requested",
    "assessment_fullscreen_exited",
    "assessment_leave_warning",
    "assessment_time_expired",
    "assessment_multiple_session",
    "assessment_suspicious_ip",
    "assessment_suspicious_device",
];
$action = $_POST["action"] ?? "";
if (!in_array($action, $allowedActions, true)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "invalid_action"]);
    exit;
}

$entityId = filter_input(INPUT_POST, "assessment_id", FILTER_VALIDATE_INT);
$entityType = ($_POST["assessment_type"] ?? "") === "exam" ? "exam" : "quiz";
$sessionToken = (string) ($_POST["session_token"] ?? "");
$detectedActions = [];
if (preg_match('/^[a-f0-9]{64}$/', $sessionToken) && $entityId && in_array($entityType, ["quiz", "exam"], true)) {
    $sessionStatement = $pdo->prepare(
        "SELECT ip_address, user_agent FROM assessment_sessions
         WHERE user_id = ? AND assessment_type = ? AND assessment_id = ? AND session_token = ?
         LIMIT 1"
    );
    $sessionStatement->execute([(int) $_SESSION["user_id"], $entityType, $entityId, $sessionToken]);
    $session = $sessionStatement->fetch(PDO::FETCH_ASSOC);
    if ($session) {
        if (!empty($session["ip_address"]) && $session["ip_address"] !== ($_SERVER["REMOTE_ADDR"] ?? "")) {
            auditLog($pdo, (int) $_SESSION["user_id"], "assessment_suspicious_ip", $entityType, $entityId);
            $detectedActions[] = "assessment_suspicious_ip";
        }
        if (!empty($session["user_agent"]) && $session["user_agent"] !== ($_SERVER["HTTP_USER_AGENT"] ?? "")) {
            auditLog($pdo, (int) $_SESSION["user_id"], "assessment_suspicious_device", $entityType, $entityId);
            $detectedActions[] = "assessment_suspicious_device";
        }
    }
}
auditLog($pdo, (int) $_SESSION["user_id"], $action, $entityType, $entityId && $entityId > 0 ? $entityId : null);

$alertActions = [
    "assessment_multiple_session" => "Session multiple détectée",
    "assessment_suspicious_ip" => "Adresse IP suspecte détectée",
    "assessment_suspicious_device" => "Appareil suspect détecté",
    "assessment_time_expired" => "Temps d'évaluation écoulé"
];
if (isset($alertActions[$action]) && $entityId) {
    $subject = $entityType === "exam" ? "examen" : "quiz";
    $message = $alertActions[$action] . " pour le {$subject} #{$entityId}. Vérifiez le journal de sécurité.";
    $recipients = array_merge(
        securityAdministratorIds($pdo),
        securityAssessmentTeacherIds($pdo, $entityType, (int) $entityId)
    );
    notifySecurityUsers($pdo, $recipients, "Alerte de sécurité d'évaluation", $message);
}
foreach ($detectedActions as $detectedAction) {
    $subject = $entityType === "exam" ? "examen" : "quiz";
    notifySecurityUsers(
        $pdo,
        array_merge(securityAdministratorIds($pdo), securityAssessmentTeacherIds($pdo, $entityType, (int) $entityId)),
        "Alerte de sécurité d'évaluation",
        $alertActions[$detectedAction] . " pour le {$subject} #{$entityId}. Vérifiez le journal de sécurité."
    );
}

if ($entityId && in_array($action, [
    "assessment_tab_left",
    "assessment_fullscreen_exited",
    "assessment_copy_blocked",
    "assessment_cut_blocked",
    "assessment_paste_blocked",
    "assessment_contextmenu_blocked",
    "assessment_shortcut_blocked"
], true)) {
    $countStatement = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_logs
         WHERE user_id = ? AND entity_type = ? AND entity_id = ?
           AND action IN (
               'assessment_tab_left',
               'assessment_fullscreen_exited',
               'assessment_copy_blocked',
               'assessment_cut_blocked',
               'assessment_paste_blocked',
               'assessment_contextmenu_blocked',
               'assessment_shortcut_blocked'
           )
           AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
    );
    $countStatement->execute([(int) $_SESSION["user_id"], $entityType, $entityId]);
    if ((int) $countStatement->fetchColumn() >= 5) {
        $subject = $entityType === "exam" ? "examen" : "quiz";
        notifySecurityUsers(
            $pdo,
            array_merge(securityAdministratorIds($pdo), securityAssessmentTeacherIds($pdo, $entityType, (int) $entityId)),
            "Violations répétées détectées",
            "Un étudiant a généré au moins 5 alertes de sécurité pour le {$subject} #{$entityId} en 30 minutes."
        );
    }
}
echo json_encode(["ok" => true]);

