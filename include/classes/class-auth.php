<?php
/**
 * Main Auth Class for the Plugin.
 *
 * @package MCPress
 */

namespace MCPress;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Dotenv\Dotenv;
use MCPress\Traits\Singleton;

/**
 * Main Auth Class for the Plugin.
 */
class Auth {
	use Singleton;

	/**
	 * The Dotenv instance.
	 *
	 * @var Dotenv|null
	 */
	public static $dotenv = null;

	/**
	 * The JWT secret key.
	 *
	 * @var string
	 */
	private static $jwt_secret = '';

	/**
	 * The JWT algorithm.
	 *
	 * @var string
	 */
	private static $jwt_alg = '';

	/**
	 * The REST API namespace.
	 *
	 * @var string
	 */
	public static $namespace = MCP_Server::REST_NAMESPACE;

	/**
	 * Constructor for the Auth Class.
	 *
	 * @return void
	 * @throws \Exception If JWT_SECRET_KEY is not defined in the .env file.
	 */
	public function __construct() {
		static::$dotenv = Dotenv::createImmutable( MCP_PLUGIN_PATH );
		static::$dotenv->load();

		if ( isset( $_ENV['JWT_SECRET_KEY'] ) && ! empty( $_ENV['JWT_SECRET_KEY'] ) ) {
			static::$jwt_secret = sanitize_text_field( $_ENV['JWT_SECRET_KEY'] );
		} else {
			throw new \Exception( 'JWT_SECRET_KEY is not defined in the .env file.' );
		}

		static::$jwt_alg = isset( $_ENV['JWT_ALG'] ) && ! empty( $_ENV['JWT_ALG'] ) ? sanitize_text_field( $_ENV['JWT_ALG'] ) : 'HS256';

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		add_filter( 'rest_authentication_errors', array( $this, 'maybe_authenticate_jwt' ), 10 );
	}

	/**
	 * Registers the REST API routes for authentication.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Login route.
		register_rest_route(
			static::$namespace,
			'/login',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'auth_callback' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handles the authentication callback for the login route.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response The response object.
	 */
	public function auth_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$creds    = $request->get_json_params();
		$username = sanitize_user( $creds['username'] );
		$password = $creds['password'];

		$user = wp_authenticate( $username, $password );

		if ( is_wp_error( $user ) ) {
			return new \WP_REST_Response( array( 'error' => 'Invalid credentials' ), 403 );
		}

		$token = JWT::encode(
			array(
				'iss'  => get_bloginfo( 'url' ),
				'iat'  => time(),
				'exp'  => time() + DAY_IN_SECONDS * 7,
				'data' => array(
					'user_id' => $user->ID,
				),
			),
			static::$jwt_secret,
			static::$jwt_alg
		);

		return new \WP_REST_Response(
			array(
				'token'      => $token,
				'user_id'    => $user->ID,
				'user_email' => $user->user_email,
				'user_name'  => $user->display_name,
			)
		);
	}

	/**
	 * Permission check for JWT authenticated routes.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return bool|\WP_Error True if the user has permission, WP_Error otherwise.
	 */
	public function permission_check( \WP_REST_Request $request ) {
		$auth_header = $request->get_header( 'Authorization' );

		if ( empty( $auth_header ) ) {
			return new \WP_Error(
				'jwt_auth_no_auth_header',
				__( 'Authorization header not found.', 'mcpress' ),
				array( 'status' => 401 )
			);
		}

		if ( ! preg_match( '/Bearer\s(\S+)/', $auth_header, $matches ) ) {
			return new \WP_Error(
				'jwt_auth_bad_auth_header',
				__( 'Authorization header is malformed.', 'mcpress' ),
				array( 'status' => 401 )
			);
		}

		$token = $matches[1];

		try {
			$decoded_token = JWT::decode( $token, new Key( static::$jwt_secret, static::$jwt_alg ) );

			$user_id = $decoded_token->data->user_id ?? null;
			$user    = null;

			if ( $user_id ) {
				$user = get_user_by( 'id', $user_id );
			}

			if ( ! $user || ! $user->exists() ) {
				return new \WP_Error(
					'jwt_auth_invalid_user',
					__( 'Invalid user in token.', 'mcpress' ),
					array( 'status' => 403 )
				);
			}

			wp_set_current_user( $decoded_token->data->user_id );

			return true;

		} catch ( \Firebase\JWT\ExpiredException $e ) {
			return new \WP_Error(
				'jwt_auth_token_expired',
				__( 'Access token expired.', 'mcpress' ),
				array( 'status' => 403 )
			);
		} catch ( \Firebase\JWT\SignatureInvalidException $e ) {
			return new \WP_Error(
				'jwt_auth_signature_invalid',
				__( 'Signature verification failed.', 'mcpress' ),
				array( 'status' => 403 )
			);
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'jwt_auth_invalid_token',
				__( 'Invalid access token.', 'mcpress' ),
				array( 'status' => 403 )
			);
		}
	}

	/**
	 * Optional JWT authentication for REST requests.
	 *
	 * @param mixed $result Current authentication result.
	 * @return mixed WP_Error|true|null
	 */
	public function maybe_authenticate_jwt( $result ) {
		if ( ! empty( $result ) ) {
			// Another authentication method already ran.
			return $result;
		}

		$auth_header = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth_header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$auth_header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		if ( empty( $auth_header ) ) {
			// No Authorization header — allow default WordPress behavior.
			return null;
		}

		if ( ! preg_match( '/^Bearer\s+([A-Za-z0-9\-\._~\+\/]+=*)$/', $auth_header, $matches ) ) {
			return new \WP_Error(
				'jwt_auth_bad_auth_header',
				__( 'Authorization header is malformed.', 'mcpress' ),
				array( 'status' => 401 )
			);
		}

		$token = $matches[1];

		try {
			$decoded_token = JWT::decode( $token, new Key( static::$jwt_secret, static::$jwt_alg ) );
			$user_id       = $decoded_token->data->user_id ?? null;

			if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
				return new \WP_Error(
					'jwt_auth_invalid_user',
					__( 'Invalid user in token.', 'mcpress' ),
					array( 'status' => 403 )
				);
			}

			wp_set_current_user( $user_id );

			return true;
		} catch ( \Firebase\JWT\ExpiredException $e ) {
			return new \WP_Error( 'jwt_auth_token_expired', __( 'Access token expired.', 'mcpress' ), array( 'status' => 403 ) );
		} catch ( \Firebase\JWT\SignatureInvalidException $e ) {
			return new \WP_Error( 'jwt_auth_signature_invalid', __( 'Signature verification failed.', 'mcpress' ), array( 'status' => 403 ) );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'jwt_auth_invalid_token', __( 'Invalid access token.', 'mcpress' ), array( 'status' => 403 ) );
		}
	}
}
