<?php

namespace minos\moderation\tests\Receiver;

use minos\moderation\controller\acp;
use minos\moderation\controller\webhook;
use minos\moderation\service\settings;
use minos\moderation\tests\Fake\Board;
use minos\moderation\tests\Fake\FormRequest;
use minos\moderation\tests\Fake\MemoryTemplate;
use Minos\Client\Signature;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The trace of refused signatures (audit K-60, shared rule of the plugins): a delivery the
 * webhook refuses answers the same `401` whatever the reason, and the ACP tells a wrong
 * secret from a drifting clock - on the gateway's own signature vector (the receiver at the
 * vector's moment, as `WebhookTest` does) and through the webhook controller and the ACP
 * page. Never the secret, the header or the body.
 */
final class SignatureTraceTest extends TestCase
{
	/** @var Board */
	private $board;

	/** @var array{secret:string,timestamp:int,body:string,header:string} */
	private $vector;

	protected function setUp(): void
	{
		$this->board = Board::open();
		$this->vector = json_decode((string) file_get_contents(__DIR__ . '/../../vendor/minos-moderation/client-php/vectors/signature.json'), true);
		$this->board->configure(array(), array(settings::WEBHOOK_SECRET => $this->vector['secret']));
		$GLOBALS['minos_test_form_key_valid'] = true;
	}

	protected function tearDown(): void
	{
		unset($GLOBALS['minos_test_form_key_valid']);
	}

	/**
	 * @return array<string,array{0:string,1:int,2:string,3:string}>
	 */
	public function refusals(): array
	{
		// Each: what changes in the gateway's vector, the clock's offset, the reason, and a word
		// the ACP's explanation of that reason must use.
		return array(
			'the clock 301 s late'   => array('', 301, 'stale', 'zegar'),
			'the clock 301 s early'  => array('', -301, 'stale', 'zegar'),
			'another body (a space)' => array(' ', 0, 'mismatch', 'sekret'),
			'no header'              => array('no-header', 0, 'malformed', 'nagłówka'),
		);
	}

	/**
	 * @dataProvider refusals
	 */
	public function testTheAcpTellsAWrongSecretFromAClockOnTheGatewaysVector(string $change, int $offset, string $reason, string $word): void
	{
		$body = ($change === ' ') ? $this->vector['body'] . ' ' : $this->vector['body'];
		$header = ($change === 'no-header') ? '' : $this->vector['header'];

		self::assertSame(401, $this->receive($body, $header, $this->vector['timestamp'] + $offset));

		self::assertSame($reason, $this->board->settings->rejections()['reason']);
		$page = $this->page();
		self::assertTrue($page['S_MINOS_REJECTED']);
		foreach (settings::REJECTION_REASONS as $any)
		{
			$explanation = $this->board->language->lang('MINOS_SIGNATURE_' . strtoupper($any));
			self::assertNotSame('MINOS_SIGNATURE_' . strtoupper($any), $explanation, 'every reason has its explanation');
			self::assertSame($any === $reason, strpos($page['MINOS_REJECTED'], $explanation) !== false, 'only the reason found: ' . $any);
		}
		self::assertStringContainsString($word, $this->board->language->lang('MINOS_SIGNATURE_' . strtoupper($reason)));
		self::assertStringContainsString(gmdate('Y-m-d H:i', $this->vector['timestamp'] + $offset), $page['MINOS_REJECTED'], 'when');
		$this->assertNothingSecretKept();
	}

	public function testEveryReasonGetsTheSameAnswer(): void
	{
		// Through the controller, on its own clock: signed now as the gateway signs.
		$secret = $this->vector['secret'];
		$body = $this->vector['body'];
		$answers = array(
			$this->answer($body, Signature::sign($secret, $body, time() - 301)),
			$this->answer($body, Signature::sign('inny-sekret-niz-ten-z-klucza-00', $body, time())),
			$this->answer($body, 'v1=' . str_repeat('a', 64)),
		);

		self::assertSame(array(array(401, ''), array(401, ''), array(401, '')), $answers);
		self::assertSame(3, $this->board->settings->rejections()['count']);
		self::assertSame('malformed', $this->board->settings->rejections()['reason'], 'the last one');
	}

	public function testADeliveryThatPassesForgetsTheRefusals(): void
	{
		$t = $this->vector['timestamp'];
		$this->receive($this->vector['body'] . ' ', $this->vector['header'], $t);
		$this->receive($this->vector['body'] . ' ', $this->vector['header'], $t);
		self::assertSame(2, $this->board->settings->rejections()['count']);

		// The vector's payload is another plugin's: accepted, then dropped as not ours.
		self::assertSame(200, $this->receive($this->vector['body'], $this->vector['header'], $t));

		self::assertNull($this->board->settings->rejections());
		self::assertFalse($this->page()['S_MINOS_REJECTED']);
	}

	public function testANewSecretForgetsTheRefusalsAndTheSameSecretKeepsThem(): void
	{
		$t = $this->vector['timestamp'];
		$this->receive($this->vector['body'] . ' ', $this->vector['header'], $t);

		$this->save(array('minos_webhook_secret' => ''));
		self::assertNotNull($this->board->settings->rejections(), 'an empty field keeps the secret, and the trace');

		$this->save(array('minos_webhook_secret' => 'nowy-sekret-webhooka-od-operatora'));
		self::assertNull($this->board->settings->rejections());
	}

	public function testWithoutAUsableStoredSecretNothingIsTraced(): void
	{
		$this->board->configure(array(), array(settings::WEBHOOK_SECRET => ''));

		self::assertSame(401, $this->receive($this->vector['body'], $this->vector['header'], $this->vector['timestamp']));

		self::assertNull($this->board->settings->rejections(), 'the page says the secret is missing instead');
	}

	public function testSwitchedOffNothingIsTraced(): void
	{
		$this->board->configure(array(settings::ENABLED => 0));

		self::assertSame(404, $this->receive($this->vector['body'] . ' ', $this->vector['header'], $this->vector['timestamp']));

		self::assertNull($this->board->settings->rejections());
	}

	/**
	 * One delivery to the receiver, at a moment of its clock.
	 *
	 * @return int The HTTP status.
	 */
	private function receive(string $body, string $header, int $now): int
	{
		return $this->board->receiver->receive($body, $header, $now)[0];
	}

	/**
	 * One delivery through the webhook controller, now.
	 *
	 * @return array{0:int,1:string} The status and the body of the answer.
	 */
	private function answer(string $body, string $header): array
	{
		$controller = new webhook(new Request(array('X-Wergiliusz-Podpis' => $header), $body), $this->board->receiver, $this->board->deferred, $this->board->dispatcher);
		$response = $controller->handle();
		$this->board->dispatcher->terminate();
		return array($response->getStatusCode(), (string) $response->getContent());
	}

	/**
	 * @return array<string,mixed> The ACP page's template variables.
	 */
	private function page(): array
	{
		$template = new MemoryTemplate();
		$controller = new acp($this->board->settings, $this->board->store, $this->board->forum, new FormRequest(), $template, $this->board->language,
			$this->board->log, new \phpbb\user(), new \phpbb\controller\helper(), './', 'php');
		$controller->display('index.php?i=minos');
		return $template->vars;
	}

	/**
	 * @param array<string,string> $fields
	 */
	private function save(array $fields): void
	{
		$controller = new acp($this->board->settings, $this->board->store, $this->board->forum, new FormRequest($fields + array(
			'minos_enabled'        => '1',
			'minos_gateway_url'    => Board::GATEWAY,
			'minos_api_key'        => '',
			'minos_profile'        => 'forum_adult',
			'minos_fail_mode'      => settings::FAIL_CLOSED,
			'minos_timeout_min'    => '20',
			'minos_censored'       => settings::CENSORED_PUBLISH,
			'minos_blocked'        => settings::HOLD,
			'submit'               => 'Wyślij',
		)), new MemoryTemplate(), $this->board->language, $this->board->log, new \phpbb\user(), new \phpbb\controller\helper(), './', 'php');
		$controller->display('index.php?i=minos');
	}

	private function assertNothingSecretKept(): void
	{
		$kept = (string) $this->board->config[settings::SIGNATURE_REJECTED] . json_encode($this->page(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
			. json_encode($this->board->log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		foreach (array($this->vector['secret'], substr($this->vector['header'], 13), $this->vector['body'], 'k-1') as $never)
		{
			self::assertStringNotContainsString($never, $kept);
		}
	}
}
