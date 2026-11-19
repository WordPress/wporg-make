/**
 * Interactivity for the Latest activity block: loading more posts, and local days and times.
 */

/**
 * WordPress dependencies
 */
import { getContext, getElement, store, withScope, withSyncEvent } from '@wordpress/interactivity';

const ENTRY = '.wp-block-wporg-make-latest-activity__entry';
const MORE = '.wp-block-wporg-make-latest-activity__more';

// How far below the viewport the next page starts loading, so it's usually there before the reader is.
const PRELOAD_DISTANCE = 800;

/**
 * Returns the language to format dates in: the page's own.
 *
 * @return {string|undefined} Language tag.
 */
function getLocale() {
	return document.documentElement.lang || undefined;
}

/**
 * Returns the viewer's local calendar day of a date, as a comparable key.
 *
 * @param {Date} date Date.
 * @return {string} Key, such as "2026-10-19" (months count from 0).
 */
function getDayKey( date ) {
	return `${ date.getFullYear() }-${ date.getMonth() }-${ date.getDate() }`;
}

/**
 * Returns the heading of the viewer's local day of a date: "Today", "Yesterday" or the date.
 *
 * @param {Date} date Date.
 * @return {string} Day heading.
 */
function getDayLabel( date ) {
	const now = new Date();
	const yesterday = new Date( now.getFullYear(), now.getMonth(), now.getDate() - 1 );

	if ( getDayKey( date ) === getDayKey( now ) ) {
		return state.today;
	}

	if ( getDayKey( date ) === getDayKey( yesterday ) ) {
		return state.yesterday;
	}

	return date.toLocaleDateString( getLocale(), {
		weekday: 'long',
		month: 'long',
		day: 'numeric',
		year: date.getFullYear() === now.getFullYear() ? undefined : 'numeric',
	} );
}

/**
 * Swaps in the current range extended by the next page, through the router.
 *
 * The posts already shown are keyed, so they stay as they are. The URL is replaced
 * rather than added to history, so Back leaves the page.
 *
 * The range is fetched here and handed to the router, because the router reloads the
 * whole page at that URL when it can't load one itself. If loading fails, a click opens
 * the link's own page; automatic loading stops and shows the link instead.
 *
 * @param {HTMLAnchorElement} more      The Load more link.
 * @param {boolean}           moveFocus Whether to move focus to the first new post.
 * @return {boolean} Whether the page was swapped in (the generator's return value).
 */
function* loadNext( more, moveFocus ) {
	if ( state.isLoadingMore ) {
		return false;
	}

	const { href } = more;
	const shown = new Set( document.querySelectorAll( ENTRY ) );
	state.isLoadingMore = true;
	state.status = '';

	try {
		const { range } = more.dataset;
		const response = yield fetch( range );
		if ( ! response.ok || ! response.headers.get( 'content-type' )?.startsWith( 'text/html' ) ) {
			throw new Error( 'Loading more posts failed.' );
		}

		const body = yield response.text();
		const { actions: router } = yield import( '@wordpress/interactivity-router' );
		yield router.navigate( range, { html: body, replace: true } );
	} catch {
		if ( moveFocus ) {
			window.location.assign( href );
		} else {
			state.isAutomatic = false;
		}

		return false;
	} finally {
		state.isLoadingMore = false;
	}

	state.status = state.loadedMore;
	if ( moveFocus ) {
		const first = [ ...document.querySelectorAll( ENTRY ) ].find( ( entry ) => ! shown.has( entry ) );
		first?.querySelector( 'h4 a' )?.focus();
	}

	return true;
}

const { state } = store( 'wporg-make/latest-activity', {
	actions: {
		/**
		 * Loads the next page from the link, where the browser can't do it on scroll.
		 *
		 * @param {MouseEvent} event Click event.
		 */
		loadMore: withSyncEvent( function* ( event ) {
			event.preventDefault();

			yield* loadNext( getElement().ref, true );
		} ),
	},

	callbacks: {
		/**
		 * Loads the next page as the reader nears the end of the list.
		 *
		 * The link stays for visitors without JavaScript; with it, the link is hidden and
		 * pages load on their own until there are no more. After each page the end is
		 * checked again, since a short page can leave it in reach.
		 *
		 * @return {(() => void)|undefined} Cleanup that stops watching.
		 */
		observeMore() {
			if ( ! ( 'IntersectionObserver' in window ) ) {
				return;
			}

			const { ref } = getElement();
			state.isAutomatic = true;

			const load = withScope( function* () {
				/*
				 * Stop once this nav leaves the page (the router replaced it; its own init
				 * takes over) or a load fails, or the cached range would load again and again.
				 */
				let more = ref.querySelector( MORE );
				while (
					more &&
					ref.isConnected &&
					ref.getBoundingClientRect().top < window.innerHeight + PRELOAD_DISTANCE
				) {
					const range = more.dataset.range;
					if ( ! ( yield* loadNext( more, false ) ) ) {
						return;
					}

					more = ref.querySelector( MORE );
					if ( more?.dataset.range === range ) {
						return;
					}
				}
			} );

			const observer = new window.IntersectionObserver(
				( entries ) => {
					if ( entries.some( ( entry ) => entry.isIntersecting ) ) {
						load();
					}
				},
				{ rootMargin: `0px 0px ${ PRELOAD_DISTANCE }px 0px` }
			);
			observer.observe( ref );

			return () => observer.disconnect();
		},

		/**
		 * Works out a post's day heading in the viewer's time zone, and whether it's the
		 * first post of its day.
		 *
		 * Posts are newest first and only ever added after the ones shown, so the posts
		 * before this one, and with them the answer, don't change.
		 */
		localizeDay() {
			const context = getContext();
			const { ref } = getElement();
			const date = new Date( ref.dataset.date );
			if ( Number.isNaN( date.getTime() ) ) {
				return;
			}

			const previous = ref.previousElementSibling;

			context.dayLabel = getDayLabel( date );
			context.isFirstOfDay =
				! previous || getDayKey( new Date( previous.dataset.date ) ) !== getDayKey( date );
		},

		/**
		 * Shows a post's time of day in the viewer's time zone and language.
		 *
		 * A post from the last hour reads as relative time instead.
		 * The title carries the date and the time zone.
		 */
		localizeTime() {
			const context = getContext();
			const { ref } = getElement();
			const date = new Date( ref.getAttribute( 'datetime' ) );
			if ( Number.isNaN( date.getTime() ) ) {
				return;
			}

			const locale = getLocale();
			const time = date.toLocaleTimeString( locale, { hour: 'numeric', minute: '2-digit' } );
			const minutes = Math.round( ( date - Date.now() ) / 60000 );

			context.text =
				minutes <= 0 && minutes > -60
					? new Intl.RelativeTimeFormat( locale, { numeric: 'auto' } ).format( minutes, 'minute' )
					: time;

			context.title = state.dateTime
				.replace(
					'%1$s',
					date.toLocaleTimeString( locale, {
						hour: 'numeric',
						minute: '2-digit',
						timeZoneName: 'short',
					} )
				)
				.replace(
					'%2$s',
					date.toLocaleDateString( locale, { year: 'numeric', month: 'long', day: 'numeric' } )
				);
		},
	},
} );
