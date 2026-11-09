/**
 * Interactivity for the Latest activity block: the comments toggle and o2's Follow action.
 */

/**
 * WordPress dependencies
 */
import { getContext, getElement, store, withSyncEvent } from '@wordpress/interactivity';

const ITEM = '.wp-block-wporg-make-latest-activity__item';

/*
 * Posts on the page when it loads get the team blogs' scripts (glossary tooltips,
 * Prism, `[time]` dates) from those scripts themselves; posts added by "Load more" don't.
 */
const initialPosts = new Set( document.querySelectorAll( ITEM ) );

/**
 * Shows `[time]` shortcode dates in the viewer's time zone.
 *
 * Team blogs do this with a footer script from wporg-time-shortcode.php, which
 * isn't printed on the root; this produces the same text.
 *
 * @param {Element} root Element to look in.
 */
function localizeShortcodeDates( root ) {
	root.querySelectorAll( 'abbr.date[title]' ).forEach( ( abbr ) => {
		const date = new Date( abbr.getAttribute( 'title' ) );
		if ( ! Number.isNaN( date.getTime() ) ) {
			abbr.textContent = date.toLocaleTimeString( navigator.language, {
				weekday: 'long',
				month: 'long',
				day: 'numeric',
				year: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
				timeZoneName: 'short',
			} );
		}
	} );
}

/**
 * Binds glossary tooltips the way wporg-glossary's glossary-hovercards.js does.
 *
 * That script only binds the terms present when the page loads.
 *
 * @param {Element} root Element to look in.
 */
function bindGlossary( root ) {
	const { tippy, hoverintent } = window;
	if ( ! tippy || ! hoverintent ) {
		return;
	}

	root.querySelectorAll( '.glossary-item-container' ).forEach( ( element ) => {
		const hidden = element.querySelector( '.glossary-item-hidden-content' );
		if ( ! hidden ) {
			return;
		}

		const html = hidden.innerHTML;
		hidden.remove();

		const tooltip = tippy( element, {
			content: html,
			allowHTML: true,
			arrow: true,
			theme: 'light',
			delay: [ 0, 500 ],
			interactive: true,
			boundary: 'window',
			zIndex: 99999,
		} );
		hoverintent(
			element,
			() => tooltip.show(),
			() => {}
		);
	} );
}

const { state } = store( 'wporg-make/latest-activity', {
	actions: {
		/**
		 * Loads the next page below the current posts without leaving the page.
		 *
		 * The router fetches the current range extended by one page and swaps it in,
		 * keeping the posts already shown (they're keyed). Focus moves to the first
		 * new post. If the router can't load, the link's own page opens instead.
		 *
		 * @param {MouseEvent} event Click event.
		 */
		loadMore: withSyncEvent( function* ( event ) {
			event.preventDefault();

			const context = getContext();
			if ( context.isLoading ) {
				return;
			}

			const { ref } = getElement();
			const shown = document.querySelectorAll( ITEM ).length;
			context.isLoading = true;

			try {
				const { actions: router } = yield import( '@wordpress/interactivity-router' );
				yield router.navigate( context.href );
			} catch {
				window.location.assign( ref.href );
				return;
			} finally {
				context.isLoading = false;
			}

			document.querySelectorAll( ITEM )[ shown ]?.querySelector( 'h2 a, a' )?.focus();
		} ),

		/**
		 * Shows or hides a post's comments.
		 */
		toggleComments: () => {
			const context = getContext();
			context.isOpen = ! context.isOpen;
		},

		/**
		 * Follows a post the way o2 does.
		 *
		 * Flips the button optimistically, calls the Follow link with `&ajax`, and flips it
		 * back unless o2's handler answers "1". Unfollowing isn't offered: the wordpress.org
		 * handler doesn't act on `post-comment-unsubscribe`, so it would only change the button.
		 *
		 * @param {MouseEvent} event Click event.
		 */
		follow: withSyncEvent( function* ( event ) {
			event.preventDefault();

			const context = getContext();
			if ( context.isFollowing || context.isBusy ) {
				return;
			}

			context.isBusy = true;
			context.isFollowing = true;

			try {
				const response = yield fetch( `${ context.href }&ajax=1`, {
					credentials: 'same-origin',
				} );
				const body = yield response.text();

				if ( ! response.ok || '1' !== body.trim() ) {
					throw new Error( 'Follow request failed.' );
				}
			} catch {
				context.isFollowing = false;
			} finally {
				context.isBusy = false;
			}
		} ),
	},

	callbacks: {
		/**
		 * Shows the list's `[time]` shortcode dates in the viewer's time zone.
		 */
		localizeShortcodeTimes() {
			localizeShortcodeDates( getElement().ref );
		},

		/**
		 * Runs the team blogs' content scripts on a post added by "Load more".
		 */
		initPost() {
			const { ref } = getElement();
			if ( initialPosts.has( ref ) ) {
				return;
			}

			localizeShortcodeDates( ref );
			bindGlossary( ref );
			window.Prism?.highlightAllUnder?.( ref );
		},

		/**
		 * Shows a timestamp in the viewer's time zone and language, the way o2 does.
		 *
		 * Today reads as relative time, yesterday as "Yesterday at 2:33 pm", anything
		 * older as "9:22 pm on November 4, 2026". The title carries the UTC offset.
		 */
		localizeTime() {
			const context = getContext();
			const { ref } = getElement();
			const date = new Date( ref.getAttribute( 'datetime' ) );
			if ( Number.isNaN( date.getTime() ) ) {
				return;
			}

			const locale = document.documentElement.lang || undefined;
			const time = date.toLocaleTimeString( locale, {
				hour: 'numeric',
				minute: '2-digit',
			} );
			const day = date.toLocaleDateString( locale, {
				year: 'numeric',
				month: 'long',
				day: 'numeric',
			} );

			const now = new Date();
			const today = new Date( now.getFullYear(), now.getMonth(), now.getDate() );
			const yesterday = new Date( today.getTime() - 86400000 );

			if ( date >= today ) {
				const minutes = Math.round( ( date - now ) / 60000 );
				const relative = new Intl.RelativeTimeFormat( locale, {
					numeric: 'auto',
				} );
				context.text =
					Math.abs( minutes ) < 60
						? relative.format( minutes, 'minute' )
						: relative.format( Math.round( minutes / 60 ), 'hour' );
			} else if ( date >= yesterday ) {
				context.text = state.yesterday.replace( '%s', time );
			} else {
				context.text = state.dateTime.replace( '%1$s', time ).replace( '%2$s', day );
			}

			const offset = -date.getTimezoneOffset();
			const hours = String( Math.floor( Math.abs( offset ) / 60 ) ).padStart( 2, '0' );
			const mins = String( Math.abs( offset ) % 60 ).padStart( 2, '0' );
			context.title = state.dateTime
				.replace( '%1$s', `${ time } (${ offset < 0 ? '-' : '+' }${ hours }:${ mins })` )
				.replace( '%2$s', day );
		},
	},
} );
