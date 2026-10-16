/* Scene Calendar front end: genre chips, venue/search filters, list + month views. No dependencies. */
( function () {
	'use strict';

	function init( root ) {
		var toolbar = root.querySelector( '.scenecal-toolbar' );
		var chipsBox = root.querySelector( '.scenecal-genres' );
		var chips = chipsBox ? Array.prototype.slice.call( chipsBox.querySelectorAll( '.scenecal-chip' ) ) : [];
		var clearBtn = chipsBox ? chipsBox.querySelector( '.scenecal-chip-clear' ) : null;
		var list = root.querySelector( '.scenecal-list' );
		var month = root.querySelector( '.scenecal-month' );
		var noMatch = root.querySelector( '.scenecal-nomatch' );
		var events = Array.prototype.slice.call( root.querySelectorAll( '.scenecal-event' ) );
		var days = Array.prototype.slice.call( root.querySelectorAll( '.scenecal-day' ) );
		var locale = root.getAttribute( 'data-locale' ) || undefined;
		var today = root.getAttribute( 'data-today' );
		var state = { venue: '', genres: {}, q: '', view: root.getAttribute( 'data-view' ) || 'list', month: null };
		var modal = root.querySelector( '.scenecal-modal' );
		var dataEl = root.querySelector( '.scenecal-data' );
		var details = {};
		try {
			details = dataEl ? JSON.parse( dataEl.textContent ) : {};
		} catch ( e ) {
			details = {};
		}

		// Event details modal (native <dialog>: focus handling and Esc for free).
		// Without JS, or without <dialog> support, titles simply link to the tickets.
		var canModal = modal && typeof modal.showModal === 'function';
		function openModal( id, opener ) {
			var d = details[ id ];
			if ( ! canModal || ! d ) {
				return false;
			}
			var set = function ( key, value ) {
				Array.prototype.forEach.call( modal.querySelectorAll( '[data-k="' + key + '"]' ), function ( node ) {
					node.hidden = ! value || ( Array.isArray( value ) && ! value.length );
				} );
				var dd = modal.querySelector( 'dd[data-k="' + key + '"]' );
				if ( dd ) {
					dd.textContent = '';
					if ( Array.isArray( value ) ) {
						value.forEach( function ( v ) {
							var chip = document.createElement( 'span' );
							chip.className = 'scenecal-artist';
							chip.textContent = v;
							dd.appendChild( chip );
						} );
					} else {
						dd.textContent = value || '';
					}
				}
			};
			modal.querySelector( '.scenecal-modal-title' ).textContent = d.title;
			var g = modal.querySelector( '.scenecal-modal-genre' );
			g.textContent = d.genre || '';
			g.hidden = ! d.genre;
			modal.style.setProperty( '--scenecal-g', d.color || '' );
			set( 'when', d.when );
			set( 'venue', d.venue );
			set( 'price', d.price );
			set( 'lineup', d.lineup );
			set( 'description', d.description );
			var link = modal.querySelector( 'a[data-k="link"]' );
			link.hidden = ! /^https?:\/\//i.test( d.link || '' );
			link.href = link.hidden ? '#' : d.link;
			link.textContent = d.linkLabel;
			var c = d.cal || {};
			modal.querySelector( 'a[data-k="gcal"]' ).href = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
				+ '&text=' + encodeURIComponent( d.title || '' )
				+ '&dates=' + String( c.dates || '' ).replace( /[^0-9TZ\/]/g, '' )
				+ '&details=' + encodeURIComponent( c.details || '' )
				+ '&location=' + encodeURIComponent( c.location || '' );
			modal.querySelector( 'a[data-k="ics"]' ).href = d.ics;
			modal._opener = opener || null;
			modal.showModal();
			modal.querySelector( '.scenecal-modal-inner' ).scrollTop = 0;
			return true;
		}
		if ( canModal ) {
			modal.querySelector( '.scenecal-modal-close' ).addEventListener( 'click', function () {
				modal.close();
			} );
			// Click on the backdrop (outside the inner panel) closes.
			modal.addEventListener( 'click', function ( ev ) {
				if ( ev.target === modal ) {
					modal.close();
				}
			} );
			modal.addEventListener( 'close', function () {
				if ( modal._opener && modal._opener.focus ) {
					modal._opener.focus();
				}
			} );
			root.addEventListener( 'click', function ( ev ) {
				if ( ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey ) {
					return; // Let modified clicks open the ticket link in a new tab.
				}
				var trigger = ev.target.closest( '.scenecal-title a, .scenecal-title-btn, .scenecal-cell-event' );
				if ( ! trigger || modal.contains( trigger ) ) {
					return;
				}
				var holder = trigger.closest( '[data-id]' );
				if ( holder && openModal( holder.getAttribute( 'data-id' ), trigger ) ) {
					ev.preventDefault();
				}
			} );
		}

		if ( toolbar ) {
			toolbar.hidden = false;
			toolbar.addEventListener( 'input', onFilter );
			toolbar.addEventListener( 'change', onFilter );
			toolbar.addEventListener( 'click', function ( ev ) {
				var btn = ev.target.closest( '[data-view-btn]' );
				if ( btn ) {
					setView( btn.getAttribute( 'data-view-btn' ) );
				}
			} );
		}

		// Genre chips: none selected = all genres; selecting narrows to those.
		if ( chipsBox ) {
			chipsBox.hidden = false;
			chipsBox.addEventListener( 'click', function ( ev ) {
				var chip = ev.target.closest( '.scenecal-chip' );
				if ( chip ) {
					var g = chip.getAttribute( 'data-genre' );
					if ( state.genres[ g ] ) {
						delete state.genres[ g ];
					} else {
						state.genres[ g ] = true;
					}
					apply();
				} else if ( ev.target.closest( '.scenecal-chip-clear' ) ) {
					state.genres = {};
					apply();
				}
			} );
		}

		function onFilter( ev ) {
			var key = ev.target.getAttribute( 'data-filter' );
			if ( ! key ) {
				return;
			}
			state[ key ] = ( ev.target.value || '' ).toLowerCase().trim();
			apply();
		}

		function anyGenre() {
			for ( var k in state.genres ) {
				if ( Object.prototype.hasOwnProperty.call( state.genres, k ) ) {
					return true;
				}
			}
			return false;
		}

		// Everything except the genre filter (chip counts are computed from this).
		function matchesBase( el ) {
			if ( state.venue && el.getAttribute( 'data-venue' ).toLowerCase() !== state.venue ) {
				return false;
			}
			if ( state.q && el.getAttribute( 'data-search' ).indexOf( state.q ) === -1 ) {
				return false;
			}
			return true;
		}

		function matches( el ) {
			return matchesBase( el ) && ( ! anyGenre() || state.genres[ el.getAttribute( 'data-genre' ) ] );
		}

		function inScope( el ) {
			if ( state.view !== 'month' || ! state.month ) {
				return true;
			}
			var d = parseDate( el.getAttribute( 'data-date' ) );
			return d.getFullYear() === state.month.getFullYear() && d.getMonth() === state.month.getMonth();
		}

		function apply() {
			var shown = 0;
			var counts = {};
			events.forEach( function ( el ) {
				var ok = matches( el );
				el.hidden = ! ok;
				shown += ok ? 1 : 0;
				if ( matchesBase( el ) && inScope( el ) ) {
					var g = el.getAttribute( 'data-genre' );
					counts[ g ] = ( counts[ g ] || 0 ) + 1;
				}
			} );
			days.forEach( function ( day ) {
				day.hidden = ! day.querySelector( '.scenecal-event:not([hidden])' );
			} );
			if ( noMatch ) {
				noMatch.hidden = shown > 0 || events.length === 0;
			}
			var active = anyGenre();
			chips.forEach( function ( chip ) {
				var g = chip.getAttribute( 'data-genre' );
				chip.setAttribute( 'aria-pressed', state.genres[ g ] ? 'true' : 'false' );
				chip.classList.toggle( 'is-dimmed', active && ! state.genres[ g ] );
				var c = chip.querySelector( '.scenecal-chip-count' );
				if ( c ) {
					c.textContent = String( counts[ g ] || 0 );
				}
				// Nothing to show for this genre here (unless it's already selected).
				chip.disabled = ! counts[ g ] && ! state.genres[ g ];
			} );
			if ( clearBtn ) {
				clearBtn.hidden = ! active;
			}
			if ( state.view === 'month' ) {
				renderMonth();
			}
		}

		function setView( view ) {
			state.view = view;
			root.setAttribute( 'data-view', view );
			list.hidden = view !== 'list';
			month.hidden = view !== 'month';
			if ( toolbar ) {
				Array.prototype.forEach.call( toolbar.querySelectorAll( '[data-view-btn]' ), function ( b ) {
					b.setAttribute( 'aria-pressed', b.getAttribute( 'data-view-btn' ) === view ? 'true' : 'false' );
				} );
			}
			apply();
		}

		function parseDate( s ) {
			var p = s.split( '-' );
			return new Date( Number( p[ 0 ] ), Number( p[ 1 ] ) - 1, Number( p[ 2 ] ) );
		}

		function ymd( d ) {
			return d.getFullYear() + '-' + String( d.getMonth() + 1 ).padStart( 2, '0' ) + '-' + String( d.getDate() ).padStart( 2, '0' );
		}

		function renderMonth() {
			var byDate = {};
			events.forEach( function ( el ) {
				if ( ! el.hidden ) {
					var d = el.getAttribute( 'data-date' );
					( byDate[ d ] = byDate[ d ] || [] ).push( el );
				}
			} );
			if ( ! state.month ) {
				var start = parseDate( today );
				state.month = new Date( start.getFullYear(), start.getMonth(), 1 );
			}
			var m = state.month;
			var weekdayFmt = new Intl.DateTimeFormat( locale, { weekday: 'short' } );
			var longFmt = new Intl.DateTimeFormat( locale, { weekday: 'long', month: 'long', day: 'numeric' } );

			month.textContent = '';
			var head = document.createElement( 'div' );
			head.className = 'scenecal-month-head';
			var prev = document.createElement( 'button' );
			prev.type = 'button';
			prev.className = 'scenecal-nav';
			prev.setAttribute( 'aria-label', 'Previous month' );
			prev.textContent = '‹';
			var next = prev.cloneNode( false );
			next.setAttribute( 'aria-label', 'Next month' );
			next.textContent = '›';
			var title = document.createElement( 'h3' );
			title.textContent = m.toLocaleDateString( locale, { month: 'long', year: 'numeric' } );
			prev.addEventListener( 'click', function () {
				state.month = new Date( m.getFullYear(), m.getMonth() - 1, 1 );
				state.refocus = 'prev';
				apply();
			} );
			next.addEventListener( 'click', function () {
				state.month = new Date( m.getFullYear(), m.getMonth() + 1, 1 );
				state.refocus = 'next';
				apply();
			} );
			head.appendChild( prev );
			head.appendChild( title );
			head.appendChild( next );
			month.appendChild( head );

			var grid = document.createElement( 'div' );
			grid.className = 'scenecal-grid';
			for ( var i = 0; i < 7; i++ ) {
				var wd = document.createElement( 'div' );
				wd.className = 'scenecal-weekday';
				wd.textContent = weekdayFmt.format( new Date( 2024, 8, 1 + i ) ); // 2024-09-01 was a Sunday.
				grid.appendChild( wd );
			}

			// Whole weeks covering the month (Sunday first).
			var cursor = new Date( m.getFullYear(), m.getMonth(), 1 - m.getDay() );
			var last = new Date( m.getFullYear(), m.getMonth() + 1, 0 );
			var end = new Date( last.getFullYear(), last.getMonth(), last.getDate() + ( 6 - last.getDay() ) );
			while ( cursor <= end ) {
				var key = ymd( cursor );
				var cell = document.createElement( 'div' );
				var dayEvents = byDate[ key ] || [];
				cell.className = 'scenecal-cell';
				if ( cursor.getMonth() !== m.getMonth() ) {
					cell.className += ' is-other';
				}
				if ( key === today ) {
					cell.className += ' is-today';
				}
				if ( ! dayEvents.length ) {
					cell.className += ' is-empty';
				}
				var num = document.createElement( 'div' );
				num.className = 'scenecal-daynum';
				num.textContent = String( cursor.getDate() );
				var full = document.createElement( 'span' );
				full.className = 'scenecal-daylabel';
				full.textContent = longFmt.format( cursor );
				num.appendChild( full );
				cell.appendChild( num );

				if ( dayEvents.length ) {
					var items = document.createElement( 'div' );
					items.className = 'scenecal-cell-events';
					items.tabIndex = 0; // Scrollable region reachable by keyboard.
					items.setAttribute( 'aria-label', longFmt.format( cursor ) + ': ' + dayEvents.length );
					dayEvents.forEach( function ( el ) {
						var link = el.querySelector( '.scenecal-title a' );
						// A link (falls back to the tickets page) or a button, so every event is keyboard-reachable.
						var item = document.createElement( link ? 'a' : 'button' );
						item.className = 'scenecal-cell-event';
						item.setAttribute( 'data-id', el.getAttribute( 'data-id' ) );
						if ( ! link ) {
							item.type = 'button';
						}
						item.style.setProperty( '--scenecal-g', el.style.getPropertyValue( '--scenecal-g' ) );
						if ( link ) {
							item.href = link.href;
							item.target = '_blank';
							item.rel = 'noopener';
						}
						var time = el.getAttribute( 'data-time' );
						var name = el.querySelector( '.scenecal-title' ).textContent.trim();
						// Titles only (cells are small); time and venue are in the tooltip.
						item.textContent = name;
						item.title = name + ' — ' + el.getAttribute( 'data-venue' ) + ( time ? ' · ' + time : '' );
						items.appendChild( item );
					} );
					cell.appendChild( items );
				}
				grid.appendChild( cell );
				cursor = new Date( cursor.getFullYear(), cursor.getMonth(), cursor.getDate() + 1 );
			}
			month.appendChild( grid );
			var visibleCount = Object.keys( byDate ).filter( function ( k ) {
				var d = parseDate( k );
				return d.getFullYear() === m.getFullYear() && d.getMonth() === m.getMonth();
			} ).length;
			if ( ! visibleCount ) {
				var empty = document.createElement( 'p' );
				empty.className = 'scenecal-nomatch';
				empty.textContent = noMatch ? noMatch.textContent : '';
				month.appendChild( empty );
			}
			// The header is rebuilt on every render; keep keyboard focus on the nav button.
			if ( state.refocus ) {
				( 'prev' === state.refocus ? prev : next ).focus();
				state.refocus = null;
			}
		}

		setView( state.view );
	}

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( '.scenecal' ), init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
