<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 INPERITIA
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace minos\moderation\tests\functional;

use minos\moderation\client_loader;
use Minos\Client\Signature;

/**
 * The extension on a live phpBB 3.3 board (phpBB's own functional test harness: the board
 * installed on MySQL, served over HTTP, driven as a browser would), installed from its own
 * package (bin/build-zip.sh), with the mock gateway of minos-moderation/client-php as real
 * processes. Run by the `phpbb-functional` job of
 * `.github/workflows/tests.yml` (`phpunit.functional.xml.dist`, see docs/development.md);
 * never by the extension's own `vendor/bin/phpunit`.
 *
 * Invented posts only; the key and the secret are invented test values the mock is given.
 * The tests run in order on one board: each one leaves the extension enabled, the last one
 * deletes its data.
 *
 * @group functional
 */
class moderation_test extends \phpbb_functional_test_case
{
	/** The extension, as phpBB's extension manager names it. */
	const EXT = 'minos/moderation';

	/** An invented key and secret, given to the mock and typed into the ACP. */
	const KEY = 'wgb2b_test_funkcjonalny_phpbb_0000000000';
	const SECRET = 'sekret-testu-funkcjonalnego-phpbb-0000';

	/** The forum phpBB's installer creates. */
	const FORUM = 2;

	/** The author the extension holds: a registered user, with f_noapprove by phpBB's defaults. */
	const AUTHOR = 'minos-autor';

	/** @var resource|null The mock gateway's server. */
	protected static $mock;

	/** @var int */
	protected static $mock_port = 0;

	/** @var string The mock's queue directory. */
	protected static $mock_dir = '';

	/**
	 * @return array<int,string>
	 */
	protected static function setup_extensions()
	{
		return array(self::EXT);
	}

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		// phpBB keeps queued e-mails in a file the board's reinstallation leaves behind.
		@unlink(self::email_queue_file());
		self::$mock_dir = sys_get_temp_dir() . '/minos-phpbb-functional-' . bin2hex(random_bytes(6));
		mkdir(self::$mock_dir, 0700, true);
		self::$mock_port = self::free_port();
		self::$mock = proc_open(array(PHP_BINARY, '-S', '127.0.0.1:' . self::$mock_port, '-t', self::mock_path() . '/public'),
			array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, null, self::mock_env());
		for ($wait = 0; $wait < 50; $wait++)
		{
			$socket = @fsockopen('127.0.0.1', self::$mock_port, $errno, $errstr, 0.1);
			if ($socket !== false)
			{
				fclose($socket);
				return;
			}
			usleep(100000);
		}
		self::fail('the mock gateway did not start');
	}

	public static function tearDownAfterClass(): void
	{
		if (is_resource(self::$mock))
		{
			proc_terminate(self::$mock);
			proc_close(self::$mock);
		}
		foreach (glob(self::$mock_dir . '/*') ?: array() as $file)
		{
			unlink($file);
		}
		@rmdir(self::$mock_dir);
		parent::tearDownAfterClass();
	}

	protected function setUp(): void
	{
		parent::setUp();
		$this->add_lang('posting');
		$db = $this->get_db();
		// One author posting several topics in a row: phpBB's flood control would refuse them.
		$db->sql_query('UPDATE ' . CONFIG_TABLE . " SET config_value = '0' WHERE config_name = 'flood_interval'");
		// phpBB's harness installs the board with `server_protocol` and `force_server_vars`
		// swapped ('0' and 'http://'), which turns every absolute URL into `//host/...`
		// without its port. A new board's default: the URL comes from the request.
		$db->sql_query('UPDATE ' . CONFIG_TABLE . " SET config_value = '0' WHERE config_name = 'force_server_vars'");
		$this->purge_cache();
	}

	public function test_enabling_the_extension_installs_it_switched_off(): void
	{
		$db = $this->get_db();
		$result = $db->sql_query('SELECT ext_active FROM ' . EXT_TABLE . " WHERE ext_name = '" . self::EXT . "'");
		$this->assertSame('1', (string) $db->sql_fetchfield('ext_active'), 'enabled through the ACP\'s extension manager');
		$db->sql_freeresult($result);
		$this->assertTrue($this->tools()->sql_table_exists('phpbb_minos_pending'));
		$this->assertSame(8, $this->count_rows(MIGRATIONS_TABLE, "migration_name LIKE '%minos%moderation%migrations%'"), 'every migration ran');
		$config = $this->config_rows();
		$this->assertSame('0', $config['minos_moderation_enabled'][0], 'a fresh installation starts switched off');
		$this->assertSame('1', $config['minos_moderation_signature_rejected'][1], 'the trace is dynamic, out of the config cache');

		$webhook = (string) $this->acp_page()->filter('fieldset input[readonly]')->attr('value');
		$this->logout();
		$this->assertSame(self::$root_url . 'app.php/minos/webhook', $webhook, 'the address to give the operator, without a session id');
	}

	public function test_a_held_post_reaches_the_mock_once_and_its_signed_verdict_publishes_it(): void
	{
		$this->configure();
		$this->subscribe_admin_to_queue_emails();
		$this->assertSame(array(), $this->mock_queue(), 'the mock\'s queue starts empty');

		$post_id = $this->post_as_author('Minos test safe', 'Testowy wpis o pogodzie na jutro.');

		$this->assertSame(ITEM_UNAPPROVED, $this->visibility($post_id), 'held in phpBB\'s queue');
		$this->assertSame('pending', $this->row($post_id)['status'], 'the mock answered 202');
		$this->assertSame(array('phpbb:' . $post_id), array_column($this->mock_queue(), 'item_id'), 'one request, one item');
		$this->assertSame(0, $this->queue_emails('Minos test safe'), 'the moderators\' e-mail waits for the outcome');

		$this->work();

		$this->assertSame(ITEM_APPROVED, $this->visibility($post_id), 'published on the signed verdict');
		$this->assertSame('published', $this->row($post_id)['status']);
		$crawler = self::request('GET', 'viewtopic.php?p=' . $post_id);
		$this->assertStringContainsString('Testowy wpis o pogodzie na jutro.', $crawler->filter('.postbody')->text());
		$this->assertSame(0, $this->queue_emails('Minos test safe'), 'a post held, then published: no e-mail about the queue');
	}

	public function test_an_image_and_an_invisible_character_send_nothing(): void
	{
		// Review of S6c: a new topic of a title, an image and U+200B reached the mock as the
		// title alone, in effect, and was published on its verdict.
		$this->configure();

		$post_id = $this->post_as_author('Minos test invisible', "[img]https://example.org/a.png[/img]\u{200B}");

		$this->assertSame(array('held', 'empty_text'), array($this->row($post_id)['status'], $this->row($post_id)['error_code']));
		$this->assertSame(ITEM_UNAPPROVED, $this->visibility($post_id), 'fail-closed: for a moderator');
		$this->assertSame(array(), $this->mock_queue(), 'nothing sent, not the title alone');
	}

	public function test_a_masked_verdict_publishes_the_masked_text(): void
	{
		$this->configure();

		$post_id = $this->post_as_author('Minos test mask', 'Testowy wpis ze [[slowem]] do ukrycia [minos:cenzuruj]');
		$this->work();

		$this->assertSame('masked', $this->row($post_id)['status']);
		$this->assertSame(ITEM_APPROVED, $this->visibility($post_id));
		$text = self::request('GET', 'viewtopic.php?p=' . $post_id)->filter('.postbody .content')->text();
		$this->assertStringContainsString('[[██████]]', $text, 'what readers see');
		$this->assertStringNotContainsString('slowem', $text);
	}

	public function test_a_held_verdict_emails_the_moderators_once(): void
	{
		$this->configure();
		$this->subscribe_admin_to_queue_emails();

		$post_id = $this->post_as_author('Minos test held', 'Testowy wpis [minos:blokuj] [minos:kategoria=nekanie]');
		$this->assertSame(0, $this->queue_emails('Minos test held'));
		$this->work();

		$this->assertSame('held', $this->row($post_id)['status']);
		$this->assertSame(ITEM_UNAPPROVED, $this->visibility($post_id));
		$this->assertSame(1, $this->queue_emails('Minos test held'), 'left to the moderators: one e-mail');

		// What the moderator sees in the MCP queue (prosilver): the mark and the outcome.
		$this->login();
		$queue = self::request('GET', 'mcp.php?i=queue&mode=unapproved_topics&sid=' . $this->sid)->filter('#main, #page-body')->text();
		$details = self::request('GET', 'mcp.php?i=queue&mode=approve_details&p=' . $post_id . '&sid=' . $this->sid)->filter('#page-body')->text();
		$this->logout();
		$this->assertStringContainsString('[Minos: zablokowane] Minos test held', $queue);
		$this->assertStringContainsString('Minos: zablokowane', $details);
	}

	public function test_what_the_webhook_answers_while_the_board_or_the_extension_is_off(): void
	{
		$this->configure();
		$post_id = $this->post_as_author('Minos test off', 'Testowy wpis czekający na werdykt.');
		$delivery = json_encode(array('id' => 'phpbb:' . $post_id, 'status' => 'ocenione', 'kwalifikacja' => 'bezpieczne',
			'kategorie' => array(), 'ocenzurowany' => null, 'wsparcie' => false, 'wersja' => '0000000000000000'));

		// The board disabled by its administrator ("Disable board"): the webhook stays open
		// (event/webhook_access.php), the verdict is applied, readers see the board disabled.
		$this->set_config('board_disable', '1');
		$answer = $this->deliver($delivery);
		$this->record('board disabled', $answer);
		$this->assertSame(array(200, ''), array($answer['status'], $answer['body']), 'the controller\'s answer, not phpBB\'s page');
		$this->assertSame('published', $this->row($post_id)['status'], 'the verdict is applied');
		$this->assertSame(ITEM_APPROVED, $this->visibility($post_id));
		$this->assertStringContainsString($this->lang('BOARD_DISABLE'), self::request('GET', 'index.php', array(), false)->text(), 'only the webhook passes');
		$this->set_config('board_disable', '0');

		// A second post for the rest: the first one is settled.
		$post_id = $this->post_as_author('Minos test off 2', 'Kolejny testowy wpis czekający na werdykt.');
		$delivery = json_encode(array('id' => 'phpbb:' . $post_id, 'status' => 'ocenione', 'kwalifikacja' => 'bezpieczne',
			'kategorie' => array(), 'ocenzurowany' => null, 'wsparcie' => false, 'wersja' => '0000000000000000'));

		// The assessment switched off in the extension's settings.
		$this->configure(false);
		$answer = $this->deliver($delivery);
		$this->record('assessment off', $answer);
		$this->assertSame(array(404, ''), array($answer['status'], $answer['body']), 'the controller\'s own 404, before reading anything');
		$this->assertSame('pending', $this->row($post_id)['status']);

		// Switched on again: what waited is queued again as a new revision (the plugins' shared
		// rule D9.13), for the cron task.
		$this->configure();
		$this->assertSame(array('queued', '1'), array($this->row($post_id)['status'], (string) $this->row($post_id)['revision']));

		// The extension disabled in the extension manager: its route is gone.
		$this->disable_ext(self::EXT);
		$answer = $this->deliver($delivery);
		$this->record('extension disabled', $answer);
		$this->assertSame(404, $answer['status'], 'phpBB has no such route');
		$this->assertSame(array('queued', '1'), array($this->row($post_id)['status'], (string) $this->row($post_id)['revision']));
	}

	public function test_deleting_the_data_removes_the_table_and_every_setting(): void
	{
		$this->configure();
		$this->assertGreaterThan(0, $this->count_rows(CONFIG_TEXT_TABLE, "config_name LIKE 'minos\\_moderation\\_%'"), 'the key and the secret are stored');

		$this->disable_ext(self::EXT);
		$this->delete_ext_data(self::EXT);

		$this->assertFalse($this->tools()->sql_table_exists('phpbb_minos_pending'), 'the table is dropped');
		$this->assertSame(0, $this->count_rows(CONFIG_TABLE, "config_name LIKE 'minos\\_moderation\\_%'"), 'no setting left');
		$this->assertSame(0, $this->count_rows(CONFIG_TEXT_TABLE, "config_name LIKE 'minos\\_moderation\\_%'"), 'neither the key nor the secret');
		$this->assertSame(0, $this->count_rows(MODULES_TABLE, "module_langname LIKE 'ACP\\_MINOS\\_%'"), 'no ACP page');
		$this->assertSame(0, $this->count_rows(MIGRATIONS_TABLE, "migration_name LIKE '%minos%moderation%migrations%'"));
		$this->assertSame(0, $this->count_rows(EXT_TABLE, "ext_name = '" . self::EXT . "'"));
	}

	/**
	 * Saves the extension's settings through its ACP page, as an administrator does.
	 *
	 * @param bool $enabled Whether the assessment is switched on.
	 * @return void
	 */
	protected function configure($enabled = true)
	{
		$crawler = $this->acp_page();
		$form = $crawler->selectButton('submit')->form();
		$form['minos_enabled']->select($enabled ? '1' : '0');
		$form['minos_gateway_url'] = 'http://127.0.0.1:' . self::$mock_port;
		$form['minos_api_key'] = self::KEY;
		$form['minos_webhook_secret'] = self::SECRET;
		$form['minos_fail_mode']->select('fail-closed');
		$crawler = self::submit($form);
		$this->assertStringContainsString('Ustawienia zostały zapisane.', $crawler->filter('#main')->text(), 'saved');
		$this->logout();
	}

	/**
	 * The extension's ACP page, logged in as the administrator.
	 *
	 * @return \Symfony\Component\DomCrawler\Crawler
	 */
	protected function acp_page()
	{
		$this->login();
		$this->admin_login();
		return self::request('GET', 'adm/index.php?i=-minos-moderation-acp-main_module&mode=settings&sid=' . $this->sid);
	}

	/**
	 * A new topic by the author the extension holds.
	 *
	 * @param string $title   An ASCII title, unique per test.
	 * @param string $message The post.
	 * @return int The post's id.
	 */
	protected function post_as_author($title, $message)
	{
		if (!$this->user_exists(self::AUTHOR))
		{
			$this->create_user(self::AUTHOR);
		}
		$this->login(self::AUTHOR);
		// phpBB's "awaits approval" answer: the extension held the post.
		$this->create_topic(self::FORUM, $title, $message, array(), 'POST_STORED_MOD');
		$this->logout();
		$db = $this->get_db();
		$result = $db->sql_query('SELECT MAX(post_id) AS post_id FROM ' . POSTS_TABLE . " WHERE post_subject = '" . $db->sql_escape($title) . "'");
		$post_id = (int) $db->sql_fetchfield('post_id');
		$db->sql_freeresult($result);
		$this->assertGreaterThan(0, $post_id, 'the post is stored');
		return $post_id;
	}

	/**
	 * Sends one delivery to the board's webhook, signed now as the gateway signs.
	 *
	 * @param string $body The payload.
	 * @return array{status:int,body:string,type:string}
	 */
	protected function deliver($body)
	{
		client_loader::register();
		$handle = curl_init(self::$root_url . 'app.php/minos/webhook');
		curl_setopt_array($handle, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'X-Wergiliusz-Podpis: ' . Signature::sign(self::SECRET, $body, time())),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 20,
		));
		$answer = (string) curl_exec($handle);
		$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		$type = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
		curl_close($handle);
		return array('status' => $status, 'body' => $answer, 'type' => $type);
	}

	/**
	 * Writes what the webhook answered to the job's log, for the README.
	 *
	 * @param string                                  $case
	 * @param array{status:int,body:string,type:string} $answer
	 * @return void
	 */
	protected function record($case, array $answer)
	{
		fwrite(STDERR, sprintf("\nwebhook, %s: HTTP %d, %s, %d bytes\n", $case, $answer['status'], $answer['type'], strlen($answer['body'])));
	}

	/**
	 * One pass of the mock's worker: it signs and delivers what it holds to the board.
	 *
	 * @return void
	 */
	protected function work()
	{
		$process = proc_open(array(PHP_BINARY, self::mock_path() . '/bin/worker.php', '--once'),
			array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, self::mock_env());
		$this->assertIsResource($process);
		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$this->assertSame(0, proc_close($process), $output);
		$this->assertSame(array(), $this->mock_queue(), 'everything delivered: ' . $output);
	}

	/**
	 * @return array<int,array<string,mixed>> The mock's queued entries.
	 */
	protected function mock_queue()
	{
		$file = self::$mock_dir . '/queue.json';
		$entries = is_file($file) ? json_decode((string) file_get_contents($file), true) : array();
		return is_array($entries) ? $entries : array();
	}

	/**
	 * The e-mails phpBB queued (its default: e-mails wait in `cache/<environment>/queue.php`
	 * for the cron task) whose subject names a topic.
	 *
	 * @param string $title The topic's title.
	 * @return int
	 */
	protected function queue_emails($title)
	{
		$file = self::email_queue_file();
		if (!is_file($file))
		{
			return 0;
		}
		$reader = new class
		{
			/** @var array<string,mixed> */
			public $queue_data = array();

			/**
			 * @param string $file
			 * @return array<string,mixed>
			 */
			public function read($file)
			{
				include $file;
				return $this->queue_data;
			}
		};
		$count = 0;
		$data = $reader->read($file);
		foreach (isset($data['email']['data']) ? $data['email']['data'] : array() as $email)
		{
			if (strpos((string) $email['subject'], $title) !== false)
			{
				$count++;
			}
		}
		return $count;
	}

	/**
	 * @return string phpBB's e-mail queue (`messenger`'s `queue::$cache_file`).
	 */
	protected static function email_queue_file()
	{
		return __DIR__ . '/../../../../../cache/' . PHPBB_ENVIRONMENT . '/queue.php';
	}

	/**
	 * The administrator (a moderator of every forum) wants phpBB's "topic in the queue" by
	 * e-mail too; phpBB's default is the board only. The UCP option of `topic_in_queue` and
	 * `post_in_queue` is `notification.type.needs_approval`.
	 *
	 * @return void
	 */
	protected function subscribe_admin_to_queue_emails()
	{
		$db = $this->get_db();
		$db->sql_query('DELETE FROM ' . USER_NOTIFICATIONS_TABLE . " WHERE user_id = 2 AND item_type = 'notification.type.needs_approval'");
		foreach (array('notification.method.board', 'notification.method.email') as $method)
		{
			$db->sql_query('INSERT INTO ' . USER_NOTIFICATIONS_TABLE . ' ' . $db->sql_build_array('INSERT', array(
				'item_type' => 'notification.type.needs_approval',
				'item_id'   => 0,
				'user_id'   => 2,
				'method'    => $method,
				'notify'    => 1,
			)));
		}
	}

	/**
	 * @param string $name
	 * @param string $value
	 * @return void
	 */
	protected function set_config($name, $value)
	{
		$db = $this->get_db();
		$db->sql_query('UPDATE ' . CONFIG_TABLE . " SET config_value = '" . $db->sql_escape($value) . "' WHERE config_name = '" . $db->sql_escape($name) . "'");
		$this->purge_cache();
	}

	/**
	 * @param int $post_id
	 * @return int The post's visibility.
	 */
	protected function visibility($post_id)
	{
		$db = $this->get_db();
		$result = $db->sql_query('SELECT post_visibility FROM ' . POSTS_TABLE . ' WHERE post_id = ' . (int) $post_id);
		$visibility = (int) $db->sql_fetchfield('post_visibility');
		$db->sql_freeresult($result);
		return $visibility;
	}

	/**
	 * @param int $post_id
	 * @return array<string,string> The extension's row of the post.
	 */
	protected function row($post_id)
	{
		$db = $this->get_db();
		$result = $db->sql_query('SELECT * FROM phpbb_minos_pending WHERE post_id = ' . (int) $post_id);
		$row = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		$this->assertIsArray($row, 'the extension holds the post');
		return $row;
	}

	/**
	 * @return array<string,array{0:string,1:string}> The extension's `config` rows: value, is_dynamic.
	 */
	protected function config_rows()
	{
		$db = $this->get_db();
		$rows = array();
		$result = $db->sql_query('SELECT config_name, config_value, is_dynamic FROM ' . CONFIG_TABLE . " WHERE config_name LIKE 'minos\\_moderation\\_%'");
		while ($row = $db->sql_fetchrow($result))
		{
			$rows[$row['config_name']] = array((string) $row['config_value'], (string) $row['is_dynamic']);
		}
		$db->sql_freeresult($result);
		return $rows;
	}

	/**
	 * @param string $table
	 * @param string $where
	 * @return int
	 */
	protected function count_rows($table, $where)
	{
		$db = $this->get_db();
		$result = $db->sql_query('SELECT COUNT(*) AS n FROM ' . $table . ' WHERE ' . $where);
		$count = (int) $db->sql_fetchfield('n');
		$db->sql_freeresult($result);
		return $count;
	}

	/**
	 * @return \phpbb\db\tools\tools_interface
	 */
	protected function tools()
	{
		$factory = new \phpbb\db\tools\factory();
		return $factory->get($this->get_db());
	}

	/**
	 * @return string The mock gateway of minos-moderation/client-php (MINOS_MOCK_PATH): the
	 *     extension installed from its package carries no mock.
	 */
	protected static function mock_path()
	{
		$path = (string) getenv('MINOS_MOCK_PATH');
		if (!is_file($path . '/public/index.php'))
		{
			self::fail('MINOS_MOCK_PATH must name the mock gateway of minos-moderation/client-php');
		}
		return $path;
	}

	/**
	 * @return array<string,string> The mock's settings: the board's webhook, the invented key and secret.
	 */
	protected static function mock_env()
	{
		$env = getenv();
		return array(
			'MINOS_MOCK_WEBHOOK_URL'    => self::$root_url . 'app.php/minos/webhook',
			'MINOS_MOCK_KEY'            => self::KEY,
			'MINOS_MOCK_WEBHOOK_SECRET' => self::SECRET,
			'MINOS_MOCK_DATA_DIR'       => self::$mock_dir,
			'MINOS_MOCK_DELAY_S'        => '0',
		) + (is_array($env) ? $env : array());
	}

	/**
	 * @return int A port nothing listens on.
	 */
	protected static function free_port()
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
		$name = stream_socket_get_name($socket, false);
		fclose($socket);
		return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
	}
}
