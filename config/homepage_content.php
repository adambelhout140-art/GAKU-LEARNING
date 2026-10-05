<?php
function homepageContentDefaults(): array
{
    return [
        "sections" => ["hero" => 1, "stats" => 1, "videos" => 1, "courses" => 1, "how" => 1, "cta" => 1, "about" => 1, "footer" => 1],
        "order" => ["hero" => 1, "stats" => 2, "videos" => 3, "courses" => 4, "how" => 5, "cta" => 6, "about" => 7],
        "hero" => [
            "label" => "ONLINE PROFESSIONAL TRAINING",
            "title" => "Learn today.\nLead tomorrow.",
            "description" => "Develop practical skills through structured courses, interactive lessons, secure assessments and recognized certificates.",
            "primary_text" => "Explore Courses",
            "primary_link" => "#courses",
            "secondary_text" => "Login",
            "secondary_link" => "auth/login.php",
            "visual_label" => "Votre parcours de formation",
            "visual_title" => "Progression à votre rythme",
            "visual_description" => "Un espace unique pour apprendre, pratiquer et mesurer vos résultats.",
            "mini_one_icon" => "📚",
            "mini_one_title" => "Formations structurées",
            "mini_one_text" => "Leçons et modules",
            "mini_two_icon" => "🏆",
            "mini_two_title" => "Certificats",
            "mini_two_text" => "Valorisez vos compétences",
            "image" => "",
        ],
        "stats" => [
            "courses_suffix" => "+",
            "courses_label" => "formations disponibles",
            "value_two" => "24/7",
            "label_two" => "accès à votre espace",
            "value_three" => "100%",
            "label_three" => "suivi en ligne",
            "value_four" => "∞",
            "label_four" => "progression à votre rythme",
        ],
        "videos" => [
            "eyebrow" => "Découvrez GAKU LEARNING",
            "title" => "Nos vidéos",
            "description" => "Découvrez la plateforme, nos formations et nos conseils.",
        ],
        "courses" => [
            "eyebrow" => "OUR TRAINING",
            "title" => "Popular Courses",
            "description" => "Develop your professional skills with our online training programs.",
            "empty_title" => "No courses available yet",
            "empty_description" => "Our training catalog is currently being prepared.",
            "placeholder" => "Course",
            "button" => "View Course",
            "duration_missing" => "Duration not specified",
            "currency" => "TND",
            "level_beginner" => "Beginner",
            "level_intermediate" => "Intermediate",
            "level_advanced" => "Advanced",
        ],
        "how" => [
            "eyebrow" => "COMMENT ÇA MARCHE",
            "title" => "Un parcours simple et efficace",
            "description" => "Commencez votre formation en quelques étapes et gardez une vision claire de vos progrès.",
            "step_one_title" => "Choisissez une formation",
            "step_one_text" => "Explorez notre catalogue et trouvez le programme adapté à vos objectifs.",
            "step_two_title" => "Apprenez et pratiquez",
            "step_two_text" => "Suivez les leçons, révisez les modules et testez vos connaissances.",
            "step_three_title" => "Validez vos compétences",
            "step_three_text" => "Terminez les évaluations et obtenez votre certificat de réussite.",
        ],
        "cta" => [
            "title" => "Prêt à développer vos compétences ?",
            "description" => "Rejoignez GAKU LEARNING et construisez votre prochaine réussite.",
            "button_logged_in" => "Voir les formations",
            "button_logged_out" => "Demander un accès",
            "link_logged_in" => "#courses",
            "link_logged_out" => "auth/register.php",
        ],
        "about" => [
            "eyebrow" => "À PROPOS DE GAKU LEARNING",
            "title" => "Une formation pensée pour votre réussite",
            "intro" => "GAKU LEARNING est une plateforme de formation professionnelle en ligne qui accompagne les étudiants, les collaborateurs et les entreprises dans le développement de leurs compétences.",
            "image" => "",
            "feature_one_icon" => "📚",
            "feature_one_title" => "Formations professionnelles",
            "feature_one_text" => "Apprenez avec des formations structurées, des modules clairs et des leçons créées par des formateurs.",
            "feature_two_icon" => "📝",
            "feature_two_title" => "Quiz et examens",
            "feature_two_text" => "Vérifiez vos connaissances grâce à des évaluations interactives et des examens finaux.",
            "feature_three_icon" => "📊",
            "feature_three_title" => "Suivi de progression",
            "feature_three_text" => "Consultez votre progression, vos résultats et vos activités depuis votre espace personnel.",
            "feature_four_icon" => "🏆",
            "feature_four_title" => "Certificats",
            "feature_four_text" => "Terminez votre parcours avec succès et obtenez un certificat pour valoriser vos nouvelles compétences.",
            "info_one_title" => "Pour les apprenants",
            "info_one_text" => "Progressez à votre rythme, retrouvez vos cours depuis votre tableau de bord et construisez un parcours d'apprentissage personnalisé.",
            "info_two_title" => "Pour les entreprises",
            "info_two_text" => "Suivez les collaborateurs rattachés à votre entreprise et obtenez une vision claire de leur participation et de leur progression.",
            "info_three_title" => "Une expérience complète",
            "info_three_item_one" => "Contenu organisé par modules et leçons",
            "info_three_item_two" => "Évaluations et résultats centralisés",
            "info_three_item_three" => "Certificats disponibles après réussite",
            "info_four_title" => "Un accès simple",
            "info_four_text" => "Découvrez les formations disponibles, demandez un accès et échangez avec notre équipe pour choisir le parcours adapté.",
        ],
        "footer" => [
            "description" => "Professional online learning platform.",
            "links_title" => "Quick Links",
            "home_label" => "Home",
            "courses_label" => "Courses",
            "about_label" => "About",
            "account_title" => "Account",
            "login_label" => "Login",
            "logout_label" => "Logout",
            "copyright" => "All rights reserved.",
        ],
        "navigation" => [
            "page_title" => "GAKU LEARNING - Online Training",
            "brand_name" => "GAKU LEARNING",
            "home" => "Home",
            "courses" => "Courses",
            "about" => "About",
            "request_status" => "Check request status",
            "request_access" => "Demander un accès",
            "login" => "Login",
            "dashboard" => "Dashboard",
            "logout" => "Logout",
        ],
    ];
}

function homepageContentLoad(PDO $pdo): array
{
    $defaults = homepageContentDefaults();
    $statement = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = 'homepage_content' LIMIT 1");
    $statement->execute();
    $stored = $statement->fetchColumn();
    if (!is_string($stored) || $stored === "") {
        return $defaults;
    }

    $decoded = json_decode($stored, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    foreach ($defaults as $group => $values) {
        if (!isset($decoded[$group]) || !is_array($decoded[$group])) {
            continue;
        }
        foreach ($values as $key => $defaultValue) {
            if (!array_key_exists($key, $decoded[$group])) {
                continue;
            }
            if ($group === "sections") {
                $defaults[$group][$key] = (int) ((bool) $decoded[$group][$key]);
            } elseif ($group === "order") {
                $defaults[$group][$key] = max(1, min(7, (int) $decoded[$group][$key]));
            } elseif (is_string($defaultValue)) {
                $defaults[$group][$key] = (string) $decoded[$group][$key];
            }
        }
    }
    return $defaults;
}

function homepageContentLinkIsValid(string $link): bool
{
    $link = trim($link);
    if ($link === "" || preg_match('/[\x00-\x1F\x7F]/', $link)) {
        return false;
    }
    if (preg_match('/^#[A-Za-z][A-Za-z0-9_-]*$/', $link)) {
        return true;
    }
    if (preg_match('#^https://#i', $link)) {
        return filter_var($link, FILTER_VALIDATE_URL) !== false;
    }
    return !preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|/)#i', $link)
        && preg_match('#^[A-Za-z0-9_./?=&%-]+$#', $link);
}
