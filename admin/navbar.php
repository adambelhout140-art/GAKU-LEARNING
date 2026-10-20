<?php
require_once __DIR__ . "/../config/i18n.php";
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION["user_id"]) || ($_SESSION["user_role"] ?? "") !== "admin") {
    http_response_code(403);
    exit("Accès interdit.");
}
require_once __DIR__ . "/../config/database.php";

$pdo->exec("CREATE TABLE IF NOT EXISTS platform_settings (setting_key VARCHAR(100) NOT NULL PRIMARY KEY, setting_value TEXT NULL, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$storedLinks = json_decode(platformSetting($pdo, "public_nav_links"), true);
$links = is_array($storedLinks) ? array_slice($storedLinks, 0, 6) : platformNavbarDefaults();
$links = array_pad($links, 6, ["label" => "", "href" => "", "visible" => false, "order" => 1]);
$message = "";
$messageType = "success";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (empty($_SESSION["csrf_token"]) || !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        $message = "La session du formulaire a expiré. Rechargez la page et réessayez.";
        $messageType = "danger";
    } else {
        $submitted = [];
        $validationError = "";
        for ($index = 0; $index < 6; $index++) {
            $label = trim((string) ($_POST["label"][$index] ?? ""));
            $href = trim((string) ($_POST["href"][$index] ?? ""));
            $visible = isset($_POST["visible"][$index]);
            $orderValue = (string) ($_POST["order"][$index] ?? ($index + 1));

            if ($label === "" && $href === "") {
                continue;
            }
            if ($label === "" || $href === "") {
                if ($visible) {
                    $validationError = "Chaque lien affiché doit avoir un libellé et une destination.";
                    break;
                }
                continue;
            }
            if (mb_strlen($label) > 60 || mb_strlen($href) > 300) {
                $validationError = "Les libellés sont limités à 60 caractères et les destinations à 300 caractères.";
                break;
            }
            if (!ctype_digit($orderValue) || (int) $orderValue < 1 || (int) $orderValue > 6) {
                $validationError = "L'ordre de chaque lien doit être compris entre 1 et 6.";
                break;
            }

            $isHttpsUrl = filter_var($href, FILTER_VALIDATE_URL)
                && strtolower((string) parse_url($href, PHP_URL_SCHEME)) === "https";
            $internalPath = parse_url($href, PHP_URL_PATH);
            $isSafeInternalPath = preg_match("/^[A-Za-z0-9._~!$&'()*+,;=:@%\\/?#-]+$/D", $href)
                && strpos($href, "//") === false
                && strpos($href, "\\") === false
                && !in_array("..", explode("/", (string) $internalPath), true);
            if (!$isHttpsUrl && !$isSafeInternalPath) {
                $validationError = "Utilisez un chemin interne de la plateforme ou une URL HTTPS sécurisée.";
                break;
            }

            $submitted[] = [
                "label" => $label,
                "href" => $href,
                "visible" => $visible,
                "order" => (int) $orderValue,
            ];
        }

        if ($validationError !== "") {
            $message = $validationError;
            $messageType = "danger";
        } else {
            try {
                $save = $pdo->prepare("INSERT INTO platform_settings (setting_key, setting_value) VALUES ('public_nav_links', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $save->execute([json_encode($submitted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                $links = array_pad($submitted, 6, ["label" => "", "href" => "", "visible" => false, "order" => 1]);
                $message = "Les liens de navigation ont été enregistrés.";
            } catch (PDOException $exception) {
                error_log("Unable to save public navbar settings: " . $exception->getMessage());
                $message = "Les liens n'ont pas pu être enregistrés. Vérifiez la base de données et réessayez.";
                $messageType = "danger";
            }
        }
    }
}

function adminNavbarEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Navigation du site - Administration</title>
    <link rel="icon" type="image/png" href="<?= adminNavbarEscape(platformLogoUrl($pdo)) ?>">
    <link rel="stylesheet" href="../assets/css/style.css?v=20261019-navbar">
</head>
<body class="admin-body">
    <div class="admin-layout">
        <?php require_once __DIR__ . "/config/admin_sidebar.php"; renderAdminSidebar("navbar.php"); ?>
        <main class="admin-main">
            <header class="admin-topbar">
                <div>
                    <h1>Navigation du site</h1>
                    <p>Modifiez l'ordre, le texte, la destination et la visibilité des liens publics.</p>
                </div>
            </header>
            <section class="admin-content">
                <div class="admin-card">
                    <?php if ($message !== ""): ?>
                        <div class="alert alert-<?= adminNavbarEscape($messageType) ?>"><?= adminNavbarEscape($message) ?></div>
                    <?php endif; ?>
                    <p>La recherche reste en première position. Les liens du compte (connexion, tableau de bord et déconnexion) restent gérés automatiquement.</p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= adminNavbarEscape($_SESSION["csrf_token"]) ?>">
                        <div class="navbar-config-list">
                            <?php foreach ($links as $index => $link): ?>
                                <fieldset class="navbar-config-row">
                                    <legend>Lien <?= $index + 1 ?></legend>
                                    <label>Libellé
                                        <input type="text" name="label[<?= $index ?>]" maxlength="60" value="<?= adminNavbarEscape($link["label"] ?? "") ?>" placeholder="Ex. Accueil">
                                    </label>
                                    <label>Destination
                                        <input type="text" name="href[<?= $index ?>]" maxlength="300" value="<?= adminNavbarEscape($link["href"] ?? "") ?>" placeholder="index.php#courses">
                                    </label>
                                    <label>Ordre
                                        <input type="number" name="order[<?= $index ?>]" min="1" max="6" value="<?= adminNavbarEscape($link["order"] ?? ($index + 1)) ?>">
                                    </label>
                                    <label class="navbar-config-visible">
                                        <input type="checkbox" name="visible[<?= $index ?>]" value="1" <?= !empty($link["visible"]) ? "checked" : "" ?>>
                                        Afficher ce lien
                                    </label>
                                </fieldset>
                            <?php endforeach; ?>
                        </div>
                        <p><small>Les destinations peuvent être des chemins internes comme <code>index.php#courses</code> ou des URL HTTPS.</small></p>
                        <button class="admin-button" type="submit">Enregistrer la navigation</button>
                    </form>
                </div>
            </section>
        </main>
    </div>
    <script src="../assets/js/admin-sidebar.js?v=20260925e"></script>
</body>
</html>
