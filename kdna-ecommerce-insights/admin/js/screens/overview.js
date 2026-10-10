/**
 * KDNA eCommerce Insights: Overview screen.
 *
 * Loads the KPI strip, performance chart, inventory donut, hero card and
 * alerts for the chosen date range, and reloads them all together when the
 * range or comparison changes (kdna:ei-range-change). Numbers count up on
 * first load and charts draw in, unless the visitor prefers reduced motion.
 */
( function ( window, document ) {
	'use strict';

	var config = window.kdnaEiApp || {};
	var t = ( config.i18n && config.i18n.overview ) || {};
	var format = window.KDNAEI.format;
	var api = window.KDNAEI.api;
	var charts = window.KDNAEI.charts;
	var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	/**
	 * Icons for the KPI strip, by metric. Anything else uses the chart icon.
	 */
	var KPI_ICONS = {
		net_revenue: 'trending-up',
		gross_sales: 'trending-up',
		net_profit: 'coins',
		gross_profit: 'coins',
		contribution_profit: 'coins',
		orders: 'cart',
		items_sold: 'bag',
		net_margin: 'percent',
		gross_margin: 'percent',
		new_customers: 'users-plus',
		customers: 'users',
		ad_spend: 'megaphone',
	};

	/**
	 * Fills in placeholders such as %s or %1$s, using the shared helper
	 * in kdna-ei-format.js.
	 *
	 * @param {string} text Text containing placeholders.
	 * @return {string}
	 */
	function sprintf( text ) {
		return window.KDNAEI.sprintf.apply( null, arguments );
	}

	/**
	 * Turns a Y-m-d string into a local date.
	 *
	 * @param {string} value Date.
	 * @return {Date}
	 */
	function toDate( value ) {
		var parts = String( value ).split( '-' );
		return new Date( Number( parts[ 0 ] ), Number( parts[ 1 ] ) - 1, Number( parts[ 2 ] ) );
	}

	/**
	 * Formats a date with the visitor's locale.
	 *
	 * @param {string} value   Y-m-d.
	 * @param {Object} options Intl date options.
	 * @return {string}
	 */
	function dateText( value, options ) {
		return toDate( value ).toLocaleDateString( config.locale || undefined, options );
	}

	document.addEventListener( 'alpine:init', function () {
		window.Alpine.data( 'kdnaEiOverview', function () {
			// Chart.js objects live outside Alpine's reactive data: wrapping them
			// in Alpine's proxies makes Chart.js loop forever.
			var chartsHeld = { performance: null, donut: null };

			return {
				t: t,
				loaded: false,
				loading: false,
				loadError: '',
				requestId: 0,

				kpis: [],
				kpiFifth: ( config.preferences && config.preferences.kpi_fifth ) || 'average_order_value',
				kpiMenuOpen: false,
				displayValues: {},
				estimates: {},

				series: null,
				seriesKey: 'net_revenue',
				granularity: 'day',
				compareRange: null,

				inventory: null,

				heroType: ( config.hero && config.hero.type ) || 'top_products',
				heroSort: 'profit',
				heroLoaded: false,
				heroMenuOpen: false,
				heroProducts: [],
				waterfall: [],
				goal: { target: 0 },

				dismissed: {},

				/**
				 * Sets up listeners: reload when the date range changes, when the
				 * hero card type changes (here or in Settings), or when
				 * background processing finishes.
				 */
				init: function () {
					var self = this;
					window.addEventListener( 'kdna:ei-range-change', function () {
						if ( self.loaded ) {
							self.load();
						}
					} );
					window.addEventListener( 'kdna:ei-hero-change', function ( event ) {
						self.heroType = event.detail.type;
						if ( self.loaded ) {
							self.loadHero();
						}
					} );
					window.addEventListener( 'kdna:ei-data-loaded', function () {
						if ( self.loaded ) {
							self.load( true );
						}
					} );
				},

				/**
				 * Loads the screen the first time it is opened.
				 */
				ensureLoaded: function () {
					if ( ! this.loaded && ! this.loading ) {
						this.load();
					}
				},

				/*
				 * -------------------------------------------------------------
				 * Loading
				 * -------------------------------------------------------------
				 */

				/**
				 * Fetches every panel for the current range at the same time.
				 * Older responses are ignored if the range changed meanwhile.
				 *
				 * @param {boolean} fresh Skip the server cache.
				 * @return {Promise}
				 */
				load: function ( fresh ) {
					var self = this;
					var id = ++this.requestId;
					var query = Object.assign( {}, this.rangeQuery, fresh ? { fresh: 1 } : {} );

					this.loading = true;
					this.loadError = '';
					this.heroLoaded = false;

					var requests = [
						api( 'summary', { query: Object.assign( {}, query, { metrics: [ 'net_revenue', 'net_profit', 'orders', 'net_margin', this.kpiFifth ].join( ',' ) } ) } ),
						api( 'timeseries', { query: Object.assign( {}, query, { metrics: 'net_revenue,net_profit,orders' } ) } ),
						api( 'inventory', { query: query } ),
						this.refreshStatus(),
					];

					return Promise.all( requests ).then( function ( results ) {
						if ( id !== self.requestId ) {
							return;
						}
						var summary = results[ 0 ];
						var timeseries = results[ 1 ];

						self.estimates = summary.meta.estimates || {};
						self.compareRange = summary.meta.compare;
						self.series = timeseries.data;
						self.granularity = timeseries.data.granularity;
						self.inventory = results[ 2 ].data;
						self.setKpis( summary.data.metrics );
						self.loaded = true;

						self.$nextTick( function () {
							self.drawPerformance();
							self.drawDonut();
						} );

						return self.loadHero( query );
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
				 * Loads the hero card's data for its current type.
				 *
				 * @param {Object} query Range query.
				 * @return {Promise}
				 */
				loadHero: function ( query ) {
					var self = this;
					var type = this.heroType;
					var request;

					query = query || this.rangeQuery;
					this.heroLoaded = false;

					if ( type === 'profit_breakdown' ) {
						request = api( 'profit', { query: query } ).then( function ( response ) {
							self.waterfall = response.data.waterfall;
						} );
					} else if ( type === 'goals' ) {
						var goal = config.hero || {};
						var metricKey = { revenue: 'net_revenue', profit: 'net_profit', orders: 'orders' }[ goal.goal_metric ] || 'net_revenue';
						request = api( 'summary', { query: { preset: 'this_month', compare: 'none', metrics: metricKey } } ).then( function ( response ) {
							self.buildGoal( response.data.metrics[ 0 ], goal );
						} );
					} else {
						request = api( 'products', { query: Object.assign( {}, query, { per_page: 5, orderby: this.heroSort } ) } ).then( function ( response ) {
							self.heroProducts = response.data.rows;
						} );
					}

					return request.then( function () {
						if ( type === self.heroType ) {
							self.heroLoaded = true;
						}
					} );
				},

				/*
				 * -------------------------------------------------------------
				 * Empty state
				 * -------------------------------------------------------------
				 */

				/**
				 * True for a store with no orders processed yet.
				 *
				 * @return {boolean}
				 */
				get isEmpty() {
					return this.loaded && ! ( this.status.orders_processed > 0 );
				},

				/*
				 * -------------------------------------------------------------
				 * KPI strip
				 * -------------------------------------------------------------
				 */

				/**
				 * Stores the KPI metrics and counts the numbers up from their
				 * previous values (or zero on first load).
				 *
				 * @param {Object[]} metrics Metrics from /summary.
				 */
				setKpis: function ( metrics ) {
					var self = this;
					var from = Object.assign( {}, this.displayValues );
					this.kpis = metrics;

					if ( reduceMotion ) {
						metrics.forEach( function ( metric ) {
							self.displayValues[ metric.key ] = metric.value;
						} );
						return;
					}

					var start = null;
					var duration = 700;
					function step( time ) {
						if ( start === null ) {
							start = time;
						}
						var p = Math.min( 1, ( time - start ) / duration );
						var eased = 1 - Math.pow( 1 - p, 3 );
						var values = {};
						metrics.forEach( function ( metric ) {
							var target = metric.value === null ? null : Number( metric.value );
							var begin = from[ metric.key ] === undefined || from[ metric.key ] === null ? 0 : Number( from[ metric.key ] );
							values[ metric.key ] = target === null ? null : begin + ( target - begin ) * eased;
						} );
						self.displayValues = values;
						if ( p < 1 ) {
							window.requestAnimationFrame( step );
						}
					}
					window.requestAnimationFrame( step );
				},

				/**
				 * The number shown for a KPI, mid count-up or final.
				 *
				 * @param {Object} kpi Metric.
				 * @return {string}
				 */
				display: function ( kpi ) {
					var value = this.displayValues[ kpi.key ];
					if ( value === undefined ) {
						value = kpi.value;
					}
					if ( value !== null && kpi.format === 'number' && ! kpi.decimals ) {
						value = Math.round( value );
					}
					return format.metric( value, kpi.format, kpi.decimals );
				},

				/**
				 * Icon for a KPI.
				 *
				 * @param {string} key   Metric key.
				 * @param {number} index Position in the strip.
				 * @return {string}
				 */
				kpiIcon: function ( key, index ) {
					return KPI_ICONS[ key ] || ( index === 4 ? 'chart' : 'trending-up' );
				},

				/**
				 * The change text, for example "12%" or "3.0 pts".
				 *
				 * @param {Object} kpi Metric.
				 * @return {string}
				 */
				changeText: function ( kpi ) {
					if ( kpi.change === null ) {
						return kpi.previous === 0 && kpi.value ? t.new : '';
					}
					var amount = format.number( Math.abs( kpi.change ), 1 );
					return kpi.change_type === 'points' ? sprintf( t.points, amount ) : amount + '%';
				},

				/**
				 * Full sentence comparing with the previous period, for the
				 * tooltip and screen readers.
				 *
				 * @param {Object} kpi Metric.
				 * @return {string}
				 */
				previousText: function ( kpi ) {
					if ( kpi.previous === null || kpi.previous === undefined ) {
						return '';
					}
					return sprintf( t.previousWas, this.comparisonLabel, format.metric( kpi.previous, kpi.format, kpi.decimals ) );
				},

				/**
				 * Metrics offered for the fifth KPI.
				 *
				 * @return {Object[]}
				 */
				get fifthOptions() {
					return ( config.kpiOptions || [] ).filter( function ( option ) {
						return [ 'net_revenue', 'net_profit', 'orders', 'net_margin' ].indexOf( option.key ) === -1;
					} );
				},

				/**
				 * Swaps the fifth KPI for another metric and remembers the choice.
				 *
				 * @param {string} key Metric key.
				 */
				chooseFifth: function ( key ) {
					this.kpiMenuOpen = false;
					if ( key === this.kpiFifth ) {
						return;
					}
					this.kpiFifth = key;
					api( 'preferences', { method: 'POST', body: { kpi_fifth: key } } ).catch( function () {} );
					this.load();
				},

				/*
				 * -------------------------------------------------------------
				 * Performance chart
				 * -------------------------------------------------------------
				 */

				/**
				 * The series buttons above the chart.
				 *
				 * @return {Object[]}
				 */
				get seriesOptions() {
					return [
						{ key: 'net_revenue', label: t.revenue },
						{ key: 'net_profit', label: t.profit },
						{ key: 'orders', label: t.orders },
					];
				},

				/**
				 * Legend label for the current series.
				 *
				 * @return {string}
				 */
				get seriesLabel() {
					var option = this.seriesOptions.filter( function ( o ) {
						return o.key === this.seriesKey;
					}, this )[ 0 ];
					return option ? option.label : '';
				},

				/**
				 * Whether there is a comparison period to show.
				 *
				 * @return {boolean}
				 */
				get hasComparison() {
					return !! ( this.series && this.series.previous_buckets );
				},

				/**
				 * Legend label for the comparison line.
				 *
				 * @return {string}
				 */
				get comparisonLabel() {
					return this.comparison === 'previous_year' ? t.lastYear : t.previousPeriod;
				},

				/**
				 * Values of the chosen series for this period.
				 *
				 * @return {Array}
				 */
				get currentSeries() {
					return this.series ? this.series.series[ this.seriesKey ].current : [];
				},

				/**
				 * Values of the chosen series for the comparison period.
				 *
				 * @return {Array|null}
				 */
				get previousSeries() {
					return this.series && this.series.series[ this.seriesKey ].previous;
				},

				/**
				 * Formats a value of the chosen series.
				 *
				 * @param {number} value Value.
				 * @return {string}
				 */
				formatValue: function ( value ) {
					return format.metric( value, this.seriesKey === 'orders' ? 'number' : 'currency' );
				},

				/**
				 * Short x axis label for a bucket, for example "14" for a day in a
				 * one-month range, "9 Oct", or "Oct".
				 *
				 * @param {number} index Bucket position.
				 * @return {string}
				 */
				axisLabel: function ( index ) {
					var bucket = this.series.buckets[ index ];
					if ( ! bucket ) {
						return '';
					}
					if ( this.granularity === 'month' ) {
						return dateText( bucket.start, { month: 'short', year: this.series.buckets.length > 12 ? '2-digit' : undefined } );
					}
					var first = this.series.buckets[ 0 ].start;
					var last = this.series.buckets[ this.series.buckets.length - 1 ].start;
					if ( this.granularity === 'day' && first.slice( 0, 7 ) === last.slice( 0, 7 ) ) {
						return String( toDate( bucket.start ).getDate() );
					}
					return dateText( bucket.start, { day: 'numeric', month: 'short' } );
				},

				/**
				 * Tooltip title for a bucket, for example "Thu 9 Oct 2026",
				 * "Week of 5 Oct" or "October 2026".
				 *
				 * @param {number} index Bucket position.
				 * @return {string}
				 */
				bucketTitle: function ( index ) {
					var bucket = this.series && this.series.buckets[ index ];
					if ( ! bucket ) {
						return '';
					}
					if ( this.granularity === 'month' ) {
						return dateText( bucket.start, { month: 'long', year: 'numeric' } );
					}
					if ( this.granularity === 'week' ) {
						return sprintf( t.weekOf, dateText( bucket.start, { day: 'numeric', month: 'short', year: 'numeric' } ) );
					}
					return dateText( bucket.start, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' } );
				},

				/**
				 * Short text describing the chart, for screen readers.
				 *
				 * @return {string}
				 */
				get chartSummary() {
					if ( ! this.series ) {
						return t.performance;
					}
					var total = this.currentSeries.reduce( function ( sum, v ) {
						return sum + ( v || 0 );
					}, 0 );
					return sprintf( t.chartSummary, this.seriesLabel, this.formatValue( total ) );
				},

				/**
				 * Chart.js settings for the current series.
				 *
				 * @return {Object}
				 */
				chartOptions: function () {
					var self = this;
					var series = [ { label: t.thisPeriod, data: this.currentSeries.slice() } ];
					if ( this.hasComparison && this.previousSeries ) {
						// Line the comparison up bucket by bucket with this period.
						series.push( { label: this.comparisonLabel, data: this.currentSeries.map( function ( v, i ) {
							return self.previousSeries[ i ] === undefined ? null : self.previousSeries[ i ];
						} ) } );
					}

					return {
						labels: this.series.buckets.map( function ( b ) {
							return b.key;
						} ),
						series: series,
						maxXTicks: this.granularity === 'day' && this.series.buckets.length > 20 ? 5 : 7,
						xLabel: function ( i ) {
							return self.axisLabel( i );
						},
						yLabel: function ( value ) {
							return format.compact( value );
						},
						title: function ( i ) {
							return self.bucketTitle( i );
						},
						seriesLabel: function ( datasetIndex, i ) {
							if ( datasetIndex === 0 ) {
								return t.thisPeriod;
							}
							var bucket = self.series.previous_buckets && self.series.previous_buckets[ i ];
							return bucket ? dateText( bucket.start, { day: 'numeric', month: 'short', year: 'numeric' } ) : self.comparisonLabel;
						},
						value: function ( v ) {
							return self.formatValue( v );
						},
						footer: function ( i ) {
							var now = self.currentSeries[ i ];
							var before = self.previousSeries ? self.previousSeries[ i ] : null;
							if ( before === null || before === undefined || ! before || now === null ) {
								return null;
							}
							var change = ( now - before ) / Math.abs( before ) * 100;
							var good = self.seriesKey === 'orders' || self.seriesKey === 'net_revenue' || self.seriesKey === 'net_profit' ? change >= 0 : change <= 0;
							return { text: ( change >= 0 ? '+' : '−' ) + format.number( Math.abs( change ), 1 ) + '%', className: good ? 'is-good' : 'is-bad' };
						},
					};
				},

				/**
				 * Draws the performance chart, or updates it in place.
				 */
				drawPerformance: function () {
					if ( ! charts || ! charts.available || ! this.series || ! this.$refs.performance ) {
						return;
					}
					if ( chartsHeld.performance ) {
						charts.updateLineChart( chartsHeld.performance, this.chartOptions() );
					} else {
						chartsHeld.performance = charts.lineChart( this.$refs.performance, this.chartOptions() );
					}
				},

				/**
				 * Switches the chart between revenue, profit and orders.
				 *
				 * @param {string} key Series key.
				 */
				chooseSeries: function ( key ) {
					this.seriesKey = key;
					this.drawPerformance();
				},

				/*
				 * -------------------------------------------------------------
				 * Inventory donut
				 * -------------------------------------------------------------
				 */

				/**
				 * Rows of the legend table: in stock, low stock, out of stock.
				 *
				 * @return {Object[]}
				 */
				get inventoryRows() {
					var status = ( this.inventory && this.inventory.status ) || { in_stock: 0, low_stock: 0, out_of_stock: 0 };
					var total = status.in_stock + status.low_stock + status.out_of_stock;
					var pct = function ( value ) {
						return total ? Math.round( value / total * 100 ) : 0;
					};
					return [
						{ key: 'in_stock', label: t.inStockLegend, token: 'accent', value: status.in_stock, percent: pct( status.in_stock ) },
						{ key: 'low_stock', label: t.lowStock, token: 'positive', value: status.low_stock, percent: pct( status.low_stock ) },
						{ key: 'out_of_stock', label: t.outOfStock, token: 'accent-2', value: status.out_of_stock, percent: pct( status.out_of_stock ) },
					];
				},

				/**
				 * The number in the middle of the donut.
				 *
				 * @return {number}
				 */
				get inventoryCentre() {
					return this.inventory && this.inventory.status ? this.inventory.status.in_stock : 0;
				},

				/**
				 * Short text describing the donut, for screen readers.
				 *
				 * @return {string}
				 */
				get inventorySummary() {
					return this.inventoryRows.map( function ( row ) {
						return row.label + ' ' + row.value;
					} ).join( ', ' );
				},

				/**
				 * Draws the donut, or updates it in place.
				 */
				drawDonut: function () {
					if ( ! charts || ! charts.available || ! this.$refs.donut ) {
						return;
					}
					var rows = this.inventoryRows;
					var values = rows.map( function ( row ) {
						return row.value;
					} );
					if ( ! values.some( function ( v ) {
						return v > 0;
					} ) ) {
						values = [ 1, 0, 0 ];
					}
					if ( chartsHeld.donut ) {
						chartsHeld.donut.data.datasets[ 0 ].data = values;
						chartsHeld.donut.update();
						return;
					}
					chartsHeld.donut = charts.donutChart( this.$refs.donut, {
						labels: rows.map( function ( row ) {
							return row.label;
						} ),
						values: values,
						colours: [ 'accent', 'positive', 'accent2' ],
						cutout: '70%',
					} );
				},

				/*
				 * -------------------------------------------------------------
				 * Hero card
				 * -------------------------------------------------------------
				 */

				/**
				 * Hero card heading.
				 *
				 * @return {string}
				 */
				get heroTitle() {
					return ( t.heroTypes && t.heroTypes[ this.heroType ] ) || '';
				},

				/**
				 * Switches the hero card and saves the choice to Settings, so it
				 * sticks for everyone.
				 *
				 * @param {string} type top_products, profit_breakdown or goals.
				 */
				chooseHero: function ( type ) {
					this.heroMenuOpen = false;
					if ( type === this.heroType ) {
						return;
					}
					config.hero = Object.assign( {}, config.hero || {}, { type: type } );
					api( 'settings', { method: 'POST', body: { settings: { hero: { type: type } } } } ).catch( function () {} );

					// Tell this card and the Settings form, so they stay in step.
					window.dispatchEvent( new window.CustomEvent( 'kdna:ei-hero-change', { detail: config.hero } ) );
				},

				/**
				 * Opens Settings on the Hero card and Goals tab.
				 */
				openGoalSettings: function () {
					window.setTimeout( function () {
						window.dispatchEvent( new window.CustomEvent( 'kdna:ei-settings-tab', { detail: 'hero' } ) );
					}, 50 );
				},

				/**
				 * Switches Top Products between ranking by profit and by revenue.
				 *
				 * @param {string} sort profit or revenue.
				 */
				chooseHeroSort: function ( sort ) {
					if ( sort !== this.heroSort ) {
						this.heroSort = sort;
						this.loadHero();
					}
				},

				/**
				 * Profit Breakdown steps, from net revenue down to net profit,
				 * each with its bar position as a share of net revenue.
				 *
				 * @return {Object[]}
				 */
				get waterfallSteps() {
					var keys = [ 'net_revenue', 'cogs', 'payment_fees', 'shipping_costs', 'extra_costs', 'ad_spend', 'overheads', 'net_profit' ];
					var lines = {};
					this.waterfall.forEach( function ( line ) {
						lines[ line.key ] = line;
					} );
					var top = Math.max( 1, Math.abs( lines.net_revenue ? lines.net_revenue.amount : 0 ) );
					var running = top;

					return keys.filter( function ( key ) {
						return lines[ key ];
					} ).map( function ( key ) {
						var line = lines[ key ];
						var amount = line.amount;
						var step = { key: key, label: line.label, amount: amount, type: line.type };

						if ( line.type === 'total' ) {
							step.left = amount >= 0 ? 0 : 0;
							step.width = Math.min( 100, Math.abs( amount ) / top * 100 );
							running = Math.max( 0, amount );
						} else {
							var size = Math.abs( amount ) / top * 100;
							running -= Math.abs( amount );
							step.left = Math.max( 0, running / top * 100 );
							step.width = Math.min( 100 - step.left, size );
						}
						step.width = Math.max( step.width, amount ? 0.8 : 0 );
						return step;
					} );
				},

				/**
				 * Works out the Goals Tracker: progress this month towards the
				 * monthly target, whether it is on track and what is needed per day.
				 *
				 * @param {Object} metric This month's value from /summary.
				 * @param {Object} goal   Goal settings: goal_metric and goal_targets.
				 */
				buildGoal: function ( metric, goal ) {
					var target = Number( ( goal.goal_targets || {} )[ goal.goal_metric ] || 0 );
					var value = Number( metric.value || 0 );
					var now = new Date();
					var daysInMonth = new Date( now.getFullYear(), now.getMonth() + 1, 0 ).getDate();
					var day = now.getDate();
					var daysLeft = daysInMonth - day + 1;
					var percentDone = target ? Math.round( value / target * 100 ) : 0;
					var expected = target * ( day / daysInMonth );
					var circumference = 2 * Math.PI * 52;
					var kind = goal.goal_metric === 'orders' ? 'number' : 'currency';

					this.goal = {
						target: target,
						percent: percentDone,
						onTrack: value >= expected,
						offset: circumference * ( 1 - Math.min( 1, percentDone / 100 ) ),
						label: ( t.goalMetrics && t.goalMetrics[ goal.goal_metric ] ) || '',
						valueText: format.metric( value, kind ),
						targetText: format.metric( target, kind ),
						daysLeft: daysLeft,
						neededText: format.metric( Math.max( 0, ( target - value ) / Math.max( 1, daysLeft ) ), kind ),
					};
				},

				/*
				 * -------------------------------------------------------------
				 * Alerts strip
				 * -------------------------------------------------------------
				 */

				/**
				 * Alerts for things that need attention (section 8.3), minus any
				 * dismissed during this visit.
				 *
				 * @return {Object[]}
				 */
				get visibleAlerts() {
					var self = this;
					var list = [];
					var e = this.estimates || {};
					var s = this.status || {};
					var stock = ( this.inventory && this.inventory.status ) || {};

					if ( s.job && s.job.status === 'running' ) {
						list.push( { key: 'processing', tone: 'info', icon: 'info', text: sprintf( t.alertProcessing, s.job.percent ) } );
					}
					if ( s.missing_costs > 0 ) {
						list.push( {
							key: 'missing_costs',
							tone: 'warning',
							icon: 'tag',
							text: sprintf( s.missing_costs === 1 ? t.alertMissingOne : t.alertMissing, format.number( s.missing_costs, 0 ) ),
							action: { label: t.addCosts, run: function () {
								self.goToMissingCosts();
							} },
						} );
					}
					if ( stock.low_stock > 0 || stock.out_of_stock > 0 ) {
						list.push( {
							key: 'stock',
							tone: stock.out_of_stock > 0 ? 'negative' : 'warning',
							icon: 'box',
							text: sprintf( t.alertStock, format.number( stock.low_stock || 0, 0 ), format.number( stock.out_of_stock || 0, 0 ) ),
							action: { label: t.viewStock, run: function () {
								window.location.hash = '#/inventory';
							} },
						} );
					}
					if ( e.loss_orders > 0 ) {
						list.push( {
							key: 'loss_orders',
							tone: 'negative',
							icon: 'alert',
							text: sprintf( e.loss_orders === 1 ? t.alertLossOne : t.alertLoss, format.number( e.loss_orders, 0 ) ),
							action: { label: t.viewProfit, run: function () {
								window.location.hash = '#/profit';
							} },
						} );
					}
					if ( e.currency_flag_orders > 0 ) {
						list.push( { key: 'currency', tone: 'warning', icon: 'alert', text: sprintf( t.alertCurrency, format.number( e.currency_flag_orders, 0 ) ) } );
					}
					if ( s.sync_errors > 0 ) {
						list.push( {
							key: 'sync_errors',
							tone: 'negative',
							icon: 'alert',
							text: sprintf( t.alertErrors, format.number( s.sync_errors, 0 ) ),
							action: { label: t.viewActivity, run: function () {
								window.location.hash = '#/settings';
							} },
						} );
					}

					return list.filter( function ( alert ) {
						return ! self.dismissed[ alert.key ];
					} );
				},

				/**
				 * Hides an alert until the page is next loaded.
				 *
				 * @param {string} key Alert key.
				 */
				dismiss: function ( key ) {
					this.dismissed = Object.assign( {}, this.dismissed, { [ key ]: true } );
				},

				/**
				 * Opens the Costs screen filtered to products missing a cost.
				 */
				goToMissingCosts: function () {
					window.location.hash = '#/costs';
					window.setTimeout( function () {
						window.dispatchEvent( new window.CustomEvent( 'kdna:ei-costs-show-missing' ) );
					}, 50 );
				},

				/*
				 * -------------------------------------------------------------
				 * Formatting
				 * -------------------------------------------------------------
				 */

				money: function ( value, decimals ) {
					return format.money( value, decimals );
				},
				percent: format.percent,
				formatNumber: function ( value ) {
					return format.number( value || 0, 0 );
				},
				sprintf: sprintf,
			};
		} );
	} );
}( window, document ) );
