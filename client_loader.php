<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 INPERITIA
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace minos\moderation;

/**
 * Loads the bundled Minos PHP client (`Minos\Client`) from the extension's own `vendor/`.
 *
 * phpBB autoloads the extension's own classes, but not a Composer tree shipped inside it.
 * Requiring the bundled `vendor/autoload.php` would register a second Composer class loader
 * next to phpBB's own, and two copies of `Composer\Autoload\ClassLoader` of different
 * versions do not mix. This maps the one namespace the extension needs onto its files.
 */
class client_loader
{
	/** The namespace of the bundled client. */
	const PREFIX = 'Minos\\Client\\';

	/** @var bool Whether the loader is registered. */
	protected static $registered = false;

	/**
	 * Makes the `Minos\Client` classes loadable; calling it again does nothing.
	 *
	 * @return void
	 */
	public static function register()
	{
		if (self::$registered)
		{
			return;
		}
		self::$registered = true;
		spl_autoload_register(array(__CLASS__, 'load'));
	}

	/**
	 * The file that holds a bundled client class.
	 *
	 * @param string $class A fully qualified class name.
	 * @return string|null The path, or null for a class outside `Minos\Client`.
	 */
	public static function path($class)
	{
		if (strncmp($class, self::PREFIX, strlen(self::PREFIX)) !== 0)
		{
			return null;
		}
		$relative = substr($class, strlen(self::PREFIX));
		if (!preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative))
		{
			return null;
		}
		return __DIR__ . '/vendor/minos-moderation/client-php/src/' . str_replace('\\', '/', $relative) . '.php';
	}

	/**
	 * The autoloader: includes a bundled client class when its file exists.
	 *
	 * @param string $class A fully qualified class name.
	 * @return void
	 */
	public static function load($class)
	{
		$path = self::path($class);
		if ($path !== null && is_file($path))
		{
			require $path;
		}
	}
}
