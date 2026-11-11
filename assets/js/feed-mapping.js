/**
 * Mapping editor of a feed (kkce_feed): rows of the mapping, built from the data printed by the
 * "Mapping" box.
 *
 * Every row writes one key in every exported item (with null when there is no value: the mapping is a
 * contract). A row has an output key, a rule and an output type:
 * - rule "Field": a chain of fields for every post type (`*`), and optional exceptions for single post
 *   types, which replace the general chain for that type. In a chain the first field with a value wins;
 *   the next ones are fallbacks. The options of the fields (run shortcodes, raw content) are shown only
 *   when a field of the row accepts them, with their warnings;
 * - rule "Fixed value": a text for every post type, with the same exceptions; the output type of the row
 *   converts it (a number, true or false).
 * Rows can be added, removed and moved. The script keeps the mapping in a hidden field
 * `kkce_feed[mapping]` that it creates itself: without JavaScript the field does not exist and the saved
 * mapping stays as it is. The checks made here only help while typing: the server checks the whole
 * mapping again when the feed is saved.
 *
 * The catalog of the sources comes from the page; when the checked post types change, a new one is asked
 * to the admin route `GET /kkce-admin/v1/catalog` with wp.apiFetch, which sends the REST nonce. The DOM is
 * built with createElement and textContent only: no HTML string is ever parsed.
 *
 * @package KKCE
 */

( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.i18n || ! wp.apiFetch ) {
		return;
	}

	const { __, _n, sprintf } = wp.i18n;

	/**
	 * Labels used by several controls.
	 */
	const LABEL_FIELD = __( 'Field', 'kk-content-exporter' );
	const LABEL_UP = __( 'Move up', 'kk-content-exporter' );
	const LABEL_DOWN = __( 'Move down', 'kk-content-exporter' );
	const LABEL_REMOVE = __( 'Remove', 'kk-content-exporter' );
	const LABEL_REMOVE_EXCEPTION = __( 'Remove exception', 'kk-content-exporter' );
	const LABEL_ADD_FALLBACK = __( 'Add fallback', 'kk-content-exporter' );
	const LABEL_INSERT_FIELD = __( 'Insert field', 'kk-content-exporter' );

	/**
	 * Output types of a row, in the order shown, with their labels.
	 */
	const TYPES = [
		[ 'auto', __( 'Automatic (type of the field)', 'kk-content-exporter' ) ],
		[ 'string', __( 'Text', 'kk-content-exporter' ) ],
		[ 'html', __( 'HTML', 'kk-content-exporter' ) ],
		[ 'number', __( 'Number', 'kk-content-exporter' ) ],
		[ 'boolean', __( 'True or false', 'kk-content-exporter' ) ],
		[ 'datetime', __( 'Date and time', 'kk-content-exporter' ) ],
		[ 'url', __( 'URL', 'kk-content-exporter' ) ],
		[ 'list', __( 'List', 'kk-content-exporter' ) ],
	];

	/**
	 * Rules this interface can edit. The rules of a later version are kept unchanged.
	 */
	const KINDS = [
		[ 'field', LABEL_FIELD ],
		[ 'fixed', __( 'Fixed value', 'kk-content-exporter' ) ],
		[ 'template', __( 'Template', 'kk-content-exporter' ) ],
	];


	/**
	 * Delay before asking a new catalog, so that several checkboxes changed quickly make one request.
	 */
	const CATALOG_DELAY = 300;

	/**
	 * Delay before computing the preview again after a change, so that typing makes one request.
	 */
	const PREVIEW_DELAY = 600;


	/**
	 * Creates an element.
	 *
	 * @param {string} tag        Tag name.
	 * @param {Object} attributes Properties set on the element (className, id, type, ...); names starting
	 *                            with `aria` or `data-` are set as attributes.
	 * @param {string} text       Text content.
	 * @return {HTMLElement} The element.
	 */
	function el( tag, attributes, text ) {
		const node = document.createElement( tag );
		Object.keys( attributes || {} ).forEach( function ( name ) {
			if ( name.startsWith( 'aria' ) || name.startsWith( 'data-' ) ) {
				node.setAttribute( name.replace( /^aria([A-Z])/, function ( match, letter ) {
					return 'aria-' + letter.toLowerCase();
				} ), attributes[ name ] );
			} else {
				node[ name ] = attributes[ name ];
			}
		} );
		if ( undefined !== text ) {
			node.textContent = text;
		}
		return node;
	}

	/**
	 * Creates a select element with its options.
	 *
	 * @param {Object}  attributes Properties of the select.
	 * @param {Array[]} options    Pairs [value, label].
	 * @param {string}  selected   Selected value.
	 * @return {HTMLSelectElement} The select.
	 */
	function select( attributes, options, selected ) {
		const node = el( 'select', attributes );
		options.forEach( function ( option ) {
			node.appendChild( el( 'option', { value: option[ 0 ], selected: option[ 0 ] === selected }, option[ 1 ] ) );
		} );
		return node;
	}

	/**
	 * Whether this interface can edit a row: a `field` row whose sources are a map of chains, or a `fixed`
	 * row whose values are a map of values. Other rows are kept unchanged.
	 *
	 * @param {Object} row Row of the mapping.
	 * @return {boolean} Whether the row can be edited.
	 */
	function isEditable( row ) {
		const isMap = function ( value ) {
			return value && 'object' === typeof value && ! Array.isArray( value );
		};
		if ( 'field' === row.kind ) {
			return isMap( row.sources ) && Object.keys( row.sources ).every( function ( scope ) {
				return Array.isArray( row.sources[ scope ] );
			} );
		}
		if ( 'fixed' === row.kind ) {
			return isMap( row.values );
		}
		if ( 'template' === row.kind ) {
			return isMap( row.templates );
		}
		return false;
	}

	/**
	 * Map of the scopes of an editable row: chains of a `field` row, values of a `fixed` row, templates of a
	 * `template` row.
	 *
	 * @param {Object} row Row of the mapping.
	 * @return {Object} Map of post types (or `*`).
	 */
	function scopeMap( row ) {
		if ( 'field' === row.kind ) {
			return row.sources;
		}
		return 'template' === row.kind ? row.templates : row.values;
	}

	/**
	 * Sources written in the placeholders of a template, for the warnings only: the server reads the template
	 * again and checks everything.
	 *
	 * @param {string} template Template.
	 * @return {string[]} Source identifiers.
	 */
	function templateSources( template ) {
		const found = [];
		const text = String( template || '' ).replace( /\{\{|\}\}/g, '' );
		( text.match( /\{[^{}]*\}/g ) || [] ).forEach( function ( placeholder ) {
			placeholder.slice( 1, -1 ).split( '|' ).forEach( function ( alternative ) {
				const id = alternative.trim();
				if ( /^[a-z][a-z0-9]*\.[A-Za-z0-9_.:-]+$/.test( id ) && -1 === found.indexOf( id ) ) {
					found.push( id );
				}
			} );
		} );
		return found;
	}

	/**
	 * Scopes of a row (`*` first, then the exceptions in their order).
	 *
	 * @param {Object} map Sources or values of the row.
	 * @return {string[]} Scopes.
	 */
	function scopesOf( map ) {
		const scopes = Object.keys( map ).filter( function ( scope ) {
			return '*' !== scope;
		} );
		return [ '*' ].concat( scopes );
	}

	/**
	 * Part of an element id for a scope (`*` cannot be used in an id).
	 *
	 * @param {string} scope Scope.
	 * @return {string} Id part.
	 */
	function scopeId( scope ) {
		return '*' === scope ? 'all' : 't-' + scope;
	}

	/**
	 * Every source of a `field` row (every chain) or of a `template` row (every template), without duplicates.
	 *
	 * @param {Object} row Row of the mapping.
	 * @return {string[]} Source identifiers.
	 */
	function rowSources( row ) {
		const found = [];
		if ( 'template' === row.kind ) {
			Object.keys( row.templates || {} ).forEach( function ( scope ) {
				templateSources( row.templates[ scope ] ).forEach( function ( source ) {
					if ( -1 === found.indexOf( source ) ) {
						found.push( source );
					}
				} );
			} );
			return found;
		}
		Object.keys( row.sources || {} ).forEach( function ( scope ) {
			( row.sources[ scope ] || [] ).forEach( function ( source ) {
				if ( source && -1 === found.indexOf( source ) ) {
					found.push( String( source ) );
				}
			} );
		} );
		return found;
	}

	/**
	 * Error of an output key, or an empty string when the key can be used.
	 *
	 * @param {string}   key       Key.
	 * @param {string[]} otherKeys Keys of the other rows.
	 * @param {number}   maxLength Maximum length.
	 * @param {RegExp}   pattern   Allowed format, given by the server.
	 * @return {string} Message.
	 */
	function keyError( key, otherKeys, maxLength, pattern ) {
		if ( '' === key ) {
			return __( 'Enter a key.', 'kk-content-exporter' );
		}
		if ( key.length > maxLength ) {
			/* translators: %d: maximum number of characters of a key. */
			return sprintf( __( 'Use at most %d characters.', 'kk-content-exporter' ), maxLength );
		}
		if ( ! pattern.test( key ) ) {
			return __( 'Use only letters, digits, underscores, dots and dashes.', 'kk-content-exporter' );
		}
		if ( /^[0-9]+$/.test( key ) ) {
			return __( 'A key cannot be made only of digits.', 'kk-content-exporter' );
		}
		if ( -1 !== otherKeys.indexOf( key ) ) {
			return __( 'Another row already uses this key.', 'kk-content-exporter' );
		}
		return '';
	}

	/**
	 * The mapping editor bound to its container.
	 *
	 * @param {HTMLElement} container Container printed by the box.
	 * @param {Object}      data      Initial data.
	 */
	function MappingEditor( container, data ) {
		const mapping = data.mapping && Array.isArray( data.mapping.rows ) ? data.mapping : { version: 1, rows: [] };
		// Rules of the keys and limits come from the server (Mapping_Validator): nothing is copied here.
		const keyPattern = new RegExp( data.keyPattern );
		let nextId = 1;
		let catalog = data.catalog;
		let request = 0;
		let timer = null;
		// A saved mapping is never replaced by the proposed rows; an empty one is, until it is touched.
		let touched = mapping.rows.length > 0;
		let rows = mapping.rows.map( function ( row ) {
			return { uid: nextId++, data: row, autoKey: null };
		} );

		const list = el( 'ol', { className: 'kkce-mapping-rows' } );
		const empty = el( 'p', { className: 'kkce-mapping-empty' }, __( 'No rows yet. Add a row to choose what the feed exports.', 'kk-content-exporter' ) );
		const addButton = el( 'button', { type: 'button', className: 'button' }, __( 'Add row', 'kk-content-exporter' ) );
		const limit = el( 'p', { className: 'description', hidden: true } );
		const status = el( 'p', { className: 'screen-reader-text', ariaLive: 'polite' } );
		const field = el( 'input', { type: 'hidden', name: data.field } );

		// Two columns when the box is wide (rules, then the preview that stays in view), one otherwise: see the CSS.
		const editor = el( 'div', { className: 'kkce-mapping-editor' } );
		const layout = el( 'div', { className: 'kkce-mapping-layout' } );
		const preview = createPreview();

		container.textContent = '';
		[ empty, list, addButton, limit ].forEach( function ( node ) {
			editor.appendChild( node );
		} );
		layout.appendChild( editor );
		layout.appendChild( preview.node );
		[ layout, status, field ].forEach( function ( node ) {
			container.appendChild( node );
		} );

		/**
		 * Announces a message to screen readers.
		 *
		 * @param {string} message Message.
		 */
		function announce( message ) {
			status.textContent = '';
			window.setTimeout( function () {
				status.textContent = message;
			}, 50 );
		}

		/**
		 * Source of the catalog with the given identifier.
		 *
		 * @param {string} id Identifier.
		 * @return {Object|null} Source.
		 */
		function findSource( id ) {
			return catalog.sources.find( function ( source ) {
				return source.id === id;
			} ) || null;
		}

		/**
		 * Options accepted by the sources of a row, from the catalog.
		 *
		 * @param {Object} row Row of the mapping.
		 * @return {string[]} Option names.
		 */
		function acceptedOptions( row ) {
			const accepted = [];
			rowSources( row ).forEach( function ( id ) {
				const source = findSource( id );
				( source ? source.options : [] ).forEach( function ( option ) {
					if ( -1 === accepted.indexOf( option ) ) {
						accepted.push( option );
					}
				} );
			} );
			return accepted;
		}

		/**
		 * Keeps the data of a `field` row consistent: options only when a source accepts them, without the ones
		 * turned off (`false`; other values, such as a date format, are kept), confirmations only for sources
		 * still in the row.
		 *
		 * @param {Object} row Row of the mapping.
		 */
		function tidy( row ) {
			if ( 'fixed' === row.kind || ! isEditable( row ) ) {
				return;
			}
			const sources = rowSources( row );
			// Keep the options while some field of the row is unknown to the catalog (not loaded, or not offered).
			const known = sources.every( function ( id ) {
				return null !== findSource( id );
			} );
			if ( row.options && known ) {
				const accepted = acceptedOptions( row );
				Object.keys( row.options ).forEach( function ( name ) {
					if ( -1 === accepted.indexOf( name ) || false === row.options[ name ] ) {
						delete row.options[ name ];
					}
				} );
			}
			if ( row.options && ! Object.keys( row.options ).length ) {
				delete row.options;
			}
			if ( Array.isArray( row.confirmed ) ) {
				row.confirmed = row.confirmed.filter( function ( id ) {
					return -1 !== sources.indexOf( id );
				} );
				if ( ! row.confirmed.length ) {
					delete row.confirmed;
				}
			}
		}

		/**
		 * Form of a row written in the hidden field.
		 *
		 * The interface keeps fields not chosen yet (an empty select after "Add fallback", a new exception) and
		 * empty fixed values while the administrator works; the mapping gets only what was chosen: empty
		 * places of a chain are left out, and so are empty chains and empty fixed values (the post types they
		 * cover then use the general chain or value, or give null). A row with nothing chosen keeps an empty
		 * general chain or no value, so the server reports it when the feed is saved.
		 *
		 * @param {Object} row Row of the mapping (state of the interface).
		 * @return {Object} Row to save.
		 */
		function serialize( row ) {
			if ( ! isEditable( row ) ) {
				return row;
			}
			const out = Object.assign( {}, row );
			if ( 'field' === row.kind ) {
				out.sources = {};
				scopesOf( row.sources ).forEach( function ( scope ) {
					const chain = ( row.sources[ scope ] || [] ).filter( function ( source ) {
						return '' !== source;
					} );
					if ( chain.length ) {
						out.sources[ scope ] = chain;
					}
				} );
				if ( ! Object.keys( out.sources ).length ) {
					out.sources = { '*': [] };
				}
			} else {
				// Fixed values and templates: empty texts are left out.
				const name = 'template' === row.kind ? 'templates' : 'values';
				out[ name ] = {};
				scopesOf( row[ name ] ).forEach( function ( scope ) {
					const value = row[ name ][ scope ];
					if ( undefined !== value && '' !== value ) {
						out[ name ][ scope ] = value;
					}
				} );
			}
			return out;
		}

		/**
		 * Message of an error of the mapping returned by the preview route.
		 *
		 * @param {Object} error Error (`row`, `field`, `code`).
		 * @return {string} Message.
		 */
		function errorMessage( error ) {
			const fields = {
				key: __( 'JSON key', 'kk-content-exporter' ),
				kind: __( 'Rule', 'kk-content-exporter' ),
				type: __( 'Output type', 'kk-content-exporter' ),
				sources: __( 'Fields', 'kk-content-exporter' ),
				values: __( 'Value', 'kk-content-exporter' ),
				templates: __( 'Template', 'kk-content-exporter' ),
				confirmed: __( 'Confirmation', 'kk-content-exporter' ),
				options: __( 'Options', 'kk-content-exporter' ),
			};
			const codes = {
				required: __( 'missing', 'kk-content-exporter' ),
				empty: __( 'empty: choose a field or enter a value', 'kk-content-exporter' ),
				format: __( 'not valid', 'kk-content-exporter' ),
				too_long: __( 'too long', 'kk-content-exporter' ),
				too_many: __( 'too many entries', 'kk-content-exporter' ),
				duplicate: __( 'already used by another row', 'kk-content-exporter' ),
				unknown: __( 'not known', 'kk-content-exporter' ),
				unknown_field: __( 'not expected', 'kk-content-exporter' ),
				not_allowed: __( 'not allowed for this rule', 'kk-content-exporter' ),
				sensitive: __( 'a field needs an explicit confirmation, asked when the feed is saved', 'kk-content-exporter' ),
			};
			const message = codes[ error.code ] || error.code;
			const label = fields[ error.field ] || error.field;

			if ( error.row < 0 ) {
				if ( 'json' === error.code || 'not_object' === error.code ) {
					return __( 'The mapping cannot be read.', 'kk-content-exporter' );
				}
				/* translators: 1: part of the mapping, 2: what is wrong with it. */
				return sprintf( __( 'Mapping, %1$s: %2$s', 'kk-content-exporter' ), label, message );
			}

			const row = rows[ error.row ];
			const key = row && row.data.key ? String( row.data.key ) : __( '(no key)', 'kk-content-exporter' );
			if ( '' === error.field ) {
				/* translators: 1: position of the row, 2: output key of the row, 3: what is wrong with it. */
				return sprintf( __( 'Row %1$d (%2$s): %3$s', 'kk-content-exporter' ), error.row + 1, key, message );
			}
			/* translators: 1: position of the row, 2: output key of the row, 3: setting of the row, 4: what is wrong with it. */
			return sprintf( __( 'Row %1$d (%2$s), %3$s: %4$s', 'kk-content-exporter' ), error.row + 1, key, label, message );
		}

		/**
		 * The preview panel: the exported object of one item per checked post type, computed by the server with
		 * the mapping of the box (saved or not), as a visitor would get it.
		 *
		 * It is computed again shortly after every change, and with the "Refresh" button. For every post type the
		 * latest modified item is used, unless another one is chosen from the latest published items. Only the
		 * errors are announced to screen readers; the JSON is shown, not read aloud.
		 *
		 * @return {Object} The panel: `node`, `schedule()`, `typesChanged()`, `setVisible()`.
		 */
		function createPreview() {
			const node = el( 'section', { className: 'kkce-preview', ariaLabelledby: 'kkce-preview-title', hidden: true } );
			const refresh = el( 'button', { type: 'button', className: 'button' }, __( 'Refresh', 'kk-content-exporter' ) );
			const spinner = el( 'span', { className: 'spinner' } );
			const state = el( 'span', { className: 'kkce-preview-state' } );
			const errors = el( 'div', { className: 'notice notice-error inline kkce-preview-errors', hidden: true } );
			const message = el( 'p', { className: 'kkce-preview-message', hidden: true } );
			const results = el( 'div', { className: 'kkce-preview-results' } );
			const toolbar = el( 'div', { className: 'kkce-preview-toolbar' } );
			// Chosen item per post type, latest items per post type, last result per post type.
			const selected = {};
			const recent = {};
			const shown = {};
			let previewTimer = null;
			let previewRequest = 0;

			toolbar.appendChild( refresh );
			toolbar.appendChild( spinner );
			toolbar.appendChild( state );
			node.appendChild( el( 'h3', { id: 'kkce-preview-title' }, __( 'Preview', 'kk-content-exporter' ) ) );
			node.appendChild( el( 'p', { className: 'description' }, __( 'The JSON of one item for each checked post type, computed with the rules of this box (saved or not) as a visitor would get it. Only published items are used.', 'kk-content-exporter' ) ) );
			node.appendChild( toolbar );
			node.appendChild( errors );
			node.appendChild( message );
			node.appendChild( results );

			/**
			 * Shows a message above the results, or hides it.
			 *
			 * @param {string} text Message, or an empty string.
			 */
			function showMessage( text ) {
				message.textContent = text;
				message.hidden = '' === text;
			}

			/**
			 * Shows the errors of the mapping, or hides them.
			 *
			 * @param {Object[]} list Errors returned by the route.
			 */
			function showErrors( list ) {
				errors.textContent = '';
				errors.hidden = ! list.length;
				if ( ! list.length ) {
					return;
				}
				errors.appendChild( el( 'p', {}, __( 'The preview is not up to date: correct these points of the mapping.', 'kk-content-exporter' ) ) );
				const items = el( 'ul' );
				list.forEach( function ( error ) {
					items.appendChild( el( 'li', {}, errorMessage( error ) ) );
				} );
				errors.appendChild( items );
			}

			/**
			 * Label of a post type.
			 *
			 * @param {string} postType Post type.
			 * @return {string} Label.
			 */
			function typeLabel( postType ) {
				return ( catalog.labels || {} )[ postType ] || postType;
			}

			/**
			 * Draws the item picker and the result of every checked post type.
			 */
			function draw() {
				const focused = document.activeElement && node.contains( document.activeElement ) ? document.activeElement.id : '';
				results.textContent = '';

				catalog.post_types.forEach( function ( postType ) {
					const block = el( 'div', { className: 'kkce-preview-type' } );
					const options = [ [ '', __( 'Latest modified item', 'kk-content-exporter' ) ] ];
					( recent[ postType ] || [] ).forEach( function ( item ) {
						const date = new Date( item.modified ).toLocaleDateString();
						const title = '' === item.title ? __( '(no title)', 'kk-content-exporter' ) : item.title;
						/* translators: 1: title of an item, 2: date of its last change, 3: ID of the item. */
						options.push( [ String( item.id ), sprintf( __( '%1$s (%2$s, ID %3$d)', 'kk-content-exporter' ), title, date, item.id ) ] );
					} );
					const picker = select( { id: 'kkce-preview-item-' + postType, className: 'kkce-preview-item', 'data-type': postType }, options, String( selected[ postType ] || '' ) );
					/* translators: %s: label of a post type. */
					block.appendChild( labelled( sprintf( __( 'Item of %s', 'kk-content-exporter' ), typeLabel( postType ) ), picker ) );

					const result = shown[ postType ];
					if ( 'empty' === result ) {
						/* translators: %s: label of a post type. */
						block.appendChild( el( 'p', {}, sprintf( __( 'No published item of %s.', 'kk-content-exporter' ), typeLabel( postType ) ) ) );
					} else if ( result ) {
						const title = '' === result.post.title ? __( '(no title)', 'kk-content-exporter' ) : result.post.title;
						/* translators: 1: title of the previewed item, 2: its ID. */
						block.appendChild( el( 'p', { className: 'kkce-preview-source' }, sprintf( __( '%1$s (ID %2$d)', 'kk-content-exporter' ), title, result.post.id ) ) );
						// Focusable, so the JSON can be scrolled with the keyboard when it is longer than the panel.
						const pre = el( 'pre', { className: 'kkce-preview-json', tabIndex: 0 } );
						pre.appendChild( el( 'code', {}, JSON.stringify( result.data, null, 2 ) ) );
						block.appendChild( pre );
					}
					results.appendChild( block );
				} );

				if ( focused ) {
					const target = document.getElementById( focused );
					if ( target ) {
						target.focus();
					}
				}
			}

			/**
			 * Forgets the results shown, so that stale JSON is never shown next to new rules.
			 */
			function clearResults() {
				Object.keys( shown ).forEach( function ( postType ) {
					delete shown[ postType ];
				} );
			}

			/**
			 * Computes the preview of every checked post type now.
			 */
			function run() {
				window.clearTimeout( previewTimer );
				const current = ++previewRequest;
				const postTypes = catalog.post_types.slice();

				// Without rows the panel is hidden (see setVisible()): nothing to compute.
				if ( ! rows.length ) {
					clearResults();
					return;
				}
				if ( ! postTypes.length ) {
					showErrors( [] );
					showMessage( __( 'Check at least one post type to see the preview.', 'kk-content-exporter' ) );
					clearResults();
					draw();
					state.textContent = '';
					return;
				}

				spinner.classList.add( 'is-active' );
				state.textContent = __( 'Updating…', 'kk-content-exporter' );

				// One request per post type, so that every type can use its own chosen item.
				Promise.all( postTypes.map( function ( postType ) {
					const body = { mapping: field.value, post_types: [ postType ] };
					if ( selected[ postType ] ) {
						body.post_id = selected[ postType ];
					}
					return wp.apiFetch( { path: data.previewPath, method: 'POST', data: body } ).then( function ( response ) {
						return { postType: postType, response: response };
					}, function ( error ) {
						return { postType: postType, error: error || {} };
					} );
				} ) ).then( function ( answers ) {
					if ( current !== previewRequest ) {
						return;
					}
					spinner.classList.remove( 'is-active' );

					const invalid = answers.find( function ( answer ) {
						return answer.error && 'kkce_invalid_mapping' === answer.error.code;
					} );
					if ( invalid ) {
						const list = ( invalid.error.data && invalid.error.data.errors ) || [];
						clearResults();
						draw();
						showMessage( '' );
						showErrors( list );
						state.textContent = '';
						/* translators: %d: number of errors in the mapping. */
						announce( sprintf( _n( 'The preview is not up to date: the mapping has %d error.', 'The preview is not up to date: the mapping has %d errors.', list.length, 'kk-content-exporter' ), list.length ) );
						return;
					}

					let retry = false;
					let failed = '';
					answers.forEach( function ( answer ) {
						if ( answer.response ) {
							shown[ answer.postType ] = answer.response.items.length ? answer.response.items[ 0 ] : 'empty';
						} else if ( 'kkce_item_not_found' === answer.error.code && selected[ answer.postType ] ) {
							// The chosen item is no longer published: go back to the latest one.
							delete selected[ answer.postType ];
							retry = true;
						} else {
							delete shown[ answer.postType ];
							failed = answer.error.message || __( 'The preview could not be computed.', 'kk-content-exporter' );
						}
					} );

					showErrors( [] );
					showMessage( failed );
					draw();
					/* translators: %s: time of the last update of the preview. */
					state.textContent = sprintf( __( 'Updated at %s', 'kk-content-exporter' ), new Date().toLocaleTimeString() );
					if ( failed ) {
						announce( failed );
					}
					if ( retry ) {
						run();
					}
				} );
			}

			/**
			 * Computes the preview again shortly, once the changes stop.
			 */
			function schedule() {
				window.clearTimeout( previewTimer );
				previewTimer = window.setTimeout( run, PREVIEW_DELAY );
			}

			/**
			 * Loads the latest items of the checked post types not loaded yet, then computes the preview.
			 */
			function typesChanged() {
				catalog.post_types.forEach( function ( postType ) {
					if ( recent[ postType ] ) {
						return;
					}
					recent[ postType ] = [];
					wp.apiFetch( { path: data.itemsPath + '?post_type=' + encodeURIComponent( postType ) } ).then( function ( response ) {
						recent[ postType ] = response.items || [];
						draw();
					}, function () {
						// The picker keeps only "Latest modified item": the preview still works.
					} );
				} );
				draw();
				schedule();
			}

			refresh.addEventListener( 'click', run );
			results.addEventListener( 'change', function ( event ) {
				const picker = event.target;
				if ( ! picker.classList.contains( 'kkce-preview-item' ) ) {
					return;
				}
				// Choosing the item to preview changes nothing of the feed: it is not an unsaved change of the page.
				event.stopPropagation();
				const postType = picker.getAttribute( 'data-type' );
				if ( '' === picker.value ) {
					delete selected[ postType ];
				} else {
					selected[ postType ] = parseInt( picker.value, 10 );
				}
				schedule();
			} );

			/**
			 * Shows the panel only when the mapping has at least one row; without it the rules use the whole width.
			 *
			 * @param {boolean} visible Whether the panel is shown.
			 */
			function setVisible( visible ) {
				if ( node.hidden === ! visible ) {
					return;
				}
				node.hidden = ! visible;
				layout.classList.toggle( 'kkce-has-preview', visible );
				if ( visible ) {
					schedule();
				}
			}

			return { node: node, schedule: schedule, typesChanged: typesChanged, setVisible: setVisible };
		}

		/**
		 * Writes the mapping in the hidden field.
		 *
		 * @param {boolean} byUser Whether the change comes from the administrator (it then counts as an
		 *                         unsaved change of the page).
		 */
		function commit( byUser ) {
			rows.forEach( function ( row ) {
				tidy( row.data );
			} );
			field.value = JSON.stringify( {
				version: mapping.version || 1,
				rows: rows.map( function ( row ) {
					return serialize( row.data );
				} ),
			} );
			if ( byUser ) {
				touched = true;
				field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
			preview.schedule();
		}

		/**
		 * Label of a post type, or of the general scope.
		 *
		 * @param {string} scope Post type, or `*`.
		 * @return {string} Label.
		 */
		function scopeLabel( scope ) {
			if ( '*' === scope ) {
				return __( 'For all post types', 'kk-content-exporter' );
			}
			if ( -1 === catalog.post_types.indexOf( scope ) ) {
				/* translators: %s: key of a post type that is not checked in the settings of the feed. */
				return sprintf( __( 'For %s (not checked)', 'kk-content-exporter' ), scope );
			}
			/* translators: %s: label of a post type. */
			return sprintf( __( 'For %s', 'kk-content-exporter' ), ( catalog.labels || {} )[ scope ] || scope );
		}

		/**
		 * Select element of a field of a chain, grouped by post type.
		 *
		 * @param {string} current Selected source.
		 * @param {Object} attrs   Properties of the select.
		 * @return {HTMLSelectElement} The select.
		 */
		function sourceSelect( current, attrs ) {
			const node = el( 'select', attrs );
			node.appendChild( el( 'option', { value: '' }, __( '— Choose a field —', 'kk-content-exporter' ) ) );

			if ( '' !== current && null === findSource( current ) ) {
				/* translators: %s: identifier of a field that the post types of the feed do not offer. */
				node.appendChild( el( 'option', { value: current, selected: true }, sprintf( __( '%s (not offered by the checked post types)', 'kk-content-exporter' ), current ) ) );
			}

			const groups = [ [ '', __( 'Common fields', 'kk-content-exporter' ) ] ];
			catalog.post_types.forEach( function ( postType ) {
				/* translators: %s: label of a post type. */
				groups.push( [ postType, sprintf( __( 'Fields of %s', 'kk-content-exporter' ), ( catalog.labels || {} )[ postType ] || postType ) ] );
			} );

			groups.forEach( function ( group ) {
				const sources = catalog.sources.filter( function ( source ) {
					return source.post_type === group[ 0 ];
				} );
				if ( ! sources.length ) {
					return;
				}
				const optgroup = el( 'optgroup', { label: group[ 1 ] } );
				sources.forEach( function ( source ) {
					optgroup.appendChild( el( 'option', { value: source.id, selected: source.id === current }, source.label ) );
				} );
				node.appendChild( optgroup );
			} );

			return node;
		}

		/**
		 * Labelled field of a row.
		 *
		 * @param {string}      label   Label.
		 * @param {HTMLElement} control Control.
		 * @return {HTMLElement} Wrapper.
		 */
		function labelled( label, control ) {
			const wrapper = el( 'div', { className: 'kkce-mapping-field' } );
			wrapper.appendChild( el( 'label', { htmlFor: control.id }, label ) );
			wrapper.appendChild( control );
			return wrapper;
		}

		/**
		 * Button of a row, carrying what it acts on.
		 *
		 * @param {Object} row     Row.
		 * @param {string} action  Action.
		 * @param {string} text    Visible text.
		 * @param {Object} extra   Other properties (id, ariaLabel, disabled, data-scope, data-index, className).
		 * @return {HTMLButtonElement} The button.
		 */
		function button( row, action, text, extra ) {
			return el( 'button', Object.assign( { type: 'button', className: 'button', 'data-uid': String( row.uid ), 'data-action': action }, extra ), text );
		}

		/**
		 * Text of the legend of a row.
		 *
		 * @param {number} index Position of the row.
		 * @param {Object} row   Row.
		 * @return {string} Legend.
		 */
		function legendText( index, row ) {
			const key = String( row.data.key || '' );
			if ( '' === key ) {
				/* translators: %d: position of the row. */
				return sprintf( __( 'Row %d (no key)', 'kk-content-exporter' ), index + 1 );
			}
			/* translators: 1: position of the row, 2: output key of the row. */
			return sprintf( __( 'Row %1$d: %2$s', 'kk-content-exporter' ), index + 1, key );
		}

		/**
		 * Chain of fields of a scope of a `field` row.
		 *
		 * @param {Object} row   Row.
		 * @param {string} scope Scope.
		 * @return {HTMLElement} The group.
		 */
		function renderChain( row, scope ) {
			const prefix = 'kkce-mapping-' + row.uid + '-' + scopeId( scope );
			const chain = row.data.sources[ scope ];
			const group = el( 'fieldset', { className: 'kkce-mapping-scope' } );
			const label = scopeLabel( scope );
			group.appendChild( el( 'legend', {}, label ) );

			const items = el( 'ol', { className: 'kkce-mapping-chain' } );
			// An empty chain still shows one select, so a field can be chosen at once.
			const shown = chain.length ? chain : [ '' ];
			shown.forEach( function ( source, index ) {
				const item = el( 'li' );
				const id = prefix + '-' + index;
				const control = sourceSelect( String( source || '' ), { id: id + '-source', className: 'kkce-mapping-source', 'data-uid': String( row.uid ), 'data-scope': scope, 'data-index': String( index ) } );
				/* translators: %d: position of a fallback field in its chain (2 for the first fallback). */
				item.appendChild( labelled( 0 === index ? LABEL_FIELD : sprintf( __( 'Otherwise, field %d', 'kk-content-exporter' ), index + 1 ), control ) );

				if ( chain.length > 1 ) {
					const actions = el( 'span', { className: 'kkce-mapping-chain-actions' } );
					/* translators: 1: position of the field in its chain, 2: name of the chain, for example "For all post types". */
					actions.appendChild( button( row, 'chain-up', LABEL_UP, { id: id + '-up', disabled: 0 === index, ariaLabel: sprintf( __( 'Move field %1$d up (%2$s)', 'kk-content-exporter' ), index + 1, label ), 'data-scope': scope, 'data-index': String( index ) } ) );
					/* translators: 1: position of the field in its chain, 2: name of the chain, for example "For all post types". */
					actions.appendChild( button( row, 'chain-down', LABEL_DOWN, { id: id + '-down', disabled: index === chain.length - 1, ariaLabel: sprintf( __( 'Move field %1$d down (%2$s)', 'kk-content-exporter' ), index + 1, label ), 'data-scope': scope, 'data-index': String( index ) } ) );
					/* translators: 1: position of the field in its chain, 2: name of the chain, for example "For all post types". */
					actions.appendChild( button( row, 'chain-remove', LABEL_REMOVE, { id: id + '-remove', className: 'button-link button-link-delete', ariaLabel: sprintf( __( 'Remove field %1$d (%2$s)', 'kk-content-exporter' ), index + 1, label ), 'data-scope': scope, 'data-index': String( index ) } ) );
					item.appendChild( actions );
				}
				items.appendChild( item );
			} );
			group.appendChild( items );

			const tools = el( 'div', { className: 'kkce-mapping-scope-actions' } );
			// A fallback can be added once the last field of the chain is chosen.
			/* translators: %s: name of the chain, for example "For all post types". */
			tools.appendChild( button( row, 'chain-add', LABEL_ADD_FALLBACK, { id: prefix + '-add', disabled: chain.length >= data.maxChain || ! chain.length || '' === chain[ chain.length - 1 ], ariaLabel: sprintf( __( 'Add a fallback field (%s)', 'kk-content-exporter' ), label ), 'data-scope': scope } ) );
			if ( '*' !== scope ) {
				/* translators: %s: post types the exception applies to, for example "For Posts". */
				tools.appendChild( button( row, 'scope-remove', LABEL_REMOVE_EXCEPTION, { id: prefix + '-scope-remove', className: 'button-link button-link-delete', ariaLabel: sprintf( __( 'Remove the exception: %s', 'kk-content-exporter' ), label ), 'data-scope': scope } ) );
			}
			group.appendChild( tools );

			return group;
		}

		/**
		 * Value of a scope of a `fixed` row.
		 *
		 * @param {Object} row   Row.
		 * @param {string} scope Scope.
		 * @return {HTMLElement} The group.
		 */
		function renderValue( row, scope ) {
			const prefix = 'kkce-mapping-' + row.uid + '-' + scopeId( scope );
			const value = row.data.values[ scope ];
			const label = scopeLabel( scope );
			const group = el( 'div', { className: 'kkce-mapping-scope kkce-mapping-value' } );
			const input = el( 'input', {
				type: 'text',
				id: prefix + '-value',
				className: 'regular-text kkce-mapping-fixed',
				value: null === value || undefined === value ? '' : String( value ),
				maxLength: data.valueMaxLength,
				'data-uid': String( row.uid ),
				'data-scope': scope,
			} );
			/* translators: %s: name of the scope, for example "For all post types". */
			group.appendChild( labelled( sprintf( __( 'Value (%s)', 'kk-content-exporter' ), label ), input ) );
			if ( '*' !== scope ) {
				/* translators: %s: post types the exception applies to, for example "For Posts". */
				group.appendChild( button( row, 'scope-remove', LABEL_REMOVE_EXCEPTION, { id: prefix + '-scope-remove', className: 'button-link button-link-delete', ariaLabel: sprintf( __( 'Remove the exception: %s', 'kk-content-exporter' ), label ), 'data-scope': scope } ) );
			}
			return group;
		}

		/**
		 * Template of a scope of a `template` row, with the control that inserts a field.
		 *
		 * @param {Object} row   Row.
		 * @param {string} scope Scope.
		 * @return {HTMLElement} The group.
		 */
		function renderTemplate( row, scope ) {
			const prefix = 'kkce-mapping-' + row.uid + '-' + scopeId( scope );
			const label = scopeLabel( scope );
			const group = el( 'div', { className: 'kkce-mapping-scope kkce-mapping-template-scope' } );
			const area = el( 'textarea', {
				id: prefix + '-template',
				className: 'large-text code kkce-mapping-template',
				rows: 2,
				value: String( row.data.templates[ scope ] || '' ),
				maxLength: data.templateMaxLength,
				spellcheck: false,
				ariaDescribedby: 'kkce-mapping-' + row.uid + '-template-help',
				'data-uid': String( row.uid ),
				'data-scope': scope,
			} );
			/* translators: %s: name of the scope, for example "For all post types". */
			group.appendChild( labelled( sprintf( __( 'Template (%s)', 'kk-content-exporter' ), label ), area ) );

			const insert = el( 'div', { className: 'kkce-mapping-insert' } );
			// No data-uid: choosing the field to insert changes nothing until the button is pressed.
			insert.appendChild( labelled( __( 'Field to insert', 'kk-content-exporter' ), sourceSelect( '', { id: prefix + '-insert', className: 'kkce-mapping-insert-source' } ) ) );
			/* translators: %s: name of the scope, for example "For all post types". */
			insert.appendChild( button( row, 'insert', LABEL_INSERT_FIELD, { id: prefix + '-insert-button', ariaLabel: sprintf( __( 'Insert the field in the template (%s)', 'kk-content-exporter' ), label ), 'data-scope': scope } ) );
			if ( '*' !== scope ) {
				/* translators: %s: post types the exception applies to, for example "For Posts". */
				insert.appendChild( button( row, 'scope-remove', LABEL_REMOVE_EXCEPTION, { id: prefix + '-scope-remove', className: 'button-link button-link-delete', ariaLabel: sprintf( __( 'Remove the exception: %s', 'kk-content-exporter' ), label ), 'data-scope': scope } ) );
			}
			group.appendChild( insert );
			return group;
		}

		/**
		 * Control that adds an exception for a checked post type, when some is left.
		 *
		 * @param {Object} row Row.
		 * @return {HTMLElement|null} The control.
		 */
		function renderExceptionPicker( row ) {
			const map = scopeMap( row.data );
			const free = catalog.post_types.filter( function ( postType ) {
				return ! Object.prototype.hasOwnProperty.call( map, postType );
			} );
			if ( ! free.length ) {
				return null;
			}
			const prefix = 'kkce-mapping-' + row.uid;
			const wrapper = el( 'div', { className: 'kkce-mapping-exception' } );
			const picker = select( { id: prefix + '-exception' }, free.map( function ( postType ) {
				return [ postType, ( catalog.labels || {} )[ postType ] || postType ];
			} ), free[ 0 ] );
			wrapper.appendChild( labelled( __( 'Different for', 'kk-content-exporter' ), picker ) );
			wrapper.appendChild( button( row, 'scope-add', __( 'Add exception', 'kk-content-exporter' ), { id: prefix + '-exception-add' } ) );
			return wrapper;
		}

		/**
		 * Options of the fields of a `field` row, with their warnings, shown only when a field accepts them.
		 *
		 * @param {Object} row Row.
		 * @return {HTMLElement|null} The options.
		 */
		function renderOptions( row ) {
			// Only field rows have options; the warnings below are for field and template rows.
			const accepted = 'field' === row.data.kind ? acceptedOptions( row.data ) : [];
			const options = row.data.options || {};
			const prefix = 'kkce-mapping-' + row.uid;
			const wrapper = el( 'div', { className: 'kkce-mapping-options' } );

			if ( -1 !== accepted.indexOf( 'raw' ) ) {
				const raw = el( 'input', { type: 'checkbox', id: prefix + '-raw', checked: true === options.raw, 'data-uid': String( row.uid ), 'data-option': 'raw', ariaDescribedby: prefix + '-raw-help' } );
				const label = el( 'label', { htmlFor: raw.id } );
				label.appendChild( raw );
				label.appendChild( document.createTextNode( ' ' + __( 'Raw content', 'kk-content-exporter' ) ) );
				wrapper.appendChild( label );
				wrapper.appendChild( el( 'p', { id: prefix + '-raw-help', className: 'description' }, __( 'The text as stored in the database: blocks are not turned into HTML and links stay as they are.', 'kk-content-exporter' ) ) );
			}

			if ( -1 !== accepted.indexOf( 'shortcodes' ) ) {
				const shortcodes = el( 'input', { type: 'checkbox', id: prefix + '-shortcodes', checked: true === options.shortcodes, disabled: true === options.raw, 'data-uid': String( row.uid ), 'data-option': 'shortcodes', ariaDescribedby: prefix + '-shortcodes-help' } );
				const label = el( 'label', { htmlFor: shortcodes.id } );
				label.appendChild( shortcodes );
				label.appendChild( document.createTextNode( ' ' + __( 'Run the shortcodes', 'kk-content-exporter' ) ) );
				wrapper.appendChild( label );
				wrapper.appendChild( el( 'p', { id: prefix + '-shortcodes-help', className: 'description kkce-mapping-warning' }, true === options.raw
					? __( 'Not used with the raw content.', 'kk-content-exporter' )
					: __( 'Off: shortcodes are removed. On: the shortcodes of other plugins run while the feed is read, as for a visitor; they can add content you do not expect or slow the feed down. Turn it on only for shortcodes you know.', 'kk-content-exporter' ) ) );
			}

			rowSources( row.data ).forEach( function ( id ) {
				const source = findSource( id );
				if ( ! source || ! source.needs_confirmation ) {
					return;
				}
				if ( 'core.author.url' === source.id ) {
					wrapper.appendChild( el( 'p', { className: 'kkce-mapping-warning' }, __( 'The author archive URL usually contains the login name of the author: it is exported only after an explicit confirmation, asked when the feed is saved.', 'kk-content-exporter' ) ) );
					return;
				}
				/* translators: %s: label of a field whose name looks sensitive or personal. */
				wrapper.appendChild( el( 'p', { className: 'kkce-mapping-warning' }, sprintf( __( '%s looks like sensitive or personal data: in a public feed anyone can read it. It is exported only after an explicit confirmation, asked when the feed is saved.', 'kk-content-exporter' ), source.label ) ) );
			} );

			return wrapper.childNodes.length ? wrapper : null;
		}

		/**
		 * Builds the element of a row.
		 *
		 * @param {Object} row   Row.
		 * @param {number} index Position.
		 * @return {HTMLElement} List item.
		 */
		function renderRow( row, index ) {
			const prefix = 'kkce-mapping-' + row.uid;
			const item = el( 'li', { className: 'kkce-mapping-row' } );
			const fieldset = el( 'fieldset' );
			const fields = el( 'div', { className: 'kkce-mapping-fields' } );
			const error = el( 'p', { id: prefix + '-key-error', className: 'kkce-mapping-error', hidden: true } );
			const key = el( 'input', {
				type: 'text',
				id: prefix + '-key',
				className: 'kkce-mapping-key',
				value: String( row.data.key || '' ),
				maxLength: data.keyMaxLength,
				autocomplete: 'off',
				spellcheck: false,
				ariaDescribedby: error.id,
				'data-uid': String( row.uid ),
			} );
			const editable = isEditable( row.data );

			fieldset.appendChild( el( 'legend', {}, legendText( index, row ) ) );
			fields.appendChild( labelled( __( 'JSON key', 'kk-content-exporter' ), key ) );
			if ( editable ) {
				fields.appendChild( labelled( __( 'Rule', 'kk-content-exporter' ), select( { id: prefix + '-kind', className: 'kkce-mapping-kind', 'data-uid': String( row.uid ) }, KINDS, row.data.kind ) ) );
			}
			// A template always gives a text: plain or HTML.
			const isTemplate = 'template' === row.data.kind;
			const types = isTemplate ? TYPES.filter( function ( type ) {
				return 'string' === type[ 0 ] || 'html' === type[ 0 ];
			} ) : TYPES;
			const type = isTemplate ? ( 'html' === row.data.type ? 'html' : 'string' ) : ( row.data.type || 'auto' );
			fields.appendChild( labelled( __( 'Output type', 'kk-content-exporter' ), select( { id: prefix + '-type', className: 'kkce-mapping-type', 'data-uid': String( row.uid ) }, types, type ) ) );
			fieldset.appendChild( fields );
			fieldset.appendChild( error );

			if ( ! editable ) {
				fieldset.appendChild( el( 'p', { className: 'kkce-mapping-kept' }, __( 'This rule cannot be edited here yet and is kept unchanged.', 'kk-content-exporter' ) ) );
			} else if ( 'field' === row.data.kind ) {
				if ( ! row.data.sources[ '*' ] ) {
					row.data.sources = Object.assign( { '*': [] }, row.data.sources );
				}
				scopesOf( row.data.sources ).forEach( function ( scope ) {
					fieldset.appendChild( renderChain( row, scope ) );
				} );
				const picker = renderExceptionPicker( row );
				if ( picker ) {
					fieldset.appendChild( picker );
				}
				const options = renderOptions( row );
				if ( options ) {
					fieldset.appendChild( options );
				}
			} else if ( 'template' === row.data.kind ) {
				if ( ! Object.prototype.hasOwnProperty.call( row.data.templates, '*' ) ) {
					row.data.templates = Object.assign( { '*': '' }, row.data.templates );
				}
				fieldset.appendChild( el( 'p', { id: prefix + '-template-help', className: 'description' }, __( 'Write the text and put the fields between braces, for example {core.title}. {a|b|"text"} uses the first one with a value; write {{ and }} for a brace. "Insert field" writes a field where the cursor is.', 'kk-content-exporter' ) ) );
				scopesOf( row.data.templates ).forEach( function ( scope ) {
					fieldset.appendChild( renderTemplate( row, scope ) );
				} );
				const picker = renderExceptionPicker( row );
				if ( picker ) {
					fieldset.appendChild( picker );
				}
				const warnings = renderOptions( row );
				if ( warnings ) {
					fieldset.appendChild( warnings );
				}
			} else {
				if ( ! Object.prototype.hasOwnProperty.call( row.data.values, '*' ) ) {
					row.data.values = Object.assign( { '*': '' }, row.data.values );
				}
				scopesOf( row.data.values ).forEach( function ( scope ) {
					fieldset.appendChild( renderValue( row, scope ) );
				} );
				const picker = renderExceptionPicker( row );
				if ( picker ) {
					fieldset.appendChild( picker );
				}
			}

			const actions = el( 'div', { className: 'kkce-mapping-actions' } );
			/* translators: %d: position of the row. */
			actions.appendChild( button( row, 'up', LABEL_UP, { id: prefix + '-up', disabled: 0 === index, ariaLabel: sprintf( __( 'Move row %d up', 'kk-content-exporter' ), index + 1 ) } ) );
			/* translators: %d: position of the row. */
			actions.appendChild( button( row, 'down', LABEL_DOWN, { id: prefix + '-down', disabled: index === rows.length - 1, ariaLabel: sprintf( __( 'Move row %d down', 'kk-content-exporter' ), index + 1 ) } ) );
			/* translators: %d: position of the row. */
			actions.appendChild( button( row, 'remove', LABEL_REMOVE, { id: prefix + '-remove', className: 'button-link button-link-delete', ariaLabel: sprintf( __( 'Remove row %d', 'kk-content-exporter' ), index + 1 ) } ) );
			fieldset.appendChild( actions );
			item.appendChild( fieldset );

			return item;
		}

		/**
		 * Shows the errors of the keys of every row.
		 */
		function checkKeys() {
			rows.forEach( function ( row, index ) {
				const others = rows.filter( function ( other, position ) {
					return position !== index;
				} ).map( function ( other ) {
					return String( other.data.key || '' );
				} );
				const message = keyError( String( row.data.key || '' ), others, data.keyMaxLength, keyPattern );
				const input = document.getElementById( 'kkce-mapping-' + row.uid + '-key' );
				const error = document.getElementById( 'kkce-mapping-' + row.uid + '-key-error' );
				if ( ! input || ! error ) {
					return;
				}
				error.textContent = message;
				error.hidden = '' === message;
				if ( '' === message ) {
					input.removeAttribute( 'aria-invalid' );
				} else {
					input.setAttribute( 'aria-invalid', 'true' );
				}
			} );
		}

		/**
		 * Draws every row again and moves the focus to an element, by its id.
		 *
		 * @param {string} focusId Id of the element to focus, if any.
		 */
		function render( focusId ) {
			list.textContent = '';
			rows.forEach( function ( row, index ) {
				list.appendChild( renderRow( row, index ) );
			} );
			empty.hidden = rows.length > 0;
			addButton.disabled = rows.length >= data.maxRows;
			/* translators: %d: maximum number of rows of a mapping. */
			limit.textContent = sprintf( __( 'A mapping can have at most %d rows.', 'kk-content-exporter' ), data.maxRows );
			limit.hidden = rows.length < data.maxRows;
			preview.setVisible( rows.length > 0 );
			checkKeys();

			if ( focusId ) {
				const target = document.getElementById( focusId );
				if ( target && ! target.disabled ) {
					target.focus();
				}
			}
		}

		/**
		 * Position of a row by its uid.
		 *
		 * @param {string} uid Uid read from a data attribute.
		 * @return {number} Position, -1 when not found.
		 */
		function indexOf( uid ) {
			return rows.findIndex( function ( row ) {
				return String( row.uid ) === uid;
			} );
		}

		/**
		 * Proposes the key of a field while the key of the row is empty or still the one proposed before.
		 *
		 * @param {Object} row Row.
		 * @param {string} id  Source identifier.
		 */
		function proposeKey( row, id ) {
			const source = findSource( id );
			const key = String( row.data.key || '' );
			if ( source && source.suggested_key && ( '' === key || key === row.autoKey ) ) {
				row.data.key = source.suggested_key;
				row.autoKey = source.suggested_key;
			}
		}

		/**
		 * Uses the rows proposed by the catalog while the administrator has not touched an empty mapping.
		 */
		function proposeRows() {
			if ( touched ) {
				return;
			}
			rows = ( catalog.suggested_rows || [] ).map( function ( row ) {
				return { uid: nextId++, data: JSON.parse( JSON.stringify( row ) ), autoKey: row.key };
			} );
		}

		addButton.addEventListener( 'click', function () {
			if ( rows.length >= data.maxRows ) {
				return;
			}
			const row = { uid: nextId++, data: { key: '', kind: 'field', type: 'auto', sources: { '*': [] } }, autoKey: '' };
			rows.push( row );
			commit( true );
			render( 'kkce-mapping-' + row.uid + '-key' );
			/* translators: %d: position of the new row. */
			announce( sprintf( __( 'Row %d added.', 'kk-content-exporter' ), rows.length ) );
		} );

		/**
		 * Actions on a whole row: move and remove.
		 *
		 * @param {string} action Action.
		 * @param {number} index  Position of the row.
		 */
		function rowAction( action, index ) {
			const row = rows[ index ];

			if ( 'remove' === action ) {
				rows.splice( index, 1 );
				commit( true );
				const next = rows[ index ] || rows[ index - 1 ];
				render( next ? 'kkce-mapping-' + next.uid + '-key' : '' );
				if ( ! next ) {
					addButton.focus();
				}
				/* translators: %d: position of the removed row. */
				announce( sprintf( __( 'Row %d removed.', 'kk-content-exporter' ), index + 1 ) );
				return;
			}

			const up = 'up' === action;
			const target = up ? index - 1 : index + 1;
			if ( target < 0 || target >= rows.length ) {
				return;
			}
			rows.splice( index, 1 );
			rows.splice( target, 0, row );
			commit( true );
			// Keep the focus on the same button; at the first or last position it is disabled, so use the other one.
			const atEdge = up ? 0 === target : rows.length - 1 === target;
			render( 'kkce-mapping-' + row.uid + '-' + ( up !== atEdge ? 'up' : 'down' ) );
			/* translators: %d: new position of the moved row. */
			announce( sprintf( __( 'Row moved to position %d.', 'kk-content-exporter' ), target + 1 ) );
		}

		/**
		 * Actions on the chains of fields and on the exceptions of a row.
		 *
		 * @param {Object} row    Row.
		 * @param {string} action Action.
		 * @param {string} scope  Scope of the button, if any.
		 * @param {number} index  Position in the chain, if any.
		 */
		function scopeAction( row, action, scope, index ) {
			const prefix = 'kkce-mapping-' + row.uid;
			const isField = 'field' === row.data.kind;
			const isTemplate = 'template' === row.data.kind;
			const map = scopeMap( row.data );

			if ( 'insert' === action ) {
				const area = document.getElementById( prefix + '-' + scopeId( scope ) + '-template' );
				const picker = document.getElementById( prefix + '-' + scopeId( scope ) + '-insert' );
				if ( ! area || ! picker || '' === picker.value ) {
					return;
				}
				// The placeholder goes where the cursor is (or replaces the selected text).
				const placeholder = '{' + picker.value + '}';
				const start = area.selectionStart;
				const end = area.selectionEnd;
				area.value = area.value.slice( 0, start ) + placeholder + area.value.slice( end );
				map[ scope ] = area.value;
				if ( '' === String( row.data.key || '' ) ) {
					proposeKey( row, picker.value );
				}
				commit( true );
				render( area.id );
				const again = document.getElementById( area.id );
				if ( again ) {
					again.setSelectionRange( start + placeholder.length, start + placeholder.length );
				}
				/* translators: %s: placeholder inserted in a template, for example {core.title}. */
				announce( sprintf( __( 'Inserted %s.', 'kk-content-exporter' ), placeholder ) );
				return;
			}

			if ( 'scope-add' === action ) {
				const picker = document.getElementById( prefix + '-exception' );
				const postType = picker ? picker.value : '';
				if ( ! postType || Object.prototype.hasOwnProperty.call( map, postType ) ) {
					return;
				}
				map[ postType ] = isField ? [] : '';
				commit( true );
				render( prefix + '-' + scopeId( postType ) + ( isField ? '-0-source' : ( isTemplate ? '-template' : '-value' ) ) );
				/* translators: %s: name of the exception, for example "For Posts". */
				announce( sprintf( __( 'Exception added: %s.', 'kk-content-exporter' ), scopeLabel( postType ) ) );
				return;
			}

			if ( 'scope-remove' === action ) {
				const label = scopeLabel( scope );
				delete map[ scope ];
				commit( true );
				render( '' );
				// The post type is free again, so the "Different for" picker exists: it takes the focus.
				const target = document.getElementById( prefix + '-exception' ) || document.getElementById( prefix + '-key' );
				if ( target ) {
					target.focus();
				}
				/* translators: %s: name of the exception, for example "For Posts". */
				announce( sprintf( __( 'Exception removed: %s.', 'kk-content-exporter' ), label ) );
				return;
			}

			const chain = row.data.sources[ scope ];
			const base = prefix + '-' + scopeId( scope );

			if ( 'chain-add' === action ) {
				if ( chain.length >= data.maxChain ) {
					return;
				}
				chain.push( '' );
				commit( true );
				render( base + '-' + ( chain.length - 1 ) + '-source' );
				announce( __( 'Fallback field added.', 'kk-content-exporter' ) );
				return;
			}

			if ( 'chain-remove' === action ) {
				chain.splice( index, 1 );
				commit( true );
				render( base + '-' + Math.min( index, Math.max( chain.length - 1, 0 ) ) + '-source' );
				announce( __( 'Field removed from the chain.', 'kk-content-exporter' ) );
				return;
			}

			const up = 'chain-up' === action;
			const target = up ? index - 1 : index + 1;
			if ( target < 0 || target >= chain.length ) {
				return;
			}
			const moved = chain.splice( index, 1 )[ 0 ];
			chain.splice( target, 0, moved );
			commit( true );
			const atEdge = up ? 0 === target : chain.length - 1 === target;
			render( base + '-' + target + '-' + ( up !== atEdge ? 'up' : 'down' ) );
			/* translators: %d: new position of the moved field in its chain. */
			announce( sprintf( __( 'Field moved to position %d of the chain.', 'kk-content-exporter' ), target + 1 ) );
		}

		list.addEventListener( 'click', function ( event ) {
			const control = event.target.closest( 'button[data-action]' );
			if ( ! control ) {
				return;
			}
			const index = indexOf( control.getAttribute( 'data-uid' ) || '' );
			if ( -1 === index ) {
				return;
			}
			const action = control.getAttribute( 'data-action' );
			if ( 'up' === action || 'down' === action || 'remove' === action ) {
				rowAction( action, index );
				return;
			}
			scopeAction( rows[ index ], action, control.getAttribute( 'data-scope' ) || '', parseInt( control.getAttribute( 'data-index' ) || '0', 10 ) );
		} );

		list.addEventListener( 'input', function ( event ) {
			const input = event.target;
			const index = indexOf( input.getAttribute( 'data-uid' ) || '' );
			if ( -1 === index ) {
				return;
			}
			const row = rows[ index ];

			if ( input.classList.contains( 'kkce-mapping-key' ) ) {
				row.data.key = input.value.trim();
				row.autoKey = null;
				commit( true );
				// Update the legend and the errors without drawing the row again, so the caret stays where it is.
				input.closest( 'li' ).querySelector( 'legend' ).textContent = legendText( index, row );
				checkKeys();
				return;
			}

			if ( input.classList.contains( 'kkce-mapping-fixed' ) ) {
				row.data.values[ input.getAttribute( 'data-scope' ) ] = input.value;
				commit( true );
			}

			if ( input.classList.contains( 'kkce-mapping-template' ) ) {
				row.data.templates[ input.getAttribute( 'data-scope' ) ] = input.value;
				commit( true );
			}
		} );

		list.addEventListener( 'change', function ( event ) {
			const control = event.target;
			const index = indexOf( control.getAttribute( 'data-uid' ) || '' );
			if ( -1 === index ) {
				return;
			}
			const row = rows[ index ];

			if ( control.classList.contains( 'kkce-mapping-key' ) ) {
				// Announced when the field is left, not at every key: the message is also linked to the field.
				const error = document.getElementById( 'kkce-mapping-' + row.uid + '-key-error' );
				if ( error && ! error.hidden && '' !== error.textContent ) {
					/* translators: 1: position of the row, 2: error message about its key. */
					announce( sprintf( __( 'Error in row %1$d: %2$s', 'kk-content-exporter' ), index + 1, error.textContent ) );
				}
				return;
			}

			if ( control.classList.contains( 'kkce-mapping-fixed' ) || control.classList.contains( 'kkce-mapping-template' ) ) {
				return;
			}

			if ( control.classList.contains( 'kkce-mapping-type' ) ) {
				row.data.type = control.value;
			} else if ( control.classList.contains( 'kkce-mapping-kind' ) ) {
				if ( control.value === row.data.kind ) {
					return;
				}
				// A row has one rule: only the chosen one is saved. The settings of the other rule stay in memory
				// until the page is left, so going back (a select can change at every arrow key) loses nothing.
				const previous = row.data;
				const blank = {
					field: { kind: 'field', sources: { '*': [] } },
					fixed: { kind: 'fixed', values: { '*': '' } },
					template: { kind: 'template', templates: { '*': '' } },
				};
				const next = ( row.stash && row.stash[ control.value ] ) || blank[ control.value ];
				row.stash = Object.assign( {}, row.stash, { [ previous.kind ]: previous } );
				// The key and the output type belong to the row, not to the rule: they follow the change.
				next.key = previous.key;
				next.type = previous.type || 'auto';
				if ( 'template' === next.kind && 'html' !== next.type ) {
					// A template gives a text: plain unless HTML was chosen.
					next.type = 'string';
				}
				row.data = next;
				/* translators: %s: name of the rule, for example "Fixed value". */
				announce( sprintf( __( 'Rule changed to %s. Only the settings of this rule are saved.', 'kk-content-exporter' ), control.options[ control.selectedIndex ].text ) );
			} else if ( control.classList.contains( 'kkce-mapping-source' ) ) {
				const scope = control.getAttribute( 'data-scope' );
				const position = parseInt( control.getAttribute( 'data-index' ) || '0', 10 );
				// An empty choice keeps its place in the chain, so the other fields do not move while browsing.
				row.data.sources[ scope ][ position ] = control.value;
				if ( 0 === position && '' !== control.value ) {
					proposeKey( row, control.value );
				}
			} else if ( control.hasAttribute( 'data-option' ) ) {
				row.data.options = Object.assign( {}, row.data.options );
				row.data.options[ control.getAttribute( 'data-option' ) ] = control.checked;
			} else {
				return;
			}

			commit( true );
			render( control.id );
		} );

		/**
		 * Asks the catalog of the checked post types.
		 *
		 * @param {string[]} postTypes Checked post types.
		 */
		function loadCatalog( postTypes ) {
			const current = ++request;

			if ( ! postTypes.length ) {
				catalog = Object.assign( {}, catalog, {
					post_types: [],
					labels: {},
					suggested_rows: [],
					sources: catalog.sources.filter( function ( source ) {
						return '' === source.post_type;
					} ),
				} );
				proposeRows();
				commit( false );
				render( '' );
				preview.typesChanged();
				return;
			}

			const query = postTypes.map( function ( postType ) {
				return 'post_types[]=' + encodeURIComponent( postType );
			} ).join( '&' );

			wp.apiFetch( { path: data.catalogPath + '?' + query } ).then( function ( response ) {
				if ( current !== request ) {
					return;
				}
				catalog = response;
				proposeRows();
				commit( false );
				render( document.activeElement && container.contains( document.activeElement ) ? document.activeElement.id : '' );
				preview.typesChanged();
			} ).catch( function () {
				if ( current === request ) {
					announce( __( 'The fields of the selected post types could not be loaded. The previous list is kept.', 'kk-content-exporter' ) );
				}
			} );
		}

		document.addEventListener( 'change', function ( event ) {
			const target = event.target;
			if ( ! target.matches || ! target.matches( 'input[type="checkbox"][name="kkce_feed[post_types][]"]' ) ) {
				return;
			}
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				const checked = Array.prototype.map.call(
					document.querySelectorAll( 'input[type="checkbox"][name="kkce_feed[post_types][]"]:checked' ),
					function ( input ) {
						return input.value;
					}
				);
				loadCatalog( checked );
			}, CATALOG_DELAY );
		} );

		proposeRows();
		commit( false );
		render( '' );
		preview.typesChanged();
	}

	function init() {
		const container = document.getElementById( 'kkce-feed-mapping' );
		if ( ! container ) {
			return;
		}

		let data;
		try {
			data = JSON.parse( container.getAttribute( 'data-kkce-mapping' ) || '' );
		} catch ( error ) {
			return;
		}
		if ( ! data || ! data.catalog || ! Array.isArray( data.catalog.sources ) || ! data.field || ! data.keyPattern || ! data.maxChain || ! data.valueMaxLength || ! data.previewPath || ! data.itemsPath || ! data.templateMaxLength ) {
			return;
		}

		MappingEditor( container, data );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}( window.wp ) );
