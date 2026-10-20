<?php
require_once __DIR__ . "/../config/i18n.php";
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}
if (($_SESSION["user_role"] ?? "") !== "parent") {
    http_response_code(403);
    exit("Accès interdit.");
}

require_once __DIR__ . "/../config/database.php";
$parentId = (int) $_SESSION["user_id"];
$studentsStatement = $pdo->prepare(
    "SELECT u.id, u.first_name, u.last_name, u.email
     FROM parent_student_links link
     INNER JOIN users u ON u.id = link.student_id AND u.role = 'student'
     WHERE link.parent_id = ?
     ORDER BY u.last_name, u.first_name"
);
$studentsStatement->execute([$parentId]);
$students = $studentsStatement->fetchAll(PDO::FETCH_ASSOC);
$selectedStudentId = filter_input(INPUT_GET, "student_id", FILTER_VALIDATE_INT);
if ($selectedStudentId && !array_filter($students, static function ($student) use ($selectedStudentId) {
    return (int) $student["id"] === $selectedStudentId;
})) {
    http_response_code(404);
    exit("Étudiant introuvable ou non lié à ce compte parent.");
}
function reportSelectionEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Télécharger un rapport - <?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260925e">
    <style>
        .report-selection-card { max-width: 760px; margin: 30px auto; padding: 30px; }
        .report-selection-options { display: grid; gap: 12px; margin: 22px 0; }
        .report-selection-option { display: flex; align-items: flex-start; gap: 12px; padding: 16px; border: 1px solid #dce6e8; border-radius: 12px; background: #fff; cursor: pointer; }
        .report-selection-option input { margin-top: 4px; accent-color: #087f78; }
        .report-selection-option span { display: grid; gap: 5px; }
        .report-selection-option small { color: #667982; line-height: 1.5; }
        .report-student-picker { display: grid; gap: 8px; margin: 0 0 22px; }
        .report-student-picker select { width: 100%; min-height: 48px; padding: 10px 12px; border: 1px solid #ccd8dc; border-radius: 8px; background: #fff; font: inherit; }
    </style>
</head>
<body class="student-body parent-body">
    <header class="student-navbar">
        <a href="../index.php" class="student-brand"><img src="<?= htmlspecialchars(platformLogoUrl($pdo, "light-bg"), ENT_QUOTES, "UTF-8") ?>" alt="<?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?>" class="brand-logo" width="38" height="38"><?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></a>
        <nav><a href="dashboard.php" class="active">Mes enfants</a><a href="account.php">Mon compte</a><a href="../auth/logout.php">Déconnexion</a></nav>
    </header>
    <main class="student-dashboard">
        <section class="student-section report-selection-card">
            <p class="student-eyebrow">Rapport PDF</p>
            <h1>Choisir les étudiants</h1>
            <p class="student-muted">Téléchargez le suivi détaillé d'un enfant ou réunissez les rapports de tous vos enfants dans un seul PDF.</p>
            <?php if (!$students): ?>
                <div class="student-empty">Aucun enfant n'est lié à votre compte.</div>
            <?php else: ?>
                <form method="get" action="student_report.php">
                    <div class="report-selection-options">
                        <label class="report-selection-option">
                            <input type="radio" name="selection" value="all">
                            <span><strong>Tous les étudiants</strong><small>Un seul PDF avec une section complète pour chaque enfant lié.</small></span>
                        </label>
                        <label class="report-selection-option">
                            <input type="radio" name="selection" value="one" checked>
                            <span><strong>Un seul étudiant</strong><small>Sélectionnez l'enfant dont vous voulez télécharger le rapport.</small></span>
                        </label>
                    </div>
                    <label class="report-student-picker"><strong>Étudiant</strong>
                        <select name="student_id" required>
                            <?php foreach ($students as $student): ?>
                                <option value="<?= (int) $student["id"] ?>" <?= $selectedStudentId === (int) $student["id"] ? "selected" : "" ?>>
                                    <?= reportSelectionEscape(trim($student["first_name"] . " " . $student["last_name"])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="parent-child-heading-actions">
                        <button class="button primary-button" type="submit">Télécharger le PDF</button>
                        <a class="button secondary-button" href="dashboard.php">Annuler</a>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </main>
    <script>
        (function () {
            var form = document.querySelector(".report-selection-card form");
            if (!form) return;
            form.addEventListener("submit", function (event) {
                var selection = form.querySelector('input[name="selection"]:checked');
                if (selection && selection.value === "all") {
                    event.preventDefault();
                    window.location.href = "student_report.php?student_id=all";
                }
            });
        }());
    </script>
<?= platformContactMarkup($pdo, "platform-contact platform-contact-app") ?>
</body>
</html>
