<?php
/**
 * Main Assets Class File
 *
 * Main Theme Asset class file for the Plugin. This class enqueues the necessary scripts and styles.
 *
 * @package DarkMatter_Package
 **/

namespace DarkMatter_Plugin;

use DarkMatter_Plugin\Traits\Singleton;

/**
 * Main Assets Class File
 *
 * Main Theme Asset class file for the Plugin. This class enqueues the necessary scripts and styles.
 *
 * @since 1.0.0
 **/
class Assets {

	use Singleton;

	/**
	 * Constructor for the Assets class.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_block_assets' ) );
		add_action(
			'enqueue_block_editor_assets',
			array(
				$this,
				'enqueue_block_editor_assets',
			)
		);
	}

	/**
	 * Enqueues styles and scripts for the theme.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$style_asset = include DMP_PLUGIN_PATH . 'assets/build/css/main.asset.php';
		wp_enqueue_style(
			'main-css',
			DMP_PLUGIN_PATH . 'assets/build/css/main.css',
			$style_asset['dependencies'],
			$style_asset['version']
		);

		$script_asset = include DMP_PLUGIN_PATH . 'assets/build/js/main.asset.php';

		wp_enqueue_script(
			'main-js',
			DMP_PLUGIN_PATH . 'assets/build/js/main.js',
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);
	}

	/**
	 * Enqueues styles and scripts for the frontend.
	 *
	 * @return void
	 */
	public function enqueue_block_assets() {
		$style_asset = include DMP_PLUGIN_PATH . 'assets/build/css/screen.asset.php';
		wp_enqueue_style(
			'block-css',
			DMP_PLUGIN_PATH . 'assets/build/css/screen.css',
			$style_asset['dependencies'],
			$style_asset['version']
		);

		$script_asset = include DMP_PLUGIN_PATH . 'assets/build/js/screen.asset.php';

		wp_enqueue_script(
			'block-js',
			DMP_PLUGIN_PATH . 'assets/build/js/screen.js',
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);
	}

	/**
	 * Enqueues styles and scripts for the block editor.
	 *
	 * @return void
	 */
	public function enqueue_block_editor_assets() {
		$style_asset = include DMP_PLUGIN_PATH . 'assets/build/css/editor.asset.php';

		wp_enqueue_style(
			'editor-css',
			DMP_PLUGIN_PATH . 'assets/build/css/editor.css',
			$style_asset['dependencies'],
			$style_asset['version']
		);

		$script_asset = include DMP_PLUGIN_PATH . 'assets/build/js/editor.asset.php';

		wp_enqueue_script(
			'editor-js',
			DMP_PLUGIN_PATH . 'assets/build/js/editor.js',
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);
	}
}
