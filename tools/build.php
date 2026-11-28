<?php

/**
 * Build the socialbored plugin ZIP.
 *
 * Usage: php plugins/socialbored/tools/build.php
 */
$root = dirname(__DIR__);
$name = 'socialbored';
$dist = $root . '/dist';
if (!is_dir($dist) && !mkdir($dist, 0755, true) && !is_dir($dist)) {
    fwrite(STDERR, "Unable to create {$dist}\n");
    exit(1);
}

$zipPath = $dist . '/' . $name . '.zip';
@unlink($zipPath);

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "The PHP zip extension is required.\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Unable to create {$zipPath}\n");
    exit(1);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$added = 0;
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $path = $file->getPathname();
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));

    // Tests, build tooling and previous archives are not part of the runtime.
    if (preg_match('#^(dist|\.git|tests|tools)/#', $relative)) {
        continue;
    }

    $zip->addFile($path, $name . '/' . $relative);
    $added++;
}

$zip->close();

echo "Built {$zipPath} ({$added} files)\n";
