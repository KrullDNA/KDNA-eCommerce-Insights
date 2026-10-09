/**
 * KDNA eCommerce Insights: Costs screen, Overheads tab.
 *
 * Lists overheads with their cost per month, and adds, edits and deletes
 * them in a pop-up form. Totals for this month and this year come from the
 * server, which spreads each overhead evenly by day.
 */
( function ( window, document ) {
	'use strict';

	var config = window.kdnaEiApp || {};
	var t = ( config.i18n && config.i18n.overheads ) || {};
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
	 * Today's date as Y-m-d in the visitor's time zone.
	 *
	 * @return {string}
	 */
	function today() {
		var now = new Date();
		var pad = function ( n ) {
			return ( n < 10 ? '0' : '' ) + n;
		};
		return now.getFullYear() + '-' + pad( now.getMonth() + 1 ) + '-' + pad( now.getDate() );
	}

	/**
	 * Formats a Y-m-d date for display, for example "9 Oct 2026".
	 *
	 * @param {string} value Date.
	 * @return {string}
	 */
	function niceDate( value ) {
		if ( ! value ) {
			return '';
		}
		var parts = value.split( '-' );
		var date = new Date( Number( parts[ 0 ] ), Number( parts[ 1 ] ) - 1, Number( parts[ 2 ] ) );
		return date.toLocaleDateString( config.locale || undefined, { day: 'numeric', month: 'short', year: 'numeric' } );
	}

	/**
	 * A blank form for a new overhead.
	 *
	 * @return {Object}
	 */
	function emptyForm() {
		return {
			id: 0,
			name: '',
			categoryChoice: 'software',
			customCategory: '',
			amount: '',
			frequency: 'monthly',
			start_date: today().slice( 0, 8 ) + '01',
			end_date: '',
			includes_gst: false,
		};
	}

	document.addEventListener( 'alpine:init', function () {
		window.Alpine.data( 'kdnaEiOverheads', function () {
			return {
				t: t,
				loaded: false,
				loading: false,
				overheads: [],
				categories: {},
				frequencies: t.frequencies || {},
				thisMonth: { net: 0, tax: 0 },
				thisYear: { net: 0, tax: 0 },
				notice: null,

				formOpen: false,
				form: emptyForm(),
				errors: {},
				formError: '',
				busy: false,
				confirmDelete: false,

				/**
				 * Loads the overheads the first time the tab is opened.
				 */
				ensureLoaded: function () {
					if ( ! this.loaded && ! this.loading ) {
						this.load();
					}
				},

				/**
				 * Fetches every overhead and the totals.
				 *
				 * @return {Promise}
				 */
				load: function () {
					var self = this;
					this.loading = true;

					return api( 'overheads' ).then( function ( data ) {
						self.overheads = data.overheads || [];
						self.categories = data.categories || {};
						self.thisMonth = data.this_month || self.thisMonth;
						self.thisYear = data.this_year || self.thisYear;
						self.loaded = true;
					} ).catch( function ( error ) {
						self.notice = { type: 'negative', text: error.message };
					} ).finally( function () {
						self.loading = false;
					} );
				},

				money: format.money,
				sprintf: sprintf,

				/**
				 * Number of recurring overheads running today.
				 *
				 * @return {number}
				 */
				get activeCount() {
					var day = today();
					return this.overheads.filter( function ( item ) {
						return item.frequency !== 'one_off' && item.start_date <= day && ( ! item.end_date || item.end_date >= day );
					} ).length;
				},

				/**
				 * Whether an overhead has finished (ended, or a one-off in the past).
				 *
				 * @param {Object} item Overhead.
				 * @return {boolean}
				 */
				isEnded: function ( item ) {
					var day = today();
					return item.frequency === 'one_off' ? item.start_date < day : ( !! item.end_date && item.end_date < day );
				},

				/**
				 * Display name for a category key or a custom category.
				 *
				 * @param {string} key Category.
				 * @return {string}
				 */
				categoryLabel: function ( key ) {
					return this.categories[ key ] || key;
				},

				/**
				 * Display name for a frequency.
				 *
				 * @param {string} key Frequency.
				 * @return {string}
				 */
				frequencyLabel: function ( key ) {
					return this.frequencies[ key ] || key;
				},

				/**
				 * The dates column: "From 1 Jan 2026", "1 Jan to 30 Jun 2026" or the date paid.
				 *
				 * @param {Object} item Overhead.
				 * @return {string}
				 */
				dateText: function ( item ) {
					if ( item.frequency === 'one_off' ) {
						return sprintf( t.paidDate, niceDate( item.start_date ) );
					}
					return item.end_date
						? sprintf( t.fromTo, niceDate( item.start_date ), niceDate( item.end_date ) )
						: sprintf( t.from, niceDate( item.start_date ) );
				},

				/**
				 * Plain-English summary of the overhead being typed, shown under the form.
				 *
				 * @return {string}
				 */
				get preview() {
					var amount = format.parseAmount( this.form.amount );
					if ( amount === null || isNaN( amount ) || ! this.form.frequency ) {
						return '';
					}
					var perDay = {
						weekly: amount / 7,
						monthly: amount * 12 / 365,
						quarterly: amount * 4 / 365,
						yearly: amount / 365,
					}[ this.form.frequency ];

					return perDay === undefined
						? sprintf( t.previewOneOff, format.money( amount ) )
						: sprintf( t.previewRecurring, format.money( perDay ) );
				},

				/**
				 * Opens the form, empty for a new overhead or filled in to edit one.
				 *
				 * @param {Object} item Overhead to edit, or nothing to add.
				 */
				openForm: function ( item ) {
					var self = this;
					var form = emptyForm();

					if ( item ) {
						var known = Object.prototype.hasOwnProperty.call( this.categories, item.category );
						form = {
							id: item.id,
							name: item.name,
							categoryChoice: known ? item.category : '__custom',
							customCategory: known ? '' : item.category,
							amount: format.inputAmount( item.amount ),
							frequency: item.frequency,
							start_date: item.start_date,
							end_date: item.end_date || '',
							includes_gst: item.includes_gst,
						};
					}

					this.form = form;
					this.errors = {};
					this.formError = '';
					this.confirmDelete = false;
					this.formOpen = true;

					this.$nextTick( function () {
						self.$refs.nameInput.focus();
					} );
				},

				/**
				 * Hides a field's error message as soon as the person starts
				 * correcting it.
				 *
				 * @param {Event} event Input or change event from a form field.
				 */
				clearError: function ( event ) {
					var model = event.target.getAttribute( 'x-model' ) || '';
					var field = model.replace( 'form.', '' );
					if ( field === 'categoryChoice' || field === 'customCategory' ) {
						field = 'category';
					}
					if ( this.errors[ field ] ) {
						var errors = Object.assign( {}, this.errors );
						delete errors[ field ];
						this.errors = errors;
						if ( ! Object.keys( errors ).length ) {
							this.formError = '';
						}
					}
				},

				/**
				 * Closes the form unless it is busy saving.
				 */
				closeForm: function () {
					if ( ! this.busy ) {
						this.formOpen = false;
					}
				},

				/**
				 * Saves the form as a new overhead or changes to an existing one.
				 *
				 * @return {Promise}
				 */
				submit: function () {
					var self = this;
					var form = this.form;
					var amount = format.parseAmount( form.amount );
					var body = {
						name: form.name,
						category: form.categoryChoice === '__custom' ? form.customCategory : form.categoryChoice,
						amount: amount === null || isNaN( amount ) ? String( form.amount ) : amount,
						frequency: form.frequency,
						start_date: form.start_date,
						end_date: form.frequency === 'one_off' ? null : ( form.end_date || null ),
						includes_gst: !! form.includes_gst,
					};

					this.busy = true;
					this.errors = {};
					this.formError = '';

					return api( form.id ? 'overheads/' + form.id : 'overheads', { method: 'POST', body: body } ).then( function () {
						self.formOpen = false;
						self.notice = { type: 'positive', text: form.id ? t.updated : t.added };
						return self.load();
					} ).catch( function ( error ) {
						self.errors = error.fields || {};
						self.formError = error.message;
					} ).finally( function () {
						self.busy = false;
					} );
				},

				/**
				 * Deletes the overhead being edited, after the person confirms.
				 *
				 * @return {Promise}
				 */
				remove: function () {
					var self = this;
					var id = this.form.id;

					this.busy = true;
					return api( 'overheads/' + id, { method: 'DELETE' } ).then( function () {
						self.formOpen = false;
						self.notice = { type: 'positive', text: t.deleted };
						return self.load();
					} ).catch( function ( error ) {
						self.formError = error.message;
					} ).finally( function () {
						self.busy = false;
						self.confirmDelete = false;
					} );
				},
			};
		} );
	} );
}( window, document ) );
