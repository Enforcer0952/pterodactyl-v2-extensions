<?php

/*
 * Packs every built extension into <out>/<id>-<version>.pteroext and writes
 * SHA256SUMS.txt and release-notes.md next to them.
 *
 *   php scripts/build-release.php <extensions-dir> <out-dir> [tag]
 *
 * Each extension must already be built (npm run build), so dist/ exists.
 */

[$self, $source, $out] = array_pad($argv, 3, null);
$tag = $argv[3] ?? 'unreleased';
if ($source === null || $out === null) {
    fwrite(STDERR, "usage: php scripts/build-release.php <extensions-dir> <out-dir> [tag]\n");
    exit(2);
}

if ($tag !== 'unreleased' && preg_match('/^v\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/D', $tag) !== 1) {
    fwrite(STDERR, "invalid tag $tag, expected something like v1.2.0\n");
    exit(2);
}
$repo = getenv('GITHUB_REPOSITORY') ?: 'Enforcer0952/pterodactyl-v2-extensions';
$urlTag = rawurlencode($tag);
@mkdir($out, 0755, true);
$rows = [];
$sums = [];

foreach (glob(rtrim($source, '/').'/*/extension.json') as $manifestFile) {
    $dir = dirname($manifestFile);
    $manifest = json_decode(file_get_contents($manifestFile), true, flags: JSON_THROW_ON_ERROR);
    if (! is_dir("$dir/dist")) {
        fwrite(STDERR, "{$manifest['id']}: not built (no dist/), run npm run build first\n");
        exit(1);
    }

    $file = "{$manifest['id']}-{$manifest['version']}.pteroext";
    passthru(sprintf('php %s %s %s', escapeshellarg(__DIR__.'/pack.php'), escapeshellarg($dir), escapeshellarg("$out/$file")), $code);
    if ($code !== 0) {
        exit($code);
    }

    $hash = hash_file('sha256', "$out/$file");
    $sums[] = "$hash  $file";
    $rows[] = [$manifest['name'], $manifest['id'], $manifest['version'], $file, $hash, $manifest['description'] ?? ''];
}

usort($rows, fn (array $a, array $b): int => strcmp($a[0], $b[0]));
sort($sums);
file_put_contents("$out/SHA256SUMS.txt", implode("\n", $sums)."\n");

$notes = "## Downloads\n\n| Extension | Version | File |\n|---|---|---|\n";
foreach ($rows as [$name, $id, $version, $file]) {
    $notes .= "| [$name](https://github.com/$repo/tree/$urlTag/extensions/$id) | $version | [`$file`](https://github.com/$repo/releases/download/$urlTag/$file) |\n";
}
$notes .= "\n## Install\n\n```bash\ncd /var/www/pterodactyl\nsudo -u www-data php artisan p:extension:install /path/to/<file>.pteroext --enable\n```\n\nUpdating: add `--replace`. Then hard-refresh the panel (Ctrl+Shift+R).\n";
$notes .= "\n## Verify your download\n\nGitHub shows a SHA-256 digest next to each file. `SHA256SUMS.txt` lists them too:\n\n```bash\nsha256sum --check --ignore-missing SHA256SUMS.txt\n```\n\n<details><summary>SHA-256</summary>\n\n```\n".implode("\n", $sums)."\n```\n\n</details>\n";
file_put_contents("$out/release-notes.md", $notes);

echo count($rows)." extensions packed into $out\n";
