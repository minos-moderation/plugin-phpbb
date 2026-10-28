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
 * The refusal that started the gateway's pause (`settings::record_pause`), next to its kind
 * and end: a post the pause keeps past its wait for a verdict gets the failure mode with that
 * code. Empty until the gateway pauses; phpBB removes it when the extension's data is deleted.
 */
class shared_pause extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return isset($this->config[settings::PAUSE_CODE]);
	}

	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return array('\minos\moderation\migrations\gateway_pause');
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return array(
			array('config.add', array(settings::PAUSE_CODE, '')),
		);
	}
}
