/**
 * KDNA eCommerce Insights: Tax & Reports screen.
 *
 * Four parts:
 * - Tax summary: GST (with the BAS lines G1, 1A and 1B), VAT or sales tax
 *   by month or quarter, for the chosen dates, last full period or a
 *   financial year, with its own small Tax settings window.
 * - Printable report: opens the A4 report in a new tab to save as a PDF.
 * - Email digest: weekly or monthly summary email settings, with Preview
 *   (shown in a frame, at desktop or phone width) and Send test.
 * - Every export: one list of every table in the app as a CSV download.
 */
( function ( window, document ) {
	'use strict';

	var config = window.kdnaEiApp || {};
	var t = ( config.i18n && config.i18n.reports ) || {};
	var format = window.KDNAEI.format;
	var api = window.KDNAEI.api;
	var sprintf = window.KDNAEI.sprintf;
	var dates = window.KDNAEI.dates;

	/**
	 * Copies a plain object, so a form can be compared with what was saved.
	 *
	 * @param {Object} value Object.
	 * @return {Object}
	 */
	function copy( value ) {
		return JSON.parse( JSON.stringify( value ) );
	}

	/**
	 * Everything inside an element that can take keyboard focus.
	 *
	 * @param {Element} root Element.
	 * @return {Element[]}
	 */
	function focusable( root ) {
		return Array.prototype.filter.call(
			root.querySelectorAll( 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), iframe, [tabindex]:not([tabindex="-1"])' ),
			function ( el ) {
				return el.offsetParent !== null;
			}
		);
	}

	document.addEventListener( 'alpine:init', function () {
		window.Alpine.data( 'kdnaEiReports', function () {
			var taxConfig = config.tax || {};

			return {
				t: t,
				systems: config.taxSystems || {},
				spans: config.taxSpans || {},
				exportGroups: config.exports || {},

				// Tax summary.
				tax: null,
				taxLoaded: false,
				taxLoading: false,
				taxError: '',
				taxStale: false,
				taxPeriod: taxConfig.reporting_period === 'monthly' ? 'monthly' : 'quarterly',
				taxSpan: 'range',
				taxSystem: taxConfig.system || 'none',
				requestId: 0,

				// Tax settings window.
				taxForm: { open: false, busy: false, error: '', fields: {}, system: taxConfig.system || 'none', rate: taxConfig.rate, reporting_period: taxConfig.reporting_period || 'quarterly' },

				// Digest.
				digest: null,
				digestForm: { frequency: 'off', recipients: '', sections: [] },
				digestSaved: null,
				digestBusy: '',
				digestErrors: {},
				digestNotice: null,
				preview: { open: false, loading: false, html: '', subject: '', dates: '', width: 'desktop', error: '' },

				// Exports.
				exportBusy: '',
				exportError: '',

				/**
				 * Reloads the tax summary when the date range changes: now if this
				 * screen is open, otherwise the next time it is opened.
				 */
				init: function () {
					var self = this;
					var refresh = function () {
						if ( ! self.taxLoaded ) {
							return;
						}
						if ( self.route === 'reports' ) {
							self.loadTax();
						} else {
							self.taxStale = true;
						}
					};
					window.addEventListener( 'kdna:ei-range-change', refresh );
					window.addEventListener( 'kdna:ei-data-loaded', refresh );
				},

				/**
				 * Loads the screen the first time it is opened, or again if the
				 * range changed while it was closed.
				 */
				ensureLoaded: function () {
					if ( ( ! this.taxLoaded || this.taxStale ) && ! this.taxLoading ) {
						this.loadTax();
					}
					if ( ! this.digest ) {
						this.loadDigest();
					}
				},

				/*
				 * -------------------------------------------------------------
				 * Tax summary
				 * -------------------------------------------------------------
				 */

				/**
				 * The query for the tax summary: the date range plus the period
				 * and span chosen on the card.
				 *
				 * @return {Object}
				 */
				get taxQuery() {
					return Object.assign( {}, this.rangeQuery, { period: this.taxPeriod, span: this.taxSpan } );
				},

				/**
				 * Fetches the tax summary.
				 *
				 * @param {boolean} fresh Skip the server cache.
				 * @return {Promise}
				 */
				loadTax: function ( fresh ) {
					var self = this;
					var id = ++this.requestId;

					this.taxLoading = true;
					this.taxStale = false;
					this.taxError = '';

					return api( 'tax', { query: Object.assign( {}, this.taxQuery, fresh ? { fresh: 1 } : {} ) } ).then( function ( response ) {
						if ( id !== self.requestId ) {
							return;
						}
						self.tax = response.data;
						self.taxSystem = response.data.system;
						self.taxLoaded = true;
					} ).catch( function ( error ) {
						if ( id === self.requestId ) {
							self.taxError = error.message;
						}
					} ).finally( function () {
						if ( id === self.requestId ) {
							self.taxLoading = false;
						}
					} );
				},

				/**
				 * Switches between monthly and quarterly.
				 *
				 * @param {string} period monthly or quarterly.
				 */
				setTaxPeriod: function ( period ) {
					if ( this.taxPeriod !== period ) {
						this.taxPeriod = period;
						this.loadTax();
					}
				},

				/**
				 * Chooses which dates the tax summary covers.
				 *
				 * @param {string} span A key from the span list.
				 */
				setTaxSpan: function ( span ) {
					this.taxSpan = span;
					this.loadTax();
				},

				/**
				 * Names of the span choices, with "Last full period" made
				 * specific (month or quarter).
				 *
				 * @return {Object[]} Each: key, label.
				 */
				get spanOptions() {
					var self = this;
					return Object.keys( this.spans ).map( function ( key ) {
						var label = self.spans[ key ];
						if ( key === 'last_period' ) {
							label = self.taxPeriod === 'monthly' ? t.lastMonth : t.lastQuarter;
						}
						return { key: key, label: label };
					} );
				},

				/**
				 * Whether tax reporting is switched on.
				 *
				 * @return {boolean}
				 */
				get taxOn() {
					return this.taxSystem !== 'none';
				},

				/**
				 * Whether the summary has a column for tax on costs.
				 *
				 * @return {boolean}
				 */
				get claimsCosts() {
					return !! ( this.tax && this.tax.claims_costs );
				},

				/**
				 * The card title for the tax system.
				 *
				 * @return {string}
				 */
				get taxTitle() {
					return this.taxSystem === 'au_gst' ? t.gstTitle : t.taxTitle;
				},

				/**
				 * The four headline tiles.
				 *
				 * @return {Object[]} Each: key, label, short, value, note.
				 */
				get taxTiles() {
					if ( ! this.tax ) {
						return [];
					}
					var labels = this.tax.labels;
					var totals = this.tax.totals;
					var tiles = [
						{ key: 'sales', label: labels.sales, short: labels.short[ 0 ], value: totals.sales, note: t.salesNote },
						{ key: 'on_sales', label: labels.on_sales, short: labels.short[ 1 ], value: totals.on_sales, note: t.onSalesNote },
					];
					if ( this.claimsCosts ) {
						tiles.push( {
							key: 'on_costs',
							label: labels.on_costs,
							short: labels.short[ 2 ],
							value: totals.on_costs,
							note: sprintf( t.onCostsNote, this.money( totals.costs.overheads ), this.money( totals.costs.ad_spend ) ),
							estimate: true,
						} );
					}
					tiles.push( { key: 'net', label: labels.net, short: '', value: totals.net, note: this.positionText, position: this.tax.position } );
					return tiles;
				},

				/**
				 * Plain-English net position, such as "GST to pay".
				 *
				 * @return {string}
				 */
				get positionText() {
					if ( ! this.tax ) {
						return '';
					}
					var tax = this.tax.labels.tax;
					if ( this.tax.position === 'payable' ) {
						return sprintf( t.toPay, tax );
					}
					if ( this.tax.position === 'refundable' ) {
						return sprintf( t.refundDue, tax );
					}
					return t.nothingDue;
				},

				/**
				 * The dates the summary covers, in words.
				 *
				 * @return {string}
				 */
				get taxRangeText() {
					if ( ! this.tax ) {
						return '';
					}
					return sprintf( t.covers, dates.short( this.tax.range.start ), dates.short( this.tax.range.end ) );
				},

				/**
				 * Badge for a period: still running, or only part of it chosen.
				 *
				 * @param {Object} row Period row.
				 * @return {string}
				 */
				periodBadge: function ( row ) {
					if ( row.in_progress ) {
						return t.inProgress;
					}
					return row.partial ? t.partial : '';
				},

				/**
				 * Explains a period badge.
				 *
				 * @param {Object} row Period row.
				 * @return {string}
				 */
				periodBadgeHelp: function ( row ) {
					if ( row.in_progress ) {
						return t.inProgressHelp;
					}
					return row.partial ? sprintf( t.partialHelp, dates.short( row.start ), dates.short( row.end ) ) : '';
				},

				/**
				 * Formats money with the store currency.
				 *
				 * @param {number} value Amount.
				 * @return {string}
				 */
				money: function ( value ) {
					return format.money( value );
				},

				/**
				 * Downloads the tax summary as a CSV file.
				 */
				exportTax: function () {
					this.runExport( 'tax', this.taxQuery );
				},

				/**
				 * Opens the Tax settings window with the saved values.
				 */
				openTaxSettings: function () {
					var current = config.tax || {};
					this.taxForm = {
						open: true,
						busy: false,
						error: '',
						fields: {},
						system: this.taxSystem,
						rate: current.rate,
						reporting_period: this.taxPeriod,
					};
					var self = this;
					this.$nextTick( function () {
						var first = self.$root.querySelector( '[x-ref="taxSystem"]' );
						if ( first ) {
							first.focus();
						}
					} );
				},

				/**
				 * Closes the Tax settings window.
				 */
				closeTaxSettings: function () {
					this.taxForm.open = false;
					var button = this.$root.querySelector( '[x-ref="taxSettingsButton"]' );
					if ( button ) {
						button.focus();
					}
				},

				/**
				 * Checks and saves the tax settings, then reloads the summary.
				 */
				saveTaxSettings: function () {
					var self = this;
					var form = this.taxForm;
					var rate = Number( String( form.rate ).replace( ',', '.' ) );

					form.fields = {};
					form.error = '';
					if ( form.system !== 'none' && ( String( form.rate ).trim() === '' || isNaN( rate ) || rate < 0 || rate > 100 ) ) {
						form.fields.rate = t.rateError;
						return;
					}

					form.busy = true;
					api( 'settings', {
						method: 'POST',
						body: { settings: { tax: { system: form.system, rate: form.system === 'none' ? ( config.tax || {} ).rate : rate, reporting_period: form.reporting_period } } },
					} ).then( function ( saved ) {
						config.tax = saved.tax;
						self.taxSystem = saved.tax.system;
						self.taxPeriod = saved.tax.reporting_period;
						self.closeTaxSettings();
						self.loadTax( true );
						window.dispatchEvent( new window.CustomEvent( 'kdna:ei-data-loaded', { detail: { source: 'tax-settings' } } ) );
					} ).catch( function ( error ) {
						form.error = error.message;
						form.fields = error.fields || {};
					} ).finally( function () {
						form.busy = false;
					} );
				},

				/*
				 * -------------------------------------------------------------
				 * Printable report
				 * -------------------------------------------------------------
				 */

				/**
				 * Opens the printable report for the chosen dates.
				 */
				openPrint: function () {
					window.KDNAEI.printReport( this.rangeQuery );
				},

				/*
				 * -------------------------------------------------------------
				 * Email digest
				 * -------------------------------------------------------------
				 */

				/**
				 * Fetches the digest settings and timing.
				 *
				 * @return {Promise}
				 */
				loadDigest: function () {
					var self = this;
					return api( 'digest' ).then( function ( data ) {
						self.setDigest( data );
					} ).catch( function ( error ) {
						self.digestNotice = { type: 'negative', text: error.message };
					} );
				},

				/**
				 * Puts digest data from the server into the form.
				 *
				 * @param {Object} data Digest overview.
				 */
				setDigest: function ( data ) {
					this.digest = data;
					this.digestForm = { frequency: data.frequency, recipients: data.recipients, sections: data.sections.slice() };
					this.digestSaved = copy( this.digestForm );
				},

				/**
				 * The section choices, in order.
				 *
				 * @return {Object[]} Each: key, label.
				 */
				get sectionOptions() {
					var options = ( this.digest && this.digest.options ) || {};
					return Object.keys( options ).map( function ( key ) {
						return { key: key, label: options[ key ] };
					} );
				},

				/**
				 * Whether the form differs from what is saved.
				 *
				 * @return {boolean}
				 */
				get digestDirty() {
					return !! this.digestSaved && JSON.stringify( this.digestForm ) !== JSON.stringify( this.digestSaved );
				},

				/**
				 * The frequency used for Preview and Send test (weekly when off).
				 *
				 * @return {string}
				 */
				get previewFrequency() {
					return this.digestForm.frequency === 'monthly' ? 'monthly' : 'weekly';
				},

				/**
				 * One line about when the next digest goes out, or why none will.
				 *
				 * @return {string}
				 */
				get nextSendText() {
					if ( ! this.digest ) {
						return '';
					}
					if ( this.digestSaved.frequency === 'off' ) {
						return t.digestOff;
					}
					if ( ! this.digest.next_send ) {
						return t.notScheduled;
					}
					return sprintf( t.nextSend, this.dateTime( this.digest.next_send ), dates.short( this.digest.next_range.start ), dates.short( this.digest.next_range.end ) );
				},

				/**
				 * One line about the last digest sent.
				 *
				 * @return {string}
				 */
				get lastSendText() {
					var last = this.digest && this.digest.last;
					if ( ! last ) {
						return '';
					}
					return sprintf( last.status === 'success' ? t.lastSent : t.lastFailed, this.dateTime( last.sent_at ) );
				},

				/**
				 * A GMT date and time shown in the browser's time, such as
				 * "Mon 12 Oct, 7:00 am".
				 *
				 * @param {string} gmt Y-m-d H:i:s in GMT.
				 * @return {string}
				 */
				dateTime: function ( gmt ) {
					var date = new Date( String( gmt ).replace( ' ', 'T' ) + 'Z' );
					if ( isNaN( date ) ) {
						return gmt;
					}
					return date.toLocaleString( config.locale || undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' } );
				},

				/**
				 * Ticks or unticks a section.
				 *
				 * @param {string} key Section key.
				 */
				toggleSection: function ( key ) {
					var list = this.digestForm.sections;
					var index = list.indexOf( key );
					if ( index === -1 ) {
						var order = Object.keys( this.digest.options );
						list.push( key );
						list.sort( function ( a, b ) {
							return order.indexOf( a ) - order.indexOf( b );
						} );
					} else {
						list.splice( index, 1 );
					}
					delete this.digestErrors.sections;
				},

				/**
				 * The body sent for saving, previewing and testing.
				 *
				 * @return {Object}
				 */
				digestBody: function () {
					return {
						frequency: this.digestForm.frequency,
						recipients: this.digestForm.recipients,
						sections: this.digestForm.sections,
					};
				},

				/**
				 * Shows a server error against its field, or as a notice.
				 *
				 * @param {Error} error Error from the API.
				 */
				digestFailed: function ( error ) {
					this.digestErrors = error.fields || {};
					this.digestNotice = { type: 'negative', text: error.message };
					var self = this;
					this.$nextTick( function () {
						var field = self.$root.querySelector( '.kdna-ei-digest .is-invalid' );
						if ( field ) {
							field.focus();
						}
					} );
				},

				/**
				 * Saves the digest settings.
				 */
				saveDigest: function () {
					var self = this;
					this.digestBusy = 'save';
					this.digestErrors = {};
					this.digestNotice = null;
					api( 'digest', { method: 'POST', body: this.digestBody() } ).then( function ( data ) {
						self.setDigest( data );
						self.digestNotice = { type: 'positive', text: data.frequency === 'off' ? t.savedOff : t.saved };
					} ).catch( function ( error ) {
						self.digestFailed( error );
					} ).finally( function () {
						self.digestBusy = '';
					} );
				},

				/**
				 * Sends a test digest to the addresses in the form.
				 */
				sendTest: function () {
					var self = this;
					this.digestBusy = 'test';
					this.digestErrors = {};
					this.digestNotice = null;
					api( 'digest/test', { method: 'POST', body: Object.assign( this.digestBody(), { frequency: this.previewFrequency } ) } ).then( function ( data ) {
						self.digestNotice = { type: 'positive', text: data.message };
					} ).catch( function ( error ) {
						self.digestFailed( error );
					} ).finally( function () {
						self.digestBusy = '';
					} );
				},

				/**
				 * Opens the preview window and loads the email into it.
				 */
				openPreview: function () {
					var self = this;
					this.preview = { open: true, loading: true, html: '', subject: '', dates: '', width: this.preview.width, error: '' };
					this.$nextTick( function () {
						var dialog = self.$root.querySelector( '[x-ref="previewDialog"]' );
						if ( dialog ) {
							dialog.focus();
						}
					} );
					api( 'digest/preview', { query: { frequency: this.previewFrequency, sections: this.digestForm.sections } } ).then( function ( data ) {
						self.preview.html = data.html;
						self.preview.subject = data.subject;
						self.preview.dates = data.dates;
					} ).catch( function ( error ) {
						self.preview.error = error.message;
					} ).finally( function () {
						self.preview.loading = false;
					} );
				},

				/**
				 * Closes the preview window.
				 */
				closePreview: function () {
					this.preview.open = false;
					var button = this.$root.querySelector( '[x-ref="previewButton"]' );
					if ( button ) {
						button.focus();
					}
				},

				/**
				 * Keeps keyboard focus inside an open window.
				 *
				 * @param {KeyboardEvent} event Tab key press.
				 * @param {string}        ref   The window's x-ref name.
				 */
				trap: function ( event, ref ) {
					var dialog = this.$root.querySelector( '[x-ref="' + ref + '"]' );
					var items = dialog ? focusable( dialog ) : [];
					if ( ! items.length ) {
						return;
					}
					var first = items[ 0 ];
					var last = items[ items.length - 1 ];
					if ( event.shiftKey && ( document.activeElement === first || document.activeElement === dialog ) ) {
						event.preventDefault();
						last.focus();
					} else if ( ! event.shiftKey && document.activeElement === last ) {
						event.preventDefault();
						first.focus();
					}
				},

				/*
				 * -------------------------------------------------------------
				 * Exports
				 * -------------------------------------------------------------
				 */

				/**
				 * Every export, grouped by screen.
				 *
				 * @return {Object[]} Each: screen, label, tables (key, label).
				 */
				get exportList() {
					var groups = this.exportGroups;
					var screens = config.screens || {};
					return Object.keys( groups ).map( function ( screen ) {
						return {
							screen: screen,
							label: screens[ screen ] || screen,
							tables: Object.keys( groups[ screen ] ).map( function ( key ) {
								return { key: key, label: groups[ screen ][ key ] };
							} ),
						};
					} );
				},

				/**
				 * Downloads one table for the chosen dates.
				 *
				 * @param {string} table Table key.
				 */
				exportOne: function ( table ) {
					this.runExport( table, table === 'tax' ? this.taxQuery : this.rangeQuery );
				},

				/**
				 * Downloads a table, showing which one is being prepared.
				 *
				 * @param {string} table Table key.
				 * @param {Object} query Query.
				 */
				runExport: function ( table, query ) {
					var self = this;
					this.exportBusy = table;
					this.exportError = '';
					window.KDNAEI.exportCsv( table, query ).catch( function ( error ) {
						self.exportError = error.message;
					} ).finally( function () {
						self.exportBusy = '';
					} );
				},
			};
		} );
	} );
}( window, document ) );
