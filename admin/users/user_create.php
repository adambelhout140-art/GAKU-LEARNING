<?php
require_once __DIR__ . '/../../config/i18n.php';// ! ADMIN USER CREATION PAGE
// * Creates accounts for students, company managers, teachers, and administrators.
// ? Validation happens on the server before any user-controlled value reaches SQL.

// ============================================================
// START SESSION
// ============================================================
// We need the session because we use $_SESSION to know
// which user is currently connected.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


// ============================================================
// DATABASE CONNECTION
// ============================================================
// Because this file is inside:
// admin/users/
//
// We need to go two folders back:
// ../../
//
// Then we reach:
// config/database.php
require_once "../../config/database.php";
require_once "../../config/audit.php";


// ============================================================
// CHECK IF THE USER IS LOGGED IN
// ============================================================
// If there is no user_id in the session, the visitor is not
// authenticated.
//
// We redirect them to the login page.
if (!isset($_SESSION["user_id"])) {

    header("Location: ../../auth/login.php");

    exit;
}


// ============================================================
// CHECK ADMIN ROLE
// ============================================================
// In login_process.php, the user's role is stored as:
//
// $_SESSION["user_role"]
//
// Therefore, we must use the exact same session variable here.
//
// Only an administrator can access this page.
if (!isset($_SESSION["user_role"]) || $_SESSION["user_role"] !== "admin") {

    // If the connected user is not an administrator,
    // redirect them to the public homepage.
    header("Location: ../../index.php");

    // Stop executing the rest of the page.
    exit;
}

// ! CSRF TOKEN
// * Forms reuse one session token so forged POST requests are rejected.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// * Company choices come from the database instead of being trusted from the browser.
$companiesStatement = $pdo->query("SELECT id, company_name FROM companies ORDER BY company_name ASC");
$companies = $companiesStatement->fetchAll();
$studentsStatement = $pdo->query("SELECT id, first_name, last_name, email FROM users WHERE role = 'student' ORDER BY last_name, first_name");
$students = $studentsStatement->fetchAll();


// ============================================================
// VARIABLES
// ============================================================
// These variables will contain the values entered in the form.
//
// We use empty strings initially so the form can be displayed
// without errors.
$first_name = "";
$last_name = "";
$email = "";
$telephone = "";
$date_of_birth = "";
$cin = "";
$education = "";
$job_title = "";
$company_id = "";
$role = "student";
$is_active = 0;
$child_ids = [];
$parent_first_name = "";
$parent_last_name = "";
$parent_email = "";
$parent_phone = "";
$parent_date_of_birth = "";
$parent_cin = "";
$parent_password = "";
$parent_password_confirmation = "";
$hasParent = false;


// This variable will contain an error message if something
// goes wrong.
$error = "";


// This variable will contain a success message after the
// user has been successfully created.
$success = "";


// ============================================================
// FORM SUBMISSION
// ============================================================
// $_SERVER["REQUEST_METHOD"] tells us which HTTP method
// was used.
//
// When the user clicks "Créer l'utilisateur", the form uses POST.
if ($_SERVER["REQUEST_METHOD"] === "POST") {


    // ========================================================
    // GET FORM VALUES
    // ========================================================
    // trim() removes unnecessary spaces at the beginning
    // and end of text fields.
    $first_name = trim($_POST["first_name"] ?? "");

    $last_name = trim($_POST["last_name"] ?? "");

    $email = trim($_POST["email"] ?? "");

    $telephone = trim($_POST["telephone"] ?? "");
    $date_of_birth = trim($_POST["date_of_birth"] ?? "");
    $cin = trim($_POST["cin"] ?? "");
    $education = trim($_POST["education"] ?? "");
    $job_title = trim($_POST["job_title"] ?? "");

    $password = $_POST["password"] ?? "";

    $password_confirmation = $_POST["password_confirmation"] ?? "";

    $company_id = trim($_POST["company_id"] ?? "");

    $role = $_POST["role"] ?? "student";

    $is_active = isset($_POST["is_active"]) ? 1 : 0;
    $child_ids = array_values(array_filter(array_map("intval", $_POST["child_ids"] ?? [])));
    $parent_first_name = trim($_POST["parent_first_name"] ?? "");
    $parent_last_name = trim($_POST["parent_last_name"] ?? "");
    $parent_email = trim($_POST["parent_email"] ?? "");
    $parent_phone = trim($_POST["parent_phone"] ?? "");
    $parent_date_of_birth = trim($_POST["parent_date_of_birth"] ?? "");
    $parent_cin = trim($_POST["parent_cin"] ?? "");
    $parent_password = $_POST["parent_password"] ?? "";
    $parent_password_confirmation = $_POST["parent_password_confirmation"] ?? "";
    $hasParent = $parent_first_name !== "" || $parent_last_name !== "" || $parent_email !== "" || $parent_phone !== "" || $parent_password !== "";

    if ($role !== "student") {
        $education = "";
        $parent_first_name = "";
        $parent_last_name = "";
        $parent_email = "";
        $parent_phone = "";
        $parent_date_of_birth = "";
        $parent_cin = "";
        $parent_password = "";
        $parent_password_confirmation = "";
        $hasParent = false;
    }
    if (!in_array($role, ["student", "parent", "teacher"], true)) {
        $date_of_birth = "";
        $cin = "";
    }
    if (!in_array($role, ["teacher", "company_manager"], true)) {
        $job_title = "";
    }
    if ($role !== "parent") {
        $child_ids = [];
    }
    if ($role !== "company_manager") {
        $company_id = "";
    }


    // ========================================================
    // VALIDATION
    // ========================================================
    // We check that the required fields are not empty.
    // ! Validate the request before checking fields or attempting the insert.
    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {

        $error = "La session du formulaire a expiré. Veuillez réessayer.";

    } elseif (
        $first_name === "" ||
        $last_name === "" ||
        $email === "" ||
        $password === "" ||
        $password_confirmation === ""
    ) {

        $error = "Veuillez remplir tous les champs obligatoires.";

    }


    // ========================================================
    // CHECK EMAIL FORMAT
    // ========================================================
    // filter_var() checks whether the entered email has
    // a valid email format.
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Veuillez entrer une adresse email valide.";

    }


    elseif ($date_of_birth !== "") {

        $dateOfBirthObject = DateTimeImmutable::createFromFormat("Y-m-d", $date_of_birth);
        if (!$dateOfBirthObject || $dateOfBirthObject->format("Y-m-d") !== $date_of_birth) {
            $error = "La date de naissance est invalide.";
        }

    }

    elseif ($role === "student" && ($date_of_birth === "" || $cin === "" || $education === "")) {

        $error = "Pour un apprenant, la date de naissance, le CIN et les études / établissement sont obligatoires.";

    }

    elseif ($role === "student" && $hasParent && ($parent_first_name === "" || $parent_last_name === "" || !filter_var($parent_email, FILTER_VALIDATE_EMAIL) || $parent_date_of_birth === "" || $parent_cin === "")) {

        $error = "Veuillez compléter les informations du parent, y compris la date de naissance et le CIN.";

    }

    elseif ($role === "student" && $hasParent && $parent_date_of_birth !== "") {

        $parentDateOfBirthObject = DateTimeImmutable::createFromFormat("Y-m-d", $parent_date_of_birth);
        if (!$parentDateOfBirthObject || $parentDateOfBirthObject->format("Y-m-d") !== $parent_date_of_birth) {
            $error = "La date de naissance du parent est invalide.";
        }

    }

    elseif ($role === "student" && $hasParent && strlen($parent_cin) > 50) {

        $error = "Le CIN du parent ne doit pas dépasser 50 caractères.";

    }

    elseif ($role === "student" && $hasParent && strlen($parent_password) < 8) {

        $error = "Le mot de passe parent doit contenir au moins 8 caractères.";

    }

    elseif ($role === "student" && $hasParent && $parent_password !== $parent_password_confirmation) {

        $error = "Les mots de passe parent ne correspondent pas.";

    }

    elseif ($role === "student" && $hasParent && strcasecmp($email, $parent_email) === 0) {

        $error = "L'email du parent doit être différent de celui de l'étudiant.";

    }

    elseif (strlen($cin) > 50) {

        $error = "Le CIN ne doit pas dépasser 50 caractères.";

    }

    elseif (strlen($education) > 255) {

        $error = "Les études / établissement ne doivent pas dépasser 255 caractères.";

    }

    // ========================================================
    // CHECK PASSWORD LENGTH
    // ========================================================
    // We require at least 8 characters.
    elseif (strlen($password) < 8) {

        $error = "Le mot de passe doit contenir au moins 8 caractères.";

    }

    elseif ($password !== $password_confirmation) {

        $error = "Les mots de passe ne correspondent pas.";

    }


    // ========================================================
    // CHECK ROLE
    // ========================================================
    // We only allow roles that exist in our application.
    //
    // This is important because we should never blindly
    // accept a role sent by the browser.
    elseif (
        !in_array(
            $role,
            ["student", "parent", "company_manager", "teacher", "admin"],
            true
        )
    ) {

        $error = "Le rôle sélectionné est invalide.";

    }

    // ? A company manager must belong to a company; an individual student may remain unassigned.
    elseif ($company_id !== "" && filter_var($company_id, FILTER_VALIDATE_INT) === false) {

        $error = "L'entreprise sélectionnée est invalide.";

    }

    elseif ($role === "company_manager" && $company_id === "") {

        $error = "Veuillez sélectionner une entreprise pour ce responsable.";

    }

    elseif (in_array($role, ["teacher", "company_manager"], true) && $job_title === "") {

        $error = "Veuillez renseigner le poste pour ce rôle.";

    }


    // ========================================================
    // IF EVERYTHING IS VALID
    // ========================================================
    if ($error === "") {


        // ====================================================
        // CHECK IF EMAIL ALREADY EXISTS
        // ====================================================
        // Email addresses must be unique.
        //
        // We use a prepared statement to protect against
        // SQL injection.
        $checkEmail = $pdo->prepare("
            SELECT id
            FROM users
            WHERE email = ?
            LIMIT 1
        ");

        $checkEmail->execute([$email]);


        // fetch() returns the user if the email already exists.
        if ($checkEmail->fetch()) {

            $error = "Cette adresse email est déjà utilisée.";

        } else {
            if ($role === "student" && $hasParent) {
                $parentEmailCheck = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
                $parentEmailCheck->execute([$parent_email]);
                if ($parentEmailCheck->fetch()) {
                    $error = "L'adresse email du parent est déjà utilisée.";
                }
            }

        }

        if ($error === "") {

            // =================================================
            // HASH PASSWORD
            // =================================================
            // NEVER save the user's real password directly
            // inside the database.
            //
            // password_hash() converts the password into a
            // secure hash.
            //
            // Later, login.php will use password_verify()
            // to check the password.
            $hashedPassword = password_hash(
                $password,
                PASSWORD_DEFAULT
            );


            // =================================================
            // INSERT USER
            // =================================================
            // We insert only columns that currently exist
            // in our users table:
            //
            // first_name
            // last_name
            // email
            // password
            // role
            // is_active
            // phone
            // company_id
            //
            // * The password is hashed and company_id is stored as NULL when no company was selected.
            $insertUser = $pdo->prepare("
                INSERT INTO users (
                    company_id,
                    first_name,
                    last_name,
                    date_of_birth,
                    cin,
                    education,
                    job_title,
                    email,
                    password,
                    role,
                    is_active,
                    phone
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");


            // =================================================
            // EXECUTE INSERT
            // =================================================
            // The values are passed separately from the SQL
            // query.
            //
            // This is another protection against SQL injection.
            try {
                $pdo->beginTransaction();
                $insertUser->execute([
                $company_id === "" ? null : (int) $company_id,
                $first_name,
                $last_name,
                $date_of_birth === "" ? null : $date_of_birth,
                $cin === "" ? null : $cin,
                $education === "" ? null : $education,
                $job_title === "" ? null : $job_title,
                $email,
                $hashedPassword,
                $role,
                $is_active,
                $telephone
                ]);
                $newUserId = (int) $pdo->lastInsertId();
            if ($role === "parent" && $child_ids) {
                $linkStatement = $pdo->prepare("INSERT IGNORE INTO parent_student_links (parent_id, student_id) VALUES (?, ?)");
                foreach ($child_ids as $childId) {
                    $linkStatement->execute([$newUserId, $childId]);
                }
                }
                if ($role === "student" && $hasParent) {
                $insertParent = $pdo->prepare("INSERT INTO users (first_name, last_name, date_of_birth, cin, email, password, role, is_active, phone) VALUES (?, ?, ?, ?, ?, ?, 'parent', 0, ?)");
                $insertParent->execute([$parent_first_name, $parent_last_name, $parent_date_of_birth, $parent_cin, $parent_email, password_hash($parent_password, PASSWORD_DEFAULT), $parent_phone ?: null]);
                $parentId = (int) $pdo->lastInsertId();
                $linkStatement = $pdo->prepare("INSERT INTO parent_student_links (parent_id, student_id) VALUES (?, ?)");
                $linkStatement->execute([$parentId, $newUserId]);
                }
                $pdo->commit();
                auditLog($pdo, (int) $_SESSION["user_id"], "user_created", "user", $newUserId, null, ["role" => $role]);


            // =================================================
            // SUCCESS
            // =================================================
            // After creating the user, we redirect to users.php.
            //
            // This prevents the form from being submitted again
            // if the administrator refreshes the page.
                header("Location: users.php?success=1");

                exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "La création du compte n'a pas pu être terminée.";
        }
    }
}
}

?>

<!DOCTYPE html>
<html lang="fr">

<head>

    <!-- =====================================================
         CHARACTER ENCODING
         ===================================================== -->
    <meta charset="UTF-8">


    <!-- =====================================================
         RESPONSIVE DESIGN
         ===================================================== -->
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >


    <!-- =====================================================
         PAGE TITLE
         ===================================================== -->
    <title>Ajouter un utilisateur - Administration</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">


    <!-- =====================================================
         CSS
         ===================================================== -->
    <!--
        This file is located in:

        admin/users/user_create.php

        Therefore we need ../../ to reach the project root.
    -->
    <link
        rel="stylesheet"
        href="../../assets/css/style.css?v=20260925e"
    >

</head>


<body class="admin-body">


<div class="admin-layout">


    <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('users/user_create.php'); ?>



    <!-- =====================================================
         MAIN CONTENT
         ===================================================== -->
    <main class="admin-main">


        <!-- =================================================
             TOP BAR
             ================================================= -->
        <header class="admin-topbar">

            <div>

                <h1>Ajouter un utilisateur</h1>

                <p>
                    Créer un nouveau compte utilisateur
                </p>
                

            </div>


            <div class="admin-user">

                <div class="admin-avatar">
                    A
                </div>

                <div>

                    <strong>Administrateur</strong>

                    <small>Admin</small>

                </div>

            </div>

        </header>



        <!-- =================================================
             CONTENT
             ================================================= -->
        <section class="dashboard-section">


            <!-- =================================================
                 ERROR MESSAGE
                 ================================================= -->
            <?php if ($error !== ""): ?>

                <div class="alert alert-danger">

                    <?php echo htmlspecialchars($error); ?>

                </div>

            <?php endif; ?>



            <!-- =================================================
                 FORM CARD
                 ================================================= -->
            <div class="form-card">


                <!-- FORM TITLE -->
                <div class="form-card-header">

                    <div>

                        <h2>Informations de l'utilisateur</h2>

                        <p>
                            Remplissez les informations du nouveau compte.
                        </p>

                    </div>

                </div>



                <!-- =================================================
                     FORM
                     ================================================= -->
                <form
                    method="POST"
                    action=""
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>"
                    >


                    <!-- =================================================
                         FIRST NAME
                         ================================================= -->
                    <div class="form-group">

                        <label for="first_name">
                            Prénom *
                        </label>

                        <input
                            type="text"
                            id="first_name"
                            name="first_name"
                            value="<?php echo htmlspecialchars($first_name); ?>"
                            placeholder="Ex : Ahmed"
                            required
                        >

                    </div>



                    <!-- =================================================
                         LAST NAME
                         ================================================= -->
                    <div class="form-group">

                        <label for="last_name">
                            Nom *
                        </label>

                        <input
                            type="text"
                            id="last_name"
                            name="last_name"
                            value="<?php echo htmlspecialchars($last_name); ?>"
                            placeholder="Ex : Ben Ali"
                            required
                        >

                    </div>



                    <!-- =================================================
                         EMAIL
                         ================================================= -->
                    <div class="form-group">

                        <label for="email">
                            Adresse email *
                        </label>

                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="<?php echo htmlspecialchars($email); ?>"
                            placeholder="Ex : ahmed@example.com"
                            required
                        >

                    </div>



                    <!-- =================================================
                         PHONE
                         ================================================= -->
                    <div class="form-group">

                        <label for="telephone">
                            Téléphone
                        </label>

                        <input
                            type="tel"
                            id="telephone"
                            name="telephone"
                            value="<?php echo htmlspecialchars($telephone); ?>"
                            placeholder="Ex : +216 20 123 456"
                            autocomplete="tel"
                        >

                    </div>

                    <div class="form-group role-field role-personal" data-roles="student,parent,teacher">
                        <label for="date_of_birth">Date de naissance</label>
                        <input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars($date_of_birth); ?>">
                    </div>
                    <div class="form-group role-field role-personal" data-roles="student,parent,teacher">
                        <label for="cin">CIN</label>
                        <input type="text" id="cin" name="cin" maxlength="50" value="<?php echo htmlspecialchars($cin); ?>">
                    </div>
                    <div class="form-group role-field role-personal" data-roles="student">
                        <label for="education">Études / établissement</label>
                        <input type="text" id="education" name="education" maxlength="255" value="<?php echo htmlspecialchars($education); ?>">
                    </div>
                    <div class="form-group role-field" data-roles="teacher,company_manager">
                        <label for="job_title">Poste dans l'entreprise</label>
                        <input type="text" id="job_title" name="job_title" maxlength="150" value="<?php echo htmlspecialchars($job_title); ?>">
                    </div>



                    <!-- =================================================
                         PASSWORD
                         ================================================= -->
                    <div class="form-group">

                        <label for="password">
                            Mot de passe *
                        </label>

                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Minimum 8 caractères"
                            minlength="8"
                            required
                        >

                        <small>
                            Le mot de passe sera automatiquement sécurisé
                            avant d'être enregistré.
                        </small>

                    </div>



                    <!-- =================================================
                         PASSWORD CONFIRMATION
                         ================================================= -->
                    <div class="form-group">

                        <label for="password_confirmation">
                            Confirmer le mot de passe *
                        </label>

                        <input
                            type="password"
                            id="password_confirmation"
                            name="password_confirmation"
                            placeholder="Retapez le mot de passe"
                            minlength="8"
                            required
                        >

                    </div>



                    <!-- =================================================
                         ROLE
                         ================================================= -->
                    <div class="form-group">

                        <label for="role">
                            Rôle *
                        </label>

                        <select
                            id="role"
                            name="role"
                            required
                        >

                            <option
                                value="student"
                                <?php echo $role === "student" ? "selected" : ""; ?>
                            >
                                Apprenant
                            </option>


                            <option
                                value="company_manager"
                                <?php echo $role === "company_manager" ? "selected" : ""; ?>
                            >
                                Responsable entreprise
                            </option>

                            <option value="parent" <?php echo $role === "parent" ? "selected" : ""; ?>>
                                Parent
                            </option>

                            <option
                                value="teacher"
                                <?php echo $role === "teacher" ? "selected" : ""; ?>
                            >
                                Formateur
                            </option>


                            <option
                                value="admin"
                                <?php echo $role === "admin" ? "selected" : ""; ?>
                            >
                                Administrateur
                            </option>

                        </select>

                    </div>

                    <details class="form-group role-field user-create-parent" data-roles="student"<?= $hasParent || $error !== "" ? " open" : "" ?>>
                        <summary>Ajouter un parent (facultatif)</summary>
                        <p class="form-help">Le parent sera créé en même temps que l'étudiant et restera inactif jusqu'à son activation.</p>
                        <div class="user-create-parent-grid">
                            <div>
                                <label for="parent_first_name">Prénom du parent</label>
                                <input type="text" id="parent_first_name" name="parent_first_name" value="<?= htmlspecialchars($parent_first_name, ENT_QUOTES, "UTF-8") ?>">
                            </div>
                            <div>
                                <label for="parent_last_name">Nom du parent</label>
                                <input type="text" id="parent_last_name" name="parent_last_name" value="<?= htmlspecialchars($parent_last_name, ENT_QUOTES, "UTF-8") ?>">
                            </div>
                        </div>
                        <div class="user-create-parent-grid">
                            <div>
                                <label for="parent_date_of_birth">Date de naissance du parent *</label>
                                <input type="date" id="parent_date_of_birth" name="parent_date_of_birth" value="<?= htmlspecialchars($parent_date_of_birth, ENT_QUOTES, "UTF-8") ?>">
                            </div>
                            <div>
                                <label for="parent_cin">CIN du parent *</label>
                                <input type="text" id="parent_cin" name="parent_cin" maxlength="50" value="<?= htmlspecialchars($parent_cin, ENT_QUOTES, "UTF-8") ?>">
                            </div>
                        </div>
                        <label for="parent_email">Email du parent</label>
                        <input type="email" id="parent_email" name="parent_email" value="<?= htmlspecialchars($parent_email, ENT_QUOTES, "UTF-8") ?>">
                        <label for="parent_phone">Téléphone du parent</label>
                        <input type="tel" id="parent_phone" name="parent_phone" value="<?= htmlspecialchars($parent_phone, ENT_QUOTES, "UTF-8") ?>">
                        <div class="user-create-parent-grid">
                            <div>
                                <label for="parent_password">Mot de passe parent</label>
                                <input type="password" id="parent_password" name="parent_password" minlength="8" autocomplete="new-password">
                            </div>
                            <div>
                                <label for="parent_password_confirmation">Confirmer le mot de passe parent</label>
                                <input type="password" id="parent_password_confirmation" name="parent_password_confirmation" minlength="8" autocomplete="new-password">
                            </div>
                        </div>
                    </details>

                    <div class="form-group role-field" data-roles="parent">
                        <label for="child_search">Enfants liés</label>
                        <input type="search" id="child_search" class="child-search" placeholder="Rechercher puis sélectionner un enfant..." autocomplete="off">
                        <div class="child-suggestions" data-child-suggestions></div>
                        <div class="child-chips" data-child-chips>
                            <?php foreach ($students as $student): ?>
                                <?php $studentLabel = trim($student["first_name"] . " " . $student["last_name"]) . " — " . $student["email"]; ?>
                                <?php if (in_array((int) $student["id"], $child_ids, true)): ?>
                                    <span class="child-chip" data-child-id="<?= (int) $student["id"] ?>"><?= htmlspecialchars($studentLabel, ENT_QUOTES, "UTF-8") ?><button type="button" aria-label="Retirer">×</button><input type="hidden" name="child_ids[]" value="<?= (int) $student["id"] ?>"></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <div class="child-student-data" hidden>
                            <?php foreach ($students as $student): ?>
                                <?php $studentLabel = trim($student["first_name"] . " " . $student["last_name"]) . " — " . $student["email"]; ?>
                                <span data-child-id="<?= (int) $student["id"] ?>" data-child-label="<?= htmlspecialchars($studentLabel, ENT_QUOTES, "UTF-8") ?>"></span>
                            <?php endforeach; ?>
                        </div>
                        <small>Sélectionnez les enfants uniquement pour un compte parent.</small>
                    </div>



                    <!-- =================================================
                         COMPANY
                         ================================================= -->
                    <div class="form-group role-field" data-roles="company_manager">

                        <label for="company_id">
                            Entreprise
                        </label>

                        <select
                            id="company_id"
                            name="company_id"
                        >

                            <option value="">Aucune entreprise</option>

                            <?php foreach ($companies as $company): ?>

                                <option
                                    value="<?php echo (int) $company["id"]; ?>"
                                    <?php echo (string) $company_id === (string) $company["id"] ? "selected" : ""; ?>
                                >
                                    <?php echo htmlspecialchars($company["company_name"]); ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                        <small>
                            Obligatoire pour un responsable entreprise.
                        </small>

                    </div>



                    <!-- =================================================
                         ACTIVE STATUS
                         ================================================= -->
                    <div class="form-group checkbox-group">

                        <label>

                            <input
                                type="checkbox"
                                name="is_active"
                                value="1"
                                <?php echo $is_active ? "checked" : ""; ?>
                            >

                            Compte actif

                        </label>

                        <small>
                            Les nouveaux comptes sont inactifs par défaut. Cochez cette case uniquement lorsque le compte doit pouvoir se connecter immédiatement.
                        </small>

                    </div>



                    <!-- =================================================
                         FORM BUTTONS
                         ================================================= -->
                    <div class="form-actions">


                        <!-- CANCEL -->
                        <a
                            href="users.php"
                            class="btn btn-secondary"
                        >
                            Annuler
                        </a>


                        <!-- CREATE -->
                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            Créer l'utilisateur
                        </button>


                    </div>


                </form>


            </div>


        </section>


    </main>


</div>


<style>
.role-field[hidden] {
    display: none;
}
.user-create-parent {
    border: 1px solid #dbe7f3;
    border-radius: 12px;
    padding: 1rem;
}
.user-create-parent summary {
    cursor: pointer;
    font-weight: 700;
}
.user-create-parent-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}
.user-create-parent-grid label {
    display: block;
    margin-bottom: .35rem;
}
.form-help {
    color: #627d98;
    margin: .5rem 0 0;
}
.child-search {
    width: 100%;
    margin: .65rem 0 0;
    min-height: 44px;
    border: 1px solid #bcccdc;
    border-radius: 10px;
    padding: .7rem .85rem;
    background: #fff;
    color: #102a43;
    box-sizing: border-box;
}
.child-suggestions {
    position: absolute;
    z-index: 2;
    left: 0;
    right: 0;
    max-height: 180px;
    overflow-y: auto;
    border: 1px solid #bcccdc;
    border-radius: 0 0 10px 10px;
    background: #fff;
    box-shadow: 0 8px 18px rgba(16, 42, 67, .14);
}
.role-field[data-roles="parent"] {
    position: relative;
}
.child-suggestion {
    padding: .7rem .85rem;
    cursor: pointer;
    color: #243b53;
}
.child-suggestion:hover {
    background: #f0f4f8;
}
.child-chips {
    display: flex;
    flex-wrap: wrap;
    gap: .4rem;
    min-height: 18px;
    margin-top: .65rem;
}
.child-chip {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    max-width: 100%;
    padding: .45rem .55rem .45rem .75rem;
    border-radius: 999px;
    background: #e8f1ff;
    border: 1px solid #b8d0f5;
    color: #163a63;
    font-size: .9rem;
}
.child-chip button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border: 0;
    background: transparent;
    cursor: pointer;
    font-size: 1.1rem;
    line-height: 1;
    color: #486581;
}
.child-chip button:hover {
    color: #c53030;
}
@media (max-width: 640px) {
    .user-create-parent-grid {
        grid-template-columns: 1fr;
    }
}
</style>
<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script>
<script>
(() => {
    const roleSelect = document.getElementById("role");
    const roleFields = Array.from(document.querySelectorAll(".role-field"));
    const companySelect = document.getElementById("company_id");
    const parentSection = document.querySelector(".user-create-parent");

    function updateRoleFields() {
        const role = roleSelect.value;

        roleFields.forEach((field) => {
            const roles = (field.dataset.roles || "").split(",");
            const visible = roles.includes(role);
            field.hidden = !visible;
            field.querySelectorAll("input, select, textarea").forEach((input) => {
                input.disabled = !visible;
            });
        });

        if (companySelect) {
            companySelect.required = role === "company_manager";
        }

        const requiredByRole = {
            date_of_birth: role === "student",
            cin: role === "student",
            education: role === "student",
            job_title: role === "teacher" || role === "company_manager"
        };

        Object.entries(requiredByRole).forEach(([id, required]) => {
            const input = document.getElementById(id);
            if (input) {
                input.required = required;
            }
        });

        ["parent_first_name", "parent_last_name", "parent_email", "parent_date_of_birth", "parent_cin", "parent_password", "parent_password_confirmation"].forEach((id) => {
            const input = document.getElementById(id);
            if (input) {
                input.required = role === "student" && parentSection && parentSection.open;
            }
        });
    }

    roleSelect.addEventListener("change", updateRoleFields);
    if (parentSection) {
        parentSection.addEventListener("toggle", updateRoleFields);
    }
    document.querySelectorAll(".child-search").forEach((search) => {
        const field = search.closest(".role-field");
        const suggestions = field.querySelector("[data-child-suggestions]");
        const chips = field.querySelector("[data-child-chips]");
        const students = Array.from(field.querySelectorAll(".child-student-data [data-child-id]"));
        const renderSuggestions = () => {
            const query = search.value.trim().toLowerCase();
            suggestions.innerHTML = "";
            if (!query) return;
            const selected = new Set(Array.from(chips.querySelectorAll("input[name='child_ids[]']")).map((input) => input.value));
            students.filter((student) => !selected.has(student.dataset.childId) && student.dataset.childLabel.toLowerCase().includes(query)).slice(0, 8).forEach((student) => {
                const option = document.createElement("div");
                option.className = "child-suggestion";
                option.textContent = student.dataset.childLabel;
                option.addEventListener("mousedown", (event) => {
                    event.preventDefault();
                    const chip = document.createElement("span");
                    chip.className = "child-chip";
                    chip.dataset.childId = student.dataset.childId;
                    chip.textContent = student.dataset.childLabel;
                    const remove = document.createElement("button");
                    remove.type = "button";
                    remove.setAttribute("aria-label", "Retirer");
                    remove.textContent = "×";
                    remove.addEventListener("click", () => chip.remove());
                    const input = document.createElement("input");
                    input.type = "hidden";
                    input.name = "child_ids[]";
                    input.value = student.dataset.childId;
                    chip.append(remove, input);
                    chips.appendChild(chip);
                    search.value = "";
                    suggestions.innerHTML = "";
                });
                suggestions.appendChild(option);
            });
        };
        chips.querySelectorAll(".child-chip button").forEach((button) => button.addEventListener("click", () => button.closest(".child-chip").remove()));
        search.addEventListener("input", renderSuggestions);
        search.addEventListener("blur", () => setTimeout(() => { suggestions.innerHTML = ""; }, 150));
    });
    updateRoleFields();
})();
</script>
</body>

</html>
