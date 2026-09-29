/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { Button, Notice, VisuallyHidden } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import RouterLink, { BackToListLink } from './RouterLink';
import JsonBlock, { formatJson } from './JsonBlock';
import { invokeAbility } from '../api';
import { useExplorer } from '../context';
import {
	isEmptyJson,
	isJsonRecord,
	isSendableJson,
	validateInput,
	type ValidationResult,
} from '../validate';
import type { AbilityDetailItem } from '../types';
import './detail.scss';

export interface TestRunnerProps {
	/**
	 * The ability, loaded from the item route by the app shell. The shell
	 * keys this component by ability name, so its state starts fresh for each
	 * ability.
	 */
	item: AbilityDetailItem;
}

/**
 * What the Result panel shows.
 */
type RunnerResult =
	| { success: true; data: unknown }
	| { success: false; error: unknown };

/**
 * The runner's state. It names the ability it belongs to, so state for one
 * ability is never shown in another's runner.
 */
interface RunnerState {
	ability: string;
	/** The textarea's value, sent as is. */
	input: string;
	validation: ValidationResult | null;
	result: RunnerResult | null;
	pending: boolean;
}

const TEXTAREA_ID = 'ai-abilities-explorer-test-input';

/*
 * An integer literal JavaScript cannot hold exactly. Reformatting text that
 * contains one would round it, and the raw string is sent so it is not (KTD7).
 */
const UNSAFE_INTEGER = /\d{16,}/;

/**
 * The textarea's starting value: the example input from the item route.
 *
 * With no input schema the textarea starts empty, which Invoke sends as
 * "no input". A schema whose example is empty starts as `{}` when it takes an
 * object, and empty otherwise.
 *
 * @param item The ability.
 * @return The starting text.
 */
export function initialInput( item: AbilityDetailItem ): string {
	const schema = item.input_schema;

	if ( isEmptyJson( schema ) ) {
		return '';
	}

	if ( ! isEmptyJson( item.example_input ) ) {
		return formatJson( item.example_input );
	}

	const { type } = isJsonRecord( schema ) ? schema : { type: undefined };

	return undefined === type || 'object' === type ? '{}' : '';
}

/**
 * The runner's fresh state for an ability.
 *
 * @param item The ability.
 * @return The state.
 */
const freshState = ( item: AbilityDetailItem ): RunnerState => ( {
	ability: item.slug,
	input: initialInput( item ),
	validation: null,
	result: null,
	pending: false,
} );

/**
 * The textarea's class for live JSON syntax feedback.
 *
 * @param input The textarea's value.
 * @return The class, or '' when empty.
 */
const syntaxClass = ( input: string ): string => {
	if ( '' === input.trim() ) {
		return '';
	}

	return isSendableJson( input ) ? 'is-json-valid' : 'is-json-invalid';
};

/**
 * Builds what the Error panel shows for a failed request.
 *
 * @param code    The REST error code.
 * @param message The message.
 * @param data    The error's data.
 * @return The value to show.
 */
const requestError = (
	code: string,
	message: string,
	data: unknown
): Record< string, unknown > => {
	const { errors } = isJsonRecord( data ) ? data : { errors: null };

	return Array.isArray( errors )
		? { code, message, errors }
		: { code, message };
};

interface ValidationPanelProps {
	validation: ValidationResult;
}

/**
 * The outcome of Validate Input, or of an Invoke refused before sending.
 *
 * @param props            Component props.
 * @param props.validation The outcome.
 * @return The panel.
 */
function ValidationPanel( { validation }: ValidationPanelProps ) {
	return (
		<div
			className={ `ai-abilities-explorer__validation ${
				validation.valid ? 'is-success' : 'is-error'
			}` }
		>
			<h4>
				<span aria-hidden="true">{ validation.valid ? '✓' : '✗' }</span>{ ' ' }
				{ validation.valid
					? __( 'Valid', 'ai' )
					: __( 'Validation Errors', 'ai' ) }
			</h4>
			{ validation.messages.length > 0 && (
				<ul>
					{ validation.messages.map( ( message, index ) => (
						<li key={ index }>{ message }</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

/**
 * The test runner for one ability.
 *
 * @param props      Component props.
 * @param props.item The ability.
 * @return The view.
 */
export default function TestRunner( { item }: TestRunnerProps ) {
	const { notify, reportError } = useExplorer();
	const [ state, setState ] = useState< RunnerState >( () =>
		freshState( item )
	);

	/*
	 * Runner state is keyed by ability name. If this component is ever given
	 * another ability without remounting, start fresh for it: the example
	 * input fills once per ability, and nothing from the previous one shows.
	 */
	if ( state.ability !== item.slug ) {
		setState( freshState( item ) );
	}

	const currentAbility = useRef( item.slug );
	currentAbility.current = item.slug;

	const request = useRef< {
		id: number;
		controller: AbortController;
	} | null >( null );
	const requestCount = useRef( 0 );
	const resultRef = useRef< HTMLElement >( null );

	/*
	 * A response that arrives after the runner has left this ability, or
	 * unmounted, is dropped: its request is aborted and no longer current.
	 */
	useEffect(
		() => () => {
			request.current?.controller.abort();
			request.current = null;
		},
		[ item.slug ]
	);

	useEffect( () => {
		const node = resultRef.current;

		if ( ! state.result || ! node || ! node.scrollIntoView ) {
			return;
		}

		const reduceMotion = window.matchMedia?.(
			'(prefers-reduced-motion: reduce)'
		).matches;

		node.scrollIntoView( {
			behavior: reduceMotion ? 'auto' : 'smooth',
			block: 'start',
		} );
	}, [ state.result ] );

	const hasSchema = ! isEmptyJson( item.input_schema );

	/**
	 * Applies an update only while the runner still shows `ability`.
	 *
	 * @param ability The ability the update belongs to.
	 * @param update  The update.
	 */
	const updateFor = (
		ability: string,
		update: ( previous: RunnerState ) => RunnerState
	) => {
		setState( ( previous ) =>
			previous.ability === ability ? update( previous ) : previous
		);
	};

	const onInvoke = async () => {
		// One invoke at a time; a second click while one is pending sends nothing.
		if ( null !== request.current ) {
			return;
		}

		const ability = item.slug;
		const rawInput = state.input;

		if ( ! isSendableJson( rawInput ) ) {
			const validation = {
				valid: false,
				messages: [ __( 'Invalid JSON input', 'ai' ) ],
			};

			updateFor( ability, ( previous ) => ( {
				...previous,
				validation,
			} ) );
			speak( __( 'Invalid JSON input', 'ai' ), 'assertive' );

			return;
		}

		const id = ++requestCount.current;
		const controller = new AbortController();
		request.current = { id, controller };

		const isCurrent = () =>
			request.current?.id === id && currentAbility.current === ability;

		updateFor( ability, ( previous ) => ( {
			...previous,
			pending: true,
			result: null,
			validation: null,
		} ) );
		speak( __( 'Invoking ability…', 'ai' ) );

		let result: RunnerResult | null = null;

		try {
			const response = await invokeAbility( ability, rawInput, {
				signal: controller.signal,
			} );

			if ( ! isCurrent() ) {
				return;
			}

			if ( response.success ) {
				result = { success: true, data: response.data };
				notify(
					'success',
					__( 'Ability invoked successfully.', 'ai' )
				);
			} else {
				result = { success: false, error: response.error };
				notify(
					'error',
					response.error.message ||
						__( 'Unknown error occurred.', 'ai' )
				);
			}
		} catch ( error ) {
			if ( ! isCurrent() ) {
				return;
			}

			// Fatal and aborted failures are the screen's to show, not the panel's.
			const classified = reportError( error );

			if (
				'failure' === classified.kind ||
				'not-found' === classified.kind
			) {
				result = {
					success: false,
					error: requestError(
						classified.code,
						classified.message,
						classified.data
					),
				};
			}
		} finally {
			if ( isCurrent() ) {
				request.current = null;
				updateFor( ability, ( previous ) => ( {
					...previous,
					pending: false,
					result,
				} ) );
			}
		}
	};

	const onValidate = () => {
		const validation = validateInput( state.input, item.input_schema );

		updateFor( item.slug, ( previous ) => ( { ...previous, validation } ) );
		speak(
			[
				validation.valid
					? __( 'Valid', 'ai' )
					: __( 'Validation Errors', 'ai' ),
				...validation.messages,
			].join( ' ' ),
			validation.valid ? 'polite' : 'assertive'
		);
	};

	const onClear = () => {
		updateFor( item.slug, ( previous ) => ( {
			...previous,
			result: null,
			validation: null,
		} ) );
	};

	const onChange = ( event: React.ChangeEvent< HTMLTextAreaElement > ) => {
		const input = event.target.value;

		updateFor( item.slug, ( previous ) => ( { ...previous, input } ) );
	};

	// Pretty-prints valid JSON when the textarea loses focus.
	const onBlur = () => {
		const trimmed = state.input.trim();

		if ( '' === trimmed || UNSAFE_INTEGER.test( trimmed ) ) {
			return;
		}

		try {
			const formatted = formatJson( JSON.parse( trimmed ) );

			updateFor( item.slug, ( previous ) =>
				previous.input === state.input
					? { ...previous, input: formatted }
					: previous
			);
		} catch {
			// Invalid JSON stays as typed.
		}
	};

	return (
		<div className="ai-abilities-explorer__runner">
			<div className="ai-abilities-explorer__view-actions">
				<BackToListLink />
				<RouterLink
					route={ { view: 'detail', ability: item.slug } }
					variant="secondary"
				>
					{ __( 'View Details', 'ai' ) }
				</RouterLink>
			</div>

			<h2>
				{ __( 'Test Ability:', 'ai' ) } { item.name || item.slug }
			</h2>
			<p className="ai-abilities-explorer__slug">
				<code>{ item.slug }</code>
			</p>

			{ !! item.description && (
				<p className="description">{ item.description }</p>
			) }

			<section className="ai-abilities-explorer__section">
				<h3>{ __( 'Input Data', 'ai' ) }</h3>
				{ hasSchema ? (
					<>
						<p className="description">
							{ __(
								'Edit the JSON input below to test the ability. The input will be validated against the input schema if available.',
								'ai'
							) }
						</p>
						<Notice
							status="info"
							isDismissible={ false }
							className="ai-abilities-explorer__runner-notice"
						>
							<strong>{ __( 'How to test:', 'ai' ) }</strong>
							<ol>
								<li>
									{ __(
										'Edit the JSON input below with your test data',
										'ai'
									) }
								</li>
								<li>
									{ __(
										'Click "Validate Input" to check your JSON is correct',
										'ai'
									) }
								</li>
								<li>
									{ __(
										'Click "Invoke Ability" to execute the ability with your input',
										'ai'
									) }
								</li>
								<li>
									{ __( 'View the results below', 'ai' ) }
								</li>
							</ol>
						</Notice>
					</>
				) : (
					<Notice
						status="warning"
						isDismissible={ false }
						className="ai-abilities-explorer__runner-notice"
					>
						<strong>{ __( 'No Input Required', 'ai' ) }</strong>
						<br />
						{ __(
							'This ability does not accept any input parameters. Simply click "Invoke Ability" to execute it.',
							'ai'
						) }
					</Notice>
				) }

				<VisuallyHidden as="label" htmlFor={ TEXTAREA_ID }>
					{ __( 'Ability test input (JSON)', 'ai' ) }
				</VisuallyHidden>
				<textarea
					id={ TEXTAREA_ID }
					className={ `ai-abilities-explorer__input ${ syntaxClass(
						state.input
					) }` }
					rows={ 12 }
					spellCheck={ false }
					value={ state.input }
					onChange={ onChange }
					onBlur={ onBlur }
				/>

				<div className="ai-abilities-explorer__runner-actions">
					<Button
						__next40pxDefaultSize
						variant="primary"
						onClick={ onInvoke }
						isBusy={ state.pending }
						disabled={ state.pending }
						accessibleWhenDisabled
					>
						{ __( 'Invoke Ability', 'ai' ) }
					</Button>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						onClick={ onValidate }
					>
						{ __( 'Validate Input', 'ai' ) }
					</Button>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						onClick={ onClear }
					>
						{ __( 'Clear Result', 'ai' ) }
					</Button>
				</div>

				{ state.validation && (
					<ValidationPanel validation={ state.validation } />
				) }
			</section>

			{ state.result && (
				<section
					className="ai-abilities-explorer__section ai-abilities-explorer__result"
					ref={ resultRef }
				>
					<h3>{ __( 'Result', 'ai' ) }</h3>
					<div
						className={ `ai-abilities-explorer__result-body ${
							state.result.success ? 'is-success' : 'is-error'
						}` }
					>
						<h4>
							{ state.result.success
								? __( 'Success!', 'ai' )
								: __( 'Error', 'ai' ) }
						</h4>
						<pre>
							{ formatJson(
								state.result.success
									? state.result.data
									: state.result.error
							) }
						</pre>
					</div>
				</section>
			) }

			{ hasSchema && (
				<section className="ai-abilities-explorer__section">
					<h3>{ __( 'Input Schema Reference', 'ai' ) }</h3>
					<JsonBlock
						value={ item.input_schema }
						label={ __( 'Input Schema', 'ai' ) }
					/>
				</section>
			) }
		</div>
	);
}
