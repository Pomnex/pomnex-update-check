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
 * Makes the HTTP requests for one page and its assets.
 *
 * All requests are loopback requests to the site's own host. Redirects are
 * followed here, one hop at a time, so a redirect to another host is never
 * followed and no request ever leaves the site.
 */
final class Page_Checker {

	/**
	 * Timeout for page requests, in seconds.
	 */
	const PAGE_TIMEOUT = 20;

	/**
	 * Timeout for asset requests, in seconds.
	 */
	const ASSET_TIMEOUT = 5;

	/**
	 * Maximum redirects followed per request.
	 */
	const MAX_REDIRECTS = 3;

	/**
	 * Lowercase host of the home URL.
	 *
	 * @var string
	 */
	private $home_host;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Loads a page the way a logged-out visitor sees it.
	 *
	 * @param string $url Page URL.
	 * @return array {
	 *     @type string|null $error            Error message when the request failed.
	 *     @type int         $status           Final HTTP status.
	 *     @type string      $body             Response body.
	 *     @type float       $time             Seconds taken, including redirects.
	 *     @type int         $size             Body size in bytes.
	 *     @type string      $url              Final URL after redirects.
	 *     @type string|null $redirect_offsite Host of a redirect that was not followed.
	 * }
	 */
	public function fetch_page( $url ) {
		$request_url = add_query_arg( 'pomnex_uc', strtolower( wp_generate_password( 12, false, false ) ), $url );
		$started     = microtime( true );
		$result      = $this->request(
			'GET',
			$request_url,
			array(
				'timeout' => self::PAGE_TIMEOUT,
				'headers' => array(
					'Cache-Control' => 'no-cache',
					'Pragma'        => 'no-cache',
				),
			)
		);
		$time        = microtime( true ) - $started;

		if ( is_wp_error( $result['response'] ) ) {
			return array(
				'error'            => $result['response']->get_error_message(),
				'status'           => 0,
				'body'             => '',
				'time'             => $time,
				'size'             => 0,
				'url'              => $result['url'],
				'redirect_offsite' => null,
			);
		}

		$body = (string) wp_remote_retrieve_body( $result['response'] );

		return array(
			'error'            => null,
			'status'           => (int) wp_remote_retrieve_response_code( $result['response'] ),
			'body'             => $body,
			'time'             => $time,
			'size'             => strlen( $body ),
			'url'              => remove_query_arg( 'pomnex_uc', $result['url'] ),
			'redirect_offsite' => $result['offsite'],
		);
	}

	/**
	 * Checks that a same-host stylesheet or script loads.
	 *
	 * Sends HEAD and falls back to GET when the server answers 405.
	 *
	 * @param string $url Asset URL.
	 * @return array `array( 'status' => int )` or `array( 'error' => string )`.
	 */
	public function check_asset( $url ) {
		if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $this->home_host ) {
			return array( 'skipped' => true );
		}

		$args   = array( 'timeout' => self::ASSET_TIMEOUT );
		$result = $this->request( 'HEAD', $url, $args );

		if ( ! is_wp_error( $result['response'] ) && 405 === (int) wp_remote_retrieve_response_code( $result['response'] ) ) {
			$args['limit_response_size'] = 65536;
			$result                      = $this->request( 'GET', $url, $args );
		}

		if ( is_wp_error( $result['response'] ) ) {
			return array( 'error' => $result['response']->get_error_message() );
		}

		return array( 'status' => (int) wp_remote_retrieve_response_code( $result['response'] ) );
	}

	/**
	 * Sends a request and follows same-host redirects.
	 *
	 * @param string $method `GET` or `HEAD`.
	 * @param string $url    URL on the site's own host.
	 * @param array  $args   Extra request arguments.
	 * @return array {
	 *     @type array|\WP_Error $response Final response or error.
	 *     @type string          $url      Final URL.
	 *     @type string|null     $offsite  Host of a redirect that was not followed.
	 * }
	 */
	private function request( $method, $url, array $args ) {
		$args = array_merge(
			array(
				'method'      => $method,
				'redirection' => 0,
				'cookies'     => array(),
				// Same rule core uses for its own loopback requests.
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
			),
			$args
		);

		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $this->home_host ) {
				return array(
					'response' => new \WP_Error( 'pomnex_uc_offsite', __( 'The URL is not on this site.', 'pomnex-update-check' ) ),
					'url'      => $url,
					'offsite'  => null,
				);
			}

			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				return array(
					'response' => $response,
					'url'      => $url,
					'offsite'  => null,
				);
			}

			$status   = (int) wp_remote_retrieve_response_code( $response );
			$location = wp_remote_retrieve_header( $response, 'location' );
			$location = is_array( $location ) ? (string) end( $location ) : (string) $location;

			if ( ! in_array( $status, array( 301, 302, 303, 307, 308 ), true ) || '' === $location ) {
				return array(
					'response' => $response,
					'url'      => $url,
					'offsite'  => null,
				);
			}

			$next = \WP_Http::make_absolute_url( $location, $url );
			$host = strtolower( (string) wp_parse_url( $next, PHP_URL_HOST ) );

			if ( $host !== $this->home_host || ! in_array( strtolower( (string) wp_parse_url( $next, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ) ) {
				// Never follow a redirect to another host. Report the redirect itself.
				return array(
					'response' => $response,
					'url'      => $url,
					'offsite'  => '' === $host ? $next : $host,
				);
			}

			if ( $hop >= self::MAX_REDIRECTS ) {
				return array(
					'response' => new \WP_Error( 'http_request_failed', __( 'Too many redirects.', 'pomnex-update-check' ) ),
					'url'      => $url,
					'offsite'  => null,
				);
			}

			$url = $next;
		}

		return array(
			'response' => new \WP_Error( 'http_request_failed', __( 'Too many redirects.', 'pomnex-update-check' ) ),
			'url'      => $url,
			'offsite'  => null,
		);
	}
}
