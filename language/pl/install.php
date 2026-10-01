<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 INPERITIA
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = array();
}

$lang = array_merge($lang, array(
	'MINOS_INSTALL_PHPBB'  => 'Rozszerzenie Minos wymaga phpBB 3.3.0 lub nowszego.',
	'MINOS_INSTALL_PHP'    => 'Rozszerzenie Minos wymaga PHP 7.4 lub nowszego.',
	'MINOS_INSTALL_CURL'   => 'Rozszerzenie Minos wymaga rozszerzenia PHP cURL.',
	'MINOS_INSTALL_VENDOR' => 'Brakuje katalogu vendor/ rozszerzenia Minos. Zainstaluj paczkę z wydania (plik ZIP), a nie kopię repozytorium.',
));
