<?php
/**
 * Settings Class file for the Plugin.
 *
 * @package MCPress
 */

namespace MCPress;

use MCPress\Traits\Singleton;

/**
 * Settings Class file for the Plugin.
 */
class Settings {

	use Singleton;

	/**
	 * Constructor for the Settings class
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
	}

	/**
	 * Add admin menu pages.
	 */
	public function add_admin_menu() {
		global $wp_filesystem;

		// Fetch image using WP_Filesystem.
		if ( empty( $wp_filesystem ) ) {
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();
		}

		$svg_icon_content = '';
		$icon_path        = MCP_PLUGIN_PATH . '/assets/images/icon.svg';

		if ( $wp_filesystem->exists( $icon_path ) ) {
			$svg_icon_content = $wp_filesystem->get_contents( $icon_path );
		}

		// Base64 encoding for admin menu image.
		$svg_icon = 'data:image/svg+xml;base64,' . base64_encode( $svg_icon_content ); // phpcs:ignore
		add_menu_page(
			esc_html__( 'MCPress Dashboard', 'mcpress' ),
			esc_html__( 'MCPress', 'mcpress' ),
			'manage_options',
			'mcpress-dashboard',
			array( $this, 'render_dashboard_page' ),
			$svg_icon,
			45
		);

		add_submenu_page(
			'mcpress-dashboard',
			esc_html__( 'Settings', 'mcpress' ),
			esc_html__( 'Settings', 'mcpress' ),
			'manage_options',
			'mcpress-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render the dashboard page.
	 */
	public function render_dashboard_page() {
		$message      = '';
		$message_type = 'success';

		$current_api_endpoint = get_option( MCP_LLM_API::OPENAI_API_ENDPOINT, '' );
		$current_api_key      = get_option( MCP_LLM_API::OPENAI_API_KEY, '' );
		?>
		<div class="wrap mcpress-admin-page">
		<h1><?php esc_html_e( 'MCP LLM Chat', 'mcpress' ); ?></h1>

		<?php if ( $message ) : ?>
			<div class="notice notice-<?php echo esc_attr( $message_type ); ?> is-dismissible">
				<p><strong><?php echo esc_html( $message ); ?></strong></p>
			</div>
		<?php endif; ?>

		<div class="mcpress-card-grid">
			<div class="mcpress-card full-width">
				<h2><?php esc_html_e( 'LLM Chat', 'mcpress' ); ?></h2>
				<p><?php esc_html_e( 'Enter a message and send it to your configured LLM API. The system prompt informs the LLM about WordPress capabilities.', 'mcpress' ); ?></p>

				<?php if ( empty( $current_api_endpoint ) || empty( $current_api_key ) ) : ?>
					<p class="error-message"><?php esc_html_e( 'Please configure your LLM API Endpoint and Key above before using the chat.', 'mcpress' ); ?></p>
				<?php else : ?>
					<div class="mcpress-chat-container">
						<div id="mcpress-chat-log" class="mcpress-chat-log">
							<div class="mcpress-chat-message system-message">
								<strong>System:</strong> <?php esc_html_e( 'Welcome to the LLM Chat. I am ready to assist.', 'mcpress' ); ?>
							</div>
						</div>
						<div class="mcpress-chat-input">
							<textarea id="mcpress-user-input" placeholder="<?php esc_attr_e( 'Type your message...', 'mcpress' ); ?>"></textarea>
							<button id="mcpress-send-button" class="button button-primary"><?php esc_html_e( 'Send', 'mcpress' ); ?></button>
							<span class="spinner" style="float: none; vertical-align: middle;"></span>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
		<?php
	}


	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
		// Handle settings saving first.
		$message      = '';
		$message_type = 'success';

		if ( isset( $_POST['mcpress_settings_submit'] ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				$message      = esc_html__( 'You do not have permission to save settings.', 'mcpress' );
				$message_type = 'error';
			} elseif ( ! check_admin_referer( 'mcpress_settings_nonce', 'mcpress_settings_nonce_field' ) ) {
				$message      = esc_html__( 'Nonce verification failed.', 'mcpress' );
				$message_type = 'error';
			} else {
				$api_endpoint = isset( $_POST['mcpress_api_endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['mcpress_api_endpoint'] ) ) : '';
				$api_key      = isset( $_POST['mcpress_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['mcpress_api_key'] ) ) : '';

				update_option( MCP_LLM_API::OPENAI_API_ENDPOINT, $api_endpoint );
				update_option( MCP_LLM_API::OPENAI_API_KEY, $api_key );

				$message      = esc_html__( 'LLM API Settings saved successfully!', 'mcpress' );
				$message_type = 'success';
			}
		}

		$current_api_endpoint = get_option( MCP_LLM_API::OPENAI_API_ENDPOINT, '' );
		$current_api_key      = get_option( MCP_LLM_API::OPENAI_API_KEY, '' );

		?>
		<div class="wrap mcpress-admin-page">
			<h1><?php esc_html_e( 'MCP LLM Chat & Settings', 'mcpress' ); ?></h1>

			<?php if ( $message ) : ?>
				<div class="notice notice-<?php echo esc_attr( $message_type ); ?> is-dismissible">
					<p><strong><?php echo esc_html( $message ); ?></strong></p>
				</div>
			<?php endif; ?>

			<div class="mcpress-card-grid">
				<div class="mcpress-card full-width">
					<h2><?php esc_html_e( 'LLM API Settings', 'mcpress' ); ?></h2>
					<form method="post" action="">
						<?php wp_nonce_field( 'mcpress_settings_nonce', 'mcpress_settings_nonce_field' ); ?>
						<table class="form-table">
							<tr>
								<th scope="row"><label for="mcpress_api_endpoint"><?php esc_html_e( 'OpenAI Compatible API Endpoint URL', 'mcpress' ); ?></label></th>
								<td><input type="url" id="mcpress_api_endpoint" name="mcpress_api_endpoint" class="regular-text" value="<?php echo esc_url( $current_api_endpoint ); ?>" placeholder="https://api.openai.com/v1/chat/completions"></td>
							</tr>
							<tr>
								<th scope="row"><label for="mcpress_api_key"><?php esc_html_e( 'API Key', 'mcpress' ); ?></label></th>
								<td><input type="password" id="mcpress_api_key" name="mcpress_api_key" class="regular-text" value="<?php echo esc_attr( $current_api_key ); ?>" placeholder="sk-XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX"></td>
							</tr>
						</table>
						<?php submit_button( esc_html__( 'Save LLM API Settings', 'mcpress' ), 'primary', 'mcpress_settings_submit' ); ?>
					</form>
				</div>
			</div>
		</div>
		<?php
	}
}
