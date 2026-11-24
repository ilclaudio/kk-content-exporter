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
 *   converts it (a number, true or false);
 * - rule "Template": a text with fields between braces, for every post type, with the same exceptions;
 * - rule "Lookup table": a field to read (for every post type, with the same exceptions), a table of lines
 *   "value → fixed text or field", and a default used when no line matches (none: null);
 * - rule "Condition": a field to check (with the same exceptions), one check, and the output written when
 *   the check is true and when it is false.
 * Rows can be added, removed and moved. The script keeps the mapping in a hidden field
 * `kkce_feed[mapping]` that it creates itself: without JavaScript the field does not exist and the saved
 * mapping stays as it is. The checks made here only help while typing: the server checks the whole
 * mapping again when the feed is saved, and a refused mapping comes back with its errors (`data.draft`).
 * A sensitive field is exported only when its row confirms it with the box "I understand, publish this value".
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

	const { __, _n, _x, sprintf } = wp.i18n;

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
	const LABEL_MAX_LENGTH = __( 'Maximum length', 'kk-content-exporter' );
	const LABEL_FIELD_TO_READ = __( 'Field to read', 'kk-content-exporter' );
	const LABEL_TABLE = __( 'Table', 'kk-content-exporter' );
	const LABEL_DEFAULT = __( 'When no line matches', 'kk-content-exporter' );
	const LABEL_CHECK = __( 'Check', 'kk-content-exporter' );
	const LABEL_THEN = __( 'If true, write', 'kk-content-exporter' );
	const LABEL_ELSE = __( 'Otherwise, write', 'kk-content-exporter' );

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
		[ 'lookup', __( 'Lookup table', 'kk-content-exporter' ) ],
		[ 'condition', __( 'Condition', 'kk-content-exporter' ) ],
	];

	/**
	 * Checks of a condition, with their labels; the first two compare no text, `one_of` a list of texts.
	 */
	const OPERATORS = [
		[ 'empty', __( 'is empty', 'kk-content-exporter' ) ],
		[ 'not_empty', __( 'is not empty', 'kk-content-exporter' ) ],
		[ 'equals', __( 'is equal to', 'kk-content-exporter' ) ],
		[ 'not_equals', __( 'is different from', 'kk-content-exporter' ) ],
		[ 'one_of', __( 'is one of', 'kk-content-exporter' ) ],
		[ 'contains', __( 'contains', 'kk-content-exporter' ) ],
	];

	/**
	 * Kinds of an output of a lookup table, with their labels; the default can also be nothing or the input.
	 */
	const LABEL_FIXED_TEXT = __( 'Fixed text', 'kk-content-exporter' );
	const OUTPUTS = [
		[ 'fixed', LABEL_FIXED_TEXT ],
		[ 'source', LABEL_FIELD ],
	];
	const DEFAULTS = [
		[ 'none', __( 'Nothing (null)', 'kk-content-exporter' ) ],
		[ 'fixed', LABEL_FIXED_TEXT ],
		[ 'source', LABEL_FIELD ],
		[ 'input', __( 'The value read, unchanged', 'kk-content-exporter' ) ],
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
	 * Value of the last option of a list of fields: it opens the form of a post meta key that is not listed.
	 */
	const OTHER_META = '__kkce_other_meta';


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
	 * Mapping the editor starts from: a refused draft, read from its JSON text so that empty maps stay objects,
	 * otherwise the saved mapping.
	 *
	 * @param {Object} data Initial data of the box.
	 * @return {Object} Mapping with a list of rows.
	 */
	function initialMapping( data ) {
		if ( data.draft && data.draft.readable && 'string' === typeof data.draft.mapping ) {
			try {
				const draft = JSON.parse( data.draft.mapping );
				if ( draft && Array.isArray( draft.rows ) ) {
					return draft;
				}
			} catch ( error ) {
				// The server checked the text: a failure here leaves the saved mapping.
			}
		}
		return data.mapping && Array.isArray( data.mapping.rows ) ? data.mapping : { version: 1, rows: [] };
	}

	/**
	 * Whether a row gives a plain text, so that a maximum length can be set: a template that is not HTML, or
	 * another rule (but a fixed value) with the text type.
	 *
	 * @param {Object} row Row of the mapping.
	 * @return {boolean} Whether the output is plain text.
	 */
	function hasTextOutput( row ) {
		if ( 'template' === row.kind ) {
			return 'html' !== row.type;
		}
		return 'fixed' !== row.kind && 'string' === row.type;
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
		if ( 'lookup' === row.kind ) {
			return isMap( row.inputs ) && Array.isArray( row.cases ) && row.cases.every( function ( item ) {
				return isMap( item ) && isMap( item.output );
			} ) && ( undefined === row.default || isMap( row.default ) );
		}
		if ( 'condition' === row.kind ) {
			return isMap( row.inputs ) && 'string' === typeof row.operator && ( undefined === row.matches || Array.isArray( row.matches ) ) && [ row.then, row.else ].every( function ( output ) {
				return undefined === output || isMap( output );
			} );
		}
		return false;
	}

	/**
	 * Kind of an output of a lookup table: `fixed`, `source`, `input`, or `none` for no output.
	 *
	 * @param {Object|undefined} output Output.
	 * @return {string} Kind.
	 */
	function outputKind( output ) {
		if ( ! output ) {
			return 'none';
		}
		if ( Object.prototype.hasOwnProperty.call( output, 'source' ) ) {
			return 'source';
		}
		return true === output.input ? 'input' : 'fixed';
	}

	/**
	 * Map of the scopes of an editable row: chains of a `field` row, values of a `fixed` row, templates of a
	 * `template` row, inputs of a `lookup` row.
	 *
	 * @param {Object} row Row of the mapping.
	 * @return {Object} Map of post types (or `*`).
	 */
	function scopeMap( row ) {
		if ( 'field' === row.kind ) {
			return row.sources;
		}
		if ( 'lookup' === row.kind || 'condition' === row.kind ) {
			return row.inputs;
		}
		return 'template' === row.kind ? row.templates : row.values;
	}

	/**
	 * Appends an element when there is one.
	 *
	 * @param {HTMLElement}      parent Parent.
	 * @param {HTMLElement|null} node   Element, or null.
	 */
	function appendIfAny( parent, node ) {
		if ( node ) {
			parent.appendChild( node );
		}
	}

	/**
	 * Keeps the scopes of a map whose value passes a check, `*` first, then the exceptions in their order.
	 *
	 * @param {Object}   map  Map of post types (or `*`).
	 * @param {Function} keep Whether a value is kept.
	 * @return {Object} New map.
	 */
	function keptScopes( map, keep ) {
		const out = {};
		scopesOf( map ).forEach( function ( scope ) {
			if ( keep( map[ scope ] ) ) {
				out[ scope ] = map[ scope ];
			}
		} );
		return out;
	}

	/**
	 * Prepares a row for the interface when it enters the editor (saved or refused mapping, proposed rows): an
	 * editable row always has the general scope `*`, empty when missing, so that drawing only reads the data. The
	 * hidden field is not affected: empty scopes are left out when the row is written.
	 *
	 * @param {Object} row Row of the mapping.
	 * @return {Object} The same row.
	 */
	function withGeneralScope( row ) {
		if ( ! isEditable( row ) ) {
			return row;
		}
		const name = { field: 'sources', template: 'templates', lookup: 'inputs', condition: 'inputs' }[ row.kind ] || 'values';
		if ( ! Object.prototype.hasOwnProperty.call( row[ name ], '*' ) ) {
			row[ name ] = Object.assign( { '*': 'field' === row.kind ? [] : '' }, row[ name ] );
		}
		return row;
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
	 * Every source of a `field` row (every chain), of a `template` row (every template) or of a `lookup` row
	 * (inputs, outputs, default), without duplicates.
	 *
	 * @param {Object} row Row of the mapping.
	 * @return {string[]} Source identifiers.
	 */
	function rowSources( row ) {
		const found = [];
		const add = function ( source ) {
			if ( source && -1 === found.indexOf( String( source ) ) ) {
				found.push( String( source ) );
			}
		};
		if ( 'lookup' === row.kind ) {
			Object.keys( row.inputs || {} ).forEach( function ( scope ) {
				add( row.inputs[ scope ] );
			} );
			( row.cases || [] ).forEach( function ( item ) {
				add( item.output && item.output.source );
			} );
			add( row.default && row.default.source );
			return found;
		}
		if ( 'condition' === row.kind ) {
			Object.keys( row.inputs || {} ).forEach( function ( scope ) {
				add( row.inputs[ scope ] );
			} );
			add( row.then && row.then.source );
			add( row.else && row.else.source );
			return found;
		}
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
	 * Labelled control of a scope of a row. The accessible name always says the scope ("Value (For all post
	 * types)"); the visible label says it only when the row has exceptions, so a simple row shows just "Value". The
	 * name then comes from `aria-label` and starts with the visible text (WCAG 2.5.3, label in name).
	 *
	 * @param {string}      short     Visible label without the scope.
	 * @param {string}      full      Label with the scope.
	 * @param {boolean}     showScope Whether the scope is shown.
	 * @param {HTMLElement} control   Control.
	 * @return {HTMLElement} Wrapper.
	 */
	function labelledScope( short, full, showScope, control ) {
		if ( showScope ) {
			return labelled( full, control );
		}
		control.setAttribute( 'aria-label', full );
		return labelled( short, control );
	}

	/**
	 * Whether an editable row has exceptions for single post types besides the general scope `*`.
	 *
	 * @param {Object} row Row of the mapping.
	 * @return {boolean} Whether it has exceptions.
	 */
	function hasExceptions( row ) {
		return scopesOf( scopeMap( row ) ).length > 1;
	}

	/**
	 * The preview panel: the exported object of one item per checked post type, computed by the server with
	 * the mapping of the box (saved or not), as a visitor would get it.
	 *
	 * It is computed again shortly after every change, and with the "Refresh" button. For every post type the
	 * latest modified item is used, unless another one is chosen from the latest published items. Only the
	 * errors are announced to screen readers; the JSON is shown, not read aloud.
	 *
	 * @param {Object}   deps              What the panel needs from the editor.
	 * @param {Element}  deps.layout       Layout of the box, marked while the panel is shown.
	 * @param {Function} deps.catalog      Current catalog (checked post types, labels).
	 * @param {Function} deps.hasRows      Whether the mapping has rows.
	 * @param {Function} deps.mapping      JSON text of the mapping of the box.
	 * @param {Function} deps.errorMessage Message of an error of the mapping.
	 * @param {Function} deps.announce     Announces a message to screen readers.
	 * @param {string}   deps.previewPath  Route of the preview.
	 * @param {string}   deps.itemsPath    Route of the latest items.
	 * @return {Object} The panel: `node`, `schedule()`, `typesChanged()`, `setVisible()`.
	 */
	function createPreview( deps ) {
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
		// State of the panels, kept across redraws; none of it is a change of the feed: closed panels and
		// expanded JSON, per post type.
		const closedTypes = {};
		const expanded = {};
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
				items.appendChild( el( 'li', {}, deps.errorMessage( error ) ) );
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
			return ( deps.catalog().labels || {} )[ postType ] || postType;
		}

		/**
		 * Draws the item picker and the result of every checked post type.
		 */
		function draw() {
			const focused = document.activeElement && node.contains( document.activeElement ) ? document.activeElement.id : '';
			results.textContent = '';

			deps.catalog().post_types.forEach( function ( postType ) {
				const result = shown[ postType ];
				// A panel per post type, open at first; its title names the type and the item, also when it is closed.
				const block = el( 'details', { className: 'kkce-preview-type', open: ! closedTypes[ postType ] } );
				let item = '';
				if ( result && 'empty' !== result ) {
					const title = '' === result.post.title ? __( '(no title)', 'kk-content-exporter' ) : result.post.title;
					/* translators: 1: title of the previewed item, 2: its ID. */
					item = sprintf( __( '%1$s (ID %2$d)', 'kk-content-exporter' ), title, result.post.id );
				}
				/* translators: 1: label of a post type, 2: title and ID of the item of the preview. */
				block.appendChild( el( 'summary', {}, '' === item ? typeLabel( postType ) : sprintf( __( '%1$s — %2$s', 'kk-content-exporter' ), typeLabel( postType ), item ) ) );
				block.addEventListener( 'toggle', function () {
					closedTypes[ postType ] = ! block.open;
				} );
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

				if ( 'empty' === result ) {
					/* translators: %s: label of a post type. */
					block.appendChild( el( 'p', {}, sprintf( __( 'No published item of %s.', 'kk-content-exporter' ), typeLabel( postType ) ) ) );
				} else if ( result ) {
					// Focusable, so the JSON can be scrolled with the keyboard. Its height is limited until it is
					// expanded; the text is always the whole JSON.
					const pre = el( 'pre', { id: 'kkce-preview-json-' + postType, className: 'kkce-preview-json' + ( expanded[ postType ] ? ' is-expanded' : '' ), tabIndex: 0 } );
					pre.appendChild( el( 'code', {}, JSON.stringify( result.data, null, 2 ) ) );
					block.appendChild( pre );
					const tools = el( 'div', { className: 'kkce-preview-tools' } );
					const label = typeLabel( postType );
					/* translators: %s: label of a post type. */
					const collapseName = sprintf( __( 'Collapse (%s)', 'kk-content-exporter' ), label );
					/* translators: %s: label of a post type. */
					const expandName = sprintf( __( 'Expand (%s)', 'kk-content-exporter' ), label );
					/* translators: %s: label of a post type. */
					const copyName = sprintf( __( 'Copy JSON (%s)', 'kk-content-exporter' ), label );
					tools.appendChild( el( 'button', {
						type: 'button',
						id: 'kkce-preview-expand-' + postType,
						className: 'button',
						ariaExpanded: expanded[ postType ] ? 'true' : 'false',
						ariaControls: pre.id,
						ariaLabel: expanded[ postType ] ? collapseName : expandName,
						'data-preview-action': 'expand',
						'data-type': postType,
					}, expanded[ postType ] ? __( 'Collapse', 'kk-content-exporter' ) : __( 'Expand', 'kk-content-exporter' ) ) );
					tools.appendChild( el( 'button', {
						type: 'button',
						id: 'kkce-preview-copy-' + postType,
						className: 'button',
						ariaLabel: copyName,
						'data-preview-action': 'copy',
						'data-type': postType,
					}, __( 'Copy JSON', 'kk-content-exporter' ) ) );
					block.appendChild( tools );
					block.appendChild( el( 'p', { className: 'kkce-preview-copied', role: 'status' } ) );
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
			const postTypes = deps.catalog().post_types.slice();

			// Without rows the panel is hidden (see setVisible()): nothing to compute.
			if ( ! deps.hasRows() ) {
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
				const body = { mapping: deps.mapping(), post_types: [ postType ] };
				if ( selected[ postType ] ) {
					body.post_id = selected[ postType ];
				}
				return wp.apiFetch( { path: deps.previewPath, method: 'POST', data: body } ).then( function ( response ) {
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
					deps.announce( sprintf( _n( 'The preview is not up to date: the mapping has %d error.', 'The preview is not up to date: the mapping has %d errors.', list.length, 'kk-content-exporter' ), list.length ) );
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
					deps.announce( failed );
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
			deps.catalog().post_types.forEach( function ( postType ) {
				if ( recent[ postType ] ) {
					return;
				}
				recent[ postType ] = [];
				wp.apiFetch( { path: deps.itemsPath + '?post_type=' + encodeURIComponent( postType ) } ).then( function ( response ) {
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
		/**
		 * Selects the whole JSON of a panel, so that it can be copied with the keyboard.
		 *
		 * @param {HTMLElement} pre Element of the JSON.
		 */
		function selectJson( pre ) {
			const range = document.createRange();
			range.selectNodeContents( pre );
			const selection = window.getSelection();
			selection.removeAllRanges();
			selection.addRange( range );
			pre.focus();
		}

		// Expanding and copying change nothing of the feed: no unsaved change.
		results.addEventListener( 'click', function ( event ) {
			const control = event.target.closest( 'button[data-preview-action]' );
			if ( ! control ) {
				return;
			}
			const postType = control.getAttribute( 'data-type' );
			const block = control.closest( '.kkce-preview-type' );
			const pre = block && block.querySelector( '.kkce-preview-json' );
			const status = block && block.querySelector( '.kkce-preview-copied' );
			if ( ! pre || ! status ) {
				return;
			}
			if ( 'expand' === control.getAttribute( 'data-preview-action' ) ) {
				expanded[ postType ] = ! expanded[ postType ];
				draw();
				return;
			}
			// The clipboard of the browser needs a secure connection (HTTPS or localhost): without it, or when
			// copying fails, the JSON is selected and the message says how to copy it.
			const fallback = function ( message ) {
				selectJson( pre );
				status.textContent = message;
			};
			const manual = __( 'The JSON is selected: press Ctrl+C (Cmd+C on a Mac).', 'kk-content-exporter' );
			if ( ! window.isSecureContext || ! navigator.clipboard ) {
				/* translators: %s: how to copy the selected JSON with the keyboard. */
				fallback( sprintf( __( 'Copying is not available on this connection (it needs HTTPS). %s', 'kk-content-exporter' ), manual ) );
				return;
			}
			navigator.clipboard.writeText( pre.textContent ).then( function () {
				status.textContent = __( 'JSON copied.', 'kk-content-exporter' );
			}, function () {
				/* translators: %s: how to copy the selected JSON with the keyboard. */
				fallback( sprintf( __( 'Copying failed. %s', 'kk-content-exporter' ), manual ) );
			} );
		} );

		results.addEventListener( 'input', function ( event ) {
			if ( event.target.classList.contains( 'kkce-preview-item' ) ) {
				// The picker emits input before change; neither changes the feed configuration.
				event.stopPropagation();
			}
		} );
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
			deps.layout.classList.toggle( 'kkce-has-preview', visible );
			if ( visible ) {
				schedule();
			}
		}

		return { node: node, schedule: schedule, typesChanged: typesChanged, setVisible: setVisible };
	}

	/**
	 * The mapping editor bound to its container.
	 *
	 * @param {HTMLElement} container Container printed by the box.
	 * @param {Object}      data      Initial data.
	 */
	function MappingEditor( container, data ) {
		const mapping = initialMapping( data );
		// Rules of the keys and limits come from the server (Mapping_Validator): nothing is copied here.
		const keyPattern = new RegExp( data.keyPattern );
		let nextId = 1;
		let catalog = data.catalog;
		// Post meta keys typed by the administrator or used by the saved mapping, listed with the others (the server judges them).
		let metaKeys = [];
		let pendingMetaKey = '';
		// The list of fields where a post meta key is being typed (id, class, row, scope, position), and its message.
		let metaTarget = null;
		let metaMessage = '';
		let metaTyped = '';
		let request = 0;
		let timer = null;
		// A saved mapping is never replaced by the proposed rows; an empty one is, until it is touched.
		let touched = mapping.rows.length > 0;
		// Saved rows start closed. A refused draft is work the administrator is correcting: its rows stay open.
		const draftShown = !! ( data.draft && data.draft.readable && mapping !== data.mapping );
		let rows = mapping.rows.map( function ( row ) {
			return { uid: nextId++, data: withGeneralScope( row ), autoKey: null, collapsed: ! draftShown };
		} );

		const list = el( 'ol', { className: 'kkce-mapping-rows' } );
		const bulk = el( 'div', { className: 'kkce-mapping-bulk', hidden: true } );
		const expandAll = el( 'button', { type: 'button', className: 'button', id: 'kkce-mapping-expand-all' }, __( 'Expand all', 'kk-content-exporter' ) );
		const collapseAll = el( 'button', { type: 'button', className: 'button', id: 'kkce-mapping-collapse-all' }, __( 'Collapse all', 'kk-content-exporter' ) );
		bulk.appendChild( expandAll );
		bulk.appendChild( collapseAll );
		const empty = el( 'p', { className: 'kkce-mapping-empty' }, __( 'No rows yet. Add a row to choose what the feed exports.', 'kk-content-exporter' ) );
		const addButton = el( 'button', { type: 'button', className: 'button' }, __( 'Add row', 'kk-content-exporter' ) );
		const limit = el( 'p', { className: 'description', hidden: true } );
		const status = el( 'p', { className: 'screen-reader-text', ariaLive: 'polite' } );
		const field = el( 'input', { type: 'hidden', name: data.field } );

		// Two columns when the box is wide (rules, then the preview that stays in view), one otherwise: see the CSS.
		const editor = el( 'div', { className: 'kkce-mapping-editor' } );
		const layout = el( 'div', { className: 'kkce-mapping-layout' } );
		const preview = createPreview( {
			layout: layout,
			catalog: function () {
				return catalog;
			},
			hasRows: function () {
				return rows.length > 0;
			},
			mapping: function () {
				return field.value;
			},
			errorMessage: errorMessage,
			announce: announce,
			previewPath: data.previewPath,
			itemsPath: data.itemsPath,
		} );

		container.textContent = '';
		const refused = renderRefused();
		if ( refused ) {
			editor.appendChild( refused );
		}
		[ empty, bulk, list, addButton, limit ].forEach( function ( node ) {
			editor.appendChild( node );
		} );
		layout.appendChild( editor );
		layout.appendChild( preview.node );
		[ layout, status, field ].forEach( function ( node ) {
			container.appendChild( node );
		} );

		/**
		 * Notice of the mapping refused by the last save, with its errors: the box shows that mapping again
		 * (or the saved one, when the refused text could not be read).
		 *
		 * @return {HTMLElement|null} The notice, or null without a refused mapping.
		 */
		function renderRefused() {
			if ( ! data.draft || ! Array.isArray( data.draft.errors ) ) {
				return null;
			}
			const notice = el( 'div', { className: 'notice notice-error inline kkce-mapping-refused', role: 'alert' } );
			notice.appendChild( el( 'p', {}, data.draft.readable
				? __( 'This mapping was not saved: it is shown as you sent it. Fix the errors below and update the feed; until then the previous mapping is used.', 'kk-content-exporter' )
				: __( 'The mapping you sent could not be read and was not saved: the saved mapping is shown.', 'kk-content-exporter' ) ) );
			const items = el( 'ul' );
			data.draft.errors.forEach( function ( error ) {
				items.appendChild( el( 'li', {}, errorMessage( error ) ) );
			} );
			notice.appendChild( items );
			return notice;
		}

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
			// The maximum length belongs to the row: kept in memory, written only for a plain text output.
			if ( ! hasTextOutput( row ) || undefined === row.max_length ) {
				delete out.max_length;
			}
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
			} else if ( 'lookup' === row.kind ) {
				// Inputs not chosen are left out (none at all: the server reports the empty inputs); the texts to
				// match lose their spaces at the ends, as the input does; a new empty line is left out.
				out.inputs = keptScopes( row.inputs, Boolean );
				out.cases = row.cases.map( function ( item ) {
					return { match: String( item.match || '' ).trim(), output: Object.assign( {}, item.output ) };
				} ).filter( function ( item ) {
					const value = 'source' === outputKind( item.output ) ? item.output.source : item.output.fixed;
					return '' !== item.match || ( undefined !== value && '' !== value );
				} );
				if ( undefined === row.default ) {
					delete out.default;
				}
			} else if ( 'condition' === row.kind ) {
				// Inputs as in a table. The texts to compare are kept while the check changes, and written only for
				// the checks that use them: trimmed, without empty lines.
				out.inputs = keptScopes( row.inputs, Boolean );
				const texts = ( row.matches || [] ).map( function ( text ) {
					return String( text ).trim();
				} );
				if ( 'empty' === row.operator || 'not_empty' === row.operator ) {
					delete out.matches;
				} else if ( 'one_of' === row.operator ) {
					out.matches = texts.filter( function ( text ) {
						return '' !== text;
					} );
				} else {
					out.matches = texts.slice( 0, 1 );
					if ( ! out.matches.length ) {
						out.matches = [ '' ];
					}
				}
				[ 'then', 'else' ].forEach( function ( name ) {
					if ( undefined === row[ name ] ) {
						delete out[ name ];
					}
				} );
			} else {
				// Fixed values and templates: empty texts are left out.
				const name = 'template' === row.kind ? 'templates' : 'values';
				out[ name ] = keptScopes( row[ name ], function ( value ) {
					return undefined !== value && '' !== value;
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
				inputs: LABEL_FIELD_TO_READ,
				cases: LABEL_TABLE,
				default: LABEL_DEFAULT,
				operator: LABEL_CHECK,
				matches: __( 'Values to compare', 'kk-content-exporter' ),
				then: LABEL_THEN,
				else: LABEL_ELSE,
				confirmed: __( 'Confirmation', 'kk-content-exporter' ),
				options: __( 'Options', 'kk-content-exporter' ),
				max_length: LABEL_MAX_LENGTH,
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
				sensitive: __( 'a field needs an explicit confirmation: check "I understand, publish this value"', 'kk-content-exporter' ),
				list: __( 'a list cannot be used here: choose a field with a single value', 'kk-content-exporter' ),
			};
			let message = codes[ error.code ] || error.code;
			if ( 'duplicate' === error.code && 'key' !== error.field ) {
				message = __( 'the same value is written more than once', 'kk-content-exporter' );
			}
			const label = fields[ error.field ] || error.field;

			if ( error.row < 0 ) {
				if ( 'json' === error.code || 'not_object' === error.code ) {
					return __( 'The mapping cannot be read.', 'kk-content-exporter' );
				}
				if ( 'too_large' === error.code ) {
					return __( 'The mapping is too large: remove some rows or shorten the longest texts.', 'kk-content-exporter' );
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
		 * @param {string}   current Selected source.
		 * @param {Object}   attrs   Properties of the select.
		 * @param {Function} accept  Optional: whether a source of the catalog is offered.
		 * @return {HTMLSelectElement} The select.
		 */
		function sourceSelect( current, attrs, accept ) {
			const node = el( 'select', attrs );
			node.appendChild( el( 'option', { value: '' }, __( '— Choose a field —', 'kk-content-exporter' ) ) );

			if ( '' !== current && null === findSource( current ) ) {
				/* translators: %s: identifier of a field that the post types of the feed do not offer. */
				node.appendChild( el( 'option', { value: current, selected: true }, sprintf( __( '%s (not offered by the checked post types)', 'kk-content-exporter' ), current ) ) );
			} else if ( '' !== current && accept && ! accept( findSource( current ) ) ) {
				/* translators: %s: label of a field that cannot be used in this place, for example a list. */
				node.appendChild( el( 'option', { value: current, selected: true }, sprintf( __( '%s (cannot be used here)', 'kk-content-exporter' ), findSource( current ).label ) ) );
			}

			const groups = [ [ '', __( 'Common fields', 'kk-content-exporter' ), false ] ];
			catalog.post_types.forEach( function ( postType ) {
				const label = ( catalog.labels || {} )[ postType ] || postType;
				/* translators: %s: label of a post type. */
				groups.push( [ postType, sprintf( __( 'Fields of %s', 'kk-content-exporter' ), label ), false ] );
				/* translators: %s: label of a post type. */
				groups.push( [ postType, sprintf( __( 'Hidden fields of %s (meant for the plugin or theme that stores them)', 'kk-content-exporter' ), label ), true ] );
			} );

			groups.forEach( function ( group ) {
				const sources = catalog.sources.filter( function ( source ) {
					return source.post_type === group[ 0 ] && !! source.hidden === group[ 2 ] && ( ! accept || accept( source ) );
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

			if ( attrs[ 'data-uid' ] && catalog.post_types.length ) {
				node.appendChild( el( 'option', { value: OTHER_META }, __( 'Other post meta key…', 'kk-content-exporter' ) ) );
			}

			return node;
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
			// The name of the group is always there for assistive technologies; it is shown only with exceptions.
			group.appendChild( el( 'legend', { className: hasExceptions( row.data ) ? '' : 'screen-reader-text' }, label ) );

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
					actions.appendChild( button( row, 'chain-up', LABEL_UP, { id: id + '-up', disabled: 0 === index, ariaLabel: sprintf( __( 'Move up: field %1$d (%2$s)', 'kk-content-exporter' ), index + 1, label ), 'data-scope': scope, 'data-index': String( index ) } ) );
					/* translators: 1: position of the field in its chain, 2: name of the chain, for example "For all post types". */
					actions.appendChild( button( row, 'chain-down', LABEL_DOWN, { id: id + '-down', disabled: index === chain.length - 1, ariaLabel: sprintf( __( 'Move down: field %1$d (%2$s)', 'kk-content-exporter' ), index + 1, label ), 'data-scope': scope, 'data-index': String( index ) } ) );
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
			tools.appendChild( button( row, 'chain-add', LABEL_ADD_FALLBACK, { id: prefix + '-add', disabled: chain.length >= data.maxChain || ! chain.length || '' === chain[ chain.length - 1 ], ariaLabel: sprintf( __( 'Add fallback (%s)', 'kk-content-exporter' ), label ), 'data-scope': scope } ) );
			if ( '*' !== scope ) {
				/* translators: %s: post types the exception applies to, for example "For Posts". */
				tools.appendChild( button( row, 'scope-remove', LABEL_REMOVE_EXCEPTION, { id: prefix + '-scope-remove', className: 'button-link button-link-delete', ariaLabel: sprintf( __( 'Remove exception: %s', 'kk-content-exporter' ), label ), 'data-scope': scope } ) );
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
			const full = sprintf( __( 'Value (%s)', 'kk-content-exporter' ), label );
			group.appendChild( labelledScope( __( 'Value', 'kk-content-exporter' ), full, hasExceptions( row.data ), input ) );
			if ( '*' !== scope ) {
				/* translators: %s: post types the exception applies to, for example "For Posts". */
				group.appendChild( button( row, 'scope-remove', LABEL_REMOVE_EXCEPTION, { id: prefix + '-scope-remove', className: 'button-link button-link-delete', ariaLabel: sprintf( __( 'Remove exception: %s', 'kk-content-exporter' ), label ), 'data-scope': scope } ) );
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
			const full = sprintf( __( 'Template (%s)', 'kk-content-exporter' ), label );
			group.appendChild( labelledScope( __( 'Template', 'kk-content-exporter' ), full, hasExceptions( row.data ), area ) );

			const insert = el( 'div', { className: 'kkce-mapping-insert' } );
			// No data-uid: choosing the field to insert changes nothing until the button is pressed.
			insert.appendChild( labelled( __( 'Field to insert', 'kk-content-exporter' ), sourceSelect( '', { id: prefix + '-insert', className: 'kkce-mapping-insert-source' } ) ) );
			/* translators: %s: name of the scope, for example "For all post types". */
			insert.appendChild( button( row, 'insert', LABEL_INSERT_FIELD, { id: prefix + '-insert-button', ariaLabel: sprintf( __( 'Insert field in the template (%s)', 'kk-content-exporter' ), label ), 'data-scope': scope } ) );
			if ( '*' !== scope ) {
				/* translators: %s: post types the exception applies to, for example "For Posts". */
				insert.appendChild( button( row, 'scope-remove', LABEL_REMOVE_EXCEPTION, { id: prefix + '-scope-remove', className: 'button-link button-link-delete', ariaLabel: sprintf( __( 'Remove exception: %s', 'kk-content-exporter' ), label ), 'data-scope': scope } ) );
			}
			group.appendChild( insert );
			return group;
		}

		/**
		 * Whether the inputs of a row can be lists: only for the checks of a condition that do not compare texts.
		 *
		 * @param {Object} row Row.
		 * @return {boolean} Whether lists are accepted.
		 */
		function acceptsLists( row ) {
			return 'condition' === row.data.kind && -1 !== [ 'empty', 'not_empty', 'contains' ].indexOf( row.data.operator );
		}

		/**
		 * Field read by a scope of a `lookup` or `condition` row. A table does not offer lists (a list cannot be
		 * compared with a text); a condition offers them only for "is empty", "is not empty" and "contains", and
		 * marks a list already chosen as not usable when another check is chosen.
		 *
		 * @param {Object} row   Row.
		 * @param {string} scope Scope.
		 * @return {HTMLElement} The group.
		 */
		function renderInput( row, scope ) {
			const prefix = 'kkce-mapping-' + row.uid + '-' + scopeId( scope );
			const label = scopeLabel( scope );
			const group = el( 'div', { className: 'kkce-mapping-scope kkce-mapping-value' } );
			const control = sourceSelect( String( row.data.inputs[ scope ] || '' ), { id: prefix + '-input', className: 'kkce-mapping-input', 'data-uid': String( row.uid ), 'data-scope': scope }, acceptsLists( row ) ? null : function ( source ) {
				return 'list' !== source.type;
			} );
			/* translators: %s: name of the scope, for example "For all post types". */
			const full = sprintf( __( 'Field to read (%s)', 'kk-content-exporter' ), label );
			group.appendChild( labelledScope( LABEL_FIELD_TO_READ, full, hasExceptions( row.data ), control ) );
			if ( '*' !== scope ) {
				/* translators: %s: post types the exception applies to, for example "For Posts". */
				group.appendChild( button( row, 'scope-remove', LABEL_REMOVE_EXCEPTION, { id: prefix + '-scope-remove', className: 'button-link button-link-delete', ariaLabel: sprintf( __( 'Remove exception: %s', 'kk-content-exporter' ), label ), 'data-scope': scope } ) );
			}
			return group;
		}

		/**
		 * Control of an output of a lookup table: a text field for a fixed text, a select for a field.
		 *
		 * @param {Object} row    Row.
		 * @param {Object} output Output.
		 * @param {string} id     Id of the control.
		 * @param {string} label  Label of the control.
		 * @param {string} index  Line of the table, or `default`.
		 * @return {HTMLElement|null} The control, or null when the output has no value to choose.
		 */
		function outputControl( row, output, id, label, index ) {
			const kind = outputKind( output );
			if ( 'source' === kind ) {
				return labelled( label, sourceSelect( String( output.source || '' ), { id: id, className: 'kkce-mapping-output-source', 'data-uid': String( row.uid ), 'data-index': index } ) );
			}
			if ( 'fixed' !== kind ) {
				return null;
			}
			return labelled( label, el( 'input', {
				type: 'text',
				id: id,
				className: 'regular-text kkce-mapping-output-fixed',
				value: null === output.fixed || undefined === output.fixed ? '' : String( output.fixed ),
				maxLength: data.valueMaxLength,
				'data-uid': String( row.uid ),
				'data-index': index,
			} ) );
		}

		/**
		 * Lines of the table of a `lookup` row and its default.
		 *
		 * @param {Object} row Row.
		 * @return {HTMLElement} The group.
		 */
		function renderCases( row ) {
			const prefix = 'kkce-mapping-' + row.uid;
			const cases = row.data.cases;
			const group = el( 'fieldset', { className: 'kkce-mapping-scope kkce-mapping-cases' } );
			group.appendChild( el( 'legend', {}, LABEL_TABLE ) );

			const items = el( 'ol', { className: 'kkce-mapping-chain' } );
			cases.forEach( function ( item, index ) {
				const id = prefix + '-case-' + index;
				const line = el( 'li', { className: 'kkce-mapping-case' } );
				const position = String( index );
				line.appendChild( labelled( __( 'If the value is', 'kk-content-exporter' ), el( 'input', {
					type: 'text',
					id: id + '-match',
					className: 'kkce-mapping-match',
					value: String( item.match || '' ),
					maxLength: data.valueMaxLength,
					spellcheck: false,
					'data-uid': String( row.uid ),
					'data-index': position,
				} ) ) );
				line.appendChild( labelled( __( 'Write', 'kk-content-exporter' ), select( { id: id + '-kind', className: 'kkce-mapping-output-kind', 'data-uid': String( row.uid ), 'data-index': position }, OUTPUTS, outputKind( item.output ) ) ) );
				const control = outputControl( row, item.output, id + '-output', 'source' === outputKind( item.output ) ? LABEL_FIELD : LABEL_FIXED_TEXT, position );
				if ( control ) {
					line.appendChild( control );
				}

				const actions = el( 'span', { className: 'kkce-mapping-chain-actions' } );
				/* translators: %d: position of a line in the table of a lookup rule. */
				actions.appendChild( button( row, 'case-up', LABEL_UP, { id: id + '-up', disabled: 0 === index, ariaLabel: sprintf( __( 'Move up: line %d', 'kk-content-exporter' ), index + 1 ), 'data-index': position } ) );
				/* translators: %d: position of a line in the table of a lookup rule. */
				actions.appendChild( button( row, 'case-down', LABEL_DOWN, { id: id + '-down', disabled: index === cases.length - 1, ariaLabel: sprintf( __( 'Move down: line %d', 'kk-content-exporter' ), index + 1 ), 'data-index': position } ) );
				/* translators: %d: position of a line in the table of a lookup rule. */
				actions.appendChild( button( row, 'case-remove', LABEL_REMOVE, { id: id + '-remove', className: 'button-link button-link-delete', ariaLabel: sprintf( __( 'Remove line %d', 'kk-content-exporter' ), index + 1 ), 'data-index': position } ) );
				line.appendChild( actions );
				items.appendChild( line );
			} );
			group.appendChild( items );

			const tools = el( 'div', { className: 'kkce-mapping-scope-actions' } );
			tools.appendChild( button( row, 'case-add', __( 'Add line', 'kk-content-exporter' ), { id: prefix + '-case-add', disabled: cases.length >= data.maxCases } ) );
			group.appendChild( tools );

			group.appendChild( renderOutput( row, 'default', LABEL_DEFAULT ) );

			return group;
		}

		/**
		 * An output that can also be nothing or the input: the default of a table, "then" and "else" of a
		 * condition.
		 *
		 * @param {Object} row   Row.
		 * @param {string} name  Field of the row (`default`, `then`, `else`).
		 * @param {string} label Label of the choice.
		 * @return {HTMLElement} The group.
		 */
		function renderOutput( row, name, label ) {
			const prefix = 'kkce-mapping-' + row.uid + '-' + name;
			const wrapper = el( 'div', { className: 'kkce-mapping-default' } );
			const kind = outputKind( row.data[ name ] );
			wrapper.appendChild( labelled( label, select( { id: prefix + '-kind', className: 'kkce-mapping-default-kind', 'data-uid': String( row.uid ), 'data-index': name }, DEFAULTS, kind ) ) );
			const control = outputControl( row, row.data[ name ], prefix, 'source' === kind ? LABEL_FIELD : LABEL_FIXED_TEXT, name );
			if ( control ) {
				wrapper.appendChild( control );
			}
			return wrapper;
		}

		/**
		 * Shows or hides the error of a list of texts with a line longer than the limit of the server.
		 *
		 * @param {HTMLElement} node  Element of the error.
		 * @param {string[]}    lines Texts, one per line.
		 */
		function showLineError( node, lines ) {
			const long = lines.some( function ( text ) {
				return String( text ).trim().length > data.valueMaxLength;
			} );
			/* translators: %d: most characters of a value. */
			node.textContent = long ? sprintf( __( 'A line is longer than %d characters: the mapping cannot be saved.', 'kk-content-exporter' ), data.valueMaxLength ) : '';
			node.hidden = ! long;
		}

		/**
		 * Check of a `condition` row: the operator and the texts it compares, then the two outputs.
		 *
		 * @param {Object} row Row.
		 * @return {HTMLElement} The group.
		 */
		function renderCheck( row ) {
			const prefix = 'kkce-mapping-' + row.uid;
			const group = el( 'fieldset', { className: 'kkce-mapping-scope kkce-mapping-check' } );
			group.appendChild( el( 'legend', {}, LABEL_CHECK ) );

			const line = el( 'div', { className: 'kkce-mapping-value' } );
			line.appendChild( labelled( __( 'The field', 'kk-content-exporter' ), select( { id: prefix + '-operator', className: 'kkce-mapping-operator', 'data-uid': String( row.uid ) }, OPERATORS, row.data.operator ) ) );
			const texts = row.data.matches || [];
			if ( 'one_of' === row.data.operator ) {
				// A textarea has no limit per line: a line that is too long is reported here, as the server would.
				const warning = el( 'p', { id: prefix + '-matches-error', className: 'kkce-mapping-error' } );
				const field = labelled( __( 'Values, one per line', 'kk-content-exporter' ), el( 'textarea', {
					id: prefix + '-matches',
					className: 'kkce-mapping-matches',
					rows: 4,
					value: texts.join( '\n' ),
					spellcheck: false,
					ariaDescribedby: warning.id,
					'data-uid': String( row.uid ),
				} ) );
				field.appendChild( warning );
				showLineError( warning, texts );
				line.appendChild( field );
			} else if ( 'empty' !== row.data.operator && 'not_empty' !== row.data.operator ) {
				line.appendChild( labelled( __( 'Value', 'kk-content-exporter' ), el( 'input', {
					type: 'text',
					id: prefix + '-matches',
					className: 'regular-text kkce-mapping-matches',
					value: String( texts[ 0 ] || '' ),
					maxLength: data.valueMaxLength,
					spellcheck: false,
					'data-uid': String( row.uid ),
				} ) ) );
			}
			group.appendChild( line );
			group.appendChild( renderOutput( row, 'then', LABEL_THEN ) );
			group.appendChild( renderOutput( row, 'else', LABEL_ELSE ) );

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
			// Exceptions on request: a button first; the picker appears only when the administrator asks for it.
			if ( ! row.pickerOpen ) {
				wrapper.appendChild( button( row, 'scope-open', __( 'Add exception for a post type', 'kk-content-exporter' ), { id: prefix + '-exception-open' } ) );
				return wrapper;
			}
			const picker = select( { id: prefix + '-exception' }, free.map( function ( postType ) {
				return [ postType, ( catalog.labels || {} )[ postType ] || postType ];
			} ), free[ 0 ] );
			wrapper.appendChild( labelled( __( 'Different for', 'kk-content-exporter' ), picker ) );
			wrapper.appendChild( button( row, 'scope-add', __( 'Add exception', 'kk-content-exporter' ), { id: prefix + '-exception-add' } ) );
			wrapper.appendChild( button( row, 'scope-cancel', __( 'Cancel', 'kk-content-exporter' ), { id: prefix + '-exception-cancel', className: 'button-link' } ) );
			return wrapper;
		}

		/**
		 * Short list of the advanced options set in a row, for the title of their section.
		 *
		 * @param {Object}  row        Row.
		 * @param {boolean} textOutput Whether the row gives a plain text (the maximum length applies).
		 * @return {string} Title of the section.
		 */
		function advancedTitle( row, textOutput ) {
			const options = row.data.options || {};
			const active = [];
			if ( textOutput && undefined !== row.data.max_length && '' !== String( row.data.max_length ) ) {
				/* translators: %s: maximum number of characters of a text. */
				active.push( sprintf( __( 'maximum length %s', 'kk-content-exporter' ), String( row.data.max_length ) ) );
			}
			if ( true === options.raw ) {
				active.push( __( 'raw content', 'kk-content-exporter' ) );
			} else if ( true === options.shortcodes ) {
				active.push( __( 'shortcodes run', 'kk-content-exporter' ) );
			}
			if ( ! active.length ) {
				return __( 'Advanced options', 'kk-content-exporter' );
			}
			/* translators: %s: advanced options set in a row, separated by commas, for example "maximum length 200, raw content". */
			return sprintf( __( 'Advanced options (%s)', 'kk-content-exporter' ), active.join( _x( ', ', 'separator of a list', 'kk-content-exporter' ) ) );
		}

		/**
		 * Advanced options of a row in a section that can be opened (closed at first, its title sums up what is set):
		 * the maximum length of a plain text, and the options of the fields that accept them (raw content, shortcodes),
		 * with their warnings. Opening or closing it is not a change of the feed.
		 *
		 * @param {Object}  row        Row.
		 * @param {boolean} textOutput Whether the row gives a plain text (the maximum length applies).
		 * @return {HTMLElement|null} The section, or null when the row has no advanced option.
		 */
		function renderAdvanced( row, textOutput ) {
			// Only field rows have options of their fields.
			const accepted = 'field' === row.data.kind ? acceptedOptions( row.data ) : [];
			const options = row.data.options || {};
			const prefix = 'kkce-mapping-' + row.uid;
			const body = el( 'div', { className: 'kkce-mapping-options' } );

			if ( textOutput ) {
				const hint = el( 'span', { id: prefix + '-max-length-help', className: 'description' }, __( 'Characters; empty: no limit.', 'kk-content-exporter' ) );
				const field = labelled( LABEL_MAX_LENGTH, el( 'input', {
					type: 'number',
					id: prefix + '-max-length',
					className: 'small-text kkce-mapping-max-length',
					min: 1,
					max: data.maxLengthLimit,
					step: 1,
					value: undefined === row.data.max_length ? '' : String( row.data.max_length ),
					ariaDescribedby: hint.id,
					'data-uid': String( row.uid ),
				} ) );
				field.appendChild( hint );
				body.appendChild( field );
			}

			if ( -1 !== accepted.indexOf( 'raw' ) ) {
				const raw = el( 'input', { type: 'checkbox', id: prefix + '-raw', checked: true === options.raw, 'data-uid': String( row.uid ), 'data-option': 'raw', ariaDescribedby: prefix + '-raw-help' } );
				const label = el( 'label', { htmlFor: raw.id } );
				label.appendChild( raw );
				label.appendChild( document.createTextNode( ' ' + __( 'Raw content', 'kk-content-exporter' ) ) );
				body.appendChild( label );
				body.appendChild( el( 'p', { id: prefix + '-raw-help', className: 'description' }, __( 'The text as stored in the database: blocks are not turned into HTML and links stay as they are.', 'kk-content-exporter' ) ) );
			}

			if ( -1 !== accepted.indexOf( 'shortcodes' ) ) {
				const shortcodes = el( 'input', { type: 'checkbox', id: prefix + '-shortcodes', checked: true === options.shortcodes, disabled: true === options.raw, 'data-uid': String( row.uid ), 'data-option': 'shortcodes', ariaDescribedby: prefix + '-shortcodes-help' } );
				const label = el( 'label', { htmlFor: shortcodes.id } );
				label.appendChild( shortcodes );
				label.appendChild( document.createTextNode( ' ' + __( 'Run the shortcodes', 'kk-content-exporter' ) ) );
				body.appendChild( label );
				body.appendChild( el( 'p', { id: prefix + '-shortcodes-help', className: 'description kkce-mapping-warning' }, true === options.raw
					? __( 'Not used with the raw content.', 'kk-content-exporter' )
					: __( 'Off: shortcodes are removed. On: the shortcodes of other plugins run while the feed is read, as for a visitor; they can add content you do not expect or slow the feed down. Turn it on only for shortcodes you know.', 'kk-content-exporter' ) ) );
			}

			if ( ! body.childNodes.length ) {
				return null;
			}
			const section = el( 'details', { className: 'kkce-mapping-advanced', open: true === row.advancedOpen } );
			section.appendChild( el( 'summary', {}, advancedTitle( row, textOutput ) ) );
			section.appendChild( body );
			// The toggle event does not bubble: the state is kept here, so a redraw leaves the section as it was.
			section.addEventListener( 'toggle', function () {
				row.advancedOpen = section.open;
			} );
			return section;
		}

		/**
		 * Confirmations of the sensitive fields of a row, with their warnings: always visible, outside the advanced
		 * options.
		 *
		 * @param {Object} row Row.
		 * @return {HTMLElement|null} The confirmations.
		 */
		function renderConfirmations( row ) {
			const prefix = 'kkce-mapping-' + row.uid;
			const wrapper = el( 'div', { className: 'kkce-mapping-options' } );

			const confirmed = Array.isArray( row.data.confirmed ) ? row.data.confirmed : [];
			rowSources( row.data ).forEach( function ( id, index ) {
				const source = findSource( id );
				if ( ! source || ! source.needs_confirmation ) {
					return;
				}
				const warningId = prefix + '-confirm-' + index + '-help';
				let text = __( 'The author archive URL usually contains the login name of the author: it is exported only if you confirm it here.', 'kk-content-exporter' );
				if ( 'core.author.url' !== source.id ) {
					/* translators: %s: label of a field whose name looks sensitive or personal. */
					text = sprintf( __( '%s looks like sensitive or personal data: in a public feed anyone can read it. It is exported only if you confirm it here.', 'kk-content-exporter' ), source.label );
				}
				wrapper.appendChild( el( 'p', { id: warningId, className: 'kkce-mapping-warning' }, text ) );
				const box = el( 'input', { type: 'checkbox', id: prefix + '-confirm-' + index, checked: -1 !== confirmed.indexOf( id ), 'data-uid': String( row.uid ), 'data-confirm': id, ariaDescribedby: warningId } );
				const label = el( 'label', { htmlFor: box.id } );
				label.appendChild( box );
				/* translators: %s: label of a field whose name looks sensitive or personal. */
				label.appendChild( document.createTextNode( ' ' + sprintf( __( 'I understand, publish this value (%s)', 'kk-content-exporter' ), source.label ) ) );
				wrapper.appendChild( label );
			} );

			return wrapper.childNodes.length ? wrapper : null;
		}

		/**
		 * Puts in a slot the warnings and the confirmations of the fields of a row, replacing what it held.
		 *
		 * @param {HTMLElement} slot Element that holds them.
		 * @param {Object}      row  Row.
		 */
		function fillWarnings( slot, row ) {
			slot.textContent = '';
			const warnings = renderConfirmations( row );
			if ( warnings ) {
				slot.appendChild( warnings );
			}
		}

		/**
		 * Appends to a row the control of every scope (`*` first, then the exceptions) and the picker that adds
		 * an exception: the part shared by every rule.
		 *
		 * @param {HTMLElement} fieldset    Element of the row.
		 * @param {Object}      row         Row.
		 * @param {Function}    renderScope Builds the control of one scope: renderChain, renderTemplate, renderInput
		 *                                  or renderValue.
		 */
		function appendScopes( fieldset, row, renderScope ) {
			scopesOf( scopeMap( row.data ) ).forEach( function ( scope ) {
				fieldset.appendChild( renderScope( row, scope ) );
			} );
			appendIfAny( fieldset, renderExceptionPicker( row ) );
		}

		/**
		 * Button of a row with an icon only; its name is given by `ariaLabel`.
		 *
		 * @param {Object} row    Row.
		 * @param {string} action Action.
		 * @param {string} icon   Dashicons class.
		 * @param {Object} extra  Other properties (id, ariaLabel, disabled, className).
		 * @return {HTMLButtonElement} The button.
		 */
		function iconButton( row, action, icon, extra ) {
			const node = button( row, action, '', Object.assign( { className: 'button kkce-mapping-icon' }, extra ) );
			node.appendChild( el( 'span', { className: 'dashicons ' + icon, ariaHidden: 'true' } ) );
			return node;
		}

		/**
		 * Label of a source for the summary of a row.
		 *
		 * @param {string} id Source identifier.
		 * @return {string} Label, or the identifier when the catalog does not offer it.
		 */
		function sourceLabel( id ) {
			const source = findSource( id );
			return source ? source.label : id;
		}

		/**
		 * One-line summary of a row, shown in its header: key, what it writes, output type, and a mark of the
		 * exceptions and of the advanced options set, for example "title ← Title · Automatic".
		 *
		 * @param {Object} row Row.
		 * @return {string} Summary.
		 */
		function rowSummary( row ) {
			const item = row.data;
			const key = String( item.key || '' ) || __( '(no key)', 'kk-content-exporter' );
			let what = '—';
			if ( ! isEditable( item ) ) {
				what = __( 'kept unchanged', 'kk-content-exporter' );
			} else if ( 'field' === item.kind ) {
				const chain = ( item.sources[ '*' ] || [] ).filter( Boolean ).map( sourceLabel );
				what = chain.length ? chain.join( _x( ', ', 'separator of a list', 'kk-content-exporter' ) ) : '—';
			} else if ( 'fixed' === item.kind ) {
				const value = item.values[ '*' ];
				/* translators: %s: fixed value written by a row, between quotes. */
				what = undefined === value || '' === value ? '—' : sprintf( __( '"%s"', 'kk-content-exporter' ), String( value ) );
			} else if ( 'template' === item.kind ) {
				const text = String( item.templates[ '*' ] || '' );
				what = '' === text ? '—' : ( text.length > 40 ? text.slice( 0, 39 ) + '…' : text );
			} else if ( 'lookup' === item.kind || 'condition' === item.kind ) {
				const input = item.inputs[ '*' ] ? sourceLabel( item.inputs[ '*' ] ) : '—';
				what = 'lookup' === item.kind
					/* translators: %s: field read by a lookup table. */
					? sprintf( __( 'Lookup table (%s)', 'kk-content-exporter' ), input )
					/* translators: %s: field checked by a condition. */
					: sprintf( __( 'Condition (%s)', 'kk-content-exporter' ), input );
			}
			const isTemplate = 'template' === item.kind;
			const type = isTemplate ? ( 'html' === item.type ? 'html' : 'string' ) : ( item.type || 'auto' );
			const typeLabel = ( TYPES.find( function ( entry ) {
				return entry[ 0 ] === type;
			} ) || [ type, type ] )[ 1 ];
			/* translators: 1: output key of a row, 2: what the row writes, for example a field. */
			const parts = [ sprintf( __( '%1$s ← %2$s', 'kk-content-exporter' ), key, what ), typeLabel ];
			if ( isEditable( item ) && hasExceptions( item ) ) {
				const count = scopesOf( scopeMap( item ) ).length - 1;
				/* translators: %d: number of exceptions for single post types of a row. */
				parts.push( sprintf( _n( '%d exception', '%d exceptions', count, 'kk-content-exporter' ), count ) );
			}
			const options = item.options || {};
			if ( undefined !== item.max_length || true === options.raw || true === options.shortcodes ) {
				parts.push( __( 'advanced options', 'kk-content-exporter' ) );
			}
			return parts.join( _x( ' · ', 'separator of the parts of a summary', 'kk-content-exporter' ) );
		}

		/**
		 * Shows the open or closed state of a row in the page without drawing it again.
		 *
		 * @param {Object} row Row.
		 */
		function showExpanded( row ) {
			const body = document.getElementById( 'kkce-mapping-' + row.uid + '-body' );
			const toggle = document.getElementById( 'kkce-mapping-' + row.uid + '-toggle' );
			if ( ! body || ! toggle ) {
				return;
			}
			body.hidden = !! row.collapsed;
			toggle.setAttribute( 'aria-expanded', row.collapsed ? 'false' : 'true' );
			const icon = toggle.querySelector( '.dashicons' );
			icon.classList.toggle( 'dashicons-arrow-right-alt2', !! row.collapsed );
			icon.classList.toggle( 'dashicons-arrow-down-alt2', ! row.collapsed );
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

			// Header: the summary opens and closes the row (interface state, not a change of the feed), then the
			// actions on the whole row, as icons with an accessible name.
			const header = el( 'div', { className: 'kkce-mapping-header' } );
			const toggle = el( 'button', {
				type: 'button',
				id: prefix + '-toggle',
				className: 'button-link kkce-mapping-toggle',
				ariaExpanded: row.collapsed ? 'false' : 'true',
				ariaControls: prefix + '-body',
				'data-uid': String( row.uid ),
				'data-action': 'toggle',
			} );
			toggle.appendChild( el( 'span', { className: 'dashicons ' + ( row.collapsed ? 'dashicons-arrow-right-alt2' : 'dashicons-arrow-down-alt2' ), ariaHidden: 'true' } ) );
			toggle.appendChild( el( 'span', { className: 'kkce-mapping-summary' }, rowSummary( row ) ) );
			header.appendChild( toggle );
			const actions = el( 'div', { className: 'kkce-mapping-actions' } );
			/* translators: %d: position of the row. */
			actions.appendChild( iconButton( row, 'up', 'dashicons-arrow-up-alt', { id: prefix + '-up', disabled: 0 === index, ariaLabel: sprintf( __( 'Move up: row %d', 'kk-content-exporter' ), index + 1 ) } ) );
			/* translators: %d: position of the row. */
			actions.appendChild( iconButton( row, 'down', 'dashicons-arrow-down-alt', { id: prefix + '-down', disabled: index === rows.length - 1, ariaLabel: sprintf( __( 'Move down: row %d', 'kk-content-exporter' ), index + 1 ) } ) );
			/* translators: %d: position of the row. */
			actions.appendChild( iconButton( row, 'remove', 'dashicons-trash', { id: prefix + '-remove', className: 'button button-link-delete kkce-mapping-icon', ariaLabel: sprintf( __( 'Remove row %d', 'kk-content-exporter' ), index + 1 ) } ) );
			header.appendChild( actions );
			fieldset.appendChild( header );

			// Closed controls are hidden, so they cannot take the focus.
			const body = el( 'div', { className: 'kkce-mapping-body', id: prefix + '-body', hidden: !! row.collapsed } );
			fieldset.appendChild( body );
			fields.appendChild( labelled( __( 'JSON key', 'kk-content-exporter' ), key ) );
			if ( editable ) {
				fields.appendChild( labelled( __( 'Rule', 'kk-content-exporter' ), select( { id: prefix + '-kind', className: 'kkce-mapping-kind', 'data-uid': String( row.uid ) }, KINDS, row.data.kind ) ) );
			}
			body.appendChild( fields );
			body.appendChild( error );

			// The output type closes the row, after the configuration of the rule: key → rule → configuration → output.
			const output = el( 'div', { className: 'kkce-mapping-fields kkce-mapping-output' } );
			// A template always gives a text: plain or HTML.
			const isTemplate = 'template' === row.data.kind;
			const types = isTemplate ? TYPES.filter( function ( type ) {
				return 'string' === type[ 0 ] || 'html' === type[ 0 ];
			} ) : TYPES;
			const type = isTemplate ? ( 'html' === row.data.type ? 'html' : 'string' ) : ( row.data.type || 'auto' );
			output.appendChild( labelled( __( 'Output type', 'kk-content-exporter' ), select( { id: prefix + '-type', className: 'kkce-mapping-type', 'data-uid': String( row.uid ) }, types, type ) ) );
			const textOutput = editable && hasTextOutput( Object.assign( {}, row.data, { type: type } ) );

			if ( ! editable ) {
				body.appendChild( el( 'p', { className: 'kkce-mapping-kept' }, __( 'This rule cannot be edited here yet and is kept unchanged.', 'kk-content-exporter' ) ) );
			} else if ( 'field' === row.data.kind ) {
				appendScopes( body, row, renderChain );
			} else if ( 'template' === row.data.kind ) {
				body.appendChild( el( 'p', { id: prefix + '-template-help', className: 'description' }, __( 'Write the text and put the fields between braces, for example {core.title}. {a|b|"text"} uses the first one with a value; a part between square brackets, such as {meta.city}[, {meta.address}], is written only when all its fields have a value; write {{, }}, [[ and ]] for a brace or a bracket. "Insert field" writes a field where the cursor is.', 'kk-content-exporter' ) ) );
				appendScopes( body, row, renderTemplate );
			} else if ( 'lookup' === row.data.kind || 'condition' === row.data.kind ) {
				// Same inputs for both rules; only the description and the table or the check differ.
				const isLookup = 'lookup' === row.data.kind;
				body.appendChild( el( 'p', { className: 'description' }, isLookup
					? __( 'The value of the field is compared with the lines of the table, exactly (capital letters included, spaces at the ends ignored); the first line that matches gives the output. Lists cannot be read.', 'kk-content-exporter' )
					: __( 'One check on one field: when it is true the first output is written, otherwise the second. Values are compared exactly (capital letters included, spaces at the ends ignored); "contains" looks for a text inside the value, or for one element of a list, such as the categories; lists can be checked only with "is empty", "is not empty" and "contains".', 'kk-content-exporter' ) ) );
				appendScopes( body, row, renderInput );
				body.appendChild( isLookup ? renderCases( row ) : renderCheck( row ) );
			} else {
				appendScopes( body, row, renderValue );
			}

			body.appendChild( output );
			if ( editable ) {
				appendIfAny( body, renderAdvanced( row, textOutput ) );
				// Redrawn alone while a template is typed (see the input handler), so the caret stays.
				const slot = el( 'div', { className: 'kkce-mapping-warnings-slot' } );
				fillWarnings( slot, row );
				body.appendChild( slot );
			}

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
				if ( '' !== message && row.collapsed ) {
					row.collapsed = false;
					showExpanded( row );
				}
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
			bulk.hidden = rows.length < 2;
			addButton.disabled = rows.length >= data.maxRows;
			/* translators: %d: maximum number of rows of a mapping. */
			limit.textContent = sprintf( __( 'A mapping can have at most %d rows.', 'kk-content-exporter' ), data.maxRows );
			limit.hidden = rows.length < data.maxRows;
			preview.setVisible( rows.length > 0 );
			checkKeys();
			if ( metaTarget ) {
				const target = document.getElementById( metaTarget.id );
				if ( target ) {
					target.closest( '.kkce-mapping-field' ).after( renderMetaForm() );
				} else {
					// Its field is gone (row removed, rule changed): the form goes too.
					metaTarget = null;
				}
			}

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
				return { uid: nextId++, data: withGeneralScope( JSON.parse( JSON.stringify( row ) ) ), autoKey: row.key, collapsed: false };
			} );
		}

		[ [ expandAll, false ], [ collapseAll, true ] ].forEach( function ( pair ) {
			// Opening or closing the rows is not a change of the feed: no commit.
			pair[ 0 ].addEventListener( 'click', function () {
				rows.forEach( function ( row ) {
					row.collapsed = pair[ 1 ];
				} );
				render( pair[ 0 ].id );
			} );
		} );

		addButton.addEventListener( 'click', function () {
			if ( rows.length >= data.maxRows ) {
				return;
			}
			const row = { uid: nextId++, data: { key: '', kind: 'field', type: 'auto', sources: { '*': [] } }, autoKey: '', collapsed: false };
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
				// The next row may be closed: its header takes the focus.
				render( next ? 'kkce-mapping-' + next.uid + '-toggle' : '' );
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
		 * Actions on the lines of the table of a `lookup` row: add, move and remove.
		 *
		 * @param {Object} row    Row.
		 * @param {string} action Action.
		 * @param {number} index  Position of the line.
		 */
		function caseAction( row, action, index ) {
			const cases = row.data.cases;
			const base = 'kkce-mapping-' + row.uid + '-case-';

			if ( 'case-add' === action ) {
				if ( cases.length >= data.maxCases ) {
					return;
				}
				cases.push( { match: '', output: { fixed: '' } } );
				commit( true );
				render( base + ( cases.length - 1 ) + '-match' );
				announce( __( 'Line added to the table.', 'kk-content-exporter' ) );
				return;
			}

			if ( 'case-remove' === action ) {
				cases.splice( index, 1 );
				commit( true );
				render( cases.length ? base + Math.min( index, cases.length - 1 ) + '-match' : 'kkce-mapping-' + row.uid + '-case-add' );
				announce( __( 'Line removed from the table.', 'kk-content-exporter' ) );
				return;
			}

			const up = 'case-up' === action;
			const target = up ? index - 1 : index + 1;
			if ( target < 0 || target >= cases.length ) {
				return;
			}
			cases.splice( target, 0, cases.splice( index, 1 )[ 0 ] );
			commit( true );
			const atEdge = up ? 0 === target : cases.length - 1 === target;
			render( base + target + '-' + ( up !== atEdge ? 'up' : 'down' ) );
			/* translators: %d: new position of the moved line in the table of a lookup rule. */
			announce( sprintf( __( 'Line moved to position %d of the table.', 'kk-content-exporter' ), target + 1 ) );
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
			const map = scopeMap( row.data );

			if ( 'case-add' === action || 'case-remove' === action || 'case-up' === action || 'case-down' === action ) {
				caseAction( row, action, index );
				return;
			}

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

			// Opening or closing the picker changes nothing of the feed: no commit, so no unsaved change.
			if ( 'scope-open' === action || 'scope-cancel' === action ) {
				row.pickerOpen = 'scope-open' === action;
				render( prefix + ( row.pickerOpen ? '-exception' : '-exception-open' ) );
				return;
			}

			if ( 'scope-add' === action ) {
				const picker = document.getElementById( prefix + '-exception' );
				const postType = picker ? picker.value : '';
				if ( ! postType || Object.prototype.hasOwnProperty.call( map, postType ) ) {
					return;
				}
				map[ postType ] = isField ? [] : '';
				row.pickerOpen = false;
				commit( true );
				const focus = { field: '-0-source', template: '-template', lookup: '-input', condition: '-input' }[ row.data.kind ] || '-value';
				render( prefix + '-' + scopeId( postType ) + focus );
				/* translators: %s: name of the exception, for example "For Posts". */
				announce( sprintf( __( 'Exception added: %s.', 'kk-content-exporter' ), scopeLabel( postType ) ) );
				return;
			}

			if ( 'scope-remove' === action ) {
				const label = scopeLabel( scope );
				delete map[ scope ];
				commit( true );
				render( '' );
				// The post type is free again, so the control that adds an exception exists: it takes the focus.
				const target = document.getElementById( prefix + '-exception-open' ) || document.getElementById( prefix + '-exception' ) || document.getElementById( prefix + '-key' );
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
			if ( 'toggle' === action ) {
				// Interface state only: no commit, so no unsaved change.
				rows[ index ].collapsed = ! rows[ index ].collapsed;
				showExpanded( rows[ index ] );
				return;
			}
			if ( 'up' === action || 'down' === action || 'remove' === action ) {
				rowAction( action, index );
				return;
			}
			scopeAction( rows[ index ], action, control.getAttribute( 'data-scope' ) || '', parseInt( control.getAttribute( 'data-index' ) || '0', 10 ) );
		} );

		list.addEventListener( 'input', function ( event ) {
			const input = event.target;
			if ( OTHER_META === input.value ) {
				// Not a change of the feed: the page must not warn about it.
				event.stopPropagation();
				return;
			}
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
				input.closest( 'li' ).querySelector( '.kkce-mapping-summary' ).textContent = rowSummary( row );
				checkKeys();
				return;
			}

			if ( input.classList.contains( 'kkce-mapping-fixed' ) ) {
				row.data.values[ input.getAttribute( 'data-scope' ) ] = input.value;
				commit( true );
			}

			if ( input.classList.contains( 'kkce-mapping-max-length' ) ) {
				// Empty: no limit. A value that is not a whole number is sent as it is, so the server reports it.
				if ( '' === input.value.trim() ) {
					delete row.data.max_length;
				} else {
					row.data.max_length = /^[0-9]+$/.test( input.value.trim() ) ? parseInt( input.value.trim(), 10 ) : input.value;
				}
				commit( true );
				// The row is not drawn again (the caret stays): only the title of the advanced options follows.
				const title = input.closest( 'details' ) && input.closest( 'details' ).querySelector( 'summary' );
				if ( title ) {
					title.textContent = advancedTitle( row, true );
				}
			}

			if ( input.classList.contains( 'kkce-mapping-template' ) ) {
				const before = rowSources( row.data ).join( ' ' );
				row.data.templates[ input.getAttribute( 'data-scope' ) ] = input.value;
				commit( true );
				// A field typed by hand can need a confirmation: redraw the warnings of the row, not the row.
				const slot = input.closest( 'li' ).querySelector( '.kkce-mapping-warnings-slot' );
				if ( slot && rowSources( row.data ).join( ' ' ) !== before ) {
					fillWarnings( slot, row );
				}
			}

			if ( input.classList.contains( 'kkce-mapping-match' ) ) {
				row.data.cases[ parseInt( input.getAttribute( 'data-index' ), 10 ) ].match = input.value;
				commit( true );
			}

			if ( input.classList.contains( 'kkce-mapping-output-fixed' ) ) {
				setOutput( row, input.getAttribute( 'data-index' ), { fixed: input.value } );
				commit( true );
			}

			if ( input.classList.contains( 'kkce-mapping-matches' ) ) {
				// Kept as typed (one text per line for "is one of"): the hidden field gets them trimmed.
				row.data.matches = 'TEXTAREA' === input.tagName ? input.value.split( '\n' ) : [ input.value ];
				const warning = document.getElementById( input.id + '-error' );
				if ( warning ) {
					showLineError( warning, row.data.matches );
				}
				commit( true );
			}

			// The summary in the header follows what is typed (fixed value, template, maximum length).
			const summary = input.closest( 'li' ).querySelector( '.kkce-mapping-summary' );
			if ( summary ) {
				summary.textContent = rowSummary( row );
			}
		} );

		/**
		 * Writes an output of a row: a line of a table (by its position), or a named output (`default`, `then`,
		 * `else`).
		 *
		 * @param {Object} row      Row.
		 * @param {string} position Position of the line, or name of the output.
		 * @param {Object} output   Output.
		 */
		function setOutput( row, position, output ) {
			if ( /^[0-9]+$/.test( position ) ) {
				row.data.cases[ parseInt( position, 10 ) ].output = output;
			} else {
				row.data[ position ] = output;
			}
		}

		list.addEventListener( 'change', function ( event ) {
			const control = event.target;
			const index = indexOf( control.getAttribute( 'data-uid' ) || '' );
			if ( -1 === index ) {
				return;
			}
			const row = rows[ index ];

			if ( OTHER_META === control.value ) {
				// Opens the form under this list; the list keeps its value and the focus (arrow keys choose at once on
				// some systems, so the focus is not moved). Not a change of the feed.
				event.stopPropagation();
				metaTarget = {
					id: control.id,
					uid: row.uid,
					kind: [ 'kkce-mapping-source', 'kkce-mapping-input', 'kkce-mapping-output-source' ].find( function ( name ) {
						return control.classList.contains( name );
					} ),
					scope: control.getAttribute( 'data-scope' ) || '',
					index: control.getAttribute( 'data-index' ) || '0',
				};
				metaMessage = '';
				metaTyped = '';
				render( control.id );
				announce( __( 'Type the post meta key below the field.', 'kk-content-exporter' ) );
				return;
			}

			if ( control.classList.contains( 'kkce-mapping-key' ) ) {
				// Announced when the field is left, not at every key: the message is also linked to the field.
				const error = document.getElementById( 'kkce-mapping-' + row.uid + '-key-error' );
				if ( error && ! error.hidden && '' !== error.textContent ) {
					/* translators: 1: position of the row, 2: error message about its key. */
					announce( sprintf( __( 'Error in row %1$d: %2$s', 'kk-content-exporter' ), index + 1, error.textContent ) );
				}
				return;
			}

			// Text fields are written while typing (input event): leaving them changes nothing.
			if ( [ 'kkce-mapping-fixed', 'kkce-mapping-template', 'kkce-mapping-match', 'kkce-mapping-output-fixed', 'kkce-mapping-matches', 'kkce-mapping-max-length' ].some( function ( name ) {
				return control.classList.contains( name );
			} ) ) {
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
					lookup: { kind: 'lookup', inputs: { '*': '' }, cases: [ { match: '', output: { fixed: '' } } ] },
					condition: { kind: 'condition', inputs: { '*': '' }, operator: 'not_empty', then: { input: true } },
				};
				const next = ( row.stash && row.stash[ control.value ] ) || blank[ control.value ];
				row.stash = Object.assign( {}, row.stash, { [ previous.kind ]: previous } );
				// The key and the output type belong to the row, not to the rule: they follow the change.
				next.key = previous.key;
				next.type = previous.type || 'auto';
				if ( undefined !== previous.max_length ) {
					next.max_length = previous.max_length;
				} else {
					delete next.max_length;
				}
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
			} else if ( control.classList.contains( 'kkce-mapping-input' ) ) {
				const scope = control.getAttribute( 'data-scope' );
				row.data.inputs[ scope ] = control.value;
				if ( '*' === scope && '' !== control.value ) {
					proposeKey( row, control.value );
				}
			} else if ( control.classList.contains( 'kkce-mapping-output-kind' ) ) {
				row.data.cases[ parseInt( control.getAttribute( 'data-index' ), 10 ) ].output = 'source' === control.value ? { source: '' } : { fixed: '' };
			} else if ( control.classList.contains( 'kkce-mapping-output-source' ) ) {
				setOutput( row, control.getAttribute( 'data-index' ), { source: control.value } );
			} else if ( control.classList.contains( 'kkce-mapping-default-kind' ) ) {
				const name = control.getAttribute( 'data-index' ) || 'default';
				const choices = { fixed: { fixed: '' }, source: { source: '' }, input: { input: true } };
				if ( choices[ control.value ] ) {
					row.data[ name ] = choices[ control.value ];
				} else {
					delete row.data[ name ];
				}
			} else if ( control.classList.contains( 'kkce-mapping-operator' ) ) {
				row.data.operator = control.value;
			} else if ( control.hasAttribute( 'data-confirm' ) ) {
				const id = control.getAttribute( 'data-confirm' );
				const list = ( Array.isArray( row.data.confirmed ) ? row.data.confirmed : [] ).filter( function ( item ) {
					return item !== id;
				} );
				if ( control.checked ) {
					list.push( id );
				}
				row.data.confirmed = list;
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
		 * Form of a post meta key that is not in the lists, under the list of fields that opened it. Typing in it is
		 * not a change of the feed; using the key chooses it in that list.
		 *
		 * @return {HTMLElement} The form.
		 */
		function renderMetaForm() {
			const form = el( 'div', { className: 'kkce-mapping-meta' } );
			const input = el( 'input', { type: 'text', id: 'kkce-meta-key', className: 'regular-text kkce-mapping-meta-key', autocomplete: 'off', spellcheck: false, maxLength: data.metaKeyMaxLength || 191, value: metaTyped, ariaDescribedby: 'kkce-meta-key-help kkce-meta-message' } );
			const use = el( 'button', { type: 'button', className: 'button', id: 'kkce-meta-use' }, __( 'Use this key', 'kk-content-exporter' ) );
			const cancel = el( 'button', { type: 'button', className: 'button-link', id: 'kkce-meta-cancel' }, __( 'Cancel', 'kk-content-exporter' ) );
			form.appendChild( el( 'label', { htmlFor: input.id }, __( 'Post meta key', 'kk-content-exporter' ) ) );
			form.appendChild( input );
			form.appendChild( use );
			form.appendChild( cancel );
			form.appendChild( el( 'p', { className: 'description', id: 'kkce-meta-key-help' }, __( 'For a custom field that is not in the lists. If the item has no such field, the value is null.', 'kk-content-exporter' ) ) );
			form.appendChild( el( 'p', { className: 'description kkce-mapping-meta-message', id: 'kkce-meta-message', role: 'status' }, metaMessage ) );
			// The key typed is not a change of the feed until it is used: the page must not warn about it.
			[ 'input', 'change' ].forEach( function ( type ) {
				input.addEventListener( type, function ( event ) {
					event.stopPropagation();
					metaTyped = input.value;
				} );
			} );
			input.addEventListener( 'keydown', function ( event ) {
				if ( 'Enter' === event.key ) {
					// Enter must not save the feed.
					event.preventDefault();
					addMetaKey( input.value );
				}
			} );
			use.addEventListener( 'click', function () {
				addMetaKey( input.value );
			} );
			cancel.addEventListener( 'click', function () {
				const back = metaTarget.id;
				metaTarget = null;
				render( back );
			} );
			return form;
		}

		/**
		 * Shows a message in the form of a post meta key. The element has `role="status"`, so screen readers
		 * announce it by themselves.
		 *
		 * @param {string} message Message.
		 */
		function showMetaMessage( message ) {
			metaMessage = message;
			const node = document.getElementById( 'kkce-meta-message' );
			if ( node ) {
				node.textContent = message;
			}
		}

		/**
		 * Reports the result of a typed meta key once the catalog has answered: the server refuses the keys that
		 * cannot be a source (internal keys, keys with spaces or other special characters). An accepted key is
		 * chosen in the list of fields that opened the form.
		 *
		 * @param {string[]} refused Keys the server did not list.
		 * @return {string} Id of the element that takes the focus, or an empty string.
		 */
		function reportMetaKeys( refused ) {
			metaKeys = metaKeys.filter( function ( key ) {
				return -1 === refused.indexOf( key );
			} );
			if ( refused.length ) {
				/* translators: %s: meta keys that cannot be used, separated by commas. */
				showMetaMessage( sprintf( __( 'This key cannot be used: %s. Keys of WordPress and of this plugin, and keys with spaces or special characters, are not allowed.', 'kk-content-exporter' ), refused.join( ', ' ) ) );
				pendingMetaKey = '';
				return metaTarget ? 'kkce-meta-key' : '';
			}
			if ( '' === pendingMetaKey || ! metaTarget ) {
				return '';
			}
			const id = 'meta.' + pendingMetaKey;
			const row = rows[ indexOf( String( metaTarget.uid ) ) ];
			const focus = metaTarget.id;
			if ( row && 'kkce-mapping-source' === metaTarget.kind ) {
				row.data.sources[ metaTarget.scope ][ parseInt( metaTarget.index, 10 ) ] = id;
				if ( '0' === metaTarget.index ) {
					proposeKey( row, id );
				}
			} else if ( row && 'kkce-mapping-input' === metaTarget.kind ) {
				row.data.inputs[ metaTarget.scope ] = id;
				if ( '*' === metaTarget.scope ) {
					proposeKey( row, id );
				}
			} else if ( row && 'kkce-mapping-output-source' === metaTarget.kind ) {
				setOutput( row, metaTarget.index, { source: id } );
			}
			/* translators: %s: meta key. */
			announce( sprintf( __( 'Field added: %s.', 'kk-content-exporter' ), pendingMetaKey ) );
			metaTarget = null;
			metaMessage = '';
			metaTyped = '';
			pendingMetaKey = '';
			commit( true );
			return focus;
		}

		/**
		 * Adds a key typed by the administrator to the lists of fields: the catalog is asked again with it.
		 *
		 * @param {string} value Typed key.
		 */
		function addMetaKey( value ) {
			const key = String( value ).trim();
			if ( '' === key ) {
				showMetaMessage( __( 'Type the post meta key first.', 'kk-content-exporter' ) );
				return;
			}
			if ( ! catalog.post_types.length ) {
				showMetaMessage( __( 'Check at least one post type of the feed first.', 'kk-content-exporter' ) );
				return;
			}
			if ( -1 === metaKeys.indexOf( key ) ) {
				metaKeys.push( key );
			}
			pendingMetaKey = key;
			loadCatalog( catalog.post_types );
		}

		/**
		 * Meta keys that the mapping uses and the catalog does not list: the keys of a saved mapping that are not
		 * among the keys in use, so the lists of fields still show them.
		 *
		 * @return {string[]} Keys.
		 */
		function unlistedMetaKeys() {
			const found = [];
			rows.forEach( function ( row ) {
				rowSources( row.data ).forEach( function ( id ) {
					if ( 0 === id.indexOf( 'meta.' ) && null === findSource( id ) && -1 === found.indexOf( id.slice( 5 ) ) ) {
						found.push( id.slice( 5 ) );
					}
				} );
			} );
			return found;
		}

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
			} ).concat( metaKeys.map( function ( key ) {
				return 'meta_keys[]=' + encodeURIComponent( key );
			} ) ).join( '&' );

			wp.apiFetch( { path: data.catalogPath + '?' + query } ).then( function ( response ) {
				if ( current !== request ) {
					return;
				}
				catalog = response;
				const focus = reportMetaKeys( response.refused_meta_keys || [] );
				proposeRows();
				commit( false );
				render( focus || ( document.activeElement && container.contains( document.activeElement ) ? document.activeElement.id : '' ) );
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
		metaKeys = unlistedMetaKeys();
		if ( metaKeys.length && catalog.post_types.length ) {
			// Keys of the saved mapping that are not among the keys in use: ask for a catalog that lists them.
			loadCatalog( catalog.post_types );
		}
		if ( data.draft && data.draft.readable ) {
			// A refused mapping shown again is not saved: leaving the page asks for confirmation. Sent after the
			// other scripts of the page have bound their listeners.
			touched = true;
			window.setTimeout( function () {
				field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} );
		}
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
		if ( ! data || ! data.catalog || ! Array.isArray( data.catalog.sources ) || ! data.field || ! data.keyPattern || ! data.maxChain || ! data.valueMaxLength || ! data.previewPath || ! data.itemsPath || ! data.templateMaxLength || ! data.maxCases || ! data.maxLengthLimit ) {
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
