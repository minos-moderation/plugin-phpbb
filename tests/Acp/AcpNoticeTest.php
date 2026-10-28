<?php

namespace minos\moderation\tests\Acp;

use minos\moderation\event\acp_notice;
use minos\moderation\service\settings;
use minos\moderation\tests\Fake\Board;
use minos\moderation\tests\Fake\MemoryTemplate;
use PHPUnit\Framework\TestCase;

/**
 * The notice on every ACP page while the extension is switched on but cannot submit posts
 * (the plugins' shared rule D9.12).
 */
final class AcpNoticeTest extends TestCase
{
	/** @var Board */
	private $board;

	/** @var MemoryTemplate */
	private $template;

	protected function setUp(): void
	{
		$this->board = Board::open();
		$this->template = new MemoryTemplate();
	}

	public function testItListensToEveryAcpPagesHeader(): void
	{
		self::assertSame(array('core.adm_page_header' => 'show_problems'), acp_notice::getSubscribedEvents());
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:array<string,string>,2:array<int,string>}>
	 */
	public function unusableConfigurations(): array
	{
		return array(
			'no key'            => array(array(), array(settings::API_KEY => ''), array('MINOS_PROBLEM_API_KEY')),
			'no secret'         => array(array(), array(settings::WEBHOOK_SECRET => ''), array('MINOS_PROBLEM_WEBHOOK_SECRET')),
			'a plain-http gateway' => array(array(settings::GATEWAY_URL => 'http://gateway.example'), array(), array('MINOS_PROBLEM_GATEWAY_URL')),
			'nothing at all'    => array(array(settings::GATEWAY_URL => ''), array(settings::API_KEY => '', settings::WEBHOOK_SECRET => ''),
				array('MINOS_PROBLEM_GATEWAY_URL', 'MINOS_PROBLEM_API_KEY', 'MINOS_PROBLEM_WEBHOOK_SECRET')),
		);
	}

	/**
	 * @dataProvider unusableConfigurations
	 * @param array<string,mixed>  $config
	 * @param array<string,string> $config_text
	 * @param array<int,string>    $problems
	 */
	public function testSwitchedOnWithoutAUsableConfigurationEveryAcpPageSaysWhy(array $config, array $config_text, array $problems): void
	{
		$this->board->configure($config, $config_text);

		$this->header();

		self::assertTrue($this->template->vars['S_MINOS_NOT_READY_NOTICE']);
		foreach ($problems as $key)
		{
			self::assertStringContainsString($this->board->language->lang($key), $this->template->vars['MINOS_NOT_READY_NOTICE']);
		}
		foreach (array(Board::KEY, Board::SECRET, 'gateway.example') as $stored)
		{
			self::assertStringNotContainsString($stored, $this->template->everything(), 'what is missing, never a stored value');
		}
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:array<string,string>}>
	 */
	public function nothingToSay(): array
	{
		return array(
			'ready'                     => array(array(), array()),
			'switched off, unconfigured' => array(array(settings::ENABLED => 0), array(settings::API_KEY => '')),
		);
	}

	/**
	 * @dataProvider nothingToSay
	 * @param array<string,mixed>  $config
	 * @param array<string,string> $config_text
	 */
	public function testOtherwiseThePagesStayAsTheyAre(array $config, array $config_text): void
	{
		$this->board->configure($config, $config_text);

		$this->header();

		self::assertSame(array(), $this->template->vars);
	}

	private function header(): void
	{
		$notice = new acp_notice($this->board->settings, $this->template, $this->board->language);
		$notice->show_problems(new \phpbb\event\data(array('page_title' => 'Panel administracji', 'adm_page_header_override' => false)));
	}
}
