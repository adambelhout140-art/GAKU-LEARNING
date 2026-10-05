<?php
require_once __DIR__ . '/../config/i18n.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";
require_once "../config/student_access.php";
require_once "../config/notifications.php";

studentRequireAuth();
studentEnsureCsrf();

$userId = (int) $_SESSION["user_id"];
$courseId = filter_input(INPUT_POST, "course_id", FILTER_VALIDATE_INT);
$rating = filter_input(INPUT_POST, "rating", FILTER_VALIDATE_INT);
$comment = trim((string) ($_POST["comment"] ?? ""));

if (!studentCheckCsrf() || !$courseId || $courseId < 1) {
    header("Location: dashboard.php?review=error");
    exit;
}

if (!$rating || $rating < 1 || $rating > 5) {
    header("Location: dashboard.php?review=rating");
    exit;
}

$comment = mb_substr($comment, 0, 1000);
$courseStatement = $pdo->prepare(
    "SELECT c.teacher_id, c.title AS course_title,
            u.first_name AS student_first_name, u.last_name AS student_last_name
     FROM courses c
     INNER JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
     INNER JOIN users u ON u.id = e.user_id
     WHERE c.id = ? AND c.teacher_id IS NOT NULL
       AND e.status IN ('active', 'completed')
       AND (e.expires_at IS NULL OR e.expires_at >= NOW())
     LIMIT 1"
);
$courseStatement->execute([$userId, $courseId]);
$course = $courseStatement->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    header("Location: dashboard.php?review=error");
    exit;
}

$reviewStatement = $pdo->prepare(
    "INSERT INTO teacher_reviews (course_id, teacher_id, student_id, rating, `comment`)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
         teacher_id = VALUES(teacher_id),
         rating = VALUES(rating),
         `comment` = VALUES(`comment`),
         updated_at = CURRENT_TIMESTAMP"
);
$reviewStatement->execute([
    $courseId,
    (int) $course["teacher_id"],
    $userId,
    $rating,
    $comment !== "" ? $comment : null
]);

$studentName = trim(
    ($course["student_first_name"] ?? "") . " " . ($course["student_last_name"] ?? "")
);
if ($studentName === "") {
    $studentName = "Un étudiant";
}

$reviewTitle = "Nouvelle évaluation formateur";
$reviewMessage = $studentName . " a évalué le formateur du cours « "
    . $course["course_title"] . " » avec " . $rating . "/5.";
if ($comment !== "") {
    $reviewMessage .= " Commentaire : " . $comment;
}

$adminStatement = $pdo->query(
    "SELECT id FROM users WHERE role = 'admin' AND is_active = 1"
);
foreach ($adminStatement->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
    notifyUser($pdo, (int) $adminId, $reviewTitle, $reviewMessage, "system");
}

header("Location: dashboard.php?review=success");
exit;
