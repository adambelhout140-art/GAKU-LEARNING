<?php
require_once __DIR__ . '/../../config/i18n.php';

// ============================================================
// * GAKU LEARNING - ADMIN COMPANY DELETE ENDPOINT
// ============================================================
// ! RESTRICTED ACCESS: Requires an authenticated administrator.
// ? Purpose: Deletes a company only after authentication, POST, CSRF, and ID validation succeed.
// ? Note: users.company_id uses ON DELETE SET NULL, so linked users are retained without a company.
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
// * REQUEST METHOD VALIDATION
// ! A GET request must never be able to delete an organization.
// ------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: companies.php");
    exit;
}


// ------------------------------------------------------------
// * INPUT SANITIZATION & CSRF VALIDATION
// ? Reject forged requests and malformed IDs before executing DELETE.
// ------------------------------------------------------------
$companyId = filter_input(INPUT_POST, "company_id", FILTER_VALIDATE_INT);
$csrfToken = $_POST["csrf_token"] ?? "";

if (empty($_SESSION["csrf_token"]) || !hash_equals($_SESSION["csrf_token"], $csrfToken) || !$companyId || $companyId < 1) {
    header("Location: companies.php?error=delete");
    exit;
}


// ------------------------------------------------------------
// * DATABASE QUERY: DELETE COMPANY RECORD
// ? Prepared SQL prevents the submitted company ID from becoming SQL syntax.
// ------------------------------------------------------------
$statement = $pdo->prepare("DELETE FROM companies WHERE id = ?");
$statement->execute([$companyId]);


// ------------------------------------------------------------
// * POST-PROCESSING REDIRECT
// ? Directs the administrator back to the company list with a status message.
// ------------------------------------------------------------
header("Location: companies.php?success=deleted");
exit;
