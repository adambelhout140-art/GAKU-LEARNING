<?php
require_once __DIR__ . '/../../config/i18n.php';

// ============================================================
// * ADMIN COURSE EDIT CONTROLLER
// ============================================================
// ! RESTRICTED ACCESS: Requires an authenticated administrator.
// ? Purpose: Fetches existing course data, performs CSRF/data validation, 
// ? handles image replacements, updates the database, and renders the edit view.
// ============================================================


// ------------------------------------------------------------
// * SESSION HANDLING & FILE DEPENDENCIES
// ------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../../config/database.php";
require_once "../../config/audit.php";
require_once "../../config/content_access.php";


// ------------------------------------------------------------
// * ACCESS CONTROL: AUTHENTICATION & ROLE CHECKS
// ! Bounces guests to login and non-admins to the public index.
// ------------------------------------------------------------
if (!isset($_SESSION["user_id"])) {
    header("Location: ../../auth/login.php");
    exit;
}

if (($_SESSION["user_role"] ?? "") !== "admin") {
    header("Location: ../../index.php");
    exit;
}


// ------------------------------------------------------------
// * CSRF TOKEN INITIALIZATION
// ------------------------------------------------------------
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}


// ------------------------------------------------------------
// * RECORD RESOLUTION & VALIDATION
// ? Validates course ID parameter and retrieves existing record.
// ------------------------------------------------------------
$courseId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
if (!$courseId || $courseId < 1) {
    header("Location: courses.php");
    exit;
}

$statement = $pdo->prepare("SELECT * FROM courses WHERE id = ? LIMIT 1");
$statement->execute([$courseId]);
$course = $statement->fetch();

if (!$course) {
    header("Location: courses.php");
    exit;
}


// ------------------------------------------------------------
// * DATA DEPENDENCIES & INITIAL FORM STATE
// ? Fetches active teacher options and populates default field values.
// ------------------------------------------------------------
$teachers = $pdo->query("
    SELECT id, first_name, last_name 
    FROM users 
    WHERE role = 'teacher' 
    ORDER BY first_name, last_name
")->fetchAll();

$title            = $course["title"];
$slug             = $course["slug"];
$shortDescription = $course["short_description"] ?? "";
$description      = $course["description"] ?? "";
$objectives       = $course["objectives"] ?? "";
$prerequisites    = $course["prerequisites"] ?? "";
$category         = $course["category"] ?? "";
$duration         = $course["duration_hours"] ?? "";
$price            = $course["price"] ?? "";
$level            = $course["level"];
$format           = $course["format"];
$status           = $course["status"];
$teacherId        = $course["teacher_id"] ?? "";
$teacherAssignmentStatement = $pdo->prepare("SELECT teacher_id FROM course_teachers WHERE course_id = ?");
$teacherAssignmentStatement->execute([$courseId]);
$teacherIds = array_map("strval", $teacherAssignmentStatement->fetchAll(PDO::FETCH_COLUMN));
if (!$teacherIds && $teacherId !== "") {
    $teacherIds = [(string) $teacherId];
}
$error            = "";


// ------------------------------------------------------------
// * HELPER FUNCTION: SLUG GENERATOR
// ? Converts raw title strings into URL-safe ASCII slugs.
// ------------------------------------------------------------
function courseSlugEdit($value) {
    $value = strtolower(trim($value));
    return trim(preg_replace('/[^a-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value)), '-');
}


// ------------------------------------------------------------
// * POST REQUEST PROCESSING & MUTATION
// ------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // ? Extract and normalize user submitted parameters
    $title            = trim($_POST["title"] ?? "");
    $slug             = courseSlugEdit($_POST["slug"] ?? $title);
    $shortDescription = trim($_POST["short_description"] ?? "");
    $description      = trim($_POST["description"] ?? "");
    $objectives       = trim($_POST["objectives"] ?? "");
    $prerequisites    = trim($_POST["prerequisites"] ?? "");
    $category         = trim($_POST["category"] ?? "");
    $duration         = trim($_POST["duration_hours"] ?? "");
    $price            = trim($_POST["price"] ?? "");
    $level            = $_POST["level"] ?? "";
    $format           = $_POST["format"] ?? "";
    $status           = $_POST["status"] ?? "";
    $teacherIds       = array_values(array_unique(array_filter(array_map("intval", (array) ($_POST["teacher_ids"] ?? [])))));
    $teacherId        = $teacherIds[0] ?? "";

    // ? Input Validation Suite
    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        $error = "La session du formulaire a expiré.";
    } elseif ($title === "" || $slug === "") {
        $error = "Le titre est obligatoire.";
    } elseif (
        !in_array($level, ["beginner", "intermediate", "advanced"], true) ||
        !in_array($format, ["online", "hybrid", "in_person"], true) ||
        !in_array($status, ["draft", "published", "unpublished"], true)
    ) {
        $error = "Une valeur de formation est invalide.";
    } elseif ($duration !== "" && (!is_numeric($duration) || $duration < 0)) {
        $error = "La durée doit être positive.";
    } elseif ($price !== "" && (!is_numeric($price) || $price < 0)) {
        $error = "Le prix doit être positif.";
    } elseif ($teacherIds && count($teacherIds) !== count(array_filter($teacherIds, static function ($id) {
        return $id > 0;
    }))) {
        $error = "Les formateurs sélectionnés sont invalides.";
    }

    // ? Foreign Key Validation: Teacher Verification
    if ($error === "" && $teacherIds) {
        $placeholders = implode(",", array_fill(0, count($teacherIds), "?"));
        $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'teacher' AND id IN ($placeholders)");
        $check->execute($teacherIds);
        if ((int) $check->fetchColumn() !== count($teacherIds)) {
            $error = "Un formateur sélectionné n'existe pas.";
        }
    }

    // ? Uniqueness Validation: Slug Duplication Check
    if ($error === "") {
        $check = $pdo->prepare("SELECT id FROM courses WHERE slug = ? AND id <> ? LIMIT 1");
        $check->execute([$slug, $courseId]);
        if (!$check->fetch()) {
            // Slug is clear
        } else {
            $error = "Ce slug est déjà utilisé.";
        }
    }

    // ? File Upload Handling (Optional Replacement)
    $coverImage = $course["cover_image"];

    if ($error === "" && isset($_FILES["cover_image"]) && $_FILES["cover_image"]["error"] !== UPLOAD_ERR_NO_FILE) {
        $allowed = [
            "image/jpeg" => "jpg",
            "image/png"  => "png",
            "image/webp" => "webp"
        ];
        
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES["cover_image"]["tmp_name"]);

        if (
            $_FILES["cover_image"]["error"] !== UPLOAD_ERR_OK ||
            !isset($allowed[$mime]) ||
            $_FILES["cover_image"]["size"] > 5 * 1024 * 1024
        ) {
            $error = "La couverture doit être JPG, PNG ou WEBP, 5 Mo maximum.";
        } else {
            $coverImage = bin2hex(random_bytes(16)) . "." . $allowed[$mime];
            
            if (!is_dir("../../uploads/courses")) {
                mkdir("../../uploads/courses", 0755, true);
            }
            
            move_uploaded_file($_FILES["cover_image"]["tmp_name"], "../../uploads/courses/" . $coverImage);
        }
    }

    // ? Database Update Execution
    if ($error === "") {
        $update = $pdo->prepare("
            UPDATE courses SET 
                teacher_id = ?, 
                title = ?, 
                slug = ?, 
                short_description = ?, 
                description = ?, 
                objectives = ?, 
                prerequisites = ?, 
                duration_hours = ?, 
                level = ?, 
                format = ?, 
                category = ?, 
                price = ?, 
                cover_image = ?, 
                status = ?, 
                published_at = ? 
            WHERE id = ?
        ");

        $publishedAt = $status === "published" ? ($course["published_at"] ?: date("Y-m-d H:i:s")) : null;

        $update->execute([
            $teacherId === "" ? null : (int) $teacherId,
            $title,
            $slug,
            $shortDescription ?: null,
            $description ?: null,
            $objectives ?: null,
            $prerequisites ?: null,
            $duration === "" ? null : $duration,
            $level,
            $format,
            $category ?: null,
            $price === "" ? 0 : $price,
            $coverImage,
            $status,
            $publishedAt,
            $courseId
        ]);
        $pdo->prepare("DELETE FROM course_teachers WHERE course_id = ?")->execute([$courseId]);
        if ($teacherIds) {
            $assignment = $pdo->prepare("INSERT INTO course_teachers (course_id, teacher_id) VALUES (?, ?)");
            foreach ($teacherIds as $assignedTeacherId) {
                $assignment->execute([$courseId, $assignedTeacherId]);
            }
        }
        auditLog($pdo, (int) $_SESSION["user_id"], "course_updated", "course", (int) $courseId, null, ["title" => $title]);

        header("Location: courses.php?success=updated");
        exit;
    }
}


// ------------------------------------------------------------
// * VIEW RENDERING
// ------------------------------------------------------------
$editing = true;
include __DIR__ . "/course_form.php";
