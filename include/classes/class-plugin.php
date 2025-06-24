<?php
/**
 * Main Plugin File for Plugin.
 *
 * @package DarkMatter_Package
 */

namespace DarkMatter_Plugin;

use DarkMatter_Plugin\Traits\Singleton;

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
