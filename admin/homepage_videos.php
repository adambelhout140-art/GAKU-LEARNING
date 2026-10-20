<?php
require_once __DIR__ . '/../config/i18n.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";
require_once "../config/homepage_videos.php";
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

function homepageVideoEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$errors = [];
$success = "";
$editing = null;
$editId = filter_input(INPUT_GET, "edit", FILTER_VALIDATE_INT);
if ($editId && $editId > 0) {
    $statement = $pdo->prepare("SELECT * FROM homepage_videos WHERE id = ? LIMIT 1");
    $statement->execute([$editId]);
    $editing = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$editing) {
        http_response_code(404);
        exit("Vidéo introuvable.");
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        $errors[] = "La session du formulaire a expiré.";
    } else {
        $action = $_POST["action"] ?? "save";
        $videoId = filter_input(INPUT_POST, "video_id", FILTER_VALIDATE_INT);
        if ($action === "delete") {
            if (!$videoId || $videoId < 1) {
                $errors[] = "Vidéo invalide.";
            } else {
                $delete = $pdo->prepare("DELETE FROM homepage_videos WHERE id = ?");
                $delete->execute([$videoId]);
                auditLog($pdo, (int) $_SESSION["user_id"], "homepage_video_deleted", "homepage_video", $videoId);
                header("Location: homepage_videos.php?success=deleted");
                exit;
            }
        } elseif ($action === "toggle") {
            if (!$videoId || $videoId < 1) {
                $errors[] = "Vidéo invalide.";
            } else {
                $toggle = $pdo->prepare("UPDATE homepage_videos SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?");
                $toggle->execute([$videoId]);
                auditLog($pdo, (int) $_SESSION["user_id"], "homepage_video_visibility_changed", "homepage_video", $videoId);
                header("Location: homepage_videos.php");
                exit;
            }
        } else {
            $title = trim((string) ($_POST["title"] ?? ""));
            $description = trim((string) ($_POST["description"] ?? ""));
            $videoUrl = trim((string) ($_POST["video_url"] ?? ""));
            $positionRaw = trim((string) ($_POST["position"] ?? "0"));
            $active = isset($_POST["is_active"]) ? 1 : 0;
            if ($title === "" || mb_strlen($title) > 180) {
                $errors[] = "Le titre est obligatoire et limité à 180 caractères.";
            }
            if ($description !== "" && mb_strlen($description) > 2000) {
                $errors[] = "La description est limitée à 2000 caractères.";
            }
            if (mb_strlen($videoUrl) > 500 || homepageVideoEmbedUrl($videoUrl) === "") {
                $errors[] = "Saisissez une URL YouTube ou Vimeo valide.";
            }
            if (!preg_match("/^-?\\d{1,6}$/", $positionRaw)) {
                $errors[] = "La position doit être un nombre entier.";
            }
            if (!$errors) {
                if ($videoId && $videoId > 0) {
                    $save = $pdo->prepare("UPDATE homepage_videos SET title = ?, description = ?, video_url = ?, position = ?, is_active = ? WHERE id = ?");
                    $save->execute([$title, $description !== "" ? $description : null, $videoUrl, (int) $positionRaw, $active, $videoId]);
                    auditLog($pdo, (int) $_SESSION["user_id"], "homepage_video_updated", "homepage_video", $videoId, null, ["title" => $title]);
                } else {
                    $save = $pdo->prepare("INSERT INTO homepage_videos (title, description, video_url, position, is_active) VALUES (?, ?, ?, ?, ?)");
                    $save->execute([$title, $description !== "" ? $description : null, $videoUrl, (int) $positionRaw, $active]);
                    $videoId = (int) $pdo->lastInsertId();
                    auditLog($pdo, (int) $_SESSION["user_id"], "homepage_video_created", "homepage_video", $videoId, null, ["title" => $title]);
                }
                header("Location: homepage_videos.php?success=saved");
                exit;
            }
            $editing = [
                "id" => $videoId ?: "",
                "title" => $title,
                "description" => $description,
                "video_url" => $videoUrl,
                "position" => $positionRaw,
                "is_active" => $active,
            ];
        }
    }
}

if (($_GET["success"] ?? "") === "saved") $success = "La vidéo a été enregistrée.";
if (($_GET["success"] ?? "") === "deleted") $success = "La vidéo a été supprimée.";
$videos = $pdo->query("SELECT id, title, description, video_url, position, is_active, updated_at FROM homepage_videos ORDER BY position, id")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vidéos de la page d'accueil - <?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260928c">
</head>
<body class="admin-body homepage-videos-admin">
<div class="admin-layout">
    <?php require_once __DIR__ . "/config/admin_sidebar.php"; renderAdminSidebar("homepage_videos.php"); ?>
    <main class="admin-main">
        <header class="admin-topbar">
            <div><p class="student-eyebrow">Contenu du site</p><h1>Vidéos de la page d'accueil</h1><p>Ajoutez et gérez les vidéos visibles sur le site public.</p></div>
        </header>
        <?php if ($errors): ?><div class="alert alert-danger"><?= homepageVideoEscape(implode(" ", $errors)) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= homepageVideoEscape($success) ?></div><?php endif; ?>
        <div class="homepage-videos-layout">
            <section class="homepage-video-editor">
                <h2><?= $editing ? "Modifier la vidéo" : "Ajouter une vidéo" ?></h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= homepageVideoEscape($_SESSION["csrf_token"]) ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="video_id" value="<?= homepageVideoEscape($editing["id"] ?? "") ?>">
                    <label>Titre<input name="title" maxlength="180" required value="<?= homepageVideoEscape($editing["title"] ?? "") ?>"></label>
                    <label>Description<textarea name="description" maxlength="2000" rows="4"><?= homepageVideoEscape($editing["description"] ?? "") ?></textarea></label>
                    <label>URL YouTube ou Vimeo<input type="url" name="video_url" required maxlength="500" placeholder="https://www.youtube.com/watch?v=..." value="<?= homepageVideoEscape($editing["video_url"] ?? "") ?>"></label>
                    <label>Position d'affichage<input type="number" name="position" min="-999999" max="999999" value="<?= homepageVideoEscape($editing["position"] ?? 0) ?>"></label>
                    <label class="homepage-video-checkbox"><input type="checkbox" name="is_active" value="1" <?= !isset($editing["is_active"]) || (int) $editing["is_active"] === 1 ? "checked" : "" ?>> Afficher sur la page d'accueil</label>
                    <div class="homepage-video-actions"><button class="button primary-button" type="submit"><?= $editing ? "Enregistrer les modifications" : "Ajouter la vidéo" ?></button><?php if ($editing): ?><a class="button secondary-button" href="homepage_videos.php">Annuler</a><?php endif; ?></div>
                </form>
                <p class="homepage-video-help">Formats acceptés : liens YouTube ou Vimeo. Les vidéos masquées restent enregistrées dans l'administration.</p>
            </section>
            <section class="homepage-video-list">
                <h2>Vidéos enregistrées <span><?= count($videos) ?></span></h2>
                <?php if (!$videos): ?><p class="homepage-video-empty">Aucune vidéo pour le moment.</p><?php else: ?>
                    <?php foreach ($videos as $video): ?>
                        <article class="homepage-video-item">
                            <div class="homepage-video-preview"><iframe src="<?= homepageVideoEscape(homepageVideoEmbedUrl($video["video_url"])) ?>" title="<?= homepageVideoEscape($video["title"]) ?>" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div>
                            <div class="homepage-video-item-info">
                                <div class="homepage-video-item-heading"><div><h3><?= homepageVideoEscape($video["title"]) ?></h3><p><?= homepageVideoEscape($video["description"] ?? "") ?></p></div><span class="<?= (int) $video["is_active"] === 1 ? "is-visible" : "is-hidden" ?>"><?= (int) $video["is_active"] === 1 ? "Visible" : "Masquée" ?></span></div>
                                <small>Position <?= (int) $video["position"] ?> · <?= homepageVideoEscape($video["video_url"]) ?></small>
                                <div class="homepage-video-row-actions">
                                    <a class="button secondary-button" href="homepage_videos.php?edit=<?= (int) $video["id"] ?>">Modifier</a>
                                    <form method="post"><input type="hidden" name="csrf_token" value="<?= homepageVideoEscape($_SESSION["csrf_token"]) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="video_id" value="<?= (int) $video["id"] ?>"><button class="button secondary-button" type="submit"><?= (int) $video["is_active"] === 1 ? "Masquer" : "Afficher" ?></button></form>
                                    <form method="post" onsubmit="return confirm('Supprimer cette vidéo ?');"><input type="hidden" name="csrf_token" value="<?= homepageVideoEscape($_SESSION["csrf_token"]) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="video_id" value="<?= (int) $video["id"] ?>"><button class="button danger-button" type="submit">Supprimer</button></form>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
<script src="../assets/js/admin-sidebar.js?v=20260925e"></script>
</body>
</html>
