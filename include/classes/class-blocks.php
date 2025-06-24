<?php
/**
 * Block Class file for the Plugin.
 *
 * @package DarkMatter_Plugin
 */

namespace DarkMatter_Plugin;

use DarkMatter_Plugin\Traits\Singleton;

/**
 * Block Class file for the Plugin.
 */
class Blocks {

	use Singleton;

	/**
	 * Constructor for the Blocks class
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
		add_filter( 'block_categories_all', array( $this, 'add_custom_block_category' ), 10, 2 );
	}

	/**
	 * Register blocks.
	 *
	 * @return void
	 */
	public function register() {
		register_block_type(
			DMP_PLUGIN_PATH . 'assets/build/blocks/block'
		);
	}


	/**
	 * Add Custom block category.
	 *
	 * @param  array                   $categories Block Categories.
	 * @param  WP_Block_Editor_Context $post       The current block editor context.
	 * @return array
	 */
	public function add_custom_block_category( $categories, $post ) {   // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- no use of block context.
		return array_merge(
			$categories,
			array(
				array(
					'slug'  => 'custom-category',
					'title' => __( 'Custom Blocks', 'text-domain-1' ),
					'icon'  => null,
				),
			)
		);
	}
}
