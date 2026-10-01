<?php
/**
 * Plugin Name:       Pomnex Update Check
 * Plugin URI:        https://github.com/Pomnex/pomnex-update-check
 * Description:       Checks your important pages after every update and emails you if something broke, so you know before your customers tell you.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Pomnex
 * Author URI:        https://pomnex.com
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       pomnex-update-check
 * Domain Path:       /languages
 */

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

defined( 'ABSPATH' ) || exit;

define( 'POMNEX_UC_VERSION', '0.1.0' );
define( 'POMNEX_UC_DB_VERSION', '1' );
define( 'POMNEX_UC_FILE', __FILE__ );
define( 'POMNEX_UC_DIR', plugin_dir_path( __FILE__ ) );
define( 'POMNEX_UC_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	/**
	 * Maps Pomnex\UpdateCheck\Class_Name to includes/class-class-name.php.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	static function ( $class_name ) {
		$prefix = 'Pomnex\\UpdateCheck\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = POMNEX_UC_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'Pomnex\\UpdateCheck\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Pomnex\\UpdateCheck\\Plugin', 'deactivate' ) );

Pomnex\UpdateCheck\Plugin::instance()->init();
