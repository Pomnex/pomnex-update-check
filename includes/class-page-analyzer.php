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
 * Turns one page response plus its baseline into a list of findings.
 *
 * This class is pure PHP. It makes no WordPress calls, so it can be unit
 * tested without loading WordPress. Everything it needs from WordPress
 * (home URL, translated strings, thresholds) is passed to the constructor.
 *
 * A finding is an array with the keys `code`, `severity` and `data`. The
 * human-readable text for each code is produced by Check_Runner, so stored
 * results can be shown in whatever language the reader uses.
 */
final class Page_Analyzer {

	/**
	 * Result and severity: nothing wrong.
	 */
	const PASS = 'pass';

	/**
	 * Result and severity: something changed that may be a problem.
	 */
	const WARNING = 'warning';

	/**
	 * Result and severity: the page is broken.
	 */
	const FAIL = 'fail';

	/**
	 * Result and severity: the page could not be loaded at all.
	 */
	const COULD_NOT_CHECK = 'could_not_check';

	/**
	 * Severity for notes that never change the result.
	 */
	const INFO = 'info';

	/**
	 * Lowercase host of the site's home URL.
	 *
	 * @var string
	 */
	private $home_host;

	/**
	 * Critical-error sentences to look for (English plus translation).
	 *
	 * @var string[]
	 */
	private $critical_strings;

	/**
	 * Comparison thresholds.
	 *
	 * @var array{slow_factor: float, slow_min_seconds: float, size_ratio: float}
	 */
	private $thresholds;

	/**
	 * Constructor.
	 *
	 * @param string   $home_url         The site's home URL.
	 * @param string[] $critical_strings Critical-error sentences to match.
	 * @param array    $thresholds       Optional. Keys `slow_factor`, `slow_min_seconds`, `size_ratio`.
	 */
	public function __construct( $home_url, array $critical_strings, array $thresholds = array() ) {
		$this->home_host        = self::host_of( $home_url );
		$this->critical_strings = array_values( array_unique( array_filter( array_map( 'strval', $critical_strings ) ) ) );
		$this->thresholds       = array(
			'slow_factor'      => isset( $thresholds['slow_factor'] ) ? (float) $thresholds['slow_factor'] : 2.0,
			'slow_min_seconds' => isset( $thresholds['slow_min_seconds'] ) ? (float) $thresholds['slow_min_seconds'] : 1.0,
			'size_ratio'       => isset( $thresholds['size_ratio'] ) ? (float) $thresholds['size_ratio'] : 0.5,
		);
	}

	/**
	 * Runs every check against one page response.
	 *
	 * @param array      $response       Page response. Keys: `error` (string|null), `status` (int),
	 *                                   `body` (string), `time` (float seconds), `size` (int bytes).
	 * @param array|null $baseline       Baseline for this page, or null when there is none.
	 * @param string     $marker         The page's marker, or an empty string.
	 * @param string[]   $shortcode_tags Shortcode tags registered now. Used only when there is no baseline.
	 * @param array|null $asset_results  Optional. Normalised asset URL => result. A result is
	 *                                   `array( 'status' => int )`, `array( 'error' => string )` or
	 *                                   `array( 'skipped' => true )`. Null skips the asset checks.
	 * @return array[] List of findings.
	 */
	public function analyze( array $response, $baseline, $marker, array $shortcode_tags = array(), $asset_results = null ) {
		if ( ! empty( $response['error'] ) ) {
			return array( self::finding( 'request_error', self::COULD_NOT_CHECK, array( 'message' => (string) $response['error'] ) ) );
		}

		$baseline = is_array( $baseline ) ? $baseline : null;
		$body     = isset( $response['body'] ) ? (string) $response['body'] : '';
		$status   = isset( $response['status'] ) ? (int) $response['status'] : 0;
		$findings = array();

		// Check 2: HTTP status.
		$finding = $this->check_status( $status, $baseline );
		if ( $finding ) {
			$findings[] = $finding;
		}

		if ( ! empty( $response['redirect_offsite'] ) ) {
			$findings[] = self::finding( 'redirect_offsite', self::INFO, array( 'host' => (string) $response['redirect_offsite'] ) );
		}

		// Check 3: WordPress critical-error page.
		if ( $this->has_critical_error( $body ) ) {
			$findings[] = self::finding( 'critical_error', self::FAIL );
		}

		// Check 4: visible PHP errors.
		foreach ( $this->find_php_errors( $body ) as $php_error ) {
			$severity   = in_array( $php_error['type'], array( 'Fatal error', 'Parse error' ), true ) ? self::FAIL : self::WARNING;
			$findings[] = self::finding( 'php_error', $severity, $php_error );
		}

		// Check 5: marker.
		$finding = $this->check_marker( $body, (string) $marker, $baseline );
		if ( $finding ) {
			$findings[] = $finding;
		}

		// Check 6: unrendered shortcodes.
		$tags = ( null !== $baseline && isset( $baseline['shortcodes'] ) ) ? (array) $baseline['shortcodes'] : $shortcode_tags;
		$raw  = $this->find_unrendered_shortcodes( $body, $tags );
		if ( $raw ) {
			$findings[] = self::finding( 'shortcode_unrendered', self::FAIL, array( 'tags' => $raw ) );
		}

		// Check 7: assets.
		if ( is_array( $asset_results ) ) {
			$findings = array_merge( $findings, $this->check_assets( $asset_results, $baseline ) );
		}

		if ( null !== $baseline ) {
			// Check 8: slow response.
			$finding = $this->check_speed( isset( $response['time'] ) ? (float) $response['time'] : 0.0, $baseline );
			if ( $finding ) {
				$findings[] = $finding;
			}

			// Check 9: size drop.
			$size    = isset( $response['size'] ) ? (int) $response['size'] : strlen( $body );
			$finding = $this->check_size( $size, $baseline );
			if ( $finding ) {
				$findings[] = $finding;
			}
		}

		return $findings;
	}

	/**
	 * Reduces a page's findings to one result.
	 *
	 * @param array[] $findings Findings from analyze().
	 * @return string One of the PASS, WARNING, FAIL or COULD_NOT_CHECK constants.
	 */
	public static function page_result( array $findings ) {
		$severities = array_column( $findings, 'severity' );

		foreach ( array( self::FAIL, self::COULD_NOT_CHECK, self::WARNING ) as $severity ) {
			if ( in_array( $severity, $severities, true ) ) {
				return $severity;
			}
		}

		return self::PASS;
	}

	/**
	 * Reduces the per-page results of a run to one overall result.
	 *
	 * Any Fail wins, then any Warning. When every page could not be checked
	 * the result is Could not check. When only some pages could not be
	 * checked the result is Warning, so a gap is never reported as a pass.
	 *
	 * @param string[] $page_results Per-page results.
	 * @return string One of the PASS, WARNING, FAIL or COULD_NOT_CHECK constants.
	 */
	public static function overall_result( array $page_results ) {
		if ( empty( $page_results ) ) {
			return self::COULD_NOT_CHECK;
		}

		if ( in_array( self::FAIL, $page_results, true ) ) {
			return self::FAIL;
		}

		if ( in_array( self::WARNING, $page_results, true ) ) {
			return self::WARNING;
		}

		$unchecked = count( array_keys( $page_results, self::COULD_NOT_CHECK, true ) );

		if ( count( $page_results ) === $unchecked ) {
			return self::COULD_NOT_CHECK;
		}

		return $unchecked > 0 ? self::WARNING : self::PASS;
	}

	/**
	 * Builds the baseline record for one page.
	 *
	 * @param string   $url            The page URL the baseline belongs to.
	 * @param string   $marker         The page's marker when captured.
	 * @param array    $response       Page response (see analyze()).
	 * @param string[] $asset_urls     Normalised same-host asset URLs found on the page.
	 * @param string[] $shortcode_tags Shortcode tags registered at capture time.
	 * @param string   $captured_at    Capture time, UTC, `Y-m-d H:i:s`.
	 * @param bool     $failing        Whether the page failed when captured.
	 * @return array Baseline record.
	 */
	public function build_baseline( $url, $marker, array $response, array $asset_urls, array $shortcode_tags, $captured_at, $failing ) {
		$body = isset( $response['body'] ) ? (string) $response['body'] : '';

		return array(
			'url'          => (string) $url,
			'marker'       => (string) $marker,
			'status'       => isset( $response['status'] ) ? (int) $response['status'] : 0,
			'size'         => isset( $response['size'] ) ? (int) $response['size'] : strlen( $body ),
			'time'         => isset( $response['time'] ) ? round( (float) $response['time'], 3 ) : 0.0,
			'marker_found' => '' !== (string) $marker && $this->marker_found( $body, (string) $marker ),
			'assets'       => array_values( array_unique( array_map( 'strval', $asset_urls ) ) ),
			'shortcodes'   => array_values( array_unique( array_map( 'strval', $shortcode_tags ) ) ),
			'captured_at'  => (string) $captured_at,
			'failing'      => (bool) $failing,
		);
	}

	/**
	 * Checks whether the marker is present.
	 *
	 * Matching is case-insensitive, against the HTML after entity decoding.
	 * Runs of whitespace (including non-breaking spaces) count as one space
	 * on both sides, so "Place&nbsp;order" matches "Place order".
	 *
	 * @param string $html   Page HTML.
	 * @param string $marker Marker text or HTML fragment.
	 * @return bool
	 */
	public function marker_found( $html, $marker ) {
		$needle = self::normalize_text( $marker );

		if ( '' === $needle ) {
			return true;
		}

		$haystack = self::normalize_text( $html );

		if ( function_exists( 'mb_stripos' ) ) {
			return false !== mb_stripos( $haystack, $needle, 0, 'UTF-8' );
		}

		return false !== stripos( $haystack, $needle );
	}

	/**
	 * Detects the WordPress critical-error page.
	 *
	 * @param string $html Page HTML.
	 * @return bool
	 */
	public function has_critical_error( $html ) {
		if ( false === strpos( $html, 'wp-die-message' ) ) {
			return false;
		}

		$decoded = self::normalize_text( $html );

		foreach ( $this->critical_strings as $sentence ) {
			$sentence = self::normalize_text( $sentence );

			if ( '' !== $sentence && false !== stripos( $decoded, $sentence ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Finds PHP errors printed into the page.
	 *
	 * Matches PHP's HTML format (`<b>Warning</b>:`) and its plain format
	 * (`Warning: message in /path/file.php on line 12`).
	 *
	 * @param string $html Page HTML.
	 * @return array[] List of `array( 'type' => string, 'excerpt' => string )`, at most 5.
	 */
	public function find_php_errors( $html ) {
		$types    = 'Fatal error|Parse error|Warning|Notice|Deprecated';
		$patterns = array(
			'#<b>(' . $types . ')</b>:[^\n]{0,300}#',
			'#(?<![\w-])(?:PHP )?(' . $types . '):\s[^\n]{1,500}?\sin\s(?:/|[A-Za-z]:[\\\\/])[^\n]{1,300}?\son line\s\d+#',
		);

		$found = array();

		foreach ( $patterns as $pattern ) {
			if ( ! preg_match_all( $pattern, $html, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $matches[0] as $index => $match ) {
				$offset = (int) $match[1];

				// The HTML format also matches the plain pattern; count each error once.
				if ( isset( $found[ $offset ] ) || $this->overlaps( $found, $offset ) ) {
					continue;
				}

				$found[ $offset ] = array(
					'type'    => $matches[1][ $index ][0],
					'excerpt' => self::excerpt( $match[0] ),
					'end'     => $offset + strlen( $match[0] ),
				);
			}
		}

		ksort( $found );

		$errors = array();
		foreach ( array_slice( $found, 0, 5 ) as $error ) {
			unset( $error['end'] );
			$errors[] = $error;
		}

		return $errors;
	}

	/**
	 * Finds shortcode tags that appear unrendered in the visible text.
	 *
	 * The contents of script, style, textarea, code and pre elements, HTML
	 * comments and the tags themselves are removed first, so attributes and
	 * code samples never match.
	 *
	 * @param string   $html Page HTML.
	 * @param string[] $tags Shortcode tags to look for.
	 * @return string[] Tags found, in the order given.
	 */
	public function find_unrendered_shortcodes( $html, array $tags ) {
		if ( empty( $tags ) || false === strpos( $html, '[' ) ) {
			return array();
		}

		$text = self::visible_text( $html );
		$raw  = array();

		foreach ( $tags as $tag ) {
			$tag = (string) $tag;

			if ( '' === $tag ) {
				continue;
			}

			if ( preg_match( '#\[' . preg_quote( $tag, '#' ) . '(?=[\s\]/]|$)#', $text ) ) {
				$raw[] = $tag;
			}
		}

		return array_values( array_unique( $raw ) );
	}

	/**
	 * Extracts same-host stylesheet and script URLs from a page.
	 *
	 * Uses DOMDocument when available and falls back to regular expressions.
	 *
	 * @param string $html     Page HTML.
	 * @param string $page_url URL of the page, used to resolve relative URLs.
	 * @return array<string, string> Normalised URL => absolute URL to request.
	 */
	public function extract_assets( $html, $page_url ) {
		if ( class_exists( '\DOMDocument' ) && function_exists( 'libxml_use_internal_errors' ) ) {
			$raw_urls = $this->extract_asset_urls_with_dom( $html );
		} else {
			$raw_urls = $this->extract_asset_urls_with_regex( $html );
		}

		return $this->filter_assets( $raw_urls, $page_url );
	}

	/**
	 * Extracts same-host asset URLs with regular expressions only.
	 *
	 * Public so the fallback used without ext-dom can be tested directly.
	 *
	 * @param string $html     Page HTML.
	 * @param string $page_url URL of the page.
	 * @return array<string, string> Normalised URL => absolute URL to request.
	 */
	public function extract_assets_without_dom( $html, $page_url ) {
		return $this->filter_assets( $this->extract_asset_urls_with_regex( $html ), $page_url );
	}

	/**
	 * Normalises an asset URL for comparison: scheme, host, port and path.
	 *
	 * The query string (including `ver`) and fragment are dropped.
	 *
	 * @param string $url Absolute URL.
	 * @return string Normalised URL, or an empty string when it cannot be parsed.
	 */
	public static function normalize_asset_url( $url ) {
		$parts = self::parse( $url );

		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'http';
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';

		return $scheme . '://' . strtolower( $parts['host'] ) . $port . $path;
	}

	/**
	 * Resolves a URL found in a page against the page URL.
	 *
	 * @param string $url  URL as written in the page.
	 * @param string $base Absolute URL of the page.
	 * @return string Absolute http(s) URL without fragment, or an empty string.
	 */
	public static function resolve_url( $url, $base ) {
		$url = trim( (string) $url );

		if ( '' === $url || '#' === $url[0] ) {
			return '';
		}

		$base_parts = self::parse( $base );

		if ( empty( $base_parts['host'] ) ) {
			return '';
		}

		$scheme = isset( $base_parts['scheme'] ) ? strtolower( $base_parts['scheme'] ) : 'http';
		$origin = $scheme . '://' . $base_parts['host'] . ( isset( $base_parts['port'] ) ? ':' . (int) $base_parts['port'] : '' );

		if ( 0 === strpos( $url, '//' ) ) {
			$url = $scheme . ':' . $url;
		} elseif ( preg_match( '#^([a-z][a-z0-9+.-]*):#i', $url, $match ) ) {
			if ( ! in_array( strtolower( $match[1] ), array( 'http', 'https' ), true ) ) {
				return '';
			}
		} elseif ( '/' === $url[0] ) {
			$url = $origin . $url;
		} else {
			$base_path = isset( $base_parts['path'] ) ? $base_parts['path'] : '/';
			$directory = substr( $base_path, 0, (int) strrpos( $base_path, '/' ) + 1 );
			$url       = $origin . ( '' === $directory ? '/' : $directory ) . $url;
		}

		$hash = strpos( $url, '#' );

		return false === $hash ? $url : substr( $url, 0, $hash );
	}

	/**
	 * Returns the lowercase host of a URL.
	 *
	 * @param string $url URL.
	 * @return string Host, or an empty string.
	 */
	public static function host_of( $url ) {
		$parts = self::parse( $url );

		return isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
	}

	/**
	 * Check 2: final HTTP status.
	 *
	 * @param int        $status   Final status code.
	 * @param array|null $baseline Baseline or null.
	 * @return array|null Finding or null.
	 */
	private function check_status( $status, $baseline ) {
		$baseline_status = null !== $baseline && isset( $baseline['status'] ) ? (int) $baseline['status'] : 0;

		if ( $status >= 400 || $status <= 0 ) {
			return self::finding(
				'http_status',
				self::FAIL,
				array(
					'status'          => $status,
					'baseline_status' => $baseline_status,
				)
			);
		}

		if ( $baseline_status >= 200 && $baseline_status < 300 && $status !== $baseline_status ) {
			return self::finding(
				'http_status',
				self::FAIL,
				array(
					'status'          => $status,
					'baseline_status' => $baseline_status,
				)
			);
		}

		return null;
	}

	/**
	 * Check 5: marker.
	 *
	 * A missing marker fails when the baseline found it, or when there is no
	 * baseline to compare with. When the baseline did not find it either,
	 * the marker is probably mistyped, so it is reported as a warning.
	 *
	 * @param string     $body     Page HTML.
	 * @param string     $marker   Marker.
	 * @param array|null $baseline Baseline or null.
	 * @return array|null Finding or null.
	 */
	private function check_marker( $body, $marker, $baseline ) {
		if ( '' === trim( $marker ) || $this->marker_found( $body, $marker ) ) {
			return null;
		}

		$baseline_knows = null !== $baseline && isset( $baseline['marker'] ) && (string) $baseline['marker'] === $marker;

		if ( $baseline_knows && empty( $baseline['marker_found'] ) ) {
			return self::finding( 'marker_never_found', self::WARNING, array( 'marker' => $marker ) );
		}

		return self::finding( 'marker_missing', self::FAIL, array( 'marker' => $marker ) );
	}

	/**
	 * Check 7: assets.
	 *
	 * @param array      $asset_results Normalised URL => result.
	 * @param array|null $baseline      Baseline or null.
	 * @return array[] Findings.
	 */
	private function check_assets( array $asset_results, $baseline ) {
		$findings  = array();
		$unchecked = array();

		foreach ( $asset_results as $url => $result ) {
			if ( isset( $result['status'] ) && (int) $result['status'] >= 400 ) {
				$findings[] = self::finding(
					'asset_broken',
					self::FAIL,
					array(
						'url'    => (string) $url,
						'status' => (int) $result['status'],
					)
				);
			} elseif ( ! empty( $result['skipped'] ) || ! empty( $result['error'] ) ) {
				$unchecked[] = (string) $url;
			}
		}

		if ( null !== $baseline && ! empty( $baseline['assets'] ) ) {
			$removed = array_values( array_diff( (array) $baseline['assets'], array_keys( $asset_results ) ) );

			if ( $removed ) {
				$findings[] = self::finding( 'asset_removed', self::WARNING, array( 'urls' => $removed ) );
			}
		}

		if ( $unchecked ) {
			$findings[] = self::finding(
				'assets_not_checked',
				self::INFO,
				array(
					'count' => count( $unchecked ),
					'urls'  => array_slice( $unchecked, 0, 10 ),
				)
			);
		}

		return $findings;
	}

	/**
	 * Check 8: slow response. Both thresholds must be exceeded.
	 *
	 * @param float $time     Response time in seconds.
	 * @param array $baseline Baseline.
	 * @return array|null Finding or null.
	 */
	private function check_speed( $time, array $baseline ) {
		$baseline_time = isset( $baseline['time'] ) ? (float) $baseline['time'] : 0.0;

		if ( $baseline_time <= 0 ) {
			return null;
		}

		$slower_by = $time - $baseline_time;

		if ( $time > $baseline_time * $this->thresholds['slow_factor'] && $slower_by >= $this->thresholds['slow_min_seconds'] ) {
			return self::finding(
				'slow',
				self::WARNING,
				array(
					'time'          => round( $time, 2 ),
					'baseline_time' => round( $baseline_time, 2 ),
				)
			);
		}

		return null;
	}

	/**
	 * Check 9: size drop.
	 *
	 * @param int   $size     Body size in bytes.
	 * @param array $baseline Baseline.
	 * @return array|null Finding or null.
	 */
	private function check_size( $size, array $baseline ) {
		$baseline_size = isset( $baseline['size'] ) ? (int) $baseline['size'] : 0;

		if ( $baseline_size <= 0 || $size >= $baseline_size * $this->thresholds['size_ratio'] ) {
			return null;
		}

		return self::finding(
			'size_drop',
			self::WARNING,
			array(
				'size'          => $size,
				'baseline_size' => $baseline_size,
			)
		);
	}

	/**
	 * Collects stylesheet and script URLs with DOMDocument.
	 *
	 * @param string $html Page HTML.
	 * @return string[] URLs as written in the page.
	 */
	private function extract_asset_urls_with_dom( $html ) {
		if ( '' === trim( $html ) ) {
			return array();
		}

		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument();
		$loaded   = $document->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return $this->extract_asset_urls_with_regex( $html );
		}

		$urls = array();

		foreach ( $document->getElementsByTagName( 'link' ) as $link ) {
			$rel = preg_split( '#\s+#', strtolower( trim( $link->getAttribute( 'rel' ) ) ) );

			if ( in_array( 'stylesheet', (array) $rel, true ) && '' !== $link->getAttribute( 'href' ) ) {
				$urls[] = $link->getAttribute( 'href' );
			}
		}

		foreach ( $document->getElementsByTagName( 'script' ) as $script ) {
			if ( '' !== $script->getAttribute( 'src' ) ) {
				$urls[] = $script->getAttribute( 'src' );
			}
		}

		return $urls;
	}

	/**
	 * Collects stylesheet and script URLs with regular expressions.
	 *
	 * @param string $html Page HTML.
	 * @return string[] URLs as written in the page, entities decoded.
	 */
	private function extract_asset_urls_with_regex( $html ) {
		$urls = array();

		if ( preg_match_all( '#<link\b[^>]*>#i', $html, $links ) ) {
			foreach ( $links[0] as $tag ) {
				$rel  = preg_split( '#\s+#', strtolower( trim( self::attribute( $tag, 'rel' ) ) ) );
				$href = self::attribute( $tag, 'href' );

				if ( in_array( 'stylesheet', (array) $rel, true ) && '' !== $href ) {
					$urls[] = $href;
				}
			}
		}

		if ( preg_match_all( '#<script\b[^>]*>#i', $html, $scripts ) ) {
			foreach ( $scripts[0] as $tag ) {
				$src = self::attribute( $tag, 'src' );

				if ( '' !== $src ) {
					$urls[] = $src;
				}
			}
		}

		return $urls;
	}

	/**
	 * Resolves raw asset URLs and keeps the same-host ones.
	 *
	 * @param string[] $raw_urls URLs as written in the page.
	 * @param string   $page_url URL of the page.
	 * @return array<string, string> Normalised URL => absolute URL.
	 */
	private function filter_assets( array $raw_urls, $page_url ) {
		$assets = array();

		foreach ( $raw_urls as $raw_url ) {
			$absolute = self::resolve_url( $raw_url, $page_url );

			if ( '' === $absolute || self::host_of( $absolute ) !== $this->home_host ) {
				continue;
			}

			$normalized = self::normalize_asset_url( $absolute );

			if ( '' !== $normalized && ! isset( $assets[ $normalized ] ) ) {
				$assets[ $normalized ] = $absolute;
			}
		}

		return $assets;
	}

	/**
	 * Checks whether an offset falls inside an error already found.
	 *
	 * @param array $found  Errors found so far, keyed by start offset.
	 * @param int   $offset Offset to test.
	 * @return bool
	 */
	private function overlaps( array $found, $offset ) {
		foreach ( $found as $start => $error ) {
			if ( $offset >= $start && $offset < $error['end'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Builds a finding.
	 *
	 * @param string $code     Finding code.
	 * @param string $severity Severity constant.
	 * @param array  $data     Optional. Details for the message.
	 * @return array Finding.
	 */
	private static function finding( $code, $severity, array $data = array() ) {
		return array(
			'code'     => $code,
			'severity' => $severity,
			'data'     => $data,
		);
	}

	/**
	 * Decodes entities and collapses whitespace.
	 *
	 * @param string $text Text or HTML.
	 * @return string
	 */
	private static function normalize_text( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text );

		return trim( (string) preg_replace( '#\s+#u', ' ', $text ) );
	}

	/**
	 * Returns the text a visitor sees, without code samples or markup.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	private static function visible_text( $html ) {
		$text = (string) preg_replace( '#<!--.*?-->#s', ' ', $html );
		$text = (string) preg_replace( '#<(script|style|textarea|code|pre)\b[^>]*>.*?</\1\s*>#is', ' ', $text );

		return (string) preg_replace( '#<[^>]*>#', ' ', $text );
	}

	/**
	 * Turns a matched error into a short plain-text excerpt.
	 *
	 * @param string $html Matched HTML.
	 * @return string
	 */
	private static function excerpt( $html ) {
		$text = trim( (string) preg_replace( '#\s+#', ' ', html_entity_decode( (string) preg_replace( '#<[^>]*>#', '', $html ), ENT_QUOTES, 'UTF-8' ) ) );

		return strlen( $text ) > 200 ? substr( $text, 0, 197 ) . '...' : $text;
	}

	/**
	 * Reads one attribute from an HTML start tag.
	 *
	 * @param string $tag  Start tag.
	 * @param string $name Attribute name.
	 * @return string Decoded value, or an empty string.
	 */
	private static function attribute( $tag, $name ) {
		if ( ! preg_match( '#\s' . preg_quote( $name, '#' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $tag, $match ) ) {
			return '';
		}

		$value = '';
		foreach ( array( 3, 2, 1 ) as $group ) {
			if ( isset( $match[ $group ] ) && '' !== $match[ $group ] ) {
				$value = $match[ $group ];
				break;
			}
		}

		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Parses a URL. Pure PHP, so wp_parse_url() is not available here.
	 *
	 * @param string $url URL.
	 * @return array URL parts, empty on failure.
	 */
	private static function parse( $url ) {
		$parts = parse_url( (string) $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure class without WordPress; inputs are absolute URLs.

		return is_array( $parts ) ? $parts : array();
	}
}
