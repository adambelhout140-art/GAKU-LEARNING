<?php
require_once __DIR__ . '/../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION["user_id"]) || ($_SESSION["user_role"] ?? "") !== "admin") {
    http_response_code(403);
    exit("Accès interdit.");
}
require_once "../config/database.php";
$pdo->exec("CREATE TABLE IF NOT EXISTS platform_settings (setting_key VARCHAR(100) NOT NULL PRIMARY KEY, setting_value TEXT NULL, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
if (empty($_SESSION["csrf_token"])) $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
$defaults = ["platform_name" => "GAKU LEARNING", "support_email" => "", "certificate_prefix" => "FP", "allow_registration" => "1"];
$stored = $pdo->query("SELECT setting_key, setting_value FROM platform_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$settings = array_merge($defaults, $stored);
$message = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        $message = "La session du formulaire a expiré.";
    } else {
        $values = ["platform_name" => trim($_POST["platform_name"] ?? ""), "support_email" => trim($_POST["support_email"] ?? ""), "certificate_prefix" => strtoupper(trim($_POST["certificate_prefix"] ?? "")), "allow_registration" => isset($_POST["allow_registration"]) ? "1" : "0"];
        if ($values["platform_name"] === "" || !filter_var($values["support_email"], FILTER_VALIDATE_EMAIL) && $values["support_email"] !== "" || !preg_match("/^[A-Z0-9_-]{2,20}$/", $values["certificate_prefix"])) {
            $message = "Vérifiez le nom, l'adresse email et le préfixe du certificat.";
        } else {
            $save = $pdo->prepare("INSERT INTO platform_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            foreach ($values as $key => $value) $save->execute([$key, $value]);
            $settings = array_merge($settings, $values);
            $message = "Paramètres enregistrés.";
        }
    }
}
function adminSettingsEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paramètres - Administration</title>
    <link rel="icon" type="image/png" href="../assets/images/logo-mark.png">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260925e">
</head>

<body class="admin-body">
    <div class="admin-layout">
        <!-- ======================================================
         SIDEBAR
         ====================================================== -->

    <?php require_once __DIR__ . "/config/admin_sidebar.php"; renderAdminSidebar('settings.php'); ?>
        <main class="admin-main">
            <header class="admin-topbar">
                <div>
                    <h1>Paramètres de la plateforme</h1>
                    <p>Configurez les informations générales utilisées par le site.</p>
                </div>
            </header>
            <section class="admin-content">
                <div class="admin-card"><?php if ($message): ?><div class="alert"><?= adminSettingsEscape($message) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= adminSettingsEscape($_SESSION["csrf_token"]) ?>">
                        <div class="form-group"><label for="platform_name">Nom de la plateforme</label><input id="platform_name" name="platform_name" required value="<?= adminSettingsEscape($settings["platform_name"]) ?>"></div>
                        <div class="form-group"><label for="support_email">Email de support</label><input id="support_email" type="email" name="support_email" value="<?= adminSettingsEscape($settings["support_email"]) ?>"></div>
                        <div class="form-group"><label for="certificate_prefix">Préfixe des certificats</label><input id="certificate_prefix" name="certificate_prefix" maxlength="20" required value="<?= adminSettingsEscape($settings["certificate_prefix"]) ?>"><small>Exemple : FP-20260915-XXXX</small></div><label><input type="checkbox" name="allow_registration" value="1" <?= $settings["allow_registration"] === "1" ? "checked" : "" ?>> Autoriser les nouvelles inscriptions</label>
                        <p><button class="admin-button" type="submit">Enregistrer les paramètres</button></p>
                    </form>
                </div>
            </section>
        </main>
    </div>
<script src="../assets/js/admin-sidebar.js?v=20260925e"></script></body>

</html>



