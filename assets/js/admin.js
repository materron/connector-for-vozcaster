/* global wp, vpconnAdmin */
( function () {
	'use strict';

	/**
	 * Opens the WordPress media picker to choose an intro, outro or separator audio file,
	 * or the cover style reference image, then stores the chosen attachment ID and
	 * submits the matching form.
	 *
	 * @param {string} type "intro", "outro", "separator" or "style".
	 */
	window.vpconnOpenMediaPicker = function ( type ) {
		var titles = {
			intro: vpconnAdmin.selectIntro,
			outro: vpconnAdmin.selectOutro,
			separator: vpconnAdmin.selectSeparator,
			style: vpconnAdmin.selectStyle
		};

		var frame = wp.media( {
			title: titles[ type ] || titles.intro,
			button: { text: vpconnAdmin.select },
			library: { type: 'style' === type ? 'image' : 'audio' },
			multiple: false
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			document.getElementById( 'vpconn_' + type + '_media_id' ).value = attachment.id;
			document.getElementById( 'vpconn_' + type + '_select_form' ).submit();
		} );

		frame.open();
	};
}() );
