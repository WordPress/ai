/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

export interface FeatureUsage {
	trigger: 'automatic' | 'on_demand' | 'none';
	runs: string;
	sends: string;
	requests: string;
}

interface FeatureLabelProps {
	label: string;
	stability?: string | undefined;
}

interface FeatureHelpProps {
	description?: React.ReactNode;
	usage?: FeatureUsage | null | undefined;
}

/**
 * Renders a feature toggle label with a stability badge for non-stable items.
 *
 * @param {FeatureLabelProps} props           The component props.
 * @param {string}            props.label     The feature label.
 * @param {string}            props.stability The feature stability level.
 * @return {React.JSX.Element} The label.
 */
export function FeatureLabel( {
	label,
	stability,
}: FeatureLabelProps ): React.JSX.Element {
	let badge = '';
	if ( stability === 'experimental' ) {
		badge = __( 'Experimental', 'ai' );
	} else if ( stability === 'deprecated' ) {
		badge = __( 'Deprecated', 'ai' );
	}

	return (
		<>
			{ label }
			{ badge && ' ' }
			{ badge && (
				<span
					className={ `ai-feature-badge ai-feature-badge--${ stability }` }
				>
					{ badge }
				</span>
			) }
		</>
	);
}

/**
 * Renders a feature description followed by when it runs, what it sends,
 * and how many requests it makes.
 *
 * @param {FeatureHelpProps} props             The component props.
 * @param {React.ReactNode}  props.description The feature description.
 * @param {FeatureUsage}     props.usage       The feature usage metadata.
 * @return {React.JSX.Element} The help text.
 */
export function FeatureHelp( {
	description,
	usage,
}: FeatureHelpProps ): React.JSX.Element {
	const facts = usage
		? [
				{
					key: 'runs',
					label: __( 'When it runs', 'ai' ),
					value: usage.runs,
				},
				{
					key: 'sends',
					label: __( 'What it sends', 'ai' ),
					value: usage.sends,
				},
				{
					key: 'requests',
					label: __( 'AI requests', 'ai' ),
					value: usage.requests,
				},
		  ].filter( ( fact ) => fact.value !== '' )
		: [];

	return (
		<>
			{ description }
			{ facts.length > 0 && (
				<span className="ai-feature-usage">
					{ facts.map( ( fact ) => (
						<span
							key={ fact.key }
							className={
								fact.key === 'runs' &&
								usage?.trigger === 'automatic'
									? 'ai-feature-usage__item ai-feature-usage__item--automatic'
									: 'ai-feature-usage__item'
							}
						>
							<span className="screen-reader-text">
								{ fact.label }:{ ' ' }
							</span>
							{ fact.value }
						</span>
					) ) }
				</span>
			) }
		</>
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
	};

	return usage.runs || usage.sends || usage.requests ? usage : null;
}
