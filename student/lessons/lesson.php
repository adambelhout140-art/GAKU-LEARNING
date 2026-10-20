<?php
require_once __DIR__ . '/../../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../../config/database.php";
require_once "../../config/student_access.php";
studentRequireAuth();
studentEnsureCsrf();

$userId = (int) $_SESSION["user_id"];
$lessonId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
$lessonStatement = $pdo->prepare(
    "SELECT l.*, m.id AS module_id, m.title AS module_title, c.id AS course_id, c.title AS course_title
     FROM lessons l
     INNER JOIN modules m ON m.id = l.module_id
     INNER JOIN courses c ON c.id = m.course_id
     INNER JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
     WHERE l.id = ? AND l.is_published = 1 AND c.status = 'published'
       AND e.status IN ('active', 'completed')
       AND (e.expires_at IS NULL OR e.expires_at >= NOW())
     LIMIT 1"
);
$lessonStatement->execute([$userId, $lessonId ?: 0]);
$lesson = $lessonStatement->fetch(PDO::FETCH_ASSOC);
if (!$lesson) {
    http_response_code(404);
    exit("Leçon introuvable ou accès non autorisé.");
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS lesson_material_views (
        user_id INT UNSIGNED NOT NULL,
        material_id INT UNSIGNED NOT NULL,
        page_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        opened_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_viewed_at TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (user_id, material_id),
        KEY idx_lesson_material_views_material (material_id),
        CONSTRAINT fk_lesson_material_views_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
        CONSTRAINT fk_lesson_material_views_material FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS lesson_material_page_views (
        user_id INT UNSIGNED NOT NULL,
        material_id INT UNSIGNED NOT NULL,
        page_number SMALLINT UNSIGNED NOT NULL,
        viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, material_id, page_number),
        KEY idx_lesson_material_page_views_material (material_id),
        CONSTRAINT fk_lesson_material_page_views_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
        CONSTRAINT fk_lesson_material_page_views_material FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$materialsStatement = $pdo->prepare(
    "SELECT id, title, type, content, file_path, file_name, file_size, mime_type, external_url
     FROM materials WHERE lesson_id = ? ORDER BY position, id"
);
$materialsStatement->execute([$lessonId]);
$materials = $materialsStatement->fetchAll(PDO::FETCH_ASSOC);
$pdfMaterials = array_values(array_filter($materials, static function (array $material): bool {
    return $material["type"] === "file" && strtolower((string) $material["mime_type"]) === "application/pdf";
}));

// Per-lesson comprehension check
$checkQuestionStatement = $pdo->prepare(
    "SELECT id, question_text, position FROM lesson_check_questions WHERE lesson_id = ? ORDER BY position, id"
);
$checkQuestionStatement->execute([$lessonId]);
$checkQuestions = $checkQuestionStatement->fetchAll(PDO::FETCH_ASSOC);
$checkOptionsByQuestion = [];
$checkAnswersByQuestion = [];
if ($checkQuestions) {
    $checkQuestionIds = array_column($checkQuestions, "id");
    $placeholders = implode(",", array_fill(0, count($checkQuestionIds), "?"));
    $optionStatement = $pdo->prepare(
        "SELECT id, question_id, option_text, is_correct FROM lesson_check_options
         WHERE question_id IN ($placeholders) ORDER BY position, id"
    );
    $optionStatement->execute($checkQuestionIds);
    foreach ($optionStatement->fetchAll(PDO::FETCH_ASSOC) as $option) {
        $checkOptionsByQuestion[$option["question_id"]][] = $option;
    }
    $answerStatement = $pdo->prepare(
        "SELECT question_id, is_correct FROM lesson_check_answers
         WHERE user_id = ? AND question_id IN ($placeholders)"
    );
    $answerStatement->execute(array_merge([$userId], $checkQuestionIds));
    foreach ($answerStatement->fetchAll(PDO::FETCH_ASSOC) as $answer) {
        $checkAnswersByQuestion[$answer["question_id"]] = (bool) $answer["is_correct"];
    }
}
$checksRequired = count($checkQuestions) > 0;
$checksAllCorrect = true;
foreach ($checkQuestions as $checkQuestion) {
    if (empty($checkAnswersByQuestion[$checkQuestion["id"]])) {
        $checksAllCorrect = false;
        break;
    }
}

$documentViewStatus = [];
if ($pdfMaterials) {
    $viewStatement = $pdo->prepare(
        "SELECT v.material_id, v.page_count, v.opened_at,
                COUNT(p.page_number) AS pages_viewed,
                MAX(p.viewed_at) AS last_page_viewed_at
         FROM lesson_material_views v
         LEFT JOIN lesson_material_page_views p
           ON p.user_id = v.user_id AND p.material_id = v.material_id
         WHERE v.user_id = ? AND v.material_id IN (" . implode(",", array_fill(0, count($pdfMaterials), "?")) . ")
         GROUP BY v.material_id, v.page_count, v.opened_at"
    );
    $viewStatement->execute(array_merge([$userId], array_column($pdfMaterials, "id")));
    foreach ($viewStatement->fetchAll(PDO::FETCH_ASSOC) as $view) {
        $documentViewStatus[(int) $view["material_id"]] = [
            "opened" => true,
            "page_count" => (int) $view["page_count"],
            "pages_viewed" => (int) $view["pages_viewed"],
            "viewed" => (int) $view["page_count"] > 0
                && (int) $view["pages_viewed"] >= (int) $view["page_count"],
            "pages_seen" => [],
            "last_viewed_at" => $view["last_page_viewed_at"],
        ];
    }
    $pageStatement = $pdo->prepare(
        "SELECT material_id, page_number
         FROM lesson_material_page_views
         WHERE user_id = ? AND material_id IN (" . implode(",", array_fill(0, count($pdfMaterials), "?")) . ")"
    );
    $pageStatement->execute(array_merge([$userId], array_column($pdfMaterials, "id")));
    foreach ($pageStatement->fetchAll(PDO::FETCH_ASSOC) as $pageView) {
        $documentViewStatus[(int) $pageView["material_id"]]["pages_seen"][] = (int) $pageView["page_number"];
    }
}
$allDocumentsViewed = !$pdfMaterials;
if ($pdfMaterials) {
    $allDocumentsViewed = true;
    foreach ($pdfMaterials as $pdfMaterial) {
        if (empty($documentViewStatus[(int) $pdfMaterial["id"]]["viewed"])) {
            $allDocumentsViewed = false;
            break;
        }
    }
}
$quizPassed = $checksRequired && $checksAllCorrect;

$noteStatement = $pdo->prepare("SELECT note FROM lesson_notes WHERE user_id = ? AND lesson_id = ? LIMIT 1");
$noteStatement->execute([$userId, $lessonId]);
$existingNote = (string) $noteStatement->fetchColumn();

$bookmarkStatement = $pdo->prepare("SELECT id FROM bookmarks WHERE user_id = ? AND lesson_id = ? LIMIT 1");
$bookmarkStatement->execute([$userId, $lessonId]);
$lessonBookmarked = (bool) $bookmarkStatement->fetchColumn();

// Background AJAX endpoint (Notes, comprehension checks, auto-progress)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajax"])) {
    header("Content-Type: application/json");

    if (!studentCheckCsrf()) {
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "csrf"]);
        exit;
    }

    $ajaxType = $_POST["ajax"];

    if ($ajaxType === "note") {
        $note = trim((string) ($_POST["note"] ?? ""));
        if ($note === "" || $note === "<br>") {
            $pdo->prepare("DELETE FROM lesson_notes WHERE user_id = ? AND lesson_id = ?")->execute([$userId, $lessonId]);
        } else {
            $save = $pdo->prepare(
                "INSERT INTO lesson_notes (user_id, lesson_id, note) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE note = VALUES(note)"
            );
            $save->execute([$userId, $lessonId, $note]);
        }
        echo json_encode(["ok" => true]);
        exit;
    }

    if ($ajaxType === "check") {
        $questionId = filter_input(INPUT_POST, "question_id", FILTER_VALIDATE_INT);
        $optionId = filter_input(INPUT_POST, "option_id", FILTER_VALIDATE_INT);
        $questionCheck = $pdo->prepare("SELECT id FROM lesson_check_questions WHERE id = ? AND lesson_id = ? LIMIT 1");
        $questionCheck->execute([$questionId, $lessonId]);
        if (!$questionId || !$optionId || !$questionCheck->fetchColumn()) {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "invalid"]);
            exit;
        }
        $optionCheck = $pdo->prepare("SELECT is_correct FROM lesson_check_options WHERE id = ? AND question_id = ? LIMIT 1");
        $optionCheck->execute([$optionId, $questionId]);
        $isCorrect = (bool) $optionCheck->fetchColumn();
        $save = $pdo->prepare(
            "INSERT INTO lesson_check_answers (user_id, question_id, is_correct, answered_at) VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE is_correct = VALUES(is_correct), answered_at = NOW()"
        );
        $save->execute([$userId, $questionId, $isCorrect ? 1 : 0]);
        echo json_encode(["ok" => true, "correct" => $isCorrect]);
        exit;
    }

    if ($ajaxType === "document_opened") {
        $materialId = filter_input(INPUT_POST, "material_id", FILTER_VALIDATE_INT);
        $pageCount = filter_input(INPUT_POST, "page_count", FILTER_VALIDATE_INT);
        $materialCheck = $pdo->prepare(
            "SELECT id FROM materials
             WHERE id = ? AND lesson_id = ? AND type = 'file' AND mime_type = 'application/pdf'
             LIMIT 1"
        );
        $materialCheck->execute([$materialId ?: 0, $lessonId]);
        if (!$materialId || $pageCount < 1 || $pageCount > 1000 || !$materialCheck->fetchColumn()) {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "invalid_document"]);
            exit;
        }

        $existingDocument = $pdo->prepare(
            "SELECT page_count FROM lesson_material_views WHERE user_id = ? AND material_id = ? LIMIT 1"
        );
        $existingDocument->execute([$userId, $materialId]);
        $oldPageCount = $existingDocument->fetchColumn();
        $resetProgress = $oldPageCount !== false && (int) $oldPageCount !== $pageCount;
        if ($resetProgress) {
            $pdo->prepare(
                "DELETE FROM lesson_material_page_views WHERE user_id = ? AND material_id = ?"
            )->execute([$userId, $materialId]);
        }
        $saveOpened = $pdo->prepare(
            "INSERT INTO lesson_material_views (user_id, material_id, page_count, opened_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                opened_at = IF(page_count <> VALUES(page_count), NOW(), opened_at),
                last_viewed_at = IF(page_count <> VALUES(page_count), NULL, last_viewed_at),
                page_count = VALUES(page_count)"
        );
        $saveOpened->execute([$userId, $materialId, $pageCount]);
        echo json_encode(["ok" => true, "reset" => $resetProgress]);
        exit;
    }

    if ($ajaxType === "document_page_view") {
        $materialId = filter_input(INPUT_POST, "material_id", FILTER_VALIDATE_INT);
        $pageNumber = filter_input(INPUT_POST, "page_number", FILTER_VALIDATE_INT);
        $pageCount = filter_input(INPUT_POST, "page_count", FILTER_VALIDATE_INT);
        $materialCheck = $pdo->prepare(
            "SELECT id FROM materials
             WHERE id = ? AND lesson_id = ? AND type = 'file' AND mime_type = 'application/pdf'
             LIMIT 1"
        );
        $materialCheck->execute([$materialId ?: 0, $lessonId]);
        if (
            !$materialId
            || $pageNumber < 1
            || $pageCount < 1
            || $pageCount > 1000
            || $pageNumber > $pageCount
            || !$materialCheck->fetchColumn()
        ) {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "invalid_document_page"]);
            exit;
        }

        $storedView = $pdo->prepare(
            "SELECT page_count FROM lesson_material_views WHERE user_id = ? AND material_id = ? LIMIT 1"
        );
        $storedView->execute([$userId, $materialId]);
        $storedPageCount = $storedView->fetchColumn();
        if ($storedPageCount === false || (int) $storedPageCount !== $pageCount) {
            http_response_code(409);
            echo json_encode(["ok" => false, "error" => "document_not_opened"]);
            exit;
        }

        $savePage = $pdo->prepare(
            "INSERT IGNORE INTO lesson_material_page_views (user_id, material_id, page_number)
             VALUES (?, ?, ?)"
        );
        $savePage->execute([$userId, $materialId, $pageNumber]);
        $pdo->prepare(
            "UPDATE lesson_material_views SET last_viewed_at = NOW() WHERE user_id = ? AND material_id = ?"
        )->execute([$userId, $materialId]);

        $viewCounts = $pdo->prepare(
            "SELECT v.page_count, COUNT(p.page_number) AS pages_viewed
             FROM lesson_material_views v
             LEFT JOIN lesson_material_page_views p
               ON p.user_id = v.user_id AND p.material_id = v.material_id
             WHERE v.user_id = ? AND v.material_id = ?
             GROUP BY v.page_count"
        );
        $viewCounts->execute([$userId, $materialId]);
        $viewCount = $viewCounts->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            "ok" => true,
            "viewed" => (int) $viewCount["pages_viewed"] >= (int) $viewCount["page_count"],
            "pages_viewed" => (int) $viewCount["pages_viewed"],
            "page_count" => (int) $viewCount["page_count"],
        ]);
        exit;
    }

    $percent = filter_input(INPUT_POST, "progress_percent", FILTER_VALIDATE_INT);
    $position = filter_input(INPUT_POST, "last_position", FILTER_VALIDATE_INT);
    $timeSpent = filter_input(INPUT_POST, "time_spent", FILTER_VALIDATE_INT);
    $completed = ($_POST["completed"] ?? "0") === "1" ? 1 : 0;
    $percent = ($percent === false || $percent === null) ? 0 : max(0, min(100, $percent));
    $position = ($position === false || $position === null) ? 0 : max(0, $position);
    $timeSpent = ($timeSpent === false || $timeSpent === null) ? 0 : max(0, min(86400, $timeSpent));

    $existingStatement = $pdo->prepare(
        "SELECT progress_percent, completed, total_time_seconds FROM lesson_progress WHERE user_id = ? AND lesson_id = ? LIMIT 1"
    );
    $existingStatement->execute([$userId, $lessonId]);
    $existing = $existingStatement->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $percent = max($percent, (int) $existing["progress_percent"]);
        $completed = $completed || (int) $existing["completed"];
    }
    $hasCompletionEvidence = !$pdfMaterials || $allDocumentsViewed || $quizPassed;
    $checksBlockCompletion = $checksRequired && !$checksAllCorrect && (!$pdfMaterials || !$allDocumentsViewed);
    $documentsBlockCompletion = $pdfMaterials && !$allDocumentsViewed && !$quizPassed;
    if ($completed && (!$hasCompletionEvidence || $checksBlockCompletion || $documentsBlockCompletion)) {
        $completed = 0;
        $percent = min($percent, 99);
    }
    if ($completed) {
        $percent = 100;
    }

    $save = $pdo->prepare(
        "INSERT INTO lesson_progress
            (user_id, lesson_id, completed, progress_percent, last_position, total_time_seconds, started_at, completed_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
         ON DUPLICATE KEY UPDATE
            completed = VALUES(completed),
            progress_percent = VALUES(progress_percent),
            last_position = VALUES(last_position),
            total_time_seconds = GREATEST(total_time_seconds, VALUES(total_time_seconds)),
            started_at = COALESCE(started_at, VALUES(started_at)),
            completed_at = IF(
                VALUES(completed) = 1,
                COALESCE(completed_at, VALUES(completed_at)),
                NULL
            )"
    );
    $save->execute([$userId, $lessonId, $completed, $percent, $position, $timeSpent, $completed ? date("Y-m-d H:i:s") : null]);
    echo json_encode([
        "ok" => true,
        "progress_percent" => $percent,
        "completed" => (bool) $completed,
        "checks_blocking" => $checksBlockCompletion,
        "documents_blocking" => (bool) $documentsBlockCompletion,
        "documents_viewed" => $allDocumentsViewed,
        "quiz_passed" => $quizPassed,
    ]);
    exit;
}

$progressStatement = $pdo->prepare("SELECT * FROM lesson_progress WHERE user_id = ? AND lesson_id = ? LIMIT 1");
$progressStatement->execute([$userId, $lessonId]);
$progress = $progressStatement->fetch(PDO::FETCH_ASSOC) ?: [
    "completed" => 0,
    "progress_percent" => 0,
    "last_position" => 0
];
if (($pdfMaterials && !$allDocumentsViewed && !$quizPassed) || ($checksRequired && !$checksAllCorrect && !$pdfMaterials)) {
    $progress["completed"] = 0;
    $progress["progress_percent"] = min(99, (int) $progress["progress_percent"]);
}

$wordCount = !empty($lesson["content"]) ? str_word_count(strip_tags($lesson["content"])) : 0;
foreach ($materials as $material) {
    if ($material["type"] === "text" && !empty($material["content"])) {
        $wordCount += str_word_count(strip_tags($material["content"]));
    }
}
$readingMinutesEstimate = $wordCount > 0 ? max(1, (int) round($wordCount / 200)) : 1;
$estimatedMinutes = (int) ($lesson["estimated_minutes"] ?? 0);
$requiredMinutes = $estimatedMinutes > 0 ? $estimatedMinutes : $readingMinutesEstimate;
$requiredActiveSeconds = (int) round($requiredMinutes * 60 * 0.6);

$navigationStatement = $pdo->prepare(
    "SELECT l.id, l.title FROM lessons l INNER JOIN modules m ON m.id = l.module_id
     WHERE m.course_id = ? AND l.is_published = 1 ORDER BY m.position, l.position, l.id"
);
$navigationStatement->execute([$lesson["course_id"]]);
$navigation = $navigationStatement->fetchAll(PDO::FETCH_ASSOC);
$currentIndex = 0;
foreach ($navigation as $index => $item) {
    if ((int) $item["id"] === (int) $lessonId) {
        $currentIndex = $index;
        break;
    }
}
$previous = $navigation[$currentIndex - 1] ?? null;
$next = $navigation[$currentIndex + 1] ?? null;
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= studentEscape($lesson["title"]) ?> - Formation</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">
    <link rel="stylesheet" href="../../assets/css/style.css?v=20261020-pdf-zoom">
    <style>
        /* Floating Left Notes Sidebar - Word / Notion Rich Text Style */
        .floating-notes-sidebar {
            position: fixed;
            top: 80px;
            left: 0;
            bottom: 24px;
            z-index: 990;
            display: flex;
            align-items: stretch;
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            filter: drop-shadow(0 20px 25px rgba(16, 42, 67, 0.2));
        }

        .floating-notes-sidebar.collapsed {
            transform: translateX(-340px);
        }

        .floating-notes-panel {
            width: 340px;
            height: 100%;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(191, 219, 254, 0.9);
            border-left: none;
            border-radius: 0 16px 16px 0;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            overflow: hidden;
            transition: background 0.3s ease, border-color 0.3s ease;
        }

        /* Header */
        .floating-notes-header {
            padding: 14px 18px;
            background: linear-gradient(135deg, #123b66 0%, #2563eb 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .floating-notes-header h3 {
            margin: 0;
            font-size: 0.92rem;
            font-weight: 600;
            letter-spacing: -0.01em;
            color: #f8fafc;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .floating-notes-status {
            font-size: 0.72rem;
            font-weight: 500;
            color: #bae6fd;
            background: rgba(14, 165, 233, 0.2);
            padding: 3px 8px;
            border-radius: 12px;
            transition: all 0.2s ease;
        }

        .floating-notes-meta {
            display: flex;
            align-items: flex-end;
            flex-direction: column;
            gap: 3px;
        }

        .floating-notes-count {
            color: #dbeafe;
            font-size: 0.65rem;
            white-space: nowrap;
        }

        /* Word Mini-Toolbar */
        .floating-notes-toolbar {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 6px 10px;
            background: #eff6ff;
            border-bottom: 1px solid #dbeafe;
            flex-wrap: wrap;
        }

        .floating-notes-toolbar button {
            background: transparent;
            border: none;
            border-radius: 4px;
            padding: 4px 8px;
            font-size: 0.82rem;
            font-weight: 600;
            color: #1e4b78;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 26px;
            height: 26px;
        }

        .floating-notes-toolbar button:hover {
            background: #dbeafe;
            color: #123b66;
        }

        .floating-notes-toolbar button[data-note-action] {
            color: #2563eb;
            font-size: 0.72rem;
        }

        .floating-notes-toolbar .toolbar-divider {
            width: 1px;
            height: 16px;
            background: #cbd5e1;
            margin: 0 2px;
        }

        /* Rich ContentEditable Editor */
        .floating-notes-body {
            flex: 1;
            padding: 12px;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }

        .lesson-notes-editor {
            width: 100%;
            height: 100%;
            flex: 1;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 0.88rem;
            line-height: 1.6;
            box-sizing: border-box;
            outline: none;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fbff;
            color: #102a43;
            overflow-y: auto;
            transition: all 0.2s ease;
        }

        .lesson-notes-editor:focus {
            border-color: #2563eb;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
        }

        /* Placeholder for contenteditable */
        .lesson-notes-editor:empty:before {
            content: attr(data-placeholder);
            color: #94a3b8;
            pointer-events: none;
            display: block;
        }

        .lesson-notes-editor ul, 
        .lesson-notes-editor ol {
            padding-left: 20px;
            margin: 6px 0;
        }

        /* Toggle Button */
        .floating-notes-toggle {
            width: 36px;
            height: 52px;
            align-self: center;
            margin-left: -1px;
            background: #123b66;
            color: #f8fafc;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-left: none;
            border-radius: 0 12px 12px 0;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            transition: all 0.2s ease;
        }

        .floating-notes-toggle:hover {
            background: #1d4ed8;
            color: #ffffff;
            width: 40px;
        }

        /* Dark Theme Adaptation */
        body:not(.lesson-light-theme) .floating-notes-panel {
            background: rgba(15, 23, 42, 0.95);
            border-color: rgba(51, 65, 85, 0.7);
        }

        body:not(.lesson-light-theme) .floating-notes-header {
            background: linear-gradient(135deg, #020617 0%, #0f172a 100%);
        }

        body:not(.lesson-light-theme) .floating-notes-toolbar {
            background: #1e293b;
            border-bottom-color: #334155;
        }

        body:not(.lesson-light-theme) .floating-notes-toolbar button {
            color: #94a3b8;
        }

        body:not(.lesson-light-theme) .floating-notes-toolbar button:hover {
            background: #334155;
            color: #f8fafc;
        }

        body:not(.lesson-light-theme) .floating-notes-toolbar button[data-note-action] {
            color: #7dd3fc;
        }

        body:not(.lesson-light-theme) .floating-notes-toolbar .toolbar-divider {
            background: #334155;
        }

        body:not(.lesson-light-theme) .lesson-notes-editor {
            background: rgba(30, 41, 59, 0.5);
            color: #f1f5f9;
            border-color: #334155;
        }

        body:not(.lesson-light-theme) .lesson-notes-editor:focus {
            border-color: #38bdf8;
            background: #1e293b;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.18);
        }

        @media (max-width: 768px) {
            .floating-notes-panel {
                width: 280px;
            }
            .floating-notes-sidebar.collapsed {
                transform: translateX(-280px);
            }
        }
    </style>
</head>

<body class="student-body student-lesson-body">
    <header class="student-navbar">
        <a href="../../index.php" class="student-brand"><img src="<?= htmlspecialchars(platformLogoUrl($pdo, "light-bg"), ENT_QUOTES, "UTF-8") ?>" alt="<?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?>" class="brand-logo" width="42" height="42"> <?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></a>
        <nav>
            <a href="../dashboard.php">Tableau de bord</a>
            <a href="../courses/course.php?id=<?= (int) $lesson["course_id"] ?>">Ma formation</a>
            <a href="../certificates/certificates.php">Mes certificats</a>
            <a href="../results.php">Mes résultats</a>
            <a href="../bookmarks.php">Mes favoris</a>
            <a href="../messages.php">Messages</a>
            <a href="../account.php">Mon compte</a>
            <?= studentNotificationBell($pdo, $userId, "../notifications.php") ?>
            <button type="button" class="student-theme-toggle" id="theme-toggle" aria-label="Activer le thème clair">☀ï¸ Clair</button>
            <a href="../../auth/logout.php">Déconnexion</a>
        </nav>
    </header>

    <!-- Floating Left Notes Sidebar (Word / Notion Style) -->
    <aside class="floating-notes-sidebar collapsed" id="notes-sidebar" aria-label="Notes de cours">
        <div class="floating-notes-panel">
            <div class="floating-notes-header">
                <h3><span>📝</span> Mes Notes</h3>
                <div class="floating-notes-meta">
                    <span class="floating-notes-count" id="lesson-note-count">0 mots · 0 caractères</span>
                    <span class="floating-notes-status" id="lesson-note-status">Prêt</span>
                </div>
            </div>
            
            <!-- Word-style Mini Toolbar -->
            <div class="floating-notes-toolbar" id="notes-toolbar">
                <button type="button" data-command="bold" title="Gras (Ctrl+B)"><b>B</b></button>
                <button type="button" data-command="italic" title="Italique (Ctrl+I)"><i>I</i></button>
                <button type="button" data-command="underline" title="Souligné (Ctrl+U)"><u style="text-decoration: underline;">U</u></button>
                <span class="toolbar-divider"></span>
                <button type="button" data-command="insertUnorderedList" title="Liste à puces">• Liste</button>
                <button type="button" data-command="insertOrderedList" title="Liste numérotée">1. Liste</button>
                <span class="toolbar-divider"></span>
                <button type="button" data-command="hiliteColor" data-value="#fef08a" title="Surligner">🖍️</button>
                <button type="button" data-command="removeFormat" title="Effacer le formatage">🧹</button>
                <span class="toolbar-divider"></span>
                <button type="button" data-note-action="copy" title="Copier le contenu">Copier</button>
                <button type="button" data-note-action="clear" title="Effacer les notes">Effacer</button>
            </div>

            <div class="floating-notes-body">
                <!-- Rich Text ContentEditable Editor -->
                <div id="lesson-note" class="lesson-notes-editor" contenteditable="true" data-placeholder="Notez ici vos remarques et points clés..."><?= $existingNote ?></div>
            </div>
        </div>
        <button type="button" class="floating-notes-toggle" id="notes-toggle-btn" aria-label="Afficher/Masquer les notes" title="Notes de cours">›</button>
    </aside>

    <main class="student-dashboard">
        <p class="student-eyebrow"><a href="../courses/course.php?id=<?= (int) $lesson["course_id"] ?>"><?= studentEscape($lesson["course_title"]) ?></a> / <?= studentEscape($lesson["module_title"]) ?></p>
        <section class="student-section lesson-view">
            <div class="student-section-heading">
                <div>
                    <h1><?= studentEscape($lesson["title"]) ?></h1>
                    <p><?= studentEscape($lesson["description"] ?? "") ?></p>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <button type="button" class="student-bookmark-star" id="lesson-bookmark-star" data-bookmarked="<?= $lessonBookmarked ? 1 : 0 ?>" title="Ajouter aux favoris"><?= $lessonBookmarked ? "★" : "☆" ?></button>
                    <?php if ((int) $progress["completed"]): ?><span class="student-course-status">Terminée</span><?php endif; ?>
                </div>
            </div>
            <?php if ($lesson["content"]): ?><article class="lesson-content"><?= studentLessonHtml($lesson["content"]) ?></article><?php endif; ?>
            <?php foreach ($materials as $material): ?>
                <article class="student-material">
                    <h2><?= studentEscape($material["title"]) ?></h2>
                    <?php if ($material["type"] === "text"): ?><div class="lesson-content"><?= studentLessonHtml($material["content"] ?? "") ?></div>
                    <?php elseif ($material["type"] === "video"): $embedUrl = studentVideoEmbedUrl($material["external_url"] ?? ""); $provider = strpos($embedUrl, "vimeo.com") !== false ? "vimeo" : (strpos($embedUrl, "youtube.com") !== false ? "youtube" : ""); ?>
                        <?php if ($embedUrl): ?><div class="student-video" data-video-provider="<?= studentEscape($provider) ?>"><iframe class="student-tracked-video" data-material-id="<?= (int) $material["id"] ?>" src="<?= studentEscape($embedUrl) ?>" title="<?= studentEscape($material["title"]) ?>" allowfullscreen loading="lazy"></iframe></div><?php else: ?><p>Cette vidéo ne peut pas être affichée.</p><?php endif; ?>
                    <?php elseif ($material["type"] === "file"): ?>
                        <?php if (strtolower((string) $material["mime_type"]) === "application/pdf"): ?>
                            <?php $materialView = $documentViewStatus[(int) $material["id"]] ?? ["opened" => false, "page_count" => 0, "pages_viewed" => 0, "viewed" => false, "pages_seen" => []]; ?>
                            <div class="student-document-viewer" data-pdf-url="material_view.php?id=<?= (int) $material["id"] ?>" data-view-url="lesson.php?id=<?= (int) $lessonId ?>" data-material-id="<?= (int) $material["id"] ?>" data-csrf="<?= studentEscape($_SESSION["csrf_token"]) ?>" data-viewed="<?= $materialView["viewed"] ? "1" : "0" ?>" data-pages-viewed="<?= (int) $materialView["pages_viewed"] ?>" data-pages-seen="<?= studentEscape(json_encode($materialView["pages_seen"])) ?>" aria-label="<?= studentEscape($material["title"]) ?>">
                                <p class="student-document-status" role="status">Chargement du document…</p>
                            </div>
                            <p class="student-document-note" id="document-status-<?= (int) $material["id"] ?>" role="status"><?= $materialView["viewed"] ? "Document consulté." : ($materialView["opened"] ? "Document ouvert — pages consultées : " . (int) $materialView["pages_viewed"] . "/" . (int) $materialView["page_count"] . "." : "Ouvrez et consultez chaque page de ce document, ou réussissez le contrôle de compréhension ci-dessous.") ?></p>
                        <?php else: ?>
                            <p class="student-document-note">Ce fichier n'est pas disponible pour la consultation intégrée. Demandez à votre formateur de le remplacer par un PDF.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if ($checkQuestions): ?>
            <div class="lesson-check">
                <h2>Contrôle de compréhension</h2>
                <p class="student-muted">Répondez correctement pour valider cette leçon.</p>
                <?php foreach ($checkQuestions as $qIndex => $checkQuestion): $qid = (int) $checkQuestion["id"]; $answered = array_key_exists($qid, $checkAnswersByQuestion); $wasCorrect = $answered && $checkAnswersByQuestion[$qid]; ?>
                    <fieldset class="assessment-question lesson-check-question" data-question-id="<?= $qid ?>">
                        <legend><?= $qIndex + 1 ?>. <?= studentEscape($checkQuestion["question_text"]) ?></legend>
                        <?php foreach ($checkOptionsByQuestion[$qid] ?? [] as $option): ?>
                            <label><input type="radio" name="lesson_check_<?= $qid ?>" value="<?= (int) $option["id"] ?>" <?= $answered ? "disabled" : "" ?>> <?= studentEscape($option["option_text"]) ?></label>
                        <?php endforeach; ?>
                        <div class="lesson-check-feedback">
                            <?php if ($answered): ?>
                                <span class="<?= $wasCorrect ? "student-result-good" : "student-result-bad" ?>"><?= $wasCorrect ? "✓ Correct" : "✗ Incorrect — réessayez" ?></span>
                                <?php if (!$wasCorrect): ?><button type="button" class="button secondary-button lesson-check-retry">Réessayer</button><?php endif; ?>
                            <?php else: ?>
                                <button type="button" class="button primary-button lesson-check-submit">Vérifier</button>
                            <?php endif; ?>
                        </div>
                    </fieldset>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div id="lesson-end-marker" aria-hidden="true"></div>
            <div class="student-auto-progress">
                <p><span id="auto-progress-text">Progression : <?= (int) $progress["progress_percent"] ?>%</span> <span id="auto-progress-done" <?= $progress["completed"] ? "" : 'style="display:none"' ?>>· Terminée ✓</span></p>
                <div class="student-progress"><span id="auto-progress-bar" style="width: <?= (int) $progress["progress_percent"] ?>%"></span></div>
                <small id="auto-progress-hint" style="display:none"></small>
                <?php if ($pdfMaterials): ?>
                    <small id="lesson-completion-requirement"><?= $allDocumentsViewed ? "Documents consultés. Vous pouvez valider cette leçon." : ($quizPassed ? "Contrôle réussi. Vous pouvez valider cette leçon sans consulter chaque page." : "Consultez toutes les pages des documents ou réussissez le contrôle de compréhension pour valider cette leçon.") ?></small>
                <?php elseif ($checksRequired && !$checksAllCorrect): ?>
                    <small>Répondez correctement au contrôle de compréhension ci-dessus pour pouvoir valider cette leçon.</small>
                <?php else: ?>
                    <small>Cette progression est calculée automatiquement (lecture active de la vidéo et du contenu).</small>
                <?php endif; ?>
            </div>
            <div class="student-lesson-nav"><?php if ($previous): ?><a class="button secondary-button" href="lesson.php?id=<?= (int) $previous["id"] ?>">← <?= studentEscape($previous["title"]) ?></a><?php endif; ?><?php if ($next): ?><a class="button primary-button" href="lesson.php?id=<?= (int) $next["id"] ?>"><?= studentEscape($next["title"]) ?> →</a><?php endif; ?></div>
        </section>
    </main>

    <script>
        (function() {
            var body = document.body;
            var toggle = document.getElementById("theme-toggle");
            var savedTheme = localStorage.getItem("student-lesson-theme");

            function updateTheme(theme) {
                var isLight = theme === "light";
                body.classList.toggle("lesson-light-theme", isLight);
                toggle.textContent = isLight ? "🌙 Sombre" : "☀ï¸ Clair";
                toggle.setAttribute("aria-label", isLight ? "Activer le thème sombre" : "Activer le thème clair");
            }

            updateTheme(savedTheme === "light" ? "light" : "dark");
            toggle.addEventListener("click", function() {
                var nextTheme = body.classList.contains("lesson-light-theme") ? "dark" : "light";
                localStorage.setItem("student-lesson-theme", nextTheme);
                updateTheme(nextTheme);
            });
        }());
    </script>

    <script>
        (function() {
            "use strict";
            var CSRF_TOKEN = <?= json_encode($_SESSION["csrf_token"]) ?>;
            var LESSON_ID = <?= (int) $lessonId ?>;
            var SAVE_URL = "lesson.php?id=" + LESSON_ID;
            var REQUIRED_ACTIVE_SECONDS = <?= (int) $requiredActiveSeconds ?>;
            var HAS_DOCUMENTS = <?= $pdfMaterials ? "true" : "false" ?>;
            var CHECKS_REQUIRED = <?= $checksRequired ? "true" : "false" ?>;
            var QUIZ_PASSED = <?= $quizPassed ? "true" : "false" ?>;
            var IDLE_LIMIT_MS = 45000;

            var activeSeconds = 0;
            var lastInteraction = Date.now();
            var documentViewers = Array.prototype.slice.call(document.querySelectorAll(".student-document-viewer[data-material-id]"));
            var documentsViewed = documentViewers.length > 0 && documentViewers.every(function(viewer) {
                return viewer.dataset.viewed === "1";
            });
            ["mousemove", "keydown", "scroll", "click", "touchstart", "wheel"].forEach(function(evt) {
                window.addEventListener(evt, function() { lastInteraction = Date.now(); }, { passive: true });
            });
            setInterval(function() {
                var isVisible = document.visibilityState === "visible";
                var isActive = (Date.now() - lastInteraction) < IDLE_LIMIT_MS;
                if (isVisible && isActive) {
                    activeSeconds += 1;
                }
                renderBar();
            }, 1000);

            var state = {
                percent: <?= (int) $progress["progress_percent"] ?>,
                completed: <?= $progress["completed"] ? "true" : "false" ?>,
                position: <?= (int) $progress["last_position"] ?>,
                timeSpent: <?= (int) ($progress["total_time_seconds"] ?? 0) ?>,
                lastSent: -1,
                lastSentTime: 0
            };

            var reachedBottom = false;
            var videos = {};
            var videoEls = Array.prototype.slice.call(document.querySelectorAll(".student-tracked-video"));

            videoEls.forEach(function(el) {
                videos[el.dataset.materialId] = { maxPercent: 0, ended: false, currentTime: 0 };
            });

            function scrollPercent() {
                var doc = document.documentElement;
                var scrollable = doc.scrollHeight - doc.clientHeight;
                if (scrollable <= 0) return 100;
                return Math.min(100, Math.round((window.scrollY / scrollable) * 100));
            }

            function computeOverall() {
                var ids = Object.keys(videos);
                var videoPercent = 0;
                var allEnded = ids.length === 0;
                if (ids.length > 0) {
                    var sum = 0;
                    allEnded = true;
                    ids.forEach(function(id) {
                        sum += videos[id].maxPercent;
                        if (!videos[id].ended) allEnded = false;
                    });
                    videoPercent = Math.round(sum / ids.length);
                }

                var timeFactor = REQUIRED_ACTIVE_SECONDS > 0
                    ? Math.min(1, activeSeconds / REQUIRED_ACTIVE_SECONDS)
                    : 1;
                var sPercent = Math.round(scrollPercent() * timeFactor);

                var overall = ids.length > 0
                    ? Math.round(videoPercent * 0.7 + sPercent * 0.3)
                    : sPercent;

                var timeRequirementMet = activeSeconds >= REQUIRED_ACTIVE_SECONDS;
                var isCompleted = reachedBottom && allEnded && timeRequirementMet;
                var documentRequirementMet = !HAS_DOCUMENTS || documentsViewed || QUIZ_PASSED;
                var quizRequirementMet = !CHECKS_REQUIRED || QUIZ_PASSED || (HAS_DOCUMENTS && documentsViewed);
                isCompleted = isCompleted && documentRequirementMet && quizRequirementMet;
                if (isCompleted) overall = 100;

                return { percent: Math.max(state.percent, overall), completed: isCompleted || state.completed };
            }

            function currentPosition() {
                var ids = Object.keys(videos);
                if (ids.length === 0) return state.position;
                var max = 0;
                ids.forEach(function(id) {
                    max = Math.max(max, videos[id].currentTime || 0);
                });
                return Math.max(state.position, Math.round(max));
            }

            function renderBar() {
                var bar = document.getElementById("auto-progress-bar");
                var text = document.getElementById("auto-progress-text");
                var done = document.getElementById("auto-progress-done");
                var hint = document.getElementById("auto-progress-hint");
                if (bar) bar.style.width = state.percent + "%";
                if (text) text.textContent = "Progression : " + state.percent + "%";
                if (done) done.style.display = state.completed ? "" : "none";
                if (hint) {
                    var remaining = REQUIRED_ACTIVE_SECONDS - activeSeconds;
                    if (!state.completed && HAS_DOCUMENTS && !documentsViewed && !QUIZ_PASSED) {
                        hint.style.display = "";
                        hint.textContent = "Consultez toutes les pages des documents ou réussissez le contrôle de compréhension pour valider cette leçon.";
                    } else if (!state.completed && CHECKS_REQUIRED && !QUIZ_PASSED && (!HAS_DOCUMENTS || documentsViewed)) {
                        hint.style.display = "";
                        hint.textContent = "Réussissez le contrôle de compréhension pour valider cette leçon.";
                    } else if (!state.completed && reachedBottom && remaining > 0) {
                        hint.style.display = "";
                        hint.textContent = "Encore " + Math.ceil(remaining / 60) + " min de lecture active nécessaires pour valider cette leçon.";
                    } else {
                        hint.style.display = "none";
                    }
                }
            }

            function updateCompletionRequirement() {
                var requirement = document.getElementById("lesson-completion-requirement");
                if (!requirement) return;
                if (documentsViewed) {
                    requirement.textContent = "Documents consultés. Vous pouvez valider cette leçon.";
                } else if (QUIZ_PASSED) {
                    requirement.textContent = "Contrôle réussi. Vous pouvez valider cette leçon sans consulter chaque page.";
                } else {
                    requirement.textContent = "Consultez toutes les pages des documents ou réussissez le contrôle de compréhension pour valider cette leçon.";
                }
            }

            document.addEventListener("lesson-document-progress", function(event) {
                documentsViewed = !!event.detail.allViewed;
                if (event.detail.materialId) {
                    var status = document.getElementById("document-status-" + event.detail.materialId);
                    if (status) {
                        status.textContent = event.detail.viewed
                            ? "Document consulté."
                            : "Pages consultées : " + event.detail.pagesViewed + "/" + event.detail.pageCount + ".";
                    }
                }
                updateCompletionRequirement();
                sync(true);
            });

            document.addEventListener("lesson-check-updated", function(event) {
                QUIZ_PASSED = !!event.detail.passed;
                updateCompletionRequirement();
                sync(true);
            });

            function sync(force) {
                var next = computeOverall();
                state.percent = next.percent;
                state.completed = next.completed;
                state.position = currentPosition();
                renderBar();

                if (!force && state.percent === state.lastSent && !state.completed && activeSeconds - state.lastSentTime < 15) return;
                state.lastSent = state.percent;
                state.lastSentTime = activeSeconds;

                var body = new URLSearchParams({
                    ajax: "1",
                    csrf_token: CSRF_TOKEN,
                    progress_percent: String(state.percent),
                    last_position: String(state.position),
                    time_spent: String(state.timeSpent + activeSeconds),
                    completed: state.completed ? "1" : "0"
                });

                if (force && navigator.sendBeacon) {
                    navigator.sendBeacon(SAVE_URL, body);
                } else {
                    fetch(SAVE_URL, { method: "POST", body: body, credentials: "same-origin" }).catch(function() {});
                }
            }

            var marker = document.getElementById("lesson-end-marker");
            if (marker && "IntersectionObserver" in window) {
                var observer = new IntersectionObserver(function(entries) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) {
                            reachedBottom = true;
                            sync(false);
                        }
                    });
                }, { threshold: 0.1 });
                observer.observe(marker);
            }
            window.addEventListener("scroll", function() { sync(false); }, { passive: true });

            var youtubeVideos = videoEls.filter(function(el) {
                return el.closest("[data-video-provider='youtube']");
            });
            if (youtubeVideos.length > 0) {
                youtubeVideos.forEach(function(el) {
                    var url = new URL(el.src);
                    url.searchParams.set("enablejsapi", "1");
                    url.searchParams.set("origin", window.location.origin);
                    el.src = url.toString();
                });
                var ytScript = document.createElement("script");
                ytScript.src = "https://www.youtube.com/iframe_api";
                document.head.appendChild(ytScript);
                window.onYouTubeIframeAPIReady = function() {
                    youtubeVideos.forEach(function(el) {
                        var materialId = el.dataset.materialId;
                        new YT.Player(el, {
                            events: {
                                onStateChange: function(e) {
                                    if (e.data === YT.PlayerState.ENDED) {
                                        videos[materialId].ended = true;
                                        videos[materialId].maxPercent = 100;
                                        sync(false);
                                    }
                                },
                                onReady: function(e) {
                                    setInterval(function() {
                                        try {
                                            var player = e.target;
                                            var duration = player.getDuration();
                                            var time = player.getCurrentTime();
                                            if (duration > 0) {
                                                videos[materialId].currentTime = time;
                                                videos[materialId].maxPercent = Math.max(
                                                    videos[materialId].maxPercent,
                                                    Math.round((time / duration) * 100)
                                                );
                                                sync(false);
                                            }
                                        } catch (err) {}
                                    }, 3000);
                                }
                            }
                        });
                    });
                };
            }

            var vimeoVideos = videoEls.filter(function(el) {
                return el.closest("[data-video-provider='vimeo']");
            });
            if (vimeoVideos.length > 0) {
                vimeoVideos.forEach(function(el) {
                    var url = new URL(el.src);
                    url.searchParams.set("api", "1");
                    el.src = url.toString();
                });
                var vimeoScript = document.createElement("script");
                vimeoScript.src = "https://player.vimeo.com/api/player.js";
                vimeoScript.onload = function() {
                    vimeoVideos.forEach(function(el) {
                        var materialId = el.dataset.materialId;
                        var player = new Vimeo.Player(el);
                        player.on("timeupdate", function(data) {
                            videos[materialId].currentTime = data.seconds;
                            videos[materialId].maxPercent = Math.max(
                                videos[materialId].maxPercent,
                                Math.round(data.percent * 100)
                            );
                            sync(false);
                        });
                        player.on("ended", function() {
                            videos[materialId].ended = true;
                            videos[materialId].maxPercent = 100;
                            sync(false);
                        });
                    });
                };
                document.head.appendChild(vimeoScript);
            }

            setInterval(function() { sync(false); }, 5000);
            window.addEventListener("pagehide", function() { sync(true); });
            document.addEventListener("visibilitychange", function() {
                if (document.visibilityState === "hidden") sync(true);
            });

            renderBar();
            sync(true);
        }());
    </script>

    <script>
        (function() {
            "use strict";
            var CSRF_TOKEN = <?= json_encode($_SESSION["csrf_token"]) ?>;
            var AJAX_URL = "lesson.php?id=<?= (int) $lessonId ?>";

            // --- Floating Sidebar Toggle ---
            var sidebar = document.getElementById("notes-sidebar");
            var toggleBtn = document.getElementById("notes-toggle-btn");
            if (sidebar && toggleBtn) {
                toggleBtn.addEventListener("click", function() {
                    sidebar.classList.toggle("collapsed");
                    var isCollapsed = sidebar.classList.contains("collapsed");
                    toggleBtn.textContent = isCollapsed ? "›" : "‹";
                });
            }

            // --- Bookmark star ---
            var star = document.getElementById("lesson-bookmark-star");
            if (star) {
                star.addEventListener("click", function() {
                    var body = new URLSearchParams({ csrf_token: CSRF_TOKEN, lesson_id: "<?= (int) $lessonId ?>" });
                    fetch("../bookmark_toggle.php", { method: "POST", body: body, credentials: "same-origin" })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (!data.ok) return;
                            star.dataset.bookmarked = data.bookmarked ? "1" : "0";
                            star.textContent = data.bookmarked ? "★" : "☆";
                        });
                });
            }

            // --- Personal Notes: Word Toolbar & Auto-save ---
            var noteBox = document.getElementById("lesson-note");
            var noteStatus = document.getElementById("lesson-note-status");
            var noteCount = document.getElementById("lesson-note-count");
            var toolbar = document.getElementById("notes-toolbar");

            // Execute formatting commands (Bold, Italic, Lists, Highlight, etc.)
            if (toolbar) {
                toolbar.querySelectorAll("button[data-command]").forEach(function(button) {
                    button.addEventListener("click", function(e) {
                        e.preventDefault();
                        var command = this.dataset.command;
                        var value = this.dataset.value || null;
                        document.execCommand(command, false, value);
                        if (noteBox) noteBox.focus();
                    });
                });
            }

            // Debounced Auto-save Logic
            if (noteBox) {
                var noteTimer = null;

                function updateNoteMeta() {
                    var text = noteBox.innerText.trim();
                    var words = text === "" ? 0 : text.split(/\s+/).length;
                    if (noteCount) noteCount.textContent = words + " mots · " + text.length + " caractères";
                }

                function saveNote() {
                    noteStatus.textContent = "Enregistrement...";
                    // Reads innerHTML to preserve all formatting tags
                    var body = new URLSearchParams({ ajax: "note", csrf_token: CSRF_TOKEN, note: noteBox.innerHTML });
                    fetch(AJAX_URL, { method: "POST", body: body, credentials: "same-origin" })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            noteStatus.textContent = data.ok ? "Enregistré ✓" : "Échec";
                        })
                        .catch(function() { noteStatus.textContent = "Échec"; });
                }

                function scheduleNoteSave() {
                    noteStatus.textContent = "Modifications non enregistrées";
                    clearTimeout(noteTimer);
                    noteTimer = setTimeout(saveNote, 1200);
                }

                toolbar.querySelectorAll("button[data-note-action]").forEach(function(button) {
                    button.addEventListener("click", function() {
                        var action = button.dataset.noteAction;
                        if (action === "copy") {
                            navigator.clipboard.writeText(noteBox.innerText).then(function() {
                                noteStatus.textContent = "Copié ✓";
                            }).catch(function() {
                                noteStatus.textContent = "Copie impossible";
                            });
                        }
                        if (action === "clear" && noteBox.innerText.trim() !== "") {
                            if (window.confirm("Effacer toutes les notes de cette leçon ?")) {
                                noteBox.innerHTML = "";
                                updateNoteMeta();
                                scheduleNoteSave();
                            }
                        }
                    });
                });

                noteBox.addEventListener("input", function() {
                    updateNoteMeta();
                    scheduleNoteSave();
                });

                noteBox.addEventListener("blur", function() {
                    clearTimeout(noteTimer);
                    saveNote();
                });

                noteBox.addEventListener("keydown", function(event) {
                    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "s") {
                        event.preventDefault();
                        clearTimeout(noteTimer);
                        saveNote();
                    }
                });

                updateNoteMeta();
            }

            // --- Comprehension check ---
            document.querySelectorAll(".lesson-check-question").forEach(function(fieldset) {
                var questionId = fieldset.dataset.questionId;
                var submitBtn = fieldset.querySelector(".lesson-check-submit");
                var retryBtn = fieldset.querySelector(".lesson-check-retry");

                function submitAnswer() {
                    var selected = fieldset.querySelector("input[type=radio]:checked");
                    if (!selected) return;
                    var body = new URLSearchParams({
                        ajax: "check",
                        csrf_token: CSRF_TOKEN,
                        question_id: questionId,
                        option_id: selected.value
                    });
                    fetch(AJAX_URL, { method: "POST", body: body, credentials: "same-origin" })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (!data.ok) return;
                            var feedback = fieldset.querySelector(".lesson-check-feedback");
                            fieldset.querySelectorAll("input[type=radio]").forEach(function(input) { input.disabled = true; });
                            if (data.correct) {
                                feedback.innerHTML = '<span class="student-result-good">✓ Correct</span>';
                            } else {
                                feedback.innerHTML = '<span class="student-result-bad">✗ Incorrect — réessayez</span> <button type="button" class="button secondary-button lesson-check-retry">Réessayer</button>';
                                feedback.querySelector(".lesson-check-retry").addEventListener("click", enableRetry);
                            }
                            var questions = Array.prototype.slice.call(document.querySelectorAll(".lesson-check-question"));
                            var passed = questions.length > 0 && questions.every(function(question) {
                                return !!question.querySelector(".student-result-good");
                            });
                            document.dispatchEvent(new CustomEvent("lesson-check-updated", {
                                detail: { passed: passed }
                            }));
                        });
                }

                function enableRetry() {
                    fieldset.querySelectorAll("input[type=radio]").forEach(function(input) {
                        input.disabled = false;
                        input.checked = false;
                    });
                    var feedback = fieldset.querySelector(".lesson-check-feedback");
                    feedback.innerHTML = '<button type="button" class="button primary-button lesson-check-submit">Vérifier</button>';
                    feedback.querySelector(".lesson-check-submit").addEventListener("click", submitAnswer);
                    document.dispatchEvent(new CustomEvent("lesson-check-updated", {
                        detail: { passed: false }
                    }));
                }

                if (submitBtn) submitBtn.addEventListener("click", submitAnswer);
                if (retryBtn) retryBtn.addEventListener("click", enableRetry);
            });
        }());
    </script>
<script src="../../assets/js/lesson-content-guard.js?v=20261019-document-tracking"></script>
<script type="module" src="../../assets/js/lesson-pdf-viewer.js?v=20261020-pdf-zoom"></script>
<?= platformContactMarkup($pdo, "platform-contact platform-contact-app") ?>
</body>

</html>
