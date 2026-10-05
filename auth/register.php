<?php
require_once __DIR__ . '/../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../config/database.php";
require_once "../config/platform_settings.php";
require_once "../config/notifications.php";

if (isset($_SESSION["user_id"])) {
	header("Location: ../student/dashboard.php");
	exit;
}

if (platformSetting($pdo, "allow_registration", "1") !== "1") {
	http_response_code(403);
	exit("Les inscriptions sont actuellement désactivées.");
}

if (empty($_SESSION["register_csrf_token"])) {
	$_SESSION["register_csrf_token"] = bin2hex(random_bytes(32));
}

function e($value)
{
	return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$firstName = trim($_POST["first_name"] ?? "");
$lastName = trim($_POST["last_name"] ?? "");
$dateOfBirth = trim($_POST["date_of_birth"] ?? "");
$cin = trim($_POST["cin"] ?? "");
$education = trim($_POST["education"] ?? "");
$email = trim($_POST["email"] ?? "");
$phone = trim($_POST["phone"] ?? "");
$password = $_POST["password"] ?? "";
$passwordConfirmation = $_POST["password_confirmation"] ?? "";
$parentFirstName = trim($_POST["parent_first_name"] ?? "");
$parentLastName = trim($_POST["parent_last_name"] ?? "");
$parentEmail = trim($_POST["parent_email"] ?? "");
$parentPhone = trim($_POST["parent_phone"] ?? "");
$parentDateOfBirth = trim($_POST["parent_date_of_birth"] ?? "");
$parentCin = trim($_POST["parent_cin"] ?? "");
$parentPassword = $_POST["parent_password"] ?? "";
$parentPasswordConfirmation = $_POST["parent_password_confirmation"] ?? "";
$hasParent = $parentFirstName !== "" || $parentLastName !== "" || $parentEmail !== "" || $parentPhone !== "" || $parentPassword !== "";
$courseId = filter_input(INPUT_POST, "course_id", FILTER_VALIDATE_INT);
$errors = [];
$success = false;
$requestId = null;

$courses = $pdo->query("SELECT id, title FROM courses WHERE status = 'published' ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
$selectedCourseId = $courseId ?: filter_input(INPUT_GET, "course_id", FILTER_VALIDATE_INT);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
	if (!hash_equals($_SESSION["register_csrf_token"], $_POST["csrf_token"] ?? "")) $errors[] = "La session du formulaire a expiré.";
	if ($firstName === "" || $lastName === "") $errors[] = "Le prénom et le nom sont obligatoires.";
	if ($dateOfBirth === "" || $cin === "" || $education === "") $errors[] = "La date de naissance, le CIN et les études / établissement sont obligatoires.";
	if ($dateOfBirth !== "") {
		$dateOfBirthObject = DateTimeImmutable::createFromFormat("Y-m-d", $dateOfBirth);
		if (!$dateOfBirthObject || $dateOfBirthObject->format("Y-m-d") !== $dateOfBirth) {
			$errors[] = "La date de naissance est invalide.";
		}
	}
	if (strlen($cin) > 50) $errors[] = "Le CIN ne doit pas dépasser 50 caractères.";
	if (strlen($education) > 255) $errors[] = "Les études / établissement ne doivent pas dépasser 255 caractères.";
	if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "L'adresse email est invalide.";
	if (!$courseId || $courseId < 1) $errors[] = "Sélectionnez une formation.";
	if (strlen($password) < 8) $errors[] = "Le mot de passe étudiant doit contenir au moins 8 caractères.";
	if ($password !== $passwordConfirmation) $errors[] = "Les mots de passe étudiant ne correspondent pas.";
	if ($hasParent && ($parentFirstName === "" || $parentLastName === "" || !filter_var($parentEmail, FILTER_VALIDATE_EMAIL))) {
		$errors[] = "Veuillez compléter correctement les informations du parent.";
	}
	if ($hasParent && ($parentDateOfBirth === "" || $parentCin === "")) {
		$errors[] = "La date de naissance et le CIN du parent sont obligatoires.";
	}
	if ($hasParent && $parentDateOfBirth !== "") {
		$parentDateOfBirthObject = DateTimeImmutable::createFromFormat("Y-m-d", $parentDateOfBirth);
		if (!$parentDateOfBirthObject || $parentDateOfBirthObject->format("Y-m-d") !== $parentDateOfBirth) {
			$errors[] = "La date de naissance du parent est invalide.";
		}
	}
	if (strlen($parentCin) > 50) $errors[] = "Le CIN du parent ne doit pas dépasser 50 caractères.";
	if ($hasParent && strlen($parentPassword) < 8) $errors[] = "Le mot de passe parent doit contenir au moins 8 caractères.";
	if ($hasParent && $parentPassword !== $parentPasswordConfirmation) $errors[] = "Les mots de passe parent ne correspondent pas.";
	if ($hasParent && strcasecmp($email, $parentEmail) === 0) $errors[] = "L'email du parent doit être différent de celui de l'étudiant.";

	if (!$errors) {
		$emailCheck = $pdo->prepare("SELECT email FROM users WHERE LOWER(email) IN (LOWER(?), LOWER(?)) LIMIT 1");
		$emailCheck->execute([$email, $hasParent ? $parentEmail : $email . "__no_parent__"]);
		if ($emailCheck->fetch()) {
			$errors[] = "Cette adresse email est déjà utilisée par un compte.";
		}
	}

	if (!$errors) {
		$pendingCheck = $pdo->prepare(
			"SELECT id
			 FROM contact_requests
			 WHERE request_kind = 'access'
			   AND status = 'new'
			   AND (LOWER(email) = LOWER(?) OR (? <> '' AND LOWER(parent_email) = LOWER(?)))
			 LIMIT 1"
		);
		$pendingCheck->execute([$email, $parentEmail, $parentEmail]);
		if ($pendingCheck->fetch()) {
			$errors[] = "Une demande d'inscription avec cette adresse est déjà en attente.";
		}
	}

	if (!$errors) {
		$courseCheck = $pdo->prepare("SELECT id, title FROM courses WHERE id = ? AND status = 'published' LIMIT 1");
		$courseCheck->execute([$courseId]);
		$course = $courseCheck->fetch(PDO::FETCH_ASSOC);
		if (!$course) $errors[] = "La formation sélectionnée n'est pas disponible.";
	}

	if (!$errors) {
		try {
			$requestInsert = $pdo->prepare("INSERT INTO contact_requests (first_name, last_name, date_of_birth, cin, education, email, phone, request_type, request_kind, course_id, subject, message, student_password_hash, parent_first_name, parent_last_name, parent_date_of_birth, parent_cin, parent_email, parent_phone, parent_password_hash) VALUES (?, ?, ?, ?, ?, ?, ?, 'quote', 'access', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
			$requestInsert->execute([$firstName, $lastName, $dateOfBirth ?: null, $cin ?: null, $education ?: null, $email, $phone ?: null, $courseId, "Demande d'inscription : " . $course["title"], "Un étudiant demande la création de son compte.", password_hash($password, PASSWORD_DEFAULT), $hasParent ? $parentFirstName : null, $hasParent ? $parentLastName : null, $hasParent ? $parentDateOfBirth : null, $hasParent ? $parentCin : null, $hasParent ? $parentEmail : null, $hasParent ? ($parentPhone ?: null) : null, $hasParent ? password_hash($parentPassword, PASSWORD_DEFAULT) : null]);

			$requestId = (int) $pdo->lastInsertId();
			$adminIds = securityAdministratorIds($pdo);
			notifySecurityUsers(
				$pdo,
				$adminIds,
				"Nouvelle demande d'accès",
				"Une nouvelle demande d'inscription (#" . $requestId . ") a été envoyée pour la formation « " . $course["title"] . " »."
			);
			$success = true;
		} catch (PDOException $exception) {
			$errors[] = "L'inscription n'a pas pu être enregistrée.";
		}
	}
}
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Demande d'accès - GAKU LEARNING</title><link rel="icon" type="image/png" href="../assets/images/logo-mark.png"><link rel="stylesheet" href="../assets/css/style.css?v=20260925g"><style>
.registration-parent { margin-top: 1.25rem; border: 1px solid #dbe7f3; border-radius: 16px; background: #f8fbfd; overflow: hidden; }
.registration-parent > summary { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.1rem; cursor: pointer; list-style: none; font-weight: 700; color: #102a43; }
.registration-parent > summary::-webkit-details-marker { display: none; }
.registration-parent > summary::after { content: "+"; color: #277cff; font-size: 1.35rem; transition: transform .25s ease; }
.registration-parent[open] > summary::after { content: "−"; transform: rotate(180deg); }
.registration-parent-summary small { display: block; margin-top: .25rem; color: #627d98; font-weight: 400; }
.registration-parent-panel { display: grid; grid-template-rows: 0fr; transition: grid-template-rows .35s ease; }
.registration-parent[open] .registration-parent-panel { grid-template-rows: 1fr; }
.registration-parent-fields { min-height: 0; overflow: hidden; padding: 0 1.1rem; }
.registration-parent[open] .registration-parent-fields { padding-bottom: 1.1rem; }
.registration-parent-fields fieldset { margin: 0; padding: 0; border: 0; }
</style></head>
<body class="auth-body"><main class="auth-page"><section class="auth-card"><a href="../index.php" class="auth-brand"><img src="../assets/images/logo-mark.png" alt="Gaku Learning" class="brand-logo" width="42" height="42"> GAKU LEARNING</a><h1>Demander un accès</h1><p class="auth-intro">Choisissez une formation et envoyez votre demande. Aucun compte n'est créé à cette étape. La société vous recontactera pour les formalités et le paiement.</p>
<?php if ($success): ?><div class="alert alert-success">Votre demande a été enregistrée. Notez votre numéro de demande <strong>#<?= (int) $requestId ?></strong>. Vous pouvez suivre son statut avec ce numéro et votre adresse email.</div><a href="request-status.php?request_id=<?= (int) $requestId ?>" class="btn btn-primary auth-full-button">Suivre ma demande</a><a href="../index.php" class="btn btn-secondary auth-full-button">Retour à l'accueil</a><?php else: ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="POST"><input type="hidden" name="csrf_token" value="<?= e($_SESSION["register_csrf_token"]) ?>"><div class="auth-form-grid"><div><label for="first_name">Prénom *</label><input id="first_name" name="first_name" value="<?= e($firstName) ?>" required></div><div><label for="last_name">Nom *</label><input id="last_name" name="last_name" value="<?= e($lastName) ?>" required></div></div><div class="auth-form-grid"><div><label for="date_of_birth">Date de naissance *</label><input id="date_of_birth" type="date" name="date_of_birth" value="<?= e($dateOfBirth) ?>" required></div><div><label for="cin">CIN *</label><input id="cin" name="cin" maxlength="50" value="<?= e($cin) ?>" required></div></div><label for="education">Études / établissement *</label><input id="education" name="education" maxlength="255" value="<?= e($education) ?>" required><label for="email">Email *</label><input id="email" type="email" name="email" value="<?= e($email) ?>" required><label for="phone">Téléphone</label><input id="phone" type="tel" name="phone" value="<?= e($phone) ?>"><label for="password">Mot de passe étudiant *</label><input id="password" type="password" name="password" minlength="8" required><label for="password_confirmation">Confirmer le mot de passe *</label><input id="password_confirmation" type="password" name="password_confirmation" minlength="8" required><label for="course_id">Formation demandée *</label><select id="course_id" name="course_id" required><option value="">-- Choisir une formation --</option><?php foreach ($courses as $courseOption): ?><option value="<?= (int) $courseOption["id"] ?>" <?= (string) $selectedCourseId === (string) $courseOption["id"] ? "selected" : "" ?>><?= e($courseOption["title"]) ?></option><?php endforeach; ?></select><details class="registration-parent"<?= $hasParent ? " open" : "" ?>><summary><span class="registration-parent-summary">Ajouter un parent <small>Facultatif — tous les champs marqués * sont obligatoires si vous ajoutez un parent.</small></span></summary><div class="registration-parent-panel"><div class="registration-parent-fields"><fieldset><legend class="sr-only">Informations du parent</legend><div class="auth-form-grid"><div><label for="parent_first_name">Prénom du parent *</label><input id="parent_first_name" name="parent_first_name" value="<?= e($parentFirstName) ?>"></div><div><label for="parent_last_name">Nom du parent *</label><input id="parent_last_name" name="parent_last_name" value="<?= e($parentLastName) ?>"></div></div><div class="auth-form-grid"><div><label for="parent_date_of_birth">Date de naissance du parent *</label><input id="parent_date_of_birth" type="date" name="parent_date_of_birth" value="<?= e($parentDateOfBirth) ?>"></div><div><label for="parent_cin">CIN du parent *</label><input id="parent_cin" name="parent_cin" maxlength="50" value="<?= e($parentCin) ?>"></div></div><label for="parent_email">Email du parent *</label><input id="parent_email" type="email" name="parent_email" value="<?= e($parentEmail) ?>"><label for="parent_phone">Téléphone du parent</label><input id="parent_phone" type="tel" name="parent_phone" value="<?= e($parentPhone) ?>"><label for="parent_password">Mot de passe parent *</label><input id="parent_password" type="password" name="parent_password" minlength="8" autocomplete="new-password"><label for="parent_password_confirmation">Confirmer le mot de passe parent *</label><input id="parent_password_confirmation" type="password" name="parent_password_confirmation" minlength="8" autocomplete="new-password"></fieldset></div></div></details><button type="submit" class="btn btn-primary auth-full-button">Envoyer la demande d'inscription</button></form><p class="auth-switch">Vous avez déjà un compte ? <a href="login.php">Se connecter</a></p><p class="auth-switch"><a href="request-status.php">Déjà envoyé une demande ? Voir son statut</a></p><?php endif; ?></section></main><script>
(() => {
    const parentSection = document.querySelector(".registration-parent");
    if (!parentSection) return;
    const fields = ["parent_first_name", "parent_last_name", "parent_date_of_birth", "parent_cin", "parent_email", "parent_password", "parent_password_confirmation"];
    const updateParentRequirements = () => fields.forEach((id) => {
        const input = document.getElementById(id);
        if (input) input.required = parentSection.open;
    });
    parentSection.addEventListener("toggle", updateParentRequirements);
    updateParentRequirements();
})();
</script>
</body></html>
