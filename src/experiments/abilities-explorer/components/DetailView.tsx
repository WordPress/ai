/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import RouterLink, { BackToListLink } from './RouterLink';
import JsonBlock from './JsonBlock';
import ProviderBadge from './ProviderBadge';
import { isEmptyJson } from '../validate';
import type { AbilityDetailItem } from '../types';
import './detail.scss';

export interface DetailViewProps {
	/** The ability, loaded from the item route by the app shell. */
	item: AbilityDetailItem;
}

/**
 * Reports whether a schema section has anything to show: data, or a note that
 * its data could not be encoded.
 *
 * @param item  The ability.
 * @param field The schema field.
 * @return True when the section should render.
 */
const hasData = (
	item: AbilityDetailItem,
	field: 'input_schema' | 'output_schema'
): boolean =>
	! isEmptyJson( item[ field ] ) || item.unencodable_fields.includes( field );

interface UnencodableNoticeProps {
	item: AbilityDetailItem;
	field: string;
}

/**
 * Explains a field the server could not encode as JSON.
 *
 * @param props       Component props.
 * @param props.item  The ability.
 * @param props.field The field name.
 * @return The note, or null when the field encoded.
 */
function UnencodableNotice( { item, field }: UnencodableNoticeProps ) {
	if ( ! item.unencodable_fields.includes( field ) ) {
		return null;
	}

	return (
		<p className="description">
			{ __( 'This data could not be encoded as JSON.', 'ai' ) }
		</p>
	);
}

/**
 * The detail view for one ability.
 *
 * The app shell has already resolved the ability, so a missing one never
 * reaches this view.
 *
 * @param props      Component props.
 * @param props.item The ability.
 * @return The view.
 */
export default function DetailView( { item }: DetailViewProps ) {
	return (
		<div className="ai-abilities-explorer__detail">
			<div className="ai-abilities-explorer__view-actions">
				<BackToListLink />
				<RouterLink
					route={ { view: 'runner', ability: item.slug } }
					variant="primary"
				>
					{ __( 'Test Ability', 'ai' ) }
				</RouterLink>
			</div>

			<h2>{ item.name || item.slug }</h2>
			<p className="ai-abilities-explorer__slug">
				<code>{ item.slug }</code>
			</p>

			{ !! item.description && (
				<section className="ai-abilities-explorer__section">
					<h3>{ __( 'Description', 'ai' ) }</h3>
					<p>{ item.description }</p>
				</section>
			) }

			<section className="ai-abilities-explorer__section">
				<h3>{ __( 'Details', 'ai' ) }</h3>
				<table className="ai-abilities-explorer__detail-table">
					<tbody>
						<tr>
							<th scope="row">{ __( 'Provider', 'ai' ) }</th>
							<td>
								<ProviderBadge
									provider={ item.provider ?? '' }
									label={ item.provider_label }
								/>
							</td>
						</tr>
					</tbody>
				</table>
			</section>

			{ hasData( item, 'input_schema' ) && (
				<section className="ai-abilities-explorer__section">
					<h3>{ __( 'Input Schema', 'ai' ) }</h3>
					<UnencodableNotice item={ item } field="input_schema" />
					<JsonBlock
						value={ item.input_schema }
						label={ __( 'Input Schema', 'ai' ) }
					/>
				</section>
			) }

			{ hasData( item, 'output_schema' ) && (
				<section className="ai-abilities-explorer__section">
					<h3>{ __( 'Output Schema', 'ai' ) }</h3>
					<UnencodableNotice item={ item } field="output_schema" />
					<JsonBlock
						value={ item.output_schema }
						label={ __( 'Output Schema', 'ai' ) }
					/>
				</section>
			) }

			<section className="ai-abilities-explorer__section">
				<h3>{ __( 'Raw Data', 'ai' ) }</h3>
				<UnencodableNotice item={ item } field="raw_data" />
				<JsonBlock
					value={ item.raw_data }
					label={ __( 'Raw Data', 'ai' ) }
				/>
			</section>
		</div>
	);
}
