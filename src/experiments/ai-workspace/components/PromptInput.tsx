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
import type { KeyboardEvent, MutableRefObject, ReactNode } from 'react';

/**
 * Renders the multi-line prompt input and its turn controls (R2, R9, R11).
 *
 * Focus returns to the textarea whenever a turn finishes, which the app drives
 * through `inputRef`. Clearing the conversation is a header action rather than
 * a composer one: it acts on the whole transcript, not on the draft message.
 *
 * @param props              Component props.
 * @param props.value        The current draft message.
 * @param props.onChange     Draft change handler.
 * @param props.onSubmit     Send handler.
 * @param props.onStop       Stop handler.
 * @param props.isRunning    Whether a turn is in flight.
 * @param props.isStopping   Whether a stop has been requested.
 * @param props.inputRef     Ref for the textarea, used to restore focus.
 * @param props.scopeControl The context-scope control, rendered in the footer.
 * @return The rendered composer.
 */
export default function PromptInput( {
	value,
	onChange,
	onSubmit,
	onStop,
	isRunning,
	isStopping,
	inputRef,
	scopeControl,
}: {
	value: string;
	onChange: ( value: string ) => void;
	onSubmit: () => void;
	onStop: () => void;
	isRunning: boolean;
	isStopping: boolean;
	inputRef: MutableRefObject< HTMLTextAreaElement | null >;
	scopeControl: ReactNode;
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
				hideLabelFromVision
				ref={ inputRef }
				label={ __( 'Message', 'ai' ) }
				placeholder={ __(
					'Ask about your site, or describe what you want to make…',
					'ai'
				) }
				help={ __(
					'Describe what you would like help with. Press Command or Control and Enter to send.',
					'ai'
				) }
				rows={ 3 }
				value={ value }
				disabled={ isRunning }
				onChange={ onChange }
				onKeyDown={ onKeyDown }
			/>

			<div className="ai-workspace__actions">
				{ scopeControl }

				<div className="ai-workspace__actions-end">
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
						variant="primary"
						disabled={ isRunning || '' === value.trim() }
						accessibleWhenDisabled
						onClick={ onSubmit }
					>
						{ __( 'Send', 'ai' ) }
					</Button>
				</div>
			</div>
		</div>
	);
}
