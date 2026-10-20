<?php
require_once __DIR__ . '/../config/i18n.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/content_access.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

if (!in_array($_SESSION["user_role"] ?? "", ["admin", "teacher"], true)) {
    http_response_code(403);
    exit("Accès interdit.");
}

$isTeacher = ($_SESSION["user_role"] ?? "") === "teacher";
$courseId = filter_input(INPUT_GET, "course_id", FILTER_VALIDATE_INT) ?: 0;
$moduleId = filter_input(INPUT_GET, "module_id", FILTER_VALIDATE_INT) ?: 0;

$courseStatement = $isTeacher
    ? $pdo->prepare("SELECT id, title FROM courses WHERE EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id = courses.id AND ct.teacher_id = ?) ORDER BY title ASC")
    : $pdo->prepare("SELECT id, title FROM courses ORDER BY title ASC");
$isTeacher ? $courseStatement->execute([(int) $_SESSION["user_id"]]) : $courseStatement->execute();
$courses = $courseStatement->fetchAll(PDO::FETCH_ASSOC);

$selectedCourse = null;
foreach ($courses as $course) {
    if ((int) $course["id"] === $courseId) {
        $selectedCourse = $course;
        break;
    }
}

if (!$selectedCourse) {
    $courseId = 0;
    $moduleId = 0;
}

if ($selectedCourse && $isTeacher && !canManageCourse($pdo, (int) $courseId, (int) $_SESSION["user_id"], "teacher")) {
    http_response_code(403);
    exit("Vous n'êtes pas autorisé à gérer cette formation.");
}

$modules = [];
if ($selectedCourse) {
    $statement = $pdo->prepare(
        "SELECT id, title
         FROM modules
         WHERE course_id = ?
         ORDER BY position ASC, title ASC"
    );
    $statement->execute([$courseId]);
    $modules = $statement->fetchAll(PDO::FETCH_ASSOC);
}

$selectedModule = null;
foreach ($modules as $module) {
    if ((int) $module["id"] === $moduleId) {
        $selectedModule = $module;
        break;
    }
}

if (!$selectedModule) {
    $moduleId = 0;
}

$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Créer du contenu - <?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260925e">
    <style>
        .content-creator {
            max-width: 1100px;
            margin: 0 auto;
            padding: 1.5rem 2.5rem 2.5rem;
        }
        .content-creator-intro {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .content-creator-intro h2 {
            margin: 0;
            color: #102a43;
        }
        .content-creator-intro p {
            margin: .25rem 0 0;
            color: #627d98;
        }
        .creator-steps {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: .65rem;
            margin-bottom: .85rem;
        }
        .creator-step {
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: .75rem .9rem;
            border: 1px solid #dbe7f3;
            border-radius: 14px;
            background: #fff;
            color: #627d98;
        }
        .creator-step.active {
            border-color: #277cff;
            box-shadow: 0 8px 24px rgba(39, 124, 255, .12);
            color: #102a43;
        }
        .creator-step.done {
            border-color: #b7e4d0;
            background: #f5fffa;
        }
        .creator-number {
            display: grid;
            place-items: center;
            flex: 0 0 auto;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #e8f1ff;
            color: #277cff;
            font-weight: 800;
        }
        .creator-step strong,
        .creator-step small {
            display: block;
        }
        .creator-step small {
            margin-top: .2rem;
            color: #829ab1;
        }
        .creator-card {
            margin-bottom: .75rem;
            padding: 1rem 1.1rem;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 8px 28px rgba(16, 42, 67, .05);
        }
        .creator-card h3 {
            margin: 0;
            color: #102a43;
        }
        .creator-card p {
            margin: .2rem 0 .75rem;
            color: #627d98;
            font-size: .9rem;
        }
        .creator-form {
            display: flex;
            gap: .75rem;
            align-items: center;
        }
        .creator-form select {
            flex: 1;
            min-width: 0;
            padding: .65rem .8rem;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            background: #fff;
            color: #102a43;
        }
        .creator-form select:focus {
            border-color: #277cff;
            outline: 3px solid rgba(39, 124, 255, .14);
        }
        .creator-select-note {
            flex: 0 0 auto;
            color: #829ab1;
            font-size: .82rem;
            white-space: nowrap;
        }
        .creator-actions {
            display: flex;
            flex-wrap: wrap;
            gap: .7rem;
        }
        .creator-actions a {
            text-decoration: none;
        }
        .creator-empty {
            padding: .8rem;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            color: #627d98;
        }
        .creator-empty a,
        .creator-link {
            color: #277cff;
            font-weight: 600;
            text-decoration: none;
        }
        .creator-quick {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-top: .75rem;
            padding: .85rem 1.1rem;
            border-radius: 14px;
            background: #f8fbff;
            border: 1px solid #dbe7f3;
        }
        .creator-quick strong,
        .creator-quick span {
            display: block;
        }
        .creator-quick strong {
            color: #102a43;
        }
        .creator-quick span {
            margin-top: .15rem;
            color: #627d98;
            font-size: .9rem;
        }
        .creator-quick .creator-actions {
            flex: 0 0 auto;
        }
        @media (max-width: 760px) {
            .content-creator {
                padding: 1rem;
            }
            .creator-steps {
                grid-template-columns: 1fr;
            }
            .content-creator-intro,
            .creator-form,
            .creator-quick {
                align-items: stretch;
                flex-direction: column;
            }
            .creator-form button {
                width: 100%;
            }
        }
    </style>
</head>
<body class="admin-body<?= $isTeacher ? " teacher-view" : "" ?>">
    <div class="admin-layout">
        <?php require_once __DIR__ . "/config/admin_sidebar.php"; renderAdminSidebar("content.php"); ?>
        <main class="admin-main">
            <header class="admin-topbar">
                <div>
                    <p class="student-eyebrow"><?= $isTeacher ? "Espace formateur" : "Espace administrateur" ?></p>
                    <h1>Créer du contenu</h1>
                    <p><?= $isTeacher ? "Gérez le contenu de vos formations étape par étape." : "Construisez votre formation étape par étape." ?></p>
                </div>
            </header>

            <section class="content-creator">
                <div class="content-creator-intro">
                    <div>
                        <h2>Créateur de contenu</h2>
                        <p>Sélectionnez un parent, puis créez l'étape suivante.</p>
                    </div>
                    <?php if (!$isTeacher): ?>
                        <a class="button secondary-button" href="courses/course_create.php?return_to=content">+ Formation</a>
                    <?php endif; ?>
                </div>

                <div class="creator-steps">
                    <div class="creator-step<?= $selectedCourse ? " done" : " active" ?>">
                        <span class="creator-number">1</span>
                        <div>
                            <strong>Formation</strong>
                            <small><?= $selectedCourse ? $escape($selectedCourse["title"]) : "À choisir" ?></small>
                        </div>
                    </div>
                    <div class="creator-step<?= $selectedModule ? " done" : ($selectedCourse ? " active" : "") ?>">
                        <span class="creator-number">2</span>
                        <div>
                            <strong>Module</strong>
                            <small><?= $selectedModule ? $escape($selectedModule["title"]) : "À choisir ensuite" ?></small>
                        </div>
                    </div>
                    <div class="creator-step<?= $selectedModule ? " active" : "" ?>">
                        <span class="creator-number">3</span>
                        <div>
                            <strong>Leçon</strong>
                            <small>Créer le prochain contenu</small>
                        </div>
                    </div>
                </div>

                <article class="creator-card">
                    <h3>1. Formation</h3>
                    <p>Choisissez la formation à compléter.</p>
                    <form class="creator-form" method="get">
                        <select name="course_id" required aria-label="Choisir une formation" onchange="this.form.submit()">
                            <option value="">Sélectionner une formation</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?= (int) $course["id"] ?>"<?= (int) $course["id"] === $courseId ? " selected" : "" ?>>
                                    <?= $escape($course["title"]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="creator-select-note">Sélection automatique</span>
                    </form>
                </article>

                <?php if ($selectedCourse): ?>
                    <article class="creator-card">
                        <h3>2. Module</h3>
                        <p><strong><?= $escape($selectedCourse["title"]) ?></strong> · choisissez le module à compléter.</p>
                        <?php if ($modules): ?>
                            <form class="creator-form" method="get">
                                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                                <select name="module_id" required aria-label="Choisir un module" onchange="this.form.submit()">
                                    <option value="">Sélectionner un module</option>
                                    <?php foreach ($modules as $module): ?>
                                        <option value="<?= (int) $module["id"] ?>"<?= (int) $module["id"] === $moduleId ? " selected" : "" ?>>
                                            <?= $escape($module["title"]) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="creator-select-note">Sélection automatique</span>
                            </form>
                        <?php else: ?>
                            <div class="creator-empty">
                                Aucun module n'existe encore pour cette formation.
                                <a href="modules/module_create.php?course_id=<?= $courseId ?>&return_to=content">Créer le premier module</a>
                            </div>
                        <?php endif; ?>
                        <div class="creator-actions" style="margin-top: .65rem;">
                            <a class="button secondary-button" href="modules/module_create.php?course_id=<?= $courseId ?>&return_to=content">+ Ajouter un module</a>
                        </div>
                    </article>
                <?php endif; ?>

                <?php if ($selectedModule): ?>
                    <article class="creator-card">
                        <h3>3. Créer le contenu</h3>
                        <p><strong><?= $escape($selectedModule["title"]) ?></strong> · choisissez une action.</p>
                        <div class="creator-actions">
                            <a class="button primary-button" href="lessons/lesson_create.php?module_id=<?= $moduleId ?>&return_to=content">+ Créer une leçon</a>
                            <a class="button secondary-button" href="quizzes/quiz-create.php?module_id=<?= $moduleId ?>&course_id=<?= $courseId ?>&return_to=content">+ Créer un quiz</a>
                            <a class="button secondary-button" href="exams/exam-create.php?course_id=<?= $courseId ?>&return_to=content">+ Créer un examen</a>
                        </div>
                    </article>
                <?php endif; ?>

                <div class="creator-quick">
                    <div>
                        <strong>Quiz et examens</strong>
                        <span>Ces contenus peuvent être créés indépendamment de la sélection ci-dessus.</span>
                    </div>
                    <div class="creator-actions">
                        <a class="button secondary-button" href="quizzes/quizzes.php">Gérer les quiz</a>
                        <a class="button secondary-button" href="exams/exams.php">Gérer les examens</a>
                    </div>
                </div>
            </section>
        </main>
    </div>
    <script src="../assets/js/admin-sidebar.js?v=20260925e"></script>
</body>
</html>
