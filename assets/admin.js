/* Scene Calendar admin helpers. */
( function () {
	'use strict';

	var cfg = window.SceneCalAdmin || {};

	// Confirm destructive links.
	document.addEventListener( 'click', function ( ev ) {
		var link = ev.target.closest( '.scenecal-confirm-delete' );
		if ( link && ! window.confirm( ( cfg.i18n && cfg.i18n.confirmDelete ) || 'Delete?' ) ) {
			ev.preventDefault();
		}
	} );

	// Venue form: show the help text and settings fields for the selected parser.
	var parser = document.getElementById( 'sv-parser' );
	if ( parser ) {
		var sync = function () {
			var slug = parser.value;
			document.querySelectorAll( '.scenecal-parser-help [data-parser]' ).forEach( function ( p ) {
				p.hidden = p.getAttribute( 'data-parser' ) !== slug;
			} );
			document.querySelectorAll( '.scenecal-parser-field' ).forEach( function ( row ) {
				var list = ( row.getAttribute( 'data-parsers' ) || '' ).split( ' ' );
				row.hidden = slug === 'none' || ( slug !== 'auto' && list.indexOf( slug ) === -1 );
			} );
		};
		parser.addEventListener( 'change', sync );
		sync();
	}

	// Venue form: "Test parser" runs the selected parser with the unsaved form values.
	var testBtn = document.getElementById( 'scenecal-test-parser' );
	if ( testBtn && cfg.ajaxUrl ) {
		var out = document.getElementById( 'scenecal-test-result' );
		var i18n = cfg.i18n || {};
		var fmt = function ( str ) {
			var args = Array.prototype.slice.call( arguments, 1 );
			return String( str || '' ).replace( /%(\d)\$[sd]|%[sd]/g, function ( m, n ) {
				return String( n ? args[ Number( n ) - 1 ] : args.shift() );
			} );
		};
		var el = function ( tag, text, cls ) {
			var node = document.createElement( tag );
			if ( text !== undefined ) {
				node.textContent = text;
			}
			if ( cls ) {
				node.className = cls;
			}
			return node;
		};
		var toggle = function () {
			var row = testBtn.closest( 'tr' );
			if ( row && parser ) {
				row.hidden = parser.value === 'none';
			}
		};
		if ( parser ) {
			parser.addEventListener( 'change', toggle );
			toggle();
		}

		testBtn.addEventListener( 'click', function () {
			var data = new FormData( testBtn.form );
			data.set( 'action', 'scenecal_test_parser' );
			data.set( '_ajax_nonce', cfg.testNonce );
			testBtn.disabled = true;
			out.textContent = '';
			out.appendChild( el( 'p', i18n.testing || 'Fetching…', 'scenecal-muted' ) );

			fetch( cfg.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( res ) {
					out.textContent = '';
					var d = ( res && res.data ) || {};
					if ( ! res || ! res.success || d.error ) {
						var p = el( 'div', undefined, 'notice notice-error inline' );
						p.appendChild( el( 'p', ( i18n.failed || 'Test failed:' ) + ' ' + ( d.error || 'unknown error' ) ) );
						out.appendChild( p );
					} else {
						var ok = el( 'div', undefined, 'notice inline ' + ( d.count ? 'notice-success' : 'notice-warning' ) );
						var msg = fmt( i18n.found, d.count, d.parser );
						if ( d.count > d.events.length ) {
							msg += ' ' + fmt( i18n.showing, d.events.length );
						}
						ok.appendChild( el( 'p', d.count ? msg : ( i18n.none || 'No events found.' ) ) );
						out.appendChild( ok );
					}
					if ( d.events && d.events.length ) {
						var table = el( 'table', undefined, 'widefat striped scenecal-test-table' );
						var head = el( 'tr' );
						( i18n.cols || [ 'Date', 'Time', 'Title', 'Venue', 'Price' ] ).forEach( function ( c ) {
							head.appendChild( el( 'th', c ) );
						} );
						var thead = el( 'thead' );
						thead.appendChild( head );
						table.appendChild( thead );
						var tbody = el( 'tbody' );
						d.events.forEach( function ( e ) {
							var tr = el( 'tr' );
							tr.appendChild( el( 'td', e.date ) );
							tr.appendChild( el( 'td', e.time ) );
							var td = el( 'td' );
							if ( e.link && /^https?:\/\//i.test( e.link ) ) {
								var a = el( 'a', e.title );
								a.href = e.link;
								a.target = '_blank';
								a.rel = 'noopener';
								td.appendChild( a );
							} else {
								td.textContent = e.title;
							}
							tr.appendChild( td );
							tr.appendChild( el( 'td', e.venue ) );
							tr.appendChild( el( 'td', e.price ) );
							tbody.appendChild( tr );
						} );
						table.appendChild( tbody );
						out.appendChild( table );
					}
					if ( d.log && d.log.length ) {
						out.appendChild( el( 'pre', d.log.join( '\n' ), 'scenecal-log scenecal-log-short' ) );
					}
				} )
				.catch( function ( err ) {
					out.textContent = '';
					out.appendChild( el( 'p', ( i18n.failed || 'Test failed:' ) + ' ' + err, 'scenecal-muted' ) );
				} )
				.then( function () {
					testBtn.disabled = false;
				} );
		} );
	}

	// Runs page: poll the active run's progress and log.
	var active = document.querySelector( '.scenecal-active[data-run]' );
	if ( active && cfg.ajaxUrl ) {
		var runId = active.getAttribute( 'data-run' );
		var progress = active.querySelector( '.scenecal-progress' );
		var log = document.getElementById( 'scenecal-log' );
		var poll = function () {
			var url = cfg.ajaxUrl + '?action=scenecal_run_status&run=' + encodeURIComponent( runId ) + '&_ajax_nonce=' + encodeURIComponent( cfg.nonce );
			fetch( url, { credentials: 'same-origin' } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( res ) {
					if ( ! res || ! res.success ) {
						return;
					}
					if ( progress ) {
						progress.textContent = res.data.progress;
					}
					if ( log ) {
						var atBottom = log.scrollTop + log.clientHeight >= log.scrollHeight - 20;
						log.textContent = res.data.log;
						if ( atBottom ) {
							log.scrollTop = log.scrollHeight;
						}
					}
					if ( res.data.status === 'queued' || res.data.status === 'running' ) {
						setTimeout( poll, 5000 );
					} else {
						window.location.reload();
					}
				} )
				.catch( function () {
					setTimeout( poll, 15000 );
				} );
		};
		if ( log ) {
			log.scrollTop = log.scrollHeight;
		}
		setTimeout( poll, 3000 );
	}
}() );
