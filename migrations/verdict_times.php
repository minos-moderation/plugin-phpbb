<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 INPERITIA
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace minos\moderation\migrations;

/**
 * When the gateway's outcome for a row arrived (`received_at`): with `submitted_at`, the
 * moment of its `202`, the ACP measures the time from sending to verdict
 * (`pending_store::verdict_stats`). A time, never content.
 */
class verdict_times extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return $this->db_tools->sql_column_exists($this->table_prefix . 'minos_pending', 'received_at');
	}

	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return array('\minos\moderation\migrations\user_groups');
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_schema()
	{
		return array(
			'add_columns' => array(
				$this->table_prefix . 'minos_pending' => array(
					'received_at' => array('TIMESTAMP', 0),
				),
			),
			'add_index'   => array(
				$this->table_prefix . 'minos_pending' => array(
					'received_at' => array('received_at'),
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function revert_schema()
	{
		return array(
			'drop_keys'    => array(
				$this->table_prefix . 'minos_pending' => array('received_at'),
			),
			'drop_columns' => array(
				$this->table_prefix . 'minos_pending' => array('received_at'),
			),
		);
	}
}
