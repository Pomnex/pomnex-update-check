<?php
/**
 * Copyright (C) 2026 Pomnex
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * Additional term under GPLv3 Section 7(b):
 * You must preserve the original author attribution ("Developed by Pomnex")
 * in the source code headers and in the plugin's settings/about screen.
 *
 * @package Pomnex\UpdateCheck
 * @author  Pomnex <https://pomnex.com>
 * @license GPL-3.0-or-later
 */

namespace Pomnex\UpdateCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the update log in a custom table.
 *
 * Every query in this class targets the plugin's own table, so the direct
 * database calls are intentional. Each query uses $wpdb->prepare().
 */
final class Log_Repository {

	/**
	 * Table name without the WordPress prefix.
	 */
	const TABLE = 'pomnex_uc_log';

	/**
	 * Option that stores the installed schema version.
	 */
	const DB_VERSION_OPTION = 'pomnex_uc_db_version';

	/**
	 * Number of entries kept. Older entries are pruned on insert.
	 */
	const KEEP = 100;

	/**
	 * Check status of an entry still waiting for a check.
	 */
	const PENDING = 'pending';

	/**
	 * Returns the full table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Creates or updates the table with dbDelta() and stores the schema version.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				source varchar(20) NOT NULL DEFAULT 'manual',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				items longtext NOT NULL,
				check_status varchar(20) NOT NULL DEFAULT 'pending',
				check_results longtext NULL,
				checked_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY check_status (check_status)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, POMNEX_UC_DB_VERSION, false );
	}

	/**
	 * Runs install() when the stored schema version is older than the code's.
	 */
	public static function maybe_upgrade() {
		if ( version_compare( (string) get_option( self::DB_VERSION_OPTION, '0' ), POMNEX_UC_DB_VERSION, '<' ) ) {
			self::install();
		}
	}

	/**
	 * Inserts a new entry and prunes old ones.
	 *
	 * @param string  $source  `manual` or `automatic`.
	 * @param int     $user_id User who ran the update, 0 for automatic.
	 * @param array[] $items   Updated items.
	 * @return int New entry ID, 0 on failure.
	 */
	public function insert( $source, $user_id, array $items ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin's own table.
		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
				'source'       => 'automatic' === $source ? 'automatic' : 'manual',
				'user_id'      => (int) $user_id,
				'items'        => wp_json_encode( array_values( $items ) ),
				'check_status' => self::PENDING,
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;
		$this->prune();

		return $id;
	}

	/**
	 * Adds items to an existing entry. An item already in the entry keeps
	 * its old version and takes the new version.
	 *
	 * @param int     $id    Entry ID.
	 * @param array[] $items Items to add.
	 * @return bool
	 */
	public function append_items( $id, array $items ) {
		global $wpdb;

		$entry = $this->get( $id );

		if ( ! $entry ) {
			return false;
		}

		$merged = array();
		foreach ( array_merge( $entry['items'], $items ) as $item ) {
			$key = $item['type'] . ':' . $item['slug'];

			if ( isset( $merged[ $key ] ) ) {
				$merged[ $key ]['new_version'] = $item['new_version'];
				continue;
			}

			$merged[ $key ] = $item;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		return false !== $wpdb->update(
			self::table_name(),
			array( 'items' => wp_json_encode( array_values( $merged ) ) ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Finds a pending entry by the same person that new items can join.
	 *
	 * Updates started from the Plugins screen run one request per plugin.
	 * Joining them keeps one bulk update as one log entry.
	 *
	 * @param string $source  `manual` or `automatic`.
	 * @param int    $user_id User ID.
	 * @param int    $seconds How far back to look.
	 * @return int Entry ID, 0 when there is none.
	 */
	public function find_open_entry( $source, $user_id, $seconds ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE check_status = %s AND source = %s AND user_id = %d AND created_at >= %s ORDER BY id DESC LIMIT 1',
				self::table_name(),
				self::PENDING,
				$source,
				(int) $user_id,
				gmdate( 'Y-m-d H:i:s', time() - (int) $seconds )
			)
		);
	}

	/**
	 * Returns one entry.
	 *
	 * @param int $id Entry ID.
	 * @return array|null Decoded entry or null.
	 */
	public function get( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table_name(), (int) $id ),
			ARRAY_A
		);

		return $row ? self::decode( $row ) : null;
	}

	/**
	 * Returns all entries still waiting for a check, oldest first.
	 *
	 * @return array[] Decoded entries.
	 */
	public function get_pending() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE check_status = %s ORDER BY id ASC', self::table_name(), self::PENDING ),
			ARRAY_A
		);

		return array_map( array( __CLASS__, 'decode' ), (array) $rows );
	}

	/**
	 * Gives entries the result of a check.
	 *
	 * @param int[]  $ids     Entry IDs.
	 * @param string $status  Overall result.
	 * @param array  $results Full run results.
	 * @return int Number of entries updated.
	 */
	public function complete( array $ids, $status, array $results ) {
		global $wpdb;

		$updated = 0;
		$json    = wp_json_encode( $results );
		$now     = gmdate( 'Y-m-d H:i:s' );

		foreach ( array_map( 'intval', $ids ) as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
			$updated += (int) $wpdb->update(
				self::table_name(),
				array(
					'check_status'  => $status,
					'check_results' => $json,
					'checked_at'    => $now,
				),
				array(
					'id'           => $id,
					'check_status' => self::PENDING,
				),
				array( '%s', '%s', '%s' ),
				array( '%d', '%s' )
			);
		}

		return $updated;
	}

	/**
	 * Returns a page of entries, newest first.
	 *
	 * @param int $per_page Entries per page.
	 * @param int $page     1-based page number.
	 * @return array[] Decoded entries.
	 */
	public function query( $per_page, $page ) {
		global $wpdb;

		$per_page = max( 1, (int) $per_page );
		$offset   = max( 0, ( (int) $page - 1 ) * $per_page );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', self::table_name(), $per_page, $offset ),
			ARRAY_A
		);

		return array_map( array( __CLASS__, 'decode' ), (array) $rows );
	}

	/**
	 * Counts all entries.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table_name() ) );
	}

	/**
	 * Returns the creation time of the oldest pending entry.
	 *
	 * @return int Unix timestamp, 0 when nothing is pending.
	 */
	public function oldest_pending_time() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$created = $wpdb->get_var(
			$wpdb->prepare( 'SELECT MIN(created_at) FROM %i WHERE check_status = %s', self::table_name(), self::PENDING )
		);

		return $created ? (int) strtotime( $created . ' UTC' ) : 0;
	}

	/**
	 * Deletes everything except the newest KEEP entries.
	 */
	public function prune() {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$oldest_kept = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d', $table, self::KEEP - 1 )
		);

		if ( $oldest_kept > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id < %d', $table, $oldest_kept ) );
		}
	}

	/**
	 * Decodes the JSON columns of a row.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private static function decode( array $row ) {
		$items   = json_decode( (string) $row['items'], true );
		$results = null === $row['check_results'] ? null : json_decode( (string) $row['check_results'], true );

		return array(
			'id'            => (int) $row['id'],
			'created_at'    => (string) $row['created_at'],
			'source'        => (string) $row['source'],
			'user_id'       => (int) $row['user_id'],
			'items'         => is_array( $items ) ? $items : array(),
			'check_status'  => (string) $row['check_status'],
			'check_results' => is_array( $results ) ? $results : null,
			'checked_at'    => null === $row['checked_at'] ? '' : (string) $row['checked_at'],
		);
	}
}
