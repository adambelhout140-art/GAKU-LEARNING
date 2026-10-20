<?php
require_once __DIR__ . '/../config/i18n.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

if (!in_array($_SESSION["user_role"] ?? "", ["admin", "teacher"], true)) {
    http_response_code(403);
    exit("Accès interdit.");
}
require_once __DIR__ . "/../config/database.php";
$isTeacher = ($_SESSION["user_role"] ?? "") === "teacher";
$communicationMessagesUrl = $isTeacher ? "../teacher/messages.php" : "messages.php";
$communicationComposeUrl = $isTeacher ? "../teacher/message.php" : "message.php";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Communication - <?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260925e">
    <style>
        .communication-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1.25rem;
            max-width: 980px;
            margin: 0 auto;
            padding: 2rem;
        }
        .communication-card {
            display: flex;
            flex-direction: column;
            gap: .8rem;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .06);
        }
        .communication-card::before {
            content: "";
            width: 42px;
            height: 4px;
            border-radius: 999px;
            background: #277cff;
        }
        .communication-card h2 {
            margin: 0;
            color: #102a43;
            font-size: 1.15rem;
        }
        .communication-card p {
            flex: 1;
            margin: 0;
            color: #627d98;
        }
        @media (max-width: 900px) {
            .communication-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="admin-body<?= $isTeacher ? " teacher-view" : "" ?>">
    <div class="admin-layout">
        <?php require_once __DIR__ . "/config/admin_sidebar.php"; renderAdminSidebar("communication.php"); ?>
        <main class="admin-main">
            <header class="admin-topbar">
                <div>
                    <p class="student-eyebrow"><?= $isTeacher ? "Espace formateur" : "Espace administrateur" ?></p>
                    <h1>Communication</h1>
                    <p>Consultez vos messages et accédez rapidement à vos outils de communication.</p>
                </div>
            </header>
            <section class="communication-grid">
                <article class="communication-card">
                    <h2>Messages</h2>
                    <p>Consultez votre boîte de réception et vos messages envoyés.</p>
                    <a class="button primary-button" href="<?= htmlspecialchars($communicationMessagesUrl, ENT_QUOTES, "UTF-8") ?>">Ouvrir les messages</a>
                </article>
                <article class="communication-card">
                    <h2>Écrire un message</h2>
                    <p><?= $isTeacher ? "Contactez les utilisateurs autorisés de la plateforme." : "Contactez un étudiant, un formateur ou un responsable entreprise." ?></p>
                    <a class="button primary-button" href="<?= htmlspecialchars($communicationComposeUrl, ENT_QUOTES, "UTF-8") ?>">Nouveau message</a>
                </article>
            </section>
        </main>
    </div>
    <script src="../assets/js/admin-sidebar.js?v=20260925e"></script>
</body>
</html>
