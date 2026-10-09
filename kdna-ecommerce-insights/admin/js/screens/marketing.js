/**
 * KDNA eCommerce Insights: Marketing screen.
 *
 * Ad spend KPIs, spend by channel over time, the channel and campaign
 * tables, the entries list with edit and delete, the Add spend form and the
 * CSV import (choose a file, check the columns, preview, import). Every
 * change reloads the screen and the rest of Insights, because ad spend
 * comes off net profit everywhere.
 */
( function ( window, document ) {
	'use strict';

	var config = window.kdnaEiApp || {};
	var t = ( config.i18n && config.i18n.marketing ) || {};
	var format = window.KDNAEI.format;
	var dates = window.KDNAEI.dates;
	var api = window.KDNAEI.api;
	var charts = window.KDNAEI.charts;
	var sprintf = window.KDNAEI.sprintf;

	/**
	 * KPIs in the strip, with icons.
	 */
	var KPIS = [
		{ key: 'ad_spend', icon: 'megaphone' },
		{ key: 'roas', icon: 'trending-up' },
		{ key: 'mer', icon: 'chart' },
		{ key: 'cpa', icon: 'users-plus' },
		{ key: 'profit_after_ads', icon: 'coins' },
	];

	/**
	 * Colours given to channels in order, as chart tokens and CSS variables.
	 */
	var COLOURS = [
		{ chart: 'accent', css: 'accent' },
		{ chart: 'accent2', css: 'accent-2' },
		{ chart: 'positive', css: 'positive' },
		{ chart: 'warning', css: 'warning' },
		{ chart: 'negative', css: 'negative' },
		{ chart: 'muted', css: 'text-muted' },
	];

	/**
	 * An empty report, so the view has something to read before loading.
	 */
	var EMPTY = { metrics: [], channels: [], campaigns: [], series: {}, buckets: [], granularity: 'day', gst_rate: 0 };

	/**
	 * Today, or the first day of this month, as Y-m-d.
	 *
	 * @param {boolean} monthStart Return the first of the month.
	 * @return {string}
	 */
	function today( monthStart ) {
		var d = new Date();
		var pad = function ( n ) {
			return ( n < 10 ? '0' : '' ) + n;
		};
		return d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + ( monthStart ? '01' : pad( d.getDate() ) );
	}

	/**
	 * Days from one Y-m-d date to another, counting both.
	 *
	 * @param {string} start First day.
	 * @param {string} end   Last day.
	 * @return {number}
	 */
	function daysBetween( start, end ) {
		var a = dates.toDate( start );
		var b = dates.toDate( end );
		return Math.round( ( b - a ) / 86400000 ) + 1;
	}

	/**
	 * Reads an uploaded file as text, whatever it was saved as: UTF-8,
	 * UTF-16 (Google Ads "Excel" downloads) or Windows-1252.
	 *
	 * @param {File} file File.
	 * @return {Promise<string>}
	 */
	function readFile( file ) {
		return file.arrayBuffer().then( function ( buffer ) {
			var bytes = new Uint8Array( buffer );
			if ( bytes[ 0 ] === 0xFF && bytes[ 1 ] === 0xFE ) {
				return new window.TextDecoder( 'utf-16le' ).decode( bytes.subarray( 2 ) );
			}
			if ( bytes[ 0 ] === 0xFE && bytes[ 1 ] === 0xFF ) {
				return new window.TextDecoder( 'utf-16be' ).decode( bytes.subarray( 2 ) );
			}
			try {
				return new window.TextDecoder( 'utf-8', { fatal: true } ).decode( bytes );
			} catch ( e ) {
				return new window.TextDecoder( 'windows-1252' ).decode( bytes );
			}
		} );
	}

	/**
	 * Elements inside a container that can take keyboard focus.
	 *
	 * @param {Element} container Container.
	 * @return {Element[]}
	 */
	function focusable( container ) {
		return Array.prototype.filter.call(
			container.querySelectorAll( 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), summary, [tabindex]:not([tabindex="-1"])' ),
			function ( el ) {
				return el.offsetParent !== null;
			}
		);
	}

	document.addEventListener( 'alpine:init', function () {
		window.Alpine.data( 'kdnaEiMarketing', function () {
			// The chart, the uploaded file and focus targets stay outside
			// Alpine's reactive data.
			var held = { chart: null, root: null, text: '', trigger: null, previewId: 0 };

			return {
				t: t,
				loaded: false,
				loading: false,
				stale: false,
				loadError: '',
				exporting: false,
				requestId: 0,
				notice: null,

				report: Object.assign( {}, EMPTY ),
				entries: [],
				channels: [],
				presets: [],
				fields: {},
				setupRate: 1,
				gstRate: 0,

				campaignSearch: '',
				campaignSort: { key: 'spend', order: 'desc' },
				showAllCampaigns: false,
				showAllEntries: false,
				confirmDelete: '',
				deleting: false,

				form: {},
				csv: { open: false },
				mappingEdited: false,

				/**
				 * Sets up the forms and reloads when the date range changes.
				 */
				init: function () {
					var self = this;
					held.root = this.$el;
					this.form = this.blankForm();
					this.csv = this.blankImport();

					var refresh = function () {
						if ( ! self.loaded ) {
							return;
						}
						if ( self.route === 'marketing' ) {
							self.load();
						} else {
							self.stale = true;
						}
					};
					window.addEventListener( 'kdna:ei-range-change', refresh );
					window.addEventListener( 'kdna:ei-data-loaded', refresh );
				},

				/**
				 * Loads the screen when it is first opened, or again if the range
				 * changed while it was closed.
				 */
				ensureLoaded: function () {
					if ( ( ! this.loaded || this.stale ) && ! this.loading ) {
						this.load();
					}
				},

				/**
				 * Fetches the marketing report, the entries list and (once) the
				 * channels and presets.
				 *
				 * @param {boolean} fresh Skip the server cache.
				 * @return {Promise}
				 */
				load: function ( fresh ) {
					var self = this;
					var id = ++this.requestId;
					var query = Object.assign( {}, this.rangeQuery, fresh ? { fresh: 1 } : {} );
					var requests = [
						api( 'marketing', { query: query } ),
						api( 'adspend', { query: this.rangeQuery } ),
					];
					if ( ! this.channels.length ) {
						requests.push( this.loadSetup() );
					}

					this.loading = true;
					this.stale = false;
					this.loadError = '';

					return Promise.all( requests ).then( function ( results ) {
						if ( id !== self.requestId ) {
							return;
						}
						self.report = Object.assign( {}, EMPTY, results[ 0 ].data );
						self.entries = results[ 1 ].data;
						self.loaded = true;
						self.$nextTick( function () {
							self.drawChart();
						} );
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
				 * Fetches the channels, CSV fields and presets.
				 *
				 * @return {Promise}
				 */
				loadSetup: function () {
					var self = this;
					return api( 'adspend/setup' ).then( function ( setup ) {
						self.channels = setup.channels;
						self.presets = setup.presets;
						self.fields = setup.fields;
						self.setupRate = setup.rate || 1;
						self.gstRate = setup.gst_rate || 0;
					} );
				},

				/**
				 * Tells every screen (this one included) that net profit has
				 * changed, so each reloads now or when next opened.
				 */
				announceChange: function () {
					window.dispatchEvent( new window.CustomEvent( 'kdna:ei-data-loaded', { detail: { source: 'adspend' } } ) );
				},

				/*
				 * -------------------------------------------------------------
				 * Figures, channels and campaigns
				 * -------------------------------------------------------------
				 */

				/**
				 * Whether any spend was entered for these dates.
				 *
				 * @return {boolean}
				 */
				get hasSpend() {
					return this.report.channels.length > 0 || this.entries.length > 0;
				},

				/**
				 * The KPIs in the strip.
				 *
				 * @return {Object[]}
				 */
				get kpis() {
					var metrics = this.report.metrics;
					return KPIS.map( function ( item ) {
						var metric = metrics.filter( function ( m ) {
							return m.key === item.key;
						} )[ 0 ];
						return metric ? Object.assign( {}, metric, { icon: item.icon } ) : null;
					} ).filter( Boolean );
				},

				/**
				 * Change text beside a KPI.
				 *
				 * @param {Object} kpi Metric.
				 * @return {string}
				 */
				changeText: function ( kpi ) {
					return window.KDNAEI.kpi.changeText( kpi );
				},

				/**
				 * Comparison sentence for a KPI.
				 *
				 * @param {Object} kpi Metric.
				 * @return {string}
				 */
				previousText: function ( kpi ) {
					return window.KDNAEI.kpi.previousText( kpi, window.KDNAEI.kpi.comparisonLabel( this.comparison ) );
				},

				/**
				 * A channel's colour, fixed by its position in the channels list.
				 *
				 * @param {string} key Channel key.
				 * @return {Object} chart and css names.
				 */
				colourOf: function ( key ) {
					var index = this.channels.map( function ( c ) {
						return c.key;
					} ).indexOf( key );
					return COLOURS[ ( index < 0 ? this.channels.length : index ) % COLOURS.length ];
				},

				/**
				 * A channel's name.
				 *
				 * @param {string} key Channel key.
				 * @return {string}
				 */
				channelLabel: function ( key ) {
					var match = this.channels.filter( function ( c ) {
						return c.key === key;
					} )[ 0 ];
					return match ? match.label : key.charAt( 0 ).toUpperCase() + key.slice( 1 );
				},

				/**
				 * Channel rows with colours and bar lengths.
				 *
				 * @return {Object[]}
				 */
				get channelRows() {
					var self = this;
					var top = this.report.channels.length ? Math.max( 1, this.report.channels[ 0 ].spend ) : 1;
					return this.report.channels.map( function ( row ) {
						return Object.assign( {}, row, { css: self.colourOf( row.channel ).css, share: Math.max( 2, row.spend / top * 100 ) } );
					} );
				},

				/**
				 * Campaigns matching the search, sorted.
				 *
				 * @return {Object[]}
				 */
				get filteredCampaigns() {
					var search = this.campaignSearch.trim().toLowerCase();
					var sort = this.campaignSort;
					return this.report.campaigns.filter( function ( row ) {
						return ! search || ( row.campaign_name + ' ' + row.label ).toLowerCase().indexOf( search ) !== -1;
					} ).slice().sort( function ( a, b ) {
						var x = a[ sort.key ];
						var y = b[ sort.key ];
						var result = sort.key === 'campaign_name' ? String( x ).localeCompare( String( y ) ) : ( x === null ? -Infinity : x ) - ( y === null ? -Infinity : y );
						return sort.order === 'asc' ? result : -result;
					} );
				},

				/**
				 * Campaigns shown: ten, or all when expanded.
				 *
				 * @return {Object[]}
				 */
				get visibleCampaigns() {
					return this.showAllCampaigns ? this.filteredCampaigns : this.filteredCampaigns.slice( 0, 10 );
				},

				/**
				 * Sorts campaigns by a column. The same column again flips it.
				 *
				 * @param {string} key Column.
				 */
				sortCampaigns: function ( key ) {
					if ( this.campaignSort.key === key ) {
						this.campaignSort = { key: key, order: this.campaignSort.order === 'asc' ? 'desc' : 'asc' };
					} else {
						this.campaignSort = { key: key, order: key === 'campaign_name' ? 'asc' : 'desc' };
					}
				},

				/**
				 * aria-sort for a campaign column heading.
				 *
				 * @param {string} key Column.
				 * @return {string}
				 */
				ariaSort: function ( key ) {
					if ( this.campaignSort.key !== key ) {
						return 'none';
					}
					return this.campaignSort.order === 'asc' ? 'ascending' : 'descending';
				},

				/**
				 * Red when the platform says a campaign returned less than it cost.
				 *
				 * @param {number|null} roas ROAS.
				 * @return {string}
				 */
				roasClass: function ( roas ) {
					return roas !== null && roas < 1 ? 'is-negative' : '';
				},

				/**
				 * Entries shown: ten, or all when expanded.
				 *
				 * @return {Object[]}
				 */
				get visibleEntries() {
					return this.showAllEntries ? this.entries : this.entries.slice( 0, 10 );
				},

				/*
				 * -------------------------------------------------------------
				 * Chart
				 * -------------------------------------------------------------
				 */

				/**
				 * Short description of the chart for screen readers.
				 *
				 * @return {string}
				 */
				get chartSummary() {
					var self = this;
					return sprintf( t.chartSummary, this.report.channels.map( function ( c ) {
						return c.label + ' ' + self.money( c.spend, 0 );
					} ).join( ', ' ) );
				},

				/**
				 * Draws spend by channel as stacked bars, or updates the chart.
				 */
				drawChart: function () {
					var self = this;
					var canvas = held.root && held.root.querySelector( '[x-ref="spend"]' );
					if ( ! charts || ! canvas || ! this.report.buckets.length ) {
						return;
					}
					var buckets = this.report.buckets;
					var granularity = this.report.granularity;
					var series = this.report.channels.map( function ( channel ) {
						return {
							label: channel.label,
							data: ( self.report.series[ channel.channel ] || [] ).slice(),
							colour: self.colourOf( channel.channel ).chart,
						};
					} );
					var options = {
						labels: buckets.map( function ( b ) {
							return b.key;
						} ),
						series: series.length ? series : [ { label: '', data: buckets.map( function () {
							return 0;
						} ), colour: 'accent' } ],
						maxXTicks: 7,
						xLabel: function ( i ) {
							return dates.axis( buckets, i, granularity );
						},
						yLabel: function ( value ) {
							return format.compact( value );
						},
						title: function ( i ) {
							return dates.title( buckets, i, granularity );
						},
						value: function ( v ) {
							return self.money( v, 2 );
						},
						footer: function ( i ) {
							var total = series.reduce( function ( sum, s ) {
								return sum + ( s.data[ i ] || 0 );
							}, 0 );
							return series.length > 1 ? { text: sprintf( t.dayTotal, self.money( total, 2 ) ) } : null;
						},
					};

					if ( held.chart && held.chart.canvas === canvas ) {
						charts.updateBarChart( held.chart, options );
					} else {
						held.chart = charts.barChart( canvas, options );
					}
				},

				/*
				 * -------------------------------------------------------------
				 * Add and edit spend
				 * -------------------------------------------------------------
				 */

				/**
				 * An empty Add spend form.
				 *
				 * @return {Object}
				 */
				blankForm: function () {
					return {
						open: false,
						group: '',
						channel: 'meta',
						newChannel: '',
						period: 'month',
						start: today(),
						end: today(),
						month: today( true ).slice( 0, 7 ),
						campaign: '',
						amount: '',
						includesGst: false,
						errors: {},
						error: '',
						busy: false,
					};
				},

				/**
				 * Opens the form, empty or filled in from an entry to edit.
				 *
				 * @param {Object|null} entry   Entry to edit, or null.
				 * @param {Element}     trigger Button that opened it.
				 */
				openForm: function ( entry, trigger ) {
					var self = this;
					var form = this.blankForm();
					held.trigger = trigger || null;

					if ( this.channels.length && ! this.channels.some( function ( c ) {
						return c.key === form.channel;
					} ) ) {
						form.channel = this.channels[ 0 ].key;
					}

					if ( entry ) {
						var start = dates.toDate( entry.start );
						var end = dates.toDate( entry.end );
						var monthEnd = new Date( start.getFullYear(), start.getMonth() + 1, 0 ).getDate();
						form.group = entry.entry_group;
						form.channel = entry.channel;
						form.campaign = entry.campaign_name || '';
						form.amount = format.inputAmount( entry.amount );
						form.includesGst = entry.includes_gst;
						form.start = entry.start;
						form.end = entry.end;
						if ( entry.start === entry.end ) {
							form.period = 'day';
						} else if ( start.getDate() === 1 && end.getDate() === monthEnd && start.getMonth() === end.getMonth() ) {
							form.period = 'month';
							form.month = entry.start.slice( 0, 7 );
						} else {
							form.period = 'range';
						}
					}

					form.open = true;
					this.form = form;
					this.$nextTick( function () {
						var field = held.root.querySelector( '#kdna-ei-spend-channel' );
						if ( field ) {
							field.focus();
						}
					} );
				},

				/**
				 * Closes the form and puts focus back.
				 */
				closeForm: function () {
					this.form.open = false;
					this.returnFocus();
				},

				/**
				 * The first and last day the form's amount covers.
				 *
				 * @return {Object} start and end, Y-m-d.
				 */
				formDates: function () {
					var f = this.form;
					if ( f.period === 'month' && /^\d{4}-\d{2}$/.test( f.month ) ) {
						var parts = f.month.split( '-' );
						var last = new Date( Number( parts[ 0 ] ), Number( parts[ 1 ] ), 0 ).getDate();
						return { start: f.month + '-01', end: f.month + '-' + ( last < 10 ? '0' : '' ) + last };
					}
					if ( f.period === 'range' ) {
						return { start: f.start, end: f.end };
					}
					return { start: f.start, end: f.start };
				},

				/**
				 * "That is $100.00 a day over 30 days" under the amount.
				 *
				 * @return {string}
				 */
				get spreadText() {
					var amount = format.parseAmount( this.form.amount );
					var range = this.formDates();
					if ( ! amount || isNaN( amount ) || ! range.start || ! range.end || range.end < range.start ) {
						return '';
					}
					var days = daysBetween( range.start, range.end );
					return days > 1 ? sprintf( t.perDay, this.money( amount / days, 2 ), this.formatNumber( days ) ) : '';
				},

				/**
				 * What happens to GST, in plain English.
				 *
				 * @return {string}
				 */
				get gstText() {
					var amount = format.parseAmount( this.form.amount );
					if ( ! this.gstRate ) {
						return t.gstNotSetUp;
					}
					if ( ! this.form.includesGst ) {
						return t.gstNo;
					}
					if ( ! amount || isNaN( amount ) ) {
						return sprintf( t.gstYesNoAmount, this.formatNumber( this.gstRate ) );
					}
					var gst = amount * this.gstRate / ( 100 + this.gstRate );
					return sprintf( t.gstYes, this.money( gst, 2 ), this.money( amount - gst, 2 ) );
				},

				/**
				 * Checks the form, adds a new channel if one was typed, then
				 * saves the entry. Problems appear against their fields.
				 *
				 * @return {Promise|undefined}
				 */
				saveForm: function () {
					var self = this;
					var f = this.form;
					var range = this.formDates();
					var amount = format.parseAmount( f.amount );
					var errors = {};

					if ( amount === null || isNaN( amount ) || amount < 0 ) {
						errors.amount = t.amountError;
					}
					if ( ! range.start ) {
						errors.start = t.dateError;
					} else if ( range.end < range.start ) {
						errors.end = t.endError;
					}
					if ( f.channel === '__new' && ! f.newChannel.trim() ) {
						errors.channel = t.channelError;
					}
					f.errors = errors;
					f.error = '';
					if ( Object.keys( errors ).length ) {
						return;
					}

					f.busy = true;
					return this.ensureChannel().then( function ( channel ) {
						var body = {
							start: range.start,
							end: range.end,
							channel: channel,
							campaign_name: f.campaign.trim(),
							amount: amount,
							includes_gst: f.includesGst,
						};
						return api( f.group ? 'adspend/' + f.group : 'adspend', { method: f.group ? 'PUT' : 'POST', body: body } );
					} ).then( function () {
						var message = sprintf( f.group ? t.updated : t.added, self.money( amount, 2 ), self.channelLabel( f.channel === '__new' ? self.slug( f.newChannel ) : f.channel ) );
						self.closeForm();
						self.notice = { type: 'positive', text: message };
						self.announceChange();
					} ).catch( function ( error ) {
						f.errors = error.fields || {};
						f.error = error.fields ? t.checkFields : error.message;
					} ).finally( function () {
						f.busy = false;
					} );
				},

				/**
				 * A channel key made from a name, for example "Snapchat Ads"
				 * becomes "snapchat_ads".
				 *
				 * @param {string} name Name.
				 * @return {string}
				 */
				slug: function ( name ) {
					return name.trim().toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' ).slice( 0, 40 ) || 'other';
				},

				/**
				 * Saves a newly typed channel to Settings, then returns the
				 * channel key to use.
				 *
				 * @return {Promise<string>}
				 */
				ensureChannel: function () {
					var self = this;
					var f = this.form;
					if ( f.channel !== '__new' ) {
						return Promise.resolve( f.channel );
					}
					var key = this.slug( f.newChannel );
					if ( this.channels.some( function ( c ) {
						return c.key === key;
					} ) ) {
						return Promise.resolve( key );
					}
					var list = this.channels.concat( [ { key: key, label: f.newChannel.trim() } ] );
					return api( 'settings', { method: 'POST', body: { settings: { marketing: { channels: list } } } } ).then( function ( settings ) {
						self.channels = settings.marketing.channels;
						return key;
					} );
				},

				/**
				 * Deletes an entry (or a whole import) after confirming.
				 *
				 * @param {Object} entry Entry.
				 * @return {Promise}
				 */
				deleteEntry: function ( entry ) {
					var self = this;
					this.deleting = true;
					return api( 'adspend/' + entry.entry_group, { method: 'DELETE' } ).then( function () {
						self.confirmDelete = '';
						self.notice = { type: 'positive', text: sprintf( t.deleted, self.money( entry.amount, 2 ), self.channelLabel( entry.channel ) ) };
						self.announceChange();
					} ).catch( function ( error ) {
						self.notice = { type: 'negative', text: error.message };
					} ).finally( function () {
						self.deleting = false;
					} );
				},

				/*
				 * -------------------------------------------------------------
				 * CSV import
				 * -------------------------------------------------------------
				 */

				/**
				 * An empty import.
				 *
				 * @return {Object}
				 */
				blankImport: function () {
					return {
						open: false,
						step: 'choose',
						busy: false,
						error: '',
						fileName: '',
						channel: '',
						preset: '',
						mapping: {},
						rate: '1',
						includesGst: false,
						savePreset: false,
						presetName: '',
						showColumns: false,
						preview: null,
						result: null,
					};
				},

				/**
				 * Opens the import window.
				 *
				 * @param {Element} trigger Button that opened it.
				 */
				openImport: function ( trigger ) {
					var self = this;
					held.trigger = trigger || null;
					held.text = '';
					this.csv = Object.assign( this.blankImport(), { open: true, rate: String( this.setupRate || 1 ) } );
					this.mappingEdited = false;
					this.$nextTick( function () {
						var dialog = held.root.querySelector( '[x-ref="importDialog"]' );
						if ( dialog ) {
							dialog.focus();
						}
					} );
				},

				/**
				 * Closes the import window and puts focus back.
				 */
				closeImport: function () {
					if ( this.csv.busy ) {
						return;
					}
					this.csv.open = false;
					held.text = '';
					this.returnFocus();
				},

				/**
				 * Reads the chosen file and shows the first preview, letting the
				 * server recognise Meta and Google exports.
				 *
				 * @param {Event} event File input change.
				 */
				chooseFile: function ( event ) {
					var self = this;
					var file = event.target.files && event.target.files[ 0 ];
					event.target.value = '';
					if ( ! file ) {
						return;
					}
					if ( file.size > 5 * 1024 * 1024 ) {
						this.csv.error = t.tooLarge;
						return;
					}

					this.csv.busy = true;
					this.csv.error = '';
					this.csv.fileName = file.name;

					readFile( file ).then( function ( text ) {
						held.text = text;
						return api( 'adspend/import/preview', { method: 'POST', body: { csv: text } } );
					} ).then( function ( preview ) {
						var preset = self.presetOf( preview.preset );
						self.csv.preset = preview.preset || '';
						self.csv.mapping = Object.assign( {}, preview.mapping );
						self.csv.channel = preset && preset.channel ? preset.channel : '';
						self.csv.preview = preview;
						self.csv.step = 'map';
						self.mappingEdited = false;
						if ( self.csv.channel ) {
							return self.refreshPreview();
						}
					} ).catch( function ( error ) {
						self.csv.error = error.message;
					} ).finally( function () {
						self.csv.busy = false;
					} );
				},

				/**
				 * A preset by key.
				 *
				 * @param {string} key Preset key.
				 * @return {Object|undefined}
				 */
				presetOf: function ( key ) {
					return this.presets.filter( function ( p ) {
						return p.key === key;
					} )[ 0 ];
				},

				/**
				 * A preset's name.
				 *
				 * @param {string} key Preset key.
				 * @return {string}
				 */
				presetName: function ( key ) {
					var preset = this.presetOf( key );
					return preset ? preset.name : '';
				},

				/**
				 * Whether a preset is one of the built-in Meta or Google ones.
				 *
				 * @param {string} key Preset key.
				 * @return {boolean}
				 */
				isBuiltin: function ( key ) {
					var preset = this.presetOf( key );
					return !! ( preset && preset.builtin );
				},

				/**
				 * Whether the date or spend column still needs choosing.
				 *
				 * @return {boolean}
				 */
				get needsMapping() {
					return ! this.csv.mapping.date || ! this.csv.mapping.spend;
				},

				/**
				 * Whether everything is ready to import.
				 *
				 * @return {boolean}
				 */
				get canImport() {
					return !! ( this.csv.channel && ! this.needsMapping && this.csv.preview && this.csv.preview.totals.rows > 0 );
				},

				/**
				 * Uses a preset's columns (and its channel and GST choice).
				 *
				 * @return {Promise}
				 */
				applyPreset: function () {
					var self = this;
					var preset = this.presetOf( this.csv.preset );
					if ( preset && preset.channel ) {
						this.csv.channel = preset.channel;
					}
					if ( preset && ! preset.builtin ) {
						this.csv.includesGst = !! preset.includes_gst;
					}
					this.mappingEdited = false;
					return this.refreshPreview( true ).then( function () {
						self.csv.showColumns = ! self.csv.preset;
					} );
				},

				/**
				 * Notes that a column was chosen by hand and updates the preview.
				 *
				 * @return {Promise}
				 */
				mappingChanged: function () {
					this.mappingEdited = true;
					return this.refreshPreview();
				},

				/**
				 * Asks the server for a fresh preview with the current choices.
				 *
				 * @param {boolean} usePreset Let the preset choose the columns.
				 * @return {Promise}
				 */
				refreshPreview: function ( usePreset ) {
					var self = this;
					var id = ++held.previewId;
					if ( ! held.text ) {
						return Promise.resolve();
					}
					this.csv.error = '';
					return api( 'adspend/import/preview', {
						method: 'POST',
						body: {
							csv: held.text,
							channel: this.csv.channel,
							preset: this.csv.preset,
							mapping: usePreset ? {} : this.csv.mapping,
							rate: this.csv.rate,
							includes_gst: this.csv.includesGst,
						},
					} ).then( function ( preview ) {
						if ( id !== held.previewId ) {
							return;
						}
						self.csv.preview = preview;
						if ( usePreset ) {
							self.csv.mapping = Object.assign( {}, preview.mapping );
						}
					} ).catch( function ( error ) {
						self.csv.error = error.message;
					} );
				},

				/**
				 * Saves the preset if asked, then imports the file.
				 *
				 * @return {Promise|undefined}
				 */
				runImport: function () {
					var self = this;
					var c = this.csv;
					if ( ! this.canImport ) {
						return;
					}
					if ( c.savePreset && ! c.presetName.trim() ) {
						c.error = t.presetNameError;
						return;
					}

					c.busy = true;
					c.error = '';
					var save = c.savePreset
						? api( 'adspend/presets', { method: 'POST', body: { name: c.presetName.trim(), channel: c.channel, mapping: c.mapping, includes_gst: c.includesGst } } ).then( function ( result ) {
							self.presets = result.presets;
						} )
						: Promise.resolve();

					return save.then( function () {
						return api( 'adspend/import', {
							method: 'POST',
							body: { csv: held.text, channel: c.channel, preset: c.preset, mapping: c.mapping, rate: c.rate, includes_gst: c.includesGst },
						} );
					} ).then( function ( result ) {
						c.result = result;
						c.step = 'done';
						held.text = '';
						self.announceChange();
					} ).catch( function ( error ) {
						c.error = error.message;
					} ).finally( function () {
						c.busy = false;
					} );
				},

				/*
				 * -------------------------------------------------------------
				 * Helpers
				 * -------------------------------------------------------------
				 */

				/**
				 * Keeps keyboard focus inside an open window.
				 *
				 * @param {KeyboardEvent} event Tab key press.
				 * @param {string}        ref   The window's x-ref name.
				 */
				trap: function ( event, ref ) {
					var dialog = held.root.querySelector( '[x-ref="' + ref + '"]' );
					var items = dialog ? focusable( dialog ) : [];
					if ( ! items.length ) {
						return;
					}
					var first = items[ 0 ];
					var last = items[ items.length - 1 ];
					if ( event.shiftKey && ( document.activeElement === first || document.activeElement === dialog ) ) {
						event.preventDefault();
						last.focus();
					} else if ( ! event.shiftKey && document.activeElement === last ) {
						event.preventDefault();
						first.focus();
					}
				},

				/**
				 * Puts focus back on the button that opened a window.
				 */
				returnFocus: function () {
					if ( held.trigger && document.body.contains( held.trigger ) ) {
						held.trigger.focus();
					}
					held.trigger = null;
				},

				/**
				 * Downloads a table as CSV.
				 *
				 * @param {string} table channels, campaigns or adspend.
				 */
				exportCsv: function ( table ) {
					var self = this;
					this.exporting = true;
					window.KDNAEI.exportCsv( table, this.rangeQuery ).catch( function ( error ) {
						self.loadError = error.message;
					} ).finally( function () {
						self.exporting = false;
					} );
				},

				/**
				 * "1 Sep 2026" or "1 Sep 2026 to 30 Sep 2026".
				 *
				 * @param {string} start Y-m-d.
				 * @param {string} end   Y-m-d.
				 * @return {string}
				 */
				dateRange: function ( start, end ) {
					if ( ! start ) {
						return '';
					}
					return start === end || ! end ? dates.short( start ) : sprintf( config.i18n.rangeTo, dates.short( start ), dates.short( end ) );
				},

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
				 * Formats a return such as ROAS: "3.2x".
				 *
				 * @param {number} value Ratio.
				 * @return {string}
				 */
				ratio: function ( value ) {
					return format.metric( value, 'ratio', 2 );
				},

				/**
				 * Formats any metric value by its kind.
				 *
				 * @param {number} value    Value.
				 * @param {string} kind     Format.
				 * @param {number} decimals Decimal places.
				 * @return {string}
				 */
				metric: function ( value, kind, decimals ) {
					return format.metric( value, kind, decimals );
				},

				/**
				 * Formats a number, with one decimal only when needed.
				 *
				 * @param {number} value Number.
				 * @return {string}
				 */
				formatNumber: function ( value ) {
					var n = Number( value || 0 );
					return format.number( n, n % 1 ? 1 : 0 );
				},

				sprintf: sprintf,
			};
		} );
	} );
}( window, document ) );
