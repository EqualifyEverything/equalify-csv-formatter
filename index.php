<?php

// Custom error handler to silently catch warnings
function customErrorHandler($errno, $errstr, $errfile, $errline) {
    if (0 === error_reporting()) {
        return false; // Error was suppressed with the @-operator
    }
    echo "Error encountered: $errstr\n";
    return true; // Indicates the error was handled
}

set_error_handler('customErrorHandler');

function fetchTitle($url) {
    $context = stream_context_create(['http' => ['method' => 'GET', 'header' => 'User-Agent: Mozilla/5.0']]);
    $content = @file_get_contents($url, false, $context);
    if ($content === FALSE) {
        return "Unknown"; // Return "Unknown" if the content couldn't be fetched
    }

    preg_match("/<title>(.*?)<\/title>/is", $content, $matches);
    $title = $matches[1] ?? "Unknown"; // Use "Unknown" if title can't be found
    return html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function hasSitemap($url) {
    $sitemapPaths = ["sitemap.xml", "wp-sitemap.xml"];
    foreach ($sitemapPaths as $path) {
        $fullPath = $url . '/' . $path;
        if (filter_var($fullPath, FILTER_VALIDATE_URL) === FALSE) continue;

        $headers = @get_headers($fullPath);
        if ($headers && strpos($headers[0], '200')) return $path;
    }

    // Attempt to fetch robots.txt and search for sitemap
    $robotsTxtUrl = $url . '/robots.txt';
    if (filter_var($robotsTxtUrl, FILTER_VALIDATE_URL) !== FALSE) {
        $robotsTxt = @file_get_contents($robotsTxtUrl);
        if ($robotsTxt !== FALSE) {
            preg_match_all("/Sitemap: (.*)/i", $robotsTxt, $matches);
            if (!empty($matches[1])) return $matches[1][0];
        }
    }

    return false; // Return false if no sitemap is found
}

function isSiteReachable($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3");
    
    curl_exec($ch);
    $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Consider any 2xx response as "site is reachable"
    return $responseCode >= 200 && $responseCode < 300;
}

$websites = array_map('str_getcsv', file('websites.csv'));
$results = fopen("results.csv", "w");
fputcsv($results, ["Name", "URL", "Discovery"]);

foreach ($websites as $website) {
    $url = trim($website[0]);
    if (empty($url) || filter_var($url, FILTER_VALIDATE_URL) === FALSE) {
        echo "Skipping invalid URL: $url\n";
        continue;
    }

    if (!isSiteReachable($url)) {
        fputcsv($results, ["Unreachable", $url, "Unreachable"]);
        continue;
    }

    $sitemap = hasSitemap($url);
    $name = fetchTitle($url) ?? "Unknown";
    $discovery = $sitemap ? "Sitemap Import" : "Single Page Import";

    fputcsv($results, [$name, $url, $discovery]);
}

fclose($results);

echo "Script completed.\n";

// Restore the previous error handler
restore_error_handler();
