<?php

namespace phpbb\db\driver;

/**
 * Stub of phpBB's database driver interface: the members the extension calls.
 */
interface driver_interface
{
	public function sql_build_array($query, $assoc_ary = array());

	public function sql_transaction($status = 'begin');

	public function sql_fetchrow($query_id = false);

	public function sql_query_limit($query, $total, $offset = 0, $cache_ttl = 0);

	public function sql_query($query = '', $cache_ttl = 0);

	public function sql_freeresult($query_id = false);

	public function sql_affectedrows();

	public function sql_escape($msg);

	public function sql_in_set($field, $array, $negate = false, $allow_empty_set = false);
}
