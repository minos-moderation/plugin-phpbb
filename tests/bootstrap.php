<?php

/*
 * The test bootstrap: phpBB's constants (values of `includes/constants.php` in phpBB 3.3),
 * Composer's autoloader (the extension, the bundled client, the stubs) and the stubbed
 * global functions.
 */

define('IN_PHPBB', true);
define('PHPBB_VERSION', '3.3.15');
define('ANONYMOUS', 1);
define('ITEM_UNAPPROVED', 0);
define('ITEM_APPROVED', 1);
define('ITEM_DELETED', 2);
define('ITEM_REAPPROVE', 3);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/stubs/functions.php';
