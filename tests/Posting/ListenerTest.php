<?php

namespace minos\moderation\tests\Posting;

use minos\moderation\event\listener;
use minos\moderation\service\pending_store;
use minos\moderation\service\settings;
use minos\moderation\tests\Fake\Board;
use minos\moderation\tests\Fake\RecordingTransport;
use PHPUnit\Framework\TestCase;

/**
 * Which posts the extension holds, through phpBB's posting events.
 */
final class ListenerTest extends TestCase
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

	public function testItListensToTheVerifiedPhpbbEvents(): void
	{
		self::assertSame(array(
			'core.posting_modify_submit_post_before',
			'core.submit_post_end',
			'core.mcp_queue_get_posts_modify_post_row',
			'core.mcp_queue_approve_details_template',
		), array_keys(listener::getSubscribedEvents()));
		foreach (listener::getSubscribedEvents() as $method)
		{
			self::assertTrue(method_exists(listener::class, $method), $method);
		}
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function newPostModes(): array
	{
		return array('a new topic' => array('post'), 'a reply' => array('reply'), 'a quote reply' => array('quote'));
	}

	/**
	 * @dataProvider newPostModes
	 */
	public function testANewPostIsHeldAndSent(string $mode): void
	{
		$posted = $this->board->posting('Zwykły testowy wpis.', $mode);

		self::assertSame(ITEM_UNAPPROVED, $posted['visibility']);
		self::assertSame(pending_store::PENDING, $this->board->row($posted['post_id'])['status']);
		self::assertCount(1, $this->gateway->requests);
	}

	/**
	 * @return array<string,array{0:callable}>
	 */
	public function postsNotHeld(): array
	{
		return array(
			'an administrator' => array(static function (Board $board) {
				$board->auth->global['a_'] = true;
			}),
			'a moderator of the forum' => array(static function (Board $board) {
				$board->auth->local['m_'][Board::FORUM] = true;
			}),
			'a forum the extension does not cover' => array(static function (Board $board) {
				$board->configure(array(), array(settings::FORUMS => '[3]'));
			}),
			'the extension switched off' => array(static function (Board $board) {
				$board->configure(array(settings::ENABLED => 0));
			}),
		);
	}

	/**
	 * @return array<string,array{0:callable}>
	 */
	public function unusableConfigurations(): array
	{
		return array(
			'no key' => array(static function (Board $board) {
				$board->configure(array(), array(settings::API_KEY => ''));
			}),
			'no webhook secret' => array(static function (Board $board) {
				$board->configure(array(), array(settings::WEBHOOK_SECRET => ''));
			}),
			'a plain-http gateway off the loopback' => array(static function (Board $board) {
				$board->configure(array(settings::GATEWAY_URL => 'http://gateway.example'));
			}),
		);
	}

	/**
	 * @dataProvider unusableConfigurations
	 */
	public function testSwitchedOnWithoutAUsableConfigurationANewPostGetsTheFailureModeAtOnce(callable $arrange): void
	{
		// Shared rule D9.12: such a post used to be published as if the extension were off.
		$arrange($this->board);

		$posted = $this->board->posting('Zwykły testowy wpis.');

		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($posted['post_id'])['post_visibility'], 'the default fail-closed');
		$row = $this->board->row($posted['post_id']);
		self::assertSame(array(pending_store::HELD, pending_store::VERDICT_REFUSED, 'not_configured'), array($row['status'], $row['verdict'], $row['error_code']));
		self::assertSame(array(), $this->gateway->requests);
	}

	public function testSwitchedOnWithoutAKeyFailOpenPublishesUnassessed(): void
	{
		$this->board->configure(array(settings::FAIL_MODE => settings::FAIL_OPEN), array(settings::API_KEY => ''));

		$posted = $this->board->posting('Zwykły testowy wpis.');

		self::assertSame(ITEM_APPROVED, (int) $this->board->post($posted['post_id'])['post_visibility']);
		self::assertGreaterThan(0, (int) $this->board->row($posted['post_id'])['approved_at'], 'marked as a fail-open publication');
		self::assertSame(array(), $this->gateway->requests);
	}

	public function testAnEditOfAWaitingPostWithoutAUsableConfigurationGetsTheFailureModeAtOnce(): void
	{
		$id = $this->board->posting('Pierwsza wersja wpisu.')['post_id'];
		$this->board->configure(array(), array(settings::WEBHOOK_SECRET => ''));

		$this->board->edit($id, 'Druga wersja wpisu.');

		self::assertSame(array('1', pending_store::HELD, 'not_configured'), array($this->board->row($id)['revision'], $this->board->row($id)['status'], $this->board->row($id)['error_code']));
		self::assertCount(1, $this->gateway->requests, 'only the first version was sent');
	}

	/**
	 * @dataProvider postsNotHeld
	 */
	public function testAPostTheExtensionDoesNotCoverIsPublishedAsBefore(callable $arrange): void
	{
		$arrange($this->board);

		$posted = $this->board->posting('Zwykły testowy wpis.');

		self::assertSame(ITEM_APPROVED, $posted['visibility']);
		self::assertNull($this->board->row($posted['post_id']));
		self::assertSame(array(), $this->gateway->requests);
	}

	public function testAPostPhpbbQueuesForAModeratorAnywayIsNotSent(): void
	{
		$this->board->auth->global['f_noapprove'] = false;

		$posted = $this->board->posting('Zwykły testowy wpis.');

		self::assertSame(ITEM_UNAPPROVED, $posted['visibility'], 'phpBB\'s own queue');
		self::assertArrayNotHasKey('force_approved_state', $posted['data']);
		self::assertNull($this->board->row($posted['post_id']));
		self::assertSame(array(), $this->gateway->requests);
	}

	public function testAVisibilityAnotherExtensionChoseIsRespected(): void
	{
		$posted = $this->board->posting('Zwykły testowy wpis.', 'post', array('data' => array('force_approved_state' => ITEM_APPROVED)));

		self::assertSame(ITEM_APPROVED, $posted['visibility']);
		self::assertSame(array(), $this->gateway->requests);
	}

	public function testAPostWithNothingToAssessIsNotHeld(): void
	{
		// No text and no link (attachments only, or formatting around nothing): nothing a
		// reader could see, nothing to hold; the title alone is never sent.
		$xml = '<r><B><s>[b]</s><e>[/b]</e></B> </r>';

		$posted = $this->board->posting('', 'post', array('xml' => $xml));

		self::assertSame(ITEM_APPROVED, $posted['visibility']);
		self::assertSame(array(), $this->gateway->requests);
	}

	/**
	 * @return array<string,array{0:string,1:int,2:string}>
	 */
	public function failureModes(): array
	{
		return array(
			'fail-closed' => array(settings::FAIL_CLOSED, ITEM_UNAPPROVED, pending_store::HELD),
			'fail-open'   => array(settings::FAIL_OPEN, ITEM_APPROVED, pending_store::PUBLISHED),
		);
	}

	/**
	 * @dataProvider failureModes
	 */
	public function testAPostWithALinkButNoTextToAssessGoesToTheFailureModeAtOnce(string $mode, int $visibility, string $status): void
	{
		// Audit probe B P5, the other way round: a link with a blank text, which a reader does
		// not even see, was published at once and unassessed, also with fail-closed.
		$this->board->configure(array(settings::FAIL_MODE => $mode));
		$xml = '<r><URL url="https://kasyno.example/bonus"><s>[url=https://kasyno.example/bonus]</s> <e>[/url]</e></URL></r>';

		$id = $this->board->posting('', 'post', array('xml' => $xml))['post_id'];

		self::assertSame($visibility, (int) $this->board->post($id)['post_visibility']);
		self::assertSame($status, $this->board->row($id)['status']);
		self::assertSame(array(), $this->gateway->requests, 'nothing to assess: no request, and never the title alone');
		if ($mode === settings::FAIL_OPEN)
		{
			self::assertGreaterThan(0, (int) $this->board->row($id)['approved_at'], 'marked as a fail-open publication');
			self::assertCount(1, $this->board->log->of('LOG_MINOS_POST_PUBLISHED_FAIL_OPEN'));
			return;
		}
		$details = new \phpbb\event\data(array('post_id' => $id, 'post_data' => array()));
		$this->board->listener->show_queue_details($details);
		self::assertSame('Minos: brak tekstu do oceny', $details['post_data']['MINOS_STATUS'], 'the moderator sees why');
		$event = new \phpbb\event\data(array('row' => array('post_id' => $id), 'post_row' => array('POST_SUBJECT' => 'Temat')));
		$this->board->listener->mark_queue_row($event);
		self::assertSame('[Minos: brak tekstu do oceny] Temat', $event['post_row']['POST_SUBJECT']);
	}

	public function testAnEditOfAWaitingPostIsAssessedAgainUnderANewRevision(): void
	{
		$id = $this->board->posting('Pierwsza wersja wpisu.')['post_id'];

		$this->board->edit($id, 'Druga wersja wpisu.');

		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
		$row = $this->board->row($id);
		self::assertSame('1', $row['revision']);
		self::assertSame(pending_store::PENDING, $row['status']);
		$item = $this->gateway->items(1)[0];
		self::assertSame('phpbb:' . $id . '.1', $item['id']);
		self::assertSame($this->board->title($id) . "\n\nDruga wersja wpisu.", $item['tekst']);
	}

	public function testANewTitleOfAWaitingTopicIsAssessedAgainUnderANewRevision(): void
	{
		$id = $this->board->posting('Treść bez zmian.', 'post', array('title' => 'Pierwszy tytuł'))['post_id'];

		$this->board->edit($id, 'Treść bez zmian.', 'Tytuł zmieniony przez autora');

		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame('1', $this->board->row($id)['revision']);
		$item = $this->gateway->items(1)[0];
		self::assertSame(array('phpbb:' . $id . '.1', "Tytuł zmieniony przez autora\n\nTreść bez zmian."), array($item['id'], $item['tekst']));
	}

	public function testSwitchedOffAnEditOfAWaitingPostIsNotSent(): void
	{
		$id = $this->board->posting('Pierwsza wersja wpisu.')['post_id'];
		$this->board->configure(array(settings::ENABLED => 0));

		$this->board->edit($id, 'Druga wersja wpisu.');

		self::assertCount(1, $this->gateway->requests);
		self::assertSame('0', $this->board->row($id)['revision']);
		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility'], 'it stays in phpBB\'s queue');
	}

	public function testAnEditOfAPublishedPostIsLeftToPhpbb(): void
	{
		$id = $this->board->posting('Pierwsza wersja wpisu.')['post_id'];
		$this->board->deliver(array('id' => 'phpbb:' . $id, 'status' => 'ocenione', 'kwalifikacja' => 'bezpieczne'));

		$this->board->edit($id, 'Druga wersja wpisu.');

		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertCount(1, $this->gateway->requests);
		self::assertSame(pending_store::PUBLISHED, $this->board->row($id)['status']);
	}

	public function testTheModeratorQueueMarksAWaitingPost(): void
	{
		$id = $this->board->posting('Zwykły testowy wpis.')['post_id'];

		$event = new \phpbb\event\data(array('row' => array('post_id' => $id), 'post_row' => array('POST_SUBJECT' => 'Temat &amp; coś')));
		$this->board->listener->mark_queue_row($event);

		self::assertSame('[Minos: w ocenie] Temat &amp; coś', $event['post_row']['POST_SUBJECT']);
	}

	public function testTheModeratorQueueLeavesOtherPostsAlone(): void
	{
		$event = new \phpbb\event\data(array('row' => array('post_id' => 999), 'post_row' => array('POST_SUBJECT' => 'Temat')));
		$this->board->listener->mark_queue_row($event);

		self::assertSame('Temat', $event['post_row']['POST_SUBJECT']);
	}
}
