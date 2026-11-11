/**
 * Editor of a feed (kkce_feed): live address of the feed, filter of a long list of post types and
 * warning about unsaved changes.
 *
 * Progressive enhancement of the "Feed settings" box: without this script the box shows the address of
 * the saved feed and works as before. The rules of the slug (pattern, maximum length, reserved slugs)
 * and the messages come from the server, printed in the box: nothing is duplicated here, and the
 * server still checks everything when the feed is saved (uniqueness included).
 *
 * @package KKCE
 */

( function () {
	'use strict';

	/**
	 * State of a slug, with the same codes and order of checks as Feed_Validator::validate_slug():
	 * 'empty', 'format', 'too_long', 'reserved', or '' when the slug can be used.
	 *
	 * @param {string}   slug      Slug, already trimmed.
	 * @param {RegExp}   pattern   Format of a slug.
	 * @param {number}   maxLength Maximum length.
	 * @param {string[]} reserved  Reserved slugs.
	 * @return {string} Error code, or an empty string.
	 */
	function slugState( slug, pattern, maxLength, reserved ) {
		if ( '' === slug ) {
			return 'empty';
		}
		if ( ! pattern.test( slug ) ) {
			return 'format';
		}
		if ( slug.length > maxLength ) {
			return 'too_long';
		}
		if ( -1 !== reserved.indexOf( slug ) ) {
			return 'reserved';
		}
		return '';
	}

	/**
	 * Updates the address of the feed while the slug is typed.
	 *
	 * @param {HTMLInputElement} input  Slug field.
	 * @param {HTMLElement}      output Element that shows the address.
	 */
	function bindAddress( input, output ) {
		const code = output.querySelector( 'code' );
		const messages = output.querySelectorAll( '[data-kkce-message]' );
		const pattern = new RegExp( '^(?:' + output.dataset.pattern + ')$' );
		const maxLength = parseInt( output.dataset.maxLength, 10 );
		const reserved = output.dataset.reserved ? output.dataset.reserved.split( ',' ) : [];
		// Address of the feed with a placeholder for the slug, built by the server with rest_url(): a query
		// string added by another plugin (a language, for example) stays after the slug.
		const template = output.dataset.template || '';
		const placeholder = output.dataset.placeholder || '';

		if ( ! code || ! placeholder || -1 === template.indexOf( placeholder ) || ! maxLength ) {
			return;
		}

		function update() {
			const slug = input.value.trim();
			const state = slugState( slug, pattern, maxLength, reserved );

			// split/join instead of replace(): a replacement string is never read as a pattern.
			code.textContent = '' === state ? template.split( placeholder ).join( slug ) : '';
			code.hidden = '' !== state;
			messages.forEach( function ( message ) {
				message.hidden = message.dataset.kkceMessage !== state;
			} );
		}

		input.addEventListener( 'input', update );
		update();
	}

	/**
	 * Hides the checkboxes of the post types whose name does not contain the text of the filter.
	 *
	 * The filter is printed hidden by the server only when the list is long; it is shown here, so without
	 * JavaScript every checkbox stays visible. Checked post types are always shown, and the form always
	 * sends every checkbox.
	 *
	 * @param {HTMLInputElement} filter   Filter field.
	 * @param {HTMLElement}      fieldset Fieldset of the post types.
	 */
	function bindPostTypeFilter( filter, fieldset ) {
		const labels = Array.prototype.filter.call( fieldset.querySelectorAll( 'label' ), function ( label ) {
			return null !== label.querySelector( 'input[type="checkbox"]' );
		} );

		function update() {
			const text = filter.value.trim().toLowerCase();
			labels.forEach( function ( label ) {
				const checkbox = label.querySelector( 'input[type="checkbox"]' );
				const name = ( label.textContent + ' ' + checkbox.value ).toLowerCase();
				const hidden = '' !== text && ! checkbox.checked && -1 === name.indexOf( text );
				// The admin style gives these labels `display: inline-block`, which wins over the `hidden`
				// attribute: the display is set on the element.
				label.style.display = hidden ? 'none' : '';
				if ( label.nextElementSibling && 'BR' === label.nextElementSibling.tagName ) {
					label.nextElementSibling.style.display = hidden ? 'none' : '';
				}
			} );
		}

		filter.closest( '.kkce-post-type-filter' ).hidden = false;
		filter.addEventListener( 'input', update );
	}

	/**
	 * Asks for confirmation before leaving the page with unsaved changes in the boxes of the feed.
	 *
	 * WordPress warns only about the title and the content of a post, not about the fields of a meta box.
	 * The mapping editor signals its changes with a `change` event that bubbles from its hidden field.
	 *
	 * @param {HTMLElement[]}   boxes Boxes of the feed (settings, mapping).
	 * @param {HTMLFormElement} form  Form of the editor.
	 */
	function bindUnsavedWarning( boxes, form ) {
		let changed = false;

		function markChanged() {
			changed = true;
		}

		boxes.forEach( function ( box ) {
			box.addEventListener( 'input', markChanged );
			box.addEventListener( 'change', markChanged );
		} );
		form.addEventListener( 'submit', function () {
			changed = false;
		} );
		window.addEventListener( 'beforeunload', function ( event ) {
			if ( changed ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );
	}

	function init() {
		const input = document.getElementById( 'kkce-feed-slug' );
		const output = document.getElementById( 'kkce-feed-url' );
		const box = document.getElementById( 'kkce_feed_settings' );
		const mappingBox = document.getElementById( 'kkce_feed_mapping' );
		const filter = document.getElementById( 'kkce-post-type-filter' );
		const postTypes = document.getElementById( 'kkce-feed-post-types' );

		if ( input && output ) {
			bindAddress( input, output );
		}
		if ( filter && postTypes ) {
			bindPostTypeFilter( filter, postTypes );
		}
		if ( box && input && input.form ) {
			bindUnsavedWarning( mappingBox ? [ box, mappingBox ] : [ box ], input.form );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
