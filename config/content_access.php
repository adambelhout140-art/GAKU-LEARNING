<?php
// ! CONTENT AUTHORIZATION HELPER
// * Admins can manage all course content; teachers can manage only their assigned courses.
function canManageCourse(PDO $pdo, int $courseId, int $userId, string $role): bool
{
    if ($role === "admin") {
        return true;
    }

    if ($role !== "teacher") {
        return false;
    }

    $statement = $pdo->prepare(
        "SELECT c.id
         FROM courses c
         LEFT JOIN course_teachers ct ON ct.course_id = c.id AND ct.teacher_id = ?
         WHERE c.id = ? AND (c.teacher_id = ? OR ct.teacher_id IS NOT NULL)
         LIMIT 1"
    );
    $statement->execute([$userId, $courseId, $userId]);
    return (bool) $statement->fetchColumn();
}

function canManageModule(PDO $pdo, int $moduleId, int $userId, string $role): bool
{
    if ($role === "admin") {
        return true;
    }

    $statement = $pdo->prepare(
        "SELECT modules.id
         FROM modules
         INNER JOIN courses ON courses.id = modules.course_id
         LEFT JOIN course_teachers ct ON ct.course_id = courses.id AND ct.teacher_id = ?
         WHERE modules.id = ? AND (courses.teacher_id = ? OR ct.teacher_id IS NOT NULL)
         LIMIT 1"
    );
    $statement->execute([$userId, $moduleId, $userId]);
    return $role === "teacher" && (bool) $statement->fetchColumn();
}

function canManageLesson(PDO $pdo, int $lessonId, int $userId, string $role): bool
{
    if ($role === "admin") {
        return true;
    }

    $statement = $pdo->prepare(
        "SELECT lessons.id
         FROM lessons
         INNER JOIN modules ON modules.id = lessons.module_id
         INNER JOIN courses ON courses.id = modules.course_id
         LEFT JOIN course_teachers ct ON ct.course_id = courses.id AND ct.teacher_id = ?
         WHERE lessons.id = ? AND (courses.teacher_id = ? OR ct.teacher_id IS NOT NULL)
         LIMIT 1"
    );
    $statement->execute([$userId, $lessonId, $userId]);
    return $role === "teacher" && (bool) $statement->fetchColumn();
}
