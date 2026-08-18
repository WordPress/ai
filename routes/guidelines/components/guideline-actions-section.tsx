/**
 * External dependencies
 */
import type { ChangeEvent } from 'react';

/**
 * WordPress dependencies
 */
import {
	Card,
	Notice,
	__experimentalConfirmDialog as ConfirmDialog,
	__experimentalHeading as Heading,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { exportGuidelines, importGuidelines } from '../import-export';
import type {
	ContentBlock,
	GuidelineQuery,
	GuidelineRow,
	Scope,
} from '../types';
import ActionItem from './action-item';
import './guideline-actions-section.scss';

function getErrorMessage( err: unknown ) {
	return err instanceof Error ? err.message : __( 'Unknown error', 'ai' );
}

interface GuidelineActionsSectionProps {
	scopes: Scope[];
	contentBlocks: ContentBlock[];
	bySlug: Record< string, GuidelineRow >;
	query: GuidelineQuery;
}

export default function GuidelineActionsSection( {
	scopes,
	contentBlocks,
	bySlug,
	query,
}: GuidelineActionsSectionProps ) {
	const fileInputRef = useRef< HTMLInputElement >( null );
	const [ isImporting, setIsImporting ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ pendingImport, setPendingImport ] = useState< File | null >( null );

	function handleImportClick() {
		fileInputRef.current?.click();
	}

	function handleFileChange( event: ChangeEvent< HTMLInputElement > ) {
		const file = event.target.files?.[ 0 ];
		event.target.value = ''; // Allow re-selecting the same file.
		if ( ! file ) {
			return;
		}
		setPendingImport( file );
	}

	async function handleModalContinue() {
		if ( ! pendingImport ) {
			return;
		}
		const file = pendingImport;
		setPendingImport( null );
		setIsImporting( true );
		try {
			await importGuidelines(
				file,
				scopes,
				bySlug,
				contentBlocks,
				query
			);
			setError( null );
		} catch ( err ) {
			setError(
				sprintf(
					/* translators: %s: Error message. */
					__(
						'We ran into a problem importing your guidelines: %s',
						'ai'
					),
					getErrorMessage( err )
				)
			);
		} finally {
			setIsImporting( false );
		}
	}

	function handleExportClick() {
		try {
			exportGuidelines( scopes, bySlug, contentBlocks );
			setError( null );
		} catch ( err ) {
			setError(
				sprintf(
					/* translators: %s: Error message. */
					__(
						'We ran into a problem exporting your guidelines: %s',
						'ai'
					),
					getErrorMessage( err )
				)
			);
		}
	}

	const ACTIONS = [
		{
			slug: 'import' as const,
			title: __( 'Import', 'ai' ),
			description: __(
				'Upload a JSON file to import your guidelines.',
				'ai'
			),
			buttonLabel: __( 'Upload', 'ai' ),
			ariaLabel: __( 'Import guidelines', 'ai' ),
			onClick: handleImportClick,
			isBusy: isImporting,
			disabled: isImporting || !! pendingImport,
		},
		{
			slug: 'export' as const,
			title: __( 'Export', 'ai' ),
			description: __( 'Export your guidelines to a JSON file.', 'ai' ),
			buttonLabel: __( 'Download', 'ai' ),
			ariaLabel: __( 'Export guidelines', 'ai' ),
			onClick: handleExportClick,
		},
	];

	return (
		<VStack spacing={ 4 } className="guidelines__actions">
			<Heading level={ 3 } size={ 15 }>
				{ __( 'Actions', 'ai' ) }
			</Heading>
			<input
				type="file"
				accept=".json"
				ref={ fileInputRef }
				onChange={ handleFileChange }
				style={ { display: 'none' } }
			/>
			{ error && (
				<Notice
					status="error"
					onRemove={ () => setError( null ) }
					isDismissible
				>
					{ error }
				</Notice>
			) }
			<Card className="guidelines__actions-card">
				{ /*
				 * Disable reason: The `list` ARIA role is redundant but
				 * Safari+VoiceOver won't announce the list otherwise.
				 */
				/* eslint-disable jsx-a11y/no-redundant-roles */ }
				<ul role="list" className="guidelines__actions-list">
					{ ACTIONS.map( ( action ) => (
						<li
							key={ action.slug }
							className="guidelines__action-list-item"
						>
							<ActionItem { ...action } />
						</li>
					) ) }
				</ul>
				{ /* eslint-enable jsx-a11y/no-redundant-roles */ }
			</Card>

			<ConfirmDialog
				isOpen={ !! pendingImport }
				__experimentalHideHeader={ false }
				title={ __( 'Import guidelines', 'ai' ) }
				confirmButtonText={ __( 'Continue', 'ai' ) }
				onConfirm={ handleModalContinue }
				onCancel={ () => setPendingImport( null ) }
				size="small"
			>
				{ __(
					'Importing new guidelines will replace your current guidelines.',
					'ai'
				) }
			</ConfirmDialog>
		</VStack>
	);
}
