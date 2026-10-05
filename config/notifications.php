<?php
/**
 * Small helper around the (previously unused) `notifications` table.
 * Call notifyUser() at the moment something worth telling a student about
 * happens: an enrollment change, a graded quiz/exam, a new certificate.
 */

/**
 * @param string $type One of: enrollment, quiz_result, exam_result, certificate, reminder, system
 */
function notifyUser(PDO $pdo, int $userId, string $title, string $message, string $type = "system"): void
{
    $allowedTypes = ["enrollment", "quiz_result", "exam_result", "certificate", "reminder", "system"];
    if (!in_array($type, $allowedTypes, true)) {
        $type = "system";
    }

    $statement = $pdo->prepare(
        "INSERT INTO notifications (user_id, title, message, type, is_read, created_at)
         VALUES (?, ?, ?, ?, 0, NOW())"
    );
    $statement->execute([$userId, $title, $message, $type]);
}

function notifyParentsOfStudent(PDO $pdo, int $studentId, string $title, string $message, string $type = "system"): void
{
    $statement = $pdo->prepare(
        "SELECT parent_id FROM parent_student_links
         INNER JOIN users p ON p.id = parent_id AND p.role = 'parent' AND p.is_active = 1
         WHERE student_id = ?"
    );
    $statement->execute([$studentId]);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $parentId) {
        notifyUser($pdo, (int) $parentId, $title, $message, $type);
    }
}

function notifySecurityUsers(PDO $pdo, array $userIds, string $title, string $message): void
{
    $userIds = array_values(array_unique(array_filter(array_map("intval", $userIds))));
    if (!$userIds) {
        return;
    }

    $existing = $pdo->prepare(
        "SELECT 1 FROM notifications
         WHERE user_id = ? AND title = ? AND message = ?
           AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
         LIMIT 1"
    );
    foreach ($userIds as $userId) {
        $existing->execute([$userId, $title, $message]);
        if (!$existing->fetchColumn()) {
            notifyUser($pdo, $userId, $title, $message, "system");
        }
    }
}

function securityAdministratorIds(PDO $pdo): array
{
    return array_map(
        "intval",
        $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN)
    );
}

function securityAssessmentTeacherIds(PDO $pdo, string $type, int $assessmentId): array
{
    if ($assessmentId < 1 || !in_array($type, ["quiz", "exam"], true)) {
        return [];
    }
    if ($type === "quiz") {
        $statement = $pdo->prepare(
            "SELECT DISTINCT ct.teacher_id
             FROM quizzes q
             INNER JOIN modules m ON m.id = q.module_id
             INNER JOIN courses c ON c.id = m.course_id
             INNER JOIN course_teachers ct ON ct.course_id = c.id
             INNER JOIN users u ON u.id = ct.teacher_id AND u.role = 'teacher' AND u.is_active = 1
             WHERE q.id = ?"
        );
    } else {
        $statement = $pdo->prepare(
            "SELECT DISTINCT ct.teacher_id
             FROM exams e
             INNER JOIN courses c ON c.id = e.course_id
             INNER JOIN course_teachers ct ON ct.course_id = c.id
             INNER JOIN users u ON u.id = ct.teacher_id AND u.role = 'teacher' AND u.is_active = 1
             WHERE e.id = ?"
        );
    }
    $statement->execute([$assessmentId]);
    return array_map("intval", $statement->fetchAll(PDO::FETCH_COLUMN));
}

function unreadNotificationCount(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $statement->execute([$userId]);
    return (int) $statement->fetchColumn();
}

function roleNotificationBell(PDO $pdo, int $userId, string $url): string
{
    $count = unreadNotificationCount($pdo, $userId);
    $badge = $count > 0
        ? '<span class="student-notif-badge">' . ($count > 9 ? "9+" : $count) . "</span>"
        : "";

    return '<a href="' . htmlspecialchars($url, ENT_QUOTES, "UTF-8")
        . '" class="active student-notif-bell" title="Notifications">🔔'
        . $badge
        . "</a>";
}
