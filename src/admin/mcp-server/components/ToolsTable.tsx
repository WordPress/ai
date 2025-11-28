import { Card, CardBody, CardHeader, Notice, ToggleControl } from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import type { DataViewField, View } from '@wordpress/dataviews';
import { __ } from '@wordpress/i18n';
import React, { useEffect, useMemo, useState } from 'react';

import type { ToolSummary } from '../types';

interface ToolsTableProps {
	tools: ToolSummary[];
	saving: boolean;
	serverEnabled: boolean;
	onToggle: ( name: string, next: boolean ) => void;
}

const ToolsTable: React.FC< ToolsTableProps > = ( { tools, saving, serverEnabled, onToggle } ) => {
	const categoryOptions = useMemo( () => {
		const seen = new Map< string, { label: string; value: string } >();
		tools.forEach( ( tool ) => {
			if ( ! seen.has( tool.category.slug ) ) {
				seen.set( tool.category.slug, {
					label: tool.category.label,
					value: tool.category.slug,
				} );
			}
		} );
		return Array.from( seen.values() );
	}, [ tools ] );

	const fields = useMemo< DataViewField< ToolSummary >[] >( () => [
		{
			id: 'ability',
			label: __( 'Ability', 'ai' ),
			type: 'text',
			enableGlobalSearch: true,
			getValue: ( { item } ) => item.label,
			render: ( { item } ) => (
				<div className="ai-mcp-server__tool-info">
					<strong>{ item.label }</strong>
					{ item.description && <p className="description">{ item.description }</p> }
					<code>{ item.name }</code>
				</div>
			),
		},
		{
			id: 'category',
			label: __( 'Category', 'ai' ),
			type: 'text',
			getValue: ( { item } ) => item.category.label,
			elements: categoryOptions,
			filterBy: categoryOptions.length
				? {
					operators: [ 'isAny' ],
				}
				: false,
		},
		{
			id: 'isPublic',
			label: __( 'Public', 'ai' ),
			type: 'boolean',
			getValue: ( { item } ) => item.isPublic,
			render: ( { item } ) => ( item.isPublic ? __( 'Yes', 'ai' ) : __( 'No', 'ai' ) ),
			filterBy: false,
		},
			{
				id: 'enabled',
			label: __( 'Expose via MCP', 'ai' ),
			type: 'boolean',
			getValue: ( { item } ) => item.enabled,
			enableSorting: false,
			enableHiding: false,
			filterBy: false,
				render: ( { item } ) => (
					<ToggleControl
						checked={ item.enabled }
						onChange={ ( value: boolean ) => onToggle( item.name, value ) }
						disabled={ saving || ! serverEnabled }
						__nextHasNoMarginBottom
					/>
				),
			},
	], [ categoryOptions, onToggle, saving, serverEnabled ] );

	const initialFields = useMemo( () => fields.map( ( field ) => field.id ), [ fields ] );

	const [ view, setView ] = useState< View >( {
		type: 'table',
		search: '',
		page: 1,
		perPage: 10,
		fields: initialFields,
		sort: {
			field: 'ability',
			direction: 'asc',
		},
		filters: [],
	} );

	useEffect( () => {
		setView( ( previous ) => {
			const availableFieldIds = fields.map( ( field ) => field.id );
			const nextFields = ( previous.fields ?? availableFieldIds ).filter( ( id ) => availableFieldIds.includes( id ) );
			return {
				...previous,
				fields: nextFields,
			};
		} );
	}, [ fields ] );

	const { data: filteredTools, paginationInfo } = useMemo( () => {
		return filterSortAndPaginate( tools, view, fields );
	}, [ tools, view, fields ] );

	const hasActiveFilters = Boolean( view.search ) || ( view.filters?.length ?? 0 ) > 0;

	const handleViewChange = ( nextView: View ) => {
		setView( ( previous ) => ( {
			...previous,
			...nextView,
			layout: nextView.layout ?? previous.layout,
		} ) );
	};

	return (
		<Card className="ai-mcp-server__card ai-mcp-server__tools">
			<CardHeader>
				<h2>{ __( 'Exposed abilities', 'ai' ) }</h2>
			</CardHeader>
			<CardBody>
				{ ! serverEnabled && (
					<Notice status="warning" isDismissible={ false }>
						{ __( 'Enable the MCP server to edit which abilities are exposed.', 'ai' ) }
					</Notice>
				 ) }

				<div className="ai-mcp-server__dataviews">
					<DataViews
						data={ filteredTools }
						fields={ fields }
						view={ view }
						onChangeView={ handleViewChange }
						getItemId={ ( item ) => item.name }
						defaultLayouts={ {
							table: {
								layout: {
									density: 'comfortable',
									enableMoving: false,
								},
							},
						} }
						isLoading={ false }
						paginationInfo={ paginationInfo }
						empty={ (
							<p className="ai-mcp-server__hint">
								{ hasActiveFilters
									? __( 'No abilities match your filters.', 'ai' )
									: __( 'Enable experiments to register more abilities for MCP clients.', 'ai' ) }
							</p>
						) }
							searchLabel={ __( 'Search abilities', 'ai' ) }
					/>
				</div>
			</CardBody>
		</Card>
	);
};

export default ToolsTable;
