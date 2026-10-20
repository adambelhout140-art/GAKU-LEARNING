<?php
require_once __DIR__ . '/../../config/i18n.php';// ! ADMIN USERS DIRECTORY
// * Lists, filters, views, edits, and removes platform user accounts.
// ? All SQL uses prepared statements and all destructive actions use a CSRF-protected POST form.

/*
|--------------------------------------------------------------------------
| USERS MANAGEMENT PAGE
|--------------------------------------------------------------------------
| This page allows the administrator to:
|
| - View all users
| - Search users
| - Filter users by role
| - See whether an account is active or inactive
| - Access the future edit page
|
| IMPORTANT:
| This file is located at:
|
| C:\wamp64\www\formation-platform\admin\users\users.php
|
| Therefore:
|
| ../../config/database.php
|
| means:
| users -> admin -> formation-platform -> config
|
| And:
|
| ../../assets/css/style.css
|
| means:
| users -> admin -> formation-platform -> assets -> css
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| START SESSION
|--------------------------------------------------------------------------
| We need the session to know which user is currently connected.
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION
|--------------------------------------------------------------------------
| The database.php file is two folders above this file.
|--------------------------------------------------------------------------
*/

require_once "../../config/database.php";


/*
|--------------------------------------------------------------------------
| SECURITY CHECK
|--------------------------------------------------------------------------
| First, we check if a user is logged in.
|
| If there is no user_id in the session, the user is sent to login.
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {

    header("Location: ../../auth/login.php");

    exit;
}


/*
|--------------------------------------------------------------------------
| ADMIN SECURITY CHECK
|--------------------------------------------------------------------------
| Only administrators are allowed to access this page.
|
| This is important because simply hiding a page from the menu
| is NOT enough for security.
|
| Someone could manually type:
|
| http://localhost/formation-platform/admin/users/users.php
|
| So we verify the role on the server.
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_role"]) || $_SESSION["user_role"] !== "admin") {

    header("Location: ../../index.php");

    exit;
}

// ! CSRF TOKEN FOR DELETE FORMS
// * The list page prints this token into every delete form.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}


/*
|--------------------------------------------------------------------------
| SEARCH VALUE
|--------------------------------------------------------------------------
| We retrieve the search value from the URL.
|
| Example:
|
| users.php?search=adam
|
| If there is no search value, we use an empty string.
|--------------------------------------------------------------------------
*/

$search = trim($_GET["search"] ?? "");


/*
|--------------------------------------------------------------------------
| ROLE FILTER
|--------------------------------------------------------------------------
| We retrieve the selected role.
|
| Possible values:
|
| student
| company_manager
| teacher
| admin
|--------------------------------------------------------------------------
*/

$role = $_GET["role"] ?? "";


/*
|--------------------------------------------------------------------------
| BASE SQL QUERY
|--------------------------------------------------------------------------
| We only select columns that are currently used by this page.
|
| Company information is included when available.
|--------------------------------------------------------------------------
*/

// * LEFT JOIN keeps individual users in the result even when company_id is NULL.
$sql = "
    SELECT
        users.id,
        users.first_name,
        users.last_name,
        users.email,
        companies.company_name,
        users.role,
        users.is_active,
        users.created_at
    FROM users
    LEFT JOIN companies ON companies.id = users.company_id
    WHERE 1 = 1
";


/*
|--------------------------------------------------------------------------
| SQL PARAMETERS
|--------------------------------------------------------------------------
| We store parameters here for PDO prepared statements.
|
| Prepared statements help protect the application from SQL injection.
|--------------------------------------------------------------------------
*/

$params = [];


/*
|--------------------------------------------------------------------------
| SEARCH USERS
|--------------------------------------------------------------------------
| If the administrator entered a search term, we search inside:
|
| - first_name
| - last_name
| - email
|
| Example:
|
| Search: Adam
|
| The query can find:
|
| Adam
| Adam Smith
| adam@gmail.com
|--------------------------------------------------------------------------
*/

if ($search !== "") {

    $sql .= "
        AND (
            users.first_name LIKE :search
            OR users.last_name LIKE :search
            OR users.email LIKE :search
        )
    ";

    /*
    |--------------------------------------------------------------------------
    | SEARCH PARAMETER
    |--------------------------------------------------------------------------
    | The % characters mean:
    |
    | %adam%
    |
    | This means that "adam" can appear anywhere in the value.
    |--------------------------------------------------------------------------
    */

    $params["search"] = "%" . $search . "%";
}


/*
|--------------------------------------------------------------------------
| ROLE FILTER
|--------------------------------------------------------------------------
| If the administrator selected a role, we add a role condition.
|--------------------------------------------------------------------------
*/

if ($role !== "") {

    $sql .= " AND users.role = :role";

    $params["role"] = $role;
}


/*
|--------------------------------------------------------------------------
| ORDER USERS
|--------------------------------------------------------------------------
| Newest users appear first.
|--------------------------------------------------------------------------
*/

$sql .= " ORDER BY users.created_at DESC";


/*
|--------------------------------------------------------------------------
| PREPARE QUERY
|--------------------------------------------------------------------------
| PDO prepares the SQL query before executing it.
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare($sql);


/*
|--------------------------------------------------------------------------
| EXECUTE QUERY
|--------------------------------------------------------------------------
| We execute the query using our parameters.
|--------------------------------------------------------------------------
*/

$stmt->execute($params);


/*
|--------------------------------------------------------------------------
| GET USERS
|--------------------------------------------------------------------------
| fetchAll() retrieves all users returned by the query.
|--------------------------------------------------------------------------
*/

$users = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| ROLE LABEL FUNCTION
|--------------------------------------------------------------------------
| The database stores English technical role names.
|
| We convert them to French labels for the administrator interface.
|--------------------------------------------------------------------------
*/

function getRoleLabel($role)
{
    switch ($role) {

        case "student":
            return "Apprenant";

        case "parent":
            return "Parent";

        case "company_manager":
            return "Responsable entreprise";

        case "teacher":
            return "Formateur";

        case "admin":
            return "Administrateur";

        default:
            return $role;
    }
}


/*
|--------------------------------------------------------------------------
| ROLE CSS CLASS FUNCTION
|--------------------------------------------------------------------------
| Each role receives a different CSS class.
|
| Example:
|
| role-student
| role-teacher
| role-admin
|--------------------------------------------------------------------------
*/

function getRoleClass($role)
{
    switch ($role) {

        case "student":
            return "role-student";

        case "parent":
            return "role-parent";

        case "company_manager":
            return "role-company";

        case "teacher":
            return "role-teacher";

        case "admin":
            return "role-admin";

        default:
            return "";
    }
}

?>


<!DOCTYPE html>

<html lang="fr">

<head>

    <!--
    |--------------------------------------------------------------------------
    | CHARACTER ENCODING
    |--------------------------------------------------------------------------
    | UTF-8 allows French characters such as:
    |
    | é è à ç ù
    |--------------------------------------------------------------------------
    -->

    <meta charset="UTF-8">


    <!--
    |--------------------------------------------------------------------------
    | RESPONSIVE DESIGN
    |--------------------------------------------------------------------------
    | This makes the page adapt to phones, tablets and computers.
    |--------------------------------------------------------------------------
    -->

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">


    <!--
    |--------------------------------------------------------------------------
    | PAGE TITLE
    |--------------------------------------------------------------------------
    -->

    <title>Gestion des utilisateurs</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">


    <!--
    |--------------------------------------------------------------------------
    | MAIN CSS
    |--------------------------------------------------------------------------
    | IMPORTANT:
    |
    | This file is inside:
    |
    | admin/users/
    |
    | So we need ../../ to return to the project root.
    |--------------------------------------------------------------------------
    -->

    <link
        rel="stylesheet"
        href="../../assets/css/style.css?v=20260925e">

</head>


<body class="admin-body">


    <!--
|--------------------------------------------------------------------------
| ADMIN LAYOUT
|--------------------------------------------------------------------------
| The complete administration interface is divided into:
|
| 1. Sidebar
| 2. Main content
|--------------------------------------------------------------------------
-->

<div class="admin-layout">


    <!-- ======================================================
         SIDEBAR
         ====================================================== -->

    <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('users/users.php'); ?>


        <!--
    |--------------------------------------------------------------------------
    | MAIN CONTENT
    |--------------------------------------------------------------------------
    -->

        <main class="admin-main">


            <!--
        |--------------------------------------------------------------------------
        | TOP BAR
        |--------------------------------------------------------------------------
        -->

            <div class="admin-topbar">


                <!-- Page title -->

                <div>

                    <h1>Utilisateurs</h1>

                    <p>
                        Gestion des comptes de la plateforme
                    </p>

                </div>


                <!--
            |--------------------------------------------------------------------------
            | CURRENT ADMIN
            |--------------------------------------------------------------------------
            -->

                <div class="admin-user">


                    <!--
                |--------------------------------------------------------------------------
                | ADMIN AVATAR
                |--------------------------------------------------------------------------
                | We take the first letter of the admin name.
                |--------------------------------------------------------------------------
                -->

                    <div class="admin-avatar">

                        <?= htmlspecialchars(
                            strtoupper(
                                substr(
                                    $_SESSION["user_name"] ?? "A",
                                    0,
                                    1
                                )
                            )
                        ) ?>

                    </div>


                    <!-- Admin name -->

                    <span>

                        <?= htmlspecialchars(
                            $_SESSION["user_name"] ?? "Administrateur"
                        ) ?>

                    </span>


                </div>

            </div>


            <!--
        |--------------------------------------------------------------------------
        | USERS SECTION
        |--------------------------------------------------------------------------
        -->

            <section class="dashboard-section">


                <!--
            |--------------------------------------------------------------------------
            | SECTION HEADER
            |--------------------------------------------------------------------------
            -->

                <div class="section-header">


                    <div>

                        <h2>Liste des utilisateurs</h2>

                        <p>

                            <?= count($users) ?>

                            utilisateur(s) trouvé(s)

                        </p>

                    </div>
                    <div class="section-header-actions">
                        <a href="users_pdf.php" class="admin-button secondary">
                            Télécharger PDF
                        </a>
                        <a href="user_create.php" class="btn btn-primary">
                            + Ajouter un utilisateur
                        </a>
                    </div>


                </div>


                <!--
            |--------------------------------------------------------------------------
            | SEARCH AND FILTER FORM
            |--------------------------------------------------------------------------
            | GET is used because search/filter values are not sensitive.
            |
            | Example URL:
            |
            | users.php?search=adam&role=student
            |--------------------------------------------------------------------------
            -->

                <form
                    method="GET"
                    class="users-filters">


                    <!--
                |--------------------------------------------------------------------------
                | SEARCH INPUT
                |--------------------------------------------------------------------------
                -->

                    <input
                        type="text"
                        name="search"
                        placeholder="Rechercher par nom ou email..."
                        value="<?= htmlspecialchars($search) ?>">


                    <!--
                |--------------------------------------------------------------------------
                | ROLE SELECT
                |--------------------------------------------------------------------------
                -->

                    <select name="role">


                        <option value="">
                            Tous les rôles
                        </option>


                        <option
                            value="student"
                            <?= $role === "student" ? "selected" : "" ?>>
                            Apprenant
                        </option>


                        <option
                            value="company_manager"
                            <?= $role === "company_manager" ? "selected" : "" ?>>
                            Responsable entreprise
                        </option>

                        <option value="parent" <?= $role === "parent" ? "selected" : "" ?>>
                            Parent
                        </option>

                        <option
                            value="teacher"
                            <?= $role === "teacher" ? "selected" : "" ?>>
                            Formateur
                        </option>


                        <option
                            value="admin"
                            <?= $role === "admin" ? "selected" : "" ?>>
                            Administrateur
                        </option>


                    </select>


                    <!--
                |--------------------------------------------------------------------------
                | SEARCH BUTTON
                |--------------------------------------------------------------------------
                -->

                    <button
                        type="submit"
                        class="admin-button">
                        Rechercher
                    </button>


                    <!--
                |--------------------------------------------------------------------------
                | RESET BUTTON
                |--------------------------------------------------------------------------
                -->

                    <a
                        href="users.php"
                        class="admin-button secondary">
                        Réinitialiser
                    </a>


                </form>


                <!--
            |--------------------------------------------------------------------------
            | USERS TABLE
            |--------------------------------------------------------------------------
            -->

                <div class="admin-table-container">


                    <table class="admin-table">


                        <!-- TABLE HEADER -->

                        <thead>

                            <tr>

                                <th>ID</th>

                                <th>Utilisateur</th>

                                <th>Email</th>

                                <th>Entreprise</th>

                                <th>Rôle</th>

                                <th>Statut</th>

                                <th>Date</th>

                                <th>Actions</th>

                            </tr>

                        </thead>


                        <!-- TABLE BODY -->

                        <tbody>


                            <?php if (count($users) > 0): ?>


                                <!--
                        |--------------------------------------------------------------------------
                        | LOOP THROUGH USERS
                        |--------------------------------------------------------------------------
                        -->

                                <?php foreach ($users as $user): ?>


                                    <tr>


                                        <!-- USER ID -->

                                        <td>

                                            #<?= (int) $user["id"] ?>

                                        </td>


                                        <!-- USER NAME -->

                                        <td>

                                            <strong>

                                                <?= htmlspecialchars(
                                                    trim(
                                                        $user["first_name"]
                                                            . " "
                                                            . $user["last_name"]
                                                    )
                                                ) ?>

                                            </strong>

                                        </td>


                                        <!-- EMAIL -->

                                        <td>

                                            <?= htmlspecialchars(
                                                $user["email"]
                                            ) ?>

                                        </td>


                                        <!-- COMPANY -->

                                        <td>

                                            <?= htmlspecialchars(
                                                $user["company_name"] ?? "-"
                                            ) ?>

                                        </td>


                                        <!-- ROLE -->

                                        <td>

                                            <span
                                                class="role-badge <?= htmlspecialchars(
                                                                        getRoleClass($user["role"])
                                                                    ) ?>">

                                                <?= htmlspecialchars(
                                                    getRoleLabel($user["role"])
                                                ) ?>

                                            </span>

                                        </td>


                                        <!-- STATUS -->

                                        <td>


                                            <?php if ($user["is_active"]): ?>


                                                <!-- ACTIVE -->

                                                <span
                                                    class="status-badge status-active">
                                                    Actif
                                                </span>


                                            <?php else: ?>


                                                <!-- INACTIVE -->

                                                <span
                                                    class="status-badge status-inactive">
                                                    Inactif
                                                </span>


                                            <?php endif; ?>


                                        </td>


                                        <!-- CREATION DATE -->

                                        <td>

                                            <?= htmlspecialchars(
                                                date(
                                                    "d/m/Y",
                                                    strtotime(
                                                        $user["created_at"]
                                                    )
                                                )
                                            ) ?>

                                        </td>


                                        <!-- ACTION -->

                                        <td>

                                            <!-- * View is read-only; edit and delete are separate explicit actions. -->
                                            <a
                                                href="see_user.php?id=<?= (int) $user["id"] ?>"
                                                class="table-action">
                                                Voir
                                            </a>

                                            <a
                                                href="user_edit.php?id=<?= (int) $user["id"] ?>"
                                                class="table-action">
                                                Modifier
                                            </a>

                                            <a
                                                href="user_pdf.php?id=<?= (int) $user["id"] ?>"
                                                class="table-action">
                                                PDF
                                            </a>

                                            <form
                                                method="POST"
                                                action="user_delete.php"
                                                style="display: inline;"
                                                onsubmit="return confirm('Voulez-vous vraiment supprimer cet utilisateur ?');">
                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">
                                                <input
                                                    type="hidden"
                                                    name="user_id"
                                                    value="<?= (int) $user["id"] ?>">
                                                <button type="submit" class="table-action">Supprimer</button>
                                            </form>

                                        </td>


                                    </tr>


                                <?php endforeach; ?>


                            <?php else: ?>


                                <!--
                        |--------------------------------------------------------------------------
                        | NO USERS
                        |--------------------------------------------------------------------------
                        -->

                                <tr>

                                    <td colspan="7">

                                        Aucun utilisateur trouvé.

                                    </td>

                                </tr>


                            <?php endif; ?>


                        </tbody>


                    </table>


                </div>


            </section>


        </main>


    </div>


<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script></body>

</html>

