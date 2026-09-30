<?php

namespace minos\moderation\tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * The language files: Polish in both directories, and every key the extension uses defined.
 *
 * The extension speaks Polish. `language/en/` exists because phpBB falls back to `en` for a
 * user whose language has no file of the extension; it holds the same Polish texts, so that
 * nobody sees raw language keys.
 */
final class LanguageTest extends TestCase
{
	private const ROOT = __DIR__ . '/../..';

	public function testTheFallbackDirectoryHoldsTheSamePolishTexts(): void
	{
		$polish = self::files('pl');
		self::assertNotEmpty($polish);
		self::assertSame(array_keys($polish), array_keys(self::files('en')));
		foreach ($polish as $name => $path)
		{
			self::assertFileEquals($path, self::ROOT . '/language/en/' . $name);
		}
	}

	public function testEveryFileRefusesToRunOutsidePhpbb(): void
	{
		foreach (self::files('pl') as $name => $path)
		{
			self::assertMatchesRegularExpression("/if \\(!defined\\('IN_PHPBB'\\)\\)\\s*\\{\\s*exit;/", (string) file_get_contents($path), $name);
		}
	}

	public function testTheLogKeysAreTheSameInTheAcpAndTheMcp(): void
	{
		$acp = self::load(array('info_acp_moderation.php'));
		$mcp = self::load(array('info_mcp_moderation.php'));
		$acp_logs = array_intersect_key($acp, $mcp);
		self::assertNotEmpty($acp_logs);
		foreach ($mcp as $key => $text)
		{
			if (strncmp($key, 'LOG_', 4) === 0)
			{
				self::assertSame($acp[$key], $text, $key);
			}
		}
	}

	public function testEveryKeyTheExtensionUsesIsDefined(): void
	{
		$defined = self::load(array_keys(self::files('pl')));
		$used = array();
		foreach (self::sources() as $path)
		{
			$text = (string) file_get_contents($path);
			// In PHP: what is passed to lang(), and the literals that are keys by their prefix
			// (template variables are MINOS_* too, and are not language keys).
			preg_match_all("/lang\\('([A-Z0-9_]+)'|'((?:ACP_MINOS|LOG_MINOS|MINOS_ERROR|MINOS_INSTALL|MINOS_REFUSAL|MINOS_MCP)_[A-Z0-9_]+)'/", $text, $php);
			preg_match_all('/\{L_([A-Z0-9_]+)\}|lang\(\'([A-Z0-9_]+)\'\)/', $text, $template);
			foreach (array_merge($php[1], $php[2], $template[1], $template[2]) as $key)
			{
				if ($key !== '')
				{
					$used[$key] = $path;
				}
			}
		}
		self::assertGreaterThan(60, count($used), 'the scan finds the keys');

		// Keys built at run time: every value they can take.
		foreach (array('QUEUED', 'PENDING', 'RECEIVED', 'PUBLISHED', 'PUBLISHED_FAIL_OPEN', 'MASKED', 'HELD', 'DELETED', 'SUPERSEDED') as $status)
		{
			$used['MINOS_STATUS_' . $status] = 'controller/acp.php';
		}
		foreach (array('BEZPIECZNE', 'OCENZUROWANE', 'ZABLOKOWANE', 'NIEOCENIONE', 'TIMEOUT', 'REFUSED') as $verdict)
		{
			$used['MINOS_MCP_VERDICT_' . $verdict] = 'event/listener.php';
		}
		foreach (array('PROFILE_FORUM_ADULT', 'PROFILE_FORUM_TEEN', 'FAIL_MODE_FAIL_OPEN', 'FAIL_MODE_FAIL_CLOSED',
			'CENSORED_PUBLISH', 'CENSORED_HOLD', 'BLOCKED_HOLD', 'BLOCKED_DELETE') as $choice)
		{
			$used['MINOS_' . $choice] = 'controller/acp.php';
		}

		// phpBB's own keys the extension borrows.
		$phpbb = array('COLON', 'YES', 'NO', 'SUBMIT', 'RESET', 'FORM_INVALID');
		foreach ($used as $key => $where)
		{
			if (in_array($key, $phpbb, true) || substr($key, -1) === '_')
			{
				continue;
			}
			self::assertArrayHasKey($key, $defined, "{$key}, used in {$where}");
		}
	}

	/**
	 * @return array<string,string> File name => path.
	 */
	private static function files(string $language): array
	{
		$files = array();
		foreach (glob(self::ROOT . '/language/' . $language . '/*.php') ?: array() as $path)
		{
			$files[basename($path)] = $path;
		}
		ksort($files);
		return $files;
	}

	/**
	 * @param array<int,string> $names
	 * @return array<string,string>
	 */
	private static function load(array $names): array
	{
		$lang = array();
		foreach ($names as $name)
		{
			include self::ROOT . '/language/pl/' . $name;
		}
		return $lang;
	}

	/**
	 * The extension's PHP and templates, without tests and dependencies.
	 *
	 * @return array<int,string>
	 */
	private static function sources(): array
	{
		$found = array();
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT, \FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file)
		{
			$path = str_replace('\\', '/', substr($file->getPathname(), strlen(self::ROOT) + 1));
			if (preg_match('#^(vendor|tests|language|build|\.git)/#', $path) || !preg_match('/\.(php|html)$/', $path))
			{
				continue;
			}
			$found[] = $file->getPathname();
		}
		return $found;
	}
}
