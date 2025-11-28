import { Card, CardBody, SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import React from 'react';

import type { LogSummary } from '../types';

interface SummaryCardsProps {
	summary: LogSummary;
	period: 'day' | 'week' | 'month' | 'all';
	onPeriodChange: ( period: 'day' | 'week' | 'month' | 'all' ) => void;
	loading: boolean;
}

const formatNumber = ( num: number ): string => {
	if ( num >= 1000000 ) {
		return ( num / 1000000 ).toFixed( 1 ) + 'M';
	}
	if ( num >= 1000 ) {
		return ( num / 1000 ).toFixed( 1 ) + 'K';
	}
	return num.toLocaleString();
};

const formatCost = ( cost: number ): string => {
	if ( cost < 0.01 ) {
		return '$' + cost.toFixed( 4 );
	}
	return '$' + cost.toFixed( 2 );
};

const formatDuration = ( ms: number ): string => {
	if ( ms < 1000 ) {
		return ms.toFixed( 0 ) + 'ms';
	}
	return ( ms / 1000 ).toFixed( 1 ) + 's';
};

const SummaryCards: React.FC< SummaryCardsProps > = ( { summary, period, onPeriodChange, loading } ) => {
	const periodLabels: Record< string, string > = {
		day: __( 'Last 24 Hours', 'ai' ),
		week: __( 'Last 7 Days', 'ai' ),
		month: __( 'Last 30 Days', 'ai' ),
		all: __( 'All Time', 'ai' ),
	};

	return (
		<div className="ai-request-logs__summary">
			<div className="ai-request-logs__summary-header">
				<h2>{ periodLabels[ period ] }</h2>
				<SelectControl
					value={ period }
					options={ [
						{ label: __( 'Last 24 Hours', 'ai' ), value: 'day' },
						{ label: __( 'Last 7 Days', 'ai' ), value: 'week' },
						{ label: __( 'Last 30 Days', 'ai' ), value: 'month' },
					{ label: __( 'All Time', 'ai' ), value: 'all' },
					] }
					onChange={ ( value ) => onPeriodChange( value as 'day' | 'week' | 'month' | 'all' ) }
					disabled={ loading }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			</div>

			<div className="ai-request-logs__summary-cards">
				<Card className="ai-request-logs__stat-card">
					<CardBody>
						<div className="ai-request-logs__stat-value">
							{ formatNumber( summary.total_requests ) }
						</div>
						<div className="ai-request-logs__stat-label">
							{ __( 'Requests', 'ai' ) }
						</div>
					</CardBody>
				</Card>

				<Card className="ai-request-logs__stat-card">
					<CardBody>
						<div className="ai-request-logs__stat-value">
							{ formatNumber( summary.total_tokens ) }
						</div>
						<div className="ai-request-logs__stat-label">
							{ __( 'Tokens', 'ai' ) }
						</div>
					</CardBody>
				</Card>

				<Card className="ai-request-logs__stat-card">
					<CardBody>
						<div className="ai-request-logs__stat-value">
							{ formatDuration( summary.avg_duration_ms ) }
						</div>
						<div className="ai-request-logs__stat-label">
							{ __( 'Avg Time', 'ai' ) }
						</div>
					</CardBody>
				</Card>

				<Card className="ai-request-logs__stat-card">
					<CardBody>
						<div className="ai-request-logs__stat-value">
							{ formatCost( summary.total_cost ) }
						</div>
						<div className="ai-request-logs__stat-label">
							{ __( 'Est. Cost', 'ai' ) }
						</div>
					</CardBody>
				</Card>

				<Card className="ai-request-logs__stat-card">
					<CardBody>
						<div className="ai-request-logs__stat-value">
							{ summary.success_rate.toFixed( 1 ) }%
						</div>
						<div className="ai-request-logs__stat-label">
							{ __( 'Success Rate', 'ai' ) }
						</div>
					</CardBody>
				</Card>
			</div>
		</div>
	);
};

export default SummaryCards;
