<?php

namespace minos\moderation\tests\Fake;

use minos\moderation\controller\webhook;
use minos\moderation\cron\sweeper;
use minos\moderation\event\deferred_verdicts;
use minos\moderation\event\listener;
use minos\moderation\migrations\install_schema;
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
			$board->log, $board->language, self::PREFIX . 'posts', self::PREFIX . 'topics', self::PREFIX . 'forums', self::PREFIX . 'users');
		$board->transport = $transport ?: new RecordingTransport();
		$board->applier = new verdict_applier($board->settings, $board->store, $board->forum, $board->db);
		$board->submitter = new submitter($board->settings, $board->store, $board->forum, $board->transport, $board->applier);
		$board->receiver = new receiver($board->settings, $board->store);
		$board->deferred = new deferred_verdicts($board->applier);
		$board->dispatcher = new RecordingDispatcher();
		$board->auth = new \phpbb\auth\auth();
		$board->auth->global['f_noapprove'] = true;
		$board->listener = new listener($board->settings, $board->store, $board->forum, $board->submitter, $board->auth, $board->language);
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
	 * @param array  $options forum_id, poster_id, topic_id (for a reply), xml (a stored text),
	 *     data (phpBB's post data as other extensions left it).
	 * @return array{post_id:int,visibility:int,data:array} The post and what phpBB stored.
	 */
	public function posting($text, $mode = 'post', array $options = array())
	{
		$forum_id = isset($options['forum_id']) ? $options['forum_id'] : self::FORUM;
		$xml = isset($options['xml']) ? $options['xml'] : $this->xml($text);
		$before = new \phpbb\event\data(array(
			'mode'     => $mode,
			'data'     => (isset($options['data']) ? $options['data'] : array()) + array('message' => $xml, 'forum_id' => $forum_id),
			'forum_id' => $forum_id,
			'post_id'  => 0,
			'topic_id' => isset($options['topic_id']) ? $options['topic_id'] : 0,
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
	 * @param int    $post_id The post.
	 * @param string $text    Its new plain text.
	 * @return void
	 */
	public function edit($post_id, $text)
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
		)) . ' WHERE post_id = ' . (int) $post_id);
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
		$sweeper = new sweeper($this->config, $this->settings, $this->store, $this->submitter, $this->applier);
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
	 * @param string $xml        The stored text.
	 * @param int    $visibility Its visibility.
	 * @param array  $options    forum_id, poster_id, topic_id.
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
		}
		$this->db->sql_query('INSERT INTO ' . self::PREFIX . 'posts ' . $this->db->sql_build_array('INSERT', array(
			'topic_id'        => $topic_id,
			'forum_id'        => $forum_id,
			'poster_id'       => $poster_id,
			'poster_ip'       => self::USER_IP,
			'post_time'       => $now,
			'post_visibility' => (int) $visibility,
			'post_subject'    => 'Temat testowy ' . $topic_id,
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
				'topic_title'          => 'Temat testowy ' . $topic_id,
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
	 * extension's own table, from its migration.
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
		$this->db->pdo->exec("INSERT INTO {$prefix}forums (forum_id, forum_name) VALUES (2, 'Ogólne'), (3, 'Dla młodzieży')");
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
			list($type, $default) = $definition;
			$sql_type = preg_match('/^(VCHAR|MTEXT)/', $type) ? 'TEXT' : 'INTEGER';
			$columns[] = $name . ' ' . $sql_type . ' NOT NULL DEFAULT ' . (is_int($default) ? $default : "'" . $default . "'");
		}
		$columns[] = 'PRIMARY KEY (' . implode(', ', (array) $schema['PRIMARY_KEY']) . ')';
		return 'CREATE TABLE ' . $table . ' (' . implode(', ', $columns) . ')';
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
		$this->deferred = new deferred_verdicts($this->applier);
		$this->listener = new listener($this->settings, $this->store, $this->forum, $this->submitter, $this->auth, $this->language);
	}
}
