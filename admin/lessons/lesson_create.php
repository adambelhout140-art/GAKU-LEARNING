<?php
require_once __DIR__ . '/../../config/i18n.php';// ============================================================
// * GAKU LEARNING - ADMIN LESSON CREATION
// ============================================================
// ! RESTRICTED ACCESS: Only authenticated Admins and Teachers can access.
// ? Purpose: Creates one complete lesson inside a verified module.
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
// ? Validates and casts parent module ID parameter from GET request.
// ------------------------------------------------------------
$moduleId = filter_input(INPUT_GET, "module_id", FILTER_VALIDATE_INT);
$returnToContent = ($_GET["return_to"] ?? "") === "content";

if (!$moduleId || $moduleId < 1) {
    header("Location: lessons.php");
    exit;
}


// ------------------------------------------------------------
// * DATABASE QUERY: FETCH PARENT MODULE & COURSE CONTEXT
// ? Verifies module existence along with parent course details.
// ------------------------------------------------------------
$moduleStatement = $pdo->prepare("SELECT modules.id, modules.course_id, modules.title, courses.title AS course_title FROM modules INNER JOIN courses ON courses.id = modules.course_id WHERE modules.id = ? LIMIT 1");
$moduleStatement->execute([$moduleId]);
$module = $moduleStatement->fetch();


// ------------------------------------------------------------
// * AUTHORIZATION CHECK
// ! Verifies user permissions to manage this specific parent module entity.
// ------------------------------------------------------------
if (!$module || !canManageModule($pdo, (int) $moduleId, (int) $_SESSION["user_id"], $_SESSION["user_role"])) {
    header("Location: lessons.php");
    exit;
}


// ------------------------------------------------------------
// * INITIALIZE FORM VALUES
// ------------------------------------------------------------
$title = "";
$description = "";
$content = "";
$materialTitles = $_POST["material_title"] ?? [];
$materialTypes = $_POST["material_type"] ?? [];
$materialContents = $_POST["material_content"] ?? [];
$materialUrls = $_POST["material_url"] ?? [];
$minutes = "";
$isPublished = 1;
$error = "";


// ------------------------------------------------------------
// * FORM PROCESSING: POST REQUEST
// ------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $returnToContent = ($_POST["return_to"] ?? "") === "content";

    // * Sanitize form input strings
    $title = trim($_POST["title"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $content = trim($_POST["content"] ?? "");
    $materialTitles = $_POST["material_title"] ?? [];
    $materialTypes = $_POST["material_type"] ?? [];
    $materialContents = $_POST["material_content"] ?? [];
    $materialUrls = $_POST["material_url"] ?? [];
    $minutes = trim($_POST["estimated_minutes"] ?? "");
    $isPublished = ($_SESSION["user_role"] ?? "") === "admin" && isset($_POST["is_published"]) ? 1 : 0;

    // ! Input Validation Checks
    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        $error = "La session du formulaire a expiré.";
    } elseif ($title === "") {
        $error = "Le titre de la leçon est obligatoire.";
    } elseif ($minutes !== "" && (!ctype_digit($minutes) || (int) $minutes < 1)) {
        $error = "La durée doit être un entier positif.";
    }

    // ? Validate every optional support before creating the lesson.
    if ($error === "") {
        foreach ($materialTypes as $index => $type) {
            $materialTitle = trim($materialTitles[$index] ?? "");
            $materialContent = trim($materialContents[$index] ?? "");
            $materialUrl = trim($materialUrls[$index] ?? "");
            if ($materialTitle === "" && $materialContent === "" && $materialUrl === "" && empty($_FILES["material_file"]["name"][$index])) { continue; }
            if ($materialTitle === "" || !in_array($type, ["text", "video", "file"], true)) { $error = "Chaque support doit avoir un titre et un type valide."; break; }
            if ($type === "text" && $materialContent === "") { $error = "Un support texte doit contenir du texte."; break; }
            if ($type === "video" && (!filter_var($materialUrl, FILTER_VALIDATE_URL) || !preg_match('/(youtube\.com|youtu\.be|vimeo\.com)/i', $materialUrl))) { $error = "La vidéo doit être un lien YouTube ou Vimeo valide."; break; }
            if ($type === "file") {
                $fileError = $_FILES["material_file"]["error"][$index] ?? UPLOAD_ERR_NO_FILE;
                if ($fileError !== UPLOAD_ERR_OK) { $error = "Veuillez sélectionner un fichier PDF pour chaque support fichier."; break; }
                $temporaryFile = $_FILES["material_file"]["tmp_name"][$index] ?? "";
                $mimeType = $temporaryFile !== "" ? (new finfo(FILEINFO_MIME_TYPE))->file($temporaryFile) : false;
                if ($mimeType !== "application/pdf" || (int) ($_FILES["material_file"]["size"][$index] ?? 0) > 20 * 1024 * 1024) {
                    $error = "Chaque support doit être un PDF valide de 20 Mo maximum.";
                    break;
                }
            }
        }
    }

    // * Database Insertion Processing
    if ($error === "") {

        // * Calculate next position index in module sequence
        $positionStatement = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM lessons WHERE module_id = ?");
        $positionStatement->execute([$moduleId]);
        $position = (int) $positionStatement->fetchColumn();

        // * Insert new lesson record
        $insert = $pdo->prepare("INSERT INTO lessons (module_id, title, description, content, position, estimated_minutes, is_published) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insert->execute([$moduleId, $title, $description ?: null, $content ?: null, $position, $minutes === "" ? null : (int) $minutes, $isPublished]);

        // * Save each support after the lesson exists so its foreign key is valid.
        $lessonId = (int) $pdo->lastInsertId();
        auditLog($pdo, (int) $_SESSION["user_id"], "lesson_created", "lesson", $lessonId, null, ["title" => $title, "module_id" => $moduleId]);
        $materialInsert = $pdo->prepare("INSERT INTO materials (lesson_id, title, type, content, file_path, file_name, file_size, mime_type, external_url, position) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($materialTypes as $index => $type) {
            $materialTitle = trim($materialTitles[$index] ?? "");
            if ($materialTitle === "") { continue; }
            $materialContent = $type === "text" ? trim($materialContents[$index] ?? "") : null;
            $materialUrl = $type === "video" ? trim($materialUrls[$index] ?? "") : null;
            $filePath = $fileName = $mimeType = null; $fileSize = null;
            if ($type === "file" && ($_FILES["material_file"]["error"][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $allowedFiles = ["application/pdf" => "pdf"];
                $temporaryFile = $_FILES["material_file"]["tmp_name"][$index]; $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryFile);
                if (!isset($allowedFiles[$mimeType]) || $_FILES["material_file"]["size"][$index] > 20 * 1024 * 1024) { throw new RuntimeException("Le support doit être un PDF de 20 Mo maximum."); }
                $fileName = basename($_FILES["material_file"]["name"][$index]); $filePath = bin2hex(random_bytes(16)) . "." . $allowedFiles[$mimeType]; if (!is_dir("../../uploads/documents")) { mkdir("../../uploads/documents", 0755, true); } move_uploaded_file($temporaryFile, "../../uploads/documents/" . $filePath); $fileSize = $_FILES["material_file"]["size"][$index];
            }
            $materialInsert->execute([$lessonId, $materialTitle, $type, $materialContent, $filePath, $fileName, $fileSize, $mimeType, $materialUrl, $index + 1]);
        }

        // ? Redirect back to parent module lessons view
        header("Location: " . ($returnToContent && ($_SESSION["user_role"] ?? "") === "admin"
            ? "../content.php?course_id=" . (int) $module["course_id"] . "&module_id=" . $moduleId
            : "lessons.php?module_id=" . $moduleId));
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

    <title>Ajouter une leçon - Administration</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">

    <!-- * Main administrative layout stylesheet import -->
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">

</head>

<body class="admin-body lesson-editor-page<?= ($_SESSION["user_role"] ?? "") === "teacher" ? " teacher-view" : "" ?>">

    <div class="admin-layout">

        <!-- ==========================================================
             * ADMINISTRATIVE SIDEBAR NAVIGATION
             ========================================================== -->

        <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('lessons/lesson_create.php'); ?>


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
                        Ajouter une leçon
                    </h1>

                    <p>
                        <?= htmlspecialchars($module["course_title"] . " / " . $module["title"]) ?>
                    </p>

                </div>

                <a href="lessons.php?module_id=<?= (int) $moduleId ?>" class="btn btn-secondary">
                    Retour aux leçons
                </a>

            </header>


            <!-- ==========================================================
                 * LESSON CREATION FORM SECTION
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
                        <p>Ajoutez le contenu pédagogique de cette leçon.</p>
                    </div>

                    <!-- * Content Creation Form -->
                    <form method="POST" enctype="multipart/form-data" onsubmit="document.getElementById('content').value = document.getElementById('content_editor').innerHTML;">
                        <input type="hidden" name="return_to" value="<?= $returnToContent ? "content" : "" ?>">

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
                            <div class="lesson-editor-toolbar">
                                <button type="button" data-command="bold"><strong>G</strong></button>
                                <button type="button" data-command="italic"><em>I</em></button>
                                <button type="button" data-command="insertUnorderedList">Liste</button>
                                <button type="button" data-command="formatBlock" data-value="h3">Titre</button>
                            </div>
                            <div id="content_editor" class="lesson-content-editor" contenteditable="true"><?= htmlspecialchars($content) ?></div>
                            <textarea id="content" name="content" hidden><?= htmlspecialchars($content) ?></textarea>
                            <small>Utilisez la barre d'outils pour mettre le contenu en forme.</small>
                        </div>

                        <fieldset class="lesson-materials">
                            <legend>Supports de la leçon</legend>
                            <p class="materials-help">Ajoutez des textes, des vidéos YouTube/Vimeo ou des fichiers PDF de 20 Mo maximum. Les étudiants consulteront les PDF dans la leçon.</p>
                            <div id="materials-list">
                                <div class="material-row">
                                    <input type="text" name="material_title[]" placeholder="Titre du support">
                                    <select name="material_type[]" class="material-type"><option value="text">Texte</option><option value="video">Vidéo</option><option value="file">Fichier</option></select>
                                    <textarea name="material_content[]" class="material-content" placeholder="Contenu du texte" rows="3"></textarea>
                                    <input type="url" name="material_url[]" placeholder="URL YouTube ou Vimeo" class="material-url">
                                    <input type="file" name="material_file[]" class="material-file" accept=".pdf,application/pdf">
                                    <button type="button" class="remove-material" title="Supprimer ce support">Supprimer</button>
                                </div>
                            </div>
                            <button type="button" id="add-material" class="btn btn-secondary">+ Ajouter un support</button>
                        </fieldset>

                        <!-- * Estimated Completion Duration -->
                        <div class="form-group">
                            <label for="estimated_minutes">Durée estimée (minutes)</label>
                            <input type="number" id="estimated_minutes" name="estimated_minutes" min="1" value="<?= htmlspecialchars($minutes) ?>">
                        </div>

                        <!-- * Publication Status Toggle -->
                        <div class="form-group checkbox-group">
                            <label>
                                <input type="checkbox" name="is_published" value="1" <?= $isPublished ? "checked" : "" ?>> Leçon publiée
                            </label>
                        </div>

                        <!-- * Form Action Buttons -->
                        <div class="form-actions">
                            <a href="lessons.php?module_id=<?= (int) $moduleId ?>" class="btn btn-secondary">Annuler</a>
                            <button type="submit" class="btn btn-primary">Créer la leçon</button>
                        </div>

                    </form>

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
