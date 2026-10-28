<?php

namespace minos\moderation\tests\Receiver;

use minos\moderation\event\webhook_access;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The webhook on a disabled or overloaded board: phpBB's `user::setup()` (6c75df1,
 * `phpbb/user.php`) answers a guest with its own page and `200` unless `SKIP_CHECK_DISABLED`
 * is defined (the disabled board) and `limit_load` is 0 (the load limit) by the time it
 * checks; `core.user_setup` comes first. Each case in its own process: a constant, once
 * defined, stays. The live board's suite (tests/functional) delivers through it.
 */
final class WebhookAccessTest extends TestCase
{
	public function testItListensToTheEventPhpbbTriggersBeforeTheChecks(): void
	{
		self::assertSame(array('core.user_setup'), array_keys(webhook_access::getSubscribedEvents()));
		self::assertTrue(method_exists(webhook_access::class, webhook_access::getSubscribedEvents()['core.user_setup']));
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function frontControllers(): array
	{
		return array('the board at the root' => array('/app.php'), 'the board in a directory' => array('/forum/app.php'));
	}

	/**
	 * @dataProvider frontControllers
	 * @runInSeparateProcess
	 */
	public function testADeliveryPassesADisabledAndAnOverloadedBoard(string $script): void
	{
		$config = new \phpbb\config\config(array('limit_load' => '0.5', 'board_disable' => '1'));

		(new webhook_access(new Request(array(), '{}', '/minos/webhook', 'POST', $script), $config))->let_deliveries_in(new \phpbb\event\data(array()));

		self::assertTrue(defined('SKIP_CHECK_DISABLED') && SKIP_CHECK_DISABLED, 'phpBB skips the disabled board');
		self::assertSame('0', $config['limit_load'], 'and the load limit, for this request');
		self::assertSame('1', $config['board_disable'], 'the board stays disabled');
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function otherRequests(): array
	{
		return array(
			'the webhook, GET'       => array('/app.php', '/minos/webhook', 'GET'),
			'another route'          => array('/app.php', '/minos/other', 'POST'),
			'below the webhook'      => array('/app.php', '/minos/webhook/x', 'POST'),
			'a page of the board'    => array('/app.php', '/', 'POST'),
			// The same path after another script (review of #7, N1): viewtopic.php/minos/webhook.
			'viewtopic.php'          => array('/viewtopic.php', '/minos/webhook', 'POST'),
			'index.php'              => array('/index.php', '/minos/webhook', 'POST'),
			'posting.php'            => array('/forum/posting.php', '/minos/webhook', 'POST'),
			'a name with app.php in it' => array('/myapp.php', '/minos/webhook', 'POST'),
			'the path left in the name' => array('/app.php/minos/webhook', '/minos/webhook', 'POST'),
		);
	}

	/**
	 * @dataProvider otherRequests
	 * @runInSeparateProcess
	 */
	public function testNoOtherRequestPasses(string $script, string $path, string $method): void
	{
		$config = new \phpbb\config\config(array('limit_load' => '0.5'));

		(new webhook_access(new Request(array(), '', $path, $method, $script), $config))->let_deliveries_in(new \phpbb\event\data(array()));

		self::assertFalse(defined('SKIP_CHECK_DISABLED'));
		self::assertSame('0.5', $config['limit_load']);
	}
}
