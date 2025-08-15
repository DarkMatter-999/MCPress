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

		$registry              = Provider_Registry::get_instance();
		$current_provider_id   = $registry->get_current_provider_id();
		$current_schema        = $registry->get_provider_options_schema( $current_provider_id );
		$current_provider_opts = $registry->get_provider_options( $current_provider_id );
		$provider_ready        = true;

		if ( is_array( $current_schema ) ) {
			foreach ( $current_schema as $field_key => $field_def ) {
				if ( 'api_key' === $field_key && empty( $current_provider_opts['api_key'] ) ) {
					$provider_ready = false;
					break;
				}
			}
		}
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

						<?php if ( ! $provider_ready ) : ?>
							<p class="error-message"><?php esc_html_e( 'Please configure your current provider credentials before using the chat.', 'mcpress' ); ?></p>
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
		$message      = '';
		$message_type = 'success';

		$registry              = Provider_Registry::get_instance();
		$providers_with_labels = $registry->get_providers_with_labels();
		$current_provider_id   = $registry->get_current_provider_id();

		if ( isset( $_POST['mcpress_settings_submit'] ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				$message      = __( 'You do not have permission to save settings.', 'mcpress' );
				$message_type = 'error';
			} elseif ( ! check_admin_referer( 'mcpress_settings_nonce', 'mcpress_settings_nonce_field' ) ) {
				$message      = __( 'Nonce verification failed.', 'mcpress' );
				$message_type = 'error';
			} else {
				// Save current provider.
				$selected_provider = isset( $_POST['mcpress_current_provider'] )
					? sanitize_text_field( wp_unslash( $_POST['mcpress_current_provider'] ) )
					: $current_provider_id;

				if ( isset( $providers_with_labels[ $selected_provider ] ) ) {
					$registry->set_current_provider_id( $selected_provider );
					$current_provider_id = $selected_provider;
				}

				// Save all providers' options (namespaced by provider_id).
				$all_raw = isset( $_POST['mcpress_provider_options'] ) && is_array( $_POST['mcpress_provider_options'] )
						? wp_unslash( $_POST['mcpress_provider_options'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- already unslashed.
					: array();

				foreach ( array_keys( $providers_with_labels ) as $pid ) {
					$schema = $registry->get_provider_options_schema( $pid );
					$raw    = isset( $all_raw[ $pid ] ) && is_array( $all_raw[ $pid ] ) ? $all_raw[ $pid ] : array();

					$clean = array();
					foreach ( (array) $schema as $field_key => $field_def ) {
						if ( ! array_key_exists( $field_key, $raw ) ) {
							continue;
						}
						$type  = isset( $field_def['type'] ) ? $field_def['type'] : 'text';
						$value = $raw[ $field_key ];

						switch ( $type ) {
							case 'url':
								$clean[ $field_key ] = esc_url_raw( $value );
								break;
							default:
								$clean[ $field_key ] = sanitize_text_field( $value );
								break;
						}
					}

					$registry->save_provider_options( $pid, $clean );
				}

				$message      = __( 'Provider settings saved successfully!', 'mcpress' );
				$message_type = 'success';
			}
		}

		// Refresh data for rendering.
		$current_provider_id   = $registry->get_current_provider_id();
		$providers_with_labels = $registry->get_providers_with_labels();

		?>
		<div class="wrap mcpress-admin-page">
			<h1><?php esc_html_e( 'MCPress Providers & Settings', 'mcpress' ); ?></h1>

			<?php if ( $message ) : ?>
				<div class="notice notice-<?php echo esc_attr( $message_type ); ?> is-dismissible">
					<p><strong><?php echo esc_html( $message ); ?></strong></p>
				</div>
			<?php endif; ?>

			<div class="mcpress-card-grid">
				<div class="mcpress-card full-width">
					<h2><?php esc_html_e( 'Provider Settings', 'mcpress' ); ?></h2>
					<form method="post" action="">
						<?php wp_nonce_field( 'mcpress_settings_nonce', 'mcpress_settings_nonce_field' ); ?>

						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="mcpress_current_provider"><?php esc_html_e( 'Current Provider', 'mcpress' ); ?></label>
								</th>
								<td>
									<select id="mcpress_current_provider" name="mcpress_current_provider">
										<?php foreach ( $providers_with_labels as $pid => $label ) : ?>
											<option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $pid, $current_provider_id ); ?>>
												<?php echo esc_html( $label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description">
										<?php esc_html_e( 'Select which provider to use for requests. You can configure all providers below.', 'mcpress' ); ?>
									</p>
								</td>
							</tr>
						</table>

						<hr />

						<?php foreach ( $providers_with_labels as $pid => $label ) : ?>
							<?php
							$schema = $registry->get_provider_options_schema( $pid );
							$values = $registry->get_provider_options( $pid );
							?>
							<h3 style="margin-top:2em;">
								<?php echo esc_html( $label ); ?>
								<?php if ( $pid === $current_provider_id ) : ?>
									<span class="dashicons dashicons-yes-alt" title="<?php esc_attr_e( 'Current provider', 'mcpress' ); ?>"></span>
								<?php endif; ?>
							</h3>
							<table class="form-table">
								<?php if ( ! empty( $schema ) && is_array( $schema ) ) : ?>
									<?php foreach ( $schema as $field_key => $field_def ) : ?>
										<?php
										$type        = isset( $field_def['type'] ) ? $field_def['type'] : 'text';
										$flabel      = isset( $field_def['label'] ) ? $field_def['label'] : $field_key;
										$placeholder = isset( $field_def['placeholder'] ) ? $field_def['placeholder'] : '';
										$desc        = isset( $field_def['description'] ) ? $field_def['description'] : '';
										$value       = isset( $values[ $field_key ] ) ? $values[ $field_key ] : '';
										$input_id    = 'mcpress_provider_options_' . sanitize_html_class( $pid . '_' . $field_key );
										?>
										<tr>
											<th scope="row">
												<label for="<?php echo esc_attr( $input_id ); ?>">
													<?php echo esc_html( $flabel ); ?>
												</label>
											</th>
											<td>
												<?php if ( 'password' === $type ) : ?>
													<input type="password"
														id="<?php echo esc_attr( $input_id ); ?>"
														name="mcpress_provider_options[<?php echo esc_attr( $pid ); ?>][<?php echo esc_attr( $field_key ); ?>]"
														class="regular-text"
														value="<?php echo esc_attr( $value ); ?>"
														placeholder="<?php echo esc_attr( $placeholder ); ?>">
												<?php elseif ( 'url' === $type ) : ?>
													<input type="url"
														id="<?php echo esc_attr( $input_id ); ?>"
														name="mcpress_provider_options[<?php echo esc_attr( $pid ); ?>][<?php echo esc_attr( $field_key ); ?>]"
														class="regular-text"
														value="<?php echo esc_url( $value ); ?>"
														placeholder="<?php echo esc_attr( $placeholder ); ?>">
												<?php else : ?>
													<input type="text"
														id="<?php echo esc_attr( $input_id ); ?>"
														name="mcpress_provider_options[<?php echo esc_attr( $pid ); ?>][<?php echo esc_attr( $field_key ); ?>]"
														class="regular-text"
														value="<?php echo esc_attr( $value ); ?>"
														placeholder="<?php echo esc_attr( $placeholder ); ?>">
												<?php endif; ?>
												<?php if ( ! empty( $desc ) ) : ?>
													<p class="description"><?php echo esc_html( $desc ); ?></p>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								<?php else : ?>
									<tr>
										<td colspan="2"><em><?php esc_html_e( 'No settings available for this provider.', 'mcpress' ); ?></em></td>
									</tr>
								<?php endif; ?>
							</table>
							<hr />
						<?php endforeach; ?>

						<?php submit_button( __( 'Save Provider Settings', 'mcpress' ), 'primary', 'mcpress_settings_submit' ); ?>
					</form>
				</div>
			</div>
		</div>
		<?php
	}
}
