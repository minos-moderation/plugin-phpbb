<?php

namespace minos\moderation\tests\Fake;

use phpbb\report\exception\already_reported_exception;
use phpbb\report\exception\empty_report_exception;
use phpbb\report\exception\entity_not_found_exception;
use phpbb\report\exception\invalid_report_exception;
use phpbb\report\exception\report_permission_denied_exception;

/**
 * phpBB 3.3's post report handler (`report_handler_post`) over the test board, as phpBB's
 * does it: the post and its forum must exist; `core.report_post_auth` (`on_auth`, the
 * extension's listener) may change the permissions checked; the request's permissions are
 * loaded for the request's user and each of `f_list`, `f_read`, `f_report` must be granted
 * on the forum; a post with an open report is refused; the reason must exist and "other"
 * needs a text. Then the report (by the request's user, with a copy of the post as reported),
 * the post's and the topic's "reported" marks, and the `report_post` notification.
 */
class ReportHandler implements \phpbb\report\report_handler_interface
{
	/** @var SqliteDriver */
	protected $db;

	/** @var \phpbb\auth\auth */
	protected $auth;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\notification\manager */
	protected $notifications;

	/** @var string */
	protected $prefix;

	/** @var callable|null The listener of `core.report_post_auth`. */
	public $on_auth = null;

	/** @var array<int,int> The user each notification was sent as (phpBB's `reporter_id`). */
	public $notified_as = array();

	/** @var array<string,mixed> */
	protected $report_data = array();

	/** @var array<string,mixed> */
	protected $forum_data = array();

	public function __construct(SqliteDriver $db, \phpbb\auth\auth $auth, \phpbb\user $user, \phpbb\notification\manager $notifications, $prefix)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->notifications = $notifications;
		$this->prefix = $prefix;
	}

	public function add_report($id, $reason_id, $report_text, $user_notify)
	{
		$id = (int) $id;
		$reason_id = (int) $reason_id;
		$report_text = (string) $report_text;
		$this->validate_report_request($id);

		$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix . 'reports_reasons WHERE reason_id = ' . $reason_id);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$row || ($report_text === '' && strtolower($row['reason_title']) === 'other'))
		{
			throw new empty_report_exception();
		}

		$this->db->sql_query('INSERT INTO ' . $this->prefix . 'reports ' . $this->db->sql_build_array('INSERT', array(
			'reason_id'          => $reason_id,
			'post_id'            => $id,
			'pm_id'              => 0,
			'user_id'            => (int) $this->user->data['user_id'],
			'user_notify'        => (int) $user_notify,
			'report_closed'      => 0,
			'report_time'        => time(),
			'report_text'        => $report_text,
			'reported_post_text' => (string) $this->report_data['post_text'],
		)));
		$this->db->sql_query('UPDATE ' . $this->prefix . 'posts SET post_reported = 1 WHERE post_id = ' . $id);
		$this->db->sql_query('UPDATE ' . $this->prefix . 'topics SET topic_reported = 1 WHERE topic_id = ' . (int) $this->report_data['topic_id']);

		// phpBB's report_post notification names the request's user as the reporter.
		$this->notified_as[] = (int) $this->user->data['user_id'];
		$this->notifications->add_notifications('notification.type.report_post', array_merge($this->report_data, $row, $this->forum_data, array(
			'report_text' => $report_text,
		)));
		return null;
	}

	public function validate_report_request($id)
	{
		$id = (int) $id;
		if ($id <= 0)
		{
			throw new entity_not_found_exception('NO_POST_SELECTED');
		}
		$result = $this->db->sql_query('SELECT t.*, p.* FROM ' . $this->prefix . 'posts p, ' . $this->prefix . 'topics t
			WHERE p.post_id = ' . $id . ' AND p.topic_id = t.topic_id');
		$report_data = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$report_data)
		{
			throw new entity_not_found_exception('POST_NOT_EXIST');
		}
		$forum_id = (int) $report_data['forum_id'];
		$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix . 'forums WHERE forum_id = ' . $forum_id);
		$forum_data = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$forum_data)
		{
			throw new invalid_report_exception('FORUM_NOT_EXIST');
		}

		$event = new \phpbb\event\data(array(
			'forum_data'    => $forum_data,
			'report_data'   => $report_data,
			'acl_check_ary' => array('f_list' => 'POST_NOT_EXIST', 'f_read' => 'USER_CANNOT_READ', 'f_report' => 'USER_CANNOT_REPORT'),
		));
		if ($this->on_auth !== null)
		{
			call_user_func($this->on_auth, $event);
		}

		$this->auth->acl($this->user->data);
		foreach ($event['acl_check_ary'] as $acl => $error)
		{
			if (!$this->auth->acl_get($acl, $forum_id))
			{
				throw new report_permission_denied_exception($error);
			}
		}
		if (!empty($report_data['post_reported']))
		{
			throw new already_reported_exception();
		}
		$this->report_data = $report_data;
		$this->forum_data = $forum_data;
		return null;
	}

	/**
	 * The reports of a post, as phpBB keeps them.
	 *
	 * @param int $post_id
	 * @return array<int,array<string,string>>
	 */
	public function of($post_id)
	{
		$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix . 'reports WHERE post_id = ' . (int) $post_id . ' ORDER BY report_id');
		$rows = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $row;
		}
		$this->db->sql_freeresult($result);
		return $rows;
	}
}
