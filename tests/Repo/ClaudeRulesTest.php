<?php

declare(strict_types=1);

namespace minos\moderation\tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * The rules for Claude Code sessions stay findable and cheap: the root `CLAUDE.md`, read by
 * every session, keeps to a size budget and names every scoped file, and every project
 * agent pins its model. The agents' powers are checked as properties, not as wording: an
 * agent without a `tools:` line inherits every tool, so a reviewer must list its tools and
 * none that writes; a Sonnet agent that edits files works from a list of allowed paths, so
 * every risk path must stand in its risk list and none in its allowed list. The fixtures in
 * `tests/Repo/fixtures/` break one property each, and the check must catch each of them.
 */
final class ClaudeRulesTest extends TestCase
{
	/** Bytes. Lowering is free; raising needs a sentence in the commit. */
	private const ROOT_LIMIT = 3100;

	/** Bytes, for every `CLAUDE.md` below the root. */
	private const SCOPED_LIMIT = 1600;

	/** Agents allowed to edit files. Every other agent is read-only. */
	private const WRITERS = ['programista', 'programista-prosty'];

	/** Tools that change files. An agent without a `tools:` line has them all. */
	private const WRITING_TOOLS = ['Edit', 'Write', 'NotebookEdit', 'MultiEdit'];

	/**
	 * What a Sonnet agent that edits files must name in its risk list, and never in its list
	 * of allowed paths: the receiver, the listener and the submission (what leaves the forum),
	 * the cron task, the settings, the applier and the platform adapter that decide publish
	 * or hold, the key and secret handling (the ACP controller), the migrations, the wiring
	 * and the packaging, the test doubles, CI, the agents and every CLAUDE.md.
	 */
	private const SONNET_RISK_MARKERS = [
		'controller/webhook.php', 'service/receiver.php', 'event/deferred_verdicts.php', 'event/listener.php',
		'service/submitter.php', 'service/curl_transport.php', 'cron/sweeper.php', 'service/settings.php',
		'service/verdict_applier.php', 'platform/forum.php', 'controller/acp.php', 'migrations/', 'config/',
		'ext.php', 'client_loader.php', 'composer.json', 'composer.lock', 'bin/', 'phpunit.xml.dist',
		'tests/stubs/', 'tests/Fake/', '.github/', '.claude/', 'CLAUDE.md',
	];

	/** The block of a Sonnet agent's paths it may change: from this phrase to a blank line. */
	private const ALLOWED_BLOCK = 'You may change';

	/** The block of its risk paths: from a line starting with this phrase to a blank line. */
	private const RISK_BLOCK = 'Risk list';

	private const MODELS = ['opus', 'sonnet', 'haiku', 'fable'];

	private const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

	private const ROOT = __DIR__ . '/../..';

	private const FIXTURES = __DIR__ . '/fixtures';

	public function testTheRootFileStaysWithinItsBudget(): void
	{
		$size = strlen((string)file_get_contents(self::ROOT . '/CLAUDE.md'));
		self::assertLessThanOrEqual(self::ROOT_LIMIT, $size,
			'move a rule that one directory needs into that directory\'s CLAUDE.md');
	}

	public function testEveryScopedFileStaysWithinItsBudgetAndIsNamedInTheRoot(): void
	{
		$root = (string)file_get_contents(self::ROOT . '/CLAUDE.md');
		foreach (self::scopedFiles() as $path) {
			self::assertLessThanOrEqual(self::SCOPED_LIMIT,
				strlen((string)file_get_contents(self::ROOT . '/' . $path)), $path);
			self::assertStringContainsString("`{$path}`", $root, "the root CLAUDE.md must name {$path}");
		}
	}

	public function testTheProjectsAgentsKeepEveryRule(): void
	{
		self::assertSame([], self::violations(self::agents(self::ROOT . '/.claude/agents')));
	}

	public function testTheCheckReachesAReviewerAndASonnetAgentThatWrites(): void
	{
		$reviewers = 0;
		$sonnet_writers = 0;
		foreach (self::agents(self::ROOT . '/.claude/agents') as $name => $agent) {
			$reviewers += in_array($name, self::WRITERS, true) ? 0 : 1;
			$sonnet_writers += (($agent['fields']['model'] ?? null) === 'sonnet' && self::writes($agent['fields'])) ? 1 : 0;
		}
		self::assertGreaterThan(0, $reviewers, 'no read-only agent was found: the check checks nothing');
		self::assertGreaterThan(0, $sonnet_writers, 'no Sonnet agent that writes was found: the check checks nothing');
	}

	/**
	 * Each fixture directory holds one agent with one broken property.
	 *
	 * @return array<string,array{0:string,1:array<int,array{0:string,1:string}>}>
	 */
	public function brokenAgents(): array
	{
		return [
			// Audit probe P3 (rules-fixture), part 1: the tools line deleted from a Sonnet reviewer,
			// which then inherits Edit and Write - a Sonnet agent that writes with no lists.
			'a reviewer without a tools line inherits every tool' => ['reviewer-without-tools', [
				['przeglad-mechaniczny', 'inherits every tool'],
				['przeglad-mechaniczny', 'has no allowed list'],
				['przeglad-mechaniczny', 'has no risk list'],
			]],
			'a reviewer that lists a writing tool' => ['reviewer-that-writes', [
				['przeglad-bezpieczenstwa', 'lists a writing tool'],
			]],
			'a Sonnet agent with a risk path in its allowed list' => ['risk-path-allowed', [
				['programista-prosty', 'allows event/listener.php'],
			]],
			'a Sonnet agent with a risk path missing from its risk list' => ['risk-path-unlisted', [
				['programista-prosty', 'does not list migrations/ as a risk'],
			]],
			// Audit probe P3 (rules-fixture), part 2: the agent's body replaced by a licence to edit anything.
			'a Sonnet agent that may edit anything' => ['no-risk-list', [
				['programista-prosty', 'has no allowed list'],
				['programista-prosty', 'has no risk list'],
			]],
		];
	}

	/**
	 * @dataProvider brokenAgents
	 * @param array<int,array{0:string,1:string}> $expected
	 */
	public function testTheCheckRejectsEachBrokenAgent(string $fixture, array $expected): void
	{
		self::assertSame($expected, self::violations(self::agents(self::FIXTURES . '/' . $fixture)));
	}

	/**
	 * Every broken rule of a set of agents.
	 *
	 * @param array<string,array{fields:array<string,string>,body:string}> $agents
	 * @return array<int,array{0:string,1:string}> [agent, what is wrong].
	 */
	private static function violations(array $agents): array
	{
		$found = [];
		foreach ($agents as $name => $agent) {
			$fields = $agent['fields'];
			if (($fields['name'] ?? null) !== $name) {
				$found[] = [$name, 'name differs from the file'];
			}
			if (!in_array($fields['model'] ?? null, self::MODELS, true)) {
				$found[] = [$name, 'pins no model'];
			}
			if (!in_array($fields['effort'] ?? null, self::EFFORTS, true)) {
				$found[] = [$name, 'pins no effort'];
			}
			if (($fields['description'] ?? '') === '') {
				$found[] = [$name, 'has no description'];
			}
			if (in_array($name, self::WRITERS, true)) {
				if (($fields['model'] ?? null) === 'fable') {
					$found[] = [$name, 'implements on Fable'];
				}
			} else {
				foreach (self::reviewer_violations($fields) as $what) {
					$found[] = [$name, $what];
				}
			}
			if (($fields['model'] ?? null) === 'sonnet' && self::writes($fields)) {
				foreach (self::sonnet_writer_violations($agent['body']) as $what) {
					$found[] = [$name, $what];
				}
			}
		}
		return $found;
	}

	/**
	 * What is wrong with a read-only agent: its tools must be listed (without the line it
	 * inherits all of them), none may write, and with Bash it can write anyway, so it may
	 * claim to be read-only only "by instruction".
	 *
	 * @param array<string,string> $fields
	 * @return array<int,string>
	 */
	private static function reviewer_violations(array $fields): array
	{
		if (trim($fields['tools'] ?? '') === '') {
			return ['inherits every tool'];
		}
		$found = [];
		if (self::writes($fields)) {
			$found[] = 'lists a writing tool';
		}
		$description = $fields['description'] ?? '';
		if (in_array('Bash', self::tools($fields), true) && stripos($description, 'read-only') !== false
			&& stripos($description, 'read-only by instruction') === false) {
			$found[] = 'claims to be read-only while it runs Bash';
		}
		return $found;
	}

	/**
	 * What is wrong with a Sonnet agent that writes: its allowed list and its risk list must
	 * both be there, every risk path in the risk list, none in the allowed list.
	 *
	 * @return array<int,string>
	 */
	private static function sonnet_writer_violations(string $body): array
	{
		$allowed = self::block($body, self::ALLOWED_BLOCK, false);
		$risks = self::block($body, self::RISK_BLOCK, true);
		$found = [];
		if ($allowed === null) {
			$found[] = 'has no allowed list';
		}
		if ($risks === null) {
			$found[] = 'has no risk list';
		}
		foreach (self::SONNET_RISK_MARKERS as $marker) {
			if ($allowed !== null && strpos($allowed, $marker) !== false) {
				$found[] = "allows {$marker}";
			}
			if ($risks !== null && strpos($risks, $marker) === false) {
				$found[] = "does not list {$marker} as a risk";
			}
		}
		return $found;
	}

	/**
	 * A block of an agent's body: the line with its phrase (at the line's start, or anywhere
	 * in it) and the lines after it, up to the first blank line.
	 *
	 * @return string|null Null when the phrase is not there once.
	 */
	private static function block(string $body, string $phrase, bool $at_start): ?string
	{
		$pattern = '/^' . ($at_start ? '' : '[^\n]*') . preg_quote($phrase, '/') . '[^\n]*(?:\n[^\n]+)*/m';
		if (preg_match_all($pattern, $body, $m) !== 1) {
			return null;
		}
		return $m[0][0];
	}

	/**
	 * Whether an agent can change files: it lists a writing tool, or lists no tools at all
	 * (and so inherits every one).
	 *
	 * @param array<string,string> $fields
	 */
	private static function writes(array $fields): bool
	{
		if (trim($fields['tools'] ?? '') === '') {
			return true;
		}
		return (bool)array_intersect(self::tools($fields), self::WRITING_TOOLS);
	}

	/**
	 * @param array<string,string> $fields
	 * @return array<int,string>
	 */
	private static function tools(array $fields): array
	{
		return array_map('trim', explode(',', $fields['tools'] ?? ''));
	}

	/**
	 * Every `CLAUDE.md` below the root, as paths relative to it.
	 *
	 * @return array<int,string>
	 */
	private static function scopedFiles(): array
	{
		$root = realpath(self::ROOT);
		$skip = ['.git', 'vendor', 'node_modules'];
		$files = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
			new \RecursiveDirectoryIterator((string)$root, \FilesystemIterator::SKIP_DOTS),
			static function (\SplFileInfo $file) use ($skip): bool {
				return !($file->isDir() && in_array($file->getFilename(), $skip, true));
			}
		));
		$found = [];
		$sawRoot = false;
		foreach ($files as $file) {
			$path = substr($file->getPathname(), strlen((string)$root) + 1);
			if ($file->getFilename() !== 'CLAUDE.md') {
				continue;
			}
			if ($path === 'CLAUDE.md') {
				$sawRoot = true;
			} else {
				$found[] = $path;
			}
		}
		// This repository has no scoped file yet; the walk must still find the root one.
		self::assertTrue($sawRoot, 'the root CLAUDE.md was not found: the search is broken');
		sort($found);
		return $found;
	}

	/**
	 * Every agent of a directory by file name: its front matter and its body.
	 *
	 * @return array<string,array{fields:array<string,string>,body:string}>
	 */
	private static function agents(string $directory): array
	{
		$agents = [];
		foreach (glob($directory . '/*.md') ?: [] as $file) {
			$text = (string)file_get_contents($file);
			$fields = [];
			$body = $text;
			if (preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $text, $m)) {
				$body = $m[2];
				foreach (explode("\n", $m[1]) as $line) {
					$pair = explode(':', $line, 2);
					if (count($pair) === 2) {
						$fields[trim($pair[0])] = trim($pair[1]);
					}
				}
			}
			$agents[basename($file, '.md')] = ['fields' => $fields, 'body' => $body];
		}
		self::assertNotEmpty($agents, "no agents found in {$directory}");
		return $agents;
	}
}
