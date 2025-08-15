<?php
/**
 * Gemini Provider
 *
 * Implements a provider for Google's Generative Language (Gemini) API.
 * This MVP supports standard chat messaging with tools/function-calling mapping.
 *
 * API reference (v1beta):
 * - Endpoint: https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key=API_KEY
 * - Request body uses "contents" with roles "user" and "model"
 * - Optional "systemInstruction" can carry system prompts
 *
 * @package MCPress
 */

namespace MCPress\Providers;

use MCPress\Traits\LLM_Provider;
use MCPress\Traits\Singleton;
use WP_Error;

/**
 * Gemini Provider
 *
 * Implements a provider for Google's Generative Language (Gemini) API.
 * This MVP supports standard chat messaging with tools/function-calling mapping.
 */
class Gemini_Provider {
	use Singleton;
	use LLM_Provider;

	/**
	 * Constructor.
	 *
	 * Registers this provider during the mcpress_register_providers hook.
	 */
	public function __construct() {
		self::$id    = 'gemini';
		self::$label = 'Gemini';

		// Register via the same mechanism as tools (action + filters).
		add_action( 'mcpress_register_providers', array( $this, 'register_provider' ) );
	}

	/**
	 * Provider-specific options schema for rendering settings UI.
	 *
	 * @return array
	 */
	public function get_options_schema(): array {
		return array(
			'api_key'  => array(
				'label'       => esc_html__( 'API Key', 'mcpress' ),
				'type'        => 'password',
				'placeholder' => 'AIza...',
				'description' => esc_html__( 'Your Gemini API key.', 'mcpress' ),
			),
			'model'    => array(
				'label'       => esc_html__( 'Model', 'mcpress' ),
				'type'        => 'text',
				'placeholder' => 'gemini-2.5-pro',
				'description' => esc_html__( 'Model identifier (eg: gemini-2.5-pro, gemini-2.5-flash).', 'mcpress' ),
			),
			'endpoint' => array(
				'label'       => esc_html__( 'API Endpoint (optional)', 'mcpress' ),
				'type'        => 'url',
				'placeholder' => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent',
				'description' => esc_html__( 'Override the default endpoint. You typically do not need to change this.', 'mcpress' ),
			),
		);
	}

	/**
	 * Execute a chat request via the Gemini API.
	 *
	 * @param array        $messages     Normalized messages array (OpenAI-style).
	 * @param array        $tools        Unused for Gemini MVP.
	 * @param string|array $tool_choice  Unused for Gemini MVP.
	 * @param array        $options      Provider options (api_key, model, endpoint).
	 * @return array|WP_Error
	 */
	public function send_chat( array $messages, array $tools = array(), $tool_choice = 'auto', array $options = array() ) {
		$api_key  = $options['api_key'] ?? '';
		$model    = $options['model'] ?? 'gemini-2.5-flash';
		$endpoint = ! empty( $options['endpoint'] )
			? $options['endpoint']
			: 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent';

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'mcpress_provider_config_missing',
				esc_html__( 'Gemini API key is not configured.', 'mcpress' )
			);
		}

		// Convert OpenAI-style messages to Gemini contents and capture system instructions.
		$contents       = array();
		$system_prompts = array();

		foreach ( $messages as $msg ) {
			$role = $msg['role'] ?? 'user';

			if ( 'system' === $role ) {
				if ( ! empty( $msg['content'] ) ) {
					$system_prompts[] = $msg['content'];
				}
				continue;
			}

			$gemini_role = ( 'assistant' === $role ) ? 'model'
				: ( ( 'tool' === $role ) ? 'function' : 'user' );

			$parts = array();

			// Function call request (assistant/tool_calls in OpenAI).
			if ( isset( $msg['tool_calls'] ) && is_array( $msg['tool_calls'] ) ) {
				foreach ( $msg['tool_calls'] as $tc ) {
					$args_obj = json_decode( $tc['function']['arguments'] ?? '{}', true );
					if ( ! is_array( $args_obj ) ) {
						$args_obj = array();
					}
					$parts[] = array(
						'functionCall' => array(
							'name' => $tc['function']['name'] ?? '',
							'args' => (object) $args_obj,
						),
					);
				}
			} elseif ( 'function' === $gemini_role && isset( $msg['tool_call_id'] ) ) { // Function response (tool in OpenAI).
				$parts[] = array(
					'functionResponse' => array(
						'name'     => $msg['name'] ?? '',
						'response' => array(
							'name'    => $msg['name'] ?? '',
							'content' => $msg['content'] ?? '',
						),
					),
				);
			} elseif ( ! empty( $msg['content'] ) ) {  // Regular text.
				$parts[] = array( 'text' => $msg['content'] );
			}

			if ( ! empty( $parts ) ) {
				$contents[] = array(
					'role'  => $gemini_role,
					'parts' => $parts,
				);
			}
		}

		$body = array(
			'contents'         => $contents,
			'generationConfig' => array( 'temperature' => 0.7 ),
		);

		if ( ! empty( $system_prompts ) ) {
			$body['systemInstruction'] = array(
				'role'  => 'system',
				'parts' => array(
					array( 'text' => implode( "\n\n", $system_prompts ) ),
				),
			);
		}

		// Convert tools from OpenAI format to Gemini.
		if ( ! empty( $tools ) ) {
			$body['tools'] = array(
				array(
					'function_declarations' => array_map(
						function ( $tool ) {
							$params = $tool['function']['parameters'] ?? array();

							if ( isset( $params['properties'] ) && is_array( $params['properties'] ) ) {
								$params['properties'] = (object) $params['properties'];
							}

							if ( is_array( $params ) ) {
								$params = (object) $params;
							}

							return array(
								'name'        => $tool['function']['name'],
								'description' => $tool['function']['description'] ?? '',
								'parameters'  => $params,
							);
						},
						$tools
					),
				),
			);
		}

		$request_url = add_query_arg( array( 'key' => $api_key ), $endpoint );

		$response = wp_remote_post(
			$request_url,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
				'timeout' => 45,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mcpress_llm_api_error',
				esc_html__( 'Failed to connect to Gemini API: ', 'mcpress' ) . $response->get_error_message()
			);
		}

		$http_code     = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$decoded_body  = json_decode( $response_body, true );

		// Gemini errors may come with HTTP 4xx/5xx and/or an "error" structure in response.
		if ( 200 !== $http_code ) {
			$error_message = $decoded_body['error']['message'] ?? esc_html__( 'Unknown provider API error.', 'mcpress' );
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

		// Convert Gemini response into OpenAI-style.
		$normalized_content = '';
		$tool_calls         = array();

		if ( ! empty( $decoded_body['candidates'][0]['content']['parts'] ) ) {
			foreach ( $decoded_body['candidates'][0]['content']['parts'] as $part ) {
				// Text.
				if ( isset( $part['text'] ) ) {
					$normalized_content .= $part['text'];
				} elseif ( isset( $part['functionCall'] ) ) { // Function call.
					$tool_calls[] = array(
						'id'       => uniqid( 'call_' ),
						'type'     => 'function',
						'function' => array(
							'name'      => $part['functionCall']['name'] ?? '',
							'arguments' => wp_json_encode( $part['functionCall']['args'] ?? array() ),
						),
					);
				}
			}
		}

		return array(
			'content'    => $normalized_content ? $normalized_content : null,
			'tool_calls' => $tool_calls,
			'raw'        => $decoded_body,
		);
	}
}
