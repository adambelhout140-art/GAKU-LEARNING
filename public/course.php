<?php
require_once __DIR__ . '/../config/i18n.php';// ! PUBLIC COURSE PAGE
// * Shows published course information and its public module/lesson outline.
// ? Unpublished and draft courses are never exposed through this route.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (($_SESSION["user_role"] ?? "") === "student") {
    header("Location: ../student/dashboard.php");
    exit;
}
require_once "../config/database.php";

// ! Visitor contact forms use a session token to prevent forged requests.
if (empty($_SESSION["contact_csrf_token"])) {
    $_SESSION["contact_csrf_token"] = bin2hex(random_bytes(32));
}

$slug = trim($_GET["slug"] ?? "");
if ($slug === "") {
    header("Location: ../index.php#courses");
    exit;
}

// * The slug is used as a prepared parameter so the public URL cannot alter the SQL query.
$courseStatement = $pdo->prepare(
    "SELECT courses.*,
            COALESCE(
                (
                    SELECT GROUP_CONCAT(
                        CONCAT(assigned.first_name, ' ', assigned.last_name)
                        ORDER BY assigned.last_name, assigned.first_name
                        SEPARATOR ', '
                    )
                    FROM course_teachers assigned_link
                    INNER JOIN users assigned ON assigned.id = assigned_link.teacher_id
                    WHERE assigned_link.course_id = courses.id
                ),
                CONCAT(users.first_name, ' ', users.last_name)
            ) AS teacher_names
     FROM courses
     LEFT JOIN users ON users.id = courses.teacher_id
     WHERE courses.slug = ? AND courses.status = 'published'
     LIMIT 1"
);
$courseStatement->execute([$slug]);
$course = $courseStatement->fetch();
$contactError = "";
$contactSuccess = false;
$modules = [];
$lessonsByModule = [];

if (!$course) {
    http_response_code(404);
    $pageTitle = "Formation introuvable";
} else {
    $pageTitle = $course["title"];
    // ! CONTACT REQUEST
    // * Visitors can ask the administrator for information about this specific course.
    if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "contact_request") {
        $firstName = trim($_POST["first_name"] ?? "");
        $lastName = trim($_POST["last_name"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $phone = trim($_POST["phone"] ?? "");
        $companyName = trim($_POST["company_name"] ?? "");
        $message = trim($_POST["message"] ?? "");

        if (!hash_equals($_SESSION["contact_csrf_token"], $_POST["csrf_token"] ?? "")) {
            $contactError = "La session du formulaire a expiré. Veuillez réessayer.";
        } elseif ($firstName === "" || $email === "" || $message === "") {
            $contactError = "Veuillez remplir votre prénom, votre email et votre message.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $contactError = "Veuillez entrer une adresse email valide.";
        } else {
            $contactStatement = $pdo->prepare("INSERT INTO contact_requests (first_name, last_name, email, phone, company_name, request_type, course_id, subject, message) VALUES (?, ?, ?, ?, ?, 'quote', ?, ?, ?)");
            $contactStatement->execute([$firstName, $lastName ?: null, $email, $phone ?: null, $companyName ?: null, $course["id"], "Demande d'information : " . $course["title"], $message]);

            $contactSuccess = true;
        }
    }

    // * Only published lessons are included in the public outline.
    $moduleStatement = $pdo->prepare("SELECT modules.id, modules.title, modules.description, modules.position FROM modules WHERE modules.course_id = ? ORDER BY modules.position ASC");
    $moduleStatement->execute([$course["id"]]);
    $modules = $moduleStatement->fetchAll();
    $lessonStatement = $pdo->prepare("SELECT id, module_id, title, description, estimated_minutes FROM lessons WHERE is_published = 1 AND module_id IN (SELECT id FROM modules WHERE course_id = ?) ORDER BY position ASC");
    $lessonStatement->execute([$course["id"]]);
    foreach ($lessonStatement->fetchAll() as $lesson) {
        $lessonsByModule[$lesson["module_id"]][] = $lesson;
    }
}

$levelLabels = ["beginner" => "Débutant", "intermediate" => "Intermédiaire", "advanced" => "Avancé"];
$formatLabels = ["online" => "En ligne", "hybrid" => "Hybride", "in_person" => "Présentiel"];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></title>
    <meta name="description" content="<?= htmlspecialchars($course ? ($course["short_description"] ?: "Découvrez cette formation professionnelle.") : "Formation introuvable") ?>">
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
    <link rel="stylesheet" href="../assets/css/style.css?v=20261019-navbar">
    <style>
        .public-course-page { background: #f7f9fc; }
        .public-course-page .navbar { background: rgba(255,255,255,.94); backdrop-filter: blur(12px); }
        .public-course-page .course-detail-hero { position: relative; overflow: hidden; padding: 96px 0 84px; background: linear-gradient(135deg, #102a43 0%, #1f4e79 58%, #2f80a8 100%); }
        .public-course-page .course-detail-hero::after { content: ""; position: absolute; width: 430px; height: 430px; right: -150px; top: -190px; border-radius: 50%; background: rgba(255,255,255,.08); }
        .public-course-page .course-detail-grid { position: relative; z-index: 1; }
        .public-course-page .course-detail-copy h1 { color: #fff; }
        .public-course-page .course-detail-copy > p { color: #dbeafe; }
        .public-course-page .course-detail-copy .hero-label { color: #a9e5ff; }
        .public-course-page .course-detail-meta span { border-color: rgba(255,255,255,.25); background: rgba(255,255,255,.1); color: #fff; }
        .public-course-page .course-detail-meta-note { margin-top: 18px; color: #b9d8ec; font-size: 13px; }
        .public-course-page .course-detail-cover { border: 8px solid rgba(255,255,255,.2); box-shadow: 0 24px 60px rgba(0,0,0,.22); }
        .public-course-page .course-detail-content { padding-top: 76px; }
        .public-course-page .course-copy-section { padding: 28px; border: 1px solid #e3ebf2; border-radius: 16px; background: #fff; box-shadow: 0 10px 28px rgba(16,42,67,.05); }
        .public-course-page .course-copy-section h2 { font-size: 25px; }
        .public-course-page .course-enrollment-panel { border-radius: 18px; box-shadow: 0 16px 36px rgba(16,42,67,.1); }
        .public-course-page .course-price { color: #1261a0; font-size: 30px; }
        .public-course-page .course-teacher { padding-top: 18px; border-top: 1px solid #e3ebf2; }
        .public-course-page .course-contact { margin-top: 24px; padding-top: 24px; border-top: 1px solid #e3ebf2; }
        .public-course-page .public-module { margin-bottom: 14px; padding: 18px; border: 1px solid #e3ebf2; border-radius: 12px; background: #f8fbfd; }
        .public-course-page .public-module h3 { margin-top: 0; }
        .public-course-page .course-not-found { margin: 80px auto; padding: 70px 30px; text-align: center; background: #fff; border-radius: 18px; }
        @media (max-width: 760px) {
            .public-course-page .course-detail-hero { padding: 68px 0 58px; }
            .public-course-page .course-detail-layout { gap: 24px; }
            .public-course-page .course-enrollment-panel { position: static; }
        }
    </style>
</head>
<body class="public-course-page">
<header class="navbar">
    <div class="container navbar-content">
        <a href="../index.php" class="logo"><img src="<?= htmlspecialchars(platformLogoUrl($pdo, "light-bg"), ENT_QUOTES, "UTF-8") ?>" alt="<?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?>" class="brand-logo" width="42" height="42"> <?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></a>
        <nav>
            <form class="course-nav-search" method="get" action="courses.php" role="search">
                <label class="visually-hidden" for="nav-course-search">Rechercher une formation</label>
                <input id="nav-course-search" type="search" name="search" placeholder="Rechercher...">
                <button type="submit" aria-label="Rechercher">
                    <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><circle cx="8.5" cy="8.5" r="5.5"></circle><path d="m13 13 4 4"></path></svg>
                </button>
            </form>
            <?php foreach (platformNavbarLinks($pdo) as $navLink): ?>
                <a href="<?= htmlspecialchars($navLink["href"], ENT_QUOTES, "UTF-8") ?>"<?= $navLink["external"] ? ' target="_blank" rel="noopener noreferrer"' : "" ?>><?= htmlspecialchars($navLink["label"], ENT_QUOTES, "UTF-8") ?></a>
            <?php endforeach; ?>
            <?php if (isset($_SESSION["user_id"])): ?>
                <a href="../<?= $_SESSION["user_role"] === "admin" ? "admin/dashboard.php" : ($_SESSION["user_role"] === "teacher" ? "teacher/dashboard.php" : ($_SESSION["user_role"] === "company_manager" ? "company/dashboard.php" : "student/dashboard.php")) ?>">Tableau de bord</a>
            <?php else: ?>
                <a href="../auth/request-status.php">Vérifier le statut</a>
                <?php if ($course): ?><a href="../auth/register.php?course_id=<?= (int) $course["id"] ?>">Demander un accès</a><?php endif; ?>
                <a href="../auth/login.php" class="nav-button">Connexion</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="public-course-page">
<?php if (!$course): ?>
    <section class="course-not-found container"><h1>Formation introuvable</h1><p>Cette formation n'est pas disponible ou n'est plus publiée.</p><a href="../index.php#courses" class="button primary-button">Retour aux formations</a></section>
<?php else: ?>
    <section class="course-detail-hero">
        <div class="container course-detail-grid">
            <div class="course-detail-copy">
                <span class="hero-label"><?= htmlspecialchars($course["category"] ?: "Formation professionnelle") ?></span>
                <h1><?= htmlspecialchars($course["title"]) ?></h1>
                <p><?= nl2br(htmlspecialchars($course["short_description"] ?: "Découvrez cette formation professionnelle.")) ?></p>
                <div class="course-detail-meta"><span><?= htmlspecialchars($levelLabels[$course["level"]] ?? $course["level"]) ?></span><span><?= $course["duration_hours"] !== null ? htmlspecialchars($course["duration_hours"]) . " heures" : "Durée flexible" ?></span><span><?= htmlspecialchars($formatLabels[$course["format"]] ?? $course["format"]) ?></span></div>
                <p class="course-detail-meta-note">Formation professionnelle · Accès en ligne · Suivi de progression inclus</p>
            </div>
            <?php if ($course["cover_image"]): ?><img class="course-detail-cover" src="../uploads/courses/<?= htmlspecialchars($course["cover_image"]) ?>" alt="<?= htmlspecialchars($course["title"]) ?>"><?php endif; ?>
        </div>
    </section>
    <section class="courses-section course-detail-content"><div class="container course-detail-layout"><div>
        <article class="course-copy-section"><h2>À propos de cette formation</h2><p><?= nl2br(htmlspecialchars($course["description"] ?: "Description à venir.")) ?></p></article>
        <article class="course-copy-section"><h2>Objectifs</h2><p><?= nl2br(htmlspecialchars($course["objectives"] ?: "Objectifs à venir.")) ?></p></article>
        <article class="course-copy-section"><h2>Prérequis</h2><p><?= nl2br(htmlspecialchars($course["prerequisites"] ?: "Aucun prérequis indiqué.")) ?></p></article>
        <article class="course-copy-section"><h2>Programme</h2><?php if ($modules): ?><div class="public-modules"><?php foreach ($modules as $module): ?><div class="public-module"><h3><?= htmlspecialchars($module["title"]) ?></h3><?php if ($module["description"]): ?><p><?= htmlspecialchars($module["description"]) ?></p><?php endif; ?><?php if (!empty($lessonsByModule[$module["id"]])): ?><ul><?php foreach ($lessonsByModule[$module["id"]] as $lesson): ?><li><?= htmlspecialchars($lesson["title"]) ?><?php if ($lesson["estimated_minutes"]): ?><small><?= (int) $lesson["estimated_minutes"] ?> min</small><?php endif; ?></li><?php endforeach; ?></ul><?php else: ?><p>Aucune leçon publiée pour le moment.</p><?php endif; ?></div><?php endforeach; ?></div><?php else: ?><p>Le programme sera bientôt disponible.</p><?php endif; ?></article>
    </div><aside class="course-enrollment-panel"><h2>Commencer la formation</h2><strong class="course-price"><?= number_format((float) $course["price"], 2, ",", " ") ?> TND</strong><p>Accédez aux leçons et aux évaluations depuis votre espace personnel.</p><?php if (isset($_SESSION["user_id"])): ?><a href="../<?= $_SESSION["user_role"] === "student" ? "student/dashboard.php" : "index.php" ?>" class="button primary-button">Accéder à mon espace</a>        <?php else: ?><a href="../auth/login.php" class="button primary-button">Se connecter pour accéder</a><a href="../auth/register.php?course_id=<?= (int) $course["id"] ?>" class="button secondary-button">Demander un accès</a><?php endif; ?><p class="course-teacher">Formateur(s) : <strong><?= htmlspecialchars(trim($course["teacher_names"] ?? "") ?: "Équipe " . platformBranding($pdo)["platform_name"]) ?></strong></p><div class="course-contact"><h2>Besoin d'informations ?</h2><?php if ($contactSuccess): ?><div class="alert alert-success">Votre demande a bien été envoyée à l'administrateur.</div><?php elseif ($contactError !== ""): ?><div class="alert alert-danger"><?= htmlspecialchars($contactError) ?></div><?php endif; ?><form method="POST" class="course-contact-form"><input type="hidden" name="action" value="contact_request"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["contact_csrf_token"]) ?>"><label for="first_name">Prénom *</label><input type="text" id="first_name" name="first_name" required><label for="last_name">Nom</label><input type="text" id="last_name" name="last_name"><label for="email">Email *</label><input type="email" id="email" name="email" required><label for="phone">Téléphone</label><input type="tel" id="phone" name="phone"><label for="company_name">Entreprise</label><input type="text" id="company_name" name="company_name"><label for="message">Message *</label><textarea id="message" name="message" rows="4" required></textarea><button type="submit" class="button primary-button">Envoyer la demande</button></form></div></aside></div></section>
<?php endif; ?>
</main>
<?= platformContactMarkup($pdo, "platform-contact platform-contact-app") ?>
</body>
</html>
