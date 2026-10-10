/**
 * KDNA eCommerce Insights: number formatting and the shared REST helper.
 *
 * Formats money, percentages and plain numbers the way the store's
 * WooCommerce settings say (currency symbol and position, decimal and
 * thousands separators), and reads amounts people type in.
 */
( function ( window ) {
	'use strict';

	window.KDNAEI = window.KDNAEI || {};

	var config = window.kdnaEiApp || {};
	var currency = config.currency || {
		symbol: '$',
		position: 'left',
		decimals: 2,
		decimal: '.',
		thousand: ',',
	};

	/**
	 * Formats a number with the store's separators and a set number of decimals.
	 *
	 * @param {number} value    Number to format.
	 * @param {number} decimals Decimal places.
	 * @return {string}
	 */
	function number( value, decimals ) {
		var fixed = Math.abs( Number( value ) ).toFixed( decimals );
		var parts = fixed.split( '.' );
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, currency.thousand );
		return ( Number( value ) < 0 ? '-' : '' ) + parts.join( currency.decimal );
	}

	/**
	 * Formats an amount of money, for example "$24,850.00".
	 * Returns an empty string for missing values.
	 *
	 * @param {number|null} value    Amount.
	 * @param {number}      decimals Decimal places, defaults to the store setting.
	 * @return {string}
	 */
	function money( value, decimals ) {
		if ( value === null || value === undefined || value === '' || isNaN( Number( value ) ) ) {
			return '';
		}

		var amount = number( Math.abs( value ), decimals === undefined ? currency.decimals : decimals );
		var sign = Number( value ) < 0 ? '-' : '';
		var symbol = currency.symbol;

		switch ( currency.position ) {
			case 'right':
				return sign + amount + symbol;
			case 'left_space':
				return sign + symbol + ' ' + amount;
			case 'right_space':
				return sign + amount + ' ' + symbol;
			default:
				return sign + symbol + amount;
		}
	}

	/**
	 * Formats a percentage with one decimal place, for example "42.5%".
	 *
	 * @param {number|null} value Percentage.
	 * @return {string}
	 */
	function percent( value ) {
		if ( value === null || value === undefined || isNaN( Number( value ) ) ) {
			return '';
		}
		return number( value, 1 ) + '%';
	}

	/**
	 * Shows an amount in an input field using the store's decimal separator,
	 * without a currency symbol or thousands separators.
	 *
	 * @param {number|null} value Amount.
	 * @return {string}
	 */
	function inputAmount( value ) {
		if ( value === null || value === undefined || value === '' ) {
			return '';
		}
		var text = String( Math.round( Number( value ) * 10000 ) / 10000 );
		return currency.decimal === '.' ? text : text.replace( '.', currency.decimal );
	}

	/**
	 * Reads an amount someone typed, accepting the store's decimal separator
	 * as well as a full stop. Returns null when empty, NaN when not a number.
	 *
	 * @param {string} text Typed value.
	 * @return {number|null}
	 */
	function parseAmount( text ) {
		var value = String( text === null || text === undefined ? '' : text ).trim();
		if ( value === '' ) {
			return null;
		}

		value = value.replace( currency.symbol, '' ).replace( /\s/g, '' );

		if ( currency.decimal !== '.' ) {
			value = value.split( currency.thousand ).join( '' ).replace( currency.decimal, '.' );
		} else if ( currency.thousand ) {
			value = value.split( currency.thousand ).join( '' );
		}

		return /^-?\d*\.?\d+$/.test( value ) ? Number( value ) : NaN;
	}

	/**
	 * Shortens a big number for chart axes, for example 30000 becomes "30K"
	 * and 1500000 becomes "1.5M", like the reference design.
	 *
	 * @param {number} value Number.
	 * @return {string}
	 */
	function compact( value ) {
		var abs = Math.abs( Number( value ) );
		var sign = Number( value ) < 0 ? '-' : '';
		if ( abs >= 1000000 ) {
			return sign + trimZero( abs / 1000000 ) + 'M';
		}
		if ( abs >= 1000 ) {
			return sign + trimZero( abs / 1000 ) + 'K';
		}
		return sign + trimZero( abs );
	}

	/**
	 * Rounds to one decimal place and drops a trailing ".0".
	 *
	 * @param {number} value Number.
	 * @return {string}
	 */
	function trimZero( value ) {
		var text = ( Math.round( value * 10 ) / 10 ).toFixed( 1 );
		text = text.replace( /\.0$/, '' );
		return currency.decimal === '.' ? text : text.replace( '.', currency.decimal );
	}

	/**
	 * Formats a metric value by its format from the metric registry:
	 * currency, number, percent, ratio (3.2x) or days. Large money amounts
	 * drop the cents, like the reference design.
	 *
	 * @param {number|null} value    Value.
	 * @param {string}      kind     Format.
	 * @param {number|null} decimals Decimal places for numbers.
	 * @return {string}
	 */
	function metric( value, kind, decimals ) {
		if ( value === null || value === undefined || isNaN( Number( value ) ) ) {
			return '\u2013';
		}
		switch ( kind ) {
			case 'currency':
				return money( value, Math.abs( value ) >= 1000 ? 0 : currency.decimals );
			case 'percent':
				return percent( value );
			case 'ratio':
				return number( value, 2 ) + 'x';
			case 'days':
				return ( config.i18n && config.i18n.days ? config.i18n.days : '%s days' ).replace( '%s', number( value, 0 ) );
			default:
				return number( value, decimals || 0 );
		}
	}

	window.KDNAEI.format = {
		number: number,
		money: money,
		percent: percent,
		compact: compact,
		metric: metric,
		inputAmount: inputAmount,
		parseAmount: parseAmount,
	};

	/**
	 * Calls a plugin REST route and returns the decoded JSON. Errors are
	 * thrown with the plain-English message the server sent.
	 *
	 * @param {string} path    Route path after kdna-ei/v1/, may include a query string.
	 * @param {Object} options { method, body, query }.
	 * @return {Promise<Object>}
	 */
	window.KDNAEI.api = function ( path, options ) {
		options = options || {};

		var url = config.restUrl + path;

		// Debug mode (?kdna_ei_debug=1 on the Insights page) asks every route for query timings.
		if ( config.debug ) {
			options.query = Object.assign( {}, options.query || {}, { kdna_ei_debug: 1 } );
		}

		if ( options.query ) {
			var query = Object.keys( options.query ).filter( function ( key ) {
				return options.query[ key ] !== '' && options.query[ key ] !== null && options.query[ key ] !== undefined && options.query[ key ] !== false;
			} ).map( function ( key ) {
				return encodeURIComponent( key ) + '=' + encodeURIComponent( options.query[ key ] );
			} ).join( '&' );

			if ( query ) {
				url += ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + query;
			}
		}

		return window.fetch( url, {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: options.body ? JSON.stringify( options.body ) : undefined,
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				if ( config.debug && data && data.meta && data.meta.debug ) {
					window.dispatchEvent( new window.CustomEvent( 'kdna:ei-debug', { detail: data.meta.debug } ) );
				}
				if ( ! response.ok ) {
					var error = new Error( ( data && data.message ) || ( config.i18n && config.i18n.requestFailed ) || 'Request failed.' );
					error.status = response.status;
					error.fields = ( data && data.data && data.data.fields ) || {};
					error.data = ( data && data.data ) || {};
					throw error;
				}
				return data;
			} );
		} );
	};

	/**
	 * Starts a file download in the browser from text made in JavaScript.
	 *
	 * @param {string} filename File name.
	 * @param {string} text     File contents.
	 * @param {string} type     MIME type.
	 */
	window.KDNAEI.download = function ( filename, text, type ) {
		var blob = new window.Blob( [ text ], { type: type || 'text/csv;charset=utf-8' } );
		var link = document.createElement( 'a' );
		link.href = window.URL.createObjectURL( blob );
		link.download = filename;
		document.body.appendChild( link );
		link.click();
		window.setTimeout( function () {
			window.URL.revokeObjectURL( link.href );
			link.remove();
		}, 1000 );
	};

	/**
	 * Replaces placeholders in text with values: %s or %d in order, or
	 * numbered ones such as %1$s and %2$s (which translations may reorder).
	 * %% becomes %.
	 *
	 * @param {string} text Text with placeholders.
	 * @return {string}
	 */
	function sprintf( text ) {
		var values = Array.prototype.slice.call( arguments, 1 );
		var next = 0;
		return String( text || '' ).replace( /%%|%(\d+)\$[sd]|%[sd]/g, function ( token, position ) {
			if ( token === '%%' ) {
				return '%';
			}
			var value = position ? values[ Number( position ) - 1 ] : values[ next++ ];
			return value === undefined ? '' : value;
		} );
	}

	window.KDNAEI.sprintf = sprintf;

	/**
	 * Date helpers shared by the report screens. Dates arrive as Y-m-d
	 * strings and are shown in the visitor's own date style.
	 */
	window.KDNAEI.dates = {
		/**
		 * Turns a Y-m-d (or Y-m) string into a local date.
		 *
		 * @param {string} value Date.
		 * @return {Date}
		 */
		toDate: function ( value ) {
			var parts = String( value ).split( '-' );
			return new Date( Number( parts[ 0 ] ), Number( parts[ 1 ] ) - 1, Number( parts[ 2 ] || 1 ) );
		},

		/**
		 * Formats a date with the visitor's locale.
		 *
		 * @param {string} value   Y-m-d or Y-m.
		 * @param {Object} options Intl date options.
		 * @return {string}
		 */
		text: function ( value, options ) {
			return window.KDNAEI.dates.toDate( value ).toLocaleDateString( config.locale || undefined, options );
		},

		/**
		 * A short date such as "9 Oct 2026".
		 *
		 * @param {string} value Y-m-d.
		 * @return {string}
		 */
		short: function ( value ) {
			return value ? window.KDNAEI.dates.text( value, { day: 'numeric', month: 'short', year: 'numeric' } ) : '';
		},

		/**
		 * Short chart axis label for a bucket: "9 Oct" for days and weeks,
		 * "Oct 26" for months.
		 *
		 * @param {Object[]} buckets     Buckets with start.
		 * @param {number}   index       Bucket position.
		 * @param {string}   granularity day, week or month.
		 * @return {string}
		 */
		axis: function ( buckets, index, granularity ) {
			var bucket = buckets[ index ];
			if ( ! bucket ) {
				return '';
			}
			return 'month' === granularity
				? window.KDNAEI.dates.text( bucket.start, { month: 'short', year: '2-digit' } )
				: window.KDNAEI.dates.text( bucket.start, { day: 'numeric', month: 'short' } );
		},

		/**
		 * Tooltip title for a bucket: "Thu 9 Oct 2026", "Week of 5 Oct 2026"
		 * or "October 2026".
		 *
		 * @param {Object[]} buckets     Buckets with start.
		 * @param {number}   index       Bucket position.
		 * @param {string}   granularity day, week or month.
		 * @return {string}
		 */
		title: function ( buckets, index, granularity ) {
			var bucket = buckets[ index ];
			var t = ( config.i18n && config.i18n.overview ) || {};
			if ( ! bucket ) {
				return '';
			}
			if ( 'month' === granularity ) {
				return window.KDNAEI.dates.text( bucket.start, { month: 'long', year: 'numeric' } );
			}
			if ( 'week' === granularity ) {
				return sprintf( t.weekOf, window.KDNAEI.dates.short( bucket.start ) );
			}
			return window.KDNAEI.dates.text( bucket.start, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' } );
		},
	};

	/**
	 * Fetches a CSV from /export for a table and downloads it.
	 *
	 * @param {string} table Table name, for example "pnl" or "products".
	 * @param {Object} query Range and filters, as the screen uses them.
	 * @return {Promise}
	 */
	window.KDNAEI.exportCsv = function ( table, query ) {
		return window.KDNAEI.api( 'export', { query: Object.assign( {}, query, { table: table } ) } ).then( function ( data ) {
			window.KDNAEI.download( data.filename, data.csv );
		} );
	};

	/**
	 * Opens the printable report (Overview, P&L and top products) for a date
	 * range in a new tab, where the print window opens straight away so it
	 * can be saved as a PDF.
	 *
	 * @param {Object} query Range query: preset, compare, and start and end for custom ranges.
	 */
	window.KDNAEI.printReport = function ( query ) {
		var base = ( window.kdnaEiApp && window.kdnaEiApp.printUrl ) || '';
		if ( ! base ) {
			return;
		}
		var params = new window.URLSearchParams( Object.assign( {}, query, { autoprint: 1 } ) );
		window.open( base + ( base.indexOf( '?' ) === -1 ? '?' : '&' ) + params.toString(), '_blank', 'noopener' );
	};

	/**
	 * Shared helpers for KPI strips on every report screen: icons, change
	 * text such as "12%" or "3.0 pts", and the comparison sentence.
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
		cogs: 'tag',
		payment_fees: 'tag',
		shipping_costs: 'box',
	};

	window.KDNAEI.kpi = {
		/**
		 * Icon name for a metric.
		 *
		 * @param {string} key Metric key.
		 * @return {string}
		 */
		icon: function ( key ) {
			return KPI_ICONS[ key ] || 'chart';
		},

		/**
		 * The change shown beside a KPI, for example "12%" or "3.0 pts".
		 *
		 * @param {Object} kpi Metric from /summary.
		 * @return {string}
		 */
		changeText: function ( kpi ) {
			var t = ( config.i18n && config.i18n.overview ) || {};
			if ( kpi.change === null ) {
				return kpi.previous === 0 && kpi.value ? t.new : '';
			}
			var amount = number( Math.abs( kpi.change ), 1 );
			return kpi.change_type === 'points' ? sprintf( t.points, amount ) : amount + '%';
		},

		/**
		 * Sentence with the comparison period's value, for tooltips and
		 * screen readers.
		 *
		 * @param {Object} kpi             Metric from /summary.
		 * @param {string} comparisonLabel For example "Previous period".
		 * @return {string}
		 */
		previousText: function ( kpi, comparisonLabel ) {
			var t = ( config.i18n && config.i18n.overview ) || {};
			if ( kpi.previous === null || kpi.previous === undefined ) {
				return '';
			}
			return sprintf( t.previousWas, comparisonLabel, metric( kpi.previous, kpi.format, kpi.decimals ) );
		},

		/**
		 * Name of the comparison period.
		 *
		 * @param {string} comparison previous_period or previous_year.
		 * @return {string}
		 */
		comparisonLabel: function ( comparison ) {
			var t = ( config.i18n && config.i18n.overview ) || {};
			return comparison === 'previous_year' ? t.lastYear : t.previousPeriod;
		},
	};
}( window ) );
