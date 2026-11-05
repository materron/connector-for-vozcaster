/* global wp, vpconnAssistant */
( function () {
	'use strict';

	var cfg = window.vpconnAssistant || {};
	var i18n = cfg.i18n || {};

	function $( id ) {
		return document.getElementById( id );
	}

	/** Step 2: "Fill in with default data" and the cover picker. */
	function initPodcastForm() {
		var fill = $( 'vpconn-gs-fill' );
		if ( ! fill ) {
			return;
		}
		var artId = $( 'vpconn-gs-artwork-id' );
		var useDef = $( 'vpconn-gs-use-default' );
		var img = $( 'vpconn-gs-cover-img' );
		var label = $( 'vpconn-gs-cover-label' );

		fill.addEventListener( 'click', function () {
			var count = 0;
			var defaults = cfg.defaults || {};
			document.querySelectorAll( '[data-default]' ).forEach( function ( el ) {
				var key = el.getAttribute( 'data-default' );
				if ( '' === el.value && defaults[ key ] ) {
					el.value = defaults[ key ];
					// A select keeps '' when the value is not one of its options.
					if ( '' !== el.value ) {
						el.classList.add( 'vpconn-gs-filled' );
						count++;
						if ( 'category' === key ) {
							$( 'vpconn-gs-category-note' ).hidden = false;
						}
					}
				}
			} );
			var radios = document.querySelectorAll( 'input[name="explicit"]' );
			var anyChecked = Array.prototype.some.call( radios, function ( r ) {
				return r.checked;
			} );
			if ( ! anyChecked ) {
				radios[ 0 ].checked = true;
				count++;
			}
			if ( ( '' === artId.value || '0' === artId.value ) && '1' !== useDef.value && ! img.getAttribute( 'src' ) ) {
				useDef.value = '1';
				img.src = cfg.defaultCover;
				img.hidden = false;
				label.textContent = i18n.defaultCover;
				count++;
			}
			$( 'vpconn-gs-fill-msg' ).textContent = count ? i18n.filled : i18n.nothing;
		} );

		$( 'vpconn-gs-cover-pick' ).addEventListener( 'click', function () {
			var frame = wp.media( {
				title: i18n.pickCover,
				button: { text: i18n.select },
				library: { type: 'image' },
				multiple: false
			} );
			frame.on( 'select', function () {
				var a = frame.state().get( 'selection' ).first().toJSON();
				artId.value = a.id;
				useDef.value = '0';
				img.src = a.url;
				img.hidden = false;
				label.textContent = i18n.ownCover;
			} );
			frame.open();
		} );
	}

	/** Step 3: ask every few seconds whether the account is connected yet. */
	function initConnectPoll() {
		var box = $( 'vpconn-gs-connected' );
		if ( ! box ) {
			return;
		}
		if ( ! box.hidden ) {
			return;
		}
		var timer = window.setInterval( function () {
			var body = new URLSearchParams( { action: 'vpconn_gs_status', nonce: cfg.nonce } );
			fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( res ) {
					if ( ! res || ! res.success || ! res.data.connected ) {
						return;
					}
					window.clearInterval( timer );
					box.hidden = false;
					[ 'vpconn-gs-connect-cards', 'vpconn-gs-waiting' ].forEach( function ( id ) {
						if ( $( id ) ) {
							$( id ).hidden = true;
						}
					} );
					var next = $( 'vpconn-gs-next' );
					next.textContent = i18n.continue;
					next.classList.add( 'button-primary' );
				} )
				.catch( function () {} );
		}, cfg.pollMs || 4000 );
	}

	// The Guided setup index starts collapsed on phones, where it is very tall.
	var index = document.querySelector( '.vpconn-gs-index' );
	if ( index && window.matchMedia( '(max-width: 782px)' ).matches ) {
		index.removeAttribute( 'open' );
	}

	// wp_localize_script turns numbers into strings.
	var step = parseInt( cfg.step, 10 );
	if ( 2 === step ) {
		initPodcastForm();
	} else if ( 3 === step ) {
		initConnectPoll();
	}
}() );
