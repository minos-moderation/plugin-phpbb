<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 INPERITIA
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace minos\moderation\event;

use minos\moderation\service\settings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Says on every ACP page why the extension cannot submit posts while it is switched on
 * without a usable configuration (the plugins' shared rule D9.12): every new post gets the
 * failure mode until then. Only what is missing is named, never a stored value.
 */
class acp_notice implements EventSubscriberInterface
{
	/** @var settings */
	protected $settings;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\language\language */
	protected $language;

	/**
	 * @param settings                 $settings The administrator's settings.
	 * @param \phpbb\template\template $template The ACP page.
	 * @param \phpbb\language\language $language The notice (`info_acp_moderation`, which the
	 *     ACP loads on every page).
	 */
	public function __construct(settings $settings, \phpbb\template\template $template, \phpbb\language\language $language)
	{
		$this->settings = $settings;
		$this->template = $template;
		$this->language = $language;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents()
	{
		return array(
			'core.adm_page_header' => 'show_problems',
		);
	}

	/**
	 * Fills the notice of `adm/style/event/acp_overall_header_body_before.html`.
	 *
	 * @param \phpbb\event\data $event `page_title`, `adm_page_header_override`.
	 * @return void
	 */
	public function show_problems($event)
	{
		if (!$this->settings->enabled())
		{
			return;
		}
		$problems = array();
		foreach ($this->settings->problems() as $key)
		{
			$problems[] = $this->language->lang($key);
		}
		if (!$problems)
		{
			return;
		}
		$this->template->assign_vars(array(
			'S_MINOS_NOT_READY_NOTICE' => true,
			'MINOS_NOT_READY_NOTICE'   => htmlspecialchars($this->language->lang('MINOS_NOT_READY_NOTICE', implode(', ', $problems)), ENT_COMPAT, 'UTF-8'),
		));
	}
}
