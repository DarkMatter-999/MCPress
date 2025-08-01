<?php
/**
 * LLM Tool: Site Information Tool
 *
 * Provides basic WordPress site information to the LLM.
 *
 * @package MCPress
 * @subpackage Tools
 */

namespace MCPress\Tools;

use MCPress\Traits\LLM_Tool;
use MCPress\Traits\Singleton;

/**
 * LLM Tool: Site Information Tool
 *
 * Provides basic WordPress site information to the LLM.
 */
class Site_Info_Tool {
	use Singleton;
	use LLM_Tool;

	/**
	 * Constructor for the Site_Info_Tool.
	 * This ensures that when the MCPress system is ready to register tools,
	 * this trait's registration method is called.
	 *
	 * @return void
	 */
	public function __construct() {
		self::$name = 'get_site_info';
		add_action( 'mcpress_register_tools', array( $this, 'register_llm_tool' ) );
	}


	/**
	 * Defines the schema for the Site Info Tool.
	 * This schema tells the LLM how to call this tool and what arguments it expects.
	 *
	 * @return array The schema definition for the LLM tool.
	 */
	public function schema(): array {
		return array(
			'name'        => self::$name,
			'description' => 'Retrieves basic information about the current WordPress site, such as its title, description, and URL. This tool requires no arguments.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(),
				'required'   => array(),
			),
		);
	}

	/**
	 * Executes the Site Info Tool functionality.
	 * Retrieves the site title, description, and URL.
	 *
	 * @param array $arguments     Arguments passed to the tool from the LLM (expected to be empty).
	 * @return string A JSON-encoded string containing the site information.
	 */
	public function tool( array $arguments ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- arguments not used in this class.
		if ( ! function_exists( 'get_bloginfo' ) ) {
			return wp_json_encode( array( 'error' => 'WordPress core functions not loaded for site info tool.' ) );
		}

		$site_info = array(
			'site_title'       => get_bloginfo( 'name' ),
			'site_description' => get_bloginfo( 'description' ),
			'site_url'         => get_bloginfo( 'url' ),
			'wp_version'       => get_bloginfo( 'version' ),
			'charset'          => get_bloginfo( 'charset' ),
		);

		return wp_json_encode( $site_info );
	}
}
