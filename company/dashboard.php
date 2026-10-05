<?php
require_once __DIR__ . '/../config/i18n.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";

if (!isset($_SESSION["user_id"]) || ($_SESSION["user_role"] ?? "") !== "company_manager") {
    header("Location: ../auth/login.php");
    exit;
}

$companyId = (int) ($_SESSION["company_id"] ?? 0);
$employees = [];
$stats = [
    "employees" => 0,
    "active_enrollments" => 0,
    "completed_enrollments" => 0,
    "average_progress" => 0,
    "quiz_attempts" => 0,
    "exam_attempts" => 0,
];

if ($companyId > 0) {
    $employeeStatement = $pdo->prepare(
        "SELECT id, first_name, last_name, email, job_title, is_active
         FROM users
         WHERE company_id = ? AND role = 'student'
         ORDER BY last_name, first_name"
    );
    $employeeStatement->execute([$companyId]);
    $employees = $employeeStatement->fetchAll(PDO::FETCH_ASSOC);

    $statsStatement = $pdo->prepare(
        "SELECT
            COUNT(DISTINCT u.id) AS employees,
            COUNT(DISTINCT CASE WHEN en.status = 'active' THEN en.id END) AS active_enrollments,
            COUNT(DISTINCT CASE WHEN en.status = 'completed' THEN en.id END) AS completed_enrollments,
            COALESCE(ROUND(AVG(CASE WHEN l.id IS NOT NULL THEN COALESCE(lp.progress_percent, 0) END), 0), 0) AS average_progress,
            COALESCE((
                SELECT COUNT(*)
                FROM quiz_attempts qa
                INNER JOIN users qu ON qu.id = qa.user_id AND qu.company_id = ? AND qu.role = 'student'
            ), 0) AS quiz_attempts,
            COALESCE((
                SELECT COUNT(*)
                FROM exam_attempts ea
                INNER JOIN users eu ON eu.id = ea.user_id AND eu.company_id = ? AND eu.role = 'student'
            ), 0) AS exam_attempts
         FROM users u
         LEFT JOIN enrollments en ON en.user_id = u.id AND en.status IN ('active', 'completed')
         LEFT JOIN courses c ON c.id = en.course_id
         LEFT JOIN modules m ON m.course_id = c.id
         LEFT JOIN lessons l ON l.module_id = m.id AND l.is_published = 1
         LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.user_id = u.id
         WHERE u.company_id = ? AND u.role = 'student'"
    );
    $statsStatement->execute([$companyId, $companyId, $companyId]);
    $stats = array_merge($stats, $statsStatement->fetch(PDO::FETCH_ASSOC) ?: []);
}

$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
};
$userName = trim((string) ($_SESSION["user_name"] ?? ""));
if ($userName === "") {
    $userName = "Responsable";
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord entreprise - GAKU LEARNING</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=20260915d">
</head>
<body class="admin-body">
<div class="admin-layout">
    <aside class="admin-sidebar">
        <div class="admin-logo"><a href="../index.php"><img src="../assets/images/logo-light.png" alt="Gaku Learning" class="brand-logo" width="34" height="34"> GAKU LEARNING</a></div>
        <nav class="admin-navigation">
            <a href="dashboard.php" class="admin-nav-link active"><span>📊</span> Tableau de bord</a>
            <a href="../index.php" class="admin-nav-link"><span>🌐</span> Voir le site</a>
        </nav>
        <div class="admin-sidebar-bottom"><a href="../auth/logout.php" class="admin-nav-link logout-link"><span>🚪</span> Déconnexion</a></div>
    </aside>

    <main class="admin-main">
        <header class="admin-topbar">
            <div>
                <p class="student-eyebrow">Espace entreprise</p>
                <h1>Tableau de bord</h1>
                <p>Bienvenue, <?= $escape($userName) ?>. Suivez l'activité de vos collaborateurs.</p>
            </div>
        </header>

        <section class="student-stat-grid">
            <article><span>👥</span><strong><?= (int) $stats["employees"] ?></strong><p>Collaborateurs</p></article>
            <article><span>📚</span><strong><?= (int) $stats["active_enrollments"] ?></strong><p>Formations en cours</p></article>
            <article><span>✅</span><strong><?= (int) $stats["completed_enrollments"] ?></strong><p>Formations terminées</p></article>
            <article><span>📈</span><strong><?= (int) $stats["average_progress"] ?>%</strong><p>Progression moyenne</p></article>
        </section>

        <section class="student-results-grid">
            <article class="student-section student-result-panel">
                <h2>Activité d'évaluation</h2>
                <ul>
                    <li><div><strong>Quiz réalisés</strong><small>Par vos collaborateurs</small></div><span class="student-result-good"><?= (int) $stats["quiz_attempts"] ?></span></li>
                    <li><div><strong>Examens réalisés</strong><small>Par vos collaborateurs</small></div><span class="student-result-good"><?= (int) $stats["exam_attempts"] ?></span></li>
                </ul>
            </article>
            <article class="student-section student-result-panel">
                <h2>Résumé</h2>
                <p class="student-muted">Les indicateurs sont calculés à partir des inscriptions et de la progression enregistrée des collaborateurs de votre entreprise.</p>
            </article>
        </section>

        <section class="dashboard-search-panel" aria-label="Recherche avancée">
            <div class="dashboard-search-heading">
                <div><p class="student-eyebrow">Équipe</p><h2>Mes collaborateurs</h2></div>
                <span data-search-count>0 résultat(s)</span>
            </div>
            <form data-dashboard-search class="dashboard-search-form">
                <input data-search-query type="search" placeholder="Nom, email ou poste..." aria-label="Rechercher un collaborateur">
                <select data-search-status aria-label="Filtrer les collaborateurs">
                    <option value="">Tous les collaborateurs</option>
                    <option value="active">Comptes actifs</option>
                    <option value="inactive">Comptes inactifs</option>
                    <option value="with-position">Avec un poste</option>
                    <option value="without-position">Poste non renseigné</option>
                </select>
                <button class="button secondary-button" type="reset">Réinitialiser</button>
            </form>
        </section>

        <section class="dashboard-section">
            <div class="dashboard-search-links">
                <?php foreach ($employees as $employee): ?>
                    <?php
                    $hasPosition = trim((string) ($employee["job_title"] ?? "")) !== "";
                    $employeeStatus = (int) $employee["is_active"] === 1 ? "active" : "inactive";
                    ?>
                    <article data-dashboard-search-item data-search-status="<?= $employeeStatus ?> <?= $hasPosition ? "with-position" : "without-position" ?>">
                        <strong><?= $escape(trim($employee["first_name"] . " " . $employee["last_name"])) ?></strong>
                        <p><?= $escape($employee["email"]) ?> · <?= $escape($employee["job_title"] ?: "Poste non renseigné") ?> · <?= $employeeStatus === "active" ? "Actif" : "Inactif" ?></p>
                    </article>
                <?php endforeach; ?>
                <?php if (!$employees): ?><p class="student-empty">Aucun collaborateur trouvé.</p><?php endif; ?>
            </div>
        </section>
    </main>
</div>
<script src="../assets/js/dashboard-search.js?v=20260915a"></script>
<script src="../assets/js/admin-sidebar.js?v=20260925e"></script>
</body>
</html>
