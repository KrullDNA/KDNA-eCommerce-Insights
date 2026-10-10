/**
 * KDNA eCommerce Insights: chart theme.
 *
 * A thin layer over Chart.js 4 that gives every Insights chart the look of
 * the reference design: smooth curves, a soft gradient under the main line,
 * a lighter comparison line, a ringed dot on the latest point, quiet
 * gridlines, a floating tooltip card and a vertical guide line on hover.
 *
 * Colours and fonts are read from the --kdna-ei- CSS variables on the
 * chart's own Insights root, so dark and light mode, client branding and
 * Elementor style controls all apply. Charts redraw themselves when the
 * theme changes (kdna:ei-theme-change).
 *
 * Used by the wp-admin app and, from Stage 13, the Elementor widgets.
 */
( function ( window, document ) {
	'use strict';

	window.KDNAEI = window.KDNAEI || {};

	var Chart = window.Chart;
	var charts = [];
	var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	/*
	 * ---------------------------------------------------------------------
	 * Design tokens
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Reads the current colours and font from the CSS variables that apply
	 * to an element (its nearest Insights root).
	 *
	 * @param {Element} element Any element inside an Insights root.
	 * @return {Object}
	 */
	function tokens( element ) {
		var style = window.getComputedStyle( element );
		var read = function ( name, fallback ) {
			var value = style.getPropertyValue( name ).trim();
			return value || fallback;
		};

		var t = {
			accent: read( '--kdna-ei-accent', '#7188EE' ),
			accent2: read( '--kdna-ei-accent-2', '#C9C2F8' ),
			positive: read( '--kdna-ei-positive', '#B6F2D0' ),
			warning: read( '--kdna-ei-warning', '#F5D58A' ),
			negative: read( '--kdna-ei-negative', '#F2A7A7' ),
			text: read( '--kdna-ei-text', '#F3F3F5' ),
			muted: read( '--kdna-ei-text-muted', '#9C9DA4' ),
			border: read( '--kdna-ei-border', 'rgba(255,255,255,0.07)' ),
			surface: read( '--kdna-ei-surface', '#26272B' ),
			raised: read( '--kdna-ei-surface-raised', '#2E2F34' ),
			font: read( '--kdna-ei-font', 'Figtree, sans-serif' ),

			// Chart style settings. Only the Elementor widgets set these; the
			// admin app uses the defaults.
			series1: read( '--kdna-ei-series-1', '' ),
			series2: read( '--kdna-ei-series-2', '' ),
			series3: read( '--kdna-ei-series-3', '' ),
			series4: read( '--kdna-ei-series-4', '' ),
			series5: read( '--kdna-ei-series-5', '' ),
			lineWidth: number( read( '--kdna-ei-chart-line-width', '' ), 2.5 ),
			compareWidth: number( read( '--kdna-ei-chart-compare-width', '' ), 2 ),
			tension: number( read( '--kdna-ei-chart-tension', '' ), 0.4 ),
			fillStart: number( read( '--kdna-ei-chart-fill-start', '' ), 0.35 ),
			fillEnd: number( read( '--kdna-ei-chart-fill-end', '' ), 0 ),
			pointSize: number( read( '--kdna-ei-chart-point-size', '' ), 0 ),
			pointStyle: read( '--kdna-ei-chart-point-style', 'circle' ),
			ring: read( '--kdna-ei-chart-ring', '1' ) !== '0',
			ringSize: number( read( '--kdna-ei-chart-ring-size', '' ), 6 ),
			grid: read( '--kdna-ei-chart-grid', '' ),
			gridStyle: read( '--kdna-ei-chart-grid-style', 'dashed' ),
			axisColour: read( '--kdna-ei-chart-axis-colour', '' ),
			axisSize: number( read( '--kdna-ei-chart-axis-size', '' ), 12 ),
			axisWeight: read( '--kdna-ei-chart-axis-weight', '400' ),
			seg1: read( '--kdna-ei-segment-1', '' ),
			seg2: read( '--kdna-ei-segment-2', '' ),
			seg3: read( '--kdna-ei-segment-3', '' ),
			seg4: read( '--kdna-ei-segment-4', '' ),
			seg5: read( '--kdna-ei-segment-5', '' ),
			donutCutout: read( '--kdna-ei-donut-cutout', '' ),
			donutSpacing: number( read( '--kdna-ei-donut-spacing', '' ), 3 ),
		};

		// Colours for charts with several series (line1 to line5): a widget's
		// series colour if set, otherwise the design colours in turn.
		t.line1 = t.series1 || t.accent;
		t.line2 = t.series2 || t.accent2;
		t.line3 = t.series3 || t.positive;
		t.line4 = t.series4 || t.warning;
		t.line5 = t.series5 || t.negative;
		return t;
	}

	/**
	 * Reads a number from a CSS variable value such as "2.5" or "2.5px",
	 * falling back when it is empty or not a number.
	 *
	 * @param {string} value    Variable value.
	 * @param {number} fallback Number to use instead.
	 * @return {number}
	 */
	function number( value, fallback ) {
		var parsed = parseFloat( value );
		return isNaN( parsed ) ? fallback : parsed;
	}

	/**
	 * The gridline dash pattern for a gridline style.
	 *
	 * @param {string} style dashed, dotted or solid.
	 * @return {number[]}
	 */
	function gridDash( style ) {
		return { solid: [], dotted: [ 1, 3 ] }[ style ] || [ 4, 4 ];
	}

	/**
	 * Turns any CSS colour into rgba() with a given opacity, so the
	 * gradient fill can fade the accent colour out.
	 *
	 * @param {string} colour  CSS colour.
	 * @param {number} opacity 0 to 1.
	 * @return {string}
	 */
	function withOpacity( colour, opacity ) {
		var canvas = withOpacity.canvas || ( withOpacity.canvas = document.createElement( 'canvas' ).getContext( '2d' ) );
		canvas.fillStyle = '#000';
		canvas.fillStyle = colour;
		var hex = canvas.fillStyle;

		if ( hex.charAt( 0 ) === '#' ) {
			var n = parseInt( hex.slice( 1 ), 16 );
			return 'rgba(' + ( ( n >> 16 ) & 255 ) + ',' + ( ( n >> 8 ) & 255 ) + ',' + ( n & 255 ) + ',' + opacity + ')';
		}
		return hex.replace( /rgba?\(([^)]+)\)/, function ( match, inner ) {
			var parts = inner.split( ',' ).slice( 0, 3 );
			return 'rgba(' + parts.join( ',' ) + ',' + opacity + ')';
		} );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Plugins: ringed latest point, hover guide line, left-to-right reveal
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Draws a ringed dot on the last point of the main line, like the
	 * reference design.
	 */
	var latestPointPlugin = {
		id: 'kdnaLatestPoint',
		afterDatasetsDraw: function ( chart, args, options ) {
			if ( ! options || options.display === false ) {
				return;
			}
			var meta = chart.getDatasetMeta( 0 );
			if ( ! meta || meta.hidden || ! meta.data.length || chart.config.type !== 'line' ) {
				return;
			}

			// The last point that has a value.
			var data = chart.data.datasets[ 0 ].data;
			var index = -1;
			for ( var i = data.length - 1; i >= 0; i-- ) {
				if ( data[ i ] !== null && data[ i ] !== undefined ) {
					index = i;
					break;
				}
			}
			var point = meta.data[ index ];
			if ( ! point || ( chart.$kdnaReveal !== undefined && chart.$kdnaReveal < 1 ) ) {
				return;
			}

			var ctx = chart.ctx;
			ctx.save();
			ctx.beginPath();
			ctx.arc( point.x, point.y, options.radius || 6, 0, Math.PI * 2 );
			ctx.fillStyle = options.fill;
			ctx.fill();
			ctx.lineWidth = options.borderWidth || 2.5;
			ctx.strokeStyle = options.stroke;
			ctx.stroke();
			ctx.restore();
		},
	};

	/**
	 * Draws a thin vertical guide line where the pointer is.
	 */
	var guideLinePlugin = {
		id: 'kdnaGuideLine',
		afterDraw: function ( chart, args, options ) {
			var active = chart.tooltip && chart.tooltip.getActiveElements();
			if ( ! active || ! active.length || chart.config.type !== 'line' ) {
				return;
			}
			var x = active[ 0 ].element.x;
			var area = chart.chartArea;
			var ctx = chart.ctx;
			ctx.save();
			ctx.beginPath();
			ctx.moveTo( x, area.top );
			ctx.lineTo( x, area.bottom );
			ctx.lineWidth = 1;
			ctx.strokeStyle = options.colour;
			ctx.setLineDash( [ 3, 3 ] );
			ctx.stroke();
			ctx.restore();
		},
	};

	/**
	 * Reveals line charts from left to right when they first appear, by
	 * clipping the drawing area and widening it over 500ms.
	 */
	var revealPlugin = {
		id: 'kdnaReveal',
		beforeDatasetsDraw: function ( chart ) {
			if ( chart.$kdnaReveal === undefined || chart.$kdnaReveal >= 1 ) {
				return;
			}
			var area = chart.chartArea;
			chart.ctx.save();
			chart.ctx.beginPath();
			chart.ctx.rect( area.left - 10, 0, ( area.right - area.left + 20 ) * chart.$kdnaReveal, chart.height );
			chart.ctx.clip();
		},
		afterDatasetsDraw: function ( chart ) {
			if ( chart.$kdnaReveal !== undefined && chart.$kdnaReveal < 1 ) {
				chart.ctx.restore();
			}
		},
	};

	/**
	 * Runs the left-to-right reveal on a chart.
	 *
	 * @param {Chart} chart Chart instance.
	 */
	function reveal( chart ) {
		if ( reduceMotion ) {
			chart.$kdnaReveal = 1;
			return;
		}
		var start = null;
		var duration = 550;
		chart.$kdnaReveal = 0;

		function step( time ) {
			if ( start === null ) {
				start = time;
			}
			var t = Math.min( 1, ( time - start ) / duration );
			chart.$kdnaReveal = 1 - Math.pow( 1 - t, 3 );
			chart.draw();
			if ( t < 1 ) {
				window.requestAnimationFrame( step );
			}
		}
		window.requestAnimationFrame( step );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Tooltip card
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Draws the floating tooltip card in HTML, so it matches the rest of the
	 * design. The chart passes its own formatter for titles and values.
	 *
	 * @param {Object} context Chart.js tooltip context.
	 */
	function externalTooltip( context ) {
		var chart = context.chart;
		var tooltip = context.tooltip;
		var holder = chart.canvas.parentNode;
		var card = holder.querySelector( '.kdna-ei-tooltip' );

		if ( ! card ) {
			card = document.createElement( 'div' );
			card.className = 'kdna-ei-tooltip';
			card.setAttribute( 'role', 'presentation' );
			holder.appendChild( card );
		}

		if ( tooltip.opacity === 0 || ! tooltip.dataPoints || ! tooltip.dataPoints.length ) {
			card.style.opacity = 0;
			return;
		}

		var helpers = chart.$kdna || {};
		var index = tooltip.dataPoints[ 0 ].dataIndex;
		var html = '';

		html += '<div class="kdna-ei-tooltip__title">' + escapeHtml( helpers.title ? helpers.title( index ) : tooltip.title.join( ' ' ) ) + '</div>';

		chart.data.datasets.forEach( function ( dataset, datasetIndex ) {
			if ( chart.getDatasetMeta( datasetIndex ).hidden ) {
				return;
			}
			var value = dataset.data[ index ];
			var label = helpers.seriesLabel ? helpers.seriesLabel( datasetIndex, index ) : dataset.label;
			html += '<div class="kdna-ei-tooltip__row">' +
				'<span class="kdna-ei-tooltip__dot" style="background:' + escapeHtml( Array.isArray( dataset.backgroundColor ) ? dataset.backgroundColor[ index ] : dataset.borderColor ) + '"></span>' +
				'<span class="kdna-ei-tooltip__label">' + escapeHtml( label ) + '</span>' +
				'<span class="kdna-ei-tooltip__value">' + escapeHtml( helpers.value ? helpers.value( value, index, datasetIndex ) : String( value ) ) + '</span>' +
				'</div>';
		} );

		if ( helpers.footer ) {
			var footer = helpers.footer( index );
			if ( footer ) {
				html += '<div class="kdna-ei-tooltip__footer ' + escapeHtml( footer.className || '' ) + '">' + escapeHtml( footer.text ) + '</div>';
			}
		}

		card.innerHTML = html;
		card.style.opacity = 1;

		// Keep the card inside the chart: flip it to the left near the right edge.
		var x = tooltip.caretX;
		var width = card.offsetWidth;
		var left = x + 14;
		if ( left + width > holder.clientWidth ) {
			left = x - width - 14;
		}
		card.style.left = Math.max( 0, left ) + 'px';
		card.style.top = Math.max( 0, tooltip.caretY - card.offsetHeight / 2 ) + 'px';
	}

	/**
	 * Escapes text for safe use in the tooltip HTML.
	 *
	 * @param {*} text Text.
	 * @return {string}
	 */
	function escapeHtml( text ) {
		return String( text === null || text === undefined ? '' : text ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Screen reader alternative
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Writes the chart's figures into a table hidden from view but read by
	 * screen readers, next to the canvas. Runs every time the chart is drawn
	 * with new figures, so the table always matches what is on screen.
	 *
	 * @param {Chart} chart Chart instance.
	 */
	function describe( chart ) {
		var canvas = chart.canvas;
		var holder = canvas && canvas.parentNode;
		if ( ! holder || ! chart.$kdnaKind ) {
			return;
		}
		var helpers = chart.$kdna || {};
		var strings = ( window.kdnaEiApp && window.kdnaEiApp.i18n ) || {};
		var number = window.KDNAEI.format ? window.KDNAEI.format.number : function ( v ) {
			return String( v );
		};
		var table = holder.querySelector( 'table.kdna-ei-chart-table' );
		if ( ! table ) {
			table = document.createElement( 'table' );
			table.className = 'kdna-ei-visually-hidden kdna-ei-chart-table';
			holder.appendChild( table );
		}
		var datasets = chart.data.datasets || [];
		var labels = chart.data.labels || [];
		var html = '<caption>' + escapeHtml( canvas.getAttribute( 'aria-label' ) || '' ) + '</caption><thead><tr><th scope="col">' + escapeHtml( strings.chartPeriod || '' ) + '</th>';

		if ( chart.$kdnaKind === 'donut' || chart.$kdnaKind === 'waterfall' ) {
			html += '<th scope="col">' + escapeHtml( strings.chartValue || '' ) + '</th></tr></thead><tbody>';
			labels.forEach( function ( label, i ) {
				var raw = datasets[ 0 ] ? datasets[ 0 ].data[ i ] : null;
				var value = chart.$kdnaKind === 'waterfall' ? ( helpers.steps && helpers.steps[ i ] ? helpers.steps[ i ].amount : null ) : raw;
				var text = helpers.value ? helpers.value( value, i, 0 ) : number( value, 0 );
				html += '<tr><th scope="row">' + escapeHtml( label ) + '</th><td>' + escapeHtml( text ) + '</td></tr>';
			} );
		} else {
			datasets.forEach( function ( dataset ) {
				html += '<th scope="col">' + escapeHtml( dataset.label ) + '</th>';
			} );
			html += '</tr></thead><tbody>';
			labels.forEach( function ( label, i ) {
				html += '<tr><th scope="row">' + escapeHtml( helpers.title ? helpers.title( i ) : label ) + '</th>';
				datasets.forEach( function ( dataset, d ) {
					var value = dataset.data[ i ];
					html += '<td>' + escapeHtml( value === null || value === undefined ? '-' : ( helpers.value ? helpers.value( value, i, d ) : number( value, 2 ) ) ) + '</td>';
				} );
				html += '</tr>';
			} );
		}
		table.innerHTML = html + '</tbody>';
	}

	// Keep the table up to date whenever an Insights chart redraws with new figures.
	if ( Chart ) {
		Chart.register( {
			id: 'kdnaDescribe',
			afterUpdate: function ( chart ) {
				if ( chart.$kdnaKind ) {
					describe( chart );
				}
			},
		} );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Building charts
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Creates the gradient that fills under the main line.
	 *
	 * @param {Chart}  chart  Chart instance.
	 * @param {string} colour Line colour.
	 * @return {CanvasGradient|string}
	 */
	function areaGradient( chart, colour ) {
		var area = chart.chartArea;
		if ( ! area ) {
			return withOpacity( colour, 0.2 );
		}
		var t = chart.$kdnaTokens || {};
		var gradient = chart.ctx.createLinearGradient( 0, area.top, 0, area.bottom );
		gradient.addColorStop( 0, withOpacity( colour, t.fillStart === undefined ? 0.35 : t.fillStart ) );
		gradient.addColorStop( 1, withOpacity( colour, t.fillEnd === undefined ? 0 : t.fillEnd ) );
		return gradient;
	}

	/**
	 * Builds Chart.js options for a line or area chart in the KDNA style.
	 *
	 * @param {Object} t       Tokens from tokens().
	 * @param {Object} options Options passed to lineChart().
	 * @return {Object}
	 */
	function lineOptions( t, options ) {
		return {
			responsive: true,
			maintainAspectRatio: false,
			animation: false,
			interaction: { mode: 'index', intersect: false },
			layout: { padding: { top: 12, right: 12 } },
			scales: {
				x: {
					grid: { display: false },
					border: { display: false },
					ticks: {
						color: t.axisColour || t.muted,
						font: { family: t.font, size: t.axisSize, weight: t.axisWeight },
						maxRotation: 0,
						autoSkip: true,
						maxTicksLimit: options.maxXTicks || 6,
						padding: 10,
						callback: function ( value, index ) {
							return options.xLabel ? options.xLabel( index ) : this.getLabelForValue( value );
						},
					},
				},
				y: {
					beginAtZero: options.beginAtZero !== false,
					grid: { display: t.gridStyle !== 'none', color: t.grid || t.border, drawTicks: false },
					border: { display: false, dash: gridDash( t.gridStyle ) },
					ticks: {
						color: t.axisColour || t.muted,
						font: { family: t.font, size: t.axisSize, weight: t.axisWeight },
						padding: 12,
						maxTicksLimit: 5,
						callback: function ( value ) {
							return options.yLabel ? options.yLabel( value ) : value;
						},
					},
				},
			},
			plugins: {
				legend: { display: false },
				tooltip: { enabled: false, external: externalTooltip },
				kdnaLatestPoint: { display: t.ring, fill: t.surface, stroke: t.series1 || t.accent, radius: t.ringSize, borderWidth: 2.5 },
				kdnaGuideLine: { colour: t.muted },
			},
		};
	}

	/**
	 * Applies colours to every dataset of a line chart.
	 *
	 * @param {Chart}  chart Chart instance.
	 * @param {Object} t     Tokens.
	 */
	function styleLineDatasets( chart, t ) {
		// The ringed latest point matches the main line's colour.
		if ( chart.options && chart.options.plugins && chart.options.plugins.kdnaLatestPoint && chart.data.datasets[ 0 ] && chart.data.datasets[ 0 ].kdnaColour ) {
			chart.options.plugins.kdnaLatestPoint.stroke = t[ chart.data.datasets[ 0 ].kdnaColour ] || chart.data.datasets[ 0 ].kdnaColour;
		}
		chart.$kdnaTokens = t;
		chart.data.datasets.forEach( function ( dataset, index ) {
			var primary = index === 0;
			var colour = dataset.kdnaColour ? ( t[ dataset.kdnaColour ] || dataset.kdnaColour ) : ( primary ? t.series1 || t.accent : t.series2 || t.accent2 );
			var filled = dataset.kdnaFill === undefined ? primary : dataset.kdnaFill;
			dataset.borderColor = colour;
			dataset.backgroundColor = filled ? function ( ctx ) {
				return areaGradient( ctx.chart, colour );
			} : 'transparent';
			dataset.fill = filled ? 'origin' : false;
			dataset.borderWidth = primary ? t.lineWidth : t.compareWidth;
			dataset.tension = t.tension;
			dataset.cubicInterpolationMode = t.tension > 0 ? 'monotone' : 'default';
			dataset.pointRadius = t.pointSize;
			dataset.pointStyle = t.pointStyle;
			dataset.pointBackgroundColor = t.surface;
			dataset.pointBorderColor = colour;
			dataset.pointHoverRadius = 5;
			dataset.pointHoverBackgroundColor = t.surface;
			dataset.pointHoverBorderColor = colour;
			dataset.pointHoverBorderWidth = 2.5;
			dataset.order = primary ? 0 : 1;
		} );
	}

	/**
	 * Turns one series ({ label, data, colour, fill }) into a Chart.js
	 * dataset. colour is a token name such as "positive"; fill adds the
	 * gradient under the line (the first series has it by default).
	 *
	 * @param {Object} series Series.
	 * @return {Object}
	 */
	function datasetFrom( series ) {
		return { label: series.label, data: series.data, spanGaps: true, kdnaColour: series.colour, kdnaFill: series.fill };
	}

	/**
	 * Creates a smooth line chart with a gradient under the first series and
	 * a lighter comparison line for the second.
	 *
	 * @param {HTMLCanvasElement} canvas  Canvas inside an Insights root.
	 * @param {Object}            options labels, series ([ { label, data } ]), plus
	 *                                    optional formatters: xLabel(index),
	 *                                    yLabel(value), title(index), value(v),
	 *                                    seriesLabel(datasetIndex, index), footer(index).
	 * @return {Chart}
	 */
	function lineChart( canvas, options ) {
		var t = tokens( canvas );
		var data = {
			labels: options.labels,
			datasets: options.series.map( function ( series ) {
				return datasetFrom( series );
			} ),
		};

		// Style the lines before the first draw, so Chart.js defaults never show.
		styleLineDatasets( { data: data }, t );

		var chart = new Chart( canvas, {
			type: 'line',
			data: data,
			options: lineOptions( t, options ),
			plugins: [ latestPointPlugin, guideLinePlugin, revealPlugin ],
		} );

		chart.$kdna = options;
		chart.$kdnaKind = 'line';
		chart.$kdnaTokens = t;
		reveal( chart );
		charts.push( chart );
		describe( chart );
		return chart;
	}

	/**
	 * Replaces a line chart's data and redraws it with the reveal.
	 *
	 * @param {Chart}  chart   Chart from lineChart().
	 * @param {Object} options Same shape as lineChart() options.
	 */
	function updateLineChart( chart, options ) {
		chart.$kdna = options;
		chart.data.labels = options.labels;
		chart.data.datasets = options.series.map( function ( series ) {
			return datasetFrom( series );
		} );
		var t = tokens( chart.canvas );
		chart.options = lineOptions( t, options );
		styleLineDatasets( chart, t );
		chart.update( 'none' );
		reveal( chart );
	}

	/**
	 * Colours for donut segments: a segment colour set by a widget style
	 * control wins, otherwise the token named by the chart.
	 *
	 * @param {Object}   t     Tokens.
	 * @param {string[]} names Token names such as "accent" or "positive".
	 * @return {string[]}
	 */
	function donutColours( t, names ) {
		return names.map( function ( name, index ) {
			return t[ 'seg' + ( index + 1 ) ] || t[ name ] || name;
		} );
	}

	/**
	 * Creates a chunky donut chart whose segments sweep in.
	 *
	 * @param {HTMLCanvasElement} canvas  Canvas inside an Insights root.
	 * @param {Object}            options values, colours (token names such as
	 *                                    "accent", "positive", "accent2"), labels.
	 * @return {Chart}
	 */
	function donutChart( canvas, options ) {
		var t = tokens( canvas );
		var chart = new Chart( canvas, {
			type: 'doughnut',
			data: {
				labels: options.labels,
				datasets: [ {
					data: options.values,
					backgroundColor: donutColours( t, options.colours ),
					borderWidth: 0,
					spacing: options.values.filter( function ( v ) {
						return v > 0;
					} ).length > 1 ? t.donutSpacing : 0,
					hoverOffset: 4,
				} ],
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				cutout: t.donutCutout || options.cutout || '68%',
				rotation: options.rotation === undefined ? 0 : options.rotation,
				animation: reduceMotion ? false : { animateRotate: true, duration: 600, easing: 'easeOutCubic' },
				plugins: { legend: { display: false }, tooltip: { enabled: false } },
			},
		} );

		chart.$kdna = options;
		chart.$kdnaKind = 'donut';
		charts.push( chart );
		describe( chart );
		return chart;
	}

	/**
	 * Colours for each waterfall bar: totals in the accent colour, money
	 * coming in in the positive colour, money going out in the negative one.
	 *
	 * @param {Object}   t     Tokens.
	 * @param {Object[]} steps Steps with a type of add, subtract or total.
	 * @return {string[]}
	 */
	function waterfallColours( t, steps ) {
		return steps.map( function ( step, index ) {
			if ( step.type === 'total' ) {
				return index === steps.length - 1 ? ( step.amount < 0 ? t.negative : t.positive ) : t.accent;
			}
			return step.type === 'add' ? t.accent2 : withOpacity( t.negative, 0.85 );
		} );
	}

	/**
	 * Builds Chart.js options for the waterfall: the same quiet axes and
	 * tooltip card as the line charts.
	 *
	 * @param {Object} t       Tokens.
	 * @param {Object} options Options passed to waterfallChart().
	 * @return {Object}
	 */
	function waterfallOptions( t, options ) {
		var base = lineOptions( t, options );
		base.interaction = { mode: 'index', intersect: false };
		base.scales.x.ticks.maxTicksLimit = options.steps.length;
		base.scales.x.ticks.autoSkip = false;
		base.scales.x.ticks.font = { family: t.font, size: 11 };
		base.plugins.kdnaLatestPoint = { display: false };
		base.animation = reduceMotion ? false : { duration: 600, easing: 'easeOutCubic' };
		return base;
	}

	/**
	 * Creates a waterfall chart: floating bars that step down from net
	 * revenue to net profit, so you can see where the money went.
	 *
	 * @param {HTMLCanvasElement} canvas  Canvas inside an Insights root.
	 * @param {Object}            options steps ([ { label, amount, type, from, to } ]),
	 *                                    plus formatters xLabel(index), yLabel(value),
	 *                                    title(index), value(value, index).
	 * @return {Chart}
	 */
	function waterfallChart( canvas, options ) {
		var t = tokens( canvas );
		var colours = waterfallColours( t, options.steps );
		var chart = new Chart( canvas, {
			type: 'bar',
			data: {
				labels: options.steps.map( function ( step ) {
					return step.label;
				} ),
				datasets: [ {
					label: '',
					data: options.steps.map( function ( step ) {
						return [ step.from, step.to ];
					} ),
					backgroundColor: colours,
					borderColor: colours,
					borderWidth: 0,
					borderRadius: 6,
					borderSkipped: false,
					maxBarThickness: 44,
					categoryPercentage: 0.7,
				} ],
			},
			options: waterfallOptions( t, options ),
		} );

		chart.$kdna = options;
		chart.$kdnaKind = 'waterfall';
		charts.push( chart );
		describe( chart );
		return chart;
	}

	/**
	 * Replaces a waterfall chart's steps and redraws it.
	 *
	 * @param {Chart}  chart   Chart from waterfallChart().
	 * @param {Object} options Same shape as waterfallChart() options.
	 */
	function updateWaterfallChart( chart, options ) {
		var t = tokens( chart.canvas );
		var colours = waterfallColours( t, options.steps );
		chart.$kdna = options;
		chart.data.labels = options.steps.map( function ( step ) {
			return step.label;
		} );
		chart.data.datasets[ 0 ].data = options.steps.map( function ( step ) {
			return [ step.from, step.to ];
		} );
		chart.data.datasets[ 0 ].backgroundColor = colours;
		chart.data.datasets[ 0 ].borderColor = colours;
		chart.options = waterfallOptions( t, options );
		chart.update();
	}

	/**
	 * Builds Chart.js options for stacked bars: the same quiet axes and
	 * tooltip card as the line charts.
	 *
	 * @param {Object} t       Tokens.
	 * @param {Object} options Options passed to barChart().
	 * @return {Object}
	 */
	function barOptions( t, options ) {
		var base = lineOptions( t, options );
		base.scales.x.stacked = options.stacked !== false;
		base.scales.y.stacked = options.stacked !== false;
		base.plugins.kdnaLatestPoint = { display: false };
		base.animation = reduceMotion ? false : { duration: 500, easing: 'easeOutCubic' };
		return base;
	}

	/**
	 * Turns series into bar datasets with their token colours. The top
	 * series in a stack gets rounded corners.
	 *
	 * @param {Object}   t       Tokens.
	 * @param {Object[]} series  Series ({ label, data, colour }).
	 * @param {boolean}  stacked Whether the bars sit on top of each other
	 *                           (only the top one rounded) or side by side
	 *                           (every bar rounded).
	 * @return {Object[]}
	 */
	function barDatasets( t, series, stacked ) {
		return series.map( function ( item, index ) {
			var colour = t[ item.colour ] || item.colour || ( index === 0 ? t.line1 : t.line2 );
			return {
				label: item.label,
				data: item.data,
				kdnaColour: item.colour,
				backgroundColor: colour,
				borderColor: colour,
				borderWidth: 0,
				borderRadius: stacked === false || index === series.length - 1 ? { topLeft: 5, topRight: 5 } : 0,
				borderSkipped: false,
				maxBarThickness: 28,
				categoryPercentage: 0.72,
				barPercentage: 0.9,
			};
		} );
	}

	/**
	 * Creates a stacked bar chart, for example new and returning customers.
	 *
	 * @param {HTMLCanvasElement} canvas  Canvas inside an Insights root.
	 * @param {Object}            options labels, series ([ { label, data, colour } ]),
	 *                                    stacked (default true), plus the same
	 *                                    formatters as lineChart().
	 * @return {Chart}
	 */
	function barChart( canvas, options ) {
		var t = tokens( canvas );
		var chart = new Chart( canvas, {
			type: 'bar',
			data: { labels: options.labels, datasets: barDatasets( t, options.series, options.stacked ) },
			options: barOptions( t, options ),
		} );
		chart.$kdna = options;
		chart.$kdnaKind = 'bar';
		charts.push( chart );
		describe( chart );
		return chart;
	}

	/**
	 * Replaces a bar chart's data and redraws it.
	 *
	 * @param {Chart}  chart   Chart from barChart().
	 * @param {Object} options Same shape as barChart() options.
	 */
	function updateBarChart( chart, options ) {
		var t = tokens( chart.canvas );
		chart.$kdna = options;
		chart.data.labels = options.labels;
		chart.data.datasets = barDatasets( t, options.series, options.stacked );
		chart.options = barOptions( t, options );
		chart.update();
	}

	/**
	 * Re-reads the theme colours and redraws every chart, for example after
	 * switching between light and dark mode.
	 */
	function refreshAll() {
		charts = charts.filter( function ( chart ) {
			return chart.canvas && chart.canvas.isConnected;
		} );

		charts.forEach( function ( chart ) {
			var t = tokens( chart.canvas );
			if ( chart.$kdnaKind === 'line' ) {
				chart.options = lineOptions( t, chart.$kdna );
				styleLineDatasets( chart, t );
			} else if ( chart.$kdnaKind === 'bar' ) {
				chart.options = barOptions( t, chart.$kdna );
				chart.data.datasets = barDatasets( t, chart.$kdna.series, chart.$kdna.stacked );
			} else if ( chart.$kdnaKind === 'waterfall' ) {
				var colours = waterfallColours( t, chart.$kdna.steps );
				chart.options = waterfallOptions( t, chart.$kdna );
				chart.data.datasets[ 0 ].backgroundColor = colours;
				chart.data.datasets[ 0 ].borderColor = colours;
			} else if ( chart.$kdnaKind === 'donut' ) {
				chart.data.datasets[ 0 ].backgroundColor = donutColours( t, chart.$kdna.colours );
			}
			chart.update( 'none' );
		} );
	}

	// Redraw after the theme switches (after the new colours are in place).
	window.addEventListener( 'kdna:ei-theme-change', function () {
		window.requestAnimationFrame( function () {
			window.requestAnimationFrame( refreshAll );
		} );
	} );

	window.KDNAEI.charts = {
		available: !! Chart,
		reduceMotion: reduceMotion,
		tokens: tokens,
		withOpacity: withOpacity,
		lineChart: lineChart,
		updateLineChart: updateLineChart,
		donutChart: donutChart,
		waterfallChart: waterfallChart,
		barChart: barChart,
		updateBarChart: updateBarChart,
		updateWaterfallChart: updateWaterfallChart,
		refreshAll: refreshAll,
	};
}( window, document ) );
