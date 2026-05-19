/**
 * WordPress dependencies
 */
import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { SummaryPeriod } from '../types';

interface HeaderPeriodSelectorProps {
	period: SummaryPeriod;
	onPeriodChange: ( period: SummaryPeriod ) => void;
	loading: boolean;
}

const HeaderPeriodSelector: React.FC< HeaderPeriodSelectorProps > = ( {
	period,
	onPeriodChange,
	loading,
} ) => (
	<SelectControl
		label={ __( 'Time period', 'ai' ) }
		hideLabelFromVision
		value={ period }
		options={ [
			{ label: __( 'Last Minute', 'ai' ), value: 'minute' },
			{ label: __( 'Last Hour', 'ai' ), value: 'hour' },
			{ label: __( 'Last 24 Hours', 'ai' ), value: 'day' },
			{ label: __( 'Last 7 Days', 'ai' ), value: 'week' },
			{ label: __( 'Last 30 Days', 'ai' ), value: 'month' },
			{ label: __( 'All Time', 'ai' ), value: 'all' },
		] }
		onChange={ ( value ) => onPeriodChange( value as SummaryPeriod ) }
		disabled={ loading }
		__nextHasNoMarginBottom
		__next40pxDefaultSize
	/>
);

export default HeaderPeriodSelector;
