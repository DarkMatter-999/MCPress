<?php
/**
 * LLM Tool Trait
 *
 * This file provides the definition of the LLM tool trait for use elsewhere.
 *
 * @package MCPress
 **/

namespace MCPress\Traits;

trait LLM_Tool {

	/**
	 * Name of the LLM Tool class.
	 * This property is expected to be defined and set in the class that uses this trait.
	 *
	 * @var string
	 */
	protected static $name = null;

	/**
	 * Abstract method for defining the tool's schema.
	 * Implementing classes must define this method to return the tool's schema
	 * in a format compatible with LLM function calling specifications (e.g., OpenAI).
	 *
	 * @return array The schema definition for the LLM tool.
	 */
	abstract public function schema(): array;

	/**
	 * Abstract method for defining the tool's functionality.
	 * Implementing classes must define this method to perform the tool's actions
	 * based on the arguments provided by the LLM.
	 *
	 * @param array $arguments Arguments passed to the tool from the LLM,
	 *                         conforming to the 'parameters' defined in the schema.
	 * @return mixed The result of the tool's execution. This can be any data type
	 *               that can be serialized and returned to the LLM (e.g., string, JSON).
	 */
	abstract public function tool( array $arguments );

	/**
	 * Registers the LLM tool with hooks.
	 * It performs checks and then hooks the tool's name, functionality, and schema
	 * into their respective MCPress/WordPress filters and actions.
	 *
	 * @return void
	 */
	public function register_llm_tool() {
		// Ensure the implementing class has defined and set static::$name.
		if ( ! property_exists( static::class, 'name' ) || empty( static::$name ) ) {
			return;
		}

		add_filter( 'mcpress_available_tools', array( $this, 'register_tool' ) );
		add_filter( 'mcpress_tools_schema', array( $this, 'register_schema' ) );

		add_filter( 'mcpress_tool_call_' . static::$name, array( $this, 'tool' ) );
	}

	/**
	 * Registers the tool's name into the collection of available tools.
	 * This method is designed to be used as a callback for the 'mcpress_available_tools' filter.
	 *
	 * @param array $tools An array of currently registered tool names.
	 * @return array The updated array of tool names, including this tool's name.
	 */
	public function register_tool( array $tools ): array {
		if ( ! is_array( $tools ) ) {
			$tools = array();
		}
		$tools[] = static::$name;
		return $tools;
	}


	/**
	 * Registers the tool's schema into the collection of all tool schemas.
	 * This method is designed to be used as a callback for the 'mcpress_tools_schema' filter.
	 * It retrieves the schema defined by the implementing class via the abstract schema() method.
	 *
	 * @param array $schemas An array of currently registered tool schemas.
	 * @return array The updated array of schemas, including this tool's schema.
	 */
	public function register_schema( array $schemas ): array {
		if ( ! is_array( $schemas ) ) {
			$schemas = array();
		}
		$schemas[] = array(
			'type'     => 'function',
			'function' => $this->schema(),
		);
		return $schemas;
	}
}
