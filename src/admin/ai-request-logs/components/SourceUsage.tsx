/**
 * WordPress dependencies
 */
import { Card, CardBody, CardHeader } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatNumber } from './SummaryCards';
import type { SourceUsage as SourceUsageEntry } from '../types';

interface SourceUsageProps {
	sources: SourceUsageEntry[];
}

const getTypeLabel = ( type: string ): string => {
	switch ( type ) {
		case 'plugin':
			return __( 'Plugin', 'ai' );
		case 'mu-plugin':
			return __( 'Must-use plugin', 'ai' );
		case 'theme':
			return __( 'Theme', 'ai' );
		case 'core':
			return __( 'Core', 'ai' );
		default:
			return '—';
	}
};

const SourceUsage: React.FC< SourceUsageProps > = ( { sources } ) => {
	const headingId = useInstanceId(
		SourceUsage,
		'ai-request-logs-source-usage'
	);

	if ( sources.length === 0 ) {
		return null;
	}

	return (
		<Card className="ai-request-logs__card ai-request-logs__source-usage">
			<CardHeader>
				<h2 id={ `${ headingId }-heading` }>
					{ __( 'Usage by source', 'ai' ) }
				</h2>
			</CardHeader>
			<CardBody>
				<p
					id={ `${ headingId }-description` }
					className="ai-request-logs__source-description"
				>
					{ __(
						'Top 20 plugins, themes, and other sources by tokens used in the selected period. Requests without a detected source, including those logged before source tracking was added, are listed as Unattributed.',
						'ai'
					) }
				</p>
				<table
					className="ai-request-logs__source-table"
					aria-labelledby={ `${ headingId }-heading` }
					aria-describedby={ `${ headingId }-description` }
				>
					<thead>
						<tr>
							<th scope="col">{ __( 'Source', 'ai' ) }</th>
							<th scope="col">{ __( 'Type', 'ai' ) }</th>
							<th scope="col">{ __( 'Requests', 'ai' ) }</th>
							<th scope="col">{ __( 'Tokens', 'ai' ) }</th>
							<th scope="col">{ __( 'Failed', 'ai' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ sources.map( ( source ) => (
							<tr key={ source.source ?? 'unattributed' }>
								<th scope="row">
									{ source.name ||
										__( 'Unattributed', 'ai' ) }
								</th>
								<td>{ getTypeLabel( source.type ) }</td>
								<td>{ formatNumber( source.requests ) }</td>
								<td>{ formatNumber( source.tokens ) }</td>
								<td>{ formatNumber( source.errors ) }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</CardBody>
		</Card>
	);
};

export default SourceUsage;
