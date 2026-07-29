<?php

declare(strict_types=1);

/**
 * Verify that every deployed PHP file was touched at or after a deployment
 * epoch. This catches deterministic ZIP timestamps that can leave OPcache
 * serving an older runtime after otherwise byte-identical deployment steps.
 *
 * Usage:
 *   php scripts/verify-deployed-php-mtime.php <plugin-directory> <minimum-epoch>
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This deployment gate is CLI-only.\n");
    exit(2);
}

$directoryArgument = $argv[1] ?? '';
$minimumArgument = $argv[2] ?? '';
$argumentIsLink = is_string($directoryArgument) && is_link($directoryArgument);
$directory = is_string($directoryArgument) ? realpath($directoryArgument) : false;
$minimumEpoch = is_string($minimumArgument) && preg_match('/\A[1-9][0-9]*\z/', $minimumArgument)
    ? filter_var($minimumArgument, FILTER_VALIDATE_INT)
    : false;

if (
    !is_string($directory)
    || !is_dir($directory)
    || $argumentIsLink
    || !is_int($minimumEpoch)
    || $minimumEpoch <= 0
) {
    fwrite(STDERR, "Usage: php verify-deployed-php-mtime.php <plugin-directory> <minimum-epoch>\n");
    exit(2);
}

$phpFiles = 0;
$staleFiles = [];
$unsafeLinks = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo) {
        continue;
    }

    $relative = substr($file->getPathname(), strlen($directory) + 1);
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    if ($file->isLink()) {
        $unsafeLinks[] = $relative;
        continue;
    }
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    ++$phpFiles;
    $mtime = $file->getMTime();
    if ($mtime < $minimumEpoch) {
        $staleFiles[] = $relative;
    }
}

sort($staleFiles, SORT_STRING);
sort($unsafeLinks, SORT_STRING);
printf(
    "php_files=%d stale=%d unsafe_links=%d minimum_epoch=%d\n",
    $phpFiles,
    count($staleFiles),
    count($unsafeLinks),
    $minimumEpoch
);

if ([] !== $unsafeLinks) {
    foreach ($unsafeLinks as $relative) {
        fwrite(STDERR, "UNSAFE_LINK {$relative}\n");
    }
    exit(1);
}

if (0 === $phpFiles) {
    fwrite(STDERR, "No PHP runtime files were found.\n");
    exit(1);
}

if ([] !== $staleFiles) {
    foreach ($staleFiles as $relative) {
        fwrite(STDERR, "STALE {$relative}\n");
    }
    exit(1);
}

exit(0);
