<?php
require_once dirname(__DIR__) . "/vendor/autoload.php";
require_once __DIR__ . "/assessment_security_report.php";

use Dompdf\Dompdf;
use Dompdf\Options;

function downloadSecurityLogsPdf(array $logs, string $title, bool $teacherView = false): void
{
    $escape = static function ($value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
    };
    $html = '<!doctype html><html><head><meta charset="UTF-8"><style>
        @page { margin: 24px; }
        body { font-family: DejaVu Sans, sans-serif; color: #172b4d; font-size: 9px; }
        h1 { font-size: 18px; margin: 0 0 6px; }
        p { color: #526174; margin: 0 0 16px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #172b4d; color: #fff; font-weight: bold; }
        th, td { border: 1px solid #cbd5e1; padding: 5px; vertical-align: top; }
        tr:nth-child(even) { background: #f8fafc; }
    </style></head><body>';
    $html .= "<h1>" . $escape($title) . "</h1>";
    $html .= "<p>Généré le " . $escape(date("d/m/Y H:i")) . " · " . count($logs) . " événement(s)</p>";
    $html .= "<table><thead><tr><th>Date</th><th>" . ($teacherView ? "Étudiant" : "Utilisateur") . "</th>";
    if (!$teacherView) {
        $html .= "<th>Rôle</th>";
    }
    $html .= "<th>Action</th><th>Entité</th><th>IP</th><th>Navigateur</th></tr></thead><tbody>";
    foreach ($logs as $log) {
        $name = trim(($log["first_name"] ?? "") . " " . ($log["last_name"] ?? ""));
        $html .= "<tr><td>" . $escape($log["created_at"]) . "</td>";
        $html .= "<td>" . $escape($name !== "" ? $name : ($log["email"] ?? "Inconnu")) . "</td>";
        if (!$teacherView) {
            $html .= "<td>" . $escape($log["role"] ?? "") . "</td>";
        }
        $html .= "<td>" . $escape(assessmentSecurityEventLabel((string) $log["action"])) . "</td>";
        $html .= "<td>" . $escape(($log["entity_type"] ?? "") . ($log["entity_id"] ? " #" . $log["entity_id"] : "")) . "</td>";
        $html .= "<td>" . $escape($log["ip_address"] ?? "") . "</td>";
        $html .= "<td>" . $escape($log["user_agent"] ?? "") . "</td></tr>";
    }
    if (!$logs) {
        $html .= '<tr><td colspan="' . ($teacherView ? "6" : "7") . '">Aucun événement enregistré.</td></tr>';
    }
    $html .= "</tbody></table></body></html>";

    $options = new Options();
    $options->set("defaultFont", "DejaVu Sans");
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, "UTF-8");
    $dompdf->setPaper("A4", "landscape");
    $dompdf->render();
    $dompdf->stream("security-logs-" . date("Y-m-d") . ".pdf", ["Attachment" => true]);
    exit;
}


