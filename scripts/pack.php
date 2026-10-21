<?php
// Mirrors Pterodactyl's p:extension:pack: manifest, composer.json and the
// routes/database/resources/dist/vendor/autoload directories, skipping dotfiles,
// node_modules, tests and source maps. Exits non-zero on any failure.
//
//   php scripts/pack.php <extension-dir> <out.pteroext>

function fail(string $message): never
{
    fwrite(STDERR, "pack: $message\n");
    exit(1);
}

if ($argc !== 3) {
    fwrite(STDERR, "usage: php scripts/pack.php <extension-dir> <out.pteroext>\n");
    exit(2);
}
[, $dir, $out] = $argv;
$dir = rtrim($dir, '/');

try {
    $manifest = json_decode((string) @file_get_contents("$dir/extension.json"), true, flags: JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fail("$dir/extension.json is missing or not valid JSON: {$exception->getMessage()}");
}
if (! is_string($manifest['id'] ?? null) || preg_match('/^[a-z0-9][a-z0-9-]{1,63}$/D', $manifest['id']) !== 1) {
    fail('extension.json has no valid id');
}
if (! is_string($manifest['version'] ?? null) || preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/D', $manifest['version']) !== 1) {
    fail('extension.json has no valid version');
}

$autoload = array_values($manifest['autoload'] ?? []);
foreach ($autoload as $path) {
    if (! is_string($path) || $path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
        fail('extension.json has an invalid autoload path');
    }
    if (! is_dir("$dir/".rtrim($path, '/'))) {
        fail("autoload path $path does not exist");
    }
}

if (file_exists($out) && ! unlink($out)) {
    fail("could not remove old $out");
}
$zip = new ZipArchive;
if ($zip->open($out, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    fail("could not create $out");
}

$add = function (string $path, string $name) use ($zip): void {
    if (! $zip->addFile($path, $name)) {
        fail("could not add $name");
    }
};

$add("$dir/extension.json", 'extension.json');
if (is_file("$dir/composer.json")) {
    $add("$dir/composer.json", 'composer.json');
}

$dirs = array_unique(['routes', 'database', 'resources', 'dist', 'vendor', ...array_map(fn ($p) => rtrim($p, '/'), $autoload)]);
$count = 0;
foreach ($dirs as $d) {
    if (! is_dir("$dir/$d")) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$dir/$d", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $rel = substr($file->getPathname(), strlen($dir) + 1);
        $parts = explode('/', $rel);
        if ($file->isLink() || array_filter($parts, fn ($p) => str_starts_with($p, '.') || in_array($p, ['node_modules', 'tests', 'test', 'coverage'], true)) || str_ends_with($rel, '.map')) {
            continue;
        }
        $add($file->getPathname(), $rel);
        $count++;
    }
}

if (! $zip->close()) {
    @unlink($out);
    fail("could not write $out");
}
echo "packed $count files into $out\n";
