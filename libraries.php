<?php
declare(strict_types=1);

// Fetch Arduino libraries doxygen DB and export to web/libraries.json

$baseDir = __DIR__;
$webDir = $baseDir . DIRECTORY_SEPARATOR . 'web';
$outPath = $webDir . DIRECTORY_SEPARATOR . 'libraries.json';
$srcUrl = 'https://lang-ship.com/reference/Arduino/libraries/doxygen.db';

// Ensure web directory exists
if (!is_dir($webDir)) {
    if (!mkdir($webDir, 0777, true) && !is_dir($webDir)) {
        fwrite(STDERR, "Failed to create directory: {$webDir}\n");
        exit(1);
    }
}

// Download DB to a temporary file
$tmpDb = tempnam(sys_get_temp_dir(), 'doxygen_db_');
if ($tmpDb === false) {
    fwrite(STDERR, "Failed to create temporary file.\n");
    exit(1);
}

$downloadOk = false;
$errMsg = '';

// Try cURL extension first
if (function_exists('curl_init')) {
    $ch = curl_init($srcUrl);
    $fh = fopen($tmpDb, 'wb');
    if ($ch && $fh) {
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FAILONERROR => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FILE => $fh,
            CURLOPT_USERAGENT => 'ArduinoCliBoard/1.0 (+php)'
        ]);
        $res = curl_exec($ch);
        if ($res === false) {
            $errMsg = 'cURL error: ' . curl_error($ch);
        } else {
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($http >= 200 && $http < 300) {
                $downloadOk = true;
            } else {
                $errMsg = 'HTTP status: ' . $http;
            }
        }
        curl_close($ch);
        if (is_resource($fh)) fclose($fh);
    } else {
        if ($ch) curl_close($ch);
        if (isset($fh) && is_resource($fh)) fclose($fh);
        $errMsg = 'Failed to init cURL/file handle';
    }
}

// Fallback to file_get_contents if needed
if (!$downloadOk) {
    $ctx = stream_context_create([
        'http' => ['timeout' => 60, 'follow_location' => 1, 'user_agent' => 'ArduinoCliBoard/1.0 (+php)'],
        'https' => ['timeout' => 60, 'user_agent' => 'ArduinoCliBoard/1.0 (+php)'],
    ]);
    $data = @file_get_contents($srcUrl, false, $ctx);
    if ($data !== false) {
        if (file_put_contents($tmpDb, $data) !== false) {
            $downloadOk = true;
        } else {
            $errMsg = 'Failed to write downloaded data to temp file';
        }
    }
}

if (!$downloadOk) {
    @unlink($tmpDb);
    fwrite(STDERR, "Failed to download DB: {$errMsg}\n");
    exit(1);
}

// Ensure SQLite3 is available
if (!class_exists(SQLite3::class)) {
    @unlink($tmpDb);
    fwrite(STDERR, "SQLite3 extension is required.\n");
    exit(1);
}

// Open DB and query
$db = new SQLite3($tmpDb, SQLITE3_OPEN_READONLY);
if (!$db) {
    @unlink($tmpDb);
    fwrite(STDERR, "Failed to open SQLite DB.\n");
    exit(1);
}

$res = $db->query('SELECT * FROM doxygen');
if ($res === false) {
    $db->close();
    @unlink($tmpDb);
    fwrite(STDERR, "Failed to query doxygen table.\n");
    exit(1);
}

$rows = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $rows[] = $row;
}
$db->close();
@unlink($tmpDb);

// Encode and write JSON
$json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
if ($json === false) {
    fwrite(STDERR, "Failed to encode JSON.\n");
    exit(1);
}

if (file_put_contents($outPath, $json) === false) {
    fwrite(STDERR, "Failed to write JSON to: {$outPath}\n");
    exit(1);
}

echo $json;
fwrite(STDOUT, "Saved libraries: {$outPath}\n");

