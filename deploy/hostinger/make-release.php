<?php

/**
 * Builds the upload package for shared hosting (Hostinger has no Node and often no Git access to
 * a private repository): the committed code, production Composer packages and the built
 * front-end, in one zip. Run on the developer's machine from the project folder:
 *
 *     php deploy/hostinger/make-release.php
 *
 * → dist/orthotrack-<commit>.zip  (upload it and follow docs/HOSTINGER.md)
 *
 * Needs a clean working tree (the zip is exactly the last commit), Composer and npm.
 */
$root = dirname(__DIR__, 2);
chdir($root);

function run(string $command, ?string $cwd = null): void
{
    echo "▶ {$command}\n";
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $cwd);
    if (! is_resource($process) || proc_close($process) !== 0) {
        fwrite(STDERR, "✖ Failed: {$command}\n");
        exit(1);
    }
}

function removeTree(string $path): void
{
    if (! file_exists($path) && ! is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);

        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

function copyTree(string $from, string $to): void
{
    @mkdir($to, 0755, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
        $target = $to.DIRECTORY_SEPARATOR.substr($item->getPathname(), strlen($from) + 1);
        $item->isDir() ? @mkdir($target, 0755, true) : copy($item->getPathname(), $target);
    }
}

if (trim((string) shell_exec('git status --porcelain')) !== '') {
    fwrite(STDERR, "✖ Commit or stash your changes first: the package is built from the last commit.\n");
    exit(1);
}

$commit = trim((string) shell_exec('git rev-parse --short HEAD'));
$dist = $root.DIRECTORY_SEPARATOR.'dist';
$work = $dist.DIRECTORY_SEPARATOR.'orthotrack-'.$commit;
$zipPath = $work.'.zip';

@mkdir($dist, 0755, true);
removeTree($work);
@unlink($zipPath);

// 1. The committed code.
run('git archive --format=zip -o '.escapeshellarg($dist.'/source.zip').' HEAD');
$source = new ZipArchive;
$source->open($dist.'/source.zip');
$source->extractTo($work);
$source->close();
unlink($dist.'/source.zip');

// 2. Production packages (the lock is resolved for PHP 8.3, see composer.json → platform).
run('composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress', $work);

// 3. Front-end, built from the same (clean) tree.
run('npm run build');
removeTree($work.'/public/build');
copyTree($root.'/public/build', $work.'/public/build');

// 4. Leave out what the server does not need.
foreach (['tests', 'node_modules', '.github', '.claude', 'phpunit.xml', 'phpstan.neon', 'phpstan-baseline.neon', 'bootstrap/cache/config.php', 'bootstrap/cache/routes-v7.php'] as $path) {
    removeTree($work.'/'.$path);
}

// 5. Zip (dot files included: public/.htaccess, .env.example).
$zip = new ZipArchive;
$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($files as $item) {
    $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($work) + 1));
    $item->isDir() ? $zip->addEmptyDir($relative) : $zip->addFile($item->getPathname(), $relative);
}
$zip->close();
removeTree($work);

printf("✔ %s (%.1f MB)\n", $zipPath, filesize($zipPath) / 1048576);
