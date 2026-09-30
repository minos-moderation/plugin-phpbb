#!/bin/bash
# Builds the package a forum administrator installs: build/minos-moderation-<version>.zip,
# holding minos/moderation/ with the files phpBB loads and the bundled client in vendor/
# (installed from composer.lock, without development packages).
#
#   bash bin/build-zip.sh
#
# Needs php (with the zip extension) and composer. The repository's own vendor/ is not
# touched: the package is assembled in a temporary directory.
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
version=$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["version"];' "$root/composer.json")
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
dest="$work/minos/moderation"
mkdir -p "$dest"

# What phpBB loads, the manifest and lock, the licence and the manual. Tests, docs, CI and
# the Claude Code files stay out.
for path in acp adm config controller cron event language migrations platform service styles \
    client_loader.php ext.php composer.json composer.lock LICENSE README.md; do
  cp -R "$root/$path" "$dest/"
done

composer install --working-dir="$dest" --no-dev --no-interaction --no-progress --prefer-dist \
  --no-scripts --no-plugins --classmap-authoritative >&2

# Of the client, only its sources and licence travel: its tests, mock gateway and docs are
# development tools, and the extension loads nothing else from vendor/.
client="$dest/vendor/minos-moderation/client-php"
test -f "$client/src/Signature.php"
find "$client" -mindepth 1 -maxdepth 1 ! -name src ! -name LICENSE ! -name composer.json -exec rm -rf {} +

mkdir -p "$root/build"
zip="$root/build/minos-moderation-$version.zip"
rm -f "$zip"
php -r '
    $zip = new ZipArchive();
    if ($zip->open($argv[1], ZipArchive::CREATE) !== true) { fwrite(STDERR, "cannot create $argv[1]\n"); exit(1); }
    $base = $argv[2];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base . "/minos", FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) { $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($base) + 1)); }
    $zip->close();
' "$zip" "$work"
echo "$zip"
