<?php
/**
 * Autoloader
 *
 * This file provides the autoloader for the Plugin.
 *
 * @package MCPress
 **/

namespace MCPress\Helpers;

require_once MCP_PLUGIN_PATH . 'vendor/autoload.php';

spl_autoload_register(
	function ( $what ) {
		$split = explode( '\\', $what );
		if ( 'MCPress' !== $split[0] ) {
			return;
		}
		$base_dir = 'include/';

		if ( isset( $split[1] ) && 'Traits' === $split[1] ) {
			$base_dir .= 'traits/trait-';
			$split[1]  = '';
		} elseif ( isset( $split[1] ) && 'Tools' === $split[1] ) {
			$base_dir .= 'classes/tools/class-';
			$split[1]  = '';
		} elseif ( isset( $split[1] ) && 'API' === $split[1] ) {
			$base_dir .= 'classes/api/class-';
			$split[1]  = '';
		} else {
			$base_dir .= 'classes/class-';
		}

		$split[ count( $split ) - 1 ] = str_replace(
			'_',
			'-',
			strtolower( $split[ count( $split ) - 1 ] ) . '.php'
		);

		$split[0] = $base_dir;

		$file_path = implode( '', $split );

		if ( file_exists( MCP_PLUGIN_PATH . $file_path ) ) {
			include_once MCP_PLUGIN_PATH . $file_path;
		}
	}
);
