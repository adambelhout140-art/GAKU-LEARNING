<?php
require_once __DIR__ . '/../../config/i18n.php';

// ============================================================
// * ADMIN COURSE CREATION CONTROLLER
// ============================================================
// ! RESTRICTED ACCESS: Requires active administrator credentials.
// ? Purpose: Handles new course creation, input sanitization, teacher verification,
// ? slug generation, image upload handling, database insertion, and rendering the creation view.
// ============================================================


// ------------------------------------------------------------
// * SESSION HANDLING & FILE DEPENDENCIES
// ------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../../config/database.php";
require_once "../../config/audit.php";


// ------------------------------------------------------------
// * ACCESS CONTROL: AUTHENTICATION & ROLE CHECKS
// ! Bounces unauthenticated users to login and non-admins to the public home page.
// ------------------------------------------------------------
if (!isset($_SESSION["user_id"])) {
    header("Location: ../../auth/login.php");
    exit;
}

if (!in_array($_SESSION["user_role"] ?? "", ["admin", "teacher"], true)) {
    header("Location: ../../index.php");
    exit;
}

$userRole = $_SESSION["user_role"];
$returnToContent = ($_GET["return_to"] ?? "") === "content";


// ------------------------------------------------------------
// * CSRF TOKEN INITIALIZATION
// ------------------------------------------------------------
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}


// ------------------------------------------------------------
// * DATA DEPENDENCIES & INITIAL FORM STATE
// ? Fetches active teacher options and initializes default form field values.
// ------------------------------------------------------------
$teachers = $userRole === "admin" ? $pdo->query("
    SELECT id, first_name, last_name 
    FROM users 
    WHERE role = 'teacher' 
    ORDER BY first_name, last_name
")->fetchAll() : [];
$audiences = $pdo->query("SELECT id, name FROM course_audiences ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$title            = "";
$slug             = "";
$shortDescription = "";
$description      = "";
$objectives       = "";
$prerequisites    = "";
$category         = "";
$duration         = "";
$price            = "";
$level            = "beginner";
$educationLevel   = "";
$selectedAudienceIds = [];
$format           = "online";
$status           = "draft";
$teacherId        = "";
$teacherIds       = [];
$error            = "";


// ------------------------------------------------------------
// * HELPER FUNCTION: SLUG GENERATOR
// ? Converts raw title strings into URL-safe ASCII slugs.
// ------------------------------------------------------------
function courseSlug($value) {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value));
    return trim($value, '-');
}


// ------------------------------------------------------------
// * POST REQUEST PROCESSING & INSERTION
// ------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $returnToContent = ($_POST["return_to"] ?? "") === "content";

    // ? Extract and normalize user submitted parameters
    $title            = trim($_POST["title"] ?? "");
    $slug             = courseSlug($_POST["slug"] ?? $title);
    $shortDescription = trim($_POST["short_description"] ?? "");
    $description      = trim($_POST["description"] ?? "");
    $objectives       = trim($_POST["objectives"] ?? "");
    $prerequisites    = trim($_POST["prerequisites"] ?? "");
    $category         = trim($_POST["category"] ?? "");
    $duration         = trim($_POST["duration_hours"] ?? "");
    $price            = trim($_POST["price"] ?? "");
    $level            = $_POST["level"] ?? "beginner";
    $educationLevel   = $_POST["education_level"] ?? "";
    $rawAudienceIds   = $_POST["audience_ids"] ?? [];
    $selectedAudienceIds = [];
    if (is_array($rawAudienceIds)) {
        foreach ($rawAudienceIds as $audienceId) {
            $validatedAudienceId = filter_var($audienceId, FILTER_VALIDATE_INT);
            if ($validatedAudienceId === false || $validatedAudienceId < 1) {
                $selectedAudienceIds = [-1];
                break;
            }
            $selectedAudienceIds[] = $validatedAudienceId;
        }
        $selectedAudienceIds = array_values(array_unique($selectedAudienceIds));
    } else {
        $selectedAudienceIds = [-1];
    }
    $format           = $_POST["format"] ?? "online";
    $status           = $_POST["status"] ?? "draft";
    $teacherIds       = $userRole === "teacher"
        ? [(string) $_SESSION["user_id"]]
        : array_values(array_unique(array_filter(array_map("intval", (array) ($_POST["teacher_ids"] ?? [])))));
    if ($userRole === "teacher" && !$teacherIds && !empty($_POST["teacher_id"])) {
        $teacherIds = [(string) (int) $_POST["teacher_id"]];
    }
    $teacherId        = $teacherIds[0] ?? "";

    // ? Input Validation Suite
    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        $error = "La session du formulaire a expiré.";
    } elseif ($title === "" || $slug === "") {
        $error = "Le titre de la formation est obligatoire.";
    } elseif (
        !in_array($level, ["beginner", "intermediate", "advanced"], true) ||
        !in_array($educationLevel, ["", "preschool", "primary", "middle_school", "high_school", "post_secondary_non_tertiary", "higher_education", "vocational_training", "other"], true) ||
        !in_array($format, ["online", "hybrid", "in_person"], true) ||
        !in_array($status, ["draft", "published", "unpublished"], true)
    ) {
        $error = "Une valeur de formation est invalide.";
    } elseif (in_array(-1, $selectedAudienceIds, true)) {
        $error = "Les publics cibles sélectionnés sont invalides.";
    } elseif ($duration !== "" && (!is_numeric($duration) || $duration < 0)) {
        $error = "La durée doit être un nombre positif.";
    } elseif ($price !== "" && (!is_numeric($price) || $price < 0)) {
        $error = "Le prix doit être un nombre positif.";
    } elseif ($userRole === "admin" && count($teacherIds) > 0 && count($teacherIds) !== count(array_filter($teacherIds, static function ($id) {
        return $id > 0;
    }))) {
        $error = "Les formateurs sélectionnés sont invalides.";
    }

    if ($error === "" && $selectedAudienceIds) {
        $placeholders = implode(",", array_fill(0, count($selectedAudienceIds), "?"));
        $audienceCheck = $pdo->prepare("SELECT COUNT(*) FROM course_audiences WHERE id IN ($placeholders)");
        $audienceCheck->execute($selectedAudienceIds);
        if ((int) $audienceCheck->fetchColumn() !== count($selectedAudienceIds)) {
            $error = "Un public cible sélectionné n'existe pas.";
        }
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
        $check = $pdo->prepare("SELECT id FROM courses WHERE slug = ? LIMIT 1");
        $check->execute([$slug]);
        if ($check->fetch()) {
            $error = "Ce slug est déjà utilisé.";
        }
    }

    // ? Optional Cover Image Upload Handling
    $coverImage = null;

    if ($error === "" && isset($_FILES["cover_image"]) && $_FILES["cover_image"]["error"] !== UPLOAD_ERR_NO_FILE) {
        $allowed = [
            "image/jpeg" => "jpg",
            "image/png"  => "png",
            "image/webp" => "webp"
        ];
        
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($_FILES["cover_image"]["tmp_name"]);

        if (
            $_FILES["cover_image"]["error"] !== UPLOAD_ERR_OK ||
            !isset($allowed[$mime]) ||
            $_FILES["cover_image"]["size"] > 5 * 1024 * 1024
        ) {
            $error = "La couverture doit être une image JPG, PNG ou WEBP de 5 Mo maximum.";
        } else {
            $coverImage = bin2hex(random_bytes(16)) . "." . $allowed[$mime];
            
            if (!is_dir("../../uploads/courses")) {
                mkdir("../../uploads/courses", 0755, true);
            }
            
            move_uploaded_file($_FILES["cover_image"]["tmp_name"], "../../uploads/courses/" . $coverImage);
        }
    }

    // ? Database Insertion Execution
    if ($error === "") {
        $statement = $pdo->prepare("
            INSERT INTO courses (
                teacher_id, 
                title, 
                slug, 
                short_description, 
                description, 
                objectives, 
                prerequisites, 
                duration_hours, 
                level, 
                education_level,
                format, 
                category, 
                price, 
                cover_image, 
                status, 
                published_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $statement->execute([
            $teacherId === "" ? null : (int) $teacherId,
            $title,
            $slug,
            $shortDescription ?: null,
            $description ?: null,
            $objectives ?: null,
            $prerequisites ?: null,
            $duration === "" ? null : $duration,
            $level,
            $educationLevel !== "" ? $educationLevel : null,
            $format,
            $category ?: null,
            $price === "" ? 0 : $price,
            $coverImage,
            $status,
            $status === "published" ? date("Y-m-d H:i:s") : null
        ]);
        $createdCourseId = (int) $pdo->lastInsertId();
        if ($selectedAudienceIds) {
            $audienceAssignment = $pdo->prepare("INSERT INTO course_target_audiences (course_id, audience_id) VALUES (?, ?)");
            foreach ($selectedAudienceIds as $audienceId) {
                $audienceAssignment->execute([$createdCourseId, $audienceId]);
            }
        }
        if ($teacherIds) {
            $assignment = $pdo->prepare("INSERT INTO course_teachers (course_id, teacher_id) VALUES (?, ?)");
            foreach ($teacherIds as $assignedTeacherId) {
                $assignment->execute([$createdCourseId, $assignedTeacherId]);
            }
        }
        auditLog($pdo, (int) $_SESSION["user_id"], "course_created", "course", $createdCourseId, null, ["title" => $title]);

        header("Location: " . ($returnToContent && $userRole === "admin"
            ? "../content.php?course_id=" . $createdCourseId
            : ($userRole === "teacher" ? "../../teacher/dashboard.php" : "courses.php?success=created")));
        exit;
    }
}


// ------------------------------------------------------------
// * VIEW RENDERING
// ------------------------------------------------------------
$editing = false;
include __DIR__ . "/course_form.php";
