/**
 * Drawable canvas overlay for mask-based image editing.
 *
 * Renders an image with a transparent canvas overlay that the user can draw
 * on to select regions. Drawn areas appear as semi-transparent red highlights.
 * The raw mask canvas is exposed via ref so the parent can read pixel data.
 */

/**
 * WordPress dependencies
 */
import {
	useRef,
	useState,
	useEffect,
	useCallback,
	useImperativeHandle,
	forwardRef,
} from '@wordpress/element';

export interface MaskCanvasHandle {
	undo: () => void;
	clear: () => void;
	getCanvas: () => HTMLCanvasElement | null;
}

interface Props {
	imageSrc: string;
	brushSize: number;
	onMaskChange?: ( hasMask: boolean ) => void;
}

const MAX_UNDO_STACK = 20;

/**
 * Drawable canvas overlay for selecting image regions.
 *
 * @param {Props}  props Component props.
 * @param {Object} ref   Imperative handle ref.
 */
export const MaskCanvas = forwardRef< MaskCanvasHandle, Props >(
	function InnerMaskCanvas( { imageSrc, brushSize, onMaskChange }, ref ) {
		const canvasRef = useRef< HTMLCanvasElement >( null );
		const ctxRef = useRef< CanvasRenderingContext2D | null >( null );
		const wrapperRef = useRef< HTMLDivElement >( null );
		const [ naturalSize, setNaturalSize ] = useState< {
			width: number;
			height: number;
		} | null >( null );
		const isDrawingRef = useRef( false );
		const undoStackRef = useRef< ImageData[] >( [] );
		const lastPointRef = useRef< { x: number; y: number } | null >( null );

		// Load image to get natural dimensions.
		useEffect( () => {
			const img = new Image();
			img.crossOrigin = 'anonymous';
			img.onload = () => {
				setNaturalSize( {
					width: img.naturalWidth,
					height: img.naturalHeight,
				} );
			};
			img.src = imageSrc;
		}, [ imageSrc ] );

		// Acquire 2D context with willReadFrequently once canvas is ready.
		useEffect( () => {
			if ( ! canvasRef.current || ! naturalSize ) {
				ctxRef.current = null;
				return;
			}
			ctxRef.current = canvasRef.current.getContext( '2d', {
				willReadFrequently: true,
			} );
		}, [ naturalSize ] );

		// Clear canvas when image source changes.
		useEffect( () => {
			if ( ! ctxRef.current || ! naturalSize ) {
				return;
			}
			ctxRef.current.clearRect(
				0,
				0,
				naturalSize.width,
				naturalSize.height
			);
			undoStackRef.current = [];
			onMaskChange?.( false );
		}, [ imageSrc, naturalSize, onMaskChange ] );

		/**
		 * Checks whether the mask canvas has any drawn pixels.
		 */
		const checkHasMask = useCallback( () => {
			if ( ! ctxRef.current || ! naturalSize ) {
				return false;
			}
			const data = ctxRef.current.getImageData(
				0,
				0,
				naturalSize.width,
				naturalSize.height
			).data;
			for ( let i = 3; i < data.length; i += 4 ) {
				if ( ( data[ i ] as number ) > 0 ) {
					return true;
				}
			}
			return false;
		}, [ naturalSize ] );

		/**
		 * Saves a snapshot of the current canvas to the undo stack.
		 */
		const saveSnapshot = useCallback( () => {
			if ( ! ctxRef.current || ! naturalSize ) {
				return;
			}
			const snapshot = ctxRef.current.getImageData(
				0,
				0,
				naturalSize.width,
				naturalSize.height
			);
			undoStackRef.current.push( snapshot );
			if ( undoStackRef.current.length > MAX_UNDO_STACK ) {
				undoStackRef.current.shift();
			}
		}, [ naturalSize ] );

		/**
		 * Converts pointer event coordinates to canvas coordinates.
		 */
		const toCanvasCoords = useCallback(
			( e: React.PointerEvent< HTMLCanvasElement > ) => {
				if ( ! canvasRef.current || ! naturalSize ) {
					return { x: 0, y: 0 };
				}
				const rect = canvasRef.current.getBoundingClientRect();
				return {
					x:
						( ( e.clientX - rect.left ) / rect.width ) *
						naturalSize.width,
					y:
						( ( e.clientY - rect.top ) / rect.height ) *
						naturalSize.height,
				};
			},
			[ naturalSize ]
		);

		/**
		 * Draws a brush stroke segment between two points.
		 */
		const drawSegment = useCallback(
			(
				from: { x: number; y: number },
				to: { x: number; y: number }
			) => {
				if ( ! canvasRef.current || ! ctxRef.current ) {
					return;
				}

				// Scale brush size relative to canvas resolution.
				const displayWidth =
					canvasRef.current.getBoundingClientRect().width;
				const scaledBrush =
					( brushSize / displayWidth ) *
					( naturalSize?.width ?? displayWidth );

				ctxRef.current.strokeStyle = 'rgba(255, 0, 0, 1)';
				ctxRef.current.lineWidth = scaledBrush;
				ctxRef.current.lineCap = 'round';
				ctxRef.current.lineJoin = 'round';
				ctxRef.current.beginPath();
				ctxRef.current.moveTo( from.x, from.y );
				ctxRef.current.lineTo( to.x, to.y );
				ctxRef.current.stroke();
			},
			[ brushSize, naturalSize ]
		);

		/**
		 * Draws a single dot at the given point.
		 */
		const drawDot = useCallback(
			( point: { x: number; y: number } ) => {
				if ( ! canvasRef.current || ! ctxRef.current ) {
					return;
				}

				const displayWidth =
					canvasRef.current.getBoundingClientRect().width;
				const scaledBrush =
					( brushSize / displayWidth ) *
					( naturalSize?.width ?? displayWidth );

				ctxRef.current.fillStyle = 'rgba(255, 0, 0, 1)';
				ctxRef.current.beginPath();
				ctxRef.current.arc(
					point.x,
					point.y,
					scaledBrush / 2,
					0,
					Math.PI * 2
				);
				ctxRef.current.fill();
			},
			[ brushSize, naturalSize ]
		);

		const handlePointerDown = useCallback(
			( e: React.PointerEvent< HTMLCanvasElement > ) => {
				e.preventDefault();
				isDrawingRef.current = true;
				saveSnapshot();
				const point = toCanvasCoords( e );
				lastPointRef.current = point;
				drawDot( point );
			},
			[ saveSnapshot, toCanvasCoords, drawDot ]
		);

		const handlePointerMove = useCallback(
			( e: React.PointerEvent< HTMLCanvasElement > ) => {
				if ( ! isDrawingRef.current || ! lastPointRef.current ) {
					return;
				}
				const point = toCanvasCoords( e );
				drawSegment( lastPointRef.current, point );
				lastPointRef.current = point;
			},
			[ toCanvasCoords, drawSegment ]
		);

		const handlePointerUp = useCallback( () => {
			if ( isDrawingRef.current ) {
				isDrawingRef.current = false;
				lastPointRef.current = null;
				onMaskChange?.( checkHasMask() );
			}
		}, [ checkHasMask, onMaskChange ] );

		// Expose imperative methods to parent.
		useImperativeHandle(
			ref,
			() => ( {
				undo() {
					if (
						! ctxRef.current ||
						! naturalSize ||
						undoStackRef.current.length === 0
					) {
						return;
					}
					const prev = undoStackRef.current.pop()!;
					ctxRef.current.putImageData( prev, 0, 0 );
					onMaskChange?.( checkHasMask() );
				},
				clear() {
					if ( ! ctxRef.current || ! naturalSize ) {
						return;
					}
					saveSnapshot();
					ctxRef.current.clearRect(
						0,
						0,
						naturalSize.width,
						naturalSize.height
					);
					undoStackRef.current = [];
					onMaskChange?.( false );
				},
				getCanvas() {
					return canvasRef.current;
				},
			} ),
			[ naturalSize, checkHasMask, saveSnapshot, onMaskChange ]
		);

		if ( ! naturalSize ) {
			return null;
		}

		return (
			<div ref={ wrapperRef } className="ai-mask-canvas">
				<img
					src={ imageSrc }
					alt=""
					className="ai-mask-canvas__image"
					draggable={ false }
				/>
				<canvas
					ref={ canvasRef }
					className="ai-mask-canvas__overlay"
					width={ naturalSize.width }
					height={ naturalSize.height }
					onPointerDown={ handlePointerDown }
					onPointerMove={ handlePointerMove }
					onPointerUp={ handlePointerUp }
					onPointerLeave={ handlePointerUp }
				/>
			</div>
		);
	}
);

MaskCanvas.displayName = 'MaskCanvas';
