<?php
require_once __DIR__ . '/../../config/i18n.php';if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once "../../config/database.php";
require_once "../../config/student_access.php";
studentRequireAuth();

$certificateId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
$isAdminViewer = ($_SESSION["user_role"] ?? "") === "admin";
$statement = $pdo->prepare(
    "SELECT cert.*, c.title AS course_title, c.duration_hours,
            u.first_name, u.last_name, u.cin,
            enrollment.enrolled_at,
            exam.title AS exam_title, attempt.attempt_number,
            attempt.score, attempt.total_points, attempt.percentage, attempt.completed_at,
            attempt.quiz_average_percentage, attempt.quiz_count, attempt.combined_percentage,
            attempt.overall_passed, exam.passing_score
     FROM certificates cert INNER JOIN courses c ON c.id = cert.course_id
     INNER JOIN users u ON u.id = cert.user_id
     LEFT JOIN enrollments enrollment
        ON enrollment.user_id = cert.user_id AND enrollment.course_id = cert.course_id
     LEFT JOIN exam_attempts attempt
        ON attempt.id = cert.exam_attempt_id AND attempt.user_id = cert.user_id
     LEFT JOIN exams exam
        ON exam.id = attempt.exam_id AND exam.course_id = cert.course_id
     WHERE cert.id = ? " . ($isAdminViewer ? "" : "AND cert.user_id = ? ") . "LIMIT 1"
);
$statement->execute($isAdminViewer ? [$certificateId ?: 0] : [$certificateId ?: 0, (int) $_SESSION["user_id"]]);
$certificate = $statement->fetch(PDO::FETCH_ASSOC);
if (!$certificate) {
    http_response_code(404);
    exit("Certificat introuvable.");
}
$durationLabel = $certificate["duration_hours"] !== null
    ? rtrim(rtrim(number_format((float) $certificate["duration_hours"], 2, ",", " "), "0"), ",") . " heures"
    : "Non précisée";
$startedAtLabel = !empty($certificate["enrolled_at"])
    ? date("d/m/Y", strtotime($certificate["enrolled_at"]))
    : "Non disponible";
$completedAtLabel = !empty($certificate["completed_at"])
    ? date("d/m/Y", strtotime($certificate["completed_at"]))
    : date("d/m/Y", strtotime($certificate["issue_date"]));
$cinLabel = trim((string) ($certificate["cin"] ?? "")) !== ""
    ? $certificate["cin"]
    : "Non renseigné";

if (($_GET["download"] ?? "") === "1") {
    header("Content-Type: text/html; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"certificat-" . preg_replace("/[^A-Za-z0-9_-]/", "-", $certificate["certificate_number"]) . ".html\"");
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificat <?= studentEscape($certificate["certificate_number"]) ?></title><link rel="icon" type="image/png" href="../../assets/images/logo-mark.png">
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 32px; background: #eef1f4; color: #1b2c3b; font-family: "Segoe UI", Arial, sans-serif; }
        .actions { display: flex; justify-content: center; flex-wrap: wrap; gap: 10px; margin: 0 auto 22px; }
        .actions a { padding: 12px 18px; border: 1px solid #0d675f; border-radius: 5px; background: #0d675f; color: #fff; font-size: 13px; font-weight: 700; text-decoration: none; }
        .actions a.secondary { border-color: #d2dbe0; background: #fff; color: #243b4a; }
        .certificate { position: relative; isolation: isolate; display: flex; flex-direction: column; max-width: 1180px; min-height: 790px; margin: 0 auto; padding: 48px 66px 35px; overflow: hidden; background: #fff; border: 1px solid #d3dce1; box-shadow: 0 22px 60px rgba(21, 42, 57, .14); text-align: center; }
        .certificate::before { position: absolute; z-index: -1; inset: 13px; border: 1px solid #b89b5e; content: ""; }
        .certificate::after { position: absolute; z-index: -1; top: -150px; right: -90px; width: 400px; height: 400px; border: 1px solid rgba(13, 103, 95, .08); border-radius: 50%; box-shadow: 0 0 0 28px rgba(13, 103, 95, .025), 0 0 0 62px rgba(13, 103, 95, .018); content: ""; }
        .certificate-header { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding-bottom: 19px; border-bottom: 1px solid #e6eaec; color: #0d675f; text-align: left; }
        .brand { display: flex; align-items: center; gap: 12px; font-size: 13px; font-weight: 800; letter-spacing: .15em; }
        .certificate-logo { width: 48px; height: 48px; object-fit: contain; }
        .document-type { color: #7b6a43; font-size: 10px; font-weight: 800; letter-spacing: .18em; text-align: right; text-transform: uppercase; }
        .certificate-kicker { margin: 29px 0 7px; color: #9a7b3d; font-size: 10px; font-weight: 800; letter-spacing: .24em; text-transform: uppercase; }
        .certificate h1 { margin: 0; color: #183449; font-family: Georgia, "Times New Roman", serif; font-size: clamp(34px, 4.4vw, 51px); font-weight: 500; letter-spacing: .015em; }
        .certificate-intro { margin: 13px 0 4px; color: #72808a; font-family: Georgia, serif; font-size: 16px; font-style: italic; }
        .student-name { margin: 0 auto 10px; color: #0d675f; font-family: Georgia, "Times New Roman", serif; font-size: clamp(29px, 3.4vw, 42px); font-weight: 700; }
        .course-label { margin: 0 0 7px; color: #72808a; font-size: 12px; }
        .course-name { max-width: 850px; margin: 0 auto; color: #1b2c3b; font-family: Georgia, "Times New Roman", serif; font-size: clamp(21px, 2.7vw, 29px); font-weight: 600; }
        .achievement { display: grid; grid-template-columns: 1fr auto; align-items: center; gap: 18px; margin: 24px auto 0; padding: 16px 22px; width: 100%; border: 1px solid #dce8e3; background: #f7faf8; text-align: left; }
        .achievement-label { display: block; margin-bottom: 5px; color: #72808a; font-size: 9px; font-weight: 800; letter-spacing: .15em; text-transform: uppercase; }
        .achievement-title { display: block; color: #245849; font-family: Georgia, serif; font-size: 18px; }
        .score-seal { display: grid; align-content: center; justify-items: center; width: 82px; height: 82px; border: 2px solid #b89b5e; border-radius: 50%; outline: 1px solid #e6dcc3; outline-offset: 3px; background: #fff; color: #0d675f; }
        .score-seal strong { font-family: Georgia, serif; font-size: 23px; line-height: 1; }
        .score-seal span { margin-top: 4px; color: #718087; font-size: 8px; font-weight: 800; letter-spacing: .11em; text-transform: uppercase; }
        .score-breakdown { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin: 12px 0 0; }
        .score-item { padding: 11px 14px; border: 1px solid #e7ecef; background: #fff; text-align: left; }
        .score-item span { display: block; margin-bottom: 5px; color: #78858c; font-size: 9px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; }
        .score-item strong { color: #233d4a; font-size: 15px; }
        .details { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px 18px; margin: 18px 0 0; padding: 14px 17px; border-top: 1px solid #e4e9eb; border-bottom: 1px solid #e4e9eb; text-align: left; }
        .detail { min-width: 0; }
        .detail-label { display: block; margin-bottom: 5px; color: #78858c; font-size: 8px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
        .detail-value { display: block; overflow-wrap: anywhere; color: #233d4a; font-size: 11px; font-weight: 700; }
        .certificate-footer { display: grid; grid-template-columns: 1fr 1fr 1fr; align-items: end; gap: 20px; margin: auto 5px 0; padding-top: 22px; text-align: left; }
        .issue-block { color: #63737b; font-size: 10px; line-height: 1.7; }
        .certificate-number { margin-top: 6px; color: #203b49; font-size: 10px; font-weight: 800; letter-spacing: .04em; }
        .signature { justify-self: center; width: 175px; padding-top: 8px; border-top: 1px solid #b89b5e; color: #405662; font-family: Georgia, serif; font-size: 11px; text-align: center; }
        .verification { justify-self: end; color: #7a878d; font-size: 8px; text-align: right; }
        @media (max-width: 760px) {
            body { padding: 12px; }
            .certificate { min-height: 0; padding: 38px 28px 27px; }
            .certificate::before { inset: 9px; }
            .certificate-header { align-items: flex-start; }
            .brand { font-size: 11px; letter-spacing: .1em; }
            .certificate-logo { width: 38px; height: 38px; }
            .document-type { max-width: 130px; font-size: 8px; line-height: 1.5; }
            .achievement { padding: 13px; }
            .score-seal { width: 70px; height: 70px; }
            .details { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .certificate-footer { grid-template-columns: 1fr; justify-items: center; text-align: center; }
            .verification { justify-self: center; text-align: center; }
        }
        @page { size: A4 landscape; margin: 8mm; }
        @media print {
            body { padding: 0; background: #fff; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            .actions { display: none; }
            .certificate { width: 100%; max-width: none; min-height: 190mm; padding: 30px 42px 25px; box-shadow: none; }
            .certificate::before { inset: 9px; }
            .certificate-kicker { margin-top: 18px; }
            .achievement { margin-top: 16px; padding: 12px 18px; }
            .score-seal { width: 70px; height: 70px; }
            .details { margin-top: 13px; padding: 10px 14px; }
            .certificate-footer { padding-top: 15px; }
        }
        body { background: #f1f3f4; color: #202124; font-family: Arial, sans-serif; }
        .actions a { border-color: #1769aa; border-radius: 4px; background: #1769aa; }
        .actions a.secondary { border-color: #dadce0; background: #fff; color: #3c4043; }
        .certificate { min-height: 760px; padding: 52px 76px 38px; border-color: #dadce0; box-shadow: 0 8px 28px rgba(60, 64, 67, .12); }
        .certificate::before { z-index: 0; inset: 0 auto 0 0; width: 9px; border: 0; background: #1769aa; }
        .certificate::after { top: 0; right: 0; width: 115px; height: 5px; border: 0; border-radius: 0; background: #18a999; box-shadow: none; }
        .certificate > * { position: relative; }
        .certificate-header { padding-bottom: 20px; border-bottom-color: #e8eaed; color: #3c4043; }
        .brand { gap: 12px; font-size: 13px; font-weight: 700; letter-spacing: .08em; }
        .certificate-logo { width: 42px; height: 42px; }
        .document-type { color: #5f6368; font-size: 10px; letter-spacing: .12em; }
        .certificate-kicker { margin-top: 45px; color: #1769aa; font-size: 11px; letter-spacing: .13em; }
        .certificate h1 { color: #202124; font-family: Arial, sans-serif; font-size: clamp(32px, 4.2vw, 48px); font-weight: 400; letter-spacing: -.025em; }
        .certificate-intro { margin-top: 35px; color: #5f6368; font-family: Arial, sans-serif; font-size: 15px; font-style: normal; }
        .student-name { color: #1769aa; font-family: Arial, sans-serif; font-size: clamp(27px, 3.3vw, 40px); font-weight: 500; letter-spacing: -.02em; }
        .course-label { color: #5f6368; font-size: 14px; }
        .course-name { color: #202124; font-family: Arial, sans-serif; font-size: clamp(20px, 2.6vw, 28px); font-weight: 500; }
        .achievement { margin-top: 36px; padding: 18px 22px; border: 1px solid #d2e3fc; border-left: 4px solid #1a73e8; border-radius: 8px; background: linear-gradient(110deg, #f5f9ff, #f8fbff); }
        .achievement-label, .score-item span, .detail-label { color: #5f6368; }
        .achievement-label { margin-bottom: 7px; }
        .achievement-title { color: #202124; font-family: Arial, sans-serif; font-size: 17px; font-weight: 600; }
        .score-seal { width: 88px; height: 88px; border: 0; outline: 3px solid #d2e3fc; outline-offset: 3px; background: #1a73e8; color: #fff; box-shadow: 0 4px 12px rgba(26, 115, 232, .2); }
        .score-seal strong { font-family: Arial, sans-serif; font-size: 24px; font-weight: 700; letter-spacing: -.04em; }
        .score-seal span { color: rgba(255, 255, 255, .86); }
        .score-breakdown { gap: 12px; margin-top: 14px; }
        .score-item { min-height: 70px; padding: 13px 16px; border-color: #e8eaed; border-radius: 7px; background: #fff; }
        .score-item:first-child { border-top: 2px solid #34a853; }
        .score-item:nth-child(2) { border-top: 2px solid #4285f4; }
        .score-item:last-child { border-top: 2px solid #fbbc04; }
        .score-item span { font-size: 9px; }
        .score-item strong { color: #202124; font-size: 16px; font-weight: 600; }
        .score-item strong, .detail-value { color: #3c4043; font-weight: 500; }
        .details { padding: 15px 0; border-color: #e8eaed; }
        .certificate-footer { padding-top: 24px; }
        .issue-block, .verification { color: #5f6368; }
        .certificate-number { color: #3c4043; }
        .signature { border-color: #9aa0a6; color: #5f6368; font-family: Arial, sans-serif; }
        @media (max-width: 760px) {
            .certificate { min-height: 0; padding: 38px 24px 27px 32px; }
            .certificate::before { inset: 0 auto 0 0; width: 7px; }
            .certificate-kicker { margin-top: 24px; }
            .certificate-intro { margin-top: 22px; }
            .achievement { margin-top: 23px; padding: 12px 14px; }
            .achievement-title { font-size: 14px; line-height: 1.45; }
            .score-seal { width: 70px; height: 70px; outline-width: 2px; }
            .score-seal strong { font-size: 20px; }
            .score-breakdown { grid-template-columns: 1fr; gap: 8px; }
            .score-item { min-height: 0; padding: 10px 12px; }
        }
        @media print {
            .certificate { min-height: 190mm; padding: 30px 42px 25px 52px; }
            .certificate::before { inset: 0 auto 0 0; width: 6px; }
            .certificate-kicker { margin-top: 25px; }
            .achievement { background: #f5f9ff !important; }
            .score-seal { box-shadow: none; }
        }
    </style>
</head>

<body>
    <?php if (($_GET["download"] ?? "") !== "1"): ?><div class="actions"><a href="certificate.php?id=<?= (int) $certificate["id"] ?>&download=1">Télécharger le certificat</a><a class="secondary" href="../courses/course.php?id=<?= (int) $certificate["course_id"] ?>">Voir la formation</a><a class="secondary" href="../dashboard.php">Retour au tableau de bord</a></div><?php endif; ?>
    <article class="certificate">
        <header class="certificate-header">
            <div class="brand"><img src="../../assets/images/logo-mark.png" alt="" class="certificate-logo" width="48" height="48"><span>GAKU LEARNING</span></div>
            <span class="document-type">Formation professionnelle<br>Certificat officiel</span>
        </header>
        <p class="certificate-kicker">Attestation de réussite</p>
        <h1>Certificat de réussite</h1>
        <p class="certificate-intro">GAKU LEARNING certifie que</p>
        <h2 class="student-name"><?= studentEscape(trim($certificate["first_name"] . " " . $certificate["last_name"])) ?></h2>
        <p class="course-label">a suivi avec succès le parcours de formation</p>
        <h2 class="course-name"><?= studentEscape($certificate["course_title"]) ?></h2>
        <section class="achievement" aria-label="Résultat global">
            <div><span class="achievement-label">Résultat du parcours</span><strong class="achievement-title">Formation validée avec succès</strong></div>
            <div class="score-seal"><strong><?= studentEscape(number_format((float) $certificate["combined_percentage"], 0, ",", " ")) ?>%</strong><span>Résultat final</span></div>
        </section>
        <section class="score-breakdown" aria-label="Détail des évaluations">
            <div class="score-item"><span>Moyenne des quiz</span><strong><?= $certificate["quiz_average_percentage"] !== null ? studentEscape(number_format((float) $certificate["quiz_average_percentage"], 2, ",", " ")) . " %" : ((int) ($certificate["quiz_count"] ?? -1) === 0 ? "Aucun quiz" : "Non disponible") ?></strong></div>
            <div class="score-item"><span>Examen final</span><strong><?= studentEscape(number_format((float) $certificate["percentage"], 2, ",", " ")) ?> %</strong></div>
            <div class="score-item"><span>Seuil requis</span><strong><?= studentEscape(number_format((float) $certificate["passing_score"], 2, ",", " ")) ?> %</strong></div>
        </section>
        <section class="details" aria-label="Informations du titulaire et de la formation">
            <div class="detail"><span class="detail-label">CIN</span><strong class="detail-value"><?= studentEscape($cinLabel) ?></strong></div>
            <div class="detail"><span class="detail-label">Durée de la formation</span><strong class="detail-value"><?= studentEscape($durationLabel) ?></strong></div>
            <div class="detail"><span class="detail-label">Début de la formation</span><strong class="detail-value"><?= studentEscape($startedAtLabel) ?></strong></div>
            <div class="detail"><span class="detail-label">Examen final</span><strong class="detail-value"><?= studentEscape($certificate["exam_title"] ?: "Examen final") ?></strong></div>
        </section>
        <footer class="certificate-footer">
            <div class="issue-block">
                Réussite obtenue le <?= studentEscape($completedAtLabel) ?><br>
                Délivré le <?= date("d/m/Y", strtotime($certificate["issue_date"])) ?>
                <div class="certificate-number">N° <?= studentEscape($certificate["certificate_number"]) ?></div>
            </div>
            <div class="signature">Service de formation<br>GAKU LEARNING</div>
            <div class="verification">Document nominatif<br>Identifiant unique de délivrance</div>
        </footer>
    </article>
</body>

</html>
