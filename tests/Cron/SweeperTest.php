<?php

namespace minos\moderation\tests\Cron;

use minos\moderation\cron\sweeper;
use minos\moderation\service\pending_store;
use minos\moderation\service\settings;
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

	public function testSwitchedOffTheTaskIsIdleAndHeldPostsWaitForAModerator(): void
	{
		$this->gateway->refuse(503, 'kolejka_niedostepna');
		$retrying = $this->board->posting('Pierwszy testowy wpis.')['post_id'];
		$waiting = $this->board->posting('Drugi testowy wpis.')['post_id'];
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
