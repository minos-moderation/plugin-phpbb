<?php
/**
 *
 * Minos post moderation. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Minos
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace minos\moderation\migrations;

/**
 * The table of posts held for assessment (see `service/pending_store.php`).
 */
class install_schema extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'minos_pending');
	}

	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return array('\phpbb\db\migration\data\v330\v330');
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_schema()
	{
		return array(
			'add_tables' => array(
				$this->table_prefix . 'minos_pending' => array(
					'COLUMNS' => array(
						'post_id'       => array('ULINT', 0),
						'revision'      => array('USINT', 0),
						'status'        => array('VCHAR:16', ''),
						'verdict'       => array('VCHAR:40', ''),
						'categories'    => array('VCHAR:255', ''),
						'support'       => array('BOOL', 0),
						'truncated'     => array('BOOL', 0),
						'attempts'      => array('USINT', 0),
						'submitted_at'  => array('TIMESTAMP', 0),
						'retry_at'      => array('TIMESTAMP', 0),
						'handled_at'    => array('TIMESTAMP', 0),
						'approved_at'   => array('TIMESTAMP', 0),
						'approved_md5'  => array('VCHAR:32', ''),
						'error_code'    => array('VCHAR:40', ''),
						'masked_text'   => array('MTEXT_UNI', ''),
						'original_text' => array('MTEXT_UNI', ''),
					),
					'PRIMARY_KEY' => 'post_id',
					'KEYS'        => array(
						'status_retry' => array('INDEX', array('status', 'retry_at')),
						'handled_at'   => array('INDEX', array('handled_at')),
					),
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
			'drop_tables' => array(
				$this->table_prefix . 'minos_pending',
			),
		);
	}
}
