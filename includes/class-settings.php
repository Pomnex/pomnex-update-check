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
 * Registers the settings with the Settings API, renders the Pages tab and
 * validates what the owner saves.
 */
final class Settings {

	/**
	 * Option name.
	 */
	const OPTION = 'pomnex_uc_settings';

	/**
	 * Settings group used by settings_fields().
	 */
	const GROUP = 'pomnex_uc_settings_group';

	/**
	 * Page slug used by do_settings_sections().
	 */
	const PAGE = 'pomnex-update-check';

	/**
	 * Maximum number of important pages.
	 */
	const MAX_PAGES = 5;

	/**
	 * Maximum label length.
	 */
	const LABEL_MAX = 60;

	/**
	 * Maximum marker length.
	 */
	const MARKER_MAX = 200;

	/**
	 * Check runner, used to capture baselines when pages change.
	 *
	 * @var Check_Runner
	 */
	private $runner;

	/**
	 * Constructor.
	 *
	 * @param Check_Runner $runner Check runner.
	 */
	public function __construct( Check_Runner $runner ) {
		$this->runner = $runner;
	}

	/**
	 * Hooks into WordPress.
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'update_option_' . self::OPTION, array( $this, 'on_saved' ), 10, 2 );
	}

	/**
	 * Returns the default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'pages'          => array(),
			'emails'         => (string) get_option( 'admin_email' ),
			'email_warnings' => false,
		);
	}

	/**
	 * Stores the first-activation settings, with the home page prefilled.
	 * Existing settings are never overwritten.
	 */
	public static function add_defaults() {
		if ( false !== get_option( self::OPTION ) ) {
			return;
		}

		$settings          = self::defaults();
		$settings['pages'] = array(
			array(
				'id'     => self::new_id(),
				'label'  => __( 'Home page', 'pomnex-update-check' ),
				'url'    => home_url( '/' ),
				'marker' => '',
			),
		);

		add_option( self::OPTION, $settings );
	}

	/**
	 * Returns the saved settings merged with the defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Returns the important pages.
	 *
	 * @return array[] List of `array( 'id', 'label', 'url', 'marker' )`.
	 */
	public static function get_pages() {
		$settings = self::get();

		return is_array( $settings['pages'] ) ? array_values( $settings['pages'] ) : array();
	}

	/**
	 * Returns the valid notification addresses. Falls back to the admin email.
	 *
	 * @return string[]
	 */
	public static function get_emails() {
		$settings = self::get();
		$emails   = array_filter( array_map( 'trim', explode( ',', (string) $settings['emails'] ) ), 'is_email' );

		if ( empty( $emails ) ) {
			$emails = array_filter( array( (string) get_option( 'admin_email' ) ), 'is_email' );
		}

		return array_values( $emails );
	}

	/**
	 * Whether warnings are emailed too.
	 *
	 * @return bool
	 */
	public static function email_warnings() {
		$settings = self::get();

		return ! empty( $settings['email_warnings'] );
	}

	/**
	 * Returns the comparison thresholds.
	 *
	 * @return array{slow_factor: float, slow_min_seconds: float, size_ratio: float}
	 */
	public static function thresholds() {
		$defaults = array(
			'slow_factor'      => 2.0,
			'slow_min_seconds' => 1.0,
			'size_ratio'       => 0.5,
		);

		/**
		 * Filters the thresholds used for the slow-response and size-drop warnings.
		 *
		 * @param array $thresholds {
		 *     @type float $slow_factor      Warn when the response takes more than this many times the baseline.
		 *     @type float $slow_min_seconds ...and is at least this many seconds slower.
		 *     @type float $size_ratio       Warn when the body is smaller than this share of the baseline size.
		 * }
		 */
		$thresholds = apply_filters( 'pomnex_uc_thresholds', $defaults );

		return array_map( 'floatval', wp_parse_args( is_array( $thresholds ) ? $thresholds : array(), $defaults ) );
	}

	/**
	 * Registers the setting, sections and fields.
	 */
	public function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'pomnex_uc_pages',
			__( 'Important pages', 'pomnex-update-check' ),
			array( $this, 'render_pages_intro' ),
			self::PAGE
		);

		add_settings_field(
			'pomnex_uc_pages_table',
			__( 'Pages', 'pomnex-update-check' ),
			array( $this, 'render_pages_field' ),
			self::PAGE,
			'pomnex_uc_pages',
			array( 'class' => 'pomnex-uc-pages-row' )
		);

		add_settings_section(
			'pomnex_uc_notifications',
			__( 'Notifications', 'pomnex-update-check' ),
			'__return_false',
			self::PAGE
		);

		add_settings_field(
			'pomnex_uc_emails',
			__( 'Notification email(s)', 'pomnex-update-check' ),
			array( $this, 'render_emails_field' ),
			self::PAGE,
			'pomnex_uc_notifications',
			array( 'label_for' => 'pomnex-uc-emails' )
		);

		add_settings_field(
			'pomnex_uc_email_warnings',
			__( 'Warnings', 'pomnex-update-check' ),
			array( $this, 'render_warnings_field' ),
			self::PAGE,
			'pomnex_uc_notifications'
		);

		add_settings_field(
			'pomnex_uc_thresholds',
			__( 'Thresholds', 'pomnex-update-check' ),
			array( $this, 'render_thresholds_field' ),
			self::PAGE,
			'pomnex_uc_notifications'
		);
	}

	/**
	 * Renders the Pages tab form.
	 */
	public function render_form() {
		settings_errors();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="pomnex-uc-settings">
			<?php
			settings_fields( self::GROUP );
			do_settings_sections( self::PAGE );
			submit_button();
			?>
		</form>
		<?php
	}

	/**
	 * Renders the help text above the pages table.
	 */
	public function render_pages_intro() {
		echo '<p>' . esc_html__( 'Choose up to 5 pages on this site that must keep working after updates. After every update, each page is loaded the way a logged-out visitor sees it and compared with how it looked when it last worked.', 'pomnex-update-check' ) . '</p>';
		echo '<p>' . esc_html__( 'The URL must be on this site. You can enter a full URL or a path such as /checkout/.', 'pomnex-update-check' ) . '</p>';
		echo '<p>' . esc_html__( 'The marker is optional: a piece of text or HTML that must always be on the page, such as "Place order" or name="woocommerce_checkout_place_order". It is matched against the page\'s HTML source, ignoring upper and lower case, so it can be visible text or part of a tag, like an id, class or name attribute.', 'pomnex-update-check' ) . '</p>';
		echo '<p>' . esc_html__( 'The check reads the HTML the server sends. Content that JavaScript adds after the page loads is not visible to it, so pick a marker that appears in the page source (in your browser: View Page Source).', 'pomnex-update-check' ) . '</p>';

		$this->render_woocommerce_hint();
	}

	/**
	 * Suggests the WooCommerce checkout and cart pages when WooCommerce is active.
	 */
	private function render_woocommerce_hint() {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_page_permalink' ) ) {
			return;
		}

		$checkout = wc_get_page_permalink( 'checkout' );
		$cart     = wc_get_page_permalink( 'cart' );

		echo '<div class="pomnex-uc-hint"><p>';
		echo esc_html__( 'WooCommerce is active. You may want to add these pages:', 'pomnex-update-check' );
		echo '</p><ul>';
		/* translators: %s: page URL. */
		echo '<li>' . esc_html( sprintf( __( 'Checkout: %s', 'pomnex-update-check' ), $checkout ) ) . '</li>';
		/* translators: %s: page URL. */
		echo '<li>' . esc_html( sprintf( __( 'Cart: %s', 'pomnex-update-check' ), $cart ) ) . '</li>';
		echo '</ul><p>';
		echo esc_html__( 'Note: WooCommerce sends visitors with an empty cart from the checkout to the cart page, so a check of the checkout page sees the cart page. Choose a marker that appears there, or check the cart page directly.', 'pomnex-update-check' );
		echo '</p></div>';
	}

	/**
	 * Renders the five page rows.
	 */
	public function render_pages_field() {
		$pages = self::get_pages();
		$name  = self::OPTION . '[pages]';
		?>
		<table class="widefat striped pomnex-uc-pages">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Label', 'pomnex-update-check' ); ?></th>
					<th scope="col"><?php esc_html_e( 'URL', 'pomnex-update-check' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Marker (optional)', 'pomnex-update-check' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			for ( $i = 0; $i < self::MAX_PAGES; $i++ ) {
				$page = isset( $pages[ $i ] ) ? $pages[ $i ] : array(
					'id'     => '',
					'label'  => '',
					'url'    => '',
					'marker' => '',
				);
				/* translators: %d: row number. */
				$row = sprintf( __( 'Page %d', 'pomnex-update-check' ), $i + 1 );
				?>
				<tr>
					<td>
						<input type="hidden" name="<?php echo esc_attr( "{$name}[{$i}][id]" ); ?>" value="<?php echo esc_attr( $page['id'] ); ?>">
						<label class="screen-reader-text" for="<?php echo esc_attr( "pomnex-uc-label-{$i}" ); ?>">
							<?php
							/* translators: %s: row name, such as "Page 1". */
							echo esc_html( sprintf( __( '%s label', 'pomnex-update-check' ), $row ) );
							?>
						</label>
						<input type="text" class="regular-text" id="<?php echo esc_attr( "pomnex-uc-label-{$i}" ); ?>" name="<?php echo esc_attr( "{$name}[{$i}][label]" ); ?>" value="<?php echo esc_attr( $page['label'] ); ?>" maxlength="<?php echo esc_attr( (string) self::LABEL_MAX ); ?>" placeholder="<?php echo esc_attr( 0 === $i ? __( 'Home page', 'pomnex-update-check' ) : __( 'Checkout', 'pomnex-update-check' ) ); ?>">
					</td>
					<td>
						<label class="screen-reader-text" for="<?php echo esc_attr( "pomnex-uc-url-{$i}" ); ?>">
							<?php
							/* translators: %s: row name, such as "Page 1". */
							echo esc_html( sprintf( __( '%s URL', 'pomnex-update-check' ), $row ) );
							?>
						</label>
						<input type="text" class="regular-text code" dir="ltr" id="<?php echo esc_attr( "pomnex-uc-url-{$i}" ); ?>" name="<?php echo esc_attr( "{$name}[{$i}][url]" ); ?>" value="<?php echo esc_attr( $page['url'] ); ?>" placeholder="<?php echo esc_attr( 0 === $i ? home_url( '/' ) : '/checkout/' ); ?>">
					</td>
					<td>
						<label class="screen-reader-text" for="<?php echo esc_attr( "pomnex-uc-marker-{$i}" ); ?>">
							<?php
							/* translators: %s: row name, such as "Page 1". */
							echo esc_html( sprintf( __( '%s marker', 'pomnex-update-check' ), $row ) );
							?>
						</label>
						<input type="text" class="regular-text code" id="<?php echo esc_attr( "pomnex-uc-marker-{$i}" ); ?>" name="<?php echo esc_attr( "{$name}[{$i}][marker]" ); ?>" value="<?php echo esc_attr( $page['marker'] ); ?>" maxlength="<?php echo esc_attr( (string) self::MARKER_MAX ); ?>">
					</td>
				</tr>
				<?php
			}
			?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'To remove a page, clear its label and URL. Saving captures a fresh baseline for new or changed pages.', 'pomnex-update-check' ); ?></p>
		<?php
	}

	/**
	 * Renders the notification email field.
	 */
	public function render_emails_field() {
		$settings = self::get();
		?>
		<input type="text" class="regular-text" dir="ltr" id="pomnex-uc-emails" name="<?php echo esc_attr( self::OPTION . '[emails]' ); ?>" value="<?php echo esc_attr( $settings['emails'] ); ?>" aria-describedby="pomnex-uc-emails-description">
		<p class="description" id="pomnex-uc-emails-description"><?php esc_html_e( 'Separate several addresses with commas. Emails are sent when a check fails.', 'pomnex-update-check' ); ?></p>
		<?php
	}

	/**
	 * Renders the "Also email on warnings" checkbox.
	 */
	public function render_warnings_field() {
		$settings = self::get();
		?>
		<label for="pomnex-uc-email-warnings">
			<input type="checkbox" id="pomnex-uc-email-warnings" name="<?php echo esc_attr( self::OPTION . '[email_warnings]' ); ?>" value="1" <?php checked( ! empty( $settings['email_warnings'] ) ); ?>>
			<?php esc_html_e( 'Also email on warnings', 'pomnex-update-check' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Warnings are changes that may be harmless, such as a slower page or a stylesheet that was renamed.', 'pomnex-update-check' ); ?></p>
		<?php
	}

	/**
	 * Shows the thresholds as plain numbers with explanations.
	 */
	public function render_thresholds_field() {
		$thresholds = self::thresholds();
		?>
		<ul class="pomnex-uc-thresholds">
			<li>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: factor, such as 2. 2: seconds, such as 1.0. */
						__( 'Slow response: warn when a page takes more than %1$s× as long as when it last worked, and is at least %2$s s slower. Both must be true, so a fast page getting slightly slower is not reported.', 'pomnex-update-check' ),
						number_format_i18n( $thresholds['slow_factor'], 1 ),
						number_format_i18n( $thresholds['slow_min_seconds'], 1 )
					)
				);
				?>
			</li>
			<li>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: percentage, such as 50. */
						__( 'Size drop: warn when the page\'s HTML is less than %s %% of its size when it last worked. A page that suddenly shrinks has often lost a section.', 'pomnex-update-check' ),
						number_format_i18n( $thresholds['size_ratio'] * 100 )
					)
				);
				?>
			</li>
		</ul>
		<?php
	}

	/**
	 * Validates and sanitises the submitted settings.
	 *
	 * Invalid rows keep their previously saved value (or are dropped when
	 * new) and an error explains why.
	 *
	 * @param mixed $input Submitted value, already unslashed by options.php.
	 * @return array Clean settings.
	 */
	public function sanitize( $input ) {
		$current = self::get();

		if ( ! is_array( $input ) ) {
			return $current;
		}

		$previous = array();
		foreach ( (array) $current['pages'] as $page ) {
			if ( ! empty( $page['id'] ) ) {
				$previous[ $page['id'] ] = $page;
			}
		}

		$pages = array();
		$ids   = array();
		$rows  = isset( $input['pages'] ) && is_array( $input['pages'] ) ? array_values( $input['pages'] ) : array();

		foreach ( array_slice( $rows, 0, self::MAX_PAGES ) as $index => $row ) {
			$row    = is_array( $row ) ? $row : array();
			$id     = isset( $row['id'] ) ? sanitize_key( $row['id'] ) : '';
			$label  = isset( $row['label'] ) ? self::limit( sanitize_text_field( $row['label'] ), self::LABEL_MAX ) : '';
			$url    = isset( $row['url'] ) ? trim( sanitize_text_field( $row['url'] ) ) : '';
			$marker = isset( $row['marker'] ) ? self::sanitize_marker( $row['marker'] ) : '';

			if ( '' === $label && '' === $url && '' === $marker ) {
				continue;
			}

			if ( '' === $id || isset( $ids[ $id ] ) ) {
				$id = self::new_id();
			}

			$error = '';
			$url   = self::normalize_url( $url, $error );

			if ( '' === $label && '' === $error ) {
				$error = __( 'a label is required.', 'pomnex-update-check' );
			}

			if ( '' !== $error ) {
				add_settings_error(
					self::OPTION,
					'pomnex_uc_page_' . ( $index + 1 ),
					sprintf(
						/* translators: 1: row number. 2: reason the row was rejected. */
						__( 'Page %1$d was not saved: %2$s', 'pomnex-update-check' ),
						$index + 1,
						$error
					)
				);

				if ( isset( $previous[ $id ] ) ) {
					$pages[]    = $previous[ $id ];
					$ids[ $id ] = true;
				}
				continue;
			}

			$pages[]    = array(
				'id'     => $id,
				'label'  => $label,
				'url'    => $url,
				'marker' => $marker,
			);
			$ids[ $id ] = true;
		}

		return array(
			'pages'          => $pages,
			'emails'         => $this->sanitize_emails( isset( $input['emails'] ) ? (string) $input['emails'] : '', (string) $current['emails'] ),
			'email_warnings' => ! empty( $input['email_warnings'] ),
		);
	}

	/**
	 * Captures baselines for new or changed pages after the settings are saved,
	 * and forgets the baselines of removed pages.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     New value.
	 */
	public function on_saved( $old_value, $value ) {
		unset( $old_value );

		$pages = is_array( $value ) && ! empty( $value['pages'] ) && is_array( $value['pages'] ) ? $value['pages'] : array();

		Check_Runner::prune_baselines( wp_list_pluck( $pages, 'id' ) );

		if ( empty( $pages ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$baselines = Check_Runner::get_baselines();
		$changed   = array();

		foreach ( $pages as $page ) {
			$baseline = isset( $baselines[ $page['id'] ] ) ? $baselines[ $page['id'] ] : null;

			if ( ! $baseline || $baseline['url'] !== $page['url'] || $baseline['marker'] !== $page['marker'] ) {
				$changed[] = $page['id'];
			}
		}

		if ( empty( $changed ) ) {
			return;
		}

		$results = $this->runner->capture_baselines( $changed );
		$labels  = wp_list_pluck( $pages, 'label', 'id' );
		$failing = array();
		$missed  = array();

		foreach ( $results as $id => $result ) {
			if ( Page_Analyzer::FAIL === $result ) {
				$failing[] = $labels[ $id ];
			} elseif ( Page_Analyzer::COULD_NOT_CHECK === $result ) {
				$missed[] = $labels[ $id ];
			}
		}

		add_settings_error( self::OPTION, 'pomnex_uc_saved', __( 'Settings saved. A baseline was captured for new or changed pages.', 'pomnex-update-check' ), 'success' );

		if ( $failing ) {
			add_settings_error(
				self::OPTION,
				'pomnex_uc_failing',
				sprintf(
					/* translators: %s: comma-separated page labels. */
					__( 'These pages are failing now: %s. Their baseline was saved anyway. See the Status tab for details.', 'pomnex-update-check' ),
					implode( ', ', $failing )
				),
				'warning'
			);
		}

		if ( $missed ) {
			add_settings_error(
				self::OPTION,
				'pomnex_uc_missed',
				sprintf(
					/* translators: %s: comma-separated page labels. */
					__( 'These pages could not be loaded, so no baseline was captured: %s. Some hosts block a site\'s requests to itself; Tools → Site Health runs its own test for this.', 'pomnex-update-check' ),
					implode( ', ', $missed )
				),
				'warning'
			);
		}
	}

	/**
	 * Turns a submitted URL into a full URL on this site.
	 *
	 * Accepts a relative path and resolves it with home_url(). Rejects any
	 * other host. This is a product rule and also prevents the check from
	 * being pointed at other servers.
	 *
	 * @param string $url   Submitted URL.
	 * @param string $error Set to the reason when the URL is rejected.
	 * @return string Full URL, or an empty string when rejected.
	 */
	public static function normalize_url( $url, &$error ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			$error = __( 'a URL is required.', 'pomnex-update-check' );
			return '';
		}

		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		} elseif ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			$url = home_url( '/' . ltrim( $url, '/' ) );
		}

		$url  = esc_url_raw( $url, array( 'http', 'https' ) );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		if ( '' === $url || '' === $host ) {
			$error = __( 'the URL is not valid.', 'pomnex-update-check' );
			return '';
		}

		if ( $host !== $home ) {
			$error = sprintf(
				/* translators: 1: host the owner entered. 2: this site's host. */
				__( 'the URL is on %1$s, but only pages on this site (%2$s) can be checked.', 'pomnex-update-check' ),
				$host,
				$home
			);
			return '';
		}

		return $url;
	}

	/**
	 * Cleans a marker. Markers may contain HTML such as `name="x"`, so tags
	 * are kept. The value is only used for matching and is always escaped
	 * on output.
	 *
	 * @param mixed $marker Submitted marker.
	 * @return string
	 */
	private static function sanitize_marker( $marker ) {
		$marker = wp_check_invalid_utf8( (string) $marker );
		$marker = (string) preg_replace( '#[\x00-\x1F\x7F]+#', ' ', $marker );

		return self::limit( trim( $marker ), self::MARKER_MAX );
	}

	/**
	 * Validates the comma-separated email list.
	 *
	 * @param string $input   Submitted list.
	 * @param string $current Currently saved list.
	 * @return string Clean comma-separated list.
	 */
	private function sanitize_emails( $input, $current ) {
		$valid   = array();
		$invalid = array();

		foreach ( array_filter( array_map( 'trim', explode( ',', $input ) ) ) as $address ) {
			$clean = sanitize_email( $address );

			if ( '' !== $clean && is_email( $clean ) ) {
				$valid[] = $clean;
			} else {
				$invalid[] = sanitize_text_field( $address );
			}
		}

		if ( $invalid ) {
			add_settings_error(
				self::OPTION,
				'pomnex_uc_emails',
				sprintf(
					/* translators: %s: comma-separated invalid addresses. */
					__( 'These email addresses are not valid and were removed: %s', 'pomnex-update-check' ),
					implode( ', ', $invalid )
				)
			);
		}

		if ( empty( $valid ) ) {
			if ( '' !== trim( $input ) ) {
				return $current;
			}

			return (string) get_option( 'admin_email' );
		}

		return implode( ', ', array_unique( $valid ) );
	}

	/**
	 * Shortens a string to a number of characters.
	 *
	 * @param string $text   Text.
	 * @param int    $length Maximum characters.
	 * @return string
	 */
	private static function limit( $text, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length, 'UTF-8' ) : substr( $text, 0, $length );
	}

	/**
	 * Generates a stable ID for a page row.
	 *
	 * @return string
	 */
	private static function new_id() {
		return 'p' . strtolower( wp_generate_password( 8, false, false ) );
	}
}
