<?php
/**
 * Gemini Provider (chat-only MVP)
 *
 * Implements a provider for Google's Generative Language (Gemini) API.
 * This MVP supports standard chat messaging (no tools/function-calling mapping).
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
 * Gemini Provider (chat-only MVP)
 *
 * Implements a provider for Google's Generative Language (Gemini) API.
 * This MVP supports standard chat messaging (no tools/function-calling mapping).
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
				'placeholder' => 'gemini-1.5-pro',
				'description' => esc_html__( 'Model identifier (eg:, gemini-2.5-pro, gemini-2.5-flash).', 'mcpress' ),
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
	 * Execute a chat request via the Gemini API (chat-only, no tools).
	 *
	 * @param array        $messages     Normalized messages array (OpenAI-style).
	 * @param array        $tools        Unused for Gemini MVP.
	 * @param string|array $tool_choice  Unused for Gemini MVP.
	 * @param array        $options      Provider options (api_key, model, endpoint).
	 * @return array|WP_Error
	 */
	public function send_chat( array $messages, array $tools = array(), $tool_choice = 'auto', array $options = array() ) {
		unset( $tools, $tool_choice ); // Not used in MVP.

		$api_key  = isset( $options['api_key'] ) ? (string) $options['api_key'] : '';
		$model    = isset( $options['model'] ) ? (string) $options['model'] : 'gemini-1.5-pro';
		$endpoint = isset( $options['endpoint'] ) && ! empty( $options['endpoint'] )
			? (string) $options['endpoint']
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
			$role    = isset( $msg['role'] ) ? (string) $msg['role'] : 'user';
			$content = isset( $msg['content'] ) ? (string) $msg['content'] : '';

			if ( '' === $content ) {
				continue;
			}

			if ( 'system' === $role ) {
				$system_prompts[] = $content;
				continue;
			}

			// Gemini roles: "user" or "model".
			$gemini_role = 'user';
			if ( 'assistant' === $role ) {
				$gemini_role = 'model';
			} elseif ( 'user' === $role ) {
				$gemini_role = 'user';
			} else {
				// Fallback any unknown role to 'user'.
				$gemini_role = 'user';
			}

			$contents[] = array(
				'role'  => $gemini_role,
				'parts' => array(
					array( 'text' => $content ),
				),
			);
		}

		$body = array(
			'contents'         => $contents,
			'generationConfig' => array(
				'temperature' => 0.7,
			),
		);

		if ( ! empty( $system_prompts ) ) {
			$body['systemInstruction'] = array(
				'role'  => 'system',
				'parts' => array(
					array( 'text' => implode( "\n\n", $system_prompts ) ),
				),
			);
		}

		// Ensure API key is appended as query string parameter (?key=...).
		$request_url = add_query_arg( array( 'key' => $api_key ), $endpoint );

		$response = wp_remote_post(
			$request_url,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 45,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mcpress_llm_api_error',
				esc_html__( 'Failed to connect to Gemini API: ', 'mcpress' ) . $response->get_error_message(),
				array( 'status' => 'http_error' )
			);
		}

		$http_code     = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$decoded_body  = json_decode( $response_body, true );

		// Gemini errors may come with HTTP 4xx/5xx and/or an "error" structure in response.
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

		// Extract content from first candidate.
		$content_text = '';
		if ( isset( $decoded_body['candidates'][0]['content']['parts'] ) && is_array( $decoded_body['candidates'][0]['content']['parts'] ) ) {
			foreach ( $decoded_body['candidates'][0]['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
					$content_text .= $part['text'];
				}
			}
		}

		return array(
			'content'    => $content_text,
			'tool_calls' => array(), // Tools not supported in this MVP.
			'raw'        => $decoded_body,
		);
	}
}
