<?php
/**
 * Shared access helpers for student learning pages.
 *
 * Every student-facing content query still applies the enrollment condition
 * in SQL; these helpers keep the authentication and CSRF checks consistent.
 */
function studentRequireAuth()
{
    if (!isset($_SESSION["user_id"])) {
        $scriptName = str_replace("\\", "/", (string) ($_SERVER["SCRIPT_NAME"] ?? ""));
        $studentPath = strpos($scriptName, "/student/");
        $applicationPath = $studentPath === false ? "" : substr($scriptName, 0, $studentPath);
        header("Location: " . ($applicationPath ?: "") . "/auth/login.php");
        exit;
    }

    if (($_SESSION["user_role"] ?? "") !== "student") {
        http_response_code(403);
        exit("Accès interdit.");
    }

    // Check the database token on every protected student request. This makes
    // a second login immediately invalidate the older browser session.
    global $pdo;
    $sessionStatement = $pdo->prepare(
        "SELECT active_session_token FROM users WHERE id = ? AND role = 'student' AND is_active = 1 LIMIT 1"
    );
    $sessionStatement->execute([(int) $_SESSION["user_id"]]);
    $activeToken = $sessionStatement->fetchColumn();

    if (
        !$activeToken
        || empty($_SESSION["student_session_token"])
        || !hash_equals((string) $activeToken, (string) $_SESSION["student_session_token"])
    ) {
        // Redirect silently so the old session cannot see protected content or
        // learn whether it was replaced by another login.
        session_unset();
        session_destroy();
        header("Location: ../auth/login.php");
        exit;
    }
}

function studentEnsureCsrf()
{
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["csrf_token"];
}

function studentCheckCsrf()
{
    return !empty($_SESSION["csrf_token"])
        && hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "");
}

function studentEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function studentLessonHtml($value)
{
    $html = trim((string) $value);

    if ($html === "") {
        return "";
    }

    $document = new DOMDocument("1.0", "UTF-8");
    $previousErrors = libxml_use_internal_errors(true);

    $document->loadHTML(
        '<?xml encoding="UTF-8"><div id="lesson-content">' . $html . "</div>",
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );

    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);

    $root = $document->getElementById("lesson-content");

    if (!$root) {
        return nl2br(studentEscape($html));
    }

    $allowedTags = [
        "a",
        "b",
        "blockquote",
        "br",
        "code",
        "em",
        "h1",
        "h2",
        "h3",
        "h4",
        "h5",
        "h6",
        "i",
        "li",
        "ol",
        "p",
        "pre",
        "strong",
        "table",
        "tbody",
        "td",
        "tfoot",
        "th",
        "thead",
        "tr",
        "u",
        "ul"
    ];

    $allowedAttributes = ["href", "title"];

    $sanitize = function (DOMNode $node) use (
        &$sanitize,
        $allowedTags,
        $allowedAttributes
    ) {
        for ($child = $node->firstChild; $child; ) {
            $next = $child->nextSibling;

            if ($child instanceof DOMElement) {
                $tagName = strtolower($child->tagName);

                if (in_array(
                    $tagName,
                    ["script", "style", "iframe", "object", "embed", "form"],
                    true
                )) {
                    $node->removeChild($child);
                    $child = $next;
                    continue;
                }

                if (!in_array($tagName, $allowedTags, true)) {
                    $sanitize($child);

                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }

                    $node->removeChild($child);
                    $child = $next;
                    continue;
                }

                for ($index = $child->attributes->length - 1; $index >= 0; $index--) {
                    $attribute = $child->attributes->item($index);

                    if (!$attribute instanceof DOMAttr) {
                        continue;
                    }

                    if (!in_array(
                        strtolower($attribute->name),
                        $allowedAttributes,
                        true
                    )) {
                        $child->removeAttribute($attribute->name);
                    }
                }

                if ($tagName === "a") {
                    $href = trim($child->getAttribute("href"));

                    if (
                        $href !== ""
                        && !preg_match("/^(https?:|mailto:|#)/i", $href)
                    ) {
                        $child->removeAttribute("href");
                    }
                }
            }

            $sanitize($child);
            $child = $next;
        }
    };

    $sanitize($root);

    return substr(
        $document->saveHTML($root),
        strlen('<div id="lesson-content">'),
        -6
    );
}

/**
 * Small bell link for the student navbar, showing the unread notification
 * count. Kept as a helper so every student page renders it identically.
 */
function studentNotificationBell(
    PDO $pdo,
    int $userId,
    string $notifUrl
): string {
    $statement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM notifications
         WHERE user_id = ? AND is_read = 0"
    );

    $statement->execute([$userId]);

    $count = (int) $statement->fetchColumn();

    $badge = $count > 0
        ? '<span class="student-notif-badge">'
            . ($count > 9 ? "9+" : (string) $count)
            . "</span>"
        : "";

    return '<a href="'
        . studentEscape($notifUrl)
        . '" class="student-notif-bell" title="Notifications">🔔'
        . $badge
        . "</a>";
}

/**
 * Builds a per-question answer-review structure for a finished quiz/exam
 * attempt, using the already-stored attempt_answers rows (no re-grading).
 * When $revealCorrect is false, correct answers are withheld and only the
 * student's own correctness is shown — used while retakes are still
 * possible, to avoid making it trivial to share answers with classmates.
 */
function studentBuildAnswerReview(
    PDO $pdo,
    array $questions,
    array $answersByQuestion,
    int $attemptId,
    string $attemptColumn,
    bool $revealCorrect
): array {
    $statement = $pdo->prepare(
        "SELECT question_id, answer_id, answer_text, is_correct
         FROM attempt_answers
         WHERE $attemptColumn = ?"
    );

    $statement->execute([$attemptId]);

    $selectedByQuestion = [];

    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $selectedByQuestion[$row["question_id"]][] = $row;
    }

    $review = [];

    foreach ($questions as $question) {
        $questionId = (int) $question["id"];
        $selected = $selectedByQuestion[$questionId] ?? [];

        $selectedOptionIds = array_filter(
            array_column($selected, "answer_id")
        );

        $questionCorrect = $selected !== []
            && !array_filter($selected, function ($row) {
                return !$row["is_correct"];
            });

        $selectedText = trim(
            (string) ($selected[0]["answer_text"] ?? "")
        );

        $options = [];

        foreach ($answersByQuestion[$questionId] ?? [] as $option) {
            $options[] = [
                "text" => $option["answer_text"],
                "was_selected" => in_array(
                    (int) $option["id"],
                    array_map("intval", $selectedOptionIds),
                    true
                ),
                "is_correct" => $revealCorrect
                    ? (bool) $option["is_correct"]
                    : null,
            ];
        }

        $review[] = [
            "question_id" => $questionId,
            "question_text" => $question["question_text"],
            "question_type" => $question["question_type"],
            "explanation" => $question["explanation"] ?? "",
            "is_correct" => $questionCorrect,
            "selected_text" => $selectedText,
            "options" => $options,
        ];
    }

    return $review;
}

function studentCourseForUser(PDO $pdo, $courseId, $userId)
{
    $statement = $pdo->prepare(
        "SELECT c.*, e.status AS enrollment_status, e.enrolled_at
         FROM courses c
         INNER JOIN enrollments e ON e.course_id = c.id
         WHERE c.id = ?
           AND e.user_id = ?
           AND c.status = 'published'
           AND e.status IN ('active', 'completed')
           AND (
               e.expires_at IS NULL
               OR e.expires_at >= NOW()
           )
         LIMIT 1"
    );

    $statement->execute([
        (int) $courseId,
        (int) $userId
    ]);

    return $statement->fetch(PDO::FETCH_ASSOC);
}

function studentVideoEmbedUrl($url)
{
    $parts = parse_url(trim((string) $url));

    if (!$parts || empty($parts["host"])) {
        return "";
    }

    $host = strtolower($parts["host"]);
    $path = trim($parts["path"] ?? "", "/");

    $query = [];
    parse_str($parts["query"] ?? "", $query);

    $videoId = "";

    if (strpos($host, "youtu.be") !== false) {
        $videoId = explode("/", $path)[0];
    } elseif (strpos($host, "youtube.com") !== false) {
        if (strpos($path, "embed/") === 0) {
            $videoId = substr($path, 6);
        } elseif (strpos($path, "shorts/") === 0) {
            $videoId = substr($path, 7);
        } else {
            $videoId = $query["v"] ?? "";
        }

        $videoId = explode("/", $videoId)[0];
    } elseif (strpos($host, "vimeo.com") !== false) {
        $videoId = preg_replace(
            "/[^0-9].*$/",
            "",
            basename($path)
        );

        return $videoId !== ""
            ? "https://player.vimeo.com/video/"
                . rawurlencode($videoId)
            : "";
    }

    return preg_match(
        "/^[A-Za-z0-9_-]{6,}$/",
        $videoId
    )
        ? "https://www.youtube.com/embed/"
            . rawurlencode($videoId)
        : "";
}