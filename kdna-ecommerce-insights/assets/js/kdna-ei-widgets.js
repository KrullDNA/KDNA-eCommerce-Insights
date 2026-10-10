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
	 *
	 * Every widget draws with these, so the Dashboard and the modular
	 * widgets always look and behave the same.
	 * ---------------------------------------------------------------------
	 */

	var parts = KDNAEI.widgetParts = {};

	// Metrics where going down is good news, for the chart tooltip colour.
	var lowerIsBetter = [ 'cogs', 'payment_fees', 'shipping_costs', 'extra_costs', 'ad_spend', 'overheads', 'discounts', 'refunds', 'refund_rate', 'discount_rate', 'cpa' ];

	// Token names for donut segments, in order (the same order as the PHP legend dots).
	var segmentColours = [ 'accent', 'positive', 'accent2', 'warning', 'negative', 'muted' ];
	var segmentVars = [ 'accent', 'positive', 'accent-2', 'warning', 'negative', 'text-muted' ];

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
	 * Fills the KPI figures: icon, label, value and the change. As a strip
	 * the figures share one card; as cards each is its own card.
	 *
	 * @param {HTMLElement} strip          The [data-kdna-ei-part="kpis"] element.
	 * @param {Object[]}    metrics        Metrics from /summary.
	 * @param {string}      comparisonName For example "Previous period".
	 */
	parts.kpis = function ( strip, metrics, comparisonName ) {
		var cards = strip.getAttribute( 'data-kdna-ei-layout' ) === 'cards';
		strip.textContent = '';
		metrics.forEach( function ( metric ) {
			var item = el( 'div', 'kdna-ei-kpi' + ( cards ? ' kdna-ei-card' : '' ) );
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
	 * Formats a value for a chart axis: short money and numbers ("12K"),
	 * percentages with a % sign.
	 *
	 * @param {number} value Value.
	 * @param {string} kind  Metric format.
	 * @return {string}
	 */
	function axisValue( value, kind ) {
		if ( kind === 'percent' ) {
			return format.number( value, 0 ) + '%';
		}
		if ( kind === 'ratio' ) {
			return format.number( value, 1 );
		}
		return format.compact( value );
	}

	/**
	 * Chart.js options for a chart of metrics over time, matching the admin
	 * app's Performance chart.
	 *
	 * @param {Object}   series Response from /timeseries.
	 * @param {string[]} keys   Metrics to draw: one (with its comparison) or several together.
	 * @param {Object}   opts   comparison (mode), compare (bool), type (area, line or bar).
	 * @return {Object}
	 */
	parts.chartOptions = function ( series, keys, opts ) {
		opts = opts || {};
		keys = keys.filter( function ( key ) {
			return series.series[ key ];
		} );
		var together = keys.length > 1;
		var first = series.series[ keys[ 0 ] ];
		var kindOf = function ( key ) {
			var f = series.series[ key ] && series.series[ key ].format;
			return f === 'currency' || f === 'percent' || f === 'ratio' || f === 'days' ? f : 'number';
		};
		var compareName = KDNAEI.kpi.comparisonLabel( opts.comparison );
		var area = opts.type !== 'line';
		var list;

		if ( together ) {
			list = keys.map( function ( key, i ) {
				return { label: series.series[ key ].label, data: series.series[ key ].current.slice(), colour: 'line' + ( i + 1 ), fill: area && i === 0 };
			} );
		} else {
			list = [ { label: t.thisPeriod, data: first.current.slice(), colour: 'line1', fill: area } ];
			if ( opts.compare !== false && first.previous && series.previous_buckets ) {
				list.push( { label: compareName, data: first.current.map( function ( v, i ) {
					return first.previous[ i ] === undefined ? null : first.previous[ i ];
				} ), colour: 'line2', fill: false } );
			}
		}

		return {
			labels: series.buckets.map( function ( b ) {
				return b.key;
			} ),
			series: list,
			stacked: false,
			maxXTicks: series.granularity === 'day' && series.buckets.length > 20 ? 5 : 7,
			xLabel: function ( i ) {
				return series.buckets[ i ] ? dates.axis( series.buckets, i, series.granularity ) : '';
			},
			yLabel: function ( value ) {
				return axisValue( value, kindOf( keys[ 0 ] ) );
			},
			title: function ( i ) {
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
			},
			seriesLabel: function ( datasetIndex, i ) {
				if ( together ) {
					return list[ datasetIndex ].label;
				}
				if ( datasetIndex === 0 ) {
					return t.thisPeriod;
				}
				var bucket = series.previous_buckets && series.previous_buckets[ i ];
				return bucket ? dates.text( bucket.start, { day: 'numeric', month: 'short', year: 'numeric' } ) : compareName;
			},
			value: function ( v, i, datasetIndex ) {
				return format.metric( v, kindOf( together ? keys[ datasetIndex || 0 ] : keys[ 0 ] ) );
			},
			footer: function ( i ) {
				if ( together || list.length < 2 ) {
					return null;
				}
				var now = first.current[ i ];
				var before = first.previous ? first.previous[ i ] : null;
				if ( before === null || before === undefined || ! before || now === null ) {
					return null;
				}
				var change = ( now - before ) / Math.abs( before ) * 100;
				var good = lowerIsBetter.indexOf( keys[ 0 ] ) === -1 ? change >= 0 : change <= 0;
				return { text: ( change >= 0 ? '+' : '−' ) + format.number( Math.abs( change ), 1 ) + '%', className: good ? 'is-good' : 'is-bad' };
			},
		};
	};

	/**
	 * Draws (or redraws) a chart card: the chart itself and its legend.
	 *
	 * @param {HTMLElement} card   The .kdna-ei-performance card.
	 * @param {Object}      series Response from /timeseries.
	 * @param {string[]}    keys   Metrics to draw.
	 * @param {Object}      opts   comparison, compare, type (area, line or bar), held (keeps the chart).
	 */
	parts.chart = function ( card, series, keys, opts ) {
		var canvas = card.querySelector( '[data-kdna-ei-part="chart"]' );
		if ( ! canvas || ! series || ! charts || ! charts.available ) {
			return;
		}
		var options = parts.chartOptions( series, keys, opts );
		var held = opts.held;
		var bar = opts.type === 'bar';
		if ( held.chart && held.chart.canvas === canvas ) {
			if ( bar ) {
				charts.updateBarChart( held.chart, options );
			} else {
				charts.updateLineChart( held.chart, options );
			}
		} else {
			held.chart = bar ? charts.barChart( canvas, options ) : charts.lineChart( canvas, options );
		}

		// The legend: this period and the comparison, or one entry per metric.
		var legend = card.querySelector( '[data-kdna-ei-part="legend"]' );
		if ( ! legend ) {
			return;
		}
		if ( options.series.length > 2 || ( keys.length > 1 && options.series.length === keys.length ) ) {
			var colours = charts.tokens( canvas );
			legend.textContent = '';
			options.series.forEach( function ( item ) {
				var entry = el( 'span', 'kdna-ei-chart-legend__item' );
				var dot = el( 'span', 'kdna-ei-chart-legend__dot' );
				dot.style.background = colours[ item.colour ] || item.colour;
				entry.appendChild( dot );
				entry.appendChild( el( 'span', '', item.label ) );
				legend.appendChild( entry );
			} );
			return;
		}
		var current = legend.querySelector( '[data-kdna-ei-legend="current"]' );
		if ( current ) {
			current.textContent = series.series[ keys[ 0 ] ] ? series.series[ keys[ 0 ] ].label : '';
		}
		var compare = legend.querySelector( '[data-kdna-ei-legend-compare]' );
		if ( compare ) {
			compare.hidden = options.series.length < 2;
			compare.querySelector( '[data-kdna-ei-legend="compare"]' ).textContent = KDNAEI.kpi.comparisonLabel( opts.comparison );
		}
		card.querySelectorAll( '[data-kdna-ei-series]' ).forEach( function ( button ) {
			button.setAttribute( 'aria-pressed', button.getAttribute( 'data-kdna-ei-series' ) === keys[ 0 ] ? 'true' : 'false' );
		} );
	};

	/**
	 * A donut and legend: one row per part with its value and share, and
	 * the total (or a chosen figure) in the middle.
	 *
	 * @param {HTMLElement} card Card with a donut canvas and legend table.
	 * @param {Object[]}    rows Parts: { label, value }.
	 * @param {Object}      opts format (metric format), centre (number for the
	 *                           middle; the total when left out), held.
	 */
	parts.breakdown = function ( card, rows, opts ) {
		var kind = opts.format || 'number';
		var total = rows.reduce( function ( sum, row ) {
			return sum + Math.max( 0, Number( row.value ) || 0 );
		}, 0 );
		var body = card.querySelector( '[data-kdna-ei-part="legend-rows"]' );
		if ( body ) {
			body.textContent = '';
			rows.forEach( function ( row, i ) {
				var value = Math.max( 0, Number( row.value ) || 0 );
				var pct = total ? Math.round( value / total * 100 ) : 0;
				var tr = el( 'tr' );
				var th = el( 'th' );
				th.setAttribute( 'scope', 'row' );
				var dot = el( 'span', 'kdna-ei-legend__dot' );
				dot.style.background = 'var(--kdna-ei-segment-' + ( i + 1 ) + ', var(--kdna-ei-' + segmentVars[ i % segmentVars.length ] + '))';
				th.appendChild( dot );
				th.appendChild( el( 'span', '', row.label ) );
				tr.appendChild( th );
				tr.appendChild( el( 'td', 'is-numeric kdna-ei-legend__value', format.metric( row.value, kind, kind === 'currency' ? 0 : undefined ) ) );
				tr.appendChild( el( 'td', 'is-numeric kdna-ei-legend__percent', ( value > 0 && pct === 0 ? '<1' : pct ) + '%' ) );
				body.appendChild( tr );
			} );
			if ( ! rows.length ) {
				var empty = el( 'tr' );
				var cell = el( 'td', 'kdna-ei-list-empty', w.noBreakdown );
				cell.colSpan = 3;
				empty.appendChild( cell );
				body.appendChild( empty );
			}
		}
		var number = card.querySelector( '[data-kdna-ei-part="donut-number"]' );
		if ( number ) {
			var centre = opts.centre === undefined ? total : opts.centre;
			number.textContent = kind === 'currency' ? format.money( centre, 0 ) : format.metric( centre, kind );
		}
		var canvas = card.querySelector( '[data-kdna-ei-part="donut"]' );
		if ( ! canvas || ! charts || ! charts.available ) {
			return;
		}
		var values = rows.map( function ( row ) {
			return Math.max( 0, Number( row.value ) || 0 );
		} );
		var names = rows.map( function ( row, i ) {
			return segmentColours[ i % segmentColours.length ];
		} );
		if ( ! total ) {
			values = [ 1 ];
			names = [ 'border' ];
		}
		var held = opts.held;
		if ( held.donut && held.donut.canvas === canvas && held.donut.data.datasets[ 0 ].data.length === values.length ) {
			held.donut.$kdna.colours = names;
			held.donut.data.datasets[ 0 ].data = values;
			held.donut.data.datasets[ 0 ].backgroundColor = names.map( function ( name, i ) {
				var tk = charts.tokens( canvas );
				return tk[ 'seg' + ( i + 1 ) ] || tk[ name ] || name;
			} );
			held.donut.update();
			return;
		}
		if ( held.donut ) {
			held.donut.destroy();
		}
		held.donut = charts.donutChart( canvas, {
			labels: rows.map( function ( row ) {
				return row.label;
			} ),
			values: values,
			colours: names,
			cutout: '70%',
		} );
	};

	/**
	 * Inventory status as a breakdown (in stock, low stock, out of stock),
	 * with the in-stock count in the middle.
	 *
	 * @param {HTMLElement} card   Breakdown card.
	 * @param {Object}      status in_stock, low_stock, out_of_stock.
	 * @param {Object}      held   Where the chart is kept between redraws.
	 */
	parts.inventory = function ( card, status, held ) {
		parts.breakdown( card, [
			{ label: t.inStockLegend, value: status.in_stock },
			{ label: t.lowStock, value: status.low_stock },
			{ label: t.outOfStock, value: status.out_of_stock },
		], { format: 'number', centre: status.in_stock, held: held } );
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
	 * Draws a hero card: top products, profit breakdown or goals tracker.
	 *
	 * @param {HTMLElement} card The .kdna-ei-hero card.
	 * @param {Object}      data products, waterfall and goal.
	 * @param {Object}      opts hero, sort, count, sample (sort the sample here), link.
	 */
	parts.hero = function ( card, data, opts ) {
		var body = card.querySelector( '[data-kdna-ei-part="hero"]' );
		if ( ! body ) {
			return;
		}
		if ( opts.hero === 'profit_breakdown' ) {
			parts.waterfall( body, data.waterfall || [] );
		} else if ( opts.hero === 'goals' ) {
			parts.goal( body, data.goal || { metric: 'revenue', value: 0, target: 0 }, opts.link || '' );
		} else {
			var rows = ( data.products || [] ).slice();
			if ( opts.sample ) {
				rows.sort( function ( a, b ) {
					return b[ opts.sort ] - a[ opts.sort ];
				} );
			}
			parts.topProducts( body, rows.slice( 0, opts.count || 5 ), opts.sort );
		}
		card.querySelectorAll( '[data-kdna-ei-sort]' ).forEach( function ( tab ) {
			tab.setAttribute( 'aria-selected', tab.getAttribute( 'data-kdna-ei-sort' ) === opts.sort ? 'true' : 'false' );
		} );
	};

	/**
	 * Fetches what a hero card needs.
	 *
	 * @param {string} hero  top_products, profit_breakdown or goals.
	 * @param {Object} query Range query.
	 * @param {string} sort  profit or revenue.
	 * @param {number} count How many products.
	 * @return {Promise<Object>} products, waterfall and goal.
	 */
	parts.fetchHero = function ( hero, query, sort, count ) {
		if ( hero === 'profit_breakdown' ) {
			return read( 'profit', query ).then( function ( r ) {
				return { waterfall: r.data.waterfall };
			} );
		}
		if ( hero === 'goals' ) {
			var goal = config.hero || {};
			var metricKey = { revenue: 'net_revenue', profit: 'net_profit', orders: 'orders' }[ goal.goal_metric ] || 'net_revenue';
			return read( 'summary', { preset: 'this_month', compare: 'none', metrics: metricKey } ).then( function ( r ) {
				return { goal: {
					metric: goal.goal_metric || 'revenue',
					value: Number( r.data.metrics[ 0 ].value || 0 ),
					target: Number( ( goal.goal_targets || {} )[ goal.goal_metric || 'revenue' ] || 0 ),
				} };
			} );
		}
		return read( 'products', Object.assign( {}, query, { per_page: count || 5, orderby: sort } ) ).then( function ( r ) {
			return { products: r.data.rows };
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

	/**
	 * Formats one table cell by its column format.
	 *
	 * @param {*}      value  Value.
	 * @param {string} kind   text, date, currency, number, percent, ratio or days.
	 * @return {string}
	 */
	function cellText( value, kind ) {
		if ( value === null || value === undefined || value === '' ) {
			return '-';
		}
		if ( kind === 'text' ) {
			return String( value );
		}
		if ( kind === 'date' ) {
			return dates.text( value, { day: 'numeric', month: 'short', year: 'numeric' } );
		}
		if ( kind === 'days' ) {
			return sprintf( ( config.i18n && config.i18n.days ) || '%s days', format.number( value, 0 ) );
		}
		if ( kind === 'number' ) {
			return format.number( value, Math.round( value ) === Number( value ) ? 0 : 1 );
		}
		return format.metric( value, kind );
	}

	/**
	 * Sorts rows by a column: numbers by size, words alphabetically, and
	 * empty values always last.
	 *
	 * @param {Object[]} rows  Rows.
	 * @param {string}   key   Column key.
	 * @param {string}   order asc or desc.
	 * @return {Object[]} A sorted copy.
	 */
	parts.sortRows = function ( rows, key, order ) {
		var dir = order === 'asc' ? 1 : -1;
		return rows.slice().sort( function ( a, b ) {
			var x = a[ key ];
			var y = b[ key ];
			var xEmpty = x === null || x === undefined || x === '';
			var yEmpty = y === null || y === undefined || y === '';
			if ( xEmpty || yEmpty ) {
				return xEmpty === yEmpty ? 0 : ( xEmpty ? 1 : -1 );
			}
			if ( typeof x === 'number' && typeof y === 'number' ) {
				return ( x - y ) * dir;
			}
			return String( x ).localeCompare( String( y ), config.locale || undefined, { numeric: true } ) * dir;
		} );
	};

	/**
	 * A sortable report table: headings (buttons when sortable), then one row
	 * per item, with thumbnails, links and badges in the first column.
	 *
	 * @param {HTMLElement} wrap    The [data-kdna-ei-part="table"] element.
	 * @param {Object[]}    columns { key, label, format, sort }.
	 * @param {Object[]}    rows    Rows.
	 * @param {Object}      opts    sort, order, sortable, thumbs, links, caption.
	 */
	parts.table = function ( wrap, columns, rows, opts ) {
		var table = el( 'table', 'kdna-ei-table kdna-ei-data-table' );
		if ( opts.caption ) {
			table.appendChild( el( 'caption', 'kdna-ei-visually-hidden', opts.caption ) );
		}
		var head = el( 'thead' );
		var headRow = el( 'tr' );
		columns.forEach( function ( column, i ) {
			var th = el( 'th', i > 0 && column.format !== 'text' ? 'is-numeric' : '' );
			th.setAttribute( 'scope', 'col' );
			if ( opts.sortable && column.sort ) {
				var active = opts.sort === column.key;
				th.setAttribute( 'aria-sort', active ? ( opts.order === 'asc' ? 'ascending' : 'descending' ) : 'none' );
				var button = el( 'button', 'kdna-ei-sort' + ( active ? ' is-active' : '' ) );
				button.type = 'button';
				button.setAttribute( 'data-kdna-ei-sort-key', column.key );
				button.appendChild( el( 'span', '', column.label ) );
				var arrow = icon( active && opts.order === 'asc' ? 'arrow-up' : 'arrow-down', 'kdna-ei-sort-icon' );
				button.appendChild( arrow );
				if ( active ) {
					button.appendChild( el( 'span', 'kdna-ei-visually-hidden', opts.order === 'asc' ? w.sortedAsc : w.sortedDesc ) );
				}
				th.appendChild( button );
			} else {
				th.textContent = column.label;
			}
			headRow.appendChild( th );
		} );
		head.appendChild( headRow );
		table.appendChild( head );

		var body = el( 'tbody' );
		rows.forEach( function ( row ) {
			var tr = el( 'tr' );
			columns.forEach( function ( column, i ) {
				var td = el( i === 0 ? 'th' : 'td', i > 0 && column.format !== 'text' ? 'is-numeric kdna-ei-num' : '' );
				if ( i === 0 ) {
					td.setAttribute( 'scope', 'row' );
					var cell = el( 'span', 'kdna-ei-cell-main' );
					if ( opts.thumbs && row.thumbnail !== undefined ) {
						var thumb = el( 'span', 'kdna-ei-thumb' );
						if ( row.thumbnail ) {
							var img = el( 'img' );
							img.src = row.thumbnail;
							img.alt = '';
							img.loading = 'lazy';
							thumb.appendChild( img );
						} else {
							thumb.appendChild( icon( 'box' ) );
						}
						cell.appendChild( thumb );
					}
					var text = cellText( row[ column.key ], column.format ) + ( row.variation ? ', ' + row.variation : '' );
					var link = opts.links ? ( row.edit_url || row.profile_url || '' ) : '';
					var name;
					if ( link ) {
						name = el( 'a', 'kdna-ei-cell-name', text );
						name.href = link;
					} else {
						name = el( 'span', 'kdna-ei-cell-name', text );
					}
					cell.appendChild( name );
					if ( row.guest && text !== w.guest ) {
						cell.appendChild( el( 'span', 'kdna-ei-badge kdna-ei-badge--plain', w.guest ) );
					}
					if ( row.reorder_now ) {
						cell.appendChild( el( 'span', 'kdna-ei-badge kdna-ei-badge--warning', w.reorderNow ) );
					}
					td.appendChild( cell );
				} else {
					td.textContent = cellText( row[ column.key ], column.format );
				}
				tr.appendChild( td );
			} );
			body.appendChild( tr );
		} );
		if ( ! rows.length ) {
			var tr = el( 'tr' );
			var td = el( 'td', 'kdna-ei-list-empty', w.noRows );
			td.colSpan = columns.length;
			tr.appendChild( td );
			body.appendChild( tr );
		}
		table.appendChild( body );
		wrap.textContent = '';
		wrap.appendChild( table );
	};

	/**
	 * The profit and loss statement: one row per line of the waterfall, one
	 * column per month and a total column, with net margin at the bottom.
	 *
	 * @param {HTMLElement} wrap    The [data-kdna-ei-part="table"] element.
	 * @param {Object}      profit  Response from /profit.
	 * @param {string}      caption Table caption.
	 */
	parts.pnl = function ( wrap, profit, caption ) {
		var months = profit.months || [];
		var columns = [ { key: 'label', label: w.line, format: 'text' } ];
		months.forEach( function ( month, i ) {
			columns.push( { key: 'm' + i, label: dates.text( month.start, { month: 'short', year: 'numeric' } ), format: 'currency' } );
		} );
		columns.push( { key: 'total', label: w.total, format: 'currency' } );

		var rows = ( profit.waterfall || [] ).map( function ( line ) {
			var row = { label: line.label, total: line.amount, type: line.type };
			months.forEach( function ( month, i ) {
				row[ 'm' + i ] = month.lines[ line.key ];
			} );
			return row;
		} );
		var lines = {};
		( profit.waterfall || [] ).forEach( function ( line ) {
			lines[ line.key ] = line.amount;
		} );
		var margin = { label: w.netMargin, type: 'margin', total: lines.net_revenue ? lines.net_profit / lines.net_revenue * 100 : null };
		months.forEach( function ( month, i ) {
			margin[ 'm' + i ] = month.lines.net_margin;
		} );
		rows.push( margin );

		parts.table( wrap, columns, [], { caption: caption } );
		var body = wrap.querySelector( 'tbody' );
		body.textContent = '';
		rows.forEach( function ( row ) {
			var tr = el( 'tr', 'is-' + row.type );
			columns.forEach( function ( column, i ) {
				var cell = el( i === 0 ? 'th' : 'td', i > 0 ? 'is-numeric kdna-ei-num' : '' );
				if ( i === 0 ) {
					cell.setAttribute( 'scope', 'row' );
					cell.textContent = row.label;
				} else {
					cell.textContent = cellText( row[ column.key ], row.type === 'margin' ? 'percent' : 'currency' );
					if ( row.type !== 'margin' && row[ column.key ] < 0 ) {
						cell.classList.add( 'is-negative' );
					}
				}
				tr.appendChild( cell );
			} );
			body.appendChild( tr );
		} );
	};

	/*
	 * ---------------------------------------------------------------------
	 * The widget lifecycle every widget with figures shares
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Sets up a widget that shows figures. Handles everything the widgets
	 * have in common: the editor's preview states, sample data, following
	 * the page's date range or a fixed one, loading, empty and error states,
	 * Try again, the theme toggle and the optional header date picker.
	 *
	 * The widget itself only says what to fetch and how to draw it.
	 *
	 * @param {HTMLElement} root     Widget root.
	 * @param {Object}      settings From data-kdna-ei-settings.
	 * @param {Object}      spec     fetch( ctx ) returns a Promise of the data;
	 *                               draw( ctx ) draws ctx.data; click( ctx, event )
	 *                               handles buttons and returns true when it did.
	 * @return {Object} The widget's context.
	 */
	function dataWidget( root, settings, spec ) {
		var range = settings.range || { follow: true };
		var requestId = 0;
		var ctx = {
			root: root,
			settings: settings,
			held: {},
			data: null,
			sample: settings.state === 'sample',
			own: range.follow ? null : { preset: range.preset, compare: range.compare, start: '', end: '' },
		};

		/**
		 * The range this widget shows.
		 *
		 * @return {Object}
		 */
		ctx.range = function () {
			return ctx.own || KDNAEI.pageRange.get();
		};

		/**
		 * REST query parameters for this widget's range.
		 *
		 * @return {Object}
		 */
		ctx.query = function () {
			return rangeQuery( ctx.range() );
		};

		/**
		 * Switches the widget between loading, ready, empty and error.
		 *
		 * @param {string} state State.
		 */
		ctx.setState = function ( state ) {
			root.setAttribute( 'data-kdna-ei-state', state );
			root.setAttribute( 'aria-busy', state === 'loading' ? 'true' : 'false' );
		};

		/**
		 * Loads (or, in the editor, fakes) the figures and draws them.
		 */
		ctx.load = function () {
			var id = ++requestId;
			if ( [ 'loading', 'empty', 'error' ].indexOf( settings.state ) !== -1 ) {
				ctx.setState( settings.state );
				return;
			}
			ctx.setState( 'loading' );
			var source = ctx.sample ? Promise.resolve( settings.sample ) : Promise.all( [ spec.fetch( ctx ), read( 'status' ) ] ).then( function ( results ) {
				return Object.assign( {}, results[ 0 ], { status: results[ 1 ] || {} } );
			} );
			source.then( function ( result ) {
				if ( id !== requestId ) {
					return;
				}
				ctx.data = result;
				if ( ! ctx.sample && result.status && Number( result.status.orders_processed ) === 0 ) {
					ctx.setState( 'empty' );
					return;
				}
				ctx.setState( 'ready' );
				spec.draw( ctx );
			} ).catch( function ( error ) {
				if ( id !== requestId ) {
					return;
				}
				var message = root.querySelector( '[data-kdna-ei-error]' );
				if ( message && error && error.message ) {
					message.textContent = error.message;
				}
				ctx.setState( 'error' );
			} );
		};

		// Buttons inside the widget.
		root.addEventListener( 'click', function ( event ) {
			if ( spec.click && spec.click( ctx, event ) ) {
				return;
			}
			if ( event.target.closest( '[data-kdna-ei-action="retry"]' ) ) {
				ctx.load();
			}
		} );

		// The optional date range picker in the header.
		var box = root.querySelector( '[data-kdna-ei-range]' );
		var picker = null;
		if ( box ) {
			picker = rangePicker( box, {
				getRange: ctx.range,
				onChange: function ( change ) {
					if ( ctx.own ) {
						ctx.own = Object.assign( {}, ctx.own, change );
						ctx.load();
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
			if ( ! ctx.own && ! ctx.sample ) {
				ctx.load();
			}
		} );

		setupTheme( root, settings );
		ctx.load();
		return ctx;
	}

	/*
	 * ---------------------------------------------------------------------
	 * The widgets
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Insights Dashboard: alerts, KPI strip, chart, inventory and hero card.
	 *
	 * @param {HTMLElement} root     Widget root.
	 * @param {Object}      settings From data-kdna-ei-settings.
	 */
	function dashboard( root, settings ) {
		var seriesKey = settings.series || 'net_revenue';
		var sort = settings.sort || 'profit';
		var panels = settings.panels || {};

		/**
		 * Draws the chart for the chosen series.
		 *
		 * @param {Object} ctx Widget context.
		 */
		function drawChart( ctx ) {
			var card = root.querySelector( '.kdna-ei-performance' );
			if ( card && ctx.data.timeseries ) {
				parts.chart( card, ctx.data.timeseries, [ seriesKey ], { comparison: ctx.range().compare, type: 'area', held: ctx.held } );
			}
		}

		/**
		 * Draws the hero card.
		 *
		 * @param {Object} ctx Widget context.
		 */
		function drawHero( ctx ) {
			var card = root.querySelector( '.kdna-ei-hero' );
			if ( card ) {
				parts.hero( card, ctx.data, { hero: settings.hero, sort: sort, count: 5, sample: ctx.sample, link: settings.links ? config.adminUrl + '#/settings' : '' } );
			}
		}

		dataWidget( root, settings, {
			fetch: function ( ctx ) {
				var query = ctx.query();
				var jobs = [
					panels.kpis || panels.alerts ? read( 'summary', Object.assign( {}, query, { metrics: [ 'net_revenue', 'net_profit', 'orders', 'net_margin', settings.fifth ].join( ',' ) } ) ) : null,
					panels.chart ? read( 'timeseries', Object.assign( {}, query, { metrics: 'net_revenue,net_profit,orders' } ) ) : null,
					panels.inventory || panels.alerts ? read( 'inventory', query ) : null,
					panels.hero ? parts.fetchHero( settings.hero, query, sort, 5 ) : null,
				];
				return Promise.all( jobs ).then( function ( r ) {
					return Object.assign( {
						summary: r[ 0 ] ? r[ 0 ].data : null,
						estimates: r[ 0 ] ? r[ 0 ].meta.estimates : {},
						timeseries: r[ 1 ] ? r[ 1 ].data : null,
						inventory: r[ 2 ] ? { status: r[ 2 ].data.status } : null,
					}, r[ 3 ] || {} );
				} );
			},
			draw: function ( ctx ) {
				var data = ctx.data;
				var strip = root.querySelector( '[data-kdna-ei-part="kpis"]' );
				if ( strip && data.summary ) {
					parts.kpis( strip, data.summary.metrics, KDNAEI.kpi.comparisonLabel( ctx.range().compare ) );
				}
				drawChart( ctx );
				var stock = root.querySelector( '.kdna-ei-inventory-card' );
				if ( stock && data.inventory ) {
					parts.inventory( stock, data.inventory.status, ctx.held );
				}
				drawHero( ctx );
				var alerts = root.querySelector( '[data-kdna-ei-part="alerts"]' );
				if ( alerts ) {
					parts.alerts( alerts, data.estimates, data.status, data.inventory && data.inventory.status, settings.links ? config.adminUrl : '' );
				}
			},
			click: function ( ctx, event ) {
				var series = event.target.closest( '[data-kdna-ei-series]' );
				var tab = event.target.closest( '[data-kdna-ei-sort]' );
				if ( series && ctx.data ) {
					seriesKey = series.getAttribute( 'data-kdna-ei-series' );
					drawChart( ctx );
					return true;
				}
				if ( tab && sort !== tab.getAttribute( 'data-kdna-ei-sort' ) ) {
					sort = tab.getAttribute( 'data-kdna-ei-sort' );
					if ( ctx.sample ) {
						drawHero( ctx );
					} else {
						ctx.load();
					}
					return true;
				}
				return false;
			},
		} );
	}

	/**
	 * Insights KPI Cards: any metrics as a strip or separate cards.
	 *
	 * @param {HTMLElement} root     Widget root.
	 * @param {Object}      settings From data-kdna-ei-settings.
	 */
	function kpiCards( root, settings ) {
		dataWidget( root, settings, {
			fetch: function ( ctx ) {
				return read( 'summary', Object.assign( {}, ctx.query(), { metrics: settings.metrics.join( ',' ) } ) ).then( function ( r ) {
					return { summary: r.data };
				} );
			},
			draw: function ( ctx ) {
				var strip = root.querySelector( '[data-kdna-ei-part="kpis"]' );
				if ( strip && ctx.data.summary ) {
					parts.kpis( strip, ctx.data.summary.metrics, KDNAEI.kpi.comparisonLabel( ctx.range().compare ) );
				}
			},
		} );
	}

	/**
	 * Insights Chart: a line, area or bar chart of metrics over time.
	 *
	 * @param {HTMLElement} root     Widget root.
	 * @param {Object}      settings From data-kdna-ei-settings.
	 */
	function chartWidget( root, settings ) {
		var metrics = settings.metrics || [ 'net_revenue' ];
		var current = metrics[ 0 ];

		/**
		 * Draws the chosen metric, or every metric together.
		 *
		 * @param {Object} ctx Widget context.
		 */
		function draw( ctx ) {
			var card = root.querySelector( '.kdna-ei-performance' );
			if ( card && ctx.data.timeseries ) {
				parts.chart( card, ctx.data.timeseries, settings.display === 'together' ? metrics : [ current ], {
					comparison: ctx.range().compare,
					compare: settings.compare,
					type: settings.type,
					held: ctx.held,
				} );
			}
		}

		dataWidget( root, settings, {
			fetch: function ( ctx ) {
				var query = Object.assign( {}, ctx.query(), { metrics: metrics.join( ',' ), granularity: settings.granularity || 'auto' } );
				if ( ! settings.compare || settings.display === 'together' ) {
					query.compare = 'none';
				}
				return read( 'timeseries', query ).then( function ( r ) {
					return { timeseries: r.data };
				} );
			},
			draw: draw,
			click: function ( ctx, event ) {
				var series = event.target.closest( '[data-kdna-ei-series]' );
				if ( series && ctx.data ) {
					current = series.getAttribute( 'data-kdna-ei-series' );
					draw( ctx );
					return true;
				}
				return false;
			},
		} );
	}

	/**
	 * Insights Breakdown: a donut and legend for stock, costs, channels or
	 * new and returning customers.
	 *
	 * @param {HTMLElement} root     Widget root.
	 * @param {Object}      settings From data-kdna-ei-settings.
	 */
	function breakdownWidget( root, settings ) {
		var routes = { inventory: 'inventory', costs: 'profit', channels: 'marketing', customers: 'customers' };
		var route = routes[ settings.source ] || 'inventory';

		dataWidget( root, settings, {
			fetch: function ( ctx ) {
				return read( route, ctx.query() ).then( function ( r ) {
					var data = {};
					data[ route ] = r.data;
					return data;
				} );
			},
			draw: function ( ctx ) {
				var card = root.querySelector( '.kdna-ei-breakdown-card' );
				var data = ctx.data[ route ] || {};
				if ( ! card ) {
					return;
				}
				if ( settings.source === 'costs' ) {
					parts.breakdown( card, ( data.cost_breakdown || [] ).map( function ( cost ) {
						return { label: cost.label, value: cost.amount };
					} ), { format: 'currency', held: ctx.held } );
				} else if ( settings.source === 'channels' ) {
					parts.breakdown( card, ( data.channels || [] ).map( function ( channel ) {
						return { label: channel.label, value: channel.spend };
					} ), { format: 'currency', held: ctx.held } );
				} else if ( settings.source === 'customers' ) {
					var byKey = {};
					( data.metrics || [] ).forEach( function ( metric ) {
						byKey[ metric.key ] = metric.value;
					} );
					var revenue = settings.measure === 'revenue';
					parts.breakdown( card, [
						{ label: w.newCustomers, value: revenue ? byKey.new_customer_revenue : byKey.new_customers },
						{ label: w.returning, value: revenue ? byKey.returning_customer_revenue : byKey.returning_customers },
					], { format: revenue ? 'currency' : 'number', held: ctx.held } );
				} else {
					parts.inventory( card, data.status || { in_stock: 0, low_stock: 0, out_of_stock: 0 }, ctx.held );
				}
			},
		} );
	}

	/**
	 * Insights Table: products, customers, campaigns, low stock or the
	 * profit and loss statement, sortable, with an optional CSV button.
	 *
	 * @param {HTMLElement} root     Widget root.
	 * @param {Object}      settings From data-kdna-ei-settings.
	 */
	function tableWidget( root, settings ) {
		var sort = settings.sort;
		var order = settings.order || 'desc';
		var serverSort = [ 'name', 'units', 'revenue', 'cost', 'profit', 'margin', 'refund_rate', 'orders' ];
		var caption = ( root.querySelector( '.kdna-ei-card__title' ) || {} ).textContent || '';

		/**
		 * The rows for the chosen table from the loaded data.
		 *
		 * @param {Object} data Loaded data.
		 * @return {Object[]}
		 */
		function rowsFrom( data ) {
			switch ( settings.source ) {
				case 'customers':
					return ( data.customers && data.customers.top_customers ) || [];
				case 'campaigns':
					return ( data.marketing && data.marketing.campaigns ) || [];
				case 'low_stock':
					return ( data.inventory && data.inventory.low_stock ) || [];
				default:
					return ( data.products && data.products.rows ) || [];
			}
		}

		/**
		 * Draws the table from the loaded data.
		 *
		 * @param {Object} ctx Widget context.
		 */
		function draw( ctx ) {
			var wrap = root.querySelector( '[data-kdna-ei-part="table"]' );
			if ( ! wrap ) {
				return;
			}
			if ( settings.source === 'pnl' ) {
				parts.pnl( wrap, ctx.data.profit || {}, caption );
				return;
			}
			var rows = parts.sortRows( rowsFrom( ctx.data ), sort, order ).slice( 0, settings.rows || 10 );
			parts.table( wrap, settings.columns, rows, {
				sort: sort,
				order: order,
				sortable: settings.sortable,
				thumbs: settings.thumbs,
				links: settings.links,
				caption: caption,
			} );
		}

		/**
		 * Downloads the CSV for this table and the widget's dates.
		 *
		 * @param {Object}      ctx    Widget context.
		 * @param {HTMLElement} button The export button.
		 */
		function exportCsv( ctx, button ) {
			var label = button.querySelector( 'span:last-child' );
			var text = label.textContent;
			if ( settings.editor || ! config.canView || ! config.restUrl ) {
				button.title = w.exportEditor;
				return;
			}
			var query = ctx.query();
			if ( settings.source === 'products' ) {
				query.orderby = serverSort.indexOf( sort ) !== -1 ? sort : 'profit';
				query.order = order;
			}
			button.disabled = true;
			label.textContent = w.exporting;
			KDNAEI.exportCsv( settings.export, query ).catch( function () {
				button.title = w.exportFailed;
				window.alert( w.exportFailed ); // eslint-disable-line no-alert
			} ).then( function () {
				button.disabled = false;
				label.textContent = text;
			} );
		}

		dataWidget( root, settings, {
			fetch: function ( ctx ) {
				var query = ctx.query();
				switch ( settings.source ) {
					case 'customers':
						return read( 'customers', query ).then( function ( r ) {
							return { customers: r.data };
						} );
					case 'campaigns':
						return read( 'marketing', query ).then( function ( r ) {
							return { marketing: r.data };
						} );
					case 'low_stock':
						return read( 'inventory', query ).then( function ( r ) {
							return { inventory: r.data };
						} );
					case 'pnl':
						return read( 'profit', query ).then( function ( r ) {
							return { profit: r.data };
						} );
					default:
						return read( 'products', Object.assign( {}, query, {
							per_page: settings.rows || 10,
							orderby: serverSort.indexOf( sort ) !== -1 ? sort : 'profit',
							order: order,
						} ) ).then( function ( r ) {
							return { products: r.data };
						} );
				}
			},
			draw: draw,
			click: function ( ctx, event ) {
				var heading = event.target.closest( '[data-kdna-ei-sort-key]' );
				var exportButton = event.target.closest( '[data-kdna-ei-action="export"]' );
				if ( heading ) {
					var key = heading.getAttribute( 'data-kdna-ei-sort-key' );
					var column = ( settings.columns || [] ).filter( function ( item ) {
						return item.key === key;
					} )[ 0 ];
					if ( key === sort ) {
						order = order === 'desc' ? 'asc' : 'desc';
					} else {
						// Words start A to Z; numbers start with the highest.
						order = column && ( column.format === 'text' || column.format === 'date' ) ? 'asc' : 'desc';
					}
					sort = key;
					// Products are ranked by the server, so the right ones are in the top rows.
					if ( settings.source === 'products' && ! ctx.sample && serverSort.indexOf( key ) !== -1 ) {
						ctx.load();
					} else if ( ctx.data ) {
						draw( ctx );
					}
					var again = root.querySelector( '[data-kdna-ei-sort-key="' + key + '"]' );
					if ( again ) {
						again.focus();
					}
					return true;
				}
				if ( exportButton ) {
					exportCsv( ctx, exportButton );
					return true;
				}
				return false;
			},
		} );
	}

	/**
	 * Insights Hero Card: top products, profit breakdown or goals tracker.
	 *
	 * @param {HTMLElement} root     Widget root.
	 * @param {Object}      settings From data-kdna-ei-settings.
	 */
	function heroCard( root, settings ) {
		var sort = settings.sort || 'profit';

		/**
		 * Draws the card.
		 *
		 * @param {Object} ctx Widget context.
		 */
		function draw( ctx ) {
			var card = root.querySelector( '.kdna-ei-hero' );
			if ( card ) {
				parts.hero( card, ctx.data, { hero: settings.hero, sort: sort, count: settings.count, sample: ctx.sample, link: settings.links ? config.adminUrl + '#/settings' : '' } );
			}
		}

		dataWidget( root, settings, {
			fetch: function ( ctx ) {
				return parts.fetchHero( settings.hero, ctx.query(), sort, settings.count );
			},
			draw: draw,
			click: function ( ctx, event ) {
				var tab = event.target.closest( '[data-kdna-ei-sort]' );
				if ( tab && sort !== tab.getAttribute( 'data-kdna-ei-sort' ) ) {
					sort = tab.getAttribute( 'data-kdna-ei-sort' );
					if ( ctx.sample ) {
						draw( ctx );
					} else {
						ctx.load();
					}
					return true;
				}
				return false;
			},
		} );
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

	var types = {
		dashboard: dashboard,
		'date-range': dateRange,
		'kpi-cards': kpiCards,
		chart: chartWidget,
		breakdown: breakdownWidget,
		table: tableWidget,
		'hero-card': heroCard,
	};
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
