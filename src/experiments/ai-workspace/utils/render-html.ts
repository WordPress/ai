/**
 * Reads post markup, via an inert `DOMParser` document and an allowlist, into the markdown renderer's node tree.
 *
 * Anything that would render, play or load on the live site is described as text, so approvers see what they approve.
 * Images are never fetched; links are live only on this site's host.
 */

/**
 * Internal dependencies
 */
import {
	isLiveDestination,
	type BlockNode,
	type HeadingLevel,
	type InlineNode,
	type MarkdownOptions,
} from './render-markdown';

/**
 * Elements whose content is never shown, not even as text.
 */
const SKIPPED = [
	'script',
	'style',
	'template',
	'noscript',
	'head',
	'title',
	'meta',
	'link',
];

/**
 * Elements described in place rather than drawn; URL attributes are shown as text, never fetched.
 */
const WITHHELD: Record<
	string,
	{ kind: 'media' | 'embed' | 'control'; attributes: string[] }
> = {
	video: {
		kind: 'media',
		attributes: [
			'src',
			'poster',
			'autoplay',
			'loop',
			'muted',
			'controls',
		],
	},
	audio: {
		kind: 'media',
		attributes: [ 'src', 'autoplay', 'loop', 'muted', 'controls' ],
	},
	source: { kind: 'media', attributes: [ 'src', 'srcset', 'type' ] },
	track: { kind: 'media', attributes: [ 'src', 'kind', 'srclang' ] },
	iframe: { kind: 'embed', attributes: [ 'src', 'srcdoc' ] },
	object: { kind: 'embed', attributes: [ 'data', 'type' ] },
	embed: { kind: 'embed', attributes: [ 'src', 'type' ] },
	svg: { kind: 'embed', attributes: [] },
	math: { kind: 'embed', attributes: [] },
	canvas: { kind: 'embed', attributes: [] },
	button: {
		kind: 'control',
		attributes: [ 'type', 'name', 'value', 'formaction' ],
	},
	textarea: { kind: 'control', attributes: [ 'name', 'placeholder' ] },
	select: { kind: 'control', attributes: [ 'name' ] },
	input: {
		kind: 'control',
		attributes: [
			'type',
			'name',
			'value',
			'placeholder',
			'src',
			'formaction',
		],
	},
	form: { kind: 'control', attributes: [ 'action', 'method' ] },
};

/**
 * Attributes that are shown by name only: their value is long or is markup.
 */
const NAME_ONLY = [ 'srcdoc', 'autoplay', 'loop', 'muted', 'controls' ];

/**
 * The longest attribute value or label shown before it is cut short.
 */
const MAX_DETAIL = 160;

/**
 * Matches a self-closing block delimiter (`<!-- wp:rss {...} /-->`), whose output is server-rendered and otherwise invisible.
 */
const BLOCK_DELIMITER =
	/^\s*wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)(\s+(\{[\s\S]*\}))?\s*\/\s*$/;

/**
 * Matches an opening or self-closing block delimiter, capturing its name and JSON attributes (nested objects kept whole).
 */
const ANY_DELIMITER =
	/<!--\s+wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s+(?:(\{[\s\S]*?\})\s+)?\/?-->/g;

/**
 * Elements that start a block of their own.
 */
const BLOCKS = [
	'p',
	'h1',
	'h2',
	'h3',
	'h4',
	'h5',
	'h6',
	'ul',
	'ol',
	'blockquote',
	'pre',
	'div',
	'figure',
	'figcaption',
	'section',
	'article',
	'aside',
	'header',
	'footer',
	'main',
	'nav',
	'table',
	'thead',
	'tbody',
	'tfoot',
	'tr',
	'td',
	'th',
	'hr',
	'details',
	'summary',
	'dl',
	'dt',
	'dd',
	'address',
	'body',
];

/**
 * Collapses runs of whitespace, as the browser would when laying out text.
 *
 * @param text The text.
 * @return The collapsed text.
 */
function collapse( text: string ): string {
	return text.replace( /\s+/g, ' ' );
}

/**
 * Cuts a string short, marking the cut.
 *
 * @param text The text.
 * @return The text, at most {@link MAX_DETAIL} characters plus an ellipsis.
 */
function truncate( text: string ): string {
	return text.length > MAX_DETAIL
		? `${ text.slice( 0, MAX_DETAIL ) }…`
		: text;
}

/**
 * Describes a block comment delimiter, when the comment is one.
 *
 * @param comment The comment's text.
 * @return The description, or `null` for any other comment.
 */
function blockDelimiter( comment: string ): InlineNode | null {
	const match = BLOCK_DELIMITER.exec( comment );
	const raw = match?.[ 1 ];

	if ( ! match || ! raw ) {
		return null;
	}

	const name = raw.includes( '/' ) ? raw : `core/${ raw }`;

	return {
		type: 'withheld',
		kind: 'block',
		name,
		detail: truncate( collapse( match[ 3 ] ?? '' ).trim() ),
	};
}

/**
 * Describes an element that would render, play or load on the live site.
 *
 * @param element The element.
 * @param tag     Its lowercase tag name.
 * @return The description.
 */
function withheld( element: Element, tag: string ): InlineNode {
	const { kind, attributes } = WITHHELD[ tag ] ?? {
		kind: 'embed',
		attributes: [],
	};
	const parts: string[] = [];

	// What a control shows, or what an svg or math element draws as text.
	let label = '';

	if ( 'select' === tag ) {
		label = Array.from( element.querySelectorAll( 'option' ) )
			.map( ( option ) => collapse( option.textContent ?? '' ).trim() )
			.filter( ( text ) => '' !== text )
			.join( ' / ' );
	} else if ( [ 'button', 'textarea', 'svg', 'math' ].includes( tag ) ) {
		label = collapse( element.textContent ?? '' ).trim();
	}

	if ( '' !== label ) {
		parts.push( `'${ truncate( label ) }'` );
	}

	attributes.forEach( ( attribute ) => {
		if ( ! element.hasAttribute( attribute ) ) {
			return;
		}

		const value = ( element.getAttribute( attribute ) ?? '' ).trim();

		parts.push(
			NAME_ONLY.includes( attribute ) || '' === value
				? attribute
				: `${ attribute }=${ truncate( value ) }`
		);
	} );

	return { type: 'withheld', kind, name: tag, detail: parts.join( ', ' ) };
}

/**
 * Reads the inline nodes under a node.
 *
 * @param node    The parent node.
 * @param options The renderer options.
 * @return The inline nodes.
 */
function inlineChildren( node: Node, options: MarkdownOptions ): InlineNode[] {
	const nodes: InlineNode[] = [];

	node.childNodes.forEach( ( child ) => {
		nodes.push( ...inline( child, options ) );
	} );

	return nodes;
}

/**
 * Reads one node as inline content.
 *
 * @param node    The node.
 * @param options The renderer options.
 * @return The inline nodes it stands for.
 */
function inline( node: Node, options: MarkdownOptions ): InlineNode[] {
	if ( node.nodeType === node.TEXT_NODE ) {
		const value = collapse( node.textContent ?? '' );

		return '' === value ? [] : [ { type: 'text', value } ];
	}

	if ( node.nodeType === node.COMMENT_NODE ) {
		const delimiter = blockDelimiter( node.textContent ?? '' );

		return delimiter ? [ delimiter ] : [];
	}

	if ( node.nodeType !== node.ELEMENT_NODE ) {
		return [];
	}

	const element = node as Element;
	const tag = element.tagName.toLowerCase();

	if ( SKIPPED.includes( tag ) ) {
		return [];
	}

	if ( Object.prototype.hasOwnProperty.call( WITHHELD, tag ) ) {
		const description = withheld( element, tag );

		if ( 'video' === tag || 'audio' === tag ) {
			// A media element's sources load too: describe each of them.
			const sources: InlineNode[] = [];

			element
				.querySelectorAll( 'source, track' )
				.forEach( ( child ) =>
					sources.push(
						{ type: 'text', value: ' ' },
						withheld( child, child.tagName.toLowerCase() )
					)
				);

			return [ description, ...sources ];
		}

		if ( 'form' === tag ) {
			// A form's contents render on the live site: read them.
			return [
				description,
				{ type: 'text', value: ' ' },
				...inlineChildren( element, options ),
			];
		}

		return [ description ];
	}

	switch ( tag ) {
		case 'br':
			return [ { type: 'break' } ];
		case 'strong':
		case 'b':
			return [
				{
					type: 'strong',
					children: inlineChildren( element, options ),
				},
			];
		case 'em':
		case 'i':
			return [
				{
					type: 'emphasis',
					children: inlineChildren( element, options ),
				},
			];
		case 'code':
		case 'kbd':
		case 'samp':
			return [ { type: 'code', value: element.textContent ?? '' } ];
		case 'img':
			return [
				{
					type: 'image',
					alt: element.getAttribute( 'alt' ) ?? '',
					destination: element.getAttribute( 'src' ) ?? '',
				},
			];
		case 'a': {
			const destination = ( element.getAttribute( 'href' ) ?? '' ).trim();
			const label = collapse( element.textContent ?? '' ).trim();

			if ( '' === destination ) {
				return inlineChildren( element, options );
			}

			return [
				{
					type: 'link',
					label,
					destination,
					inert: ! isLiveDestination(
						destination,
						options.allowedHost
					),
				},
			];
		}
		default:
			return inlineChildren( element, options );
	}
}

/**
 * Trims the whitespace a run of inline nodes starts and ends with.
 *
 * @param nodes The inline nodes.
 * @return The trimmed nodes, empty when nothing but whitespace was left.
 */
function trimInline( nodes: InlineNode[] ): InlineNode[] {
	// Join adjacent text first: a skipped node can leave two whitespace runs side by side.
	const trimmed: InlineNode[] = [];

	nodes.forEach( ( node ) => {
		const previous = trimmed[ trimmed.length - 1 ];

		if ( 'text' === node.type && previous && 'text' === previous.type ) {
			trimmed[ trimmed.length - 1 ] = {
				type: 'text',
				value: collapse( previous.value + node.value ),
			};
			return;
		}

		trimmed.push( node );
	} );

	const first = trimmed[ 0 ];

	if ( first && 'text' === first.type ) {
		trimmed[ 0 ] = { type: 'text', value: first.value.trimStart() };
	}

	const lastIndex = trimmed.length - 1;
	const last = trimmed[ lastIndex ];

	if ( last && 'text' === last.type ) {
		trimmed[ lastIndex ] = { type: 'text', value: last.value.trimEnd() };
	}

	return trimmed.filter(
		( node ) => 'text' !== node.type || '' !== node.value
	);
}

/**
 * Reads the block nodes under a node, wrapping loose inline content in paragraphs.
 *
 * @param node    The parent node.
 * @param options The renderer options.
 * @return The block nodes.
 */
function blockChildren( node: Node, options: MarkdownOptions ): BlockNode[] {
	const blocks: BlockNode[] = [];
	let run: InlineNode[] = [];

	const flush = (): void => {
		const children = trimInline( run );

		if ( children.length > 0 ) {
			blocks.push( { type: 'paragraph', children } );
		}

		run = [];
	};

	node.childNodes.forEach( ( child ) => {
		const isBlock =
			child.nodeType === child.ELEMENT_NODE &&
			BLOCKS.includes( ( child as Element ).tagName.toLowerCase() );

		if ( ! isBlock ) {
			run.push( ...inline( child, options ) );
			return;
		}

		flush();
		blocks.push( ...block( child as Element, options ) );
	} );

	flush();

	return blocks;
}

/**
 * Reads one block element.
 *
 * @param element The element.
 * @param options The renderer options.
 * @return The block nodes it stands for.
 */
function block( element: Element, options: MarkdownOptions ): BlockNode[] {
	const tag = element.tagName.toLowerCase();

	switch ( tag ) {
		case 'p': {
			const children = trimInline( inlineChildren( element, options ) );

			return children.length > 0
				? [ { type: 'paragraph', children } ]
				: [];
		}
		case 'h1':
		case 'h2':
		case 'h3':
		case 'h4':
		case 'h5':
		case 'h6':
			return [
				{
					type: 'heading',
					level: Number( tag.slice( 1 ) ) as HeadingLevel,
					children: trimInline( inlineChildren( element, options ) ),
				},
			];
		case 'ul':
		case 'ol': {
			const items: InlineNode[][] = [];

			Array.from( element.children ).forEach( ( child ) => {
				if ( 'li' === child.tagName.toLowerCase() ) {
					items.push(
						trimInline( inlineChildren( child, options ) )
					);
				}
			} );

			const start = Number( element.getAttribute( 'start' ) ?? '1' );

			return [
				{
					type: 'list',
					ordered: 'ol' === tag,
					start: Number.isInteger( start ) ? start : 1,
					items,
				},
			];
		}
		case 'blockquote':
			return [
				{
					type: 'blockquote',
					children: blockChildren( element, options ),
				},
			];
		case 'pre':
			return [
				{
					type: 'codeBlock',
					language: '',
					value: element.textContent ?? '',
				},
			];
		case 'hr':
			return [];
		default:
			return blockChildren( element, options );
	}
}

/**
 * Reads post markup into block nodes.
 *
 * @param html    The markup.
 * @param options The renderer options.
 * @return The block nodes.
 */
export function renderHtml(
	html: string,
	options: MarkdownOptions
): BlockNode[] {
	if ( '' === html.trim() || 'undefined' === typeof window.DOMParser ) {
		return [];
	}

	// An inert document: nothing in it runs or loads. The leading `<body>` keeps a leading block comment inside the body.
	const parsed = new window.DOMParser().parseFromString(
		`<body>${ html }`,
		'text/html'
	);

	const blocks = blockChildren( parsed.body, options );

	// Block delimiters after a stray `</body>` or `</html>` land outside the body; describe them too.
	const outside: InlineNode[] = [];

	[ parsed, parsed.documentElement, parsed.head ].forEach( ( parent ) => {
		parent?.childNodes.forEach( ( child ) => {
			if ( child.nodeType === child.COMMENT_NODE ) {
				const delimiter = blockDelimiter( child.textContent ?? '' );

				if ( delimiter ) {
					outside.push( delimiter );
				}
			}
		} );
	} );

	if ( outside.length > 0 ) {
		blocks.push( { type: 'paragraph', children: outside } );
	}

	return blocks;
}

/**
 * Writes a parsed attribute value as JSON with object keys sorted, for comparison.
 *
 * @param value The parsed value.
 * @return The canonical JSON.
 */
function canonical( value: unknown ): string {
	if ( Array.isArray( value ) ) {
		return `[${ value.map( canonical ).join( ',' ) }]`;
	}

	if ( value && 'object' === typeof value ) {
		const record = value as Record< string, unknown >;

		return `{${ Object.keys( record )
			.sort()
			.map(
				( key ) =>
					`${ JSON.stringify( key ) }:${ canonical( record[ key ] ) }`
			)
			.join( ',' ) }}`;
	}

	return JSON.stringify( value ) ?? '';
}

/**
 * Lists each block's settings in raw markup, by block name, skipping blocks with none.
 *
 * @param html The raw markup.
 * @return The canonical settings of each block, keyed by block name.
 */
function blockSettings( html: string ): Map< string, string[] > {
	const settings = new Map< string, string[] >();

	for ( const match of html.matchAll( ANY_DELIMITER ) ) {
		const raw = match[ 1 ] ?? '';
		const attributes = ( match[ 2 ] ?? '' ).trim();

		if ( '' === raw || '' === attributes ) {
			continue;
		}

		const name = raw.includes( '/' ) ? raw : `core/${ raw }`;
		let value = collapse( attributes );

		try {
			value = canonical( JSON.parse( attributes ) );
		} catch {
			// Attributes that are not valid JSON are compared as written.
		}

		if ( '{}' === value ) {
			continue;
		}

		settings.set( name, [ ...( settings.get( name ) ?? [] ), value ] );
	}

	return settings;
}

/**
 * Names the blocks whose (unrendered) delimiter settings differ between two versions of post markup.
 *
 * @param current  The current raw markup.
 * @param proposed The proposed raw markup.
 * @return The block names, in the order they first appear.
 */
export function changedBlockSettings(
	current: string,
	proposed: string
): string[] {
	const before = blockSettings( current );
	const after = blockSettings( proposed );
	const names = [ ...new Set( [ ...after.keys(), ...before.keys() ] ) ];

	return names.filter( ( name ) => {
		const was = [ ...( before.get( name ) ?? [] ) ].sort();
		const now = [ ...( after.get( name ) ?? [] ) ].sort();

		return (
			was.length !== now.length ||
			was.some( ( value, index ) => value !== now[ index ] )
		);
	} );
}
