<?php
declare(strict_types=1);

// Simple PHP runner to fetch Arduino CLI JSON, save raw and formatted

// Base path is project root (this file's directory)
$baseDir = __DIR__;
$rawDir = $baseDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'raw';
$rawDetailsDir = $rawDir . DIRECTORY_SEPARATOR . 'details';
$webDir = $baseDir . DIRECTORY_SEPARATOR . 'docs';

// Ensure storage directories exist
foreach ([$rawDir, $rawDetailsDir, $webDir] as $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            fwrite(STDERR, "Failed to create directory: {$dir}\n");
            exit(1);
        }
    }
}

// Command configuration
$arduinoCli = getenv('ARDUINO_CLI_CMD') ?: './arduino-cli';
$args = ['board', 'listall', '--json'];

// Build command line safely
$cmdParts = array_map(static fn($p) => escapeshellarg($p), array_merge([$arduinoCli], $args));
$cmd = implode(' ', $cmdParts);

// Execute command
$descriptorSpec = [
    0 => ['pipe', 'r'], // stdin
    1 => ['pipe', 'w'], // stdout
    2 => ['pipe', 'w'], // stderr
];

$process = proc_open($cmd, $descriptorSpec, $pipes, $baseDir);
if (!\is_resource($process)) {
    fwrite(STDERR, "Failed to start process: {$cmd}\n");
    exit(1);
}

fclose($pipes[0]); // no input
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

if ($exitCode !== 0) {
    fwrite(STDERR, "arduino-cli command failed (exit {$exitCode}).\n");
    if ($stderr !== '') {
        fwrite(STDERR, $stderr . "\n");
    }
    exit($exitCode);
}

// Prepare filenames (always overwrite latest)
$baseName = "board_listall.json";
$rawPath = $rawDir . DIRECTORY_SEPARATOR . $baseName;
// No formatted path for listall

// Save raw JSON
if (file_put_contents($rawPath, $stdout) === false) {
    fwrite(STDERR, "Failed to write raw JSON to: {$rawPath}\n");
    exit(1);
}

// Try decode to pretty-print (and transform to fqbn=>name map)
$decoded = json_decode($stdout, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    // Warn and continue; only raw is saved
    fwrite(STDERR, "Warning: Received invalid JSON (" . json_last_error_msg() . "). Saved original content to raw.\n");
    // Paths info
    fwrite(STDOUT, "Saved raw: {$rawPath}\n");
    exit(0);
}

// Build fqbn => name map from decoded data
$isList = static function (array $arr): bool {
    if (function_exists('array_is_list')) {
        return array_is_list($arr);
    }
    $i = 0;
    foreach ($arr as $k => $_) {
        if ($k !== $i++)
            return false;
    }
    return true;
};

$map = [];
$stack = [$decoded];
while ($stack) {
    $node = array_pop($stack);
    if (!is_array($node)) {
        continue;
    }
    $hasFqbn = array_key_exists('fqbn', $node) && is_string($node['fqbn']);
    $hasName = array_key_exists('name', $node) && is_string($node['name']);
    if ($hasFqbn && $hasName) {
        $map[$node['fqbn']] = $node['name'];
    }
    if ($isList($node)) {
        foreach ($node as $item) {
            if (is_array($item)) {
                $stack[] = $item;
            }
        }
    } else {
        foreach ($node as $value) {
            if (is_array($value)) {
                $stack[] = $value;
            }
        }
    }
}

// Log saved path for listall (raw only)
fwrite(STDOUT, "Saved raw: {$rawPath}\n");

// For each FQBN, fetch board details and save raw and formatted
$sanitize = static function (string $name): string {
    // Windows-safe: replace any non [A-Za-z0-9._-] with '_'
    return preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'unknown';
};

$detailsArgsBase = ['board', 'details', '--json', '-b'];
$detailsSaved = 0;
$detailsFailed = 0;
$detailsCompact = [];

// Heuristic URL extractor: pick a plausible package URL from details
$extractPackageUrl = static function ($node) {
    $best = null;
    $bestScore = -1;
    $isUrl = static function ($s) {
        return is_string($s) && preg_match('#^https?://#i', $s);
    };
    $score = static function ($key, $val) {
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
    $walk = static function ($x, $path = []) use (&$walk, &$best, &$bestScore, $isUrl, $score) {
        if (is_array($x)) {
            foreach ($x as $k => $v) {
                if ($isUrl($v)) {
                    $sc = $score($k, $v);
                    if ($sc > $bestScore) {
                        $bestScore = $sc;
                        $best = $v;
                    }
                } elseif (is_array($v)) {
                    $walk($v, array_merge($path, [$k]));
                }
            }
        }
    };
    $walk($node);
    return $best;
};

// Normalize/override specific package URLs
$normalizePackageUrl = static function ($url) {
    if (!is_string($url) || $url === '') {
        return $url;
    }
    $replacements = [
        'https://downloads.arduino.cc/packages/package_index.tar.bz2' => 'https://espressif.github.io/arduino-esp32/package_esp32_index.json',
    ];
    return $replacements[$url] ?? $url;
};
foreach (array_keys($map) as $fqbn) {
    $fqbnArg = $fqbn;
    $cmdParts2 = array_map(static fn($p) => escapeshellarg($p), array_merge([$arduinoCli], $detailsArgsBase, [$fqbnArg]));
    $cmd2 = implode(' ', $cmdParts2);

    $proc2 = proc_open($cmd2, $descriptorSpec, $pipes2, $baseDir);
    if (!\is_resource($proc2)) {
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
        $configOptions = [];
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
        $detailsCompact[$fqbn] = [
            'name' => $nameVal,
            'version' => $versionVal,
            'config_options' => $configOptions,
            'package_url' => $packageUrl,
        ];
    }
    $detailsSaved++;
}

fwrite(STDOUT, "Details saved: {$detailsSaved}, failed: {$detailsFailed}\n");

// Save aggregated compact details and print to stdout
$compactPath = $webDir . DIRECTORY_SEPARATOR . 'board_details.json';
$compactJson = json_encode($detailsCompact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
if ($compactJson === false) {
    $compactJson = "{}\n";
}
if (file_put_contents($compactPath, $compactJson) === false) {
    fwrite(STDERR, "Failed to write aggregated details to: {$compactPath}\n");
    exit(1);
}
echo $compactJson;
fwrite(STDOUT, "Saved aggregated details: {$compactPath}\n");
