<?php
/**
 * Main Assets Class File
 *
 * Main Theme Asset class file for the Plugin. This class enqueues the necessary scripts and styles.
 *
 * @package MCPress
 **/

namespace MCPress;

use MCPress\Traits\Singleton;

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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
	}

	/**
	 * Enqueues styles and scripts for the theme.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$style_asset = include MCP_PLUGIN_PATH . 'assets/build/css/main.asset.php';
		wp_enqueue_style(
			'main-css',
			MCP_PLUGIN_URL . 'assets/build/css/main.css',
			$style_asset['dependencies'],
			$style_asset['version']
		);

		$script_asset = include MCP_PLUGIN_PATH . 'assets/build/js/main.asset.php';

		wp_enqueue_script(
			'main-js',
			MCP_PLUGIN_URL . 'assets/build/js/main.js',
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);
	}

	/**
	 * Enqueue admin scripts and styles for the Chat UI.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {

		$style_asset = include MCP_PLUGIN_PATH . 'assets/build/css/admin.asset.php';
		wp_enqueue_style(
			'mcpress-admin-css',
			MCP_PLUGIN_URL . 'assets/build/css/admin.css',
			$style_asset['dependencies'],
			$style_asset['version']
		);

		if ( 'toplevel_page_mcpress-dashboard' === $hook ) {
			$script_asset = include MCP_PLUGIN_PATH . 'assets/build/js/admin.asset.php';

			wp_enqueue_script(
				'mcpress-admin-js',
				MCP_PLUGIN_URL . 'assets/build/js/admin.js',
				$script_asset['dependencies'],
				$script_asset['version'],
				true
			);
			wp_localize_script(
				'mcpress-admin-js',
				'mcpress_vars',
				array(
					'chat_url' => esc_url_raw( site_url( 'wp-json/mcp/v1/chat' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
				)
			);
		}
	}
}
