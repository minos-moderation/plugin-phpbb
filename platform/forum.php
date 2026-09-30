<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Minos
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace minos\moderation\platform;

/**
 * Every operation the extension performs on phpBB's posts, in one place.
 *
 * The rest of the extension decides; this class carries a decision out with phpBB's own
 * services, the way the moderator control panel does (`includes/mcp/mcp_queue.php`,
 * `approve_posts`), so topic and forum counters, the last-post data and the notifications
 * stay consistent. It is also the one class to check when phpBB changes.
 */
class forum
{
	/** The longest text the gateway accepts, in characters. */
	const MAX_CHARS = 3000;

	/** The largest `meta.links` value the gateway keeps. */
	const MAX_LINKS = 100000;

	/** The gateway's mask character. */
	const MASK = '█';

	/** What introduces a quote's author in the text sent (content, in Polish). */
	const QUOTE_AUTHOR_SUFFIX = ' napisał(a):';

	/** The most `meta.link_domains` the gateway keeps. */
	const MAX_LINK_DOMAINS = 10;

	/**
	 * Second-level labels under which a country code's registrable domains sit one level
	 * deeper (`example.co.uk`, `example.com.pl`). An approximation of the Public Suffix List,
	 * which the extension does not bundle: a domain it misjudges is reported one level short.
	 */
	const SECOND_LEVEL_LABELS = array('ac', 'biz', 'co', 'com', 'edu', 'gov', 'info', 'net', 'org');

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\content_visibility */
	protected $visibility;

	/** @var \phpbb\notification\manager */
	protected $notifications;

	/** @var \phpbb\textformatter\utils_interface */
	protected $text_utils;

	/** @var \phpbb\textformatter\parser_interface */
	protected $parser;

	/** @var \phpbb\log\log_interface */
	protected $log;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var string */
	protected $posts_table;

	/** @var string */
	protected $topics_table;

	/** @var string */
	protected $forums_table;

	/** @var string */
	protected $users_table;

	/**
	 * @param \phpbb\db\driver\driver_interface     $db            The database.
	 * @param \phpbb\content_visibility             $visibility    Post visibility changes.
	 * @param \phpbb\notification\manager           $notifications Notifications.
	 * @param \phpbb\textformatter\utils_interface  $text_utils    Stored text → plain text.
	 * @param \phpbb\textformatter\parser_interface $parser        Plain text → stored text.
	 * @param \phpbb\log\log_interface              $log           The moderator and error logs.
	 * @param \phpbb\language\language              $language      For the soft-delete reason.
	 * @param string                                $posts_table   Table names.
	 * @param string                                $topics_table
	 * @param string                                $forums_table
	 * @param string                                $users_table
	 */
	public function __construct(
		\phpbb\db\driver\driver_interface $db,
		\phpbb\content_visibility $visibility,
		\phpbb\notification\manager $notifications,
		\phpbb\textformatter\utils_interface $text_utils,
		\phpbb\textformatter\parser_interface $parser,
		\phpbb\log\log_interface $log,
		\phpbb\language\language $language,
		$posts_table,
		$topics_table,
		$forums_table,
		$users_table
	)
	{
		$this->db = $db;
		$this->visibility = $visibility;
		$this->notifications = $notifications;
		$this->text_utils = $text_utils;
		$this->parser = $parser;
		$this->log = $log;
		$this->language = $language;
		$this->posts_table = $posts_table;
		$this->topics_table = $topics_table;
		$this->forums_table = $forums_table;
		$this->users_table = $users_table;
	}

	/**
	 * The words of a stored post, as the gateway should read them.
	 *
	 * Quotes are kept, each introduced by its author ("Kasia napisał(a):"): whatever a post
	 * publishes is assessed, and wrapping words in `[quote]` must not hide them. The rest
	 * loses its formatting (BBCode, links, smilies stay as their text), but the text of
	 * `title` and `alt` attributes is kept, after the text: words can hide there as well.
	 * The text is NOT shortened here.
	 *
	 * @param string $xml The stored text (`post_text`).
	 * @return string The plain text, trimmed.
	 */
	public function plain_text($xml)
	{
		$xml = (string) $xml;
		if ($xml === '')
		{
			return '';
		}
		$xml = (string) preg_replace_callback('#<QUOTE\b([^>]*)>#', function (array $m) {
			$author = preg_match('/\sauthor="([^"]*)"/', $m[1], $found)
				? trim(html_entity_decode($found[1], ENT_QUOTES | ENT_XML1, 'UTF-8')) : '';
			$line = ($author !== '') ? htmlspecialchars($author . self::QUOTE_AUTHOR_SUFFIX, ENT_NOQUOTES, 'UTF-8') . "\n" : '';
			return "\n" . $m[0] . $line;
		}, $xml);
		$xml = str_replace('</QUOTE>', "</QUOTE>\n", $xml);
		$text = (string) $this->text_utils->clean_formatting($xml);
		preg_match_all('/\s(?:title|alt)="([^"]*)"/', $xml, $found);
		foreach ($found[1] as $attribute)
		{
			$attribute = trim(html_entity_decode($attribute, ENT_QUOTES | ENT_XML1, 'UTF-8'));
			if ($attribute !== '')
			{
				$text .= "\n" . $attribute;
			}
		}
		$text = str_replace(array("\r\n", "\r"), "\n", $text);
		$trimmed = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $text);
		return is_string($trimmed) ? $trimmed : trim($text);
	}

	/**
	 * The first {@see MAX_CHARS} characters of a plain text, the part the gateway assesses.
	 *
	 * @param string $text A plain text.
	 * @return string
	 */
	public static function first_chars($text)
	{
		if (mb_strlen($text, 'UTF-8') <= self::MAX_CHARS)
		{
			return $text;
		}
		return rtrim(mb_substr($text, 0, self::MAX_CHARS, 'UTF-8'));
	}

	/**
	 * Whether the gateway's masked text can replace the post's text as it is.
	 *
	 * Only when (a) it is the text sent with some characters replaced by `█` - the same
	 * length in characters, every other character unchanged - and (b) the text sent is exactly
	 * what the author wrote: no BBCode, quote or attribute text was transformed on the way out,
	 * so publishing the masked text changes nothing but the masked characters. Anything else
	 * is for a moderator to apply by hand.
	 *
	 * @param array<string,mixed> $post   A row from {@see load_post}.
	 * @param string              $masked The gateway's `ocenzurowany`.
	 * @return bool
	 */
	public function mask_fits(array $post, $masked)
	{
		$sent = self::first_chars($this->plain_text((string) $post['post_text']));
		$raw = str_replace(array("\r\n", "\r"), "\n", (string) $this->text_utils->unparse((string) $post['post_text']));
		$trimmed = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $raw);
		if ($sent === '' || $sent !== (is_string($trimmed) ? $trimmed : trim($raw)))
		{
			return false;
		}
		$sent_chars = preg_split('//u', $sent, -1, PREG_SPLIT_NO_EMPTY);
		$masked_chars = preg_split('//u', (string) $masked, -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($sent_chars) || !is_array($masked_chars) || count($sent_chars) !== count($masked_chars))
		{
			return false;
		}
		foreach ($masked_chars as $i => $char)
		{
			if ($char !== $sent_chars[$i] && $char !== self::MASK)
			{
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a plain text is longer than the part the gateway assesses.
	 *
	 * @param string $text A plain text.
	 * @return bool
	 */
	public static function is_cut($text)
	{
		return mb_strlen($text, 'UTF-8') > self::MAX_CHARS;
	}

	/**
	 * The spam signals the gateway may receive about a post, and nothing else: the number of
	 * links, up to ten registrable domains they point to and, for a registered author,
	 * whether this is the author's first post. Never an e-mail, an IP address or a user id.
	 *
	 * @param array<string,mixed> $post A row from {@see load_post}.
	 * @return array<string,int|bool|array<int,string>>
	 */
	public function meta(array $post)
	{
		$xml = (string) $post['post_text'];
		$domains = array();
		preg_match_all('#<URL\s[^>]*?url="([^"]*)"#', $xml, $found);
		foreach ($found[1] as $url)
		{
			$domain = self::registrable_domain((string) parse_url(html_entity_decode($url, ENT_QUOTES | ENT_XML1, 'UTF-8'), PHP_URL_HOST));
			if ($domain !== null && count($domains) < self::MAX_LINK_DOMAINS)
			{
				$domains[$domain] = $domain;
			}
		}
		$meta = array(
			'links'        => min(self::MAX_LINKS, (int) preg_match_all('#<URL[\s>]#', $xml)),
			'link_domains' => array_values($domains),
		);
		if ((int) $post['poster_id'] !== ANONYMOUS && isset($post['user_posts']))
		{
			// The post itself is unapproved, so it is not counted in user_posts yet.
			$meta['author_first_post'] = ((int) $post['user_posts'] === 0);
		}
		return $meta;
	}

	/**
	 * The registrable domain of a host name: `forum.example.com` → `example.com`,
	 * `www.example.co.uk` → `example.co.uk` (see {@see SECOND_LEVEL_LABELS}).
	 *
	 * @param string $host A host name.
	 * @return string|null The domain, or null for an IP address, a single label or a name
	 *     that is not plain ASCII.
	 */
	public static function registrable_domain($host)
	{
		$host = rtrim(strtolower(trim((string) $host)), '.');
		if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false
			|| !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $host))
		{
			return null;
		}
		$labels = explode('.', $host);
		$count = count($labels);
		$take = ($count >= 3 && strlen($labels[$count - 1]) === 2 && in_array($labels[$count - 2], self::SECOND_LEVEL_LABELS, true)) ? 3 : 2;
		return implode('.', array_slice($labels, -$take));
	}

	/**
	 * A post with the topic, forum and author data the operations below need.
	 *
	 * @param int $post_id The post.
	 * @return array<string,mixed>|null Null when the post does not exist.
	 */
	public function load_post($post_id)
	{
		$sql = 'SELECT p.*, t.topic_title, t.topic_first_post_id, t.topic_last_post_id, t.topic_time,
				t.topic_last_post_time, t.topic_posts_approved, f.forum_name, u.username, u.user_posts
			FROM ' . $this->posts_table . ' p
			INNER JOIN ' . $this->topics_table . ' t ON t.topic_id = p.topic_id
			LEFT JOIN ' . $this->forums_table . ' f ON f.forum_id = p.forum_id
			LEFT JOIN ' . $this->users_table . ' u ON u.user_id = p.poster_id
			WHERE p.post_id = ' . (int) $post_id;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row ? $row : null;
	}

	/**
	 * Whether a post is in the approval queue: new (unapproved) or edited after publication
	 * (phpBB's re-approve state).
	 *
	 * @param array<string,mixed> $post A row from {@see load_post}.
	 * @return bool
	 */
	public function awaits_approval(array $post)
	{
		return in_array((int) $post['post_visibility'], array(ITEM_UNAPPROVED, ITEM_REAPPROVE), true);
	}

	/**
	 * Approves a post held in the queue, as a moderator's approval would, and sends the
	 * notifications phpBB withheld while it waited - those `submit_post()` sends for an approved
	 * post: the topic's, or for a reply the bookmark, post and forum ones, and the quote one.
	 * The author is not notified: from their side the post simply appears.
	 *
	 * phpBB's `content_visibility` sends no notification of its own, so these are the only
	 * ones; a post that is no longer in the queue was approved on phpBB's own path (the MCP),
	 * which notified already, and is left alone.
	 *
	 * @param array<string,mixed> $post A row from {@see load_post}.
	 * @param int                 $time The moment, stored by phpBB with the change.
	 * @return bool False when the post was not in the queue (nothing done).
	 */
	public function approve(array $post, $time)
	{
		if (!$this->awaits_approval($post))
		{
			return false;
		}
		$this->set_visibility(ITEM_APPROVED, $post, '', $time);

		// A post back from the re-approve state was announced when first published: as in the
		// MCP, only a first publication notifies the watchers.
		$first_publication = ((int) $post['post_visibility'] === ITEM_UNAPPROVED);
		if (!(int) $post['topic_posts_approved'])
		{
			$this->notifications->delete_notifications('notification.type.topic_in_queue', (int) $post['topic_id']);
			if ($first_publication)
			{
				$this->notifications->add_notifications(array('notification.type.topic'), $post);
			}
		}
		else if ($first_publication)
		{
			$this->notifications->add_notifications(array(
				'notification.type.bookmark',
				'notification.type.post',
				'notification.type.forum',
			), $post);
		}
		$this->notifications->add_notifications(array('notification.type.quote'), $post);
		$this->notifications->delete_notifications('notification.type.post_in_queue', (int) $post['post_id']);
		return true;
	}

	/**
	 * Soft-deletes a post.
	 *
	 * phpBB's `content_visibility` accounts only an approved → deleted change: applied to an
	 * unapproved post, it would lower post counts that were never raised. A post held in the
	 * queue is therefore approved and then deleted, without any notification; the caller runs
	 * both in one transaction, so no reader sees it approved.
	 *
	 * @param array<string,mixed> $post A row from {@see load_post}.
	 * @param int                 $time The moment, stored by phpBB with the change.
	 * @return void
	 */
	public function soft_delete(array $post, $time)
	{
		$this->language->add_lang('common', 'minos/moderation');
		if ($this->awaits_approval($post))
		{
			$this->set_visibility(ITEM_APPROVED, $post, '', $time);
		}
		$this->set_visibility(ITEM_DELETED, $post, $this->language->lang('MINOS_DELETE_REASON'), $time);

		$this->notifications->delete_notifications('notification.type.topic_in_queue', (int) $post['topic_id']);
		$this->notifications->delete_notifications('notification.type.post_in_queue', (int) $post['post_id']);
	}

	/**
	 * Puts a published post back into the approval queue (phpBB's "re-approve" state, which
	 * the MCP queue lists), and tells the moderators as phpBB does for an edited post.
	 *
	 * @param array<string,mixed> $post A row from {@see load_post}.
	 * @param int                 $time The moment, stored by phpBB with the change.
	 * @return void
	 */
	public function return_to_queue(array $post, $time)
	{
		$this->set_visibility(ITEM_REAPPROVE, $post, '', $time);
		$first = (int) $post['post_id'] <= (int) $post['topic_first_post_id'];
		$this->notifications->add_notifications($first ? 'notification.type.topic_in_queue' : 'notification.type.post_in_queue', $post);
	}

	/**
	 * Whether a post the extension approved by its failure mode is still exactly as it left
	 * it: approved by the extension at that moment (phpBB records who changed a post's
	 * visibility and when, so a moderator's deletion, restoration or approval shows), with the
	 * text it approved (an edit shows).
	 *
	 * @param array<string,mixed> $post A row from {@see load_post}.
	 * @param int                 $time The moment of the extension's approval.
	 * @param string              $md5  The MD5 of the stored text it approved.
	 * @return bool
	 */
	public function untouched_since_approval(array $post, $time, $md5)
	{
		return (int) $post['post_visibility'] === ITEM_APPROVED
			&& (int) $post['post_delete_user'] === ANONYMOUS
			&& (int) $post['post_delete_time'] === (int) $time
			&& hash_equals((string) $md5, md5((string) $post['post_text']));
	}

	/**
	 * Replaces a post's text with a plain text (the gateway's `ocenzurowany`).
	 *
	 * The text is parsed with BBCode, smilies and automatic links switched off, so what the
	 * gateway returned is shown exactly as returned. The update is checked and the stored
	 * text read back: a caller publishes only a text that is really there.
	 *
	 * @param array<string,mixed> $post  A row from {@see load_post}.
	 * @param string              $plain The new text.
	 * @return array<string,mixed>|null The post with its new text, or null when the stored
	 *     text is not the new one (the caller rolls back and must not publish).
	 */
	public function replace_text(array $post, $plain)
	{
		$this->parser->disable_bbcodes();
		$this->parser->disable_smilies();
		$this->parser->disable_magic_url();
		$xml = (string) $this->parser->parse((string) $plain);
		$this->parser->enable_bbcodes();
		$this->parser->enable_smilies();
		$this->parser->enable_magic_url();

		$updated = $this->db->sql_query('UPDATE ' . $this->posts_table . ' SET ' . $this->db->sql_build_array('UPDATE', array(
			'post_text'       => $xml,
			'bbcode_uid'      => '',
			'bbcode_bitfield' => '',
			'post_checksum'   => md5((string) $plain),
		)) . ' WHERE post_id = ' . (int) $post['post_id']);
		if ($updated === false || $xml === '')
		{
			return null;
		}

		$result = $this->db->sql_query('SELECT post_text FROM ' . $this->posts_table . ' WHERE post_id = ' . (int) $post['post_id']);
		$stored = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$stored || (string) $stored['post_text'] !== $xml)
		{
			return null;
		}

		$post['post_text'] = $xml;
		return $post;
	}

	/**
	 * Writes what the extension did with a post into the moderator log.
	 *
	 * @param array<string,mixed> $post      A row from {@see load_post}.
	 * @param string              $operation A `LOG_MINOS_*` language key.
	 * @return void
	 */
	public function log_decision(array $post, $operation)
	{
		$this->log->add('mod', ANONYMOUS, '', $operation, false, array(
			'forum_id' => (int) $post['forum_id'],
			'topic_id' => (int) $post['topic_id'],
			'post_id'  => (int) $post['post_id'],
			(string) $post['post_subject'],
		));
	}

	/**
	 * Writes a refusal of the gateway into the error log: the status and the code only, never
	 * the key, the text or the response body.
	 *
	 * @param int    $status The HTTP status.
	 * @param string $code   A safe label.
	 * @return void
	 */
	public function log_refusal($status, $code)
	{
		$this->log->add('critical', ANONYMOUS, '', 'LOG_MINOS_GATEWAY_REFUSED', false, array((int) $status, (string) $code));
	}

	/**
	 * Changes one post's visibility with the first/last-post flags the MCP would compute.
	 *
	 * @param int                 $visibility ITEM_APPROVED, ITEM_DELETED or ITEM_REAPPROVE.
	 * @param array<string,mixed> $post       A row from {@see load_post}.
	 * @param string              $reason     The reason stored with a deletion.
	 * @param int                 $time       The moment.
	 * @return void
	 */
	protected function set_visibility($visibility, array $post, $reason, $time)
	{
		$post_id = (int) $post['post_id'];
		$is_starter = $post_id <= (int) $post['topic_first_post_id'] || (int) $post['post_time'] <= (int) $post['topic_time'];
		$is_latest = $post_id >= (int) $post['topic_last_post_id'] || (int) $post['post_time'] >= (int) $post['topic_last_post_time'];
		$this->visibility->set_post_visibility($visibility, array($post_id), (int) $post['topic_id'], (int) $post['forum_id'],
			ANONYMOUS, (int) $time, $reason, $is_starter, $is_latest);
	}
}
