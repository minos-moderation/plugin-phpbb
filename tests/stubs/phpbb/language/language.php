<?php

namespace phpbb\language;

/**
 * Stub of phpBB's language service over the extension's own Polish files: a missing key
 * comes back as itself, as in phpBB.
 */
class language
{
	/** @var array<string,string> */
	protected $lang = array();

	/** @var array<int,array{0:string,1:string|null}> Every add_lang() call. */
	public $loaded = array();

	/**
	 * @param string $directory A language directory of the extension.
	 */
	public function __construct($directory)
	{
		foreach (glob($directory . '/*.php') ?: array() as $file)
		{
			$lang = $this->lang;
			include $file;
			$this->lang = $lang;
		}
	}

	public function add_lang($component, $extension_name = null)
	{
		$this->loaded[] = array((string) $component, $extension_name);
	}

	public function lang()
	{
		$args = func_get_args();
		$key = array_shift($args);
		if (!isset($this->lang[$key]))
		{
			return $key;
		}
		return $args ? vsprintf($this->lang[$key], $args) : $this->lang[$key];
	}
}
