/**
 * The workspace composer: the message input and its turn controls.
 */

/**
 * WordPress dependencies
 */
import { Button, TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * External dependencies
 */
import type { KeyboardEvent, MutableRefObject } from 'react';

/**
 * Renders the multi-line prompt input, with send, stop and clear (R2, R9, R11).
 *
 * Focus returns to the textarea whenever a turn finishes, which the app drives
 * through `focusSignal`.
 *
 * @param props            Component props.
 * @param props.value      The current draft message.
 * @param props.onChange   Draft change handler.
 * @param props.onSubmit   Send handler.
 * @param props.onStop     Stop handler.
 * @param props.onClear    Clear handler.
 * @param props.isRunning  Whether a turn is in flight.
 * @param props.isStopping Whether a stop has been requested.
 * @param props.canClear   Whether there is a conversation to clear.
 * @param props.inputRef   Ref for the textarea, used to restore focus.
 * @return The rendered composer.
 */
export default function PromptInput( {
	value,
	onChange,
	onSubmit,
	onStop,
	onClear,
	isRunning,
	isStopping,
	canClear,
	inputRef,
}: {
	value: string;
	onChange: ( value: string ) => void;
	onSubmit: () => void;
	onStop: () => void;
	onClear: () => void;
	isRunning: boolean;
	isStopping: boolean;
	canClear: boolean;
	inputRef: MutableRefObject< HTMLTextAreaElement | null >;
} ) {
	const onKeyDown = ( event: KeyboardEvent< HTMLTextAreaElement > ): void => {
		if ( 'Enter' === event.key && ( event.metaKey || event.ctrlKey ) ) {
			event.preventDefault();
			onSubmit();
		}
	};

	return (
		<div className="ai-workspace__composer-fields">
			<TextareaControl
				__nextHasNoMarginBottom
				ref={ inputRef }
				label={ __( 'Message', 'ai' ) }
				help={ __(
					'Describe what you would like help with. Press Command or Control and Enter to send.',
					'ai'
				) }
				rows={ 4 }
				value={ value }
				disabled={ isRunning }
				onChange={ onChange }
				onKeyDown={ onKeyDown }
			/>

			<div className="ai-workspace__actions">
				<Button
					__next40pxDefaultSize
					variant="primary"
					disabled={ isRunning || '' === value.trim() }
					accessibleWhenDisabled
					onClick={ onSubmit }
				>
					{ __( 'Send', 'ai' ) }
				</Button>

				<Button
					__next40pxDefaultSize
					variant="secondary"
					disabled={ ! isRunning || isStopping }
					accessibleWhenDisabled
					onClick={ onStop }
				>
					{ isStopping
						? __( 'Stopping…', 'ai' )
						: __( 'Stop', 'ai' ) }
				</Button>

				<Button
					__next40pxDefaultSize
					variant="tertiary"
					disabled={ ! canClear || isRunning }
					accessibleWhenDisabled
					onClick={ onClear }
				>
					{ __( 'Clear conversation', 'ai' ) }
				</Button>
			</div>
		</div>
	);
}
