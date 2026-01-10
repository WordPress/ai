/**
 * WordPress dependencies
 */
import { useState, useCallback, useEffect, useRef } from '@wordpress/element';
import {
	Card,
	CardBody,
	CardHeader,
	Button,
	ExternalLink,
	__experimentalVStack as VStack,
	__experimentalText as Text,
} from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews';
import { chevronDown, chevronUp } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ExperimentData, DataFormField } from '../types';

interface ExperimentCardProps {
	experiment: ExperimentData;
	globalEnabled: boolean;
	onToggle: ( id: string, enabled: boolean ) => void;
	onSettingsChange: ( id: string, data: Record< string, unknown > ) => void;
}

/**
 * ExperimentCard component displays a single experiment with toggle and collapsible settings.
 */
export function ExperimentCard( {
	experiment,
	globalEnabled,
	onToggle,
	onSettingsChange,
}: ExperimentCardProps ): JSX.Element {
	const hasSettings =
		experiment.hasSettings &&
		experiment.settingsFields &&
		experiment.settingsFields.length > 0;

	// Initialize expanded state: expanded if enabled and has settings.
	const [ isExpanded, setIsExpanded ] = useState(
		hasSettings && experiment.enabled
	);

	// Track previous enabled state to detect when experiment gets enabled.
	const prevEnabledRef = useRef( experiment.enabled );

	// Auto-expand when experiment is enabled (from disabled state).
	useEffect( () => {
		if ( hasSettings && ! prevEnabledRef.current && experiment.enabled ) {
			setIsExpanded( true );
		}
		prevEnabledRef.current = experiment.enabled;
	}, [ experiment.enabled, hasSettings ] );

	const toggleFields: DataFormField[] = [
		{
			id: 'enabled',
			type: 'boolean',
			label: experiment.label,
		},
	];

	const toggleForm = {
		type: 'regular' as const,
		fields: [ 'enabled' ],
	};

	const handleToggleChange = useCallback(
		( changes: { enabled?: boolean } ) => {
			if ( typeof changes.enabled === 'boolean' ) {
				onToggle( experiment.id, changes.enabled );
			}
		},
		[ experiment.id, onToggle ]
	);

	const handleSettingsChange = useCallback(
		( changes: Record< string, unknown > ) => {
			onSettingsChange( experiment.id, changes );
		},
		[ experiment.id, onSettingsChange ]
	);

	const isDisabled = ! globalEnabled;

	return (
		<Card
			className={ `ai-experiments__card ${
				isDisabled ? 'ai-experiments__card--disabled' : ''
			}` }
		>
			<CardHeader className="ai-experiments__card-header">
				<div className="ai-experiments__card-header-content">
					<DataForm
						data={ { enabled: experiment.enabled } }
						fields={ toggleFields }
						form={ toggleForm }
						onChange={ handleToggleChange }
					/>
					{ experiment.entryPoints.length > 0 && (
						<span className="ai-experiments__entry-points">
							(
							{ experiment.entryPoints.map( ( ep, index ) => (
								<span key={ ep.url }>
									{ index > 0 && ' · ' }
									<ExternalLink href={ ep.url }>
										{ ep.label }
									</ExternalLink>
								</span>
							) ) }
							)
						</span>
					) }
				</div>
				{ hasSettings && (
					<Button
						icon={ isExpanded ? chevronUp : chevronDown }
						label={
							isExpanded
								? __( 'Collapse settings', 'ai' )
								: __( 'Expand settings', 'ai' )
						}
						onClick={ () => setIsExpanded( ! isExpanded ) }
						disabled={ isDisabled }
					/>
				) }
			</CardHeader>
			<CardBody>
				<Text className="ai-experiments__description">
					{ experiment.description }
				</Text>
				{ hasSettings && isExpanded && experiment.settingsValues && (
					<VStack className="ai-experiments__settings-panel">
						<DataForm
							data={ experiment.settingsValues }
							fields={ experiment.settingsFields! }
							form={ {
								type: 'regular' as const,
								fields: experiment.settingsFields!.map(
									( f ) => f.id
								),
							} }
							onChange={ handleSettingsChange }
						/>
					</VStack>
				) }
			</CardBody>
		</Card>
	);
}
