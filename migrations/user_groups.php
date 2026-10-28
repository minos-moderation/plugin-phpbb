<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 INPERITIA
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace minos\moderation\migrations;

use minos\moderation\service\settings;

/**
 * The user groups whose posts are assessed (`settings::group_ids`). Empty, as installed:
 * every group, the behaviour before the setting existed. `config_text.add` keeps a value that
 * is already there; phpBB removes it when the extension's data is deleted.
 */
class user_groups extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return array('\minos\moderation\migrations\shared_pause');
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return array(
			array('config_text.add', array(settings::GROUPS, '')),
		);
	}
}
