<?php

namespace minos\moderation\tests\Acp;

use minos\moderation\controller\acp;
use minos\moderation\service\settings;
use minos\moderation\tests\Fake\Board;
use minos\moderation\tests\Fake\FormRequest;
use minos\moderation\tests\Fake\MemoryTemplate;
use PHPUnit\Framework\TestCase;

/**
 * The ACP page: saving the settings, and never showing the key or the secret back.
 */
final class AcpControllerTest extends TestCase
{
	/** A key the administrator types in. */
	const NEW_KEY = 'wgb2b_nowy_klucz_forum_0123456789';

	/** A secret with characters phpBB's request escaping would change. */
	const NEW_SECRET = 'S3kret&<webhooka>"z-cudzyslowem\'';

	/** @var Board */
	private $board;

	/** @var MemoryTemplate */
	private $template;

	protected function setUp(): void
	{
		$this->board = Board::open();
		$this->template = new MemoryTemplate();
		$GLOBALS['minos_test_form_key_valid'] = true;
	}

	protected function tearDown(): void
	{
		unset($GLOBALS['minos_test_form_key_valid']);
	}

	public function testSavingStoresEverySetting(): void
	{
		$this->submit(array(
			'minos_enabled'        => '1',
			'minos_gateway_url'    => 'https://gateway.example/',
			'minos_api_key'        => self::NEW_KEY,
			'minos_webhook_secret' => self::NEW_SECRET,
			'minos_profile'        => 'forum_teen',
			'minos_fail_mode'      => settings::FAIL_CLOSED,
			'minos_timeout_min'    => '30',
			'minos_censored'       => settings::HOLD,
			'minos_blocked'        => settings::BLOCKED_DELETE,
			'minos_forums'         => array('3'),
		));

		self::assertTrue($this->template->vars['S_MINOS_SAVED'], (string) $this->template->vars['ERROR_MSG']);
		$settings = new settings($this->board->config, $this->board->config_text);
		self::assertSame('https://gateway.example', $settings->gateway_url());
		self::assertSame(self::NEW_KEY, $settings->api_key());
		self::assertSame(self::NEW_SECRET, $settings->webhook_secret(), 'the secret is stored exactly as typed');
		self::assertSame('forum_teen', $settings->profile());
		self::assertSame(settings::FAIL_CLOSED, $settings->fail_mode());
		self::assertSame(1800, $settings->timeout_seconds());
		self::assertSame(settings::HOLD, $settings->censored_mode());
		self::assertSame(settings::BLOCKED_DELETE, $settings->blocked_mode());
		self::assertSame(array(3), $settings->forum_ids());
		self::assertCount(1, $this->board->log->of('LOG_MINOS_SETTINGS_UPDATED'));
	}

	public function testTheKeyAndTheSecretAreNeverShownBeyondAPrefix(): void
	{
		$this->submit($this->form(array('minos_api_key' => self::NEW_KEY, 'minos_webhook_secret' => self::NEW_SECRET)));

		$page = $this->template->everything();
		self::assertStringNotContainsString(self::NEW_KEY, $page);
		self::assertStringNotContainsString(htmlspecialchars(self::NEW_KEY), $page);
		self::assertStringNotContainsString(substr(self::NEW_SECRET, 0, 8), $page);
		self::assertStringStartsWith('wgb2b_nowy', $this->template->vars['MINOS_KEY_HINT']);
		self::assertLessThanOrEqual(settings::KEY_HINT_LENGTH + 3, strlen($this->template->vars['MINOS_KEY_HINT']));
		self::assertStringNotContainsString(self::NEW_KEY, json_encode($this->board->log->entries));
	}

	public function testEmptyKeyAndSecretFieldsKeepTheStoredOnes(): void
	{
		$this->submit($this->form(array('minos_api_key' => '', 'minos_webhook_secret' => '')));

		$settings = new settings($this->board->config, $this->board->config_text);
		self::assertSame(Board::KEY, $settings->api_key());
		self::assertSame(Board::SECRET, $settings->webhook_secret());
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public function invalidForms(): array
	{
		return array(
			'plain http off the loopback' => array(array('minos_gateway_url' => 'http://gateway.example'), 'MINOS_ERROR_GATEWAY_URL'),
			'credentials in the URL'      => array(array('minos_gateway_url' => 'https://ktos:haslo@gateway.example'), 'MINOS_ERROR_GATEWAY_URL'),
			'a key of another shape'      => array(array('minos_api_key' => 'sk_live_0123456789'), 'MINOS_ERROR_API_KEY'),
			'a short secret'              => array(array('minos_webhook_secret' => 'krotki'), 'MINOS_ERROR_WEBHOOK_SECRET'),
			'an unknown profile'          => array(array('minos_profile' => 'demo_dziecko'), 'MINOS_ERROR_CHOICE'),
			'an unknown failure mode'     => array(array('minos_fail_mode' => 'fail-maybe'), 'MINOS_ERROR_CHOICE'),
			'a timeout below the TTL'     => array(array('minos_timeout_min' => '10'), 'MINOS_ERROR_TIMEOUT'),
			'a timeout under 20 minutes'  => array(array('minos_timeout_min' => '19'), 'MINOS_ERROR_TIMEOUT'),
		);
	}

	/**
	 * @dataProvider invalidForms
	 * @param array<string,mixed> $fields
	 */
	public function testAnInvalidFormChangesNothing(array $fields, string $error): void
	{
		$before = array($this->board->config[settings::GATEWAY_URL], $this->board->config_text->values);

		$this->submit($this->form($fields));

		self::assertFalse($this->template->vars['S_MINOS_SAVED']);
		self::assertStringContainsString($this->board->language->lang($error), $this->template->vars['ERROR_MSG']);
		self::assertSame($before, array($this->board->config[settings::GATEWAY_URL], $this->board->config_text->values));
	}

	public function testTheMockGatewayOnTheLoopbackIsAccepted(): void
	{
		$this->submit($this->form(array('minos_gateway_url' => 'http://127.0.0.1:8100')));

		self::assertTrue($this->template->vars['S_MINOS_SAVED']);
	}

	public function testAnInvalidFormKeyChangesNothing(): void
	{
		$GLOBALS['minos_test_form_key_valid'] = false;

		$this->submit($this->form(array('minos_api_key' => self::NEW_KEY)));

		self::assertFalse($this->template->vars['S_MINOS_SAVED']);
		self::assertSame(Board::KEY, $this->board->config_text->values[settings::API_KEY]);
	}

	public function testTheWebhookUrlCarriesNoSessionId(): void
	{
		$this->show(new FormRequest());

		self::assertSame('https://forum.example/app.php/minos/webhook', $this->template->vars['MINOS_WEBHOOK_URL']);
	}

	public function testASessionIdAddedByAHookIsRemovedFromTheWebhookUrl(): void
	{
		// phpBB's append_sid() hooks and $_EXTRA_URL can still add one.
		$helper = new class extends \phpbb\controller\helper {
			public function route($route, array $params = array(), $is_amp = true, $session_id = false, $reference_type = 1)
			{
				return 'https://forum.example/app.php/minos/webhook?sid=0123456789abcdef0123456789abcdef';
			}
		};

		$this->show(new FormRequest(), $helper);

		self::assertSame('https://forum.example/app.php/minos/webhook', $this->template->vars['MINOS_WEBHOOK_URL']);
	}

	public function testTheLastRefusalIsShownWithAnExplanation(): void
	{
		$this->board->settings->record_error(401, 'brak_klucza', time());

		$this->show(new FormRequest());

		self::assertTrue($this->template->vars['S_MINOS_LAST_ERROR']);
		self::assertSame('401 brak_klucza', $this->template->vars['MINOS_LAST_ERROR']);
		self::assertSame($this->board->language->lang('MINOS_REFUSAL_BRAK_KLUCZA'), $this->template->vars['MINOS_LAST_ERROR_HELP']);
	}

	public function testTheListShowsHandledPostsAndTheSupportFlag(): void
	{
		$id = $this->board->posting('Testowy wpis o bardzo złym dniu.')['post_id'];
		$this->board->deliver(array('id' => 'phpbb:' . $id, 'status' => 'ocenione', 'kwalifikacja' => 'zablokowane',
			'kategorie' => array('samookaleczenie'), 'wsparcie' => true));

		$this->show(new FormRequest());

		$row = $this->template->blocks['minos_rows'][0];
		self::assertSame($id, $row['POST_ID']);
		self::assertTrue($row['S_SUPPORT']);
		self::assertTrue($row['S_HELD']);
		self::assertSame('zablokowane', $row['VERDICT']);
		self::assertStringContainsString('mode=approve_details', $row['U_MCP']);
	}

	public function testAMaskedTextLeftToAModeratorIsShownInTheList(): void
	{
		$id = $this->board->posting('To jest brzydkie słowo.')['post_id'];
		$this->board->deliver(array('id' => 'phpbb:' . $id, 'status' => 'ocenione', 'kwalifikacja' => 'ocenzurowane',
			'ocenzurowany' => 'To jest <███> słowo.'));

		$this->show(new FormRequest());

		$row = $this->template->blocks['minos_rows'][0];
		self::assertTrue($row['S_MASK_MANUAL']);
		self::assertSame('To jest &lt;███&gt; słowo.', $row['MASKED'], 'escaped for the page');
	}

	public function testAFailOpenApprovalIsShownAsSuch(): void
	{
		$this->board->configure(array(settings::FAIL_MODE => settings::FAIL_OPEN));
		$this->board->posting('Zwykły testowy wpis.');
		$this->board->sweeper()->sweep(time() + 20 * 60 + 1);

		$this->show(new FormRequest());

		self::assertSame('Opublikowany bez oceny (fail-open)', $this->template->blocks['minos_rows'][0]['STATUS']);
	}

	/**
	 * A complete, valid form with some fields replaced.
	 *
	 * @param array<string,mixed> $fields
	 * @return array<string,mixed>
	 */
	private function form(array $fields): array
	{
		return $fields + array(
			'minos_enabled'        => '1',
			'minos_gateway_url'    => Board::GATEWAY,
			'minos_api_key'        => '',
			'minos_webhook_secret' => '',
			'minos_profile'        => 'forum_adult',
			'minos_fail_mode'      => settings::FAIL_OPEN,
			'minos_timeout_min'    => '20',
			'minos_censored'       => settings::CENSORED_PUBLISH,
			'minos_blocked'        => settings::HOLD,
		);
	}

	/**
	 * @param array<string,mixed> $fields
	 */
	private function submit(array $fields): void
	{
		$this->show(new FormRequest($fields + array('submit' => 'Wyślij')));
	}

	private function show(FormRequest $request, ?\phpbb\controller\helper $helper = null): void
	{
		$controller = new acp($this->board->settings, $this->board->store, $request, $this->template, $this->board->language,
			$this->board->log, new \phpbb\user(), $helper ?: new \phpbb\controller\helper(), './', 'php');
		$controller->display('index.php?i=minos');
	}
}
