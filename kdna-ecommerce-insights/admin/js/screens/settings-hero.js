/**
 * KDNA eCommerce Insights: Settings > Hero card and Goals.
 *
 * Chooses what the Overview's hero card shows and sets the monthly targets
 * for the Goals tracker. Saving tells the Overview straight away
 * (kdna:ei-hero-change), so it updates without a page reload.
 */
( function ( window, document ) {
	'use strict';

	var config = window.kdnaEiApp || {};
	var format = window.KDNAEI.format;
	var t = ( config.i18n && config.i18n.heroSettings ) || {};

	document.addEventListener( 'alpine:init', function () {
		window.Alpine.data( 'kdnaEiHeroSettings', function () {
			return {
				form: { type: 'top_products', goal_metric: 'revenue', revenue: '', profit: '', orders: '' },
				errors: {},
				saving: false,
				message: '',
				symbol: ( config.currency && config.currency.symbol ) || '',

				/**
				 * Fills the form from the saved settings and keeps the hero type in
				 * step with the Overview card's own menu.
				 */
				init: function () {
					var self = this;
					this.fill( config.hero || {} );

					// The Overview's card menu can change the type too.
					window.addEventListener( 'kdna:ei-hero-change', function ( event ) {
						self.form.type = event.detail.type;
					} );
				},

				/**
				 * Copies saved hero settings into the form, leaving zero targets blank.
				 *
				 * @param {Object} hero Saved hero settings.
				 */
				fill: function ( hero ) {
					var targets = hero.goal_targets || {};
					this.form = {
						type: hero.type || 'top_products',
						goal_metric: hero.goal_metric || 'revenue',
						revenue: Number( targets.revenue ) ? format.inputAmount( Number( targets.revenue ) ) : '',
						profit: Number( targets.profit ) ? format.inputAmount( Number( targets.profit ) ) : '',
						orders: Number( targets.orders ) ? String( targets.orders ) : '',
					};
				},

				/**
				 * Checks the targets are sensible numbers. Returns false and shows
				 * a plain-English message against any that are not.
				 *
				 * @return {Object|false} Clean targets.
				 */
				check: function () {
					var errors = {};
					var revenue = format.parseAmount( this.form.revenue );
					var profit = format.parseAmount( this.form.profit );
					var orders = String( this.form.orders ).trim() === '' ? 0 : Number( this.form.orders );

					if ( revenue !== null && ( isNaN( revenue ) || revenue < 0 ) ) {
						errors.revenue = t.amountError;
					}
					if ( profit !== null && ( isNaN( profit ) || profit < 0 ) ) {
						errors.profit = t.amountError;
					}
					if ( isNaN( orders ) || orders < 0 || Math.floor( orders ) !== orders ) {
						errors.orders = t.ordersError;
					}

					this.errors = errors;
					if ( Object.keys( errors ).length ) {
						return false;
					}
					return { revenue: revenue || 0, profit: profit || 0, orders: orders };
				},

				/**
				 * Saves the hero card choice and targets.
				 */
				save: function () {
					var self = this;
					var targets = this.check();
					if ( ! targets ) {
						this.message = '';
						return;
					}

					this.saving = true;
					this.message = '';
					window.KDNAEI.api( 'settings', {
						method: 'POST',
						body: { settings: { hero: { type: this.form.type, goal_metric: this.form.goal_metric, goal_targets: targets } } },
					} ).then( function ( settings ) {
						config.hero = settings.hero;
						self.fill( settings.hero );
						self.message = t.saved;
						window.dispatchEvent( new window.CustomEvent( 'kdna:ei-hero-change', { detail: settings.hero } ) );
					} ).catch( function ( error ) {
						self.message = error.message;
					} ).finally( function () {
						self.saving = false;
					} );
				},
			};
		} );
	} );
}( window, document ) );
