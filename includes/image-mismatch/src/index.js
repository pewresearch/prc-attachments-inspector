/**
 * WordPress Dependencies
 */
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { Fragment } from '@wordpress/element';

/**
 * Internal Dependencies
 */
import './style.scss';
import FixMismatchToolbar from './fix-mismatch-toolbar';

/**
 * Wrap core/image block edit with the Fix Mismatch toolbar button.
 * We use a HOC so we can read block attributes and inject into BlockControls
 * without replacing the block's own edit UI.
 */
const withFixMismatchToolbar = createHigherOrderComponent(
	(BlockEdit) =>
		function WithFixMismatchToolbar(props) {
			if (props.name !== 'core/image') {
				return <BlockEdit {...props} />;
			}
			return (
				<Fragment>
					<BlockEdit {...props} />
					<FixMismatchToolbar {...props} />
				</Fragment>
			);
		},
	'withFixMismatchToolbar'
);

addFilter(
	'editor.BlockEdit',
	'prc-attachments-inspector/fix-mismatch-toolbar',
	withFixMismatchToolbar
);
