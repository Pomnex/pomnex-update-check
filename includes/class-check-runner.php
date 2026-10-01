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
 * Checks every important page, works out the overall result, refreshes
 * baselines, records the result in the update log and sends the email.
 */
final class Check_Runner {

	/**
	 * Option holding the most recent run.
	 */
	const LAST_RUN_OPTION = 'pomnex_uc_last_run';

	/**
	 * Option holding the per-page baselines.
	 */
	const BASELINE_OPTION = 'pomnex_uc_baseline';

	/**
	 * Option holding small state between runs (email de-duplication).
	 */
	const STATE_OPTION = 'pomnex_uc_state';

	/**
	 * Transient that stops two runs overlapping.
	 */
	const LOCK_TRANSIENT = 'pomnex_uc_lock';

	/**
	 * Run started by an update (the scheduled check).
	 */
	const TRIGGER_UPDATE = 'update';

	/**
	 * Run started with the Run check now button.
	 */
	const TRIGGER_MANUAL = 'manual';

	/**
	 * Daily baseline refresh run.
	 */
	const TRIGGER_DAILY = 'daily';

	/**
	 * Maximum assets checked per page.
	 */
	const MAX_ASSETS_PER_PAGE = 30;

	/**
	 * Maximum assets checked per run.
	 */
	const MAX_ASSETS_PER_RUN = 60;

	/**
	 * Seconds after which no new request is started.
	 */
	const TIME_BUDGET = 60;

	/**
	 * The English critical-error sentence from wp_die().
	 */
	const CRITICAL_ERROR_EN = 'There has been a critical error on this website.';

	/**
	 * Update log.
	 *
	 * @var Log_Repository
	 */
	private $log;

	/**
	 * HTTP layer.
	 *
	 * @var Page_Checker
	 */
	private $checker;

	/**
	 * Analyzer, created on first use.
	 *
	 * @var Page_Analyzer|null
	 */
	private $analyzer;

	/**
	 * Constructor.
	 *
	 * @param Log_Repository $log     Update log.
	 * @param Page_Checker   $checker HTTP layer.
	 */
	public function __construct( Log_Repository $log, Page_Checker $checker ) {
		$this->log     = $log;
		$this->checker = $checker;
	}

	/**
	 * Runs a full check of every important page.
	 *
	 * @param string $trigger One of the TRIGGER_* constants.
	 * @return array|\WP_Error The run record, or an error when the run could not start.
	 */
	public function run( $trigger ) {
		if ( function_exists( 'wp_is_maintenance_mode' ) && wp_is_maintenance_mode() ) {
			return new \WP_Error( 'pomnex_uc_maintenance', __( 'The site is in maintenance mode. The check will run when it ends.', 'pomnex-update-check' ) );
		}

		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return new \WP_Error( 'pomnex_uc_locked', __( 'A check is already running. Please wait a minute and try again.', 'pomnex-update-check' ) );
		}

		$pages = Settings::get_pages();

		if ( empty( $pages ) ) {
			return new \WP_Error( 'pomnex_uc_no_pages', __( 'No important pages are set up yet. Add them on the Pages tab.', 'pomnex-update-check' ) );
		}

		set_transient( self::LOCK_TRANSIENT, time(), 5 * MINUTE_IN_SECONDS );

		try {
			return $this->do_run( $trigger, $pages );
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
		}
	}

	/**
	 * Captures a baseline for the given pages, whatever their result.
	 *
	 * Used when pages are added or changed and by the "Save current state
	 * as baseline" button. Pages that fail are saved anyway and flagged, so
	 * the Status tab can show them as "Failing now". Assets are listed but
	 * not requested, to keep saving the settings fast.
	 *
	 * @param string[] $page_ids Page IDs.
	 * @return array<string, string> Page ID => result at capture time.
	 */
	public function capture_baselines( array $page_ids ) {
		$baselines = self::get_baselines();
		$results   = array();
		$started   = microtime( true );

		foreach ( Settings::get_pages() as $page ) {
			if ( ! in_array( $page['id'], $page_ids, true ) ) {
				continue;
			}

			if ( microtime( true ) - $started >= self::TIME_BUDGET ) {
				$results[ $page['id'] ] = Page_Analyzer::COULD_NOT_CHECK;
				continue;
			}

			$response = $this->fetch_with_retry( $page, null, $started );
			$findings = $this->analyzer()->analyze( $response, null, $page['marker'], self::shortcode_tags() );
			$result   = Page_Analyzer::page_result( $findings );

			$results[ $page['id'] ] = $result;

			if ( Page_Analyzer::COULD_NOT_CHECK === $result ) {
				continue;
			}

			$baselines[ $page['id'] ] = $this->baseline_from( $page, $response, Page_Analyzer::FAIL === $result );
		}

		self::save_baselines( $baselines );

		return $results;
	}

	/**
	 * Returns the stored baselines.
	 *
	 * @return array<string, array> Page ID => baseline.
	 */
	public static function get_baselines() {
		$baselines = get_option( self::BASELINE_OPTION, array() );

		return is_array( $baselines ) ? $baselines : array();
	}

	/**
	 * Forgets the baselines of pages that no longer exist.
	 *
	 * @param string[] $page_ids IDs of the current pages.
	 */
	public static function prune_baselines( array $page_ids ) {
		$baselines = self::get_baselines();
		$kept      = array_intersect_key( $baselines, array_flip( $page_ids ) );

		if ( count( $kept ) !== count( $baselines ) ) {
			self::save_baselines( $kept );
		}
	}

	/**
	 * Returns the most recent run.
	 *
	 * @return array|null
	 */
	public static function get_last_run() {
		$run = get_option( self::LAST_RUN_OPTION );

		return is_array( $run ) ? $run : null;
	}

	/**
	 * Returns the translated label of a result.
	 *
	 * @param string $status Result.
	 * @return string
	 */
	public static function result_label( $status ) {
		switch ( $status ) {
			case Page_Analyzer::PASS:
				return __( 'Pass', 'pomnex-update-check' );
			case Page_Analyzer::WARNING:
				return __( 'Warning', 'pomnex-update-check' );
			case Page_Analyzer::FAIL:
				return __( 'Fail', 'pomnex-update-check' );
			case Page_Analyzer::COULD_NOT_CHECK:
				return __( 'Could not check', 'pomnex-update-check' );
			case Log_Repository::PENDING:
				return __( 'Waiting for check', 'pomnex-update-check' );
			case 'failing_now':
				return __( 'Failing now', 'pomnex-update-check' );
			default:
				return __( 'Not checked yet', 'pomnex-update-check' );
		}
	}

	/**
	 * Returns the translated label of a run trigger.
	 *
	 * @param string $trigger Trigger.
	 * @return string
	 */
	public static function trigger_label( $trigger ) {
		switch ( $trigger ) {
			case self::TRIGGER_UPDATE:
				return __( 'after an update', 'pomnex-update-check' );
			case self::TRIGGER_DAILY:
				return __( 'daily baseline refresh', 'pomnex-update-check' );
			default:
				return __( 'started manually', 'pomnex-update-check' );
		}
	}

	/**
	 * Describes a finding in plain language.
	 *
	 * @param array $finding Finding from Page_Analyzer.
	 * @return string Plain text, not escaped.
	 */
	public static function describe_finding( array $finding ) {
		$data = isset( $finding['data'] ) && is_array( $finding['data'] ) ? $finding['data'] : array();

		switch ( isset( $finding['code'] ) ? $finding['code'] : '' ) {
			case 'request_error':
				/* translators: %s: error message from the HTTP request. */
				return sprintf( __( 'The page could not be loaded: %s', 'pomnex-update-check' ), $data['message'] );

			case 'not_checked':
				return __( 'Not checked: the check ran out of time before it reached this page.', 'pomnex-update-check' );

			case 'http_status':
				if ( $data['status'] < 400 && $data['baseline_status'] >= 200 && $data['baseline_status'] < 300 ) {
					/* translators: 1: HTTP status now. 2: HTTP status when the page last worked. */
					return sprintf( __( 'The page returned HTTP status %1$d instead of %2$d.', 'pomnex-update-check' ), $data['status'], $data['baseline_status'] );
				}
				/* translators: %d: HTTP status code, such as 500. */
				return sprintf( __( 'The page returned an error: HTTP status %d.', 'pomnex-update-check' ), $data['status'] );

			case 'redirect_offsite':
				/* translators: %s: host name. */
				return sprintf( __( 'The page redirects to another site (%s). Only the redirect itself was checked.', 'pomnex-update-check' ), $data['host'] );

			case 'critical_error':
				return __( 'The page shows the WordPress "There has been a critical error on this website" message.', 'pomnex-update-check' );

			case 'php_error':
				/* translators: %s: PHP error message shown on the page. */
				return sprintf( __( 'A PHP error message is visible on the page: %s', 'pomnex-update-check' ), $data['excerpt'] );

			case 'marker_missing':
				/* translators: %s: the marker text. */
				return sprintf( __( 'The marker "%s" is missing from the page.', 'pomnex-update-check' ), $data['marker'] );

			case 'marker_never_found':
				/* translators: %s: the marker text. */
				return sprintf( __( 'The marker "%s" was not found, and it was not found when the baseline was captured either. Check that the marker is typed exactly as it appears in the page source.', 'pomnex-update-check' ), $data['marker'] );

			case 'shortcode_unrendered':
				$tags = array_map(
					static function ( $tag ) {
						return '[' . $tag . ']';
					},
					(array) $data['tags']
				);
				/* translators: %s: comma-separated shortcodes, such as [contact-form-7]. */
				return sprintf( __( 'Shortcodes are showing as plain text instead of being rendered: %s. The plugin that provides them may be inactive or broken.', 'pomnex-update-check' ), implode( ', ', $tags ) );

			case 'asset_broken':
				/* translators: 1: HTTP status code. 2: file URL. */
				return sprintf( __( 'A stylesheet or script failed to load (HTTP %1$d): %2$s', 'pomnex-update-check' ), $data['status'], $data['url'] );

			case 'asset_removed':
				/* translators: %s: comma-separated file URLs. */
				return sprintf( __( 'These stylesheets or scripts were on the page when it last worked and are gone now. This can be harmless when a plugin renames its files: %s', 'pomnex-update-check' ), implode( ', ', (array) $data['urls'] ) );

			case 'assets_not_checked':
				return sprintf(
					/* translators: %d: number of files. */
					_n(
						'%d stylesheet or script was not checked (time or request limit reached, or no response).',
						'%d stylesheets or scripts were not checked (time or request limit reached, or no response).',
						(int) $data['count'],
						'pomnex-update-check'
					),
					(int) $data['count']
				);

			case 'slow':
				/* translators: 1: seconds now. 2: seconds when the page last worked. */
				return sprintf( __( 'The page took %1$s s to load, compared with %2$s s when it last worked.', 'pomnex-update-check' ), number_format_i18n( $data['time'], 2 ), number_format_i18n( $data['baseline_time'], 2 ) );

			case 'size_drop':
				/* translators: 1: size now, such as 12 KB. 2: size when the page last worked. */
				return sprintf( __( 'The page is much smaller than when it last worked (%1$s, was %2$s). A section may be missing.', 'pomnex-update-check' ), size_format( $data['size'], 1 ), size_format( $data['baseline_size'], 1 ) );

			default:
				return __( 'Unknown problem.', 'pomnex-update-check' );
		}
	}

	/**
	 * Performs the run once the lock is held.
	 *
	 * @param string  $trigger Trigger.
	 * @param array[] $pages   Important pages.
	 * @return array Run record.
	 */
	private function do_run( $trigger, array $pages ) {
		$started      = microtime( true );
		$baselines    = self::get_baselines();
		$asset_budget = self::MAX_ASSETS_PER_RUN;
		$asset_cache  = array();
		$page_runs    = array();
		$responses    = array();

		foreach ( $pages as $page ) {
			$baseline = $this->baseline_for( $page, $baselines );

			if ( microtime( true ) - $started >= self::TIME_BUDGET ) {
				$page_runs[] = self::page_record(
					$page,
					Page_Analyzer::COULD_NOT_CHECK,
					array(
						array(
							'code'     => 'not_checked',
							'severity' => Page_Analyzer::COULD_NOT_CHECK,
							'data'     => array(),
						),
					)
				);
				continue;
			}

			$response = $this->fetch_with_retry( $page, $baseline, $started );
			$assets   = array();
			$checked  = 0;

			if ( empty( $response['error'] ) ) {
				foreach ( $this->analyzer()->extract_assets( $response['body'], $response['url'] ) as $normalized => $absolute ) {
					// Pages share most assets; a file already requested in this run is not requested again.
					if ( isset( $asset_cache[ $absolute ] ) ) {
						$assets[ $normalized ] = $asset_cache[ $absolute ];
						continue;
					}

					$out_of_budget = $checked >= self::MAX_ASSETS_PER_PAGE || $asset_budget <= 0 || microtime( true ) - $started >= self::TIME_BUDGET;

					if ( $out_of_budget ) {
						$assets[ $normalized ] = array( 'skipped' => true );
						continue;
					}

					$assets[ $normalized ]    = $this->checker->check_asset( $absolute );
					$asset_cache[ $absolute ] = $assets[ $normalized ];
					++$checked;
					--$asset_budget;
				}
			}

			$findings = $this->analyzer()->analyze( $response, $baseline, $page['marker'], self::shortcode_tags(), empty( $response['error'] ) ? $assets : null );
			$result   = Page_Analyzer::page_result( $findings );

			$page_runs[]              = self::page_record( $page, $result, $findings, $response, count( $assets ), count( array_filter( $assets, array( __CLASS__, 'was_checked' ) ) ) );
			$responses[ $page['id'] ] = array(
				'response' => $response,
				'assets'   => array_keys( $assets ),
				'result'   => $result,
			);
		}

		$overall = Page_Analyzer::overall_result( wp_list_pluck( $page_runs, 'result' ) );
		$run     = array(
			'run_at'   => gmdate( 'Y-m-d H:i:s' ),
			'trigger'  => $trigger,
			'overall'  => $overall,
			'duration' => round( microtime( true ) - $started, 2 ),
			'pages'    => $page_runs,
		);

		$this->refresh_baselines( $pages, $baselines, $responses );

		$entries = array();
		if ( self::TRIGGER_DAILY !== $trigger ) {
			$entries = $this->log->get_pending();

			if ( $entries ) {
				$this->log->complete( wp_list_pluck( $entries, 'id' ), $overall, $run );
			}

			// This run answers every waiting entry, so a scheduled check is no longer needed.
			wp_clear_scheduled_hook( Update_Listener::CHECK_HOOK );
		}

		$this->maybe_email( $run, $entries );

		update_option( self::LAST_RUN_OPTION, $run, false );

		return $run;
	}

	/**
	 * Loads a page, and loads it again once right away if it fails, to rule
	 * out a one-off glitch. The second response is the one reported.
	 *
	 * @param array      $page     Page settings.
	 * @param array|null $baseline Baseline or null.
	 * @param float      $started  Run start time.
	 * @return array Response.
	 */
	private function fetch_with_retry( array $page, $baseline, $started ) {
		$response = $this->checker->fetch_page( $page['url'] );
		$result   = Page_Analyzer::page_result( $this->analyzer()->analyze( $response, $baseline, $page['marker'], self::shortcode_tags() ) );

		if ( in_array( $result, array( Page_Analyzer::FAIL, Page_Analyzer::COULD_NOT_CHECK ), true ) && microtime( true ) - $started < self::TIME_BUDGET ) {
			$response = $this->checker->fetch_page( $page['url'] );
		}

		return $response;
	}

	/**
	 * Refreshes baselines after a run.
	 *
	 * A page that passed gets a fresh baseline. A page that has no baseline
	 * yet gets its first one even when it fails (flagged as failing). A page
	 * that could not be checked is left alone.
	 *
	 * @param array[] $pages     Important pages.
	 * @param array   $baselines Current baselines.
	 * @param array   $responses Page ID => response, assets and result.
	 */
	private function refresh_baselines( array $pages, array $baselines, array $responses ) {
		$changed = false;

		foreach ( $pages as $page ) {
			if ( ! isset( $responses[ $page['id'] ] ) ) {
				continue;
			}

			$data     = $responses[ $page['id'] ];
			$existing = $this->baseline_for( $page, $baselines );

			if ( Page_Analyzer::PASS === $data['result'] || ( null === $existing && Page_Analyzer::COULD_NOT_CHECK !== $data['result'] ) ) {
				$baselines[ $page['id'] ] = $this->baseline_from( $page, $data['response'], Page_Analyzer::FAIL === $data['result'], $data['assets'] );
				$changed                  = true;
			}
		}

		if ( $changed ) {
			self::save_baselines( $baselines );
		}
	}

	/**
	 * Returns the baseline of a page, or null when it is missing or belongs to an older URL.
	 *
	 * @param array $page      Page settings.
	 * @param array $baselines All baselines.
	 * @return array|null
	 */
	private function baseline_for( array $page, array $baselines ) {
		if ( empty( $baselines[ $page['id'] ] ) || ! is_array( $baselines[ $page['id'] ] ) ) {
			return null;
		}

		$baseline = $baselines[ $page['id'] ];

		return isset( $baseline['url'] ) && $baseline['url'] === $page['url'] ? $baseline : null;
	}

	/**
	 * Builds a baseline record from a response.
	 *
	 * @param array         $page     Page settings.
	 * @param array         $response Response.
	 * @param bool          $failing  Whether the page failed.
	 * @param string[]|null $assets   Normalised asset URLs, or null to extract them from the body.
	 * @return array
	 */
	private function baseline_from( array $page, array $response, $failing, $assets = null ) {
		if ( null === $assets ) {
			$assets = array_keys( $this->analyzer()->extract_assets( $response['body'], $response['url'] ) );
		}

		return $this->analyzer()->build_baseline( $page['url'], $page['marker'], $response, $assets, self::shortcode_tags(), gmdate( 'Y-m-d H:i:s' ), $failing );
	}

	/**
	 * Sends the result email when the rules call for one.
	 *
	 * Fail always emails. Warning emails when the setting is on. Could not
	 * check emails only the first time in a row. The daily baseline refresh
	 * never emails.
	 *
	 * @param array   $run     Run record.
	 * @param array[] $entries Log entries this run answered.
	 */
	private function maybe_email( array $run, array $entries ) {
		$state   = get_option( self::STATE_OPTION, array() );
		$state   = is_array( $state ) ? $state : array();
		$overall = $run['overall'];
		$send    = false;

		if ( Page_Analyzer::COULD_NOT_CHECK !== $overall ) {
			$state['cnc_notified'] = false;
		}

		if ( self::TRIGGER_DAILY !== $run['trigger'] ) {
			if ( Page_Analyzer::FAIL === $overall ) {
				$send = true;
			} elseif ( Page_Analyzer::WARNING === $overall ) {
				$send = Settings::email_warnings();
			} elseif ( Page_Analyzer::COULD_NOT_CHECK === $overall && empty( $state['cnc_notified'] ) ) {
				$send                  = true;
				$state['cnc_notified'] = true;
			}
		}

		update_option( self::STATE_OPTION, $state, false );

		if ( ! $send ) {
			return;
		}

		$recipients = Settings::get_emails();

		if ( empty( $recipients ) ) {
			return;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name. */
		$subject = sprintf( __( '[%s] Update Check: problem found after an update', 'pomnex-update-check' ), $site_name );

		$force_plain = static function () {
			return 'text/plain';
		};

		add_filter( 'wp_mail_content_type', $force_plain, 999 );
		wp_mail( $recipients, $subject, $this->email_body( $run, $entries ) );
		remove_filter( 'wp_mail_content_type', $force_plain, 999 );
	}

	/**
	 * Builds the plain-text email body.
	 *
	 * @param array   $run     Run record.
	 * @param array[] $entries Log entries this run answered.
	 * @return string
	 */
	private function email_body( array $run, array $entries ) {
		$lines = array();

		switch ( $run['overall'] ) {
			case Page_Analyzer::FAIL:
				/* translators: %s: site URL. */
				$lines[] = sprintf( __( 'Update Check found a problem on %s.', 'pomnex-update-check' ), home_url( '/' ) );
				break;
			case Page_Analyzer::WARNING:
				/* translators: %s: site URL. */
				$lines[] = sprintf( __( 'Update Check found something that may be a problem on %s.', 'pomnex-update-check' ), home_url( '/' ) );
				break;
			default:
				/* translators: %s: site URL. */
				$lines[] = sprintf( __( 'Update Check could not check the pages on %s.', 'pomnex-update-check' ), home_url( '/' ) );
		}

		$lines[] = '';
		$lines[] = __( 'Updates before this check:', 'pomnex-update-check' );

		if ( empty( $entries ) ) {
			$lines[] = '- ' . __( 'No updates were recorded before this check. It was started manually.', 'pomnex-update-check' );
		}

		foreach ( $entries as $entry ) {
			foreach ( $entry['items'] as $item ) {
				$lines[] = '- ' . sprintf(
					/* translators: 1: item type, such as Plugin. 2: name. 3: old version. 4: new version. 5: who ran the update. 6: date and time. */
					__( '%1$s %2$s: %3$s → %4$s (%5$s, %6$s)', 'pomnex-update-check' ),
					self::item_type_label( $item['type'] ),
					$item['name'],
					'' === (string) $item['old_version'] ? '?' : $item['old_version'],
					$item['new_version'],
					self::entry_author( $entry ),
					self::format_date( $entry['created_at'] )
				);
			}
		}

		$lines[] = '';
		$lines[] = __( 'Pages:', 'pomnex-update-check' );

		foreach ( $run['pages'] as $page ) {
			$lines[] = '';
			$lines[] = sprintf( '%1$s (%2$s)', $page['label'], $page['url'] );
			/* translators: %s: result, such as Fail. */
			$lines[] = '  ' . sprintf( __( 'Result: %s', 'pomnex-update-check' ), self::result_label( $page['result'] ) );

			foreach ( $page['findings'] as $finding ) {
				$lines[] = '  - ' . self::describe_finding( $finding );
			}
		}

		if ( in_array( Page_Analyzer::COULD_NOT_CHECK, wp_list_pluck( $run['pages'], 'result' ), true ) ) {
			$lines[] = '';
			$lines[] = __( 'Some hosts block a site\'s requests to itself (loopback requests), so the pages could not be loaded. Tools → Site Health runs its own loopback test:', 'pomnex-update-check' );
			$lines[] = admin_url( 'site-health.php' );
		}

		$lines[] = '';
		$lines[] = __( 'Full results are in the update log:', 'pomnex-update-check' );
		$lines[] = admin_url( 'tools.php?page=' . Admin::SLUG . '&tab=log' );

		return implode( "\n", $lines );
	}

	/**
	 * Returns the label for an item type.
	 *
	 * @param string $type `core`, `plugin` or `theme`.
	 * @return string
	 */
	public static function item_type_label( $type ) {
		switch ( $type ) {
			case 'core':
				return __( 'WordPress', 'pomnex-update-check' );
			case 'theme':
				return __( 'Theme', 'pomnex-update-check' );
			default:
				return __( 'Plugin', 'pomnex-update-check' );
		}
	}

	/**
	 * Returns who ran an update: a display name or "Automatic".
	 *
	 * @param array $entry Log entry.
	 * @return string
	 */
	public static function entry_author( array $entry ) {
		if ( 'automatic' === $entry['source'] ) {
			return __( 'Automatic', 'pomnex-update-check' );
		}

		$user = $entry['user_id'] ? get_userdata( $entry['user_id'] ) : false;

		return $user ? $user->display_name : __( 'Unknown user', 'pomnex-update-check' );
	}

	/**
	 * Formats a UTC database date in the site's timezone.
	 *
	 * @param string $utc Date, `Y-m-d H:i:s`, UTC.
	 * @return string
	 */
	public static function format_date( $utc ) {
		$timestamp = strtotime( $utc . ' UTC' );

		if ( ! $timestamp ) {
			return '';
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Builds the stored record of one page's result.
	 *
	 * @param array      $page           Page settings.
	 * @param string     $result         Page result.
	 * @param array[]    $findings       Findings.
	 * @param array|null $response       Optional. Response.
	 * @param int        $assets_found   Optional. Same-host assets on the page.
	 * @param int        $assets_checked Optional. Assets requested.
	 * @return array
	 */
	private static function page_record( array $page, $result, array $findings, $response = null, $assets_found = 0, $assets_checked = 0 ) {
		return array(
			'id'             => $page['id'],
			'label'          => $page['label'],
			'url'            => $page['url'],
			'result'         => $result,
			'status'         => $response ? (int) $response['status'] : 0,
			'time'           => $response ? round( (float) $response['time'], 3 ) : 0,
			'size'           => $response ? (int) $response['size'] : 0,
			'assets_found'   => (int) $assets_found,
			'assets_checked' => (int) $assets_checked,
			'findings'       => $findings,
		);
	}

	/**
	 * Whether an asset result came from a request (not skipped by the budget).
	 *
	 * @param array $result Asset result.
	 * @return bool
	 */
	private static function was_checked( array $result ) {
		return empty( $result['skipped'] );
	}

	/**
	 * Saves the baselines (not autoloaded).
	 *
	 * @param array $baselines Page ID => baseline.
	 */
	private static function save_baselines( array $baselines ) {
		update_option( self::BASELINE_OPTION, $baselines, false );
	}

	/**
	 * Returns the shortcode tags registered now.
	 *
	 * @return string[]
	 */
	private static function shortcode_tags() {
		global $shortcode_tags;

		return is_array( $shortcode_tags ) ? array_map( 'strval', array_keys( $shortcode_tags ) ) : array();
	}

	/**
	 * Returns the analyzer, configured for this site.
	 *
	 * @return Page_Analyzer
	 */
	private function analyzer() {
		if ( null === $this->analyzer ) {
			$this->analyzer = new Page_Analyzer( home_url(), self::critical_error_strings(), Settings::thresholds() );
		}

		return $this->analyzer;
	}

	/**
	 * Returns the critical-error sentence in English and in the site's language.
	 *
	 * The visitor sees the page in the site language, which can differ from
	 * the admin user's language, so the site locale is used. The translation
	 * comes from WordPress core's own text domain.
	 *
	 * @return string[]
	 */
	private static function critical_error_strings() {
		$switched   = switch_to_locale( get_locale() );
		$translated = get_translations_for_domain( 'default' )->translate( self::CRITICAL_ERROR_EN );

		if ( $switched ) {
			restore_previous_locale();
		}

		return array_values( array_unique( array( self::CRITICAL_ERROR_EN, (string) $translated ) ) );
	}
}
