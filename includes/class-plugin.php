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
 * Wires the plugin's parts into WordPress.
 */
final class Plugin {

	/**
	 * Cron hook of the daily baseline refresh.
	 */
	const DAILY_HOOK = 'pomnex_uc_daily_baseline';

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Update log.
	 *
	 * @var Log_Repository
	 */
	private $log;

	/**
	 * Check runner.
	 *
	 * @var Check_Runner
	 */
	private $runner;

	/**
	 * Returns the single instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->log    = new Log_Repository();
		$this->runner = new Check_Runner( $this->log, new Page_Checker() );
	}

	/**
	 * Registers hooks. Nothing is loaded on public pages beyond these hooks.
	 */
	public function init() {
		add_action( 'plugins_loaded', array( Log_Repository::class, 'maybe_upgrade' ) );

		( new Update_Listener( $this->log ) )->init();

		add_action( Update_Listener::CHECK_HOOK, array( $this, 'run_scheduled_check' ) );
		add_action( self::DAILY_HOOK, array( $this, 'run_daily' ) );

		if ( is_admin() ) {
			$settings = new Settings( $this->runner );
			$settings->init();
			( new Admin( $this->log, $this->runner, $settings ) )->init();
		}
	}

	/**
	 * Runs the post-update check from WP-Cron.
	 *
	 * When the site is in maintenance mode or another check is running, the
	 * check is tried again a minute later.
	 */
	public function run_scheduled_check() {
		$result = $this->runner->run( Check_Runner::TRIGGER_UPDATE );

		if ( is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'pomnex_uc_maintenance', 'pomnex_uc_locked' ), true ) ) {
			Update_Listener::schedule_check();
		}
	}

	/**
	 * Daily: refreshes the version snapshot and the baselines of pages that pass.
	 */
	public function run_daily() {
		Update_Listener::refresh_snapshot();
		$this->runner->run( Check_Runner::TRIGGER_DAILY );
	}

	/**
	 * Schedules the daily event when it is missing, for example on a
	 * multisite subsite where the plugin was network-activated.
	 */
	public static function schedule_daily() {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			// The first run, a minute from now, captures the first baselines.
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * Activation: creates the table, stores defaults, takes the first
	 * version snapshot and schedules the daily event.
	 */
	public static function activate() {
		Log_Repository::install();
		Settings::add_defaults();
		Update_Listener::refresh_snapshot();
		self::schedule_daily();
	}

	/**
	 * Deactivation: clears all scheduled events.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( Update_Listener::CHECK_HOOK );
		wp_clear_scheduled_hook( self::DAILY_HOOK );
	}
}
