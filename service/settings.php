<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Minos
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace minos\moderation\service;

/**
 * The administrator's settings, read defensively.
 *
 * Plain settings live in phpBB's `config` table. The API key, the webhook secret and the
 * forum list live in `config_text`, which phpBB does not load into every page nor into its
 * global cache file. A stored value this class does not recognise reads as the setting that
 * publishes nothing by itself (hold, fail-closed): a damaged row must never publish posts.
 */
class settings
{
	/** The public gateway. */
	const DEFAULT_GATEWAY_URL = 'https://gateway.wergiliusz.app';

	/** The forum profiles a key may ask for (wire values); the first is the default. */
	const PROFILES = array('forum_adult', 'forum_teen');

	/** Failure mode: an unassessed post is published. */
	const FAIL_OPEN = 'fail-open';

	/** Failure mode: an unassessed post stays in the approval queue. */
	const FAIL_CLOSED = 'fail-closed';

	/** The failure mode of a new installation (see README: a forum keeps working as it did). */
	const DEFAULT_FAIL_MODE = self::FAIL_OPEN;

	/** `ocenzurowane`: publish the masked text. */
	const CENSORED_PUBLISH = 'publish';

	/** `ocenzurowane` or `zablokowane`: keep the post in the approval queue. */
	const HOLD = 'hold';

	/** `zablokowane`: soft-delete the post. */
	const BLOCKED_DELETE = 'delete';

	/** Minutes to wait for a verdict: the gateway's 15-minute TTL plus grace. */
	const DEFAULT_TIMEOUT_MIN = 20;

	/** Below the gateway's TTL a verdict could still be on its way. */
	const MIN_TIMEOUT_MIN = 16;

	/** One day. */
	const MAX_TIMEOUT_MIN = 1440;

	/** Characters of the key shown back to the administrator (`wgb2b_` and four more). */
	const KEY_HINT_LENGTH = 10;

	/** Characters of the webhook secret shown back to the administrator. */
	const SECRET_HINT_LENGTH = 4;

	/** `config` keys. */
	const ENABLED = 'minos_moderation_enabled';
	const GATEWAY_URL = 'minos_moderation_gateway_url';
	const PROFILE = 'minos_moderation_profile';
	const FAIL_MODE = 'minos_moderation_fail_mode';
	const TIMEOUT_MIN = 'minos_moderation_timeout_min';
	const CENSORED = 'minos_moderation_censored';
	const BLOCKED = 'minos_moderation_blocked';
	const LAST_ERROR = 'minos_moderation_last_error';
	const LAST_ERROR_TIME = 'minos_moderation_last_error_time';
	const LAST_SWEEP = 'minos_moderation_last_sweep';

	/** `config_text` keys. */
	const API_KEY = 'minos_moderation_api_key';
	const WEBHOOK_SECRET = 'minos_moderation_webhook_secret';
	const FORUMS = 'minos_moderation_forums';

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\config\db_text */
	protected $config_text;

	/** @var array<string,string> `config_text` values already read in this request. */
	protected $text_cache = array();

	/**
	 * @param \phpbb\config\config  $config      phpBB's configuration.
	 * @param \phpbb\config\db_text $config_text phpBB's large-value configuration.
	 */
	public function __construct(\phpbb\config\config $config, \phpbb\config\db_text $config_text)
	{
		$this->config = $config;
		$this->config_text = $config_text;
	}

	/**
	 * Whether the administrator switched the assessment of new posts on.
	 *
	 * @return bool
	 */
	public function enabled()
	{
		return !empty($this->config[self::ENABLED]);
	}

	/**
	 * The gateway's base URL, without a trailing slash.
	 *
	 * @return string The URL, or '' when the stored one is not acceptable.
	 */
	public function gateway_url()
	{
		$url = rtrim((string) $this->config[self::GATEWAY_URL], '/');
		return self::valid_url($url) ? $url : '';
	}

	/**
	 * The B2B key sent in `X-Gateway-Key`. Never log it, show it or put it in an error.
	 *
	 * @return string The key, or '' when none is stored.
	 */
	public function api_key()
	{
		return $this->text(self::API_KEY);
	}

	/**
	 * The secret the gateway signs webhook deliveries with. Never log it or show it.
	 *
	 * @return string The secret, or '' when none is stored.
	 */
	public function webhook_secret()
	{
		return $this->text(self::WEBHOOK_SECRET);
	}

	/**
	 * The profile the posts are assessed with.
	 *
	 * @return string One of {@see PROFILES}.
	 */
	public function profile()
	{
		$profile = (string) $this->config[self::PROFILE];
		return in_array($profile, self::PROFILES, true) ? $profile : self::PROFILES[0];
	}

	/**
	 * What happens to a post without a verdict (`nieocenione`, no answer in time, a refusal).
	 *
	 * @return string {@see FAIL_OPEN} or {@see FAIL_CLOSED}; an unknown value is fail-closed.
	 */
	public function fail_mode()
	{
		return ((string) $this->config[self::FAIL_MODE] === self::FAIL_OPEN) ? self::FAIL_OPEN : self::FAIL_CLOSED;
	}

	/**
	 * How long a post may wait for its verdict.
	 *
	 * @return int Seconds.
	 */
	public function timeout_seconds()
	{
		return self::clamp_timeout((int) $this->config[self::TIMEOUT_MIN]) * 60;
	}

	/**
	 * What happens to an `ocenzurowane` post.
	 *
	 * @return string {@see CENSORED_PUBLISH} or {@see HOLD}.
	 */
	public function censored_mode()
	{
		return ((string) $this->config[self::CENSORED] === self::CENSORED_PUBLISH) ? self::CENSORED_PUBLISH : self::HOLD;
	}

	/**
	 * What happens to a `zablokowane` post.
	 *
	 * @return string {@see BLOCKED_DELETE} or {@see HOLD}.
	 */
	public function blocked_mode()
	{
		return ((string) $this->config[self::BLOCKED] === self::BLOCKED_DELETE) ? self::BLOCKED_DELETE : self::HOLD;
	}

	/**
	 * The forums the extension works in.
	 *
	 * @return array<int,int> Forum ids; empty means every forum.
	 */
	public function forum_ids()
	{
		$ids = json_decode($this->text(self::FORUMS), true);
		if (!is_array($ids))
		{
			return array();
		}
		$clean = array();
		foreach ($ids as $id)
		{
			if (is_int($id) && $id > 0)
			{
				$clean[$id] = $id;
			}
		}
		return array_values($clean);
	}

	/**
	 * Whether new posts in a forum are assessed.
	 *
	 * @param int $forum_id The forum.
	 * @return bool
	 */
	public function applies_to_forum($forum_id)
	{
		$ids = $this->forum_ids();
		return empty($ids) || in_array((int) $forum_id, $ids, true);
	}

	/**
	 * Whether the extension can submit posts: switched on, with a gateway, a key and a secret.
	 * Until then it holds nothing, so a half-configured forum works as before.
	 *
	 * @return bool
	 */
	public function is_ready()
	{
		return $this->enabled()
			&& $this->gateway_url() !== ''
			&& self::valid_key($this->api_key())
			&& self::valid_secret($this->webhook_secret());
	}

	/**
	 * The part of the key the ACP may show, so the administrator can tell keys apart.
	 *
	 * @return string A prefix followed by an ellipsis, or '' when no key is stored.
	 */
	public function key_hint()
	{
		return self::hint($this->api_key(), self::KEY_HINT_LENGTH);
	}

	/**
	 * The part of the webhook secret the ACP may show.
	 *
	 * @return string A prefix followed by an ellipsis, or '' when no secret is stored.
	 */
	public function secret_hint()
	{
		return self::hint($this->webhook_secret(), self::SECRET_HINT_LENGTH);
	}

	/**
	 * Remembers the last refusal the gateway gave, for the ACP. Only the status and the code:
	 * a response body is never stored.
	 *
	 * @param int    $status The HTTP status.
	 * @param string $code   The refusal's `kod`, already reduced to a safe label.
	 * @param int    $now    Unix seconds.
	 * @return void
	 */
	public function record_error($status, $code, $now)
	{
		$this->config->set(self::LAST_ERROR, (int) $status . ' ' . $code);
		$this->config->set(self::LAST_ERROR_TIME, (int) $now, false);
	}

	/**
	 * The last refusal, for the ACP.
	 *
	 * @return array{status:int,code:string,time:int}|null Null when there was none.
	 */
	public function last_error()
	{
		$value = (string) $this->config[self::LAST_ERROR];
		if (!preg_match('/^(\d{3}) ([a-z0-9_]{1,40})$/', $value, $m))
		{
			return null;
		}
		return array('status' => (int) $m[1], 'code' => $m[2], 'time' => (int) $this->config[self::LAST_ERROR_TIME]);
	}

	/**
	 * Validates and stores the ACP form.
	 *
	 * An empty key or secret field keeps the stored value, so the page never needs to show
	 * either of them back.
	 *
	 * @param array<string,mixed> $values enabled (bool), gateway_url, api_key, webhook_secret,
	 *     profile, fail_mode, censored, blocked (strings), timeout_min (int), forums (int[]).
	 * @return array<int,string> Language keys of the problems; empty when everything was saved.
	 */
	public function save(array $values)
	{
		$errors = array();
		$url = rtrim(trim((string) $values['gateway_url']), '/');
		if (!self::valid_url($url))
		{
			$errors[] = 'MINOS_ERROR_GATEWAY_URL';
		}
		$key = trim((string) $values['api_key']);
		if ($key !== '' && !self::valid_key($key))
		{
			$errors[] = 'MINOS_ERROR_API_KEY';
		}
		$secret = trim((string) $values['webhook_secret']);
		if ($secret !== '' && !self::valid_secret($secret))
		{
			$errors[] = 'MINOS_ERROR_WEBHOOK_SECRET';
		}
		$profile = (string) $values['profile'];
		$fail_mode = (string) $values['fail_mode'];
		$censored = (string) $values['censored'];
		$blocked = (string) $values['blocked'];
		$timeout = (int) $values['timeout_min'];
		if (!in_array($profile, self::PROFILES, true)
			|| !in_array($fail_mode, array(self::FAIL_OPEN, self::FAIL_CLOSED), true)
			|| !in_array($censored, array(self::CENSORED_PUBLISH, self::HOLD), true)
			|| !in_array($blocked, array(self::HOLD, self::BLOCKED_DELETE), true))
		{
			$errors[] = 'MINOS_ERROR_CHOICE';
		}
		if ($timeout !== self::clamp_timeout($timeout))
		{
			$errors[] = 'MINOS_ERROR_TIMEOUT';
		}
		if ($errors)
		{
			return $errors;
		}

		$forums = array();
		foreach ((array) $values['forums'] as $id)
		{
			if ((int) $id > 0)
			{
				$forums[(int) $id] = (int) $id;
			}
		}

		$this->config->set(self::ENABLED, empty($values['enabled']) ? 0 : 1);
		$this->config->set(self::GATEWAY_URL, $url);
		$this->config->set(self::PROFILE, $profile);
		$this->config->set(self::FAIL_MODE, $fail_mode);
		$this->config->set(self::TIMEOUT_MIN, $timeout);
		$this->config->set(self::CENSORED, $censored);
		$this->config->set(self::BLOCKED, $blocked);
		$this->set_text(self::FORUMS, $forums ? json_encode(array_values($forums)) : '');
		if ($key !== '')
		{
			$this->set_text(self::API_KEY, $key);
		}
		if ($secret !== '')
		{
			$this->set_text(self::WEBHOOK_SECRET, $secret);
		}
		return array();
	}

	/**
	 * Whether a gateway URL may receive the key: `https` with a host name and no credentials,
	 * query or fragment; plain `http` only on the loopback, for the mock gateway.
	 *
	 * @param string $url The URL.
	 * @return bool
	 */
	public static function valid_url($url)
	{
		$parts = parse_url((string) $url);
		if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])
			|| isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']))
		{
			return false;
		}
		$scheme = strtolower($parts['scheme']);
		if ($scheme === 'https')
		{
			return true;
		}
		return $scheme === 'http' && in_array(strtolower($parts['host']), array('localhost', '127.0.0.1', '[::1]'), true);
	}

	/**
	 * Whether a string is shaped like a B2B key.
	 *
	 * @param string $key The key.
	 * @return bool
	 */
	public static function valid_key($key)
	{
		return (bool) preg_match('/^wgb2b_[A-Za-z0-9_-]{8,200}$/', (string) $key);
	}

	/**
	 * Whether a string can be a webhook secret: 16 to 255 visible ASCII characters.
	 *
	 * @param string $secret The secret.
	 * @return bool
	 */
	public static function valid_secret($secret)
	{
		return (bool) preg_match('/^[\x21-\x7e]{16,255}$/', (string) $secret);
	}

	/**
	 * A timeout within the accepted range.
	 *
	 * @param int $minutes The stored or submitted value; 0 means the default.
	 * @return int Minutes.
	 */
	protected static function clamp_timeout($minutes)
	{
		if ($minutes <= 0)
		{
			return self::DEFAULT_TIMEOUT_MIN;
		}
		return max(self::MIN_TIMEOUT_MIN, min(self::MAX_TIMEOUT_MIN, $minutes));
	}

	/**
	 * A visible prefix of a stored credential.
	 *
	 * @param string $value  The credential.
	 * @param int    $length Characters to show.
	 * @return string
	 */
	protected static function hint($value, $length)
	{
		if ($value === '')
		{
			return '';
		}
		return substr($value, 0, min($length, (int) floor(strlen($value) / 2))) . '…';
	}

	/**
	 * A `config_text` value, read once per request.
	 *
	 * @param string $name The key.
	 * @return string
	 */
	protected function text($name)
	{
		if (!array_key_exists($name, $this->text_cache))
		{
			$this->text_cache[$name] = (string) $this->config_text->get($name);
		}
		return $this->text_cache[$name];
	}

	/**
	 * Stores a `config_text` value.
	 *
	 * @param string $name  The key.
	 * @param string $value The value.
	 * @return void
	 */
	protected function set_text($name, $value)
	{
		$this->config_text->set($name, $value);
		$this->text_cache[$name] = $value;
	}
}
