/**
 * WordPress dependencies
 */
import { getContext, store } from '@wordpress/interactivity';

store( 'wporg-make/latest-activity', {
	actions: {
		toggleComments() {
			const context = getContext();
			context.isOpen = ! context.isOpen;
		},
	},
} );
