import { Button, Card, CardBody, CardHeader, SelectControl, TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import React, { useEffect, useMemo, useState } from 'react';

import type { ConfigTemplate, CopyHandler } from '../types';

interface ConfigGeneratorProps {
	templates: Record< string, ConfigTemplate >;
	onCopy: CopyHandler;
}

const ConfigGenerator: React.FC< ConfigGeneratorProps > = ( { templates, onCopy } ) => {
	const templateEntries = useMemo( () => Object.values( templates ), [ templates ] );
	const hasTemplates = templateEntries.length > 0;
	const [ selected, setSelected ] = useState( templateEntries[ 0 ]?.id ?? '' );

	useEffect( () => {
		if ( ! templateEntries.length ) {
			return;
		}

		if ( ! templateEntries.find( ( tpl ) => tpl.id === selected ) ) {
			setSelected( templateEntries[ 0 ].id );
		}
	}, [ selected, templateEntries ] );

	const activeTemplate = useMemo( () => {
		if ( ! hasTemplates ) {
			return null;
		}

		return templateEntries.find( ( tpl ) => tpl.id === selected ) ?? templateEntries[ 0 ];
	}, [ hasTemplates, selected, templateEntries ] );

	const handleCopy = () => {
		if ( activeTemplate ) {
			onCopy( activeTemplate.content, __( 'Client configuration', 'ai' ) );
		}
	};

	const handleDownload = () => {
		if ( ! activeTemplate ) {
			return;
		}

		const blob = new Blob( [ activeTemplate.content ], { type: 'application/json' } );
		const url = URL.createObjectURL( blob );
		const anchor = document.createElement( 'a' );
		anchor.href = url;
		anchor.download = activeTemplate.fileName;
		anchor.click();
		URL.revokeObjectURL( url );
	};

	return (
		<Card className="ai-mcp-server__card">
			<CardHeader>
				<h2>{ __( 'Client configuration', 'ai' ) }</h2>
			</CardHeader>
			<CardBody>
				{ hasTemplates ? (
					<>
						<SelectControl
							label={ __( 'Choose a client', 'ai' ) }
							value={ activeTemplate?.id }
							onChange={ ( value: string ) => setSelected( value ) }
							options={ templateEntries.map( ( tpl ) => ( {
								label: tpl.fileName,
								value: tpl.id,
							} ) ) }
						/>
						<TextareaControl
							label={ __( 'Paste this JSON into your MCP client', 'ai' ) }
							value={ activeTemplate?.content ?? '' }
							rows={ 12 }
							readOnly
						/>
						<div className="ai-mcp-server__button-row">
							<Button variant="primary" onClick={ handleCopy }>
								{ __( 'Copy config', 'ai' ) }
							</Button>
							<Button variant="secondary" onClick={ handleDownload }>
								{ __( 'Download file', 'ai' ) }
							</Button>
						</div>
					</>
				 ) : (
					<p className="ai-mcp-server__hint">
						{ __( 'Once the MCP HTTP endpoint is available we will generate ready-to-use config snippets for Claude Desktop, Cursor, and other clients.', 'ai' ) }
					</p>
				 ) }
			</CardBody>
		</Card>
	);
};

export default ConfigGenerator;
