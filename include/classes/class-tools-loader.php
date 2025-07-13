<?php
/**
 * Tools Class file for managing LLM tools.
 *
 * @package MCPress
 */

namespace MCPress;

use MCPress\Tools\Site_Info_Tool;
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
		Site_Info_Tool::get_instance();
	}
}
