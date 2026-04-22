/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { Button, Spinner, Notice } from '@wordpress/components';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { runAbility } from '../../utils/run-ability';
import './index.scss';

const data = window.aiContentProvenanceData || {};

// ── Badge component ──────────────────────────────────────────────────────────

const BADGE_CONFIG = {
	verified: {
		color: '#00a32a',
		fill: '#d7f0de',
		icon: '\u2713',
		label: __( 'Signed — Identity Verified', 'ai' ),
	},
	local_signed: {
		color: '#2271b1',
		fill: '#e8f3fb',
		icon: '\u25C8',
		label: __( 'Signed — Content Integrity Verified', 'ai' ),
	},
	modified: {
		color: '#dba617',
		fill: '#fcf9e8',
		icon: '\u26A0',
		label: __( 'Modified Since Signing', 'ai' ),
	},
	tampered: {
		color: '#cc1818',
		fill: '#fce8e8',
		icon: '\u2717',
		label: __( 'Tamper Detected', 'ai' ),
	},
	unsigned: {
		color: '#8c8f94',
		fill: '#f6f7f7',
		icon: '\u25CB',
		label: __( 'Not Signed', 'ai' ),
	},
	loading: {
		color: '#8c8f94',
		fill: '#f6f7f7',
		icon: '\u2026',
		label: __( 'Checking\u2026', 'ai' ),
	},
};

const ShieldBadge = ( { status } ) => {
	const cfg = BADGE_CONFIG[ status ] || BADGE_CONFIG.unsigned;
	return (
		<div
			className="content-provenance-badge"
			style={ {
				background: cfg.fill,
				'--badge-color': cfg.color,
			} }
		>
			<svg
				viewBox="0 0 24 24"
				width="20"
				height="20"
				fill="none"
				stroke={ cfg.color }
				strokeWidth="2"
				strokeLinecap="round"
				strokeLinejoin="round"
				aria-hidden="true"
			>
				<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
				{ status === 'verified' && <path d="M9 12l2 2 4-4" /> }
				{ status === 'tampered' && (
					<>
						<line x1="15" y1="9" x2="9" y2="15" />
						<line x1="9" y1="9" x2="15" y2="15" />
					</>
				) }
			</svg>
			<span
				className="content-provenance-badge__label"
				style={ { color: cfg.color } }
			>
				{ cfg.label }
			</span>
		</div>
	);
};

// ── Trust tier notice ────────────────────────────────────────────────────────

const TIER_LABELS = {
	local: __( 'local key', 'ai' ),
	connected: __( 'CA-verified provider', 'ai' ),
	byok: __( 'publisher certificate', 'ai' ),
};

const TrustTierNotice = ( { tier, status } ) => {
	if ( 'loading' === status ) {
		return null;
	}

	const tierLabel = TIER_LABELS[ tier ] || TIER_LABELS.local;

	if ( 'unsigned' === status ) {
		return (
			<p className="content-provenance-panel__tier-info">
				{ sprintf(
					/* translators: %s: signing tier label (e.g. "local key") */
					__( 'Will be signed with %s.', 'ai' ),
					tierLabel
				) }
			</p>
		);
	}

	return null;
};

// ── Main panel ───────────────────────────────────────────────────────────────

const ContentProvenancePanel = () => {
	const postId = useSelect(
		( select ) => select( editorStore ).getCurrentPostId(),
		[]
	);

	const [ status, setStatus ] = useState( 'loading' );
	const [ signedAt, setSignedAt ] = useState( null );
	const [ signerTier, setSignerTier ] = useState(
		data.signerTier || 'local'
	);
	const [ verifyResult, setVerifyResult ] = useState( null );
	const [ isSigning, setIsSigning ] = useState( false );
	const [ isVerifying, setIsVerifying ] = useState( false );
	const [ error, setError ] = useState( '' );

	const fetchStatus = useCallback( () => {
		if ( ! postId ) {
			return;
		}
		setStatus( 'loading' );
		apiFetch( {
			path: `c2pa-provenance/v1/status?post_id=${ postId }`,
		} )
			.then( ( res ) => {
				setStatus( res.status || 'unsigned' );
				setSignedAt( res.signed_at || null );
				setSignerTier( res.signer_tier || data.signerTier || 'local' );
				setError( '' );
			} )
			.catch( () => {
				setStatus( 'unsigned' );
				setError( '' );
			} );
	}, [ postId ] );

	useEffect( () => {
		fetchStatus();
	}, [ fetchStatus ] );

	const handleSign = () => {
		if ( isSigning ) {
			return;
		}
		setIsSigning( true );
		setError( '' );
		runAbility( 'c2pa/sign', { post_id: postId } )
			.then( () => {
				setIsSigning( false );
				fetchStatus();
			} )
			.catch( ( err ) => {
				setIsSigning( false );
				setError( err?.message || __( 'Signing failed.', 'ai' ) );
			} );
	};

	const handleVerify = () => {
		if ( isVerifying ) {
			return;
		}
		setIsVerifying( true );
		setVerifyResult( null );
		runAbility( 'c2pa/verify', { post_id: postId } )
			.then( ( res ) => {
				setVerifyResult( res );
				setIsVerifying( false );
				if ( res.status ) {
					setStatus( res.status );
				}
			} )
			.catch( () => {
				setIsVerifying( false );
				setError( __( 'Verification failed.', 'ai' ) );
			} );
	};

	if ( ! data.enabled ) {
		return (
			<PluginDocumentSettingPanel
				name="content-provenance-panel"
				title={ __( 'Content Provenance', 'ai' ) }
				className="content-provenance-panel"
				initialOpen={ false }
			>
				<p className="content-provenance-panel__disabled-text">
					{ __(
						'Enable the Content Provenance experiment in the AI plugin settings to add C2PA provenance to published content.',
						'ai'
					) }
				</p>
			</PluginDocumentSettingPanel>
		);
	}

	const chainInfo = signedAt ? (
		<p className="content-provenance-panel__chain-info">
			{ __( 'Last signed:', 'ai' ) } { signedAt }
			{ signerTier && <> &middot; { signerTier }</> }
		</p>
	) : null;

	return (
		<PluginDocumentSettingPanel
			name="content-provenance-panel"
			title={ __( 'Content Provenance', 'ai' ) }
			className="content-provenance-panel"
			initialOpen
		>
			<ShieldBadge status={ status === 'loading' ? 'loading' : status } />
			<TrustTierNotice tier={ signerTier } status={ status } />
			{ chainInfo }
			{ error && (
				<Notice
					status="error"
					isDismissible={ false }
					className="content-provenance-panel__notice"
				>
					{ error }
				</Notice>
			) }
			{ verifyResult && (
				<Notice
					status={ verifyResult.verified ? 'success' : 'warning' }
					isDismissible
					onRemove={ () => setVerifyResult( null ) }
					className="content-provenance-panel__notice"
				>
					{ verifyResult.verified
						? __(
								'Content integrity confirmed — no tampering detected.',
								'ai'
						  )
						: __( 'Verification result:', 'ai' ) +
						  ( verifyResult.status || 'unknown' ) }
				</Notice>
			) }
			<div className="content-provenance-panel__actions">
				<Button
					variant="primary"
					isSmall
					onClick={ handleSign }
					disabled={ isSigning }
				>
					{ isSigning ? <Spinner /> : __( 'Sign Now', 'ai' ) }
				</Button>
				<Button
					variant="secondary"
					isSmall
					onClick={ handleVerify }
					disabled={ isVerifying || 'unsigned' === status }
				>
					{ isVerifying ? <Spinner /> : __( 'Verify', 'ai' ) }
				</Button>
			</div>
		</PluginDocumentSettingPanel>
	);
};

registerPlugin( 'content-provenance', {
	render: ContentProvenancePanel,
	icon: 'shield',
} );
