<?php
require_once __DIR__ . '/../../config/i18n.php';// ============================================================
// * GAKU LEARNING - ADMIN COMPANY CREATION PAGE
// ============================================================
// ! RESTRICTED ACCESS: Requires an authenticated administrator.
// ? Purpose: Creates the organization record used to group company managers and employees.
// ? Security: The form uses server-side validation and a CSRF token before inserting data.
// ============================================================


// ------------------------------------------------------------
// * SESSION HANDLING
// ? Starts or resumes session to verify identity and retrieve credentials.
// ------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


// ------------------------------------------------------------
// * FILE DEPENDENCIES
// ! Configures database PDO connection.
// ------------------------------------------------------------
require_once "../../config/database.php";


// ------------------------------------------------------------
// * ACCESS CONTROL: AUTHENTICATION CHECK
// ! Bounces unauthenticated guests directly to the login panel.
// ------------------------------------------------------------
if (!isset($_SESSION["user_id"])) {
    header("Location: ../../auth/login.php");
    exit;
}


// ------------------------------------------------------------
// * ACCESS CONTROL: ROLE CHECK
// ! Denies access to users without administrator privileges.
// ------------------------------------------------------------
if (!isset($_SESSION["user_role"]) || $_SESSION["user_role"] !== "admin") {
    header("Location: ../../index.php");
    exit;
}


// ------------------------------------------------------------
// * CSRF PROTECTION INITIALIZATION
// ! Generate the form token once per session and reuse it for this request.
// ------------------------------------------------------------
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}


// ------------------------------------------------------------
// * INITIALIZE FORM FIELDS
// ? Defines base values for company fields prior to form submission.
// ------------------------------------------------------------
$companyName = "";
$industry = "";
$registrationNumber = "";
$address = "";
$city = "";
$country = "Tunisia";
$phone = "";
$email = "";
$website = "";
$responsibleName = "";
$responsibleEmail = "";
$responsiblePhone = "";
$directorName = "";
$directorEmail = "";
$directorPhone = "";
$isActive = 1;
$error = "";


// ------------------------------------------------------------
// * FORM PROCESSING & VALIDATION
// ! Only a submitted POST is allowed to create a company.
// ------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $companyName = trim($_POST["company_name"] ?? "");
    $industry = trim($_POST["industry"] ?? "");
    $registrationNumber = trim($_POST["registration_number"] ?? "");
    $address = trim($_POST["address"] ?? "");
    $city = trim($_POST["city"] ?? "");
    $country = trim($_POST["country"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $website = trim($_POST["website"] ?? "");
    $responsibleName = trim($_POST["responsible_name"] ?? "");
    $responsibleEmail = trim($_POST["responsible_email"] ?? "");
    $responsiblePhone = trim($_POST["responsible_phone"] ?? "");
    $directorName = trim($_POST["director_name"] ?? "");
    $directorEmail = trim($_POST["director_email"] ?? "");
    $directorPhone = trim($_POST["director_phone"] ?? "");
    $isActive = isset($_POST["is_active"]) ? 1 : 0;

    // ? Reject forged forms before validating or writing any submitted values.
    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        $error = "La session du formulaire a expiré. Veuillez réessayer.";
    } elseif ($companyName === "") {
        $error = "Le nom de l'entreprise est obligatoire.";
    } elseif (($responsibleName === "") !== ($responsibleEmail === "")) {
        $error = "Le nom et l'email du responsable doivent être renseignés ensemble.";
    } elseif (($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) || ($responsibleEmail !== "" && !filter_var($responsibleEmail, FILTER_VALIDATE_EMAIL)) || ($directorEmail !== "" && !filter_var($directorEmail, FILTER_VALIDATE_EMAIL))) {
        $error = "Veuillez entrer une adresse email valide.";
    }

    if ($error === "") {

        // ? Names and registration numbers identify companies and should not be duplicated.
        $duplicateStatement = $pdo->prepare("SELECT id FROM companies WHERE company_name = ? OR (registration_number IS NOT NULL AND registration_number <> '' AND registration_number = ?) LIMIT 1");
        $duplicateStatement->execute([$companyName, $registrationNumber]);

        if ($duplicateStatement->fetch()) {
            $error = "Le nom ou le matricule fiscal existe déjà.";
        } else {
            if ($responsibleEmail !== "") {
                $userEmailStatement = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $userEmailStatement->execute([$responsibleEmail]);

                if ($userEmailStatement->fetch()) {
                    $error = "L'adresse email du responsable est déjà utilisée par un utilisateur.";
                }
            }

            if ($error === "") {
                try {
                    $pdo->beginTransaction();

                // * Optional empty fields become NULL, which accurately represents missing company data.
                $statement = $pdo->prepare("INSERT INTO companies (company_name, industry, registration_number, address, city, country, phone, email, website, responsible_name, responsible_email, responsible_phone, director_name, director_email, director_phone, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $statement->execute([
                    $companyName,
                    $industry ?: null,
                    $registrationNumber ?: null,
                    $address ?: null,
                    $city ?: null,
                    $country ?: null,
                    $phone ?: null,
                    $email ?: null,
                    $website ?: null,
                    $responsibleName ?: null,
                    $responsibleEmail ?: null,
                    $responsiblePhone ?: null,
                    $directorName ?: null,
                    $directorEmail ?: null,
                    $directorPhone ?: null,
                    $isActive
                ]);

                $companyId = (int) $pdo->lastInsertId();

                if ($responsibleEmail !== "") {
                    $nameParts = preg_split("/\s+/", $responsibleName, 2);
                    $firstName = trim($nameParts[0] ?? "");
                    $lastName = trim($nameParts[1] ?? "");

                    // * The account stays inactive until an administrator sets a password or activation flow is added.
                    $managerStatement = $pdo->prepare("INSERT INTO users (company_id, first_name, last_name, email, password, role, phone, is_active) VALUES (?, ?, ?, ?, ?, 'company_manager', ?, 0)");
                    $managerStatement->execute([
                        $companyId,
                        $firstName,
                        $lastName,
                        $responsibleEmail,
                        password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                        $responsiblePhone ?: null
                    ]);
                }

                $pdo->commit();
                } catch (Exception $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $error = "L'entreprise et le compte du responsable n'ont pas pu être créés.";
                }
            }
        }

        if ($error !== "") {
            // Keep the form visible so the administrator can correct duplicate data.
        } else {
            header("Location: companies.php?success=created");
            exit;
        }
    }
}

?>

<!DOCTYPE html>

<html lang="fr">

<head>

    <!-- * Basic page metadata and responsive viewport settings -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Ajouter une entreprise - Administration</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">

    <!-- * Main administrative layout stylesheet import -->
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">

</head>

<body class="admin-body">

    <div class="admin-layout">

        <!-- ==========================================================
             * ADMINISTRATIVE SIDEBAR NAVIGATION
             ========================================================== -->

        <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('companies/company_create.php'); ?>


        <!-- ==========================================================
             * MAIN ADMINISTRATIVE CONTENT
             ========================================================== -->

        <main class="admin-main">

            <!-- ==========================================================
                 * DASHBOARD TOP BAR
                 ========================================================== -->

            <header class="admin-topbar">

                <div>
                    <h1>Ajouter une entreprise</h1>
                    <p>Créer un nouveau compte entreprise</p>
                </div>

                <a href="companies.php" class="btn btn-secondary">
                    Retour aux entreprises
                </a>

            </header>


            <!-- ==========================================================
                 * CREATE COMPANY FORM SECTION
                 ========================================================== -->

            <section class="dashboard-section">

                <!-- * Validation Error Notification -->
                <?php if ($error !== ""): ?>
                    <div class="alert alert-danger">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <div class="form-card">

                    <div class="form-card-header">
                        <h2>Informations de l'entreprise</h2>
                        <p>Les champs marqués d'un astérisque sont obligatoires.</p>
                    </div>

                    <form method="POST">

                        <!-- * Anti-CSRF Token Security Payload -->
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">

                        <!-- * Company Name Input -->
                        <div class="form-group">
                            <label for="company_name">Nom de l'entreprise *</label>
                            <input type="text" id="company_name" name="company_name" value="<?= htmlspecialchars($companyName) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="industry">Secteur d'activité</label>
                            <input type="text" id="industry" name="industry" value="<?= htmlspecialchars($industry) ?>">
                        </div>

                        <!-- * Registration Number / Fiscal ID Input -->
                        <div class="form-group">
                            <label for="registration_number">Matricule fiscal</label>
                            <input type="text" id="registration_number" name="registration_number" value="<?= htmlspecialchars($registrationNumber) ?>">
                        </div>

                        <!-- * Street Address Input -->
                        <div class="form-group">
                            <label for="address">Adresse</label>
                            <input type="text" id="address" name="address" value="<?= htmlspecialchars($address) ?>">
                        </div>

                        <!-- * City Input -->
                        <div class="form-group">
                            <label for="city">Ville</label>
                            <input type="text" id="city" name="city" value="<?= htmlspecialchars($city) ?>">
                        </div>

                        <!-- * Country Input -->
                        <div class="form-group">
                            <label for="country">Pays</label>
                            <input type="text" id="country" name="country" value="<?= htmlspecialchars($country) ?>">
                        </div>

                        <!-- * Phone Number Input -->
                        <div class="form-group">
                            <label for="phone">Téléphone</label>
                            <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($phone) ?>">
                        </div>

                        <!-- * Email Address Input -->
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" value="<?= htmlspecialchars($email) ?>">
                        </div>
                        <div class="form-group"><label for="website">Site web</label><input type="url" id="website" name="website" value="<?= htmlspecialchars($website) ?>"></div>
                        <h3>Responsable entreprise</h3>
                        <p class="form-help">Un compte responsable inactif sera créé automatiquement si le nom et l'email sont renseignés.</p>
                        <div class="form-group"><label for="responsible_name">Nom complet</label><input type="text" id="responsible_name" name="responsible_name" value="<?= htmlspecialchars($responsibleName) ?>"></div>
                        <div class="form-group"><label for="responsible_email">Email</label><input type="email" id="responsible_email" name="responsible_email" value="<?= htmlspecialchars($responsibleEmail) ?>"></div>
                        <div class="form-group"><label for="responsible_phone">Téléphone</label><input type="tel" id="responsible_phone" name="responsible_phone" value="<?= htmlspecialchars($responsiblePhone) ?>"></div>
                        <h3>Chef / directeur de l'entreprise</h3>
                        <div class="form-group"><label for="director_name">Nom complet</label><input type="text" id="director_name" name="director_name" value="<?= htmlspecialchars($directorName) ?>"></div>
                        <div class="form-group"><label for="director_email">Email</label><input type="email" id="director_email" name="director_email" value="<?= htmlspecialchars($directorEmail) ?>"></div>
                        <div class="form-group"><label for="director_phone">Téléphone</label><input type="tel" id="director_phone" name="director_phone" value="<?= htmlspecialchars($directorPhone) ?>"></div>

                        <!-- * Active Account Status Checkbox -->
                        <div class="form-group checkbox-group">
                            <label>
                                <input type="checkbox" name="is_active" value="1" <?= $isActive ? "checked" : "" ?>>
                                Entreprise active
                            </label>
                        </div>

                        <!-- * Form Action Buttons -->
                        <div class="form-actions">
                            <a href="companies.php" class="btn btn-secondary">Annuler</a>
                            <button type="submit" class="btn btn-primary">Créer l'entreprise</button>
                        </div>

                    </form>

                </div>

            </section>

        </main>

    </div>

<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script></body>

</html>




