<?php
require_once __DIR__ . '/../../config/i18n.php';// ============================================================
// * GAKU LEARNING - ADMIN COMPANY EDIT PAGE
// ============================================================
// ! RESTRICTED ACCESS: Requires an authenticated administrator.
// ? Purpose: Loads one company, validates changes, and updates its organization details.
// ? Security: The company ID comes from the URL, but it is still validated before querying.
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
// ? Generates a unique token if one does not already exist for the session.
// ------------------------------------------------------------
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}


// ------------------------------------------------------------
// * QUERY PARAMETER SANITIZATION
// ? Validates and casts target company ID parameter from GET request.
// ------------------------------------------------------------
$companyId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$companyId || $companyId < 1) {
    header("Location: companies.php");
    exit;
}


// ------------------------------------------------------------
// * DATABASE QUERY: FETCH TARGET COMPANY
// ? Prepared SQL keeps the requested record ID separate from the query text.
// ------------------------------------------------------------
$companyStatement = $pdo->prepare("SELECT id, company_name, industry, registration_number, address, city, country, phone, email, website, responsible_name, responsible_email, responsible_phone, director_name, director_email, director_phone, is_active FROM companies WHERE id = ? LIMIT 1");
$companyStatement->execute([$companyId]);
$company = $companyStatement->fetch();

if (!$company) {
    header("Location: companies.php");
    exit;
}


// ------------------------------------------------------------
// * INITIALIZE FORM FIELDS
// ? Populates local variables with existing database values.
// ------------------------------------------------------------
$fields = ["company_name", "industry", "registration_number", "address", "city", "country", "phone", "email", "website", "responsible_name", "responsible_email", "responsible_phone", "director_name", "director_email", "director_phone"];

foreach ($fields as $field) {
    ${$field} = $company[$field] ?? "";
}

$isActive = (int) $company["is_active"];
$error = "";


// ------------------------------------------------------------
// * FORM PROCESSING & VALIDATION
// ! Process updates only when the form is submitted with POST.
// ------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    foreach ($fields as $field) {
        ${$field} = trim($_POST[$field] ?? "");
    }

    $isActive = isset($_POST["is_active"]) ? 1 : 0;

    // ? The token prevents another website from submitting changes in the administrator's session.
    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        $error = "La session du formulaire a expiré. Veuillez réessayer.";
    } elseif ($company_name === "") {
        $error = "Le nom de l'entreprise est obligatoire.";
    } elseif (($responsible_name === "") !== ($responsible_email === "")) {
        $error = "Le nom et l'email du responsable doivent être renseignés ensemble.";
    } elseif (($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) || ($responsible_email !== "" && !filter_var($responsible_email, FILTER_VALIDATE_EMAIL)) || ($director_email !== "" && !filter_var($director_email, FILTER_VALIDATE_EMAIL))) {
        $error = "Veuillez entrer une adresse email valide.";
    }

    if ($error === "") {

        // ? Exclude the current company so keeping its existing values is allowed.
        $duplicateStatement = $pdo->prepare("SELECT id FROM companies WHERE id <> ? AND (company_name = ? OR (registration_number IS NOT NULL AND registration_number <> '' AND registration_number = ?)) LIMIT 1");
        $duplicateStatement->execute([$companyId, $company_name, $registration_number]);

        if ($duplicateStatement->fetch()) {
            $error = "Le nom ou le matricule fiscal existe déjà.";
        } else {

            $managerStatement = $pdo->prepare("SELECT id FROM users WHERE company_id = ? AND role = 'company_manager' ORDER BY id ASC LIMIT 1");
            $managerStatement->execute([$companyId]);
            $manager = $managerStatement->fetch();

            if ($responsible_email !== "") {
                $emailStatement = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
                $emailStatement->execute([$responsible_email, $manager ? (int) $manager["id"] : 0]);

                if ($emailStatement->fetch()) {
                    $error = "L'adresse email du responsable est déjà utilisée par un utilisateur.";
                }
            }

            if ($error === "") {
                try {
                    $pdo->beginTransaction();

                    // * Convert optional blank form values to NULL before saving them.
                    $values = [$company_name];
                    foreach (array_slice($fields, 1) as $field) {
                        $values[] = ${$field} !== "" ? ${$field} : null;
                    }
                    $values[] = $isActive;
                    $values[] = $companyId;
                    $statement = $pdo->prepare("UPDATE companies SET company_name = ?, industry = ?, registration_number = ?, address = ?, city = ?, country = ?, phone = ?, email = ?, website = ?, responsible_name = ?, responsible_email = ?, responsible_phone = ?, director_name = ?, director_email = ?, director_phone = ?, is_active = ? WHERE id = ?");
                    $statement->execute($values);

                    if ($responsible_email !== "") {
                        $nameParts = preg_split("/\s+/", $responsible_name, 2);
                        $firstName = trim($nameParts[0] ?? "");
                        $lastName = trim($nameParts[1] ?? "");

                        if ($manager) {
                            $updateManager = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, company_id = ? WHERE id = ? AND role = 'company_manager'");
                            $updateManager->execute([
                                $firstName,
                                $lastName,
                                $responsible_email,
                                $responsible_phone !== "" ? $responsible_phone : null,
                                $companyId,
                                (int) $manager["id"]
                            ]);
                        } else {
                            $insertManager = $pdo->prepare("INSERT INTO users (company_id, first_name, last_name, email, password, role, phone, is_active) VALUES (?, ?, ?, ?, ?, 'company_manager', ?, 0)");
                            $insertManager->execute([
                                $companyId,
                                $firstName,
                                $lastName,
                                $responsible_email,
                                password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                                $responsible_phone !== "" ? $responsible_phone : null
                            ]);
                        }
                    }

                    $pdo->commit();
                    header("Location: companies.php?success=updated");
                    exit;
                } catch (Exception $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $error = "L'entreprise et le compte du responsable n'ont pas pu être mis à jour.";
                }
            }
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

    <title>Modifier une entreprise - Administration</title><link rel="icon" type="image/png" href="<?= htmlspecialchars(platformLogoUrl($pdo), ENT_QUOTES, "UTF-8") ?>">

    <!-- * Main administrative layout stylesheet import -->
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">

</head>

<body class="admin-body">

    <div class="admin-layout">

        <!-- ==========================================================
             * ADMINISTRATIVE SIDEBAR NAVIGATION
             ========================================================== -->

        <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('companies/company_edit.php'); ?>


        <!-- ==========================================================
             * MAIN ADMINISTRATIVE CONTENT
             ========================================================== -->

        <main class="admin-main">

            <!-- ==========================================================
                 * DASHBOARD TOP BAR
                 ========================================================== -->

            <header class="admin-topbar">

                <div>
                    <h1>Modifier une entreprise</h1>
                    <p>Mettre à jour les informations de <?= htmlspecialchars($company_name) ?></p>
                </div>

                <a href="companies.php" class="btn btn-secondary">
                    Retour aux entreprises
                </a>

            </header>


            <!-- ==========================================================
                 * EDIT COMPANY FORM SECTION
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
                        <p>Modifiez les informations nécessaires.</p>
                    </div>

                    <form method="POST">

                        <!-- * Anti-CSRF Token Security Payload -->
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">

                        <!-- * Company Name Input -->
                        <div class="form-group">
                            <label for="company_name">Nom de l'entreprise *</label>
                            <input type="text" id="company_name" name="company_name" value="<?= htmlspecialchars($company_name) ?>" required>
                        </div>
                        <div class="form-group"><label for="industry">Secteur d'activité</label><input type="text" id="industry" name="industry" value="<?= htmlspecialchars($industry) ?>"></div>

                        <!-- * Registration Number / Fiscal ID Input -->
                        <div class="form-group">
                            <label for="registration_number">Matricule fiscal</label>
                            <input type="text" id="registration_number" name="registration_number" value="<?= htmlspecialchars($registration_number) ?>">
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
                        <div class="form-group"><label for="responsible_name">Nom complet</label><input type="text" id="responsible_name" name="responsible_name" value="<?= htmlspecialchars($responsible_name) ?>"></div>
                        <div class="form-group"><label for="responsible_email">Email</label><input type="email" id="responsible_email" name="responsible_email" value="<?= htmlspecialchars($responsible_email) ?>"></div>
                        <div class="form-group"><label for="responsible_phone">Téléphone</label><input type="tel" id="responsible_phone" name="responsible_phone" value="<?= htmlspecialchars($responsible_phone) ?>"></div>
                        <h3>Chef / directeur de l'entreprise</h3>
                        <div class="form-group"><label for="director_name">Nom complet</label><input type="text" id="director_name" name="director_name" value="<?= htmlspecialchars($director_name) ?>"></div>
                        <div class="form-group"><label for="director_email">Email</label><input type="email" id="director_email" name="director_email" value="<?= htmlspecialchars($director_email) ?>"></div>
                        <div class="form-group"><label for="director_phone">Téléphone</label><input type="tel" id="director_phone" name="director_phone" value="<?= htmlspecialchars($director_phone) ?>"></div>

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
                            <button type="submit" class="btn btn-primary">Enregistrer les modifications</button>
                        </div>

                    </form>

                </div>

            </section>

        </main>

    </div>

<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script></body>

</html>




