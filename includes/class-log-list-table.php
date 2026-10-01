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

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists update log entries on the Update log tab.
 */
final class Log_List_Table extends \WP_List_Table {

	/**
	 * Entries per page.
	 */
	const PER_PAGE = 20;

	/**
	 * Update log.
	 *
	 * @var Log_Repository
	 */
	private $log;

	/**
	 * Constructor.
	 *
	 * @param Log_Repository $log Update log.
	 */
	public function __construct( Log_Repository $log ) {
		$this->log = $log;

		parent::__construct(
			array(
				'singular' => 'pomnex-uc-entry',
				'plural'   => 'pomnex-uc-entries',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Returns the columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'date'   => __( 'Date', 'pomnex-update-check' ),
			'items'  => __( 'What was updated', 'pomnex-update-check' ),
			'user'   => __( 'By', 'pomnex-update-check' ),
			'result' => __( 'Check result', 'pomnex-update-check' ),
		);
	}

	/**
	 * Loads one page of entries.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'date' );
		$this->items           = $this->log->query( self::PER_PAGE, $this->get_pagenum() );

		$this->set_pagination_args(
			array(
				'total_items' => $this->log->count(),
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Message when the log is empty.
	 */
	public function no_items() {
		esc_html_e( 'No updates recorded yet. Entries appear here after the next core, plugin or theme update.', 'pomnex-update-check' );
	}

	/**
	 * Renders a row. The whole row links to the entry's details.
	 *
	 * @param array $item Log entry.
	 */
	public function single_row( $item ) {
		printf( '<tr class="pomnex-uc-clickable" data-pomnex-uc-href="%s">', esc_url( self::detail_url( $item['id'] ) ) );
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Date column, in the site's timezone, linking to the details.
	 *
	 * @param array $item Log entry.
	 * @return string
	 */
	public function column_date( $item ) {
		return sprintf(
			'<a href="%1$s"><strong>%2$s</strong></a>',
			esc_url( self::detail_url( $item['id'] ) ),
			esc_html( Check_Runner::format_date( $item['created_at'] ) )
		);
	}

	/**
	 * What was updated: old → new for each item.
	 *
	 * @param array $item Log entry.
	 * @return string
	 */
	public function column_items( $item ) {
		return self::items_html( $item['items'] );
	}

	/**
	 * Who ran the update.
	 *
	 * @param array $item Log entry.
	 * @return string
	 */
	public function column_user( $item ) {
		return esc_html( Check_Runner::entry_author( $item ) );
	}

	/**
	 * Check result label.
	 *
	 * @param array $item Log entry.
	 * @return string
	 */
	public function column_result( $item ) {
		return Admin::status_badge( $item['check_status'] );
	}

	/**
	 * Fallback for unknown columns.
	 *
	 * @param array  $item        Log entry.
	 * @param string $column_name Column.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) && is_scalar( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}

	/**
	 * Returns the escaped list of updated items.
	 *
	 * @param array[] $items Items.
	 * @return string HTML.
	 */
	public static function items_html( array $items ) {
		if ( empty( $items ) ) {
			return '';
		}

		$html = '<ul class="pomnex-uc-items">';

		foreach ( $items as $item ) {
			$name = 'plugin' === $item['type'] ? $item['name'] : Check_Runner::item_type_label( $item['type'] ) . ( 'core' === $item['type'] ? '' : ': ' . $item['name'] );

			$html .= sprintf(
				'<li>%1$s <span class="pomnex-uc-version" dir="ltr">%2$s → %3$s</span></li>',
				esc_html( $name ),
				esc_html( '' === (string) $item['old_version'] ? '?' : $item['old_version'] ),
				esc_html( $item['new_version'] )
			);
		}

		return $html . '</ul>';
	}

	/**
	 * Returns the URL of an entry's details.
	 *
	 * @param int $id Entry ID.
	 * @return string
	 */
	public static function detail_url( $id ) {
		return Admin::url( 'log', array( 'entry' => (int) $id ) );
	}
}
