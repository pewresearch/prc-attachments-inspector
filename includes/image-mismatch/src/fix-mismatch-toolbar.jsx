/**
 * WordPress Dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useCallback } from '@wordpress/element';
import { BlockControls } from '@wordpress/block-editor';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { createBlock } from '@wordpress/blocks';
import { useDispatch } from '@wordpress/data';
import { replace } from '@wordpress/icons';
import { ToolbarGroup, ToolbarButton, Popover } from '@wordpress/components';

/**
 * Internal Dependencies
 */
import {
	isLegacyImageUrl,
	findMatchingAttachments,
	inferSizeSlugFromUrl,
} from './detect-mismatch';
import usePostAttachments from './use-post-attachments';

/**
 * Build the replacement block attributes by merging the matched attachment data
 * over the existing block attributes.
 *
 * Preserves: align, linkDestination, linkTarget, linkClass, any other attrs.
 * Overrides: id, url, sizeSlug, href, alt, caption.
 *
 * sizeSlug resolution order:
 *   1. existing block's sizeSlug (preserve user intent)
 *   2. inferred from the legacy URL's "NNNpx" hint (e.g. "...640px.png" → "640-wide")
 *   3. "full" (safe default — render at original uploaded size)
 */
function buildReplacementAttrs(existingAttrs, attachment) {
	const sizeSlug =
		existingAttrs.sizeSlug ||
		inferSizeSlugFromUrl(existingAttrs.url) ||
		'full';

	return {
		...existingAttrs,
		id: attachment.id,
		url: attachment.url,
		sizeSlug,
		href: attachment.attachmentLink || existingAttrs.href,
		alt: attachment.alt || existingAttrs.alt || '',
		caption: attachment.caption || existingAttrs.caption || '',
	};
}

/**
 * Multi-match picker rendered in a Popover below the toolbar button.
 */
function MatchPicker({ matches, onPick, onClose }) {
	return (
		<Popover
			position="bottom center"
			onClose={onClose}
			className="prc-image-mismatch-picker__popover"
			noArrow={false}
		>
			<div className="prc-image-mismatch-picker">
				<p className="prc-image-mismatch-picker__title">
					{__(
						'Select replacement image',
						'prc-attachments-inspector'
					)}
				</p>
				{matches.map((attachment) => (
					<button
						key={attachment.id}
						type="button"
						className="prc-image-mismatch-picker__item"
						onClick={() => onPick(attachment)}
					>
						{attachment.url && (
							<img
								src={attachment.url}
								alt={attachment.alt || ''}
							/>
						)}
						<span className="prc-image-mismatch-picker__item-meta">
							<span className="prc-image-mismatch-picker__item-title">
								{attachment.title}
							</span>
							<span className="prc-image-mismatch-picker__item-filename">
								{attachment.filename}
							</span>
						</span>
					</button>
				))}
			</div>
		</Popover>
	);
}

/**
 * BlockEdit HOC wrapper for core/image.
 *
 * Renders the original block unchanged except when the image src is "legacy"
 * (assets.pewresearch.org or /sites/N/ where N ≠ 20) AND at least one post
 * attachment has a matching filename.  In that case a "Fix Mismatch" toolbar
 * button is injected:
 *   - 1 match  → replaces immediately on click.
 *   - N matches → opens a small Popover picker.
 */
export default function FixMismatchToolbar({ clientId, attributes, ...props }) {
	const { url } = attributes;
	const { attachments, status } = usePostAttachments();
	const { replaceBlock } = useDispatch(blockEditorStore);

	const [pickerOpen, setPickerOpen] = useState(false);

	const matches = isLegacyImageUrl(url)
		? findMatchingAttachments(url, attachments)
		: [];

	const performReplacement = useCallback(
		(attachment) => {
			const newAttrs = buildReplacementAttrs(attributes, attachment);
			replaceBlock(clientId, createBlock('core/image', newAttrs));
			setPickerOpen(false);
		},
		[attributes, clientId, replaceBlock]
	);

	const handleButtonClick = useCallback(() => {
		if (matches.length === 1) {
			performReplacement(matches[0]);
		} else if (matches.length > 1) {
			setPickerOpen((open) => !open);
		}
	}, [matches, performReplacement]);

	// Don't render any toolbar addition when there's nothing to fix.
	if (
		!isLegacyImageUrl(url) ||
		status === 'loading' ||
		matches.length === 0
	) {
		return null;
	}

	const buttonLabel =
		matches.length > 1
			? __('Fix Mismatch', 'prc-attachments-inspector') +
			` (${matches.length})`
			: __('Fix Mismatch', 'prc-attachments-inspector');

	return (
		<>
			<BlockControls group="other">
				<ToolbarGroup>
					<ToolbarButton
						icon={replace}
						label={buttonLabel}
						showTooltip
						onClick={handleButtonClick}
					/>
					{pickerOpen && (
						<MatchPicker
							matches={matches}
							onPick={performReplacement}
							onClose={() => setPickerOpen(false)}
						/>
					)}
				</ToolbarGroup>
			</BlockControls>
		</>
	);
}
