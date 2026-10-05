<?php
require_once __DIR__ . '/../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";
require_once "../config/notifications.php";
require_once "../config/messaging.php";
require_once "../config/audit.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}
if (($_SESSION["user_role"] ?? "") !== "teacher") {
    http_response_code(403);
    exit("Accès interdit.");
}
if (empty($_SESSION["csrf_token"]))
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

$teacherId = (int) $_SESSION["user_id"];
$errors = [];
$success = "";
$studentStatement = $pdo->prepare(
    "SELECT DISTINCT u.id, u.first_name, u.last_name, u.email, u.is_active
     FROM users u
     INNER JOIN enrollments e ON e.user_id = u.id
     INNER JOIN courses c ON c.id = e.course_id AND EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id = c.id AND ct.teacher_id = ?)
     WHERE u.role = 'student' AND u.is_active = 1 AND e.status IN ('active', 'completed')
     ORDER BY u.first_name, u.last_name"
);
$studentStatement->execute([$teacherId]);
$students = $studentStatement->fetchAll(PDO::FETCH_ASSOC);
$adminStatement = $pdo->query(
    "SELECT id, first_name, last_name, email, is_active
     FROM users
     WHERE role = 'admin' AND is_active = 1
     ORDER BY first_name, last_name"
);
$admins = $adminStatement->fetchAll(PDO::FETCH_ASSOC);
$parentStatement = $pdo->prepare(
    "SELECT DISTINCT p.id, p.first_name, p.last_name, p.email, p.is_active
     FROM users p
     INNER JOIN parent_student_links l ON l.parent_id = p.id
     INNER JOIN enrollments e ON e.user_id = l.student_id AND e.status IN ('active', 'completed')
     INNER JOIN courses c ON c.id = e.course_id AND EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id = c.id AND ct.teacher_id = ?)
     WHERE p.role = 'parent' AND p.is_active = 1
     ORDER BY p.first_name, p.last_name"
);
$parentStatement->execute([$teacherId]);
$parents = $parentStatement->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $recipientId = filter_input(INPUT_POST, "recipient_id", FILTER_VALIDATE_INT);
    $recipientType = $_POST["recipient_type"] ?? "student";
    $title = trim($_POST["title"] ?? "");
    $message = trim($_POST["message"] ?? "");
    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? ""))
        $errors[] = "La session du formulaire a expiré.";
    if (!$recipientId || $recipientId < 1)
        $errors[] = "Sélectionnez un destinataire.";
    if (!in_array($recipientType, ["student", "admin", "parent"], true))
        $errors[] = "Le destinataire est invalide.";
    if ($title === "" || mb_strlen($title) > 150)
        $errors[] = "Le titre est obligatoire et limité à 150 caractères.";
    if ($message === "" || mb_strlen($message) > 2000)
        $errors[] = "Le message est obligatoire et limité à 2000 caractères.";

    if (!$errors) {
        $allowedRecipient = $recipientType === "admin"
            ? $pdo->prepare(
                "SELECT id FROM users WHERE id = ? AND role = 'admin' AND is_active = 1 LIMIT 1"
            )
            : ($recipientType === "parent"
                ? $pdo->prepare(
                    "SELECT DISTINCT p.id FROM users p
                     INNER JOIN parent_student_links l ON l.parent_id = p.id
                     INNER JOIN enrollments e ON e.user_id = l.student_id AND e.status IN ('active', 'completed')
                     INNER JOIN courses c ON c.id = e.course_id AND EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id = c.id AND ct.teacher_id = ?)
                     WHERE p.id = ? AND p.role = 'parent' AND p.is_active = 1 LIMIT 1"
                )
                : $pdo->prepare(
                    "SELECT u.id FROM users u INNER JOIN enrollments e ON e.user_id = u.id
                     INNER JOIN courses c ON c.id = e.course_id AND EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id = c.id AND ct.teacher_id = ?)
                     WHERE u.id = ? AND u.role = 'student' AND u.is_active = 1 AND e.status IN ('active', 'completed') LIMIT 1"
                ));
        $allowedRecipient->execute(
            $recipientType === "admin" ? [$recipientId] : [$teacherId, $recipientId]
        );
        if (!$allowedRecipient->fetchColumn()) {
            $errors[] = $recipientType === "admin"
                ? "Cet administrateur est invalide."
                : ($recipientType === "parent"
                    ? "Ce parent n'est pas lié à un étudiant de vos formations."
                    : "Cet étudiant ne suit pas une de vos formations.");
        } else {
            notifyUser($pdo, $recipientId, $title, $message, "system");
            sendPlatformMessage($pdo, $teacherId, $recipientId, $title, $message);
            auditLog($pdo, $teacherId, $recipientType . "_message_sent", "user", $recipientId, null, ["title" => $title]);
            $success = $recipientType === "admin"
                ? "Message envoyé à l'administrateur."
                : ($recipientType === "parent"
                    ? "Message envoyé au parent."
                    : "Message envoyé à l'étudiant.");
        }
    }
}
function teacherMessageEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Message - GAKU LEARNING</title><link rel="icon" type="image/png" href="../assets/images/logo-mark.png">
    <link rel="stylesheet" href="../assets/css/style.css?v=20260925e">
</head>

<body class="admin-body teacher-view teacher-dashboard-page">
    <div class="admin-layout">
        <?php require_once __DIR__ . "/../admin/config/admin_sidebar.php"; renderAdminSidebar("message.php"); ?>
        <main class="admin-main teacher-dashboard">
            <header class="admin-topbar">
                <div>
                    <h1>Écrire à un étudiant</h1>
                    <p>Envoyez un message aux étudiants inscrits à vos formations.</p>
                </div><a class="btn btn-secondary" href="results.php">Voir les résultats</a>
            </header>
            <section class="teacher-page-heading">
                <div>
                    <p class="student-eyebrow">Communication</p>
                    <h1>Écrire à un étudiant</h1>
                    <p>Envoyez un message aux étudiants inscrits à vos formations.</p>
                </div>
            </section>
            <section class="teacher-results-panel teacher-message-panel">
                <?php if ($success): ?>
                    <div class="alert alert-success"><?= teacherMessageEscape($success) ?></div><?php endif; ?>
                <?php if ($errors): ?>
                    <div class="alert alert-danger"><?= teacherMessageEscape(implode(" ", $errors)) ?></div><?php endif; ?>
                <form method="post" class="teacher-message-form"><input type="hidden" name="csrf_token"
                        value="<?= teacherMessageEscape($_SESSION["csrf_token"]) ?>"><label
                        for="recipient_id">Destinataire</label><select id="recipient_id" name="recipient_id" required>
                        <option value="">Choisir un destinataire</option>
                        <optgroup label="Administrateurs"><?php foreach ($admins as $admin): ?>
                            <option value="<?= (int) $admin["id"] ?>" data-recipient-type="admin">
                                <?= teacherMessageEscape(trim($admin["first_name"] . " " . $admin["last_name"])) ?> —
                                <?= teacherMessageEscape($admin["email"]) ?><?= !(int) $admin["is_active"] ? " — Inactif" : "" ?></option><?php endforeach; ?></optgroup>
                        <optgroup label="Étudiants"><?php foreach ($students as $student): ?>
                            <option value="<?= (int) $student["id"] ?>" data-recipient-type="student">
                                <?= teacherMessageEscape(trim($student["first_name"] . " " . $student["last_name"])) ?> —
                                <?= teacherMessageEscape($student["email"]) ?><?= !(int) $student["is_active"] ? " — Inactif" : "" ?></option><?php endforeach; ?>
                    </optgroup><optgroup label="Parents"><?php foreach ($parents as $parent): ?>
                            <option value="<?= (int) $parent["id"] ?>" data-recipient-type="parent">
                                <?= teacherMessageEscape(trim($parent["first_name"] . " " . $parent["last_name"])) ?> —
                                <?= teacherMessageEscape($parent["email"]) ?><?= !(int) $parent["is_active"] ? " — Inactif" : "" ?></option><?php endforeach; ?>
                    </optgroup></select><input type="hidden" id="recipient_type" name="recipient_type" value="student"><label for="title">Titre</label><input id="title" name="title" maxlength="150"
                        required><label for="message">Message</label><textarea id="message" name="message" rows="7"
                        maxlength="2000" required></textarea><button class="button primary-button" type="submit">Envoyer
                        le message</button></form>
            </section>
        </main>
    </div>
<script src="../assets/js/admin-sidebar.js?v=20260925e"></script>
<script src="../assets/js/teacher-navigation.js?v=20260925b"></script>
</body>
<script>
document.getElementById("recipient_id").addEventListener("change", function () {
    var option = this.options[this.selectedIndex];
    document.getElementById("recipient_type").value = option.dataset.recipientType || "student";
});
</script>
</html>
