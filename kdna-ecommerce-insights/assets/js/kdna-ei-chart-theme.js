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

		return {
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
		};
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
				'<span class="kdna-ei-tooltip__dot" style="background:' + escapeHtml( dataset.borderColor ) + '"></span>' +
				'<span class="kdna-ei-tooltip__label">' + escapeHtml( label ) + '</span>' +
				'<span class="kdna-ei-tooltip__value">' + escapeHtml( helpers.value ? helpers.value( value ) : String( value ) ) + '</span>' +
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
		var gradient = chart.ctx.createLinearGradient( 0, area.top, 0, area.bottom );
		gradient.addColorStop( 0, withOpacity( colour, 0.35 ) );
		gradient.addColorStop( 1, withOpacity( colour, 0 ) );
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
						color: t.muted,
						font: { family: t.font, size: 12 },
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
					grid: { color: t.border, drawTicks: false },
					border: { display: false, dash: [ 4, 4 ] },
					ticks: {
						color: t.muted,
						font: { family: t.font, size: 12 },
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
				kdnaLatestPoint: { fill: t.surface, stroke: t.accent, radius: 6, borderWidth: 2.5 },
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
		chart.data.datasets.forEach( function ( dataset, index ) {
			var primary = index === 0;
			var colour = primary ? t.accent : t.accent2;
			dataset.borderColor = colour;
			dataset.backgroundColor = primary ? function ( ctx ) {
				return areaGradient( ctx.chart, colour );
			} : 'transparent';
			dataset.fill = primary ? 'origin' : false;
			dataset.borderWidth = primary ? 2.5 : 2;
			dataset.tension = 0.4;
			dataset.cubicInterpolationMode = 'monotone';
			dataset.pointRadius = 0;
			dataset.pointHoverRadius = 5;
			dataset.pointHoverBackgroundColor = t.surface;
			dataset.pointHoverBorderColor = colour;
			dataset.pointHoverBorderWidth = 2.5;
			dataset.order = primary ? 0 : 1;
		} );
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
				return { label: series.label, data: series.data, spanGaps: true };
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
		reveal( chart );
		charts.push( chart );
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
			return { label: series.label, data: series.data, spanGaps: true };
		} );
		var t = tokens( chart.canvas );
		chart.options = lineOptions( t, options );
		styleLineDatasets( chart, t );
		chart.update( 'none' );
		reveal( chart );
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
					backgroundColor: options.colours.map( function ( name ) {
						return t[ name ] || name;
					} ),
					borderWidth: 0,
					spacing: options.values.filter( function ( v ) {
						return v > 0;
					} ).length > 1 ? 3 : 0,
					hoverOffset: 4,
				} ],
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				cutout: options.cutout || '68%',
				rotation: options.rotation === undefined ? 0 : options.rotation,
				animation: reduceMotion ? false : { animateRotate: true, duration: 600, easing: 'easeOutCubic' },
				plugins: { legend: { display: false }, tooltip: { enabled: false } },
			},
		} );

		chart.$kdna = options;
		chart.$kdnaKind = 'donut';
		charts.push( chart );
		return chart;
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
			} else if ( chart.$kdnaKind === 'donut' ) {
				chart.data.datasets[ 0 ].backgroundColor = chart.$kdna.colours.map( function ( name ) {
					return t[ name ] || name;
				} );
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
		refreshAll: refreshAll,
	};
}( window, document ) );
