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

		// Remove the MCPress namespace.
		array_shift( $split );

		$base_dir    = 'include/';
		$file_prefix = '';

		// Handle specific namespace mappings.
		if ( ! empty( $split ) ) {
			switch ( $split[0] ) {
				case 'Traits':
					$base_dir   .= 'traits/';
					$file_prefix = 'trait-';
					array_shift( $split ); // Remove 'Traits' from path.
					break;
				case 'Tools':
					$base_dir   .= 'classes/tools/';
					$file_prefix = 'class-';
					array_shift( $split ); // Remove 'Tools' from path.
					break;
				case 'API':
					$base_dir   .= 'classes/api/';
					$file_prefix = 'class-';
					array_shift( $split ); // Remove 'API' from path.
					break;
				case 'Helpers':
					$base_dir   .= 'helpers/';
					$file_prefix = '';
					array_shift( $split ); // Remove 'Helpers' from path.
					break;
				default:
					$base_dir   .= 'classes/';
					$file_prefix = 'class-';
					break;
			}
		} else {
			$base_dir   .= 'classes/';
			$file_prefix = 'class-';
		}

		// Handle subdirectories (everything except the last element which is the class name).
		$class_name = array_pop( $split );

		// Convert remaining namespace parts to subdirectories.
		if ( ! empty( $split ) ) {
			$subdirs   = array_map(
				function ( $part ) {
					return strtolower( str_replace( '_', '-', $part ) );
				},
				$split
			);
			$base_dir .= implode( '/', $subdirs ) . '/';
		}

		// Convert class name to file name.
		$file_name = $file_prefix . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';

		// Build the complete file path.
		$file_path = $base_dir . $file_name;

		// Try to include the file if it exists.
		if ( file_exists( MCP_PLUGIN_PATH . $file_path ) ) {
			include_once MCP_PLUGIN_PATH . $file_path;
		}
	}
);
