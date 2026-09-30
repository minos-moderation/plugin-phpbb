<?php

namespace minos\moderation\tests\EndToEnd;

use minos\moderation\service\curl_transport;
use minos\moderation\service\pending_store;
use minos\moderation\service\settings;
use minos\moderation\tests\Fake\Board;
use Minos\Mock\Config;
use PHPUnit\Framework\TestCase;

/**
 * The whole round trip over HTTP, with real processes: posts go through the listener and the
 * real cURL transport to the mock gateway of `minos-moderation/client-php` (as Composer
 * installs it); its worker delivers the verdicts, signed, to a forum stand-in that serves
 * the extension's webhook controller over the same SQLite board.
 *
 * The verdicts come from the mock's `[minos:…]` markers, never from assessing content.
 */
final class MockGatewayTest extends TestCase
{
	private const MOCK = __DIR__ . '/../../vendor/minos-moderation/client-php/mock-gateway';

	/** @var string */
	private $dir;

	/** @var array<int,array{0:resource,1:int}> Started servers and their ports. */
	private $servers = array();

	/** @var array<string,string> The mock's environment. */
	private $env = array();

	protected function setUp(): void
	{
		self::assertFileExists(self::MOCK . '/public/index.php', 'the mock gateway comes with minos-moderation/client-php');
		require_once self::MOCK . '/autoload.php';
		$this->dir = sys_get_temp_dir() . '/minos-phpbb-e2e-' . bin2hex(random_bytes(6));
		mkdir($this->dir, 0700, true);
	}

	protected function tearDown(): void
	{
		foreach ($this->servers as $server)
		{
			proc_terminate($server[0]);
			proc_close($server[0]);
			// A server that outlives its test is a leak.
			$socket = @fsockopen('127.0.0.1', $server[1], $errno, $errstr, 0.2);
			self::assertFalse($socket, "the server on port {$server[1]} is still running");
		}
		foreach (glob($this->dir . '/*') ?: array() as $file)
		{
			unlink($file);
		}
		rmdir($this->dir);
	}

	public function testVerdictsTravelFromTheMockGatewayToThePosts(): void
	{
		$database = $this->dir . '/board.sqlite';
		$deliveries = $this->dir . '/deliveries.jsonl';
		$board = Board::open($database, new curl_transport());

		$forum_port = $this->serve(array(PHP_BINARY, '-S', '127.0.0.1:%d', __DIR__ . '/forum_router.php'), array(
			'MINOS_E2E_DB'     => $database,
			'MINOS_E2E_SECRET' => Config::DEFAULT_SECRET,
			'MINOS_E2E_LOG'    => $deliveries,
		));
		$this->env = array(
			'MINOS_MOCK_WEBHOOK_URL' => "http://127.0.0.1:{$forum_port}/minos/webhook",
			'MINOS_MOCK_DATA_DIR'    => $this->dir,
			'MINOS_MOCK_DELAY_S'     => '0',
		);
		$mock_port = $this->serve(array(PHP_BINARY, '-S', '127.0.0.1:%d', '-t', self::MOCK . '/public'), $this->env);

		$board->configure(array(settings::GATEWAY_URL => "http://127.0.0.1:{$mock_port}"), array(
			settings::API_KEY        => Config::DEFAULT_KEY,
			settings::WEBHOOK_SECRET => Config::DEFAULT_SECRET,
		));

		$posts = array();
		foreach ($texts = array(
			'safe'      => 'Testowy wpis o pogodzie.',
			'blocked'   => 'Testowy wpis [minos:blokuj] [minos:kategoria=nekanie]',
			'masked'    => 'Testowy wpis ze [[słowem]] do ukrycia [minos:cenzuruj] [minos:kategoria=wulgaryzmy]',
			'unassessed' => 'Testowy wpis [minos:nieocenione]',
			'twice'     => 'Testowy wpis [minos:dwa-razy]',
			'forged'    => 'Testowy wpis [minos:zly-podpis]',
			'support'   => 'Testowy wpis [minos:kategoria=samookaleczenie]',
		) as $name => $text)
		{
			$posts[$name] = $board->posting($text)['post_id'];
			self::assertSame(pending_store::PENDING, $board->row($posts[$name])['status'], "{$name}: accepted by the mock");
		}

		// Two passes: the second delivers the repeat of [minos:dwa-razy].
		$this->work();
		$this->work();

		self::assertSame(ITEM_APPROVED, (int) $board->post($posts['safe'])['post_visibility']);
		self::assertSame(pending_store::PUBLISHED, $board->row($posts['safe'])['status']);

		self::assertSame(ITEM_UNAPPROVED, (int) $board->post($posts['blocked'])['post_visibility']);
		self::assertSame(pending_store::HELD, $board->row($posts['blocked'])['status']);
		self::assertSame('nekanie', $board->row($posts['blocked'])['categories']);

		self::assertSame(pending_store::MASKED, $board->row($posts['masked'])['status']);
		$sent = $texts['masked'];
		$masked = $board->row($posts['masked'])['masked_text'];
		self::assertSame(mb_strlen($sent, 'UTF-8'), mb_strlen($masked, 'UTF-8'), 'the masked text keeps the sent text\'s length');
		self::assertSame(str_replace('słowem', '██████', $sent), $masked);
		self::assertSame($board->xml($masked), $board->post($posts['masked'])['post_text']);

		self::assertSame('nieocenione', $board->row($posts['unassessed'])['verdict']);
		self::assertSame(ITEM_UNAPPROVED, (int) $board->post($posts['unassessed'])['post_visibility'], 'the default fail-closed');
		self::assertSame(pending_store::HELD, $board->row($posts['unassessed'])['status']);

		self::assertSame(pending_store::PUBLISHED, $board->row($posts['twice'])['status']);

		self::assertSame(pending_store::PENDING, $board->row($posts['forged'])['status'], 'a forged delivery changes nothing');
		self::assertSame(ITEM_UNAPPROVED, (int) $board->post($posts['forged'])['post_visibility']);

		self::assertSame('1', $board->row($posts['support'])['support']);

		$statuses = array();
		foreach (file($deliveries, FILE_IGNORE_NEW_LINES) ?: array() as $line)
		{
			$delivery = json_decode($line, true);
			$statuses[$delivery['id']][] = $delivery['status'];
		}
		self::assertSame(array(200, 200), $statuses['phpbb:' . $posts['twice']], 'delivered twice, answered 200 twice');
		self::assertSame(array(401), $statuses['phpbb:' . $posts['forged']]);
	}

	/**
	 * One pass of the mock's worker, as a process.
	 */
	private function work(): void
	{
		$process = proc_open(array(PHP_BINARY, self::MOCK . '/bin/worker.php', '--once'),
			array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, $this->env + self::environment());
		self::assertIsResource($process);
		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		self::assertSame(0, proc_close($process), $output);
	}

	/**
	 * Starts PHP's built-in server on a free port and waits until it answers.
	 *
	 * @param array<int,string>    $command With `%d` where the port goes.
	 * @param array<string,string> $env
	 * @return int The port.
	 */
	private function serve(array $command, array $env): int
	{
		for ($try = 0; $try < 5; $try++)
		{
			$port = random_int(20000, 40000);
			$argv = array_map(static function ($part) use ($port) {
				return str_replace('%d', (string) $port, $part);
			}, $command);
			// An array, not a string: a string runs through `sh -c`, and terminating the
			// shell would leave PHP's server running.
			$server = proc_open($argv, array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')),
				$pipes, null, $env + self::environment());
			self::assertIsResource($server);
			for ($wait = 0; $wait < 50; $wait++)
			{
				$socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
				if ($socket !== false)
				{
					fclose($socket);
					$this->servers[] = array($server, $port);
					return $port;
				}
				usleep(100000);
			}
			proc_terminate($server);
			proc_close($server);
		}
		self::fail('the built-in server did not start');
	}

	/**
	 * This process's environment, for the child processes.
	 *
	 * @return array<string,string>
	 */
	private static function environment(): array
	{
		$env = getenv();
		return is_array($env) ? $env : array();
	}
}
