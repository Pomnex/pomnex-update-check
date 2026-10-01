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
 * Listens for finished updates, logs what changed and schedules a check.
 *
 * Checks never run inside the upgrader request. During bulk updates
 * WordPress is in maintenance mode, so a loopback request would only see
 * the maintenance page. The check is scheduled about a minute later.
 */
final class Update_Listener {

	/**
	 * Option holding the installed versions snapshot.
	 */
	const VERSIONS_OPTION = 'pomnex_uc_versions';

	/**
	 * Cron hook of the post-update check.
	 */
	const CHECK_HOOK = 'pomnex_uc_run_check';

	/**
	 * Delay between an update and its check, in seconds.
	 */
	const CHECK_DELAY = 60;

	/**
	 * A new update joins a pending entry by the same person this recent.
	 */
	const MERGE_WINDOW = 600;

	/**
	 * Update log.
	 *
	 * @var Log_Repository
	 */
	private $log;

	/**
	 * Whether WordPress's automatic updater is running in this request.
	 *
	 * @var bool
	 */
	private $in_auto_update = false;

	/**
	 * Constructor.
	 *
	 * @param Log_Repository $log Update log.
	 */
	public function __construct( Log_Repository $log ) {
		$this->log = $log;
	}

	/**
	 * Hooks into WordPress.
	 */
	public function init() {
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_process_complete' ), 20, 2 );
		add_action( 'pre_auto_update', array( $this, 'on_pre_auto_update' ) );
		add_action( 'automatic_updates_complete', array( $this, 'on_automatic_updates_complete' ) );
	}

	/**
	 * Handles a finished manual or automatic upgrade.
	 *
	 * @param \WP_Upgrader|mixed $upgrader   Upgrader instance (unused).
	 * @param array|mixed        $hook_extra Details: `action`, `type`, and the items.
	 */
	public function on_upgrader_process_complete( $upgrader, $hook_extra ) {
		unset( $upgrader );

		$hook_extra = is_array( $hook_extra ) ? $hook_extra : array();
		$type       = isset( $hook_extra['type'] ) ? $hook_extra['type'] : '';
		$action     = isset( $hook_extra['action'] ) ? $hook_extra['action'] : '';

		if ( 'translation' === $type ) {
			return;
		}

		if ( 'install' === $action ) {
			self::refresh_snapshot();
			return;
		}

		if ( 'update' !== $action ) {
			return;
		}

		// During an automatic update run, all items are logged together at the end.
		if ( $this->in_auto_update ) {
			return;
		}

		$this->record( wp_doing_cron() ? 'automatic' : 'manual', $hook_extra );
	}

	/**
	 * Notes that WordPress's automatic updater has started.
	 */
	public function on_pre_auto_update() {
		if ( $this->in_auto_update ) {
			return;
		}

		$this->in_auto_update = true;

		// If the run never reaches automatic_updates_complete, still log what changed.
		register_shutdown_function( array( $this, 'on_shutdown' ) );
	}

	/**
	 * Logs everything the automatic updater changed as one entry.
	 */
	public function on_automatic_updates_complete() {
		$this->in_auto_update = false;
		$this->record( 'automatic' );
	}

	/**
	 * Fallback for an automatic update run that ended early.
	 */
	public function on_shutdown() {
		if ( $this->in_auto_update ) {
			$this->on_automatic_updates_complete();
		}
	}

	/**
	 * Diffs installed versions against the snapshot, logs the changes and
	 * schedules a check.
	 *
	 * @param string $source     `manual` or `automatic`.
	 * @param array  $hook_extra Optional. Upgrader details, used when there is no snapshot yet.
	 * @return int Log entry ID, 0 when nothing changed.
	 */
	public function record( $source, array $hook_extra = array() ) {
		$items = $this->diff_and_refresh( $hook_extra );

		if ( empty( $items ) ) {
			return 0;
		}

		$user_id = 'automatic' === $source ? 0 : get_current_user_id();
		$entry   = $this->log->find_open_entry( $source, $user_id, self::MERGE_WINDOW );

		if ( $entry ) {
			$this->log->append_items( $entry, $items );
		} else {
			$entry = $this->log->insert( $source, $user_id, $items );
		}

		self::schedule_check();

		return $entry;
	}

	/**
	 * Schedules the post-update check unless one is already scheduled, so
	 * a bulk update leads to one check.
	 */
	public static function schedule_check() {
		if ( ! wp_next_scheduled( self::CHECK_HOOK ) ) {
			wp_schedule_single_event( time() + self::CHECK_DELAY, self::CHECK_HOOK );
		}
	}

	/**
	 * Stores the currently installed versions as the snapshot.
	 */
	public static function refresh_snapshot() {
		$current = self::current_versions();

		update_option(
			self::VERSIONS_OPTION,
			array(
				'core'    => $current['core'],
				'plugins' => wp_list_pluck( $current['plugins'], 'version' ),
				'themes'  => wp_list_pluck( $current['themes'], 'version' ),
			),
			false
		);
	}

	/**
	 * Reads the installed versions straight from disk.
	 *
	 * @return array {
	 *     @type string $core    Core version.
	 *     @type array  $plugins Plugin file => array( name, version ).
	 *     @type array  $themes  Theme stylesheet => array( name, version ).
	 * }
	 */
	public static function current_versions() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		wp_clean_plugins_cache( false );
		wp_clean_themes_cache( false );

		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[ $file ] = array(
				'name'    => '' !== $data['Name'] ? $data['Name'] : $file,
				'version' => (string) $data['Version'],
			);
		}

		$themes = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$themes[ $stylesheet ] = array(
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
			);
		}

		return array(
			'core'    => self::core_version(),
			'plugins' => $plugins,
			'themes'  => $themes,
		);
	}

	/**
	 * Compares installed versions with the snapshot, then refreshes it.
	 *
	 * @param array $hook_extra Upgrader details, used only when there is no snapshot.
	 * @return array[] Changed items.
	 */
	private function diff_and_refresh( array $hook_extra ) {
		$snapshot = get_option( self::VERSIONS_OPTION );
		$current  = self::current_versions();
		$items    = array();

		if ( ! is_array( $snapshot ) ) {
			$items = self::items_from_hook_extra( $hook_extra, $current );
			self::refresh_snapshot();
			return $items;
		}

		if ( ! empty( $snapshot['core'] ) && $snapshot['core'] !== $current['core'] ) {
			$items[] = self::item( 'core', 'core', __( 'WordPress', 'pomnex-update-check' ), $snapshot['core'], $current['core'] );
		}

		$groups = array(
			'plugin' => 'plugins',
			'theme'  => 'themes',
		);

		foreach ( $groups as $type => $group ) {
			$old_versions = isset( $snapshot[ $group ] ) && is_array( $snapshot[ $group ] ) ? $snapshot[ $group ] : array();

			foreach ( $current[ $group ] as $slug => $data ) {
				if ( isset( $old_versions[ $slug ] ) && (string) $old_versions[ $slug ] !== $data['version'] ) {
					$items[] = self::item( $type, $slug, $data['name'], $old_versions[ $slug ], $data['version'] );
				}
			}
		}

		self::refresh_snapshot();

		return $items;
	}

	/**
	 * Builds items from the upgrader details when no snapshot exists yet.
	 * The old version is unknown in that case.
	 *
	 * @param array $hook_extra Upgrader details.
	 * @param array $current    Current versions.
	 * @return array[]
	 */
	private static function items_from_hook_extra( array $hook_extra, array $current ) {
		$items = array();
		$type  = isset( $hook_extra['type'] ) ? $hook_extra['type'] : '';

		if ( 'core' === $type ) {
			return array( self::item( 'core', 'core', __( 'WordPress', 'pomnex-update-check' ), '', $current['core'] ) );
		}

		$map = array(
			'plugin' => array( 'plugins', 'plugin', 'plugins' ),
			'theme'  => array( 'themes', 'theme', 'themes' ),
		);

		if ( ! isset( $map[ $type ] ) ) {
			return $items;
		}

		list( $group, $single, $multiple ) = $map[ $type ];

		$slugs = isset( $hook_extra[ $multiple ] ) ? (array) $hook_extra[ $multiple ] : array();
		if ( isset( $hook_extra[ $single ] ) ) {
			$slugs[] = $hook_extra[ $single ];
		}

		foreach ( array_unique( $slugs ) as $slug ) {
			if ( isset( $current[ $group ][ $slug ] ) ) {
				$items[] = self::item( $type, $slug, $current[ $group ][ $slug ]['name'], '', $current[ $group ][ $slug ]['version'] );
			}
		}

		return $items;
	}

	/**
	 * Builds one log item.
	 *
	 * @param string $type        `core`, `plugin` or `theme`.
	 * @param string $slug        Plugin file, theme stylesheet or `core`.
	 * @param string $name        Display name.
	 * @param string $old_version Version before the update.
	 * @param string $new_version Version after the update.
	 * @return array
	 */
	private static function item( $type, $slug, $name, $old_version, $new_version ) {
		return array(
			'type'        => $type,
			'slug'        => (string) $slug,
			'name'        => wp_strip_all_tags( (string) $name ),
			'old_version' => (string) $old_version,
			'new_version' => (string) $new_version,
		);
	}

	/**
	 * Reads the core version from version.php.
	 *
	 * The global $wp_version still holds the old version in the request
	 * that updated core, so the file is read instead, as core does.
	 *
	 * @return string
	 */
	private static function core_version() {
		$wp_version = '';

		include ABSPATH . WPINC . '/version.php';

		return (string) $wp_version;
	}
}
