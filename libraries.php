<?php
// Fetch Arduino library index and export to docs/libraries.json

$baseDir = __DIR__;
$webDir = $baseDir . DIRECTORY_SEPARATOR . 'docs';
$outPath = $webDir . DIRECTORY_SEPARATOR . 'libraries.json';
$srcUrl = 'http://downloads.arduino.cc/libraries/library_index.json';

// Ensure web directory exists
if (!is_dir($webDir)) {
    if (!mkdir($webDir, 0777, true) && !is_dir($webDir)) {
        fwrite(STDERR, "Failed to create directory: {$webDir}\n");
        exit(1);
    }
}

// Download library index JSON
$rawData = null;
$errMsg = '';

if (function_exists('curl_init')) {
    $ch = curl_init($srcUrl);
    if ($ch) {
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FAILONERROR => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'ArduinoCliBoard/1.0 (+php)'
        ));
        $data = curl_exec($ch);
        if ($data === false) {
            $errMsg = 'cURL error: ' . curl_error($ch);
        } else {
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($http >= 200 && $http < 300) {
                $rawData = $data;
            } else {
                $errMsg = 'HTTP status: ' . $http;
            }
        }
        curl_close($ch);
    } else {
        $errMsg = 'Failed to init cURL handle';
    }
}

if ($rawData === null) {
    $ctx = stream_context_create(array(
        'http' => array('timeout' => 60, 'follow_location' => 1, 'user_agent' => 'ArduinoCliBoard/1.0 (+php)'),
        'https' => array('timeout' => 60, 'user_agent' => 'ArduinoCliBoard/1.0 (+php)'),
    ));
    $fallback = @file_get_contents($srcUrl, false, $ctx);
    if ($fallback !== false) {
        $rawData = $fallback;
    } elseif ($errMsg === '') {
        $errMsg = 'Failed to download library index';
    }
}

if ($rawData === null) {
    fwrite(STDERR, "Failed to download library index: {$errMsg}\n");
    exit(1);
}

$decoded = json_decode($rawData, true);
if (!is_array($decoded)) {
    fwrite(STDERR, "Failed to decode library index JSON.\n");
    exit(1);
}

$libraryEntries = $decoded['libraries'] ?? null;
if (!is_array($libraryEntries)) {
    fwrite(STDERR, "Unexpected library index format.\n");
    exit(1);
}

$aggregated = array();
foreach ($libraryEntries as $library) {
    if (!is_array($library) || !isset($library['name'], $library['version'])) {
        continue;
    }

    $name = $library['name'];
    $version = $library['version'];

    $dependencies = array();
    if (!empty($library['dependencies']) && is_array($library['dependencies'])) {
        foreach ($library['dependencies'] as $dep) {
            if (is_array($dep) && isset($dep['name']) && $dep['name'] !== '') {
                $dependencies[] = $dep['name'];
            } elseif (is_string($dep) && $dep !== '') {
                $dependencies[] = $dep;
            }
        }
    }
    $dependenciesStr = implode(', ', $dependencies);

    $architecturesField = $library['architectures'] ?? array();
    if (is_array($architecturesField)) {
        $architecturesStr = implode(', ', $architecturesField);
    } elseif (is_string($architecturesField)) {
        $architecturesStr = $architecturesField;
    } else {
        $architecturesStr = '';
    }

    $typesField = $library['types'] ?? array();
    if (is_array($typesField)) {
        $typesStr = implode(', ', $typesField);
    } elseif (is_string($typesField)) {
        $typesStr = $typesField;
    } else {
        $typesStr = '';
    }

    $entry = array(
        'name' => $name,
        'version' => $version,
        'versions' => array($version),
        'author' => $library['author'] ?? null,
        'maintainer' => $library['maintainer'] ?? null,
        'website' => $library['website'] ?? null,
        'category' => $library['category'] ?? null,
        'architectures' => $architecturesStr,
        'types' => $typesStr,
        'repository' => $library['repository'] ?? null,
        'dependencies' => $dependenciesStr,
    );

    if (isset($aggregated[$name])) {
        $existing = $aggregated[$name];
        $existingVersions = $existing['versions'];
        if (!in_array($version, $existingVersions, true)) {
            $existingVersions[] = $version;
        }

        if (version_compare($existing['version'], $version) === 1) {
            $existing['versions'] = $existingVersions;
            $aggregated[$name] = $existing;
            continue;
        }

        $entry['versions'] = array_merge($entry['versions'], $existingVersions);
    }

    $aggregated[$name] = $entry;
}

$rows = array();
foreach ($aggregated as $item) {
    $versions = array_values(array_unique($item['versions']));
    usort($versions, function ($a, $b) {
        return version_compare($b, $a);
    });

    $latestVersion = $versions[0] ?? $item['version'];
    $versionsStr = implode(', ', $versions);
    $dependenciesStr = $item['dependencies'];

    $rows[] = array(
        'name' => $item['name'],
        'version' => $latestVersion,
        'versions' => $versionsStr,
        'author' => $item['author'],
        'maintainer' => $item['maintainer'],
        'website' => $item['website'],
        'category' => $item['category'],
        'architectures' => $item['architectures'],
        'types' => $item['types'],
        'repository' => $item['repository'],
        'dependencies' => explode(', ', $dependenciesStr),
    );
}

// Encode and write JSON
$jsonFlags = 0;
if (defined('JSON_PRETTY_PRINT')) $jsonFlags |= JSON_PRETTY_PRINT;
if (defined('JSON_UNESCAPED_UNICODE')) $jsonFlags |= JSON_UNESCAPED_UNICODE;
if (defined('JSON_UNESCAPED_SLASHES')) $jsonFlags |= JSON_UNESCAPED_SLASHES;
$json = json_encode($rows, $jsonFlags);
if ($json === false || $json === null) {
    fwrite(STDERR, "Failed to encode JSON.\n");
    exit(1);
}
$json .= "\n";

if (file_put_contents($outPath, $json) === false) {
    fwrite(STDERR, "Failed to write JSON to: {$outPath}\n");
    exit(1);
}

echo $json;
fwrite(STDOUT, "Saved libraries: {$outPath}\n");
