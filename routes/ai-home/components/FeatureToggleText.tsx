/**
 * WordPress dependencies
 */
import { BaseControl, FormToggle } from '@wordpress/components';
import { useId, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { info as infoIcon } from '@wordpress/icons';
import { IconButton, Link, Popover, VisuallyHidden } from '@wordpress/ui';
/**
 * External dependencies
 */

export interface FeatureUsage {
	trigger: 'automatic' | 'on_demand' | 'none';
	runs: string;
	sends: string;
	requests: string;
	details: string;
}

interface FeatureUsageProps {
	label: string;
	usage?: FeatureUsage | null | undefined;
	docsUrl?: string | undefined;
}

interface FeatureToggleControlProps extends FeatureUsageProps {
	help?: React.ReactNode;
	checked: boolean;
	onChange: ( value: boolean ) => void;
}

/**
 * Renders a feature toggle with an info button after the name, and flags
 * features that run without a user action.
 *
 * Mirrors ToggleControl's markup, but keeps the info button and badge
 * outside the `<label>` so they don't become part of the toggle's
 * accessible name.
 *
 * @param {FeatureToggleControlProps} props          The component props.
 * @param {string}                    props.label    The feature label.
 * @param {React.ReactNode}           props.help     The feature description.
 * @param {boolean}                   props.checked  Whether the feature is enabled.
 * @param {(value: boolean) => void}  props.onChange Called with the new value when toggled.
 * @param {FeatureUsage}              props.usage    The feature usage metadata.
 * @param {string}                    props.docsUrl  URL of the feature's documentation.
 * @return {React.JSX.Element} The toggle.
 */
export function FeatureToggleControl( {
	label,
	help,
	checked,
	onChange,
	usage,
	docsUrl,
}: FeatureToggleControlProps ): React.JSX.Element {
	const id = `ai-feature-toggle-control-${ useId() }`;

	return (
		<BaseControl
			id={ id }
			help={
				help && (
					<span className="components-toggle-control__help">
						{ help }
					</span>
				)
			}
			className="components-toggle-control ai-feature-toggle-control"
		>
			<div className="ai-feature-toggle-control__row">
				<FormToggle
					id={ id }
					checked={ checked }
					onChange={ ( event ) => onChange( event.target.checked ) }
					aria-describedby={ help ? `${ id }__help` : undefined }
				/>
				<label
					htmlFor={ id }
					className="components-toggle-control__label"
				>
					{ label }
				</label>
				<FeatureUsageInfo
					label={ label }
					usage={ usage }
					docsUrl={ docsUrl }
				/>
				{ usage?.trigger === 'automatic' && (
					<span className="ai-feature-badge">
						{ __( 'Runs automatically', 'ai' ) }
					</span>
				) }
			</div>
		</BaseControl>
	);
}

/**
 * Renders an info button that shows when the feature runs, what it sends,
 * and how many requests it makes.
 *
 * @param {FeatureUsageProps} props         The component props.
 * @param {string}            props.label   The feature label.
 * @param {FeatureUsage}      props.usage   The feature usage metadata.
 * @param {string}            props.docsUrl URL of the feature's documentation.
 * @return {React.JSX.Element|null} The info button, or null without usage details.
 */
function FeatureUsageInfo( {
	label,
	usage,
	docsUrl,
}: FeatureUsageProps ): React.JSX.Element | null {
	const popupRef = useRef< HTMLDivElement >( null );

	const facts = usage
		? [
				{ label: __( 'When it runs', 'ai' ), value: usage.runs },
				{
					label: __( 'Sent to your AI provider', 'ai' ),
					value: usage.sends,
				},
				{ label: __( 'Requests', 'ai' ), value: usage.requests },
				{ label: __( 'Details', 'ai' ), value: usage.details },
		  ].filter( ( fact ) => fact.value !== '' )
		: [];

	if ( facts.length === 0 ) {
		return null;
	}

	return (
		<Popover.Root>
			<Popover.Trigger
				render={
					<IconButton
						className="ai-feature-usage-toggle"
						icon={ infoIcon }
						size="small"
						variant="minimal"
						tone="neutral"
						label={ sprintf(
							/* translators: %s: Feature or experiment name. */
							__( 'When %s runs and what it sends', 'ai' ),
							label
						) }
					/>
				}
			/>
			<Popover.Popup
				ref={ popupRef }
				className="ai-feature-usage-popover"
				// Start at the top so the usage facts are read before the
				// docs link, rather than jumping to the first link.
				initialFocus={ popupRef }
				positioner={
					<Popover.Positioner side="bottom" align="start" />
				}
			>
				<VisuallyHidden render={ <Popover.Title /> }>
					{ sprintf(
						/* translators: %s: Feature or experiment name. */
						__( '%s usage', 'ai' ),
						label
					) }
				</VisuallyHidden>
				<dl className="ai-feature-usage">
					{ facts.map( ( fact ) => (
						<div
							key={ fact.label }
							className="ai-feature-usage__item"
						>
							<dt>{ fact.label }</dt>
							<dd>{ fact.value }</dd>
						</div>
					) ) }
				</dl>
				{ docsUrl && (
					<Link href={ docsUrl } openInNewTab>
						{ __( 'Learn more', 'ai' ) }
					</Link>
				) }
			</Popover.Popup>
		</Popover.Root>
	);
}

/**
 * Parses usage metadata from page data.
 *
 * @param {unknown} value Raw usage value.
 * @return {FeatureUsage|null} Parsed usage, or null when absent.
 */
export function parseFeatureUsage( value: unknown ): FeatureUsage | null {
	if ( typeof value !== 'object' || value === null ) {
		return null;
	}

	const raw = value as Record< string, unknown >;
	const toText = ( item: unknown ): string =>
		typeof item === 'string' ? item : '';
	// eslint-disable-next-line dot-notation
	const rawTrigger = raw[ 'trigger' ];
	const trigger =
		rawTrigger === 'automatic' || rawTrigger === 'none'
			? rawTrigger
			: 'on_demand';
	const usage: FeatureUsage = {
		trigger,
		// eslint-disable-next-line dot-notation
		runs: toText( raw[ 'runs' ] ),
		// eslint-disable-next-line dot-notation
		sends: toText( raw[ 'sends' ] ),
		// eslint-disable-next-line dot-notation
		requests: toText( raw[ 'requests' ] ),
		// eslint-disable-next-line dot-notation
		details: toText( raw[ 'details' ] ),
	};

	return usage.runs || usage.sends || usage.requests || usage.details
		? usage
		: null;
}
