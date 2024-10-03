<?php
/**
 * Blocks hooks.
 */

namespace WordPressdotorg\Theme\Make_2024\Block_Hooks;

add_filter( 'render_block_core/query', __NAMESPACE__ . '\render_team_grid_block_query', 10, 2 );

/**
 * Modifies the block content for team grid blocks, inserting a placeholder item to ensure the grid is complete.
 *
 * @param string $block_content The block content.
 * @param array  $block         The block settings and attributes.
 * @return string Modified block content.
 */
function render_team_grid_block_query( $block_content, $block ) {
	if ( isset( $block['attrs']['className'] ) && strpos( $block['attrs']['className'], 'wporg-make-team-grid' ) !== false ) {
		$tags           = wp_html_split( $block_content );
		$count          = 0;
		$ul_close_index = -1;

		foreach ( $tags as $index => $tag ) {
			if ( false !== strpos( $tag, 'class="' ) && false !== strpos( $tag, 'make_site' ) ) {
				$count++;
			}
			if ( 0 === strpos( $tag, '</ul>' ) ) {
				$ul_close_index = $index;
				break;
			}
		}

		if ( -1 !== $ul_close_index ) {
			$placeholder_class = 'make_site make_site-placeholder has-border-color has-light-grey-1-border-color';
			// Calculate the number of empty slots
			$remainder = ( 3 - ( $count % 3 ) ) % 3;
			// We'll stretch the placeholder to span 2 columns if there are 2 empty slots.
			if ( 2 === $remainder ) {
				$placeholder_class .= ' make_site-placeholder-span-2';
			}
			$placeholder = '<li class="' . esc_attr( $placeholder_class ) . '" style="border-width:1px;"></li>';

			// Insert the single placeholder item just before the closing </ul> tag.
			array_splice( $tags, $ul_close_index, 0, $placeholder );

			$block_content = implode( '', $tags );
		}
	}

	return $block_content;
}
