/**
 * KDNA eCommerce Insights: Inventory screen.
 *
 * Stock figures, the status donut, the stock value trend from the nightly
 * snapshots, the reorder planner, low, out of stock and dead stock tables,
 * and the stock settings with optional low stock emails. Stock is always
 * "now"; the date range only changes the dates of the trend.
 */
( function ( window, document ) {
	'use strict';

	var config = window.kdnaEiApp || {};
	var t = ( config.i18n && config.i18n.inventory ) || {};
	var format = window.KDNAEI.format;
	var dates = window.KDNAEI.dates;
	var api = window.KDNAEI.api;
	var charts = window.KDNAEI.charts;
	var sprintf = window.KDNAEI.sprintf;

	/**
	 * KPIs in the strip, with their icons.
	 */
	var KPIS = [
		{ key: 'units_in_stock', icon: 'box' },
		{ key: 'stock_value_cost', icon: 'coins' },
		{ key: 'stock_value_retail', icon: 'tag' },
		{ key: 'low_stock', icon: 'alert' },
		{ key: 'out_of_stock', icon: 'cart' },
	];

	/**
	 * An empty report, so the view has something to read before loading.
	 */
	var EMPTY = {
		metrics: [],
		status: { in_stock: 0, low_stock: 0, out_of_stock: 0 },
		counts: { low_stock: 0, out_of_stock: 0, days_of_cover: 0, dead_stock: 0 },
		low_stock: [],
		out_of_stock: [],
		days_of_cover: [],
		dead_stock: [],
		trend: [],
		dead_days: 90,
		dead_value: 0,
		lead_days: 14,
		threshold: 0,
		store_threshold: 2,
		no_cost: 0,
	};

	document.addEventListener( 'alpine:init', function () {
		window.Alpine.data( 'kdnaEiInventory', function () {
			// Charts stay outside Alpine's reactive data.
			var held = { status: null, trend: null, root: null };
			var alerts = config.alerts || {};

			return {
				t: t,
				loaded: false,
				loading: false,
				stale: false,
				loadError: '',
				exporting: false,
				requestId: 0,
				report: Object.assign( {}, EMPTY ),
				expanded: { days_of_cover: false, low_stock: false, out_of_stock: false, dead_stock: false },

				settingsOpen: false,
				saving: false,
				testing: false,
				settingsMessage: '',
				errors: {},
				form: {
					low_stock_threshold: alerts.low_stock_threshold ? String( alerts.low_stock_threshold ) : '',
					dead_stock_days: String( alerts.dead_stock_days || 90 ),
					reorder_lead_days: String( alerts.reorder_lead_days === undefined ? 14 : alerts.reorder_lead_days ),
					low_stock_emails: !! alerts.low_stock_emails,
					alert_recipients: alerts.alert_recipients || '',
				},

				/**
				 * Reloads when the date range changes (for the trend) or when
				 * orders finish processing.
				 */
				init: function () {
					var self = this;
					held.root = this.$el;
					var refresh = function () {
						if ( ! self.loaded ) {
							return;
						}
						if ( self.route === 'inventory' ) {
							self.load();
						} else {
							self.stale = true;
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
					if ( ( ! this.loaded || this.stale ) && ! this.loading ) {
						this.load();
					}
				},

				/**
				 * Fetches the inventory report.
				 *
				 * @param {boolean} fresh Skip the server cache.
				 * @return {Promise}
				 */
				load: function ( fresh ) {
					var self = this;
					var id = ++this.requestId;

					this.loading = true;
					this.stale = false;
					this.loadError = '';

					return api( 'inventory', { query: Object.assign( {}, this.rangeQuery, fresh ? { fresh: 1 } : {} ) } ).then( function ( response ) {
						if ( id !== self.requestId ) {
							return;
						}
						self.report = Object.assign( {}, EMPTY, response.data );
						self.loaded = true;
						self.$nextTick( function () {
							self.drawStatus();
							self.drawTrend();
						} );
					} ).catch( function ( error ) {
						if ( id === self.requestId ) {
							self.loadError = error.message;
						}
					} ).finally( function () {
						if ( id === self.requestId ) {
							self.loading = false;
						}
					} );
				},

				/**
				 * Finds one of this screen's canvases by its x-ref name.
				 *
				 * @param {string} name x-ref name.
				 * @return {Element|null}
				 */
				element: function ( name ) {
					return held.root ? held.root.querySelector( '[x-ref="' + name + '"]' ) : null;
				},

				/*
				 * -------------------------------------------------------------
				 * KPI strip and status donut
				 * -------------------------------------------------------------
				 */

				/**
				 * The KPIs in the strip, with icons and a warning colour for low
				 * and out of stock counts above zero.
				 *
				 * @return {Object[]}
				 */
				get kpis() {
					var metrics = this.report.metrics;
					return KPIS.map( function ( item ) {
						var metric = metrics.filter( function ( m ) {
							return m.key === item.key;
						} )[ 0 ];
						if ( ! metric ) {
							return null;
						}
						var tone = '';
						if ( item.key === 'low_stock' && metric.value > 0 ) {
							tone = 'is-warning-text';
						} else if ( item.key === 'out_of_stock' && metric.value > 0 ) {
							tone = 'is-negative';
						}
						return Object.assign( {}, metric, { icon: item.icon, tone: tone } );
					} ).filter( Boolean );
				},

				/**
				 * Legend rows for the status donut.
				 *
				 * @return {Object[]}
				 */
				get statusRows() {
					var s = this.report.status;
					var total = s.in_stock + s.low_stock + s.out_of_stock;
					var pct = function ( value ) {
						if ( ! total ) {
							return '0%';
						}
						var p = Math.round( value / total * 100 );
						return ( value > 0 && p === 0 ? '<1' : p ) + '%';
					};
					return [
						{ key: 'in_stock', label: t.inStock, token: 'accent', chart: 'accent', value: s.in_stock, percent: pct( s.in_stock ) },
						{ key: 'low_stock', label: t.lowStock, token: 'positive', chart: 'positive', value: s.low_stock, percent: pct( s.low_stock ) },
						{ key: 'out_of_stock', label: t.outOfStock, token: 'accent-2', chart: 'accent2', value: s.out_of_stock, percent: pct( s.out_of_stock ) },
					];
				},

				/**
				 * Short description of the donut for screen readers.
				 *
				 * @return {string}
				 */
				get statusSummary() {
					return this.statusRows.map( function ( row ) {
						return row.label + ' ' + row.value;
					} ).join( ', ' );
				},

				/**
				 * Draws the status donut, or updates it in place.
				 */
				drawStatus: function () {
					var canvas = this.element( 'status' );
					if ( ! charts || ! canvas ) {
						return;
					}
					var rows = this.statusRows;
					var values = rows.map( function ( r ) {
						return r.value;
					} );
					if ( ! values.some( function ( v ) {
						return v > 0;
					} ) ) {
						values = [ 1, 0, 0 ];
					}
					if ( held.status && held.status.canvas === canvas ) {
						held.status.data.datasets[ 0 ].data = values;
						held.status.update();
						return;
					}
					held.status = charts.donutChart( canvas, {
						labels: rows.map( function ( r ) {
							return r.label;
						} ),
						values: values,
						colours: rows.map( function ( r ) {
							return r.chart;
						} ),
						cutout: '72%',
					} );
				},

				/*
				 * -------------------------------------------------------------
				 * Stock value trend
				 * -------------------------------------------------------------
				 */

				/**
				 * Short description of the trend for screen readers.
				 *
				 * @return {string}
				 */
				get trendSummary() {
					var last = this.report.trend[ this.report.trend.length - 1 ];
					return last ? sprintf( t.trendSummary, this.money( last.cost, 0 ), this.money( last.retail, 0 ) ) : t.trendEmpty;
				},

				/**
				 * Draws the stock value trend, or updates it in place. Needs at
				 * least two nightly snapshots.
				 */
				drawTrend: function () {
					var self = this;
					var canvas = this.element( 'trend' );
					var trend = this.report.trend;
					if ( ! charts || ! canvas || trend.length < 2 ) {
						return;
					}
					var buckets = trend.map( function ( row ) {
						return { start: row.day };
					} );
					var options = {
						labels: trend.map( function ( row ) {
							return row.day;
						} ),
						series: [
							{ label: t.atCost, data: trend.map( function ( r ) {
								return r.cost;
							} ), colour: 'accent', fill: true },
							{ label: t.atRetail, data: trend.map( function ( r ) {
								return r.retail;
							} ), colour: 'accent2', fill: false },
						],
						maxXTicks: 6,
						xLabel: function ( i ) {
							return dates.axis( buckets, i, 'day' );
						},
						yLabel: function ( value ) {
							return format.compact( value );
						},
						title: function ( i ) {
							return dates.title( buckets, i, 'day' );
						},
						value: function ( v ) {
							return self.money( v, 0 );
						},
						footer: function ( i ) {
							return { text: sprintf( t.unitsInStock, self.formatNumber( trend[ i ].units ) ) };
						},
					};
					if ( held.trend && held.trend.canvas === canvas ) {
						charts.updateLineChart( held.trend, options );
					} else {
						held.trend = charts.lineChart( canvas, options );
					}
				},

				/*
				 * -------------------------------------------------------------
				 * Tables
				 * -------------------------------------------------------------
				 */

				/**
				 * Rows shown in a table: eight, or all when expanded.
				 *
				 * @param {string} key List key.
				 * @return {Object[]}
				 */
				rowsOf: function ( key ) {
					var rows = this.report[ key ] || [];
					return this.expanded[ key ] ? rows : rows.slice( 0, 8 );
				},

				/**
				 * Shows all or fewer rows of a table.
				 *
				 * @param {string} key List key.
				 */
				toggleList: function ( key ) {
					this.expanded[ key ] = ! this.expanded[ key ];
				},

				/**
				 * Colour for days of stock left: red when it runs out within the
				 * lead time, amber within twice the lead time.
				 *
				 * @param {Object} row Row.
				 * @return {string}
				 */
				daysClass: function ( row ) {
					if ( row.reorder_now ) {
						return 'is-negative';
					}
					return row.days <= this.report.lead_days * 2 ? 'is-warning-text' : '';
				},

				/**
				 * Units sold per day, for example "0.3" or "12".
				 *
				 * @param {number} value Units per day.
				 * @return {string}
				 */
				perDay: function ( value ) {
					return format.number( value, value >= 10 ? 0 : 1 );
				},

				/**
				 * Note under the Low stock title explaining the threshold used.
				 *
				 * @return {string}
				 */
				get thresholdNote() {
					return this.report.threshold > 0
						? sprintf( t.thresholdOwn, this.formatNumber( this.report.threshold ) )
						: sprintf( t.thresholdStore, this.formatNumber( this.report.store_threshold ) );
				},

				/**
				 * Note under the Dead stock title.
				 *
				 * @return {string}
				 */
				get deadNote() {
					return sprintf( t.deadNote, this.formatNumber( this.report.dead_days ), this.money( this.report.dead_value, 0 ) );
				},

				/**
				 * Downloads a table as CSV.
				 *
				 * @param {string} table days_of_cover, low_stock, out_of_stock or dead_stock.
				 */
				exportCsv: function ( table ) {
					var self = this;
					this.exporting = true;
					window.KDNAEI.exportCsv( table, this.rangeQuery ).catch( function ( error ) {
						self.loadError = error.message;
					} ).finally( function () {
						self.exporting = false;
					} );
				},

				/*
				 * -------------------------------------------------------------
				 * Settings and alerts
				 * -------------------------------------------------------------
				 */

				/**
				 * Checks the settings form, with plain-English messages.
				 *
				 * @return {Object|false} Clean values, or false.
				 */
				checkSettings: function () {
					var errors = {};
					var whole = function ( text ) {
						var value = String( text ).trim();
						return value === '' ? 0 : ( /^\d+$/.test( value ) ? Number( value ) : NaN );
					};
					var threshold = whole( this.form.low_stock_threshold );
					var dead = whole( this.form.dead_stock_days );
					var lead = whole( this.form.reorder_lead_days );

					if ( isNaN( threshold ) ) {
						errors.low_stock_threshold = t.wholeNumber;
					}
					if ( isNaN( dead ) || dead < 1 ) {
						errors.dead_stock_days = t.deadDaysError;
					}
					if ( isNaN( lead ) || lead > 365 ) {
						errors.reorder_lead_days = t.leadDaysError;
					}

					var emails = this.form.alert_recipients.split( ',' ).map( function ( e ) {
						return e.trim();
					} ).filter( Boolean );
					if ( this.form.low_stock_emails && ( ! emails.length || emails.some( function ( e ) {
						return ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( e );
					} ) ) ) {
						errors.alert_recipients = t.emailError;
					}

					this.errors = errors;
					if ( Object.keys( errors ).length ) {
						return false;
					}
					return {
						low_stock_threshold: threshold,
						dead_stock_days: dead,
						reorder_lead_days: lead,
						low_stock_emails: this.form.low_stock_emails,
						alert_recipients: emails.join( ', ' ),
					};
				},

				/**
				 * Saves the stock settings and reloads the figures.
				 *
				 * @return {Promise|undefined}
				 */
				saveSettings: function () {
					var self = this;
					var values = this.checkSettings();
					if ( ! values ) {
						this.settingsMessage = '';
						return;
					}
					this.saving = true;
					this.settingsMessage = '';
					return api( 'settings', { method: 'POST', body: { settings: { alerts: values } } } ).then( function ( settings ) {
						config.alerts = settings.alerts;
						self.settingsMessage = t.saved;
						return self.load( true );
					} ).catch( function ( error ) {
						self.settingsMessage = error.message;
					} ).finally( function () {
						self.saving = false;
					} );
				},

				/**
				 * Saves the settings, then sends a test low stock email.
				 *
				 * @return {Promise|undefined}
				 */
				sendTest: function () {
					var self = this;
					var values = this.checkSettings();
					if ( ! values ) {
						return;
					}
					if ( ! values.alert_recipients ) {
						this.errors = { alert_recipients: t.emailError };
						return;
					}
					this.testing = true;
					this.settingsMessage = t.sending;
					return api( 'settings', { method: 'POST', body: { settings: { alerts: values } } } ).then( function () {
						return api( 'inventory/test-alert', { method: 'POST' } );
					} ).then( function ( result ) {
						self.settingsMessage = result.message;
					} ).catch( function ( error ) {
						self.settingsMessage = error.message;
					} ).finally( function () {
						self.testing = false;
					} );
				},

				/*
				 * -------------------------------------------------------------
				 * Formatting
				 * -------------------------------------------------------------
				 */

				/**
				 * Formats money.
				 *
				 * @param {number} value    Amount.
				 * @param {number} decimals Decimal places.
				 * @return {string}
				 */
				money: function ( value, decimals ) {
					return format.money( value, decimals );
				},

				/**
				 * Formats any metric value by its kind.
				 *
				 * @param {number} value    Value.
				 * @param {string} kind     Format.
				 * @param {number} decimals Decimal places.
				 * @return {string}
				 */
				metric: function ( value, kind, decimals ) {
					return format.metric( value, kind, decimals );
				},

				/**
				 * Formats a number (whole, or with one decimal when needed).
				 *
				 * @param {number} value Number.
				 * @return {string}
				 */
				formatNumber: function ( value ) {
					var n = Number( value || 0 );
					return format.number( n, n % 1 ? 1 : 0 );
				},

				/**
				 * A Y-m-d date as short text.
				 *
				 * @param {string} value Date.
				 * @return {string}
				 */
				dateLabel: function ( value ) {
					return dates.short( value );
				},

				sprintf: sprintf,
			};
		} );
	} );
}( window, document ) );
