<?php
// Increase memory for heavy JSON processing (override with PHP_MEMORY_LIMIT env)
@ini_set('memory_limit', getenv('PHP_MEMORY_LIMIT') ? getenv('PHP_MEMORY_LIMIT') : '512M');

// Simple PHP runner to fetch Arduino CLI JSON, save raw and formatted

// Base path is project root (this file's directory)
$baseDir = __DIR__;
$rawDir = $baseDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'raw';
$rawDetailsDir = $rawDir . DIRECTORY_SEPARATOR . 'details';
$webDir = $baseDir . DIRECTORY_SEPARATOR . 'docs';

// Ensure storage directories exist
foreach (array($rawDir, $rawDetailsDir, $webDir) as $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            fwrite(STDERR, "Failed to create directory: {$dir}\n");
            exit(1);
        }
    }
}

// Command configuration
$arduinoCli = getenv('ARDUINO_CLI_CMD') ? getenv('ARDUINO_CLI_CMD') : './arduino-cli';
$args = array('board', 'listall', '--json');
// Prepare filenames (always overwrite latest)
$baseName = "board_listall.json";
$rawPath = $rawDir . DIRECTORY_SEPARATOR . $baseName;

// Build command line safely
$cmdParts = array_map(function($p) { return escapeshellarg($p); }, array_merge(array($arduinoCli), $args));
$cmd = implode(' ', $cmdParts);

// Execute command (stream stdout directly to file to reduce memory)
$descriptorSpec = array(
    0 => array('pipe', 'r'), // stdin
    1 => array('file', $rawPath, 'w'), // stdout -> file
    2 => array('pipe', 'w'), // stderr
);

$process = proc_open($cmd, $descriptorSpec, $pipes, $baseDir);
if (!is_resource($process)) {
    fwrite(STDERR, "Failed to start process: {$cmd}\n");
    exit(1);
}

fclose($pipes[0]); // no input
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
$exitCode = proc_close($process);

if ($exitCode !== 0) {
    fwrite(STDERR, "arduino-cli command failed (exit {$exitCode}).\n");
    if ($stderr !== '') {
        fwrite(STDERR, $stderr . "\n");
    }
    exit($exitCode);
}

// Parse FQBNs from saved JSON in a streaming manner to reduce memory
$seenFqbn = array();
$buf = '';
$fhList = fopen($rawPath, 'rb');
if ($fhList) {
    while (!feof($fhList)) {
        $chunk = fread($fhList, 8192);
        if ($chunk === false) break;
        $buf .= $chunk;
        if (preg_match_all('/"fqbn"\s*:\s*"([^"]+)"/i', $buf, $m)) {
            foreach ($m[1] as $v) { $seenFqbn[$v] = true; }
        }
        if (strlen($buf) > 1024) { $buf = substr($buf, -1024); }
    }
    fclose($fhList);
}
$fqbnList = array_keys($seenFqbn);

// Log saved path for listall (raw only)
fwrite(STDOUT, "Saved raw: {$rawPath}\n");

// For each FQBN, fetch board details and save raw and formatted
$sanitize = function ($name) {
    // Windows-safe: replace any non [A-Za-z0-9._-] with '_'
    $res = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    return ($res !== null) ? $res : 'unknown';
};

$detailsArgsBase = array('board', 'details', '--json', '-b');
$detailsSaved = 0;
$detailsFailed = 0;
$jsonFlags = 0;
if (defined('JSON_PRETTY_PRINT')) $jsonFlags |= JSON_PRETTY_PRINT;
if (defined('JSON_UNESCAPED_UNICODE')) $jsonFlags |= JSON_UNESCAPED_UNICODE;
if (defined('JSON_UNESCAPED_SLASHES')) $jsonFlags |= JSON_UNESCAPED_SLASHES;

// Open aggregated compact details file for streaming write
$compactPath = $webDir . DIRECTORY_SEPARATOR . 'board_details.json';
$compactFH = fopen($compactPath, 'wb');
if (!$compactFH) {
    fwrite(STDERR, "Failed to open aggregated details for write: {$compactPath}\n");
    exit(1);
}
fwrite($compactFH, "{\n");
$firstEntry = true;

// Heuristic URL extractor: pick a plausible package URL from details
$extractPackageUrl = function ($node) {
    $best = null;
    $bestScore = -1;
    $isUrl = function ($s) {
        return is_string($s) && preg_match('#^https?://#i', $s);
    };
    $score = function ($key, $val) {
        $s = 0;
        if (!is_string($val))
            return -1;
        if (!preg_match('#^https?://#i', $val))
            return -1;
        $k = is_string($key) ? strtolower($key) : '';
        $v = strtolower($val);
        if (strpos($k, 'package') !== false)
            $s += 4;  // strong signal
        if (strpos($v, 'package') !== false)
            $s += 2;
        if (preg_match('#index\.json$#', $v))
            $s += 3;
        if (strpos($k, 'url') !== false)
            $s += 1;
        if (strpos($k, 'website') !== false || strpos($k, 'home') !== false)
            $s += 1;
        if (strpos($k, 'help') !== false || strpos($k, 'online') !== false)
            $s += 0; // weak
        return $s;
    };
    $walk = null;
    $walk = function ($x, $path = array()) use (&$walk, &$best, &$bestScore, $isUrl, $score) {
        if (is_array($x)) {
            foreach ($x as $k => $v) {
                if ($isUrl($v)) {
                    $sc = $score($k, $v);
                    if ($sc > $bestScore) {
                        $bestScore = $sc;
                        $best = $v;
                    }
                } elseif (is_array($v)) {
                    $walk($v, array_merge($path, array($k)));
                }
            }
        }
    };
    $walk($node);
    return $best;
};

// Normalize/override specific package URLs
$normalizePackageUrl = function ($url) {
    if (!is_string($url) || $url === '') {
        return $url;
    }
    $replacements = array(
        'https://downloads.arduino.cc/packages/package_index.tar.bz2' => 'https://espressif.github.io/arduino-esp32/package_esp32_index.json',
    );
    return isset($replacements[$url]) ? $replacements[$url] : $url;
};
foreach ($fqbnList as $fqbn) {
    $fqbnArg = $fqbn;
    $cmdParts2 = array_map(function($p) { return escapeshellarg($p); }, array_merge(array($arduinoCli), $detailsArgsBase, array($fqbnArg)));
    $cmd2 = implode(' ', $cmdParts2);

$descriptorSpecDetails = array(
    0 => array('pipe', 'r'),
    1 => array('pipe', 'w'),
    2 => array('pipe', 'w'),
);
$proc2 = proc_open($cmd2, $descriptorSpecDetails, $pipes2, $baseDir);
    if (!is_resource($proc2)) {
        fwrite(STDERR, "Failed to start details process for {$fqbn}\n");
        $detailsFailed++;
        continue;
    }
    fclose($pipes2[0]);
    $out2 = stream_get_contents($pipes2[1]);
    $err2 = stream_get_contents($pipes2[2]);
    fclose($pipes2[1]);
    fclose($pipes2[2]);
    $code2 = proc_close($proc2);

    $safe = $sanitize($fqbn);
    $rawDetailsPath = $rawDetailsDir . DIRECTORY_SEPARATOR . $safe . '.json';
    // No formatted details path

    if ($code2 !== 0) {
        fwrite(STDERR, "Details failed for {$fqbn} (exit {$code2}): {$err2}\n");
        $detailsFailed++;
        // still write stderr to a file for inspection
        file_put_contents($rawDetailsPath . '.error.txt', $err2 !== '' ? $err2 : 'Unknown error');
        continue;
    }

    // Save raw
    if (file_put_contents($rawDetailsPath, $out2) === false) {
        fwrite(STDERR, "Failed to write raw details for {$fqbn} to: {$rawDetailsPath}\n");
        $detailsFailed++;
        continue;
    }

    // Collect compact data
    $decoded2 = json_decode($out2, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        // Extract compact fields
        $nameVal = null;
        $versionVal = null;
        $configOptions = array();
        $packageUrl = null;
        if (is_array($decoded2)) {
            if (array_key_exists('name', $decoded2) && is_string($decoded2['name'])) {
                $nameVal = $decoded2['name'];
            }
            if (array_key_exists('version', $decoded2) && (is_string($decoded2['version']) || is_numeric($decoded2['version']))) {
                $versionVal = (string)$decoded2['version'];
            }
            if (array_key_exists('config_options', $decoded2) && is_array($decoded2['config_options'])) {
                $configOptions = $decoded2['config_options'];
            }
            if (isset($decoded2['package']) && is_array($decoded2['package']) && isset($decoded2['package']['url']) && is_string($decoded2['package']['url'])) {
                $packageUrl = $normalizePackageUrl($decoded2['package']['url']);
            } else {
                $packageUrl = $normalizePackageUrl($extractPackageUrl($decoded2));
            }
        }
        $entry = array(
            'name' => $nameVal,
            'version' => $versionVal,
            'config_options' => $configOptions,
            'package_url' => $packageUrl,
        );
        $keyJson = json_encode($fqbn);
        $entryJson = json_encode($entry, $jsonFlags);
        if ($keyJson === false || $entryJson === false || $keyJson === null || $entryJson === null) {
            // skip if encoding fails
        } else {
            if (!$firstEntry) { fwrite($compactFH, ",\n"); }
            fwrite($compactFH, "  " . $keyJson . ": " . $entryJson);
            $firstEntry = false;
        }
    }
    // Free per-iteration memory
    $decoded2 = null;
    $out2 = null;
    if (function_exists('gc_collect_cycles')) { gc_collect_cycles(); }
    $detailsSaved++;
}

fwrite(STDOUT, "Details saved: {$detailsSaved}, failed: {$detailsFailed}\n");

// Close aggregated file
fwrite($compactFH, "\n}\n");
fclose($compactFH);
fwrite(STDOUT, "Saved aggregated details: {$compactPath}\n");
