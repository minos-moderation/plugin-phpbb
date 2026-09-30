<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Minos
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace minos\moderation;

/**
 * The extension's entry point for phpBB's extension manager.
 */
class ext extends \phpbb\extension\base
{
	/** The oldest phpBB the extension supports. */
	const PHPBB_MIN = '3.3.0';

	/** The oldest PHP the extension supports. */
	const PHP_MIN = '7.4.0';

	/**
	 * Refuses to be enabled where it could not work: an older phpBB or PHP, no cURL, or a
	 * package without its bundled client (a copy of the repository instead of the release).
	 *
	 * @return bool|array<int,string> True, or the reasons in the administrator's language.
	 */
	public function is_enableable()
	{
		$problems = array();
		if (!defined('PHPBB_VERSION') || version_compare(PHPBB_VERSION, self::PHPBB_MIN, '<'))
		{
			$problems[] = 'MINOS_INSTALL_PHPBB';
		}
		if (version_compare(PHP_VERSION, self::PHP_MIN, '<'))
		{
			$problems[] = 'MINOS_INSTALL_PHP';
		}
		if (!function_exists('curl_init'))
		{
			$problems[] = 'MINOS_INSTALL_CURL';
		}
		if (!is_file(client_loader::path('Minos\Client\Signature')))
		{
			$problems[] = 'MINOS_INSTALL_VENDOR';
		}
		if (!$problems)
		{
			return true;
		}

		/** @var \phpbb\language\language $language */
		$language = $this->container->get('language');
		$language->add_lang('install', 'minos/moderation');
		$messages = array();
		foreach ($problems as $key)
		{
			$messages[] = $language->lang($key);
		}
		return $messages;
	}
}
