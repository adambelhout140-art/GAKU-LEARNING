<?php
require_once __DIR__ . '/../../config/i18n.php';// ! ADMIN USER EDIT PAGE
// * Loads one user, validates submitted changes, and updates the account.
// ? Only administrators may reach this page; every update is protected by CSRF validation.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once "../../config/database.php";
require_once "../../config/audit.php";

if (!isset($_SESSION["user_id"])) {
	header("Location: ../../auth/login.php");
	exit;
}

if (!isset($_SESSION["user_role"]) || $_SESSION["user_role"] !== "admin") {
	header("Location: ../../index.php");
	exit;
}

// ! CSRF TOKEN
// * The token is stored in the session and printed into the form. It proves that the POST came from this page.
if (empty($_SESSION["csrf_token"])) {
	$_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// * Load companies so an administrator can link this user to an organization.
$companiesStatement = $pdo->query("SELECT id, company_name FROM companies ORDER BY company_name ASC");
$companies = $companiesStatement->fetchAll();
$studentsStatement = $pdo->query("SELECT id, first_name, last_name, email FROM users WHERE role = 'student' ORDER BY last_name, first_name");
$students = $studentsStatement->fetchAll();

$userId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
$error = "";

if (!$userId || $userId < 1) {
	header("Location: users.php");
	exit;
}

// * A prepared statement keeps the user ID separate from SQL syntax.
$userStatement = $pdo->prepare("SELECT id, company_id, first_name, last_name, date_of_birth, cin, education, job_title, email, phone, role, is_active FROM users WHERE id = ? LIMIT 1");
$userStatement->execute([$userId]);
$user = $userStatement->fetch();

if (!$user) {
	header("Location: users.php");
	exit;
}

$firstName = $user["first_name"];
$lastName = $user["last_name"];
$dateOfBirth = $user["date_of_birth"] ?? "";
$cin = $user["cin"] ?? "";
$education = $user["education"] ?? "";
$jobTitle = $user["job_title"] ?? "";
$email = $user["email"];
$phone = $user["phone"] ?? "";
$companyId = $user["company_id"] ?? "";
$role = $user["role"];
$isActive = (int) $user["is_active"];
$childIdsStatement = $pdo->prepare("SELECT student_id FROM parent_student_links WHERE parent_id = ?");
$childIdsStatement->execute([$userId]);
$childIds = array_map("intval", $childIdsStatement->fetchAll(PDO::FETCH_COLUMN));

// ! UPDATE REQUEST
// * Empty password fields intentionally preserve the current password.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
	if (($_POST["action"] ?? "") === "reset_device") {
		if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
			exit("La session du formulaire a expiré. Veuillez réessayer.");
		} elseif ($user["role"] !== "student") {
			exit("Seuls les comptes étudiants ont un appareil enregistré.");
		} else {
			// Clearing both tokens disconnects the current device and allows the
			// student's next successful login to register a new device.
			$resetDevice = $pdo->prepare("UPDATE users SET registered_device_token = NULL, active_session_token = NULL WHERE id = ? AND role = 'student'");
			$resetDevice->execute([$userId]);
			auditLog($pdo, (int) $_SESSION["user_id"], "student_device_reset", "user", $userId);
			header("Location: user_edit.php?id=" . $userId . "&device_reset=1");
			exit;
		}
	}

	$firstName = trim($_POST["first_name"] ?? "");
	$lastName = trim($_POST["last_name"] ?? "");
	$dateOfBirth = trim($_POST["date_of_birth"] ?? "");
	$cin = trim($_POST["cin"] ?? "");
	$education = trim($_POST["education"] ?? "");
	$jobTitle = trim($_POST["job_title"] ?? "");
	$email = trim($_POST["email"] ?? "");
	$phone = trim($_POST["phone"] ?? "");
	$password = $_POST["password"] ?? "";
	$passwordConfirmation = $_POST["password_confirmation"] ?? "";
	$companyId = trim($_POST["company_id"] ?? "");
	$role = $_POST["role"] ?? "";
	$isActive = isset($_POST["is_active"]) ? 1 : 0;
	$childIds = array_values(array_filter(array_map("intval", $_POST["child_ids"] ?? [])));

	if ($role !== "student") {
		$education = "";
	}
	if (!in_array($role, ["student", "parent", "teacher"], true)) {
		$dateOfBirth = "";
		$cin = "";
	}
	if (!in_array($role, ["teacher", "company_manager"], true)) {
		$jobTitle = "";
	}
	if ($role !== "parent") {
		$childIds = [];
	}
	if ($role !== "company_manager") {
		$companyId = "";
	}

	// ? Validate in this order so invalid or forged requests cannot reach the database update.
	if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
		$error = "La session du formulaire a expiré. Veuillez réessayer.";
	} elseif ($firstName === "" || $lastName === "" || $email === "") {
		$error = "Veuillez remplir tous les champs obligatoires.";
	} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
		$error = "Veuillez entrer une adresse email valide.";
	} elseif ($dateOfBirth !== "") {
		$dateOfBirthObject = DateTimeImmutable::createFromFormat("Y-m-d", $dateOfBirth);
		if (!$dateOfBirthObject || $dateOfBirthObject->format("Y-m-d") !== $dateOfBirth) {
			$error = "La date de naissance est invalide.";
		}
	} elseif ($role === "student" && ($dateOfBirth === "" || $cin === "" || $education === "")) {
		$error = "Pour un apprenant, la date de naissance, le CIN et les études / établissement sont obligatoires.";
	} elseif ($role === "parent" && ($dateOfBirth === "" || $cin === "")) {
		$error = "Pour un parent, la date de naissance et le CIN sont obligatoires.";
	} elseif (strlen($cin) > 50) {
		$error = "Le CIN ne doit pas dépasser 50 caractères.";
	} elseif (strlen($education) > 255) {
		$error = "Les études / établissement ne doivent pas dépasser 255 caractères.";
	} elseif (strlen($jobTitle) > 150) {
		$error = "Le poste ne doit pas dépasser 150 caractères.";
	} elseif (!in_array($role, ["student", "parent", "company_manager", "teacher", "admin"], true)) {
		$error = "Le rôle sélectionné est invalide.";
	} elseif ($password !== "" && strlen($password) < 8) {
		$error = "Le mot de passe doit contenir au moins 8 caractères.";
	} elseif ($password !== $passwordConfirmation && $password !== "") {
		$error = "Les mots de passe ne correspondent pas.";
	} elseif ($password === "" && $passwordConfirmation !== "") {
		$error = "Veuillez laisser les deux champs de mot de passe vides ou les remplir tous les deux.";
	} elseif ($companyId !== "" && filter_var($companyId, FILTER_VALIDATE_INT) === false) {
		$error = "L'entreprise sélectionnée est invalide.";
	} elseif ($role === "company_manager" && $companyId === "") {
		$error = "Veuillez sélectionner une entreprise pour ce responsable.";
	} elseif (in_array($role, ["teacher", "company_manager"], true) && $jobTitle === "") {
		$error = "Veuillez renseigner le poste pour ce rôle.";
	}

	if ($error === "") {
		// ? The current record is excluded because a user is allowed to keep their own email.
		$duplicateStatement = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
		$duplicateStatement->execute([$email, $userId]);

		if ($duplicateStatement->fetch()) {
			$error = "Cette adresse email est déjà utilisée.";
		} else {
			// * Build a different update query only when a new password was supplied.
			if ($password !== "") {
				$statement = $pdo->prepare("UPDATE users SET company_id = ?, first_name = ?, last_name = ?, date_of_birth = ?, cin = ?, education = ?, job_title = ?, email = ?, phone = ?, role = ?, is_active = ?, password = ? WHERE id = ?");
				$statement->execute([$companyId === "" ? null : (int) $companyId, $firstName, $lastName, $dateOfBirth === "" ? null : $dateOfBirth, $cin === "" ? null : $cin, $education === "" ? null : $education, $jobTitle === "" ? null : $jobTitle, $email, $phone, $role, $isActive, password_hash($password, PASSWORD_DEFAULT), $userId]);
				auditLog($pdo, (int) $_SESSION["user_id"], "user_updated", "user", $userId, null, ["password_changed" => true, "role" => $role]);
			} else {
				$statement = $pdo->prepare("UPDATE users SET company_id = ?, first_name = ?, last_name = ?, date_of_birth = ?, cin = ?, education = ?, job_title = ?, email = ?, phone = ?, role = ?, is_active = ? WHERE id = ?");
				$statement->execute([$companyId === "" ? null : (int) $companyId, $firstName, $lastName, $dateOfBirth === "" ? null : $dateOfBirth, $cin === "" ? null : $cin, $education === "" ? null : $education, $jobTitle === "" ? null : $jobTitle, $email, $phone, $role, $isActive, $userId]);
				auditLog($pdo, (int) $_SESSION["user_id"], "user_updated", "user", $userId, null, ["role" => $role]);
			}
			$pdo->prepare("DELETE FROM parent_student_links WHERE parent_id = ?")->execute([$userId]);
			if ($role === "parent" && $childIds) {
				$linkStatement = $pdo->prepare("INSERT IGNORE INTO parent_student_links (parent_id, student_id) VALUES (?, ?)");
				foreach ($childIds as $childId) {
					$linkStatement->execute([$userId, $childId]);
				}
			}

			header("Location: users.php?success=updated");
			exit;
		}
	}
}

$roleLabels = [
	"student" => "Apprenant",
	"parent" => "Parent",
	"company_manager" => "Responsable entreprise",
	"teacher" => "Formateur",
	"admin" => "Administrateur"
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Modifier un utilisateur - Administration</title><link rel="icon" type="image/png" href="../../assets/images/logo-mark.png">
	<link rel="stylesheet" href="../../assets/css/style.css?v=20260925e">
</head>
<body class="admin-body">
<div class="admin-layout">
	    <?php require_once __DIR__ . "/../config/admin_sidebar.php"; renderAdminSidebar('users/user_edit.php'); ?>

	<main class="admin-main">
		<header class="admin-topbar">
			<div>
				<h1>Modifier un utilisateur</h1>
				<p>Mettre à jour les informations de <?= htmlspecialchars($firstName . " " . $lastName) ?></p>
			</div>
			<a href="users.php" class="btn btn-secondary">Retour aux utilisateurs</a>
		</header>

		<section class="dashboard-section">
			<?php if ($error !== ""): ?>
				<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
			<?php endif; ?>

			<div class="form-card">
				<div class="form-card-header">
					<div>
						<h2>Informations de l'utilisateur</h2>
						<p>Les champs marqués d'un astérisque sont obligatoires.</p>
					</div>
				</div>

				<form method="POST" action="">
					<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">
					<div class="form-group">
						<label for="first_name">Prénom *</label>
						<input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($firstName) ?>" required>
					</div>
					<div class="form-group">
						<label for="last_name">Nom *</label>
						<input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($lastName) ?>" required>
					</div>
					<div class="form-group">
						<label for="email">Adresse email *</label>
						<input type="email" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
					</div>
					<div class="form-group">
						<label for="phone">Téléphone</label>
						<input type="text" id="phone" name="phone" value="<?= htmlspecialchars($phone) ?>">
					</div>
					<div class="form-group role-field" data-roles="student,parent,teacher">
						<label for="date_of_birth">Date de naissance</label>
						<input type="date" id="date_of_birth" name="date_of_birth" value="<?= htmlspecialchars($dateOfBirth) ?>">
					</div>
					<div class="form-group role-field" data-roles="student,parent,teacher">
						<label for="cin">CIN</label>
						<input type="text" id="cin" name="cin" maxlength="50" value="<?= htmlspecialchars($cin) ?>">
					</div>
					<div class="form-group role-field" data-roles="student">
						<label for="education">Études / établissement</label>
						<input type="text" id="education" name="education" maxlength="255" value="<?= htmlspecialchars($education) ?>">
					</div>
					<div class="form-group role-field" data-roles="teacher,company_manager">
						<label for="job_title">Poste dans l'entreprise</label>
						<input type="text" id="job_title" name="job_title" maxlength="150" value="<?= htmlspecialchars($jobTitle) ?>">
					</div>
					<div class="form-group">
						<label for="role">Rôle *</label>
						<select id="role" name="role" required>
							<?php foreach ($roleLabels as $value => $label): ?>
								<option value="<?= $value ?>" <?= $role === $value ? "selected" : "" ?>><?= $label ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="form-group role-field" data-roles="parent">
						<label for="child_search">Enfants liés</label>
						<input type="search" id="child_search" class="child-search" placeholder="Rechercher puis sélectionner un enfant..." autocomplete="off">
						<div class="child-suggestions" data-child-suggestions></div>
						<div class="child-chips" data-child-chips>
							<?php foreach ($students as $student): ?>
								<?php $studentLabel = trim($student["first_name"] . " " . $student["last_name"]) . " — " . $student["email"]; ?>
								<?php if (in_array((int) $student["id"], $childIds, true)): ?>
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
					<div class="form-group">
						<label for="password">Nouveau mot de passe</label>
						<input type="password" id="password" name="password" minlength="8" autocomplete="new-password">
						<small>Laissez ce champ vide pour conserver le mot de passe actuel.</small>
					</div>
					<div class="form-group">
						<label for="password_confirmation">Confirmer le mot de passe</label>
						<input type="password" id="password_confirmation" name="password_confirmation" minlength="8" autocomplete="new-password">
					</div>
					<div class="form-group role-field" data-roles="company_manager">
						<label for="company_id">Entreprise</label>
						<select id="company_id" name="company_id">
							<option value="">Aucune entreprise</option>
							<?php foreach ($companies as $company): ?>
								<option value="<?= (int) $company["id"] ?>" <?= (string) $companyId === (string) $company["id"] ? "selected" : "" ?>><?= htmlspecialchars($company["company_name"]) ?></option>
							<?php endforeach; ?>
						</select>
						<small>Obligatoire pour un responsable entreprise.</small>
					</div>
					<div class="form-group checkbox-group">
						<label>
							<input type="checkbox" name="is_active" value="1" <?= $isActive === 1 ? "checked" : "" ?>>
							Compte actif
						</label>
					</div>
					<?php if ($role === "student"): ?>
						<div class="form-group">
							<label>Appareil autorisé</label>
							<p><small>L'appareil est enregistré lors de la première connexion de l'étudiant. Un autre appareil est bloqué.</small></p>
							<button type="submit" class="btn btn-secondary" name="action" value="reset_device">Réinitialiser l'appareil</button>
						</div>
					<?php endif; ?>
					<div class="form-actions">
						<a href="users.php" class="btn btn-secondary">Annuler</a>
						<button type="submit" class="btn btn-primary">Enregistrer les modifications</button>
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
</style>
<script src="../../assets/js/admin-sidebar.js?v=20260925e"></script>
<script>
(() => {
	const roleSelect = document.getElementById("role");
	const roleFields = Array.from(document.querySelectorAll(".role-field"));
	const companySelect = document.getElementById("company_id");

	function updateRoleFields() {
		const role = roleSelect.value;
		roleFields.forEach((field) => {
			const visible = (field.dataset.roles || "").split(",").includes(role);
			field.hidden = !visible;
			field.querySelectorAll("input, select, textarea").forEach((input) => {
				input.disabled = !visible;
			});
		});

		if (companySelect) {
			companySelect.required = role === "company_manager";
		}

		const requiredByRole = {
			date_of_birth: role === "student" || role === "parent",
			cin: role === "student" || role === "parent",
			education: role === "student",
			job_title: role === "teacher" || role === "company_manager"
		};
		Object.entries(requiredByRole).forEach(([id, required]) => {
			const input = document.getElementById(id);
			if (input) input.required = required;
		});
	}

	roleSelect.addEventListener("change", updateRoleFields);
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
