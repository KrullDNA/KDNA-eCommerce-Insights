/**
 * KDNA eCommerce Insights: Settings screen.
 *
 * Every tab from section 11 of the brief. Each tab is a form of its own:
 * it remembers what was last saved, shows "Unsaved changes" when edited,
 * and saves only its own settings. The server checks everything again and
 * explains any problem in plain English against the field.
 *
 * Saving some tabs changes the rest of the app straight away:
 * - Branding swaps the brand colours, font and custom CSS on the page and
 *   redraws every chart, and updates the logo and store name.
 * - Hero card and Goals updates the Overview's hero card.
 * - General, Tax and Marketing tell report screens their figures may have
 *   changed (kdna:ei-data-loaded).
 *
 * The Data tab's processing card uses the app's job controls; this script
 * adds the sync log viewer and the uninstall setting.
 */
( function ( window, document ) {
	'use strict';

	var config = window.kdnaEiApp || {};
	var t = ( config.i18n && config.i18n.settings ) || {};
	var format = window.KDNAEI.format;
	var api = window.KDNAEI.api;
	var sprintf = window.KDNAEI.sprintf;

	/**
	 * Tabs that are edited as forms (Data is partly a form: the uninstall switch).
	 */
	var TABS = [ 'general', 'costs', 'marketing', 'tax', 'branding', 'hero', 'alerts', 'data' ];

	/**
	 * Theme colours the brand colours sit on, from section 4.2 of the brief.
	 * The preview uses them so it looks like the real dashboard.
	 */
	var BASE = {
		dark: { bg: '#202125', surface: '#26272B', raised: '#2E2F34', border: 'rgba(255,255,255,0.07)', text: '#F3F3F5', muted: '#9C9DA4' },
		light: { bg: '#F4F5F7', surface: '#FFFFFF', raised: '#F0F1F4', border: 'rgba(17,18,22,0.08)', text: '#15161A', muted: '#6B6E78' },
	};

	/**
	 * Font stacks for the preview.
	 */
	var SYSTEM_FONT = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';

	/**
	 * Copies plain data so a form can be compared with what was saved.
	 *
	 * @param {*} value Value.
	 * @return {*}
	 */
	function copy( value ) {
		return JSON.parse( JSON.stringify( value ) );
	}

	/**
	 * Turns a channel name into a key, the way WordPress's sanitize_key()
	 * would: lower case letters, numbers, dashes and underscores.
	 *
	 * @param {string} label Channel name.
	 * @return {string}
	 */
	function slug( label ) {
		return String( label ).toLowerCase().trim().replace( /\s+/g, '_' ).replace( /[^a-z0-9_\-]/g, '' ).slice( 0, 40 );
	}

	/**
	 * Whether text is a colour code like #5A6FE0 or #5AF.
	 *
	 * @param {string} value Text.
	 * @return {boolean}
	 */
	function isHex( value ) {
		return /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test( String( value || '' ) );
	}

	/**
	 * How bright a colour looks, from 0 (black) to 1 (white), using the
	 * WCAG formula.
	 *
	 * @param {string} hex Colour code.
	 * @return {number}
	 */
	function luminance( hex ) {
		var value = String( hex ).replace( '#', '' );
		if ( value.length === 3 ) {
			value = value.replace( /(.)/g, '$1$1' );
		}
		var channels = [ 0, 2, 4 ].map( function ( start ) {
			var c = parseInt( value.substr( start, 2 ), 16 ) / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
		} );
		return 0.2126 * channels[ 0 ] + 0.7152 * channels[ 1 ] + 0.0722 * channels[ 2 ];
	}

	/**
	 * The contrast ratio between two colours (1 to 21). Shapes and large
	 * text need at least 3 to be easy to see.
	 *
	 * @param {string} a Colour code.
	 * @param {string} b Colour code.
	 * @return {number}
	 */
	function contrast( a, b ) {
		var one = luminance( a );
		var two = luminance( b );
		return ( Math.max( one, two ) + 0.05 ) / ( Math.min( one, two ) + 0.05 );
	}

	/**
	 * A number typed in a field, or the text itself when it is not a number
	 * (so the server can explain what is wrong).
	 *
	 * @param {string} text Typed text.
	 * @return {number|string}
	 */
	function numberOrText( text ) {
		var value = String( text === null || text === undefined ? '' : text ).trim();
		if ( value === '' ) {
			return '';
		}
		var parsed = format.parseAmount( value );
		return parsed === null || isNaN( parsed ) ? value : parsed;
	}

	document.addEventListener( 'alpine:init', function () {
		window.Alpine.data( 'kdnaEiSettings', function () {
			var uid = 0;

			return {
				t: t,
				sprintf: sprintf,
				symbol: ( config.currency && config.currency.symbol ) || '',
				settingsTab: 'general',
				confirmRebuild: false,
				confirmReset: '',
				loaded: false,
				loading: false,
				loadError: '',
				saved: {},
				form: {},
				snapshots: {},
				errors: {},
				busy: '',
				notice: { tab: '', type: '', text: '' },
				context: { order_statuses: [], weekdays: [], connections: [], presets: [], costs: {}, channel_usage: {}, fonts: {}, digest: null },
				logo: { url: '', thumb: '' },
				previewTheme: 'dark',
				sectionOrder: [ 'kpis', 'profit', 'top_products', 'inventory', 'marketing', 'alerts' ],
				log: { rows: [], page: 1, pages: 1, total: 0, type: '', status: '', types: {}, loading: false, loaded: false, error: '' },

				/**
				 * Keeps the hero type in step with the Overview card's own menu,
				 * and warns before leaving the page with unsaved changes.
				 */
				init: function () {
					var self = this;
					window.addEventListener( 'kdna:ei-hero-change', function ( event ) {
						if ( self.loaded && event.detail && event.detail.source !== 'settings' ) {
							self.form.hero.type = event.detail.type;
							self.saved.hero.type = event.detail.type;
							self.snapshot( 'hero' );
						}
					} );
					window.addEventListener( 'beforeunload', function ( event ) {
						if ( self.loaded && TABS.some( function ( tab ) {
							return self.isDirty( tab );
						} ) ) {
							event.preventDefault();
							event.returnValue = '';
						}
					} );
				},

				/**
				 * Loads the settings the first time the screen is opened.
				 */
				ensureLoaded: function () {
					if ( ! this.loaded && ! this.loading ) {
						this.load();
					}
					if ( this.settingsTab === 'data' && ! this.log.loaded && ! this.log.loading ) {
						this.loadLog( 1 );
					}
				},

				/**
				 * Shows a tab.
				 *
				 * @param {string} tab Tab key.
				 */
				openTab: function ( tab ) {
					this.settingsTab = tab;
					this.confirmReset = '';
					this.ensureLoaded();
				},

				/**
				 * Fetches every setting and what the screen shows alongside them.
				 *
				 * @return {Promise}
				 */
				load: function () {
					var self = this;
					this.loading = true;
					this.loadError = '';
					return Promise.all( [ api( 'settings' ), api( 'settings/context' ) ] ).then( function ( results ) {
						self.context = results[ 1 ];
						self.logo = { url: results[ 1 ].logo.url, thumb: results[ 1 ].logo.thumb };
						self.setSaved( results[ 0 ], TABS );
						self.previewTheme = results[ 0 ].branding.default_theme;
						self.loaded = true;
					} ).catch( function ( error ) {
						self.loadError = error.message;
					} ).finally( function () {
						self.loading = false;
					} );
				},

				/*
				 * -------------------------------------------------------------
				 * Forms
				 * -------------------------------------------------------------
				 */

				/**
				 * Takes saved settings from the server and refills the forms for
				 * the given tabs.
				 *
				 * @param {Object}   settings Every setting.
				 * @param {string[]} tabs     Tabs to refill.
				 */
				setSaved: function ( settings, tabs ) {
					var self = this;
					this.saved = settings;
					var form = Object.assign( {}, this.form );
					tabs.forEach( function ( tab ) {
						form[ tab ] = self.toForm( tab, settings[ tab ] || {} );
					} );
					this.form = form;
					tabs.forEach( function ( tab ) {
						self.snapshot( tab );
					} );
				},

				/**
				 * Remembers a tab's form as it is now, as the "saved" version.
				 *
				 * @param {string} tab Tab key.
				 */
				snapshot: function ( tab ) {
					var snapshots = Object.assign( {}, this.snapshots );
					snapshots[ tab ] = JSON.stringify( this.form[ tab ] );
					this.snapshots = snapshots;
				},

				/**
				 * Turns saved settings for a tab into form values (numbers become
				 * text in the store's number format).
				 *
				 * @param {string} tab      Tab key.
				 * @param {Object} settings The tab's saved settings.
				 * @return {Object}
				 */
				toForm: function ( tab, settings ) {
					var s = copy( settings );
					switch ( tab ) {
						case 'costs':
							return { cost_field_placement: s.cost_field_placement };
						case 'marketing':
							return {
								channels: ( s.channels || [] ).map( function ( channel ) {
									return { uid: ++uid, key: channel.key, label: channel.label };
								} ),
								ad_currency_rate: String( s.ad_currency_rate ),
								sync_frequency: s.sync_frequency,
							};
						case 'tax':
							return { system: s.system, rate: String( s.rate ), reporting_period: s.reporting_period };
						case 'branding':
							return {
								store_name: s.store_name,
								logo_id: Number( s.logo_id ) || 0,
								font: s.font,
								colours: s.colours,
								default_theme: s.default_theme,
								custom_css: s.custom_css,
							};
						case 'hero':
							var targets = s.goal_targets || {};
							return {
								type: s.type,
								goal_metric: s.goal_metric,
								revenue: Number( targets.revenue ) ? format.inputAmount( Number( targets.revenue ) ) : '',
								profit: Number( targets.profit ) ? format.inputAmount( Number( targets.profit ) ) : '',
								orders: Number( targets.orders ) ? String( targets.orders ) : '',
							};
						case 'alerts':
							return {
								low_stock_threshold: String( s.low_stock_threshold ),
								dead_stock_days: String( s.dead_stock_days ),
								reorder_lead_days: String( s.reorder_lead_days ),
								alert_recipients: s.alert_recipients,
								low_stock_emails: !! s.low_stock_emails,
								sync_failure_emails: !! s.sync_failure_emails,
								digest_frequency: s.digest_frequency,
								digest_recipients: s.digest_recipients,
								digest_sections: s.digest_sections || [],
							};
						case 'data':
							return { delete_on_uninstall: !! s.delete_on_uninstall };
						default:
							return s;
					}
				},

				/**
				 * Turns a tab's form back into settings to send to the server.
				 *
				 * @param {string} tab Tab key.
				 * @return {Object}
				 */
				fromForm: function ( tab ) {
					var f = copy( this.form[ tab ] );
					switch ( tab ) {
						case 'marketing':
							var keys = {};
							f.channels = f.channels.map( function ( channel ) {
								var key = channel.key || slug( channel.label );
								// A new channel whose name matches another's key gets a number.
								var base = key;
								var n = 2;
								while ( key && keys[ key ] ) {
									key = base + '_' + n++;
								}
								keys[ key ] = true;
								return { key: key, label: String( channel.label ).trim() };
							} );
							f.ad_currency_rate = numberOrText( f.ad_currency_rate );
							return f;
						case 'tax':
							f.rate = numberOrText( f.rate );
							return f;
						case 'branding':
							f.store_name = String( f.store_name || '' ).trim();
							return f;
						case 'hero':
							return {
								type: f.type,
								goal_metric: f.goal_metric,
								goal_targets: {
									revenue: f.revenue === '' ? 0 : numberOrText( f.revenue ),
									profit: f.profit === '' ? 0 : numberOrText( f.profit ),
									orders: String( f.orders ).trim() === '' ? 0 : numberOrText( f.orders ),
								},
							};
						case 'alerts':
							[ 'low_stock_threshold', 'dead_stock_days', 'reorder_lead_days' ].forEach( function ( key ) {
								f[ key ] = String( f[ key ] ).trim() === '' && key === 'low_stock_threshold' ? 0 : numberOrText( f[ key ] );
							} );
							return f;
						default:
							return f;
					}
				},

				/**
				 * Whether a tab has changes that are not saved yet.
				 *
				 * @param {string} tab Tab key.
				 * @return {boolean}
				 */
				isDirty: function ( tab ) {
					return this.loaded && !! this.form[ tab ] && this.snapshots[ tab ] !== JSON.stringify( this.form[ tab ] );
				},

				/**
				 * The problem with a field, if any.
				 *
				 * @param {string} path Field path, such as "general.week_start".
				 * @return {string}
				 */
				err: function ( path ) {
					return this.errors[ path ] || '';
				},

				/**
				 * Adds a value to a list, or takes it out if it is there, keeping
				 * an order when one is given.
				 *
				 * @param {Array}  list  List.
				 * @param {string} value Value.
				 * @param {Array}  order Optional order to keep.
				 */
				toggleIn: function ( list, value, order ) {
					var index = list.indexOf( value );
					if ( index === -1 ) {
						list.push( value );
						if ( order ) {
							list.sort( function ( a, b ) {
								return order.indexOf( a ) - order.indexOf( b );
							} );
						}
					} else {
						list.splice( index, 1 );
					}
				},

				/**
				 * Checks what can be checked here before asking the server, so the
				 * person sees problems straight away.
				 *
				 * @param {string} tab Tab key.
				 * @return {Object} Problems keyed by field path.
				 */
				quickCheck: function ( tab ) {
					var errors = {};
					var f = this.form[ tab ];
					if ( tab === 'branding' ) {
						[ 'dark', 'light' ].forEach( function ( theme ) {
							Object.keys( f.colours[ theme ] ).forEach( function ( key ) {
								if ( ! isHex( f.colours[ theme ][ key ] ) ) {
									errors[ 'branding.colours.' + theme + '.' + key ] = t.colourError;
								}
							} );
						} );
					}
					if ( tab === 'general' && ! f.order_statuses.length ) {
						errors[ 'general.order_statuses' ] = t.statusError;
					}
					return errors;
				},

				/**
				 * Saves one tab.
				 *
				 * @param {string} tab Tab key.
				 * @return {Promise|undefined}
				 */
				save: function ( tab ) {
					var self = this;
					var errors = this.quickCheck( tab );
					var body = {};

					this.notice = { tab: '', type: '', text: '' };
					this.confirmReset = '';
					if ( Object.keys( errors ).length ) {
						this.errors = errors;
						this.notice = { tab: tab, type: 'negative', text: t.fixFields };
						this.focusProblem();
						return;
					}

					body[ tab ] = this.fromForm( tab );
					var before = copy( this.saved );
					this.errors = {};
					this.busy = tab;

					return api( 'settings', { method: 'POST', body: { settings: body } } ).then( function ( saved ) {
						self.setSaved( saved, [ tab ] );
						self.notice = { tab: tab, type: 'positive', text: tab === 'branding' ? t.savedBranding : t.saved };
						return self.afterSave( tab, before, saved );
					} ).catch( function ( error ) {
						self.errors = error.fields || {};
						self.notice = { tab: tab, type: 'negative', text: error.message };
						self.focusProblem();
					} ).finally( function () {
						self.busy = '';
					} );
				},

				/**
				 * Puts one tab back to its defaults.
				 *
				 * @param {string} tab Tab key.
				 * @return {Promise}
				 */
				resetTab: function ( tab ) {
					var self = this;
					var before = copy( this.saved );
					this.confirmReset = '';
					this.errors = {};
					this.busy = tab;
					return api( 'settings/reset', { method: 'POST', body: { tab: tab } } ).then( function ( saved ) {
						self.setSaved( saved, [ tab ] );
						if ( tab === 'branding' ) {
							self.logo = { url: '', thumb: '' };
							self.previewTheme = saved.branding.default_theme;
						}
						self.notice = { tab: tab, type: 'positive', text: t.resetDone };
						return self.afterSave( tab, before, saved );
					} ).catch( function ( error ) {
						self.notice = { tab: tab, type: 'negative', text: error.message };
					} ).finally( function () {
						self.busy = '';
					} );
				},

				/**
				 * Moves focus to the first field with a problem.
				 */
				focusProblem: function () {
					var self = this;
					this.$nextTick( function () {
						var field = self.$root.querySelector( '.kdna-ei-settings-panel:not([style*="display: none"]) .is-invalid' );
						if ( field ) {
							var input = field.matches( 'input, select, textarea' ) ? field : field.querySelector( 'input, select, textarea' );
							( input || field ).focus();
						}
					} );
				},

				/**
				 * Tells the rest of the app about a saved tab.
				 *
				 * @param {string} tab    Tab key.
				 * @param {Object} before Settings before saving.
				 * @param {Object} saved  Settings now.
				 * @return {Promise|undefined}
				 */
				afterSave: function ( tab, before, saved ) {
					var self = this;
					switch ( tab ) {
						case 'branding':
							return this.applyBranding();
						case 'hero':
							config.hero = saved.hero;
							window.dispatchEvent( new window.CustomEvent( 'kdna:ei-hero-change', { detail: { type: saved.hero.type, source: 'settings' } } ) );
							return;
						case 'alerts':
							config.alerts = saved.alerts;
							return api( 'settings/context' ).then( function ( context ) {
								self.context.digest = context.digest;
							} ).catch( function () {} );
						case 'tax':
							config.tax = saved.tax;
							break;
						case 'general':
							if ( JSON.stringify( [ before.general.order_statuses, before.general.date_basis, before.general.refund_dating, before.general.restock_treatment ] ) !== JSON.stringify( [ saved.general.order_statuses, saved.general.date_basis, saved.general.refund_dating, saved.general.restock_treatment ] ) ) {
								// The figures are being re-worked: show the progress bar.
								this.refreshStatus();
								this.startPolling();
							}
							break;
					}
					if ( [ 'general', 'tax', 'marketing', 'costs' ].indexOf( tab ) !== -1 ) {
						window.dispatchEvent( new window.CustomEvent( 'kdna:ei-data-loaded', { detail: { source: 'settings', tab: tab } } ) );
					}
				},

				/*
				 * -------------------------------------------------------------
				 * General
				 * -------------------------------------------------------------
				 */

				/**
				 * Whether a rule that re-works the figures has been changed.
				 *
				 * @return {boolean}
				 */
				get rulesChanged() {
					if ( ! this.loaded ) {
						return false;
					}
					var f = this.form.general;
					var s = this.saved.general;
					return f.date_basis !== s.date_basis || f.refund_dating !== s.refund_dating || f.restock_treatment !== s.restock_treatment || f.order_statuses.slice().sort().join() !== s.order_statuses.slice().sort().join();
				},

				/*
				 * -------------------------------------------------------------
				 * Costs
				 * -------------------------------------------------------------
				 */

				/**
				 * Opens the Costs screen at one of its tabs.
				 *
				 * @param {string} tab products, fees, shipping, extras, overheads or recalc.
				 */
				openCosts: function ( tab ) {
					window.KDNAEI.router.go( 'costs' );
					window.setTimeout( function () {
						window.dispatchEvent( new window.CustomEvent( 'kdna:ei-costs-tab', { detail: tab } ) );
					}, 0 );
				},

				/*
				 * -------------------------------------------------------------
				 * Marketing
				 * -------------------------------------------------------------
				 */

				/**
				 * How much spend a channel has, as text.
				 *
				 * @param {Object} channel Channel.
				 * @return {string}
				 */
				channelUse: function ( channel ) {
					var rows = channel.key ? this.context.channel_usage[ channel.key ] || 0 : 0;
					return rows ? sprintf( t.channelRows, rows.toLocaleString() ) : ( channel.key ? t.channelUnused : t.channelNew );
				},

				/**
				 * Whether a channel has spend, so cannot be removed.
				 *
				 * @param {Object} channel Channel.
				 * @return {boolean}
				 */
				channelLocked: function ( channel ) {
					return !! channel.key && ( this.context.channel_usage[ channel.key ] || 0 ) > 0;
				},

				/**
				 * Adds an empty channel row and puts the cursor in it.
				 */
				addChannel: function () {
					var self = this;
					var row = { uid: ++uid, key: '', label: '' };
					this.form.marketing.channels.push( row );
					this.$nextTick( function () {
						var input = self.$root.querySelector( '#kdna-ei-channel-' + row.uid );
						if ( input ) {
							input.focus();
						}
					} );
				},

				/**
				 * Removes a channel row (not saved until Save is pressed).
				 *
				 * @param {number} index Row position.
				 */
				removeChannel: function ( index ) {
					if ( ! this.channelLocked( this.form.marketing.channels[ index ] ) ) {
						this.form.marketing.channels.splice( index, 1 );
					}
				},

				/**
				 * Saved CSV layouts (the built-in ones are not listed).
				 *
				 * @return {Object[]}
				 */
				get savedPresets() {
					return ( this.context.presets || [] ).filter( function ( preset ) {
						return ! preset.builtin;
					} );
				},

				/**
				 * One line about a saved CSV layout.
				 *
				 * @param {Object} preset Preset.
				 * @return {string}
				 */
				presetText: function ( preset ) {
					var channel = ( this.saved.marketing.channels || [] ).filter( function ( c ) {
						return c.key === preset.channel;
					} )[ 0 ];
					var name = channel ? channel.label : t.anyChannel;
					return preset.includes_gst ? sprintf( t.presetGst, name ) : name;
				},

				/**
				 * Deletes a saved CSV layout.
				 *
				 * @param {Object} preset Preset.
				 * @return {Promise}
				 */
				deletePreset: function ( preset ) {
					var self = this;
					this.busy = 'preset';
					return api( 'adspend/presets', { method: 'DELETE', query: { name: preset.name } } ).then( function ( data ) {
						self.context.presets = data.presets;
						self.notice = { tab: 'marketing', type: 'positive', text: sprintf( t.presetDeleted, preset.name ) };
					} ).catch( function ( error ) {
						self.notice = { tab: 'marketing', type: 'negative', text: error.message };
					} ).finally( function () {
						self.busy = '';
					} );
				},

				/**
				 * Badge colour for a connection's state.
				 *
				 * @param {Object} connection Connection.
				 * @return {string}
				 */
				connectionBadge: function ( connection ) {
					return {
						connected: 'kdna-ei-badge--positive',
						error: 'kdna-ei-badge--negative',
						ready: 'kdna-ei-badge--warning',
					}[ connection.state ] || '';
				},

				/**
				 * One line about a connection.
				 *
				 * @param {Object} connection Connection.
				 * @return {string}
				 */
				connectionText: function ( connection ) {
					if ( connection.state === 'error' ) {
						return connection.message;
					}
					if ( connection.state === 'not_set_up' ) {
						return t.notConnected;
					}
					var parts = [];
					if ( connection.account ) {
						parts.push( connection.account );
					}
					if ( connection.last_sync ) {
						parts.push( sprintf( t.lastSync, this.niceDateTime( connection.last_sync ) ) );
					}
					return parts.join( ', ' );
				},

				/*
				 * -------------------------------------------------------------
				 * Branding
				 * -------------------------------------------------------------
				 */

				/**
				 * The five brand colours, with a short note on where each is used.
				 *
				 * @return {Object[]}
				 */
				get colourKeys() {
					return [
						{ key: 'accent', label: t.colours.accent, help: t.colours.accentHelp },
						{ key: 'accent_2', label: t.colours.accent_2, help: t.colours.accent_2Help },
						{ key: 'positive', label: t.colours.positive, help: t.colours.positiveHelp },
						{ key: 'warning', label: t.colours.warning, help: t.colours.warningHelp },
						{ key: 'negative', label: t.colours.negative, help: t.colours.negativeHelp },
					];
				},

				/**
				 * The name shown in the eyebrow: the display name, or the site title.
				 *
				 * @return {string}
				 */
				get displayName() {
					return ( this.form.branding && this.form.branding.store_name.trim() ) || this.context.site_name || '';
				},

				/**
				 * First letter of the name, shown when there is no logo.
				 *
				 * @return {string}
				 */
				get initial() {
					return this.displayName.charAt( 0 ).toUpperCase();
				},

				/**
				 * CSS variables for the preview card, from the form as it is now.
				 *
				 * @return {string}
				 */
				get previewStyle() {
					if ( ! this.form.branding ) {
						return '';
					}
					var theme = this.previewTheme === 'light' ? 'light' : 'dark';
					var base = BASE[ theme ];
					var colours = this.form.branding.colours[ theme ];
					var defaults = ( this.context.default_colours || {} )[ theme ] || {};
					var font = this.form.branding.font;
					var family = font === 'inherit' ? SYSTEM_FONT : '"' + ( this.context.fonts[ font ] || 'Figtree' ) + '", sans-serif';
					var pick = function ( key ) {
						return isHex( colours[ key ] ) ? colours[ key ] : defaults[ key ];
					};
					return [
						'--kdna-ei-bg:' + base.bg,
						'--kdna-ei-surface:' + base.surface,
						'--kdna-ei-surface-raised:' + base.raised,
						'--kdna-ei-border:' + base.border,
						'--kdna-ei-text:' + base.text,
						'--kdna-ei-text-muted:' + base.muted,
						'--kdna-ei-accent:' + pick( 'accent' ),
						'--kdna-ei-accent-2:' + pick( 'accent_2' ),
						'--kdna-ei-positive:' + pick( 'positive' ),
						'--kdna-ei-warning:' + pick( 'warning' ),
						'--kdna-ei-negative:' + pick( 'negative' ),
						'font-family:' + family,
					].join( ';' );
				},

				/**
				 * A gentle warning when a colour is hard to see on the cards.
				 *
				 * @param {string} theme dark or light.
				 * @param {string} key   Colour key.
				 * @return {string}
				 */
				contrastWarning: function ( theme, key ) {
					var value = this.form.branding.colours[ theme ][ key ];
					if ( ! isHex( value ) ) {
						return '';
					}
					// The second accent is only used for thin comparison lines and
					// small segments, so it can be softer than the others.
					var needed = key === 'accent_2' ? 2.2 : 3;
					return contrast( value, BASE[ theme ].surface ) < needed ? ( theme === 'dark' ? t.lowContrastDark : t.lowContrastLight ) : '';
				},

				/**
				 * Opens the Media Library to choose a logo.
				 */
				chooseLogo: function () {
					var self = this;
					if ( ! window.wp || ! window.wp.media ) {
						this.notice = { tab: 'branding', type: 'negative', text: t.noMedia };
						return;
					}
					var frame = window.wp.media( {
						title: t.logoTitle,
						button: { text: t.logoButton },
						library: { type: 'image' },
						multiple: false,
					} );
					frame.on( 'select', function () {
						var image = frame.state().get( 'selection' ).first().toJSON();
						var sizes = image.sizes || {};
						self.form.branding.logo_id = image.id;
						self.logo = {
							url: ( sizes.medium || sizes.full || image ).url,
							thumb: ( sizes.thumbnail || sizes.medium || image ).url,
						};
					} );
					frame.open();
				},

				/**
				 * Takes the logo off (not saved until Save is pressed).
				 */
				removeLogo: function () {
					this.form.branding.logo_id = 0;
					this.logo = { url: '', thumb: '' };
				},

				/**
				 * After saving Branding: swaps the brand CSS and custom CSS on the
				 * page, updates the logo and name, and redraws every chart.
				 *
				 * @return {Promise}
				 */
				applyBranding: function () {
					var self = this;
					return api( 'settings/context' ).then( function ( context ) {
						self.context = Object.assign( {}, self.context, context );
						self.logo = { url: context.logo.url, thumb: context.logo.thumb };
						self.replaceStyle( 'kdna-ei-tokens-inline-css', context.branding_css );
						self.replaceStyle( 'kdna-ei-custom-inline-css', context.custom_css );

						var name = self.displayName;
						config.storeName = name;
						var eyebrow = document.querySelector( '.kdna-ei-eyebrow span' );
						if ( eyebrow ) {
							eyebrow.textContent = name;
						}
						var brand = document.querySelector( '.kdna-ei-sidebar__brand' );
						if ( brand ) {
							brand.title = name;
							brand.textContent = '';
							if ( context.logo.thumb ) {
								var img = document.createElement( 'img' );
								img.src = context.logo.thumb;
								img.alt = name;
								brand.appendChild( img );
							} else {
								var span = document.createElement( 'span' );
								span.setAttribute( 'aria-hidden', 'true' );
								span.textContent = self.initial;
								brand.appendChild( span );
							}
						}

						// Charts read the colours when they draw, so redraw them all.
						window.dispatchEvent( new window.CustomEvent( 'kdna:ei-theme-change', { detail: { theme: self.theme, source: 'branding' } } ) );
					} ).catch( function () {} );
				},

				/**
				 * Replaces the text of one of the page's style blocks, creating it
				 * if WordPress did not print it.
				 *
				 * @param {string} id  Style element ID.
				 * @param {string} css New CSS.
				 */
				replaceStyle: function ( id, css ) {
					var style = document.getElementById( id );
					if ( ! style ) {
						style = document.createElement( 'style' );
						style.id = id;
						document.head.appendChild( style );
					}
					style.textContent = css || '';
				},

				/*
				 * -------------------------------------------------------------
				 * Hero card and Goals
				 * -------------------------------------------------------------
				 */

				/**
				 * Whether the goal being tracked has a target.
				 *
				 * @return {boolean}
				 */
				get goalSet() {
					var f = this.form.hero;
					if ( ! f ) {
						return true;
					}
					var value = format.parseAmount( String( f[ f.goal_metric ] || '' ) );
					return !! value && value > 0;
				},

				/*
				 * -------------------------------------------------------------
				 * Alerts and digests
				 * -------------------------------------------------------------
				 */

				/**
				 * One line about when the next digest goes out.
				 *
				 * @return {string}
				 */
				get digestText() {
					var digest = this.context.digest;
					var saved = this.saved.alerts || {};
					if ( ! digest || saved.digest_frequency === 'off' ) {
						return t.digestOff;
					}
					if ( ! digest.next_send ) {
						return t.digestSoon;
					}
					return sprintf( t.digestNext, this.niceDateTime( digest.next_send ) );
				},

				/**
				 * Sends a test low stock email to the saved alert addresses.
				 *
				 * @return {Promise}
				 */
				testAlert: function () {
					var self = this;
					this.busy = 'test-alert';
					return api( 'inventory/test-alert', { method: 'POST' } ).then( function ( data ) {
						self.notice = { tab: 'alerts', type: data.sent ? 'positive' : 'warning', text: data.message };
					} ).catch( function ( error ) {
						self.notice = { tab: 'alerts', type: 'negative', text: error.message };
					} ).finally( function () {
						self.busy = '';
					} );
				},

				/**
				 * Sends a test digest to the addresses in the form (saved or not).
				 *
				 * @return {Promise}
				 */
				testDigest: function () {
					var self = this;
					var f = this.form.alerts;
					this.busy = 'test-digest';
					this.errors = {};
					return api( 'digest/test', {
						method: 'POST',
						body: { frequency: f.digest_frequency === 'monthly' ? 'monthly' : 'weekly', recipients: f.digest_recipients, sections: f.digest_sections },
					} ).then( function ( data ) {
						self.notice = { tab: 'alerts', type: 'positive', text: data.message };
					} ).catch( function ( error ) {
						var fields = {};
						Object.keys( error.fields || {} ).forEach( function ( key ) {
							fields[ 'alerts.digest_' + key ] = error.fields[ key ];
						} );
						self.errors = fields;
						self.notice = { tab: 'alerts', type: 'negative', text: error.message };
					} ).finally( function () {
						self.busy = '';
					} );
				},

				/*
				 * -------------------------------------------------------------
				 * Data: sync log
				 * -------------------------------------------------------------
				 */

				/**
				 * Loads one page of the sync log with the chosen filters.
				 *
				 * @param {number} page Page number.
				 * @return {Promise}
				 */
				loadLog: function ( page ) {
					var self = this;
					this.log.loading = true;
					this.log.error = '';
					return api( 'log', { query: { page: Math.max( 1, page || 1 ), per_page: 25, type: this.log.type, status: this.log.status } } ).then( function ( data ) {
						self.log.rows = data.rows;
						self.log.page = data.page;
						self.log.pages = data.pages;
						self.log.total = data.total;
						self.log.types = data.types;
						self.log.loaded = true;
					} ).catch( function ( error ) {
						self.log.error = error.message;
					} ).finally( function () {
						self.log.loading = false;
					} );
				},

				/**
				 * Downloads the whole log as a CSV file.
				 */
				exportLog: function () {
					var self = this;
					window.KDNAEI.exportCsv( 'activity', this.rangeQuery ).catch( function ( error ) {
						self.log.error = error.message;
					} );
				},
			};
		} );
	} );
}( window, document ) );
