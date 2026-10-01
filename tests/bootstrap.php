<?php
/**
 * PHPUnit bootstrap. Page_Analyzer is pure PHP, so WordPress is not loaded.
 *
 * @package Pomnex\UpdateCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Stands in for WordPress in unit tests.
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-page-analyzer.php';
