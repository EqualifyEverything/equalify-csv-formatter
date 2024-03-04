<?php
// Configuration
$csvFile = 'websites.csv';
$logFile = 'results.log';

// Open the CSV file for reading
$handle = fopen($csvFile, 'r');
if ($handle === false) {
    die("Error: Could not open CSV file for reading.\n");
}

// Process each Website from the CSV
fgetcsv($handle); // Skip header row
while (($data = fgetcsv($handle, 1000, ",")) !== false) {
    $website = trim($data[0]); // Extract the URL

    // Find the associated sitemap (if any)
    $sitemapUrl = findSitemap($website);

    if ($sitemapUrl) {
        // Process the sitemap, extract pages, AND update results.log
        processAndSavePages($website, $sitemapUrl); 
    } else {
        // No sitemap 
        saveToCsv($website, 'pages.csv');
        logMessage("$website - No Sitemap\n"); // Update results.log
    }
}

fclose($handle);

/**
 * Attempts to find a valid sitemap associated with a given URL.
 * @param string $website The base URL to check.
 * @return string|false The URL of the valid sitemap, or false if none found.
 */
function findSitemap($website) {
    // 1. Check for sitemap.xml directly
    $potentialUrl = $website . '/sitemap.xml';
    if (isValidSitemap($potentialUrl)) {
        return $potentialUrl;
    }

    // 2. Check robots.txt
    $robotsTxtUrl = $website . '/robots.txt';

    // Use cURL for better error handling
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $robotsTxtUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false); // Don't need headers
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3); // Limit redirects
    $robotsTxtContent = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 200) { // Check if robots.txt was fetched successfully
        $sitemapUrl = parseRobotsForSitemap($robotsTxtContent);
        if ($sitemapUrl && isValidSitemap($sitemapUrl)) {
            return $sitemapUrl;
        }
    }

    // No sitemap found
    return false;
}

/**
 * Processes a sitemap, extracts pages, saves them to a CSV file, AND logs to results.log
 * @param string $baseUrl The original URL associated with the sitemap.
 * @param string $sitemapUrl The URL of the sitemap to process.
 */
function processAndSavePages($baseUrl, $sitemapUrl) {
    $content = file_get_contents($sitemapUrl); // Consider using cURL for more detailed error handling

    // Pre-validate XML (if possible)
    libxml_use_internal_errors(true); 
    $xml = simplexml_load_string($content);

    if ($xml === false) {
        // Invalid XML
        $errors = libxml_get_errors();
        foreach ($errors as $error) {
            logMessage("$baseUrl - $sitemapUrl - XML Error: $error->message\n");
        }
        libxml_clear_errors();
        return; 
    }

    if ($xml->getName() === 'sitemapindex') {
        logMessage("$baseUrl - $sitemapUrl - Sitemap Index\n"); 
        // Process nested sitemaps (logging included within recursion)
        foreach ($xml->sitemap as $nestedSitemap) {
            processAndSavePages($baseUrl, $nestedSitemap->loc); // Updated!
        }
    } else {
        // Regular sitemap, extract pages & log
        foreach ($xml->url as $url) {
            saveToCsv($url->loc, 'pages.csv');
        }
        $pageCount = count($xml->url);
        logMessage("$baseUrl - $sitemapUrl - Pages: $pageCount\n"); 
    }
}


/**
 * Processes a sitemap and logs the appropriate information.
 * @param string $baseUrl The original URL associated with the sitemap.
 * @param string $sitemapUrl The URL of the sitemap to process.
 */
function processSitemap($baseUrl, $sitemapUrl) {
    $xml = simplexml_load_string(file_get_contents($sitemapUrl));

    if ($xml->getName() === 'sitemapindex') {
        logMessage("$baseUrl - $sitemapUrl - Sitemap Index\n");
        // Process nested sitemaps
        foreach ($xml->sitemap as $nestedSitemap) {
            processSitemap($baseUrl, $nestedSitemap->loc);
        }
    } else {
        $pageCount = count($xml->url);
        logMessage("$baseUrl - $sitemapUrl - Pages: $pageCount\n");
    }
}

/**
 * Checks if a given URL points to a valid sitemap.
 * @param string $sitemapUrl The sitemap URL to check.
 * @return bool True if valid, false otherwise.
 */
function isValidSitemap($sitemapUrl) {
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false, 
            'verify_peer_name' => false,
        ],
    ]);

    try {
        $headers = get_headers($sitemapUrl, 0, $context);
        return is_array($headers) && strpos($headers[0], '200 OK') !== false;
    } catch (Exception $e) {
        logMessage("$sitemapUrl - Sitemap Error: " . $e->getMessage() . "\n"); 
        return false;
    }
}

/**
 * Parses robots.txt content to find a Sitemap directive.
 * @param string $content The content of the robots.txt file.
 * @return string|false The URL of the sitemap found in robots.txt, or false if none found.
 */
function parseRobotsForSitemap($content) {
    $lines = explode("\n", $content);
    foreach ($lines as $line) {
        if (strpos($line, 'Sitemap:') === 0) {
            return trim(substr($line, 8));
        }
    }
    return false;
}

/**
 * Logs a message to the specified log file.
 * @param string $message The message to log.
 */
function logMessage($message) {
    global $logFile;
    file_put_contents($logFile, $message, FILE_APPEND);
}

/**
 * Saves a URL to a CSV file.
 * @param string $url The URL to save.
 * @param string $filename The name of the CSV file.
 */
function saveToCsv($url, $filename) {
    file_put_contents($filename, "$url\n", FILE_APPEND);
}