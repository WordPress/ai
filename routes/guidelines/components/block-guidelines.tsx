/**
 * WordPress dependencies
 */
import {
	Button,
	Icon as WCIcon,
	Notice,
	__experimentalConfirmDialog as ConfirmDialog,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import type { Field, View } from '@wordpress/dataviews';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { blockDefault } from '@wordpress/icons';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { blockSlug, deleteGuidelineRow } from '../data';
import type { ContentBlock, GuidelineQuery, GuidelineRow } from '../types';
import BlockGuidelineModal from './block-guideline-modal';
import './block-guidelines.scss';

const PER_PAGE = 5;

const initialView: View = {
	type: 'list',
	search: '',
	page: 1,
	perPage: PER_PAGE,
	filters: [],
	mediaField: 'icon',
	showMedia: true,
	titleField: 'label',
	// Default (non-compact) density: its 48px media tile gives block icons
	// padding around the canonical 24px render, without shrinking the icon
	// (which would crop icons that lack a viewBox, e.g. core/icon).
};

interface DataRow {
	id: string;
	label: string;
	guidelines: string;
	icon?: unknown;
}

const fields: Field< DataRow >[] = [
	{
		id: 'icon',
		label: __( 'Icon', 'ai' ),
		type: 'media' as const,
		// No `size` prop: block icons render at their native 24px, matching the
		// editor's `.block-editor-block-icon`. That keeps viewBox-less icons
		// (e.g. core/icon) centered and uncropped. Painted and clamped in
		// block-guidelines.scss.
		render: ( { item }: { item: DataRow } ) => (
			<div className="block-guidelines__icon">
				<WCIcon icon={ ( item.icon ?? blockDefault ) as never } />
			</div>
		),
	},
	{
		id: 'label',
		label: __( 'Label', 'ai' ),
		type: 'text' as const,
		enableGlobalSearch: true,
		getValue: ( { item }: { item: DataRow } ) => item.label,
		render: ( { item }: { item: DataRow } ) => item.label,
	},
];

interface BlockGuidelinesProps {
	contentBlocks: ContentBlock[];
	bySlug: Record< string, GuidelineRow >;
	query: GuidelineQuery;
}

export default function BlockGuidelines( {
	contentBlocks,
	bySlug,
	query,
}: BlockGuidelinesProps ) {
	const [ isOpen, setIsOpen ] = useState( false );
	const [ view, setView ] = useState< View >( initialView );
	const [ selectedItem, setSelectedItem ] = useState< string >();
	const [ error, setError ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ itemToDelete, setItemToDelete ] = useState< DataRow | null >(
		null
	);
	const { createSuccessNotice } = useDispatch( noticesStore );

	const rows = useMemo(
		() =>
			contentBlocks
				.filter( ( block ) => bySlug[ blockSlug( block.name ) ] )
				.map( ( block ) => ( {
					id: block.name,
					label: block.title,
					guidelines:
						bySlug[ blockSlug( block.name ) ]?.content ?? '',
					icon: block.icon?.src,
				} ) ),
		[ contentBlocks, bySlug ]
	);

	const handleRowClick = ( id: string ) => {
		setSelectedItem( id );
		setIsOpen( true );
	};

	const actions = useMemo(
		() => [
			{
				id: 'edit',
				label: __( 'Edit', 'ai' ),
				callback: ( items: DataRow[] ) => {
					const item = items[ 0 ];
					if ( item ) {
						handleRowClick( item.id );
					}
				},
			},
			{
				id: 'remove',
				label: __( 'Remove', 'ai' ),
				callback: ( items: DataRow[] ) => {
					setItemToDelete( items[ 0 ] ?? null );
				},
			},
		],
		[ setItemToDelete ]
	);

	const handleDelete = () => {
		if ( ! itemToDelete ) {
			return;
		}
		const row = bySlug[ blockSlug( itemToDelete.id ) ];
		if ( ! row ) {
			setItemToDelete( null );
			return;
		}
		setBusy( true );
		deleteGuidelineRow( row.id )
			.then( () => {
				setError( null );
				createSuccessNotice( __( 'Guidelines removed.', 'ai' ), {
					type: 'snackbar',
				} );
			} )
			.catch( ( e: Error ) => setError( e.message ) )
			.finally( () => {
				setBusy( false );
				setItemToDelete( null );
			} );
	};

	const { data: processedData, paginationInfo } = useMemo(
		() => filterSortAndPaginate( rows, view, fields ),
		[ rows, view ]
	);

	useEffect( () => {
		const lastPage = Math.max( paginationInfo.totalPages, 1 );

		if ( view.page && view.page > lastPage ) {
			setView( ( currentView ) =>
				currentView.page && currentView.page > lastPage
					? {
							...currentView,
							page: lastPage,
					  }
					: currentView
			);
		}
	}, [ paginationInfo.totalPages, view.page ] );

	const closeModal = () => {
		setIsOpen( false );
		setSelectedItem( undefined );
	};

	const openModal = () => {
		setSelectedItem( undefined );
		setIsOpen( true );
	};

	const shouldShowDataViewControls = rows.length > PER_PAGE;

	return (
		<VStack spacing={ 4 } className="block-guidelines">
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ sprintf(
						/* translators: %s: Error message. */
						__( 'Error: %s', 'ai' ),
						error
					) }
				</Notice>
			) }
			{ rows.length > 0 && (
				<DataViews
					paginationInfo={ paginationInfo }
					data={ processedData }
					view={ view }
					onChangeView={ setView }
					fields={ fields }
					actions={ actions }
					config={ { perPageSizes: [ PER_PAGE ] } }
					onChangeSelection={ ( items ) => {
						const id = items[ 0 ];
						if ( id ) {
							handleRowClick( id );
						}
					} }
					defaultLayouts={ {
						list: true,
					} }
				>
					<VStack spacing={ 4 }>
						{ shouldShowDataViewControls && (
							<DataViews.Search
								label={ __( 'Search blocks', 'ai' ) }
							/>
						) }
						<DataViews.Layout />
						{ shouldShowDataViewControls && <DataViews.Footer /> }
					</VStack>
				</DataViews>
			) }
			<HStack>
				<Button
					variant="primary"
					onClick={ openModal }
					__next40pxDefaultSize
				>
					{ __( 'Add guidelines', 'ai' ) }
				</Button>
			</HStack>

			{ isOpen && (
				<BlockGuidelineModal
					closeModal={ closeModal }
					initialBlock={ selectedItem ?? undefined }
					contentBlocks={ contentBlocks }
					bySlug={ bySlug }
					query={ query }
				/>
			) }
			<ConfirmDialog
				isOpen={ !! itemToDelete }
				title={ __( 'Remove block guidelines', 'ai' ) }
				__experimentalHideHeader={ false }
				onConfirm={ handleDelete }
				onCancel={ () => setItemToDelete( null ) }
				confirmButtonText={ __( 'Remove', 'ai' ) }
				isBusy={ busy }
				size="small"
			>
				{ sprintf(
					/* translators: %s: Block name. */
					__(
						'You are about to remove the block guidelines for the %s block.',
						'ai'
					),
					itemToDelete?.label ?? ''
				) }
			</ConfirmDialog>
		</VStack>
	);
}
