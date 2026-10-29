/**
 * Editor side of the VozCaster blocks. Written without JSX so the plugin
 * needs no build step. The blocks are rendered on the server (render.php);
 * in the editor each one shows what it will do, because its real content
 * depends on who is logged in when the page is visited.
 */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { Placeholder, PanelBody, SelectControl } = wp.components;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { __ } = wp.i18n;

	const feeds = ( window.vpconnBlocks && window.vpconnBlocks.feeds ) || [];

	const help = {
		'vozcaster/connect': __( 'Each logged-in user sees the buttons to connect their account to the VozCaster app or Telegram, for the podcasts they may publish in.', 'connector-for-vozcaster' ),
		'vozcaster/classes': __( 'Teachers see the classes of their podcasts here, create new ones and choose who reviews each class.', 'connector-for-vozcaster' ),
		'vozcaster/review': __( 'Reviewers see the episodes waiting for them here, listen to them and publish them or return them with a comment.', 'connector-for-vozcaster' ),
		'vozcaster/access': __( 'The people who manage VozCaster choose here who publishes in each podcast and who needs a review.', 'connector-for-vozcaster' ),
	};

	const titles = {
		'vozcaster/connect': __( 'Connect with VozCaster', 'connector-for-vozcaster' ),
		'vozcaster/classes': __( 'VozCaster classes', 'connector-for-vozcaster' ),
		'vozcaster/review': __( 'VozCaster review', 'connector-for-vozcaster' ),
		'vozcaster/access': __( 'VozCaster access', 'connector-for-vozcaster' ),
	};

	Object.keys( help ).forEach( function ( name ) {
		registerBlockType( name, {
			title: titles[ name ],
			edit: function ( props ) {
				const placeholder = el(
					Placeholder,
					{ icon: 'microphone', label: titles[ name ], instructions: help[ name ] },
					el( 'em', null, __( 'Visitors who are not logged in are asked to log in.', 'connector-for-vozcaster' ) )
				);
				if ( 'vozcaster/connect' !== name ) {
					return el( 'div', useBlockProps(), placeholder );
				}
				const options = [ { label: __( 'All their podcasts', 'connector-for-vozcaster' ), value: '' } ].concat(
					feeds.map( function ( f ) {
						return { label: f.name, value: f.slug };
					} )
				);
				return el(
					Fragment,
					null,
					el(
						InspectorControls,
						null,
						el(
							PanelBody,
							{ title: __( 'Podcast', 'connector-for-vozcaster' ) },
							el( SelectControl, {
								label: __( 'Show the buttons for', 'connector-for-vozcaster' ),
								value: props.attributes.feed,
								options: options,
								onChange: function ( value ) {
									props.setAttributes( { feed: value } );
								},
							} )
						)
					),
					el( 'div', useBlockProps(), placeholder )
				);
			},
			save: function () {
				return null;
			},
		} );
	} );
} )( window.wp );
