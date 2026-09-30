#!/bin/bash
# Builds the package a forum administrator installs: build/minos-moderation-<version>.zip,
# holding minos/moderation/ with the files phpBB loads and the bundled client's sources.
#
#   bash bin/build-zip.sh
#
# Needs php (with the zip extension) and composer. The client is installed from
# composer.lock, without development packages, in a temporary directory; what goes into the
# ZIP is decided by bin/package.php (tests/Extension/PackageTest.php checks it).
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
version=$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["version"];' "$root/composer.json")
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

cp "$root/composer.json" "$root/composer.lock" "$work/"
composer install --working-dir="$work" --no-dev --no-interaction --no-progress --prefer-dist \
  --no-scripts --no-plugins --no-autoloader >&2

mkdir -p "$root/build"
php "$root/bin/package.php" "$root/build/minos-moderation-$version.zip" "$work/vendor"
