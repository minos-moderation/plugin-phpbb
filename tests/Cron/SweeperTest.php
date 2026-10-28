<?php

namespace minos\moderation\tests\Cron;

use minos\moderation\cron\sweeper;
use minos\moderation\service\pending_store;
use minos\moderation\service\settings;
use minos\moderation\service\submitter;
use minos\moderation\tests\Fake\Board;
use minos\moderation\tests\Fake\RecordingTransport;
use PHPUnit\Framework\TestCase;

/**
 * The cron task: time-outs, retries, leftovers and forgetting.
 */
final class SweeperTest extends TestCase
{
	/** @var Board */
	private $board;

	/** @var RecordingTransport */
	private $gateway;

	protected function setUp(): void
	{
		$this->gateway = new RecordingTransport();
		$this->board = Board::open(':memory:', $this->gateway);
	}

	/**
	 * @return array<string,array{0:string,1:int,2:string}>
	 */
	public function failureModes(): array
	{
		return array(
			'fail-open'   => array(settings::FAIL_OPEN, ITEM_APPROVED, pending_store::PUBLISHED),
			'fail-closed' => array(settings::FAIL_CLOSED, ITEM_UNAPPROVED, pending_store::HELD),
		);
	}

	/**
	 * @dataProvider failureModes
	 */
	public function testAPostWithoutAVerdictInTimeGetsTheFailureMode(string $mode, int $visibility, string $status): void
	{
		$this->board->configure(array(settings::FAIL_MODE => $mode));
		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];

		$this->board->sweeper()->sweep(time() + 20 * 60 + 1);

		self::assertSame($visibility, (int) $this->board->post($id)['post_visibility']);
		self::assertSame($status, $this->board->row($id)['status']);
		self::assertSame(pending_store::VERDICT_TIMEOUT, $this->board->row($id)['verdict']);
	}

	public function testAPostStillWithinTheTimeoutKeepsWaiting(): void
	{
		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];

		$this->board->sweeper()->sweep(time() + 15 * 60);

		self::assertSame(pending_store::PENDING, $this->board->row($id)['status']);
		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
	}

	public function testALongerTimeoutIsHonoured(): void
	{
		$this->board->configure(array(settings::TIMEOUT_MIN => 60));
		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];

		$this->board->sweeper()->sweep(time() + 30 * 60);
		self::assertSame(pending_store::PENDING, $this->board->row($id)['status']);

		$this->board->sweeper()->sweep(time() + 60 * 60 + 1);
		self::assertSame(pending_store::VERDICT_TIMEOUT, $this->board->row($id)['verdict']);
	}

	public function testTheWaitForAVerdictCountsFromTheGatewaysAcceptance(): void
	{
		// Audit probe B P3, the other way round: a post the gateway accepts at minute 16 used to
		// fall to the failure mode at minute 20, 4 of the gateway's 15 minutes later.
		$this->board->configure(array(settings::FAIL_MODE => settings::FAIL_OPEN));
		$t0 = time();
		$this->gateway->refuse(429, 'kolejka_pelna', 60);
		$id = $this->board->posting('Post czekający na miejsce w kolejce.')['post_id'];
		foreach (array(5, 10) as $minute)
		{
			$this->gateway->refuse(429, 'kolejka_pelna', 60);
			$this->board->sweeper()->sweep($t0 + $minute * 60);
		}
		$this->board->sweeper()->sweep($t0 + 16 * 60);
		self::assertSame(pending_store::PENDING, $this->board->row($id)['status'], 'accepted at minute 16');

		$this->board->sweeper()->sweep($t0 + 20 * 60 + 1);
		self::assertSame(pending_store::PENDING, $this->board->row($id)['status'], 'still waiting at minute 20');
		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);

		$this->board->sweeper()->sweep($t0 + (16 + 20) * 60 - 1);
		self::assertSame(pending_store::PENDING, $this->board->row($id)['status'], 'still waiting just before 16 + 20 minutes');

		$this->board->sweeper()->sweep($t0 + (16 + 20) * 60 + 1);
		self::assertSame(array(pending_store::PUBLISHED, pending_store::VERDICT_TIMEOUT), array($this->board->row($id)['status'], $this->board->row($id)['verdict']));
		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
	}

	public function testAPostThatNeverGotThroughAlsoTimesOut(): void
	{
		$this->gateway->refuse(503, 'kolejka_niedostepna', 86400);
		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];

		$this->board->sweeper()->sweep(time() + 20 * 60 + 1);

		self::assertSame(pending_store::VERDICT_TIMEOUT, $this->board->row($id)['verdict']);
		self::assertSame(pending_store::HELD, $this->board->row($id)['status'], 'the default fail-closed');
		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
	}

	public function testADueRetryIsSentAgain(): void
	{
		$this->gateway->refuse(503, 'kolejka_niedostepna');
		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];

		$this->board->sweeper()->sweep(time() + 30);
		self::assertCount(1, $this->gateway->requests, 'not due yet');

		$this->board->sweeper()->sweep(time() + 61);
		self::assertCount(2, $this->gateway->requests);
		self::assertSame(pending_store::PENDING, $this->board->row($id)['status']);
	}

	/**
	 * @return array<string,array{0:int|null,1:string}>
	 */
	public function answersThatStopARun(): array
	{
		return array(
			'a 503'            => array(503, 'kolejka_niedostepna'),
			'a full queue'     => array(429, 'kolejka_pelna'),
			'the minute limit' => array(429, 'limit_minutowy_klucza'),
			'no answer at all' => array(null, 'network'),
		);
	}

	/**
	 * @dataProvider answersThatStopARun
	 */
	public function testAfterTheFirstAnswerThatAsksToWaitARunSendsNoFurtherBatch(?int $status, string $code): void
	{
		// Audit C-22, the other way round: a run used to send every batch after a 503, up to
		// five requests of 10 seconds each.
		$this->gateway->refuse(503, 'kolejka_niedostepna');
		$now = time();
		$ids = array();
		for ($i = 0; $i < 45; $i++)
		{
			$ids[] = $this->board->posting('Testowy wpis numer ' . $i . '.')['post_id'];
		}
		self::assertCount(1, $this->gateway->requests, 'the first refusal paused sending');

		if ($status === null)
		{
			$this->gateway->script[] = null;
		}
		else
		{
			$this->gateway->refuse($status, $code);
		}
		$this->board->sweeper()->sweep($now + 61);

		self::assertCount(2, $this->gateway->requests, 'one batch, then no further one');
		self::assertCount(submitter::MAX_ITEMS, $this->gateway->items(1));
		$untouched = 0;
		foreach ($ids as $id)
		{
			$row = $this->board->row($id);
			self::assertSame(pending_store::QUEUED, $row['status']);
			$untouched += ($row['attempts'] === '0') ? 1 : 0;
		}
		self::assertSame(45 - 1 - submitter::MAX_ITEMS, $untouched, 'the rest was never sent');

		$this->board->sweeper()->sweep($now + 61 + 30);
		self::assertCount(2, $this->gateway->requests, 'nothing during the new pause');

		$this->board->sweeper()->sweep($now + 61 + 61);
		self::assertCount(5, $this->gateway->requests, 'after it, batches of 20, 20 and 5');
		foreach ($ids as $id)
		{
			self::assertSame(pending_store::PENDING, $this->board->row($id)['status']);
		}
	}

	public function testSwitchedOffTheTaskIsIdleAndHeldPostsWaitForAModerator(): void
	{
		$waiting = $this->board->posting('Pierwszy testowy wpis.')['post_id'];
		$this->gateway->refuse(503, 'kolejka_niedostepna');
		$retrying = $this->board->posting('Drugi testowy wpis.')['post_id'];
		$this->board->configure(array(settings::ENABLED => 0, settings::FAIL_MODE => settings::FAIL_OPEN));
		$sweeper = $this->board->sweeper();

		self::assertFalse($sweeper->is_runnable());
		$sweeper->sweep(time() + 30 * 86400);

		self::assertCount(2, $this->gateway->requests, 'no retry');
		self::assertSame(pending_store::QUEUED, $this->board->row($retrying)['status']);
		self::assertSame(pending_store::PENDING, $this->board->row($waiting)['status'], 'no time-out, even fail-open');
		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($waiting)['post_visibility']);
		self::assertSame(array(), $this->board->visibility->calls);

		$this->board->configure(array(settings::ENABLED => 1));
		self::assertTrue($this->board->sweeper()->is_runnable(), 'switched on again, it picks up');
	}

	public function testFinishedRowsAreForgottenAfterThirtyDaysAndWaitingOnesAreNot(): void
	{
		$held = $this->board->posting('Pierwszy testowy wpis.')['post_id'];
		$this->board->deliver(array('id' => 'phpbb:' . $held, 'status' => 'ocenione', 'kwalifikacja' => 'zablokowane'));
		$waiting = $this->board->posting('Drugi testowy wpis.')['post_id'];
		// Kept from timing out, so that only the pruning can remove it.
		$this->board->db->sql_query('UPDATE phpbb_minos_pending SET submitted_at = ' . (time() + 40 * 86400) . ' WHERE post_id = ' . $waiting);

		$this->board->sweeper()->sweep(time() + 29 * 86400);
		self::assertNotNull($this->board->row($held));

		$this->board->sweeper()->sweep(time() + 31 * 86400);
		self::assertNull($this->board->row($held));
		self::assertSame(pending_store::PENDING, $this->board->row($waiting)['status']);
	}

	public function testTheTaskRunsEveryFiveMinutes(): void
	{
		$sweeper = $this->board->sweeper();
		self::assertSame('cron.task.minos.moderation.sweeper', $sweeper->get_name());
		self::assertTrue($sweeper->should_run());

		$sweeper->run();

		self::assertEqualsWithDelta(time(), (int) $this->board->config[settings::LAST_SWEEP], 2);
		self::assertFalse($sweeper->should_run());
		$this->board->config->set(settings::LAST_SWEEP, time() - sweeper::INTERVAL_S);
		self::assertTrue($sweeper->should_run());
	}
}
