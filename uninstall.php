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

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * Removes everything the plugin stored: options, the log table, scheduled
 * events, the lock transient and the per-user notice dismissal.
 */

foreach ( array( 'pomnex_uc_settings', 'pomnex_uc_baseline', 'pomnex_uc_versions', 'pomnex_uc_db_version', 'pomnex_uc_last_run', 'pomnex_uc_state' ) as $pomnex_uc_option ) {
	delete_option( $pomnex_uc_option );
}

delete_transient( 'pomnex_uc_lock' );
delete_metadata( 'user', 0, 'pomnex_uc_dismissed_notice', '', true );

wp_clear_scheduled_hook( 'pomnex_uc_run_check' );
wp_clear_scheduled_hook( 'pomnex_uc_daily_baseline' );

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own table on uninstall.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'pomnex_uc_log' ) );
