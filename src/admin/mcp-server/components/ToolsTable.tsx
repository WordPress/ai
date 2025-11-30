/**
 * WordPress dependencies
 */
import {
	Card,
	CardBody,
	CardHeader,
	Notice,
	ToggleControl,
} from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import type { DataViewField, View } from '@wordpress/dataviews';
import { __ } from '@wordpress/i18n';
/**
 * External dependencies
 */
import React, { useEffect, useMemo } from 'react';

/**
 * Internal dependencies
 */
import { usePersistedView } from '../../hooks/usePersistedView';
import type { ToolSummary } from '../types';

interface ToolsTableProps {
	tools: ToolSummary[];
	saving: boolean;
	globalEnabled: boolean;
	serverEnabled: boolean;
	onToggle: ( name: string, next: boolean ) => void;
}

const ToolsTable: React.FC< ToolsTableProps > = ( {
	tools,
	saving,
	globalEnabled,
	serverEnabled,
	onToggle,
} ) => {
	const canEditTools = globalEnabled && serverEnabled;
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

	const fields = useMemo< DataViewField< ToolSummary >[] >(
		() => [
			{
				id: 'enabled',
				label: __( 'Expose via MCP', 'ai' ),
				type: 'text',
				getValue: ( { item } ) =>
					item.enabled ? 'exposed' : 'not_exposed',
				elements: [
					{ label: __( 'Exposed', 'ai' ), value: 'exposed' },
					{ label: __( 'Not exposed', 'ai' ), value: 'not_exposed' },
				],
				filterBy: {
					operators: [ 'is' ],
				},
				enableSorting: true,
				enableHiding: false,
				render: ( { item } ) => (
					<ToggleControl
						checked={ item.enabled }
						onChange={ ( value: boolean ) =>
							onToggle( item.name, value )
						}
						disabled={ saving || ! canEditTools }
						__nextHasNoMarginBottom
					/>
				),
			},
			{
				id: 'ability',
				label: __( 'Ability', 'ai' ),
				type: 'text',
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.label,
				render: ( { item } ) => (
					<div className="ai-mcp-server__tool-info">
						<strong>{ item.label }</strong>
						{ item.description && (
							<p className="description">{ item.description }</p>
						) }
						<code>{ item.name }</code>
					</div>
				),
			},
			{
				id: 'category',
				label: __( 'Category', 'ai' ),
				type: 'text',
				getValue: ( { item } ) => item.category.slug,
				elements: categoryOptions,
				filterBy:
					categoryOptions.length > 0
						? { operators: [ 'is' ] }
						: false,
				render: ( { item } ) => item.category.label,
			},
			{
				id: 'isPublic',
				label: __( 'Public', 'ai' ),
				type: 'boolean',
				getValue: ( { item } ) => item.isPublic,
				render: ( { item } ) =>
					item.isPublic ? __( 'Yes', 'ai' ) : __( 'No', 'ai' ),
				filterBy: false,
			},
		],
		[ categoryOptions, onToggle, saving, canEditTools ]
	);

	const initialFields = useMemo(
		() => fields.map( ( field ) => field.id ),
		[ fields ]
	);

	const defaultView: View = useMemo(
		() => ( {
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
		} ),
		[ initialFields ]
	);

	const { view, setView } = usePersistedView( 'mcp-tools', defaultView );

	useEffect( () => {
		setView( ( previous ) => {
			const availableFieldIds = fields.map( ( field ) => field.id );
			const previousFields = previous.fields ?? availableFieldIds;
			const visibleSet = new Set( previousFields );
			const nextFields = availableFieldIds.filter( ( id ) =>
				visibleSet.has( id )
			);
			return {
				...previous,
				fields: nextFields,
			};
		} );
	}, [ fields, setView ] );

	const { data: filteredTools, paginationInfo } = useMemo( () => {
		const result = filterSortAndPaginate( tools, view, fields );
		return (
			result ?? {
				data: tools,
				paginationInfo: { totalItems: tools.length, totalPages: 1 },
			}
		);
	}, [ tools, view, fields ] );

	const hasActiveFilters =
		Boolean( view.search ) || ( view.filters?.length ?? 0 ) > 0;

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
				{ ! canEditTools && (
					<Notice status="warning" isDismissible={ false }>
						{ ! globalEnabled
							? __(
									'Enable MCP globally to edit which abilities are exposed.',
									'ai'
							  )
							: __(
									'Enable the selected server to edit which abilities are exposed.',
									'ai'
							  ) }
					</Notice>
				) }

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
					empty={
						<p className="ai-mcp-server__hint">
							{ hasActiveFilters
								? __( 'No abilities match your filters.', 'ai' )
								: __(
										'Enable experiments to register more abilities for MCP clients.',
										'ai'
								  ) }
						</p>
					}
					searchLabel={ __( 'Search abilities', 'ai' ) }
				/>
			</CardBody>
		</Card>
	);
};

export default ToolsTable;
