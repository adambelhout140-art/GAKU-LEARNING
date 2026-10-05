<?php
require_once __DIR__ . '/../config/i18n.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";
require_once "../config/homepage_content.php";
require_once "../config/audit.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}
if (($_SESSION["user_role"] ?? "") !== "admin") {
    http_response_code(403);
    exit("Accès interdit.");
}
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function homepageEditorEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function homepageEditorUpload(string $field, string $existing): array
{
    if (empty($_FILES[$field]) || $_FILES[$field]["error"] === UPLOAD_ERR_NO_FILE) {
        return ["path" => $existing, "error" => ""];
    }
    $file = $_FILES[$field];
    if ($file["error"] !== UPLOAD_ERR_OK || $file["size"] > 5 * 1024 * 1024) {
        return ["path" => $existing, "error" => "L'image doit faire moins de 5 Mo et être téléversée correctement."];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file["tmp_name"]);
    $extensions = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
    if (!isset($extensions[$mime]) || @getimagesize($file["tmp_name"]) === false) {
        return ["path" => $existing, "error" => "Format d'image invalide. Utilisez JPG, PNG ou WEBP."];
    }

    $directory = __DIR__ . "/../uploads/homepage";
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        return ["path" => $existing, "error" => "Impossible de créer le dossier des images d'accueil."];
    }
    $filename = bin2hex(random_bytes(16)) . "." . $extensions[$mime];
    if (!move_uploaded_file($file["tmp_name"], $directory . "/" . $filename)) {
        return ["path" => $existing, "error" => "Impossible d'enregistrer l'image téléversée."];
    }
    return ["path" => "uploads/homepage/" . $filename, "error" => ""];
}

function homepageEditorDeleteImage(string $relativePath): void
{
    if (!preg_match('#^uploads/homepage/[A-Za-z0-9_-]+\.(?:jpg|png|webp)$#', $relativePath)) {
        return;
    }
    $path = __DIR__ . "/../" . $relativePath;
    if (is_file($path)) {
        unlink($path);
    }
}

$content = homepageContentLoad($pdo);
$defaults = homepageContentDefaults();
$errors = [];
$success = "";
$groups = [
    "navigation" => "Navigation du site",
    "hero" => "Bannière principale",
    "stats" => "Chiffres clés",
    "videos" => "Section vidéos",
    "courses" => "Section formations",
    "how" => "Comment ça marche",
    "cta" => "Bannière d'action",
    "about" => "Section à propos",
    "footer" => "Pied de page",
];
$imageFields = ["hero" => "image", "about" => "image"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        $errors[] = "La session du formulaire a expiré. Rechargez la page et réessayez.";
    } else {
        $submitted = is_array($_POST["content"] ?? null) ? $_POST["content"] : [];
        foreach ($defaults as $group => $values) {
            if (in_array($group, ["sections", "order"], true)) {
                continue;
            }
            foreach ($values as $key => $defaultValue) {
                if ($key === "image") {
                    continue;
                }
                $value = $submitted[$group][$key] ?? "";
                if (!is_string($value) && !is_numeric($value)) {
                    $errors[] = "Valeur invalide pour " . $key . ".";
                    continue;
                }
                $value = trim((string) $value);
                if (mb_strlen($value) > 2000) {
                    $errors[] = "Le champ " . $key . " dépasse la limite de 2000 caractères.";
                    continue;
                }
                if (substr($key, -5) === "_link" && !homepageContentLinkIsValid($value)) {
                    $errors[] = "Lien invalide dans le champ " . $key . ". Utilisez un lien interne, une ancre ou une URL HTTPS.";
                    continue;
                }
                $content[$group][$key] = $value;
            }
        }

        foreach ($content["sections"] as $section => $enabled) {
            $content["sections"][$section] = isset($_POST["sections"][$section]) ? 1 : 0;
        }
        $submittedOrder = $_POST["order"] ?? [];
        $orderValues = [];
        foreach ($content["order"] as $section => $defaultPosition) {
            $position = filter_var($submittedOrder[$section] ?? null, FILTER_VALIDATE_INT);
            if ($position === false || $position < 1 || $position > 7) {
                $errors[] = "Chaque section doit avoir une position comprise entre 1 et 7.";
                continue;
            }
            $content["order"][$section] = $position;
            $orderValues[] = $position;
        }
        if (count(array_unique($orderValues)) !== count($content["order"])) {
            $errors[] = "Chaque section doit avoir une position différente.";
        }

        $newImages = [];
        if (!$errors) {
            foreach ($imageFields as $group => $field) {
                if (isset($_POST["remove_image"][$group])) {
                    $newImages[$group] = "";
                    continue;
                }
                $upload = homepageEditorUpload("image_" . $group, (string) ($content[$group][$field] ?? ""));
                if ($upload["error"] !== "") {
                    $errors[] = $upload["error"];
                } else {
                    $newImages[$group] = $upload["path"];
                }
            }
        }
        if ($errors) {
            foreach ($newImages as $group => $path) {
                if ($path !== "" && $path !== ($content[$group]["image"] ?? "")) {
                    homepageEditorDeleteImage($path);
                }
            }
            foreach ($defaults as $group => $values) {
                if (isset($values["image"])) {
                    $content[$group]["image"] = $content[$group]["image"] ?? "";
                }
            }
        } else {
            $replacedImages = [];
            $originalImages = [];
            foreach ($imageFields as $group => $field) {
                $previous = (string) ($content[$group][$field] ?? "");
                $originalImages[$group] = $previous;
                $content[$group][$field] = $newImages[$group];
                if ($previous !== "" && $previous !== $newImages[$group]) {
                    $replacedImages[] = $previous;
                }
            }
            $json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $errors[] = "Impossible d'encoder les paramètres de la page d'accueil.";
                foreach ($newImages as $group => $path) {
                    if ($path !== "" && $path !== ($originalImages[$group] ?? "")) {
                        homepageEditorDeleteImage($path);
                    }
                    $content[$group][$imageFields[$group]] = $originalImages[$group] ?? "";
                }
            } else {
                try {
                    $save = $pdo->prepare(
                        "INSERT INTO platform_settings (setting_key, setting_value)
                         VALUES ('homepage_content', ?)
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
                    );
                    $save->execute([$json]);
                } catch (PDOException $exception) {
                    error_log("Homepage content save failed: " . $exception->getMessage());
                    $errors[] = "Impossible d'enregistrer les paramètres de la page d'accueil.";
                    foreach ($newImages as $group => $path) {
                        if ($path !== "" && $path !== ($originalImages[$group] ?? "")) {
                            homepageEditorDeleteImage($path);
                        }
                        $content[$group][$imageFields[$group]] = $originalImages[$group] ?? "";
                    }
                }
                if (!$errors) {
                    foreach ($replacedImages as $path) {
                        homepageEditorDeleteImage($path);
                    }
                    auditLog($pdo, (int) $_SESSION["user_id"], "homepage_content_updated", "homepage_content");
                    header("Location: homepage_editor.php?saved=1");
                    exit;
                }
            }
        }
    }
}

if (($_GET["saved"] ?? "") === "1") {
    $success = "La page d'accueil a été mise à jour.";
}

$labels = [
    "label" => "Petit titre", "title" => "Titre", "description" => "Description",
    "primary_text" => "Texte du bouton principal", "primary_link" => "Lien du bouton principal",
    "secondary_text" => "Texte du second bouton", "secondary_link" => "Lien du second bouton",
    "visual_label" => "Titre de la carte", "visual_title" => "Accroche de la carte",
    "visual_description" => "Texte de la carte", "mini_one_icon" => "Icône de la carte 1",
    "mini_one_title" => "Titre de la carte 1", "mini_one_text" => "Texte de la carte 1",
    "mini_two_icon" => "Icône de la carte 2", "mini_two_title" => "Titre de la carte 2",
    "mini_two_text" => "Texte de la carte 2", "courses_suffix" => "Suffixe du nombre de formations",
    "courses_label" => "Libellé des formations", "value_two" => "Valeur du chiffre 2", "label_two" => "Libellé du chiffre 2",
    "value_three" => "Valeur du chiffre 3", "label_three" => "Libellé du chiffre 3", "value_four" => "Valeur du chiffre 4",
    "label_four" => "Libellé du chiffre 4", "eyebrow" => "Petit titre", "empty_title" => "Titre si aucun résultat",
    "empty_description" => "Texte si aucun résultat", "placeholder" => "Texte de remplacement d'image",
    "button" => "Texte du bouton", "duration_missing" => "Texte durée non spécifiée", "currency" => "Devise",
    "level_beginner" => "Niveau débutant", "level_intermediate" => "Niveau intermédiaire", "level_advanced" => "Niveau avancé",
    "step_one_title" => "Étape 1 - titre", "step_one_text" => "Étape 1 - description",
    "step_two_title" => "Étape 2 - titre", "step_two_text" => "Étape 2 - description",
    "step_three_title" => "Étape 3 - titre", "step_three_text" => "Étape 3 - description",
    "button_logged_in" => "Bouton si connecté", "button_logged_out" => "Bouton si déconnecté",
    "link_logged_in" => "Lien si connecté", "link_logged_out" => "Lien si déconnecté", "intro" => "Introduction",
    "feature_one_icon" => "Icône - fonctionnalité 1", "feature_one_title" => "Titre - fonctionnalité 1", "feature_one_text" => "Description - fonctionnalité 1",
    "feature_two_icon" => "Icône - fonctionnalité 2", "feature_two_title" => "Titre - fonctionnalité 2", "feature_two_text" => "Description - fonctionnalité 2",
    "feature_three_icon" => "Icône - fonctionnalité 3", "feature_three_title" => "Titre - fonctionnalité 3", "feature_three_text" => "Description - fonctionnalité 3",
    "feature_four_icon" => "Icône - fonctionnalité 4", "feature_four_title" => "Titre - fonctionnalité 4", "feature_four_text" => "Description - fonctionnalité 4",
    "info_one_title" => "Bloc à propos 1 - titre", "info_one_text" => "Bloc à propos 1 - texte",
    "info_two_title" => "Bloc à propos 2 - titre", "info_two_text" => "Bloc à propos 2 - texte",
    "info_three_title" => "Bloc à propos 3 - titre", "info_three_item_one" => "Bloc à propos 3 - élément 1",
    "info_three_item_two" => "Bloc à propos 3 - élément 2", "info_three_item_three" => "Bloc à propos 3 - élément 3",
    "info_four_title" => "Bloc à propos 4 - titre", "info_four_text" => "Bloc à propos 4 - texte",
    "links_title" => "Titre des liens", "home_label" => "Lien accueil", "courses_label" => "Lien formations",
    "about_label" => "Lien à propos", "account_title" => "Titre compte", "login_label" => "Lien connexion",
    "logout_label" => "Lien déconnexion", "copyright" => "Texte de copyright",
    "page_title" => "Titre de la page (navigateur)", "brand_name" => "Nom affiché de la plateforme", "home" => "Menu accueil", "courses" => "Menu formations", "about" => "Menu à propos",
    "request_status" => "Menu suivi de demande", "request_access" => "Menu demande d'accès",
    "login" => "Menu connexion", "dashboard" => "Menu tableau de bord", "logout" => "Menu déconnexion",
];
$textareaFields = ["title", "description", "visual_description", "intro"];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Modifier la page d'accueil - GAKU LEARNING</title>
    <link rel="icon" type="image/png" href="../assets/images/logo-mark.png">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260928c">
</head>
<body class="admin-body homepage-editor-admin">
<div class="admin-layout">
    <?php require_once __DIR__ . "/config/admin_sidebar.php"; renderAdminSidebar("homepage_editor.php"); ?>
    <main class="admin-main">
        <header class="admin-topbar">
            <div><p class="student-eyebrow">Contenu du site</p><h1>Modifier la page d'accueil</h1><p>Modifiez les textes, liens, images et l'ordre d'affichage sans toucher au code.</p></div>
            <a class="btn btn-secondary" href="../index.php" target="_blank" rel="noopener">Voir la page</a>
        </header>
        <?php if ($errors): ?><div class="alert alert-danger"><?= homepageEditorEscape(implode(" ", $errors)) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= homepageEditorEscape($success) ?></div><?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="homepage-editor-form">
            <input type="hidden" name="csrf_token" value="<?= homepageEditorEscape($_SESSION["csrf_token"]) ?>">
            <section class="homepage-editor-section homepage-editor-arrangement">
                <div class="homepage-editor-heading"><div><span>01</span><div><h2>Sections et ordre</h2><p>Choisissez les sections visibles et leur ordre sur la page.</p></div></div></div>
                <div class="homepage-editor-section-grid">
                    <?php foreach ($content["sections"] as $section => $enabled): ?>
                        <label class="homepage-editor-toggle">
                            <input type="checkbox" name="sections[<?= homepageEditorEscape($section) ?>]" value="1" <?= $enabled ? "checked" : "" ?>>
                            <span><?= homepageEditorEscape(ucfirst($section)) ?></span>
                            <?php if (isset($content["order"][$section])): ?>
                                <select name="order[<?= homepageEditorEscape($section) ?>]" aria-label="Ordre <?= homepageEditorEscape($section) ?>">
                                    <?php for ($position = 1; $position <= 7; $position++): ?><option value="<?= $position ?>" <?= (int) $content["order"][$section] === $position ? "selected" : "" ?>>Ordre <?= $position ?></option><?php endfor; ?>
                                </select>
                            <?php else: ?><small>Fin de page</small><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="homepage-editor-note">La section vidéos est également gérée depuis « Vidéos d'accueil ».</p>
            </section>
            <?php $sectionNumber = 2; foreach ($groups as $group => $groupTitle): ?>
                <section class="homepage-editor-section">
                    <div class="homepage-editor-heading"><div><span><?= str_pad((string) $sectionNumber, 2, "0", STR_PAD_LEFT) ?></span><div><h2><?= homepageEditorEscape($groupTitle) ?></h2><p>Ces changements sont affichés directement sur la page publique.</p></div></div></div>
                    <?php if (isset($imageFields[$group])): $imageKey = $imageFields[$group]; ?>
                        <div class="homepage-editor-image">
                            <label>Image de la section (JPG, PNG ou WEBP, 5 Mo maximum)<input type="file" name="image_<?= homepageEditorEscape($group) ?>" accept="image/jpeg,image/png,image/webp"></label>
                            <?php if (!empty($content[$group][$imageKey])): ?>
                                <div class="homepage-editor-current-image"><img src="../<?= homepageEditorEscape($content[$group][$imageKey]) ?>" alt="Image actuelle"><label><input type="checkbox" name="remove_image[<?= homepageEditorEscape($group) ?>]" value="1"> Supprimer l'image actuelle</label></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="homepage-editor-fields">
                        <?php foreach ($defaults[$group] as $key => $value): if ($key === "image") continue; $fieldLabel = $labels[$key] ?? ucfirst(str_replace("_", " ", $key)); $isTextarea = in_array($key, $textareaFields, true) || strpos($key, "_text") !== false || strpos($key, "_description") !== false || $key === "intro"; ?>
                            <label class="<?= $isTextarea ? "is-wide" : "" ?>">
                                <span><?= homepageEditorEscape($fieldLabel) ?></span>
                                <?php if ($isTextarea): ?>
                                    <textarea name="content[<?= homepageEditorEscape($group) ?>][<?= homepageEditorEscape($key) ?>]" rows="3" maxlength="2000"><?= homepageEditorEscape($content[$group][$key]) ?></textarea>
                                <?php else: ?>
                                    <input name="content[<?= homepageEditorEscape($group) ?>][<?= homepageEditorEscape($key) ?>]" maxlength="2000" value="<?= homepageEditorEscape($content[$group][$key]) ?>">
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php $sectionNumber++; endforeach; ?>
            <div class="homepage-editor-save"><button class="button primary-button" type="submit">Enregistrer les changements</button><a class="button secondary-button" href="../index.php" target="_blank" rel="noopener">Aperçu du site</a></div>
        </form>
    </main>
</div>
<script src="../assets/js/admin-sidebar.js?v=20260925e"></script>
</body>
</html>
