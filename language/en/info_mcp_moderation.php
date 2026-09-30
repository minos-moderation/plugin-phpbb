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

// The LOG_ keys are also in info_acp_moderation.php: the ACP and the MCP each load only their own file.
$lang = array_merge($lang, array(
	'LOG_MINOS_POST_PUBLISHED' => '<strong>Minos opublikował post</strong><br />» %s',
	'LOG_MINOS_POST_PUBLISHED_FAIL_OPEN' => '<strong>Minos opublikował post bez oceny (tryb awarii fail-open)</strong><br />» %s',
	'LOG_MINOS_POST_CONFIRMED' => '<strong>Minos potwierdził post opublikowany wcześniej bez oceny</strong><br />» %s',
	'LOG_MINOS_POST_RETURNED'  => '<strong>Minos cofnął do kolejki post opublikowany wcześniej bez oceny</strong><br />» %s',
	'LOG_MINOS_POST_MASKED'    => '<strong>Minos opublikował post z zamaskowanymi fragmentami</strong><br />» %s',
	'LOG_MINOS_POST_HELD'      => '<strong>Minos zostawił post w kolejce do zatwierdzenia</strong><br />» %s',
	'LOG_MINOS_POST_DELETED'   => '<strong>Minos usunął post (miękko)</strong><br />» %s',
	'LOG_MINOS_SUPPORT'        => '<strong>Minos: autor może potrzebować wsparcia</strong><br />» %s',

	'MINOS_MCP_WAITING'                  => 'Minos: w ocenie',
	'MINOS_MCP_HANDLED'                  => 'Minos',
	'MINOS_MCP_VERDICT_BEZPIECZNE'       => 'Minos: bezpieczne',
	'MINOS_MCP_VERDICT_OCENZUROWANE'     => 'Minos: ocenzurowane',
	'MINOS_MCP_VERDICT_ZABLOKOWANE'      => 'Minos: zablokowane',
	'MINOS_MCP_VERDICT_NIEOCENIONE'      => 'Minos: nieocenione',
	'MINOS_MCP_VERDICT_TIMEOUT'          => 'Minos: brak werdyktu na czas',
	'MINOS_MCP_VERDICT_REFUSED'          => 'Minos: błąd konfiguracji',
	'MINOS_MCP_SUPPORT'                  => 'potrzebne wsparcie',
	'MINOS_MCP_TRUNCATED'                => 'oceniono 3000 pierwszych znaków',
	'MINOS_MCP_TRUNCATED_EXPLAIN'        => 'Post jest dłuższy niż 3000 znaków, a brama oceniła tylko jego początek. Dlatego nie został opublikowany na podstawie werdyktu: o jego losie zdecydował tryb awarii albo decyduje moderator.',
	'MINOS_MCP_TITLE'                    => 'Ocena Minos',
	'MINOS_MCP_CATEGORIES'               => 'Kategorie',
	'MINOS_MCP_SUPPORT_EXPLAIN'          => 'Treść może świadczyć o samookaleczeniu lub kryzysie autora. Rozważ kontakt i wsparcie, nie tylko decyzję o publikacji.',
	'MINOS_MCP_MASKED'                   => 'Tekst z fragmentami zamaskowanymi przez bramę',

	'MINOS_CATEGORY_MOWA_NIENAWISCI'          => 'mowa nienawiści',
	'MINOS_CATEGORY_GROZBA'                   => 'groźba',
	'MINOS_CATEGORY_PRZEMOC'                  => 'przemoc',
	'MINOS_CATEGORY_NEKANIE'                  => 'nękanie',
	'MINOS_CATEGORY_TRESC_SEKSUALNA'          => 'treść seksualna',
	'MINOS_CATEGORY_SAMOOKALECZENIE'          => 'samookaleczenie',
	'MINOS_CATEGORY_OSZUSTWO'                 => 'oszustwo',
	'MINOS_CATEGORY_SPAM'                     => 'spam',
	'MINOS_CATEGORY_UJAWNIANIE_DANYCH'        => 'ujawnianie danych',
	'MINOS_CATEGORY_DANE_OSOBOWE'             => 'dane osobowe',
	'MINOS_CATEGORY_WULGARYZMY'               => 'wulgaryzmy',
	'MINOS_CATEGORY_UZYWKI'                   => 'używki',
	'MINOS_CATEGORY_UWODZENIE_NIELETNICH'     => 'uwodzenie nieletnich',
	'MINOS_CATEGORY_HAZARD'                   => 'hazard',
	'MINOS_CATEGORY_NIEBEZPIECZNE_ZACHOWANIE' => 'niebezpieczne zachowanie',
));
