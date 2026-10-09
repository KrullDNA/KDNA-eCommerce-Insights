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
				status: config.status || {},
				debug: !! config.debug,
				pollTimer: null,
				jobJustFinished: false,
				jobError: '',

				/**
				 * Runs once when the app starts: follows address changes and
				 * applies the saved Focus Mode.
				 */
				init: function () {
					var self = this;

					applyFocusClasses( this.focus );
					this.updateDocumentTitle();

					if ( this.jobRunning ) {
						this.startPolling();
					}

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

				/*
				 * -----------------------------------------------------------
				 * Background jobs (order history processing, recalculation)
				 * -----------------------------------------------------------
				 */

				/**
				 * Whether order processing is running in the background.
				 *
				 * @return {boolean}
				 */
				get jobRunning() {
					return !! ( this.status.job && this.status.job.status === 'running' );
				},

				/**
				 * Heading for the progress bar, describing what is running.
				 *
				 * @return {string}
				 */
				get jobTitle() {
					var job = this.status.job || {};
					var jobs = i18n.jobs || {};
					if ( job.mode === 'after' ) {
						return jobs.after.replace( '%s', job.after );
					}
					return jobs[ job.mode ] || '';
				},

				/**
				 * Progress text, for example "1,200 of 5,000 orders (24%)".
				 *
				 * @return {string}
				 */
				get jobDetail() {
					var job = this.status.job || {};
					var format = window.KDNAEI.format;
					return ( i18n.jobs.progress || '' )
						.replace( '%1$s', format.number( job.processed || 0, 0 ) )
						.replace( '%2$s', format.number( job.total || 0, 0 ) )
						.replace( '%3$s', job.percent || 0 );
				},

				/**
				 * Fetches the latest status from the server.
				 *
				 * @return {Promise}
				 */
				refreshStatus: function () {
					var self = this;
					var wasRunning = this.jobRunning;

					return window.KDNAEI.api( 'status' ).then( function ( data ) {
						self.status = data;
						broadcast( 'kdna:ei-status', data );

						if ( wasRunning && ! self.jobRunning ) {
							self.stopPolling();
							if ( data.job && data.job.status === 'complete' ) {
								self.jobJustFinished = true;
								window.setTimeout( function () {
									self.jobJustFinished = false;
								}, 6000 );
								broadcast( 'kdna:ei-data-loaded', data );
							}
						}
					} ).catch( function () {} );
				},

				/**
				 * Checks progress every few seconds while a job runs.
				 */
				startPolling: function () {
					var self = this;
					if ( this.pollTimer ) {
						return;
					}
					this.pollTimer = window.setInterval( function () {
						self.refreshStatus();
					}, 3000 );
				},

				/**
				 * Stops checking progress.
				 */
				stopPolling: function () {
					window.clearInterval( this.pollTimer );
					this.pollTimer = null;
				},

				/**
				 * Starts a background job: all orders, orders missing costs, or
				 * orders on or after a date.
				 *
				 * @param {string} mode  all, missing or after.
				 * @param {string} after Y-m-d, for "after".
				 * @return {Promise<boolean>} True if the job started.
				 */
				startJob: function ( mode, after ) {
					var self = this;
					this.jobError = '';

					return window.KDNAEI.api( 'jobs', { method: 'POST', body: { mode: mode, after: after || '' } } ).then( function ( data ) {
						self.status = data;
						self.jobJustFinished = false;
						self.startPolling();
						return true;
					} ).catch( function ( error ) {
						self.jobError = error.message;
						return false;
					} );
				},

				/**
				 * Text for the Data tab and Recalculate tab.
				 *
				 * @return {Object}
				 */
				get i18nData() {
					return i18n.data || {};
				},

				/**
				 * Replaces %s placeholders in job text, in order.
				 *
				 * @param {string} text Text with placeholders.
				 * @return {string}
				 */
				sprintfJobs: function ( text ) {
					var values = Array.prototype.slice.call( arguments, 1 );
					return String( text || '' ).replace( /%s/g, function () {
						return values.length ? values.shift() : '';
					} );
				},

				/**
				 * Shows a UTC date and time from the server in the viewer's own
				 * time, for example "9 Oct 2026, 3:15 pm".
				 *
				 * @param {string} value Y-m-d H:i:s in UTC.
				 * @return {string}
				 */
				niceDateTime: function ( value ) {
					if ( ! value ) {
						return '';
					}
					var date = new Date( value.replace( ' ', 'T' ) + 'Z' );
					return isNaN( date ) ? value : date.toLocaleString( config.locale || undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' } );
				},

				/**
				 * Cancels the running job. Orders already done keep their figures.
				 *
				 * @return {Promise}
				 */
				cancelJob: function () {
					var self = this;
					return window.KDNAEI.api( 'jobs', { method: 'DELETE' } ).then( function ( data ) {
						self.status = data;
						self.stopPolling();
					} ).catch( function ( error ) {
						self.jobError = error.message;
					} );
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
