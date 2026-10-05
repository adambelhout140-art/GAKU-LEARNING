<?php
require_once __DIR__ . '/../../config/i18n.php';// ============================================================
// * GAKU LEARNING - ADMIN LESSON EDIT
// ============================================================
// ! RESTRICTED ACCESS: Only authenticated Admins and Teachers can access.
// ? Purpose: Updates lesson content only within its verified module relationship.
// ============================================================


// ------------------------------------------------------------
// * SESSION HANDLING
// ? Starts or resumes session to read authentication state and role data.
// ------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


// ------------------------------------------------------------
// * FILE DEPENDENCIES
// ! Configures database PDO connection and authorization helpers.
// ------------------------------------------------------------
require_once "../../config/database.php";
require_once "../../config/audit.php";
require_once "../../config/content_access.php";


// ------------------------------------------------------------
// * ACCESS CONTROL: AUTHENTICATION CHECK
// ! Bounces unauthenticated guests directly to the login panel.
// ------------------------------------------------------------
if (!isset($_SESSION["user_id"])) {
    header("Location: ../../auth/login.php");
    exit;
}


// ------------------------------------------------------------
// * ACCESS CONTROL: ROLE CHECK
// ! Rejects users without admin or teacher role assignments.
// ------------------------------------------------------------
if (!in_array($_SESSION["user_role"] ?? "", ["admin", "teacher"], true)) {
    header("Location: ../../index.php");
    exit;
}


// ------------------------------------------------------------
// * CSRF PROTECTION INITIALIZATION
// ? Generates a secure random token if one does not exist for POST forms.
// ------------------------------------------------------------
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}


// ------------------------------------------------------------
// * QUERY PARAMETER SANITIZATION
// ? Validates and casts target lesson ID parameter from GET request.
// ------------------------------------------------------------
$lessonId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$lessonId || $lessonId < 1) {
    header("Location: lessons.php");
    exit;
}


// ------------------------------------------------------------
// * DATABASE QUERY: FETCH LESSON & PARENT CONTEXT
// ? Verifies lesson existence along with parent module and course details.
// ------------------------------------------------------------
$find = $pdo->prepare("SELECT lessons.*, modules.title AS module_title, courses.title AS course_title FROM lessons INNER JOIN modules ON modules.id = lessons.module_id INNER JOIN courses ON courses.id = modules.course_id WHERE lessons.id = ? LIMIT 1");
$find->execute([$lessonId]);
$lesson = $find->fetch();


// ------------------------------------------------------------
// * AUTHORIZATION CHECK
// ! Verifies user permissions to manage this specific lesson entity.
// ------------------------------------------------------------
if (!$lesson || !canManageLesson($pdo, (int) $lessonId, (int) $_SESSION["user_id"], $_SESSION["user_role"])) {
    header("Location: lessons.php");
    exit;
}


// ------------------------------------------------------------
// * DATABASE QUERY: FETCH EXISTING VIDEO MATERIAL
// ? Queries associated video attachments for the active lesson.
// ------------------------------------------------------------
$videoStatement = $pdo->prepare("SELECT external_url FROM materials WHERE lesson_id = ? AND type = 'video' ORDER BY position ASC LIMIT 1");
$videoStatement->execute([$lessonId]);
$videoUrl = $videoStatement->fetchColumn() ?: "";
$materialsStatement = $pdo->prepare("SELECT id, title, type, content, external_url, file_name FROM materials WHERE lesson_id = ? ORDER BY position ASC, id ASC");
$materialsStatement->execute([$lessonId]);
$materials = $materialsStatement->fetchAll();


// ------------------------------------------------------------
// * INITIALIZE FORM VALUES FROM DATABASE
// ------------------------------------------------------------
$title = $lesson["title"];
$description = $lesson["description"] ?? "";
$content = $lesson["content"] ?? "";
$minutes = $lesson["estimated_minutes"] ?? "";
$isPublished = (int) $lesson["is_published"];
$error = "";


// ------------------------------------------------------------
// * FORM PROCESSING: POST REQUEST
// ------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // * Sanitize form input strings
    $title = trim($_POST["title"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $content = trim($_POST["content"] ?? "");
    $videoUrl = trim($_POST["video_url"] ?? "");
    $materialTitles = $_POST["material_title"] ?? [];
    $materialTypes = $_POST["material_type"] ?? [];
    $materialContents = $_POST["material_content"] ?? [];
    $materialUrls = $_POST["material_url"] ?? [];
    $deleteMaterialIds = array_map("intval", $_POST["delete_material_ids"] ?? []);
    $minutes = trim($_POST["estimated_minutes"] ?? "");
    $isPublished = ($_SESSION["user_role"] ?? "") === "admin" ? (isset($_POST["is_published"]) ? 1 : 0) : (int) $lesson["is_published"];

    // ! Input Validation Checks
    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        $error = "La session du formulaire a expiré.";
    } elseif ($title === "") {
        $error = "Le titre de la leçon est obligatoire.";
    } elseif ($minutes !== "" && (!ctype_digit($minutes) || (int) $minutes < 1)) {
        $error = "La durée doit être un entier positif.";
    } elseif ($videoUrl !== "" && (!filter_var($videoUrl, FILTER_VALIDATE_URL) || !preg_match('/(youtube\.com|youtu\.be|vimeo\.com)/i', $videoUrl))) {
        $error = "La vidéo doit être un lien YouTube ou Vimeo valide.";
    }

    if ($error === "") {
        foreach ($materialTypes as $index => $type) {
            $materialTitle = trim($materialTitles[$index] ?? "");
            $materialContent = trim($materialContents[$index] ?? "");
            $materialUrl = trim($materialUrls[$index] ?? "");
            $hasFile = ($_FILES["material_file"]["error"][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($materialTitle === "" && $materialContent === "" && $materialUrl === "" && !$hasFile) { continue; }
            if ($materialTitle === "" || !in_array($type, ["text", "video", "file"], true)) { $error = "Chaque support doit avoir un titre et un type valide."; break; }
            if ($type === "text" && $materialContent === "") { $error = "Un support texte doit contenir du texte."; break; }
            if ($type === "video" && (!filter_var($materialUrl, FILTER_VALIDATE_URL) || !preg_match('/(youtube\.com|youtu\.be|vimeo\.com)/i', $materialUrl))) { $error = "La vidéo doit être un lien YouTube ou Vimeo valide."; break; }
            if ($type === "file" && ($_FILES["material_file"]["error"][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $error = "Veuillez sélectionner un fichier pour chaque support fichier."; break; }
        }
    }

    // * Database Update Processing
    if ($error === "") {

        // * Update core lesson details
        $update = $pdo->prepare("UPDATE lessons SET title = ?, description = ?, content = ?, estimated_minutes = ?, is_published = ? WHERE id = ? AND module_id = ?");
        $update->execute([$title, $description ?: null, $content ?: null, $minutes === "" ? null : (int) $minutes, $isPublished, $lessonId, $lesson["module_id"]]);
        auditLog($pdo, (int) $_SESSION["user_id"], "lesson_updated", "lesson", (int) $lessonId, null, ["title" => $title]);

        // * Replace the single lesson video while preserving it when unchanged.
        $deleteVideo = $pdo->prepare("DELETE FROM materials WHERE lesson_id = ? AND type = 'video'");
        $deleteVideo->execute([$lessonId]);

        if ($videoUrl !== "") {
            $material = $pdo->prepare("INSERT INTO materials (lesson_id, title, type, external_url, position) VALUES (?, ?, 'video', ?, 1)");
            $material->execute([$lessonId, "Vidéo de la leçon", $videoUrl]);
        }

        if ($deleteMaterialIds) {
            $placeholders = implode(",", array_fill(0, count($deleteMaterialIds), "?"));
            $findMaterials = $pdo->prepare(
                "SELECT id, file_path FROM materials WHERE lesson_id = ? AND id IN ($placeholders)"
            );
            $findMaterials->execute(array_merge([$lessonId], $deleteMaterialIds));
            $materialsToDelete = $findMaterials->fetchAll();

            $deleteMaterials = $pdo->prepare(
                "DELETE FROM materials WHERE lesson_id = ? AND id IN ($placeholders)"
            );
            $deleteMaterials->execute(array_merge([$lessonId], $deleteMaterialIds));

            foreach ($materialsToDelete as $materialToDelete) {
                if (!empty($materialToDelete["file_path"])) {
                    $filePath = __DIR__ . "/../../uploads/documents/" . basename($materialToDelete["file_path"]);
                    if (is_file($filePath)) {
                        unlink($filePath);
                    }
                }
            }
        }

        // * Append newly added supports after the lesson update.
        $materialInsert = $pdo->prepare("INSERT INTO materials (lesson_id, title, type, content, file_path, file_name, file_size, mime_type, external_url, position) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($materialTypes ?? [] as $index => $type) {
            $materialTitle = trim($materialTitles[$index] ?? "");
            if ($materialTitle === "") { continue; }
            $materialContent = $type === "text" ? trim($materialContents[$index] ?? "") : null;
            $materialUrl = $type === "video" ? trim($materialUrls[$index] ?? "") : null;
            $filePath = $fileName = $mimeType = null; $fileSize = null;
            if ($type === "file" && ($_FILES["material_file"]["error"][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $allowedFiles = ["application/pdf" => "pdf", "application/msword" => "doc", "application/vnd.openxmlformats-officedocument.wordprocessingml.document" => "docx", "application/vnd.ms-powerpoint" => "ppt", "application/vnd.openxmlformats-officedocument.presentationml.presentation" => "pptx"];
                $temporaryFile = $_FILES["material_file"]["tmp_name"][$index]; $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryFile);
                if (!isset($allowedFiles[$mimeType]) || $_FILES["material_file"]["size"][$index] > 20 * 1024 * 1024) { $error = "Fichier de support invalide."; break; }
                $fileName = basename($_FILES["material_file"]["name"][$index]); $filePath = bin2hex(random_bytes(16)) . "." . $allowedFiles[$mimeType]; if (!is_dir("../../uploads/documents")) { mkdir("../../uploads/documents", 0755, true); } move_uploaded_file($temporaryFile, "../../uploads/documents/" . $filePath); $fileSize = $_FILES["material_file"]["size"][$index];
            }
            $materialInsert->execute([$lessonId, $materialTitle, $type, $materialContent, $filePath, $fileName, $fileSize, $mimeType, $materialUrl, 1]);
        }

        // ? Redirect back to parent module lessons view
        header("Location: lessons.php?module_id=" . $lesson["module_id"]);
        exit;

    }

}

?>

<!DOCTYPE html>

<html lang="fr">

<head>

    <!-- * Basic page metadata and responsive viewport settings -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Modifier une leçon - Administration</title><link rel="icon" type="image/png" href="../../assets/images/logo-mark.png">

    <!-- * Main administrative layout stylesheet import -->
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">

</head>

<body class="admin-body lesson-editor-page<?= ($_SESSION["user_role"] ?? "") === "teacher" ? " teacher-view" : "" ?>">

    <div class="admin-layout">

        <!-- ==========================================================
             * ADMINISTRATIVE SIDEBAR NAVIGATION
             ========================================================== -->

        <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('lessons/lesson_edit.php'); ?>


        <!-- ==========================================================
             * MAIN ADMINISTRATIVE CONTENT
             ========================================================== -->

        <main class="admin-main">

            <!-- ==========================================================
                 * DASHBOARD TOP BAR
                 ========================================================== -->

            <header class="admin-topbar">

                <div>

                    <h1>
                        Modifier une leçon
                    </h1>

                    <p>
                        <?= htmlspecialchars($lesson["course_title"] . " / " . $lesson["module_title"]) ?>
                    </p>

                </div>

                <a href="lessons.php?module_id=<?= (int) $lesson["module_id"] ?>" class="btn btn-secondary">
                    Retour aux leçons
                </a>

            </header>


            <!-- ==========================================================
                 * LESSON EDIT FORM SECTION
                 ========================================================== -->

            <section class="dashboard-section">

                <!-- ! Error Notification Banner -->
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <div class="form-card">

                    <div class="form-card-header">
                        <h2>Informations de la leçon</h2>
                        <p>Modifiez le contenu pédagogique.</p>
                    </div>

                    <!-- * Content Editor Form -->
                    <form method="POST" enctype="multipart/form-data" onsubmit="document.getElementById('content').value = document.getElementById('content_editor').innerHTML;">

                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">

                        <!-- * Title Field -->
                        <div class="form-group">
                            <label for="title">Titre *</label>
                            <input id="title" name="title" value="<?= htmlspecialchars($title) ?>" required>
                        </div>

                        <!-- * Summary / Description Field -->
                        <div class="form-group">
                            <label for="description">Résumé</label>
                            <textarea id="description" name="description" rows="3"><?= htmlspecialchars($description) ?></textarea>
                        </div>

                        <!-- * Rich Text Lesson Content Editor -->
                        <div class="form-group">

                            <label for="content_editor">Contenu</label>

                            <!-- * Editor Toolbar Buttons -->
                            <div class="lesson-editor-toolbar">
                                <button type="button" data-command="bold"><strong>G</strong></button>
                                <button type="button" data-command="italic"><em>I</em></button>
                                <button type="button" data-command="insertUnorderedList">Liste</button>
                                <button type="button" data-command="formatBlock" data-value="h3">Titre</button>
                            </div>

                            <!-- * Editable Container & Hidden Form Input -->
                            <div id="content_editor" class="lesson-content-editor" contenteditable="true"><?= $content ?></div>
                            <textarea id="content" name="content" hidden><?= htmlspecialchars($content) ?></textarea>

                            <small>Utilisez la barre d'outils pour mettre le contenu en forme.</small>

                        </div>

                        <fieldset class="lesson-materials">
                            <legend>Supports existants</legend>
                            <?php if ($materials): ?>
                                <?php foreach ($materials as $material): ?>
                                    <div class="existing-material">
                                        <div>
                                            <strong><?= htmlspecialchars($material["title"]) ?></strong>
                                            <span><?= htmlspecialchars($material["type"]) ?><?= $material["file_name"] ? " - " . htmlspecialchars($material["file_name"]) : "" ?></span>
                                        </div>
                                        <?php if ($material["type"] === "video"): ?>
                                            <input type="url" name="video_url" value="<?= htmlspecialchars($material["external_url"] ?? "") ?>" placeholder="URL YouTube ou Vimeo">
                                        <?php endif; ?>
                                        <label class="material-delete">
                                            <input type="checkbox" name="delete_material_ids[]" value="<?= (int) $material["id"] ?>">
                                            Supprimer
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="materials-help">Aucun support ajouté.</p>
                            <?php endif; ?>
                        </fieldset>

                        <fieldset class="lesson-materials">
                            <legend>Ajouter des supports</legend>
                            <p class="materials-help">Ajoutez un support texte, vidéo ou fichier.</p>
                            <div id="materials-list">
                                <div class="material-row">
                                    <input type="text" name="material_title[]" placeholder="Titre du support">
                                    <select name="material_type[]" class="material-type"><option value="text">Texte</option><option value="video">Vidéo</option><option value="file">Fichier</option></select>
                                    <textarea name="material_content[]" class="material-content" placeholder="Contenu du texte" rows="3"></textarea>
                                    <input type="url" name="material_url[]" placeholder="URL YouTube ou Vimeo" class="material-url">
                                    <input type="file" name="material_file[]" class="material-file" accept=".pdf,.doc,.docx,.ppt,.pptx">
                                    <button type="button" class="remove-material">Supprimer</button>
                                </div>
                            </div>
                            <button type="button" id="add-material" class="btn btn-secondary">+ Ajouter un support</button>
                        </fieldset>

                        <!-- * Estimated Completion Duration -->
                        <div class="form-group">
                            <label for="estimated_minutes">Durée estimée (minutes)</label>
                            <input type="number" id="estimated_minutes" name="estimated_minutes" min="1" value="<?= htmlspecialchars((string) $minutes) ?>">
                        </div>

                        <!-- * Publication Status Toggle -->
                        <div class="form-group checkbox-group">
                            <label>
                                <input type="checkbox" name="is_published" value="1" <?= $isPublished ? "checked" : "" ?>> Leçon publiée
                            </label>
                        </div>

                        <!-- * Form Action Buttons -->
                        <div class="form-actions">
                            <a href="lessons.php?module_id=<?= (int) $lesson["module_id"] ?>" class="btn btn-secondary">Annuler</a>
                            <button type="submit" class="btn btn-primary">Enregistrer</button>
                        </div>

                    </form>

                </div>

            </section>

        </main>


        <!-- ==========================================================
             * RICH TEXT EDITOR TOOLBAR JAVASCRIPT
             ========================================================== -->

        <script>
            document.querySelectorAll('.lesson-editor-toolbar button').forEach(function(button) {
                button.addEventListener('click', function() {
                    document.execCommand(button.dataset.command, false, button.dataset.value || null);
                    document.getElementById('content_editor').focus();
                });
            });

            const materialsList = document.getElementById('materials-list');
            const addMaterial = document.getElementById('add-material');
            function updateMaterialFields(row) {
                const type = row.querySelector('.material-type').value;
                row.querySelector('.material-content').hidden = type !== 'text';
                row.querySelector('.material-url').hidden = type !== 'video';
                row.querySelector('.material-file').hidden = type !== 'file';
            }
            addMaterial.addEventListener('click', function() {
                const row = materialsList.firstElementChild.cloneNode(true);
                row.querySelectorAll('input, textarea').forEach(function(field) { field.value = ''; });
                row.querySelector('.material-type').value = 'text';
                materialsList.appendChild(row);
                updateMaterialFields(row);
            });
            materialsList.addEventListener('change', function(event) {
                if (event.target.classList.contains('material-type')) updateMaterialFields(event.target.closest('.material-row'));
            });
            materialsList.addEventListener('click', function(event) {
                if (event.target.classList.contains('remove-material') && materialsList.children.length > 1) event.target.closest('.material-row').remove();
            });
            materialsList.querySelectorAll('.material-row').forEach(updateMaterialFields);
        </script>

    </div>

<script src="../../assets/js/teacher-navigation.js?v=20260925b"></script>
<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script></body>

</html>




