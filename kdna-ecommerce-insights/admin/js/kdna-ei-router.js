/**
 * KDNA eCommerce Insights: hash router.
 *
 * Switches between sidebar screens using the part of the address after the
 * # sign (for example admin.php?page=kdna-ei#/products), so screens change
 * instantly without reloading the page, and the browser back button works.
 */
( function ( window ) {
	'use strict';

	window.KDNAEI = window.KDNAEI || {};

	var routes = [];
	var fallback = '';

	/**
	 * Reads the screen ID from the address, or returns the fallback screen
	 * if the address does not name a known screen.
	 *
	 * @return {string} Screen ID.
	 */
	function current() {
		var id = window.location.hash.replace( /^#\/?/, '' ).split( /[/?]/ )[ 0 ];
		return routes.indexOf( id ) !== -1 ? id : fallback;
	}

	window.KDNAEI.router = {

		/**
		 * Tells the router which screens exist and which to show by default.
		 *
		 * @param {string[]} screenIds   Every screen ID, in sidebar order.
		 * @param {string}   defaultId   Screen shown when the address names none.
		 */
		init: function ( screenIds, defaultId ) {
			routes = screenIds.slice();
			fallback = defaultId || routes[ 0 ] || '';
		},

		current: current,

		/**
		 * Moves to a screen by changing the address.
		 *
		 * @param {string} id Screen ID.
		 */
		go: function ( id ) {
			window.location.hash = '#/' + id;
		},

		/**
		 * Runs a function every time the screen changes, passing the new screen ID.
		 *
		 * @param {Function} callback Function to run.
		 */
		onChange: function ( callback ) {
			window.addEventListener( 'hashchange', function () {
				callback( current() );
			} );
		},
	};
}( window ) );
