<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 INPERITIA
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace minos\moderation\service;

use minos\moderation\platform\forum;

/**
 * Sends queued posts to the gateway (`POST /api/v1/b2b/oceny`) and handles the answer.
 *
 * What leaves the forum is exactly: an id (`phpbb:<post id>`, with `.<revision>` after an
 * edit), the post's plain text - for the first post of a topic preceded by the topic's title
 * and a blank line (and by the topic's poll, its question and options, and a blank line), for
 * a reply by its own subject when the author changed phpBB's default "Re: ..." - (its first
 * 3000 characters, title and poll included), the profile, and the spam signals
 * (`links`, `link_domains`, `author_first_post`). Never an e-mail, an IP address or a user
 * id.
 *
 * Before the request leaves, each row stores the digest of its text (`sent_md5`). The wait
 * for a verdict - the time-out - is counted from the gateway's `202`.
 *
 * - `202` whose `przyjete` names every item: the rows wait for their webhooks;
 * - `429`, `503`, another `5xx`, no answer at all, or an answer that is not the gateway's:
 *   the rows are retried after `blad.ponow_za_s`, or after a backoff doubling from 60 s -
 *   unless `ponow_za_s` ends after a row's time-out: no verdict can come in time, so the
 *   failure mode applies at once (the plugins' shared rule D9.3);
 * - any other refusal (`3xx`, `4xx`): a configuration error that retrying cannot fix. The
 *   failure mode applies, and the status and the code go to the error log and the ACP.
 *
 * A `429`, a `503` or no answer at all also starts the shared pause of sending
 * ({@see settings::record_pause}): until `ponow_za_s` (or 60 s) has passed, nothing is sent -
 * a post from an author's request waits in the queue for the cron task, and a run of the
 * cron task sends no further batch. Every refusal by the key's limits spends its allowance,
 * so one refused request is better than one per post. A post whose wait for a verdict ends
 * before the pause does gets the failure mode at once, as above. The next `202` ends the
 * pause; the ACP shows it until then.
 */
class submitter
{
	/** The gateway's B2B route. */
	const PATH = '/api/v1/b2b/oceny';

	/** The whole request's time limit: a post's author waits for it. */
	const TIMEOUT_S = 10;

	/** Items one request may carry. */
	const MAX_ITEMS = 20;

	/** The first retry pause without `ponow_za_s`. */
	const FIRST_BACKOFF_S = 60;

	/** The longest retry pause without `ponow_za_s`. */
	const MAX_BACKOFF_S = 3600;

	/** The longest `ponow_za_s` taken as given. */
	const MAX_RETRY_AFTER_S = 86400;

	/** The prefix of every item id. */
	const ID_PREFIX = 'phpbb:';

	/**
	 * The kind of pause each `429` code starts ({@see settings::record_pause}); a `429` with
	 * any other code pauses as the key's limit ({@see settings::PAUSE_KEY}), a `503` and no
	 * answer at all as an unavailable gateway ({@see settings::PAUSE_UNAVAILABLE}).
	 */
	const PAUSES = array(
		'limit_dobowy_klucza'   => settings::PAUSE_LIMIT,
		'limit_globalny_b2b'    => settings::PAUSE_OVERLOAD,
		'kolejka_pelna'         => settings::PAUSE_OVERLOAD,
		'limit_minutowy_klucza' => settings::PAUSE_KEY,
		'limit_w_locie_klucza'  => settings::PAUSE_KEY,
	);

	/** Error labels of the extension's own (the gateway's codes are kept as sent). */
	const ERROR_NETWORK = 'network';
	const ERROR_ANSWER = 'bad_answer';
	const ERROR_REDIRECT = 'redirect';
	const ERROR_EMPTY = 'empty_text';
	const ERROR_NOT_CONFIGURED = 'not_configured';

	/** @var settings */
	protected $settings;

	/** @var pending_store */
	protected $store;

	/** @var forum */
	protected $forum;

	/** @var transport_interface */
	protected $transport;

	/** @var verdict_applier */
	protected $applier;

	/**
	 * @param settings            $settings  The administrator's settings.
	 * @param pending_store       $store     The pending rows.
	 * @param forum               $forum     phpBB's posts.
	 * @param transport_interface $transport HTTP.
	 * @param verdict_applier     $applier   For the failure mode.
	 */
	public function __construct(settings $settings, pending_store $store, forum $forum, transport_interface $transport, verdict_applier $applier)
	{
		$this->settings = $settings;
		$this->store = $store;
		$this->forum = $forum;
		$this->transport = $transport;
		$this->applier = $applier;
	}

	/**
	 * The id a post is sent under. Content never goes into it.
	 *
	 * @param int $post_id  The post.
	 * @param int $revision Edits while it waited.
	 * @return string `phpbb:<post id>`, or `phpbb:<post id>.<revision>` after an edit.
	 */
	public static function item_id($post_id, $revision)
	{
		return self::ID_PREFIX . (int) $post_id . (((int) $revision > 0) ? '.' . (int) $revision : '');
	}

	/**
	 * Reads an id back.
	 *
	 * @param string $id An id from a webhook.
	 * @return array{0:int,1:int}|null The post and the revision, or null for an id this
	 *     extension never sent.
	 */
	public static function parse_item_id($id)
	{
		if (!preg_match('/^phpbb:([1-9][0-9]{0,17})(?:\.([1-9][0-9]{0,4}))?$/', (string) $id, $m))
		{
			return null;
		}
		return array((int) $m[1], isset($m[2]) ? (int) $m[2] : 0);
	}

	/**
	 * Sends queued rows, in batches the gateway accepts - or, while the extension cannot
	 * submit ({@see settings::is_ready}), gives them the failure mode at once.
	 *
	 * Nothing is sent while the gateway's pause lasts ({@see settings::pause}), and once an
	 * answer starts one, no further batch is sent: the rows left wait for the cron task
	 * ({@see hold_back}).
	 *
	 * @param array<int,array<string,mixed>> $rows Queued rows.
	 * @param int                            $now  Unix seconds.
	 * @return void
	 */
	public function submit(array $rows, $now)
	{
		if (!$this->settings->is_ready())
		{
			// Switched on without a key, a secret or a usable gateway address: no verdict can
			// come, so the failure mode applies at once (the plugins' shared rule D9.12).
			foreach ($rows as $row)
			{
				if ($row['status'] === pending_store::QUEUED)
				{
					$this->guarded((int) $row['post_id'], function () use ($row, $now) {
						return $this->applier->fail($row, pending_store::VERDICT_REFUSED, self::ERROR_NOT_CONFIGURED, $now);
					});
				}
			}
			return;
		}
		$rows = array_values($rows);
		$batch = array();
		foreach ($rows as $index => $row)
		{
			if ($row['status'] !== pending_store::QUEUED)
			{
				continue;
			}
			// Before each batch: a pause, also one the previous batch's answer started, stops it.
			$pause = $batch ? null : $this->settings->pause($now);
			if ($pause !== null)
			{
				$this->hold_back(array_slice($rows, $index), $pause, $now);
				return;
			}
			$entry = $this->guarded((int) $row['post_id'], function () use ($row, $now) {
				return $this->prepare($row, $now);
			});
			if ($entry === null)
			{
				continue;
			}
			$batch[] = $entry;
			if (count($batch) === self::MAX_ITEMS)
			{
				$this->send($batch, $now);
				$batch = array();
			}
		}
		if ($batch)
		{
			$this->send($batch, $now);
		}
	}

	/**
	 * What the gateway's pause leaves unsent: a row waits, queued, for the cron task after
	 * the pause - unless its wait for a verdict ends before the pause does, when no verdict
	 * can come in time and the failure mode applies at once (the plugins' shared rule D9.3).
	 *
	 * @param array<int,array<string,mixed>>         $rows  Rows not sent.
	 * @param array{kind:string,code:string,until:int} $pause The pause.
	 * @param int                                     $now   Unix seconds.
	 * @return void
	 */
	protected function hold_back(array $rows, array $pause, $now)
	{
		$code = ($pause['code'] !== '') ? $pause['code'] : 'paused';
		foreach ($rows as $row)
		{
			if ($row['status'] === pending_store::QUEUED
				&& $pause['until'] > (int) $row['submitted_at'] + $this->settings->timeout_seconds())
			{
				$this->guarded((int) $row['post_id'], function () use ($row, $code, $now) {
					return $this->applier->fail($row, pending_store::VERDICT_TIMEOUT, $code, $now);
				});
			}
		}
	}

	/**
	 * Starts the shared pause of sending after an answer that asks to wait.
	 *
	 * @param int|null $status      The HTTP status, or null for no answer at all.
	 * @param string   $code        The refusal's code, or the extension's label.
	 * @param int|null $retry_after The gateway's `ponow_za_s`.
	 * @param int      $now         Unix seconds.
	 * @return void
	 */
	protected function pause_sending($status, $code, $retry_after, $now)
	{
		if ($status === 429)
		{
			$kind = array_key_exists($code, self::PAUSES) ? self::PAUSES[$code] : settings::PAUSE_KEY;
		}
		else if ($status === null || $status === 503)
		{
			$kind = settings::PAUSE_UNAVAILABLE;
		}
		else
		{
			return;
		}
		$wait = ($retry_after !== null && $retry_after > 0) ? min(self::MAX_RETRY_AFTER_S, $retry_after) : self::FIRST_BACKOFF_S;
		$this->settings->record_pause($kind, $code, $now + $wait);
	}

	/**
	 * The item of one queued row, or null when there is nothing to send for it.
	 *
	 * @param array<string,mixed> $row A queued row.
	 * @param int                 $now Unix seconds.
	 * @return array{row:array<string,mixed>,md5:string,item:array<string,mixed>}|null
	 */
	protected function prepare(array $row, $now)
	{
		$post = $this->forum->load_post((int) $row['post_id']);
		if ($post === null || !$this->forum->awaits_approval($post))
		{
			$this->store->supersede((int) $row['post_id'], (int) $row['revision'], $now);
			return null;
		}
		// The title is read from the topic on every attempt: what is sent is what a reader sees now.
		$text = forum::first_chars($this->forum->text_of($post));
		if ($text === '')
		{
			// Nothing to assess: a post with only a link (the failure mode at once, shared rule
			// D9.5), or one edited down to nothing while it waited. A title alone is never sent.
			$this->applier->fail($row, pending_store::VERDICT_REFUSED, self::ERROR_EMPTY, $now);
			return null;
		}
		// The digest goes in before the request: a verdict may overtake the gateway's 202.
		if (!$this->store->mark_sending((int) $row['post_id'], (int) $row['revision'], md5($text)))
		{
			return null;
		}
		return array(
			'row'  => $row,
			'md5'  => md5($text),
			'item' => array(
				'id'     => self::item_id((int) $row['post_id'], (int) $row['revision']),
				'tekst'  => $text,
				'profil' => $this->settings->profile(),
				'meta'   => $this->forum->meta($post),
			),
		);
	}

	/**
	 * Runs one row's step; a failure is logged (step and post id, never the message) and the
	 * other rows go on.
	 *
	 * @param int      $post_id The post.
	 * @param callable $work    The step.
	 * @return mixed The step's result, or null when it failed.
	 */
	protected function guarded($post_id, callable $work)
	{
		try
		{
			return $work();
		}
		catch (\Throwable $e)
		{
			$this->forum->log_failure('submit', (int) $post_id);
			return null;
		}
	}

	/**
	 * One request, and what its answer means for each of its rows.
	 *
	 * @param array<int,array{row:array<string,mixed>,md5:string,item:array<string,mixed>}> $batch The items.
	 * @param int                                                                $now   Unix seconds.
	 * @return void
	 */
	protected function send(array $batch, $now)
	{
		$items = array();
		foreach ($batch as $entry)
		{
			$items[] = $entry['item'];
		}
		$body = json_encode(array('elementy' => $items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		$answer = $this->transport->post($this->settings->gateway_url() . self::PATH, array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
			'X-Gateway-Key' => $this->settings->api_key(),
		), (string) $body, self::TIMEOUT_S);

		if ($answer === null)
		{
			$this->pause_sending(null, self::ERROR_NETWORK, null, $now);
			$this->retry($batch, null, self::ERROR_NETWORK, $now);
			return;
		}
		$status = (int) $answer['status'];
		$data = json_decode((string) $answer['body'], true);
		$error = (is_array($data) && isset($data['blad']) && is_array($data['blad'])) ? $data['blad'] : array();
		$code = (isset($error['kod']) && is_string($error['kod']) && preg_match('/^[a-z0-9_]{1,40}$/', $error['kod'])) ? $error['kod'] : '';

		if ($status >= 200 && $status < 300)
		{
			if ($this->all_accepted($items, $data))
			{
				$this->settings->clear_pause();
				foreach ($batch as $entry)
				{
					$this->guarded((int) $entry['row']['post_id'], function () use ($entry, $now) {
						return $this->store->mark_pending((int) $entry['row']['post_id'], (int) $entry['row']['revision'], $now);
					});
				}
				return;
			}
			$this->retry($batch, null, self::ERROR_ANSWER, $now);
			return;
		}
		if ($status === 429 || $status >= 500)
		{
			$retry_after = (isset($error['ponow_za_s']) && is_int($error['ponow_za_s'])) ? $error['ponow_za_s'] : null;
			$code = ($code !== '') ? $code : 'http_' . $status;
			$this->pause_sending($status, $code, $retry_after, $now);
			$this->retry($batch, $retry_after, $code, $now);
			return;
		}

		// A refusal that retrying will not fix.
		$code = ($code !== '') ? $code : (($status >= 300 && $status < 400) ? self::ERROR_REDIRECT : 'http_' . $status);
		$this->settings->record_error($status, $code, $now);
		$this->forum->log_refusal($status, $code);

		$element = (isset($error['element']) && is_int($error['element'])) ? $error['element'] : null;
		if ($element !== null && isset($batch[$element]) && count($batch) > 1)
		{
			// One item spoiled the batch: that item fails, the others go again without it.
			$spoiled = $batch[$element]['row'];
			$this->guarded((int) $spoiled['post_id'], function () use ($spoiled, $code, $now) {
				return $this->applier->fail($spoiled, pending_store::VERDICT_REFUSED, $code, $now);
			});
			unset($batch[$element]);
			$this->send(array_values($batch), $now);
			return;
		}
		foreach ($batch as $entry)
		{
			$this->guarded((int) $entry['row']['post_id'], function () use ($entry, $code, $now) {
				return $this->applier->fail($entry['row'], pending_store::VERDICT_REFUSED, $code, $now);
			});
		}
	}

	/**
	 * Whether a `2xx` answer is the gateway's acceptance of every item sent.
	 *
	 * @param array<int,array<string,mixed>> $items The items sent.
	 * @param mixed                          $data  The decoded answer.
	 * @return bool
	 */
	protected function all_accepted(array $items, $data)
	{
		if (!is_array($data) || !isset($data['przyjete']) || !is_array($data['przyjete']))
		{
			return false;
		}
		foreach ($items as $item)
		{
			if (!in_array($item['id'], $data['przyjete'], true))
			{
				return false;
			}
		}
		return true;
	}

	/**
	 * Schedules another attempt for every row of a batch - or, for a row whose time-out comes
	 * before the gateway's `ponow_za_s` (taken as sent, not shortened) ends, applies the
	 * failure mode at once: the gateway will not take it in time.
	 *
	 * @param array<int,array{row:array<string,mixed>,md5:string,item:array<string,mixed>}> $batch       The items.
	 * @param int|null                                                           $retry_after The gateway's `ponow_za_s`.
	 * @param string                                                             $code        Why, for the ACP.
	 * @param int                                                                $now         Unix seconds.
	 * @return void
	 */
	protected function retry(array $batch, $retry_after, $code, $now)
	{
		foreach ($batch as $entry)
		{
			$row = $entry['row'];
			if ($retry_after !== null && $retry_after > 0
				&& $now + $retry_after > (int) $row['submitted_at'] + $this->settings->timeout_seconds())
			{
				$this->guarded((int) $row['post_id'], function () use ($row, $code, $now) {
					return $this->applier->fail($row, pending_store::VERDICT_TIMEOUT, $code, $now);
				});
				continue;
			}
			if ($retry_after !== null && $retry_after > 0)
			{
				$pause = min(self::MAX_RETRY_AFTER_S, $retry_after);
			}
			else
			{
				$pause = min(self::MAX_BACKOFF_S, self::FIRST_BACKOFF_S * (2 ** min(10, (int) $row['attempts'])));
			}
			$this->guarded((int) $row['post_id'], function () use ($row, $now, $pause, $code) {
				return $this->store->mark_retry((int) $row['post_id'], (int) $row['revision'], $now + $pause, $code);
			});
		}
	}
}
