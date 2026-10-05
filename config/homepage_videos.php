<?php
function homepageVideoEmbedUrl(string $url): string
{
    $parts = parse_url(trim($url));
    if (!$parts || empty($parts["host"])) {
        return "";
    }

    $host = strtolower($parts["host"]);
    $path = trim($parts["path"] ?? "", "/");
    parse_str($parts["query"] ?? "", $query);

    if (in_array($host, ["youtube.com", "www.youtube.com", "m.youtube.com", "youtube-nocookie.com", "www.youtube-nocookie.com"], true)) {
        $videoId = "";
        if (isset($query["v"])) {
            $videoId = (string) $query["v"];
        } elseif (preg_match("#^(?:embed|shorts)/([^/]+)#", $path, $matches)) {
            $videoId = $matches[1];
        }
        return preg_match("/^[A-Za-z0-9_-]{6,}$/", $videoId)
            ? "https://www.youtube-nocookie.com/embed/" . rawurlencode($videoId)
            : "";
    }

    if ($host === "youtu.be") {
        $videoId = explode("/", $path)[0] ?? "";
        return preg_match("/^[A-Za-z0-9_-]{6,}$/", $videoId)
            ? "https://www.youtube-nocookie.com/embed/" . rawurlencode($videoId)
            : "";
    }

    if (in_array($host, ["vimeo.com", "www.vimeo.com"], true)) {
        $videoId = explode("/", $path)[0] ?? "";
        return ctype_digit($videoId)
            ? "https://player.vimeo.com/video/" . rawurlencode($videoId)
            : "";
    }

    return "";
}
