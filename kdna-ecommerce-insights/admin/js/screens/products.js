/**
 * KDNA eCommerce Insights: Products screen.
 *
 * The product performance table (sorted, searched, filtered and paged on
 * the server, so it stays quick with thousands of products), the best and
 * worst performer cards, the category breakdown and the drill-down drawer
 * with each product's own sales and profit chart.
 */
( function ( window, document ) {
	'use strict';

	var config = window.kdnaEiApp || {};
	var t = ( config.i18n && config.i18n.products ) || {};
	var format = window.KDNAEI.format;
	var api = window.KDNAEI.api;
	var charts = window.KDNAEI.charts;
	var sprintf = window.KDNAEI.sprintf;

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
	 * Elements inside a container that can take keyboard focus.
	 *
	 * @param {Element} container Container.
	 * @return {Element[]}
	 */
	function focusable( container ) {
		return Array.prototype.filter.call(
			container.querySelectorAll( 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])' ),
			function ( el ) {
				return el.offsetParent !== null;
			}
		);
	}

	/**
	 * Works out the change between two values for the drawer's figures.
	 *
	 * @param {number|null} now            This period.
	 * @param {number|null} before         Comparison period.
	 * @param {boolean}     points         Show the change in percentage points.
	 * @param {boolean}     higherIsBetter Whether going up is good.
	 * @param {string}      title          Comparison sentence.
	 * @return {Object|null} text, direction, sentiment and title, or null.
	 */
	function change( now, before, points, higherIsBetter, title ) {
		if ( now === null || before === null || before === undefined ) {
			return null;
		}
		var amount;
		if ( points ) {
			amount = now - before;
		} else if ( ! before ) {
			return null;
		} else {
			amount = ( now - before ) / Math.abs( before ) * 100;
		}
		if ( Math.abs( amount ) < 0.05 ) {
			return { text: points ? sprintf( config.i18n.overview.points, '0.0' ) : '0%', direction: 'flat', sentiment: 'neutral', title: title };
		}
		var up = amount > 0;
		var text = format.number( Math.abs( amount ), 1 );
		return {
			text: points ? sprintf( config.i18n.overview.points, text ) : text + '%',
			direction: up ? 'up' : 'down',
			sentiment: up === higherIsBetter ? 'good' : 'bad',
			title: title,
		};
	}

	document.addEventListener( 'alpine:init', function () {
		window.Alpine.data( 'kdnaEiProducts', function () {
			// Chart.js objects and the element to return focus to stay outside
			// Alpine's reactive data.
			var held = { chart: null, trigger: null, root: null };

			return {
				t: t,
				loaded: false,
				loading: false,
				stale: false,
				loadError: '',
				exporting: false,
				requestId: 0,

				rows: [],
				total: 0,
				page: 1,
				pages: 1,
				perPage: 25,
				best: [],
				worst: [],
				categories: [],
				lossCount: 0,

				filters: { search: '', category: '', group: 'product', loss_only: false },
				orderby: 'profit',
				order: 'desc',

				drawerOpen: false,
				drawerItem: null,
				drawerVariationId: 0,
				drawerVariation: '',
				detail: null,
				detailLoading: false,
				detailError: '',
				detailId: 0,

				/**
				 * Reloads when the date range changes: now if this screen is
				 * open, otherwise the next time it is opened.
				 */
				init: function () {
					var self = this;

					// Remember the screen's root element. Methods can be called from
					// buttons that Alpine later removes (the variation list), and
					// $refs looked up from a removed button finds nothing.
					held.root = this.$el;

					var refresh = function () {
						if ( ! self.loaded ) {
							return;
						}
						if ( self.route === 'products' ) {
							self.load();
							if ( self.drawerOpen ) {
								self.loadDetail();
							}
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

				/*
				 * -------------------------------------------------------------
				 * Table
				 * -------------------------------------------------------------
				 */

				/**
				 * The range plus the table's filters, sorting and page, as sent
				 * to the server.
				 *
				 * @return {Object}
				 */
				tableQuery: function () {
					var query = Object.assign( {}, this.rangeQuery, {
						group: this.filters.group,
						orderby: this.orderby,
						order: this.order,
						page: this.page,
						per_page: this.perPage,
						loss_only: this.filters.loss_only ? 1 : 0,
					} );
					if ( this.filters.search.trim() ) {
						query.search = this.filters.search.trim();
					}
					if ( this.filters.category ) {
						query.category = this.filters.category;
					}
					return query;
				},

				/**
				 * Fetches the current page of the table, the performer cards
				 * and the category breakdown. Older responses are ignored.
				 *
				 * @param {boolean} fresh Skip the server cache.
				 * @return {Promise}
				 */
				load: function ( fresh ) {
					var self = this;
					var id = ++this.requestId;
					var query = Object.assign( this.tableQuery(), fresh ? { fresh: 1 } : {} );

					this.loading = true;
					this.stale = false;
					this.loadError = '';

					return api( 'products', { query: query } ).then( function ( response ) {
						if ( id !== self.requestId ) {
							return;
						}
						var data = response.data;
						self.rows = data.rows;
						self.total = data.total;
						self.page = data.page;
						self.pages = data.pages;
						self.best = data.best;
						self.worst = data.worst.filter( function ( item ) {
							// Do not list the same product as both best and worst.
							return ! data.best.some( function ( b ) {
								return self.rowKey( b ) === self.rowKey( item );
							} );
						} );
						self.categories = data.categories;
						self.lossCount = data.loss_count;
						self.loaded = true;
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
				 * A unique key for a product or variation row.
				 *
				 * @param {Object} row Row.
				 * @return {string}
				 */
				rowKey: function ( row ) {
					return row.product_id + ':' + row.variation_id;
				},

				/**
				 * Goes back to page one with the current filters.
				 */
				applyFilters: function () {
					this.page = 1;
					this.load();
				},

				/**
				 * Whether any filter is in use.
				 *
				 * @return {boolean}
				 */
				get filtered() {
					return !! ( this.filters.search.trim() || this.filters.category || this.filters.loss_only );
				},

				/**
				 * Clears the search and filters (keeps products or variations).
				 */
				resetFilters: function () {
					this.filters = Object.assign( {}, this.filters, { search: '', category: '', loss_only: false } );
					this.applyFilters();
				},

				/**
				 * Switches the table between products and variations.
				 *
				 * @param {string} group product or variation.
				 */
				setGroup: function ( group ) {
					if ( group !== this.filters.group ) {
						this.filters.group = group;
						this.applyFilters();
					}
				},

				/**
				 * Filters the table to one category, or clears it if chosen again.
				 *
				 * @param {number} id Category ID.
				 */
				filterCategory: function ( id ) {
					this.filters.category = this.filters.category === String( id ) ? '' : String( id );
					this.applyFilters();
				},

				/**
				 * Sorts by a column. Choosing the same column again flips the order.
				 *
				 * @param {string} key Column.
				 */
				sortBy: function ( key ) {
					if ( this.orderby === key ) {
						this.order = this.order === 'asc' ? 'desc' : 'asc';
					} else {
						this.orderby = key;
						this.order = key === 'name' ? 'asc' : 'desc';
					}
					this.applyFilters();
				},

				/**
				 * The aria-sort value for a column heading.
				 *
				 * @param {string} key Column.
				 * @return {string}
				 */
				ariaSort: function ( key ) {
					if ( this.orderby !== key ) {
						return 'none';
					}
					return this.order === 'asc' ? 'ascending' : 'descending';
				},

				/**
				 * Moves to another page.
				 *
				 * @param {number} page Page number.
				 */
				goToPage: function ( page ) {
					this.page = Math.max( 1, page );
					this.load();
				},

				/**
				 * "Showing 1 to 25 of 240" text under the table.
				 *
				 * @return {string}
				 */
				get rangeText() {
					var from = ( this.page - 1 ) * this.perPage + 1;
					var to = Math.min( this.total, this.page * this.perPage );
					return sprintf( t.showing, this.formatNumber( from ), this.formatNumber( to ), this.formatNumber( this.total ) );
				},

				/**
				 * Categories for the filter dropdown, by name.
				 *
				 * @return {Object[]}
				 */
				get categoryOptions() {
					return this.categories.filter( function ( c ) {
						return c.id;
					} ).slice().sort( function ( a, b ) {
						return a.name.localeCompare( b.name );
					} );
				},

				/**
				 * The biggest categories by revenue for the breakdown card, each
				 * with its bar length as a share of the biggest.
				 *
				 * @return {Object[]}
				 */
				get topCategories() {
					var list = this.categories.slice( 0, 6 );
					var top = list.length ? Math.max( 1, list[ 0 ].revenue ) : 1;
					return list.map( function ( c ) {
						return Object.assign( {}, c, { share: Math.max( 2, Math.min( 100, c.revenue / top * 100 ) ) } );
					} );
				},

				/**
				 * Downloads a table as CSV with the current filters and sorting.
				 *
				 * @param {string} table products or categories.
				 */
				exportCsv: function ( table ) {
					var self = this;
					var query = this.tableQuery();
					delete query.page;
					delete query.per_page;

					this.exporting = true;
					window.KDNAEI.exportCsv( table, query ).catch( function ( error ) {
						self.loadError = error.message;
					} ).finally( function () {
						self.exporting = false;
					} );
				},

				/**
				 * CSS class for a margin: red below zero, amber when thin.
				 *
				 * @param {number|null} value Margin.
				 * @return {string}
				 */
				marginClass: function ( value ) {
					if ( value === null ) {
						return 'kdna-ei-change--neutral';
					}
					if ( value < 0 ) {
						return 'kdna-ei-change--bad';
					}
					return value < 20 ? 'is-thin' : 'kdna-ei-change--good';
				},

				/*
				 * -------------------------------------------------------------
				 * Drawer
				 * -------------------------------------------------------------
				 */

				/**
				 * Opens the drawer for a product (or variation) and loads its
				 * figures. Focus moves into the drawer and returns afterwards.
				 *
				 * @param {Object}  item    Row.
				 * @param {Element} trigger The button that opened it.
				 */
				openDrawer: function ( item, trigger ) {
					var self = this;
					held.trigger = trigger || null;
					this.drawerItem = item;
					this.drawerVariationId = item.variation_id;
					this.drawerVariation = item.variation;
					this.drawerOpen = true;
					this.loadDetail();
					this.$nextTick( function () {
						var close = self.element( 'drawerClose' );
						if ( close ) {
							close.focus();
						}
					} );
				},

				/**
				 * Closes the drawer and puts focus back where it was.
				 */
				closeDrawer: function () {
					this.drawerOpen = false;
					if ( held.trigger && document.body.contains( held.trigger ) ) {
						held.trigger.focus();
					}
					held.trigger = null;
				},

				/**
				 * Keeps keyboard focus inside the drawer while it is open.
				 *
				 * @param {KeyboardEvent} event Tab key press.
				 */
				trapFocus: function ( event ) {
					var items = focusable( this.element( 'drawer' ) );
					if ( ! items.length ) {
						return;
					}
					var first = items[ 0 ];
					var last = items[ items.length - 1 ];
					if ( event.shiftKey && document.activeElement === first ) {
						event.preventDefault();
						last.focus();
					} else if ( ! event.shiftKey && document.activeElement === last ) {
						event.preventDefault();
						first.focus();
					}
				},

				/**
				 * Shows one variation in the drawer, or the whole product (0).
				 *
				 * @param {number} variationId Variation ID or 0.
				 * @param {string} name        Variation name.
				 */
				showVariation: function ( variationId, name ) {
					this.drawerVariationId = variationId;
					this.drawerVariation = variationId ? name : '';
					this.loadDetail();
				},

				/**
				 * Fetches the drawer's figures for the current range.
				 *
				 * @return {Promise}
				 */
				loadDetail: function () {
					var self = this;
					var id = ++this.detailId;

					this.detailLoading = true;
					this.detailError = '';

					return api( 'products/' + this.drawerItem.product_id, {
						query: Object.assign( {}, this.rangeQuery, { variation: this.drawerVariationId } ),
					} ).then( function ( response ) {
						if ( id !== self.detailId ) {
							return;
						}
						self.detail = response.data;
						self.$nextTick( function () {
							self.drawChart();
						} );
					} ).catch( function ( error ) {
						if ( id === self.detailId ) {
							self.detailError = error.message;
						}
					} ).finally( function () {
						if ( id === self.detailId ) {
							self.detailLoading = false;
						}
					} );
				},

				/**
				 * The figures at the top of the drawer, with change against the
				 * comparison period.
				 *
				 * @return {Object[]}
				 */
				get drawerStats() {
					var d = this.detail;
					var now = d ? d.totals : {};
					var before = d ? d.previous : null;
					var label = window.KDNAEI.kpi.comparisonLabel( this.comparison );
					var self = this;

					/**
					 * Builds one figure.
					 *
					 * @param {string}  key            Field.
					 * @param {string}  title          Label.
					 * @param {Function} show          Formatter.
					 * @param {boolean} points         Change in points.
					 * @param {boolean} higherIsBetter Whether up is good.
					 * @return {Object}
					 */
					var stat = function ( key, title, show, points, higherIsBetter ) {
						var value = d ? now[ key ] : null;
						var previous = before ? before[ key ] : null;
						return {
							key: key,
							label: title,
							value: value === null || value === undefined ? '–' : show( value ),
							negative: value < 0,
							change: d && before ? change( value, previous, points, higherIsBetter, sprintf( config.i18n.overview.previousWas, label, previous === null ? '–' : show( previous ) ) ) : null,
						};
					};

					return [
						stat( 'revenue', t.revenue, function ( v ) {
							return self.money( v, 0 );
						}, false, true ),
						stat( 'profit', t.profit, function ( v ) {
							return self.money( v, 0 );
						}, false, true ),
						stat( 'margin', t.margin, format.percent, true, true ),
						stat( 'units', t.units, function ( v ) {
							return self.formatNumber( v );
						}, false, true ),
						stat( 'orders', t.orders, function ( v ) {
							return self.formatNumber( v );
						}, false, true ),
						stat( 'refund_rate', t.refundRate, format.percent, true, false ),
					];
				},

				/**
				 * Stock text for the drawer.
				 *
				 * @return {string}
				 */
				get stockText() {
					var p = this.detail && this.detail.product;
					if ( ! p ) {
						return '';
					}
					var status = ( t.stockStatuses && t.stockStatuses[ p.stock_status ] ) || p.stock_status || '–';
					return p.stock === null ? status : sprintf( t.inStockCount, this.formatNumber( p.stock ) );
				},

				/**
				 * Short description of the drawer chart for screen readers.
				 *
				 * @return {string}
				 */
				get drawerChartSummary() {
					if ( ! this.detail ) {
						return t.salesAndProfit;
					}
					return sprintf( t.chartSummary, this.money( this.detail.totals.revenue, 0 ), this.money( this.detail.totals.profit, 0 ) );
				},

				/**
				 * Short axis label for a bucket of the drawer chart.
				 *
				 * @param {number} index Bucket position.
				 * @return {string}
				 */
				axisLabel: function ( index ) {
					var bucket = this.detail.buckets[ index ];
					if ( ! bucket ) {
						return '';
					}
					return this.detail.granularity === 'month' ? dateText( bucket.start, { month: 'short', year: '2-digit' } ) : dateText( bucket.start, { day: 'numeric', month: 'short' } );
				},

				/**
				 * Tooltip title for a bucket of the drawer chart.
				 *
				 * @param {number} index Bucket position.
				 * @return {string}
				 */
				bucketTitle: function ( index ) {
					var bucket = this.detail.buckets[ index ];
					if ( ! bucket ) {
						return '';
					}
					if ( this.detail.granularity === 'month' ) {
						return dateText( bucket.start, { month: 'long', year: 'numeric' } );
					}
					if ( this.detail.granularity === 'week' ) {
						return sprintf( config.i18n.overview.weekOf, dateText( bucket.start, { day: 'numeric', month: 'short', year: 'numeric' } ) );
					}
					return dateText( bucket.start, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' } );
				},

				/**
				 * Finds an element of this screen by its x-ref name, without
				 * relying on $refs (see init).
				 *
				 * @param {string} name x-ref name.
				 * @return {Element|null}
				 */
				element: function ( name ) {
					return held.root ? held.root.querySelector( '[x-ref="' + name + '"]' ) : null;
				},

				/**
				 * Draws the drawer's revenue and profit chart, or updates it.
				 */
				drawChart: function () {
					var self = this;
					var canvas = this.element( 'drawerChart' );
					if ( ! charts || ! this.detail || ! canvas ) {
						return;
					}
					var units = this.detail.series.units;
					var options = {
						labels: this.detail.buckets.map( function ( b ) {
							return b.key;
						} ),
						series: [
							{ label: t.revenue, data: this.detail.series.revenue.slice(), colour: 'accent', fill: true },
							{ label: t.profit, data: this.detail.series.profit.slice(), colour: 'positive', fill: false },
						],
						maxXTicks: 5,
						xLabel: function ( i ) {
							return self.axisLabel( i );
						},
						yLabel: function ( value ) {
							return format.compact( value );
						},
						title: function ( i ) {
							return self.bucketTitle( i );
						},
						value: function ( v ) {
							return self.money( v, 2 );
						},
						footer: function ( i ) {
							return { text: sprintf( t.unitsSold, self.formatNumber( units[ i ] ) ) };
						},
					};

					if ( held.chart && held.chart.canvas === canvas ) {
						charts.updateLineChart( held.chart, options );
					} else {
						held.chart = charts.lineChart( canvas, options );
					}
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
				 * Formats a whole number.
				 *
				 * @param {number} value Number.
				 * @return {string}
				 */
				formatNumber: function ( value ) {
					return format.number( value || 0, 0 );
				},

				sprintf: sprintf,
			};
		} );
	} );
}( window, document ) );
