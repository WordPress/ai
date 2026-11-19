/**
 * Builds the sample content that Playground previews import: the book
 * "Milestones: The Story of WordPress" (WordPress Library, Vol. 1) as a WXR
 * file plus resized images.
 *
 * Usage:
 *
 *     git clone https://github.com/WordPress/library.git ../library
 *     node tests/playground/build.mjs ../library
 *
 * Writes tests/playground/milestones/milestones.xml and the images next to it.
 * See tests/playground/README.md for the license, the content map and what is
 * left out.
 */

/**
 * External dependencies
 */
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseDocument, DomUtils } from 'htmlparser2';
import MarkdownIt from 'markdown-it';
import Vips from 'wasm-vips';

const ROOT = path.resolve(
	path.dirname( fileURLToPath( import.meta.url ) ),
	'..',
	'..'
);
const OUT_DIR = path.join( ROOT, 'tests', 'playground', 'milestones' );
const IMAGE_DIR = path.join( OUT_DIR, 'images' );
const VOLUME = 'milestones-vol-1';

// Placeholder site the WXR is written for. Playground's importWxr step
// rewrites it to the preview's own URL.
const SITE = 'https://milestones.example';

// Where Playground downloads the images from. The PR preview workflow maps
// this to the pull request's own commit with importWxr's urlMapping.
const IMAGE_BASE =
	'https://raw.githubusercontent.com/WordPress/ai/trunk/tests/playground/milestones/images/';

// The book went up on wordpress.org/book on 13 November 2015. The
// Introduction takes that date and every chapter after it is one day later.
const FIRST_DATE = Date.UTC( 2015, 10, 13, 12, 0, 0 );
const DAY = 24 * 60 * 60 * 1000;

const MAX_IMAGE_WIDTH = 1200;
const WEBP_QUALITY = 80;

/*
 * Photographs left out because they are third-party work with their own
 * licenses (some non-commercial), or show people without a credit. Their
 * italic captions are dropped with them.
 */
const EXCLUDED_IMAGES = [
	/^introduction\//,
	/^21\//, // WordCamp 2006 photos, Scott Beale / Laughing Squid.
	/^24\/habari_oh_linux_fest_credit_morydd\.jpg$/, // Sean T. Evans.
	/^24\/php-graph-2007\.png$/, // Nexen Services chart, all rights reserved.
	/^33\/1st-core-meetup\.jpg$/, // Uncredited photo of people.
];

/*
 * Made-up reader comments from Wapuu, so Comment Moderation and Suggest
 * Reply have something to work on. Status 1 is approved, 0 is pending.
 */
const COMMENTS = [
	{
		post: 'hello-world',
		days: 1,
		status: 1,
		text: 'Two first posts, five thousand miles apart, and look where they led. My own first post was a photo of a pancake, so I feel this one.',
	},
	{
		key: 'corsica-question',
		post: 'the-only-blogger-in-corsica',
		days: 1,
		status: 1,
		text: 'I had no idea b2 was named after a Blur song! Does anyone know which other names Michel tried before he settled on b2?',
	},
	{
		parent: 'corsica-question',
		post: 'the-only-blogger-in-corsica',
		days: 2,
		status: 1,
		text: 'Answering my own question: the first footnote says “blog2” and then “blogger2”. Should have read to the end!',
	},
	{
		post: 'the-gpl',
		days: 1,
		status: 1,
		text: 'Bookmarking this chapter for the next time someone asks why I am free to wear any costume I like.',
	},
	{
		post: 'a-new-logo',
		days: 3,
		status: 1,
		text: 'The Santa Maria sketches are lovely, but I am partial to the St Patrick’s Day logo. Green suits everyone.',
	},
	{
		post: 'akismet',
		days: 2,
		status: 0,
		author: 'Wapuu Honey Deals',
		text: 'GREAT POST!!! Get FREE honey and cheap premium plugin licenses today only at https://honey-deals.example/wapuu-promo click now!!!',
	},
	{
		post: 'shuttle',
		days: 1,
		status: 1,
		text: 'Version 14 is my favourite of the Shuttle mockups. Which one would you have picked?',
	},
	{
		post: 'wordcamp-2006',
		days: 4,
		status: 1,
		text: 'Every WordCamp since owes something to that first Saturday in San Francisco. Who here has been to one?',
	},
	{
		post: 'premium-themes',
		days: 2,
		status: 0,
		text: 'I think this chapter is too kind to theme shops that sold themes under other licenses. Selling support is not the same as selling code, and that point gets lost here.',
	},
	{
		post: 'thesis',
		days: 1,
		status: 0,
		text: 'This chapter is nonsense and whoever wrote it should stop writing.',
	},
	{
		post: 'the-wordpress-foundation',
		days: 2,
		status: 1,
		text: 'Glad the trademarks ended up with a foundation. It makes me feel safe as a mascot.',
	},
	{
		post: 'mp6',
		days: 1,
		status: 1,
		text: 'MP6 still looks fresh to me. Thank you to everyone who tested it as a plugin first!',
	},
];

const md = new MarkdownIt( { html: true, linkify: false, typographer: false } );

const libraryDir = path.resolve( process.argv[ 2 ] || '' );
if (
	! process.argv[ 2 ] ||
	! fs.existsSync( path.join( libraryDir, 'manifest.json' ) )
) {
	process.stderr.write(
		'Usage: node tests/playground/build.mjs <path to a WordPress/library checkout>\n'
	);
	process.exit( 1 );
}

const librarySha = ( () => {
	try {
		return execFileSync( 'git', [ '-C', libraryDir, 'rev-parse', 'HEAD' ], {
			encoding: 'utf8',
		} ).trim();
	} catch {
		return 'unknown';
	}
} )();

let nextId = 1000;
const newId = () => ++nextId;

/**
 * A stable UUID-shaped id, so rebuilding gives the same file.
 *
 * @param {...string} parts Values to hash.
 * @return {string} UUID-shaped hex string.
 */
function stableUuid( ...parts ) {
	const h = createHash( 'sha1' ).update( parts.join( '|' ) ).digest( 'hex' );
	return `${ h.slice( 0, 8 ) }-${ h.slice( 8, 12 ) }-5${ h.slice(
		13,
		16
	) }-a${ h.slice( 17, 20 ) }-${ h.slice( 20, 32 ) }`;
}

const escapeHtml = ( s ) =>
	s
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' );

const readLibrary = ( rel ) =>
	fs
		.readFileSync( path.join( libraryDir, rel ), 'utf8' )
		.replace( /\r\n?/g, '\n' );

/**
 * Serializes a block with optional attributes.
 *
 * @param {string}  name  Block name without the core/ prefix.
 * @param {string}  inner Inner HTML.
 * @param {Object=} attrs Block attributes.
 * @return {string} Block markup.
 */
function block( name, inner, attrs ) {
	const a =
		attrs && Object.keys( attrs ).length
			? ` ${ JSON.stringify( attrs ) }`
			: '';
	return `<!-- wp:${ name }${ a } -->\n${ inner }\n<!-- /wp:${ name } -->`;
}

const paragraph = ( html ) => block( 'paragraph', `<p>${ html }</p>` );

function heading( html, level = 2 ) {
	return block(
		'heading',
		`<h${ level } class="wp-block-heading">${ html }</h${ level }>`,
		level === 2 ? undefined : { level }
	);
}

function list( itemsHtml, { ordered = false, start } = {} ) {
	const tag = ordered ? 'ol' : 'ul';
	const attrs = {};
	if ( ordered ) {
		attrs.ordered = true;
	}
	if ( start && start !== 1 ) {
		attrs.start = start;
	}
	const startAttr = attrs.start ? ` start="${ start }"` : '';
	const items = itemsHtml
		.map( ( li ) => block( 'list-item', `<li>${ li }</li>` ) )
		.join( '\n' );
	return block(
		'list',
		`<${ tag }${ startAttr } class="wp-block-list">${ items }</${ tag }>`,
		attrs
	);
}

// Images ---------------------------------------------------------------

const attachments = new Map(); // Library path -> attachment record.

/**
 * Registers an image used by the content, returns its attachment record or
 * null when the image is left out.
 *
 * @param {string} libraryPath Path relative to the library root.
 * @param {string} alt         Alt text from the source.
 * @param {number} parent      Post the image first appears in.
 * @return {Object|null} Attachment record.
 */
function attachment( libraryPath, alt, parent ) {
	const imagesRel = libraryPath.split( `${ VOLUME }/Resources/images/` )[ 1 ];
	if ( imagesRel && EXCLUDED_IMAGES.some( ( re ) => re.test( imagesRel ) ) ) {
		return null;
	}
	if ( ! attachments.has( libraryPath ) ) {
		const rel = imagesRel || path.basename( libraryPath );
		const ext = path.extname( rel ).toLowerCase();
		const stem = rel
			.slice( 0, -ext.length )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-|-$/g, '' );
		const file = `${ stem }${ ext === '.gif' ? '.gif' : '.webp' }`;
		attachments.set( libraryPath, {
			id: newId(),
			source: libraryPath,
			file,
			url: IMAGE_BASE + file,
			title: alt || stem.replace( /-/g, ' ' ),
			alt,
			parent,
		} );
	}
	return attachments.get( libraryPath );
}

function imageBlock( att, alt, captionHtml ) {
	const caption = captionHtml
		? `<figcaption class="wp-element-caption">${ captionHtml }</figcaption>`
		: '';
	return block(
		'image',
		`<figure class="wp-block-image size-large"><img src="${
			att.url
		}" alt="${ escapeHtml( alt || '' ) }" class="wp-image-${
			att.id
		}"/>${ caption }</figure>`,
		{ id: att.id, sizeSlug: 'large', linkDestination: 'none' }
	);
}

// Markdown to blocks ------------------------------------------------------

const INLINE = new Set( [
	'a',
	'abbr',
	'b',
	'br',
	'cite',
	'code',
	'del',
	'em',
	'i',
	'ins',
	'q',
	's',
	'small',
	'span',
	'strong',
	'sub',
	'sup',
	'u',
] );

const outer = ( nodes ) =>
	DomUtils.getOuterHTML( nodes, { decodeEntities: false } );
const inner = ( node ) =>
	DomUtils.getInnerHTML( node, { decodeEntities: false } ).trim();
const isTag = ( node ) => node.type === 'tag';
const textOf = ( node ) => DomUtils.textContent( node ).trim();

/**
 * Whether a node is a paragraph made only of italic text, which the book
 * uses for image captions.
 *
 * @param {Object} node DOM node.
 * @return {boolean} Whether it is a caption.
 */
function isCaption( node ) {
	if ( ! node || ! isTag( node ) || node.name !== 'p' ) {
		return false;
	}
	const kids = node.children.filter(
		( c ) => ! ( c.type === 'text' && ! c.data.trim() )
	);
	return kids.length === 1 && isTag( kids[ 0 ] ) && kids[ 0 ].name === 'em';
}

/**
 * Converts a chapter's Markdown to block markup.
 *
 * @param {string} source    Markdown.
 * @param {string} sourceDir Directory of the Markdown file, relative to the library root.
 * @param {number} postId    Post the content belongs to.
 * @param {Array}  footnotes Collects the core/footnotes meta entries.
 * @return {string} Block markup.
 */
function toBlocks( source, sourceDir, postId, footnotes ) {
	let text = source.replace( /^\s*## .*\n/, '' ); // The chapter title is the post title.
	text = text.replace( /(!\[[^\]]*?)\s*=\d+x\]/g, '$1]' ); // "=600x" size hints.

	// Footnotes: pull out the definitions, number the references in order.
	const definitions = new Map();
	text = text.replace( /^\[\^([^\]]+)\]:\s*(.+)$/gm, ( _m, key, note ) => {
		definitions.set( key, note.trim() );
		return '';
	} );
	const numbers = new Map();
	text = text.replace( /\[\^([^\]]+)\]/g, ( m, key ) => {
		if ( ! definitions.has( key ) ) {
			return m;
		}
		if ( ! numbers.has( key ) ) {
			numbers.set( key, numbers.size + 1 );
			footnotes.push( {
				content: md.renderInline( definitions.get( key ) ),
				id: stableUuid( String( postId ), key ),
			} );
		}
		const id = stableUuid( String( postId ), key );
		return `<sup data-fn="${ id }" class="fn"><a href="#${ id }" id="${ id }-link">${ numbers.get(
			key
		) }</a></sup>`;
	} );

	// Raw HTML blocks swallow the Markdown that follows them up to the next
	// blank line, so give each block-level tag its own lines.
	text = text
		.replace( /^(<(?:blockquote|ul|ol|img)\b)/gm, '\n$1' )
		.replace( /(<\/(?:blockquote|ul|ol)>|<img\b[^>]*>)[ \t]*$/gm, '$1\n' );

	const dom = parseDocument( md.render( text ), { decodeEntities: false } );
	const nodes = dom.children;
	const blocks = [];
	let run = [];

	const flush = () => {
		const html = outer( run ).trim();
		run = [];
		for ( const chunk of html.split( /\n\s*\n/ ) ) {
			if ( chunk.trim() ) {
				blocks.push( paragraph( chunk.trim() ) );
			}
		}
	};

	for ( let i = 0; i < nodes.length; i++ ) {
		const node = nodes[ i ];
		if (
			node.type === 'text' ||
			( isTag( node ) && INLINE.has( node.name ) )
		) {
			run.push( node );
			continue;
		}
		flush();
		if ( ! isTag( node ) ) {
			continue;
		}

		const images =
			node.name === 'img'
				? [ node ]
				: DomUtils.getElementsByTagName( 'img', node );
		if ( images.length && ! textOf( node ) ) {
			// Look past whitespace for an italic caption.
			let j = i + 1;
			while (
				nodes[ j ] &&
				nodes[ j ].type === 'text' &&
				! nodes[ j ].data.trim()
			) {
				j++;
			}
			const captionNode = isCaption( nodes[ j ] ) ? nodes[ j ] : null;
			let kept = 0;
			images.forEach( ( img ) => {
				const src = decodeURIComponent( img.attribs.src );
				const rel = path.posix.normalize(
					path.posix.join( sourceDir, src )
				);
				const alt = img.attribs.alt || '';
				const att = attachment( rel, alt, postId );
				if ( att ) {
					kept++;
					const isLast = img === images[ images.length - 1 ];
					blocks.push(
						imageBlock(
							att,
							alt,
							isLast && captionNode
								? inner( captionNode.children[ 0 ] )
								: ''
						)
					);
				}
			} );
			if ( captionNode ) {
				i = j; // Used as the caption, or dropped with a left-out photo.
			}
			if ( ! kept && ! captionNode ) {
				continue;
			}
			continue;
		}

		switch ( node.name ) {
			case 'p':
				blocks.push( paragraph( inner( node ) ) );
				break;
			case 'h2':
			case 'h3':
			case 'h4':
				blocks.push(
					heading( inner( node ), Number( node.name[ 1 ] ) )
				);
				break;
			case 'blockquote': {
				const paras = node.children.filter(
					( c ) => isTag( c ) && c.name === 'p'
				);
				const body = paras.length
					? paras.map( ( p ) => paragraph( inner( p ) ) )
					: [ paragraph( inner( node ) ) ];
				blocks.push(
					block(
						'quote',
						`<blockquote class="wp-block-quote">${ body.join(
							'\n'
						) }</blockquote>`
					)
				);
				break;
			}
			case 'ul':
			case 'ol':
				blocks.push(
					list(
						node.children
							.filter( ( c ) => isTag( c ) && c.name === 'li' )
							.map( inner ),
						{ ordered: node.name === 'ol' }
					)
				);
				break;
			case 'hr':
				blocks.push(
					block(
						'separator',
						'<hr class="wp-block-separator has-alpha-channel-opacity"/>'
					)
				);
				break;
			default:
				process.stderr.write(
					`Kept <${ node.name }> as an HTML block in post ${ postId }\n`
				);
				blocks.push( block( 'html', outer( [ node ] ) ) );
		}
	}
	flush();

	if ( footnotes.length ) {
		blocks.push( '<!-- wp:footnotes /-->' );
	}
	return blocks.join( '\n\n' );
}

// Book structure ----------------------------------------------------------

const manifest = JSON.parse( readLibrary( 'manifest.json' ) );
const chapters = Object.values( manifest ).filter( ( c ) =>
	c.markdown_source.startsWith( `${ VOLUME }/` )
);

const parts = [ 1, 2, 3, 4, 5, 6 ].map( ( n ) => {
	const dir = `${ VOLUME }/Content/Part ${ n }`;
	const endmatter = [ 'endmatter.md', `part-${ n }-endmatter.md` ].find(
		( f ) => fs.existsSync( path.join( libraryDir, dir, f ) )
	);
	const era = endmatter
		? readLibrary( `${ dir }/${ endmatter }` )
				.trim()
				.split( '\n' )[ 0 ]
				.trim()
		: '';
	return {
		n,
		dir,
		endmatter,
		era,
		slug: `part-${ n }`,
		name: `Part ${ n }`,
		termId: 10 + n,
		chapters: [],
	};
} );

const posts = chapters.map( ( chapter, index ) => {
	const source = decodeURIComponent( chapter.markdown_source );
	const partMatch = source.match( /\/Part (\d)\// );
	const part = partMatch ? parts[ Number( partMatch[ 1 ] ) - 1 ] : null;
	const id = newId();
	const footnotes = [];
	const content = toBlocks(
		readLibrary( source ),
		path.posix.dirname( source ),
		id,
		footnotes
	);
	const post = {
		id,
		type: 'post',
		title: chapter.title,
		slug: chapter.slug,
		number: index, // The Introduction is 0, chapters run 1 to 44 as in the book.
		date: new Date( FIRST_DATE + index * DAY ),
		category: part,
		content,
		meta: footnotes.length
			? { footnotes: JSON.stringify( footnotes ) }
			: {},
	};
	if ( part ) {
		part.chapters.push( post );
	}
	return post;
} );

const link = ( slug ) => `${ SITE }/${ slug }/`;
const chapterList = ( part ) =>
	list(
		part.chapters.map(
			( p ) =>
				`<a href="${ link( p.slug ) }">${ escapeHtml( p.title ) }</a>`
		),
		{ ordered: true, start: part.chapters[ 0 ].number }
	);

const bookId = newId();
const cover = attachment(
	`${ VOLUME }/Resources/illustrations/exports/Cover.png`,
	'Cover of Milestones: The Story of WordPress',
	bookId
);

const partPages = parts.map( ( part ) => {
	const id = newId();
	const illustration = attachment(
		`${ VOLUME }/Resources/illustrations/exports/Part-${ part.n }-Illustration.png`,
		`Illustration for Part ${ part.n }`,
		id
	);
	const body = [ imageBlock( illustration, illustration.alt ) ];

	// Part 5's file holds three drafts ("Original:", "2nd option:", ...); the
	// published book uses the original, so keep the first three-line stanza.
	const haiku = readLibrary( `${ part.dir }/haiku.md` )
		.split( '\n' )
		.map( ( l ) => l.replace( /<[^>]+>/g, '' ).trim() )
		.filter( ( l ) => l && ! /^(original|\w+ option):$/i.test( l ) )
		.slice( 0, 3 );
	body.push(
		block(
			'verse',
			`<pre class="wp-block-verse">${ haiku
				.map( escapeHtml )
				.join( '<br>' ) }</pre>`
		)
	);
	body.push( heading( 'Chapters' ), chapterList( part ) );

	if ( part.endmatter ) {
		body.push( heading( 'Timeline' ) );
		const groups = readLibrary( `${ part.dir }/${ part.endmatter }` )
			.trim()
			.split( /\n\s*\n/ );
		for ( const group of groups ) {
			const lines = group
				.split( '\n' )
				.map( ( l ) => l.trim() )
				.filter( Boolean );
			const textLines = lines.filter( ( l ) => ! l.startsWith( '![' ) );
			if ( textLines.length === 1 ) {
				body.push( paragraph( escapeHtml( textLines[ 0 ] ) ) );
			} else if ( textLines.length > 1 ) {
				body.push(
					heading(
						escapeHtml( textLines[ 0 ].replace( /:$/, '' ) ),
						3
					)
				);
				body.push(
					list(
						textLines
							.slice( 1 )
							.map( ( l ) =>
								escapeHtml( l.replace( /^-\s*/, '' ) )
							)
					)
				);
			}
			for ( const l of lines.filter( ( x ) => x.startsWith( '![' ) ) ) {
				const m = l.match( /^!\[([^\]]*?)(?:\s*=\d+x)?\]\(([^)]+)\)/ );
				const rel = path.posix.normalize(
					path.posix.join( part.dir, decodeURIComponent( m[ 2 ] ) )
				);
				const att = attachment( rel, m[ 1 ], id );
				if ( att ) {
					body.push( imageBlock( att, m[ 1 ] ) );
				}
			}
		}
	}

	return {
		id,
		type: 'page',
		title: part.name,
		slug: part.slug,
		parent: bookId,
		menuOrder: part.n,
		date: new Date( FIRST_DATE ),
		content: body.join( '\n\n' ),
		meta: {},
	};
} );

const intro = posts.find( ( p ) => p.slug === 'introduction' );
const contents = [
	paragraph( `<a href="${ link( intro.slug ) }">Introduction</a>` ),
];
for ( const part of parts ) {
	const era = part.era ? ` · ${ escapeHtml( part.era ) }` : '';
	contents.push(
		heading(
			`<a href="${ link( `milestones/${ part.slug }` ) }">${
				part.name
			}</a>${ era }`,
			3
		)
	);
	contents.push( chapterList( part ) );
}

const bookPage = {
	id: bookId,
	type: 'page',
	title: 'Milestones: The Story of WordPress',
	slug: 'milestones',
	date: new Date( FIRST_DATE ),
	meta: {},
	content: [
		imageBlock( cover, cover.alt ),
		paragraph(
			'The origins of WordPress and its first decade, told chapter by chapter.'
		),
		heading( 'Contents' ),
		...contents,
		heading( 'About this content' ),
		paragraph(
			'This site’s sample content is <em>Milestones: The Story of WordPress</em>, Vol. 1 of the WordPress Library, written by WordPress contributors. ' +
				`The source is <a href="https://github.com/WordPress/library/tree/${ librarySha }/${ VOLUME }">github.com/WordPress/library</a>, and the book is also published at <a href="https://wordpress.org/book/">wordpress.org/book</a>.`
		),
		paragraph(
			'The book is dual-licensed under the <a href="https://www.gnu.org/licenses/old-licenses/gpl-2.0.html">GNU GPL v2</a> and <a href="https://creativecommons.org/licenses/by-sa/4.0/">Creative Commons Attribution-ShareAlike 4.0</a>. ' +
				'It has been modified for use as test content in the WordPress AI plugin: each chapter is a post and each part is a page and a category, the text is converted to blocks, images are resized, ' +
				'and photographs that carry their own licenses are left out. The comments are made up.'
		),
	].join( '\n\n' ),
};

// Comments ------------------------------------------------------------------

const comments = new Map(); // Post id -> comments.
let commentId = 100;
const commentIds = new Map();
for ( const c of COMMENTS ) {
	const post = posts.find( ( p ) => p.slug === c.post );
	if ( ! post ) {
		throw new Error( `No chapter with slug ${ c.post }` );
	}
	const id = ++commentId;
	if ( c.key ) {
		commentIds.set( c.key, id );
	}
	const list_ = comments.get( post.id ) || [];
	list_.push( {
		id,
		parent: c.parent ? commentIds.get( c.parent ) : 0,
		author: c.author || 'Wapuu',
		date: new Date(
			post.date.getTime() + c.days * DAY + 3 * 60 * 60 * 1000
		),
		status: c.status,
		text: c.text,
	} );
	comments.set( post.id, list_ );
}

// WXR -----------------------------------------------------------------------

const cdata = ( s ) =>
	`<![CDATA[${ String( s ).replace( /]]>/g, ']]]]><![CDATA[>' ) }]]>`;
const wpDate = ( d ) => d.toISOString().slice( 0, 19 ).replace( 'T', ' ' );
const meta = ( key, value ) =>
	`\t\t<wp:postmeta>\n\t\t\t<wp:meta_key>${ key }</wp:meta_key>\n\t\t\t<wp:meta_value>${ cdata(
		value
	) }</wp:meta_value>\n\t\t</wp:postmeta>\n`;

function commentXml( c ) {
	return (
		'\t\t<wp:comment>\n' +
		`\t\t\t<wp:comment_id>${ c.id }</wp:comment_id>\n` +
		`\t\t\t<wp:comment_author>${ cdata(
			c.author
		) }</wp:comment_author>\n` +
		`\t\t\t<wp:comment_author_email>${ cdata(
			'wapuu@example.com'
		) }</wp:comment_author_email>\n` +
		`\t\t\t<wp:comment_author_url></wp:comment_author_url>\n` +
		`\t\t\t<wp:comment_author_IP>${ cdata(
			'192.0.2.1'
		) }</wp:comment_author_IP>\n` +
		`\t\t\t<wp:comment_date>${ cdata(
			wpDate( c.date )
		) }</wp:comment_date>\n` +
		`\t\t\t<wp:comment_date_gmt>${ cdata(
			wpDate( c.date )
		) }</wp:comment_date_gmt>\n` +
		`\t\t\t<wp:comment_content>${ cdata(
			c.text
		) }</wp:comment_content>\n` +
		`\t\t\t<wp:comment_approved>${ cdata(
			String( c.status )
		) }</wp:comment_approved>\n` +
		`\t\t\t<wp:comment_type>${ cdata( 'comment' ) }</wp:comment_type>\n` +
		`\t\t\t<wp:comment_parent>${ c.parent }</wp:comment_parent>\n` +
		'\t\t\t<wp:comment_user_id>0</wp:comment_user_id>\n' +
		'\t\t</wp:comment>\n'
	);
}

function itemXml( p ) {
	const d = wpDate( p.date );
	const category = p.category
		? `\t\t<category domain="category" nicename="${
				p.category.slug
		  }">${ cdata( p.category.name ) }</category>\n`
		: '';
	return (
		'\t<item>\n' +
		`\t\t<title>${ cdata( p.title ) }</title>\n` +
		`\t\t<link>${ link( p.slug ) }</link>\n` +
		`\t\t<dc:creator>${ cdata( 'admin' ) }</dc:creator>\n` +
		`\t\t<guid isPermaLink="false">${ SITE }/?p=${ p.id }</guid>\n` +
		`\t\t<content:encoded>${ cdata( p.content ) }</content:encoded>\n` +
		`\t\t<excerpt:encoded>${ cdata( '' ) }</excerpt:encoded>\n` +
		`\t\t<wp:post_id>${ p.id }</wp:post_id>\n` +
		`\t\t<wp:post_date>${ cdata( d ) }</wp:post_date>\n` +
		`\t\t<wp:post_date_gmt>${ cdata( d ) }</wp:post_date_gmt>\n` +
		`\t\t<wp:comment_status>${ cdata(
			p.type === 'post' ? 'open' : 'closed'
		) }</wp:comment_status>\n` +
		`\t\t<wp:ping_status>${ cdata( 'closed' ) }</wp:ping_status>\n` +
		`\t\t<wp:post_name>${ cdata( p.slug ) }</wp:post_name>\n` +
		`\t\t<wp:status>${ cdata( 'publish' ) }</wp:status>\n` +
		`\t\t<wp:post_parent>${ p.parent || 0 }</wp:post_parent>\n` +
		`\t\t<wp:menu_order>${ p.menuOrder || 0 }</wp:menu_order>\n` +
		`\t\t<wp:post_type>${ cdata( p.type ) }</wp:post_type>\n` +
		`\t\t<wp:post_password>${ cdata( '' ) }</wp:post_password>\n` +
		'\t\t<wp:is_sticky>0</wp:is_sticky>\n' +
		category +
		Object.entries( p.meta )
			.map( ( [ k, v ] ) => meta( k, v ) )
			.join( '' ) +
		( comments.get( p.id ) || [] ).map( commentXml ).join( '' ) +
		'\t</item>\n'
	);
}

function attachmentXml( a ) {
	const d = wpDate( new Date( FIRST_DATE ) );
	return (
		'\t<item>\n' +
		`\t\t<title>${ cdata( a.title ) }</title>\n` +
		`\t\t<dc:creator>${ cdata( 'admin' ) }</dc:creator>\n` +
		`\t\t<guid isPermaLink="false">${ a.url }</guid>\n` +
		`\t\t<content:encoded>${ cdata( '' ) }</content:encoded>\n` +
		`\t\t<excerpt:encoded>${ cdata( '' ) }</excerpt:encoded>\n` +
		`\t\t<wp:post_id>${ a.id }</wp:post_id>\n` +
		`\t\t<wp:post_date>${ cdata( d ) }</wp:post_date>\n` +
		`\t\t<wp:post_date_gmt>${ cdata( d ) }</wp:post_date_gmt>\n` +
		`\t\t<wp:post_name>${ cdata(
			`milestones-${ a.file.replace( /\.\w+$/, '' ) }`
		) }</wp:post_name>\n` +
		`\t\t<wp:status>${ cdata( 'inherit' ) }</wp:status>\n` +
		`\t\t<wp:post_parent>${ a.parent }</wp:post_parent>\n` +
		`\t\t<wp:post_type>${ cdata( 'attachment' ) }</wp:post_type>\n` +
		`\t\t<wp:attachment_url>${ cdata( a.url ) }</wp:attachment_url>\n` +
		( a.alt ? meta( '_wp_attachment_image_alt', a.alt ) : '' ) +
		'\t</item>\n'
	);
}

const categoriesXml = parts
	.map(
		( p ) =>
			`\t<wp:category>\n\t\t<wp:term_id>${
				p.termId
			}</wp:term_id>\n\t\t<wp:category_nicename>${ cdata(
				p.slug
			) }</wp:category_nicename>\n` +
			`\t\t<wp:category_parent>${ cdata(
				''
			) }</wp:category_parent>\n\t\t<wp:cat_name>${ cdata(
				p.name
			) }</wp:cat_name>\n` +
			`\t\t<wp:category_description>${ cdata(
				p.era
			) }</wp:category_description>\n\t</wp:category>\n`
	)
	.join( '' );

// Pages and posts come before attachments so each attachment's parent exists.
const xml = `<?xml version="1.0" encoding="UTF-8" ?>
<!--
	Milestones: The Story of WordPress (WordPress Library, Vol. 1), by WordPress contributors.
	Dual-licensed under GPLv2 and CC BY-SA 4.0. Modified: split into posts and pages,
	converted to blocks, some photographs left out, made-up comments added.
	Generated by tests/playground/build.mjs from WordPress/library@${ librarySha }.
	Do not edit by hand; see tests/playground/README.md.
-->
<rss version="2.0"
	xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
	xmlns:content="http://purl.org/rss/1.0/modules/content/"
	xmlns:wfw="http://wellformedweb.org/CommentAPI/"
	xmlns:dc="http://purl.org/dc/elements/1.1/"
	xmlns:wp="http://wordpress.org/export/1.2/"
>
<channel>
	<title>Milestones</title>
	<link>${ SITE }</link>
	<description>The Story of WordPress</description>
	<language>en-US</language>
	<wp:wxr_version>1.2</wp:wxr_version>
	<wp:base_site_url>${ SITE }</wp:base_site_url>
	<wp:base_blog_url>${ SITE }</wp:base_blog_url>
	<wp:author>
		<wp:author_id>1</wp:author_id>
		<wp:author_login>${ cdata( 'admin' ) }</wp:author_login>
		<wp:author_email>${ cdata( 'admin@example.com' ) }</wp:author_email>
		<wp:author_display_name>${ cdata( 'admin' ) }</wp:author_display_name>
	</wp:author>
${ categoriesXml }${ [ bookPage, ...partPages, ...posts ]
	.map( itemXml )
	.join( '' ) }${ [ ...attachments.values() ]
	.map( attachmentXml )
	.join( '' ) }</channel>
</rss>
`;

fs.mkdirSync( IMAGE_DIR, { recursive: true } );
fs.writeFileSync( path.join( OUT_DIR, 'milestones.xml' ), xml );

// Images: resize to at most 1200 px wide as WebP; GIFs are copied as they are.
const vips = await Vips();
const wanted = new Set();
let bytes = 0;
for ( const a of attachments.values() ) {
	const src = fs.readFileSync( path.join( libraryDir, a.source ) );
	const dest = path.join( IMAGE_DIR, a.file );
	if ( a.file.endsWith( '.gif' ) ) {
		fs.writeFileSync( dest, src );
	} else {
		const image = vips.Image.thumbnailBuffer( src, MAX_IMAGE_WIDTH, {
			size: 'down',
			height: 10000,
		} );
		fs.writeFileSync(
			dest,
			image.writeToBuffer( '.webp', { Q: WEBP_QUALITY, strip: true } )
		);
		image.delete();
	}
	wanted.add( a.file );
	bytes += fs.statSync( dest ).size;
}
for ( const file of fs.readdirSync( IMAGE_DIR ) ) {
	if ( ! wanted.has( file ) ) {
		fs.unlinkSync( path.join( IMAGE_DIR, file ) );
	}
}

const commentCount = [ ...comments.values() ].reduce(
	( n, l ) => n + l.length,
	0
);
process.stdout.write(
	`${ posts.length } posts, ${ partPages.length + 1 } pages, ` +
		`${ parts.length } categories, ${ attachments.size } images ` +
		`(${ Math.round( bytes / 1024 ) } KB), ${ commentCount } comments, ` +
		`WXR ${ Math.round( xml.length / 1024 ) } KB, ` +
		`from WordPress/library@${ librarySha.slice( 0, 7 ) }\n`
);
