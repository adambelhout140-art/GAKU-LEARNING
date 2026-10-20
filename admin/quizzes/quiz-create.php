```php
<?php
require_once __DIR__ . '/../../config/i18n.php';// ! ================================================================
// ! QUIZ CREATION
// ! ================================================================
// ! This page allows an administrator or teacher to create a quiz.
// !
// ! A quiz belongs to ONE module.
// !
// ! The teacher/admin can define:
// !
// ! - The module containing the quiz
// ! - The passing score
// ! - The maximum number of attempts
// ! - Whether questions should appear in random order
// ! - Whether the quiz is published
// !
// ! IMPORTANT:
// ! Questions and answers are NOT created here yet.
// !
// ! After creating the quiz, we will build the question management
// ! page where the teacher/admin can add:
// !
// ! - Questions
// ! - Answers
// ! - Correct answers
// ! - Points
// ! - Explanations
// !
// ! ================================================================


// ! ================================================================
// ! 1. START SESSION
// ! ================================================================
// ! We need the session to know:
// !
// ! - Who is logged in
// ! - What role they have
// ! - Which teacher is creating the quiz
// ! ================================================================

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


// ! ================================================================
// ! 2. CHECK LOGIN
// ! ================================================================
// ! Only authenticated users can access this page.
// ! ================================================================

if (!isset($_SESSION["user_id"])) {

    header("Location: ../../auth/login.php");
    exit;
}


// ! ================================================================
// ! 3. CHECK USER ROLE
// ! ================================================================
// ! Only administrators and teachers can create quizzes.
// !
// ! Students must never be able to create quizzes.
// ! ================================================================

$user_role = $_SESSION["user_role"] ?? "";

if ($user_role !== "admin" && $user_role !== "teacher") {

    http_response_code(403);

    die("Accès interdit.");
}


// ! ================================================================
// ! 4. DATABASE CONNECTION
// ! ================================================================
// ! Load our PDO database connection.
// ! ================================================================

require_once "../../config/database.php";
require_once "../../config/audit.php";


// ! ================================================================
// ! 5. CONTENT ACCESS FUNCTIONS
// ! ================================================================
// ! This file contains the authorization functions used to make
// ! sure teachers can only manage their own courses.
// ! ================================================================

require_once "../../config/content_access.php";


// ! ================================================================
// ! 6. CREATE CSRF TOKEN
// ! ================================================================
// ! CSRF protection prevents another website from secretly
// ! submitting a form using the logged-in user's session.
// !
// ! We create the token only if one does not already exist.
// ! ================================================================

if (empty($_SESSION["csrf_token"])) {

    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}


// ! ================================================================
// ! 7. VARIABLES USED BY THE FORM
// ! ================================================================
// ! These variables store validation errors and form values.
// ! ================================================================

$errors = [];

$title = "";
$module_id = filter_input(INPUT_GET, "module_id", FILTER_VALIDATE_INT) ?: "";
$returnCourseId = filter_input(INPUT_GET, "course_id", FILTER_VALIDATE_INT) ?: 0;
$returnToContent = ($_GET["return_to"] ?? "") === "content";
$passing_score = "50";
$max_attempts = "";
$random_order = 0;
$published = 0;
$questions = [[
    "question_text" => "",
    "question_type" => "single_choice",
    "points" => "1",
    "answers" => ["", "", "", ""],
    "correct_answers" => [],
    "explanation" => ""
]];


// ! ================================================================
// ! 8. LOAD AVAILABLE MODULES
// ! ================================================================
// ! The user must select the module to which the quiz belongs.
// !
// ! ADMIN:
// ! -> Can see all modules.
// !
// ! TEACHER:
// ! -> Can only see modules belonging to courses assigned to them.
// !
// ! This is important because a teacher must not be able to create
// ! a quiz inside another teacher's course.
// ! ================================================================

if ($user_role === "admin") {

    // ! ------------------------------------------------------------
    // ! ADMIN MODULE QUERY
    // ! ------------------------------------------------------------
    // ! Admins can access every module.
    // ! ------------------------------------------------------------

    $module_stmt = $pdo->query("
        SELECT
            m.id,
            m.title AS module_title,
            c.title AS course_title

        FROM modules m

        INNER JOIN courses c
            ON c.id = m.course_id

        ORDER BY
            c.title ASC,
            m.position ASC
    ");

    $modules = $module_stmt->fetchAll();

} else {

    // ! ------------------------------------------------------------
    // ! TEACHER MODULE QUERY
    // ! ------------------------------------------------------------
    // ! Teachers only receive modules belonging to courses where
    // ! courses.teacher_id equals their user ID.
    // ! ------------------------------------------------------------

    $module_stmt = $pdo->prepare("
        SELECT
            m.id,
            m.title AS module_title,
            c.title AS course_title

        FROM modules m

        INNER JOIN courses c
            ON c.id = m.course_id

        WHERE EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id = c.id AND ct.teacher_id = :teacher_id)

        ORDER BY
            c.title ASC,
            m.position ASC
    ");

    $module_stmt->execute([
        ":teacher_id" => $_SESSION["user_id"]
    ]);

    $modules = $module_stmt->fetchAll();
}


// ! ================================================================
// ! 9. HANDLE FORM SUBMISSION
// ! ================================================================
// ! The form uses POST.
// !
// ! We only process the form when the request method is POST.
// ! ================================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $returnToContent = ($_POST["return_to"] ?? "") === "content";
    $returnCourseId = filter_input(INPUT_POST, "return_course_id", FILTER_VALIDATE_INT) ?: 0;


    // ! ============================================================
    // ! 10. CHECK CSRF TOKEN
    // ! ============================================================
    // ! Compare the token submitted by the form with the token
    // ! stored in the user's session.
    // ! ============================================================

    $csrf_token = $_POST["csrf_token"] ?? "";

    if (
        empty($csrf_token) ||
        empty($_SESSION["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $csrf_token)
    ) {

        $errors[] = "La requête est invalide. Veuillez réessayer.";

    }


    // ! ============================================================
    // ! 11. GET MODULE ID
    // ! ============================================================
    // ! The module ID comes from the form.
    // !
// ! We validate that it is a positive integer.
// ! ============================================================

    $title = trim($_POST["title"] ?? "");

    if ($title === "") {

        $errors[] = "Le titre du quiz est obligatoire.";

    } elseif (mb_strlen($title) > 200) {

        $errors[] = "Le titre du quiz ne peut pas dépasser 200 caractères.";

    }


    $module_id = filter_input(
        INPUT_POST,
        "module_id",
        FILTER_VALIDATE_INT
    );

    if ($module_id === false || $module_id === null || $module_id <= 0) {

        $errors[] = "Veuillez sélectionner un module.";

    }


    // ! ============================================================
    // ! 12. GET PASSING SCORE
    // ! ============================================================
    // ! The passing score represents the percentage required
    // ! to pass the quiz.
// !
// ! Example:
// ! 50 means the learner needs at least 50%.
// ! ============================================================

    $passing_score = trim(
        $_POST["passing_score"] ?? ""
    );

    if (
        $passing_score === "" ||
        !is_numeric($passing_score)
    ) {

        $errors[] = "Le score de réussite est obligatoire.";

    } else {

        $passing_score = (float) $passing_score;

        // ! The score must be between 0 and 100.
        if ($passing_score < 0 || $passing_score > 100) {

            $errors[] = "Le score de réussite doit être compris entre 0 et 100.";

        }

    }


    // ! ============================================================
    // ! 13. GET MAXIMUM ATTEMPTS
    // ! ============================================================
    // ! This field is optional.
    // !
// ! Empty = unlimited attempts.
// !
// ! Example:
// ! 3 = learner can take the quiz three times.
// ! ============================================================

    // ! Practice quizzes always allow unlimited attempts.
    $max_attempts = null;


    // ! ============================================================
    // ! 14. RANDOM QUESTION ORDER
    // ! ============================================================
    // ! Checkbox:
    // !
// ! checked = 1
    // ! unchecked = 0
    // ! ============================================================

    $random_order = isset($_POST["random_order"]) ? 1 : 0;


    // ! ============================================================
    // ! 15. PUBLISHED STATUS
    // ! ============================================================
    // ! Checkbox:
    // !
// ! checked = published
    // ! unchecked = draft
    // !
// ! Teachers can create a draft.
// ! Publication permissions can be refined later if necessary.
// ! ============================================================

    $published = isset($_POST["published"]) ? 1 : 0;

    $postedQuestions = $_POST["questions"] ?? [];
    $questions = [];
    if (is_array($postedQuestions)) {
        foreach ($postedQuestions as $postedQuestion) {
            if (!is_array($postedQuestion)) {
                continue;
            }
            $answers = $postedQuestion["answers"] ?? [];
            $correctAnswers = $postedQuestion["correct_answers"] ?? [];
            $questions[] = [
                "question_text" => trim($postedQuestion["question_text"] ?? ""),
                "question_type" => $postedQuestion["question_type"] ?? "single_choice",
                "points" => trim($postedQuestion["points"] ?? "1"),
                "answers" => array_pad(array_map("trim", array_slice(is_array($answers) ? $answers : [], 0, 4)), 4, ""),
                "correct_answers" => array_map("intval", is_array($correctAnswers) ? $correctAnswers : []),
                "explanation" => trim($postedQuestion["explanation"] ?? "")
            ];
        }
    }

    if (!$questions) {
        $errors[] = "Ajoutez au moins une question au quiz.";
    }

    foreach ($questions as $questionNumber => &$question) {
        $number = $questionNumber + 1;
        if ($question["question_text"] === "") {
            $errors[] = "La question {$number} est obligatoire.";
        }
        if (!in_array($question["question_type"], ["single_choice", "multiple_choice", "true_false", "short_answer"], true)) {
            $errors[] = "Le type de la question {$number} est invalide.";
        }
        if (!is_numeric($question["points"]) || (float) $question["points"] <= 0 || (float) $question["points"] > 100) {
            $errors[] = "Les points de la question {$number} doivent être compris entre 0 et 100.";
        }

        if ($question["question_type"] === "true_false") {
            $question["answers"] = ["Vrai", "Faux"];
            $question["correct_answers"] = array_values(array_intersect($question["correct_answers"], [0, 1]));
        } elseif ($question["question_type"] === "short_answer") {
            $expectedAnswer = trim($question["answers"][0] ?? "");
            $question["answers"] = [$expectedAnswer, "", "", ""];
            $question["correct_answers"] = $expectedAnswer === "" ? [] : [0];
            if ($expectedAnswer === "") {
                $errors[] = "Saisissez la réponse attendue pour la question {$number}.";
            }
        } else {
            $nonEmptyAnswers = array_filter($question["answers"], static function ($answer) {
                return $answer !== "";
            });
            if (count($nonEmptyAnswers) < 2) {
                $errors[] = "La question {$number} doit avoir au moins deux réponses.";
            }
            $question["correct_answers"] = array_values(array_unique(array_filter($question["correct_answers"], static function ($index) use ($question) {
                return isset($question["answers"][$index]) && $question["answers"][$index] !== "";
            })));
        }

        if (count($question["correct_answers"]) < 1) {
            $errors[] = "Sélectionnez une bonne réponse pour la question {$number}.";
        }
        if ($question["question_type"] === "single_choice" && count($question["correct_answers"]) > 1) {
            $errors[] = "La question {$number} ne peut avoir qu'une seule bonne réponse.";
        }
    }
    unset($question);


    // ! ============================================================
    // ! 16. VERIFY MODULE ACCESS
    // ! ============================================================
    // ! We do NOT trust the module ID sent by the browser.
// !
// ! A teacher could manually modify:
// !
// ! module_id=5
// !
// ! into:
// !
// ! module_id=999
// !
// ! Therefore we check the database to make sure the selected
// ! module belongs to a course the current teacher can manage.
// !
// ! Admins are allowed to use every module.
// ! ============================================================

    if (empty($errors)) {

        if ($user_role === "admin") {

            // ! ----------------------------------------------------
            // ! ADMIN ACCESS CHECK
            // ! ----------------------------------------------------

            $access_stmt = $pdo->prepare("
                SELECT
                    m.id,
                    m.course_id
                FROM modules m
                WHERE m.id = :module_id
                LIMIT 1
            ");

            $access_stmt->execute([
                ":module_id" => $module_id
            ]);

        } else {

            // ! ----------------------------------------------------
            // ! TEACHER ACCESS CHECK
            // ! ----------------------------------------------------
            // ! The selected module must belong to a course assigned
            // ! to the current teacher.
            // ! ----------------------------------------------------

            $access_stmt = $pdo->prepare("
                SELECT
                    m.id,
                    m.course_id
                FROM modules m

                INNER JOIN courses c
                    ON c.id = m.course_id

                WHERE
                    m.id = :module_id
                    AND EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id = c.id AND ct.teacher_id = :teacher_id)

                LIMIT 1
            ");

            $access_stmt->execute([
                ":module_id" => $module_id,
                ":teacher_id" => $_SESSION["user_id"]
            ]);
        }


        // ! --------------------------------------------------------
        // ! FETCH ACCESS RESULT
        // ! --------------------------------------------------------

        $selected_module = $access_stmt->fetch();


        // ! --------------------------------------------------------
        // ! IF MODULE DOES NOT EXIST OR IS NOT ACCESSIBLE
        // ! --------------------------------------------------------

        if (!$selected_module) {

            $errors[] = "Vous n'avez pas accès à ce module.";

        }

    }


    // ! ============================================================
    // ! 17. CHECK WHETHER THE MODULE ALREADY HAS A QUIZ
    // ! ============================================================
    // ! Our current design allows us to keep one quiz associated
    // ! with a module for this V1 implementation.
    // !
// ! Before creating a quiz, check whether a quiz already exists
    // ! for the selected module.
    // ! ============================================================

    if (empty($errors)) {

        $existing_stmt = $pdo->prepare("
            SELECT id
            FROM quizzes
            WHERE module_id = :module_id
            LIMIT 1
        ");

        $existing_stmt->execute([
            ":module_id" => $module_id
        ]);

        $existing_quiz = $existing_stmt->fetch();


        if ($existing_quiz) {

            $errors[] = "Ce module possède déjà un quiz.";

        }

    }


    // ! ============================================================
    // ! 18. INSERT QUIZ
    // ! ============================================================
    // ! If there are no validation errors, insert the new quiz.
    // !
// ! We use a prepared statement for security.
    // ! ============================================================

    if (empty($errors)) {

        try {

            $insert_stmt = $pdo->prepare("
                INSERT INTO quizzes (
                    module_id,
                    title,
                    passing_score,
                    max_attempts,
                    random_order,
                    is_published
                )

                VALUES (
                    :module_id,
                    :title,
                    :passing_score,
                    :max_attempts,
                    :random_order,
                    :is_published
                )
            ");


            $pdo->beginTransaction();

            $insert_stmt->execute([

                ":module_id" => $module_id,

                ":title" => $title,

                ":passing_score" => $passing_score,

                ":max_attempts" => $max_attempts,

                ":random_order" => $random_order,

                ":is_published" => $published

            ]);


            // ! ====================================================
            // ! 19. GET THE NEW QUIZ ID
            // ! ====================================================
            // ! lastInsertId() returns the ID generated by MySQL.
            // !
// ! We need this ID to continue to the question management
            // ! page.
            // ! ====================================================

            $quiz_id = $pdo->lastInsertId();
            auditLog($pdo, (int) $_SESSION["user_id"], "quiz_created", "quiz", (int) $quiz_id, null, ["title" => $title]);

            $question_stmt = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM questions WHERE quiz_id = ?");
            $question_insert = $pdo->prepare("INSERT INTO questions (quiz_id, question_text, question_type, points, explanation, position) VALUES (?, ?, ?, ?, ?, ?)");
            $answer_insert = $pdo->prepare("INSERT INTO question_answers (question_id, answer_text, is_correct, position) VALUES (?, ?, ?, ?)");

            foreach ($questions as $question) {
                $question_stmt->execute([$quiz_id]);
                $position = (int) $question_stmt->fetchColumn();
                $question_insert->execute([
                    $quiz_id,
                    $question["question_text"],
                    $question["question_type"],
                    (float) $question["points"],
                    $question["explanation"] !== "" ? $question["explanation"] : null,
                    $position
                ]);
                $question_id = $pdo->lastInsertId();

                foreach ($question["answers"] as $answerPosition => $answer) {
                    if ($answer === "") {
                        continue;
                    }
                    $answer_insert->execute([
                        $question_id,
                        $answer,
                        in_array($answerPosition, $question["correct_answers"], true) ? 1 : 0,
                        $answerPosition + 1
                    ]);
                }
            }

            $pdo->commit();


            // ! ====================================================
            // ! 20. REDIRECT TO QUESTION MANAGEMENT
            // ! ====================================================
            // ! The quiz itself is now created.
// !
// ! The next step will be adding questions and answers.
            // ! ====================================================

            header("Location: " . ($returnToContent && $user_role === "admin"
                ? "../content.php?course_id=" . (int) $returnCourseId . "&module_id=" . (int) $module_id
                : "quizzes.php?created=1"));

            exit;


        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // ! ====================================================
            // ! DATABASE ERROR
            // ! ====================================================
            // ! We don't expose database details to the user.
            // ! This avoids revealing internal database information.
            // ! ====================================================

            $errors[] = "Une erreur est survenue lors de la création du quiz.";

        }

    }

}


// ! ================================================================
// ! 21. HTML ESCAPE FUNCTION
// ! ================================================================
// ! This function protects displayed values from XSS.
// ! ================================================================

function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

?>

<!DOCTYPE html>

<html lang="fr">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Créer un quiz</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">

    <!--
        ============================================================
        MAIN CSS
        ============================================================

        We reuse the existing stylesheet.
    -->

    <link
        rel="stylesheet"
        href="../../assets/css/style.css?v=20260925e"
    >

</head>


<body class="admin-body<?= ($_SESSION["user_role"] ?? "") === "teacher" ? " teacher-view" : "" ?>">


<div class="admin-layout">


    <!-- ============================================================
         SIDEBAR
         ============================================================ -->

    <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('quizzes/quiz-create.php'); ?>


    <!-- ============================================================
         MAIN CONTENT
         ============================================================ -->

    <main class="admin-main quiz-page">


        <!-- ========================================================
             PAGE HEADER
             ======================================================== -->

        <header class="admin-topbar page-header">

            <div>

                <h1>Créer un quiz</h1>

                <p>
                    Créer un quiz associé à un module.
                </p>

            </div>


            <div>

                <a
                    href="quizzes.php"
                    class="btn btn-secondary"
                >
                    Retour
                </a>

            </div>

        </header>


        <!-- ========================================================
             ERROR MESSAGES
             ======================================================== -->

        <?php if (!empty($errors)): ?>

            <div class="alert alert-danger">

                <ul>

                    <?php foreach ($errors as $error): ?>

                        <li>
                            <?= e($error) ?>
                        </li>

                    <?php endforeach; ?>

                </ul>

            </div>

        <?php endif; ?>


        <!-- ========================================================
             QUIZ FORM
             ======================================================== -->

        <div class="form-container">


            <form
                method="POST"
                action=""
            >


                <!-- ==================================================
                     CSRF TOKEN
                     ================================================== -->

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION["csrf_token"]) ?>"
                >
                <input type="hidden" name="return_to" value="<?= $returnToContent ? "content" : "" ?>">
                <input type="hidden" name="return_course_id" value="<?= (int) $returnCourseId ?>">


                <!-- ==================================================
                     TITLE
                     ================================================== -->

                <div class="form-group">

                    <label for="title">
                        Titre du quiz *
                    </label>

                    <input
                        type="text"
                        name="title"
                        id="title"
                        value="<?= e($title) ?>"
                        maxlength="200"
                        required
                    >

                </div>


                <!-- ==================================================
                     MODULE
                     ================================================== -->

                <div class="form-group">

                    <label for="module_id">
                        Module *
                    </label>


                    <select
                        name="module_id"
                        id="module_id"
                        required
                    >

                        <option value="">
                            -- Sélectionner un module --
                        </option>


                        <?php foreach ($modules as $module): ?>

                            <option
                                value="<?= (int) $module["id"] ?>"
                                <?= ((string) $module_id === (string) $module["id"]) ? "selected" : "" ?>
                            >

                                <?= e($module["course_title"]) ?>
                                -
                                <?= e($module["module_title"]) ?>

                            </option>

                        <?php endforeach; ?>


                    </select>

                </div>


                <!-- ==================================================
                     PASSING SCORE
                     ================================================== -->

                <div class="form-group">

                    <label for="passing_score">
                        Score de réussite (%) *
                    </label>


                    <input
                        type="number"
                        name="passing_score"
                        id="passing_score"
                        value="<?= e($passing_score) ?>"
                        min="0"
                        max="100"
                        step="0.01"
                        required
                    >


                    <small>
                        Pourcentage minimum nécessaire pour réussir le quiz.
                    </small>

                </div>


                <!-- ==================================================
                     RANDOM ORDER
                     ================================================== -->

                <div class="form-group">


                    <label>

                        <input
                            type="checkbox"
                            name="random_order"
                            value="1"
                            <?= $random_order ? "checked" : "" ?>
                        >

                        Afficher les questions dans un ordre aléatoire

                    </label>


                </div>


                <!-- ==================================================
                     PUBLISHED
                     ================================================== -->

                <div class="form-group">


                    <label>

                        <input
                            type="checkbox"
                            name="published"
                            value="1"
                            <?= $published ? "checked" : "" ?>
                        >

                        Publier le quiz immédiatement

                    </label>


                </div>


                <section class="quiz-builder">
                    <div class="quiz-builder-heading">
                        <div>
                            <h2>Questions du quiz</h2>
                            <p>Ajoutez les questions, réponses, bonnes réponses et explications maintenant.</p>
                        </div>
                        <button type="button" class="btn btn-secondary" id="add-question">+ Ajouter une question</button>
                    </div>

                    <div id="question-list">
                        <?php foreach ($questions as $questionIndex => $question): ?>
                            <fieldset class="quiz-builder-question" data-question-index="<?= $questionIndex ?>">
                                <legend>Question <span class="question-number"><?= $questionIndex + 1 ?></span></legend>

                                <div class="form-group">
                                    <label>Question *</label>
                                    <textarea name="questions[<?= $questionIndex ?>][question_text]" rows="3" required><?= e($question["question_text"]) ?></textarea>
                                </div>

                                <div class="quiz-form-grid">
                                    <div class="form-group">
                                        <label>Type *</label>
                                        <select name="questions[<?= $questionIndex ?>][question_type]" class="question-type" required>
                                            <option value="single_choice" <?= $question["question_type"] === "single_choice" ? "selected" : "" ?>>Choix unique</option>
                                            <option value="multiple_choice" <?= $question["question_type"] === "multiple_choice" ? "selected" : "" ?>>Choix multiple</option>
                                            <option value="true_false" <?= $question["question_type"] === "true_false" ? "selected" : "" ?>>Vrai / Faux</option>
                                            <option value="short_answer" <?= $question["question_type"] === "short_answer" ? "selected" : "" ?>>Réponse courte</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Points *</label>
                                        <input type="number" name="questions[<?= $questionIndex ?>][points]" min="0.01" max="100" step="0.01" value="<?= e($question["points"]) ?>" required>
                                    </div>
                                </div>

                                <div class="question-answers">
                                    <label>Réponses et bonnes réponses *</label>
                                    <p class="quiz-help answer-help">Cochez la ou les réponses correctes.</p>
                                    <?php foreach ($question["answers"] as $answerIndex => $answer): ?>
                                        <div class="quiz-answer-row">
                                            <input type="checkbox" name="questions[<?= $questionIndex ?>][correct_answers][]" value="<?= $answerIndex ?>" <?= in_array($answerIndex, $question["correct_answers"], true) ? "checked" : "" ?> aria-label="Bonne réponse">
                                            <input type="text" name="questions[<?= $questionIndex ?>][answers][]" value="<?= e($answer) ?>" maxlength="1000" placeholder="<?= $question["question_type"] === "short_answer" && $answerIndex === 0 ? "Réponse attendue" : "Réponse " . ($answerIndex + 1) ?>">
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="form-group">
                                    <label>Explication</label>
                                    <textarea name="questions[<?= $questionIndex ?>][explanation]" rows="2" placeholder="Explication affichée après la correction."><?= e($question["explanation"]) ?></textarea>
                                </div>

                                <button type="button" class="btn btn-danger remove-question">Supprimer cette question</button>
                            </fieldset>
                        <?php endforeach; ?>
                    </div>
                </section>


                <!-- ==================================================
                     ACTION BUTTONS
                     ================================================== -->

                <div class="form-actions">


                    <a
                        href="quizzes.php"
                        class="btn btn-secondary"
                    >
                        Annuler
                    </a>


                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        Créer le quiz
                    </button>


                </div>


            </form>


        </div>


    </main>


</div>

<script>
    (function () {
        const questionList = document.getElementById("question-list");
        const addQuestionButton = document.getElementById("add-question");

        function updateQuestions() {
            questionList.querySelectorAll(".quiz-builder-question").forEach(function (question, index) {
                question.dataset.questionIndex = index;
                question.querySelector(".question-number").textContent = index + 1;
                question.querySelectorAll("[name]").forEach(function (field) {
                    field.name = field.name.replace(/questions\[\d+\]/, "questions[" + index + "]");
                });
            });
            questionList.querySelectorAll(".remove-question").forEach(function (button) {
                button.disabled = questionList.children.length === 1;
            });
        }

        addQuestionButton.addEventListener("click", function () {
            const template = questionList.querySelector(".quiz-builder-question").cloneNode(true);
            template.querySelectorAll("input[type=text], textarea").forEach(function (field) {
                field.value = "";
            });
            template.querySelectorAll("input[type=checkbox]").forEach(function (field) {
                field.checked = false;
            });
            template.querySelector("select").value = "single_choice";
            questionList.appendChild(template);
            updateQuestions();
        });

        questionList.addEventListener("click", function (event) {
            if (event.target.classList.contains("remove-question") && questionList.children.length > 1) {
                event.target.closest(".quiz-builder-question").remove();
                updateQuestions();
            }
        });

        questionList.addEventListener("change", function (event) {
            if (!event.target.classList.contains("question-type")) {
                return;
            }
            const question = event.target.closest(".quiz-builder-question");
            const answerInputs = question.querySelectorAll(".quiz-answer-row input[type=text]");
            const answerRows = question.querySelectorAll(".quiz-answer-row");
            const answerHelp = question.querySelector(".answer-help");
            if (event.target.value === "true_false") {
                answerInputs[0].value = "Vrai";
                answerInputs[1].value = "Faux";
                answerInputs[0].readOnly = true;
                answerInputs[1].readOnly = true;
                answerInputs[0].closest(".quiz-answer-row").querySelector("input[type=checkbox]").checked = false;
                answerInputs[1].closest(".quiz-answer-row").querySelector("input[type=checkbox]").checked = false;
                answerRows.forEach(function (row, index) { row.style.display = index < 2 ? "flex" : "none"; });
                answerHelp.textContent = "Cochez la bonne réponse.";
            } else if (event.target.value === "short_answer") {
                answerInputs.forEach(function (input, index) {
                    input.value = index === 0 ? "" : "";
                    input.readOnly = index !== 0;
                    input.placeholder = index === 0 ? "Réponse attendue" : "";
                });
                answerRows.forEach(function (row, index) { row.style.display = index === 0 ? "flex" : "none"; });
                question.querySelector(".quiz-answer-row input[type=checkbox]").style.display = "none";
                answerHelp.textContent = "Saisissez la réponse attendue pour cette question.";
            } else {
                answerInputs.forEach(function (input, index) {
                    input.readOnly = false;
                    input.placeholder = "Réponse " + (index + 1);
                });
                answerRows.forEach(function (row) { row.style.display = "flex"; });
                question.querySelectorAll(".quiz-answer-row input[type=checkbox]").forEach(function (checkbox) { checkbox.style.display = "inline-block"; });
                answerHelp.textContent = "Cochez la ou les réponses correctes.";
            }
        });

        updateQuestions();
        questionList.querySelectorAll(".question-type").forEach(function (select) {
            select.dispatchEvent(new Event("change"));
        });
    }());
</script>


<script src="../../assets/js/teacher-navigation.js?v=20260925b"></script>
<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script></body>

</html>
```

