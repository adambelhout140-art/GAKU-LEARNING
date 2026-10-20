<?php
require_once __DIR__ . "/notifications.php";

function renderRoleProfile(PDO $pdo, string $role, string $dashboardUrl, string $assetPrefix): void
{
    if (!isset($_SESSION["user_id"]) || ($_SESSION["user_role"] ?? "") !== $role) {
        http_response_code(403);
        exit("Accès interdit.");
    }

    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    $userId = (int) $_SESSION["user_id"];
    $errors = [];
    $success = "";

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
            $errors[] = "La session du formulaire a expiré.";
        } else {
            $action = $_POST["action"] ?? "";

            if ($action === "profile") {
                $firstName = trim((string) ($_POST["first_name"] ?? ""));
                $lastName = trim((string) ($_POST["last_name"] ?? ""));
                $email = trim((string) ($_POST["email"] ?? ""));
                $dateOfBirth = trim((string) ($_POST["date_of_birth"] ?? ""));
                $cin = trim((string) ($_POST["cin"] ?? ""));
                $education = trim((string) ($_POST["education"] ?? ""));
                $jobTitle = trim((string) ($_POST["job_title"] ?? ""));

                if ($firstName === "" || mb_strlen($firstName) > 100) {
                    $errors[] = "Le prénom est obligatoire et doit contenir au maximum 100 caractères.";
                }
                if ($lastName === "" || mb_strlen($lastName) > 100) {
                    $errors[] = "Le nom est obligatoire et doit contenir au maximum 100 caractères.";
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
                    $errors[] = "Saisissez une adresse email valide.";
                }

                if (!$errors) {
                    $duplicate = $pdo->prepare(
                        "SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1"
                    );
                    $duplicate->execute([$email, $userId]);

                    if ($duplicate->fetchColumn()) {
                        $errors[] = "Cette adresse email est déjà utilisée.";
                    } else {
                        $update = $pdo->prepare(
                            "UPDATE users
                             SET first_name = ?, last_name = ?, email = ?, date_of_birth = ?,
                                 cin = ?, education = ?, job_title = ?
                             WHERE id = ? AND role = ?"
                        );
                        $update->execute([
                            $firstName,
                            $lastName,
                            $email,
                            $dateOfBirth === "" ? null : $dateOfBirth,
                            $cin === "" ? null : $cin,
                            $education === "" ? null : $education,
                            $jobTitle === "" ? null : $jobTitle,
                            $userId,
                            $role
                        ]);
                        $_SESSION["user_name"] = $firstName;
                        $_SESSION["user_last_name"] = $lastName;
                        $_SESSION["user_email"] = $email;
                        $success = "Vos informations ont été mises à jour.";
                    }
                }
            } elseif ($action === "password") {
                $currentPassword = (string) ($_POST["current_password"] ?? "");
                $newPassword = (string) ($_POST["new_password"] ?? "");
                $confirmPassword = (string) ($_POST["confirm_password"] ?? "");

                $statement = $pdo->prepare(
                    "SELECT password FROM users WHERE id = ? AND role = ? LIMIT 1"
                );
                $statement->execute([$userId, $role]);
                $user = $statement->fetch(PDO::FETCH_ASSOC);

                if (!$user || !password_verify($currentPassword, $user["password"])) {
                    $errors[] = "Le mot de passe actuel est incorrect.";
                }
                if (strlen($newPassword) < 8) {
                    $errors[] = "Le nouveau mot de passe doit contenir au moins 8 caractères.";
                }
                if ($newPassword !== $confirmPassword) {
                    $errors[] = "Les deux nouveaux mots de passe ne correspondent pas.";
                }

                if (!$errors) {
                    $update = $pdo->prepare(
                        "UPDATE users SET password = ? WHERE id = ? AND role = ?"
                    );
                    $update->execute([
                        password_hash($newPassword, PASSWORD_DEFAULT),
                        $userId,
                        $role
                    ]);
                    $success = "Votre mot de passe a été changé avec succès.";
                }
            }
        }
    }

    $statement = $pdo->prepare(
        "SELECT first_name, last_name, email, date_of_birth, cin, education, job_title
         FROM users
         WHERE id = ? AND role = ?
         LIMIT 1"
    );
    $statement->execute([$userId, $role]);
    $user = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        exit("Utilisateur introuvable.");
    }

    $escape = static function ($value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
    };
    $roleLabel = $role === "admin" ? "Administrateur" : "Formateur";
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Mon profil - <?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?></title>
        <link rel="icon" type="image/png" href="<?= $escape(platformLogoUrl($pdo)) ?>">
        <link rel="stylesheet" href="<?= $escape($assetPrefix) ?>assets/css/style.css?v=20260925g">
    </head>
    <body class="admin-body profile-page <?= $role === "teacher" ? "teacher-view teacher-dashboard-page" : "" ?>">
    <div class="admin-layout">
        <?php if ($role === "teacher"): ?>
            <?php require_once __DIR__ . "/../admin/config/admin_sidebar.php"; renderAdminSidebar("profile.php"); ?>
        <?php else: ?>
        <aside class="admin-sidebar">
            <div class="admin-logo">
                <a href="<?= $escape($assetPrefix) ?>index.php">
                    <img                     src="<?= $escape(platformLogoUrl($pdo, "dark-bg")) ?>"
                         alt="<?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?>"
                         class="brand-logo"
                         width="34"
                         height="34">
                    <?= htmlspecialchars(platformBranding($pdo)["platform_name"], ENT_QUOTES, "UTF-8") ?>
                </a>
            </div>
            <nav class="admin-navigation">
                <?php if ($role === "admin"): ?>
                    <a href="<?= $escape($dashboardUrl) ?>" class="admin-nav-link"><span>📊</span> Tableau de bord</a>
                    <a href="users/users.php" class="admin-nav-link"><span>👥</span> Utilisateurs</a>
                    <a href="companies/companies.php" class="admin-nav-link"><span>🏢</span> Entreprises</a>
                    <a href="courses/courses.php" class="admin-nav-link"><span>📚</span> Formations</a>
                    <a href="modules/modules.php" class="admin-nav-link"><span>🧩</span> Modules</a>
                    <a href="quizzes/quizzes.php" class="admin-nav-link"><span>🧠</span> Quiz</a>
                    <a href="exams/exams.php" class="admin-nav-link"><span>📝</span> Examens</a>
                    <a href="enrollments/enrollments.php" class="admin-nav-link"><span>🎓</span> Inscriptions</a>
                    <a href="contact_requests/contact_requests.php" class="admin-nav-link"><span>✉️</span> Demandes de contact</a>
                    <a href="security_logs.php" class="admin-nav-link"><span>🔐</span> Sécurité</a>
                    <a href="messages.php" class="admin-nav-link"><span>✉️</span> Messages</a>
                    <a href="message.php" class="admin-nav-link"><span>📝</span> Écrire un message</a>
                    <a href="notifications.php" class="admin-nav-link"><span>🔔</span> Notifications</a>
                    <a href="security_logs.php" class="admin-nav-link"><span>🔐</span> Sécurité</a>
                <?php else: ?>
                    <a href="<?= $escape($dashboardUrl) ?>" class="admin-nav-link"><span>📊</span> Tableau de bord</a>
                    <a href="../admin/modules/modules.php" class="admin-nav-link"><span>🧩</span> Modules</a>
                    <a href="../admin/lessons/lessons.php" class="admin-nav-link"><span>📖</span> Leçons</a>
                    <a href="../admin/quizzes/quizzes.php" class="admin-nav-link"><span>🧠</span> Quiz</a>
                    <a href="../admin/exams/exams.php" class="admin-nav-link"><span>📝</span> Examens</a>
                    <a href="results.php" class="admin-nav-link"><span>📈</span> Résultats étudiants</a>
                    <a href="messages.php" class="admin-nav-link"><span>✉️</span> Messages</a>
                    <a href="message.php" class="admin-nav-link"><span>📝</span> Écrire un message</a>
                    <a href="notifications.php" class="admin-nav-link"><span>🔔</span> Notifications</a>
                    <a href="security_logs.php" class="admin-nav-link"><span>🔐</span> Sécurité étudiants</a>
                <?php endif; ?>
            </nav>
            <div class="admin-sidebar-bottom">
                <a href="profile.php" class="admin-nav-link active"><span>👤</span> Mon profil</a>
                <a href="<?= $escape($assetPrefix) ?>index.php" class="admin-nav-link"><span>🌐</span> Voir le site</a>
                <a href="<?= $escape($assetPrefix) ?>auth/logout.php" class="admin-nav-link logout-link"><span>🚪</span> Déconnexion</a>
            </div>
        </aside>
        <?php endif; ?>
        <main class="admin-main">
            <header class="admin-topbar">
                <div>
                    <p class="student-eyebrow">Compte <?= $escape(strtolower($roleLabel)) ?></p>
                    <h1>Mon profil</h1>
                    <p>Gérez votre nom, votre adresse email et votre mot de passe.</p>
                </div>
                <?= roleNotificationBell($pdo, $userId, "notifications.php") ?>
                <a class="btn btn-secondary" href="<?= $escape($dashboardUrl) ?>">Retour au tableau de bord</a>
            </header>

            <?php if ($errors): ?>
                <div class="alert alert-danger"><?= $escape(implode(" ", $errors)) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?= $escape($success) ?></div>
            <?php endif; ?>

            <section class="student-profile-grid role-profile-grid">
                <article class="student-section">
                    <h2>Informations personnelles</h2>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION["csrf_token"]) ?>">
                        <input type="hidden" name="action" value="profile">
                        <div class="student-form-grid">
                            <label>Prénom
                                <input name="first_name" maxlength="100" required value="<?= $escape($user["first_name"]) ?>">
                            </label>
                            <label>Nom
                                <input name="last_name" maxlength="100" required value="<?= $escape($user["last_name"]) ?>">
                            </label>
                            <label class="student-form-full">Email
                                <input type="email" name="email" maxlength="150" required value="<?= $escape($user["email"]) ?>">
                            </label>
                            <label>Date de naissance
                                <input type="date" name="date_of_birth" value="<?= $escape($user["date_of_birth"] ?? "") ?>">
                            </label>
                            <label>CIN
                                <input name="cin" maxlength="50" value="<?= $escape($user["cin"] ?? "") ?>">
                            </label>
                            <label>Études / établissement
                                <input name="education" maxlength="255" value="<?= $escape($user["education"] ?? "") ?>">
                            </label>
                            <label>Poste dans l'entreprise
                                <input name="job_title" maxlength="150" value="<?= $escape($user["job_title"] ?? "") ?>">
                            </label>
                        </div>
                        <button class="button primary-button" type="submit">Enregistrer</button>
                    </form>
                </article>

                <article class="student-section">
                    <h2>Changer le mot de passe</h2>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION["csrf_token"]) ?>">
                        <input type="hidden" name="action" value="password">
                        <label>Mot de passe actuel
                            <input type="password" name="current_password" required>
                        </label>
                        <label>Nouveau mot de passe
                            <input type="password" name="new_password" minlength="8" required>
                        </label>
                        <label>Confirmer le nouveau mot de passe
                            <input type="password" name="confirm_password" minlength="8" required>
                        </label>
                        <button class="button primary-button" type="submit">Changer le mot de passe</button>
                    </form>
                </article>
            </section>
        </main>
    </div>
    <?= platformContactMarkup($pdo, "platform-contact platform-contact-app") ?>
    </body>
    </html>
    <?php
}
