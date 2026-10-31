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
	'MINOS_DELETE_REASON' => 'Minos: treść zablokowana',
	'MINOS_SUPPORT_REPORT' => 'Minos: potrzebne wsparcie — werdykt „%s”. Treść może świadczyć o samookaleczeniu lub kryzysie autora. Post pozostaje opublikowany: rozważ kontakt i wsparcie, decyzja należy do moderatora.',
	'MINOS_SUPPORT_REPORT_LATE' => 'Minos: potrzebne wsparcie — werdykt „%s” po publikacji bez oceny. Treść może świadczyć o samookaleczeniu lub kryzysie autora. Post pozostaje opublikowany: rozważ kontakt i wsparcie, decyzja należy do moderatora.',
));
