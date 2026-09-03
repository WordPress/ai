/**
 * Renders assistant markdown as React elements.
 *
 * Nothing in this file produces an HTML string and nothing is ever passed to
 * `dangerouslySetInnerHTML`; every node from {@link renderMarkdown} becomes an
 * element or a text child, so model-supplied markup can only ever be displayed
 * (R19). Code blocks get a copy affordance and never an insert affordance
 * (KTD9).
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	renderMarkdown,
	type BlockNode,
	type InlineNode,
} from '../utils/render-markdown';

/**
 * Renders one inline node.
 *
 * @param props      Component props.
 * @param props.node The inline node.
 * @return The rendered node.
 */
function Inline( { node }: { node: InlineNode } ) {
	switch ( node.type ) {
		case 'text':
			return <>{ node.value }</>;
		case 'strong':
			return (
				<strong>
					<InlineList nodes={ node.children } />
				</strong>
			);
		case 'emphasis':
			return (
				<em>
					<InlineList nodes={ node.children } />
				</em>
			);
		case 'code':
			return <code>{ node.value }</code>;
		case 'image':
			// An image is never fetched. It is described in place, so a model
			// cannot turn a rendered response into an outbound request.
			return (
				<span className="ai-workspace__inert">
					{ sprintf(
						/* translators: 1: image description, 2: image address. */
						__( '[image not shown: %1$s — %2$s]', 'ai' ),
						'' === node.alt
							? __( 'no description', 'ai' )
							: node.alt,
						node.destination
					) }
				</span>
			);
		case 'link':
		default:
			return <Link node={ node } />;
	}
}

/**
 * Renders a link, live or inert, always showing where it goes.
 *
 * @param props      Component props.
 * @param props.node The link node.
 * @return The rendered link.
 */
function Link( { node }: { node: Extract< InlineNode, { type: 'link' } > } ) {
	if ( node.inert ) {
		return (
			<span className="ai-workspace__inert">
				{ sprintf(
					/* translators: 1: link text, 2: link address. */
					__( '%1$s [link not opened: %2$s]', 'ai' ),
					node.label,
					node.destination
				) }
			</span>
		);
	}

	return (
		<>
			<a href={ node.destination } rel="noreferrer noopener">
				{ '' === node.label ? node.destination : node.label }
			</a>{ ' ' }
			<span className="ai-workspace__destination">
				{ sprintf(
					/* translators: %s: link address. */
					__( '(%s)', 'ai' ),
					node.destination
				) }
			</span>
		</>
	);
}

/**
 * Renders a run of inline nodes.
 *
 * @param props       Component props.
 * @param props.nodes The inline nodes.
 * @return The rendered nodes.
 */
function InlineList( { nodes }: { nodes: InlineNode[] } ) {
	return (
		<>
			{ nodes.map( ( node, index ) => (
				<Inline key={ index } node={ node } />
			) ) }
		</>
	);
}

/**
 * Renders a fenced code block with a copy affordance.
 *
 * @param props       Component props.
 * @param props.value The code.
 * @return The rendered block.
 */
function CodeBlock( { value }: { value: string } ) {
	const [ copied, setCopied ] = useState( false );

	const copy = async (): Promise< void > => {
		try {
			await window.navigator.clipboard.writeText( value );
			setCopied( true );
			window.setTimeout( () => setCopied( false ), 2000 );
		} catch {
			setCopied( false );
		}
	};

	return (
		<div className="ai-workspace__code">
			<pre>
				<code>{ value }</code>
			</pre>
			<Button
				variant="secondary"
				size="small"
				onClick={ () => {
					void copy();
				} }
			>
				{ copied ? __( 'Copied', 'ai' ) : __( 'Copy code', 'ai' ) }
			</Button>
		</div>
	);
}

/**
 * Renders a list of block nodes.
 *
 * @param props        Component props.
 * @param props.blocks The block nodes.
 * @return The rendered blocks.
 */
function Blocks( { blocks }: { blocks: BlockNode[] } ) {
	return (
		<>
			{ blocks.map( ( block, index ) => {
				switch ( block.type ) {
					case 'heading': {
						const Tag = ( 'h' + block.level ) as 'h1';

						return (
							<Tag key={ index }>
								<InlineList nodes={ block.children } />
							</Tag>
						);
					}
					case 'codeBlock':
						return (
							<CodeBlock key={ index } value={ block.value } />
						);
					case 'list':
						return block.ordered ? (
							<ol key={ index }>
								{ block.items.map( ( item, position ) => (
									<li key={ position }>
										<InlineList nodes={ item } />
									</li>
								) ) }
							</ol>
						) : (
							<ul key={ index }>
								{ block.items.map( ( item, position ) => (
									<li key={ position }>
										<InlineList nodes={ item } />
									</li>
								) ) }
							</ul>
						);
					case 'blockquote':
						return (
							<blockquote key={ index }>
								<Blocks blocks={ block.children } />
							</blockquote>
						);
					case 'paragraph':
					default:
						return (
							<p key={ index }>
								<InlineList nodes={ block.children } />
							</p>
						);
				}
			} ) }
		</>
	);
}

/**
 * Renders assistant output.
 *
 * @param props        Component props.
 * @param props.source The assistant's markdown.
 * @return The rendered output.
 */
export default function Markdown( { source }: { source: string } ) {
	const blocks = renderMarkdown( source, {
		allowedHost: window.location.host,
	} );

	return (
		<div className="ai-workspace__markdown">
			<Blocks blocks={ blocks } />
		</div>
	);
}
