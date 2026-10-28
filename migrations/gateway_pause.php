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
 * The gateway's pause the ACP shows: what it is (a spent daily limit, an overload) and until
 * when. Empty until the gateway pauses; phpBB removes both settings when the extension's data
 * is deleted.
 */
class gateway_pause extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return isset($this->config[settings::PAUSE_UNTIL]);
	}

	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return array('\minos\moderation\migrations\install_data');
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return array(
			array('config.add', array(settings::PAUSE_KIND, '')),
			array('config.add', array(settings::PAUSE_UNTIL, 0, true)),
		);
	}
}
