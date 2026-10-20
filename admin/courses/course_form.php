<?php
require_once __DIR__ . '/../../config/i18n.php';// ============================================================
// * ADMIN COURSE FORM TEMPLATE
// ============================================================
// ! RESTRICTED ACCESS: Rendered via create/edit controllers with admin privileges.
// ? Purpose: Reusable, structured admin form for creating or modifying course records.
// ============================================================

// ------------------------------------------------------------
// * TEMPLATE VARIABLE INITIALIZATION
// ? Defaults keep this shared form template safe to inspect or render standalone.
// ------------------------------------------------------------
$editing          = $editing ?? false;
$error            = $error ?? "";
$teachers         = $teachers ?? [];
$title            = $title ?? "";
$slug             = $slug ?? "";
$shortDescription = $shortDescription ?? "";
$description      = $description ?? "";
$objectives       = $objectives ?? "";
$prerequisites    = $prerequisites ?? "";
$category         = $category ?? "";
$duration         = $duration ?? "";
$price            = $price ?? "";
$level            = $level ?? "beginner";
$format           = $format ?? "online";
$status           = $status ?? "draft";
$teacherId        = $teacherId ?? "";
$teacherIds       = $teacherIds ?? ($teacherId !== "" ? [(string) $teacherId] : []);
$userRole         = $userRole ?? ($_SESSION["user_role"] ?? "admin");
$returnUrl        = $userRole === "teacher" ? "../../teacher/dashboard.php" : "courses.php";

?>
<!DOCTYPE html>
<html lang="fr">

<head>

    <!-- * Basic page metadata and responsive viewport settings -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= $editing ? "Modifier" : "Ajouter" ?> une formation - Administration</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">

    <!-- * Main administrative layout stylesheet import -->
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">

</head>

<body class="admin-body">

    <div class="admin-layout">

        <!-- ==========================================================
             * ADMINISTRATIVE SIDEBAR NAVIGATION
             ========================================================== -->

        <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('courses/course_form.php'); ?>


        <!-- ==========================================================
             * MAIN ADMINISTRATIVE CONTENT
             ========================================================== -->

        <main class="admin-main">

            <!-- ==========================================================
                 * DASHBOARD TOP BAR
                 ========================================================== -->

            <header class="admin-topbar">

                <div>
                    <h1><?= $editing ? "Modifier" : "Ajouter" ?> une formation</h1>
                    <p><?= $editing ? "Mettre à jour cette formation" : "Créer une nouvelle formation" ?></p>
                </div>

                <a href="<?= htmlspecialchars($returnUrl) ?>" class="btn btn-secondary">
                    Retour aux formations
                </a>

            </header>


            <!-- ==========================================================
                 * FORM SECTION & ERROR ALERTS
                 ========================================================== -->

            <section class="dashboard-section">

                <!-- * Error Alert Banner -->
                <?php if ($error !== ""): ?>
                    <div class="alert alert-danger">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <div class="form-card">

                    <div class="form-card-header">
                        <h2>Informations de la formation</h2>
                        <p>Les champs marqués d'un astérisque sont obligatoires.</p>
                    </div>

                    <!-- ==========================================================
                         * COURSE EDIT / CREATE FORM
                         ========================================================== -->

                    <form method="POST" enctype="multipart/form-data">

                        <!-- * Anti-CSRF Security Token -->
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"] ?? "") ?>">
                        <input type="hidden" name="return_to" value="<?= ($returnToContent ?? false) ? "content" : "" ?>">

                        <!-- * Course Title -->
                        <div class="form-group">
                            <label for="title">Titre *</label>
                            <input id="title" name="title" value="<?= htmlspecialchars($title) ?>" required>
                        </div>

                        <!-- * URL Slug -->
                        <div class="form-group">
                            <label for="slug">Slug *</label>
                            <input id="slug" name="slug" value="<?= htmlspecialchars($slug) ?>" required>
                            <small>Utilisé dans l'URL publique.</small>
                        </div>

                        <!-- * Short Summary -->
                        <div class="form-group">
                            <label for="short_description">Résumé</label>
                            <textarea id="short_description" name="short_description"><?= htmlspecialchars($shortDescription) ?></textarea>
                        </div>

                        <!-- * Full Description -->
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" rows="5"><?= htmlspecialchars($description) ?></textarea>
                        </div>

                        <!-- * Learning Objectives -->
                        <div class="form-group">
                            <label for="objectives">Objectifs</label>
                            <textarea id="objectives" name="objectives" rows="4"><?= htmlspecialchars($objectives) ?></textarea>
                        </div>

                        <!-- * Course Prerequisites -->
                        <div class="form-group">
                            <label for="prerequisites">Prérequis</label>
                            <textarea id="prerequisites" name="prerequisites" rows="4"><?= htmlspecialchars($prerequisites) ?></textarea>
                        </div>

                        <!-- * Teacher Assignment -->
                        <?php if (($userRole ?? "admin") === "admin"): ?><div class="form-group">
                            <label for="teacher_search">Formateurs</label>
                            <input type="search" id="teacher_search" class="assignment-search" placeholder="Rechercher puis sélectionner un formateur..." autocomplete="off">
                            <div class="assignment-suggestions" data-assignment-suggestions></div>
                            <div class="assignment-chips" data-assignment-chips>
                                <?php foreach ($teachers as $teacher): ?>
                                    <?php $teacherIdValue = (string) $teacher["id"]; $teacherLabel = trim($teacher["first_name"] . " " . $teacher["last_name"]); ?>
                                    <?php if (in_array($teacherIdValue, array_map("strval", $teacherIds), true)): ?>
                                        <span class="assignment-chip" data-assignment-id="<?= (int) $teacher["id"] ?>"><?= htmlspecialchars($teacherLabel) ?><button type="button" aria-label="Retirer">×</button><input type="hidden" name="teacher_ids[]" value="<?= (int) $teacher["id"] ?>"></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <div class="assignment-data" hidden>
                                <?php foreach ($teachers as $teacher): ?>
                                    <span data-assignment-id="<?= (int) $teacher["id"] ?>" data-assignment-label="<?= htmlspecialchars(trim($teacher["first_name"] . " " . $teacher["last_name"]), ENT_QUOTES, "UTF-8") ?>"></span>
                                <?php endforeach; ?>
                            </div>
                            <small>Ajoutez un ou plusieurs formateurs avec la recherche.</small>
                        </div><?php else: ?><input type="hidden" name="teacher_id" value="<?= (int) ($_SESSION["user_id"] ?? 0) ?>"><?php endif; ?>

                        <!-- * Category Name -->
                        <div class="form-group">
                            <label for="category">Catégorie</label>
                            <input id="category" name="category" value="<?= htmlspecialchars($category) ?>">
                        </div>

                        <!-- * Duration in Hours -->
                        <div class="form-group">
                            <label for="duration_hours">Durée (heures)</label>
                            <input type="number" step="0.01" min="0" id="duration_hours" name="duration_hours" value="<?= htmlspecialchars($duration) ?>">
                        </div>

                        <!-- * Price -->
                        <div class="form-group">
                            <label for="price">Prix</label>
                            <input type="number" step="0.01" min="0" id="price" name="price" value="<?= htmlspecialchars($price) ?>">
                        </div>

                        <!-- * Difficulty Level -->
                        <div class="form-group">
                            <label for="level">Niveau</label>
                            <select id="level" name="level">
                                <option value="beginner" <?= $level === "beginner" ? "selected" : "" ?>>Débutant</option>
                                <option value="intermediate" <?= $level === "intermediate" ? "selected" : "" ?>>Intermédiaire</option>
                                <option value="advanced" <?= $level === "advanced" ? "selected" : "" ?>>Avancé</option>
                            </select>
                        </div>

                        <!-- * Course Delivery Format -->
                        <div class="form-group">
                            <label for="format">Format</label>
                            <select id="format" name="format">
                                <option value="online" <?= $format === "online" ? "selected" : "" ?>>En ligne</option>
                                <option value="hybrid" <?= $format === "hybrid" ? "selected" : "" ?>>Hybride</option>
                                <option value="in_person" <?= $format === "in_person" ? "selected" : "" ?>>Présentiel</option>
                            </select>
                        </div>

                        <!-- * Publication Status -->
                        <div class="form-group">
                            <label for="status">Statut</label>
                            <select id="status" name="status">
                                <option value="draft" <?= $status === "draft" ? "selected" : "" ?>>Brouillon</option>
                                <option value="published" <?= $status === "published" ? "selected" : "" ?>>Publié</option>
                                <option value="unpublished" <?= $status === "unpublished" ? "selected" : "" ?>>Dépublié</option>
                            </select>
                        </div>

                        <!-- * Cover Image File Upload -->
                        <div class="form-group">
                            <label for="cover_image">Image de couverture</label>
                            <input type="file" id="cover_image" name="cover_image" accept="image/jpeg,image/png,image/webp">
                            <small>JPG, PNG ou WEBP, 5 Mo maximum.</small>
                        </div>

                        <!-- * Form Action Buttons -->
                        <div class="form-actions">
                            <a href="<?= htmlspecialchars($returnUrl) ?>" class="btn btn-secondary">Annuler</a>
                            <button type="submit" class="btn btn-primary"><?= $editing ? "Enregistrer" : "Créer" ?> la formation</button>
                        </div>

                    </form>

                </div>

            </section>

        </main>

    </div>
<style>
.form-group:has(.assignment-search) {
    position: relative;
}
.assignment-search {
    width: 100%;
    min-height: 44px;
    margin-top: .65rem;
    border: 1px solid #bcccdc;
    border-radius: 10px;
    padding: .7rem .85rem;
    box-sizing: border-box;
}
.assignment-suggestions {
    position: absolute;
    z-index: 2;
    left: 0;
    right: 0;
    max-height: 180px;
    overflow-y: auto;
    border: 1px solid #bcccdc;
    border-radius: 0 0 10px 10px;
    background: #fff;
    box-shadow: 0 8px 18px rgba(16, 42, 67, .14);
}
.assignment-suggestion {
    padding: .7rem .85rem;
    cursor: pointer;
}
.assignment-suggestion:hover {
    background: #f0f4f8;
}
.assignment-chips {
    display: flex;
    flex-wrap: wrap;
    gap: .4rem;
    min-height: 18px;
    margin-top: .65rem;
}
.assignment-chip {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    max-width: 100%;
    padding: .45rem .55rem .45rem .75rem;
    border-radius: 999px;
    background: #e8f1ff;
    border: 1px solid #b8d0f5;
    color: #163a63;
    font-size: .9rem;
}
.assignment-chip button {
    border: 0;
    background: transparent;
    cursor: pointer;
    color: #486581;
    font-size: 1.1rem;
}
.assignment-chip button:hover {
    color: #c53030;
}
</style>

<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script>
<script>
(() => {
    const search = document.getElementById("teacher_search");
    if (!search) return;
    const group = search.closest(".form-group");
    const suggestions = group.querySelector("[data-assignment-suggestions]");
    const chips = group.querySelector("[data-assignment-chips]");
    const teachers = Array.from(group.querySelectorAll(".assignment-data [data-assignment-id]"));
    const render = () => {
        const query = search.value.trim().toLowerCase();
        suggestions.innerHTML = "";
        if (!query) return;
        const selected = new Set(Array.from(chips.querySelectorAll("input[name='teacher_ids[]']")).map((input) => input.value));
        teachers.filter((teacher) => !selected.has(teacher.dataset.assignmentId) && teacher.dataset.assignmentLabel.toLowerCase().includes(query)).slice(0, 8).forEach((teacher) => {
            const option = document.createElement("div");
            option.className = "assignment-suggestion";
            option.textContent = teacher.dataset.assignmentLabel;
            option.addEventListener("mousedown", (event) => {
                event.preventDefault();
                const chip = document.createElement("span");
                chip.className = "assignment-chip";
                chip.textContent = teacher.dataset.assignmentLabel;
                const remove = document.createElement("button");
                remove.type = "button";
                remove.setAttribute("aria-label", "Retirer");
                remove.textContent = "×";
                remove.addEventListener("click", () => chip.remove());
                const input = document.createElement("input");
                input.type = "hidden";
                input.name = "teacher_ids[]";
                input.value = teacher.dataset.assignmentId;
                chip.append(remove, input);
                chips.appendChild(chip);
                search.value = "";
                suggestions.innerHTML = "";
            });
            suggestions.appendChild(option);
        });
    };
    chips.querySelectorAll(".assignment-chip button").forEach((button) => button.addEventListener("click", () => button.closest(".assignment-chip").remove()));
    search.addEventListener("input", render);
    search.addEventListener("blur", () => setTimeout(() => { suggestions.innerHTML = ""; }, 150));
})();
</script>
</body>
</html>
