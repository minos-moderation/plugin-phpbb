<?php

namespace phpbb\db\migration;

/**
 * Stub of phpBB's migration base class.
 */
abstract class migration
{
	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var mixed */
	protected $db_tools;

	/** @var string */
	protected $table_prefix;

	/** @var string */
	protected $phpbb_root_path;

	/** @var string */
	protected $php_ext;

	public function __construct(\phpbb\config\config $config, \phpbb\db\driver\driver_interface $db, $db_tools, $phpbb_root_path, $php_ext, $table_prefix)
	{
		$this->config = $config;
		$this->db = $db;
		$this->db_tools = $db_tools;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;
		$this->table_prefix = $table_prefix;
	}

	public static function depends_on()
	{
		return array();
	}

	public function effectively_installed()
	{
		return false;
	}

	public function update_schema()
	{
		return array();
	}

	public function revert_schema()
	{
		return array();
	}

	public function update_data()
	{
		return array();
	}

	public function revert_data()
	{
		return array();
	}
}
