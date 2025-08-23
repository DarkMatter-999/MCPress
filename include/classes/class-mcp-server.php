<?php
/**
 * Main Class file for REST API for the plugin.
 *
 * @package MCPress
 */

namespace MCPress;

use MCPress\Traits\Singleton;
use MCPress\MCP_LLM_API;
use MCPress\Provider_Registry;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

/**
 * MCP Server Class
 * Manages custom REST API endpoints for the MCP.
 */
class MCP_Server {
	use Singleton;

	const REST_NAMESPACE = 'mcp/v1';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_streaming_response' ), 10, 4 );
	}

	/**
	 * Register custom REST API routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/chat',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'chat_callback' ),
				'permission_callback' => array( $this, 'permission_check_read' ),
				'args'                => array(),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/chat-init',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'chat_init_callback' ),
				'permission_callback' => array( $this, 'permission_check_read' ),
				'args'                => array(),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/execute-tool',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'execute_tool_callback' ),
				'permission_callback' => array( $this, 'permission_check_read' ),
				'args'                => array(
					'tool_calls' => array(
						'required'          => true,
						'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
							return is_array( $param );
						},
						'sanitize_callback' => null, // Tools data is complex, let LLM API handle structure.
					),
					'messages'   => array(
						'required'          => true,
						'validate_callback' => function ( $param, $request, $key ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
							return is_array( $param );
						},
						'sanitize_callback' => null, // Messages array is validated by LLM API.
					),
				),
			)
		);
	}

	/**
	 * Permission callback: Check if current user can read.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return bool True if the user has read capability, false otherwise.
	 */
	public function permission_check_read( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return is_user_logged_in() && current_user_can( 'read' );
	}

	/**
	 * Routes a chat request through the currently selected provider.
	 *
	 * @param array  $messages The conversation messages array.
	 * @param array  $tools Optional. An array of tool definitions.
	 * @param string $tool_choice Optional. 'auto', 'none', or {'type': 'function', 'function': {'name': 'my_function'}}.
	 * @return array|WP_Error Normalized provider response or WP_Error.
	 */
	private function make_llm_request( $messages, $tools = array(), $tool_choice = 'auto' ) {
		$registry = Provider_Registry::get_instance();
		return $registry->send_chat( $messages, $tools, $tool_choice );
	}

	/**
	 * Callback for /chat-init endpoint.
	 * Returns the initial system prompt and context.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response object.
	 */
	public function chat_init_callback( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable

		/*
		* Translators:
		* 1: WordPress site name (e.g., "My Blog").
		* 2: WordPress version number (e.g., "6.8.2").
		* 3: WordPress site URL (e.g., "https://example.com").
		* 4: Comma-separated list of current user capabilities (e.g., "read, edit_posts").
		*/
		$system_prompt = esc_html__( 'You are a helpful AI assistant for a WordPress site named "%1$s" running version %2$s. The site URL is %3$s. The current user has capabilities: %4$s. Your purpose is to assist the user with tasks related to WordPress. You can use available tools to interact with the WordPress environment. Always respond in Markdown format. DO NOT USE TOOLS UNECESSARILY', 'mcpress' );

		$initial_messages = array(
			array(
				'role'    => 'system',
				'content' => sprintf(
					$system_prompt,
					get_bloginfo( 'name' ),
					get_bloginfo( 'version' ),
					site_url(),
					implode( ', ', array_keys( wp_get_current_user()->allcaps ) )
				),
			),
		);

		return new WP_REST_Response(
			array(
				'success'                 => true,
				'messages'                => $initial_messages,
				'display_initial_message' => esc_html__( 'Welcome to the LLM Chat. I am ready to assist.', 'mcpress' ),
			),
			200
		);
	}

	/**
	 * Callback for /chat endpoint.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response object.
	 */
	public function chat_callback( WP_REST_Request $request ) {
		$messages = $request->get_param( 'messages' ); // Expecting an array of messages.

		if ( ! is_array( $messages ) || empty( $messages ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => esc_html__( 'Messages array cannot be empty.', 'mcpress' ),
				),
				400
			);
		}

		// Detailed validation of each message object (role, content) might be added later.

		$llm_api = MCP_LLM_API::get_instance();
		$tools   = $llm_api->schemas; // Tool schemas for the LLM.

		// Streaming detection: Accept header, explicit header, or query param.
		$is_stream     = false;
		$accept_header = (string) $request->get_header( 'accept' );
		if ( false !== stripos( $accept_header, 'text/event-stream' ) ) {
			$is_stream = true;
		}
		$explicit_stream = (string) $request->get_header( 'x-mcpress-stream' );
		if ( '1' === $explicit_stream ) {
			$is_stream = true;
		}
		$stream_param = (string) $request->get_param( 'stream' );
		if ( '1' === $stream_param ) {
			$is_stream = true;
		}

		if ( $is_stream ) {
			// Stash payload for streaming responder; it will take over in rest_pre_serve_request.
			$request->set_param(
				'__mcpress_stream_payload',
				array(
					'messages'    => $messages,
					'tools'       => $tools,
					'tool_choice' => 'auto',
				)
			);
			return new WP_REST_Response( null, 200 );
		}

		// First LLM call (non-streaming).
		$llm_response = $this->make_llm_request( $messages, $tools );

		if ( is_wp_error( $llm_response ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $llm_response->get_error_message(),
				),
				500
			);
		}

		$response_content = isset( $llm_response['content'] ) ? $llm_response['content'] : '';
		$tool_calls       = isset( $llm_response['tool_calls'] ) ? $llm_response['tool_calls'] : array();

		// Handle tool calls.
		if ( ! empty( $tool_calls ) ) {
			$messages[] = array(
				'role'       => 'assistant',
				'tool_calls' => $tool_calls,
			);

			return new WP_REST_Response(
				array(
					'success'               => true,
					'message'               => esc_html__( 'I am suggesting to use a tool to help you with your request.', 'mcpress' ),
					'tool_calls'            => $tool_calls,
					'messages'              => $messages,
					'requires_confirmation' => true,
				),
				200
			);
		} else {
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => $response_content,
				),
				200
			);
		}
	}

	/**
	 * Streaming response server for /chat when requested via headers.
	 *
	 * Accumulates tool_call_delta events into full tool_calls before emitting a final
	 * tool_calls event, so the frontend can confirm execution with complete arguments.
	 *
	 * @param mixed           $served  Whether the request has already been served.
	 * @param mixed           $result  Result to send to the client. Ignored for streaming.
	 * @param WP_REST_Request $request The request.
	 * @param WP_REST_Server  $server  Server instance.
	 * @return bool True if we streamed the response; original $served otherwise.
	 */
	public function serve_streaming_response( $served, $result, $request, $server ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$payload = $request->get_param( '__mcpress_stream_payload' );
		if ( empty( $payload ) ) {
			return $served;
		}

		nocache_headers();
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-transform' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );
		header( 'Content-Encoding: none' );

		@ini_set( 'implicit_flush', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
		ob_implicit_flush( true );
		ignore_user_abort( true );
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		@ini_set( 'output_buffering', 'off' ); // phpcs:ignore WordPress.PHP.IniSet.Risky, WordPress.PHP.NoSilencedErrors.Discouraged,
		@ini_set( 'zlib.output_compression', 0 ); // phpcs:ignore WordPress.PHP.IniSet.Risky, WordPress.PHP.NoSilencedErrors.Discouraged

		$send = function ( array $event ) {
			$type = isset( $event['type'] ) ? $event['type'] : 'message';
			echo 'event: ' . $type . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output comes from LLM directly.
			echo 'data: ' . wp_json_encode( $event ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output comes from LLM directly.
			flush();
		};

		echo ':' . str_repeat( ' ', 2048 ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Constant string output.
		flush();

		$registry = Provider_Registry::get_instance();
		$provider = $registry->get_current_provider();
		$options  = $registry->get_current_provider_options();

		// Accumulator for assembling complete tool calls from streaming deltas (indexed by tool call index).
		$tool_call_accumulator = array();

		$on_chunk = function ( array $chunk ) use ( $send, &$tool_call_accumulator ) {
			// Capture tool call deltas to build a final list.
			if ( isset( $chunk['type'] ) && 'tool_call_delta' === $chunk['type'] && ! empty( $chunk['tool_calls'] ) && is_array( $chunk['tool_calls'] ) ) {
				foreach ( $chunk['tool_calls'] as $delta_call ) {
					$idx = isset( $delta_call['index'] ) ? $delta_call['index'] : null;
					if ( null === $idx ) {
						continue;
					}
					if ( ! isset( $tool_call_accumulator[ $idx ] ) ) {
						$tool_call_accumulator[ $idx ] = array(
							'id'       => isset( $delta_call['id'] ) ? $delta_call['id'] : '',
							'type'     => isset( $delta_call['type'] ) ? $delta_call['type'] : 'function',
							'function' => array(
								'name'      => isset( $delta_call['function']['name'] ) ? $delta_call['function']['name'] : '',
								'arguments' => '',
							),
						);
					}
					// Update ID if provided.
					if ( isset( $delta_call['id'] ) && '' !== $delta_call['id'] ) {
						$tool_call_accumulator[ $idx ]['id'] = $delta_call['id'];
					}
					// Update name if present.
					if ( isset( $delta_call['function']['name'] ) && '' !== $delta_call['function']['name'] ) {
						$tool_call_accumulator[ $idx ]['function']['name'] = $delta_call['function']['name'];
					}
					// Append argument fragment.
					if ( isset( $delta_call['function']['arguments'] ) && '' !== $delta_call['function']['arguments'] ) {
						$tool_call_accumulator[ $idx ]['function']['arguments'] .= $delta_call['function']['arguments'];
					}
				}
			}

			// Forward original chunk for live updates (content or deltas).
			$send( $chunk );
		};

		if ( $provider && method_exists( $provider, 'stream_chat' ) ) {
			$result = $provider->stream_chat(
				(array) $payload['messages'],
				isset( $payload['tools'] ) ? (array) $payload['tools'] : array(),
				$payload['tool_choice'] ?? 'auto',
				$options,
				$on_chunk
			);

			if ( is_wp_error( $result ) ) {
				$send(
					array(
						'type'    => 'error',
						'message' => $result->get_error_message(),
					)
				);
			} elseif ( ! empty( $tool_call_accumulator ) ) {
				// Emit consolidated tool_calls after streaming if any were built.
				ksort( $tool_call_accumulator );
				$final_tool_calls = array_values( $tool_call_accumulator );
				$send(
					array(
						'type'       => 'tool_calls',
						'tool_calls' => $final_tool_calls,
					)
				);
			}

			$send( array( 'type' => 'done' ) );
			// Terminate execution after streaming to avoid further header modifications by REST server.
			exit;
		}

		// Fallback: non-streaming single response.
		$result = $registry->send_chat(
			(array) $payload['messages'],
			isset( $payload['tools'] ) ? (array) $payload['tools'] : array(),
			$payload['tool_choice'] ?? 'auto',
			$options
		);

		if ( is_wp_error( $result ) ) {
			$send(
				array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				)
			);
		} else {
			$send(
				array(
					'type'    => 'delta',
					'content' => (string) ( $result['content'] ?? '' ),
				)
			);
			if ( ! empty( $result['tool_calls'] ) ) {
				$send(
					array(
						'type'       => 'tool_calls',
						'tool_calls' => $result['tool_calls'],
					)
				);
			}
		}
		$send( array( 'type' => 'done' ) );
		return true;
	}

	/**
	 * Callback for /execute-tool endpoint.
	 * Executes the tool and makes a follow-up LLM request.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response object.
	 */
	public function execute_tool_callback( WP_REST_Request $request ) {
		$tool_calls = $request->get_param( 'tool_calls' );
		$messages   = $request->get_param( 'messages' );

		if ( ! is_array( $tool_calls ) || empty( $tool_calls ) || ! is_array( $messages ) || empty( $messages ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => esc_html__( 'Invalid tool calls or messages provided for execution.', 'mcpress' ),
				),
				400
			);
		}

		$llm_api         = MCP_LLM_API::get_instance();
		$tools           = $llm_api->schemas;     // Tool schemas for the LLM.
		$available_tools = $llm_api->tools;     // PHP callable tools.

		foreach ( $tool_calls as $tool_call ) {
			$function_name = $tool_call['function']['name'];
			$function_args = json_decode( $tool_call['function']['arguments'], true );
			$tool_output   = null;

			// Execute the tool using the WordPress filter.
			if ( in_array( $function_name, $available_tools, true ) ) {
				$tool_output = apply_filters( 'mcpress_tool_call_' . $function_name, $function_args );
			} else {
				/*
				* Translators: %s: The name of the tool function that was not found.
				*/
				$error_message = esc_html__( 'Tool "%s" not found or not registered.', 'mcpress' );
				$tool_output   = array(
					'message' => sprintf( $error_message, $function_name ),
					'status'  => 'error',
				);
			}

			// Convert the tool output to a suitable string for the LLM.
			$tool_output_content = '';
			if ( is_wp_error( $tool_output ) ) {
				$tool_output_content = $tool_output->get_error_message();
			} elseif ( is_array( $tool_output ) || is_object( $tool_output ) ) {
				if ( isset( $tool_output['message'] ) ) {
					$tool_output_content = $tool_output['message'];
				} else {
					$tool_output_content = wp_json_encode( $tool_output );
				}
			} else {
				$tool_output_content = (string) $tool_output;
			}

			/*
			* Translators:
			* 1: Name of the tool function.
			* 2: Tool output content.
			*/
			$tool_summary_message = esc_html__( 'Tool "%1$s" executed. Output: %2$s', 'mcpress' );
			$tool_summary_message = sprintf(
				$tool_summary_message,
				$function_name,
				$tool_output_content
			);

			// Add tool outputs to messages history as a user message to maintain alternation.
			$messages[] = array(
				'role'    => 'user',
				'content' => $tool_summary_message,
			);
		}

		// Make the second LLM call with the tool output.
		$second_llm_response = $this->make_llm_request( $messages, $tools );

		if ( is_wp_error( $second_llm_response ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $second_llm_response->get_error_message(),
				),
				500
			);
		}

		$final_response_content = isset( $second_llm_response['content'] ) ? $second_llm_response['content'] : '';

		if ( empty( $final_response_content ) ) {
			$final_response_content = esc_html__( 'Tool execution completed and LLM did not provide a follow-up response.', 'mcpress' );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => $final_response_content,
			),
			200
		);
	}
}
