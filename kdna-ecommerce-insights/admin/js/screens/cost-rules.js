/**
 * KDNA eCommerce Insights: Costs screen rule tabs.
 *
 * Payment fee rules per gateway, shipping cost rules per shipping method,
 * the real label cost meta key, and extra per-order costs. Each tab saves
 * its own part of Settings > Costs.
 */
( function ( window, document ) {
	'use strict';

	var config = window.kdnaEiApp || {};
	var t = ( config.i18n && config.i18n.rules ) || {};
	var format = window.KDNAEI.format;
	var api = window.KDNAEI.api;

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
	 * Turns a typed amount into a number for saving. Empty becomes 0. Text
	 * that is not a number is sent as typed, so the server can explain the problem.
	 *
	 * @param {string|number} value Typed value.
	 * @return {number|string}
	 */
	function toNumber( value ) {
		var parsed = format.parseAmount( value );
		if ( parsed === null ) {
			return 0;
		}
		return isNaN( parsed ) ? String( value ) : parsed;
	}

	/**
	 * Shows a saved number in an input, leaving zero empty so placeholders show.
	 *
	 * @param {number} value Saved value.
	 * @return {string}
	 */
	function toInput( value ) {
		return value ? format.inputAmount( value ) : '';
	}

	var uid = 0;

	document.addEventListener( 'alpine:init', function () {
		window.Alpine.data( 'kdnaEiCostRules', function () {
			return {
				t: t,
				loaded: false,
				loading: false,
				loadError: '',
				gateways: [],
				methods: [],
				shippingTypes: t.shippingTypes || {},
				form: {
					gateway_fees: {},
					shipping_rules: {},
					shipping_cost_meta_key: '',
					extra_costs: [],
				},
				errors: {},
				notices: { fees: null, shipping: null, extras: null },
				dirty: { fees: false, shipping: false, extras: false },
				saving: '',

				/**
				 * Warns before leaving the page with unsaved rule changes.
				 */
				init: function () {
					var self = this;
					window.addEventListener( 'beforeunload', function ( event ) {
						if ( self.dirty.fees || self.dirty.shipping || self.dirty.extras ) {
							event.preventDefault();
							event.returnValue = '';
						}
					} );
				},

				/**
				 * Loads the rules the first time one of these tabs is opened.
				 */
				ensureLoaded: function () {
					if ( ! this.loaded && ! this.loading ) {
						this.load();
					}
				},

				/**
				 * Fetches installed gateways, shipping methods and the saved rules.
				 *
				 * @return {Promise}
				 */
				load: function () {
					var self = this;
					this.loading = true;
					this.loadError = '';

					return api( 'costs/setup' ).then( function ( data ) {
						self.gateways = data.gateways || [];
						self.methods = data.shipping_methods || [];
						self.fill( data.costs || {} );
						self.loaded = true;
					} ).catch( function ( error ) {
						self.loadError = error.message;
					} ).finally( function () {
						self.loading = false;
					} );
				},

				/**
				 * Copies saved settings into the form, adding an empty rule for
				 * every gateway and shipping method that has none yet.
				 *
				 * Pass a section to refresh only that tab, keeping unsaved edits
				 * in the other tabs.
				 *
				 * @param {Object} costs   Settings > Costs values.
				 * @param {string} section Optional: fees, shipping or extras.
				 */
				fill: function ( costs, section ) {
					var fees = {};
					var savedFees = costs.gateway_fees || {};
					this.gateways.forEach( function ( gateway ) {
						var rule = savedFees[ gateway.id ] || {};
						fees[ gateway.id ] = { percent: toInput( rule.percent ), fixed: toInput( rule.fixed ) };
					} );

					var rules = {};
					var saved = {};
					( costs.shipping_rules || [] ).forEach( function ( rule ) {
						saved[ rule.method ] = rule;
					} );
					this.shippingRows.forEach( function ( method ) {
						var rule = saved[ method.key ] || {};
						rules[ method.key ] = {
							type: rule.type || 'none',
							amount: toInput( rule.amount ),
							per_kg: toInput( rule.per_kg ),
						};
					} );

					var extras = ( costs.extra_costs || [] ).map( function ( cost ) {
						return { uid: ++uid, label: cost.label, type: cost.type, amount: toInput( cost.amount ) };
					} );

					if ( ! section || section === 'fees' ) {
						this.form.gateway_fees = fees;
						this.dirty.fees = false;
					}
					if ( ! section || section === 'shipping' ) {
						this.form.shipping_rules = rules;
						this.form.shipping_cost_meta_key = costs.shipping_cost_meta_key || '';
						this.dirty.shipping = false;
					}
					if ( ! section || section === 'extras' ) {
						this.form.extra_costs = extras;
						this.dirty.extras = false;
					}
				},

				/**
				 * Shipping methods from every zone, plus the "all other methods" rule.
				 *
				 * @return {Object[]}
				 */
				get shippingRows() {
					return this.methods.concat( [ { key: '*', title: t.otherMethods, zone: t.otherMethodsNote, enabled: true } ] );
				},

				/**
				 * The problem message for a field after a failed save, if any.
				 *
				 * @param {string} path Field path, for example "costs.extra_costs.0.label".
				 * @return {string}
				 */
				fieldError: function ( path ) {
					return this.errors[ path ] || '';
				},

				/**
				 * What a gateway's rule would charge on a 100 order, as a live example.
				 *
				 * @param {string} id Gateway ID.
				 * @return {string}
				 */
				feeExample: function ( id ) {
					var rule = this.form.gateway_fees[ id ] || {};
					var percent = format.parseAmount( rule.percent ) || 0;
					var fixed = format.parseAmount( rule.fixed ) || 0;
					if ( isNaN( percent ) || isNaN( fixed ) ) {
						return '';
					}
					if ( ! percent && ! fixed ) {
						return '–';
					}
					return format.money( 100 * percent / 100 + fixed );
				},

				/**
				 * Live example of what the extra costs add to a 100 order.
				 *
				 * @return {string}
				 */
				get extrasExample() {
					var total = 0;
					this.form.extra_costs.forEach( function ( cost ) {
						var amount = format.parseAmount( cost.amount ) || 0;
						if ( ! isNaN( amount ) ) {
							total += cost.type === 'percent' ? 100 * amount / 100 : amount;
						}
					} );
					return sprintf( t.extrasExample, format.money( 100 ), format.money( total ) );
				},

				/**
				 * Adds an empty extra cost row.
				 */
				addExtra: function () {
					this.form.extra_costs.push( { uid: ++uid, label: '', type: 'fixed', amount: '' } );
					this.dirty.extras = true;
				},

				/**
				 * Removes an extra cost row.
				 *
				 * @param {number} index Row position.
				 */
				removeExtra: function ( index ) {
					this.form.extra_costs.splice( index, 1 );
					this.dirty.extras = true;
					this.errors = {};
				},

				/**
				 * Builds the settings changes for one tab, ready to send.
				 *
				 * @param {string} section fees, shipping or extras.
				 * @return {Object}
				 */
				changesFor: function ( section ) {
					var form = this.form;
					var costs = {};

					if ( section === 'fees' ) {
						costs.gateway_fees = {};
						Object.keys( form.gateway_fees ).forEach( function ( id ) {
							costs.gateway_fees[ id ] = {
								percent: toNumber( form.gateway_fees[ id ].percent ),
								fixed: toNumber( form.gateway_fees[ id ].fixed ),
							};
						} );
					}

					if ( section === 'shipping' ) {
						costs.shipping_cost_meta_key = String( form.shipping_cost_meta_key || '' ).trim();
						costs.shipping_rules = Object.keys( form.shipping_rules ).filter( function ( key ) {
							return form.shipping_rules[ key ].type !== 'none';
						} ).map( function ( key ) {
							var rule = form.shipping_rules[ key ];
							return { method: key, type: rule.type, amount: toNumber( rule.amount ), per_kg: toNumber( rule.per_kg ) };
						} );
					}

					if ( section === 'extras' ) {
						costs.extra_costs = form.extra_costs.map( function ( cost ) {
							return { label: String( cost.label || '' ).trim(), type: cost.type, amount: cost.amount === '' ? '' : toNumber( cost.amount ) };
						} );
					}

					return { costs: costs };
				},

				/**
				 * Saves one tab. If anything needs fixing, the fields are
				 * outlined with a plain-English message and nothing is saved.
				 *
				 * @param {string} section fees, shipping or extras.
				 * @return {Promise}
				 */
				save: function ( section ) {
					var self = this;

					this.saving = section;
					this.errors = {};
					this.notices[ section ] = null;

					return api( 'settings', { method: 'POST', body: { settings: this.changesFor( section ) } } ).then( function ( settings ) {
						self.fill( settings.costs || {}, section );
						self.notices[ section ] = { type: 'positive', text: t.saved };
					} ).catch( function ( error ) {
						self.errors = error.fields || {};
						self.notices[ section ] = { type: 'negative', text: error.message };
					} ).finally( function () {
						self.saving = '';
					} );
				},

				sprintf: sprintf,
			};
		} );
	} );
}( window, document ) );
