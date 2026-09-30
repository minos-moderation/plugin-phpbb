<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Minos
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

// The LOG_ keys are also in info_mcp_moderation.php: the ACP and the MCP each load only their own file.
$lang = array_merge($lang, array(
	'ACP_MINOS_TITLE'    => 'Minos',
	'ACP_MINOS_SETTINGS' => 'Ustawienia moderacji',

	'LOG_MINOS_SETTINGS_UPDATED' => '<strong>Zmieniono ustawienia rozszerzenia Minos</strong>',
	'LOG_MINOS_GATEWAY_REFUSED'  => '<strong>Minos: brama odrzuciła żądanie</strong><br />» HTTP %1$s, kod %2$s',
	'LOG_MINOS_POST_PUBLISHED'   => '<strong>Minos opublikował post</strong><br />» %s',
	'LOG_MINOS_POST_PUBLISHED_FAIL_OPEN' => '<strong>Minos opublikował post bez oceny (tryb awarii fail-open)</strong><br />» %s',
	'LOG_MINOS_POST_CONFIRMED'   => '<strong>Minos potwierdził post opublikowany wcześniej bez oceny</strong><br />» %s',
	'LOG_MINOS_POST_RETURNED'    => '<strong>Minos cofnął do kolejki post opublikowany wcześniej bez oceny</strong><br />» %s',
	'LOG_MINOS_POST_MASKED'      => '<strong>Minos opublikował post z zamaskowanymi fragmentami</strong><br />» %s',
	'LOG_MINOS_POST_HELD'        => '<strong>Minos zostawił post w kolejce do zatwierdzenia</strong><br />» %s',
	'LOG_MINOS_POST_DELETED'     => '<strong>Minos usunął post (miękko)</strong><br />» %s',
	'LOG_MINOS_SUPPORT'          => '<strong>Minos: autor może potrzebować wsparcia</strong><br />» %s',
));
