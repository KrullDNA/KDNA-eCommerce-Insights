/**
 * KDNA eCommerce Insights: Profit & Loss screen.
 *
 * Loads the P&L totals, profit waterfall, margin trend, cost breakdown and
 * monthly statement for the chosen date range. Reloads when the range
 * changes (straight away if the screen is open, otherwise next time it is).
 */
( function ( window, document ) {
	'use strict';

	var config = window.kdnaEiApp || {};
	var t = ( config.i18n && config.i18n.profit ) || {};
	var format = window.KDNAEI.format;
	var api = window.KDNAEI.api;
	var charts = window.KDNAEI.charts;
	var sprintf = window.KDNAEI.sprintf;

	/**
	 * Colour for each cost in the donut, as a chart token and a CSS variable.
	 */
	var COST_COLOURS = {
		cogs: { chart: 'accent', css: 'accent' },
		payment_fees: { chart: 'accent2', css: 'accent-2' },
		shipping_costs: { chart: 'positive', css: 'positive' },
		extra_costs: { chart: 'warning', css: 'warning' },
		ad_spend: { chart: 'negative', css: 'negative' },
		overheads: { chart: 'muted', css: 'text-muted' },
	};

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
	 * Formats a Y-m-d date with the visitor's locale.
	 *
	 * @param {string} value   Date.
	 * @param {Object} options Intl date options.
	 * @return {string}
	 */
	function dateText( value, options ) {
		return toDate( value ).toLocaleDateString( config.locale || undefined, options );
	}

	/**
	 * Splits a long label over two lines so waterfall bars stay readable.
	 *
	 * @param {string} label Label.
	 * @return {string|string[]}
	 */
	function twoLines( label ) {
		var words = String( label ).split( ' ' );
		if ( words.length < 2 || label.length < 11 ) {
			return label;
		}
		var half = Math.ceil( words.length / 2 );
		return [ words.slice( 0, half ).join( ' ' ), words.slice( half ).join( ' ' ) ];
	}

	document.addEventListener( 'alpine:init', function () {
		window.Alpine.data( 'kdnaEiProfit', function () {
			// Chart.js objects stay outside Alpine's reactive data.
			var held = { waterfall: null, margin: null, costs: null };

			return {
				t: t,
				loaded: false,
				loading: false,
				stale: false,
				loadError: '',
				exporting: false,
				requestId: 0,

				kpis: [],
				waterfall: [],
				previousWaterfall: null,
				months: [],
				costBreakdown: [],
				netMargin: null,
				estimates: {},
				trend: null,

				/**
				 * Reloads when the date range changes: now if this screen is
				 * open, otherwise the next time it is opened.
				 */
				init: function () {
					var self = this;
					var refresh = function () {
						if ( ! self.loaded ) {
							return;
						}
						if ( self.route === 'profit' ) {
							self.load();
						} else {
							self.stale = true;
						}
					};
					window.addEventListener( 'kdna:ei-range-change', refresh );
					window.addEventListener( 'kdna:ei-data-loaded', refresh );
				},

				/**
				 * Loads the screen when it is opened for the first time, or
				 * again if the range changed while it was closed.
				 */
				ensureLoaded: function () {
					if ( ( ! this.loaded || this.stale ) && ! this.loading ) {
						this.load();
					}
				},

				/**
				 * Fetches the totals, P&L and margin trend at the same time.
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
						api( 'summary', { query: Object.assign( {}, query, { metrics: 'net_revenue,gross_profit,contribution_profit,net_profit,net_margin' } ) } ),
						api( 'profit', { query: query } ),
						api( 'timeseries', { query: Object.assign( {}, query, { metrics: 'net_margin,gross_margin' } ) } ),
					] ).then( function ( results ) {
						if ( id !== self.requestId ) {
							return;
						}
						var profit = results[ 1 ].data;

						self.kpis = results[ 0 ].data.metrics;
						self.waterfall = profit.waterfall;
						self.previousWaterfall = profit.previous_waterfall;
						self.months = profit.months;
						self.costBreakdown = profit.cost_breakdown;
						self.netMargin = profit.margins.net;
						self.estimates = results[ 1 ].meta.estimates || {};
						self.trend = results[ 2 ].data;
						self.loaded = true;

						self.$nextTick( function () {
							self.drawWaterfall();
							self.drawMargin();
							self.drawCosts();
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
				 * KPI strip
				 * -------------------------------------------------------------
				 */

				/**
				 * Icon for a KPI.
				 *
				 * @param {string} key Metric key.
				 * @return {string}
				 */
				kpiIcon: function ( key ) {
					return window.KDNAEI.kpi.icon( key );
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

				/*
				 * -------------------------------------------------------------
				 * Estimated lines
				 * -------------------------------------------------------------
				 */

				/**
				 * Explains why a line is estimated or incomplete, or returns an
				 * empty string when the line is exact.
				 *
				 * @param {string} key Line key.
				 * @return {string}
				 */
				lineFlag: function ( key ) {
					var e = this.estimates || {};
					var orders = format.number( e.orders || 0, 0 );
					if ( key === 'payment_fees' && e.fee_orders > 0 ) {
						return sprintf( t.feesEstimated, format.number( e.fee_orders, 0 ), orders );
					}
					if ( key === 'shipping_costs' && e.shipping_orders > 0 ) {
						return sprintf( t.shippingEstimated, format.number( e.shipping_orders, 0 ), orders );
					}
					if ( key === 'cogs' && e.missing_cost_orders > 0 ) {
						return sprintf( t.cogsIncomplete, format.number( e.missing_cost_orders, 0 ), orders );
					}
					return '';
				},

				/*
				 * -------------------------------------------------------------
				 * Waterfall
				 * -------------------------------------------------------------
				 */

				/**
				 * Waterfall steps with where each bar starts and ends: added
				 * lines step up, costs step down, subtotals stand on zero.
				 *
				 * @return {Object[]}
				 */
				get waterfallSteps() {
					var running = 0;
					return this.waterfall.map( function ( line ) {
						var step = { key: line.key, label: line.label, amount: line.amount, type: line.type };
						if ( line.type === 'total' ) {
							step.from = 0;
							step.to = line.amount;
						} else {
							step.from = running;
							step.to = running + line.amount;
						}
						running = step.to;
						return step;
					} );
				},

				/**
				 * Short description of the waterfall for screen readers.
				 *
				 * @return {string}
				 */
				get waterfallSummary() {
					var self = this;
					return this.waterfall.map( function ( line ) {
						return line.label + ' ' + self.money( line.amount, 0 );
					} ).join( ', ' );
				},

				/**
				 * Draws the waterfall, or updates it in place.
				 */
				drawWaterfall: function () {
					var self = this;
					if ( ! charts || ! this.$refs.waterfall ) {
						return;
					}
					var steps = this.waterfallSteps;
					var options = {
						steps: steps,
						beginAtZero: true,
						xLabel: function ( i ) {
							return twoLines( steps[ i ].label );
						},
						yLabel: function ( value ) {
							return format.compact( value );
						},
						title: function ( i ) {
							return steps[ i ].label;
						},
						seriesLabel: function () {
							return t.thisPeriod;
						},
						value: function ( v, i ) {
							return self.money( steps[ i ].amount, 2 );
						},
						footer: function ( i ) {
							var flag = self.lineFlag( steps[ i ].key );
							var before = self.previousWaterfall && self.previousWaterfall[ i ];
							var text = before ? sprintf( t.previousAmount, window.KDNAEI.kpi.comparisonLabel( self.comparison ), self.money( before.amount, 2 ) ) : '';
							if ( flag ) {
								text = text ? text + '. ' + t.includesEstimates : t.includesEstimates;
							}
							return text ? { text: text } : null;
						},
					};

					if ( held.waterfall ) {
						charts.updateWaterfallChart( held.waterfall, options );
					} else {
						held.waterfall = charts.waterfallChart( this.$refs.waterfall, options );
					}
				},

				/*
				 * -------------------------------------------------------------
				 * Margin trend
				 * -------------------------------------------------------------
				 */

				/**
				 * Short axis label for a bucket of the margin trend.
				 *
				 * @param {number} index Bucket position.
				 * @return {string}
				 */
				trendLabel: function ( index ) {
					var bucket = this.trend.buckets[ index ];
					if ( ! bucket ) {
						return '';
					}
					if ( this.trend.granularity === 'month' ) {
						return dateText( bucket.start, { month: 'short', year: '2-digit' } );
					}
					return dateText( bucket.start, { day: 'numeric', month: 'short' } );
				},

				/**
				 * Tooltip title for a bucket of the margin trend.
				 *
				 * @param {number} index Bucket position.
				 * @return {string}
				 */
				trendTitle: function ( index ) {
					var bucket = this.trend.buckets[ index ];
					if ( ! bucket ) {
						return '';
					}
					if ( this.trend.granularity === 'month' ) {
						return dateText( bucket.start, { month: 'long', year: 'numeric' } );
					}
					if ( this.trend.granularity === 'week' ) {
						return sprintf( config.i18n.overview.weekOf, dateText( bucket.start, { day: 'numeric', month: 'short', year: 'numeric' } ) );
					}
					return dateText( bucket.start, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' } );
				},

				/**
				 * Short description of the margin trend for screen readers.
				 *
				 * @return {string}
				 */
				get marginSummary() {
					return sprintf( t.marginSummary, this.netMargin === null ? '' : this.percent( this.netMargin ) );
				},

				/**
				 * Draws the margin trend, or updates it in place.
				 */
				drawMargin: function () {
					var self = this;
					if ( ! charts || ! this.trend || ! this.$refs.margin ) {
						return;
					}
					var options = {
						labels: this.trend.buckets.map( function ( b ) {
							return b.key;
						} ),
						series: [
							{ label: t.netMargin, data: this.trend.series.net_margin.current.slice(), colour: 'accent', fill: true },
							{ label: t.grossMargin, data: this.trend.series.gross_margin.current.slice(), colour: 'positive', fill: false },
						],
						maxXTicks: 6,
						xLabel: function ( i ) {
							return self.trendLabel( i );
						},
						yLabel: function ( value ) {
							return format.number( value, 0 ) + '%';
						},
						title: function ( i ) {
							return self.trendTitle( i );
						},
						value: function ( v ) {
							return v === null || v === undefined ? '–' : self.percent( v );
						},
					};

					if ( held.margin ) {
						charts.updateLineChart( held.margin, options );
					} else {
						held.margin = charts.lineChart( this.$refs.margin, options );
					}
				},

				/*
				 * -------------------------------------------------------------
				 * Cost breakdown
				 * -------------------------------------------------------------
				 */

				/**
				 * Rows of the cost breakdown legend, with their colours.
				 *
				 * @return {Object[]}
				 */
				get costRows() {
					return this.costBreakdown.map( function ( cost ) {
						return Object.assign( {}, cost, { token: ( COST_COLOURS[ cost.key ] || COST_COLOURS.overheads ).css } );
					} );
				},

				/**
				 * Every cost added together, for the middle of the donut.
				 *
				 * @return {number}
				 */
				get totalCosts() {
					return this.costBreakdown.reduce( function ( sum, cost ) {
						return sum + cost.amount;
					}, 0 );
				},

				/**
				 * Short description of the cost breakdown for screen readers.
				 *
				 * @return {string}
				 */
				get costSummary() {
					return this.costBreakdown.map( function ( cost ) {
						return cost.label + ' ' + cost.share + '%';
					} ).join( ', ' );
				},

				/**
				 * Draws the cost donut, or updates it in place.
				 */
				drawCosts: function () {
					if ( ! charts || ! this.$refs.costs ) {
						return;
					}
					var values = this.costBreakdown.map( function ( cost ) {
						return cost.amount;
					} );
					if ( ! values.some( function ( v ) {
						return v > 0;
					} ) ) {
						values = values.map( function ( v, i ) {
							return i === 0 ? 1 : 0;
						} );
					}

					if ( held.costs ) {
						held.costs.data.datasets[ 0 ].data = values;
						held.costs.update();
						return;
					}
					held.costs = charts.donutChart( this.$refs.costs, {
						labels: this.costBreakdown.map( function ( cost ) {
							return cost.label;
						} ),
						values: values,
						colours: this.costBreakdown.map( function ( cost ) {
							return ( COST_COLOURS[ cost.key ] || COST_COLOURS.overheads ).chart;
						} ),
						cutout: '72%',
					} );
				},

				/*
				 * -------------------------------------------------------------
				 * Statement
				 * -------------------------------------------------------------
				 */

				/**
				 * Lines of the statement, in the order from section 6.
				 *
				 * @return {Object[]}
				 */
				get statement() {
					return this.waterfall;
				},

				/**
				 * Column heading for a month, for example "Oct 2026". Part
				 * months (at the start or end of the range) say so.
				 *
				 * @param {Object} month Month with start and end.
				 * @return {string}
				 */
				monthLabel: function ( month ) {
					var label = dateText( month.start, { month: 'short', year: 'numeric' } );
					var start = toDate( month.start );
					var end = toDate( month.end );
					var lastDay = new Date( end.getFullYear(), end.getMonth() + 1, 0 ).getDate();
					if ( start.getDate() !== 1 || end.getDate() !== lastDay ) {
						label = sprintf( t.partMonth, label );
					}
					return label;
				},

				/**
				 * Downloads the statement as a CSV.
				 */
				exportCsv: function () {
					var self = this;
					this.exporting = true;
					window.KDNAEI.exportCsv( 'pnl', this.rangeQuery ).catch( function ( error ) {
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
				 * @param {string} kind     currency, number, percent and so on.
				 * @param {number} decimals Decimal places.
				 * @return {string}
				 */
				metric: function ( value, kind, decimals ) {
					return format.metric( value, kind, decimals );
				},
			};
		} );
	} );
}( window, document ) );
