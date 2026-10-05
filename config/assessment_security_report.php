<?php

function assessmentSecurityEventLabel(string $action): string
{
    $labels = [
        "assessment_tab_left" => "Onglet quitté",
        "assessment_fullscreen_exited" => "Plein écran quitté",
        "assessment_copy_blocked" => "Copie bloquée",
        "assessment_cut_blocked" => "Couper bloqué",
        "assessment_paste_blocked" => "Collage bloqué",
        "assessment_contextmenu_blocked" => "Menu contextuel bloqué",
        "assessment_shortcut_blocked" => "Raccourci bloqué",
        "assessment_multiple_session" => "Session multiple",
        "assessment_suspicious_ip" => "Adresse IP suspecte",
        "assessment_suspicious_device" => "Appareil suspect",
        "assessment_time_expired" => "Temps écoulé",
        "assessment_leave_warning" => "Tentative de sortie"
    ];
    return $labels[$action] ?? $action;
}

function assessmentSecurityReport(PDO $pdo, ?int $teacherId = null, string $from = "", string $to = "", string $role = "", int $studentId = 0, string $eventType = "", string $archiveMode = ""): array
{
    $where = [
        "l.action IN ('assessment_tab_left', 'assessment_fullscreen_exited',
            'assessment_copy_blocked', 'assessment_cut_blocked',
            'assessment_paste_blocked', 'assessment_contextmenu_blocked',
            'assessment_shortcut_blocked', 'assessment_multiple_session',
            'assessment_suspicious_ip', 'assessment_suspicious_device',
            'assessment_time_expired', 'assessment_leave_warning')",
        "u.role = 'student'"
    ];
    $params = [];
    if ($archiveMode === "history") {
        $where[] = "l.created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)";
    } elseif ($archiveMode === "current") {
        $where[] = "l.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
    }

    if ($teacherId !== null) {
        $where[] = "EXISTS (
            SELECT 1 FROM enrollments scope_en
            INNER JOIN courses scope_c ON scope_c.id = scope_en.course_id
            WHERE scope_en.user_id = u.id
              AND EXISTS (
                  SELECT 1
                  FROM course_teachers scope_ct
                  WHERE scope_ct.course_id = scope_c.id
                    AND scope_ct.teacher_id = :teacher_id
              )
              AND scope_en.status IN ('active', 'completed')
              AND (scope_en.expires_at IS NULL OR scope_en.expires_at >= NOW())
              AND (
                  (l.entity_type = 'quiz' AND scope_c.id = quiz_course.id)
                  OR (l.entity_type = 'exam' AND scope_c.id = exam_course.id)
              )
        )";
        $params["teacher_id"] = $teacherId;
    }
    if ($from === "__last24h") {
        $where[] = "l.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
    } elseif ($from !== "") {
        $where[] = "l.created_at >= :security_from";
        $params["security_from"] = $from . " 00:00:00";
    }
    if ($to !== "") {
        $where[] = "l.created_at <= :security_to";
        $params["security_to"] = $to . " 23:59:59";
    }
    if ($role !== "") {
        $where[] = "u.role = :security_role";
        $params["security_role"] = $role;
    }
    if ($studentId > 0) {
        $where[] = "u.id = :security_student";
        $params["security_student"] = $studentId;
    }
    if ($eventType !== "" && preg_match('/^assessment_[a-z_]+$/', $eventType)) {
        $where[] = "l.action = :security_event_type";
        $params["security_event_type"] = $eventType;
    }

    $statement = $pdo->prepare(
        "SELECT u.id AS user_id, u.first_name, u.last_name, u.email,
                l.entity_type, l.entity_id,
                COALESCE(quiz.title, exam.title, CONCAT('Évaluation #', l.entity_id)) AS assessment_title,
                COUNT(*) AS violation_count,
                MAX(l.created_at) AS latest_event,
                GROUP_CONCAT(DISTINCT l.action ORDER BY l.created_at DESC SEPARATOR ', ') AS event_types,
                GROUP_CONCAT(DISTINCT l.ip_address ORDER BY l.ip_address SEPARATOR ', ') AS ip_addresses,
                GROUP_CONCAT(DISTINCT LEFT(l.user_agent, 120) ORDER BY l.user_agent SEPARATOR ' | ') AS devices
         FROM (
             SELECT id, user_id, action, entity_type, entity_id, ip_address, user_agent, created_at FROM audit_logs
             UNION ALL
             SELECT id, user_id, action, entity_type, entity_id, ip_address, user_agent, created_at FROM audit_logs_history
         ) l
         INNER JOIN users u ON u.id = l.user_id
         LEFT JOIN quizzes quiz ON l.entity_type = 'quiz' AND quiz.id = l.entity_id
         LEFT JOIN modules quiz_module ON quiz_module.id = quiz.module_id
         LEFT JOIN courses quiz_course ON quiz_course.id = quiz_module.course_id
         LEFT JOIN exams exam ON l.entity_type = 'exam' AND exam.id = l.entity_id
         LEFT JOIN courses exam_course ON exam_course.id = exam.course_id
         WHERE " . implode(" AND ", $where) . "
         GROUP BY u.id, u.first_name, u.last_name, u.email,
                  l.entity_type, l.entity_id, quiz.title, exam.title
         ORDER BY latest_event DESC
         LIMIT 500"
    );
    $statement->execute($params);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $count = (int) $row["violation_count"];
        $events = (string) $row["event_types"];
        $highSignal = preg_match('/multiple_session|suspicious_ip|suspicious_device|time_expired/', $events);
        $row["risk"] = $highSignal || $count >= 5 ? "high" : ($count >= 3 ? "medium" : "low");
    }
    unset($row);
    return $rows;
}
