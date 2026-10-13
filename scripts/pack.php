<?php
// Mirrors Pterodactyl's p:extension:pack: manifest, composer.json and the
// routes/database/resources/dist/vendor/autoload directories, skipping dotfiles,
// node_modules, tests and source maps.
//
//   php scripts/pack.php <extension-dir> <out.pteroext>
[$_, $dir, $out] = $argv;
$manifest = json_decode(file_get_contents("$dir/extension.json"), true, flags: JSON_THROW_ON_ERROR);
@unlink($out);
$zip = new ZipArchive;
$zip->open($out, ZipArchive::CREATE | ZipArchive::EXCL) === true or exit("open failed\n");
$zip->addFile("$dir/extension.json", 'extension.json');
if (is_file("$dir/composer.json")) $zip->addFile("$dir/composer.json", 'composer.json');
$dirs = array_unique(['routes', 'database', 'resources', 'dist', 'vendor', ...array_values($manifest['autoload'] ?? [])]);
$count = 0;
foreach ($dirs as $d) {
    if (! is_dir("$dir/$d")) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$dir/$d", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $rel = substr($file->getPathname(), strlen($dir) + 1);
        $parts = explode('/', $rel);
        if ($file->isLink() || array_filter($parts, fn ($p) => str_starts_with($p, '.') || in_array($p, ['node_modules', 'tests', 'test', 'coverage'], true)) || str_ends_with($rel, '.map')) continue;
        $zip->addFile($file->getPathname(), $rel); $count++;
    }
}
$zip->close();
echo "packed $count files into $out\n";
