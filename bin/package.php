<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 INPERITIA
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

/*
 * Writes the installable package: a ZIP holding minos/moderation/ with what phpBB loads and
 * the bundled client's sources, and nothing else.
 *
 *   php bin/package.php <zip> <vendor>
 *
 * <vendor> is a Composer vendor/ directory holding minos-moderation/client-php (bin/build-zip.sh
 * installs one from composer.lock without development packages). Of it, only the client's
 * src/*.php and LICENSE are packed. No composer.lock, no dependency manifest, no tests, no
 * mock gateway. The extension's own composer.json ships, reduced to the metadata phpBB's
 * extension manager reads (phpBB refuses an extension without it).
 */

if (PHP_SAPI !== 'cli' || $argc !== 3)
{
	fwrite(STDERR, "usage: php bin/package.php <zip> <vendor>\n");
	exit(2);
}

$root = dirname(__DIR__);
$zip_path = $argv[1];
$client = rtrim($argv[2], '/') . '/minos-moderation/client-php';
if (!is_file($client . '/src/Signature.php') || !is_file($client . '/LICENSE'))
{
	fwrite(STDERR, "the bundled client is not in {$argv[2]}\n");
	exit(1);
}

// What phpBB loads, the licence and the manual.
$directories = array('acp', 'adm', 'config', 'controller', 'cron', 'event', 'language', 'migrations', 'platform', 'service', 'styles');
$files = array('client_loader.php', 'ext.php', 'LICENSE', 'README.md');

// The metadata phpBB's metadata_manager reads; the dependencies are bundled, not declared.
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
$shipped = array();
foreach (array('name', 'type', 'description', 'homepage', 'version', 'license', 'authors', 'extra') as $key)
{
	if (isset($composer[$key]))
	{
		$shipped[$key] = $composer[$key];
	}
}
$shipped['require'] = array_intersect_key($composer['require'], array_flip(array_filter(array_keys($composer['require']), function ($name) {
	return $name === 'php' || strncmp($name, 'ext-', 4) === 0;
})));

@unlink($zip_path);
$zip = new ZipArchive();
if ($zip->open($zip_path, ZipArchive::CREATE) !== true)
{
	fwrite(STDERR, "cannot create {$zip_path}\n");
	exit(1);
}
$prefix = 'minos/moderation/';
foreach ($directories as $directory)
{
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
	foreach ($iterator as $file)
	{
		$zip->addFile($file->getPathname(), $prefix . substr($file->getPathname(), strlen($root) + 1));
	}
}
foreach ($files as $file)
{
	$zip->addFile($root . '/' . $file, $prefix . $file);
}
$zip->addFromString($prefix . 'composer.json', json_encode($shipped, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
foreach (glob($client . '/src/*.php') ?: array() as $file)
{
	$zip->addFile($file, $prefix . 'vendor/minos-moderation/client-php/src/' . basename($file));
}
$zip->addFile($client . '/LICENSE', $prefix . 'vendor/minos-moderation/client-php/LICENSE');
$zip->close();
echo $zip_path, "\n";
