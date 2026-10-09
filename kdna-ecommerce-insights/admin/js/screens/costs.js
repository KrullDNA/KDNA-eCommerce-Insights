/**
 * KDNA eCommerce Insights: Costs screen, product cost editor.
 *
 * A spreadsheet-style list of every product and variation. Costs can be
 * typed straight into the table, margins update as you type, and all
 * changes are saved together. Also handles CSV export and import, and
 * importing costs from another cost plugin.
 */
( function ( window, document ) {
	'use strict';

	var config = window.kdnaEiApp || {};
	var i18n = ( config.i18n && config.i18n.costs ) || {};
	var format = window.KDNAEI.format;
	var api = window.KDNAEI.api;

	/**
	 * How many cost changes are sent to the server in one request.
	 */
	var SAVE_BATCH = 100;

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

	document.addEventListener( 'alpine:init', function () {
		window.Alpine.data( 'kdnaEiCosts', function () {
			return {
				i18nCosts: i18n,
				loaded: false,
				loading: false,
				loadError: '',
				requestId: 0,

				rows: [],
				total: 0,
				page: 1,
				pages: 1,
				perPage: 50,
				summary: { products: 0, missing: 0, with_cost: 0, average_margin: null },
				categories: [],
				native: false,

				filters: {
					search: '',
					category: '',
					missing: false,
					stock_status: '',
				},

				// Unsaved edits keyed by product ID: { value: typed text, original: saved cost }.
				edits: {},
				saving: false,
				saveDone: 0,
				saveTotal: 0,
				notice: null,

				csv: {
					open: false,
					step: 'choose',
					busy: false,
					error: '',
					fileName: '',
					result: null,
					view: 'change',
					saved: 0,
					saveErrors: [],
				},

				plugins: {
					open: false,
					busy: false,
					error: '',
					sources: [],
					source: '',
					overwrite: false,
					progress: 0,
					total: 0,
					imported: 0,
					skipped: 0,
					done: false,
				},

				/**
				 * Sets up the screen: warns before leaving with unsaved changes, and
				 * listens for the Overview's "Add costs" alert.
				 */
				init: function () {
					var self = this;
					window.addEventListener( 'kdna:ei-costs-show-missing', function () {
						self.showMissing();
					} );
					window.addEventListener( 'beforeunload', function ( event ) {
						if ( self.dirtyCount > 0 ) {
							event.preventDefault();
							event.returnValue = '';
						}
					} );
				},

				/**
				 * Loads the product list the first time the Costs screen is opened.
				 */
				ensureLoaded: function () {
					if ( ! this.loaded && ! this.loading ) {
						this.load();
					}
				},

				/**
				 * Fetches the current page of products with the chosen filters.
				 * Older responses are ignored if a newer search has started.
				 *
				 * @return {Promise}
				 */
				load: function () {
					var self = this;
					var requestId = ++this.requestId;

					this.loading = true;
					this.loadError = '';

					return api( 'costs', {
						query: {
							search: this.filters.search,
							category: this.filters.category,
							missing: this.filters.missing ? 1 : '',
							stock_status: this.filters.stock_status,
							page: this.page,
							per_page: this.perPage,
						},
					} ).then( function ( data ) {
						if ( requestId !== self.requestId ) {
							return;
						}
						self.rows = data.rows || [];
						self.total = data.total || 0;
						self.page = data.page || 1;
						self.pages = data.pages || 1;
						self.summary = data.summary || self.summary;
						self.categories = data.categories || [];
						self.native = !! data.native;
						self.loaded = true;
					} ).catch( function ( error ) {
						if ( requestId === self.requestId ) {
							self.loadError = error.message;
						}
					} ).finally( function () {
						if ( requestId === self.requestId ) {
							self.loading = false;
						}
					} );
				},

				/**
				 * Reloads from page one after a filter changes.
				 */
				applyFilters: function () {
					this.page = 1;
					this.load();
				},

				/**
				 * Shows only products missing a cost (the summary card shortcut).
				 */
				showMissing: function () {
					this.filters.missing = true;
					this.applyFilters();
				},

				/**
				 * Clears every filter and the search box.
				 */
				resetFilters: function () {
					this.filters = { search: '', category: '', missing: false, stock_status: '' };
					this.applyFilters();
				},

				/**
				 * Moves to another page of results. Unsaved edits are kept.
				 *
				 * @param {number} page Page number.
				 */
				goToPage: function ( page ) {
					if ( page < 1 || page > this.pages || page === this.page ) {
						return;
					}
					this.page = page;
					this.load().then( function () {
						window.scrollTo( { top: 0, behavior: 'smooth' } );
					} );
				},

				/**
				 * True when any filter is active.
				 *
				 * @return {boolean}
				 */
				get filtered() {
					return this.filters.search !== '' || this.filters.category !== '' || this.filters.missing || this.filters.stock_status !== '';
				},

				/*
				 * -------------------------------------------------------------
				 * Editing costs
				 * -------------------------------------------------------------
				 */

				/**
				 * The text shown in a row's cost box: the unsaved edit if there
				 * is one, otherwise the saved cost.
				 *
				 * @param {Object} row Product row.
				 * @return {string}
				 */
				inputValue: function ( row ) {
					var edit = this.edits[ row.id ];
					return edit ? edit.value : format.inputAmount( row.own_cost );
				},

				/**
				 * Records a typed cost. If it matches the saved cost again, the
				 * edit is dropped so it no longer counts as a change.
				 *
				 * @param {Object} row   Product row.
				 * @param {string} value Typed text.
				 */
				setCost: function ( row, value ) {
					var original = this.edits[ row.id ] ? this.edits[ row.id ].original : row.own_cost;
					var parsed = format.parseAmount( value );
					var edits = Object.assign( {}, this.edits );

					if ( ( parsed === null && original === null ) || ( parsed !== null && original !== null && Math.abs( parsed - original ) < 0.00005 ) ) {
						delete edits[ row.id ];
					} else {
						edits[ row.id ] = { value: value, original: original };
					}

					this.edits = edits;
				},

				/**
				 * The cost a row currently has, including unsaved edits.
				 * Returns NaN while the typed value is not a valid number.
				 *
				 * @param {Object} row Product row.
				 * @return {number|null}
				 */
				ownCost: function ( row ) {
					var edit = this.edits[ row.id ];
					return edit ? format.parseAmount( edit.value ) : row.own_cost;
				},

				/**
				 * Finds the parent row of a variation on the current page.
				 *
				 * @param {Object} row Variation row.
				 * @return {Object|null}
				 */
				parentOf: function ( row ) {
					if ( ! row.parent_id ) {
						return null;
					}
					for ( var i = 0; i < this.rows.length; i++ ) {
						if ( this.rows[ i ].id === row.parent_id ) {
							return this.rows[ i ];
						}
					}
					return null;
				},

				/**
				 * The parent cost a variation would use, including unsaved edits.
				 *
				 * @param {Object} row Variation row.
				 * @return {number|null}
				 */
				parentCost: function ( row ) {
					var parent = this.parentOf( row );
					if ( ! parent ) {
						return row.inherited ? row.effective_cost : null;
					}
					var cost = this.ownCost( parent );
					return isNaN( cost ) ? null : cost;
				},

				/**
				 * The cost actually used for a row: its own cost, or for a
				 * variation without one, the parent cost.
				 *
				 * @param {Object} row Product row.
				 * @return {number|null}
				 */
				effectiveCost: function ( row ) {
					var own = this.ownCost( row );
					if ( isNaN( own ) ) {
						return null;
					}
					if ( row.type !== 'variation' ) {
						return own;
					}
					var parent = this.parentCost( row );
					if ( row.additive && this.native ) {
						return own === null && parent === null ? null : ( own || 0 ) + ( parent || 0 );
					}
					return own !== null ? own : parent;
				},

				/**
				 * The margin as a percentage of the price before tax, worked out
				 * live from whatever cost is typed.
				 *
				 * @param {Object} row Product row.
				 * @return {number|null}
				 */
				margin: function ( row ) {
					var cost = this.effectiveCost( row );
					if ( cost === null || row.price_net === null || row.price_net <= 0 ) {
						return null;
					}
					return Math.round( ( row.price_net - cost ) / row.price_net * 1000 ) / 10;
				},

				/**
				 * Colour class for a margin: negative when selling at a loss,
				 * warning when thin (under 20%).
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

				/**
				 * Placeholder text for an empty cost box.
				 *
				 * @param {Object} row Product row.
				 * @return {string}
				 */
				placeholder: function ( row ) {
					if ( row.type === 'variation' ) {
						var parent = this.parentCost( row );
						return parent === null ? i18n.noCost : sprintf( i18n.parentCost, format.money( parent ) );
					}
					return row.sellable ? i18n.noCost : i18n.defaultForVariations;
				},

				/**
				 * Whether a row's typed value is not a valid cost.
				 *
				 * @param {Object} row Product row.
				 * @return {boolean}
				 */
				isInvalid: function ( row ) {
					var cost = this.ownCost( row );
					return ( cost !== null && isNaN( cost ) ) || cost < 0;
				},

				/**
				 * Whether a row has an unsaved change.
				 *
				 * @param {Object} row Product row.
				 * @return {boolean}
				 */
				isDirty: function ( row ) {
					return !! this.edits[ row.id ];
				},

				/**
				 * Whether a row still has no cost anywhere, including unsaved edits.
				 *
				 * @param {Object} row Product row.
				 * @return {boolean}
				 */
				isMissing: function ( row ) {
					return row.sellable && this.effectiveCost( row ) === null && ! this.isInvalid( row );
				},

				/**
				 * Number of products with unsaved changes, across all pages.
				 *
				 * @return {number}
				 */
				get dirtyCount() {
					return Object.keys( this.edits ).length;
				},

				/**
				 * Number of unsaved changes that are not valid numbers.
				 *
				 * @return {number}
				 */
				get invalidCount() {
					var edits = this.edits;
					return Object.keys( edits ).filter( function ( id ) {
						var value = format.parseAmount( edits[ id ].value );
						return value !== null && ( isNaN( value ) || value < 0 );
					} ).length;
				},

				/**
				 * Moves to the next cost box when Enter is pressed, like a spreadsheet.
				 *
				 * @param {KeyboardEvent} event Key press.
				 */
				nextInput: function ( event ) {
					var inputs = Array.prototype.slice.call( this.$root.querySelectorAll( '.kdna-ei-cost-input' ) );
					var index = inputs.indexOf( event.target );
					if ( index > -1 && inputs[ index + 1 ] ) {
						inputs[ index + 1 ].focus();
						inputs[ index + 1 ].select();
					}
				},

				/**
				 * Throws away every unsaved change.
				 */
				discard: function () {
					this.edits = {};
				},

				/**
				 * Saves every unsaved change in batches, showing progress, then
				 * reloads the list so inherited costs and margins are up to date.
				 *
				 * @return {Promise}
				 */
				save: function () {
					var self = this;

					if ( this.saving || ! this.dirtyCount ) {
						return Promise.resolve();
					}
					if ( this.invalidCount ) {
						this.notice = { type: 'negative', text: i18n.fixInvalid };
						return Promise.resolve();
					}

					var items = Object.keys( this.edits ).map( function ( id ) {
						return { id: Number( id ), cost: format.parseAmount( self.edits[ id ].value ) };
					} );

					return this.sendItems( items ).then( function ( result ) {
						self.edits = {};
						self.notice = result.errors.length
							? { type: 'warning', text: sprintf( i18n.savedWithErrors, result.saved, result.errors.length ), errors: result.errors }
							: { type: 'positive', text: sprintf( result.saved === 1 ? i18n.savedOne : i18n.saved, result.saved ) };
						return self.load();
					} );
				},

				/**
				 * Sends cost changes to the server in batches and adds up the results.
				 * Used by Save and by the CSV import.
				 *
				 * @param {Object[]} items Changes: { id, cost }.
				 * @return {Promise<Object>} { saved, unchanged, errors }.
				 */
				sendItems: function ( items ) {
					var self = this;
					var totals = { saved: 0, unchanged: 0, errors: [] };
					var batches = [];

					for ( var i = 0; i < items.length; i += SAVE_BATCH ) {
						batches.push( items.slice( i, i + SAVE_BATCH ) );
					}

					this.saving = true;
					this.saveDone = 0;
					this.saveTotal = items.length;
					this.notice = null;

					return batches.reduce( function ( chain, batch ) {
						return chain.then( function () {
							return api( 'costs', { method: 'POST', body: { items: batch } } ).then( function ( data ) {
								totals.saved += data.saved || 0;
								totals.unchanged += data.unchanged || 0;
								totals.errors = totals.errors.concat( data.errors || [] );
								self.saveDone += batch.length;
								if ( data.summary ) {
									self.summary = data.summary;
								}
							} );
						} );
					}, Promise.resolve() ).then( function () {
						return totals;
					} ).catch( function ( error ) {
						self.notice = { type: 'negative', text: sprintf( i18n.saveFailed, error.message ) };
						throw error;
					} ).finally( function () {
						self.saving = false;
					} );
				},

				/*
				 * -------------------------------------------------------------
				 * Display helpers
				 * -------------------------------------------------------------
				 */

				money: format.money,
				percent: format.percent,

				/**
				 * Badge style and label for a stock status.
				 *
				 * @param {Object} row Product row.
				 * @return {{ cls: string, label: string }}
				 */
				stock: function ( row ) {
					var labels = i18n.stock || {};
					var map = {
						instock: 'kdna-ei-badge--positive',
						outofstock: 'kdna-ei-badge--negative',
						onbackorder: 'kdna-ei-badge--warning',
					};
					var label = labels[ row.stock_status ] || row.stock_status;
					if ( row.stock !== null && row.stock !== undefined && row.manage_stock !== false && row.stock_status === 'instock' ) {
						label = sprintf( i18n.inStockCount, format.number( row.stock, 0 ) );
					}
					return { cls: map[ row.stock_status ] || '', label: label };
				},

				/**
				 * The "1 to 50 of 230 products" line under the table.
				 *
				 * @return {string}
				 */
				get rangeText() {
					if ( ! this.total ) {
						return '';
					}
					var from = ( this.page - 1 ) * this.perPage + 1;
					var to = Math.min( this.total, this.page * this.perPage );
					return sprintf( i18n.showing, from, to, this.total );
				},

				/*
				 * -------------------------------------------------------------
				 * CSV export and import
				 * -------------------------------------------------------------
				 */

				/**
				 * Downloads every product and variation cost as a CSV file.
				 */
				exportCsv: function () {
					var self = this;
					this.notice = null;
					api( 'costs/export' ).then( function ( data ) {
						window.KDNAEI.download( data.filename, data.csv );
					} ).catch( function ( error ) {
						self.notice = { type: 'negative', text: error.message };
					} );
				},

				/**
				 * Opens the CSV import window at the first step.
				 */
				openCsv: function () {
					var self = this;
					this.csv = { open: true, step: 'choose', busy: false, error: '', fileName: '', result: null, view: 'change', saved: 0, saveErrors: [] };
					this.$nextTick( function () {
						self.$refs.csvDialog.focus();
					} );
				},

				/**
				 * Closes the CSV import window, unless it is busy saving.
				 */
				closeCsv: function () {
					if ( ! this.csv.busy ) {
						this.csv.open = false;
					}
				},

				/**
				 * Reads the chosen CSV file and asks the server for a preview of
				 * what it would change. Nothing is saved yet.
				 *
				 * @param {Event} event File input change.
				 */
				previewCsv: function ( event ) {
					var self = this;
					var file = event.target.files && event.target.files[ 0 ];
					event.target.value = '';

					if ( ! file ) {
						return;
					}
					if ( file.size > 5 * 1024 * 1024 ) {
						this.csv.error = i18n.fileTooLarge;
						return;
					}

					this.csv.busy = true;
					this.csv.error = '';
					this.csv.fileName = file.name;

					file.text().then( function ( text ) {
						return api( 'costs/import/preview', { method: 'POST', body: { csv: text } } );
					} ).then( function ( result ) {
						self.csv.result = result;
						self.csv.step = 'preview';
						self.csv.view = result.counts.change ? 'change' : ( result.counts.error ? 'error' : 'all' );
					} ).catch( function ( error ) {
						self.csv.error = error.message;
					} ).finally( function () {
						self.csv.busy = false;
					} );
				},

				/**
				 * Preview rows for the chosen tab (changes, problems or all).
				 * Shows the first 500 so very large files stay quick.
				 *
				 * @return {Object[]}
				 */
				get csvRows() {
					var result = this.csv.result;
					var view = this.csv.view;
					if ( ! result ) {
						return [];
					}
					return result.rows.filter( function ( row ) {
						return view === 'all' || row.status === view;
					} ).slice( 0, 500 );
				},

				/**
				 * Saves the changes listed in the CSV preview.
				 */
				applyCsv: function () {
					var self = this;
					var changes = this.csv.result ? this.csv.result.changes : [];

					if ( ! changes.length ) {
						return;
					}

					this.csv.busy = true;
					this.sendItems( changes ).then( function ( result ) {
						self.csv.step = 'done';
						self.csv.saved = result.saved;
						self.csv.saveErrors = result.errors;
						self.edits = {};
						return self.load();
					} ).catch( function ( error ) {
						self.csv.error = error.message;
					} ).finally( function () {
						self.csv.busy = false;
					} );
				},

				/*
				 * -------------------------------------------------------------
				 * Import from another cost plugin
				 * -------------------------------------------------------------
				 */

				/**
				 * Opens the plugin import window and checks which cost plugins
				 * have costs saved on this store.
				 */
				openPlugins: function () {
					var self = this;
					this.plugins = { open: true, busy: true, error: '', sources: [], source: '', overwrite: false, progress: 0, total: 0, imported: 0, skipped: 0, done: false };
					this.$nextTick( function () {
						self.$refs.pluginsDialog.focus();
					} );

					api( 'costs/plugin-import' ).then( function ( sources ) {
						self.plugins.sources = sources;
						var found = sources.filter( function ( source ) {
							return source.count > 0;
						} );
						self.plugins.source = found.length ? found[ 0 ].key : '';
					} ).catch( function ( error ) {
						self.plugins.error = error.message;
					} ).finally( function () {
						self.plugins.busy = false;
					} );
				},

				/**
				 * Closes the plugin import window, unless an import is running.
				 */
				closePlugins: function () {
					if ( ! this.plugins.busy ) {
						this.plugins.open = false;
					}
				},

				/**
				 * Runs the import from the chosen plugin one batch at a time,
				 * updating the progress bar, then reloads the list.
				 */
				runPluginImport: function () {
					var self = this;
					var plugins = this.plugins;

					if ( ! plugins.source || plugins.busy ) {
						return;
					}

					plugins.busy = true;
					plugins.error = '';
					plugins.progress = 0;
					plugins.imported = 0;
					plugins.skipped = 0;
					plugins.done = false;

					var step = function ( offset ) {
						return api( 'costs/plugin-import', {
							method: 'POST',
							body: { source: plugins.source, offset: offset, overwrite: plugins.overwrite },
						} ).then( function ( data ) {
							plugins.total = data.total;
							plugins.progress = data.next_offset;
							plugins.imported += data.imported;
							plugins.skipped += data.skipped;
							return data.done ? null : step( data.next_offset );
						} );
					};

					step( 0 ).then( function () {
						plugins.done = true;
						return self.load();
					} ).catch( function ( error ) {
						plugins.error = error.message;
					} ).finally( function () {
						plugins.busy = false;
					} );
				},

				/**
				 * Progress of the running plugin import, as a percentage.
				 *
				 * @return {number}
				 */
				get pluginPercent() {
					return this.plugins.total ? Math.round( this.plugins.progress / this.plugins.total * 100 ) : 0;
				},

				sprintf: sprintf,
			};
		} );
	} );
}( window, document ) );
