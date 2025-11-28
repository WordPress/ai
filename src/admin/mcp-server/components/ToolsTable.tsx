import { Card, CardBody, CardHeader, Notice, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import React, { useMemo, useState } from 'react';

import type { ToolSummary } from '../types';

interface ToolsTableProps {
	tools: ToolSummary[];
	saving: boolean;
	serverEnabled: boolean;
	onToggle: ( name: string, next: boolean ) => void;
}

const ToolsTable: React.FC< ToolsTableProps > = ( { tools, saving, serverEnabled, onToggle } ) => {
	const [ search, setSearch ] = useState( '' );

	const filtered = useMemo( () => {
		if ( ! search ) {
			return tools;
		}

		const lower = search.toLowerCase();
		return tools.filter( ( tool ) =>
			tool.label.toLowerCase().includes( lower ) ||
			tool.name.toLowerCase().includes( lower ) ||
			tool.category.label.toLowerCase().includes( lower )
		);
	}, [ search, tools ] );

	return (
		<Card className="ai-mcp-server__card ai-mcp-server__tools">
			<CardHeader>
				<h2>{ __( 'Exposed abilities', 'ai' ) }</h2>
			</CardHeader>
			<CardBody>
				<TextControl
					label={ __( 'Search abilities', 'ai' ) }
					value={ search }
					onChange={ setSearch }
				/>

				{ ! serverEnabled && (
					<Notice status="warning" isDismissible={ false }>
						{ __( 'Enable the MCP server to edit which abilities are exposed.', 'ai' ) }
					</Notice>
				 ) }

				{ filtered.length > 0 ? (
					<table>
						<thead>
							<tr>
								<th>{ __( 'Ability', 'ai' ) }</th>
								<th>{ __( 'Category', 'ai' ) }</th>
								<th>{ __( 'Public', 'ai' ) }</th>
								<th>{ __( 'Expose via MCP', 'ai' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ filtered.map( ( tool ) => (
								<tr key={ tool.name }>
									<td>
										<strong>{ tool.label }</strong>
										<div className="description">{ tool.description }</div>
										<code>{ tool.name }</code>
									</td>
									<td>{ tool.category.label }</td>
									<td>{ tool.isPublic ? __( 'Yes', 'ai' ) : __( 'No', 'ai' ) }</td>
									<td>
										<ToggleControl
											checked={ tool.enabled }
											onChange={ ( value: boolean ) => onToggle( tool.name, value ) }
											disabled={ saving || ! serverEnabled }
										/>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				 ) : (
					<p className="ai-mcp-server__hint">
						{ search
							? __( 'No abilities match your search.', 'ai' )
							: __( 'Enable experiments to register more abilities for MCP clients.', 'ai' ) }
					</p>
				 ) }
			</CardBody>
		</Card>
	);
};

export default ToolsTable;
