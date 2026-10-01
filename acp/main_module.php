<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 INPERITIA
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace minos\moderation\acp;

/**
 * The ACP module. phpBB's module system instantiates it without the container, so it only
 * fetches the page's controller service and hands it the page URL.
 */
class main_module
{
	/** @var string The page's URL, set by phpBB. */
	public $u_action;

	/** @var string The template, read by phpBB. */
	public $tpl_name;

	/** @var string The page title, read by phpBB. */
	public $page_title;

	/**
	 * Shows the page.
	 *
	 * @param string $id   The module id.
	 * @param string $mode The mode (`settings`).
	 * @return void
	 */
	public function main($id, $mode)
	{
		global $phpbb_container;

		/** @var \phpbb\language\language $language */
		$language = $phpbb_container->get('language');
		$language->add_lang('acp', 'minos/moderation');

		$this->tpl_name = 'acp_minos_moderation';
		$this->page_title = $language->lang('ACP_MINOS_TITLE');

		/** @var \minos\moderation\controller\acp $controller */
		$controller = $phpbb_container->get('minos.moderation.controller.acp');
		$controller->display($this->u_action);
	}
}
