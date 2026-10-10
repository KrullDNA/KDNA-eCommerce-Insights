/**
 * KDNA eCommerce Insights: Customers screen.
 *
 * Customer figures, the new and returning chart, the revenue split, the
 * cohort retention grid, top customers and locations for the chosen dates.
 * Guests are matched by billing email on the server, so a repeat guest is
 * one customer. Reloads when the date range changes.
 */
( function ( window, document ) {
	'use strict';

	var config = window.kdnaEiApp || {};
	var t = ( config.i18n && config.i18n.customers ) || {};
	var format = window.KDNAEI.format;
	var dates = window.KDNAEI.dates;
	var api = window.KDNAEI.api;
	var charts = window.KDNAEI.charts;
	var sprintf = window.KDNAEI.sprintf;

	/**
	 * KPIs in the strip, in order.
	 */
	var KPI_KEYS = [ 'customers', 'repeat_purchase_rate', 'average_lifetime_value', 'average_orders_per_customer', 'time_between_orders' ];

	/**
	 * Figures worked out over every order ever placed, not just the range.
	 */
	var LIFETIME = [ 'repeat_purchase_rate', 'average_lifetime_value', 'average_orders_per_customer', 'time_between_orders' ];

	/**
	 * Icons for the KPI strip.
	 */
	var ICONS = {
		customers: 'users',
		repeat_purchase_rate: 'users-plus',
		average_lifetime_value: 'coins',
		average_orders_per_customer: 'cart',
		time_between_orders: 'calendar',
	};

	document.addEventListener( 'alpine:init', function () {
		window.Alpine.data( 'kdnaEiCustomers', function () {
			// The chart stays outside Alpine's reactive data.
			var held = { chart: null, root: null };

			return {
				t: t,
				loaded: false,
				loading: false,
				stale: false,
				loadError: '',
				exporting: false,
				requestId: 0,

				metrics: [],
				series: null,
				cohorts: [],
				topCustomers: [],
				locations: [],
				locationLevel: 'country',
				showAllCustomers: false,

				/**
				 * Reloads when the date range changes: now if this screen is
				 * open, otherwise the next time it is opened.
				 */
				init: function () {
					var self = this;
					held.root = this.$el;
					var refresh = function () {
						if ( ! self.loaded ) {
							return;
						}
						if ( self.route === 'customers' ) {
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
				 * Fetches the customer report and the new and returning series.
				 *
				 * @param {boolean} fresh Skip the server cache.
				 * @return {Promise}
				 */
				load: function ( fresh ) {
					var self = this;
					var id = ++this.requestId;
					var query = Object.assign( {}, this.rangeQuery, fresh ? { fresh: 1 } : {} );

					this.loading = true;
					this.stale = false;
					this.loadError = '';

					return Promise.all( [
						api( 'customers', { query: query } ),
						api( 'timeseries', { query: Object.assign( {}, query, { metrics: 'new_customers,returning_customers' } ) } ),
					] ).then( function ( results ) {
						if ( id !== self.requestId ) {
							return;
						}
						var data = results[ 0 ].data;
						self.metrics = data.metrics;
						self.cohorts = data.cohorts;
						self.topCustomers = data.top_customers;
						self.locations = data.locations;
						self.series = results[ 1 ].data;
						self.loaded = true;
						self.$nextTick( function () {
							self.drawChart();
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

				/*
				 * -------------------------------------------------------------
				 * KPI strip and revenue split
				 * -------------------------------------------------------------
				 */

				/**
				 * One metric from the report by key.
				 *
				 * @param {string} key Metric key.
				 * @return {Object|null}
				 */
				metricOf: function ( key ) {
					return this.metrics.filter( function ( m ) {
						return m.key === key;
					} )[ 0 ] || null;
				},

				/**
				 * The KPIs in the strip.
				 *
				 * @return {Object[]}
				 */
				get kpis() {
					var self = this;
					return KPI_KEYS.map( function ( key ) {
						return self.metricOf( key );
					} ).filter( Boolean );
				},

				/**
				 * Whether a KPI covers every order ever, rather than the range.
				 *
				 * @param {string} key Metric key.
				 * @return {boolean}
				 */
				isLifetime: function ( key ) {
					return LIFETIME.indexOf( key ) !== -1;
				},

				/**
				 * Icon for a KPI.
				 *
				 * @param {string} key Metric key.
				 * @return {string}
				 */
				kpiIcon: function ( key ) {
					return ICONS[ key ] || 'users';
				},

				/**
				 * Change text beside a KPI.
				 *
				 * @param {Object} kpi Metric.
				 * @return {string}
				 */
				changeText: function ( kpi ) {
					return window.KDNAEI.kpi.changeText( kpi );
				},

				/**
				 * Comparison sentence for a KPI.
				 *
				 * @param {Object} kpi Metric.
				 * @return {string}
				 */
				previousText: function ( kpi ) {
					return window.KDNAEI.kpi.previousText( kpi, window.KDNAEI.kpi.comparisonLabel( this.comparison ) );
				},

				/**
				 * New and returning customers with their share and revenue.
				 *
				 * @return {Object[]}
				 */
				get splitRows() {
					var value = function ( m ) {
						return m && m.value ? Number( m.value ) : 0;
					};
					var newCount = value( this.metricOf( 'new_customers' ) );
					var returning = value( this.metricOf( 'returning_customers' ) );
					var newRevenue = value( this.metricOf( 'new_customer_revenue' ) );
					var returningRevenue = value( this.metricOf( 'returning_customer_revenue' ) );
					var people = Math.max( 1, newCount + returning );
					var money = Math.max( 1, Math.abs( newRevenue ) + Math.abs( returningRevenue ) );

					return [
						{ key: 'new', label: t.newCustomers, token: 'accent', count: newCount, share: newCount / people * 100, revenue: newRevenue, revenueShare: Math.round( newRevenue / money * 100 ) },
						{ key: 'returning', label: t.returningCustomers, token: 'accent-2', count: returning, share: returning / people * 100, revenue: returningRevenue, revenueShare: Math.round( returningRevenue / money * 100 ) },
					];
				},

				/*
				 * -------------------------------------------------------------
				 * New and returning chart
				 * -------------------------------------------------------------
				 */

				/**
				 * Short description of the chart for screen readers.
				 *
				 * @return {string}
				 */
				get nvrSummary() {
					var rows = this.splitRows;
					return sprintf( t.chartSummary, this.formatNumber( rows[ 0 ].count ), this.formatNumber( rows[ 1 ].count ) );
				},

				/**
				 * Draws the new and returning chart, or updates it in place.
				 */
				drawChart: function () {
					var self = this;
					var canvas = held.root && held.root.querySelector( '[x-ref="nvr"]' );
					if ( ! charts || ! this.series || ! canvas ) {
						return;
					}
					var buckets = this.series.buckets;
					var granularity = this.series.granularity;
					var options = {
						labels: buckets.map( function ( b ) {
							return b.key;
						} ),
						series: [
							{ label: t.newCustomers, data: this.series.series.new_customers.current.slice(), colour: 'accent' },
							{ label: t.returningCustomers, data: this.series.series.returning_customers.current.slice(), colour: 'accent2' },
						],
						maxXTicks: 7,
						xLabel: function ( i ) {
							return dates.axis( buckets, i, granularity );
						},
						yLabel: function ( value ) {
							return format.compact( value );
						},
						title: function ( i ) {
							return dates.title( buckets, i, granularity );
						},
						value: function ( v ) {
							return self.formatNumber( v );
						},
					};

					if ( held.chart && held.chart.canvas === canvas ) {
						charts.updateBarChart( held.chart, options );
					} else {
						held.chart = charts.barChart( canvas, options );
					}
				},

				/*
				 * -------------------------------------------------------------
				 * Cohort grid
				 * -------------------------------------------------------------
				 */

				/**
				 * The number of month columns in the grid.
				 *
				 * @return {number}
				 */
				get cohortWidth() {
					return this.cohorts.reduce( function ( most, c ) {
						return Math.max( most, c.retention.length );
					}, 0 );
				},

				/**
				 * The highest retention after the first month, so the shading
				 * uses the full range of colour.
				 *
				 * @return {number}
				 */
				get cohortMax() {
					var most = 1;
					this.cohorts.forEach( function ( c ) {
						c.retention.slice( 1 ).forEach( function ( v ) {
							most = Math.max( most, v );
						} );
					} );
					return most;
				},

				/**
				 * Shading for a grid cell: stronger colour for more customers
				 * coming back. The first month (always 100%) is kept quiet.
				 *
				 * @param {number|undefined} value  Retention percentage.
				 * @param {number}           offset Months since the first order.
				 * @return {string}
				 */
				cellStyle: function ( value, offset ) {
					if ( value === undefined ) {
						return '';
					}
					if ( offset === 0 ) {
						return 'background: color-mix(in srgb, var(--kdna-ei-text-muted) 12%, transparent);';
					}
					var strength = Math.round( Math.min( 1, value / this.cohortMax ) * 70 );
					var style = 'background: color-mix(in srgb, var(--kdna-ei-accent) ' + Math.max( 4, strength ) + '%, transparent);';
					return strength > 45 ? style + ' color: #FFFFFF;' : style;
				},

				/**
				 * Tooltip for a grid cell, for example "5 of 19 customers ordered
				 * again in March 2026".
				 *
				 * @param {Object} cohort Cohort row.
				 * @param {number} offset Months since the first order.
				 * @return {string}
				 */
				cellTitle: function ( cohort, offset ) {
					var value = cohort.retention[ offset ];
					if ( value === undefined ) {
						return '';
					}
					var month = dates.toDate( cohort.month + '-01' );
					month.setMonth( month.getMonth() + offset );
					var monthText = month.toLocaleDateString( config.locale || undefined, { month: 'long', year: 'numeric' } );
					return sprintf( offset === 0 ? t.cellFirst : t.cellTitle, this.formatNumber( Math.round( cohort.size * value / 100 ) ), this.formatNumber( cohort.size ), monthText );
				},

				/**
				 * A Y-m month as text, for example "Jan 2026".
				 *
				 * @param {string} month Y-m.
				 * @return {string}
				 */
				monthName: function ( month ) {
					return dates.text( month + '-01', { month: 'short', year: 'numeric' } );
				},

				/*
				 * -------------------------------------------------------------
				 * Top customers and locations
				 * -------------------------------------------------------------
				 */

				/**
				 * Top customers shown: ten, or all 25 when expanded.
				 *
				 * @return {Object[]}
				 */
				get visibleCustomers() {
					return this.showAllCustomers ? this.topCustomers : this.topCustomers.slice( 0, 10 );
				},

				/**
				 * Initials for a customer's avatar.
				 *
				 * @param {string} name  Name.
				 * @param {string} email Email.
				 * @return {string}
				 */
				initials: function ( name, email ) {
					var source = name && name !== t.guest ? name : ( email || '?' );
					var parts = source.replace( /@.*/, '' ).split( /[\s._-]+/ ).filter( Boolean );
					return ( ( parts[ 0 ] || '?' ).charAt( 0 ) + ( parts[ 1 ] ? parts[ 1 ].charAt( 0 ) : '' ) ).toUpperCase();
				},

				/**
				 * Locations by country (states added together) or by state,
				 * biggest ten by revenue, each with a bar length.
				 *
				 * @return {Object[]}
				 */
				get locationRows() {
					var grouped = {};
					var level = this.locationLevel;

					this.locations.forEach( function ( place ) {
						var key = level === 'state' ? place.country + ':' + place.state : place.country;
						var name = level === 'state' && place.state_name ? place.state_name + ', ' + place.country_name : place.country_name;
						if ( ! grouped[ key ] ) {
							grouped[ key ] = { key: key, name: name || t.unknownPlace, customers: 0, revenue: 0 };
						}
						grouped[ key ].customers += place.customers;
						grouped[ key ].revenue += place.revenue;
					} );

					var rows = Object.keys( grouped ).map( function ( key ) {
						return grouped[ key ];
					} ).sort( function ( a, b ) {
						return b.revenue - a.revenue;
					} ).slice( 0, 10 );

					var top = rows.length ? Math.max( 1, rows[ 0 ].revenue ) : 1;
					return rows.map( function ( row ) {
						return Object.assign( row, { share: Math.max( 2, row.revenue / top * 100 ) } );
					} );
				},

				/**
				 * Downloads a table as CSV for the current range.
				 *
				 * @param {string} table customers, cohorts or locations.
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
				 * Formats a percentage.
				 *
				 * @param {number} value Percentage.
				 * @return {string}
				 */
				percent: function ( value ) {
					return format.percent( value );
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
				 * Formats a whole number.
				 *
				 * @param {number} value Number.
				 * @return {string}
				 */
				formatNumber: function ( value ) {
					return format.number( value || 0, 0 );
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
