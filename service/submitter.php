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

use minos\moderation\platform\forum;

/**
 * Sends queued posts to the gateway (`POST /api/v1/b2b/oceny`) and handles the answer.
 *
 * What leaves the forum is exactly: an id (`phpbb:<post id>`, with `.<revision>` after an
 * edit), the post's plain text (its first 3000 characters), the profile, and two spam
 * signals (`links`, `author_first_post`). Never an e-mail, an IP address or a user id.
 *
 * - `202` whose `przyjete` names every item: the rows wait for their webhooks;
 * - `429`, `503`, another `5xx`, no answer at all, or an answer that is not the gateway's:
 *   the rows are retried after `blad.ponow_za_s`, or after a backoff doubling from 60 s;
 * - any other refusal (`3xx`, `4xx`): a configuration error that retrying cannot fix. The
 *   failure mode applies, and the status and the code go to the error log and the ACP.
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

	/** Error labels of the extension's own (the gateway's codes are kept as sent). */
	const ERROR_NETWORK = 'network';
	const ERROR_ANSWER = 'bad_answer';
	const ERROR_REDIRECT = 'redirect';
	const ERROR_EMPTY = 'empty_text';

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
	 * Sends queued rows, in batches the gateway accepts.
	 *
	 * @param array<int,array<string,mixed>> $rows Queued rows.
	 * @param int                            $now  Unix seconds.
	 * @return void
	 */
	public function submit(array $rows, $now)
	{
		$batch = array();
		foreach ($rows as $row)
		{
			if ($row['status'] !== pending_store::QUEUED)
			{
				continue;
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
		$text = forum::first_chars($this->forum->plain_text((string) $post['post_text']));
		if ($text === '')
		{
			// Edited down to nothing assessable while it waited.
			$this->applier->fail($row, pending_store::VERDICT_REFUSED, self::ERROR_EMPTY, $now);
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
				foreach ($batch as $entry)
				{
					$this->guarded((int) $entry['row']['post_id'], function () use ($entry) {
						return $this->store->mark_pending((int) $entry['row']['post_id'], (int) $entry['row']['revision'], $entry['md5']);
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
			$this->retry($batch, $retry_after, ($code !== '') ? $code : 'http_' . $status, $now);
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
	 * Schedules another attempt for every row of a batch.
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
			if ($retry_after !== null && $retry_after > 0)
			{
				$pause = min(self::MAX_RETRY_AFTER_S, $retry_after);
			}
			else
			{
				$pause = min(self::MAX_BACKOFF_S, self::FIRST_BACKOFF_S * (2 ** min(10, (int) $row['attempts'])));
			}
			$this->guarded((int) $row['post_id'], function () use ($row, $now, $pause, $code, $entry) {
				return $this->store->mark_retry((int) $row['post_id'], (int) $row['revision'], $now + $pause, $code, $entry['md5']);
			});
		}
	}
}
