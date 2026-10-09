/**
 * KDNA eCommerce Insights: wp-admin app.
 *
 * The Alpine.js component behind the app shell: which screen is showing,
 * light and dark mode, Focus Mode and the date range dropdown. Choices are
 * saved to the current user's preferences through the plugin's REST API.
 *
 * Other parts of Insights can listen for these browser events:
 * - kdna:ei-range-change  when the date range or comparison changes
 * - kdna:ei-theme-change  when light or dark mode is switched
 */
( function ( window, document ) {
	'use strict';

	var config = window.kdnaEiApp || {};
	var i18n = config.i18n || {};
	var router = window.KDNAEI.router;

	/**
	 * Saves preference changes for the current user. Failures are logged
	 * quietly because the change has already been applied on screen.
	 *
	 * @param {Object} changes Preferences to save, for example { theme: 'light' }.
	 * @return {Promise}
	 */
	function savePreferences( changes ) {
		return window.fetch( config.restUrl + 'preferences', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: JSON.stringify( changes ),
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}
			return response.json();
		} ).catch( function ( error ) {
			window.console && window.console.warn( 'KDNA Insights: preference not saved.', error );
		} );
	}

	/**
	 * Sends a named browser event other Insights code can listen for.
	 *
	 * @param {string} name   Event name.
	 * @param {Object} detail Information passed with the event.
	 */
	function broadcast( name, detail ) {
		window.dispatchEvent( new window.CustomEvent( name, { detail: detail } ) );
	}

	/**
	 * Turns Focus Mode on or off by adding or removing classes on the page.
	 * The CSS then hides the WordPress menu and admin bar.
	 *
	 * @param {boolean} on Whether Focus Mode should be on.
	 */
	function applyFocusClasses( on ) {
		document.body.classList.toggle( 'kdna-ei-focus', on );
		document.documentElement.classList.toggle( 'kdna-ei-focus-html', on );
	}

	document.addEventListener( 'alpine:init', function () {
		var prefs = config.preferences || {};
		var screenIds = Object.keys( config.screens || {} );

		router.init( screenIds, config.defaultRoute );

		window.Alpine.data( 'kdnaEiApp', function () {
			return {
				route: router.current(),
				screens: config.screens || {},
				ranges: config.ranges || {},
				comparisons: config.comparisons || {},
				theme: prefs.theme === 'light' ? 'light' : 'dark',
				focus: !! prefs.focus,
				range: prefs.range || 'this_month',
				comparison: prefs.comparison || 'previous_period',
				rangeOpen: false,
				baseTitle: document.title,

				/**
				 * Runs once when the app starts: follows address changes and
				 * applies the saved Focus Mode.
				 */
				init: function () {
					var self = this;

					applyFocusClasses( this.focus );
					this.updateDocumentTitle();

					router.onChange( function ( id ) {
						self.route = id;
						self.rangeOpen = false;
						self.updateDocumentTitle();
						window.scrollTo( 0, 0 );
						self.$nextTick( function () {
							if ( self.$refs.title ) {
								self.$refs.title.focus( { preventScroll: true } );
							}
						} );
					} );
				},

				/**
				 * The display name of the screen currently showing.
				 *
				 * @return {string}
				 */
				get screenLabel() {
					return this.screens[ this.route ] || '';
				},

				/**
				 * The display name of the chosen date range.
				 *
				 * @return {string}
				 */
				get rangeLabel() {
					return this.ranges[ this.range ] || '';
				},

				/**
				 * Accessible label for the theme button, describing what it will do.
				 *
				 * @return {string}
				 */
				get themeLabel() {
					return this.theme === 'dark' ? i18n.switchToLight : i18n.switchToDark;
				},

				/**
				 * Accessible label for the Focus Mode button.
				 *
				 * @return {string}
				 */
				get focusLabel() {
					return this.focus ? i18n.exitFocus : i18n.enterFocus;
				},

				/**
				 * Checks whether a sidebar link belongs to the current screen.
				 *
				 * @param {string} id Screen ID.
				 * @return {boolean}
				 */
				isActive: function ( id ) {
					return this.route === id;
				},

				/**
				 * Puts the current screen name in the browser tab title.
				 */
				updateDocumentTitle: function () {
					var parts = this.baseTitle.split( ' ‹ ' );
					parts[ 0 ] = this.screenLabel + ' | ' + ( i18n.pageTitle || 'Insights' );
					document.title = parts.join( ' ‹ ' );
				},

				/**
				 * Switches between dark and light mode and remembers the choice.
				 */
				toggleTheme: function () {
					this.theme = this.theme === 'dark' ? 'light' : 'dark';
					savePreferences( { theme: this.theme } );
					broadcast( 'kdna:ei-theme-change', { theme: this.theme } );
				},

				/**
				 * Turns Focus Mode on or off and remembers the choice.
				 */
				toggleFocus: function () {
					this.focus = ! this.focus;
					applyFocusClasses( this.focus );
					savePreferences( { focus: this.focus } );
				},

				/**
				 * Opens or closes the date range menu.
				 */
				toggleRangeMenu: function () {
					this.rangeOpen = ! this.rangeOpen;
				},

				/**
				 * Closes any open menu, for example when Escape is pressed.
				 */
				closeMenus: function () {
					if ( this.rangeOpen && this.$refs.rangeTrigger ) {
						this.$refs.rangeTrigger.focus();
					}
					this.rangeOpen = false;
				},

				/**
				 * Chooses a date range preset, remembers it and tells the rest of
				 * the dashboard so every panel can update together.
				 *
				 * @param {string} key Preset key such as 'this_month'.
				 */
				selectRange: function ( key ) {
					this.range = key;
					this.rangeOpen = false;
					savePreferences( { range: key } );
					broadcast( 'kdna:ei-range-change', { range: this.range, comparison: this.comparison } );
				},

				/**
				 * Chooses what the figures are compared against, remembers it and
				 * tells the rest of the dashboard.
				 *
				 * @param {string} key Comparison key such as 'previous_period'.
				 */
				selectComparison: function ( key ) {
					this.comparison = key;
					savePreferences( { comparison: key } );
					broadcast( 'kdna:ei-range-change', { range: this.range, comparison: this.comparison } );
				},
			};
		} );
	} );
}( window, document ) );
