<?php
/**
 * LLM Provider Trait
 *
 * Defines a consistent interface for MCPress provider classes to:
 * - Declare a unique ID and human-readable label.
 * - Handle chat requests via `send_chat()`.
 * - Define settings schema via `get_options_schema()`.
 * - Register themselves with WordPress for system-wide discovery.
 *
 * Implementing classes must set `static::$id` and `static::$label`, implement
 * `send_chat()` and `get_options_schema()`, and call `add_action( 'mcpress_register_providers', array( $this, 'register_provider' ) );`
 * in their constructor.
 *
 * @package MCPress
 */

namespace MCPress\Traits;

use WP_Error;

trait LLM_Provider {

	/**
	 * Unique identifier for the provider
	 *
	 * This must be set by the implementing class.
	 *
	 * @var string|null
	 */
	protected static $id = null;

	/**
	 * Human-readable label for the provider
	 *
	 * This should be set by the implementing class.
	 *
	 * @var string|null
	 */
	protected static $label = null;

	/**
	 * Execute a chat/completions request via this provider.
	 *
	 * Implementing classes must translate the normalized MCPress inputs to the
	 * provider's API format and produce a normalized response.
	 *
	 * Expected normalized return on success:
	 * - array(
	 *     'content'    => (string) Assistant message content,
	 *     'tool_calls' => (array) OpenAI-style tool_calls array or [] if not used,
	 *     'raw'        => (mixed) Optional raw provider response for debugging,
	 *   )
	 *
	 * On error, this method should return a WP_Error object.
	 *
	 * @param array        $messages     Normalized messages, each item like ['role' => 'system|user|assistant', 'content' => '...'].
	 * @param array        $tools        Optional. Array of tool schemas (OpenAI-style).
	 * @param string|array $tool_choice Optional. 'auto', 'none', or OpenAI-style function choice array.
	 * @param array        $options      Provider-specific options (eg: endpoint, api_key, model).
	 * @return array|WP_Error
	 */
	abstract public function send_chat( array $messages, array $tools = array(), $tool_choice = 'auto', array $options = array() );

	/**
	 * Return an associative array describing the provider's settings schema.
	 *
	 * The schema is used to render fields in the MCPress settings UI. Example:
	 * return array(
	 *   'endpoint' => array(
	 *     'label'       => 'API Endpoint',
	 *     'type'        => 'url',        // 'text' | 'password' | 'url' | 'select' | etc.
	 *     'placeholder' => 'https://api.example.com/v1/chat/completions',
	 *     'description' => 'Your provider endpoint URL.',
	 *   ),
	 *   'api_key' => array(
	 *     'label'       => 'API Key',
	 *     'type'        => 'password',
	 *     'placeholder' => 'sk-...'
	 *   ),
	 *   'model' => array(
	 *     'label'       => 'Model',
	 *     'type'        => 'text',
	 *     'placeholder' => 'gpt-5',
	 *   ),
	 * );
	 *
	 * @return array
	 */
	abstract public function get_options_schema(): array;

	/**
	 * Register this provider with MCPress via WordPress filters/actions.
	 *
	 * This method wires the provider into:
	 * - mcpress_available_providers (list of provider IDs)
	 * - mcpress_providers_map (map of provider_id => provider instance)
	 * - mcpress_provider_labels (map of provider_id => human label)
	 * - mcpress_provider_options_schemas (map of provider_id => options schema)
	 * - mcpress_provider_send_chat_{provider_id} (callback to execute a chat request)
	 *
	 * Implementing classes should call this method during the 'mcpress_register_providers' action.
	 *
	 * @return void
	 */
	public function register_provider(): void {
		// Ensure the implementing class has defined and set static::$id.
		if ( ! property_exists( static::class, 'id' ) || empty( static::$id ) ) {
			return;
		}

		add_filter( 'mcpress_available_providers', array( $this, 'register_provider_id' ) );
		add_filter( 'mcpress_providers_map', array( $this, 'register_provider_instance' ) );
		add_filter( 'mcpress_provider_labels', array( $this, 'register_provider_label' ) );
		add_filter( 'mcpress_provider_options_schemas', array( $this, 'register_provider_options_schema' ) );

		// Filter to execute this provider's send_chat method.
		add_filter( 'mcpress_provider_send_chat_' . static::$id, array( $this, 'send_chat' ), 10, 4 );
	}

	/**
	 * Adds this provider's ID to the available providers list.
	 *
	 * @param array $providers List of provider IDs.
	 * @return array
	 */
	public function register_provider_id( array $providers ): array {
		if ( ! is_array( $providers ) ) {
			$providers = array();
		}

		$providers[] = static::$id;
		$providers   = array_values( array_unique( $providers ) );

		return $providers;
	}

	/**
	 * Adds this provider instance to the providers map.
	 *
	 * @param array $map Associative array of provider_id => provider_instance.
	 * @return array
	 */
	public function register_provider_instance( array $map ): array {
		if ( ! is_array( $map ) ) {
			$map = array();
		}

		$map[ static::$id ] = $this;

		return $map;
	}

	/**
	 * Adds this provider's label to the labels map.
	 *
	 * @param array $labels Associative array of provider_id => label.
	 * @return array
	 */
	public function register_provider_label( array $labels ): array {
		if ( ! is_array( $labels ) ) {
			$labels = array();
		}

		$labels[ static::$id ] = $this->get_label();

		return $labels;
	}

	/**
	 * Adds this provider's options schema to the schemas map.
	 *
	 * @param array $schemas Associative array of provider_id => schema_array.
	 * @return array
	 */
	public function register_provider_options_schema( array $schemas ): array {
		if ( ! is_array( $schemas ) ) {
			$schemas = array();
		}

		$schemas[ static::$id ] = $this->get_options_schema();

		return $schemas;
	}

	/**
	 * Returns the provider label. Falls back to the provider ID if no label is set.
	 *
	 * @return string
	 */
	public function get_label(): string {
		if ( property_exists( static::class, 'label' ) && ! empty( static::$label ) ) {
			return (string) static::$label;
		}
		if ( property_exists( static::class, 'id' ) && ! empty( static::$id ) ) {
			return (string) static::$id;
		}
		return '';
	}
}
