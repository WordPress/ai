/**
 * AI Refine Notes plugin component.
 */

/**
 * WordPress dependencies
 */
import { Button, Flex, FlexItem } from '@wordpress/components';
import { PluginPostStatusInfo } from '@wordpress/editor';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { customLink } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { useRefineNotes } from '../hooks/useRefineNotes';

/**
 * RefineNotesPlugin component.
 *
 * Renders a "Refine from Notes" button in the post status info panel
 * when unresolved Notes exist.
 */
export default function RefineNotesPlugin() {
	const { isRefining, hasPendingNotes, checkPendingNotes, runRefinement } =
		useRefineNotes();

	// Check for pending notes when the component mounts and when window gains focus.
	useEffect( () => {
		checkPendingNotes();

		const handleFocus = () => checkPendingNotes();
		window.addEventListener( 'focus', handleFocus );
		return () => {
			window.removeEventListener( 'focus', handleFocus );
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	if ( ! ( window as any ).aiRefineNotesData?.enabled ) {
		return null;
	}

	// Always render the container, but hide the button if no notes,
	// or maybe render it disabled if no notes? Let's hide it if no pending notes
	// based on the issue description, "when Notes are present".
	if ( ! hasPendingNotes && ! isRefining ) {
		return null;
	}

	const buttonLabel = isRefining
		? __( 'Refining blocks…', 'ai' )
		: __( 'Refine from Notes', 'ai' );
	const buttonDescription = __(
		'Automatically updates blocks using unresolved editorial feedback Notes.',
		'ai'
	);

	return (
		<PluginPostStatusInfo>
			<Flex direction="column" gap={ 2 }>
				<FlexItem>
					<Button
						variant="secondary"
						icon={ customLink }
						onClick={ () => runRefinement() }
						isBusy={ isRefining }
						disabled={ isRefining }
						style={ {
							justifyContent: 'center',
							width: '100%',
						} }
						__next40pxDefaultSize
					>
						{ buttonLabel }
					</Button>
				</FlexItem>
				<FlexItem>
					<span
						className="description"
						style={ { color: '#757575' } }
					>
						{ buttonDescription }
					</span>
				</FlexItem>
			</Flex>
		</PluginPostStatusInfo>
	);
}
