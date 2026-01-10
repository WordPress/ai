/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	Notice,
	__experimentalVStack as VStack,
	__experimentalHeading as Heading,
	__experimentalText as Text,
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { ExperimentCard } from './ExperimentCard';
import type { ExperimentData } from '../types';

interface ExperimentsListProps {
	experiments: ExperimentData[];
	globalEnabled: boolean;
	onToggle: ( id: string, enabled: boolean ) => void;
	onSettingsChange: ( id: string, data: Record< string, unknown > ) => void;
}

/**
 * ExperimentsList component renders all available experiments.
 */
export function ExperimentsList( {
	experiments,
	globalEnabled,
	onToggle,
	onSettingsChange,
}: ExperimentsListProps ): JSX.Element {
	return (
		<VStack spacing={ 4 } className="ai-experiments__list">
			<VStack spacing={ 2 }>
				<Heading level={ 2 }>
					{ __( 'Available Experiments', 'ai' ) }
				</Heading>
				<Text>
					{ __(
						'Try out the following experiments to see how AI can help your site.',
						'ai'
					) }
				</Text>
			</VStack>

			{ ! globalEnabled && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Enable experiments above to configure individual experiment settings.',
						'ai'
					) }
				</Notice>
			) }

			<VStack spacing={ 3 } className="ai-experiments__grid">
				{ experiments.map( ( experiment ) => (
					<ExperimentCard
						key={ experiment.id }
						experiment={ experiment }
						globalEnabled={ globalEnabled }
						onToggle={ onToggle }
						onSettingsChange={ onSettingsChange }
					/>
				) ) }
			</VStack>
		</VStack>
	);
}
