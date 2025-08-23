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

	/**
	 * Stream a chat/completions request via an OpenAI-compatible API.
	 *
	 * This forwards SSE chunks from the provider and invokes $on_chunk for each
	 * piece of data. The callback is expected to accept an associative array with
	 * keys like:
	 * - [ 'type' => 'delta', 'content' => '...' ]
	 * - [ 'type' => 'tool_call_delta', 'tool_calls' => [...] ]
	 *
	 * @param array         $messages     Normalized messages.
	 * @param array         $tools        Optional tool schemas (OpenAI-style).
	 * @param string|array  $tool_choice  Optional tool choice.
	 * @param array         $options      Provider options (endpoint, api_key, model, etc.).
	 * @param callable|null $on_chunk     Callback invoked for each streamed piece.
	 * @return array|WP_Error             ['streamed' => true] on success or WP_Error.
	 */
	public function stream_chat( array $messages, array $tools = array(), $tool_choice = 'auto', array $options = array(), callable $on_chunk = null ) {
		$endpoint = isset( $options['endpoint'] ) && ! empty( $options['endpoint'] ) ? (string) $options['endpoint'] : '';
		$api_key  = isset( $options['api_key'] ) ? (string) $options['api_key'] : '';
		$model    = isset( $options['model'] ) ? (string) $options['model'] : 'gpt-5';

		if ( empty( $endpoint ) || empty( $api_key ) ) {
			return new WP_Error(
				'mcpress_provider_config_missing',
				esc_html__( 'OpenAI-compatible endpoint or API key is not configured.', 'mcpress' )
			);
		}

		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error(
				'mcpress_curl_missing',
				esc_html__( 'cURL is not available for streaming requests.', 'mcpress' )
			);
		}

		$body = array(
			'model'       => $model,
			'messages'    => $messages,
			'temperature' => 0.7,
			'stream'      => true,
		);

		if ( ! empty( $tools ) ) {
			$body['tools'] = $tools;
		}
		if ( ! empty( $tool_choice ) ) {
			$body['tool_choice'] = $tool_choice;
		}

		$ch = curl_init( $endpoint ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
		curl_setopt_array(  // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
			$ch,
			array(
				CURLOPT_POST           => true,
				CURLOPT_HTTPHEADER     => array(
					'Content-Type: application/json',
					'Authorization: Bearer ' . $api_key,
				),
				CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
				CURLOPT_RETURNTRANSFER => false, // stream to callback.
				CURLOPT_TIMEOUT        => 0,     // let it stream.
				CURLOPT_WRITEFUNCTION  => function ( $curl, $chunk ) use ( $on_chunk ) {
					static $buffer = '';
					$buffer       .= $chunk;

					// Process SSE frames, handling both "\n\n" and "\r\n\r\n" separators.
					while ( true ) {
						$pos = strpos( $buffer, "\n\n" );
						$sep = "\n\n";
						if ( false === $pos ) {
							$pos = strpos( $buffer, "\r\n\r\n" );
							$sep = "\r\n\r\n";
						}
						if ( false === $pos ) {
							break;
						}

						$frame  = substr( $buffer, 0, $pos );
						$buffer = substr( $buffer, $pos + strlen( $sep ) );

						$lines = preg_split( "/\r?\n/", trim( (string) $frame ) );
						if ( ! is_array( $lines ) ) {
							continue;
						}

						foreach ( $lines as $line ) {
							$line = trim( (string) $line );
							if ( '' === $line ) {
								continue;
							}
							if ( 0 !== strpos( $line, 'data:' ) ) {
								continue;
							}
							$payload = trim( substr( $line, 5 ) );

							if ( '[DONE]' === $payload ) {
								// Upstream signals end; caller will emit a final "done".
								continue;
							}

							$decoded = json_decode( $payload, true );
							if ( ! is_array( $decoded ) ) {
								continue;
							}

							$choice = isset( $decoded['choices'][0] ) ? $decoded['choices'][0] : null;
							if ( empty( $choice ) || ! is_array( $choice ) ) {
								continue;
							}

							$delta = isset( $choice['delta'] ) ? $choice['delta'] : array();

							// Stream content deltas.
							if ( array_key_exists( 'content', $delta ) && null !== $delta['content'] ) {
								if ( is_callable( $on_chunk ) ) {
									$on_chunk(
										array(
											'type'    => 'delta',
											'content' => (string) $delta['content'],
										)
									);
								}
							}

							// Optional: stream tool_call deltas.
							if ( isset( $delta['tool_calls'] ) && is_array( $delta['tool_calls'] ) ) {
								if ( is_callable( $on_chunk ) ) {
									$on_chunk(
										array(
											'type'       => 'tool_call_delta',
											'tool_calls' => $delta['tool_calls'],
										)
									);
								}
							}
						}
					}

					return strlen( $chunk );
				},
				CURLOPT_HEADERFUNCTION => function ( $ch_ref, $header_line ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
					return strlen( (string) $header_line );
				},
			)
		);

		$ok = curl_exec( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
		if ( false === $ok ) {
			$err = curl_error( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error
			curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
			return new WP_Error(
				'mcpress_llm_api_error',
				esc_html__( 'Failed to stream from provider: ', 'mcpress' ) . $err
			);
		}
		curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close

		return array( 'streamed' => true );
	}
}
