<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Minos
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace minos\moderation\controller;

use minos\moderation\service\pending_store;
use minos\moderation\service\settings;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The ACP page "Minos": the settings, the webhook URL to register with the key, the last
 * refusal of the gateway, and the posts the extension handled lately.
 *
 * The key and the secret are write-only here: the form never carries them back, the page
 * shows a prefix of each, and an empty field keeps the stored value.
 */
class acp
{
	/** The form key. */
	const FORM_KEY = 'minos_moderation_acp';

	/** Rows in the list of recent posts. */
	const RECENT = 50;

	/** The webhook route's name. */
	const WEBHOOK_ROUTE = 'minos_moderation_webhook';

	/** @var settings */
	protected $settings;

	/** @var pending_store */
	protected $store;

	/** @var \phpbb\request\request_interface */
	protected $request;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\log\log_interface */
	protected $log;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\controller\helper */
	protected $helper;

	/** @var string */
	protected $root_path;

	/** @var string */
	protected $php_ext;

	/**
	 * @param settings                         $settings  The administrator's settings.
	 * @param pending_store                    $store     The pending rows.
	 * @param \phpbb\request\request_interface $request   The form.
	 * @param \phpbb\template\template         $template  The page.
	 * @param \phpbb\language\language         $language  The texts.
	 * @param \phpbb\log\log_interface         $log       The admin log.
	 * @param \phpbb\user                      $user      The administrator.
	 * @param \phpbb\controller\helper         $helper    The webhook URL.
	 * @param string                           $root_path phpBB's root path.
	 * @param string                           $php_ext   `php`.
	 */
	public function __construct(settings $settings, pending_store $store, \phpbb\request\request_interface $request,
		\phpbb\template\template $template, \phpbb\language\language $language, \phpbb\log\log_interface $log,
		\phpbb\user $user, \phpbb\controller\helper $helper, $root_path, $php_ext)
	{
		$this->settings = $settings;
		$this->store = $store;
		$this->request = $request;
		$this->template = $template;
		$this->language = $language;
		$this->log = $log;
		$this->user = $user;
		$this->helper = $helper;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
	}

	/**
	 * Handles the form and fills the page.
	 *
	 * @param string $u_action The page's URL.
	 * @return void
	 */
	public function display($u_action)
	{
		$this->language->add_lang('acp', 'minos/moderation');
		add_form_key(self::FORM_KEY);

		$errors = array();
		$saved = false;
		if ($this->request->is_set_post('submit'))
		{
			if (!check_form_key(self::FORM_KEY))
			{
				$errors[] = 'FORM_INVALID';
			}
			else
			{
				$errors = $this->settings->save($this->read_form());
				if (!$errors)
				{
					$saved = true;
					$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_MINOS_SETTINGS_UPDATED');
				}
			}
		}

		$messages = array();
		foreach ($errors as $key)
		{
			$messages[] = $this->language->lang($key);
		}

		$last_error = $this->settings->last_error();
		$counts = $this->store->counts() + array_fill_keys(pending_store::UNSETTLED, 0) + array(pending_store::HELD => 0);
		$waiting = $counts[pending_store::QUEUED] + $counts[pending_store::PENDING] + $counts[pending_store::RECEIVED];
		$this->template->assign_vars(array(
			'U_ACTION'              => $u_action,
			'S_MINOS_SAVED'         => $saved,
			'S_MINOS_ERROR'         => (bool) $messages,
			'ERROR_MSG'             => self::escape(implode('<br>', $messages), true),
			'S_MINOS_READY'         => $this->settings->is_ready(),
			'S_MINOS_ENABLED'       => $this->settings->enabled(),
			'MINOS_GATEWAY_URL'     => self::escape($this->shown_url()),
			'MINOS_KEY_HINT'        => self::escape($this->settings->key_hint()),
			'MINOS_SECRET_HINT'     => self::escape($this->settings->secret_hint()),
			'MINOS_TIMEOUT_MIN'     => (int) ($this->settings->timeout_seconds() / 60),
			'MINOS_TIMEOUT_MIN_MIN' => settings::MIN_TIMEOUT_MIN,
			'MINOS_TIMEOUT_MIN_MAX' => settings::MAX_TIMEOUT_MIN,
			'MINOS_WEBHOOK_URL'     => self::escape($this->webhook_url()),
			'S_MINOS_LAST_ERROR'    => $last_error !== null,
			'MINOS_LAST_ERROR'      => ($last_error !== null) ? self::escape($last_error['status'] . ' ' . $last_error['code']) : '',
			'MINOS_LAST_ERROR_HELP' => ($last_error !== null) ? self::escape($this->error_help($last_error['code'])) : '',
			'MINOS_LAST_ERROR_TIME' => ($last_error !== null) ? $this->user->format_date($last_error['time']) : '',
			'MINOS_RECENT_EXPLAIN'  => self::escape($this->language->lang('MINOS_RECENT_EXPLAIN', $waiting, $counts[pending_store::HELD])),
			'S_FORUM_OPTIONS'       => make_forum_select($this->settings->forum_ids(), false, true, true, true, false, false),
			'S_MINOS_ALL_FORUMS'    => !$this->settings->forum_ids(),
		));

		$this->choices('minos_profiles', settings::PROFILES, $this->settings->profile(), 'MINOS_PROFILE_');
		$this->choices('minos_fail_modes', array(settings::FAIL_OPEN, settings::FAIL_CLOSED), $this->settings->fail_mode(), 'MINOS_FAIL_MODE_');
		$this->choices('minos_censored', array(settings::CENSORED_PUBLISH, settings::HOLD), $this->settings->censored_mode(), 'MINOS_CENSORED_');
		$this->choices('minos_blocked', array(settings::HOLD, settings::BLOCKED_DELETE), $this->settings->blocked_mode(), 'MINOS_BLOCKED_');

		foreach ($this->store->recent(self::RECENT) as $row)
		{
			$post_id = (int) $row['post_id'];
			$this->template->assign_block_vars('minos_rows', array(
				'POST_ID'    => $post_id,
				'U_POST'     => append_sid($this->root_path . 'viewtopic.' . $this->php_ext, 'p=' . $post_id) . '#p' . $post_id,
				'U_MCP'      => append_sid($this->root_path . 'mcp.' . $this->php_ext, 'i=queue&amp;mode=approve_details&amp;p=' . $post_id),
				'STATUS'     => self::escape($this->language->lang('MINOS_STATUS_' . strtoupper((string) $row['status']))),
				'VERDICT'    => self::escape((string) $row['verdict']),
				'CATEGORIES' => self::escape(str_replace(',', ', ', (string) $row['categories'])),
				'ERROR_CODE' => self::escape((string) $row['error_code']),
				'S_SUPPORT'  => !empty($row['support']),
				'S_TRUNCATED' => !empty($row['truncated']),
				'S_HELD'     => $row['status'] === pending_store::HELD,
				'TIME'       => $this->user->format_date((int) $row['submitted_at']),
			));
		}
	}

	/**
	 * The submitted form, read raw where phpBB's escaping would change the value (a secret
	 * with `&` or `<` in it would otherwise never verify a signature).
	 *
	 * @return array<string,mixed>
	 */
	protected function read_form()
	{
		return array(
			'enabled'        => $this->request->variable('minos_enabled', 0),
			'gateway_url'    => $this->raw('minos_gateway_url'),
			'api_key'        => $this->raw('minos_api_key'),
			'webhook_secret' => $this->raw('minos_webhook_secret'),
			'profile'        => $this->request->variable('minos_profile', ''),
			'fail_mode'      => $this->request->variable('minos_fail_mode', ''),
			'timeout_min'    => $this->request->variable('minos_timeout_min', 0),
			'censored'       => $this->request->variable('minos_censored', ''),
			'blocked'        => $this->request->variable('minos_blocked', ''),
			'forums'         => $this->request->variable('minos_forums', array(0)),
		);
	}

	/**
	 * A POSTed string exactly as sent.
	 *
	 * @param string $name The field.
	 * @return string '' for a missing or non-string field.
	 */
	protected function raw($name)
	{
		$value = $this->request->raw_variable($name, '', \phpbb\request\request_interface::POST);
		return is_string($value) ? trim($value) : '';
	}

	/**
	 * The URL the administrator registers with the key, without any session id.
	 *
	 * @return string
	 */
	protected function webhook_url()
	{
		$url = (string) $this->helper->route(self::WEBHOOK_ROUTE, array(), false, '', UrlGeneratorInterface::ABSOLUTE_URL);
		// A session id in it would hand the administrator's session to whoever sees the URL.
		return rtrim((string) preg_replace('/([?&])sid=[^&#]*(&|$)/', '$1', $url), '?&');
	}

	/**
	 * The gateway URL as stored, for the form (even when it is not valid, so it can be fixed).
	 *
	 * @return string
	 */
	protected function shown_url()
	{
		$url = $this->settings->gateway_url();
		return ($url !== '') ? $url : settings::DEFAULT_GATEWAY_URL;
	}

	/**
	 * An explanation of a refusal code, when the extension knows one.
	 *
	 * @param string $code The code.
	 * @return string
	 */
	protected function error_help($code)
	{
		$key = 'MINOS_REFUSAL_' . strtoupper($code);
		$text = $this->language->lang($key);
		return ($text === $key) ? $this->language->lang('MINOS_REFUSAL_OTHER') : $text;
	}

	/**
	 * Options of a select.
	 *
	 * @param string            $block    The template block.
	 * @param array<int,string> $values   The stored values.
	 * @param string            $current  The selected one.
	 * @param string            $lang_pre The language key prefix of their labels.
	 * @return void
	 */
	protected function choices($block, array $values, $current, $lang_pre)
	{
		foreach ($values as $value)
		{
			$this->template->assign_block_vars($block, array(
				'VALUE'      => self::escape($value),
				'LABEL'      => self::escape($this->language->lang($lang_pre . strtoupper(str_replace('-', '_', $value)))),
				'S_SELECTED' => $value === $current,
			));
		}
	}

	/**
	 * Escapes a value for the template (phpBB templates print variables as they are).
	 *
	 * @param string $value     The value.
	 * @param bool   $keep_html Whether the value is trusted HTML (language strings joined with `<br>`).
	 * @return string
	 */
	protected static function escape($value, $keep_html = false)
	{
		return $keep_html ? (string) $value : htmlspecialchars((string) $value, ENT_COMPAT, 'UTF-8');
	}
}
