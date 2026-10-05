<?php
require_once __DIR__ . "/../../config/i18n.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
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

require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../vendor/autoload.php";

use Dompdf\Dompdf;
use Dompdf\Options;

$statement = $pdo->query(
    "SELECT
        u.id,
        u.first_name,
        u.last_name,
        u.date_of_birth,
        u.cin,
        u.education,
        u.job_title,
        u.email,
        u.phone,
        u.role,
        u.is_active,
        u.created_at,
        u.updated_at,
        c.company_name
     FROM users u
     LEFT JOIN companies c ON c.id = u.company_id
     ORDER BY u.last_name ASC, u.first_name ASC, u.id ASC"
);
$users = $statement->fetchAll(PDO::FETCH_ASSOC);

$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
};

$date = static function ($value): string {
    return $value ? date("d/m/Y H:i", strtotime($value)) : "—";
};

$roleLabels = [
    "student" => "Apprenant",
    "parent" => "Parent",
    "company_manager" => "Responsable entreprise",
    "teacher" => "Formateur",
    "admin" => "Administrateur"
];

$activeUsers = 0;
foreach ($users as $user) {
    if ((int) $user["is_active"] === 1) {
        $activeUsers++;
    }
}

$html = '<!doctype html><html lang="fr"><head><meta charset="UTF-8"><style>
    @page { margin: 28px 24px; }
    body { font-family: DejaVu Sans, sans-serif; color: #172b4d; font-size: 8px; }
    h1 { color: #102a43; font-size: 19px; margin: 0 0 5px; }
    .meta { color: #627d98; margin: 0 0 16px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #1261a0; color: #fff; font-weight: bold; }
    th, td { border: 1px solid #cbd5e1; padding: 5px 4px; vertical-align: top; }
    tr:nth-child(even) { background: #f5f9fc; }
    .active { color: #087f5b; font-weight: bold; }
    .inactive { color: #b42318; font-weight: bold; }
    .small { color: #627d98; font-size: 7px; }
</style></head><body>';

$html .= "<h1>Annuaire complet des utilisateurs</h1>";
$html .= '<p class="meta">Généré le ' . $escape(date("d/m/Y H:i")) .
    " · " . count($users) . " utilisateur(s) · " . $activeUsers . " compte(s) actif(s)</p>";
$html .= "<table><thead><tr>
    <th>ID</th><th>Nom complet</th><th>Contact</th><th>Identité</th>
    <th>Profil professionnel</th><th>Entreprise</th><th>Rôle</th>
    <th>Statut</th><th>Créé le</th><th>Modifié le</th>
</tr></thead><tbody>";

foreach ($users as $user) {
    $fullName = trim(($user["first_name"] ?? "") . " " . ($user["last_name"] ?? ""));
    $role = $roleLabels[$user["role"]] ?? $user["role"];
    $status = (int) $user["is_active"] === 1
        ? '<span class="active">Actif</span>'
        : '<span class="inactive">Inactif</span>';

    $html .= "<tr>";
    $html .= "<td>" . (int) $user["id"] . "</td>";
    $html .= "<td><strong>" . $escape($fullName !== "" ? $fullName : "—") . "</strong></td>";
    $html .= "<td>" . $escape($user["email"]) . "<br><span class=\"small\">" .
        $escape($user["phone"] ?: "Téléphone non renseigné") . "</span></td>";
    $html .= "<td>Date de naissance : " . $escape($user["date_of_birth"] ?: "—") .
        "<br>CIN : " . $escape($user["cin"] ?: "—") . "</td>";
    $html .= "<td>Poste : " . $escape($user["job_title"] ?: "—") .
        "<br>Formation : " . $escape($user["education"] ?: "—") . "</td>";
    $html .= "<td>" . $escape($user["company_name"] ?: "Aucune entreprise") . "</td>";
    $html .= "<td>" . $escape($role) . "</td>";
    $html .= "<td>" . $status . "</td>";
    $html .= "<td>" . $escape($date($user["created_at"])) . "</td>";
    $html .= "<td>" . $escape($date($user["updated_at"])) . "</td>";
    $html .= "</tr>";
}

if (!$users) {
    $html .= '<tr><td colspan="10">Aucun utilisateur enregistré.</td></tr>';
}

$html .= "</tbody></table></body></html>";

$options = new Options();
$options->set("defaultFont", "DejaVu Sans");
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, "UTF-8");
$dompdf->setPaper("A4", "landscape");
$dompdf->render();
$dompdf->stream("utilisateurs-" . date("Y-m-d") . ".pdf", ["Attachment" => true]);
exit;
