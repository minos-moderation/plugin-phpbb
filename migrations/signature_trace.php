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
 * The trace of deliveries whose signature the webhook refused (`settings::record_rejection`):
 * how many since one last passed, the reason and the moment of the last. A dynamic value,
 * left out of phpBB's config cache, because a refused delivery writes it. Empty until a
 * delivery is refused; phpBB removes it when the extension's data is deleted.
 */
class signature_trace extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return isset($this->config[settings::SIGNATURE_REJECTED]);
	}

	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return array('\minos\moderation\migrations\queue_notice');
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return array(
			array('config.add', array(settings::SIGNATURE_REJECTED, '', true)),
		);
	}
}
