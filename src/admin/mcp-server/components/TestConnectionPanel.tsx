import { Button, Card, CardBody, CardHeader, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import React from 'react';

import type { TestResult } from '../types';

interface TestConnectionPanelProps {
	testing: boolean;
	result: TestResult | null;
	onTest: () => void;
}

const TestConnectionPanel: React.FC< TestConnectionPanelProps > = ( { testing, result, onTest } ) => (
	<Card className="ai-mcp-server__card">
		<CardHeader>
			<h2>{ __( 'Connection test', 'ai' ) }</h2>
		</CardHeader>
		<CardBody>
			<p>
				{ __( 'Verify that the MCP HTTP endpoint is reachable from WordPress. For external clients you may still need to configure authentication.', 'ai' ) }
			</p>
			<Button variant="primary" onClick={ onTest } isBusy={ testing } disabled={ testing }>
				{ testing ? __( 'Testing…', 'ai' ) : __( 'Test connection', 'ai' ) }
			</Button>

			{ result && (
				<Notice
					status={ result.success ? 'success' : 'error' }
					isDismissible={ false }
					className="ai-mcp-server__test-result"
				>
					<p>
						<strong>{ result.message }</strong>
					</p>
					<p>
						{ __( 'Response code:', 'ai' ) } { result.code ?? __( 'Unavailable', 'ai' ) }
					</p>
					{ result.body && (
						<pre>{ result.body }</pre>
					 ) }
				</Notice>
			) }
		</CardBody>
	</Card>
);

export default TestConnectionPanel;
