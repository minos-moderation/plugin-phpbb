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
 * The ACP module's description: one page, "Minos", for administrators who may change the
 * board's settings.
 */
class main_info
{
	/**
	 * @return array<string,mixed> The module's file, title and modes.
	 */
	public function module()
	{
		return array(
			'filename' => '\minos\moderation\acp\main_module',
			'title'    => 'ACP_MINOS_TITLE',
			'modes'    => array(
				'settings' => array(
					'title' => 'ACP_MINOS_SETTINGS',
					'auth'  => 'ext_minos/moderation && acl_a_board',
					'cat'   => array('ACP_MINOS_TITLE'),
				),
			),
		);
	}
}
