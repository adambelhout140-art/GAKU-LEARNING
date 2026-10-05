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

$userId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
if (!$userId || $userId < 1) {
    http_response_code(400);
    exit("Utilisateur invalide.");
}

$statement = $pdo->prepare(
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
     WHERE u.id = ?
     LIMIT 1"
);
$statement->execute([$userId]);
$user = $statement->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(404);
    exit("Utilisateur introuvable.");
}

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

$fullName = trim(($user["first_name"] ?? "") . " " . ($user["last_name"] ?? ""));
$role = $roleLabels[$user["role"]] ?? $user["role"];
$status = (int) $user["is_active"] === 1 ? "Actif" : "Inactif";

$html = '<!doctype html><html lang="fr"><head><meta charset="UTF-8"><style>
    @page { margin: 34px; }
    body { font-family: DejaVu Sans, sans-serif; color: #172b4d; font-size: 11px; }
    h1 { color: #102a43; font-size: 22px; margin: 0 0 5px; }
    h2 { color: #1261a0; font-size: 14px; margin: 24px 0 8px; }
    .meta { color: #627d98; margin: 0 0 22px; }
    .identity { padding: 15px; border: 1px solid #cbd5e1; background: #f5f9fc; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #cbd5e1; padding: 9px; text-align: left; vertical-align: top; }
    th { width: 32%; background: #edf5fb; color: #1261a0; }
    .active { color: #087f5b; font-weight: bold; }
    .inactive { color: #b42318; font-weight: bold; }
</style></head><body>';

$html .= "<h1>Fiche utilisateur</h1>";
$html .= '<p class="meta">Document généré le ' . $escape(date("d/m/Y H:i")) . "</p>";
$html .= '<div class="identity"><strong>' . $escape($fullName ?: "Utilisateur sans nom") .
    "</strong><br>" . $escape($user["email"]) . "</div>";
$html .= "<h2>Informations personnelles et professionnelles</h2><table>";
$rows = [
    "Identifiant" => (int) $user["id"],
    "Prénom" => $user["first_name"],
    "Nom" => $user["last_name"],
    "Email" => $user["email"],
    "Téléphone" => $user["phone"] ?: "—",
    "Date de naissance" => $user["date_of_birth"] ?: "—",
    "CIN" => $user["cin"] ?: "—",
    "Formation / éducation" => $user["education"] ?: "—",
    "Poste" => $user["job_title"] ?: "—",
    "Entreprise" => $user["company_name"] ?: "Aucune entreprise",
    "Rôle" => $role,
    "Statut" => $status,
    "Compte créé le" => $date($user["created_at"]),
    "Dernière modification" => $date($user["updated_at"])
];

foreach ($rows as $label => $value) {
    $class = $label === "Statut" && $status === "Actif" ? ' class="active"' :
        ($label === "Statut" ? ' class="inactive"' : "");
    $html .= "<tr><th>" . $escape($label) . "</th><td" . $class . ">" .
        $escape($value) . "</td></tr>";
}

$html .= "</table></body></html>";

$options = new Options();
$options->set("defaultFont", "DejaVu Sans");
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, "UTF-8");
$dompdf->setPaper("A4", "portrait");
$dompdf->render();
$dompdf->stream("utilisateur-" . (int) $user["id"] . ".pdf", ["Attachment" => true]);
exit;
