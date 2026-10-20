<?php
require_once __DIR__ . "/../config/i18n.php";
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . "/../config/database.php";

$search = trim((string) ($_GET["search"] ?? ""));
$level = (string) ($_GET["level"] ?? "");
$format = (string) ($_GET["format"] ?? "");
$category = trim((string) ($_GET["category"] ?? ""));
$educationLevel = (string) ($_GET["education_level"] ?? "");
$audienceId = filter_input(INPUT_GET, "audience_id", FILTER_VALIDATE_INT);
$sort = (string) ($_GET["sort"] ?? "recent");
$rangeInputs = [
    "min_price" => "Prix minimum",
    "max_price" => "Prix maximum",
    "min_duration" => "Durée minimum",
    "max_duration" => "Durée maximum",
];
$ranges = [];
$filterErrors = [];
foreach ($rangeInputs as $key => $label) {
    $rawValue = trim((string) ($_GET[$key] ?? ""));
    if ($rawValue === "") {
        $ranges[$key] = null;
    } elseif (!is_numeric($rawValue) || (float) $rawValue < 0) {
        $ranges[$key] = null;
        $filterErrors[] = $label . " : saisissez un nombre égal ou supérieur à zéro.";
    } else {
        $ranges[$key] = (float) $rawValue;
    }
}
foreach (["price", "duration"] as $rangeName) {
    $minimum = $ranges["min_" . $rangeName];
    $maximum = $ranges["max_" . $rangeName];
    if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
        $filterErrors[] = "La valeur minimum ne peut pas dépasser la valeur maximum (" . $rangeName . ").";
    }
}

$levelLabels = [
    "beginner" => "Débutant",
    "intermediate" => "Intermédiaire",
    "advanced" => "Avancé",
];
$formatLabels = [
    "online" => "En ligne",
    "hybrid" => "Hybride",
    "in_person" => "Présentiel",
];
$educationLevelLabels = [
    "preschool" => "Niveau 0 — Éducation préscolaire",
    "primary" => "Niveau 1 — Enseignement primaire",
    "middle_school" => "Niveau 2 — Premier cycle du secondaire",
    "high_school" => "Niveau 3 — Deuxième cycle du secondaire",
    "post_secondary_non_tertiary" => "Niveau 4 — Post-secondaire non supérieur",
    "higher_education" => "Niveaux 5 à 8 — Enseignement supérieur",
    "vocational_training" => "Formation professionnelle",
    "other" => "Autre",
];
$sortOptions = [
    "recent" => "Plus récentes",
    "title" => "Nom (A à Z)",
    "price_low" => "Prix croissant",
    "price_high" => "Prix décroissant",
];

if (!isset($levelLabels[$level])) {
    $level = "";
}
if (!isset($formatLabels[$format])) {
    $format = "";
}
if (!isset($educationLevelLabels[$educationLevel])) {
    $educationLevel = "";
}
if (!isset($sortOptions[$sort])) {
    $sort = "recent";
}

$categoryStatement = $pdo->query(
    "SELECT DISTINCT category FROM courses
     WHERE status = 'published' AND category IS NOT NULL AND TRIM(category) <> ''
     ORDER BY category"
);
$categories = $categoryStatement->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($category, $categories, true)) {
    $category = "";
}
$audiences = $pdo->query("SELECT id, name FROM course_audiences ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$audienceIds = array_map("intval", array_column($audiences, "id"));
if ($audienceId === false || !in_array((int) $audienceId, $audienceIds, true)) {
    $audienceId = null;
}

$where = ["courses.status = 'published'"];
$params = [];
if ($search !== "") {
    $escapedSearch = str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $search);
    $where[] = "(courses.title LIKE ? OR courses.short_description LIKE ? OR courses.category LIKE ?)";
    $term = "%" . $escapedSearch . "%";
    array_push($params, $term, $term, $term);
}
if ($level !== "") {
    $where[] = "courses.level = ?";
    $params[] = $level;
}
if ($format !== "") {
    $where[] = "courses.format = ?";
    $params[] = $format;
}
if ($category !== "") {
    $where[] = "courses.category = ?";
    $params[] = $category;
}
if ($educationLevel !== "") {
    $where[] = "courses.education_level = ?";
    $params[] = $educationLevel;
}
if ($audienceId !== null) {
    $where[] = "EXISTS (
        SELECT 1 FROM course_target_audiences cta
        WHERE cta.course_id = courses.id AND cta.audience_id = ?
    )";
    $params[] = (int) $audienceId;
}
if ($ranges["min_price"] !== null) {
    $where[] = "courses.price >= ?";
    $params[] = $ranges["min_price"];
}
if ($ranges["max_price"] !== null) {
    $where[] = "courses.price <= ?";
    $params[] = $ranges["max_price"];
}
if ($ranges["min_duration"] !== null) {
    $where[] = "courses.duration_hours >= ?";
    $params[] = $ranges["min_duration"];
}
if ($ranges["max_duration"] !== null) {
    $where[] = "courses.duration_hours <= ?";
    $params[] = $ranges["max_duration"];
}
if ($filterErrors) {
    $where[] = "1 = 0";
}

$orderBy = [
    "recent" => "courses.published_at DESC, courses.id DESC",
    "title" => "courses.title ASC",
    "price_low" => "courses.price ASC, courses.title ASC",
    "price_high" => "courses.price DESC, courses.title ASC",
][$sort];
$statement = $pdo->prepare(
    "SELECT courses.id, courses.title, courses.slug, courses.short_description,
            courses.cover_image, courses.level, courses.format,
            courses.duration_hours, courses.price, courses.category, courses.education_level,
            (SELECT GROUP_CONCAT(ca.name ORDER BY ca.name SEPARATOR ', ')
             FROM course_target_audiences cta
             INNER JOIN course_audiences ca ON ca.id = cta.audience_id
             WHERE cta.course_id = courses.id) AS target_audiences
     FROM courses
     WHERE " . implode(" AND ", $where) . "
     ORDER BY " . $orderBy
);
$statement->execute($params);
$courses = $statement->fetchAll(PDO::FETCH_ASSOC);
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catalogue des formations - <?= $escape(platformBranding($pdo)["platform_name"]) ?></title>
    <link rel="icon" type="image/png" href="<?= $escape(platformLogoUrl($pdo)) ?>">
    <link rel="stylesheet" href="../assets/css/style.css?v=20261020-course-catalog-cite">
</head>
<body class="course-catalog-page">
<header class="navbar">
    <div class="container navbar-content">
        <a href="../index.php" class="logo"><img src="<?= $escape(platformLogoUrl($pdo, "light-bg")) ?>" alt="<?= $escape(platformBranding($pdo)["platform_name"]) ?>" class="brand-logo" width="42" height="42"> <?= $escape(platformBranding($pdo)["platform_name"]) ?></a>
        <nav>
            <form class="course-nav-search" method="get" action="courses.php" role="search">
                <label class="visually-hidden" for="nav-course-search">Rechercher une formation</label>
                <input id="nav-course-search" type="search" name="search" placeholder="Rechercher...">
                <button type="submit" aria-label="Rechercher">
                    <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><circle cx="8.5" cy="8.5" r="5.5"></circle><path d="m13 13 4 4"></path></svg>
                </button>
            </form>
            <?php foreach (platformNavbarLinks($pdo) as $navLink): ?>
                <a href="<?= $escape($navLink["href"]) ?>"<?= $navLink["external"] ? ' target="_blank" rel="noopener noreferrer"' : "" ?>><?= $escape($navLink["label"]) ?></a>
            <?php endforeach; ?>
            <?php if (isset($_SESSION["user_id"])): ?>
                <a href="../<?= $_SESSION["user_role"] === "admin" ? "admin/dashboard.php" : ($_SESSION["user_role"] === "teacher" ? "teacher/dashboard.php" : ($_SESSION["user_role"] === "company_manager" ? "company/dashboard.php" : ($_SESSION["user_role"] === "parent" ? "parent/dashboard.php" : "student/dashboard.php"))) ?>">Tableau de bord</a>
            <?php else: ?>
                <a href="../auth/login.php" class="nav-button">Connexion</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="course-catalog container">
    <header class="course-catalog-heading">
        <p class="student-eyebrow">Catalogue</p>
        <h1>Trouvez votre formation</h1>
        <p>Filtrez par public cible, niveau d’enseignement, format ou catégorie pour trouver la formation adaptée.</p>
    </header>
    <form method="get" action="courses.php" class="course-catalog-filters">
        <div class="course-catalog-filter-grid">
            <label for="course-search">Recherche
                <input id="course-search" type="search" name="search" value="<?= $escape($search) ?>" placeholder="Titre, catégorie ou mot-clé">
            </label>
            <label for="course-level">Niveau
                <select id="course-level" name="level">
                    <option value="">Tous les niveaux</option>
                    <?php foreach ($levelLabels as $value => $label): ?>
                        <option value="<?= $escape($value) ?>" <?= $level === $value ? "selected" : "" ?>><?= $escape($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="course-format">Format
                <select id="course-format" name="format">
                    <option value="">Tous les formats</option>
                    <?php foreach ($formatLabels as $value => $label): ?>
                        <option value="<?= $escape($value) ?>" <?= $format === $value ? "selected" : "" ?>><?= $escape($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="course-education-level">Niveau d’enseignement
                <select id="course-education-level" name="education_level">
                    <option value="">Tous les niveaux d’enseignement</option>
                    <?php foreach ($educationLevelLabels as $value => $label): ?>
                        <option value="<?= $escape($value) ?>" <?= $educationLevel === $value ? "selected" : "" ?>><?= $escape($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="course-audience">Public cible
                <select id="course-audience" name="audience_id">
                    <option value="">Tous les publics</option>
                    <?php foreach ($audiences as $audience): ?>
                        <option value="<?= (int) $audience["id"] ?>" <?= $audienceId === (int) $audience["id"] ? "selected" : "" ?>><?= $escape($audience["name"]) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="course-category">Catégorie
                <select id="course-category" name="category">
                    <option value="">Toutes les catégories</option>
                    <?php foreach ($categories as $categoryOption): ?>
                        <option value="<?= $escape($categoryOption) ?>" <?= $category === $categoryOption ? "selected" : "" ?>><?= $escape($categoryOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="course-sort">Trier par
                <select id="course-sort" name="sort">
                    <?php foreach ($sortOptions as $value => $label): ?>
                        <option value="<?= $escape($value) ?>" <?= $sort === $value ? "selected" : "" ?>><?= $escape($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="course-catalog-range-grid">
            <fieldset>
                <legend>Prix (TND)</legend>
                <label for="min-price">Minimum
                    <input id="min-price" type="number" name="min_price" min="0" step="0.01" value="<?= $escape($_GET["min_price"] ?? "") ?>" placeholder="Sans minimum">
                </label>
                <label for="max-price">Maximum
                    <input id="max-price" type="number" name="max_price" min="0" step="0.01" value="<?= $escape($_GET["max_price"] ?? "") ?>" placeholder="Sans maximum">
                </label>
            </fieldset>
            <fieldset>
                <legend>Durée (heures)</legend>
                <label for="min-duration">Minimum
                    <input id="min-duration" type="number" name="min_duration" min="0" step="0.25" value="<?= $escape($_GET["min_duration"] ?? "") ?>" placeholder="Sans minimum">
                </label>
                <label for="max-duration">Maximum
                    <input id="max-duration" type="number" name="max_duration" min="0" step="0.25" value="<?= $escape($_GET["max_duration"] ?? "") ?>" placeholder="Sans maximum">
                </label>
            </fieldset>
        </div>
        <div class="course-catalog-filter-actions">
            <button class="button primary-button" type="submit">Appliquer les filtres</button>
            <a class="button secondary-button" href="courses.php">Effacer les filtres</a>
        </div>
    </form>
    <div class="course-catalog-results">
        <?php if ($filterErrors): ?><div class="alert alert-danger" role="alert"><?= $escape(implode(" ", $filterErrors)) ?></div><?php endif; ?>
        <div class="course-catalog-results-heading">
            <div>
                <span class="course-catalog-section-label">À DÉCOUVRIR</span>
                <h2>Formations disponibles</h2>
            </div>
            <span class="course-catalog-count"><?= count($courses) ?> formation<?= count($courses) === 1 ? "" : "s" ?></span>
        </div>
        <?php if (!$courses): ?>
            <div class="no-courses"><h2>Aucune formation trouvée</h2><p>Essayez de modifier vos filtres ou votre recherche.</p></div>
        <?php else: ?>
            <div class="courses-grid">
                <?php foreach ($courses as $course): ?>
                    <article class="course-card">
                        <div class="course-image">
                            <?php if (!empty($course["cover_image"])): ?>
                                <img src="../uploads/courses/<?= $escape($course["cover_image"]) ?>" alt="<?= $escape($course["title"]) ?>" loading="lazy">
                            <?php else: ?>
                                <div class="course-placeholder">Formation professionnelle</div>
                            <?php endif; ?>
                        </div>
                        <div class="course-content">
                            <span class="course-level"><?= $escape($levelLabels[$course["level"]] ?? $course["level"]) ?></span>
                            <h2><?= $escape($course["title"]) ?></h2>
                            <p><?= $escape($course["short_description"]) ?></p>
                            <div class="course-info">
                                <span><?= $escape($formatLabels[$course["format"]] ?? $course["format"]) ?></span>
                                <span><?= $course["duration_hours"] !== null ? $escape($course["duration_hours"]) . " h" : "Durée flexible" ?></span>
                            </div>
                            <div class="course-info">
                                <span><?= $escape($course["category"] ?: "Formation") ?></span>
                                <span><?= number_format((float) $course["price"], 2, ",", " ") ?> TND</span>
                            </div>
                            <?php if ($course["education_level"] && isset($educationLevelLabels[$course["education_level"]])): ?>
                                <div class="course-target-label"><?= $escape($educationLevelLabels[$course["education_level"]]) ?></div>
                            <?php endif; ?>
                            <?php if ($course["target_audiences"]): ?>
                                <div class="course-target-labels" aria-label="Publics cibles">
                                    <?php foreach (explode(", ", $course["target_audiences"]) as $targetAudience): ?>
                                        <span><?= $escape($targetAudience) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <a href="course.php?slug=<?= urlencode($course["slug"]) ?>" class="course-button">Voir la formation</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
<?= platformContactMarkup($pdo, "platform-contact platform-contact-app") ?>
</body>
</html>