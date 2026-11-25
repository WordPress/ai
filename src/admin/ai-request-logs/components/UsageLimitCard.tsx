/**
 * WordPress dependencies
 */
import { Card, CardBody, ExternalLink } from '@wordpress/components';
import { dateI18n, getSettings } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { UsageLimitStatus } from '../types';

interface UsageLimitCardProps {
	usageLimit: UsageLimitStatus;
}

const STATE_LABELS: Record< UsageLimitStatus[ 'state' ], string > = {
	ok: __( 'Within limit', 'ai' ),
	warning: __( 'Approaching limit', 'ai' ),
	exceeded: __( 'Limit reached', 'ai' ),
};

/**
 * Shows this month's token usage against the monthly limit.
 *
 * @param {UsageLimitCardProps} props            Component props.
 * @param {UsageLimitStatus}    props.usageLimit Usage status from the server.
 */
const UsageLimitCard: React.FC< UsageLimitCardProps > = ( { usageLimit } ) => {
	const {
		limit,
		used,
		percent,
		threshold,
		state,
		resets_at: resetsAt,
	} = usageLimit;
	const resetsOn = dateI18n( getSettings().formats.date, resetsAt );

	return (
		<Card
			className={ `ai-request-logs__usage-limit is-${ state }` }
			size="small"
		>
			<CardBody>
				<div className="ai-request-logs__usage-limit-header">
					<h2>{ __( 'Monthly token limit', 'ai' ) }</h2>
					<span className="ai-request-logs__usage-limit-state">
						{ STATE_LABELS[ state ] }
					</span>
				</div>
				<div
					className="ai-request-logs__usage-limit-meter"
					role="meter"
					aria-label={ __( 'Tokens used this month', 'ai' ) }
					aria-valuemin={ 0 }
					aria-valuemax={ limit }
					aria-valuenow={ Math.min( used, limit ) }
					aria-valuetext={ sprintf(
						/* translators: 1: Percentage used. */
						__( '%1$s%% used', 'ai' ),
						percent.toLocaleString()
					) }
				>
					<span
						className="ai-request-logs__usage-limit-fill"
						style={ { width: `${ Math.min( percent, 100 ) }%` } }
					/>
					<span
						className="ai-request-logs__usage-limit-marker"
						style={ { left: `${ threshold }%` } }
					/>
				</div>
				<p className="ai-request-logs__usage-limit-text">
					{ sprintf(
						/* translators: 1: Tokens used. 2: Monthly token limit. 3: Percentage used. 4: Date usage resets. */
						__(
							'%1$s of %2$s tokens used (%3$s%%). Resets on %4$s.',
							'ai'
						),
						used.toLocaleString(),
						limit.toLocaleString(),
						percent.toLocaleString(),
						resetsOn
					) }{ ' ' }
					<ExternalLink href={ usageLimit.settings_url }>
						{ __( 'Change limit', 'ai' ) }
					</ExternalLink>
				</p>
			</CardBody>
		</Card>
	);
};

export default UsageLimitCard;
