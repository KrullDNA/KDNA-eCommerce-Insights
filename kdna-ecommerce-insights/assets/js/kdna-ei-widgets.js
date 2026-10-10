/**
 * KDNA eCommerce Insights: Elementor widgets on the front end.
 *
 * Plain JavaScript (no Alpine), built on the shared helpers the wp-admin
 * app uses: KDNAEI.format, KDNAEI.api, KDNAEI.dates, KDNAEI.kpi and the
 * chart theme (KDNAEI.charts).
 *
 * - Page range: one date range shared by every widget on the page. The
 *   Date Range widget changes it and broadcasts kdna:ei-range-change;
 *   widgets set to "Follow page date range" reload. The choice is saved to
 *   the Administrator's preferences, like the wp-admin app.
 * - Parts: renderers for the KPI strip, performance chart, inventory donut,
 *   hero card (top products, profit breakdown, goals tracker) and alerts.
 *   They take data in the shapes the REST API sends, so live data and the
 *   editor's sample data draw the same way. Stage 14's widgets reuse them.
 * - Widgets: each element with data-kdna-ei-widget is set up once, on page
 *   load and whenever Elementor's editor redraws it.
 *
 * Figures are only requested by logged-in Administrators; for anyone else
 * this script is not loaded at all.
 */
( function ( window, document ) {
	'use strict';

	var KDNAEI = window.KDNAEI = window.KDNAEI || {};
	var config = window.kdnaEiApp || {};
	var t = ( config.i18n && config.i18n.overview ) || {};
	var w = ( config.i18n && config.i18n.widgets ) || {};
	var format = KDNAEI.format;
	var api = KDNAEI.api;
	var sprintf = KDNAEI.sprintf;
	var dates = KDNAEI.dates;
	var charts = KDNAEI.charts;
	var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	/*
	 * ---------------------------------------------------------------------
	 * Small helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Creates an element with a class and optional text.
	 *
	 * @param {string} tag       Tag name.
	 * @param {string} className Classes.
	 * @param {string} text      Text content.
	 * @return {HTMLElement}
	 */
	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined && text !== null ) {
			node.textContent = text;
		}
		return node;
	}

	/**
	 * A line icon from the sprite printed in the page footer.
	 *
	 * @param {string} name      Icon name, such as "coins".
	 * @param {string} className Extra classes.
	 * @return {SVGElement}
	 */
	function icon( name, className ) {
		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS( ns, 'svg' );
		svg.setAttribute( 'class', 'kdna-ei-icon' + ( className ? ' ' + className : '' ) );
		svg.setAttribute( 'aria-hidden', 'true' );
		var use = document.createElementNS( ns, 'use' );
		use.setAttribute( 'href', '#kdna-ei-icon-' + name );
		svg.appendChild( use );
		return svg;
	}

	/**
	 * Today in the store's time zone, Y-m-d.
	 *
	 * @return {string}
	 */
	function today() {
		return config.today || iso( new Date() );
	}

	/**
	 * A date as Y-m-d.
	 *
	 * @param {Date} date Date.
	 * @return {string}
	 */
	function iso( date ) {
		var pad = function ( n ) {
			return ( n < 10 ? '0' : '' ) + n;
		};
		return date.getFullYear() + '-' + pad( date.getMonth() + 1 ) + '-' + pad( date.getDate() );
	}

	/**
	 * Fires a window event with details.
	 *
	 * @param {string} name   Event name.
	 * @param {Object} detail Details.
	 */
	function broadcast( name, detail ) {
		window.dispatchEvent( new window.CustomEvent( name, { detail: detail } ) );
	}

	var inFlight = {};

	/**
	 * Reads from the REST API, sharing the answer when several widgets on
	 * the page ask the same question at the same moment, so a page with two
	 * dashboards does not ask the server for everything twice.
	 *
	 * @param {string} path  Route, such as "summary".
	 * @param {Object} query Query parameters.
	 * @return {Promise}
	 */
	function read( path, query ) {
		var key = path + '?' + JSON.stringify( query || {} );
		if ( ! inFlight[ key ] ) {
			inFlight[ key ] = api( path, { query: query } );
			var forget = function () {
				delete inFlight[ key ];
			};
			inFlight[ key ].then( forget, forget );
		}
		return inFlight[ key ];
	}

	/*
	 * ---------------------------------------------------------------------
	 * The page's date range
	 * ---------------------------------------------------------------------
	 */

	var prefs = config.preferences || {};
	var pageRange = {
		preset: prefs.range || 'this_month',
		compare: prefs.comparison || 'previous_period',
		start: prefs.start || '',
		end: prefs.end || '',
	};

	KDNAEI.pageRange = {
		/**
		 * The page's current range.
		 *
		 * @return {Object} preset, compare, start, end.
		 */
		get: function () {
			return Object.assign( {}, pageRange );
		},

		/**
		 * Changes the page's range, saves it for the Administrator and tells
		 * every widget (kdna:ei-range-change).
		 *
		 * @param {Object} range  preset, compare, and start and end for custom.
		 * @param {*}      source Whoever changed it, so it can ignore its own event.
		 * @param {boolean} save  Whether to save it to the person's preferences.
		 */
		set: function ( range, source, save ) {
			pageRange = Object.assign( {}, pageRange, range );
			if ( save !== false && config.canView && config.restUrl ) {
				var body = { range: pageRange.preset, comparison: pageRange.compare };
				if ( pageRange.preset === 'custom' ) {
					body.start = pageRange.start;
					body.end = pageRange.end;
				}
				api( 'preferences', { method: 'POST', body: body } ).catch( function () {} );
			}
			broadcast( 'kdna:ei-range-change', { range: KDNAEI.pageRange.get(), source: source || null } );
		},
	};

	/**
	 * REST query parameters for a range.
	 *
	 * @param {Object} range preset, compare, start, end.
	 * @return {Object}
	 */
	function rangeQuery( range ) {
		var query = { preset: range.preset, compare: range.compare };
		if ( range.preset === 'custom' ) {
			query.start = range.start;
			query.end = range.end;
		}
		return query;
	}

	/**
	 * Words for a range, such as "Last 30 days" or "1 Oct to 9 Oct".
	 *
	 * @param {Object} range Range.
	 * @return {string}
	 */
	function rangeLabel( range ) {
		if ( range.preset === 'custom' && range.start && range.end ) {
			var opts = { day: 'numeric', month: 'short' };
			return sprintf( ( config.i18n && config.i18n.rangeTo ) || '%1$s to %2$s', dates.text( range.start, opts ), dates.text( range.end, opts ) );
		}
		return ( config.ranges && config.ranges[ range.preset ] ) || range.preset;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Theme
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Sets up the theme toggle button, if the widget has one.
	 *
	 * @param {HTMLElement} root     Widget root.
	 * @param {Object}      settings Widget settings.
	 */
	function setupTheme( root, settings ) {
		var button = root.querySelector( '[data-kdna-ei-action="theme"]' );
		if ( ! button ) {
			return;
		}
		var label = function () {
			var dark = root.getAttribute( 'data-kdna-ei-theme' ) !== 'light';
			button.setAttribute( 'aria-label', dark ? w.switchToLight : w.switchToDark );
			button.setAttribute( 'title', dark ? w.switchToLight : w.switchToDark );
		};
		label();
		button.addEventListener( 'click', function () {
			var theme = root.getAttribute( 'data-kdna-ei-theme' ) === 'light' ? 'dark' : 'light';
			root.setAttribute( 'data-kdna-ei-theme', theme );
			label();
			if ( config.canView && ! settings.editor ) {
				prefs.theme = theme;
				api( 'preferences', { method: 'POST', body: { theme: theme } } ).catch( function () {} );
			}
			broadcast( 'kdna:ei-theme-change', { theme: theme } );
		} );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Date range picker (dropdown, comparison and calendar)
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Wires up a date range dropdown.
	 *
	 * @param {HTMLElement} box      The .kdna-ei-range element.
	 * @param {Object}      options  getRange(), onChange( range ).
	 * @return {Object} sync() to update the label and ticks from the range.
	 */
	function rangePicker( box, options ) {
		var trigger = box.querySelector( '.kdna-ei-range__trigger' );
		var menu = box.querySelector( '.kdna-ei-range__menu' );
		var label = box.querySelector( '[data-kdna-ei-range-label]' );
		var custom = box.querySelector( '[data-kdna-ei-calendar]' );
		var grid = box.querySelector( '[data-kdna-ei-cal-grid]' );
		var monthLabel = box.querySelector( '[data-kdna-ei-cal-month]' );
		var hint = box.querySelector( '[data-kdna-ei-cal-hint]' );
		var apply = box.querySelector( '[data-kdna-ei-action="range-apply"]' );
		var picking = { start: '', end: '' };
		var shown = null;

		/**
		 * Updates the button label and the ticks to match the range.
		 */
		function sync() {
			var range = options.getRange();
			label.textContent = rangeLabel( range );
			box.querySelectorAll( '[data-kdna-ei-preset]' ).forEach( function ( item ) {
				item.setAttribute( 'aria-checked', item.getAttribute( 'data-kdna-ei-preset' ) === range.preset ? 'true' : 'false' );
			} );
			box.querySelectorAll( '[data-kdna-ei-compare]' ).forEach( function ( item ) {
				item.setAttribute( 'aria-checked', item.getAttribute( 'data-kdna-ei-compare' ) === range.compare ? 'true' : 'false' );
			} );
		}

		/**
		 * Opens or closes the menu.
		 *
		 * @param {boolean} open Whether to open.
		 */
		function toggle( open ) {
			menu.hidden = ! open;
			trigger.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			if ( open ) {
				var range = options.getRange();
				if ( custom ) {
					custom.hidden = range.preset !== 'custom';
					if ( range.preset === 'custom' ) {
						startCustom( range );
					}
				}
				var checked = menu.querySelector( '[aria-checked="true"]' ) || menu.querySelector( 'button' );
				if ( checked ) {
					checked.focus();
				}
			}
		}

		/**
		 * Opens the calendar, showing the current custom range if there is one.
		 *
		 * @param {Object} range Range.
		 */
		function startCustom( range ) {
			picking = { start: range.preset === 'custom' ? range.start : '', end: range.preset === 'custom' ? range.end : '' };
			var anchor = dates.toDate( picking.end || picking.start || today() );
			shown = new Date( anchor.getFullYear(), anchor.getMonth(), 1 );
			drawCalendar();
		}

		/**
		 * Draws one month of the calendar with the chosen days highlighted.
		 */
		function drawCalendar() {
			if ( ! grid || ! shown ) {
				return;
			}
			var weekStart = Number( config.weekStart || 0 );
			var first = new Date( shown.getFullYear(), shown.getMonth(), 1 );
			var offset = ( first.getDay() - weekStart + 7 ) % 7;
			var days = new Date( shown.getFullYear(), shown.getMonth() + 1, 0 ).getDate();
			var now = today();

			monthLabel.textContent = first.toLocaleDateString( config.locale || undefined, { month: 'long', year: 'numeric' } );
			grid.textContent = '';

			var head = el( 'div', 'kdna-ei-cal__row kdna-ei-cal__row--head' );
			head.setAttribute( 'role', 'row' );
			for ( var d = 0; d < 7; d++ ) {
				var name = new Date( 2024, 0, 7 + ( ( weekStart + d ) % 7 ) ).toLocaleDateString( config.locale || undefined, { weekday: 'short' } );
				var cell = el( 'span', 'kdna-ei-cal__weekday', name.slice( 0, 2 ) );
				cell.setAttribute( 'role', 'columnheader' );
				cell.setAttribute( 'aria-label', name );
				head.appendChild( cell );
			}
			grid.appendChild( head );

			var row = null;
			for ( var i = 0; i < offset + days; i++ ) {
				if ( i % 7 === 0 ) {
					row = el( 'div', 'kdna-ei-cal__row' );
					row.setAttribute( 'role', 'row' );
					grid.appendChild( row );
				}
				if ( i < offset ) {
					row.appendChild( el( 'span', 'kdna-ei-cal__blank' ) );
					continue;
				}
				var date = iso( new Date( shown.getFullYear(), shown.getMonth(), i - offset + 1 ) );
				var day = el( 'button', 'kdna-ei-cal__day', String( i - offset + 1 ) );
				day.type = 'button';
				day.setAttribute( 'role', 'gridcell' );
				day.setAttribute( 'data-date', date );
				day.setAttribute( 'aria-label', dates.text( date, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' } ) );
				if ( date > now ) {
					day.disabled = true;
				}
				if ( date === now ) {
					day.classList.add( 'is-today' );
					day.setAttribute( 'aria-current', 'date' );
				}
				var end = picking.end || picking.start;
				if ( picking.start && date >= picking.start && date <= end ) {
					day.classList.add( 'is-in-range' );
				}
				if ( date === picking.start || date === picking.end ) {
					day.classList.add( 'is-selected' );
					day.setAttribute( 'aria-pressed', 'true' );
				}
				if ( date === picking.start ) {
					day.classList.add( 'is-start' );
				}
				if ( date === end ) {
					day.classList.add( 'is-end' );
				}
				row.appendChild( day );
			}

			hint.textContent = picking.start && ! picking.end ? w.chooseEnd : ( picking.start ? rangeLabel( { preset: 'custom', start: picking.start, end: picking.end } ) : w.chooseStart );
			apply.disabled = ! ( picking.start && picking.end );
		}

		/**
		 * Chooses a day: the first click sets the start, the second the end.
		 *
		 * @param {string} date Y-m-d.
		 */
		function chooseDay( date ) {
			if ( ! picking.start || picking.end ) {
				picking = { start: date, end: '' };
			} else if ( date < picking.start ) {
				picking = { start: date, end: picking.start };
			} else {
				picking.end = date;
			}
			drawCalendar();
			var focus = grid.querySelector( '[data-date="' + date + '"]' );
			if ( focus ) {
				focus.focus();
			}
		}

		trigger.addEventListener( 'click', function () {
			toggle( menu.hidden );
		} );

		box.addEventListener( 'click', function ( event ) {
			var preset = event.target.closest( '[data-kdna-ei-preset]' );
			var compare = event.target.closest( '[data-kdna-ei-compare]' );
			var day = event.target.closest( '.kdna-ei-cal__day' );
			var action = event.target.closest( '[data-kdna-ei-action]' );

			if ( preset ) {
				var key = preset.getAttribute( 'data-kdna-ei-preset' );
				if ( key === 'custom' ) {
					custom.hidden = false;
					startCustom( options.getRange() );
					return;
				}
				options.onChange( { preset: key, start: '', end: '' } );
				sync();
				toggle( false );
				trigger.focus();
			} else if ( compare ) {
				options.onChange( { compare: compare.getAttribute( 'data-kdna-ei-compare' ) } );
				sync();
			} else if ( day && ! day.disabled ) {
				chooseDay( day.getAttribute( 'data-date' ) );
			} else if ( action ) {
				var name = action.getAttribute( 'data-kdna-ei-action' );
				if ( name === 'cal-prev' || name === 'cal-next' ) {
					shown = new Date( shown.getFullYear(), shown.getMonth() + ( name === 'cal-prev' ? -1 : 1 ), 1 );
					drawCalendar();
				} else if ( name === 'range-apply' && picking.start && picking.end ) {
					options.onChange( { preset: 'custom', start: picking.start, end: picking.end } );
					sync();
					toggle( false );
					trigger.focus();
				} else if ( name === 'range-cancel' ) {
					custom.hidden = true;
				}
			}
		} );

		// Arrow keys move between days in the calendar.
		if ( grid ) {
			grid.addEventListener( 'keydown', function ( event ) {
				var moves = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
				var current = event.target.closest( '.kdna-ei-cal__day' );
				if ( ! current || moves[ event.key ] === undefined ) {
					return;
				}
				event.preventDefault();
				var target = dates.toDate( current.getAttribute( 'data-date' ) );
				target.setDate( target.getDate() + moves[ event.key ] );
				if ( target.getMonth() !== shown.getMonth() ) {
					shown = new Date( target.getFullYear(), target.getMonth(), 1 );
					drawCalendar();
				}
				var next = grid.querySelector( '[data-date="' + iso( target ) + '"]' );
				if ( next && ! next.disabled ) {
					next.focus();
				}
			} );
		}

		// Escape or a click elsewhere closes the menu.
		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' && ! menu.hidden ) {
				toggle( false );
				trigger.focus();
			}
		} );
		// The path is read from the event, as a click on a calendar day redraws
		// the calendar and the clicked button is gone by the time this runs.
		document.addEventListener( 'click', function ( event ) {
			var path = event.composedPath ? event.composedPath() : [ event.target ];
			if ( ! menu.hidden && path.indexOf( box ) === -1 ) {
				toggle( false );
			}
		} );

		sync();
		return { sync: sync };
	}

	/*
	 * ---------------------------------------------------------------------
	 * Parts: shared renderers
	 * ---------------------------------------------------------------------
	 */

	var parts = KDNAEI.widgetParts = {};

	/**
	 * Counts a number up from zero, like the admin app's KPI strip.
	 *
	 * @param {HTMLElement} node    Element whose text shows the number.
	 * @param {Object}      metric  Metric from /summary.
	 */
	parts.countUp = function ( node, metric ) {
		var show = function ( value ) {
			if ( value !== null && metric.format === 'number' && ! metric.decimals ) {
				value = Math.round( value );
			}
			node.textContent = format.metric( value, metric.format, metric.decimals );
		};
		if ( reduceMotion || metric.value === null ) {
			show( metric.value );
			return;
		}
		var start = null;
		var target = Number( metric.value );
		function step( time ) {
			if ( start === null ) {
				start = time;
			}
			var p = Math.min( 1, ( time - start ) / 700 );
			show( target * ( 1 - Math.pow( 1 - p, 3 ) ) );
			if ( p < 1 ) {
				window.requestAnimationFrame( step );
			}
		}
		window.requestAnimationFrame( step );
	};

	/**
	 * Fills a KPI strip: icon, label, value and the change.
	 *
	 * @param {HTMLElement} strip          The .kdna-ei-kpi-strip element.
	 * @param {Object[]}    metrics        Metrics from /summary.
	 * @param {string}      comparisonName For example "Previous period".
	 */
	parts.kpis = function ( strip, metrics, comparisonName ) {
		strip.textContent = '';
		metrics.forEach( function ( metric ) {
			var item = el( 'div', 'kdna-ei-kpi' );
			var iconBox = el( 'span', 'kdna-ei-kpi__icon' );
			iconBox.setAttribute( 'aria-hidden', 'true' );
			iconBox.appendChild( icon( KDNAEI.kpi.icon( metric.key ) ) );
			var body = el( 'div', 'kdna-ei-kpi__body' );
			var labelRow = el( 'div', 'kdna-ei-kpi__label-row' );
			var label = el( 'span', 'kdna-ei-kpi__label', metric.label );
			label.title = metric.help || '';
			labelRow.appendChild( label );
			var row = el( 'div', 'kdna-ei-kpi__row' );
			var value = el( 'span', 'kdna-ei-kpi__value' );
			row.appendChild( value );
			parts.countUp( value, metric );

			var change = KDNAEI.kpi.changeText( metric );
			if ( change ) {
				var tone = metric.sentiment === 'good' ? 'good' : ( metric.sentiment === 'bad' ? 'bad' : 'neutral' );
				var badge = el( 'span', 'kdna-ei-change kdna-ei-change--' + tone );
				badge.title = KDNAEI.kpi.previousText( metric, comparisonName );
				if ( metric.direction === 'up' || metric.direction === 'down' ) {
					badge.appendChild( icon( metric.direction === 'up' ? 'arrow-up' : 'arrow-down' ) );
				}
				badge.appendChild( el( 'span', '', change ) );
				badge.appendChild( el( 'span', 'kdna-ei-visually-hidden', badge.title ) );
				row.appendChild( badge );
			}
			body.appendChild( labelRow );
			body.appendChild( row );
			item.appendChild( iconBox );
			item.appendChild( body );
			strip.appendChild( item );
		} );
	};

	/**
	 * Chart.js options for the performance chart, matching the admin app's.
	 *
	 * @param {Object} series     Response from /timeseries.
	 * @param {string} key        Series key: net_revenue, net_profit or orders.
	 * @param {string} comparison Comparison mode.
	 * @return {Object}
	 */
	parts.chartOptions = function ( series, key, comparison ) {
		var current = series.series[ key ].current;
		var previous = series.series[ key ].previous;
		var kind = key === 'orders' ? 'number' : 'currency';
		var compareName = KDNAEI.kpi.comparisonLabel( comparison );
		var list = [ { label: t.thisPeriod, data: current.slice() } ];
		if ( previous && series.previous_buckets ) {
			list.push( { label: compareName, data: current.map( function ( v, i ) {
				return previous[ i ] === undefined ? null : previous[ i ];
			} ) } );
		}
		var title = function ( i ) {
			var bucket = series.buckets[ i ];
			if ( ! bucket ) {
				return '';
			}
			if ( series.granularity === 'month' ) {
				return dates.text( bucket.start, { month: 'long', year: 'numeric' } );
			}
			if ( series.granularity === 'week' ) {
				return sprintf( t.weekOf, dates.text( bucket.start, { day: 'numeric', month: 'short', year: 'numeric' } ) );
			}
			return dates.text( bucket.start, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' } );
		};
		return {
			labels: series.buckets.map( function ( b ) {
				return b.key;
			} ),
			series: list,
			maxXTicks: series.granularity === 'day' && series.buckets.length > 20 ? 5 : 7,
			xLabel: function ( i ) {
				return dates.axis ? dates.axis( series.buckets, i, series.granularity ) : series.buckets[ i ].start;
			},
			yLabel: function ( value ) {
				return format.compact( value );
			},
			title: title,
			seriesLabel: function ( datasetIndex, i ) {
				if ( datasetIndex === 0 ) {
					return t.thisPeriod;
				}
				var bucket = series.previous_buckets && series.previous_buckets[ i ];
				return bucket ? dates.text( bucket.start, { day: 'numeric', month: 'short', year: 'numeric' } ) : compareName;
			},
			value: function ( v ) {
				return format.metric( v, kind );
			},
			footer: function ( i ) {
				var now = current[ i ];
				var before = previous ? previous[ i ] : null;
				if ( before === null || before === undefined || ! before || now === null ) {
					return null;
				}
				var change = ( now - before ) / Math.abs( before ) * 100;
				return { text: ( change >= 0 ? '+' : '−' ) + format.number( Math.abs( change ), 1 ) + '%', className: change >= 0 ? 'is-good' : 'is-bad' };
			},
		};
	};

	/**
	 * Inventory legend rows and the donut.
	 *
	 * @param {HTMLElement} card   The inventory card.
	 * @param {Object}      status in_stock, low_stock, out_of_stock.
	 * @param {Object}      held   Where the chart is kept between redraws.
	 */
	parts.inventory = function ( card, status, held ) {
		var total = status.in_stock + status.low_stock + status.out_of_stock;
		[ 'in_stock', 'low_stock', 'out_of_stock' ].forEach( function ( key ) {
			var row = card.querySelector( '[data-kdna-ei-stock="' + key + '"]' );
			if ( ! row ) {
				return;
			}
			var pct = total ? Math.round( status[ key ] / total * 100 ) : 0;
			row.cells[ 1 ].textContent = format.number( status[ key ], 0 );
			row.cells[ 2 ].textContent = ( status[ key ] > 0 && pct === 0 ? '<1' : pct ) + '%';
		} );
		var number = card.querySelector( '[data-kdna-ei-part="donut-number"]' );
		if ( number ) {
			number.textContent = format.number( status.in_stock, 0 );
		}
		var canvas = card.querySelector( '[data-kdna-ei-part="donut"]' );
		if ( ! canvas || ! charts || ! charts.available ) {
			return;
		}
		var values = [ status.in_stock, status.low_stock, status.out_of_stock ];
		if ( ! values.some( function ( v ) {
			return v > 0;
		} ) ) {
			values = [ 1, 0, 0 ];
		}
		if ( held.donut && held.donut.canvas === canvas ) {
			held.donut.data.datasets[ 0 ].data = values;
			held.donut.update();
			return;
		}
		held.donut = charts.donutChart( canvas, {
			labels: [ t.inStockLegend, t.lowStock, t.outOfStock ],
			values: values,
			colours: [ 'accent', 'positive', 'accent2' ],
			cutout: '70%',
		} );
	};

	/**
	 * Top products: ranked list with thumbnail, amount, units, margin and a bar.
	 *
	 * @param {HTMLElement} body  Where to draw.
	 * @param {Object[]}    rows  Products from /products.
	 * @param {string}      sort  profit or revenue.
	 */
	parts.topProducts = function ( body, rows, sort ) {
		body.textContent = '';
		if ( ! rows.length ) {
			body.appendChild( el( 'p', 'kdna-ei-muted kdna-ei-list-empty', t.noProducts ) );
			return;
		}
		var list = el( 'ol', 'kdna-ei-rank' );
		rows.forEach( function ( item ) {
			var li = el( 'li', 'kdna-ei-rank__item' );
			var thumb = el( 'span', 'kdna-ei-thumb kdna-ei-rank__thumb' );
			if ( item.thumbnail ) {
				var img = el( 'img' );
				img.src = item.thumbnail;
				img.alt = '';
				img.loading = 'lazy';
				thumb.appendChild( img );
			} else {
				thumb.appendChild( icon( 'box' ) );
			}
			var main = el( 'span', 'kdna-ei-rank__body' );
			var top = el( 'span', 'kdna-ei-rank__top' );
			top.appendChild( el( 'span', 'kdna-ei-rank__name', item.name + ( item.variation ? ', ' + item.variation : '' ) ) );
			top.appendChild( el( 'span', 'kdna-ei-rank__amount kdna-ei-num', format.money( sort === 'revenue' ? item.revenue : item.profit, 0 ) ) );
			var meta = el( 'span', 'kdna-ei-rank__meta' );
			meta.appendChild( el( 'span', '', sprintf( t.unitsSold, format.number( item.units, 0 ) ) ) );
			meta.appendChild( el( 'span', '', item.margin === null ? '' : sprintf( t.marginOf, format.percent( item.margin ) ) ) );
			var bar = el( 'span', 'kdna-ei-bar' );
			bar.setAttribute( 'aria-hidden', 'true' );
			var fill = el( 'span', 'kdna-ei-bar__fill' + ( item.margin < 0 ? ' is-negative' : '' ) );
			fill.style.width = Math.max( 2, Math.min( 100, Math.abs( item.margin || 0 ) ) ) + '%';
			bar.appendChild( fill );
			main.appendChild( top );
			main.appendChild( meta );
			main.appendChild( bar );
			li.appendChild( thumb );
			li.appendChild( main );
			list.appendChild( li );
		} );
		body.appendChild( list );
	};

	/**
	 * Profit breakdown: net revenue down to net profit as horizontal bars.
	 *
	 * @param {HTMLElement} body      Where to draw.
	 * @param {Object[]}    waterfall Lines from /profit.
	 */
	parts.waterfall = function ( body, waterfall ) {
		var keys = [ 'net_revenue', 'cogs', 'payment_fees', 'shipping_costs', 'extra_costs', 'ad_spend', 'overheads', 'net_profit' ];
		var lines = {};
		waterfall.forEach( function ( line ) {
			lines[ line.key ] = line;
		} );
		var top = Math.max( 1, Math.abs( lines.net_revenue ? lines.net_revenue.amount : 0 ) );
		var running = top;
		body.textContent = '';
		var box = el( 'div', 'kdna-ei-waterfall' );
		keys.filter( function ( key ) {
			return lines[ key ];
		} ).forEach( function ( key ) {
			var line = lines[ key ];
			var left;
			var width;
			if ( line.type === 'total' ) {
				left = 0;
				width = Math.min( 100, Math.abs( line.amount ) / top * 100 );
				running = Math.max( 0, line.amount );
			} else {
				running -= Math.abs( line.amount );
				left = Math.max( 0, running / top * 100 );
				width = Math.min( 100 - left, Math.abs( line.amount ) / top * 100 );
			}
			width = Math.max( width, line.amount ? 0.8 : 0 );
			var row = el( 'div', 'kdna-ei-waterfall__row is-' + line.type );
			row.appendChild( el( 'span', 'kdna-ei-waterfall__label', line.label ) );
			row.appendChild( el( 'span', 'kdna-ei-waterfall__amount kdna-ei-num' + ( line.amount < 0 ? ' is-negative' : '' ), format.money( line.amount, 0 ) ) );
			var track = el( 'span', 'kdna-ei-waterfall__track' );
			track.setAttribute( 'aria-hidden', 'true' );
			var barEl = el( 'span', 'kdna-ei-waterfall__bar' );
			barEl.style.left = left + '%';
			barEl.style.width = width + '%';
			track.appendChild( barEl );
			row.appendChild( track );
			box.appendChild( row );
		} );
		body.appendChild( box );
	};

	/**
	 * Goals tracker: progress ring, on track or behind, and four facts.
	 *
	 * @param {HTMLElement} body Where to draw.
	 * @param {Object}      goal metric (revenue, profit or orders), value, target.
	 * @param {string}      link Address of the goal settings, or empty.
	 */
	parts.goal = function ( body, goal, link ) {
		body.textContent = '';
		var box = el( 'div', 'kdna-ei-goal' );
		if ( ! goal.target ) {
			var empty = el( 'div', 'kdna-ei-goal__empty' );
			empty.appendChild( el( 'p', 'kdna-ei-list-empty', t.goalEmpty ) );
			if ( link ) {
				var a = el( 'a', 'kdna-ei-btn', t.goalSet );
				a.href = link;
				empty.appendChild( a );
			}
			box.appendChild( empty );
			body.appendChild( box );
			return;
		}
		var now = dates.toDate( today() );
		var daysInMonth = new Date( now.getFullYear(), now.getMonth() + 1, 0 ).getDate();
		var day = now.getDate();
		var daysLeft = daysInMonth - day + 1;
		var percent = Math.round( goal.value / goal.target * 100 );
		var onTrack = goal.value >= goal.target * ( day / daysInMonth );
		var kind = goal.metric === 'orders' ? 'number' : 'currency';
		var circumference = 2 * Math.PI * 52;

		var main = el( 'div', 'kdna-ei-goal__body' );
		var ring = el( 'div', 'kdna-ei-ring' );
		ring.setAttribute( 'role', 'img' );
		ring.setAttribute( 'aria-label', sprintf( t.goalProgress, percent ) );
		ring.innerHTML = '<svg viewBox="0 0 120 120" aria-hidden="true"><circle class="kdna-ei-ring__track" cx="60" cy="60" r="52"></circle><circle class="kdna-ei-ring__fill' + ( onTrack ? '' : ' is-behind' ) + '" cx="60" cy="60" r="52" style="stroke-dashoffset:' + circumference + '"></circle></svg>';
		var centre = el( 'span', 'kdna-ei-ring__centre' );
		centre.appendChild( el( 'span', 'kdna-ei-ring__percent kdna-ei-num', percent + '%' ) );
		centre.appendChild( el( 'span', 'kdna-ei-ring__label', ( t.goalMetrics && t.goalMetrics[ goal.metric ] ) || '' ) );
		ring.appendChild( centre );
		main.appendChild( ring );
		main.appendChild( el( 'span', 'kdna-ei-badge ' + ( onTrack ? 'kdna-ei-badge--positive' : 'kdna-ei-badge--warning' ), onTrack ? t.onTrack : t.behind ) );
		var facts = el( 'dl', 'kdna-ei-goal__facts' );
		[
			[ t.soFar, format.metric( goal.value, kind ) ],
			[ t.target, format.metric( goal.target, kind ) ],
			[ t.daysLeft, String( daysLeft ) ],
			[ t.perDayNeeded, format.metric( Math.max( 0, ( goal.target - goal.value ) / Math.max( 1, daysLeft ) ), kind ) ],
		].forEach( function ( fact ) {
			var item = el( 'div' );
			item.appendChild( el( 'dt', '', fact[ 0 ] ) );
			item.appendChild( el( 'dd', 'kdna-ei-num', fact[ 1 ] ) );
			facts.appendChild( item );
		} );
		main.appendChild( facts );
		box.appendChild( main );
		body.appendChild( box );

		// Sweep the ring round after it is on the page.
		var fill = ring.querySelector( '.kdna-ei-ring__fill' );
		window.requestAnimationFrame( function () {
			fill.style.strokeDashoffset = circumference * ( 1 - Math.min( 1, Math.max( 0, percent ) / 100 ) );
		} );
	};

	/**
	 * The alerts strip: missing costs, stock, loss-making orders, currency
	 * and sync problems.
	 *
	 * @param {HTMLElement} box       The alerts element.
	 * @param {Object}      estimates From the summary meta.
	 * @param {Object}      status    From /status.
	 * @param {Object}      stock     Inventory status.
	 * @param {string}      admin     Address of Insights in wp-admin, or empty for no links.
	 */
	parts.alerts = function ( box, estimates, status, stock, admin ) {
		var list = [];
		var e = estimates || {};
		var s = status || {};
		stock = stock || {};
		if ( s.missing_costs > 0 ) {
			list.push( { tone: 'warning', icon: 'tag', text: sprintf( s.missing_costs === 1 ? t.alertMissingOne : t.alertMissing, format.number( s.missing_costs, 0 ) ), label: t.addCosts, hash: '#/costs' } );
		}
		if ( stock.low_stock > 0 || stock.out_of_stock > 0 ) {
			list.push( { tone: stock.out_of_stock > 0 ? 'negative' : 'warning', icon: 'box', text: sprintf( t.alertStock, format.number( stock.low_stock || 0, 0 ), format.number( stock.out_of_stock || 0, 0 ) ), label: t.viewStock, hash: '#/inventory' } );
		}
		if ( e.loss_orders > 0 ) {
			list.push( { tone: 'negative', icon: 'alert', text: sprintf( e.loss_orders === 1 ? t.alertLossOne : t.alertLoss, format.number( e.loss_orders, 0 ) ), label: t.viewProfit, hash: '#/profit' } );
		}
		if ( e.currency_flag_orders > 0 ) {
			list.push( { tone: 'warning', icon: 'alert', text: sprintf( t.alertCurrency, format.number( e.currency_flag_orders, 0 ) ) } );
		}
		if ( s.sync_errors > 0 ) {
			list.push( { tone: 'negative', icon: 'alert', text: sprintf( t.alertErrors, format.number( s.sync_errors, 0 ) ), label: t.viewActivity, hash: '#/settings' } );
		}

		box.textContent = '';
		box.hidden = ! list.length;
		list.forEach( function ( alert ) {
			var row = el( 'div', 'kdna-ei-alert kdna-ei-alert--' + alert.tone );
			var iconBox = el( 'span', 'kdna-ei-alert__icon' );
			iconBox.setAttribute( 'aria-hidden', 'true' );
			iconBox.appendChild( icon( alert.icon, 'kdna-ei-icon--sm' ) );
			row.appendChild( iconBox );
			row.appendChild( el( 'span', 'kdna-ei-alert__text', alert.text ) );
			if ( admin && alert.hash ) {
				var link = el( 'a', 'kdna-ei-link', alert.label );
				link.href = admin + alert.hash;
				row.appendChild( link );
			}
			var close = el( 'button', 'kdna-ei-alert__close', '×' );
			close.type = 'button';
			close.setAttribute( 'aria-label', t.dismiss );
			close.addEventListener( 'click', function () {
				row.remove();
				box.hidden = ! box.children.length;
			} );
			row.appendChild( close );
			box.appendChild( row );
		} );
	};

	/*
	 * ---------------------------------------------------------------------
	 * Dashboard widget
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Sets up one Dashboard widget.
	 *
	 * @param {HTMLElement} root     Widget root.
	 * @param {Object}      settings From data-kdna-ei-settings.
	 */
	function dashboard( root, settings ) {
		var held = {};
		var requestId = 0;
		var data = null;
		var seriesKey = settings.series || 'net_revenue';
		var sort = settings.sort || 'profit';
		var own = settings.range.follow ? null : { preset: settings.range.preset, compare: settings.range.compare, start: '', end: '' };
		var picker = null;

		/**
		 * The range this widget shows.
		 *
		 * @return {Object}
		 */
		function range() {
			return own || KDNAEI.pageRange.get();
		}

		/**
		 * Switches the widget between loading, ready, empty and error.
		 *
		 * @param {string} state State.
		 */
		function setState( state ) {
			root.setAttribute( 'data-kdna-ei-state', state );
			root.setAttribute( 'aria-busy', state === 'loading' ? 'true' : 'false' );
		}

		/**
		 * Fetches everything the panels need for the current range.
		 *
		 * @return {Promise<Object>} Data in the same shape as the sample data.
		 */
		function fetchLive() {
			var query = rangeQuery( range() );
			var panels = settings.panels;
			var hero = settings.hero;
			var jobs = {
				summary: panels.kpis || panels.alerts ? read( 'summary', Object.assign( {}, query, { metrics: [ 'net_revenue', 'net_profit', 'orders', 'net_margin', settings.fifth ].join( ',' ) } ) ) : null,
				timeseries: panels.chart ? read( 'timeseries', Object.assign( {}, query, { metrics: 'net_revenue,net_profit,orders' } ) ) : null,
				inventory: panels.inventory || panels.alerts ? read( 'inventory', query ) : null,
				status: read( 'status' ),
				profit: panels.hero && hero === 'profit_breakdown' ? read( 'profit', query ) : null,
				products: panels.hero && hero === 'top_products' ? read( 'products', Object.assign( {}, query, { per_page: 5, orderby: sort } ) ) : null,
				goal: null,
			};
			if ( panels.hero && hero === 'goals' ) {
				var goal = config.hero || {};
				var metricKey = { revenue: 'net_revenue', profit: 'net_profit', orders: 'orders' }[ goal.goal_metric ] || 'net_revenue';
				jobs.goal = read( 'summary', { preset: 'this_month', compare: 'none', metrics: metricKey } ).then( function ( response ) {
					return {
						metric: goal.goal_metric || 'revenue',
						value: Number( response.data.metrics[ 0 ].value || 0 ),
						target: Number( ( goal.goal_targets || {} )[ goal.goal_metric || 'revenue' ] || 0 ),
					};
				} );
			}
			var keys = Object.keys( jobs );
			return Promise.all( keys.map( function ( key ) {
				return jobs[ key ];
			} ) ).then( function ( results ) {
				var r = {};
				keys.forEach( function ( key, i ) {
					r[ key ] = results[ i ];
				} );
				return {
					summary: r.summary ? r.summary.data : null,
					estimates: r.summary ? r.summary.meta.estimates : {},
					compare: r.summary ? r.summary.meta.compare : null,
					timeseries: r.timeseries ? r.timeseries.data : null,
					inventory: r.inventory ? { status: r.inventory.data.status } : null,
					products: r.products ? r.products.data.rows : [],
					waterfall: r.profit ? r.profit.data.waterfall : [],
					goal: r.goal,
					status: r.status || {},
				};
			} );
		}

		/**
		 * Loads (or, in the editor, fakes) the figures and draws them.
		 *
		 * @return {Promise|undefined}
		 */
		function load() {
			var id = ++requestId;
			if ( [ 'loading', 'empty', 'error' ].indexOf( settings.state ) !== -1 ) {
				setState( settings.state );
				return;
			}
			setState( 'loading' );
			var source = settings.state === 'sample' ? Promise.resolve( settings.sample ) : fetchLive();
			return source.then( function ( result ) {
				if ( id !== requestId ) {
					return;
				}
				data = result;
				if ( settings.state !== 'sample' && result.status && Number( result.status.orders_processed ) === 0 ) {
					setState( 'empty' );
					return;
				}
				setState( 'ready' );
				draw();
			} ).catch( function ( error ) {
				if ( id !== requestId ) {
					return;
				}
				var message = root.querySelector( '[data-kdna-ei-error]' );
				if ( message && error && error.message ) {
					message.textContent = error.message;
				}
				setState( 'error' );
			} );
		}

		/**
		 * Draws every panel from the loaded data.
		 */
		function draw() {
			var compareName = KDNAEI.kpi.comparisonLabel( range().compare );
			var strip = root.querySelector( '[data-kdna-ei-part="kpis"]' );
			if ( strip && data.summary ) {
				parts.kpis( strip, data.summary.metrics, compareName );
			}
			drawChart();
			var stockCard = root.querySelector( '.kdna-ei-inventory-card' );
			if ( stockCard && data.inventory ) {
				parts.inventory( stockCard, data.inventory.status, held );
			}
			drawHero();
			var alerts = root.querySelector( '[data-kdna-ei-part="alerts"]' );
			if ( alerts ) {
				parts.alerts( alerts, data.estimates, data.status, data.inventory && data.inventory.status, settings.links ? config.adminUrl : '' );
			}
		}

		/**
		 * Draws the performance chart for the chosen series.
		 */
		function drawChart() {
			var canvas = root.querySelector( '[data-kdna-ei-part="chart"]' );
			if ( ! canvas || ! data.timeseries || ! charts || ! charts.available ) {
				return;
			}
			var options = parts.chartOptions( data.timeseries, seriesKey, range().compare );
			if ( held.chart && held.chart.canvas === canvas ) {
				charts.updateLineChart( held.chart, options );
			} else {
				held.chart = charts.lineChart( canvas, options );
			}
			var names = { net_revenue: t.revenue, net_profit: t.profit, orders: t.orders };
			root.querySelector( '[data-kdna-ei-legend="current"]' ).textContent = names[ seriesKey ];
			var compare = root.querySelector( '[data-kdna-ei-legend-compare]' );
			compare.hidden = options.series.length < 2;
			root.querySelector( '[data-kdna-ei-legend="compare"]' ).textContent = KDNAEI.kpi.comparisonLabel( range().compare );
			root.querySelectorAll( '[data-kdna-ei-series]' ).forEach( function ( button ) {
				button.setAttribute( 'aria-pressed', button.getAttribute( 'data-kdna-ei-series' ) === seriesKey ? 'true' : 'false' );
			} );
		}

		/**
		 * Draws the hero card.
		 */
		function drawHero() {
			var body = root.querySelector( '[data-kdna-ei-part="hero"]' );
			if ( ! body ) {
				return;
			}
			if ( settings.hero === 'profit_breakdown' ) {
				parts.waterfall( body, data.waterfall || [] );
			} else if ( settings.hero === 'goals' ) {
				parts.goal( body, data.goal || { metric: 'revenue', value: 0, target: 0 }, settings.links ? config.adminUrl + '#/settings' : '' );
			} else {
				var rows = ( data.products || [] ).slice();
				if ( settings.state === 'sample' ) {
					rows.sort( function ( a, b ) {
						return b[ sort ] - a[ sort ];
					} );
				}
				parts.topProducts( body, rows, sort );
			}
			root.querySelectorAll( '[data-kdna-ei-sort]' ).forEach( function ( tab ) {
				tab.setAttribute( 'aria-selected', tab.getAttribute( 'data-kdna-ei-sort' ) === sort ? 'true' : 'false' );
			} );
		}

		// Buttons inside the widget.
		root.addEventListener( 'click', function ( event ) {
			var series = event.target.closest( '[data-kdna-ei-series]' );
			var sortTab = event.target.closest( '[data-kdna-ei-sort]' );
			var retry = event.target.closest( '[data-kdna-ei-action="retry"]' );
			if ( series && data ) {
				seriesKey = series.getAttribute( 'data-kdna-ei-series' );
				drawChart();
			} else if ( sortTab && sort !== sortTab.getAttribute( 'data-kdna-ei-sort' ) ) {
				sort = sortTab.getAttribute( 'data-kdna-ei-sort' );
				if ( settings.state === 'sample' ) {
					drawHero();
				} else {
					load();
				}
			} else if ( retry ) {
				load();
			}
		} );

		// The optional date range picker in the header.
		var box = root.querySelector( '[data-kdna-ei-range]' );
		if ( box ) {
			picker = rangePicker( box, {
				getRange: range,
				onChange: function ( change ) {
					if ( own ) {
						own = Object.assign( {}, own, change );
						load();
					} else {
						KDNAEI.pageRange.set( change, root, ! settings.editor );
					}
				},
			} );
		}

		// Follow the page's date range.
		window.addEventListener( 'kdna:ei-range-change', function () {
			if ( ! root.isConnected ) {
				return;
			}
			if ( picker ) {
				picker.sync();
			}
			if ( ! own && settings.state !== 'sample' ) {
				load();
			}
		} );

		setupTheme( root, settings );
		load();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Date Range widget
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Sets up one Date Range widget.
	 *
	 * @param {HTMLElement} root     Widget root.
	 * @param {Object}      settings From data-kdna-ei-settings.
	 */
	function dateRange( root, settings ) {
		var box = root.querySelector( '[data-kdna-ei-range]' );
		if ( ! box ) {
			return;
		}
		// "Starts on" a fixed preset: the page starts there, without saving it.
		if ( settings.start && settings.start !== 'saved' && ! dateRange.started ) {
			dateRange.started = true;
			KDNAEI.pageRange.set( { preset: settings.start, start: '', end: '' }, root, false );
		}
		var picker = rangePicker( box, {
			getRange: function () {
				return KDNAEI.pageRange.get();
			},
			onChange: function ( change ) {
				KDNAEI.pageRange.set( change, root, ! settings.editor );
			},
		} );
		window.addEventListener( 'kdna:ei-range-change', function () {
			if ( root.isConnected ) {
				picker.sync();
			}
		} );
		setupTheme( root, settings );
		root.setAttribute( 'data-kdna-ei-state', 'ready' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Setting widgets up
	 * ---------------------------------------------------------------------
	 */

	var types = { dashboard: dashboard, 'date-range': dateRange };
	KDNAEI.widgetTypes = types;

	/**
	 * Sets up every Insights widget inside an element, once each.
	 *
	 * @param {Element} scope Element to look in.
	 */
	function mountAll( scope ) {
		var roots = [];
		if ( scope.matches && scope.matches( '[data-kdna-ei-widget]' ) ) {
			roots.push( scope );
		}
		scope.querySelectorAll( '[data-kdna-ei-widget]' ).forEach( function ( root ) {
			roots.push( root );
		} );
		roots.forEach( function ( root ) {
			if ( root.kdnaEiMounted ) {
				return;
			}
			root.kdnaEiMounted = true;
			var type = types[ root.getAttribute( 'data-kdna-ei-widget' ) ];
			var settings = {};
			try {
				settings = JSON.parse( root.getAttribute( 'data-kdna-ei-settings' ) || '{}' );
			} catch ( e ) {
				settings = {};
			}
			if ( type ) {
				type( root, settings );
			}
		} );
	}
	KDNAEI.mountWidgets = mountAll;

	/**
	 * Tells Elementor's editor to set widgets up each time it draws one.
	 */
	function hookElementor() {
		if ( ! window.elementorFrontend || ! window.elementorFrontend.hooks ) {
			return;
		}
		Object.keys( types ).forEach( function ( type ) {
			window.elementorFrontend.hooks.addAction( 'frontend/element_ready/kdna-ei-' + type + '.default', function ( $scope ) {
				mountAll( $scope && $scope[ 0 ] ? $scope[ 0 ] : document );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			mountAll( document );
		} );
	} else {
		mountAll( document );
	}

	if ( window.elementorFrontend && window.elementorFrontend.hooks ) {
		hookElementor();
	} else if ( window.jQuery ) {
		window.jQuery( window ).on( 'elementor/frontend/init', hookElementor );
	}
}( window, document ) );
