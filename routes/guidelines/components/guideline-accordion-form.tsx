/**
 * External dependencies
 */
import type { FormEvent } from 'react';

/**
 * WordPress dependencies
 */
import {
	Button,
	Notice,
	__experimentalConfirmDialog as ConfirmDialog,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { DataForm } from '@wordpress/dataviews';
import type { Field, Form } from '@wordpress/dataviews';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { deleteGuidelineRow, saveGuidelineRow, scopeSlug } from '../data';
import type { GuidelineQuery, Scope } from '../types';

interface GuidelineAccordionFormProps {
	scope: Scope;
	existingId: number | undefined;
	content: string;
	query: GuidelineQuery;
}

export default function GuidelineAccordionForm( {
	scope,
	existingId,
	content,
	query,
}: GuidelineAccordionFormProps ) {
	const { createSuccessNotice } = useDispatch( noticesStore );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ showClearConfirmation, setShowClearConfirmation ] =
		useState( false );

	const [ draft, setDraft ] = useState( content );
	useEffect( () => setDraft( content ), [ content ] );

	const data = useMemo( () => ( { guidelines: draft } ), [ draft ] );

	const fields: Field< { guidelines: string } >[] = useMemo(
		() => [
			{
				id: 'guidelines',
				label: sprintf(
					/* translators: %s: Guideline section title. */
					__( '%s guidelines', 'ai' ),
					scope.title
				),
				type: 'text',
				Edit: 'textarea',
			},
		],
		[ scope.title ]
	);

	const form: Form = useMemo(
		() => ( {
			layout: { type: 'regular', labelPosition: 'top' },
			fields: [ 'guidelines' ],
		} ),
		[]
	);

	const handleSave = ( event: FormEvent< HTMLFormElement > ) => {
		event.preventDefault();
		setLoading( true );
		saveGuidelineRow(
			scopeSlug( scope.slug ),
			scope.title,
			draft,
			existingId,
			query
		)
			.then( () => {
				setError( null );
				createSuccessNotice( __( 'Guidelines saved.', 'ai' ), {
					type: 'snackbar',
				} );
			} )
			.catch( ( e: Error ) =>
				setError(
					sprintf(
						/* translators: %s: Error message. */
						__( 'Error saving guidelines: %s', 'ai' ),
						e.message
					)
				)
			)
			.finally( () => setLoading( false ) );
	};

	const handleClearClick = () => setShowClearConfirmation( true );

	const handleClearConfirm = () => {
		if ( ! existingId ) {
			setShowClearConfirmation( false );
			return;
		}
		setLoading( true );
		deleteGuidelineRow( existingId )
			.then( () => {
				setError( null );
				createSuccessNotice( __( 'Guidelines cleared.', 'ai' ), {
					type: 'snackbar',
				} );
			} )
			.catch( ( e: Error ) =>
				setError(
					sprintf(
						/* translators: %s: Error message. */
						__( 'Error clearing guidelines: %s', 'ai' ),
						e.message
					)
				)
			)
			.finally( () => {
				setLoading( false );
				setShowClearConfirmation( false );
			} );
	};

	return (
		<form onSubmit={ handleSave }>
			<VStack spacing={ 4 }>
				<DataForm
					data={ data }
					fields={ fields }
					form={ form }
					onChange={ ( edits: Partial< { guidelines: string } > ) =>
						setDraft( edits.guidelines ?? draft )
					}
				/>
				{ error && (
					<Notice status="error" onRemove={ () => setError( null ) }>
						{ error }
					</Notice>
				) }
				<HStack spacing={ 4 } alignment="right">
					<Button
						variant="tertiary"
						type="button"
						disabled={ loading || ! existingId }
						accessibleWhenDisabled
						isBusy={ loading }
						onClick={ handleClearClick }
						__next40pxDefaultSize
					>
						{ __( 'Clear', 'ai' ) }
					</Button>
					<Button
						variant="primary"
						type="submit"
						disabled={ loading || ! draft }
						accessibleWhenDisabled
						isBusy={ loading }
						__next40pxDefaultSize
					>
						{ __( 'Save', 'ai' ) }
					</Button>
				</HStack>
			</VStack>
			<ConfirmDialog
				isOpen={ showClearConfirmation }
				title={ sprintf(
					/* translators: %s: Guideline section title. */
					__( 'Clear %s guideline', 'ai' ),
					scope.title
				) }
				__experimentalHideHeader={ false }
				onConfirm={ handleClearConfirm }
				onCancel={ () => setShowClearConfirmation( false ) }
				confirmButtonText={ __( 'Clear', 'ai' ) }
				isBusy={ loading }
				size="small"
			>
				{ sprintf(
					/* translators: %s: Guideline section title. */
					__( 'You are about to clear the %s guideline.', 'ai' ),
					scope.title
				) }
			</ConfirmDialog>
		</form>
	);
}
