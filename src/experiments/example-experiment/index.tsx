/**
 * External dependencies
 */
import React from 'react';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './index.scss';

const ExampleExperiment = () => {
	return <div className="test">{ __( 'Example Experiment', 'ai' ) }</div>;
};

export default ExampleExperiment;
