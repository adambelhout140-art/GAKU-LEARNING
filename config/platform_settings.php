<?php

function platformBrandingDefaults(): array
{
    return [
        "platform_name" => "GAKU LEARNING",
        "platform_logo" => "",
        "platform_logo_full" => "",
        "platform_logo_dark" => "",
        "support_email" => "",
        "support_phone" => "",
        "contact_address" => "",
        "social_facebook" => "",
        "social_instagram" => "",
        "social_linkedin" => "",
        "social_youtube" => "",
        "social_tiktok" => "",
        "social_x" => "",
        "certificate_prefix" => "FP",
        "allow_registration" => "1",
    ];
}

function platformBranding(PDO $pdo): array
{
    global $platformBranding;
    if (isset($platformBranding) && is_array($platformBranding)) {
        return array_merge(platformBrandingDefaults(), $platformBranding);
    }

    $settings = platformBrandingDefaults();
    try {
        $rows = $pdo->query(
            "SELECT setting_key, setting_value FROM platform_settings"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($settings as $key => $default) {
            if (array_key_exists($key, $rows) && $rows[$key] !== null) {
                $settings[$key] = (string) $rows[$key];
            }
        }
    } catch (PDOException $exception) {
        // Existing installations may not have applied the platform settings migration yet.
    }
    return $settings;
}

function platformApplicationRoot(): string
{
    $scriptPath = str_replace("\\", "/", (string) ($_SERVER["SCRIPT_NAME"] ?? ""));
    $segments = ["/admin/", "/auth/", "/teacher/", "/student/", "/parent/", "/company/", "/public/"];
    $rootPosition = false;
    foreach ($segments as $segment) {
        $position = strpos($scriptPath, $segment);
        if ($position !== false && ($rootPosition === false || $position < $rootPosition)) {
            $rootPosition = $position;
        }
    }

    if ($rootPosition !== false) {
        return substr($scriptPath, 0, $rootPosition);
    }

    $directory = str_replace("\\", "/", dirname($scriptPath));
    return $directory === "/" || $directory === "." ? "" : rtrim($directory, "/");
}

function platformLogoUrl(PDO $pdo, string $variant = "icon"): string
{
    $settings = platformBranding($pdo);
    if ($variant === "full") {
        $variant = "light-bg";
    }
    $settingKey = [
        "light-bg" => "platform_logo_full",
        "dark-bg" => "platform_logo_dark",
        "icon" => "platform_logo",
    ][$variant] ?? "platform_logo";
    $fallback = [
        "light-bg" => "assets/images/logo-mark.png",
        "dark-bg" => "assets/images/logo-light.png",
        "icon" => "assets/images/logo-mark.png",
    ][$variant] ?? "assets/images/logo-mark.png";
    $logo = (string) ($settings[$settingKey] ?? "");
    if (!preg_match("#^uploads/branding/[a-f0-9]{32}\\.(png|jpg|webp)$#i", $logo)) {
        $logo = $fallback;
    }
    return platformApplicationRoot() . "/" . $logo;
}

function platformContactMarkup(PDO $pdo, string $className = "platform-contact"): string
{
    $settings = platformBranding($pdo);
    $items = [];
    $escape = static function (string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
    };

    $email = trim((string) $settings["support_email"]);
    if ($email !== "") {
        $items[] = '<a href="mailto:' . $escape($email) . '">' . $escape($email) . "</a>";
    }

    $phone = trim((string) $settings["support_phone"]);
    if ($phone !== "") {
        $tel = preg_replace("/[^0-9+*#;,]/", "", $phone);
        if ($tel !== "") {
            $items[] = '<a href="tel:' . $escape($tel) . '">' . $escape($phone) . "</a>";
        }
    }

    $address = trim((string) $settings["contact_address"]);
    if ($address !== "") {
        $items[] = "<span>" . nl2br($escape($address)) . "</span>";
    }

    $socialLabels = [
        "social_facebook" => "Facebook",
        "social_instagram" => "Instagram",
        "social_linkedin" => "LinkedIn",
        "social_youtube" => "YouTube",
        "social_tiktok" => "TikTok",
        "social_x" => "X",
    ];
    foreach ($socialLabels as $key => $label) {
        $url = trim((string) $settings[$key]);
        if ($url !== "" && filter_var($url, FILTER_VALIDATE_URL) && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === "https") {
            $items[] = '<a href="' . $escape($url) . '" target="_blank" rel="noopener noreferrer">' . $label . "</a>";
        }
    }

    if ($items === []) {
        return "";
    }

    $class = preg_replace("/[^a-zA-Z0-9 _-]/", "", $className);
    return '<section class="' . $escape($class) . '" aria-label="Contact information">'
        . '<strong>Contact</strong><div>' . implode(" <span aria-hidden=\"true\">·</span> ", $items) . "</div></section>";
}

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

function platformNavbarDefaults(): array
{
    return [
        ["label" => "Accueil", "href" => "index.php", "visible" => true, "order" => 1],
        ["label" => "Formations", "href" => "index.php#courses", "visible" => true, "order" => 2],
        ["label" => "Toutes les formations", "href" => "public/courses.php", "visible" => true, "order" => 3],
        ["label" => "À propos", "href" => "index.php#about", "visible" => true, "order" => 4],
    ];
}

function platformNavbarLinks(PDO $pdo): array
{
    $stored = platformSetting($pdo, "public_nav_links");
    $links = $stored !== "" ? json_decode($stored, true) : null;
    if (!is_array($links)) {
        $links = platformNavbarDefaults();
    }

    $applicationRoot = platformApplicationRoot();
    $visibleLinks = [];
    foreach ($links as $index => $link) {
        if (!is_array($link) || empty($link["visible"])) {
            continue;
        }
        $label = trim((string) ($link["label"] ?? ""));
        $href = trim((string) ($link["href"] ?? ""));
        if ($label === "" || $href === "") {
            continue;
        }
        $isExternal = strpos($href, "https://") === 0;
        $visibleLinks[] = [
            "label" => $label,
            "href" => $isExternal ? $href : (strpos($href, "/") === 0
                ? $href
                : $applicationRoot . "/" . ltrim($href, "/")),
            "external" => $isExternal,
            "order" => (int) ($link["order"] ?? $index + 1),
            "index" => $index,
        ];
    }

    usort($visibleLinks, static function (array $left, array $right): int {
        return ($left["order"] <=> $right["order"]) ?: ($left["index"] <=> $right["index"]);
    });

    return $visibleLinks;
}
