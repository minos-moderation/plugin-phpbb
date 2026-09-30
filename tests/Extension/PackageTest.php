<?php

namespace minos\moderation\tests\Extension;

use PHPUnit\Framework\TestCase;

/**
 * The installable package, as `bin/package.php` writes it: what phpBB loads and the bundled
 * client's sources, and nothing else.
 */
final class PackageTest extends TestCase
{
	private const ROOT = __DIR__ . '/../..';

	/** @var string */
	private $dir;

	/** @var array<int,string> */
	private $entries = array();

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . '/minos-phpbb-package-' . bin2hex(random_bytes(6));
		mkdir($this->dir, 0700, true);
		$zip = $this->dir . '/package.zip';
		// The repository's vendor/ holds development packages too: the packer must leave them out.
		$this->run_php(array(self::ROOT . '/bin/package.php', $zip, self::ROOT . '/vendor'));
		$archive = new \ZipArchive();
		self::assertTrue($archive->open($zip) === true);
		for ($i = 0; $i < $archive->numFiles; $i++)
		{
			$this->entries[] = (string) $archive->getNameIndex($i);
		}
		$archive->extractTo($this->dir . '/unpacked');
		$archive->close();
	}

	protected function tearDown(): void
	{
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $file)
		{
			$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		rmdir($this->dir);
	}

	public function testEverythingInstallsUnderExtMinosModeration(): void
	{
		self::assertGreaterThan(20, count($this->entries));
		foreach ($this->entries as $entry)
		{
			self::assertStringStartsWith('minos/moderation/', $entry);
		}
		foreach (array('ext.php', 'client_loader.php', 'composer.json', 'config/services.yml', 'config/routing.yml',
			'language/pl/acp.php', 'language/en/acp.php', 'LICENSE', 'README.md',
			'vendor/minos-moderation/client-php/src/Signature.php',
			'vendor/minos-moderation/client-php/src/WebhookPayload.php',
			'vendor/minos-moderation/client-php/LICENSE') as $needed)
		{
			self::assertContains('minos/moderation/' . $needed, $this->entries);
		}
	}

	public function testNoLockNoDependencyManifestNoTestsNoMock(): void
	{
		foreach ($this->entries as $entry)
		{
			$path = substr($entry, strlen('minos/moderation/'));
			self::assertStringEndsNotWith('composer.lock', $path);
			self::assertFalse($path !== 'composer.json' && basename($path) === 'composer.json', "{$path}: a dependency manifest");
			self::assertDoesNotMatchRegularExpression('#(^|/)(tests?|mock-gateway|vectors|docs|bin|\.github|\.claude)/#', $path);
			self::assertDoesNotMatchRegularExpression('#(^|/)(CLAUDE\.md|phpunit[^/]*|\.phpunit\.result\.cache)$#', $path);
			self::assertDoesNotMatchRegularExpression('#^vendor/(?!minos-moderation/client-php/(src/[A-Za-z]+\.php|LICENSE)$)#', $path,
				'of vendor/, only the client\'s sources and licence');
		}
	}

	public function testTheShippedComposerJsonIsOnlyWhatPhpbbReads(): void
	{
		$meta = json_decode((string) file_get_contents($this->dir . '/unpacked/minos/moderation/composer.json'), true);

		self::assertSame('minos/moderation', $meta['name']);
		self::assertSame('phpbb-extension', $meta['type']);
		self::assertNotEmpty($meta['version']);
		self::assertNotEmpty($meta['license']);
		self::assertNotEmpty($meta['authors'][0]['name']);
		self::assertSame('Minos — moderacja postów', $meta['extra']['display-name']);
		self::assertSame('~3.3', $meta['extra']['soft-require']['phpbb/phpbb']);
		foreach (array('require-dev', 'autoload', 'autoload-dev', 'repositories', 'config', 'minimum-stability') as $key)
		{
			self::assertArrayNotHasKey($key, $meta);
		}
		foreach (array_keys($meta['require']) as $requirement)
		{
			self::assertMatchesRegularExpression('/^(php|ext-[a-z_]+)$/', $requirement, 'the client is bundled, not a dependency');
		}
	}

	public function testThePackageVerifiesTheGatewaysSignatureWithoutComposer(): void
	{
		$script = $this->dir . '/check.php';
		file_put_contents($script, '<?php
			require $argv[1] . "/minos/moderation/client_loader.php";
			\minos\moderation\client_loader::register();
			$v = json_decode(file_get_contents($argv[2]), true);
			echo \Minos\Client\Signature::verify($v["secret"], $v["header"], $v["body"], (int) $v["timestamp"]) ? "verified" : "refused";
			echo " ", (new ReflectionClass("Minos\Client\Signature"))->getFileName();
		');

		$output = $this->run_php(array($script, $this->dir . '/unpacked', self::ROOT . '/vendor/minos-moderation/client-php/vectors/signature.json'));

		self::assertSame('verified ' . realpath($this->dir . '/unpacked/minos/moderation/vendor/minos-moderation/client-php/src/Signature.php'), $output);
	}

	/**
	 * Runs PHP in a process of its own, without this process's autoloaders.
	 *
	 * @param array<int,string> $arguments
	 * @return string Its standard output.
	 */
	private function run_php(array $arguments): string
	{
		$process = proc_open(array_merge(array(PHP_BINARY), $arguments), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
		self::assertIsResource($process);
		$output = (string) stream_get_contents($pipes[1]);
		$errors = (string) stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		self::assertSame(0, proc_close($process), $errors . $output);
		return trim($output);
	}
}
