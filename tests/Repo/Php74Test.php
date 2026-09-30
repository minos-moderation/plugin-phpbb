<?php

namespace minos\moderation\tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * The extension runs on PHP 7.4 (customers' hosts). CI's 7.4 job rejects PHP 8 syntax, but
 * a PHP 8 function passes `php -l` there and fails only on the line that calls it; this
 * finds those calls anywhere in the extension, tested or not.
 */
final class Php74Test extends TestCase
{
	private const ROOT = __DIR__ . '/../..';

	/** Functions PHP 8 added. */
	private const PHP8_FUNCTIONS = array(
		'str_contains', 'str_starts_with', 'str_ends_with', 'array_is_list', 'get_debug_type',
		'get_resource_id', 'fdiv', 'preg_last_error_msg', 'array_find', 'array_any', 'array_all',
	);

	public function testTheExtensionCallsNoFunctionPhp8Added(): void
	{
		$files = self::files();
		self::assertGreaterThan(15, count($files), 'the scan finds the extension\'s files');
		foreach ($files as $path)
		{
			foreach (token_get_all((string) file_get_contents($path)) as $token)
			{
				if (is_array($token) && $token[0] === T_STRING)
				{
					self::assertNotContains(strtolower($token[1]), self::PHP8_FUNCTIONS, "{$path}:{$token[2]}");
				}
			}
		}
	}

	/**
	 * The extension's and the tests' PHP files, without dependencies.
	 *
	 * @return array<int,string>
	 */
	private static function files(): array
	{
		$found = array();
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT, \FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file)
		{
			$path = str_replace('\\', '/', substr($file->getPathname(), strlen(self::ROOT) + 1));
			if (!preg_match('#^(vendor|build|\.git)/#', $path) && substr($path, -4) === '.php' && $path !== 'tests/Repo/Php74Test.php')
			{
				$found[] = $file->getPathname();
			}
		}
		return $found;
	}
}
