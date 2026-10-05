<?php
// Keep the language preference in the session so it follows the visitor.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$supportedLanguages = [
    "en" => ["label" => "English", "dir" => "ltr"],
    "fr" => ["label" => "Français", "dir" => "ltr"],
];

if (isset($_GET["lang"]) && isset($supportedLanguages[$_GET["lang"]])) {
    $_SESSION["language"] = $_GET["lang"];
}

$currentLanguage = $_SESSION["language"] ?? "fr";
if (!isset($supportedLanguages[$currentLanguage])) {
    $currentLanguage = "fr";
}
$languageDirection = $supportedLanguages[$currentLanguage]["dir"];

function translate(string $text, ?string $language = null): string
{
    static $translations = [
        "fr" => [
            "Home" => "Accueil",
            "Courses" => "Formations",
            "About" => "À propos",
            "Dashboard" => "Tableau de bord",
            "Logout" => "Déconnexion",
            "Login" => "Connexion",
            "Request access" => "Demander un accès",
            "Request access to a course" => "Demander l'accès à une formation",
            "Check request status" => "Suivre ma demande",
            "Explore Courses" => "Découvrir les formations",
            "Online Training" => "Formation en ligne",
            "ONLINE PROFESSIONAL TRAINING" => "FORMATION PROFESSIONNELLE EN LIGNE",
            "Email" => "E-mail",
            "Password" => "Mot de passe",
            "Forgot your password?" => "Mot de passe oublié ?",
            "Back to Home" => "Retour à l'accueil",
            "Submit" => "Envoyer",
            "Save" => "Enregistrer",
            "Cancel" => "Annuler",
            "Edit" => "Modifier",
            "Delete" => "Supprimer",
            "Create" => "Créer",
            "Search" => "Rechercher",
            "Status" => "Statut",
            "Users" => "Utilisateurs",
            "Companies" => "Entreprises",
            "Lessons" => "Leçons",
            "Modules" => "Modules",
            "Quizzes" => "Quiz",
            "Exams" => "Examens",
            "Results" => "Résultats",
            "Certificates" => "Certificats",
            "Contact Requests" => "Demandes de contact",
            "Students" => "Étudiants",
            "Teachers" => "Formateurs",
            "Enrollments" => "Inscriptions",
            "Welcome" => "Bienvenue",
            "Progress" => "Progression",
            "View" => "Voir",
            "Continue" => "Continuer",
            "Published" => "Publié",
            "Draft" => "Brouillon",
            "Active" => "Actif",
            "Suspended" => "Suspendu",
            "Profile" => "Profil",
            "Settings" => "Paramètres",
            "Messages" => "Messages",
            "Notifications" => "Notifications",
            "Security" => "Sécurité",
            "Access" => "Accès",
            "Training" => "Formation",
            "Trainings" => "Formations",
            "Course" => "Formation",
            "Teacher" => "Formateur",
            "Student" => "Étudiant",
            "Company" => "Entreprise",
            "Company manager" => "Responsable entreprise",
            "Administrator" => "Administrateur",
            "New" => "Nouveau",
            "In progress" => "En cours",
            "Accepted" => "Acceptée",
            "Refused" => "Refusée",
            "Closed" => "Fermée",
            "Open" => "Ouvrir",
            "Add" => "Ajouter",
            "Update" => "Mettre à jour",
            "Save changes" => "Enregistrer les modifications",
            "Create account" => "Créer un compte",
            "Forgot Password" => "Mot de passe oublié",
            "Forgot password?" => "Mot de passe oublié ?",
            "Reset Password" => "Réinitialiser le mot de passe",
            "Confirm password" => "Confirmer le mot de passe",
            "Current password" => "Mot de passe actuel",
            "New password" => "Nouveau mot de passe",
            "First name" => "Prénom",
            "Last name" => "Nom",
            "Phone" => "Téléphone",
            "Address" => "Adresse",
            "City" => "Ville",
            "Country" => "Pays",
            "Website" => "Site web",
            "Industry" => "Secteur d'activité",
            "Filter" => "Filtrer",
            "Reset" => "Réinitialiser",
            "Actions" => "Actions",
            "Details" => "Détails",
            "Information" => "Informations",
            "Description" => "Description",
            "Duration" => "Durée",
            "Level" => "Niveau",
            "Price" => "Prix",
            "Instructor" => "Formateur",
            "No results found" => "Aucun résultat trouvé",
            "No courses available yet" => "Aucune formation disponible pour le moment",
            "View Course" => "Voir la formation",
            "Popular Courses" => "Formations populaires",
            "Professional Courses" => "Formations professionnelles",
            "Quizzes & Exams" => "Quiz et examens",
            "Track Your Progress" => "Suivez votre progression",
            "Language" => "Langue",
            "English" => "Anglais",
            "French" => "Français",
            "Unauthorized access." => "Accès non autorisé.",
            "Invalid request." => "Demande invalide.",
            "The requested item was not found." => "L'élément demandé est introuvable.",
        ],
    ];

    $lang = $language ?? ($_SESSION["language"] ?? "fr");
    $lang = isset($translations[$lang]) ? $lang : "fr";

    return $translations[$lang][$text] ?? $text;
}

// This keeps the first multilingual rollout centralized while existing PHP pages
// continue to use their current markup.
ob_start(function (string $html) use ($currentLanguage, $languageDirection, $supportedLanguages): string {
    if (stripos($html, "</body>") === false) {
        return $html;
    }

    $payload = json_encode([
        "language" => $currentLanguage,
        "direction" => $languageDirection,
        "translations" => [
            "fr" => [
                "Home" => "Accueil",
                "Courses" => "Formations",
                "About" => "À propos",
                "Dashboard" => "Tableau de bord",
                "Logout" => "Déconnexion",
                "Login" => "Connexion",
                "Request access" => "Demander un accès",
                "Check request status" => "Suivre ma demande",
                "Explore Courses" => "Découvrir les formations",
                "Online Training" => "Formation en ligne",
                "ONLINE PROFESSIONAL TRAINING" => "FORMATION PROFESSIONNELLE EN LIGNE",
                "Email" => "E-mail",
                "Password" => "Mot de passe",
                "Forgot your password?" => "Mot de passe oublié ?",
                "Back to Home" => "Retour à l'accueil",
                "Submit" => "Envoyer",
                "Save" => "Enregistrer",
                "Cancel" => "Annuler",
                "Edit" => "Modifier",
                "Delete" => "Supprimer",
                "Create" => "Créer",
                "Search" => "Rechercher",
                "Status" => "Statut",
                "Users" => "Utilisateurs",
                "Companies" => "Entreprises",
                "Lessons" => "Leçons",
                "Modules" => "Modules",
                "Quizzes" => "Quiz",
                "Exams" => "Examens",
                "Results" => "Résultats",
                "Certificates" => "Certificats",
                "Contact Requests" => "Demandes de contact",
                "Students" => "Étudiants",
                "Teachers" => "Formateurs",
                "Enrollments" => "Inscriptions",
                "Welcome" => "Bienvenue",
                "Progress" => "Progression",
                "View" => "Voir",
                "Continue" => "Continuer",
                "Published" => "Publié",
                "Draft" => "Brouillon",
                "Active" => "Actif",
                "Suspended" => "Suspendu",
                "Profile" => "Profil",
                "Settings" => "Paramètres",
                "Messages" => "Messages",
                "Notifications" => "Notifications",
                "Security" => "Sécurité",
                "Access" => "Accès",
                "Training" => "Formation",
                "Trainings" => "Formations",
                "Course" => "Formation",
                "Teacher" => "Formateur",
                "Student" => "Étudiant",
                "Company" => "Entreprise",
                "Company manager" => "Responsable entreprise",
                "Administrator" => "Administrateur",
                "New" => "Nouveau",
                "In progress" => "En cours",
                "Accepted" => "Acceptée",
                "Refused" => "Refusée",
                "Closed" => "Fermée",
                "Open" => "Ouvrir",
                "Add" => "Ajouter",
                "Update" => "Mettre à jour",
                "Save changes" => "Enregistrer les modifications",
                "Create account" => "Créer un compte",
                "Forgot Password" => "Mot de passe oublié",
                "Forgot password?" => "Mot de passe oublié ?",
                "Reset Password" => "Réinitialiser le mot de passe",
                "Confirm password" => "Confirmer le mot de passe",
                "Current password" => "Mot de passe actuel",
                "New password" => "Nouveau mot de passe",
                "First name" => "Prénom",
                "Last name" => "Nom",
                "Phone" => "Téléphone",
                "Address" => "Adresse",
                "City" => "Ville",
                "Country" => "Pays",
                "Website" => "Site web",
                "Industry" => "Secteur d'activité",
                "Filter" => "Filtrer",
                "Reset" => "Réinitialiser",
                "Actions" => "Actions",
                "Details" => "Détails",
                "Information" => "Informations",
                "Description" => "Description",
                "Duration" => "Durée",
                "Level" => "Niveau",
                "Price" => "Prix",
                "Instructor" => "Formateur",
                "No results found" => "Aucun résultat trouvé",
                "No courses available yet" => "Aucune formation disponible pour le moment",
                "View Course" => "Voir la formation",
                "Popular Courses" => "Formations populaires",
                "Professional Courses" => "Formations professionnelles",
                "Quizzes & Exams" => "Quiz et examens",
                "Track Your Progress" => "Suivez votre progression",
                "Language" => "Langue",
                "English" => "Anglais",
                "French" => "Français",
                "Unauthorized access." => "Accès non autorisé.",
                "Invalid request." => "Demande invalide.",
                "The requested item was not found." => "L'élément demandé est introuvable.",
            ],
        ],
        "languages" => array_map(
            static function (array $language): string {
                return $language["label"];
            },
            $supportedLanguages
        ),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $script = <<<HTML
<script>
(function () {
    const config = {$payload};
    const selector = document.createElement("select");
    selector.className = "language-selector";
    selector.setAttribute("aria-label", "Language");
    Object.entries(config.languages).forEach(function ([code, label]) {
        const option = document.createElement("option");
        option.value = code;
        option.textContent = label;
        option.selected = code === config.language;
        selector.appendChild(option);
    });
    selector.addEventListener("change", function () {
        const url = new URL(window.location.href);
        url.searchParams.set("lang", selector.value);
        window.location.assign(url.toString());
    });
    document.body.appendChild(selector);
    document.documentElement.lang = config.language;
    document.documentElement.dir = config.direction;
    if (config.translations && config.translations[config.language]) {
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        const nodes = [];
        while (walker.nextNode()) {
            nodes.push(walker.currentNode);
        }
        nodes.forEach(function (node) {
            const value = node.nodeValue.trim();
            if (config.translations[config.language][value]) {
                node.nodeValue = node.nodeValue.replace(value, config.translations[config.language][value]);
            }
        });
    }
})();
</script>
HTML;

    return str_ireplace("</body>", $script . "</body>", $html);
});
