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
 * Turns a recorded outcome into what happens to the post, under the administrator's
 * settings, and carries it out.
 *
 * The gateway decides and the extension applies: `bezpieczne` publishes; `ocenzurowane`
 * and `zablokowane` follow their settings; anything that is not a verdict (`nieocenione`,
 * no answer in time, a refused request) follows the failure mode and is never read as one.
 * A post a moderator already dealt with is left alone.
 *
 * A post longer than 3000 characters was assessed on its beginning only: a block still
 * blocks, but nothing publishes it on the gateway's word. `bezpieczne` reads as
 * `nieocenione` (the failure mode), and `ocenzurowane` always holds.
 */
class verdict_applier
{
	/** Publish the post as written. */
	const PUBLISH = 'publish';

	/** Publish the gateway's masked text in place of the post's text. */
	const PUBLISH_MASKED = 'publish_masked';

	/** Keep the post in the approval queue. */
	const HOLD = 'hold';

	/** Soft-delete the post. */
	const DELETE = 'delete';

	/** The masked text could not be stored (also the error code recorded on the row). */
	const MASK_FAILED = 'mask_failed';

	/** @var settings */
	protected $settings;

	/** @var pending_store */
	protected $store;

	/** @var forum */
	protected $forum;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/**
	 * @param settings                          $settings The administrator's settings.
	 * @param pending_store                     $store    The pending rows.
	 * @param forum                             $forum    phpBB's posts.
	 * @param \phpbb\db\driver\driver_interface $db       For the transaction around each post.
	 */
	public function __construct(settings $settings, pending_store $store, forum $forum, \phpbb\db\driver\driver_interface $db)
	{
		$this->settings = $settings;
		$this->store = $store;
		$this->forum = $forum;
		$this->db = $db;
	}

	/**
	 * What to do with a post.
	 *
	 * @param string      $verdict   The recorded outcome.
	 * @param string|null $masked    The gateway's `ocenzurowany`, if any.
	 * @param bool        $complete  Whether the gateway saw the whole text (not cut at 3000
	 *     characters): only a whole text is published on the gateway's word.
	 * @param string      $censored  {@see settings::censored_mode()}.
	 * @param string      $blocked   {@see settings::blocked_mode()}.
	 * @param string      $fail_mode {@see settings::fail_mode()}.
	 * @return string One of the action constants.
	 */
	public static function decide($verdict, $masked, $complete, $censored, $blocked, $fail_mode)
	{
		switch ($verdict)
		{
			case 'zablokowane':
				return ($blocked === settings::BLOCKED_DELETE) ? self::DELETE : self::HOLD;

			case 'ocenzurowane':
				$usable = is_string($masked) && $masked !== '' && $complete;
				return ($censored === settings::CENSORED_PUBLISH && $usable) ? self::PUBLISH_MASKED : self::HOLD;

			case 'bezpieczne':
				if ($complete)
				{
					return self::PUBLISH;
				}
			break;
		}
		// `nieocenione`, a timeout, a refusal, anything unknown, or `bezpieczne` about the
		// beginning of a longer text: not a verdict on the post.
		return ($fail_mode === settings::FAIL_OPEN) ? self::PUBLISH : self::HOLD;
	}

	/**
	 * Applies the recorded outcome of one post, once.
	 *
	 * The row's move to its final status and the change to the post share one transaction:
	 * if either fails, both roll back and the row stays `received`, for the cron task. When
	 * the masked text cannot be stored, everything rolls back and the post is held instead:
	 * its original text is never published in place of the masked one.
	 *
	 * @param int $post_id The post.
	 * @param int $now     Unix seconds.
	 * @return string|null The final status, or null when there was nothing to apply.
	 */
	public function apply($post_id, $now)
	{
		$status = $this->attempt($post_id, $now, false);
		if ($status === self::MASK_FAILED)
		{
			$status = $this->attempt($post_id, $now, true);
		}
		return $status;
	}

	/**
	 * One attempt at {@see apply}.
	 *
	 * @param int  $post_id     The post.
	 * @param int  $now         Unix seconds.
	 * @param bool $mask_failed Whether an earlier attempt could not store the masked text.
	 * @return string|null The final status, {@see MASK_FAILED}, or null.
	 */
	protected function attempt($post_id, $now, $mask_failed)
	{
		$row = $this->store->find($post_id);
		if ($row === null || $row['status'] !== pending_store::RECEIVED)
		{
			return null;
		}
		$revision = (int) $row['revision'];

		$this->db->sql_transaction('begin');
		try
		{
			$post = $this->forum->load_post($post_id);
			if ($post === null || !$this->forum->awaits_approval($post))
			{
				$status = $this->store->finish($post_id, $revision, pending_store::SUPERSEDED, '', $now) ? pending_store::SUPERSEDED : null;
				$this->db->sql_transaction('commit');
				return $status;
			}

			$masked = ($row['masked_text'] !== '') ? (string) $row['masked_text'] : null;
			$complete = !forum::is_cut($this->forum->plain_text((string) $post['post_text']));
			$action = self::decide((string) $row['verdict'], $masked, $complete,
				$this->settings->censored_mode(), $this->settings->blocked_mode(), $this->settings->fail_mode());

			$original = '';
			$extra = array('truncated' => $complete ? 0 : 1);
			if ($action === self::PUBLISH_MASKED && $mask_failed)
			{
				$action = self::HOLD;
				$extra['error_code'] = self::MASK_FAILED;
			}
			switch ($action)
			{
				case self::PUBLISH:
					$status = pending_store::PUBLISHED;
					$log = 'LOG_MINOS_POST_PUBLISHED';
				break;

				case self::PUBLISH_MASKED:
					$status = pending_store::MASKED;
					$log = 'LOG_MINOS_POST_MASKED';
					$original = (string) $post['post_text'];
				break;

				case self::DELETE:
					$status = pending_store::DELETED;
					$log = 'LOG_MINOS_POST_DELETED';
				break;

				default:
					$status = pending_store::HELD;
					$log = 'LOG_MINOS_POST_HELD';
				break;
			}

			// Claim the row first: of two concurrent appliers, only one gets here.
			if (!$this->store->finish($post_id, $revision, $status, $original, $now, $extra))
			{
				$this->db->sql_transaction('rollback');
				return null;
			}

			if ($action === self::PUBLISH)
			{
				$this->forum->approve($post);
			}
			else if ($action === self::PUBLISH_MASKED)
			{
				$masked_post = $this->forum->replace_text($post, (string) $masked);
				if ($masked_post === null)
				{
					$this->db->sql_transaction('rollback');
					return self::MASK_FAILED;
				}
				$this->forum->approve($masked_post);
			}
			else if ($action === self::DELETE)
			{
				$this->forum->soft_delete($post);
			}
			$this->forum->log_decision($post, $log);
			if (!empty($row['support']))
			{
				// `wsparcie`: a cue to reach out, whatever happened to the post; the moderator log
				// shows it next to the post, also when the post was published.
				$this->forum->log_decision($post, 'LOG_MINOS_SUPPORT');
			}

			$this->db->sql_transaction('commit');
			return $status;
		}
		catch (\Throwable $e)
		{
			$this->db->sql_transaction('rollback');
			throw $e;
		}
	}

	/**
	 * Records the absence of a verdict and applies the failure mode at once.
	 *
	 * @param array<string,mixed> $row        The pending row.
	 * @param string              $verdict    {@see pending_store::VERDICT_TIMEOUT} or
	 *     {@see pending_store::VERDICT_REFUSED}.
	 * @param string              $error_code The refusal's code, if any.
	 * @param int                 $now        Unix seconds.
	 * @return string|null The final status, or null when the row had moved on.
	 */
	public function fail($row, $verdict, $error_code, $now)
	{
		$post_id = (int) $row['post_id'];
		if (!$this->store->record_verdict($post_id, (int) $row['revision'], $verdict, array(), false, null, $error_code))
		{
			return null;
		}
		return $this->apply($post_id, $now);
	}
}
