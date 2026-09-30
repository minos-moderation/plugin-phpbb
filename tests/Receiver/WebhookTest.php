<?php

namespace minos\moderation\tests\Receiver;

use minos\moderation\controller\webhook;
use minos\moderation\service\pending_store;
use minos\moderation\service\settings;
use minos\moderation\tests\Fake\Board;
use Minos\Client\Signature;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The webhook through the real controller, receiver and applier, with deliveries signed the
 * way the gateway signs them (`Signature::sign` of the bundled client).
 */
final class WebhookTest extends TestCase
{
	/** @var Board */
	private $board;

	protected function setUp(): void
	{
		$this->board = Board::open();
	}

	public function testASafeVerdictPublishesThePost(): void
	{
		$id = $this->pending();

		self::assertSame(200, $this->board->deliver(self::verdict($id, 'bezpieczne')));

		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(pending_store::PUBLISHED, $this->board->row($id)['status']);
		self::assertContains(array('add', 'notification.type.topic', $id), $this->board->notifications->calls);
		self::assertContains(array('delete', 'notification.type.post_in_queue', $id), $this->board->notifications->calls);
		self::assertCount(1, $this->board->log->of('LOG_MINOS_POST_PUBLISHED'));
	}

	public function testASafeReplyNotifiesTheTopicsWatchers(): void
	{
		$topic = $this->board->write_post($this->board->xml('Pierwszy wpis tematu.'), ITEM_APPROVED);
		$this->board->db->sql_query('UPDATE phpbb_topics SET topic_posts_approved = 1');
		$reply = $this->board->posting('Odpowiedź testowa.', 'reply', array('topic_id' => (int) $this->board->post($topic)['topic_id']));

		$this->board->deliver(self::verdict($reply['post_id'], 'bezpieczne'));

		self::assertContains(array('add', 'notification.type.post', $reply['post_id']), $this->board->notifications->calls);
		self::assertNotContains(array('add', 'notification.type.topic', $reply['post_id']), $this->board->notifications->calls);
	}

	/**
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public function forgedDeliveries(): array
	{
		return array(
			'a wrong secret'           => array(array('secret' => 'inny-sekret-niz-ten-z-klucza-00')),
			'a stale signature'        => array(array('t' => time() - 600)),
			'a signature from later'   => array(array('t' => time() + 600)),
			'no signature'             => array(array('header' => '')),
			'a malformed header'       => array(array('header' => 'v1=' . str_repeat('a', 64))),
			'another body signed'      => array(array('header' => Signature::sign(Board::SECRET, '{"id":"phpbb:1"}', time()))),
		);
	}

	/**
	 * @dataProvider forgedDeliveries
	 * @param array<string,mixed> $options
	 */
	public function testAForgedOrStaleDeliveryIs401AndChangesNothing(array $options): void
	{
		$id = $this->pending();

		self::assertSame(401, $this->board->deliver(self::verdict($id, 'bezpieczne'), $options));

		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(pending_store::PENDING, $this->board->row($id)['status']);
		self::assertSame(array(), $this->board->visibility->calls);
	}

	public function testTheReceiverVerifiesTheGatewaysOwnTestVector(): void
	{
		$vector = json_decode((string) file_get_contents(__DIR__ . '/../../vendor/minos-moderation/client-php/vectors/signature.json'), true);
		$this->board->configure(array(), array(settings::WEBHOOK_SECRET => $vector['secret']));

		// A valid signature on a payload of another plugin: accepted, then dropped as not ours.
		self::assertSame(array(200, null), $this->board->receiver->receive($vector['body'], $vector['header'], (int) $vector['timestamp']));
		self::assertSame(401, $this->board->receiver->receive($vector['body'] . ' ', $vector['header'], (int) $vector['timestamp'])[0]);
		self::assertSame(401, $this->board->receiver->receive($vector['body'], $vector['header'], (int) $vector['timestamp'] + 301)[0]);
	}

	public function testWithoutAStoredSecretEveryDeliveryIs401(): void
	{
		$id = $this->pending();
		$this->board->configure(array(), array(settings::WEBHOOK_SECRET => ''));

		self::assertSame(401, $this->board->deliver(self::verdict($id, 'bezpieczne'), array('secret' => '')));
		self::assertSame(pending_store::PENDING, $this->board->row($id)['status']);
	}

	public function testASignedBodyThatIsNotAPayloadIs400(): void
	{
		$this->pending();

		self::assertSame(400, $this->board->deliver('to nie jest JSON'));
		self::assertSame(400, $this->board->deliver('{"status":"ocenione"}'));
		self::assertSame(array(), $this->board->visibility->calls);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function foreignIds(): array
	{
		return array(
			'another plugin\'s id' => array('k-1027'),
			'a post never held'    => array('phpbb:999999'),
			'a zero post id'       => array('phpbb:0'),
			'a revision never sent' => array('phpbb:1.7'),
		);
	}

	/**
	 * @dataProvider foreignIds
	 */
	public function testAnIdThisForumDoesNotWaitForIs200AndChangesNothing(string $id): void
	{
		$this->pending();

		self::assertSame(200, $this->board->deliver(array('id' => $id) + self::verdict(1, 'zablokowane')));
		self::assertSame(array(), $this->board->visibility->calls);
		self::assertSame(pending_store::PENDING, $this->board->row(1)['status']);
	}

	public function testARepeatedDeliveryIsAppliedOnce(): void
	{
		$id = $this->pending();

		self::assertSame(200, $this->board->deliver(self::verdict($id, 'bezpieczne')));
		self::assertSame(200, $this->board->deliver(self::verdict($id, 'bezpieczne')));
		self::assertSame(200, $this->board->deliver(self::verdict($id, 'zablokowane')));

		self::assertCount(1, $this->board->visibility->calls);
		self::assertCount(1, $this->board->log->of('LOG_MINOS_POST_PUBLISHED'));
		self::assertSame('bezpieczne', $this->board->row($id)['verdict']);
	}

	public function testASecondDeliveryBeforeTheFirstIsAppliedChangesNothing(): void
	{
		$id = $this->pending();
		foreach (array('bezpieczne', 'zablokowane') as $qualification)
		{
			$body = json_encode(self::verdict($id, $qualification));
			$controller = new webhook(new Request(array('X-Wergiliusz-Podpis' => Signature::sign(Board::SECRET, $body, time())), $body),
				$this->board->receiver, $this->board->deferred, $this->board->dispatcher);
			self::assertSame(200, $controller->handle()->getStatusCode());
		}

		$this->board->dispatcher->terminate();

		self::assertSame('bezpieczne', $this->board->row($id)['verdict']);
		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
	}

	public function testCensoredWithPublishReplacesTheTextAndKeepsTheOriginal(): void
	{
		$id = $this->pending('To jest brzydkie słowo w teście.');
		$original = $this->board->post($id)['post_text'];

		$this->board->deliver(self::verdict($id, 'ocenzurowane', array(
			'kategorie'    => array('wulgaryzmy'),
			'ocenzurowany' => 'To jest ████████ słowo w teście.',
		)));

		$post = $this->board->post($id);
		self::assertSame(ITEM_APPROVED, (int) $post['post_visibility']);
		self::assertStringContainsString('████████', $post['post_text']);
		self::assertStringNotContainsString('brzydkie', $post['post_text']);
		self::assertSame('', $post['bbcode_uid']);
		$row = $this->board->row($id);
		self::assertSame(pending_store::MASKED, $row['status']);
		self::assertSame($original, $row['original_text']);
		self::assertSame('wulgaryzmy', $row['categories']);
		$parse = end($this->board->parser->parsed);
		self::assertSame(array('bbcodes' => false, 'magic_url' => false, 'smilies' => false), $parse['enabled'],
			'the masked text is stored with BBCode, links and smilies off');
		self::assertSame(array('bbcodes' => true, 'magic_url' => true, 'smilies' => true), $this->board->parser->enabled,
			'the parser is left as it was found');
	}

	public function testCensoredWithHoldKeepsThePostQueuedWithTheMaskedTextForTheModerator(): void
	{
		$this->board->configure(array(settings::CENSORED => settings::HOLD));
		$id = $this->pending('To jest brzydkie słowo w teście.');

		$this->board->deliver(self::verdict($id, 'ocenzurowane', array('ocenzurowany' => 'To jest ████████ słowo w teście.')));

		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertStringContainsString('brzydkie', $this->board->post($id)['post_text']);
		self::assertSame(pending_store::HELD, $this->board->row($id)['status']);
		self::assertSame('To jest ████████ słowo w teście.', $this->board->row($id)['masked_text']);
		self::assertCount(1, $this->board->log->of('LOG_MINOS_POST_HELD'));
	}

	public function testCensoredWithoutMaskedTextIsHeld(): void
	{
		$id = $this->pending();

		$this->board->deliver(self::verdict($id, 'ocenzurowane'));

		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(pending_store::HELD, $this->board->row($id)['status']);
	}

	public function testCensoredTextOfAPostLongerThanTheAssessedPartIsNeverPublished(): void
	{
		$id = $this->pending(str_repeat('Długi testowy wpis. ', 200));

		$this->board->deliver(self::verdict($id, 'ocenzurowane', array('ocenzurowany' => str_repeat('█', 3000))));

		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility'],
			'publishing the masked 3000 characters would cut the rest of the post off');
		self::assertSame(pending_store::HELD, $this->board->row($id)['status']);
	}

	public function testBlockedIsHeldByDefault(): void
	{
		$id = $this->pending();

		$this->board->deliver(self::verdict($id, 'zablokowane', array('kategorie' => array('nekanie'))));

		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(pending_store::HELD, $this->board->row($id)['status']);
		self::assertSame(array(), $this->board->visibility->calls);
	}

	public function testBlockedWithDeleteIsSoftDeletedWithoutSkewingPhpbbsCounters(): void
	{
		$this->board->configure(array(settings::BLOCKED => settings::BLOCKED_DELETE));
		$id = $this->pending();

		$this->board->deliver(self::verdict($id, 'zablokowane'));

		self::assertSame(ITEM_DELETED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(pending_store::DELETED, $this->board->row($id)['status']);
		$calls = $this->board->visibility->calls;
		$deletion = end($calls);
		self::assertSame(ITEM_DELETED, $deletion['visibility']);
		self::assertSame(array($id => ITEM_APPROVED), $deletion['before'],
			'phpBB accounts a deletion as if the post had been approved');
		self::assertSame('Minos: treść zablokowana', $deletion['reason']);
		self::assertNotContains(array('add', 'notification.type.topic', $id), $this->board->notifications->calls,
			'nobody is notified of a post that is deleted at once');
		self::assertSame(array('begin', 'commit'), $this->board->db->transactions, 'the approval and the deletion share one transaction');
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
	public function testUnassessedFollowsTheFailureMode(string $mode, int $visibility, string $status): void
	{
		$this->board->configure(array(settings::FAIL_MODE => $mode));
		$id = $this->pending();

		self::assertSame(200, $this->board->deliver(array('id' => 'phpbb:' . $id, 'status' => 'nieocenione')));

		self::assertSame($visibility, (int) $this->board->post($id)['post_visibility']);
		self::assertSame($status, $this->board->row($id)['status']);
		self::assertSame('nieocenione', $this->board->row($id)['verdict']);
	}

	/**
	 * @dataProvider failureModes
	 */
	public function testAQualificationTheClientDoesNotKnowIsNeverAVerdict(string $mode, int $visibility, string $status): void
	{
		$this->board->configure(array(settings::FAIL_MODE => $mode, settings::BLOCKED => settings::BLOCKED_DELETE));
		$id = $this->pending();

		$this->board->deliver(self::verdict($id, 'usuniete_calkowicie'));

		self::assertSame($visibility, (int) $this->board->post($id)['post_visibility']);
		self::assertSame($status, $this->board->row($id)['status']);
	}

	public function testSupportIsFlaggedAndShownInTheModeratorQueue(): void
	{
		$id = $this->pending('Testowy wpis o bardzo złym dniu.');

		$this->board->deliver(self::verdict($id, 'bezpieczne', array('kategorie' => array('samookaleczenie'), 'wsparcie' => true)));

		$row = $this->board->row($id);
		self::assertSame('1', $row['support']);
		self::assertSame('samookaleczenie', $row['categories']);
		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility'], 'support is a cue, not a verdict');
		self::assertCount(1, $this->board->log->of('LOG_MINOS_SUPPORT'));

		$queue = new \phpbb\event\data(array('row' => array('post_id' => $id), 'post_row' => array('POST_SUBJECT' => 'Temat')));
		$this->board->listener->mark_queue_row($queue);
		self::assertStringContainsString('potrzebne wsparcie', $queue['post_row']['POST_SUBJECT']);

		$details = new \phpbb\event\data(array('post_id' => $id, 'post_data' => array()));
		$this->board->listener->show_queue_details($details);
		self::assertTrue($details['post_data']['S_MINOS_SUPPORT']);
		self::assertSame('samookaleczenie', $details['post_data']['MINOS_CATEGORIES']);
	}

	public function testAPostAModeratorDealtWithFirstIsLeftAlone(): void
	{
		$this->board->configure(array(settings::BLOCKED => settings::BLOCKED_DELETE));
		$id = $this->pending();
		$this->board->db->sql_query('UPDATE phpbb_posts SET post_visibility = ' . ITEM_APPROVED . ' WHERE post_id = ' . $id);

		self::assertSame(200, $this->board->deliver(self::verdict($id, 'zablokowane')));

		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(pending_store::SUPERSEDED, $this->board->row($id)['status']);
		self::assertSame(array(), $this->board->visibility->calls);
	}

	public function testAVerdictForTheTextBeforeAnEditIsIgnored(): void
	{
		$id = $this->pending('Pierwsza wersja wpisu.');
		$this->board->edit($id, 'Druga wersja wpisu.');

		self::assertSame(200, $this->board->deliver(self::verdict($id, 'bezpieczne')));
		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility']);

		self::assertSame(200, $this->board->deliver(array('id' => 'phpbb:' . $id . '.1') + self::verdict($id, 'bezpieczne')));
		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
	}

	public function testTheAnswerIsGivenBeforeTheVerdictIsApplied(): void
	{
		$id = $this->pending();
		$body = json_encode(self::verdict($id, 'bezpieczne'));
		$controller = new webhook(new Request(array('X-Wergiliusz-Podpis' => Signature::sign(Board::SECRET, $body, time())), $body),
			$this->board->receiver, $this->board->deferred, $this->board->dispatcher);

		self::assertSame(200, $controller->handle()->getStatusCode());
		self::assertSame(ITEM_UNAPPROVED, (int) $this->board->post($id)['post_visibility'], 'nothing slow before the answer');
		self::assertSame(pending_store::RECEIVED, $this->board->row($id)['status']);

		$this->board->dispatcher->terminate();
		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
	}

	public function testAVerdictLeftUnappliedIsAppliedByTheCronTask(): void
	{
		$id = $this->pending();
		$body = json_encode(self::verdict($id, 'bezpieczne'));
		$controller = new webhook(new Request(array('X-Wergiliusz-Podpis' => Signature::sign(Board::SECRET, $body, time())), $body),
			$this->board->receiver, $this->board->deferred, $this->board->dispatcher);
		$controller->handle();
		// The process ends before kernel.terminate runs.

		$this->board->sweeper()->sweep(time());

		self::assertSame(ITEM_APPROVED, (int) $this->board->post($id)['post_visibility']);
		self::assertSame(pending_store::PUBLISHED, $this->board->row($id)['status']);
	}

	/**
	 * A post held and accepted by the gateway.
	 */
	private function pending(string $text = 'Zwykły testowy wpis o pogodzie.'): int
	{
		$posted = $this->board->posting($text);
		self::assertSame(pending_store::PENDING, $this->board->row($posted['post_id'])['status']);
		return $posted['post_id'];
	}

	/**
	 * A payload as the gateway builds it.
	 *
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private static function verdict(int $post_id, string $qualification, array $extra = array()): array
	{
		return $extra + array(
			'id'           => 'phpbb:' . $post_id,
			'status'       => 'ocenione',
			'kwalifikacja' => $qualification,
			'kategorie'    => array(),
			'wsparcie'     => false,
			'wersja'       => '3f0c9a41d2b7e8c5',
		);
	}
}
