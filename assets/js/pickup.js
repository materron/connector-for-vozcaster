/* global vpconnPickup */
( function () {
	'use strict';

	/**
	 * The pickup page: collects what VozCaster has waiting for this site, one
	 * step per request (each step stays well under the hosting's time limit).
	 */
	var cfg = window.vpconnPickup || {};
	var button = document.getElementById( 'vpconn-go' );
	if ( ! button ) {
		return;
	}
	var box = document.getElementById( 'vpconn-progress' );
	var bar = document.getElementById( 'vpconn-bar' );
	var status = document.getElementById( 'vpconn-status' );
	var results = document.getElementById( 'vpconn-results' );
	var steps = 0;

	function say( text ) {
		status.textContent = text;
	}

	function addResult( r ) {
		var li = document.createElement( 'li' );
		if ( ! r.ok ) {
			li.className = 'vpconn-bad';
			li.textContent = '❌ ' + cfg.i18n.failed + ' ' + ( r.title || '' ) + ' — ' + ( r.error || '' );
			results.appendChild( li );
			return;
		}
		var label = cfg.i18n.published;
		if ( r.status === 'draft' ) {
			label = cfg.i18n.draft;
		} else if ( r.status === 'future' ) {
			label = cfg.i18n.future;
		} else if ( r.status === 'pending' ) {
			li.textContent = '📨 ' + ( r.title || '' ) + ' — ' + cfg.i18n.pending;
			results.appendChild( li );
			return;
		}
		li.appendChild( document.createTextNode( '✅ ' + label + ' ' ) );
		if ( r.url ) {
			var a = document.createElement( 'a' );
			a.href = r.url;
			a.textContent = r.title || r.url;
			li.appendChild( a );
		} else {
			li.appendChild( document.createTextNode( r.title || '' ) );
		}
		results.appendChild( li );
	}

	function fail( message ) {
		say( '❌ ' + cfg.i18n.failed + ' ' + message );
		button.disabled = false;
		button.textContent = cfg.i18n.retry;
		button.hidden = false;
	}

	function step() {
		fetch( cfg.stepUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { code: cfg.code } )
		} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( data ) {
				steps++;
				if ( data.state === 'busy' ) {
					setTimeout( step, 2000 );
					return;
				}
				if ( data.state === 'error' ) {
					fail( data.message || '' );
					return;
				}
				if ( data.state === 'done' ) {
					bar.style.width = '100%';
					say( cfg.i18n.done );
					( data.results || [] ).forEach( addResult );
					return;
				}
				if ( data.kind === 'upload_audio' ) {
					say( cfg.i18n.uploading + ' ' + ( data.percent || 0 ) + ' %' );
					bar.style.width = Math.max( 5, data.percent || 0 ) + '%';
				} else {
					say( cfg.i18n.working + ( data.label ? ' ' + data.label : '' ) );
					bar.style.width = Math.min( 95, 10 + steps * 8 ) + '%';
				}
				step();
			} )
			.catch( function ( err ) {
				fail( String( err && err.message ? err.message : err ) );
			} );
	}

	button.addEventListener( 'click', function () {
		button.disabled = true;
		button.hidden = true;
		box.hidden = false;
		say( cfg.i18n.working );
		step();
	} );
}() );
