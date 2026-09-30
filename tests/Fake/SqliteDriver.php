<?php

namespace minos\moderation\tests\Fake;

/**
 * phpBB's database driver over SQLite (PDO), for the tests.
 *
 * Like phpBB on MySQL, every value comes back as a string (or null), so a comparison that
 * forgets a cast fails here as it would there. Transactions nest the way phpBB's do: only
 * the outermost `begin`/`commit` reach the database, and `rollback` ends them all.
 */
class SqliteDriver implements \phpbb\db\driver\driver_interface
{
	/** @var \PDO */
	public $pdo;

	/** @var array<int,string> Every transaction call, in order. */
	public $transactions = array();

	/** @var int */
	protected $affected = 0;

	/** @var int */
	protected $depth = 0;

	/**
	 * @param string $path A database file, or ':memory:'.
	 */
	public function __construct($path)
	{
		$this->pdo = new \PDO('sqlite:' . $path);
		$this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$this->pdo->setAttribute(\PDO::ATTR_TIMEOUT, 10);
	}

	public function sql_query($query = '', $cache_ttl = 0)
	{
		$statement = $this->pdo->query($query);
		$this->affected = $statement->rowCount();
		return $statement;
	}

	public function sql_query_limit($query, $total, $offset = 0, $cache_ttl = 0)
	{
		return $this->sql_query($query . ' LIMIT ' . (int) $total . ' OFFSET ' . (int) $offset);
	}

	public function sql_fetchrow($query_id = false)
	{
		if (!$query_id instanceof \PDOStatement)
		{
			return false;
		}
		$row = $query_id->fetch(\PDO::FETCH_ASSOC);
		if ($row === false)
		{
			return false;
		}
		foreach ($row as $key => $value)
		{
			$row[$key] = ($value === null) ? null : (string) $value;
		}
		return $row;
	}

	public function sql_freeresult($query_id = false)
	{
		if ($query_id instanceof \PDOStatement)
		{
			$query_id->closeCursor();
		}
		return true;
	}

	public function sql_affectedrows()
	{
		return $this->affected;
	}

	public function sql_escape($msg)
	{
		return str_replace("'", "''", (string) $msg);
	}

	public function sql_build_array($query, $assoc_ary = array())
	{
		$values = array();
		foreach ($assoc_ary as $key => $value)
		{
			$values[$key] = $this->value($value);
		}
		if ($query === 'INSERT')
		{
			return '(' . implode(', ', array_keys($values)) . ') VALUES (' . implode(', ', $values) . ')';
		}
		$pairs = array();
		foreach ($values as $key => $value)
		{
			$pairs[] = $key . ' = ' . $value;
		}
		return implode(($query === 'UPDATE') ? ', ' : ' AND ', $pairs);
	}

	public function sql_in_set($field, $array, $negate = false, $allow_empty_set = false)
	{
		$array = array_values((array) $array);
		if (!$array)
		{
			return $negate ? '1=1' : '1=0';
		}
		if (count($array) === 1)
		{
			return $field . ($negate ? ' <> ' : ' = ') . $this->value($array[0]);
		}
		return $field . ($negate ? ' NOT IN ' : ' IN ') . '(' . implode(', ', array_map(array($this, 'value'), $array)) . ')';
	}

	public function sql_transaction($status = 'begin')
	{
		$this->transactions[] = $status;
		switch ($status)
		{
			case 'begin':
				if ($this->depth++ === 0)
				{
					$this->pdo->beginTransaction();
				}
			break;

			case 'commit':
				if ($this->depth > 0 && --$this->depth === 0)
				{
					$this->pdo->commit();
				}
			break;

			case 'rollback':
				if ($this->depth > 0)
				{
					$this->depth = 0;
					$this->pdo->rollBack();
				}
			break;
		}
		return true;
	}

	/**
	 * Whether a transaction is open.
	 *
	 * @return bool
	 */
	public function in_transaction()
	{
		return $this->depth > 0;
	}

	/**
	 * A value as SQL, the way phpBB's `_sql_validate_value()` writes it.
	 *
	 * @param mixed $value
	 * @return string
	 */
	public function value($value)
	{
		if ($value === null)
		{
			return 'NULL';
		}
		if (is_bool($value))
		{
			return (string) (int) $value;
		}
		if (is_int($value) || is_float($value))
		{
			return (string) $value;
		}
		return "'" . $this->sql_escape($value) . "'";
	}
}
