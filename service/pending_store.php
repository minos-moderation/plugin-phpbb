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
 * The `minos_pending` table: one row per post the extension held for assessment.
 *
 * A row moves `queued` (to be sent) → `pending` (the gateway took it) → `received` (a
 * verdict, or its absence, is recorded) → a final status once the verdict was applied.
 * Every move is a conditional UPDATE, so a repeated delivery, a webhook that overtakes the
 * gateway's own `202`, and the cron task racing the webhook each change a row at most once.
 *
 * `revision` counts edits of a post while it waits: a verdict for an earlier revision never
 * applies to the edited text. `sent_md5` is the digest of the text last sent, so a verdict
 * is applied only to that text, however the post was changed meanwhile.
 */
class pending_store
{
	/** To be sent (first time, or again after a retryable failure). */
	const QUEUED = 'queued';

	/** The gateway accepted it; the verdict is awaited. */
	const PENDING = 'pending';

	/** A verdict (or its absence) is recorded and waits to be applied. */
	const RECEIVED = 'received';

	/** Final: published. */
	const PUBLISHED = 'published';

	/** Final: published with the gateway's masked text. */
	const MASKED = 'masked';

	/** Final: kept in the approval queue for a moderator. */
	const HELD = 'held';

	/** Final: soft-deleted. */
	const DELETED = 'deleted';

	/** Final: a moderator acted first, or the post is gone; nothing was applied. */
	const SUPERSEDED = 'superseded';

	/** Rows that still wait for a verdict. */
	const OPEN = array(self::QUEUED, self::PENDING);

	/** Rows whose post stays unapproved because of the extension. */
	const UNSETTLED = array(self::QUEUED, self::PENDING, self::RECEIVED);

	/** Rows the extension is done with. */
	const FINAL_STATUSES = array(self::PUBLISHED, self::MASKED, self::HELD, self::DELETED, self::SUPERSEDED);

	/** Verdict recorded when no verdict arrived in time. */
	const VERDICT_TIMEOUT = 'timeout';

	/** Verdict recorded when the gateway refused the request (a configuration error). */
	const VERDICT_REFUSED = 'refused';

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var string */
	protected $table;

	/**
	 * @param \phpbb\db\driver\driver_interface $db    The database.
	 * @param string                            $table The table's full name.
	 */
	public function __construct(\phpbb\db\driver\driver_interface $db, $table)
	{
		$this->db = $db;
		$this->table = $table;
	}

	/**
	 * Adds a new post, queued for its first submission.
	 *
	 * @param int $post_id The post.
	 * @param int $now     Unix seconds.
	 * @return bool False when the post already has a row.
	 */
	public function create($post_id, $now)
	{
		if ($this->find($post_id) !== null)
		{
			return false;
		}
		$this->db->sql_query('INSERT INTO ' . $this->table . ' ' . $this->db->sql_build_array('INSERT', array(
			'post_id'       => (int) $post_id,
			'revision'      => 0,
			'status'        => self::QUEUED,
			'verdict'       => '',
			'categories'    => '',
			'support'       => 0,
			'attempts'      => 0,
			'submitted_at'  => (int) $now,
			'retry_at'      => (int) $now,
			'handled_at'    => 0,
			'error_code'    => '',
			'masked_text'   => '',
			'original_text' => '',
		)));
		return true;
	}

	/**
	 * One row.
	 *
	 * @param int $post_id The post.
	 * @return array<string,mixed>|null
	 */
	public function find($post_id)
	{
		$result = $this->db->sql_query('SELECT * FROM ' . $this->table . ' WHERE post_id = ' . (int) $post_id);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row ? $row : null;
	}

	/**
	 * Rows for several posts.
	 *
	 * @param array<int,int> $post_ids The posts.
	 * @return array<int,array<string,mixed>> Keyed by post id.
	 */
	public function find_many(array $post_ids)
	{
		$post_ids = array_values(array_unique(array_map('intval', $post_ids)));
		if (!$post_ids)
		{
			return array();
		}
		return $this->select($this->db->sql_in_set('post_id', $post_ids), 'post_id', count($post_ids));
	}

	/**
	 * Queued rows whose next attempt is due.
	 *
	 * @param int $now   Unix seconds.
	 * @param int $limit At most this many.
	 * @return array<int,array<string,mixed>>
	 */
	public function due($now, $limit)
	{
		return $this->select("status = '" . self::QUEUED . "' AND retry_at <= " . (int) $now, 'retry_at, post_id', $limit);
	}

	/**
	 * Rows still without a verdict that entered the queue at or before a moment.
	 *
	 * @param int $cutoff Unix seconds.
	 * @param int $limit  At most this many.
	 * @return array<int,array<string,mixed>>
	 */
	public function overdue($cutoff, $limit)
	{
		return $this->select($this->db->sql_in_set('status', self::OPEN) . ' AND submitted_at <= ' . (int) $cutoff,
			'submitted_at, post_id', $limit);
	}

	/**
	 * Rows with a recorded verdict that was not applied yet.
	 *
	 * @param int $limit At most this many.
	 * @return array<int,array<string,mixed>>
	 */
	public function received($limit)
	{
		return $this->select("status = '" . self::RECEIVED . "'", 'post_id', $limit);
	}

	/**
	 * The newest rows, for the ACP.
	 *
	 * @param int $limit At most this many.
	 * @return array<int,array<string,mixed>>
	 */
	public function recent($limit)
	{
		return $this->select('1 = 1', 'submitted_at DESC, post_id DESC', $limit);
	}

	/**
	 * How many rows are in each status.
	 *
	 * @return array<string,int>
	 */
	public function counts()
	{
		$counts = array();
		$result = $this->db->sql_query('SELECT status, COUNT(post_id) AS total FROM ' . $this->table . ' GROUP BY status');
		while ($row = $this->db->sql_fetchrow($result))
		{
			$counts[(string) $row['status']] = (int) $row['total'];
		}
		$this->db->sql_freeresult($result);
		return $counts;
	}

	/**
	 * The gateway accepted a queued row.
	 *
	 * @param int    $post_id  The post.
	 * @param int    $revision The revision that was sent.
	 * @param string $sent_md5 The MD5 of the text that was sent.
	 * @return bool False when the row moved on meanwhile (its webhook may have come first).
	 */
	public function mark_pending($post_id, $revision, $sent_md5)
	{
		return $this->update(array('status' => self::PENDING, 'error_code' => '', 'sent_md5' => (string) $sent_md5),
			$post_id, $revision, array(self::QUEUED), true);
	}

	/**
	 * A queued row gets another attempt later.
	 *
	 * @param int    $post_id    The post.
	 * @param int    $revision   The revision that was sent.
	 * @param int    $retry_at   Unix seconds.
	 * @param string $error_code Why, for the ACP (never content).
	 * @param string $sent_md5   The MD5 of the text that was sent (the gateway may have taken it
	 *     although no answer came back).
	 * @return bool
	 */
	public function mark_retry($post_id, $revision, $retry_at, $error_code, $sent_md5)
	{
		return $this->update(array('retry_at' => (int) $retry_at, 'error_code' => self::code($error_code), 'sent_md5' => (string) $sent_md5),
			$post_id, $revision, array(self::QUEUED), true);
	}

	/**
	 * Records the outcome of an assessment, once.
	 *
	 * A row that timed out (published by the fail-open mode, or held by the fail-closed one)
	 * still takes a verdict that arrives late, since the gateway may have accepted the post
	 * later than it was queued here.
	 *
	 * @param int                $post_id    The post.
	 * @param int                $revision   The revision the outcome is for.
	 * @param string             $verdict    `bezpieczne`, `ocenzurowane`, `zablokowane`,
	 *     `nieocenione`, {@see VERDICT_TIMEOUT} or {@see VERDICT_REFUSED}.
	 * @param array<int,string>  $categories The categories the gateway named.
	 * @param bool               $support    The gateway's `wsparcie`.
	 * @param string|null        $masked     The gateway's `ocenzurowany`.
	 * @param string             $error_code The refusal's code, for {@see VERDICT_REFUSED}.
	 * @return bool False when the row is unknown, of another revision, or already has one.
	 */
	public function record_verdict($post_id, $revision, $verdict, array $categories, $support, $masked, $error_code = '')
	{
		$labels = array();
		foreach ($categories as $label)
		{
			if (is_string($label) && preg_match('/^[a-z0-9_]{1,40}$/', $label))
			{
				$labels[] = $label;
			}
		}
		return $this->update(array(
			'status'      => self::RECEIVED,
			'verdict'     => self::code($verdict),
			'categories'  => substr(implode(',', $labels), 0, 255),
			'support'     => $support ? 1 : 0,
			'masked_text' => is_string($masked) ? $masked : '',
			'error_code'  => self::code($error_code),
		), $post_id, $revision, self::OPEN, false, array(self::PUBLISHED, self::HELD));
	}

	/**
	 * Marks a recorded verdict as applied.
	 *
	 * @param int    $post_id       The post.
	 * @param int    $revision      The revision.
	 * @param string $status        A final status.
	 * @param string $original_text The post's stored text before it was replaced, or ''.
	 * @param int    $now           Unix seconds.
	 * @param array<string,int|string> $extra Other columns: `truncated` (the gateway saw only
	 *     the first 3000 characters), `error_code`, and the mark of the extension's own
	 *     fail-open approval: `approved_at` (its time, 0 for none) and `approved_md5` (the
	 *     stored text it approved).
	 * @return bool False when the row is not `received` (someone else applied it).
	 */
	public function finish($post_id, $revision, $status, $original_text, $now, array $extra = array())
	{
		if (isset($extra['error_code']))
		{
			$extra['error_code'] = self::code($extra['error_code']);
		}
		return $this->update(array_intersect_key($extra, array_flip(array('truncated', 'error_code', 'approved_at', 'approved_md5'))) + array(
			'status'        => $status,
			'original_text' => (string) $original_text,
			'handled_at'    => (int) $now,
		), $post_id, $revision, array(self::RECEIVED), false);
	}

	/**
	 * Closes a row whose post no longer waits (approved by a moderator, deleted, gone).
	 *
	 * @param int $post_id  The post.
	 * @param int $revision The revision.
	 * @param int $now      Unix seconds.
	 * @return bool
	 */
	public function supersede($post_id, $revision, $now)
	{
		return $this->update(array('status' => self::SUPERSEDED, 'handled_at' => (int) $now),
			$post_id, $revision, self::UNSETTLED, false);
	}

	/**
	 * Starts the assessment again after the post was edited while it waited, or after a
	 * time-out left it unassessed (held by fail-closed, published by fail-open): a new
	 * revision, queued now, with the previous outcome and marks forgotten.
	 *
	 * @param int $post_id The post.
	 * @param int $now     Unix seconds.
	 * @return bool False when the row is neither waiting nor timed out.
	 */
	public function restart($post_id, $now)
	{
		$sql = 'UPDATE ' . $this->table . '
			SET revision = revision + 1, ' . $this->db->sql_build_array('UPDATE', array(
				'status'       => self::QUEUED,
				'verdict'      => '',
				'categories'   => '',
				'support'      => 0,
				'attempts'     => 0,
				'submitted_at' => (int) $now,
				'retry_at'     => (int) $now,
				'error_code'   => '',
				'masked_text'  => '',
				'sent_md5'     => '',
				'approved_at'  => 0,
				'approved_md5' => '',
			)) . '
			WHERE post_id = ' . (int) $post_id . '
				AND ' . $this->reopenable();
		$this->db->sql_query($sql);
		return $this->db->sql_affectedrows() === 1;
	}

	/**
	 * Whether a row still belongs to the assessment: waiting, or timed out.
	 *
	 * @return string The condition.
	 */
	public function reopenable()
	{
		return '(' . $this->db->sql_in_set('status', self::UNSETTLED) . ' OR ('
			. $this->db->sql_in_set('status', array(self::PUBLISHED, self::HELD)) . " AND verdict = '" . self::VERDICT_TIMEOUT . "'))";
	}

	/**
	 * Whether a row is waiting or timed out, as {@see reopenable} says in SQL.
	 *
	 * @param array<string,mixed> $row The row.
	 * @return bool
	 */
	public static function is_reopenable(array $row)
	{
		return in_array($row['status'], self::UNSETTLED, true)
			|| (in_array($row['status'], array(self::PUBLISHED, self::HELD), true) && $row['verdict'] === self::VERDICT_TIMEOUT);
	}

	/**
	 * Deletes rows the extension finished with before a moment.
	 *
	 * @param int $cutoff Unix seconds.
	 * @return int Rows deleted.
	 */
	public function prune($cutoff)
	{
		$this->db->sql_query('DELETE FROM ' . $this->table . '
			WHERE ' . $this->db->sql_in_set('status', self::FINAL_STATUSES) . '
				AND handled_at < ' . (int) $cutoff);
		return (int) $this->db->sql_affectedrows();
	}

	/**
	 * A conditional update of one row.
	 *
	 * @param array<string,mixed> $values        Columns to set.
	 * @param int                 $post_id       The post.
	 * @param int                 $revision      The revision the change is for.
	 * @param array<int,string>   $from          Statuses the row must be in.
	 * @param bool                $count_attempt Whether this was a submission attempt.
	 * @param array<int,string>   $timed_out     Statuses a timed-out row may also be in.
	 * @return bool Whether the row changed.
	 */
	protected function update(array $values, $post_id, $revision, array $from, $count_attempt, array $timed_out = array())
	{
		$status = $this->db->sql_in_set('status', $from);
		if ($timed_out)
		{
			$status = '(' . $status . ' OR (' . $this->db->sql_in_set('status', $timed_out) . " AND verdict = '" . self::VERDICT_TIMEOUT . "'))";
		}
		$sql = 'UPDATE ' . $this->table . '
			SET ' . ($count_attempt ? 'attempts = attempts + 1, ' : '') . $this->db->sql_build_array('UPDATE', $values) . '
			WHERE post_id = ' . (int) $post_id . '
				AND revision = ' . (int) $revision . '
				AND ' . $status;
		$this->db->sql_query($sql);
		return $this->db->sql_affectedrows() === 1;
	}

	/**
	 * Rows matching a condition.
	 *
	 * @param string $where    The condition (already escaped).
	 * @param string $order_by The order.
	 * @param int    $limit    At most this many.
	 * @return array<int,array<string,mixed>> Keyed by post id.
	 */
	protected function select($where, $order_by, $limit)
	{
		$rows = array();
		$result = $this->db->sql_query_limit('SELECT * FROM ' . $this->table . ' WHERE ' . $where . ' ORDER BY ' . $order_by, (int) $limit);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[(int) $row['post_id']] = $row;
		}
		$this->db->sql_freeresult($result);
		return $rows;
	}

	/**
	 * A label that is safe to store and show: lower-case letters, digits and underscores.
	 *
	 * @param string $code The label.
	 * @return string The label, or '' for anything else.
	 */
	protected static function code($code)
	{
		return preg_match('/^[a-z0-9_]{1,40}$/', (string) $code) ? (string) $code : '';
	}
}
