<?php
/**
 * Tools Class file for managing LLM tools.
 *
 * @package MCPress
 */

namespace MCPress;

use MCPress\Traits\Singleton;

/**
 * Tools Class for managing LLM tools.
 * Instantiates all classes found in the 'classes/tools' directory.
 */
class Tools_Loader {

	use Singleton;

	/**
	 * Constructor for the Tools class.
	 * Scans the 'classes/tools' directory and instantiates each tool.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->auto_load_tools();
	}

	/**
	 * Automatically discovers and instantiates all tool classes in the tools directory.
	 *
	 * @return void
	 */
	private function auto_load_tools(): void {
		$tools_dir = plugin_dir_path( __FILE__ ) . 'tools/';

		if ( ! is_dir( $tools_dir ) ) {
			return;
		}

		$this->scan_directory_for_tools( $tools_dir );
	}

	/**
	 * Recursively scans a directory for tool class files and instantiates them.
	 *
	 * @param string $directory The directory to scan.
	 * @param string $base_tools_dir The base tools directory for namespace calculation.
	 * @return void
	 */
	private function scan_directory_for_tools( $directory, $base_tools_dir = null ): void {
		if ( null === $base_tools_dir ) {
			$base_tools_dir = plugin_dir_path( __FILE__ ) . 'tools/';
		}

		$files = glob( $directory . '*' );

		foreach ( $files as $file ) {
			if ( is_dir( $file ) ) {
				// Recursively scan subdirectories.
				$this->scan_directory_for_tools( $file . '/', $base_tools_dir );
			} elseif ( is_file( $file ) && str_ends_with( $file, '.php' ) && str_contains( $file, 'class-' ) ) {
				$this->load_tool_from_file( $file, $base_tools_dir );
			}
		}
	}

	/**
	 * Loads and instantiates a tool class using the autoloader.
	 *
	 * @param string $file_path The path to the tool class file.
	 * @param string $base_tools_dir The base tools directory for namespace calculation.
	 * @return void
	 */
	private function load_tool_from_file( $file_path, $base_tools_dir ): void {
		$filename = basename( $file_path, '.php' );

		// Convert from 'class-tool-name-tool' to 'Tool_Name_Tool'.
		$class_name = str_replace( 'class-', '', $filename );
		$class_name = str_replace( '-', '_', $class_name );
		$class_name = implode( '_', array_map( 'ucfirst', explode( '_', $class_name ) ) );

		$relative_path   = str_replace( $base_tools_dir, '', dirname( $file_path ) );
		$namespace_parts = array();

		if ( ! empty( $relative_path ) && '.' !== $relative_path ) {
			$path_parts = explode( '/', trim( $relative_path, '/' ) );
			foreach ( $path_parts as $part ) {
				if ( ! empty( $part ) ) {
					$namespace_part    = str_replace( '-', '_', $part );
					$namespace_part    = implode( '_', array_map( 'ucfirst', explode( '_', $namespace_part ) ) );
					$namespace_parts[] = $namespace_part;
				}
			}
		}

		// Build full class name with namespace including subdirectories.
		$full_namespace = '\\MCPress\\Tools\\';
		if ( ! empty( $namespace_parts ) ) {
			$full_namespace .= implode( '\\', $namespace_parts ) . '\\';
		}
		$full_class_name = $full_namespace . $class_name;

		if ( class_exists( $full_class_name ) ) {
			if ( method_exists( $full_class_name, 'get_instance' ) ) {
				$full_class_name::get_instance();
			}
		}
	}
}
