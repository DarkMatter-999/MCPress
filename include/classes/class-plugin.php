<?php
/**
 * Main Plugin File for Plugin.
 *
 * @package MCPress
 */

namespace MCPress;

use MCPress\Traits\Singleton;

/**
 * Main Plugin File for the Plugin.
 */
class Plugin {


	use Singleton;

	/**
	 * Constructor for the Plugin.
	 *
	 * @return void
	 */
	public function __construct() {
		Assets::get_instance();
		Settings::get_instance();
		MCP_LLM_API::get_instance();
		Tools_Loader::get_instance();
		MCP_Server::get_instance();
	}
}
