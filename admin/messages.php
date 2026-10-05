<?php
header("Location: notifications.php" . (!empty($_SERVER["QUERY_STRING"]) ? "?" . $_SERVER["QUERY_STRING"] : ""));
exit;
