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
}( window ) );
