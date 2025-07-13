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

	const OPENAI_API_ENDPOINT = 'mcpress_openai_endpoint';
	const OPENAI_API_KEY      = 'mcpress_openai_api_key';

	/**
	 * Constructor for the LLM API class
	 *
	 * @return void
	 */
	public function __construct() {
	}
}
