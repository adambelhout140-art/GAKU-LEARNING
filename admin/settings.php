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
$defaults = platformBrandingDefaults();
$stored = $pdo->query("SELECT setting_key, setting_value FROM platform_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$settings = array_merge($defaults, $stored);
$message = "";
$messageType = "success";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        $message = "La session du formulaire a expiré.";
        $messageType = "danger";
    } else {
        $values = [
            "platform_name" => trim($_POST["platform_name"] ?? ""),
            "support_email" => trim($_POST["support_email"] ?? ""),
            "support_phone" => trim($_POST["support_phone"] ?? ""),
            "contact_address" => trim($_POST["contact_address"] ?? ""),
            "social_facebook" => trim($_POST["social_facebook"] ?? ""),
            "social_instagram" => trim($_POST["social_instagram"] ?? ""),
            "social_linkedin" => trim($_POST["social_linkedin"] ?? ""),
            "social_youtube" => trim($_POST["social_youtube"] ?? ""),
            "social_tiktok" => trim($_POST["social_tiktok"] ?? ""),
            "social_x" => trim($_POST["social_x"] ?? ""),
            "certificate_prefix" => strtoupper(trim($_POST["certificate_prefix"] ?? "")),
            "allow_registration" => isset($_POST["allow_registration"]) ? "1" : "0",
        ];
        $uploadError = "";

        if ($values["platform_name"] === "" || mb_strlen($values["platform_name"]) > 100) {
            $uploadError = "Le nom de la plateforme est obligatoire et limité à 100 caractères.";
        } elseif ($values["support_email"] !== "" && (!filter_var($values["support_email"], FILTER_VALIDATE_EMAIL) || mb_strlen($values["support_email"]) > 254)) {
            $uploadError = "Saisissez une adresse email de support valide.";
        } elseif (mb_strlen($values["support_phone"]) > 40 || ($values["support_phone"] !== "" && !preg_match("/^[0-9+().,;#*\\-\\s]+$/", $values["support_phone"]))) {
            $uploadError = "Vérifiez le numéro de téléphone.";
        } elseif (mb_strlen($values["contact_address"]) > 1000) {
            $uploadError = "L'adresse ne doit pas dépasser 1 000 caractères.";
        } elseif (!preg_match("/^[A-Z0-9_-]{2,20}$/", $values["certificate_prefix"])) {
            $uploadError = "Vérifiez le préfixe du certificat.";
        } else {
            foreach (["social_facebook", "social_instagram", "social_linkedin", "social_youtube", "social_tiktok", "social_x"] as $socialKey) {
                $url = $values[$socialKey];
                if (mb_strlen($url) > 500 || ($url !== "" && (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== "https"))) {
                    $uploadError = "Les liens sociaux doivent être des URL HTTPS valides.";
                    break;
                }
            }
        }

        $logoFields = [
            "platform_logo" => ["file" => "platform_logo_icon", "remove" => "remove_logo_icon"],
            "platform_logo_full" => ["file" => "platform_logo_light", "remove" => "remove_logo_light"],
            "platform_logo_dark" => ["file" => "platform_logo_dark", "remove" => "remove_logo_dark"],
        ];
        $pendingUploads = [];
        $oldLogosToRemove = [];
        foreach ($logoFields as $settingKey => $fieldNames) {
            $upload = $_FILES[$fieldNames["file"]] ?? ["error" => UPLOAD_ERR_NO_FILE];
            $removeLogo = isset($_POST[$fieldNames["remove"]]);
            $values[$settingKey] = (string) ($settings[$settingKey] ?? "");

            if ($removeLogo && $upload["error"] !== UPLOAD_ERR_NO_FILE) {
                $uploadError = "Choisissez soit un nouveau logo, soit la suppression du logo personnalisé.";
                break;
            }
            if ($removeLogo) {
                $oldLogosToRemove[] = $values[$settingKey];
                $values[$settingKey] = "";
                continue;
            }
            if ($upload["error"] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($upload["error"] !== UPLOAD_ERR_OK) {
                $uploadError = "Le chargement d'un logo a échoué. Réessayez avec une image PNG, JPG ou WEBP de 5 Mo maximum.";
                break;
            }
            if ((int) $upload["size"] > 5 * 1024 * 1024) {
                $uploadError = "Chaque logo ne doit pas dépasser 5 Mo.";
                break;
            }

            $image = @getimagesize($upload["tmp_name"]);
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($upload["tmp_name"]);
            $extensions = ["image/png" => "png", "image/jpeg" => "jpg", "image/webp" => "webp"];
            if (!$image || !isset($extensions[$mime]) || $image["mime"] !== $mime) {
                $uploadError = "Chaque logo doit être une image PNG, JPG ou WEBP valide.";
                break;
            }
            $pendingUploads[$settingKey] = [
                "upload" => $upload,
                "extension" => $extensions[$mime],
            ];
            $oldLogosToRemove[] = $values[$settingKey];
        }

        if ($uploadError !== "") {
            $message = $uploadError;
            $messageType = "danger";
        } else {
            $createdLogos = [];
            $uploadDirectory = dirname(__DIR__) . "/uploads/branding";
            foreach ($pendingUploads as $settingKey => $pendingUpload) {
                $newLogoPath = "uploads/branding/" . bin2hex(random_bytes(16)) . "." . $pendingUpload["extension"];
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
                    $uploadError = "Le dossier de stockage des logos n'a pas pu être créé.";
                    break;
                }
                if (!move_uploaded_file($pendingUpload["upload"]["tmp_name"], dirname(__DIR__) . "/" . $newLogoPath)) {
                    $uploadError = "Un logo n'a pas pu être enregistré sur le serveur.";
                    break;
                }
                $values[$settingKey] = $newLogoPath;
                $createdLogos[] = $newLogoPath;
            }

            if ($uploadError !== "") {
                foreach ($createdLogos as $createdLogo) {
                    if (is_file(dirname(__DIR__) . "/" . $createdLogo)) {
                        unlink(dirname(__DIR__) . "/" . $createdLogo);
                    }
                }
                $message = $uploadError;
                $messageType = "danger";
            } else {
                try {
                    $pdo->beginTransaction();
                    $save = $pdo->prepare("INSERT INTO platform_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    foreach ($values as $key => $value) $save->execute([$key, $value]);
                    $pdo->commit();
                    $settings = array_merge($settings, $values);
                    $platformBranding = $settings;
                    $message = "Paramètres enregistrés et appliqués à la plateforme.";

                    foreach (array_unique($oldLogosToRemove) as $oldLogoPath) {
                        if (preg_match("#^uploads/branding/[a-f0-9]{32}\\.(png|jpg|webp)$#i", $oldLogoPath)) {
                            $oldLogo = dirname(__DIR__) . "/" . $oldLogoPath;
                            if (is_file($oldLogo) && !unlink($oldLogo)) {
                                error_log("Unable to remove replaced platform logo: " . $oldLogo);
                            }
                        }
                    }
                } catch (PDOException $exception) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    foreach ($createdLogos as $createdLogo) {
                        if (is_file(dirname(__DIR__) . "/" . $createdLogo)) {
                            unlink(dirname(__DIR__) . "/" . $createdLogo);
                        }
                    }
                    error_log("Unable to save platform settings: " . $exception->getMessage());
                    $message = "Les paramètres n'ont pas pu être enregistrés. Vérifiez la base de données et réessayez.";
                    $messageType = "danger";
                }
            }
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
    <link rel="icon" type="image/png" href="<?= adminSettingsEscape(platformLogoUrl($pdo)) ?>">
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
                <div class="admin-card"><?php if ($message): ?><div class="alert alert-<?= adminSettingsEscape($messageType) ?>"><?= adminSettingsEscape($message) ?></div><?php endif; ?><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= adminSettingsEscape($_SESSION["csrf_token"]) ?>">
                        <h2>Identité de la plateforme</h2>
                        <div class="form-group"><label for="platform_name">Nom de la plateforme</label><input id="platform_name" name="platform_name" maxlength="100" required value="<?= adminSettingsEscape($_POST["platform_name"] ?? $settings["platform_name"]) ?>"></div>
                        <div class="form-group"><label for="platform_logo_light">Logo pour fond clair</label><input id="platform_logo_light" type="file" name="platform_logo_light" accept="image/png,image/jpeg,image/webp"><small>Version foncée du logo pour les zones blanches ou claires. PNG, JPG ou WEBP, 5 Mo maximum.</small><p><img src="<?= adminSettingsEscape(platformLogoUrl($pdo, "light-bg")) ?>" alt="Logo sur fond clair" style="max-width:220px;max-height:100px;object-fit:contain;background:#eef2f5;padding:8px;border-radius:8px"><?php if ($settings["platform_logo_full"] !== ""): ?><label><input type="checkbox" name="remove_logo_light" value="1"> Revenir au logo clair par défaut</label><?php endif; ?></p></div>
                        <div class="form-group"><label for="platform_logo_dark">Logo pour fond sombre</label><input id="platform_logo_dark" type="file" name="platform_logo_dark" accept="image/png,image/jpeg,image/webp"><small>Version claire du logo pour les zones sombres, comme la barre latérale d'administration. PNG, JPG ou WEBP, 5 Mo maximum.</small><p><img src="<?= adminSettingsEscape(platformLogoUrl($pdo, "dark-bg")) ?>" alt="Logo sur fond sombre" style="max-width:220px;max-height:100px;object-fit:contain;background:#263746;padding:8px;border-radius:8px"><?php if ($settings["platform_logo_dark"] !== ""): ?><label><input type="checkbox" name="remove_logo_dark" value="1"> Revenir au logo sombre par défaut</label><?php endif; ?></p></div>
                        <div class="form-group"><label for="platform_logo_icon">Icône du navigateur</label><input id="platform_logo_icon" type="file" name="platform_logo_icon" accept="image/png,image/jpeg,image/webp"><small>Petite icône affichée dans l'onglet du navigateur. PNG, JPG ou WEBP, 5 Mo maximum.</small><p><img src="<?= adminSettingsEscape(platformLogoUrl($pdo, "icon")) ?>" alt="Icône de la plateforme" style="width:72px;height:72px;object-fit:contain;background:#eef2f5;padding:8px;border-radius:8px"><?php if ($settings["platform_logo"] !== ""): ?><label><input type="checkbox" name="remove_logo_icon" value="1"> Revenir à l'icône par défaut</label><?php endif; ?></p></div>
                        <h2>Coordonnées publiques</h2>
                        <div class="form-group"><label for="support_email">Adresse email</label><input id="support_email" type="email" name="support_email" maxlength="254" value="<?= adminSettingsEscape($_POST["support_email"] ?? $settings["support_email"]) ?>"></div>
                        <div class="form-group"><label for="support_phone">Téléphone</label><input id="support_phone" type="tel" name="support_phone" maxlength="40" value="<?= adminSettingsEscape($_POST["support_phone"] ?? $settings["support_phone"]) ?>"></div>
                        <div class="form-group"><label for="contact_address">Adresse</label><textarea id="contact_address" name="contact_address" rows="3" maxlength="1000"><?= adminSettingsEscape($_POST["contact_address"] ?? $settings["contact_address"]) ?></textarea></div>
                        <h2>Comptes de réseaux sociaux</h2>
                        <?php foreach (["facebook" => "Facebook", "instagram" => "Instagram", "linkedin" => "LinkedIn", "youtube" => "YouTube", "tiktok" => "TikTok", "x" => "X"] as $network => $label): $key = "social_" . $network; ?>
                            <div class="form-group"><label for="<?= adminSettingsEscape($key) ?>"><?= adminSettingsEscape($label) ?> — URL du profil</label><input id="<?= adminSettingsEscape($key) ?>" type="url" name="<?= adminSettingsEscape($key) ?>" maxlength="500" placeholder="https://" value="<?= adminSettingsEscape($_POST[$key] ?? $settings[$key]) ?>"></div>
                        <?php endforeach; ?>
                        <h2>Autres paramètres</h2>
                        <div class="form-group"><label for="certificate_prefix">Préfixe des certificats</label><input id="certificate_prefix" name="certificate_prefix" maxlength="20" required value="<?= adminSettingsEscape($_POST["certificate_prefix"] ?? $settings["certificate_prefix"]) ?>"><small>Exemple : FP-20260915-XXXX</small></div><label><input type="checkbox" name="allow_registration" value="1" <?= ($settings["allow_registration"] === "1" && ($_SERVER["REQUEST_METHOD"] !== "POST" || isset($_POST["allow_registration"]))) ? "checked" : "" ?>> Autoriser les nouvelles inscriptions</label>
                        <p><button class="admin-button" type="submit">Enregistrer tous les paramètres</button></p>
                    </form>
                </div>
            </section>
        </main>
    </div>
<script src="../assets/js/admin-sidebar.js?v=20260925e"></script></body>

</html>
