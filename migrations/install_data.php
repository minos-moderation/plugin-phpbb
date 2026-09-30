<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Minos
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace minos\moderation\migrations;

use minos\moderation\service\settings;

/**
 * The settings with their defaults, and the ACP module. The extension starts switched off:
 * nothing is held until the administrator enters a key and switches it on.
 */
class install_data extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return isset($this->config[settings::ENABLED]);
	}

	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return array('\minos\moderation\migrations\install_schema');
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return array(
			array('config.add', array(settings::ENABLED, 0)),
			array('config.add', array(settings::GATEWAY_URL, settings::DEFAULT_GATEWAY_URL)),
			array('config.add', array(settings::PROFILE, settings::PROFILES[0])),
			array('config.add', array(settings::FAIL_MODE, settings::DEFAULT_FAIL_MODE)),
			array('config.add', array(settings::TIMEOUT_MIN, settings::DEFAULT_TIMEOUT_MIN)),
			array('config.add', array(settings::CENSORED, settings::CENSORED_PUBLISH)),
			array('config.add', array(settings::BLOCKED, settings::HOLD)),
			array('config.add', array(settings::LAST_ERROR, '')),
			array('config.add', array(settings::LAST_ERROR_TIME, 0, true)),
			array('config.add', array(settings::LAST_SWEEP, 0, true)),
			array('config_text.add', array(settings::API_KEY, '')),
			array('config_text.add', array(settings::WEBHOOK_SECRET, '')),
			array('config_text.add', array(settings::FORUMS, '')),
			array('module.add', array('acp', 'ACP_CAT_DOT_MODS', 'ACP_MINOS_TITLE')),
			array('module.add', array('acp', 'ACP_MINOS_TITLE', array(
				'module_basename' => '\minos\moderation\acp\main_module',
				'modes'           => array('settings'),
			))),
		);
	}
}
