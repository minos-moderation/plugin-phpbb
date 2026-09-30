<?php

namespace phpbb;

use phpbb\db\driver\driver_interface;

/**
 * Stub and fake of phpBB's `content_visibility`: records every change and applies it to the
 * test database's `post_visibility`, the one column the extension reads back.
 *
 * phpBB's real method accepts only ITEM_APPROVED, ITEM_DELETED and ITEM_REAPPROVE, and
 * accounts post counts as if every ITEM_DELETED post had been approved; the fake records the
 * visibility each post had before, so a test can check that property holds.
 */
class content_visibility
{
	/** @var array<int,array<string,mixed>> Every call, in order. */
	public $calls = array();

	/** @var driver_interface */
	protected $db;

	/** @var string */
	protected $posts_table;

	public function __construct(driver_interface $db, $posts_table)
	{
		$this->db = $db;
		$this->posts_table = $posts_table;
	}

	public function set_post_visibility($visibility, $post_id, $topic_id, $forum_id, $user_id, $time, $reason, $is_starter, $is_latest, $limit_visibility = false, $limit_delete_time = false)
	{
		if (!in_array($visibility, array(ITEM_APPROVED, ITEM_DELETED, ITEM_REAPPROVE), true))
		{
			return array();
		}
		$ids = array_map('intval', (array) $post_id);
		$before = array();
		$result = $this->db->sql_query('SELECT post_id, post_visibility FROM ' . $this->posts_table . ' WHERE ' . $this->db->sql_in_set('post_id', $ids));
		while ($row = $this->db->sql_fetchrow($result))
		{
			$before[(int) $row['post_id']] = (int) $row['post_visibility'];
		}
		$this->db->sql_freeresult($result);

		$this->calls[] = array(
			'visibility' => $visibility,
			'post_ids'   => $ids,
			'before'     => $before,
			'topic_id'   => (int) $topic_id,
			'forum_id'   => (int) $forum_id,
			'reason'     => (string) $reason,
			'user_id'    => (int) $user_id,
			'time'       => (int) $time,
			'is_starter' => (bool) $is_starter,
			'is_latest'  => (bool) $is_latest,
		);
		// phpBB stores who changed the visibility and when, for every change.
		$this->db->sql_query('UPDATE ' . $this->posts_table . ' SET ' . $this->db->sql_build_array('UPDATE', array(
			'post_visibility'    => (int) $visibility,
			'post_delete_user'   => (int) $user_id,
			'post_delete_time'   => ((int) $time) ?: time(),
			'post_delete_reason' => (string) $reason,
		)) . ' WHERE ' . $this->db->sql_in_set('post_id', $ids));
		return array('post_visibility' => $visibility);
	}
}
