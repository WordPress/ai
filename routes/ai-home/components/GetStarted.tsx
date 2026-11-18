/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import {
	useCallback,
	useEffect,
	useId,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Badge, Button, ButtonLink } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import {
	COMPLETED_SETTING,
	HIDDEN_USER_META,
	buildUseCaseEdits,
	getChosenFeatureIds,
	normalizeChosen,
} from '../onboarding';
import type { OnboardingData, TryPointer, UseCase } from '../onboarding';

type GuideStep = 'connect' | 'choose' | 'try' | 'done';

interface GetStartedProps {
	/** Guide data passed from PHP. */
	onboarding: OnboardingData;
	/** Whether any AI provider has credentials. */
	hasCredentials: boolean;
	/** Whether a connected provider can generate text. */
	hasValidCredentials: boolean;
	/** URL of the Connectors screen. */
	connectorsUrl: string;
	/** The saved use case and add-on IDs. */
	selected: string[];
	/** Whether the last step was finished or skipped. */
	completed: boolean;
	/** The number of Features and Experiments that are on. */
	enabledCount: number;
	/** Saves settings edits. Resolves to whether the save succeeded. */
	onSave: (
		edits: Record< string, unknown >,
		options?: { message?: string }
	) => Promise< boolean >;
	/** Moves to the full list of settings below the guide. */
	onShowAllSettings: () => void;
	/** Shown above the guide while it is collapsed, such as the provider notice. */
	collapsedNotice?: React.ReactNode;
}

const STEPS: Array< { id: GuideStep; label: string } > = [
	{ id: 'connect', label: __( 'Connect', 'ai' ) },
	{ id: 'choose', label: __( 'Choose', 'ai' ) },
	{ id: 'try', label: __( 'Try it', 'ai' ) },
];

/**
 * Renders a Wapuu sprite. The sprite is decorative, so it has no alt text.
 *
 * @param props         The component props.
 * @param props.sprites Sprite URLs by pose.
 * @param props.pose    The pose to show.
 * @param props.size    The rendered size in pixels.
 * @return The sprite image, or null when the pose has no sprite.
 */
function Wapuu( {
	sprites,
	pose,
	size = 72,
}: {
	sprites: Record< string, string >;
	pose: string;
	size?: number;
} ): React.JSX.Element | null {
	const src = sprites[ pose ];
	if ( ! src ) {
		return null;
	}

	return (
		<img
			className="ai-get-started__wapuu"
			src={ src }
			alt=""
			width={ size }
			height={ size }
		/>
	);
}

function getToolCountLabel( count: number ): string {
	return sprintf(
		// translators: %d: Number of AI tools.
		_n( '%d tool', '%d tools', count, 'ai' ),
		count
	);
}

function getToolsOnLabel( count: number ): string {
	return sprintf(
		// translators: %d: Number of AI tools that are turned on.
		_n( '%d tool is on.', '%d tools are on.', count, 'ai' ),
		count
	);
}

/**
 * Shows the three steps and which one is current.
 *
 * @param props         The component props.
 * @param props.current The current step.
 * @return The step tracker.
 */
function StepTracker( { current }: { current: GuideStep } ): React.JSX.Element {
	const currentIndex =
		current === 'done'
			? STEPS.length
			: STEPS.findIndex( ( step ) => step.id === current );

	return (
		<ol className="ai-get-started__steps">
			{ STEPS.map( ( step, index ) => {
				let className = 'ai-get-started__step';
				let status = '';
				if ( index < currentIndex ) {
					className += ' is-done';
					status = __( 'Done', 'ai' );
				} else if ( index === currentIndex ) {
					className += ' is-current';
					status = __( 'Current step', 'ai' );
				}

				return (
					<li
						key={ step.id }
						className={ className }
						aria-current={
							index === currentIndex ? 'step' : undefined
						}
					>
						<span
							className="ai-get-started__step-number"
							aria-hidden="true"
						>
							{ index < currentIndex ? '✓' : index + 1 }
						</span>
						<span>{ step.label }</span>
						{ status && (
							<span className="screen-reader-text">
								{ status }
							</span>
						) }
					</li>
				);
			} ) }
		</ol>
	);
}

/**
 * One selectable use case, with its optional automatic add-on.
 *
 * @param props            The component props.
 * @param props.useCase    The use case.
 * @param props.sprites    Sprite URLs by pose.
 * @param props.chosen     The chosen use case and add-on IDs.
 * @param props.isDisabled Whether choices are locked, such as while saving.
 * @param props.onToggle   Called with an ID and whether it is now chosen.
 * @return The use case card.
 */
function UseCaseCard( {
	useCase,
	sprites,
	chosen,
	isDisabled,
	onToggle,
}: {
	useCase: UseCase;
	sprites: Record< string, string >;
	chosen: string[];
	isDisabled: boolean;
	onToggle: ( id: string, isChosen: boolean ) => void;
} ): React.JSX.Element {
	const baseId = useId();
	const isChosen = chosen.includes( useCase.id );
	const isUnavailable = useCase.unsupported.length >= useCase.features.length;
	const addon = useCase.addon;

	let note = '';
	if ( isUnavailable ) {
		note = __(
			'Your AI provider cannot do this yet. Connect another provider to use it.',
			'ai'
		);
	} else if ( useCase.unsupported.length > 0 ) {
		note = sprintf(
			// translators: %s: Comma-separated list of AI tool names.
			__(
				'Your AI provider cannot run these yet: %s. The rest will work.',
				'ai'
			),
			useCase.unsupported.join( ', ' )
		);
	}

	return (
		<div
			className={
				'ai-get-started__card' +
				( isChosen ? ' is-chosen' : '' ) +
				( isUnavailable ? ' is-unavailable' : '' )
			}
		>
			<label
				className="ai-get-started__card-main"
				htmlFor={ `${ baseId }-input` }
			>
				<input
					id={ `${ baseId }-input` }
					type="checkbox"
					checked={ isChosen }
					disabled={ isDisabled || ( isUnavailable && ! isChosen ) }
					aria-labelledby={ `${ baseId }-label` }
					aria-describedby={
						`${ baseId }-description` +
						( note ? ` ${ baseId }-note` : '' )
					}
					onChange={ ( event ) =>
						onToggle( useCase.id, event.target.checked )
					}
				/>
				<Wapuu
					sprites={ sprites }
					pose={ useCase.sprite }
					size={ 44 }
				/>
				<span className="ai-get-started__card-text">
					<strong id={ `${ baseId }-label` }>
						{ useCase.label }
					</strong>
					<span id={ `${ baseId }-description` }>
						{ useCase.description }
					</span>
				</span>
			</label>
			{ note && (
				<p id={ `${ baseId }-note` } className="ai-get-started__note">
					{ note }
				</p>
			) }
			{ addon && (
				<label
					className="ai-get-started__addon"
					htmlFor={ `${ baseId }-addon-input` }
				>
					<input
						id={ `${ baseId }-addon-input` }
						type="checkbox"
						checked={ isChosen && chosen.includes( addon.id ) }
						disabled={ isDisabled || ! isChosen }
						aria-labelledby={ `${ baseId }-addon-label` }
						aria-describedby={ `${ baseId }-addon-description` }
						onChange={ ( event ) =>
							onToggle( addon.id, event.target.checked )
						}
					/>
					<span className="ai-get-started__card-text">
						<span className="ai-get-started__addon-label">
							<span id={ `${ baseId }-addon-label` }>
								{ addon.label }
							</span>
							<Badge intent="informational">
								{ __( 'Auto', 'ai' ) }
							</Badge>
						</span>
						<span id={ `${ baseId }-addon-description` }>
							{ addon.description }
						</span>
					</span>
				</label>
			) }
		</div>
	);
}

/**
 * The Get started guide on Settings > AI.
 *
 * Wapuu leads the site owner through three steps: connect an AI provider,
 * choose what AI helps with, and try a tool. Nothing is turned on until the
 * owner chooses it, and the guide can be hidden at any point.
 *
 * @param props                     The component props.
 * @param props.onboarding          Guide data passed from PHP.
 * @param props.hasCredentials      Whether any AI provider has credentials.
 * @param props.hasValidCredentials Whether a connected provider can generate text.
 * @param props.connectorsUrl       URL of the Connectors screen.
 * @param props.selected            The saved use case and add-on IDs.
 * @param props.completed           Whether the last step was finished or skipped.
 * @param props.enabledCount        The number of Features and Experiments that are on.
 * @param props.onSave              Saves settings edits.
 * @param props.onShowAllSettings   Moves to the full list of settings.
 * @param props.collapsedNotice     Shown above the guide while it is collapsed.
 * @return The guide.
 */
export function GetStarted( {
	onboarding,
	hasCredentials,
	hasValidCredentials,
	connectorsUrl,
	selected,
	completed,
	enabledCount,
	onSave,
	onShowAllSettings,
	collapsedNotice,
}: GetStartedProps ): React.JSX.Element {
	const { useCases, sprites } = onboarding;
	const headingId = useId();
	const headingRef = useRef< HTMLHeadingElement >( null );

	// A site that already has tools on, but never used the guide, is treated
	// as set up: the guide starts collapsed and stays out of the way.
	const [ isExpanded, setIsExpanded ] = useState(
		() =>
			! onboarding.hidden &&
			! (
				onboarding.selected.length === 0 &&
				onboarding.enabledCount > 0 &&
				! onboarding.completed
			)
	);
	const [ isChoosing, setIsChoosing ] = useState( false );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ chosen, setChosen ] = useState< string[] >( selected );

	// Keep the ticked choices in step with what is saved, unless the owner is
	// in the middle of changing them.
	const selectedKey = selected.join( ',' );
	useEffect( () => {
		if ( ! isChoosing ) {
			setChosen( selected );
		}
		// `selectedKey` stands in for `selected`, which is a new array each render.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ selectedKey, isChoosing ] );

	let step: GuideStep = 'done';
	if ( ! hasValidCredentials ) {
		step = 'connect';
	} else if ( isChoosing || selected.length === 0 ) {
		step = 'choose';
	} else if ( ! completed ) {
		step = 'try';
	}

	// Move focus to the heading when the step changes, so keyboard and screen
	// reader users land on the new content instead of a removed button.
	const previousStep = useRef< string >( `${ step }:${ isExpanded }` );
	useEffect( () => {
		const current = `${ step }:${ isExpanded }`;
		if ( previousStep.current !== current ) {
			previousStep.current = current;
			headingRef.current?.focus();
		}
	}, [ step, isExpanded ] );

	const persistHidden = useCallback( ( hidden: boolean ) => {
		// Hiding the guide is a personal preference, so a failed save only
		// means the guide shows again on the next visit.
		apiFetch( {
			path: '/wp/v2/users/me',
			method: 'POST',
			data: { meta: { [ HIDDEN_USER_META ]: hidden } },
		} ).catch( () => {} );
	}, [] );

	const hideGuide = useCallback( () => {
		setIsExpanded( false );
		setIsChoosing( false );
		persistHidden( true );
	}, [ persistHidden ] );

	const openGuide = useCallback( () => {
		setIsExpanded( true );
		setIsChoosing( selected.length > 0 || enabledCount > 0 );
		persistHidden( false );
	}, [ enabledCount, persistHidden, selected.length ] );

	const toggleChoice = useCallback(
		( id: string, isChosen: boolean ) => {
			setChosen( ( current ) => {
				const next = isChosen
					? [ ...current, id ]
					: current.filter( ( item ) => item !== id );
				return normalizeChosen( useCases, next );
			} );
		},
		[ useCases ]
	);

	const chosenUseCaseCount = useMemo(
		() =>
			useCases.filter( ( useCase ) => chosen.includes( useCase.id ) )
				.length,
		[ chosen, useCases ]
	);
	const chosenToolCount = useMemo(
		() => getChosenFeatureIds( useCases, chosen ).length,
		[ chosen, useCases ]
	);
	const chosenAutoCount = useMemo(
		() =>
			useCases.reduce(
				( count, useCase ) =>
					useCase.addon &&
					chosen.includes( useCase.id ) &&
					chosen.includes( useCase.addon.id )
						? count + useCase.addon.features.length
						: count,
				0
			),
		[ chosen, useCases ]
	);
	const hasSavedChoices = selected.length > 0;
	const isUnchanged =
		normalizeChosen( useCases, chosen ).join( ',' ) ===
		normalizeChosen( useCases, selected ).join( ',' );

	const saveChoices = useCallback( async () => {
		// Stay on this step until the save succeeds, since the saved choices
		// update optimistically and would otherwise move the guide on early.
		setIsChoosing( true );
		setIsSaving( true );
		const edits = buildUseCaseEdits( useCases, selected, chosen );
		const toolCount = getChosenFeatureIds( useCases, chosen ).length;
		const saved = await onSave( edits, {
			message:
				toolCount > 0
					? getToolsOnLabel( toolCount )
					: __( 'Use cases saved.', 'ai' ),
		} );
		setIsSaving( false );
		if ( saved ) {
			setIsChoosing( false );
		}
	}, [ chosen, onSave, selected, useCases ] );

	const completeGuide = useCallback( async () => {
		setIsSaving( true );
		await onSave(
			{ [ COMPLETED_SETTING ]: true },
			{ message: __( 'Setup guide finished.', 'ai' ) }
		);
		setIsSaving( false );
	}, [ onSave ] );

	const followTryLink = useCallback(
		( event: React.MouseEvent< HTMLAnchorElement >, url: string ) => {
			// Let the browser handle new-tab and new-window clicks itself.
			const opensElsewhere =
				event.metaKey ||
				event.ctrlKey ||
				event.shiftKey ||
				event.button !== 0;

			if ( ! opensElsewhere ) {
				event.preventDefault();
			}

			onSave( { [ COMPLETED_SETTING ]: true }, { message: '' } ).finally(
				() => {
					if ( ! opensElsewhere ) {
						window.location.assign( url );
					}
				}
			);
		},
		[ onSave ]
	);

	if ( ! isExpanded ) {
		let restingText: string = __(
			"Wapuu's resting. Open the guide when you want a hand with setup.",
			'ai'
		);
		if ( enabledCount > 0 ) {
			restingText = sprintf(
				// translators: %s: Sentence saying how many tools are on, such as "3 tools are on."
				__( "%s Wapuu's resting.", 'ai' ),
				getToolsOnLabel( enabledCount )
			);
		}

		return (
			<>
				{ collapsedNotice }
				<div className="ai-get-started ai-get-started--resting">
					<Wapuu sprites={ sprites } pose="napping" size={ 32 } />
					<span className="ai-get-started__resting-text">
						{ restingText }
					</span>
					<Button
						variant="minimal"
						size="compact"
						onClick={ openGuide }
					>
						{ hasSavedChoices || enabledCount > 0
							? __( 'Use cases', 'ai' )
							: __( 'Open guide', 'ai' ) }
					</Button>
				</div>
			</>
		);
	}

	const tryPointers: Array< TryPointer & { key: string; isAuto: boolean } > =
		[];
	for ( const useCase of useCases ) {
		if ( ! selected.includes( useCase.id ) ) {
			continue;
		}
		if ( useCase.try ) {
			tryPointers.push( {
				...useCase.try,
				key: useCase.id,
				isAuto: false,
			} );
		}
		if (
			useCase.addon &&
			useCase.addon.try &&
			selected.includes( useCase.addon.id )
		) {
			tryPointers.push( {
				...useCase.addon.try,
				key: useCase.addon.id,
				isAuto: true,
			} );
		}
	}

	const selectedToolCount = getChosenFeatureIds( useCases, selected ).length;

	let pose = 'celebrating';
	let heading: string = __( "You're set up.", 'ai' );
	let intro: string =
		enabledCount > 0
			? sprintf(
					// translators: %s: Sentence saying how many tools are on, such as "3 tools are on."
					__( '%s Change them any time below.', 'ai' ),
					getToolsOnLabel( enabledCount )
			  )
			: __(
					'No tools are on right now. Choose use cases whenever you like.',
					'ai'
			  );

	if ( step === 'connect' && hasCredentials ) {
		pose = 'oops';
		heading = __( "Hmm, this provider can't write text.", 'ai' );
		intro = __(
			'Most tools need a provider that can generate text. Add another provider, or check the one you connected.',
			'ai'
		);
	} else if ( step === 'connect' ) {
		pose = 'navigating';
		heading = __( 'First stop: connect an AI provider.', 'ai' );
		intro = __(
			"AI tools send requests to a provider such as Anthropic, Google or OpenAI. You'll need an account and API key with one of them, and they bill you for what you use.",
			'ai'
		);
	} else if ( step === 'choose' ) {
		pose = 'juggling';
		heading = __( 'What do you want help with?', 'ai' );
		intro = __(
			'Pick as many as you like. Nothing is turned on until you choose it.',
			'ai'
		);
	} else if ( step === 'try' ) {
		pose = 'navigating';
		heading = sprintf(
			// translators: %s: Sentence saying how many tools are on, such as "3 tools are on."
			__( "%s Let's try one.", 'ai' ),
			getToolsOnLabel( selectedToolCount )
		);
		intro = __(
			"Here's where to find them. Pick any to see it work.",
			'ai'
		);
	}

	let chooseButtonLabel: string = sprintf(
		// translators: %d: Number of use cases.
		_n(
			'Turn on %d use case',
			'Turn on %d use cases',
			chosenUseCaseCount,
			'ai'
		),
		chosenUseCaseCount
	);
	if ( hasSavedChoices ) {
		chooseButtonLabel = __( 'Save use cases', 'ai' );
	} else if ( chosenUseCaseCount === 0 ) {
		chooseButtonLabel = __( 'Turn on use cases', 'ai' );
	}

	return (
		<section
			className={ `ai-get-started ai-get-started--${ step }` }
			aria-labelledby={ headingId }
		>
			<div className="ai-get-started__top">
				<StepTracker current={ step } />
				{ step !== 'done' && (
					<Button
						variant="minimal"
						tone="neutral"
						size="compact"
						onClick={ hideGuide }
					>
						{ __( 'Hide guide', 'ai' ) }
					</Button>
				) }
			</div>
			<div className="ai-get-started__intro">
				<Wapuu sprites={ sprites } pose={ pose } />
				<div className="ai-get-started__intro-text">
					<h2
						id={ headingId }
						ref={ headingRef }
						tabIndex={ -1 }
						className="ai-get-started__heading"
					>
						{ heading }
					</h2>
					<p>{ intro }</p>
				</div>
			</div>

			{ step === 'connect' && connectorsUrl && (
				<div className="ai-get-started__actions">
					<ButtonLink variant="solid" href={ connectorsUrl }>
						{ hasCredentials
							? __( 'Add a provider', 'ai' )
							: __( 'Choose a provider', 'ai' ) }
					</ButtonLink>
				</div>
			) }

			{ step === 'choose' && (
				<>
					<div
						className="ai-get-started__cards"
						role="group"
						aria-labelledby={ headingId }
					>
						{ useCases.map( ( useCase ) => (
							<UseCaseCard
								key={ useCase.id }
								useCase={ useCase }
								sprites={ sprites }
								chosen={ chosen }
								isDisabled={ isSaving }
								onToggle={ toggleChoice }
							/>
						) ) }
					</div>
					<div className="ai-get-started__actions">
						<Button
							variant="solid"
							disabled={
								isSaving ||
								isUnchanged ||
								( ! hasSavedChoices &&
									chosenUseCaseCount === 0 )
							}
							onClick={ () => {
								void saveChoices();
							} }
						>
							{ chooseButtonLabel }
						</Button>
						{ isChoosing && ( hasSavedChoices || completed ) && (
							<Button
								variant="minimal"
								tone="neutral"
								disabled={ isSaving }
								onClick={ () => {
									setChosen( selected );
									setIsChoosing( false );
								} }
							>
								{ __( 'Cancel', 'ai' ) }
							</Button>
						) }
						{ chosenToolCount > 0 && (
							<span
								className="ai-get-started__count"
								aria-live="polite"
							>
								{ getToolCountLabel( chosenToolCount ) }
								{ chosenAutoCount > 0 &&
									' · ' +
										sprintf(
											// translators: %d: Number of AI tools that run automatically.
											_n(
												'%d runs on its own',
												'%d run on their own',
												chosenAutoCount,
												'ai'
											),
											chosenAutoCount
										) }
							</span>
						) }
					</div>
					<p className="ai-get-started__footnote">
						{ __(
							'Want to pick tool by tool, or set up tools for developers?',
							'ai'
						) }{ ' ' }
						<Button
							variant="minimal"
							size="compact"
							onClick={ onShowAllSettings }
						>
							{ __( 'Go to all settings', 'ai' ) }
						</Button>
					</p>
				</>
			) }

			{ step === 'try' && (
				<>
					<ul className="ai-get-started__tries">
						{ tryPointers.map( ( pointer ) => (
							<li
								key={ pointer.key }
								className="ai-get-started__try"
							>
								<span className="ai-get-started__card-text">
									<span className="ai-get-started__addon-label">
										<strong>{ pointer.label }</strong>
										{ pointer.isAuto && (
											<Badge intent="informational">
												{ __( 'Auto', 'ai' ) }
											</Badge>
										) }
									</span>
									<span>{ pointer.description }</span>
								</span>
								<ButtonLink
									variant={
										pointer.isAuto ? 'minimal' : 'outline'
									}
									size="compact"
									href={ pointer.url }
									onClick={ ( event ) =>
										followTryLink( event, pointer.url )
									}
								>
									{ pointer.linkText || __( 'Open', 'ai' ) }
								</ButtonLink>
							</li>
						) ) }
					</ul>
					<div className="ai-get-started__actions">
						<Button
							variant="minimal"
							size="compact"
							disabled={ isSaving }
							onClick={ () => setIsChoosing( true ) }
						>
							{ __( 'Change use cases', 'ai' ) }
						</Button>
						<Button
							variant="minimal"
							size="compact"
							onClick={ onShowAllSettings }
						>
							{ __( 'See all in settings', 'ai' ) }
						</Button>
						<Button
							variant="minimal"
							tone="neutral"
							size="compact"
							disabled={ isSaving }
							onClick={ () => {
								void completeGuide();
							} }
						>
							{ __( 'Skip', 'ai' ) }
						</Button>
					</div>
				</>
			) }

			{ step === 'done' && (
				<div className="ai-get-started__actions">
					<Button
						variant="outline"
						onClick={ () => setIsChoosing( true ) }
					>
						{ __( 'Change use cases', 'ai' ) }
					</Button>
					<Button
						variant="minimal"
						tone="neutral"
						onClick={ hideGuide }
					>
						{ __( 'Close guide', 'ai' ) }
					</Button>
				</div>
			) }
		</section>
	);
}
