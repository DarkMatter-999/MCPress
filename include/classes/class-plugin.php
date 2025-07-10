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
		// `Blocks::get_instance();` // Comment this out when using custom blocks registered via Blocks class.
	}
}
