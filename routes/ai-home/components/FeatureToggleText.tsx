/**
 * WordPress dependencies
 */
import { Button, ExternalLink, Popover } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { info as infoIcon } from '@wordpress/icons';

export interface FeatureUsage {
	trigger: 'automatic' | 'on_demand' | 'none';
	runs: string;
	sends: string;
	requests: string;
}

interface FeatureLabelProps {
	label: string;
	usage?: FeatureUsage | null | undefined;
}

interface FeatureHelpProps {
	label: string;
	description?: React.ReactNode;
	usage?: FeatureUsage | null | undefined;
	docsUrl?: string | undefined;
}

/**
 * Renders a feature toggle label, flagging features that run without a user action.
 *
 * @param {FeatureLabelProps} props       The component props.
 * @param {string}            props.label The feature label.
 * @param {FeatureUsage}      props.usage The feature usage metadata.
 * @return {React.JSX.Element} The label.
 */
export function FeatureLabel( {
	label,
	usage,
}: FeatureLabelProps ): React.JSX.Element {
	return (
		<>
			{ label }
			{ usage?.trigger === 'automatic' && (
				<>
					{ ' ' }
					<span className="ai-feature-badge">
						{ __( 'Runs automatically', 'ai' ) }
					</span>
				</>
			) }
		</>
	);
}

/**
 * Renders a feature description with an info button that shows when the
 * feature runs, what it sends, and how many requests it makes.
 *
 * @param {FeatureHelpProps} props             The component props.
 * @param {string}           props.label       The feature label.
 * @param {React.ReactNode}  props.description The feature description.
 * @param {FeatureUsage}     props.usage       The feature usage metadata.
 * @param {string}           props.docsUrl     URL of the feature's documentation.
 * @return {React.JSX.Element} The help text.
 */
export function FeatureHelp( {
	label,
	description,
	usage,
	docsUrl,
}: FeatureHelpProps ): React.JSX.Element {
	const [ anchor, setAnchor ] = useState< HTMLElement | null >( null );
	const [ isOpen, setIsOpen ] = useState( false );

	const facts = usage
		? [
				{ label: __( 'When it runs', 'ai' ), value: usage.runs },
				{
					label: __( 'Sent to your AI provider', 'ai' ),
					value: usage.sends,
				},
				{ label: __( 'Requests', 'ai' ), value: usage.requests },
		  ].filter( ( fact ) => fact.value !== '' )
		: [];

	if ( facts.length === 0 ) {
		return <>{ description }</>;
	}

	return (
		<>
			{ description }{ ' ' }
			<Button
				ref={ setAnchor }
				className="ai-feature-usage-toggle"
				icon={ infoIcon }
				size="small"
				label={ sprintf(
					/* translators: %s: Feature or experiment name. */
					__( 'When %s runs and what it sends', 'ai' ),
					label
				) }
				aria-expanded={ isOpen }
				onClick={ () => setIsOpen( ( open ) => ! open ) }
			/>
			{ isOpen && (
				<Popover
					anchor={ anchor }
					className="ai-feature-usage-popover"
					placement="bottom-start"
					focusOnMount="firstElement"
					onClose={ () => setIsOpen( false ) }
				>
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
						<ExternalLink href={ docsUrl }>
							{ __( 'Learn more', 'ai' ) }
						</ExternalLink>
					) }
				</Popover>
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
