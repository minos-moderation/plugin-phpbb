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
			'core.notification_manager_add_notifications',
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

	public function testAPostOfAUserOutsideTheChosenGroupsIsNeitherHeldNorSent(): void
	{
		// Audit probe P8 as the model: a post the extension does not cover leaves nothing.
		// A newly registered user who may post without approval, while only the board's
		// regulars are chosen (and his own request to join them still waits).
		$this->board->configure(array(), array(settings::GROUPS => '[' . Board::GROUP_TRUSTED . ']'));

		$posted = $this->board->posting('Wpis nowego użytkownika.', 'post', array('poster_id' => Board::NEW_USER));

		self::assertSame(ITEM_APPROVED, $posted['visibility'], 'published as before');
		self::assertArrayNotHasKey('force_approved_state', $posted['data']);
		self::assertNull($this->board->row($posted['post_id']));
		self::assertSame(array(), $this->gateway->requests, 'zero requests to the gateway');
	}

	/**
	 * @return array<string,array{0:string,1:int,2:bool}>
	 */
	public function groupChoices(): array
	{
		$trusted = '[' . Board::GROUP_TRUSTED . ']';
		return array(
			'none chosen: every group'                => array('', Board::NEW_USER, true),
			'a member of the chosen group'            => array($trusted, Board::USER, true),
			'a member of one of several'              => array('[' . Board::GROUP_TRUSTED . ',' . Board::GROUP_NEWLY_REGISTERED . ']', Board::NEW_USER, true),
			'a request to join is no membership'      => array($trusted, Board::NEW_USER, false),
			'a guest is in the guests\' group'        => array('[' . Board::GROUP_GUESTS . ']', ANONYMOUS, true),
			'a guest is not a registered user'        => array('[' . Board::GROUP_REGISTERED . ']', ANONYMOUS, false),
			'a damaged value: every group'            => array('grupy', Board::NEW_USER, true),
			'ids that are not numbers: every group'   => array('["10"]', Board::NEW_USER, true),
			'only groups deleted since: every group'  => array('[999]', Board::NEW_USER, true),
		);
	}

	/**
	 * @dataProvider groupChoices
	 */
	public function testOnlyMembersOfTheChosenGroupsAreHeldAndSent(string $stored, int $poster, bool $held): void
	{
		$this->board->configure(array(), array(settings::GROUPS => $stored));

		$posted = $this->board->posting('Zwykły testowy wpis.', 'post', array('poster_id' => $poster));

		self::assertSame($held ? ITEM_UNAPPROVED : ITEM_APPROVED, $posted['visibility']);
		self::assertCount($held ? 1 : 0, $this->gateway->requests);
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

	/**
	 * @return array<string,array{0:string,1:string,2:int,3:string}>
	 */
	public function postsWithNothingToRead(): array
	{
		// phpBB 3.3's real storage of each (tests/fixtures/phpbb-3.3/), in a reply under the
		// default subject: no title either.
		$cases = array();
		foreach (array('image-only', 'image-with-size', 'attachment-inline', 'flash', 'link-blank-label', 'link-empty-label', 'blank-bold',
			'image-zwsp', 'image-shy', 'image-word-joiner', 'image-lrm', 'link-label-shy', 'link-label-zwsp', 'bold-zwsp') as $body)
		{
			$cases[$body . ', fail-closed'] = array($body, settings::FAIL_CLOSED, ITEM_UNAPPROVED, pending_store::HELD);
			$cases[$body . ', fail-open'] = array($body, settings::FAIL_OPEN, ITEM_APPROVED, pending_store::PUBLISHED);
		}
		return $cases;
	}

	/**
	 * @dataProvider postsWithNothingToRead
	 */
	public function testAPostWithoutTextAReaderReadsGoesToTheFailureModeUnsent(string $body, string $mode, int $visibility, string $status): void
	{
		// The plugins' shared rule F7: an image alone used to go to the gateway as its address,
		// and a reply with only an attachment or formatting was published unassessed.
		$this->board->configure(array(settings::FAIL_MODE => $mode));

		$id = $this->board->posting('', 'reply', array('xml' => self::stored($body), 'topic_id' => $this->topic()))['post_id'];

		self::assertSame(array(), $this->gateway->requests, 'nothing to assess: no request');
		$row = $this->board->row($id);
		self::assertSame(array($status, 'empty_text'), array($row['status'], $row['error_code']));
		self::assertSame($visibility, (int) $this->board->post($id)['post_visibility']);
	}

	/**
	 * @dataProvider failureModes
	 */
	public function testATitleOverAnImageAndAnInvisibleCharacterIsNeverSentAlone(string $mode, int $visibility, string $status): void
	{
		// Review of S6c: a new topic of a title, an image and U+200B reached the gateway as
		// the title and the one invisible character, and was published on its verdict.
		$this->board->configure(array(settings::FAIL_MODE => $mode));

		$id = $this->board->posting('', 'post', array('xml' => self::stored('image-zwsp'), 'title' => 'Obraźliwy tytuł testowy'))['post_id'];

		self::assertSame(array(), $this->gateway->requests, 'a title is never sent alone');
		self::assertSame(array($status, 'empty_text'), array($this->board->row($id)['status'], $this->board->row($id)['error_code']));
		self::assertSame($visibility, (int) $this->board->post($id)['post_visibility']);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function addressesAReaderSees(): array
	{
		return array('a bare address' => array('bare-link-only'), 'a [url] address' => array('url-tag'));
	}

	/**
	 * @dataProvider addressesAReaderSees
	 */
	public function testAnAddressAReaderSeesIsTextAndIsSentWithItsLink(string $body): void
	{
		$id = $this->board->posting('', 'reply', array('xml' => self::stored($body), 'topic_id' => $this->topic()))['post_id'];

		self::assertCount(1, $this->gateway->requests);
		$item = $this->gateway->items(0)[0];
		self::assertSame('phpbb:' . $id, $item['id']);
		self::assertSame('https://example.org/a', $item['tekst']);
		self::assertSame(array(1, array('example.org')), array($item['meta']['links'], $item['meta']['link_domains']));
	}

	public function testAnImageBesideTextSendsTheTextWithoutTheImagesAddress(): void
	{
		$this->board->posting('', 'reply', array('xml' => self::stored('image'), 'topic_id' => $this->topic()));

		$sent = $this->gateway->items(0)[0]['tekst'];
		self::assertStringContainsString('Obrazek', $sent);
		self::assertStringContainsString('koniec', $sent);
		self::assertStringNotContainsString('a.png', $sent, 'the reader sees the image, not its address');
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function imageTitles(): array
	{
		// phpBB's own [img] carries none; a board's custom BBCode may (an XML of that shape).
		return array(
			'alone'       => array('<r><IMG src="https://example.org/a.png" title="Podpis obrazka testowego"><s>[img]</s>https://example.org/a.png<e>[/img]</e></IMG></r>', ''),
			'beside text' => array('<r>Tekst testowy <IMG src="https://example.org/a.png" alt="Podpis obrazka testowego"><s>[img]</s>https://example.org/a.png<e>[/img]</e></IMG></r>',
				'Podpis obrazka testowego'),
		);
	}

	/**
	 * @dataProvider imageTitles
	 */
	public function testAnImagesTitleIsSentBesideTextAndNeverMakesATextAlone(string $xml, string $sent): void
	{
		$this->board->posting('', 'reply', array('xml' => $xml, 'topic_id' => $this->topic()));

		if ($sent === '')
		{
			self::assertSame(array(), $this->gateway->requests, 'an image with a title is still an image alone');
			return;
		}
		$text = $this->gateway->items(0)[0]['tekst'];
		self::assertStringStartsWith('Tekst testowy', $text);
		self::assertStringContainsString($sent, $text, 'the title a reader sees beside the text');
		self::assertStringNotContainsString('a.png', $text);
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function titlesWithoutText(): array
	{
		$cases = array();
		foreach (array('blank-bold', 'empty-italic') as $body)
		{
			foreach (array('post', 'reply') as $kind)
			{
				foreach (array(settings::FAIL_CLOSED, settings::FAIL_OPEN) as $mode)
				{
					$cases[$kind . ', ' . $body . ', ' . $mode] = array($kind, $body, $mode);
				}
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider titlesWithoutText
	 */
	public function testATitleOverNoTextToAssessGoesToTheFailureModeAtOnce(string $kind, string $body, string $mode): void
	{
		// Review of S6a: a new topic (or a reply under a subject of its own) whose post has no
		// text a reader sees was published at once, and its title never reached the gateway.
		$this->board->configure(array(settings::FAIL_MODE => $mode));
		$options = ($kind === 'post')
			? array('xml' => self::stored($body), 'title' => 'Obraźliwy tytuł testowy')
			: array('xml' => self::stored($body), 'topic_id' => $this->topic(), 'subject' => 'Obraźliwy temat odpowiedzi');

		$id = $this->board->posting('', $kind, $options)['post_id'];

		$row = $this->board->row($id);
		self::assertSame(array(), $this->gateway->requests, 'a title is never sent alone');
		self::assertSame('empty_text', $row['error_code']);
		if ($mode === settings::FAIL_OPEN)
		{
			self::assertSame(array(ITEM_APPROVED, pending_store::PUBLISHED), array((int) $this->board->post($id)['post_visibility'], $row['status']));
			self::assertGreaterThan(0, (int) $row['approved_at'], 'marked as a fail-open publication');
			return;
		}
		self::assertSame(array(ITEM_UNAPPROVED, pending_store::HELD), array((int) $this->board->post($id)['post_visibility'], $row['status']));
		$details = new \phpbb\event\data(array('post_id' => $id, 'post_data' => array()));
		$this->board->listener->show_queue_details($details);
		self::assertSame('Minos: brak tekstu do oceny', $details['post_data']['MINOS_STATUS']);
	}

	/**
	 * What phpBB 3.3 stores for a recorded text (tests/fixtures/phpbb-3.3/).
	 */
	private static function stored(string $name): string
	{
		$fixture = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/phpbb-3.3/' . $name . '.json'), true);
		self::assertIsArray($fixture, $name);
		return (string) $fixture['xml'];
	}

	/**
	 * An approved topic by someone the extension does not hold, for replies.
	 */
	private function topic(): int
	{
		$first = $this->board->write_post($this->board->xml('Pierwszy wpis tematu.'), ITEM_APPROVED, array('title' => 'Wątek testowy'));
		return (int) $this->board->post($first)['topic_id'];
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
