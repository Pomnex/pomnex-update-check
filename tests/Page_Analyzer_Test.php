<?php
/**
 * Unit tests for Page_Analyzer.
 *
 * @package Pomnex\UpdateCheck
 */

namespace Pomnex\UpdateCheck\Tests;

defined( 'ABSPATH' ) || exit;

use PHPUnit\Framework\TestCase;
use Pomnex\UpdateCheck\Page_Analyzer;

/**
 * Covers every check in the specification with HTML fixtures.
 */
final class Page_Analyzer_Test extends TestCase {

	const HOME = 'https://example.com/';

	const PAGE = 'https://example.com/checkout/';

	const CRITICAL_EN = 'There has been a critical error on this website.';

	const CRITICAL_AR = 'حدث خطأ فادح في هذا الموقع.';

	/**
	 * Builds an analyzer that knows the English and Arabic critical-error sentences.
	 *
	 * @return Page_Analyzer
	 */
	private function analyzer() {
		return new Page_Analyzer( self::HOME, array( self::CRITICAL_EN, self::CRITICAL_AR ) );
	}

	/**
	 * Reads a fixture.
	 *
	 * @param string $name File name in tests/fixtures.
	 * @return string
	 */
	private function fixture( $name ) {
		return (string) file_get_contents( __DIR__ . '/fixtures/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
	}

	/**
	 * Builds a response array for a fixture.
	 *
	 * @param string $html   Body.
	 * @param int    $status Status code.
	 * @param float  $time   Response time.
	 * @return array
	 */
	private function response( $html, $status = 200, $time = 0.4 ) {
		return array(
			'error'  => null,
			'status' => $status,
			'body'   => $html,
			'time'   => $time,
			'size'   => strlen( $html ),
		);
	}

	/**
	 * Builds a baseline from the clean fixture.
	 *
	 * @param array $overrides Values to replace.
	 * @return array
	 */
	private function baseline( array $overrides = array() ) {
		$analyzer = $this->analyzer();
		$html     = $this->fixture( 'clean.html' );
		$baseline = $analyzer->build_baseline(
			self::PAGE,
			'Place order',
			$this->response( $html ),
			array_keys( $analyzer->extract_assets( $html, self::PAGE ) ),
			array( 'contact-form-7', 'hours', 'cart' ),
			'2026-10-01 10:00:00',
			false
		);

		return array_merge( $baseline, $overrides );
	}

	/**
	 * Asset results for every asset in the clean fixture, all returning 200.
	 *
	 * @return array
	 */
	private function healthy_assets() {
		$results = array();

		foreach ( array_keys( $this->analyzer()->extract_assets( $this->fixture( 'clean.html' ), self::PAGE ) ) as $url ) {
			$results[ $url ] = array( 'status' => 200 );
		}

		return $results;
	}

	/**
	 * Returns the codes of the findings.
	 *
	 * @param array[] $findings Findings.
	 * @return string[]
	 */
	private function codes( array $findings ) {
		return array_column( $findings, 'code' );
	}

	/**
	 * Returns the first finding with a code.
	 *
	 * @param array[] $findings Findings.
	 * @param string  $code     Code.
	 * @return array|null
	 */
	private function find( array $findings, $code ) {
		foreach ( $findings as $finding ) {
			if ( $code === $finding['code'] ) {
				return $finding;
			}
		}

		return null;
	}

	/**
	 * A healthy page compared with its own baseline passes with no findings.
	 */
	public function test_clean_page_passes() {
		$findings = $this->analyzer()->analyze(
			$this->response( $this->fixture( 'clean.html' ) ),
			$this->baseline(),
			'Place order',
			array(),
			$this->healthy_assets()
		);

		$this->assertSame( array(), $findings );
		$this->assertSame( Page_Analyzer::PASS, Page_Analyzer::page_result( $findings ) );
	}

	/**
	 * A clean page with no baseline yet also passes.
	 */
	public function test_clean_page_without_baseline_passes() {
		$findings = $this->analyzer()->analyze( $this->response( $this->fixture( 'clean.html' ) ), null, 'Place order', array( 'contact-form-7', 'hours' ) );

		$this->assertSame( array(), $findings );
	}

	/**
	 * Check 1: a WP_Error from the request means the page could not be checked.
	 */
	public function test_request_error_is_could_not_check() {
		$findings = $this->analyzer()->analyze(
			array(
				'error'  => 'cURL error 7: Failed to connect to example.com port 443',
				'status' => 0,
				'body'   => '',
			),
			$this->baseline(),
			'Place order'
		);

		$this->assertSame( array( 'request_error' ), $this->codes( $findings ) );
		$this->assertSame( Page_Analyzer::COULD_NOT_CHECK, Page_Analyzer::page_result( $findings ) );
		$this->assertStringContainsString( 'Failed to connect', $findings[0]['data']['message'] );
	}

	/**
	 * Check 2: a status of 400 or higher fails.
	 */
	public function test_error_status_fails() {
		$findings = $this->analyzer()->analyze( $this->response( 'Server error', 500 ), $this->baseline(), '' );
		$finding  = $this->find( $findings, 'http_status' );

		$this->assertNotNull( $finding );
		$this->assertSame( Page_Analyzer::FAIL, $finding['severity'] );
		$this->assertSame( 500, $finding['data']['status'] );
		$this->assertSame( 200, $finding['data']['baseline_status'] );
	}

	/**
	 * Check 2: a status that differs from a 2xx baseline fails.
	 */
	public function test_status_different_from_2xx_baseline_fails() {
		$findings = $this->analyzer()->analyze( $this->response( $this->fixture( 'clean.html' ), 302 ), $this->baseline(), 'Place order' );

		$this->assertContains( 'http_status', $this->codes( $findings ) );
		$this->assertSame( Page_Analyzer::FAIL, Page_Analyzer::page_result( $findings ) );
	}

	/**
	 * Check 2: a non-2xx baseline is not compared, only the 400 rule applies.
	 */
	public function test_status_compared_only_with_2xx_baseline() {
		$findings = $this->analyzer()->analyze(
			$this->response( $this->fixture( 'clean.html' ), 200 ),
			$this->baseline( array( 'status' => 302 ) ),
			'Place order'
		);

		$this->assertNotContains( 'http_status', $this->codes( $findings ) );
	}

	/**
	 * Check 3: the English critical-error page fails.
	 */
	public function test_critical_error_english_fails() {
		$findings = $this->analyzer()->analyze( $this->response( $this->fixture( 'critical-error-en.html' ) ), null, '' );

		$this->assertContains( 'critical_error', $this->codes( $findings ) );
		$this->assertSame( Page_Analyzer::FAIL, Page_Analyzer::page_result( $findings ) );
	}

	/**
	 * Check 3: the translated (Arabic) critical-error page fails.
	 */
	public function test_critical_error_translated_fails() {
		$findings = $this->analyzer()->analyze( $this->response( $this->fixture( 'critical-error-ar.html' ) ), null, '' );

		$this->assertContains( 'critical_error', $this->codes( $findings ) );
	}

	/**
	 * Check 3: the translated page is only detected when its sentence is known.
	 */
	public function test_critical_error_translated_needs_translation() {
		$analyzer = new Page_Analyzer( self::HOME, array( self::CRITICAL_EN ) );

		$this->assertFalse( $analyzer->has_critical_error( $this->fixture( 'critical-error-ar.html' ) ) );
	}

	/**
	 * Check 3: the sentence alone, without the wp-die-message class, is not the error page.
	 */
	public function test_critical_sentence_without_wp_die_class_passes() {
		$html = '<html><body><p>Our blog post: "There has been a critical error on this website." explained.</p></body></html>';

		$this->assertFalse( $this->analyzer()->has_critical_error( $html ) );
	}

	/**
	 * Check 4: a visible PHP warning is a Warning.
	 */
	public function test_php_warning_is_warning() {
		$findings = $this->analyzer()->analyze( $this->response( $this->fixture( 'php-warning.html' ) ), null, '' );
		$finding  = $this->find( $findings, 'php_error' );

		$this->assertNotNull( $finding );
		$this->assertSame( Page_Analyzer::WARNING, $finding['severity'] );
		$this->assertSame( 'Warning', $finding['data']['type'] );
		$this->assertStringContainsString( 'Undefined array key', $finding['data']['excerpt'] );
		$this->assertCount( 1, $findings, 'The HTML and plain patterns must not report the same error twice.' );
		$this->assertSame( Page_Analyzer::WARNING, Page_Analyzer::page_result( $findings ) );
	}

	/**
	 * Check 4: a visible fatal error is a Fail.
	 */
	public function test_php_fatal_error_fails() {
		$findings = $this->analyzer()->analyze( $this->response( $this->fixture( 'php-fatal.html' ) ), null, '' );
		$finding  = $this->find( $findings, 'php_error' );

		$this->assertSame( 'Fatal error', $finding['data']['type'] );
		$this->assertSame( Page_Analyzer::FAIL, $finding['severity'] );
		$this->assertSame( Page_Analyzer::FAIL, Page_Analyzer::page_result( $findings ) );
	}

	/**
	 * Check 4: the plain-text format with a file path and line number is detected,
	 * but ordinary text that starts with "Warning:" is not.
	 */
	public function test_php_plain_text_notice_detected_without_false_positive() {
		$errors = $this->analyzer()->find_php_errors( $this->fixture( 'php-notice-plain.html' ) );

		$this->assertCount( 1, $errors );
		$this->assertSame( 'Deprecated', $errors[0]['type'] );
	}

	/**
	 * Check 5: a marker found in the baseline and now missing fails.
	 */
	public function test_missing_marker_fails() {
		$html     = str_replace( array( 'Place&nbsp;order', 'value="Place order"' ), array( 'Continue', '' ), $this->fixture( 'clean.html' ) );
		$findings = $this->analyzer()->analyze( $this->response( $html ), $this->baseline(), 'Place order' );
		$finding  = $this->find( $findings, 'marker_missing' );

		$this->assertNotNull( $finding );
		$this->assertSame( Page_Analyzer::FAIL, $finding['severity'] );
		$this->assertSame( 'Place order', $finding['data']['marker'] );
	}

	/**
	 * Check 5: marker matching is case-insensitive, decodes entities and can match HTML.
	 */
	public function test_marker_matching_rules() {
		$analyzer = $this->analyzer();
		$html     = $this->fixture( 'clean.html' );

		$this->assertTrue( $analyzer->marker_found( $html, 'PLACE ORDER' ) );
		$this->assertTrue( $analyzer->marker_found( $html, 'name="woocommerce_checkout_place_order"' ) );
		$this->assertTrue( $analyzer->marker_found( $html, 'Checkout – Example Shop' ) );
		$this->assertFalse( $analyzer->marker_found( $html, 'Add to basket' ) );
	}

	/**
	 * Check 5: a marker that the baseline never found is a Warning, not a Fail.
	 */
	public function test_marker_never_found_is_warning() {
		$findings = $this->analyzer()->analyze(
			$this->response( $this->fixture( 'clean.html' ) ),
			$this->baseline(
				array(
					'marker'       => 'Plaec order',
					'marker_found' => false,
				)
			),
			'Plaec order'
		);

		$this->assertSame( array( 'marker_never_found' ), $this->codes( $findings ) );
		$this->assertSame( Page_Analyzer::WARNING, Page_Analyzer::page_result( $findings ) );
	}

	/**
	 * Check 5: with no baseline to compare with, a missing marker fails.
	 */
	public function test_missing_marker_without_baseline_fails() {
		$findings = $this->analyzer()->analyze( $this->response( $this->fixture( 'clean.html' ) ), null, 'Add to basket' );

		$this->assertSame( array( 'marker_missing' ), $this->codes( $findings ) );
	}

	/**
	 * Check 6: shortcode tags from the baseline that appear as text fail.
	 */
	public function test_unrendered_shortcode_fails() {
		$findings = $this->analyzer()->analyze( $this->response( $this->fixture( 'shortcode-unrendered.html' ) ), null, '', array( 'contact-form-7', 'hours', 'gallery' ) );
		$finding  = $this->find( $findings, 'shortcode_unrendered' );

		$this->assertNotNull( $finding );
		$this->assertSame( Page_Analyzer::FAIL, $finding['severity'] );
		$this->assertSame( array( 'contact-form-7', 'hours' ), $finding['data']['tags'] );
	}

	/**
	 * Check 6: the baseline's tags are used, not the tags registered now.
	 */
	public function test_unrendered_shortcode_uses_baseline_tags() {
		$baseline = $this->baseline( array( 'shortcodes' => array( 'hours' ) ) );
		$findings = $this->analyzer()->analyze( $this->response( $this->fixture( 'shortcode-unrendered.html' ) ), $baseline, '', array() );
		$finding  = $this->find( $findings, 'shortcode_unrendered' );

		$this->assertSame( array( 'hours' ), $finding['data']['tags'] );
	}

	/**
	 * Check 6: shortcodes inside code, pre, textarea, script, style, comments and attributes are not reported.
	 */
	public function test_shortcode_inside_code_is_not_a_false_positive() {
		$tags = $this->analyzer()->find_unrendered_shortcodes( $this->fixture( 'shortcode-in-code.html' ), array( 'contact-form-7', 'hours', 'brackets-plugin' ) );

		$this->assertSame( array(), $tags );
	}

	/**
	 * Check 6: a tag must be followed by a space, slash or closing bracket.
	 */
	public function test_shortcode_prefix_does_not_match_longer_words() {
		$analyzer = $this->analyzer();

		$this->assertSame( array(), $analyzer->find_unrendered_shortcodes( '<p>[cartoon]</p>', array( 'cart' ) ) );
		$this->assertSame( array( 'cart' ), $analyzer->find_unrendered_shortcodes( '<p>[cart]</p>', array( 'cart' ) ) );
		$this->assertSame( array( 'cart' ), $analyzer->find_unrendered_shortcodes( '<p>[cart id="1"]</p>', array( 'cart' ) ) );
	}

	/**
	 * Check 7: only same-host stylesheets and scripts are collected, normalised without `ver`.
	 */
	public function test_extracts_same_host_assets() {
		$assets = $this->analyzer()->extract_assets( $this->fixture( 'clean.html' ), self::PAGE );

		$this->assertSame(
			array(
				'https://example.com/wp-content/themes/shop/style.css',
				'https://example.com/wp-content/plugins/woocommerce/assets/css/woocommerce.css',
				'https://example.com/wp-includes/js/jquery/jquery.min.js',
				'https://example.com/wp-content/plugins/woocommerce/assets/js/checkout.js',
			),
			array_keys( $assets )
		);
		$this->assertSame( 'https://example.com/wp-content/themes/shop/style.css?ver=1.4.2', $assets['https://example.com/wp-content/themes/shop/style.css'] );
		$this->assertSame( 'https://example.com/wp-content/plugins/woocommerce/assets/css/woocommerce.css?ver=9.1.0&x=1', $assets['https://example.com/wp-content/plugins/woocommerce/assets/css/woocommerce.css'] );
	}

	/**
	 * Check 7: the regex fallback used without ext-dom finds the same assets.
	 */
	public function test_regex_fallback_matches_dom() {
		$analyzer = $this->analyzer();
		$html     = $this->fixture( 'clean.html' );

		$this->assertSame( $analyzer->extract_assets( $html, self::PAGE ), $analyzer->extract_assets_without_dom( $html, self::PAGE ) );
	}

	/**
	 * Check 7: a same-host asset returning 400 or more fails.
	 */
	public function test_broken_asset_fails() {
		$assets = $this->healthy_assets();
		$assets['https://example.com/wp-content/themes/shop/style.css'] = array( 'status' => 404 );

		$findings = $this->analyzer()->analyze( $this->response( $this->fixture( 'clean.html' ) ), $this->baseline(), 'Place order', array(), $assets );
		$finding  = $this->find( $findings, 'asset_broken' );

		$this->assertSame( Page_Analyzer::FAIL, $finding['severity'] );
		$this->assertSame( 404, $finding['data']['status'] );
		$this->assertSame( 'https://example.com/wp-content/themes/shop/style.css', $finding['data']['url'] );
	}

	/**
	 * Check 7: a baseline asset that is no longer on the page is a Warning, never a Fail.
	 */
	public function test_removed_asset_is_warning() {
		$html     = str_replace( "<script src=\"//example.com/wp-content/plugins/woocommerce/assets/js/checkout.js?ver=9.1.0\"></script>\n", '', $this->fixture( 'clean.html' ) ); // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Fixture text, not output.
		$assets   = $this->healthy_assets();
		$analyzer = $this->analyzer();
		unset( $assets['https://example.com/wp-content/plugins/woocommerce/assets/js/checkout.js'] );

		$this->assertCount( 3, $analyzer->extract_assets( $html, self::PAGE ) );

		$findings = $analyzer->analyze( $this->response( $html ), $this->baseline(), 'Place order', array(), $assets );
		$finding  = $this->find( $findings, 'asset_removed' );

		$this->assertSame( Page_Analyzer::WARNING, $finding['severity'] );
		$this->assertSame( array( 'https://example.com/wp-content/plugins/woocommerce/assets/js/checkout.js' ), $finding['data']['urls'] );
		$this->assertSame( Page_Analyzer::WARNING, Page_Analyzer::page_result( $findings ) );
	}

	/**
	 * Check 7: assets skipped by the budget are reported as not checked, never as passed or failed.
	 */
	public function test_skipped_assets_are_reported_not_checked() {
		$assets = $this->healthy_assets();
		$assets['https://example.com/wp-includes/js/jquery/jquery.min.js'] = array( 'skipped' => true );
		$assets['https://example.com/wp-content/themes/shop/style.css']    = array( 'error' => 'Operation timed out' );

		$findings = $this->analyzer()->analyze( $this->response( $this->fixture( 'clean.html' ) ), $this->baseline(), 'Place order', array(), $assets );
		$finding  = $this->find( $findings, 'assets_not_checked' );

		$this->assertSame( Page_Analyzer::INFO, $finding['severity'] );
		$this->assertSame( 2, $finding['data']['count'] );
	}

	/**
	 * Check 8: a response more than 2x and at least 1 s slower than the baseline is a Warning.
	 */
	public function test_slow_response_is_warning() {
		$findings = $this->analyzer()->analyze(
			$this->response( $this->fixture( 'clean.html' ), 200, 2.5 ),
			$this->baseline( array( 'time' => 0.8 ) ),
			'Place order'
		);
		$finding  = $this->find( $findings, 'slow' );

		$this->assertSame( Page_Analyzer::WARNING, $finding['severity'] );
		$this->assertSame( 2.5, $finding['data']['time'] );
		$this->assertSame( 0.8, $finding['data']['baseline_time'] );
	}

	/**
	 * Check 8: both thresholds must be met.
	 */
	public function test_slow_response_needs_both_thresholds() {
		$analyzer = $this->analyzer();
		$html     = $this->fixture( 'clean.html' );

		// 3x slower but only 0.4 s: no warning.
		$findings = $analyzer->analyze( $this->response( $html, 200, 0.6 ), $this->baseline( array( 'time' => 0.2 ) ), 'Place order' );
		$this->assertNotContains( 'slow', $this->codes( $findings ) );

		// 1.5 s slower but only 1.5x: no warning.
		$findings = $analyzer->analyze( $this->response( $html, 200, 4.5 ), $this->baseline( array( 'time' => 3.0 ) ), 'Place order' );
		$this->assertNotContains( 'slow', $this->codes( $findings ) );
	}

	/**
	 * Check 9: a body under 50 % of the baseline size is a Warning.
	 */
	public function test_size_drop_is_warning() {
		$html     = $this->fixture( 'clean.html' );
		$findings = $this->analyzer()->analyze(
			$this->response( $html ),
			$this->baseline( array( 'size' => strlen( $html ) * 3 ) ),
			'Place order'
		);
		$finding  = $this->find( $findings, 'size_drop' );

		$this->assertSame( Page_Analyzer::WARNING, $finding['severity'] );
		$this->assertSame( strlen( $html ), $finding['data']['size'] );
	}

	/**
	 * Check 9: a smaller page that is still above 50 % is fine.
	 */
	public function test_small_size_change_passes() {
		$html     = $this->fixture( 'clean.html' );
		$findings = $this->analyzer()->analyze(
			$this->response( $html ),
			$this->baseline( array( 'size' => (int) ( strlen( $html ) * 1.9 ) ) ),
			'Place order'
		);

		$this->assertNotContains( 'size_drop', $this->codes( $findings ) );
	}

	/**
	 * Overall result: Fail beats Warning beats Pass.
	 */
	public function test_overall_result_priority() {
		$this->assertSame( Page_Analyzer::FAIL, Page_Analyzer::overall_result( array( 'pass', 'warning', 'fail', 'could_not_check' ) ) );
		$this->assertSame( Page_Analyzer::WARNING, Page_Analyzer::overall_result( array( 'pass', 'warning' ) ) );
		$this->assertSame( Page_Analyzer::PASS, Page_Analyzer::overall_result( array( 'pass', 'pass' ) ) );
	}

	/**
	 * Overall result: Could not check never turns into a pass.
	 */
	public function test_overall_result_never_turns_could_not_check_into_pass() {
		$this->assertSame( Page_Analyzer::COULD_NOT_CHECK, Page_Analyzer::overall_result( array( 'could_not_check', 'could_not_check' ) ) );
		$this->assertSame( Page_Analyzer::WARNING, Page_Analyzer::overall_result( array( 'pass', 'could_not_check' ) ) );
		$this->assertSame( Page_Analyzer::COULD_NOT_CHECK, Page_Analyzer::overall_result( array() ) );
	}

	/**
	 * URL helpers resolve relative URLs and normalise for comparison.
	 */
	public function test_url_helpers() {
		$this->assertSame( 'https://example.com/a/b.css', Page_Analyzer::resolve_url( '/a/b.css', self::PAGE ) );
		$this->assertSame( 'https://example.com/checkout/b.js', Page_Analyzer::resolve_url( 'b.js', self::PAGE ) );
		$this->assertSame( 'https://example.com/x.js', Page_Analyzer::resolve_url( '//example.com/x.js#top', self::PAGE ) );
		$this->assertSame( '', Page_Analyzer::resolve_url( 'data:text/css,body{}', self::PAGE ) );
		$this->assertSame( 'https://example.com:8443/a.css', Page_Analyzer::normalize_asset_url( 'HTTPS://Example.com:8443/a.css?ver=2' ) );
	}

	/**
	 * The baseline records everything the later comparisons need.
	 */
	public function test_build_baseline() {
		$baseline = $this->baseline();

		$this->assertSame( self::PAGE, $baseline['url'] );
		$this->assertSame( 200, $baseline['status'] );
		$this->assertTrue( $baseline['marker_found'] );
		$this->assertCount( 4, $baseline['assets'] );
		$this->assertSame( array( 'contact-form-7', 'hours', 'cart' ), $baseline['shortcodes'] );
		$this->assertSame( '2026-10-01 10:00:00', $baseline['captured_at'] );
		$this->assertFalse( $baseline['failing'] );
	}
}
