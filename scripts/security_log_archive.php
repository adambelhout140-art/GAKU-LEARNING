<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit("CLI uniquement.\n");
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/assessment_security_report.php";
require_once __DIR__ . "/../vendor/autoload.php";

use Dompdf\Dompdf;
use Dompdf\Options;

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS audit_logs_history (
        id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        user_id INT UNSIGNED NULL,
        action VARCHAR(100) NOT NULL,
        entity_type VARCHAR(100) NULL,
        entity_id INT UNSIGNED NULL,
        old_values JSON NULL,
        new_values JSON NULL,
        ip_address VARCHAR(45) NULL,
        user_agent TEXT NULL,
        created_at TIMESTAMP NULL DEFAULT NULL,
        archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_history_user (user_id),
        KEY idx_history_created (created_at),
        KEY idx_history_action (action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$pdo->beginTransaction();
$pdo->exec(
    "INSERT IGNORE INTO audit_logs_history
        (id, user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, created_at)
     SELECT id, user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, created_at
     FROM audit_logs
     WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
);
$pdo->exec("DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
$pdo->commit();

$date = $argv[1] ?? date("Y-m-d", strtotime("-1 day"));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    fwrite(STDERR, "Date invalide. Utilisez YYYY-MM-DD.\n");
    exit(1);
}

$start = $date . " 00:00:00";
$end = date("Y-m-d H:i:s", strtotime($date . " +1 day"));
$statement = $pdo->prepare(
    "SELECT l.created_at, l.user_id, u.first_name, u.last_name, u.email, u.role,
            l.action, l.entity_type, l.entity_id, l.ip_address, l.user_agent
     FROM audit_logs l
     LEFT JOIN users u ON u.id = l.user_id
     WHERE l.created_at >= ? AND l.created_at < ?
     ORDER BY l.created_at ASC, l.id ASC"
);
$statement->execute([$start, $end]);

$directory = __DIR__ . "/../storage/security-logs";
if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
    fwrite(STDERR, "Impossible de créer le dossier d'archives.\n");
    exit(1);
}

$path = $directory . "/security-" . $date . ".csv";
$output = fopen($path, "wb");
if ($output === false) {
    fwrite(STDERR, "Impossible d'écrire l'archive.\n");
    exit(1);
}
fwrite($output, "\xEF\xBB\xBF");
$rows = [];
fputcsv($output, ["Date", "Utilisateur", "Email", "Rôle", "Action", "Entité", "ID entité", "IP", "Navigateur"], ";");
while ($log = $statement->fetch(PDO::FETCH_ASSOC)) {
    $name = trim(($log["first_name"] ?? "") . " " . ($log["last_name"] ?? ""));
    $row = [
        $log["created_at"],
        $name !== "" ? $name : ($log["user_id"] ? "Utilisateur #" . $log["user_id"] : "Système"),
        $log["email"] ?? "",
        $log["role"] ?? "",
        assessmentSecurityEventLabel((string) $log["action"]),
        $log["entity_type"] ?? "",
        $log["entity_id"] ?? "",
        $log["ip_address"] ?? "",
        $log["user_agent"] ?? ""
    ];
    $rows[] = $row;
    fputcsv($output, $row, ";");
}
fclose($output);

$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
};
$html = '<!doctype html><html><head><meta charset="UTF-8"><style>
    @page { margin: 24px; }
    body { font-family: DejaVu Sans, sans-serif; color: #172b4d; font-size: 8px; }
    h1 { font-size: 18px; margin: 0 0 6px; }
    p { color: #526174; margin: 0 0 16px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #172b4d; color: #fff; font-weight: bold; }
    th, td { border: 1px solid #cbd5e1; padding: 4px; vertical-align: top; }
    tr:nth-child(even) { background: #f8fafc; }
</style></head><body>';
$html .= "<h1>Journal de sécurité - " . $escape($date) . "</h1>";
$html .= "<p>" . count($rows) . " événement(s) · Archive générée le " . $escape(date("d/m/Y H:i")) . "</p>";
$html .= "<table><thead><tr><th>Date</th><th>Utilisateur</th><th>Email</th><th>Rôle</th><th>Action</th><th>Entité</th><th>ID</th><th>IP</th><th>Navigateur</th></tr></thead><tbody>";
foreach ($rows as $row) {
    $html .= "<tr>";
    foreach ($row as $value) {
        $html .= "<td>" . $escape($value) . "</td>";
    }
    $html .= "</tr>";
}
if (!$rows) {
    $html .= '<tr><td colspan="9">Aucun événement enregistré.</td></tr>';
}
$html .= "</tbody></table></body></html>";

$options = new Options();
$options->set("defaultFont", "DejaVu Sans");
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, "UTF-8");
$dompdf->setPaper("A4", "landscape");
$dompdf->render();
$pdfPath = $directory . "/security-" . $date . ".pdf";
file_put_contents($pdfPath, $dompdf->output());

echo "CSV créée : {$path}\n";
echo "PDF créé : {$pdfPath}\n";

