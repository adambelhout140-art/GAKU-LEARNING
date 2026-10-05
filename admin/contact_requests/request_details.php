<?php
require_once __DIR__ . "/../../config/i18n.php";
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../../config/database.php";

if (!isset($_SESSION["user_id"]) || ($_SESSION["user_role"] ?? "") !== "admin") {
    header("Location: ../../auth/login.php");
    exit;
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$requestId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
if (!$requestId) {
    header("Location: contact_requests.php?error=update");
    exit;
}

$statement = $pdo->prepare("SELECT contact_requests.*, courses.title AS course_title FROM contact_requests LEFT JOIN courses ON courses.id = contact_requests.course_id WHERE contact_requests.id = ? LIMIT 1");
$statement->execute([$requestId]);
$request = $statement->fetch(PDO::FETCH_ASSOC);
if (!$request) {
    header("Location: contact_requests.php?error=not_found");
    exit;
}

$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
};
$isAccessRequest = ($request["request_kind"] ?? "") === "access";
$studentExists = !empty($request["created_student_id"]);
$parentExists = !empty($request["created_parent_id"]);
$createdAccountsActive = false;
if ($studentExists || $parentExists) {
    $accountIds = array_values(array_filter([(int) $request["created_student_id"], (int) $request["created_parent_id"]]));
    $placeholders = implode(",", array_fill(0, count($accountIds), "?"));
    $accountStatement = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id IN ($placeholders) AND is_active = 1");
    $accountStatement->execute($accountIds);
    $createdAccountsActive = (int) $accountStatement->fetchColumn() === count($accountIds);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détails de la demande #<?= (int) $requestId ?> - Administration</title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">
    <style>
    .request-details-card {
        max-width: 980px;
        margin: 0 auto;
    }
    .request-details-card > h2 {
        margin-bottom: .35rem;
        color: #102a43;
    }
    .request-details-card > p {
        margin: .45rem 0;
    }
    .request-details-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
        margin: 1.5rem 0;
    }
    .request-details-grid > div,
    .request-account-links {
        padding: 1.1rem;
        border: 1px solid #dbe7f3;
        border-radius: 12px;
        background: #f8fbfd;
    }
    .request-details-grid h3,
    .request-account-links h3 {
        margin-top: 0;
        color: #163a63;
    }
    .request-details-grid p {
        margin: .55rem 0;
        overflow-wrap: anywhere;
    }
    .request-refusal-form {
        max-width: 620px;
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid #dbe7f3;
    }
    .request-refusal-form label {
        display: block;
        margin-bottom: .4rem;
        font-weight: 700;
    }
    .request-refusal-form textarea {
        width: 100%;
        min-height: 100px;
        margin-bottom: .75rem;
        box-sizing: border-box;
        resize: vertical;
    }
    .request-account-links {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .55rem;
        margin: 1rem 0;
    }
    .request-account-links h3 {
        flex-basis: 100%;
        margin-bottom: .1rem;
    }
    @media (max-width: 700px) {
        .request-details-grid {
            grid-template-columns: 1fr;
        }
        .request-details-card {
            padding: 1rem;
        }
        .request-details-card .btn {
            width: 100%;
            margin-top: .35rem;
        }
    }
    </style>
</head>
<body class="admin-body">
<div class="admin-layout">
    <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar("contact_requests/contact_requests.php"); ?>
    <main class="admin-main">
        <header class="admin-topbar">
            <div>
                <h1>Détails de la demande #<?= (int) $requestId ?></h1>
                <p>Vérifiez les informations avant toute création de compte.</p>
            </div>
            <a href="contact_requests.php" class="btn btn-secondary">Retour aux demandes</a>
        </header>
        <section class="dashboard-section">
            <div class="form-card request-details-card">
                <h2><?= $escape(trim($request["first_name"] . " " . ($request["last_name"] ?? ""))) ?></h2>
                <p><strong>Type :</strong> <?= $isAccessRequest ? "Demande d'inscription" : "Message de contact" ?></p>
                <p><strong>Formation :</strong> <?= $escape($request["course_title"] ?: "Demande générale") ?></p>
                <p><strong>Statut :</strong> <?= $escape($request["status"]) ?></p>
                <?php if (!empty($request["refusal_reason"])): ?><p><strong>Motif du refus :</strong> <?= $escape($request["refusal_reason"]) ?></p><?php endif; ?>
                <div class="request-details-grid">
                    <div>
                        <h3>Étudiant</h3>
                        <p>Email : <?= $escape($request["email"]) ?></p>
                        <p>Téléphone : <?= $escape($request["phone"] ?: "Non renseigné") ?></p>
                        <p>Date de naissance : <?= $escape($request["date_of_birth"] ?: "Non renseignée") ?></p>
                        <p>CIN : <?= $escape($request["cin"] ?: "Non renseigné") ?></p>
                        <p>Études / établissement : <?= $escape($request["education"] ?: "Non renseigné") ?></p>
                    </div>
                    <div>
                        <h3>Parent</h3>
                        <?php if (!empty($request["parent_email"])): ?>
                            <p>Nom : <?= $escape(trim($request["parent_first_name"] . " " . $request["parent_last_name"])) ?></p>
                            <p>Email : <?= $escape($request["parent_email"]) ?></p>
                            <p>Téléphone : <?= $escape($request["parent_phone"] ?: "Non renseigné") ?></p>
                            <p>Date de naissance : <?= $escape($request["parent_date_of_birth"] ?: "Non renseignée") ?></p>
                            <p>CIN : <?= $escape($request["parent_cin"] ?: "Non renseigné") ?></p>
                        <?php else: ?>
                            <p>Aucun parent demandé.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($isAccessRequest && $request["status"] === "new"): ?>
                    <div class="alert alert-warning">L'acceptation créera le compte étudiant et, le cas échéant, le compte parent. Les comptes seront créés <strong>inactifs</strong>.</div>
                    <form method="POST" action="contact_requests.php" onsubmit="return confirm('Confirmer la création des comptes inactifs pour cette demande ?');">
                        <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION["csrf_token"]) ?>">
                        <input type="hidden" name="action" value="confirm_accept">
                        <input type="hidden" name="request_id" value="<?= (int) $requestId ?>">
                        <button type="submit" class="btn btn-primary">Confirmer et créer les comptes inactifs</button>
                    </form>
                    <form method="POST" action="contact_requests.php" class="request-refusal-form">
                        <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION["csrf_token"]) ?>">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="request_id" value="<?= (int) $requestId ?>">
                        <input type="hidden" name="status" value="refused">
                        <label for="refusal_reason">Motif du refus *</label>
                        <textarea id="refusal_reason" name="refusal_reason" rows="3" required placeholder="Expliquez pourquoi la demande est refusée"></textarea>
                        <button type="submit" class="btn btn-danger">Refuser la demande</button>
                    </form>
                <?php elseif ($isAccessRequest && $request["status"] === "accepted"): ?>
                    <div class="request-account-links">
                        <h3>Comptes créés</h3>
                        <?php if ($studentExists): ?><a class="btn btn-secondary" href="../users/see_user.php?id=<?= (int) $request["created_student_id"] ?>">Voir l'étudiant</a> <a class="btn btn-secondary" href="../users/user_edit.php?id=<?= (int) $request["created_student_id"] ?>">Modifier l'étudiant</a><?php endif; ?>
                        <?php if ($parentExists): ?><a class="btn btn-secondary" href="../users/see_user.php?id=<?= (int) $request["created_parent_id"] ?>">Voir le parent</a> <a class="btn btn-secondary" href="../users/user_edit.php?id=<?= (int) $request["created_parent_id"] ?>">Modifier le parent</a><?php endif; ?>
                    </div>
                    <?php if (!$createdAccountsActive): ?>
                        <form method="POST" action="contact_requests.php" onsubmit="return confirm('Activer les comptes créés pour cette demande ?');">
                            <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION["csrf_token"]) ?>">
                            <input type="hidden" name="action" value="activate_accounts">
                            <input type="hidden" name="request_id" value="<?= (int) $requestId ?>">
                            <button type="submit" class="btn btn-primary">Activer les comptes</button>
                        </form>
                    <?php else: ?>
                        <span class="status-badge status-active">Comptes actifs</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>
</body>
</html>
