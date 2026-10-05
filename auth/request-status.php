<?php
require_once __DIR__ . '/../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

if (empty($_SESSION["request_status_csrf_token"])) {
    $_SESSION["request_status_csrf_token"] = bin2hex(random_bytes(32));
}

$requestId = filter_input(INPUT_POST, "request_id", FILTER_VALIDATE_INT);
if (!$requestId) {
    $requestId = filter_input(INPUT_GET, "request_id", FILTER_VALIDATE_INT);
}
$email = trim($_POST["email"] ?? "");
$request = null;
$error = "";
$statusLabels = [
    "new" => "Demande reçue",
    "accepted" => "Acceptée",
    "refused" => "Refusée",
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!hash_equals($_SESSION["request_status_csrf_token"], $_POST["csrf_token"] ?? "")) {
        $error = "La session a expiré. Veuillez réessayer.";
    } elseif (!$requestId || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Saisissez un numéro de demande et une adresse email valides.";
    } else {
        $statement = $pdo->prepare(
            "SELECT contact_requests.id, contact_requests.email, contact_requests.status,
                    contact_requests.created_at, contact_requests.updated_at,
                    courses.title AS course_title
             FROM contact_requests
             LEFT JOIN courses ON courses.id = contact_requests.course_id
             WHERE contact_requests.id = ? AND LOWER(contact_requests.email) = LOWER(?)
             LIMIT 1"
        );
        $statement->execute([$requestId, $email]);
        $request = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            $error = "Aucune demande ne correspond à ce numéro et cette adresse email.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suivre ma demande - GAKU LEARNING</title><link rel="icon" type="image/png" href="../assets/images/logo-mark.png">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260917g">
</head>
<body class="auth-body">
    <main class="auth-page">
        <section class="auth-card">
            <a href="../index.php" class="auth-brand"><img src="../assets/images/logo-mark.png" alt="Gaku Learning" class="brand-logo" width="42" height="42"> GAKU LEARNING</a>
            <h1>Suivi de votre demande</h1>
            <p class="auth-intro">Consultez en toute sécurité l'avancement de votre demande d'accès à une formation.</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($request): ?>
                <div class="request-status-result">
                    <div class="request-status-header">
                        <div>
                            <span class="request-status-label">Référence de la demande</span>
                            <strong class="request-reference">#<?= (int) $request["id"] ?></strong>
                        </div>
                        <span class="status-badge status-<?= e($request["status"]) ?>"><?= e($statusLabels[$request["status"]] ?? $request["status"]) ?></span>
                    </div>
                    <dl class="request-status-details">
                        <div><dt>Formation</dt><dd><?= e($request["course_title"] ?: "Demande générale") ?></dd></div>
                        <div><dt>Dernière mise à jour</dt><dd><?= e(date("d/m/Y à H:i", strtotime($request["updated_at"] ?: $request["created_at"]))) ?></dd></div>
                    </dl>
                    <?php if ($request["status"] === "new"): ?>
                        <p class="request-status-message">Votre demande a bien été reçue. Elle sera examinée prochainement par notre équipe.</p>
                    <?php elseif ($request["status"] === "accepted"): ?>
                        <p class="request-status-message">Votre demande a été acceptée. Contactez notre société pour finaliser les formalités et le paiement.</p>
                    <?php else: ?>
                        <p class="request-status-message">Votre demande a été refusée. Contactez notre société pour toute information complémentaire.</p>
                    <?php endif; ?>
                </div>
                <a href="request-status.php" class="btn btn-primary auth-full-button">Vérifier une autre demande</a>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION["request_status_csrf_token"]) ?>">
                    <label for="request_id">Référence de la demande</label>
                    <input id="request_id" name="request_id" type="number" min="1" value="<?= e($requestId) ?>" required>
                    <small class="form-help">La référence vous a été affichée après l'envoi de votre demande.</small>
                    <label for="email">Adresse email utilisée lors de la demande</label>
                    <input id="email" name="email" type="email" value="<?= e($email) ?>" required>
                    <button type="submit" class="btn btn-primary auth-full-button">Voir le statut</button>
                </form>
            <?php endif; ?>

            <p class="auth-switch"><a href="../index.php">Retour à l'accueil</a></p>
        </section>
    </main>
</body>
</html>



