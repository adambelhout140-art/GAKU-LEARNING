<?php

//* 1. Define the connection details (Where is the database and how do we access it?)
$host = "localhost";        //* The server address (localhost means it is running on this same computer)
$port = "3308"; //* Specify port 3308 used by WampServer
$dbname = "formation_platform"; //* The specific name of the database we want to talk to
$username = "root";         //* Your database username (default for local servers)
$password = "";             //* Your database password (empty by default on local setups)

try {
    //* 2. Attempt to open a connection using PDO (PHP Data Objects)
    //* We pass the database type (MySQL), address, database name, character encoding, and login details
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    //* 3. Configure how the connection behaves once connected:

    //* Tell PHP to throw a clear error (Exception) if a database query fails, making debugging much easier
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    //* Set the default format when pulling data so results are automatically formatted as clean key-value lists (e.g., $row['username'])
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION["user_id"])) {
        $scriptName = str_replace("\\", "/", (string) ($_SERVER["SCRIPT_NAME"] ?? ""));
        $protectedArea = preg_match("#/(admin|teacher|student|parent|company)/#", $scriptName);

        if ($protectedArea) {
            $activeAccount = $pdo->prepare("SELECT is_active FROM users WHERE id = ? LIMIT 1");
            $activeAccount->execute([(int) $_SESSION["user_id"]]);

            if ((int) $activeAccount->fetchColumn() !== 1) {
                $_SESSION = [];
                if (ini_get("session.use_cookies")) {
                    $params = session_get_cookie_params();
                    setcookie(
                        session_name(),
                        "",
                        time() - 42000,
                        $params["path"],
                        $params["domain"],
                        $params["secure"],
                        $params["httponly"]
                    );
                }
                session_destroy();
                $applicationPath = preg_replace("#/(admin|teacher|student|parent|company)/.*$#", "", $scriptName);
                header("Location: " . ($applicationPath ?: "") . "/auth/login.php?inactive=1");
                exit;
            }
        }
    }

} catch (PDOException $e) {
    //* 4. If anything goes wrong during connection, stop the script immediately and show what failed
    die("Database connection failed: " . $e->getMessage());
}

?>