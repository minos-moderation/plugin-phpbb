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
 * Records what phpBB 3.3's real text formatter makes of a corpus of invented posts, as the
 * fixtures of tests/fixtures/phpbb-3.3/ (see docs/development.md, "Text fixtures").
 *
 *   php bin/phpbb-text-fixtures.php <phpBB checkout> <output directory>
 *
 * <phpBB checkout> is a clone of phpBB's own repository (github.com/phpbb/phpbb) with
 * `composer install` run in its phpBB/ directory. The script builds phpBB's s9e factory,
 * parser and utils the way phpBB's own unit tests do (tests/test_framework/
 * phpbb_test_case_helpers.php, set_s9e_services()): the default BBCodes, the smilies and the
 * style of phpBB's test fixture tests/text_formatter/s9e/fixtures/default_formatting.xml, no
 * censored words, no custom BBCodes. Each text is parsed as posting.php parses a post with
 * BBCode, smilies and links on, and the stored XML, `clean_formatting()` and `unparse()` are
 * written with the phpBB commit they came from. Never run it on real posts.
 */

if ($argc !== 3)
{
	fwrite(STDERR, "usage: php bin/phpbb-text-fixtures.php <phpBB checkout> <output directory>\n");
	exit(2);
}

$checkout = rtrim($argv[1], '/');
$out = rtrim($argv[2], '/');
$phpbb_root_path = $checkout . '/phpBB/';
$phpEx = 'php';
if (!is_file($phpbb_root_path . 'vendor/autoload.php') || !is_file($phpbb_root_path . 'includes/constants.php'))
{
	fwrite(STDERR, "{$checkout} is not a phpBB checkout with composer install run in phpBB/\n");
	exit(1);
}

// The corpus: invented texts only. The key is the fixture's file name.
$corpus = array(
	'bold-inside-a-word'   => 'zak[b]a[/b]zane',
	'formatting-in-words'  => 'to jest zak[b]a[/b]zane słowo i [i][/i]dru[color=#000000]gie[/color]',
	'quote-then-a-word'    => '[quote="X"]Zgoda[/quote]zakazane',
	'nested-quotes'        => "[quote=\"Ala\"][quote=\"Ola\"]środek[/quote]zewnątrz[/quote]ja",
	'list'                 => "Lista:\n[list]\n[*]jeden\n[*]dwa\n[/list]\nkoniec",
	'ordered-list'         => '[list=1][*]pierwszy[*]drugi[/list]',
	'link-with-text'       => 'Zobacz [url=https://example.org/strona]tekst linku[/url] tutaj.',
	'bare-link'            => 'Adres https://example.org/a w tekście.',
	'code'                 => "Przed[code]kod  z  odstępami\ndruga linia[/code]po",
	'color'                => 'ko[color=#FF0000]lor[/color]owy',
	'size'                 => 'roz[size=150]miar[/size]owy',
	'underline-italic'     => 'pod[u]kreś[/u]lo[i]ne[/i]',
	'smilies'              => 'Wesoło :) i bardzo :D koniec',
	'image'                => 'Obrazek [img]https://example.org/a.png[/img] koniec',
	'plain-lines'          => "Pierwsza linia\nDruga linia\n\nCzwarta & <znaki> \"cudzysłów\"",
	'blank-bold'           => '[b] [/b]',
	'empty-italic'         => '[i][/i]',
	'image-only'           => '[img]https://example.org/a.png[/img]',
	'image-with-size'      => '[img=100x100]https://example.org/a.png[/img]',
	'link-blank-label'     => '[url=https://example.org/a] [/url]',
	'link-empty-label'     => '[url=https://example.org/a][/url]',
	'url-tag'              => '[url]https://example.org/a[/url]',
	'bare-link-only'       => 'https://example.org/a',
	'attachment-inline'    => '[attachment=0]obrazek.png[/attachment]',
	'flash'                => '[flash=200,100]https://example.org/a.swf[/flash]',
);

define('IN_PHPBB', true);
// What phpBB's test bootstrap loads (tests/bootstrap.php), less its test framework.
require $phpbb_root_path . 'includes/constants.php';
require $phpbb_root_path . 'includes/utf/utf_tools.php';
require $phpbb_root_path . 'includes/functions.php';
require $phpbb_root_path . 'includes/functions_content.php';
require $phpbb_root_path . 'includes/functions_compatibility.php';
require $phpbb_root_path . 'vendor/autoload.php';
require $phpbb_root_path . 'phpbb/class_loader.php';
$loader = new \phpbb\class_loader('phpbb\\', $phpbb_root_path . 'phpbb/', $phpEx);
$loader->register();

/**
 * The fixture's tables, as phpBB's test helper reads them.
 *
 * @param string $file The dataset.
 * @return array<string,array<int,array<string,string>>>
 */
function minos_dataset($file)
{
	$dataset = new DOMDocument();
	$dataset->load($file);
	$tables = array();
	foreach ($dataset->getElementsByTagName('table') as $table)
	{
		$columns = array();
		foreach ($table->getElementsByTagName('column') as $column)
		{
			$columns[] = $column->textContent;
		}
		$rows = array();
		foreach ($table->getElementsByTagName('row') as $row)
		{
			$values = array();
			foreach ($row->getElementsByTagName('value') as $value)
			{
				$values[] = $value->textContent;
			}
			$rows[] = array_combine($columns, $values);
		}
		$tables[$table->getAttribute('name')] = $rows;
	}
	return $tables;
}

/**
 * phpBB's data access with the fixture's rows in place of the database, as the test helper
 * mocks it.
 */
class minos_fixture_data_access extends \phpbb\textformatter\data_access
{
	/** @var array<string,array<int,array<string,string>>> */
	public $tables = array();

	public function get_bbcodes()
	{
		return array();
	}

	public function get_smilies()
	{
		return isset($this->tables['phpbb_smilies']) ? $this->tables['phpbb_smilies'] : array();
	}

	public function get_styles()
	{
		return array(array('style_id' => 1, 'style_path' => 'prosilver', 'bbcode_bitfield' => '//g=', 'style_parent_id' => 0));
	}

	public function get_censored_words()
	{
		return array();
	}
}

/**
 * A log that only reports what the factory would log.
 */
class minos_stderr_log implements \phpbb\log\log_interface
{
	public function is_enabled($type = '')
	{
		return true;
	}

	public function disable($type = '')
	{
	}

	public function enable($type = '')
	{
	}

	public function add($mode, $user_id, $log_ip, $log_operation, $log_time = false, $additional_data = array())
	{
		fwrite(STDERR, $log_operation . ' ' . json_encode($additional_data) . "\n");
		return false;
	}

	public function delete($mode, $conditions = array())
	{
	}

	public function get_logs($mode, $count_logs = true, $limit = 0, $offset = 0, $forum_id = 0, $topic_id = 0, $user_id = 0, $log_time = 0, $sort_by = 'l.log_time DESC', $keywords = '')
	{
		return array();
	}

	public function get_log_count()
	{
		return 0;
	}

	public function get_valid_offset()
	{
		return 0;
	}
}

$data_access = new minos_fixture_data_access(new \phpbb\db\driver\sqlite3(), 'phpbb_bbcodes', 'phpbb_smilies', 'phpbb_styles', 'phpbb_words', $phpbb_root_path . 'styles/');
$data_access->tables = minos_dataset($checkout . '/tests/text_formatter/s9e/fixtures/default_formatting.xml');
$cache = new \phpbb\cache\driver\dummy();
$dispatcher = new \phpbb\event\dispatcher();
// The test helper's minimum configuration; generate_board_url() reads it (and $user->host).
$config = new \phpbb\config\config(array(
	'allow_nocensors'       => false,
	'allowed_schemes_links' => 'http,https,ftp',
	'cookie_secure'         => 0,
	'force_server_vars'     => 1,
	'script_path'           => '/phpbb',
	'server_name'           => 'localhost',
	'server_port'           => 80,
	'server_protocol'       => 'http://',
	'smilies_path'          => 'images/smilies',
));
$user = new stdClass();
$user->host = '';
$factory = new \phpbb\textformatter\s9e\factory($data_access, $cache, $dispatcher, $config, new \phpbb\textformatter\s9e\link_helper(),
	new minos_stderr_log(), sys_get_temp_dir() . '/', '_s9e_parser', '_s9e_renderer');
$parser = new \phpbb\textformatter\s9e\parser($cache, '_s9e_parser', $factory, $dispatcher);
$utils = new \phpbb\textformatter\s9e\utils();

// posting.php's settings for a post: everything on, the default limits of a new board.
$parser->enable_bbcodes();
$parser->enable_magic_url();
$parser->enable_smilies();
$parser->set_vars(array('max_font_size' => 200, 'max_img_height' => 0, 'max_img_width' => 0, 'max_smilies' => 0, 'max_urls' => 0));

$commit = trim((string) shell_exec('git -C ' . escapeshellarg($checkout) . ' rev-parse HEAD'));
if (!preg_match('/^[0-9a-f]{40}$/', $commit))
{
	fwrite(STDERR, "cannot read the checkout's commit\n");
	exit(1);
}
if (!is_dir($out) && !mkdir($out, 0755, true))
{
	fwrite(STDERR, "cannot create {$out}\n");
	exit(1);
}
foreach ($corpus as $name => $text)
{
	$xml = $parser->parse($text);
	$fixture = array(
		'phpbb_commit'     => $commit,
		'phpbb_version'    => PHPBB_VERSION,
		'generator'        => 'bin/phpbb-text-fixtures.php',
		'bbcode'           => $text,
		'xml'              => $xml,
		'clean_formatting' => $utils->clean_formatting($xml),
		'unparse'          => $utils->unparse($xml),
	);
	file_put_contents($out . '/' . $name . '.json', json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
	echo $name, "\n";
}
