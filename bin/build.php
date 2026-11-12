<?php
/**
 * Builds the release archives of the module:
 *   dist/coinspay-magento.zip — the module files at the root: for app/code/CoinsPay/Payment, and the
 *                               package for the Adobe Commerce Marketplace (composer.json at the root);
 * and dist/build/Payment with the same files.
 *
 *   php bin/build.php   (PHP with the zip extension)
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$skip = ['/.git', '/.gitattributes', '/.gitignore', '/bin', '/dist', '/phpcs.xml.dist', '/phpunit.xml.dist',
    '/.phpunit.result.cache', '/.phpunit.cache', '/docs/images'];
$dist = $root . '/dist';
$build = $dist . '/build/Payment';
$zipFile = $dist . '/coinspay-magento.zip';

/**
 * Deletes a folder with its files.
 *
 * @param string $dir
 * @return void
 */
function remove(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

remove($dist . '/build');
@unlink($zipFile);
mkdir($build, 0755, true);

$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "cannot write $zipFile\n");
    exit(1);
}
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$count = 0;
$paths = [];
foreach ($files as $file) {
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root)));
    foreach ($skip as $prefix) {
        if ($relative === $prefix || strpos($relative, $prefix . '/') === 0) {
            continue 2;
        }
    }
    $paths[] = ltrim($relative, '/');
}
sort($paths);
foreach ($paths as $path) {
    $target = $build . '/' . $path;
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0755, true);
    }
    copy($root . '/' . $path, $target);
    $zip->addFile($root . '/' . $path, $path);
    $count++;
}
$zip->close();
printf("dist/coinspay-magento.zip: %d files, %d bytes\n", $count, filesize($zipFile));
