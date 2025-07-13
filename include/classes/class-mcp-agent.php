<?php
/**
 * Main Agent Class File
 *
 * @package MCPress
 **/

namespace MCPress;

use MCPress\Traits\Singleton;

use NeuronAI\Agent;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\SystemPrompt;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Tool;
use WP_Application_Passwords;

/**
 * MCP_Agent Class
 */
class MCP_Agent extends Agent {

	use Singleton;

	/**
	 * AI Provider
	 *
	 * @return AIProviderInterface
	 */
	public function provider(): AIProviderInterface {
		return new Anthropic(
			key: 'ANTHROPIC_API_KEY',
			model: 'ANTHROPIC_MODEL',
		);
	}

	/**
	 * Instructions for the Agent
	 *
	 * @return string
	 */
	public function instructions(): string {
		return new SystemPrompt(
			array(
				'background' => 'You are an intelligent AI agent designed to interact with a WordPress website.',
				'steps'      => array(
					'Your primary goal is to help users manage and create content on the WordPress site.',
					'You can create new posts, manage existing posts, and retrieve information from the site.',
					"When asked to create content, ensure it's well-structured and relevant to the topic.",
					'If a user asks for information, retrieve it accurately and concisely from the WordPress site.',
					'If a request involves creating or updating content, ensure you ask for all necessary details (e.g., post title, content, categories, tags) before attempting the action.',
				),
				'output'     => 'Provide clear and concise responses, confirming actions taken or explaining why an action cannot be performed. Use friendly and professional language.',
			)
		);
	}

	/**
	 * Tools available to the Agent
	 *
	 * @return array
	 */
	public function tools(): array {
		$base         = trailingslashit( home_url() );
		$current_user = wp_get_current_user();
		if ( $current_user->exists() ) {
			$username = $current_user->user_login ?? '';
		}
		$app_password = WP_Application_Passwords::create_new_application_password(
			$current_user->ID,
			array(
				'name' => 'MCPress Application Password',
			)
		);

		return array(
			...McpConnector::make(
				array(
					'command' => 'npx',
					'args'    => array( '-y', '@adi.lib/wp-mcp' ),
					'env'     => array(
						'WP_BASE_URL'     => $base,
						'WP_USERNAME'     => $username,
						'WP_APP_PASSWORD' => $app_password,
					),
				)
			)->tools(),
		);
	}
}
