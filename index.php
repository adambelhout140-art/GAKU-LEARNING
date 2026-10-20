<?php
require_once __DIR__ . '/config/i18n.php';// ============================================================
// * GAKU LEARNING - PUBLIC HOME PAGE
// ============================================================
// ! PUBLIC PAGE: A visitor does NOT need an account to access this page.
// ? Purpose: Serves as the main landing page and fetches published courses.
// ============================================================


// ------------------------------------------------------------
// * SESSION HANDLING
// ? Checks if the visitor is already logged in to customize navigation links.
// ------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


// ------------------------------------------------------------
// * DATABASE CONNECTION
// ! Imports $pdo object from database.php to communicate with MySQL.
// ------------------------------------------------------------
require_once "config/database.php";
require_once "config/homepage_videos.php";
require_once "config/homepage_content.php";
$platformBranding = platformBranding($pdo);
$homepageContent = homepageContentLoad($pdo);
$homeText = static function (string $group, string $key) use (&$homepageContent): string {
    global $platformBranding;
    $text = (string) ($homepageContent[$group][$key] ?? "");
    return htmlspecialchars(str_replace("GAKU LEARNING", $platformBranding["platform_name"], $text), ENT_QUOTES, "UTF-8");
};
$homeLink = static function (string $group, string $key, string $fallback) use (&$homepageContent): string {
    $link = (string) ($homepageContent[$group][$key] ?? $fallback);
    return htmlspecialchars(homepageContentLinkIsValid($link) ? $link : $fallback, ENT_QUOTES, "UTF-8");
};


// ------------------------------------------------------------
// * DATABASE QUERY: FETCH PUBLISHED COURSES
// ? Filters out draft and unpublished courses, returning only the 6 newest.
// ------------------------------------------------------------
$sql = "SELECT
            id,
            title,
            slug,
            short_description,
            cover_image,
            level,
            education_level,
            duration_hours,
            price,
            (SELECT GROUP_CONCAT(ca.name ORDER BY ca.name SEPARATOR ', ')
             FROM course_target_audiences cta
             INNER JOIN course_audiences ca ON ca.id = cta.audience_id
             WHERE cta.course_id = courses.id) AS target_audiences
        FROM courses
        WHERE status = 'published'
        ORDER BY published_at DESC
        LIMIT 6";


// ------------------------------------------------------------
// * SQL PREPARATION & EXECUTION
// ! Using prepare() prevents SQL injection attacks across the application.
// ------------------------------------------------------------
$stmt = $pdo->prepare($sql);
$stmt->execute();


// ------------------------------------------------------------
// * FETCH RESULTS
// ? Array stored in $courses for HTML rendering.
// ------------------------------------------------------------
$courses = $stmt->fetchAll();
$videoStatement = $pdo->query("SELECT title, description, video_url FROM homepage_videos WHERE is_active = 1 ORDER BY position, id");
$homepageVideos = $videoStatement->fetchAll(PDO::FETCH_ASSOC);

// * Convert database level values into readable labels for visitors.
$levelLabels = [
    "beginner" => $homepageContent["courses"]["level_beginner"],
    "intermediate" => $homepageContent["courses"]["level_intermediate"],
    "advanced" => $homepageContent["courses"]["level_advanced"]
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

$homepageSectionOrder = $homepageContent["order"];

?>

<!DOCTYPE html>

<html lang="fr">

<head>

    <!-- * Basic page metadata and responsive viewport settings -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">



    <title><?= $homeText("navigation", "page_title") ?></title>

    <!-- * Primary stylesheet import -->
    <link rel="stylesheet" href="assets/css/style.css?v=20261020-course-targets">

</head>
<body class="home-page">


<!-- ==========================================================
     * NAVIGATION BAR
     ========================================================== -->

<header class="navbar">

    <div class="container navbar-content">

        <!-- * Brand Logo -->
        <a href="index.php" class="logo">

            <img src="<?= htmlspecialchars(platformLogoUrl($pdo, "light-bg"), ENT_QUOTES, "UTF-8") ?>" alt="<?= htmlspecialchars($platformBranding["platform_name"], ENT_QUOTES, "UTF-8") ?>" class="logo-image" width="42" height="42">
            <?= $homeText("navigation", "brand_name") ?>
        </a>


        <!-- * Navigation Links -->
        <nav>

            <form class="course-nav-search" method="get" action="public/courses.php" role="search">
                <label class="visually-hidden" for="nav-course-search">Rechercher une formation</label>
                <input id="nav-course-search" type="search" name="search" placeholder="Rechercher...">
                <button type="submit" aria-label="Rechercher">
                    <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><circle cx="8.5" cy="8.5" r="5.5"></circle><path d="m13 13 4 4"></path></svg>
                </button>
            </form>

            <?php foreach (platformNavbarLinks($pdo) as $navLink): ?>
                <a href="<?= htmlspecialchars($navLink["href"], ENT_QUOTES, "UTF-8") ?>"<?= $navLink["external"] ? ' target="_blank" rel="noopener noreferrer"' : "" ?>><?= htmlspecialchars($navLink["label"], ENT_QUOTES, "UTF-8") ?></a>
            <?php endforeach; ?>

            <?php
            // --------------------------------------------------
            // ? DYNAMIC DASHBOARD ROUTING
            // * Checks active user session and routes to role-specific dashboard.
            // --------------------------------------------------
            if (isset($_SESSION["user_id"])):

            ?>

                <?php if ($_SESSION["user_role"] === "admin"): ?>

                    <a href="admin/dashboard.php">
                        <?= $homeText("navigation", "dashboard") ?>
                    </a>

                <?php elseif ($_SESSION["user_role"] === "teacher"): ?>

                    <a href="teacher/dashboard.php">
                        <?= $homeText("navigation", "dashboard") ?>
                    </a>

                <?php elseif ($_SESSION["user_role"] === "student"): ?>

                    <a href="student/dashboard.php">
                        <?= $homeText("navigation", "dashboard") ?>
                    </a>

                <?php elseif ($_SESSION["user_role"] === "company_manager"): ?>

                    <a href="company/dashboard.php">
                        <?= $homeText("navigation", "dashboard") ?>
                    </a>

                <?php elseif ($_SESSION["user_role"] === "parent"): ?>

                    <a href="parent/dashboard.php">
                        <?= $homeText("navigation", "dashboard") ?>
                    </a>

                <?php endif; ?>


                <!-- * Logout action -->
                <a href="auth/logout.php">
                    <?= $homeText("navigation", "logout") ?>
                </a>


            <?php else: ?>

                <a href="auth/request-status.php">
                    <?= $homeText("navigation", "request_status") ?>
                </a>

                <a href="auth/register.php">
                    <?= $homeText("navigation", "request_access") ?>
                </a>

                <a href="auth/login.php" class="nav-button">
                    <?= $homeText("navigation", "login") ?>
                </a>

            <?php endif; ?>

        </nav>

    </div>

</header>




<!-- ==========================================================
     * HERO SECTION
     ========================================================== -->

<main class="homepage-sections">
<?php if ($homepageContent["sections"]["hero"]): ?>
<section class="hero" style="order: <?= (int) $homepageSectionOrder["hero"] ?>">

    <div class="container hero-content">

        <div class="hero-text">

            <span class="hero-label"><?= $homeText("hero", "label") ?></span>

            <h1><?= nl2br($homeText("hero", "title")) ?></h1>

            <p><?= nl2br($homeText("hero", "description")) ?></p>


            <div class="hero-buttons">

                <a href="<?= $homeLink("hero", "primary_link", "#courses") ?>" class="button primary-button">
                    <?= $homeText("hero", "primary_text") ?>
                </a>

                <?php if (!isset($_SESSION["user_id"])): ?>

                    <a href="<?= $homeLink("hero", "secondary_link", "auth/login.php") ?>" class="button secondary-button">
                        <?= $homeText("hero", "secondary_text") ?>
                    </a>

                <?php endif; ?>

            </div>

        </div>

        <div class="hero-visual" aria-label="<?= $homeText("hero", "visual_label") ?>">
            <?php if (!empty($homepageContent["hero"]["image"])): ?>
                <img class="homepage-hero-image" src="<?= htmlspecialchars($homepageContent["hero"]["image"], ENT_QUOTES, "UTF-8") ?>" alt="<?= $homeText("hero", "visual_label") ?>">
            <?php else: ?>
            <div class="hero-panel">
                <span><?= $homeText("hero", "visual_label") ?></span>
                <strong><?= $homeText("hero", "visual_title") ?></strong>
                <p><?= $homeText("hero", "visual_description") ?></p>
            </div>
            <div class="hero-panel-row">
                <div class="hero-mini-card"><span><?= $homeText("hero", "mini_one_icon") ?></span><strong><?= $homeText("hero", "mini_one_title") ?></strong><small><?= $homeText("hero", "mini_one_text") ?></small></div>
                <div class="hero-mini-card"><span><?= $homeText("hero", "mini_two_icon") ?></span><strong><?= $homeText("hero", "mini_two_title") ?></strong><small><?= $homeText("hero", "mini_two_text") ?></small></div>
            </div>
            <?php endif; ?>
        </div>

    </div>

</section>
<?php endif; ?>

<?php if ($homepageContent["sections"]["stats"]): ?>
<section class="home-stats" style="order: <?= (int) $homepageSectionOrder["stats"] ?>">
    <div class="container home-stats-grid">
        <div class="home-stat"><strong><?= count($courses) ?><?= $homeText("stats", "courses_suffix") ?></strong><span><?= $homeText("stats", "courses_label") ?></span></div>
        <div class="home-stat"><strong><?= $homeText("stats", "value_two") ?></strong><span><?= $homeText("stats", "label_two") ?></span></div>
        <div class="home-stat"><strong><?= $homeText("stats", "value_three") ?></strong><span><?= $homeText("stats", "label_three") ?></span></div>
        <div class="home-stat"><strong><?= $homeText("stats", "value_four") ?></strong><span><?= $homeText("stats", "label_four") ?></span></div>
    </div>
</section>
<?php endif; ?>

<!-- ==========================================================
     * PLATFORM PRESENTATION VIDEO
     ========================================================== -->

<?php if ($homepageContent["sections"]["videos"] && $homepageVideos): ?>
<section class="video-section homepage-video-gallery" style="order: <?= (int) $homepageSectionOrder["videos"] ?>">
    <div class="container">

        <div class="section-header">
            <span><?= $homeText("videos", "eyebrow") ?></span>
            <h2><?= $homeText("videos", "title") ?></h2>
            <p><?= nl2br($homeText("videos", "description")) ?></p>
        </div>

        <div class="homepage-video-grid">
            <?php foreach ($homepageVideos as $homepageVideo): $embedUrl = homepageVideoEmbedUrl((string) $homepageVideo["video_url"]); if ($embedUrl === "") continue; ?>
                <article class="homepage-video-card">
                    <div class="video-wrapper">
                        <iframe
                            src="<?= htmlspecialchars($embedUrl, ENT_QUOTES, "UTF-8") ?>"
                            title="<?= htmlspecialchars($homepageVideo["title"], ENT_QUOTES, "UTF-8") ?>"
                            loading="lazy"
                            referrerpolicy="strict-origin-when-cross-origin"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                            allowfullscreen>
                        </iframe>
                    </div>
                    <div class="homepage-video-card-copy">
                        <h3><?= htmlspecialchars($homepageVideo["title"], ENT_QUOTES, "UTF-8") ?></h3>
                        <?php if (!empty($homepageVideo["description"])): ?><p><?= nl2br(htmlspecialchars($homepageVideo["description"], ENT_QUOTES, "UTF-8")) ?></p><?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

    </div>
</section>
<?php endif; ?>




<!-- ==========================================================
     * COURSES SECTION
     ========================================================== -->

<?php if ($homepageContent["sections"]["courses"]): ?>
<section class="courses-section" id="courses" style="order: <?= (int) $homepageSectionOrder["courses"] ?>">

    <div class="container">

        <div class="section-header">

            <span><?= $homeText("courses", "eyebrow") ?></span>

            <h2><?= $homeText("courses", "title") ?></h2>

            <p><?= nl2br($homeText("courses", "description")) ?></p>

        </div>


        <div class="courses-grid">


            <?php if (count($courses) > 0): ?>


                <?php foreach ($courses as $course): ?>

                    <!-- * Dynamic Course Card -->
                    <div class="course-card">


                        <!-- * Course Cover Image / Placeholder -->
                        <div class="course-image">

                            <?php if (!empty($course["cover_image"])): ?>

                                <img
                                    src="uploads/courses/<?= htmlspecialchars($course["cover_image"]) ?>"
                                    alt="<?= htmlspecialchars($course["title"]) ?>"
                                >

                            <?php else: ?>

                                <div class="course-placeholder">
                                    <?= $homeText("courses", "placeholder") ?>
                                </div>

                            <?php endif; ?>

                        </div>


                        <!-- * Course Details -->
                        <div class="course-content">

                            <span class="course-level">
                                <?= htmlspecialchars($levelLabels[$course["level"]] ?? $course["level"]) ?>
                            </span>
                            <?php if (!empty($course["education_level"]) && isset($educationLevelLabels[$course["education_level"]])): ?>
                                <span class="course-target-label"><?= htmlspecialchars($educationLevelLabels[$course["education_level"]]) ?></span>
                            <?php endif; ?>


                            <h3>
                                <?= htmlspecialchars($course["title"]) ?>
                            </h3>


                            <p>
                                <?= htmlspecialchars(
                                    $course["short_description"]
                                ) ?>
                            </p>
                            <?php if (!empty($course["target_audiences"])): ?>
                                <div class="course-target-labels">
                                    <?php foreach (explode(", ", $course["target_audiences"]) as $targetAudience): ?>
                                        <span><?= htmlspecialchars($targetAudience, ENT_QUOTES, "UTF-8") ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>


                            <div class="course-info">

                                <span>
                                    <?= $course["duration_hours"] !== null
                                        ? htmlspecialchars($course["duration_hours"]) . " hours"
                                        : $homepageContent["courses"]["duration_missing"] ?>
                                </span>

                                <span>
                                    <?= number_format((float) $course["price"], 2) ?>
                                    <?= $homeText("courses", "currency") ?>
                                </span>

                            </div>


                            <a
                                href="public/course.php?slug=<?= urlencode($course["slug"]) ?>"
                                class="course-button"
                            >
                                <?= $homeText("courses", "button") ?>
                            </a>

                        </div>

                    </div>

                <?php endforeach; ?>


            <?php else: ?>

                <!-- ! EMPTY STATE: Displayed if no published courses exist in MySQL -->
                <div class="no-courses">

                    <h3><?= $homeText("courses", "empty_title") ?></h3>

                    <p><?= nl2br($homeText("courses", "empty_description")) ?></p>

                </div>

            <?php endif; ?>


        </div>

        <div class="course-catalog-cta">
            <a href="public/courses.php" class="button secondary-button">Rechercher dans toutes les formations</a>
        </div>

    </div>

</section>
<?php endif; ?>

<?php if ($homepageContent["sections"]["how"]): ?>
<section class="how-section" style="order: <?= (int) $homepageSectionOrder["how"] ?>">
    <div class="container">
        <div class="section-header">
            <span><?= $homeText("how", "eyebrow") ?></span>
            <h2><?= $homeText("how", "title") ?></h2>
            <p><?= nl2br($homeText("how", "description")) ?></p>
        </div>
        <div class="steps-grid">
            <article class="step-card"><span class="step-number">01</span><h3><?= $homeText("how", "step_one_title") ?></h3><p><?= nl2br($homeText("how", "step_one_text")) ?></p></article>
            <article class="step-card"><span class="step-number">02</span><h3><?= $homeText("how", "step_two_title") ?></h3><p><?= nl2br($homeText("how", "step_two_text")) ?></p></article>
            <article class="step-card"><span class="step-number">03</span><h3><?= $homeText("how", "step_three_title") ?></h3><p><?= nl2br($homeText("how", "step_three_text")) ?></p></article>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($homepageContent["sections"]["cta"]): ?>
<section class="container final-cta" style="order: <?= (int) $homepageSectionOrder["cta"] ?>">
    <div>
        <h2><?= $homeText("cta", "title") ?></h2>
        <p><?= nl2br($homeText("cta", "description")) ?></p>
    </div>
    <a class="button" href="<?= isset($_SESSION["user_id"]) ? $homeLink("cta", "link_logged_in", "#courses") : $homeLink("cta", "link_logged_out", "auth/register.php") ?>">
        <?= isset($_SESSION["user_id"]) ? $homeText("cta", "button_logged_in") : $homeText("cta", "button_logged_out") ?>
    </a>
</section>
<?php endif; ?>



<!-- ==========================================================
     * ABOUT SECTION
     ========================================================== -->

<?php if ($homepageContent["sections"]["about"]): ?>
<section class="about-section" id="about" style="order: <?= (int) $homepageSectionOrder["about"] ?>">

    <div class="container">

        <div class="section-header">

            <span><?= $homeText("about", "eyebrow") ?></span>

            <h2><?= $homeText("about", "title") ?></h2>

        </div>

        <p class="about-intro"><?= nl2br($homeText("about", "intro")) ?></p>
        <?php if (!empty($homepageContent["about"]["image"])): ?><img class="homepage-about-image" src="<?= htmlspecialchars($homepageContent["about"]["image"], ENT_QUOTES, "UTF-8") ?>" alt="<?= $homeText("about", "title") ?>"><?php endif; ?>

        <div class="features-grid">


            <div class="feature-card">

                <div class="feature-icon"><?= $homeText("about", "feature_one_icon") ?></div>
                <h3><?= $homeText("about", "feature_one_title") ?></h3>
                <p><?= nl2br($homeText("about", "feature_one_text")) ?></p>

            </div>


            <div class="feature-card">

                <div class="feature-icon"><?= $homeText("about", "feature_two_icon") ?></div>
                <h3><?= $homeText("about", "feature_two_title") ?></h3>
                <p><?= nl2br($homeText("about", "feature_two_text")) ?></p>

            </div>


            <div class="feature-card">

                <div class="feature-icon"><?= $homeText("about", "feature_three_icon") ?></div>
                <h3><?= $homeText("about", "feature_three_title") ?></h3>
                <p><?= nl2br($homeText("about", "feature_three_text")) ?></p>

            </div>


            <div class="feature-card">

                <div class="feature-icon"><?= $homeText("about", "feature_four_icon") ?></div>
                <h3><?= $homeText("about", "feature_four_title") ?></h3>
                <p><?= nl2br($homeText("about", "feature_four_text")) ?></p>

            </div>

        </div>

        <div class="about-information">
            <article class="about-information-card">
                <h3><?= $homeText("about", "info_one_title") ?></h3>
                <p><?= nl2br($homeText("about", "info_one_text")) ?></p>
            </article>
            <article class="about-information-card">
                <h3><?= $homeText("about", "info_two_title") ?></h3>
                <p><?= nl2br($homeText("about", "info_two_text")) ?></p>
            </article>
            <article class="about-information-card">
                <h3><?= $homeText("about", "info_three_title") ?></h3>
                <ul class="about-list">
                    <li><?= $homeText("about", "info_three_item_one") ?></li>
                    <li><?= $homeText("about", "info_three_item_two") ?></li>
                    <li><?= $homeText("about", "info_three_item_three") ?></li>
                </ul>
            </article>
            <article class="about-information-card">
                <h3><?= $homeText("about", "info_four_title") ?></h3>
                <p><?= nl2br($homeText("about", "info_four_text")) ?></p>
            </article>
        </div>

    </div>

</section>
<?php endif; ?>

</main>



<!-- ==========================================================
     * FOOTER
     ========================================================== -->

<?php if ($homepageContent["sections"]["footer"]): ?><footer class="footer">

    <div class="container">

        <div class="footer-content">

            <div>

                <h3 class="footer-brand">
                    <img src="<?= htmlspecialchars(platformLogoUrl($pdo, "dark-bg"), ENT_QUOTES, "UTF-8") ?>" alt="<?= $homeText("navigation", "brand_name") ?>" class="brand-logo platform-brand-logo" width="34" height="34"> <?= $homeText("navigation", "brand_name") ?>
                </h3>

                <p><?= nl2br($homeText("footer", "description")) ?></p>

            </div>


            <div>

                <h4><?= $homeText("footer", "links_title") ?></h4>

                <a href="index.php"><?= $homeText("footer", "home_label") ?></a>

                <a href="#courses"><?= $homeText("footer", "courses_label") ?></a>

                <a href="#about"><?= $homeText("footer", "about_label") ?></a>

            </div>


            <div>

                <h4><?= $homeText("footer", "account_title") ?></h4>

                <?php if (isset($_SESSION["user_id"])): ?>

                    <a href="auth/logout.php"><?= $homeText("footer", "logout_label") ?></a>

                <?php else: ?>

                    <a href="auth/login.php"><?= $homeText("footer", "login_label") ?></a>

                <?php endif; ?>

            </div>

            <div>
                <h4>Contact</h4>
                <?= platformContactMarkup($pdo, "platform-contact") ?>
            </div>

        </div>


        <div class="footer-bottom">

            <p>
                © <?= date("Y") ?> <?= $homeText("navigation", "brand_name") ?>.
                <?= $homeText("footer", "copyright") ?>
            </p>

        </div>

    </div>

</footer>
<?php endif; ?>


</body>

</html>
