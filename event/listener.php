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

use minos\moderation\platform\forum;
use minos\moderation\service\pending_store;
use minos\moderation\service\settings;
use minos\moderation\service\submitter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * phpBB's events the extension listens to.
 *
 * Posting: a new topic or reply is put into the approval queue before phpBB stores it
 * (`core.posting_modify_submit_post_before`, `force_approved_state`), and sent to the gateway
 * once it has an id (`core.submit_post_end`). An edit of a post that still waits, or that a
 * time-out left unassessed, starts its assessment again, so a verdict never publishes text
 * the gateway did not see.
 *
 * Not held: posts by administrators and moderators of the forum; posts phpBB queues for a
 * moderator anyway (no `f_noapprove`); posts by users outside the groups the administrator
 * chose (none chosen: every group); posts another extension already set a visibility for;
 * replies with neither text to assess, a link nor a subject of their own (attachments only);
 * and everything while the extension is off. Switched on but unable to submit (no key, no secret, no usable gateway
 * address), it holds new posts and gives them the failure mode at once (the plugins' shared
 * rule D9.12). A post with a link or a title (a new topic, a reply's own subject) but no text
 * to assess is held and goes to the failure mode at once, without a request (the plugins'
 * shared rule D9.5): a link, even one with a blank text, and a title never pass unassessed,
 * and a title is never sent alone. Switched off, the
 * extension does nothing at all: an edit of a post it held is not sent either, and the post
 * waits for a moderator.
 *
 * MCP: the approval queue marks the posts the extension holds, and a post's details show
 * the outcome, including the gateway's call for support (`wsparcie`).
 *
 * Notifications: phpBB tells the moderators of every post it stores in the queue at once,
 * also by e-mail. For a post the extension holds, that notification is kept on the board
 * only (`core.notification_manager_add_notifications`); the e-mail goes out when the
 * extension leaves the post in the queue for a moderator ({@see forum::notify_queue}), never
 * for a post it publishes.
 */
class listener implements EventSubscriberInterface
{
	/** A key the extension adds to phpBB's post data between the two posting events. */
	const FLAG = 'minos_moderation';

	/** Flag value: a new post to assess. */
	const ASSESS = 'assess';

	/** Flag value: an edit of a waiting post to assess again. */
	const REASSESS = 'reassess';

	/** @var settings */
	protected $settings;

	/** @var pending_store */
	protected $store;

	/** @var forum */
	protected $forum;

	/** @var submitter */
	protected $submitter;

	/** @var \phpbb\auth\auth */
	protected $auth;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\user */
	protected $user;

	/**
	 * @param settings                 $settings  The administrator's settings.
	 * @param pending_store            $store     The pending rows.
	 * @param forum                    $forum     phpBB's posts.
	 * @param submitter                $submitter Sends posts to the gateway.
	 * @param \phpbb\auth\auth         $auth      The poster's permissions.
	 * @param \phpbb\language\language $language  The MCP labels.
	 * @param \phpbb\user              $user      The poster (a new post is always the current user's).
	 */
	public function __construct(settings $settings, pending_store $store, forum $forum, submitter $submitter,
		\phpbb\auth\auth $auth, \phpbb\language\language $language, \phpbb\user $user)
	{
		$this->settings = $settings;
		$this->store = $store;
		$this->forum = $forum;
		$this->submitter = $submitter;
		$this->auth = $auth;
		$this->language = $language;
		$this->user = $user;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents()
	{
		return array(
			'core.posting_modify_submit_post_before' => 'hold_for_assessment',
			'core.submit_post_end'                   => 'send_for_assessment',
			'core.mcp_queue_get_posts_modify_post_row' => 'mark_queue_row',
			'core.mcp_queue_approve_details_template'  => 'show_queue_details',
			'core.notification_manager_add_notifications' => 'hold_back_queue_messages',
			'core.report_post_auth'                  => 'allow_own_report',
		);
	}

	/**
	 * Lets the extension's own report of a post with `wsparcie` through phpBB's check of the
	 * reporter's permissions (`f_list`, `f_read`, `f_report`): the report is filed in the
	 * guest's name, whom phpBB does not let report by default ({@see forum::report_support}).
	 * Every other report is checked as phpBB checks it.
	 *
	 * @param \phpbb\event\data $event `report_data` (the post and its topic), `acl_check_ary`.
	 * @return void
	 */
	public function allow_own_report($event)
	{
		$report = $event['report_data'];
		$reporting = $this->forum->reporting();
		if ($reporting > 0 && is_array($report) && isset($report['post_id']) && (int) $report['post_id'] === $reporting)
		{
			$event['acl_check_ary'] = array();
		}
	}

	/**
	 * Puts a new post into the approval queue while it is assessed.
	 *
	 * @param \phpbb\event\data $event `mode`, `data`, `forum_id`, `post_id`.
	 * @return void
	 */
	public function hold_for_assessment($event)
	{
		if (!$this->settings->enabled())
		{
			return;
		}
		$mode = (string) $event['mode'];
		$data = $event['data'];
		$forum_id = (int) $event['forum_id'];

		if ($mode === 'edit')
		{
			$row = $this->store->find((int) $event['post_id']);
			$post = ($row !== null) ? $this->forum->load_post((int) $event['post_id']) : null;
			if ($row === null || $post === null || !pending_store::is_reopenable($row))
			{
				return;
			}
			// A waiting or late verdict is for the old text: the edited post goes (back) into
			// the queue - phpBB's re-approve state if it was published - and is sent again.
			$data['force_approved_state'] = ((int) $post['post_visibility'] === ITEM_APPROVED) ? ITEM_REAPPROVE : ITEM_UNAPPROVED;
			$data[self::FLAG] = self::REASSESS;
			$event['data'] = $data;
			return;
		}

		if (!in_array($mode, array('post', 'reply', 'quote'), true) || !$this->should_hold($data, $forum_id))
		{
			return;
		}
		$data['force_approved_state'] = ITEM_UNAPPROVED;
		$data[self::FLAG] = self::ASSESS;
		$event['data'] = $data;
	}

	/**
	 * Sends a held post to the gateway, now that it has an id.
	 *
	 * @param \phpbb\event\data $event `data` (with `post_id`), `post_visibility`.
	 * @return void
	 */
	public function send_for_assessment($event)
	{
		$data = $event['data'];
		$flag = isset($data[self::FLAG]) ? $data[self::FLAG] : null;
		$post_id = isset($data['post_id']) ? (int) $data['post_id'] : 0;
		if ($flag === null || $post_id <= 0 || !in_array((int) $event['post_visibility'], array(ITEM_UNAPPROVED, ITEM_REAPPROVE), true)
			|| !$this->settings->enabled())
		{
			return;
		}

		$now = time();
		$queued = ($flag === self::REASSESS) ? $this->store->restart($post_id, $now) : $this->store->create($post_id, $now);
		if (!$queued)
		{
			// Nothing to assess it with: the moderators get phpBB's e-mail, held back for it
			// (once, if the post has a row).
			$post = $this->forum->load_post($post_id);
			if ($post !== null && $this->forum->awaits_approval($post)
				&& ($this->store->find($post_id) === null || $this->store->mark_announced($post_id)))
			{
				$this->forum->notify_queue($post);
			}
			return;
		}
		// Sent at once; without a usable configuration, the failure mode at once instead.
		$this->submitter->submit(array($this->store->find($post_id)), $now);
	}

	/**
	 * Keeps phpBB's "post (topic) in the queue" notification of a post the extension holds on
	 * the board only: the e-mail (or any other message) waits until the extension leaves the
	 * post for a moderator ({@see forum::notify_queue}), and a post it publishes sends none.
	 *
	 * The post is the extension's by the flag {@see hold_for_assessment} put into phpBB's post
	 * data, which `submit_post()` passes into the notification's data.
	 *
	 * @param \phpbb\event\data $event `notification_type_name`, `data`, `notify_users` (user id
	 *     => the methods), `options`.
	 * @return void
	 */
	public function hold_back_queue_messages($event)
	{
		$data = $event['data'];
		if (!in_array((string) $event['notification_type_name'], forum::QUEUE_NOTIFICATIONS, true)
			|| !is_array($data) || empty($data[self::FLAG]))
		{
			return;
		}
		$board_only = array();
		foreach ((array) $event['notify_users'] as $user_id => $methods)
		{
			if (in_array(forum::BOARD_METHOD, (array) $methods, true))
			{
				$board_only[$user_id] = array(forum::BOARD_METHOD);
			}
		}
		$event['notify_users'] = $board_only;
	}

	/**
	 * Marks a post of the MCP approval queue that the extension holds.
	 *
	 * @param \phpbb\event\data $event `post_row` (template data), `row` (the post).
	 * @return void
	 */
	public function mark_queue_row($event)
	{
		$row = $event['row'];
		$pending = $this->store->find((int) $row['post_id']);
		if ($pending === null)
		{
			return;
		}
		$post_row = $event['post_row'];
		$label = htmlspecialchars($this->language->lang($this->status_key($pending)), ENT_COMPAT, 'UTF-8');
		if (!empty($pending['truncated']))
		{
			$label .= ', ' . htmlspecialchars($this->language->lang('MINOS_MCP_TRUNCATED'), ENT_COMPAT, 'UTF-8');
		}
		if (!empty($pending['support']))
		{
			$label .= ', ' . htmlspecialchars($this->language->lang('MINOS_MCP_SUPPORT'), ENT_COMPAT, 'UTF-8');
		}
		$post_row['POST_SUBJECT'] = '[' . $label . '] ' . $post_row['POST_SUBJECT'];
		$event['post_row'] = $post_row;
	}

	/**
	 * Shows the extension's outcome above a queued post's text in the MCP.
	 *
	 * @param \phpbb\event\data $event `post_id`, `post_data` (template data).
	 * @return void
	 */
	public function show_queue_details($event)
	{
		$pending = $this->store->find((int) $event['post_id']);
		if ($pending === null)
		{
			return;
		}
		$categories = array();
		foreach (array_filter(explode(',', (string) $pending['categories'])) as $label)
		{
			$key = 'MINOS_CATEGORY_' . strtoupper($label);
			$text = $this->language->lang($key);
			$categories[] = htmlspecialchars(($text === $key) ? $label : $text, ENT_COMPAT, 'UTF-8');
		}
		$post_data = $event['post_data'];
		$post_data['S_MINOS'] = true;
		$post_data['MINOS_STATUS'] = htmlspecialchars($this->language->lang($this->status_key($pending)), ENT_COMPAT, 'UTF-8');
		$post_data['MINOS_CATEGORIES'] = implode(', ', $categories);
		$post_data['S_MINOS_SUPPORT'] = !empty($pending['support']);
		$post_data['S_MINOS_TRUNCATED'] = !empty($pending['truncated']);
		$post_data['S_MINOS_MASK_MANUAL'] = ($pending['error_code'] === 'mask_manual' || $pending['error_code'] === 'mask_failed');
		$post_data['MINOS_MASKED'] = ($pending['status'] === pending_store::HELD && $pending['masked_text'] !== '')
			? nl2br(htmlspecialchars((string) $pending['masked_text'], ENT_COMPAT, 'UTF-8'))
			: '';
		$event['post_data'] = $post_data;
	}

	/**
	 * Whether a new post is to be held and assessed: every post the settings cover, whatever
	 * it holds. One without text to assess - blank, only images, attachments or videos, only
	 * a link with a blank label, a title alone - is held too, and gets the failure mode at once
	 * without a request (the plugins' shared rules D9.5 and F7): what a reader sees of it is
	 * nothing the gateway assesses, so the failure mode decides it instead.
	 *
	 * @param array<string,mixed> $data     phpBB's post data going to be stored.
	 * @param int                 $forum_id The forum.
	 * @return bool
	 */
	protected function should_hold(array $data, $forum_id)
	{
		if (!$this->settings->enabled() || !$this->settings->applies_to_forum($forum_id))
		{
			return false;
		}
		if (isset($data['force_approved_state']) || isset($data['force_visibility']))
		{
			return false;
		}
		if ($this->auth->acl_get('a_') || $this->auth->acl_get('m_', $forum_id))
		{
			return false;
		}
		if (!$this->auth->acl_get('f_noapprove', $forum_id))
		{
			return false;
		}
		// Only members of the chosen groups; groups that no longer exist choose nobody out.
		$groups = $this->settings->group_ids();
		return !$groups || $this->forum->in_groups((int) $this->user->data['user_id'], $groups) !== false;
	}

	/**
	 * The language key describing a row in the MCP.
	 *
	 * @param array<string,mixed> $pending The row.
	 * @return string
	 */
	protected function status_key(array $pending)
	{
		if (in_array($pending['status'], pending_store::UNSETTLED, true))
		{
			return 'MINOS_MCP_WAITING';
		}
		if ($pending['error_code'] === submitter::ERROR_EMPTY)
		{
			return 'MINOS_MCP_NO_TEXT';
		}
		return ($pending['verdict'] !== '') ? 'MINOS_MCP_VERDICT_' . strtoupper((string) $pending['verdict']) : 'MINOS_MCP_HANDLED';
	}
}
