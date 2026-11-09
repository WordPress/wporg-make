/**
 * Interactivity for the Latest activity block: the comments toggle and o2's Follow action.
 */

/**
 * WordPress dependencies
 */
import { getContext, store } from '@wordpress/interactivity';

store( 'wporg-make/latest-activity', {
	actions: {
		/**
		 * Shows or hides a post's comments.
		 */
		toggleComments() {
			const context = getContext();
			context.isOpen = ! context.isOpen;
		},

		/**
		 * Follows or unfollows a post the way o2 does.
		 *
		 * Flips the button optimistically, calls the Follow link with `&ajax`, and flips it
		 * back unless o2's handler answers "1".
		 *
		 * @param {MouseEvent} event Click event.
		 */
		*toggleFollow( event ) {
			event.preventDefault();

			const context = getContext();
			const following = ! context.isFollowing;
			context.isFollowing = following;

			const [ current, next ] = following
				? [ 'post-comment-subscribe', 'post-comment-unsubscribe' ]
				: [ 'post-comment-unsubscribe', 'post-comment-subscribe' ];

			try {
				const response = yield fetch( `${ context.href }&ajax=1`, {
					credentials: 'same-origin',
				} );
				const body = yield response.text();

				if ( ! response.ok || '1' !== body.trim() ) {
					throw new Error( 'Follow request failed.' );
				}

				context.href = context.href.replace( `action=${ current }`, `action=${ next }` );
			} catch {
				context.isFollowing = ! following;
			}
		},
	},
} );
