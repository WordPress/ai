/**
 * One change of an update card: the current value beside the new one.
 */

/**
 * WordPress dependencies
 */
import {
	Button,
	CheckboxControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { RenderedHtml } from './Markdown';
import {
	changeLabel,
	editFieldLabel,
	liveNotice,
} from '../utils/proposal-card';
import type { ProposalItem } from '../types';

/**
 * Shows one value as the reader will see it.
 *
 * Content is post markup and is rendered through the sanitised renderer; every
 * other field is plain text and is shown as text.
 *
 * @param props       Component props.
 * @param props.field The field the value belongs to.
 * @param props.value The value.
 * @return The value.
 */
function Value( { field, value }: { field: string; value: string } ) {
	if ( '' === value.trim() ) {
		return (
			<p className="ai-workspace__change-empty">
				{ __( '(empty)', 'ai' ) }
			</p>
		);
	}

	if ( 'content' === field ) {
		return <RenderedHtml source={ value } />;
	}

	return <p className="ai-workspace__proposal-value">{ value }</p>;
}

/**
 * Renders one proposed change with its current value, its new value, a way to
 * edit the new value, and its checkbox.
 *
 * The current value stays rendered while the new one is edited, so the two can
 * be compared. A message the server returned for an edit is shown under the
 * field it is about.
 *
 * @param props            Component props.
 * @param props.item       The proposed change.
 * @param props.isSelected Whether it will be applied.
 * @param props.onSelect   Called with the new selection state.
 * @param props.edit       The edited value, or null while it is not edited.
 * @param props.onEdit     Called with the edited value, or null to undo it.
 * @param props.error      The server's message about the edit, if any.
 * @param props.disabled   True while a request is out.
 * @return The change.
 */
export default function ProposedChange( {
	item,
	isSelected,
	onSelect,
	edit,
	onEdit,
	error,
	disabled,
}: {
	item: ProposalItem;
	isSelected: boolean;
	onSelect: ( isChecked: boolean ) => void;
	edit: string | null;
	onEdit: ( value: string | null ) => void;
	error: string;
	disabled: boolean;
} ) {
	const label = changeLabel( item );
	const notice = liveNotice( item );
	const isEditing = null !== edit;
	const field = useRef< HTMLInputElement & HTMLTextAreaElement >( null );

	// Focus moves into the field only when the person opened it, not on load.
	const [ opened, setOpened ] = useState( false );

	useEffect( () => {
		if ( opened && isEditing ) {
			field.current?.focus();
			setOpened( false );
		}
	}, [ opened, isEditing ] );

	const fieldLabel = editFieldLabel( item );

	// The server's message sits under the field, as its help text.
	const invalid =
		'' === error ? {} : { help: error, 'aria-invalid': true as const };

	const editor = ( () => {
		if ( null === edit ) {
			return null;
		}

		if ( 'excerpt' !== item.field && 'content' !== item.field ) {
			return (
				<TextControl
					ref={ field }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ fieldLabel }
					value={ edit }
					onChange={ ( value: string ) => onEdit( value ) }
					{ ...invalid }
					disabled={ disabled }
				/>
			);
		}

		return (
			<TextareaControl
				ref={ field }
				__nextHasNoMarginBottom
				{ ...( 'content' === item.field
					? { className: 'ai-workspace__change-markup' }
					: {} ) }
				label={ fieldLabel }
				value={ edit }
				rows={ 'content' === item.field ? 8 : 3 }
				onChange={ ( value: string ) => onEdit( value ) }
				{ ...invalid }
				disabled={ disabled }
			/>
		);
	} )();

	return (
		<div
			role="group"
			aria-label={ label }
			className={
				'' === error
					? 'ai-workspace__proposal-item ai-workspace__change'
					: 'ai-workspace__proposal-item ai-workspace__change has-error'
			}
		>
			<CheckboxControl
				__nextHasNoMarginBottom
				checked={ isSelected }
				onChange={ onSelect }
				label={ label }
				help={ '' === notice ? undefined : notice }
				disabled={ disabled }
			/>

			<div className="ai-workspace__change-columns">
				<div className="ai-workspace__change-current">
					<span className="ai-workspace__change-heading">
						{ __( 'Current', 'ai' ) }
					</span>
					<Value field={ item.field } value={ item.current } />
				</div>
				<div className="ai-workspace__change-new">
					<span className="ai-workspace__change-heading">
						{ __( 'New', 'ai' ) }
					</span>
					{ null === edit ? (
						<Value field={ item.field } value={ item.value } />
					) : (
						editor
					) }
					{ null !== edit && 'content' === item.field && (
						<div className="ai-workspace__change-preview">
							<span className="ai-workspace__change-heading">
								{ __( 'Preview', 'ai' ) }
							</span>
							<RenderedHtml source={ edit } />
						</div>
					) }
				</div>
			</div>

			<div className="ai-workspace__change-actions">
				{ isEditing ? (
					<Button
						size="compact"
						variant="tertiary"
						disabled={ disabled }
						onClick={ () => onEdit( null ) }
					>
						{ __( 'Undo edit', 'ai' ) }
					</Button>
				) : (
					<Button
						size="compact"
						variant="secondary"
						disabled={ disabled }
						label={ sprintf(
							/* translators: %s: what is changed, such as Title of “Hello world!”. */
							__( 'Edit the new value of %s', 'ai' ),
							label
						) }
						showTooltip={ false }
						onClick={ () => {
							onEdit( item.value );
							setOpened( true );
						} }
					>
						{ __( 'Edit', 'ai' ) }
					</Button>
				) }
			</div>
		</div>
	);
}
