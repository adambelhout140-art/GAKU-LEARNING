<?php

/**
 * Render the shared administrator navigation.
 *
 * Links are built from the application root so the same component works
 * from admin pages and pages inside nested admin directories.
 */
function renderAdminSidebar(string $activePage = ""): void
{
    global $pdo;
    $branding = platformBranding($pdo);
    $scriptPath = str_replace("\\", "/", (string) ($_SERVER["SCRIPT_NAME"] ?? ""));
    $adminPosition = strpos($scriptPath, "/admin/");
    $teacherPosition = strpos($scriptPath, "/teacher/");
    $isTeacher = ($_SESSION["user_role"] ?? "") === "teacher";
    $applicationRoot = $adminPosition !== false
        ? substr($scriptPath, 0, $adminPosition)
        : ($teacherPosition !== false ? substr($scriptPath, 0, $teacherPosition) : "");
    $adminRoot = $applicationRoot . "/admin";
    $teacherRoot = $applicationRoot . "/teacher";

    $links = $isTeacher
        ? [
            ["dashboard.php", "📊", "Tableau de bord", $teacherRoot . "/dashboard.php"],
            ["notifications.php", "💬", "Communication", $teacherRoot . "/notifications.php"],
            ["results.php", "📈", "Résultats étudiants", $teacherRoot . "/results.php"],
            ["security_logs.php", "🔐", "Sécurité étudiants", $teacherRoot . "/security_logs.php"],
        ]
        : [
            ["dashboard.php", "📊", "Dashboard", $adminRoot . "/dashboard.php"],
            ["homepage_editor.php", "🖥️", "Page d'accueil", $adminRoot . "/homepage_editor.php"],
            ["navbar.php", "🧭", "Navigation du site", $adminRoot . "/navbar.php"],
            ["users/users.php", "👥", "Utilisateurs", $adminRoot . "/users/users.php"],
            ["companies/companies.php", "🏢", "Entreprises", $adminRoot . "/companies/companies.php"],
            ["results.php", "📈", "Résultats", $adminRoot . "/results.php"],
            ["enrollments/enrollments.php", "🎓", "Accès", $adminRoot . "/enrollments/enrollments.php"],
            ["certificates.php", "🏆", "Certificats", $adminRoot . "/certificates.php"],
            ["notifications.php", "💬", "Communication", $adminRoot . "/notifications.php"],
            ["contact_requests/contact_requests.php", "✉️", "Demandes d'inscription", $adminRoot . "/contact_requests/contact_requests.php"],
            ["homepage_videos.php", "🎬", "Vidéos d'accueil", $adminRoot . "/homepage_videos.php"],
            ["security_logs.php", "🔐", "Sécurité", $adminRoot . "/security_logs.php"],
            ["settings.php", "⚙️", "Paramètres", $adminRoot . "/settings.php"],
        ];

    $escape = function (string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
    };
    $contentPages = [
        "content.php",
        "homepage_editor.php",
        "homepage_videos.php",
        "courses/courses.php",
        "courses/course_form.php",
        "courses/see_course.php",
        "modules/modules.php",
        "modules/module_create.php",
        "modules/module_edit.php",
        "lessons/lessons.php",
        "lessons/lesson_create.php",
        "lessons/lesson_edit.php",
        "quizzes/quizzes.php",
        "quizzes/quiz-create.php",
        "quizzes/quiz-edit.php",
        "quizzes/quiz-view.php",
        "exams/exams.php",
        "exams/exam-create.php",
        "exams/exam-edit.php",
        "exams/exam-view.php",
    ];
    $contentIsActive = in_array($activePage, $contentPages, true);
    ?>
    <aside class="admin-sidebar">
        <div class="admin-logo">
            <a href="<?= $escape($applicationRoot . "/index.php") ?>">
                <img src="<?= $escape(platformLogoUrl($pdo, "dark-bg")) ?>" alt="<?= $escape($branding["platform_name"]) ?>" class="brand-logo platform-brand-logo" width="34" height="34">
                <span class="brand-name"><?= $escape($branding["platform_name"]) ?></span>
            </a>
        </div>
        <nav class="admin-navigation">
            <?php foreach ($links as [$href, $icon, $label, $url]): ?>
                <a href="<?= $escape($url) ?>" class="admin-nav-link<?= $activePage === $href ? " active" : "" ?>">
                    <span><?= $icon ?></span> <?= $escape($label) ?>
                </a>
                <?php if ($isTeacher && $href === "dashboard.php"): ?>
                    <details class="admin-content-menu"<?= $contentIsActive ? " open" : "" ?>>
                        <summary><span class="admin-content-menu-icon">📚</span><span>Contenu</span><b aria-hidden="true">⌄</b></summary>
                        <div class="admin-content-menu-links">
                            <a href="<?= $escape($adminRoot . "/content.php") ?>" class="<?= $activePage === "content.php" ? "active" : "" ?>">✨ Créer du contenu</a>
                            <a href="<?= $escape($adminRoot . "/modules/modules.php") ?>" class="<?= strpos($activePage, "modules/") === 0 ? "active" : "" ?>">Modules</a>
                            <a href="<?= $escape($adminRoot . "/lessons/lessons.php") ?>" class="<?= strpos($activePage, "lessons/") === 0 ? "active" : "" ?>">Leçons</a>
                            <a href="<?= $escape($adminRoot . "/quizzes/quizzes.php") ?>" class="<?= strpos($activePage, "quizzes/") === 0 ? "active" : "" ?>">Quiz</a>
                            <a href="<?= $escape($adminRoot . "/exams/exams.php") ?>" class="<?= strpos($activePage, "exams/") === 0 ? "active" : "" ?>">Examens</a>
                        </div>
                    </details>
                <?php elseif (!$isTeacher && $href === "companies/companies.php"): ?>
                    <details class="admin-content-menu"<?= $contentIsActive ? " open" : "" ?>>
                        <summary><span class="admin-content-menu-icon">📚</span><span>Contenu</span><b aria-hidden="true">⌄</b></summary>
                        <div class="admin-content-menu-links">
                            <a href="<?= $escape($adminRoot . "/content.php") ?>" class="<?= $activePage === "content.php" ? "active" : "" ?>">✨ Créer du contenu</a>
                            <a href="<?= $escape($adminRoot . "/courses/courses.php") ?>" class="<?= $activePage === "courses/courses.php" ? "active" : "" ?>">Formations</a>
                            <a href="<?= $escape($adminRoot . "/modules/modules.php") ?>" class="<?= $activePage === "modules/modules.php" ? "active" : "" ?>">Modules</a>
                            <a href="<?= $escape($adminRoot . "/lessons/lessons.php") ?>" class="<?= $activePage === "lessons/lessons.php" ? "active" : "" ?>">Leçons</a>
                            <a href="<?= $escape($adminRoot . "/quizzes/quizzes.php") ?>" class="<?= $activePage === "quizzes/quizzes.php" ? "active" : "" ?>">Quiz</a>
                            <a href="<?= $escape($adminRoot . "/exams/exams.php") ?>" class="<?= $activePage === "exams/exams.php" ? "active" : "" ?>">Examens</a>
                        </div>
                    </details>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <div class="admin-sidebar-bottom">
            <?= platformContactMarkup($pdo, "platform-contact platform-contact-sidebar") ?>
            <a href="<?= $escape(($isTeacher ? $teacherRoot : $adminRoot) . "/profile.php") ?>" class="admin-nav-link"><span>👤</span> Mon profil</a>
            <a href="<?= $escape($applicationRoot . "/index.php") ?>" class="admin-nav-link"><span>🌐</span> Voir le site</a>
            <a href="<?= $escape($applicationRoot . "/auth/logout.php") ?>" class="admin-nav-link logout-link"><span>🚪</span> Déconnexion</a>
        </div>
    </aside>
    <?php
}
