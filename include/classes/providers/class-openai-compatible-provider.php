<?php
/**
 * OpenAI Compatible Provider
 *
 * Implements a provider that talks to OpenAI-compatible Chat Completions APIs.
 *
 * @package MCPress
 */

namespace MCPress\Providers;

use MCPress\Traits\LLM_Provider;
use MCPress\Traits\Singleton;
use WP_Error;

/**
 * OpenAI Compatible Provider
 *
 * Implements a provider that talks to OpenAI-compatible Chat Completions APIs.
 */
class OpenAI_Compatible_Provider {
	use Singleton;
	use LLM_Provider;

	const OPENAI_API_URL = 'https://api.openai.com/v1/chat/completions';

	/**
	 * Constructor.
	 *
	 * Registers this provider during the mcpress_register_providers hook.
	 */
	public function __construct() {
		self::$id    = 'openai_compatible';
		self::$label = 'OpenAI Compatible';

		// Register via the same mechanism tools use (action + filters).
		add_action( 'mcpress_register_providers', array( $this, 'register_provider' ) );
	}

	/**
	 * Provider-specific options schema for rendering settings UI.
	 *
	 * @return array
	 */
	public function get_options_schema(): array {
		return array(
			'endpoint' => array(
				'label'       => esc_html__( 'API Endpoint', 'mcpress' ),
				'type'        => 'url',
				'placeholder' => self::OPENAI_API_URL,
				'description' => esc_html__( 'OpenAI-compatible Chat Completions endpoint URL.', 'mcpress' ),
			),
			'api_key'  => array(
				'label'       => esc_html__( 'API Key', 'mcpress' ),
				'type'        => 'password',
				'placeholder' => 'sk-...',
				'description' => esc_html__( 'OpenAI-compatible API key.', 'mcpress' ),
			),
			'model'    => array(
				'label'       => esc_html__( 'Model', 'mcpress' ),
				'type'        => 'text',
				'placeholder' => 'gpt-5',
				'description' => esc_html__( 'Model identifier (eg: gpt-5, gpt-4o, etc.).', 'mcpress' ),
			),
		);
	}

	/**
	 * Execute a chat/completions request via an OpenAI-compatible API.
	 *
	 * Normalized input and output ensure MCP server code does not care about the provider details.
	 *
	 * @param array        $messages     Normalized messages array.
	 * @param array        $tools        Optional tool schemas (OpenAI-style).
	 * @param string|array $tool_choice  Optional tool choice.
	 * @param array        $options      Provider options (endpoint, api_key, model, etc.).
	 * @return array|WP_Error
	 */
	public function send_chat( array $messages, array $tools = array(), $tool_choice = 'auto', array $options = array() ) {
		$endpoint = isset( $options['endpoint'] ) && ! empty( $options['endpoint'] ) ? (string) $options['endpoint'] : '';
		$api_key  = isset( $options['api_key'] ) ? (string) $options['api_key'] : '';
		$model    = isset( $options['model'] ) ? (string) $options['model'] : 'gpt-5';

		if ( empty( $endpoint ) || empty( $api_key ) ) {
			return new WP_Error(
				'mcpress_provider_config_missing',
				esc_html__( 'OpenAI-compatible endpoint or API key is not configured.', 'mcpress' )
			);
		}

		$body = array(
			'model'       => $model,
			'messages'    => $messages,
			'temperature' => 0.7,
		);

		if ( ! empty( $tools ) ) {
			$body['tools'] = $tools;
		}
		if ( ! empty( $tool_choice ) ) {
			$body['tool_choice'] = $tool_choice;
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 45,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mcpress_llm_api_error',
				esc_html__( 'Failed to connect to OpenAI-compatible API: ', 'mcpress' ) . $response->get_error_message(),
				array( 'status' => 'http_error' )
			);
		}

		$http_code     = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$decoded_body  = json_decode( $response_body, true );

		if ( 200 !== $http_code ) {
			$error_message = isset( $decoded_body['error']['message'] ) ? $decoded_body['error']['message'] : esc_html__( 'Unknown provider API error.', 'mcpress' );
			return new WP_Error(
				'mcpress_provider_http_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: error message */
					esc_html__( 'Provider API returned HTTP %1$d: %2$s', 'mcpress' ),
					$http_code,
					$error_message
				),
				array(
					'status'  => $http_code,
					'details' => $decoded_body,
				)
			);
		}

		$content    = isset( $decoded_body['choices'][0]['message']['content'] ) ? (string) $decoded_body['choices'][0]['message']['content'] : '';
		$tool_calls = isset( $decoded_body['choices'][0]['message']['tool_calls'] ) && is_array( $decoded_body['choices'][0]['message']['tool_calls'] )
			? $decoded_body['choices'][0]['message']['tool_calls']
			: array();

		return array(
			'content'    => $content,
			'tool_calls' => $tool_calls,
			'raw'        => $decoded_body,
		);
	}
}
