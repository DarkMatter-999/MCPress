<?php
/**
 * Provider Registry
 *
 * Collects providers registered via hooks, exposes utilities to select the current
 * provider, read/write provider-specific options, and route chat requests
 * through the selected provider.
 *
 * Providers register themselves similarly to LLM tools by:
 * - Using the MCPress\Traits\Provider trait
 * - Setting static::$id and static::$label
 * - Implementing send_chat() and get_options_schema()
 * - Calling register_provider() during the 'mcpress_register_providers' action
 *
 * @package MCPress
 */

namespace MCPress;

use MCPress\Traits\Singleton;
use WP_Error;

/**
 * Provider Registry
 *
 * Collects providers registered via hooks, exposes utilities to select the current
 * provider, read/write provider-specific options, and route chat requests
 * through the selected provider.
 */
class Provider_Registry {
	use Singleton;

	/**
	 * Option name that stores the currently selected provider ID.
	 */
	const OPTION_CURRENT_PROVIDER = 'mcpress_current_provider';



	/**
	 * Default provider ID to use when no selection has been made.
	 */
	const DEFAULT_PROVIDER = 'openai_compatible';

	/**
	 * Per-field provider option keys use the format: 'mcpress_{provider_id}_{field_key}'.
	 */
	const OPTION_PREFIX = 'mcpress_';

	/**
	 * Ordered list of available provider IDs (eg: ['openai_compatible', 'openrouter']).
	 *
	 * @var string[]
	 */
	private $available_providers = array();

	/**
	 * Map of provider_id => provider instance.
	 *
	 * @var array<string, object>
	 */
	private $providers_map = array();

	/**
	 * Map of provider_id => human-readable label.
	 *
	 * @var array<string, string>
	 */
	private $provider_labels = array();

	/**
	 * Map of provider_id => provider options schema array.
	 *
	 * @var array<string, array>
	 */
	private $provider_options_schemas = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		Providers\OpenAI_Compatible_Provider::get_instance();
		Providers\Gemini_Provider::get_instance();
		Providers\OpenRouter_Provider::get_instance();

		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register all available providers by running the discovery hooks/filters.
	 *
	 * @return void
	 */
	public function register(): void {
		// Allow providers (built-in or third-party) to call their register_provider() hooks.
		do_action( 'mcpress_register_providers' );

		// Collect everything providers have registered.
		$this->available_providers      = apply_filters( 'mcpress_available_providers', $this->available_providers );
		$this->providers_map            = apply_filters( 'mcpress_providers_map', $this->providers_map );
		$this->provider_labels          = apply_filters( 'mcpress_provider_labels', $this->provider_labels );
		$this->provider_options_schemas = apply_filters( 'mcpress_provider_options_schemas', $this->provider_options_schemas );

		// Ensure arrays.
		$this->available_providers      = is_array( $this->available_providers ) ? array_values( array_unique( $this->available_providers ) ) : array();
		$this->providers_map            = is_array( $this->providers_map ) ? $this->providers_map : array();
		$this->provider_labels          = is_array( $this->provider_labels ) ? $this->provider_labels : array();
		$this->provider_options_schemas = is_array( $this->provider_options_schemas ) ? $this->provider_options_schemas : array();
	}

	/**
	 * Get list of available provider IDs.
	 *
	 * @return string[]
	 */
	public function get_available_providers(): array {
		return $this->available_providers;
	}

	/**
	 * Get a list suitable for settings dropdown: [ provider_id => label ].
	 *
	 * @return array<string,string>
	 */
	public function get_providers_with_labels(): array {
		$result = array();
		foreach ( $this->available_providers as $id ) {
			$result[ $id ] = $this->provider_labels[ $id ] ?? $id;
		}
		return $result;
	}

	/**
	 * Get provider instance by ID.
	 *
	 * @param string $provider_id Provider ID.
	 * @return object|null
	 */
	public function get_provider_instance( string $provider_id ) {
		return $this->providers_map[ $provider_id ] ?? null;
	}

	/**
	 * Get provider label by ID.
	 *
	 * @param string $provider_id Provider ID.
	 * @return string
	 */
	public function get_provider_label( string $provider_id ): string {
		return isset( $this->provider_labels[ $provider_id ] ) ? (string) $this->provider_labels[ $provider_id ] : $provider_id;
	}

	/**
	 * Get provider options schema.
	 *
	 * @param string $provider_id Provider ID.
	 * @return array
	 */
	public function get_provider_options_schema( string $provider_id ): array {
		return isset( $this->provider_options_schemas[ $provider_id ] ) ? (array) $this->provider_options_schemas[ $provider_id ] : array();
	}

	/**
	 * Build the per-option name for a provider field.
	 *
	 * @param string $provider_id Provider ID.
	 * @param string $field_key   Field key from the provider schema.
	 * @return string
	 */
	private function build_option_key( string $provider_id, string $field_key ): string {
		$provider_id = sanitize_key( $provider_id );
		$field_key   = sanitize_key( $field_key );
		return self::OPTION_PREFIX . $provider_id . '_' . $field_key;
	}

	/**
	 * Get the currently selected provider ID, with fallback to defaults.
	 *
	 * @return string
	 */
	public function get_current_provider_id(): string {
		$current = get_option( self::OPTION_CURRENT_PROVIDER, '' );
		if ( $current && in_array( $current, $this->available_providers, true ) ) {
			return $current;
		}

		// Fallback to default if available.
		if ( in_array( self::DEFAULT_PROVIDER, $this->available_providers, true ) ) {
			return self::DEFAULT_PROVIDER;
		}

		// Fallback to the first available provider.
		if ( ! empty( $this->available_providers ) ) {
			return (string) $this->available_providers[0];
		}

		return '';
	}

	/**
	 * Set the currently selected provider ID.
	 *
	 * @param string $provider_id Provider ID to set.
	 * @return bool True on success, false on failure.
	 */
	public function set_current_provider_id( string $provider_id ): bool {
		if ( empty( $provider_id ) || ! in_array( $provider_id, $this->available_providers, true ) ) {
			return false;
		}
		return (bool) update_option( self::OPTION_CURRENT_PROVIDER, $provider_id );
	}

	/**
	 * Get the instance of the currently selected provider.
	 *
	 * @return object|null
	 */
	public function get_current_provider() {
		$id = $this->get_current_provider_id();
		return $this->get_provider_instance( $id );
	}

	/**
	 * Get saved options for a provider.
	 *
	 * @param string $provider_id Provider ID.
	 * @return array
	 */
	public function get_provider_options( string $provider_id ): array {
		$schema  = $this->get_provider_options_schema( $provider_id );
		$options = array();

		if ( ! empty( $schema ) && is_array( $schema ) ) {
			foreach ( $schema as $field_key => $field_def ) {
				$opt_name              = $this->build_option_key( $provider_id, (string) $field_key );
				$options[ $field_key ] = get_option( $opt_name, '' );
			}
			return $options;
		}

		return array();
	}

	/**
	 * Get saved options for the currently selected provider.
	 *
	 * @return array
	 */
	public function get_current_provider_options(): array {
		$id = $this->get_current_provider_id();
		return $this->get_provider_options( $id );
	}

	/**
	 * Save options for a provider.
	 *
	 * @param string $provider_id Provider ID.
	 * @param array  $options     Provider options.
	 * @return bool True on success, false on failure.
	 */
	public function save_provider_options( string $provider_id, array $options ): bool {
		if ( empty( $provider_id ) ) {
			return false;
		}

		$schema = $this->get_provider_options_schema( $provider_id );
		$ok     = true;

		// Save each field as its own option.
		foreach ( (array) $options as $field_key => $value ) {
			// If schema exists and field is not in it, ignore.
			if ( ! empty( $schema ) && ! array_key_exists( $field_key, $schema ) ) {
				continue;
			}
			$opt_name = $this->build_option_key( $provider_id, (string) $field_key );
			$ok       = update_option( $opt_name, $value ) && $ok;
		}

		// Do not write to legacy aggregated option; keep it only as a read fallback.
		return (bool) $ok;
	}

	/**
	 * Route a chat request through the currently selected provider.
	 *
	 * @param array        $messages      Normalized messages.
	 * @param array        $tools         Tool schemas (optional).
	 * @param string|array $tool_choice   Tool choice (optional).
	 * @param array        $override_opts Options overriding saved provider options (optional).
	 * @return array|WP_Error
	 */
	public function send_chat( array $messages, array $tools = array(), $tool_choice = 'auto', array $override_opts = array() ) {
		$provider_id = $this->get_current_provider_id();
		return $this->send_chat_via( $provider_id, $messages, $tools, $tool_choice, $override_opts );
	}

	/**
	 * Route a chat request through a specific provider by ID.
	 *
	 * If the provider registered a dynamic filter "mcpress_provider_send_chat_{$id}",
	 * it will be used to call the provider's send_chat implementation.
	 *
	 * @param string       $provider_id   Provider ID.
	 * @param array        $messages      Normalized messages.
	 * @param array        $tools         Tool schemas (optional).
	 * @param string|array $tool_choice   Tool choice (optional).
	 * @param array        $override_opts Options overriding saved provider options (optional).
	 * @return array|WP_Error
	 */
	public function send_chat_via( string $provider_id, array $messages, array $tools = array(), $tool_choice = 'auto', array $override_opts = array() ) {
		if ( empty( $provider_id ) ) {
			return new WP_Error( 'mcpress_no_provider', __( 'No provider selected.', 'mcpress' ) );
		}

		if ( ! in_array( $provider_id, $this->available_providers, true ) ) {
			return new WP_Error( 'mcpress_invalid_provider', __( 'Selected provider is not available.', 'mcpress' ) );
		}

		// Merge saved options with override options (overrides take precedence).
		$saved   = $this->get_provider_options( $provider_id );
		$options = array_merge( is_array( $saved ) ? $saved : array(), is_array( $override_opts ) ? $override_opts : array() );

		/**
		 * Providers register a filter "mcpress_provider_send_chat_{$provider_id}" in order
		 * to wire their send_chat implementation. The first parameter is used as the
		 * primary value by WordPress filter mechanics, but here we pass $messages to
		 * match the provider's signature.
		 *
		 * The filter callback should return either:
		 * - array( 'content' => string, 'tool_calls' => array, 'raw' => mixed )
		 * - WP_Error on failure
		 */
		$result = apply_filters(
			'mcpress_provider_send_chat_' . $provider_id,
			$messages,
			$tools,
			$tool_choice,
			$options
		);

		// If no provider hooked the filter, attempt calling the instance directly (fallback).
		if ( $result === $messages ) {
			$instance = $this->get_provider_instance( $provider_id );
			if ( $instance && method_exists( $instance, 'send_chat' ) ) {
				$result = $instance->send_chat( $messages, $tools, $tool_choice, $options );
			}
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Basic normalization/validation.
		if ( ! is_array( $result ) ) {
			return new WP_Error( 'mcpress_provider_bad_response', __( 'Provider returned an invalid response.', 'mcpress' ) );
		}

		if ( ! array_key_exists( 'content', $result ) ) {
			$result['content'] = '';
		}
		if ( ! array_key_exists( 'tool_calls', $result ) || ! is_array( $result['tool_calls'] ) ) {
			$result['tool_calls'] = array();
		}

		return $result;
	}
}
