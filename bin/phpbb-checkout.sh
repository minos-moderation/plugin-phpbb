#!/bin/bash
# Fetches phpBB 3.3.x at the one commit this extension is checked against, for the text
# fixtures (bin/phpbb-text-fixtures.php) and for the live board of the functional tests
# (phpunit.functional.xml.dist, the `phpbb-functional` job of .github/workflows/tests.yml).
#
#   bash bin/phpbb-checkout.sh <directory>           # phpBB with its runtime dependencies
#   bash bin/phpbb-checkout.sh <directory> --tests   # and its test harness's
#
# The directory must not exist. Only that commit is fetched (no history). With --tests the
# dependencies are installed as phpBB's own CI installs them (.github/setup-phpbb.sh of that
# commit): `composer install --dev` with phpBB's composer.phar, and on PHP 8 phpunit/dbunit
# swapped for misantron/dbunit with PHPUnit 9 (phpBB 3.3's lock holds PHPUnit 7). The board's
# web server and database are the caller's (see docs/development.md, "The live board").
set -euo pipefail

# phpBB 3.3.19, branch 3.3.x, 2026-09-28: the commit of the stubs and the text fixtures.
PHPBB_COMMIT=6c75df15811d706e60694df86ccd487c98159656

dir=${1:?usage: phpbb-checkout.sh <directory> [--tests]}
mode=${2:-}
if [ -e "$dir" ]; then
	echo "phpbb-checkout: $dir exists" >&2
	exit 1
fi

git init -q "$dir"
git -C "$dir" fetch -q --depth 1 https://github.com/phpbb/phpbb.git "$PHPBB_COMMIT"
git -C "$dir" checkout -q FETCH_HEAD
test "$(git -C "$dir" rev-parse HEAD)" = "$PHPBB_COMMIT"

cd "$dir/phpBB"
if [ "$mode" = "--tests" ]; then
	php ../composer.phar install --dev --no-interaction --no-progress
	if php -r 'exit(PHP_MAJOR_VERSION >= 8 ? 0 : 1);'; then
		php ../composer.phar remove phpunit/dbunit --dev --update-with-dependencies --no-interaction --no-progress
		php ../composer.phar require symfony/yaml:^4.4 misantron/dbunit:5.2.1 phpunit/phpunit:^9.3 doctrine/instantiator:^1.4 \
			--dev --update-with-all-dependencies --ignore-platform-reqs --no-security-blocking --no-interaction --no-progress
	fi
else
	php ../composer.phar install --no-dev --no-interaction --no-progress
fi
