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
 * The Tools → Update Check screen: menu, tabs, actions, notices and About.
 */
final class Admin {

	/**
	 * Admin page slug.
	 */
	const SLUG = 'pomnex-update-check';

	/**
	 * User meta that remembers a dismissed overdue notice.
	 */
	const DISMISS_META = 'pomnex_uc_dismissed_notice';

	/**
	 * Seconds after which a scheduled check counts as overdue.
	 */
	const OVERDUE_AFTER = 600;

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
	 * Hook suffix of the plugin screen.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Settings, used to render the Pages tab.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Log_Repository $log      Update log.
	 * @param Check_Runner   $runner   Check runner.
	 * @param Settings       $settings Settings.
	 */
	public function __construct( Log_Repository $log, Check_Runner $runner, Settings $settings ) {
		$this->log      = $log;
		$this->runner   = $runner;
		$this->settings = $settings;
	}

	/**
	 * Hooks into WordPress.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_overdue_notice' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
		add_action( 'admin_init', array( Plugin::class, 'schedule_daily' ) );
		add_action( 'admin_post_pomnex_uc_run_check', array( $this, 'handle_run_check' ) );
		add_action( 'admin_post_pomnex_uc_capture_baseline', array( $this, 'handle_capture_baseline' ) );
		add_action( 'admin_post_pomnex_uc_dismiss_notice', array( $this, 'handle_dismiss_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( POMNEX_UC_FILE ), array( $this, 'add_action_links' ) );
	}

	/**
	 * Adds Tools → Update Check.
	 */
	public function add_menu() {
		$this->hook_suffix = (string) add_management_page(
			__( 'Update Check', 'pomnex-update-check' ),
			__( 'Update Check', 'pomnex-update-check' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Loads the admin CSS and JS on the plugin screen only.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'pomnex-uc-admin', POMNEX_UC_URL . 'assets/admin.css', array(), POMNEX_UC_VERSION );
		wp_style_add_data( 'pomnex-uc-admin', 'rtl', 'replace' );
		wp_enqueue_script( 'pomnex-uc-admin', POMNEX_UC_URL . 'assets/admin.js', array(), POMNEX_UC_VERSION, true );
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Action links.
	 * @return string[]
	 */
	public function add_action_links( $links ) {
		$link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::url( 'pages' ) ),
			esc_html__( 'Settings', 'pomnex-update-check' )
		);

		array_unshift( $links, $link );

		return $links;
	}

	/**
	 * Suggests text for the site's privacy policy.
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . esc_html__( 'Pomnex Update Check does not send any data off this site. To check pages after updates, it loads them from this site\'s own server, as a logged-out visitor and without cookies.', 'pomnex-update-check' ) . '</p>'
			. '<p>' . esc_html__( 'It stores in this site\'s database: the user ID of the person who ran each update, with the update details, in its update log (the newest 100 entries are kept), and the email addresses that receive its notifications.', 'pomnex-update-check' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Pomnex Update Check', 'pomnex-update-check' ), wp_kses_post( $content ) );
	}

	/**
	 * Handles Run check now: runs the check in this request, then shows the result.
	 */
	public function handle_run_check() {
		$this->authorize( 'pomnex_uc_run_check' );

		$result = $this->runner->run( Check_Runner::TRIGGER_MANUAL );
		$notice = is_wp_error( $result ) ? str_replace( 'pomnex_uc_', '', $result->get_error_code() ) : 'ran';

		wp_safe_redirect( add_query_arg( 'pomnex_uc_notice', $notice, self::url( 'status' ) ) );
		exit;
	}

	/**
	 * Handles Save current state as baseline.
	 */
	public function handle_capture_baseline() {
		$this->authorize( 'pomnex_uc_capture_baseline' );

		$ids    = wp_list_pluck( Settings::get_pages(), 'id' );
		$notice = 'no_pages';

		if ( $ids ) {
			$results = $this->runner->capture_baselines( $ids );
			$notice  = in_array( Page_Analyzer::COULD_NOT_CHECK, $results, true ) ? 'captured_partly' : 'captured';
		}

		wp_safe_redirect( add_query_arg( 'pomnex_uc_notice', $notice, self::url( 'status' ) ) );
		exit;
	}

	/**
	 * Remembers that the current user dismissed the overdue notice.
	 */
	public function handle_dismiss_notice() {
		$this->authorize( 'pomnex_uc_dismiss_notice' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified in authorize().
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		update_user_meta( get_current_user_id(), self::DISMISS_META, $key );

		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url() );
		exit;
	}

	/**
	 * Shows a dismissible notice when a post-update check is more than ten
	 * minutes overdue, which usually means WP-Cron is slow or disabled.
	 *
	 * Only shown to administrators, on the Dashboard, Plugins, Updates and
	 * plugin screens.
	 */
	public function render_overdue_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins', 'update-core', $this->hook_suffix ), true ) ) {
			return;
		}

		$key = $this->overdue_key();

		if ( '' === $key || get_user_meta( get_current_user_id(), self::DISMISS_META, true ) === $key ) {
			return;
		}

		$run_url     = wp_nonce_url( admin_url( 'admin-post.php?action=pomnex_uc_run_check' ), 'pomnex_uc_run_check' );
		$dismiss_url = wp_nonce_url( add_query_arg( 'key', rawurlencode( $key ), admin_url( 'admin-post.php?action=pomnex_uc_dismiss_notice' ) ), 'pomnex_uc_dismiss_notice' );
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong><?php esc_html_e( 'Update Check:', 'pomnex-update-check' ); ?></strong>
				<?php esc_html_e( 'the check after your last update has not run yet, and it is more than 10 minutes late. WP-Cron may be slow or disabled on this site.', 'pomnex-update-check' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $run_url ); ?>" class="button button-primary"><?php esc_html_e( 'Run check now', 'pomnex-update-check' ); ?></a>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button"><?php esc_html_e( 'Dismiss', 'pomnex-update-check' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders the plugin screen.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'pomnex-update-check' ), 403 );
		}

		$tabs = array(
			'status' => __( 'Status', 'pomnex-update-check' ),
			'pages'  => __( 'Pages', 'pomnex-update-check' ),
			'log'    => __( 'Update log', 'pomnex-update-check' ),
			'about'  => __( 'About', 'pomnex-update-check' ),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'status';
		$current = isset( $tabs[ $current ] ) ? $current : 'status';
		?>
		<div class="wrap pomnex-uc">
			<h1><?php esc_html_e( 'Update Check', 'pomnex-update-check' ); ?></h1>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Update Check sections', 'pomnex-update-check' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $slug ) ); ?>" class="nav-tab<?php echo $slug === $current ? ' nav-tab-active' : ''; ?>"<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="pomnex-uc-tab">
				<?php
				switch ( $current ) {
					case 'pages':
						$this->settings->render_form();
						break;
					case 'log':
						$this->render_log_tab();
						break;
					case 'about':
						$this->render_about_tab();
						break;
					default:
						$this->render_status_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Returns a status label: coloured, and always with text.
	 *
	 * @param string $status Result.
	 * @return string HTML.
	 */
	public static function status_badge( $status ) {
		return sprintf(
			'<span class="pomnex-uc-status pomnex-uc-status--%1$s">%2$s</span>',
			esc_attr( sanitize_html_class( $status ) ),
			esc_html( Check_Runner::result_label( $status ) )
		);
	}

	/**
	 * Returns the URL of a tab.
	 *
	 * @param string $tab  Tab slug.
	 * @param array  $args Optional. Extra query arguments.
	 * @return string
	 */
	public static function url( $tab, array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'tools.php' )
		);
	}

	/**
	 * Renders the Status tab.
	 */
	private function render_status_tab() {
		$this->render_action_notice();

		$run       = Check_Runner::get_last_run();
		$pages     = Settings::get_pages();
		$baselines = Check_Runner::get_baselines();
		$next      = wp_next_scheduled( Update_Listener::CHECK_HOOK );
		$daily     = wp_next_scheduled( Plugin::DAILY_HOOK );
		?>
		<h2><?php esc_html_e( 'Last check', 'pomnex-update-check' ); ?></h2>
		<?php if ( $run ) : ?>
			<p class="pomnex-uc-summary">
				<?php
				echo wp_kses_post( self::status_badge( $run['overall'] ) ) . ' ';
				echo esc_html(
					sprintf(
						/* translators: 1: date and time. 2: how the check started, such as "after an update". */
						__( '%1$s (%2$s)', 'pomnex-update-check' ),
						Check_Runner::format_date( $run['run_at'] ),
						Check_Runner::trigger_label( $run['trigger'] )
					)
				);
				?>
			</p>
			<?php $this->render_overall_explanation( $run['overall'] ); ?>
		<?php else : ?>
			<p><?php esc_html_e( 'No check has run yet. The first baseline is captured about a minute after activation, and a check runs after every update. You can also run one now.', 'pomnex-update-check' ); ?></p>
		<?php endif; ?>

		<?php if ( empty( $pages ) ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: link to the Pages tab. */
					esc_html__( 'No important pages are set up yet. Add them on the %s tab.', 'pomnex-update-check' ),
					'<a href="' . esc_url( self::url( 'pages' ) ) . '">' . esc_html__( 'Pages', 'pomnex-update-check' ) . '</a>'
				);
				?>
			</p>
		<?php else : ?>
			<?php $this->render_results_table( $this->status_rows( $pages, $baselines, $run ), true ); ?>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Schedule', 'pomnex-update-check' ); ?></h2>
		<ul class="pomnex-uc-schedule">
			<li>
				<?php
				echo esc_html(
					$next
						/* translators: %s: date and time. */
						? sprintf( __( 'Next check after an update: %s', 'pomnex-update-check' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) )
						: __( 'Next check after an update: none scheduled. A check is scheduled automatically when an update finishes.', 'pomnex-update-check' )
				);
				?>
			</li>
			<?php if ( $daily ) : ?>
				<li>
					<?php
					/* translators: %s: date and time. */
					echo esc_html( sprintf( __( 'Next daily baseline refresh: %s', 'pomnex-update-check' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $daily ) ) );
					?>
				</li>
			<?php endif; ?>
		</ul>

		<div class="pomnex-uc-actions">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pomnex-uc-busy-form">
				<input type="hidden" name="action" value="pomnex_uc_run_check">
				<?php wp_nonce_field( 'pomnex_uc_run_check' ); ?>
				<button type="submit" class="button button-primary" data-busy-label="<?php esc_attr_e( 'Checking…', 'pomnex-update-check' ); ?>"><?php esc_html_e( 'Run check now', 'pomnex-update-check' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pomnex-uc-busy-form">
				<input type="hidden" name="action" value="pomnex_uc_capture_baseline">
				<?php wp_nonce_field( 'pomnex_uc_capture_baseline' ); ?>
				<button type="submit" class="button" data-busy-label="<?php esc_attr_e( 'Saving…', 'pomnex-update-check' ); ?>"><?php esc_html_e( 'Save current state as baseline', 'pomnex-update-check' ); ?></button>
			</form>
		</div>
		<p class="description"><?php esc_html_e( 'Baselines refresh by themselves after every check a page passes. Use "Save current state as baseline" when a warning is about a change you expected, such as a redesigned page.', 'pomnex-update-check' ); ?></p>
		<?php
	}

	/**
	 * Builds the Status tab rows from the settings, baselines and last run.
	 *
	 * @param array[]    $pages     Important pages.
	 * @param array      $baselines Baselines.
	 * @param array|null $run       Last run.
	 * @return array[]
	 */
	private function status_rows( array $pages, array $baselines, $run ) {
		$run_pages = array();
		if ( $run ) {
			foreach ( $run['pages'] as $page_run ) {
				$run_pages[ $page_run['id'] ] = $page_run;
			}
		}

		$rows = array();
		foreach ( $pages as $page ) {
			$baseline = isset( $baselines[ $page['id'] ] ) && $baselines[ $page['id'] ]['url'] === $page['url'] ? $baselines[ $page['id'] ] : null;
			$page_run = isset( $run_pages[ $page['id'] ] ) && $run_pages[ $page['id'] ]['url'] === $page['url'] ? $run_pages[ $page['id'] ] : null;
			$result   = $page_run ? $page_run['result'] : '';
			$findings = $page_run ? $page_run['findings'] : array();

			// A baseline captured while failing, newer than the last check.
			if ( $baseline && ! empty( $baseline['failing'] ) && ( ! $page_run || $baseline['captured_at'] > $run['run_at'] ) ) {
				$result = 'failing_now';
			}

			$rows[] = array(
				'label'    => $page['label'],
				'url'      => $page['url'],
				'marker'   => $page['marker'],
				'result'   => $result,
				'findings' => $findings,
				'baseline' => $baseline
					/* translators: %s: date and time. */
					? sprintf( __( 'Captured %s', 'pomnex-update-check' ), Check_Runner::format_date( $baseline['captured_at'] ) )
					: __( 'No baseline yet', 'pomnex-update-check' ),
			);
		}

		return $rows;
	}

	/**
	 * Renders a per-page results table.
	 *
	 * @param array[] $rows          Rows with label, url, result, findings and optionally baseline.
	 * @param bool    $show_baseline Whether to show the baseline column.
	 */
	private function render_results_table( array $rows, $show_baseline ) {
		?>
		<table class="widefat striped pomnex-uc-results">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Page', 'pomnex-update-check' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'pomnex-update-check' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'pomnex-update-check' ); ?></th>
					<?php if ( $show_baseline ) : ?>
						<th scope="col"><?php esc_html_e( 'Baseline', 'pomnex-update-check' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $row['label'] ); ?></strong><br>
							<a href="<?php echo esc_url( $row['url'] ); ?>" class="pomnex-uc-url" dir="ltr"><?php echo esc_html( $row['url'] ); ?></a>
							<?php if ( ! empty( $row['marker'] ) ) : ?>
								<br><span class="pomnex-uc-marker">
									<?php
									/* translators: %s: marker text. */
									echo esc_html( sprintf( __( 'Marker: %s', 'pomnex-update-check' ), $row['marker'] ) );
									?>
								</span>
							<?php endif; ?>
						</td>
						<td><?php echo wp_kses_post( self::status_badge( $row['result'] ) ); ?></td>
						<td>
							<?php if ( empty( $row['findings'] ) ) : ?>
								<?php echo esc_html( Page_Analyzer::PASS === $row['result'] ? __( 'No problems found.', 'pomnex-update-check' ) : '—' ); ?>
							<?php else : ?>
								<ul class="pomnex-uc-findings">
									<?php foreach ( $row['findings'] as $finding ) : ?>
										<li class="pomnex-uc-finding--<?php echo esc_attr( sanitize_html_class( $finding['severity'] ) ); ?>"><?php echo esc_html( Check_Runner::describe_finding( $finding ) ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
						<?php if ( $show_baseline ) : ?>
							<td><?php echo esc_html( $row['baseline'] ); ?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Explains an overall result in one or two sentences.
	 *
	 * @param string $overall Overall result.
	 */
	private function render_overall_explanation( $overall ) {
		switch ( $overall ) {
			case Page_Analyzer::PASS:
				$text = __( 'All pages passed.', 'pomnex-update-check' );
				break;
			case Page_Analyzer::WARNING:
				$text = __( 'Something changed that may be a problem. See the details below.', 'pomnex-update-check' );
				break;
			case Page_Analyzer::FAIL:
				$text = __( 'At least one page is broken. See the details below.', 'pomnex-update-check' );
				break;
			default:
				$text = '';
		}

		if ( '' !== $text ) {
			echo '<p>' . esc_html( $text ) . '</p>';
		}

		if ( Page_Analyzer::COULD_NOT_CHECK === $overall || Page_Analyzer::WARNING === $overall ) {
			$this->render_loopback_help( Page_Analyzer::COULD_NOT_CHECK === $overall );
		}
	}

	/**
	 * Explains loopback blocking and links to Site Health.
	 *
	 * @param bool $all_pages Whether no page could be checked.
	 */
	private function render_loopback_help( $all_pages ) {
		$run = Check_Runner::get_last_run();

		if ( ! $all_pages && ( ! $run || ! in_array( Page_Analyzer::COULD_NOT_CHECK, wp_list_pluck( $run['pages'], 'result' ), true ) ) ) {
			return;
		}
		?>
		<div class="notice notice-warning inline">
			<p>
				<?php
				echo esc_html(
					$all_pages
						? __( 'The pages could not be loaded, so nothing was checked. This is not a pass.', 'pomnex-update-check' )
						: __( 'Some pages could not be loaded, so they were not checked.', 'pomnex-update-check' )
				);
				echo ' ';
				printf(
					/* translators: %s: link to Tools → Site Health. */
					esc_html__( 'Some hosts block a site\'s requests to itself (loopback requests), which this check needs. %s runs its own loopback test and can tell you if this is the cause.', 'pomnex-update-check' ),
					'<a href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'Tools → Site Health', 'pomnex-update-check' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Shows the result of a Run check now or Save baseline action.
	 */
	private function render_action_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only; the action itself was nonce-checked.
		$code = isset( $_GET['pomnex_uc_notice'] ) ? sanitize_key( wp_unslash( $_GET['pomnex_uc_notice'] ) ) : '';

		$messages = array(
			'ran'             => array( 'success', __( 'The check finished. The results are below.', 'pomnex-update-check' ) ),
			'captured'        => array( 'success', __( 'The current state of every page was saved as its baseline.', 'pomnex-update-check' ) ),
			'captured_partly' => array( 'warning', __( 'Baselines were saved, except for pages that could not be loaded.', 'pomnex-update-check' ) ),
			'locked'          => array( 'warning', __( 'A check is already running. Please wait a minute and try again.', 'pomnex-update-check' ) ),
			'maintenance'     => array( 'warning', __( 'The site is in maintenance mode. Try again when the update has finished.', 'pomnex-update-check' ) ),
			'no_pages'        => array( 'warning', __( 'No important pages are set up yet. Add them on the Pages tab.', 'pomnex-update-check' ) ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $messages[ $code ][1] )
		);
	}

	/**
	 * Renders the Update log tab, with the selected entry's details on top.
	 */
	private function render_log_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only entry selection.
		$entry_id = isset( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0;
		$entry    = $entry_id ? $this->log->get( $entry_id ) : null;

		if ( $entry ) {
			$this->render_log_entry( $entry );
		}

		echo '<p>' . esc_html__( 'Every core, plugin and theme update is recorded here with the result of the check that followed. The newest 100 entries are kept. Click an entry to see the full results.', 'pomnex-update-check' ) . '</p>';

		$table = new Log_List_Table( $this->log );
		$table->prepare_items();
		$table->display();
	}

	/**
	 * Renders one log entry with its per-page results.
	 *
	 * @param array $entry Log entry.
	 */
	private function render_log_entry( array $entry ) {
		?>
		<div class="pomnex-uc-entry card">
			<h2>
				<?php
				/* translators: %s: date and time. */
				echo esc_html( sprintf( __( 'Update on %s', 'pomnex-update-check' ), Check_Runner::format_date( $entry['created_at'] ) ) );
				?>
			</h2>
			<p>
				<?php
				/* translators: %s: user name or "Automatic". */
				echo esc_html( sprintf( __( 'By: %s', 'pomnex-update-check' ), Check_Runner::entry_author( $entry ) ) );
				?>
			</p>
			<?php echo wp_kses_post( Log_List_Table::items_html( $entry['items'] ) ); ?>
			<p>
				<?php
				echo esc_html__( 'Check result:', 'pomnex-update-check' ) . ' ' . wp_kses_post( self::status_badge( $entry['check_status'] ) );

				if ( '' !== $entry['checked_at'] ) {
					/* translators: %s: date and time. */
					echo ' ' . esc_html( sprintf( __( 'checked %s', 'pomnex-update-check' ), Check_Runner::format_date( $entry['checked_at'] ) ) );
				}
				?>
			</p>
			<?php
			if ( ! empty( $entry['check_results']['pages'] ) ) {
				$rows = array();
				foreach ( $entry['check_results']['pages'] as $page ) {
					$rows[] = array(
						'label'    => $page['label'],
						'url'      => $page['url'],
						'marker'   => '',
						'result'   => $page['result'],
						'findings' => $page['findings'],
					);
				}
				$this->render_results_table( $rows, false );
			} elseif ( Log_Repository::PENDING === $entry['check_status'] ) {
				echo '<p>' . esc_html__( 'The check for this update has not run yet.', 'pomnex-update-check' ) . '</p>';
			}
			?>
			<p><a href="<?php echo esc_url( self::url( 'log' ) ); ?>"><?php esc_html_e( 'Close', 'pomnex-update-check' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Renders the About tab, including the attribution required by the
	 * license's additional term under GPLv3 section 7(b).
	 */
	private function render_about_tab() {
		?>
		<h2><?php esc_html_e( 'About Update Check', 'pomnex-update-check' ); ?></h2>
		<p><?php esc_html_e( 'Update Check loads your important pages after every core, plugin and theme update, compares them with how they looked when they last worked, and emails you if something broke. Everything runs on your own site. No account, no external service, and no data leaves the site.', 'pomnex-update-check' ); ?></p>
		<p><?php esc_html_e( 'It does not fix problems, roll back updates or take backups. It does not take screenshots, so it does not catch small visual changes, and it does not click buttons or place test orders.', 'pomnex-update-check' ); ?></p>
		<p>
			<?php
			printf(
				/* translators: %s: link to the source code. */
				esc_html__( 'Source code and issue tracker: %s', 'pomnex-update-check' ),
				'<a href="https://github.com/Pomnex/pomnex-update-check">github.com/Pomnex/pomnex-update-check</a>'
			);
			?>
		</p>
		<hr>
		<p class="pomnex-uc-credit">
			<?php
			printf(
				/* translators: %s: link to Pomnex. */
				esc_html__( 'Developed by %s', 'pomnex-update-check' ),
				'<a href="https://pomnex.com">Pomnex</a>'
			);
			?>
		</p>
		<p>
			<a href="https://pomnex.com/wordpress-maintenance"><?php esc_html_e( 'The plugin tells you something broke. Pomnex\'s WordPress maintenance plan fixes it for you.', 'pomnex-update-check' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Returns a key identifying the current overdue situation, or an empty string.
	 *
	 * @return string
	 */
	private function overdue_key() {
		$next = wp_next_scheduled( Update_Listener::CHECK_HOOK );

		if ( $next ) {
			return $next < time() - self::OVERDUE_AFTER ? 'check-' . $next : '';
		}

		// Entries still waiting with nothing scheduled: the event was lost.
		$oldest = $this->log->oldest_pending_time();

		if ( $oldest && $oldest < time() - Update_Listener::CHECK_DELAY - self::OVERDUE_AFTER ) {
			return 'pending-' . $oldest;
		}

		return '';
	}

	/**
	 * Checks the capability and nonce of an admin action.
	 *
	 * @param string $action Nonce action.
	 */
	private function authorize( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'pomnex-update-check' ), 403 );
		}

		check_admin_referer( $action );
	}
}
