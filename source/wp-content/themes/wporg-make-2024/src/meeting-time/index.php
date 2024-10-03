<?php
namespace WordPressdotorg\Theme\Make_2024\Meeting_time;

add_action( 'init', __NAMESPACE__ . '\init' );

/**
 * Registers the block using the metadata loaded from the `block.json` file.
 * Behind the scenes, it registers also all assets so they can be enqueued
 * through the block editor in the corresponding context.
 *
 * @see https://developer.wordpress.org/reference/functions/register_block_type/
 */
function init() {
	register_block_type(
		dirname( dirname( __DIR__ ) ) . '/build/meeting-time',
		array(
			'render_callback' => __NAMESPACE__ . '\render',
		)
	);
}

/**
 * Render the block content.
 *
 * @param array    $attributes Block attributes.
 * @param string   $content    Block default content.
 * @param WP_Block $block      Block instance.
 *
 * @return string Returns the block markup.
 */
function render( $attributes, $content, $block ) {
	if ( ! isset( $block->context['postId'] ) || ! shortcode_exists( 'meeting_time' ) ) {
		return '';
	}

	$post_id = $block->context['postId'];
	$team = get_post_field( 'post_title', $post_id );

	$wrapper_attributes = get_block_wrapper_attributes();
	return sprintf(
		'<div %1$s>%2$s</div>',
		$wrapper_attributes,
		do_shortcode( sprintf( '[meeting_time team="%s"][/meeting_time]', esc_attr( $team ) ) )
	);
}
