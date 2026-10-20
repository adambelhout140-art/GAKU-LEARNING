<?php
require_once __DIR__ . '/../../config/i18n.php';// ! READ-ONLY ADMIN USER PROFILE
// * Displays account information without exposing the password hash or allowing edits.
// ? Access is limited to administrators because this page contains personal data.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once "../../config/database.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../auth/login.php");
    exit;
}

if (!isset($_SESSION["user_role"]) || $_SESSION["user_role"] !== "admin") {
    header("Location: ../../index.php");
    exit;
}

$userId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$userId || $userId < 1) {
    header("Location: users.php");
    exit;
}

// * LEFT JOIN keeps users without a company visible and adds the company name when one exists.
$userStatement = $pdo->prepare("
    SELECT
        users.id,
        users.first_name,
        users.last_name,
        users.date_of_birth,
        users.cin,
        users.education,
        users.job_title,
        users.email,
        users.phone,
        users.role,
        users.is_active,
        users.created_at,
        users.updated_at,
        companies.company_name
    FROM users
    LEFT JOIN companies ON companies.id = users.company_id
    WHERE users.id = ?
    LIMIT 1
");
$userStatement->execute([$userId]);
$user = $userStatement->fetch();

if (!$user) {
    header("Location: users.php");
    exit;
}

// * Technical role values are converted into labels suitable for the French admin interface.
$roleLabels = [
    "student" => "Apprenant",
    "company_manager" => "Responsable entreprise",
    "teacher" => "Formateur",
    "admin" => "Administrateur"
];

// * These display values are calculated once and escaped when rendered below.
$fullName = trim($user["first_name"] . " " . $user["last_name"]);
$roleLabel = $roleLabels[$user["role"]] ?? $user["role"];
$age = $user["date_of_birth"] ? (new DateTimeImmutable($user["date_of_birth"]))->diff(new DateTimeImmutable("today"))->y : null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voir un utilisateur - Administration</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">
</head>
<body class="admin-body">
<div class="admin-layout">
        <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('users/see_user.php'); ?>

    <main class="admin-main">
        <header class="admin-topbar">
            <div>
                <h1>Profil utilisateur</h1>
                <p>Consulter les informations du compte</p>
            </div>
            <a href="users.php" class="btn btn-secondary">Retour aux utilisateurs</a>
        </header>

        <section class="dashboard-section">
            <div class="form-card user-profile-card">
                <div class="user-profile-heading">
                    <div class="admin-avatar"><?= htmlspecialchars(strtoupper(substr($fullName ?: "U", 0, 1))) ?></div>
                    <div>
                        <h2><?= htmlspecialchars($fullName ?: "Utilisateur sans nom") ?></h2>
                        <p><?= htmlspecialchars($user["email"]) ?></p>
                    </div>
                </div>

                <div class="user-details-grid">
                    <div class="user-detail">
                        <span>Identifiant</span>
                        <strong>#<?= (int) $user["id"] ?></strong>
                    </div>
                    <div class="user-detail">
                        <span>Rôle</span>
                        <strong><?= htmlspecialchars($roleLabel) ?></strong>
                    </div>
                    <div class="user-detail">
                        <span>Entreprise</span>
                        <strong><?= htmlspecialchars($user["company_name"] ?? "Aucune entreprise") ?></strong>
                    </div>
                    <div class="user-detail">
                        <span>Téléphone</span>
                        <strong><?= htmlspecialchars($user["phone"] ?: "Non renseigné") ?></strong>
                    </div>
                    <div class="user-detail">
                        <span>Date de naissance</span>
                        <strong><?= $user["date_of_birth"] ? htmlspecialchars(date("d/m/Y", strtotime($user["date_of_birth"]))) : "Non renseignée" ?></strong>
                    </div>
                    <div class="user-detail">
                        <span>Âge</span>
                        <strong><?= $age === null ? "Non renseigné" : $age . " ans" ?></strong>
                    </div>
                    <div class="user-detail">
                        <span>CIN</span>
                        <strong><?= htmlspecialchars($user["cin"] ?: "Non renseigné") ?></strong>
                    </div>
                    <div class="user-detail">
                        <span>Études / établissement</span>
                        <strong><?= htmlspecialchars($user["education"] ?: "Non renseigné") ?></strong>
                    </div>
                    <div class="user-detail">
                        <span>Poste dans l'entreprise</span>
                        <strong><?= htmlspecialchars($user["job_title"] ?: "Non renseigné") ?></strong>
                    </div>
                    <div class="user-detail">
                        <span>Statut</span>
                        <strong class="<?= $user["is_active"] ? "profile-status-active" : "profile-status-inactive" ?>">
                            <?= $user["is_active"] ? "Actif" : "Inactif" ?>
                        </strong>
                    </div>
                    <div class="user-detail">
                        <span>Créé le</span>
                        <strong><?= htmlspecialchars(date("d/m/Y à H:i", strtotime($user["created_at"]))) ?></strong>
                    </div>
                    <div class="user-detail">
                        <span>Dernière modification</span>
                        <strong><?= htmlspecialchars(date("d/m/Y à H:i", strtotime($user["updated_at"]))) ?></strong>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="users.php" class="btn btn-secondary">Annuler</a>
                    <a href="user_edit.php?id=<?= (int) $user["id"] ?>" class="btn btn-primary">Modifier l'utilisateur</a>
                </div>
            </div>
        </section>
    </main>
</div>
<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script></body>
</html>



