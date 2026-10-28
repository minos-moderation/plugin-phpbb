<?php

namespace minos\moderation\tests\Acp;

use minos\moderation\controller\acp;
use minos\moderation\service\pending_store;
use minos\moderation\tests\Fake\Board;
use minos\moderation\tests\Fake\FormRequest;
use minos\moderation\tests\Fake\MemoryTemplate;
use minos\moderation\tests\Fake\RecordingTransport;
use Minos\Client\Signature;
use PHPUnit\Framework\TestCase;

/**
 * The ACP's numbers of the last 7 days: how long the gateway's verdicts took from its `202`
 * to their delivery (P50, P95) and how many of each it gave - posted, delivered through the
 * webhook, read on the page.
 */
final class VerdictStatsTest extends TestCase
{
	/** @var Board */
	private $board;

	/** @var RecordingTransport */
	private $gateway;

	/** @var MemoryTemplate */
	private $template;

	protected function setUp(): void
	{
		$this->gateway = new RecordingTransport();
		$this->board = Board::open(':memory:', $this->gateway);
		$this->template = new MemoryTemplate();
	}

	public function testThePageShowsThePercentilesAndTheCountsOfTheLastSevenDays(): void
	{
		// Ten verdicts that came 1 to 10 minutes after their 202.
		$kinds = array('bezpieczne', 'bezpieczne', 'bezpieczne', 'bezpieczne', 'ocenzurowane', 'ocenzurowane', 'ocenzurowane',
			'zablokowane', 'zablokowane', 'nieocenione');
		foreach ($kinds as $index => $kind)
		{
			$this->deliver_after($kind, ($index + 1) * 60);
		}
		// One more, eight days old: outside the window.
		$old = $this->deliver_after('zablokowane', 3600);
		$this->board->db->sql_query('UPDATE phpbb_minos_pending SET received_at = received_at - ' . (8 * 86400)
			. ', submitted_at = submitted_at - ' . (8 * 86400) . ' WHERE post_id = ' . $old);
		// And a post the gateway never answered in time.
		$this->board->posting('Wpis bez werdyktu.');
		$this->board->sweeper()->sweep(time() + 20 * 60 + 1);
		$requests = count($this->gateway->requests);

		$this->show();

		$lang = $this->board->language;
		self::assertTrue($this->template->vars['S_MINOS_STATS']);
		self::assertSame($lang->lang('MINOS_STATS_WAIT', $lang->lang('MINOS_DURATION_MIN', 5, 0), $lang->lang('MINOS_DURATION_MIN', 10, 0), 10),
			$this->template->vars['MINOS_STATS_WAIT'], 'nearest rank: the 5th and the 10th of ten');
		self::assertSame($lang->lang('MINOS_STATS_VERDICTS', 4, 3, 2, 1, 1), $this->template->vars['MINOS_STATS_VERDICTS']);
		self::assertCount($requests, $this->gateway->requests, 'the page asks the gateway nothing');
		foreach (array('MINOS_STATS_WAIT', 'MINOS_STATS_VERDICTS') as $var)
		{
			self::assertStringNotContainsString('Wpis', $this->template->vars[$var], 'never a post\'s text');
			self::assertStringNotContainsString(Board::KEY, $this->template->vars[$var]);
		}
	}

	public function testTheWaitIsMeasuredFromTheGatewaysAcceptanceToTheDelivery(): void
	{
		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];
		$accepted = (int) $this->board->row($id)['submitted_at'];
		self::assertSame(200, $this->board->deliver(array('id' => 'phpbb:' . $id, 'status' => 'ocenione', 'kwalifikacja' => 'bezpieczne')));

		$row = $this->board->row($id);
		self::assertSame($accepted, (int) $row['submitted_at'], 'the 202 stays the start');
		self::assertEqualsWithDelta(time(), (int) $row['received_at'], 2, 'the delivery is the end');
		$stats = $this->board->store->verdict_stats(time() - 60);
		self::assertSame(1, $stats['count']);
		self::assertSame(1, $stats['verdicts']['bezpieczne']);
	}

	public function testAnEditForgetsTheWaitOfTheTextBefore(): void
	{
		// A verdict recorded, not applied yet, when the author edits: the new revision waits anew.
		$id = $this->board->posting('Pierwsza wersja wpisu.')['post_id'];
		$body = json_encode(array('id' => 'phpbb:' . $id, 'status' => 'ocenione', 'kwalifikacja' => 'bezpieczne'));
		self::assertSame(array(200, $id), $this->board->receiver->receive($body, Signature::sign(Board::SECRET, $body, time()), time()));
		self::assertSame(pending_store::RECEIVED, $this->board->row($id)['status']);

		$this->board->edit($id, 'Druga wersja wpisu.');

		self::assertSame('1', $this->board->row($id)['revision']);
		self::assertSame('0', $this->board->row($id)['received_at']);
		self::assertSame(0, $this->board->store->verdict_stats(time() - 60)['count']);
	}

	public function testWithoutVerdictsThePageSaysSo(): void
	{
		$this->show();

		self::assertFalse($this->template->vars['S_MINOS_STATS']);
		self::assertSame('', $this->template->vars['MINOS_STATS_WAIT']);
	}

	/**
	 * @return array<string,array{0:int,1:string,2:array<int,int>}>
	 */
	public function durations(): array
	{
		return array(
			'seconds'           => array(42, 'MINOS_DURATION_S', array(42)),
			'minutes, seconds'  => array(185, 'MINOS_DURATION_MIN', array(3, 5)),
			'hours, minutes'    => array(2 * 3600 + 7 * 60 + 30, 'MINOS_DURATION_H', array(2, 7)),
		);
	}

	/**
	 * @dataProvider durations
	 * @param array<int,int> $parts
	 */
	public function testAWaitReadsAsTheAdministratorWouldSayIt(int $wait, string $key, array $parts): void
	{
		$this->deliver_after('bezpieczne', $wait);

		$this->show();

		$shown = $this->board->language->lang($key, ...$parts);
		self::assertSame($this->board->language->lang('MINOS_STATS_WAIT', $shown, $shown, 1), $this->template->vars['MINOS_STATS_WAIT']);
	}

	/**
	 * A post accepted by the gateway and its verdict delivered through the webhook, the 202
	 * having come a given time before the delivery.
	 */
	private function deliver_after(string $kind, int $wait): int
	{
		$id = $this->board->posting('Wpis testowy przed werdyktem ' . $kind . '.')['post_id'];
		$payload = ($kind === 'nieocenione')
			? array('id' => 'phpbb:' . $id, 'status' => 'nieocenione')
			: array('id' => 'phpbb:' . $id, 'status' => 'ocenione', 'kwalifikacja' => $kind);
		if ($kind === 'ocenzurowane')
		{
			$payload['ocenzurowany'] = $this->board->forum->text_of($this->board->forum->load_post($id));
		}
		self::assertSame(200, $this->board->deliver($payload));
		$this->board->db->sql_query('UPDATE phpbb_minos_pending SET submitted_at = received_at - ' . $wait . ' WHERE post_id = ' . $id);
		return $id;
	}

	private function show(): void
	{
		$controller = new acp($this->board->settings, $this->board->store, $this->board->forum, new FormRequest(), $this->template,
			$this->board->language, $this->board->log, new \phpbb\user(), new \phpbb\controller\helper(), './', 'php');
		$controller->display('index.php?i=minos');
	}
}
