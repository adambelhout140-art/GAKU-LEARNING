<?php
require_once __DIR__ . '/../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION["user_id"]) || ($_SESSION["user_role"] ?? "") !== "admin") {
    http_response_code(403);
    exit("Accès interdit.");
}
require_once "../config/database.php";
if (empty($_SESSION["csrf_token"])) $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
$message = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    $id = filter_input(INPUT_POST, "certificate_id", FILTER_VALIDATE_INT);
    if (!$token || !hash_equals($_SESSION["csrf_token"], $token) || !$id) {
        $message = "Action invalide.";
    } else {
        $delete = $pdo->prepare("DELETE FROM certificates WHERE id = ?");
        $delete->execute([$id]);
        $message = $delete->rowCount() ? "Certificat supprimé." : "Certificat introuvable.";
    }
}
$certificates = $pdo->query(
    "SELECT cert.id, cert.certificate_number, cert.issue_date, cert.pdf_path,
            u.first_name, u.last_name, u.email, c.title AS course_title,
            a.percentage AS exam_percentage, a.quiz_average_percentage,
            a.quiz_count, a.combined_percentage
     FROM certificates cert
     INNER JOIN users u ON u.id = cert.user_id
     INNER JOIN courses c ON c.id = cert.course_id
     INNER JOIN exam_attempts a ON a.id = cert.exam_attempt_id
     ORDER BY cert.issue_date DESC, cert.id DESC"
)->fetchAll(PDO::FETCH_ASSOC);
function adminCertificatesEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificats - Administration</title>
    <link rel="icon" type="image/png" href="../assets/images/logo-mark.png">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260925e">
</head>

<body class="admin-body">
    <div class="admin-layout">
        <!-- ======================================================
         SIDEBAR
         ====================================================== -->

    <?php require_once __DIR__ . "/config/admin_sidebar.php"; renderAdminSidebar('certificates.php'); ?>
        <main class="admin-main">
            <header class="admin-topbar">
                <div>
                    <h1>Administration des certificats</h1>
                    <p>Vérifiez les certificats générés et supprimez un certificat invalide.</p>
                </div>
            </header>
            <section class="admin-content"><?php if ($message): ?><div class="alert"><?= adminCertificatesEscape($message) ?></div><?php endif; ?><div class="admin-table-wrapper">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Numéro</th>
                                <th>étudiant</th>
                                <th>Formation</th>
                                <th>Résultat final</th>
                                <th>Délivré le</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody><?php if (!$certificates): ?><tr>
                                    <td colspan="6">Aucun certificat.</td>
                                </tr><?php else: foreach ($certificates as $certificate): ?><tr>
                                        <td><?= adminCertificatesEscape($certificate["certificate_number"]) ?></td>
                                        <td><?= adminCertificatesEscape(trim($certificate["first_name"] . " " . $certificate["last_name"])) ?><br><small><?= adminCertificatesEscape($certificate["email"]) ?></small></td>
                                        <td><?= adminCertificatesEscape($certificate["course_title"]) ?></td>
                                        <td><?= adminCertificatesEscape($certificate["combined_percentage"]) ?>%<br><small>Quiz : <?= $certificate["quiz_average_percentage"] !== null ? adminCertificatesEscape($certificate["quiz_average_percentage"]) . "%" : ((int) ($certificate["quiz_count"] ?? -1) === 0 ? "aucun" : "n/d") ?> · Examen : <?= adminCertificatesEscape($certificate["exam_percentage"]) ?>%</small></td>
                                        <td><?= date("d/m/Y", strtotime($certificate["issue_date"])) ?></td>
                                        <td><a class="admin-button secondary-button" target="_blank" href="../student/certificates/certificate.php?id=<?= (int) $certificate["id"] ?>">Voir</a>
                                            <form method="post" style="display:inline" onsubmit="return confirm('Supprimer ce certificat ?');"><input type="hidden" name="csrf_token" value="<?= adminCertificatesEscape($_SESSION["csrf_token"]) ?>"><input type="hidden" name="certificate_id" value="<?= (int) $certificate["id"] ?>"><button class="admin-button danger-button" type="submit">Supprimer</button></form>
                                        </td>
                                    </tr><?php endforeach;
                                    endif; ?></tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
<script src="../assets/js/admin-sidebar.js?v=20260925e"></script></body>

</html>
