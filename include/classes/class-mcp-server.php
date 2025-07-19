<?php
/**
 * Main Class file for REST API for the plugin.
 *
 * @package MCPress
 */

namespace MCPress;

use MCPress\Traits\Singleton;
use MCPress\MCP_LLM_API;
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
	}

	/**
	 * Permission callback: Check if current user can read.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return bool True if the user has read capability, false otherwise.
	 */
	public function permission_check_read( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return is_user_logged_in() && current_user_can( 'read' );
	}

	/**
	 * Makes a request to the LLM API.
	 *
	 * @param array  $messages The conversation messages array.
	 * @param array  $tools Optional. An array of tool definitions.
	 * @param string $tool_choice Optional. 'auto', 'none', or {'type': 'function', 'function': {'name': 'my_function'}}.
	 * @return array|WP_Error Decoded LLM response or WP_Error.
	 */
	private function make_llm_request( $messages, $tools = array(), $tool_choice = 'auto' ) {
		$api_endpoint = get_option( MCP_LLM_API::OPENAI_API_ENDPOINT );
		$api_key      = get_option( MCP_LLM_API::OPENAI_API_KEY );

		if ( empty( $api_endpoint ) || empty( $api_key ) ) {
			return new WP_Error(
				'mcp_llm_config_missing',
				esc_html__( 'LLM API endpoint or key is not configured.', 'mcpress' )
			);
		}

		$body = array(
			'model'       => 'gpt-3.5-turbo',
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

		$response = wp_remote_post(
			$api_endpoint,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
				'timeout' => 45, // Increased timeout for external API calls.
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mcp_llm_api_error',
				esc_html__( 'Failed to connect to LLM API: ', 'mcpress' ) . $response->get_error_message(),
				array( 'status' => 'http_error' )
			);
		}

		$http_code     = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$decoded_body  = json_decode( $response_body, true );

		if ( 200 !== $http_code ) {
			$error_message = isset( $decoded_body['error']['message'] ) ? $decoded_body['error']['message'] : esc_html__( 'Unknown LLM API error.', 'mcpress' );

			/*
			* Translators:
			* 1: HTTP status code (e.g., 400, 500).
			* 2: Specific error message from the LLM API.
			*/
			$result = sprintf( esc_html__( 'LLM API returned HTTP %1$d: %2$s', 'mcpress' ), $http_code, $error_message );
			return new WP_Error(
				'mcp_llm_api_http_error',
				$result,
				array(
					'status'  => $http_code,
					'details' => $decoded_body,
				)
			);
		}

		return $decoded_body;
	}

	/**
	 * Callback for /chat endpoint.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response object.
	 */
	public function chat_callback( WP_REST_Request $request ) {
		$user_message = sanitize_textarea_field( $request->get_param( 'message' ) );

		if ( empty( $user_message ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => esc_html__( 'Message cannot be empty.', 'mcpress' ),
				),
				400
			);
		}

		$llm_api         = MCP_LLM_API::get_instance();
		$tools           = $llm_api->schemas; // Tool schemas for the LLM.
		$available_tools = $llm_api->tools; // PHP callable tools.

		/*
		* Translators:
		* 1: WordPress site name (e.g., "My Blog").
		* 2: WordPress version number (e.g., "6.8.2").
		* 3: WordPress site URL (e.g., "https://example.com").
		* 4: Comma-separated list of current user capabilities (e.g., "read, edit_posts").
		*/
		$system_prompt = esc_html__( 'You are a helpful AI assistant for a WordPress site named "%1$s" running version %2$s. The site URL is %3$s. The current user has capabilities: %4$s. Your purpose is to assist the user with tasks related to WordPress. You can use available tools to interact with the WordPress environment. Always respond in Markdown format. DO NOT USE TOOLS UNECESSARILY', 'mcpress' );

		// Initial messages for the LLM.
		$messages = array(
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
			array(
				'role'    => 'user',
				'content' => $user_message,
			),
		);

		// First LLM call.
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

		$response_content = isset( $llm_response['choices'][0]['message']['content'] ) ? $llm_response['choices'][0]['message']['content'] : '';
		$tool_calls       = isset( $llm_response['choices'][0]['message']['tool_calls'] ) ? $llm_response['choices'][0]['message']['tool_calls'] : array();

		// Handle tool calls.
		if ( ! empty( $tool_calls ) ) {
			$tool_execution_results = array();

			// Add the assistant's tool_calls message to history for the next LLM call.
			$messages[] = array(
				'role'       => 'assistant',
				'tool_calls' => $tool_calls,
			);

			foreach ( $tool_calls as $tool_call ) {
				$function_name = $tool_call['function']['name'];
				$function_args = json_decode( $tool_call['function']['arguments'], true );

				// Execute the tool using the WordPress filter.
				// The tool's `tool` method (from LLM_Tool trait) is hooked to `mcpress_tool_call_{$name}`.
				if ( in_array( $function_name, $available_tools, true ) ) {
					$tool_output              = apply_filters( 'mcpress_tool_call_' . $function_name, $function_args );
					$tool_execution_results[] = array(
						'tool_call_id' => $tool_call['id'],
						'content'      => wp_json_encode( $tool_output ),
					);
				} else {
					/*
					* Translators:
					* 1: Name of the tool function that was not found or registered.
					*/
					$error_message            = esc_html__( 'Tool "%s" not found or not registered.', 'mcpress' );
					$error_message            = sprintf(
						$error_message,
						$function_name
					);
					$tool_execution_results[] = array(
						'tool_call_id' => $tool_call['id'],
						'content'      => $error_message,
					);
				}
			}

			// Add tool outputs to messages history.
			foreach ( $tool_execution_results as $result ) {
				$messages[] = array(
					'role'         => 'tool',
					'tool_call_id' => $result['tool_call_id'],
					'content'      => $result['content'],
				);
			}

			// Make a second LLM call with tool output.
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

			$second_response_content = isset( $second_llm_response['choices'][0]['message']['content'] ) ? $second_llm_response['choices'][0]['message']['content'] : '';

			$final_response_message = $second_response_content;

			if ( empty( $final_response_message ) ) {
				$final_response_message = esc_html__( 'Tool execution completed and LLM did not provide a follow-up response.', 'mcpress' );
			}
		} else {
			// No tool calls, just normal LLM response.
			$final_response_message = $response_content;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => $final_response_message,
			),
			200
		);
	}
}
