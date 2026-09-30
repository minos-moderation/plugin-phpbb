<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Minos
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
 * once it has an id (`core.submit_post_end`). An edit of a post that still waits starts its
 * assessment again, so a verdict never publishes text the gateway did not see.
 *
 * Not held: posts by administrators and moderators of the forum; posts phpBB queues for a
 * moderator anyway (no `f_noapprove`); posts another extension already set a visibility for;
 * posts without text to assess (only a quote, an image); and everything while the
 * extension is off or not configured. Switched off, the extension does nothing at all: an
 * edit of a post it held is not sent either, and the post waits for a moderator.
 *
 * MCP: the approval queue marks the posts the extension holds, and a post's details show
 * the outcome, including the gateway's call for support (`wsparcie`).
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

	/**
	 * @param settings                 $settings  The administrator's settings.
	 * @param pending_store            $store     The pending rows.
	 * @param forum                    $forum     phpBB's posts.
	 * @param submitter                $submitter Sends posts to the gateway.
	 * @param \phpbb\auth\auth         $auth      The poster's permissions.
	 * @param \phpbb\language\language $language  The MCP labels.
	 */
	public function __construct(settings $settings, pending_store $store, forum $forum, submitter $submitter,
		\phpbb\auth\auth $auth, \phpbb\language\language $language)
	{
		$this->settings = $settings;
		$this->store = $store;
		$this->forum = $forum;
		$this->submitter = $submitter;
		$this->auth = $auth;
		$this->language = $language;
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
		);
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
			if ($row === null || !in_array($row['status'], pending_store::UNSETTLED, true))
			{
				return;
			}
			// The waiting verdict is for the old text: the edit stays queued and goes again.
			$data['force_approved_state'] = ITEM_UNAPPROVED;
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
		if ($flag === null || $post_id <= 0 || (int) $event['post_visibility'] !== ITEM_UNAPPROVED || !$this->settings->enabled())
		{
			return;
		}

		$now = time();
		$queued = ($flag === self::REASSESS) ? $this->store->restart($post_id, $now) : $this->store->create($post_id, $now);
		if (!$queued || !$this->settings->is_ready())
		{
			// Not ready: the row waits, and the cron task applies the failure mode in time.
			return;
		}
		$this->submitter->submit(array($this->store->find($post_id)), $now);
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
		$post_data['MINOS_MASKED'] = ($pending['status'] === pending_store::HELD && $pending['masked_text'] !== '')
			? nl2br(htmlspecialchars((string) $pending['masked_text'], ENT_COMPAT, 'UTF-8'))
			: '';
		$event['post_data'] = $post_data;
	}

	/**
	 * Whether a new post is to be held and assessed.
	 *
	 * @param array<string,mixed> $data     phpBB's post data.
	 * @param int                 $forum_id The forum.
	 * @return bool
	 */
	protected function should_hold(array $data, $forum_id)
	{
		if (!$this->settings->is_ready() || !$this->settings->applies_to_forum($forum_id))
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
		return $this->forum->plain_text(isset($data['message']) ? (string) $data['message'] : '') !== '';
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
		return ($pending['verdict'] !== '') ? 'MINOS_MCP_VERDICT_' . strtoupper((string) $pending['verdict']) : 'MINOS_MCP_HANDLED';
	}
}
