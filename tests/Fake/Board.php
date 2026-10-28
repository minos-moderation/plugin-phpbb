<?php

namespace minos\moderation\tests\Fake;

use minos\moderation\controller\webhook;
use minos\moderation\cron\sweeper;
use minos\moderation\event\deferred_verdicts;
use minos\moderation\event\listener;
use minos\moderation\migrations\install_schema;
use minos\moderation\migrations\verdict_times;
use minos\moderation\platform\forum;
use minos\moderation\service\pending_store;
use minos\moderation\service\receiver;
use minos\moderation\service\settings;
use minos\moderation\service\submitter;
use minos\moderation\service\transport_interface;
use minos\moderation\service\verdict_applier;
use Minos\Client\Signature;
use Symfony\Component\HttpFoundation\Request;

/**
 * A phpBB board in miniature: SQLite tables shaped like phpBB's, the extension's services
 * wired as `config/services.yml` wires them, and the fakes of the phpBB services they use.
 * Every text in it is invented.
 */
class Board
{
	/** A key shaped like a real one. */
	const KEY = 'wgb2b_test_0123456789abcdef';

	/** The webhook secret the tests sign with. */
	const SECRET = 'test-webhook-secret-0123456789ab';

	/** The gateway the recording transport stands for. */
	const GATEWAY = 'https://gateway.example';

	/** The forum every post goes to unless a test says otherwise. */
	const FORUM = 2;

	/** A registered author. */
	const USER = 2;

	/** Her e-mail address and IP: they must never reach a request. */
	const USER_EMAIL = 'autorka@forum.example';
	const USER_IP = '198.51.100.23';

	/** A newly registered user (phpBB's NEWLY_REGISTERED group, besides REGISTERED). */
	const NEW_USER = 3;

	/** phpBB's special groups as it installs them (`install/schemas/schema_data.sql`). */
	const GROUP_GUESTS = 1;
	const GROUP_REGISTERED = 2;
	const GROUP_NEWLY_REGISTERED = 7;

	/** A group the administrator made; the author belongs to it. */
	const GROUP_TRUSTED = 10;

	/** phpBB's table prefix. */
	const PREFIX = 'phpbb_';

	/** @var SqliteDriver */
	public $db;

	/** @var \phpbb\config\config */
	public $config;

	/** @var \phpbb\config\db_text */
	public $config_text;

	/** @var settings */
	public $settings;

	/** @var pending_store */
	public $store;

	/** @var \phpbb\content_visibility */
	public $visibility;

	/** @var \phpbb\notification\manager */
	public $notifications;

	/** @var TextParser */
	public $parser;

	/** @var MemoryLog */
	public $log;

	/** @var \phpbb\language\language */
	public $language;

	/** @var forum */
	public $forum;

	/** @var transport_interface */
	public $transport;

	/** @var verdict_applier */
	public $applier;

	/** @var submitter */
	public $submitter;

	/** @var receiver */
	public $receiver;

	/** @var deferred_verdicts */
	public $deferred;

	/** @var RecordingDispatcher */
	public $dispatcher;

	/** @var \phpbb\auth\auth */
	public $auth;

	/** @var \phpbb\user The user who posts. */
	public $user;

	/** @var listener */
	public $listener;

	/** @var int */
	protected $next_topic = 100;

	/**
	 * A board on a database, with the extension set up and ready.
	 *
	 * @param string                   $path      A SQLite file, or ':memory:'.
	 * @param transport_interface|null $transport The gateway; a recording one by default.
	 * @param bool                     $install   Whether to create the tables and the seed data.
	 * @return self
	 */
	public static function open($path = ':memory:', ?transport_interface $transport = null, $install = true)
	{
		$board = new self();
		$board->db = new SqliteDriver($path);
		if ($install)
		{
			$board->install();
		}
		$board->config = new \phpbb\config\config();
		$board->config_text = new \phpbb\config\db_text();
		$board->configure();

		$board->settings = new settings($board->config, $board->config_text);
		$board->store = new pending_store($board->db, self::PREFIX . 'minos_pending');
		$board->visibility = new \phpbb\content_visibility($board->db, self::PREFIX . 'posts');
		$board->notifications = new \phpbb\notification\manager();
		$board->parser = new TextParser();
		$board->log = new MemoryLog();
		$board->language = new \phpbb\language\language(__DIR__ . '/../../language/pl');
		$board->forum = new forum($board->db, $board->visibility, $board->notifications, new TextUtils(), $board->parser,
			$board->log, $board->language, self::PREFIX . 'posts', self::PREFIX . 'topics', self::PREFIX . 'forums', self::PREFIX . 'users',
			self::PREFIX . 'groups', self::PREFIX . 'user_group');
		$board->transport = $transport ?: new RecordingTransport();
		$board->applier = new verdict_applier($board->settings, $board->store, $board->forum, $board->db);
		$board->submitter = new submitter($board->settings, $board->store, $board->forum, $board->transport, $board->applier);
		$board->receiver = new receiver($board->settings, $board->store);
		$board->deferred = new deferred_verdicts($board->applier, $board->forum);
		$board->dispatcher = new RecordingDispatcher();
		$board->auth = new \phpbb\auth\auth();
		$board->auth->global['f_noapprove'] = true;
		$board->user = new \phpbb\user();
		$board->listener = new listener($board->settings, $board->store, $board->forum, $board->submitter, $board->auth, $board->language, $board->user);
		// phpBB's dispatcher runs the extension's listener inside the notification manager.
		$board->notifications->on_add = static function (\phpbb\event\data $event) use ($board) {
			$events = listener::getSubscribedEvents();
			$method = $events['core.notification_manager_add_notifications'];
			$board->listener->$method($event);
		};
		return $board;
	}

	/**
	 * Sets the extension's settings: ready, with the defaults of a new installation, and the
	 * given values over them.
	 *
	 * @param array<string,mixed>  $config      `config` values.
	 * @param array<string,string> $config_text `config_text` values.
	 * @return void
	 */
	public function configure(array $config = array(), array $config_text = array())
	{
		foreach ($config + array(
			settings::ENABLED     => 1,
			settings::GATEWAY_URL => self::GATEWAY,
			settings::PROFILE     => 'forum_adult',
			settings::FAIL_MODE   => settings::DEFAULT_FAIL_MODE,
			settings::TIMEOUT_MIN => settings::DEFAULT_TIMEOUT_MIN,
			settings::CENSORED    => settings::CENSORED_PUBLISH,
			settings::BLOCKED     => settings::HOLD,
			settings::LAST_ERROR  => '',
			settings::LAST_SWEEP  => 0,
		) as $key => $value)
		{
			$this->config->set($key, $value);
		}
		foreach ($config_text + array(
			settings::API_KEY        => self::KEY,
			settings::WEBHOOK_SECRET => self::SECRET,
			settings::FORUMS         => '',
		) as $key => $value)
		{
			$this->config_text->set($key, $value);
		}
		if ($this->settings !== null)
		{
			// A fresh reader: the settings object caches config_text per request.
			$this->settings = new settings($this->config, $this->config_text);
			$this->rewire();
		}
	}

	/**
	 * phpBB's posting flow for a new topic or a reply: the "before" event, `submit_post()`
	 * storing the post with the visibility it ends up with, and the "end" event.
	 *
	 * @param string $text    The post's plain text (stored the way the parser stores it).
	 * @param string $mode    `post`, `reply` or `quote`.
	 * @param array  $options forum_id, poster_id (also the current user), topic_id (for a reply), xml (a stored text),
	 *     data (phpBB's post data as other extensions left it), title (of a new topic) or
	 *     subject (of a reply), both as phpBB stores them (HTML-escaped).
	 * @return array{post_id:int,visibility:int,data:array} The post and what phpBB stored.
	 */
	public function posting($text, $mode = 'post', array $options = array())
	{
		$forum_id = isset($options['forum_id']) ? $options['forum_id'] : self::FORUM;
		$xml = isset($options['xml']) ? $options['xml'] : $this->xml($text);
		// A new post is always the current user's.
		$this->user->data['user_id'] = isset($options['poster_id']) ? (int) $options['poster_id'] : self::USER;
		// What posting.php passes: the subject typed (`post_data`), and the topic's title, or a
		// new topic's subject (`data`).
		if (empty($options['topic_id']))
		{
			$options['title'] = isset($options['title']) ? $options['title'] : 'Temat testowy ' . $this->next_topic;
			$topic_title = $options['title'];
			$subject = $options['title'];
		}
		else
		{
			$topic_title = $this->topic_title((int) $options['topic_id']);
			$options['subject'] = isset($options['subject']) ? $options['subject'] : 'Re: ' . $topic_title;
			$subject = $options['subject'];
		}
		$before = new \phpbb\event\data(array(
			'mode'      => $mode,
			'data'      => (isset($options['data']) ? $options['data'] : array()) + array('message' => $xml, 'forum_id' => $forum_id, 'topic_title' => $topic_title),
			'post_data' => array('post_subject' => $subject),
			'forum_id'  => $forum_id,
			'post_id'   => 0,
			'topic_id'  => isset($options['topic_id']) ? $options['topic_id'] : 0,
		));
		$this->listener->hold_for_assessment($before);
		$data = $before['data'];

		if (isset($data['force_approved_state']))
		{
			$visibility = (int) $data['force_approved_state'];
		}
		else
		{
			$visibility = $this->auth->acl_get('f_noapprove', $forum_id) ? ITEM_APPROVED : ITEM_UNAPPROVED;
		}
		$post_id = $this->write_post($xml, $visibility, $options + array('forum_id' => $forum_id));

		$data['post_id'] = $post_id;
		// What submit_post() then sends for a post in the queue, with the post data the
		// "before" event left (the extension's flag included).
		if ($visibility === ITEM_UNAPPROVED)
		{
			$this->notifications->submit_post_notification(($mode === 'post') ? 'notification.type.topic_in_queue' : 'notification.type.post_in_queue',
				$data + array('topic_id' => (int) $this->post($post_id)['topic_id']));
		}
		$this->listener->send_for_assessment(new \phpbb\event\data(array(
			'mode'            => $mode,
			'data'            => $data,
			'post_visibility' => $visibility,
		)));
		return array('post_id' => $post_id, 'visibility' => $visibility, 'data' => $data);
	}

	/**
	 * phpBB's posting flow for an edit.
	 *
	 * @param int         $post_id The post.
	 * @param string      $text    Its new plain text.
	 * @param string|null $title   Its new subject (as stored); for a topic's first post also
	 *     the topic's title, as phpBB changes both.
	 * @return void
	 */
	public function edit($post_id, $text, $title = null)
	{
		$post = $this->post($post_id);
		$before = new \phpbb\event\data(array(
			'mode'     => 'edit',
			'data'     => array('message' => $this->xml($text), 'forum_id' => (int) $post['forum_id'], 'post_id' => $post_id),
			'forum_id' => (int) $post['forum_id'],
			'post_id'  => $post_id,
			'topic_id' => (int) $post['topic_id'],
		));
		$this->listener->hold_for_assessment($before);
		$data = $before['data'];
		$visibility = isset($data['force_approved_state']) ? (int) $data['force_approved_state'] : (int) $post['post_visibility'];
		$this->db->sql_query('UPDATE ' . self::PREFIX . 'posts SET ' . $this->db->sql_build_array('UPDATE', array(
			'post_text'       => $this->xml($text),
			'post_visibility' => $visibility,
		) + (($title !== null) ? array('post_subject' => $title) : array())) . ' WHERE post_id = ' . (int) $post_id);
		if ($title !== null)
		{
			$this->db->sql_query('UPDATE ' . self::PREFIX . "topics SET topic_title = '" . $this->db->sql_escape($title) . "'
				WHERE topic_first_post_id = " . (int) $post_id);
		}
		// submit_post() for an edit that puts a published post back into the queue.
		if ($visibility === ITEM_REAPPROVE && (int) $post['post_visibility'] !== ITEM_REAPPROVE)
		{
			$first = (int) $post_id === (int) $this->first_post((int) $post['topic_id']);
			$this->notifications->submit_post_notification($first ? 'notification.type.topic_in_queue' : 'notification.type.post_in_queue',
				$data + array('topic_id' => (int) $post['topic_id']));
		}
		$this->listener->send_for_assessment(new \phpbb\event\data(array(
			'mode'            => 'edit',
			'data'            => $data,
			'post_visibility' => $visibility,
		)));
	}

	/**
	 * A delivery through the webhook controller, then what `app.php` does after the answer.
	 *
	 * @param array<string,mixed>|string $payload The payload, or an exact body.
	 * @param array<string,mixed>        $options secret, t (signing time), header (a whole
	 *     header value instead of a signature).
	 * @return int The HTTP status.
	 */
	public function deliver($payload, array $options = array())
	{
		$body = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$header = isset($options['header']) ? $options['header'] : Signature::sign(
			isset($options['secret']) ? $options['secret'] : self::SECRET,
			$body,
			isset($options['t']) ? $options['t'] : time()
		);
		$controller = new webhook(new Request(array('X-Wergiliusz-Podpis' => $header), $body), $this->receiver, $this->deferred, $this->dispatcher);
		$status = $controller->handle()->getStatusCode();
		$this->dispatcher->terminate();
		return $status;
	}

	/**
	 * The cron task.
	 *
	 * @return sweeper
	 */
	public function sweeper()
	{
		$sweeper = new sweeper($this->config, $this->settings, $this->store, $this->submitter, $this->applier, $this->forum);
		$sweeper->set_name('cron.task.minos.moderation.sweeper');
		return $sweeper;
	}

	/**
	 * A stored post, as phpBB keeps it.
	 *
	 * @param int $post_id
	 * @return array<string,string|null>
	 */
	public function post($post_id)
	{
		$result = $this->db->sql_query('SELECT * FROM ' . self::PREFIX . 'posts WHERE post_id = ' . (int) $post_id);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row;
	}

	/**
	 * The first post of a topic.
	 *
	 * @param int $topic_id
	 * @return int
	 */
	public function first_post($topic_id)
	{
		$result = $this->db->sql_query('SELECT topic_first_post_id FROM ' . self::PREFIX . 'topics WHERE topic_id = ' . (int) $topic_id);
		$topic = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $topic ? (int) $topic['topic_first_post_id'] : 0;
	}

	/**
	 * The stored title of a topic (HTML-escaped, as phpBB stores it).
	 *
	 * @param int $topic_id
	 * @return string '' for no such topic.
	 */
	public function topic_title($topic_id)
	{
		$result = $this->db->sql_query('SELECT topic_title FROM ' . self::PREFIX . 'topics WHERE topic_id = ' . (int) $topic_id);
		$topic = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $topic ? (string) $topic['topic_title'] : '';
	}

	/**
	 * The stored title of a post's topic (HTML-escaped, as phpBB stores it).
	 *
	 * @param int $post_id
	 * @return string
	 */
	public function title($post_id)
	{
		$result = $this->db->sql_query('SELECT t.topic_title FROM ' . self::PREFIX . 'topics t
			INNER JOIN ' . self::PREFIX . 'posts p ON p.topic_id = t.topic_id WHERE p.post_id = ' . (int) $post_id);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return (string) $row['topic_title'];
	}

	/**
	 * The extension's row of a post.
	 *
	 * @param int $post_id
	 * @return array<string,string|null>|null
	 */
	public function row($post_id)
	{
		return $this->store->find($post_id);
	}

	/**
	 * A plain text stored the way phpBB's parser stores plain text.
	 *
	 * @param string $text
	 * @return string
	 */
	public function xml($text)
	{
		return '<t>' . str_replace("\n", "<br/>\n", htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8')) . '</t>';
	}

	/**
	 * Stores a post (and, for a new topic, its topic) as `submit_post()` would.
	 *
	 * A new topic's title is "Temat testowy <topic id>" unless `title` says otherwise; a reply's
	 * subject is phpBB's default, "Re: " and the topic's title, unless `subject` says otherwise.
	 *
	 * @param string $xml        The stored text.
	 * @param int    $visibility Its visibility.
	 * @param array  $options    forum_id, poster_id, topic_id, title, subject.
	 * @return int The post id.
	 */
	public function write_post($xml, $visibility, array $options = array())
	{
		$forum_id = isset($options['forum_id']) ? (int) $options['forum_id'] : self::FORUM;
		$poster_id = isset($options['poster_id']) ? (int) $options['poster_id'] : self::USER;
		$now = time();
		$topic_id = isset($options['topic_id']) ? (int) $options['topic_id'] : 0;
		$new_topic = ($topic_id === 0);
		if ($new_topic)
		{
			$topic_id = $this->next_topic++;
			$subject = isset($options['title']) ? (string) $options['title'] : 'Temat testowy ' . $topic_id;
		}
		else
		{
			$subject = isset($options['subject']) ? (string) $options['subject'] : 'Re: ' . $this->topic_title($topic_id);
		}
		$this->db->sql_query('INSERT INTO ' . self::PREFIX . 'posts ' . $this->db->sql_build_array('INSERT', array(
			'topic_id'        => $topic_id,
			'forum_id'        => $forum_id,
			'poster_id'       => $poster_id,
			'poster_ip'       => self::USER_IP,
			'post_time'       => $now,
			'post_visibility' => (int) $visibility,
			'post_subject'    => $subject,
			'post_text'       => $xml,
			'post_username'   => '',
			'post_checksum'   => md5($xml),
			'bbcode_uid'      => 'abcd1234',
			'bbcode_bitfield' => 'QQ==',
		)));
		$post_id = (int) $this->db->pdo->lastInsertId();
		if ($new_topic)
		{
			$approved = ((int) $visibility === ITEM_APPROVED);
			$this->db->sql_query('INSERT INTO ' . self::PREFIX . 'topics ' . $this->db->sql_build_array('INSERT', array(
				'topic_id'             => $topic_id,
				'forum_id'             => $forum_id,
				'topic_title'          => $subject,
				'topic_first_post_id'  => $post_id,
				'topic_last_post_id'   => $post_id,
				'topic_time'           => $now,
				'topic_last_post_time' => $now,
				'topic_posts_approved' => $approved ? 1 : 0,
				'topic_visibility'     => (int) $visibility,
			)));
		}
		return $post_id;
	}

	/**
	 * Creates phpBB's tables (the columns the extension and the fakes touch) and the
	 * extension's own table, from its migrations (the table, then the columns added later).
	 *
	 * @return void
	 */
	public function install()
	{
		$prefix = self::PREFIX;
		$this->db->pdo->exec("CREATE TABLE {$prefix}forums (forum_id INTEGER PRIMARY KEY, forum_name TEXT NOT NULL DEFAULT '')");
		$this->db->pdo->exec("CREATE TABLE {$prefix}users (user_id INTEGER PRIMARY KEY, username TEXT NOT NULL DEFAULT '',
			user_email TEXT NOT NULL DEFAULT '', user_ip TEXT NOT NULL DEFAULT '', user_posts INTEGER NOT NULL DEFAULT 0)");
		$this->db->pdo->exec("CREATE TABLE {$prefix}topics (topic_id INTEGER PRIMARY KEY, forum_id INTEGER NOT NULL DEFAULT 0,
			topic_title TEXT NOT NULL DEFAULT '', topic_first_post_id INTEGER NOT NULL DEFAULT 0, topic_last_post_id INTEGER NOT NULL DEFAULT 0,
			topic_time INTEGER NOT NULL DEFAULT 0, topic_last_post_time INTEGER NOT NULL DEFAULT 0,
			topic_posts_approved INTEGER NOT NULL DEFAULT 0, topic_visibility INTEGER NOT NULL DEFAULT 0)");
		$this->db->pdo->exec("CREATE TABLE {$prefix}posts (post_id INTEGER PRIMARY KEY AUTOINCREMENT, topic_id INTEGER NOT NULL DEFAULT 0,
			forum_id INTEGER NOT NULL DEFAULT 0, poster_id INTEGER NOT NULL DEFAULT 0, poster_ip TEXT NOT NULL DEFAULT '',
			post_time INTEGER NOT NULL DEFAULT 0, post_visibility INTEGER NOT NULL DEFAULT 0, post_subject TEXT NOT NULL DEFAULT '',
			post_text TEXT NOT NULL DEFAULT '', post_username TEXT NOT NULL DEFAULT '', post_checksum TEXT NOT NULL DEFAULT '',
			bbcode_uid TEXT NOT NULL DEFAULT '', bbcode_bitfield TEXT NOT NULL DEFAULT '', post_delete_reason TEXT NOT NULL DEFAULT '',
			post_delete_user INTEGER NOT NULL DEFAULT 0, post_delete_time INTEGER NOT NULL DEFAULT 0)");
		$this->db->pdo->exec("CREATE TABLE {$prefix}groups (group_id INTEGER PRIMARY KEY, group_type INTEGER NOT NULL DEFAULT 1,
			group_name TEXT NOT NULL DEFAULT '')");
		$this->db->pdo->exec("CREATE TABLE {$prefix}user_group (group_id INTEGER NOT NULL DEFAULT 0, user_id INTEGER NOT NULL DEFAULT 0,
			group_leader INTEGER NOT NULL DEFAULT 0, user_pending INTEGER NOT NULL DEFAULT 1)");
		$this->db->pdo->exec("INSERT INTO {$prefix}forums (forum_id, forum_name) VALUES (2, 'Ogólne'), (3, 'Dla młodzieży')");
		// phpBB's special groups (group_type 3) as installed, and one the administrator made.
		$this->db->pdo->exec("INSERT INTO {$prefix}groups (group_id, group_type, group_name) VALUES
			(1, 3, 'GUESTS'), (2, 3, 'REGISTERED'), (3, 3, 'REGISTERED_COPPA'), (4, 3, 'GLOBAL_MODERATORS'),
			(5, 3, 'ADMINISTRATORS'), (6, 3, 'BOTS'), (7, 3, 'NEWLY_REGISTERED'), (" . self::GROUP_TRUSTED . ", 0, 'Stali bywalcy')");
		// The guest in GUESTS (as phpBB installs it); the author registered and trusted; the new
		// user registered, newly registered, and only asking to join the trusted (pending).
		$this->db->pdo->exec("INSERT INTO {$prefix}user_group (group_id, user_id, user_pending) VALUES
			(1, 1, 0), (2, 2, 0), (" . self::GROUP_TRUSTED . ", 2, 0), (2, 3, 0), (7, 3, 0), (" . self::GROUP_TRUSTED . ", 3, 1)");
		$this->db->pdo->exec("INSERT INTO {$prefix}users (user_id, username, user_email, user_ip, user_posts) VALUES
			(1, 'Anonymous', '', '', 0), (2, 'Autorka', '" . self::USER_EMAIL . "', '" . self::USER_IP . "', 5), (3, 'Nowy', 'nowy@forum.example', '198.51.100.99', 0)");

		$migration = new install_schema(new \phpbb\config\config(), $this->db, null, './', 'php', self::PREFIX);
		foreach ($migration->update_schema()['add_tables'] as $table => $schema)
		{
			$this->db->pdo->exec(self::create_table($table, $schema));
			foreach ($schema['KEYS'] as $name => $key)
			{
				$this->db->pdo->exec('CREATE INDEX ' . $table . '_' . $name . ' ON ' . $table . ' (' . implode(', ', (array) $key[1]) . ')');
			}
		}
		$later = new verdict_times(new \phpbb\config\config(), $this->db, null, './', 'php', self::PREFIX);
		$changes = $later->update_schema();
		foreach ($changes['add_columns'] as $table => $columns)
		{
			foreach ($columns as $name => $definition)
			{
				$this->db->pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . self::column($name, $definition));
			}
		}
		foreach ($changes['add_index'] as $table => $indexes)
		{
			foreach ($indexes as $name => $columns)
			{
				$this->db->pdo->exec('CREATE INDEX ' . $table . '_' . $name . ' ON ' . $table . ' (' . implode(', ', $columns) . ')');
			}
		}
	}

	/**
	 * phpBB's schema array as SQLite DDL.
	 *
	 * @param string              $table
	 * @param array<string,mixed> $schema
	 * @return string
	 */
	public static function create_table($table, array $schema)
	{
		$columns = array();
		foreach ($schema['COLUMNS'] as $name => $definition)
		{
			$columns[] = self::column($name, $definition);
		}
		$columns[] = 'PRIMARY KEY (' . implode(', ', (array) $schema['PRIMARY_KEY']) . ')';
		return 'CREATE TABLE ' . $table . ' (' . implode(', ', $columns) . ')';
	}

	/**
	 * One column of phpBB's schema array as SQLite DDL.
	 *
	 * @param string            $name
	 * @param array{0:string,1:int|string} $definition The type and the default.
	 * @return string
	 */
	public static function column($name, array $definition)
	{
		list($type, $default) = $definition;
		$sql_type = preg_match('/^(VCHAR|MTEXT)/', $type) ? 'TEXT' : 'INTEGER';
		return $name . ' ' . $sql_type . ' NOT NULL DEFAULT ' . (is_int($default) ? $default : "'" . $default . "'");
	}

	/**
	 * Rebuilds the services that hold the settings object.
	 *
	 * @return void
	 */
	protected function rewire()
	{
		$this->applier = new verdict_applier($this->settings, $this->store, $this->forum, $this->db);
		$this->submitter = new submitter($this->settings, $this->store, $this->forum, $this->transport, $this->applier);
		$this->receiver = new receiver($this->settings, $this->store);
		$this->deferred = new deferred_verdicts($this->applier, $this->forum);
		$this->listener = new listener($this->settings, $this->store, $this->forum, $this->submitter, $this->auth, $this->language, $this->user);
	}
}
