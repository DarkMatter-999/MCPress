<?php
/**
 * Plugin Name:       MCPress
 * Plugin URI:        https://github.com/DarkMatter-999/MCPress
 * Description:       Model Context Protocol (MCP) Plugin for WordPress. Automate your tasks easily
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            DarkMatter-999, Adi-ty, USERSATOSHI, yashjawale
 * Author URI:
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mcpress
 * Domain Path:       /languages
 *
 * @category Plugin
 * @package  MCPress
 * @author   DarkMatter-999, Adi-ty, USERSATOSHI, yashjawale <>
 * @license  GPL v2 or later <https://www.gnu.org/licenses/gpl-2.0.html>
 * @link     https://github.com/DarkMatter-999/MCPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Plugin base path and URL.
 */
define( 'MCP_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'MCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once MCP_PLUGIN_PATH . 'include/helpers/autoloader.php';

use MCPress\Plugin;

Plugin::get_instance();
