<?php
/**
 * Main Class file for LLM Integration for the plugin.
 *
 * @package MCPress
 */

namespace MCPress;

use MCPress\Traits\Singleton;

/**
 * Main Class file for LLM Integration for the plugin.
 */
class MCP_LLM_API {
	use Singleton;

	/**
	 * All tools which will be used by the LLMavailable_tools.
	 *
	 * @var array
	 */
	public $tools = array();

	/**
	 * Schemas for all tools.
	 *
	 * @var array
	 */
	public $schemas = array();

	/**
	 * Constructor for the LLM API class
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register all the tools for the LLM.
	 */
	public function register() {
		do_action( 'mcpress_register_tools' );

		$this->tools = apply_filters( 'mcpress_available_tools', $this->tools );

		$this->schemas = apply_filters( 'mcpress_tools_schema', $this->schemas );
	}
}
