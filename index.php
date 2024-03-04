<?php
// Read CSV file
$csvFile = 'sitelist.csv'; // Change to actual path
$handle = fopen($csvFile, 'r');
if ($handle !== FALSE) {
    fgetcsv($handle); // Skip header
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        $url = $data[0];
        $sitemapUrl = checkSitemap($url);
        if ($sitemapUrl) {
            logMessage('Valid Sitemap: ' .$url);
            //processSitemap($sitemapUrl);
        } else {
            logMessage('No Sitemap: ' .$url);
        }
    }
    fclose($handle);
} else {
    logMessage("Failed to open the CSV file.");
}

function logMessage($message) {
    $logFile = 'process.log';
    file_put_contents($logFile, $message . PHP_EOL, FILE_APPEND);
    echo "$message\n";
}

function checkSitemap($url) {
    $sitemapUrl = rtrim($url, '/') . '/sitemap.xml';
    $robotsTxtUrl = rtrim($url, '/') . '/robots.txt';

    // Check sitemap.xml
    $sitemapUrl = followRedirects($sitemapUrl);
    if ($sitemapUrl) {
        return $sitemapUrl; // Return final sitemap URL after following redirects
    }

    // Use cURL for better error handling when checking robots.txt
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $robotsTxtUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FAILONERROR, true); // Important: this will make cURL fail silently on HTTP errors
    $robotsTxt = curl_exec($ch);
    if ($robotsTxt) {
        $lines = explode("\n", $robotsTxt);
        foreach ($lines as $line) {
            if (strpos($line, 'Sitemap:') === 0) {
                $sitemapFromRobots = trim(substr($line, strlen('Sitemap:')));
                $headers = get_headers($sitemapFromRobots);
                if ($headers && strpos($headers[0], '200') !== false) {
                    curl_close($ch);
                    return $sitemapFromRobots; // Return sitemap URL found via robots.txt
                }
            }
        }
    }
    curl_close($ch);

    return false; // No sitemap found
}

function saveResult($jobId, $data) {
    $resultsDir = 'results'; // Ensure this directory exists and is writable
    $filePath = $resultsDir . '/' . $jobId . '.json';
    file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT));
}


function followRedirects($url) {
    $headers = get_headers($url, 1);
    if ($headers && strpos($headers[0], '200') !== false) {
        return $url; // URL points directly to a resource
    } elseif ($headers && (strpos($headers[0], '301') !== false || strpos($headers[0], '302') !== false)) {
        if (is_array($headers['Location'])) {
            // If there are multiple Location headers, use the last one
            $newUrl = end($headers['Location']);
        } else {
            $newUrl = $headers['Location'];
        }
        return followRedirects($newUrl); // Recursive call to follow redirects
    }

    return false; // No valid URL found after following redirects
}

function processSitemap($sitemapUrl) {
    $apiUrl = "http://198.211.98.156/generate/sitemapurl";
    $postData = json_encode(['url' => $sitemapUrl]);

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($postData)
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false || $httpcode != 200) {
        logMessage("Error processing sitemap for $sitemapUrl: " . curl_error($ch));
        curl_close($ch);
        return;
    }

    curl_close($ch);

    $jobIds = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logMessage("Error decoding JSON response for $sitemapUrl");
        return;
    }

    generateResults($jobIds);
}

function fetchJobResult($jobId) {
    $apiUrl = "http://198.211.98.156/results/" . $jobId;
    sleep(3); // Wait for 5 seconds before making the request

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false || $httpcode != 200) {
        logMessage("Error fetching results for JobID $jobId: " . curl_error($ch));
        curl_close($ch);
        return;
    }

    curl_close($ch);
    $data = json_decode($response, true);
    if (isset($data['status']) && $data['status'] === 'completed') {
        saveResult($jobId, $data);
    } else {
        // Log any other status
        $status = isset($data['status']) ? $data['status'] : 'unknown';
        logMessage("JobID $jobId returned status: $status");
    }
}

function generateResults($jobIds) {
    foreach ($jobIds as $job) {
        fetchJobResult($job['JobID']);
    }
}

?>