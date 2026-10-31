<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 INPERITIA
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

	/** phpBB's `GROUP_SPECIAL` (`includes/constants.php`): a group phpBB itself created. */
	const GROUP_SPECIAL = 3;

	/**
	 * phpBB's `GROUP_OPEN` and `GROUP_FREE`: groups a member may leave by himself in the UCP
	 * (`ucp_groups`, "resign", refused for any other type), and join (`GROUP_FREE` without
	 * approval).
	 */
	const GROUPS_OPEN = array(0, 4);

	/** phpBB's notification types that tell moderators a post or topic waits for approval. */
	const QUEUE_NOTIFICATIONS = array('notification.type.topic_in_queue', 'notification.type.post_in_queue');

	/** phpBB's notification method that only lists a notification on the board (no e-mail). */
	const BOARD_METHOD = 'notification.method.board';

	/** The gateway's mask character. */
	const MASK = '█';

	/** What introduces a quote's author in the text sent (content, in Polish). */
	const QUOTE_AUTHOR_SUFFIX = ' napisał(a):';

	/** Elements of phpBB's stored XML that hold the markup as typed (`[b]`, `[/b]`), not text. */
	const MARKUP_ELEMENTS = array('s', 'e');

	/**
	 * Elements of phpBB's stored XML a reader sees as an image, a video or a file, not as
	 * text: an image (`[img]`, also `[img=WxH]`), an inline attachment, a Flash video. The
	 * text inside them is an address or a file name the reader does not read (the plugins'
	 * shared rule F7): it is not text to assess.
	 */
	const MEDIA_ELEMENTS = array('IMG', 'ATTACHMENT', 'FLASH');

	/**
	 * What a reader does not see on its own (as plugin-wordpress's and plugin-mybb's
	 * `Text::INVISIBLE`): white space and separators (`\p{Z}`: U+3000, U+2028, ...), format
	 * characters (`\p{Cf}`: U+200B, U+00AD, U+2060, U+200E, U+FEFF, ...), controls
	 * (`\p{Cc}`), marks without a base letter (`\p{M}`: U+034F, U+FE0F; real text keeps its
	 * base letters, so "e" + U+0301 stays text), and the blank fillers some scripts carry (the
	 * Hangul fillers U+115F, U+1160, U+3164, U+FFA0 and the blank Braille pattern U+2800).
	 */
	const INVISIBLE = '/[\s\p{Z}\p{Cf}\p{Cc}\p{M}\x{115F}\x{1160}\x{3164}\x{FFA0}\x{2800}]+/u';

	/** Elements of phpBB's stored XML that a reader sees on lines of their own. */
	const BLOCK_ELEMENTS = array('QUOTE', 'LIST', 'LI', 'CODE', 'br', 'p');

	/** Marks a block's edge while a text is read; never in phpBB's XML (not an XML character). */
	const BREAK_MARK = "\x00";

	/** What separates a title from the post's text in the text sent: a blank line. */
	const TITLE_SEPARATOR = "\n\n";

	/** What phpBB puts before the topic's title in a reply's default subject. */
	const REPLY_PREFIX = 'Re: ';

	/** A link in phpBB's stored XML (a `[url]` or an automatic link). */
	const LINK_PATTERN = '#<URL[\s>]#';

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

	/** @var string */
	protected $groups_table;

	/** @var string */
	protected $user_group_table;

	/** @var string */
	protected $poll_options_table;

	/** @var \phpbb\report\handler_factory */
	protected $reports;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\auth\auth */
	protected $auth;

	/** @var string */
	protected $reports_reasons_table;

	/** @var int The post the extension is reporting right now ({@see report_support}), or 0. */
	protected $reporting = 0;

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
	 * @param string                                $groups_table
	 * @param string                                $user_group_table
	 * @param string                                $poll_options_table
	 * @param \phpbb\report\handler_factory         $reports       phpBB's reports (the MCP's "Reported posts").
	 * @param \phpbb\user                           $user          The request's user, whom phpBB's reports name.
	 * @param \phpbb\auth\auth                      $auth          The request's permissions, which a report reloads.
	 * @param string                                $reports_reasons_table
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
		$users_table,
		$groups_table,
		$user_group_table,
		$poll_options_table,
		\phpbb\report\handler_factory $reports,
		\phpbb\user $user,
		\phpbb\auth\auth $auth,
		$reports_reasons_table
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
		$this->groups_table = $groups_table;
		$this->user_group_table = $user_group_table;
		$this->poll_options_table = $poll_options_table;
		$this->reports = $reports;
		$this->user = $user;
		$this->auth = $auth;
		$this->reports_reasons_table = $reports_reasons_table;
	}

	/**
	 * The words of a stored post, as the gateway should read them: as a reader sees them.
	 *
	 * Read from phpBB's stored XML here, not with phpBB's `clean_formatting()`: that one puts
	 * a space where every BBCode tag sat, so `zak[b]a[/b]zane` would reach the gateway as
	 * "zak a zane" while the reader sees one word (tests/fixtures/phpbb-3.3/). Inline
	 * formatting (bold, colour, size, links, ...) adds nothing between the words; a block
	 * (a quote, a list and its items, code, a line break) starts and ends a line.
	 *
	 * Quotes are kept, each introduced by its author ("Kasia napisał(a):"): whatever a post
	 * publishes is assessed, and wrapping words in `[quote]` must not hide them. A link and a
	 * smiley stay as the text a reader sees (a bare address too); a link with a blank label
	 * adds nothing. An image, an inline attachment or a video adds no text: the address or
	 * file name inside it is not what a reader reads (the plugins' shared rule F7), so a post
	 * of only such things has no text to assess. The text of `title` and `alt` attributes is
	 * kept, after the text, when there is other text: words can hide there as well, but they
	 * never make a text to assess alone. A stored text that is not phpBB's XML is read with
	 * its markup, so that nothing in it is hidden. The text is NOT shortened here.
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
		$dom = new \DOMDocument();
		$errors = libxml_use_internal_errors(true);
		$loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
		libxml_clear_errors();
		libxml_use_internal_errors($errors);
		if (!$loaded || $dom->documentElement === null || $dom->doctype !== null)
		{
			$text = html_entity_decode(strip_tags($xml), ENT_QUOTES, 'UTF-8');
		}
		else
		{
			$attributes = array();
			$text = $this->node_text($dom->documentElement, $attributes);
			// A block boundary is one line break, unless the author's own line breaks are there.
			// (The pattern names the mark as \x00: PHP before 8.2 refuses a NUL byte in a pattern.)
			$text = (string) preg_replace_callback('/[\x00\n]+/', function (array $m) {
				$lines = substr_count($m[0], "\n");
				return str_repeat("\n", max(1, $lines));
			}, $text);
			// A title or alt goes with the text it sits beside, never alone (F7).
			if (!self::is_blank($text))
			{
				foreach ($attributes as $attribute)
				{
					$text .= "\n" . $attribute;
				}
			}
		}
		$text = str_replace(array("\r\n", "\r"), "\n", $text);
		if (self::is_blank($text))
		{
			// Only characters a reader does not see: no text to assess (F7).
			return '';
		}
		$trimmed = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $text);
		return is_string($trimmed) ? $trimmed : trim($text);
	}

	/**
	 * Whether a text holds nothing a reader sees: only characters in {@see INVISIBLE}, which
	 * do not make a text to assess (the plugins' shared rule F7). A text the pattern cannot
	 * read (not UTF-8) is not blank: more text, never less.
	 *
	 * @param string $text A text.
	 * @return bool
	 */
	public static function is_blank($text)
	{
		return preg_replace(self::INVISIBLE, '', (string) $text) === '';
	}

	/**
	 * The text of an element of phpBB's stored XML, with a {@see BREAK_MARK} at each block's
	 * edges; collects the `title` and `alt` attributes on the way, in document order.
	 *
	 * @param \DOMNode         $node       The element.
	 * @param array<int,string> $attributes The attributes' texts found so far.
	 * @return string
	 */
	protected function node_text(\DOMNode $node, array &$attributes)
	{
		$text = '';
		foreach ($node->childNodes as $child)
		{
			if ($child instanceof \DOMText)
			{
				$text .= $child->data;
				continue;
			}
			if (!$child instanceof \DOMElement || in_array($child->nodeName, self::MARKUP_ELEMENTS, true))
			{
				continue;
			}
			foreach ($child->attributes as $attribute)
			{
				$value = in_array($attribute->nodeName, array('title', 'alt'), true) ? trim($attribute->nodeValue) : '';
				if ($value !== '')
				{
					$attributes[] = $value;
				}
			}
			$inner = $this->node_text($child, $attributes);
			if (in_array($child->nodeName, self::MEDIA_ELEMENTS, true))
			{
				// Seen as an image, a file or a video; its own title or alt were collected above.
				continue;
			}
			if ($child->nodeName === 'QUOTE')
			{
				$author = trim($child->getAttribute('author'));
				$text .= self::BREAK_MARK . (($author !== '') ? $author . self::QUOTE_AUTHOR_SUFFIX . self::BREAK_MARK : '') . $inner . self::BREAK_MARK;
			}
			else if (in_array($child->nodeName, self::BLOCK_ELEMENTS, true))
			{
				$text .= self::BREAK_MARK . $inner . self::BREAK_MARK;
			}
			else
			{
				$text .= $inner;
			}
		}
		return $text;
	}

	/**
	 * The title a reader sees above a post, when it belongs in the text sent: the topic's
	 * title for the first post of a topic; for a reply, its own subject, only when the author
	 * wrote one (phpBB fills in "Re: " and the topic's title, which tells nothing new).
	 *
	 * phpBB stores titles HTML-escaped (and characters beyond the BMP as numeric entities);
	 * the title is decoded once, to what the reader sees.
	 *
	 * @param array<string,mixed> $post A row from {@see load_post}.
	 * @return string The title, trimmed, or '' for none.
	 */
	public function title_of(array $post)
	{
		return self::own_title(isset($post['topic_title']) ? (string) $post['topic_title'] : '',
			isset($post['post_subject']) ? (string) $post['post_subject'] : '',
			(int) $post['post_id'] === (int) $post['topic_first_post_id']);
	}

	/**
	 * {@see title_of} from the stored values, also before the post is stored: the topic's
	 * title for the first post of a topic; for a reply, its own subject unless it is phpBB's
	 * default ("Re: " and the topic's title) or the topic's title itself.
	 *
	 * @param string $topic_title The topic's title, as phpBB stores it.
	 * @param string $subject     The post's subject, as phpBB stores it.
	 * @param bool   $first_post  Whether the post opens the topic.
	 * @return string The title, decoded and trimmed, or '' for none.
	 */
	public static function own_title($topic_title, $subject, $first_post)
	{
		$topic_title = self::decoded_line((string) $topic_title);
		if ($first_post)
		{
			return $topic_title;
		}
		$subject = self::decoded_line((string) $subject);
		$default = ((strpos($topic_title, self::REPLY_PREFIX) === 0) ? '' : self::REPLY_PREFIX) . $topic_title;
		return ($subject === $default || $subject === $topic_title) ? '' : $subject;
	}

	/**
	 * The poll a post carries, as a reader sees it: its question, then each option on a line of
	 * its own (the plugins' shared rule D9.1: a poll's question and options are part of the text
	 * of the post that carries the poll). In phpBB the poll belongs to the topic and is edited
	 * with its first post, which carries it; the question and the options are stored as parsed
	 * text, as a post is.
	 *
	 * @param array<string,mixed> $post A row from {@see load_post}.
	 * @return string The poll, or '' for none.
	 */
	public function poll_of(array $post)
	{
		if ((int) $post['post_id'] !== (int) $post['topic_first_post_id'] || !isset($post['poll_title']) || (string) $post['poll_title'] === '')
		{
			return '';
		}
		$lines = array($this->plain_text((string) $post['poll_title']));
		$result = $this->db->sql_query('SELECT poll_option_text FROM ' . $this->poll_options_table . '
			WHERE topic_id = ' . (int) $post['topic_id'] . '
			ORDER BY poll_option_id');
		while ($row = $this->db->sql_fetchrow($result))
		{
			$lines[] = $this->plain_text((string) $row['poll_option_text']);
		}
		$this->db->sql_freeresult($result);
		return trim(implode("\n", array_filter($lines, 'strlen')));
	}

	/**
	 * The whole text the gateway reads for a post: its title ({@see title_of}), the poll it
	 * carries ({@see poll_of}) and the post's plain text ({@see plain_text}), a blank line
	 * between each two. A title alone is never sent: without text to assess in the post itself
	 * or its poll, the result is ''. The text is NOT shortened here.
	 *
	 * @param array<string,mixed> $post A row from {@see load_post}.
	 * @return string
	 */
	public function text_of(array $post)
	{
		$text = $this->plain_text((string) $post['post_text']);
		$poll = $this->poll_of($post);
		if (self::is_blank($text) && self::is_blank($poll))
		{
			return '';
		}
		return implode(self::TITLE_SEPARATOR, array_filter(array($this->title_of($post), $poll, $text), 'strlen'));
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
	 * @param array<string,mixed> $post   A row from {@see load_post}.
	 * @param string              $masked The gateway's `ocenzurowany`.
	 * @return bool
	 * @see masked_body
	 */
	public function mask_fits(array $post, $masked)
	{
		return $this->masked_body($post, $masked) !== null;
	}

	/**
	 * The post's new text when the gateway's masked text can replace it as it is, or null.
	 *
	 * Only when (a) it is the text sent ({@see text_of}, whole) with some characters replaced
	 * by `█` - the same length in characters, every other character unchanged; (b) the title
	 * and the poll sent with it are untouched: the extension never changes a title or a poll,
	 * so a mask in them is for a moderator; and (c) the post's text sent is exactly what the author wrote: no
	 * BBCode, quote or attribute text was transformed on the way out, so publishing the masked
	 * text changes nothing but the masked characters. Anything else is for a moderator to
	 * apply by hand.
	 *
	 * @param array<string,mixed> $post   A row from {@see load_post}.
	 * @param string              $masked The gateway's `ocenzurowany`.
	 * @return string|null The masked text without the title: what replaces the post's text.
	 */
	public function masked_body(array $post, $masked)
	{
		$body = $this->plain_text((string) $post['post_text']);
		$raw = str_replace(array("\r\n", "\r"), "\n", (string) $this->text_utils->unparse((string) $post['post_text']));
		$trimmed = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $raw);
		$text = $this->text_of($post);
		if ($body === '' || $body !== (is_string($trimmed) ? $trimmed : trim($raw)) || self::first_chars($text) !== $text)
		{
			return null;
		}
		$sent_chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
		$masked_chars = preg_split('//u', (string) $masked, -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($sent_chars) || !is_array($masked_chars) || count($sent_chars) !== count($masked_chars))
		{
			return null;
		}
		// Everything before the post's own text: the title, the poll and the blank lines.
		$title_chars = count($sent_chars) - mb_strlen($body, 'UTF-8');
		foreach ($masked_chars as $i => $char)
		{
			if ($char !== $sent_chars[$i] && ($char !== self::MASK || $i < $title_chars))
			{
				return null;
			}
		}
		return implode('', array_slice($masked_chars, $title_chars));
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
			'links'        => min(self::MAX_LINKS, (int) preg_match_all(self::LINK_PATTERN, $xml)),
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
	 * A one-line text as phpBB stores it (a subject: HTML-escaped, numeric entities for
	 * characters beyond the BMP), decoded once and trimmed.
	 *
	 * @param string $stored The stored value.
	 * @return string
	 */
	protected static function decoded_line($stored)
	{
		$text = str_replace(array("\r\n", "\r", "\n"), ' ', html_entity_decode((string) $stored, ENT_QUOTES, 'UTF-8'));
		$trimmed = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $text);
		return is_string($trimmed) ? $trimmed : trim($text);
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
				t.topic_last_post_time, t.topic_posts_approved, t.poll_title, f.forum_name, u.username, u.user_posts
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
	 * The board's user groups, for the ACP, in the order phpBB's own group lists use: the
	 * special groups (guests, registered users, newly registered users, ...) first.
	 *
	 * @return array<int,array{group_id:int,group_name:string,special:bool,open:bool}>
	 */
	public function groups()
	{
		$groups = array();
		$result = $this->db->sql_query('SELECT group_id, group_name, group_type
			FROM ' . $this->groups_table . '
			ORDER BY group_type DESC, group_name ASC');
		while ($row = $this->db->sql_fetchrow($result))
		{
			$groups[] = array(
				'group_id'   => (int) $row['group_id'],
				'group_name' => (string) $row['group_name'],
				'special'    => (int) $row['group_type'] === self::GROUP_SPECIAL,
				'open'       => in_array((int) $row['group_type'], self::GROUPS_OPEN, true),
			);
		}
		$this->db->sql_freeresult($result);
		return $groups;
	}

	/**
	 * Whether a user belongs to one of some groups. Only an approved membership counts: phpBB
	 * keeps a request to join a group as a row with `user_pending = 1`. A guest belongs to the
	 * guests' group, as phpBB installs it.
	 *
	 * @param int            $user_id   The user.
	 * @param array<int,int> $group_ids The groups.
	 * @return bool|null Whether the user is a member; null when none of the groups exists any
	 *     more (deleted since they were chosen).
	 */
	public function in_groups($user_id, array $group_ids)
	{
		$group_ids = array_values(array_unique(array_map('intval', $group_ids)));
		if (!$group_ids)
		{
			return null;
		}
		$result = $this->db->sql_query('SELECT g.group_id, ug.user_id
			FROM ' . $this->groups_table . ' g
			LEFT JOIN ' . $this->user_group_table . ' ug
				ON (ug.group_id = g.group_id AND ug.user_id = ' . (int) $user_id . ' AND ug.user_pending = 0)
			WHERE ' . $this->db->sql_in_set('g.group_id', $group_ids));
		$exists = false;
		$member = false;
		while ($row = $this->db->sql_fetchrow($result))
		{
			$exists = true;
			$member = $member || ($row['user_id'] !== null);
		}
		$this->db->sql_freeresult($result);
		return $exists ? $member : null;
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
	 * Tells the moderators, once, that a post the extension leaves in the queue waits for them:
	 * phpBB's own "post in the queue" (or "topic in the queue") notification with its e-mail.
	 *
	 * While the extension assessed the post, phpBB's notification of its posting reached the
	 * board only ({@see \minos\moderation\event\listener::hold_back_queue_messages}); it is
	 * withdrawn and sent again, so phpBB - which skips a user it already notified about the
	 * item - delivers it by every method each moderator chose. Callers do this once per post
	 * (`pending_store::mark_announced`): a second time would withdraw the board notice of a
	 * moderator phpBB then skips for the e-mail it remembers.
	 *
	 * @param array<string,mixed> $post A row from {@see load_post}.
	 * @return void
	 */
	public function notify_queue(array $post)
	{
		$first = (int) $post['post_id'] <= (int) $post['topic_first_post_id'];
		$type = $first ? self::QUEUE_NOTIFICATIONS[0] : self::QUEUE_NOTIFICATIONS[1];
		$this->notifications->delete_notifications($type, $first ? (int) $post['topic_id'] : (int) $post['post_id']);
		$this->notifications->add_notifications($type, $post);
	}

	/**
	 * Reports a published post that carries `wsparcie` to its moderators: a report in the MCP's
	 * "Reported posts", with phpBB's own notification of it (the plugins' shared rule A7: an
	 * active signal in the moderators' own queue; the post itself stays published).
	 *
	 * Filed through phpBB's report handler (`phpbb.report.handler_factory`, the post handler's
	 * `add_report()`), with the reason "other" (which phpBB never lets an administrator delete)
	 * and the extension's text, never the post's: phpBB itself keeps a copy of the post as
	 * reported, as for every report. The reporter is the guest (`ANONYMOUS`): phpBB names the
	 * request's user, so for a request of someone else (the cron task run by a member's page
	 * view, the CLI) the user's data is the guest's for the call and restored after it, with the
	 * permissions phpBB reloads for the report. phpBB checks the reporter's `f_list`, `f_read`
	 * and `f_report` on the forum, which a guest lacks by default: the extension's listener
	 * clears that check for this one report ({@see reporting}, `core.report_post_auth`).
	 *
	 * A post that already has an open report (phpBB refuses a second one) is in the moderators'
	 * reports already, and is not reported again. Any other refusal (the post gone, a check
	 * another extension adds on `core.report_post_auth`) loses the signal: the error log says so
	 * ({@see log_report_refusal}).
	 *
	 * @param array<string,mixed> $post    A row from {@see load_post}.
	 * @param string              $verdict The gateway's verdict, named in the report.
	 * @param bool                $late    Whether the verdict came after the failure mode
	 *     published the post.
	 * @return bool Whether a report was filed.
	 */
	public function report_support(array $post, $verdict, $late)
	{
		$post_id = (int) $post['post_id'];
		$result = $this->db->sql_query('SELECT reason_id FROM ' . $this->reports_reasons_table . " WHERE LOWER(reason_title) = 'other'");
		$reason = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		$result = $this->db->sql_query('SELECT * FROM ' . $this->users_table . ' WHERE user_id = ' . (int) ANONYMOUS);
		$guest = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$reason || !$guest || $post_id <= 0)
		{
			return false;
		}
		$this->language->add_lang('common', 'minos/moderation');
		$text = $this->language->lang($late ? 'MINOS_SUPPORT_REPORT_LATE' : 'MINOS_SUPPORT_REPORT', (string) $verdict);

		$saved = $this->user->data;
		$as_guest = (!isset($saved['user_id'], $saved['user_permissions']) || (int) $saved['user_id'] !== ANONYMOUS);
		if ($as_guest)
		{
			$this->user->data = $guest;
		}
		$this->reporting = $post_id;
		try
		{
			$this->reports->get_instance('post')->add_report($post_id, (int) $reason['reason_id'], $text, 0);
			return true;
		}
		catch (\phpbb\report\exception\already_reported_exception $e)
		{
			// An open report is in the moderators' queue already.
			return false;
		}
		catch (\phpbb\report\exception\invalid_report_exception $e)
		{
			$this->log_report_refusal($post_id, $e);
			return false;
		}
		finally
		{
			$this->reporting = 0;
			if ($as_guest)
			{
				$this->user->data = $saved;
				if (isset($saved['user_permissions']))
				{
					// add_report() loaded the guest's permissions into the request's.
					$this->auth->acl($this->user->data);
				}
			}
		}
	}

	/**
	 * Writes into the error log that a post with `wsparcie` could not be reported: the post's
	 * id and phpBB's code of the refusal (a language key such as `USER_CANNOT_REPORT`, or the
	 * exception's class when the message is not one), never the post's text.
	 *
	 * @param int                                          $post_id The post.
	 * @param \phpbb\report\exception\invalid_report_exception $e       phpBB's refusal.
	 * @return void
	 */
	protected function log_report_refusal($post_id, \phpbb\report\exception\invalid_report_exception $e)
	{
		$code = (string) $e->getMessage();
		if (!preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $code))
		{
			$parts = explode('\\', get_class($e));
			$code = (string) end($parts);
		}
		$this->log->add('critical', ANONYMOUS, '', 'LOG_MINOS_SUPPORT_REPORT_FAILED', false, array((int) $post_id, $code));
	}

	/**
	 * The post the extension is reporting right now ({@see report_support}), for the listener
	 * of `core.report_post_auth`.
	 *
	 * @return int The post id, or 0.
	 */
	public function reporting()
	{
		return $this->reporting;
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
	 * Writes into the error log that one post could not be handled: the step and the post id
	 * only, never an exception's message (it may carry SQL with the post's text).
	 *
	 * @param string $step    `submit`, `timeout` or `apply`.
	 * @param int    $post_id The post.
	 * @return void
	 */
	public function log_failure($step, $post_id)
	{
		$this->log->add('critical', ANONYMOUS, '', 'LOG_MINOS_ROW_FAILED', false, array((string) $step, (int) $post_id));
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
