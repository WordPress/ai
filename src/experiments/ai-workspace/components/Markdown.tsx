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
import { speak } from '@wordpress/a11y';
import { Button } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { copy as copyIcon } from '@wordpress/icons';

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
		case 'break':
			return <br />;
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
 * Line count above which a code block starts collapsed.
 *
 * A generated template runs to dozens of lines; shown in full it pushed the
 * rest of the answer, and the next turn, out of the transcript.
 */
const COLLAPSE_AFTER_LINES = 12;

/**
 * Renders a fenced code block with a copy affordance.
 *
 * A long block is collapsed until the person asks to see all of it. The copy
 * control always takes the whole block, collapsed or not.
 *
 * @param props          Component props.
 * @param props.language The fence's language, or an empty string.
 * @param props.value    The code.
 * @return The rendered block.
 */
function CodeBlock( { language, value }: { language: string; value: string } ) {
	const [ expanded, setExpanded ] = useState( false );
	const codeId = useInstanceId( CodeBlock, 'ai-workspace-code' );
	const lineCount = value.split( '\n' ).length;
	const collapsible = lineCount > COLLAPSE_AFTER_LINES;

	const copy = async (): Promise< void > => {
		try {
			await window.navigator.clipboard.writeText( value );
			speak( __( 'Copied', 'ai' ) );
		} catch {
			speak( __( 'The code could not be copied.', 'ai' ), 'assertive' );
		}
	};

	return (
		<div
			className={
				collapsible && ! expanded
					? 'ai-workspace__code is-collapsed'
					: 'ai-workspace__code'
			}
		>
			<div className="ai-workspace__code-header">
				{ '' !== language && (
					<span className="ai-workspace__code-language">
						{ language }
					</span>
				) }
				<Button
					className="ai-workspace__code-copy"
					icon={ copyIcon }
					label={ __( 'Copy code', 'ai' ) }
					size="small"
					onClick={ () => {
						void copy();
					} }
				/>
			</div>
			<pre id={ codeId }>
				<code>{ value }</code>
			</pre>
			{ collapsible && (
				<Button
					className="ai-workspace__code-toggle"
					variant="link"
					aria-expanded={ expanded }
					aria-controls={ codeId }
					onClick={ () => setExpanded( ! expanded ) }
				>
					{ expanded
						? __( 'Show fewer lines', 'ai' )
						: sprintf(
								/* translators: %d: number of lines in the code block. */
								_n(
									'Show all %d line',
									'Show all %d lines',
									lineCount,
									'ai'
								),
								lineCount
						  ) }
				</Button>
			) }
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
							<CodeBlock
								key={ index }
								language={ block.language }
								value={ block.value }
							/>
						);
					case 'list':
						return block.ordered ? (
							<ol
								key={ index }
								start={
									1 === block.start ? undefined : block.start
								}
							>
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
