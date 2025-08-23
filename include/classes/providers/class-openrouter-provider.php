<?php
/**
 * OpenRouter Provider
 *
 * Implements a provider that communicates with OpenRouter's OpenAI-compatible
 * Chat Completions API. It uses the Provider trait to register itself and
 * exposes a unified send_chat() interface for MCPress.
 *
 * @package MCPress
 */

namespace MCPress\Providers;

use MCPress\Traits\LLM_Provider;
use MCPress\Traits\Singleton;

/**
 * OpenRouter Provider
 *
 * Implements a provider that communicates with OpenRouter's OpenAI-compatible
 * Chat Completions API. Exposes a unified send_chat() interface for MCPress.
 *
 * @package MCPress
 */
class OpenRouter_Provider {
	use Singleton;
	use LLM_Provider;

	const OPENROUTER_API_URL = 'https://openrouter.ai/api/v1/chat/completions';

	/**
	 * Constructor.
	 *
	 * Registers this provider during the mcpress_register_providers hook.
	 */
	public function __construct() {
		self::$id    = 'openrouter';
		self::$label = 'OpenRouter';

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
			'endpoint'     => array(
				'label'       => esc_html__( 'API Endpoint', 'mcpress' ),
				'type'        => 'url',
				'placeholder' => self::OPENROUTER_API_URL,
				'description' => esc_html__( 'OpenRouter Chat Completions endpoint URL. Leave default unless you know what you are doing.', 'mcpress' ),
			),
			'api_key'      => array(
				'label'       => esc_html__( 'API Key', 'mcpress' ),
				'type'        => 'password',
				'placeholder' => 'sk-or-v1-...',
				'description' => esc_html__( 'Your OpenRouter API key.', 'mcpress' ),
			),
			'model'        => array(
				'label'       => esc_html__( 'Model', 'mcpress' ),
				'type'        => 'text',
				'placeholder' => 'openrouter/auto',
				'description' => esc_html__( 'Model identifier (eg:, openrouter/auto or a specific model).', 'mcpress' ),
			),
			'http_referer' => array(
				'label'       => esc_html__( 'HTTP Referer (optional)', 'mcpress' ),
				'type'        => 'url',
				'placeholder' => 'https://your-site.example',
				'description' => esc_html__( 'Optional. Recommended by OpenRouter to identify your app.', 'mcpress' ),
			),
			'x_title'      => array(
				'label'       => esc_html__( 'X-Title (optional)', 'mcpress' ),
				'type'        => 'text',
				'placeholder' => 'MCPress',
				'description' => esc_html__( 'Optional. Human-readable app title for OpenRouter.', 'mcpress' ),
			),
		);
	}

	/**
	 * Execute a chat/completions request via the OpenRouter API.
	 *
	 * Normalized input and output ensure MCP server code does not care about provider details.
	 *
	 * @param array        $messages     Normalized messages array.
	 * @param array        $tools        Optional tool schemas (OpenAI-style).
	 * @param string|array $tool_choice  Optional tool choice.
	 * @param array        $options      Provider options (endpoint, api_key, model, http_referer, x_title).
	 * @return array|\WP_Error
	 */
	public function send_chat( array $messages, array $tools = array(), $tool_choice = 'auto', array $options = array() ) {
		$endpoint     = isset( $options['endpoint'] ) && ! empty( $options['endpoint'] ) ? (string) $options['endpoint'] : self::OPENROUTER_API_URL;
		$api_key      = isset( $options['api_key'] ) ? (string) $options['api_key'] : '';
		$model        = isset( $options['model'] ) ? (string) $options['model'] : 'openrouter/auto';
		$http_referer = isset( $options['http_referer'] ) ? (string) $options['http_referer'] : '';
		$x_title      = isset( $options['x_title'] ) ? (string) $options['x_title'] : '';

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'mcpress_provider_config_missing',
				esc_html__( 'OpenRouter API key is not configured.', 'mcpress' )
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

		$headers = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $api_key,
		);

		if ( ! empty( $http_referer ) ) {
			$headers['HTTP-Referer'] = $http_referer;
		}
		if ( ! empty( $x_title ) ) {
			$headers['X-Title'] = $x_title;
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
				'timeout' => 45,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'mcpress_llm_api_error',
				esc_html__( 'Failed to connect to OpenRouter API: ', 'mcpress' ) . $response->get_error_message(),
				array( 'status' => 'http_error' )
			);
		}

		$http_code     = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$decoded_body  = json_decode( $response_body, true );

		if ( 200 !== $http_code ) {
			$error_message = isset( $decoded_body['error']['message'] ) ? $decoded_body['error']['message'] : esc_html__( 'Unknown provider API error.', 'mcpress' );
			return new \WP_Error(
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
	 * Stream a chat/completions request via the OpenRouter API.
	 *
	 * Forwards SSE chunks and invokes $on_chunk for each piece of data, emitting
	 * OpenAI-style intermediary events:
	 *  - [ 'type' => 'delta', 'content' => '...' ]
	 *  - [ 'type' => 'tool_call_delta', 'tool_calls' => [ { index, id, type, function: { name, arguments } } ] ]
	 *
	 * @param array         $messages     Normalized messages array.
	 * @param array         $tools        Optional tool schemas.
	 * @param string|array  $tool_choice  Optional tool choice.
	 * @param array         $options      Provider options (endpoint, api_key, model, http_referer, x_title).
	 * @param callable|null $on_chunk     Callback for each streamed piece.
	 * @return array|\WP_Error            ['streamed' => true] on success or WP_Error on failure.
	 */
	public function stream_chat( array $messages, array $tools = array(), $tool_choice = 'auto', array $options = array(), callable $on_chunk = null ) {
		$endpoint     = isset( $options['endpoint'] ) && ! empty( $options['endpoint'] ) ? (string) $options['endpoint'] : self::OPENROUTER_API_URL;
		$api_key      = isset( $options['api_key'] ) ? (string) $options['api_key'] : '';
		$model        = isset( $options['model'] ) ? (string) $options['model'] : 'openrouter/auto';
		$http_referer = isset( $options['http_referer'] ) ? (string) $options['http_referer'] : '';
		$x_title      = isset( $options['x_title'] ) ? (string) $options['x_title'] : '';

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'mcpress_provider_config_missing',
				esc_html__( 'OpenRouter API key is not configured.', 'mcpress' )
			);
		}

		if ( ! function_exists( 'curl_init' ) ) {
			return new \WP_Error(
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

		$headers = array(
			'Content-Type: application/json',
			'Authorization: Bearer ' . $api_key,
		);
		if ( ! empty( $http_referer ) ) {
			$headers[] = 'HTTP-Referer: ' . $http_referer;
		}
		if ( ! empty( $x_title ) ) {
			$headers[] = 'X-Title: ' . $x_title;
		}

		$ch = curl_init( $endpoint ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
		if ( false === $ch ) {
			return new \WP_Error(
				'mcpress_llm_api_error',
				esc_html__( 'Failed to initialize cURL for OpenRouter streaming.', 'mcpress' )
			);
		}

		curl_setopt_array( // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
			$ch,
			array(
				CURLOPT_POST           => true,
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_TIMEOUT        => 0,
				CURLOPT_WRITEFUNCTION  => function ( $curl, $chunk ) use ( $on_chunk ) {
					static $buffer = '';
					$buffer       .= $chunk;

					// Split frames on double newlines (either \n\n or \r\n\r\n).
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
							if ( '' === $payload || '[DONE]' === $payload ) {
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
							// Content delta tokens.
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

							// Tool call deltas (OpenAI-style).
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
			return new \WP_Error(
				'mcpress_llm_api_error',
				esc_html__( 'Failed to stream from OpenRouter provider: ', 'mcpress' ) . $err
			);
		}
		curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close

		return array( 'streamed' => true );
	}
}
