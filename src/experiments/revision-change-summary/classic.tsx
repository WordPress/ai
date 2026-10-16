/**
 * Revision change summary for the classic revisions screen.
 */

/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';
import { createRoot, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import SummaryPanel from './components/SummaryPanel';
import { useRevisionSummary } from './hooks/useRevisionSummary';
import './index.scss';

interface RevisionModel {
	id: number;
}

interface FrameModel {
	get: ( attribute: 'from' | 'to' ) => RevisionModel | undefined;
	on: ( event: string, callback: () => void ) => void;
	off: ( event: string, callback: () => void ) => void;
}

interface RevisionsGlobal {
	wp?: {
		revisions?: {
			view?: {
				frame?: {
					model?: FrameModel;
				};
			};
		};
	};
}

const CONTAINER_CLASS = 'ai-revision-change-summary-classic';
const MAX_ATTEMPTS = 50;
const INTERVAL_MS = 100;

interface SelectedRevisions {
	from: number | null;
	to: number | null;
}

/**
 * Returns the revisions currently compared on the screen.
 *
 * @param model The revisions frame model.
 * @return The older and newer revision IDs.
 */
function getSelected( model: FrameModel ): SelectedRevisions {
	return {
		from: model.get( 'from' )?.id ?? null,
		to: model.get( 'to' )?.id ?? null,
	};
}

/**
 * Renders the summary panel for the revisions compared on the screen.
 *
 * @param props        Component props.
 * @param props.model  The revisions frame model.
 * @param props.postId The post ID.
 */
function ClassicSummary( {
	model,
	postId,
}: {
	model: FrameModel;
	postId: number;
} ) {
	const [ selected, setSelected ] = useState< SelectedRevisions >( () =>
		getSelected( model )
	);

	useEffect( () => {
		const update = () => setSelected( getSelected( model ) );

		model.on( 'update:revisions', update );

		return () => {
			model.off( 'update:revisions', update );
		};
	}, [ model ] );

	const summary = useRevisionSummary( postId, selected.to, selected.from );

	let unavailableMessage: string | undefined;

	if ( ! selected.from || ! selected.to ) {
		unavailableMessage = __(
			'There is no earlier revision to compare with.',
			'ai'
		);
	} else if ( selected.from === postId || selected.to === postId ) {
		// Core shows the post itself when an autosave is its only revision.
		unavailableMessage = __(
			'Changes can only be summarized between two saved revisions.',
			'ai'
		);
	}

	return (
		<>
			<h2 className="ai-revision-change-summary-classic__title">
				{ __( 'Change summary', 'ai' ) }
			</h2>
			<SummaryPanel
				summary={ summary }
				unavailableMessage={ unavailableMessage }
			/>
		</>
	);
}

/**
 * Mounts the summary panel once core has set up the revisions screen.
 *
 * Core builds the screen on document ready, so this waits briefly for it.
 *
 * @param attempt The current attempt.
 */
function mount( attempt = 0 ) {
	const data = window.aiRevisionChangeSummaryData;
	const postId = Number( data?.postId );

	if ( ! data?.enabled || ! postId ) {
		return;
	}

	const model = ( window as unknown as RevisionsGlobal ).wp?.revisions?.view
		?.frame?.model;
	const controls = document.querySelector( '.revisions-control-frame' );

	if ( ! model || ! controls ) {
		if ( attempt < MAX_ATTEMPTS ) {
			window.setTimeout( () => mount( attempt + 1 ), INTERVAL_MS );
		}
		return;
	}

	if ( document.querySelector( `.${ CONTAINER_CLASS }` ) ) {
		return;
	}

	const container = document.createElement( 'div' );
	container.className = CONTAINER_CLASS;
	controls.after( container );

	createRoot( container ).render(
		<ClassicSummary model={ model } postId={ postId } />
	);
}

domReady( () => mount() );
