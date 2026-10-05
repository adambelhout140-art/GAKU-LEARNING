<?php
require_once __DIR__ . "/audit.php";
require_once __DIR__ . "/notifications.php";

function assessmentSecurityToken(PDO $pdo, int $userId, string $type, int $assessmentId, ?string &$error = null): string
{
    if (!in_array($type, ["quiz", "exam"], true) || $assessmentId < 1) {
        $error = "Évaluation invalide.";
        return "";
    }

    $key = "assessment_session_{$type}_{$assessmentId}";
    $token = $_SESSION[$key] ?? "";
    $statement = $pdo->prepare(
        "SELECT * FROM assessment_sessions
         WHERE user_id = ? AND assessment_type = ? AND assessment_id = ?
         LIMIT 1"
    );
    $statement->execute([$userId, $type, $assessmentId]);
    $session = $statement->fetch(PDO::FETCH_ASSOC);

    $currentIp = $_SERVER["REMOTE_ADDR"] ?? "";
    $currentUserAgent = $_SERVER["HTTP_USER_AGENT"] ?? "";

    if ($session && (!$token || !hash_equals((string) $session["session_token"], $token))) {
        $sameBrowser = (string) ($session["ip_address"] ?? "") === $currentIp
            && (string) ($session["user_agent"] ?? "") === $currentUserAgent;
        if ($sameBrowser) {
            // Recover a lost PHP session without allowing a different device to take over.
            $token = (string) $session["session_token"];
            $_SESSION[$key] = $token;
        } else {
            auditLog($pdo, $userId, "assessment_multiple_session", $type, $assessmentId);
            $error = "Cette évaluation est déjà ouverte sur un autre appareil ou navigateur.";
            return "";
        }
    }

    if (!$token) {
        $token = bin2hex(random_bytes(32));
        $_SESSION[$key] = $token;
    }

    if (!$session) {
        $insert = $pdo->prepare(
            "INSERT INTO assessment_sessions
             (user_id, assessment_type, assessment_id, session_token, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $insert->execute([
            $userId,
            $type,
            $assessmentId,
            $token,
            $currentIp ?: null,
            $currentUserAgent ?: null
        ]);
    } else {
        $update = $pdo->prepare(
            "UPDATE assessment_sessions SET last_seen_at = NOW()
             WHERE id = ? AND session_token = ?"
        );
        $update->execute([(int) $session["id"], $token]);
    }

    return $token;
}

function assessmentSecurityDraft(PDO $pdo, int $userId, string $type, int $assessmentId, string $token): array
{
    $statement = $pdo->prepare(
        "SELECT draft_answers FROM assessment_sessions
         WHERE user_id = ? AND assessment_type = ? AND assessment_id = ? AND session_token = ?
         LIMIT 1"
    );
    $statement->execute([$userId, $type, $assessmentId, $token]);
    $draft = $statement->fetchColumn();
    if (!$draft) {
        return [];
    }

    $decoded = json_decode((string) $draft, true);
    return is_array($decoded) ? $decoded : [];
}

function assessmentSecurityExpired(PDO $pdo, int $userId, string $type, int $assessmentId, string $token, int $durationMinutes): bool
{
    if ($durationMinutes < 1 || $token === "") {
        return false;
    }
    $statement = $pdo->prepare(
        "SELECT started_at FROM assessment_sessions
         WHERE user_id = ? AND assessment_type = ? AND assessment_id = ? AND session_token = ?
         LIMIT 1"
    );
    $statement->execute([$userId, $type, $assessmentId, $token]);
    $startedAt = $statement->fetchColumn();
    $expired = $startedAt && (time() - strtotime($startedAt)) > ($durationMinutes * 60);
    if ($expired && ($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
        auditLog($pdo, $userId, "assessment_time_expired", $type, $assessmentId);
    }
    if ($expired) {
        $subject = $type === "exam" ? "examen" : "quiz";
        notifySecurityUsers(
            $pdo,
            array_merge(
                securityAdministratorIds($pdo),
                securityAssessmentTeacherIds($pdo, $type, $assessmentId)
            ),
            "Évaluation expirée",
            "Le temps du {$subject} #{$assessmentId} est écoulé pour un étudiant."
        );
    }
    return (bool) $expired;
}
