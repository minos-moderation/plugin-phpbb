<?php

namespace minos\moderation\tests\Extension;

use minos\moderation\client_loader;
use minos\moderation\ext;
use minos\moderation\migrations\install_data;
use minos\moderation\migrations\install_schema;
use minos\moderation\service\settings;
use PHPUnit\Framework\TestCase;

/**
 * What phpBB's extension manager sees: the metadata, the migrations, the bundled client.
 */
final class ExtensionTest extends TestCase
{
	private const ROOT = __DIR__ . '/../..';

	public function testTheBundledClientLoadsFromTheExtensionsVendorDirectory(): void
	{
		$path = client_loader::path('Minos\Client\Signature');

		self::assertSame(realpath(self::ROOT . '/vendor/minos-moderation/client-php/src/Signature.php'), realpath((string) $path));
		self::assertNull(client_loader::path('minos\moderation\ext'));
		self::assertNull(client_loader::path('Minos\Client\..\..\config'));
	}

	public function testTheExtensionCanBeEnabledWhereItsRequirementsHold(): void
	{
		self::assertTrue((new ext(null))->is_enableable());
	}

	public function testTheComposerMetadataPassesPhpbbsValidation(): void
	{
		$meta = json_decode((string) file_get_contents(self::ROOT . '/composer.json'), true);

		// The rules of phpbb\extension\metadata_manager::validate() in phpBB 3.3.
		self::assertMatchesRegularExpression('#^[a-zA-Z0-9_\x7f-\xff]{2,}/[a-zA-Z0-9_\x7f-\xff]{2,}$#', $meta['name']);
		self::assertSame('minos/moderation', $meta['name'], 'the name is the install path under ext/');
		self::assertSame('phpbb-extension', $meta['type']);
		self::assertNotEmpty($meta['license']);
		self::assertNotEmpty($meta['version']);
		self::assertNotEmpty($meta['authors'][0]['name']);
		self::assertSame('Minos — moderacja postów', $meta['extra']['display-name']);
		self::assertSame('~3.3', $meta['extra']['soft-require']['phpbb/phpbb']);
		self::assertSame('>=7.4', $meta['require']['php']);
	}

	public function testANewInstallationStartsOffWithTheDocumentedDefaults(): void
	{
		$config = new \phpbb\config\config();
		$config_text = new \phpbb\config\db_text();
		$migration = new install_data($config, new \minos\moderation\tests\Fake\SqliteDriver(':memory:'), null, './', 'php', 'phpbb_');
		$modules = 0;
		foreach ($migration->update_data() as $step)
		{
			list($tool, $arguments) = $step;
			if ($tool === 'config.add')
			{
				$config->set($arguments[0], $arguments[1]);
			}
			else if ($tool === 'config_text.add')
			{
				$config_text->set($arguments[0], $arguments[1]);
			}
			else if ($tool === 'module.add')
			{
				$modules++;
			}
		}
		$settings = new settings($config, $config_text);

		self::assertFalse($settings->enabled());
		self::assertFalse($settings->is_ready());
		self::assertSame('https://gateway.wergiliusz.app', $settings->gateway_url());
		self::assertSame('forum_adult', $settings->profile());
		self::assertSame(settings::FAIL_CLOSED, $settings->fail_mode(), 'an unassessed post waits for a human');
		self::assertSame(20 * 60, $settings->timeout_seconds());
		self::assertSame(settings::CENSORED_PUBLISH, $settings->censored_mode());
		self::assertSame(settings::HOLD, $settings->blocked_mode());
		self::assertSame(array(), $settings->forum_ids());
		self::assertSame(2, $modules, 'a category and the settings page');
		self::assertSame(array('\minos\moderation\migrations\install_schema'), install_data::depends_on());
		self::assertSame(array('\phpbb\db\migration\data\v330\v330'), install_schema::depends_on());
	}

	public function testAnUnrecognisedStoredValueNeverPublishesByItself(): void
	{
		$config = new \phpbb\config\config(array(
			settings::FAIL_MODE => 'open',
			settings::CENSORED  => 'publikuj',
			settings::BLOCKED   => 'usun',
			settings::PROFILE   => 'forum_kids',
		));
		$settings = new settings($config, new \phpbb\config\db_text());

		self::assertSame(settings::FAIL_CLOSED, $settings->fail_mode());
		self::assertSame(settings::HOLD, $settings->censored_mode());
		self::assertSame(settings::HOLD, $settings->blocked_mode());
		self::assertSame('forum_adult', $settings->profile());
	}

	public function testTheTimeoutIsNeverUnderTwentyMinutes(): void
	{
		foreach (array(0 => 20, 5 => 20, 16 => 20, 19 => 20, 20 => 20, 45 => 45, 5000 => 1440) as $stored => $minutes)
		{
			$settings = new settings(new \phpbb\config\config(array(settings::TIMEOUT_MIN => $stored)), new \phpbb\config\db_text());
			self::assertSame($minutes * 60, $settings->timeout_seconds(), "stored {$stored}");
		}
	}

	/**
	 * The phpBB 3.3 services the extension uses (`config/default/container/*.yml`), with the
	 * class or interface each one is.
	 */
	private const PHPBB_SERVICES = array(
		'config'                => '\phpbb\config\config',
		'config_text'           => '\phpbb\config\db_text',
		'dbal.conn'             => '\phpbb\db\driver\driver_interface',
		'content.visibility'    => '\phpbb\content_visibility',
		'notification_manager'  => '\phpbb\notification\manager',
		'text_formatter.utils'  => '\phpbb\textformatter\utils_interface',
		'text_formatter.parser' => '\phpbb\textformatter\parser_interface',
		'log'                   => '\phpbb\log\log_interface',
		'language'              => '\phpbb\language\language',
		'auth'                  => '\phpbb\auth\auth',
		'symfony_request'       => '\Symfony\Component\HttpFoundation\Request',
		'dispatcher'            => '\Symfony\Component\EventDispatcher\EventDispatcherInterface',
		'request'               => '\phpbb\request\request_interface',
		'template'              => '\phpbb\template\template',
		'user'                  => '\phpbb\user',
		'controller.helper'     => '\phpbb\controller\helper',
	);

	public function testEveryServiceGetsTheArgumentsItsConstructorTakes(): void
	{
		$services = self::services();
		self::assertGreaterThanOrEqual(10, count($services), 'the parser found the services');

		foreach ($services as $id => $service)
		{
			$class = new \ReflectionClass($service['class']);
			$parameters = $class->getConstructor() ? $class->getConstructor()->getParameters() : array();
			self::assertCount(count($parameters), $service['arguments'], $id);
			foreach ($service['arguments'] as $index => $argument)
			{
				$type = $parameters[$index]->getType();
				if (strncmp($argument, '@', 1) !== 0)
				{
					self::assertTrue($type === null || !$type instanceof \ReflectionNamedType || $type->isBuiltin(),
						"{$id}: argument {$index} is a parameter where a service is expected");
					continue;
				}
				$ref = substr($argument, 1);
				self::assertTrue(isset($services[$ref]) || array_key_exists($ref, self::PHPBB_SERVICES), "{$id}: unknown service {$ref}");
				if ($type instanceof \ReflectionNamedType && !$type->isBuiltin())
				{
					$given = isset($services[$ref]) ? $services[$ref]['class'] : ltrim(self::PHPBB_SERVICES[$ref], '\\');
					self::assertTrue(is_a($given, $type->getName(), true), "{$id}: argument {$index} ({$ref}) is not a {$type->getName()}");
				}
			}
		}
		self::assertSame('minos\moderation\cron\sweeper', $services['minos.moderation.cron.sweeper']['class']);
	}

	public function testTheWebhookRouteTakesOnlyPost(): void
	{
		$routing = (string) file_get_contents(self::ROOT . '/config/routing.yml');

		self::assertMatchesRegularExpression('#^\s+path: /minos/webhook$#m', $routing);
		self::assertMatchesRegularExpression('#^\s+defaults: \{ _controller: minos\.moderation\.controller\.webhook:handle \}$#m', $routing);
		self::assertMatchesRegularExpression('#^\s+methods: \[POST\]$#m', $routing);
		self::assertTrue(method_exists('minos\moderation\controller\webhook', 'handle'));
	}

	/**
	 * `config/services.yml`, read for its classes and arguments (the file keeps one shape:
	 * an id, `class:`, and `arguments:` as a list).
	 *
	 * @return array<string,array{class:string,arguments:array<int,string>}>
	 */
	private static function services(): array
	{
		$services = array();
		$current = null;
		$in_arguments = false;
		foreach (file(self::ROOT . '/config/services.yml', FILE_IGNORE_NEW_LINES) ?: array() as $line)
		{
			if (preg_match('/^    ([a-z0-9_.]+):$/', $line, $m))
			{
				$current = $m[1];
				$services[$current] = array('class' => '', 'arguments' => array());
				$in_arguments = false;
			}
			else if ($current !== null && preg_match('/^        class: (\S+)$/', $line, $m))
			{
				$services[$current]['class'] = $m[1];
			}
			else if ($current !== null && preg_match('/^        arguments:$/', $line))
			{
				$in_arguments = true;
			}
			else if ($current !== null && $in_arguments && preg_match("/^            - '([^']+)'$/", $line, $m))
			{
				$services[$current]['arguments'][] = $m[1];
			}
			else if (preg_match('/^        \S/', $line))
			{
				$in_arguments = false;
			}
		}
		return $services;
	}
}
