<?php

function platformSetting(PDO $pdo, string $key, string $default = ""): string
{
    static $settings = [];
    if (!array_key_exists($key, $settings)) {
        try {
            $statement = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = ? LIMIT 1");
            $statement->execute([$key]);
            $settings[$key] = (string) ($statement->fetchColumn() ?? $default);
        } catch (PDOException $exception) {
            $settings[$key] = $default;
        }
    }
    return $settings[$key] !== "" ? $settings[$key] : $default;
}
