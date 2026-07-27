/**
 * WordPress Dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useCallback, useMemo } from '@wordpress/element';
import {
	BlockControls,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { createBlock } from '@wordpress/blocks';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';
import { replace } from '@wordpress/icons';
import {
	ToolbarGroup,
	ToolbarButton,
	Popover,
	Button,
	Spinner,
	Notice,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal Dependencies
 */
import {
	isLegacyImageUrl,
	findMatchingAttachments,
	inferSizeSlugFromUrl,
	isIdSrcFilenameMismatch,
	normalizeFilename,
} from './detect-mismatch';
import usePostAttachments, {
	invalidatePostAttachments,
} from './use-post-attachments';

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
 *
 * @param {Object} existingAttrs Existing core/image attributes.
 * @param {Object} attachment    Matched attachment payload.
 * @return {Object} Replacement attributes.
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
 *
 * @param {Object}      props
 * @param {Array}       props.matches    Candidate attachments.
 * @param {Function}    props.onPick     Called when an attachment is selected.
 * @param {Function}    props.onClose    Called when the popover closes.
 * @param {Function}    props.onImport   Called to sideload from the block src.
 * @param {boolean}     props.showImport Whether to show the import CTA.
 * @param {boolean}     props.busy       Whether a request is in flight.
 * @param {string|null} props.error      Error message to display.
 * @return {Object} Picker popover.
 */
function MatchPicker({
	matches,
	onPick,
	onClose,
	onImport,
	showImport,
	busy,
	error,
}) {
	return (
		<Popover
			position="bottom center"
			onClose={onClose}
			className="prc-image-mismatch-picker__popover"
			noArrow={false}
		>
			<div className="prc-image-mismatch-picker">
				<p className="prc-image-mismatch-picker__title">
					{matches.length > 0
						? __(
								'Select replacement image',
								'prc-attachments-inspector'
							)
						: __(
								'No media library match',
								'prc-attachments-inspector'
							)}
				</p>
				{busy && (
					<div className="prc-image-mismatch-picker__busy">
						<Spinner />
					</div>
				)}
				{error && (
					<Notice
						status="error"
						isDismissible={false}
						className="prc-image-mismatch-picker__notice"
					>
						{error}
					</Notice>
				)}
				{!busy &&
					matches.map((attachment) => (
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
				{!busy && showImport && (
					<div className="prc-image-mismatch-picker__import">
						<p className="prc-image-mismatch-picker__import-help">
							{__(
								'No matching attachment found. Download the image from the current src and add it to the media library.',
								'prc-attachments-inspector'
							)}
						</p>
						<Button
							variant="primary"
							onClick={onImport}
							disabled={busy}
						>
							{__('Import from URL', 'prc-attachments-inspector')}
						</Button>
					</div>
				)}
			</div>
		</Popover>
	);
}

/**
 * BlockEdit HOC wrapper for core/image.
 *
 * Case A: legacy src + matching post-attached filename → Fix Mismatch.
 * Case B: id missing/invalid or attachment filename ≠ src → search ML / import.
 *
 * @param {Object} props
 * @param {string} props.clientId   Block client id.
 * @param {Object} props.attributes Block attributes.
 * @return {Object|null} Toolbar controls when a mismatch can be fixed.
 */
export default function FixMismatchToolbar({ clientId, attributes }) {
	const { url, id } = attributes;
	const { attachments, status } = usePostAttachments();
	const { replaceBlock } = useDispatch(blockEditorStore);

	const postId = useSelect(
		(select) => select(editorStore).getCurrentPostId(),
		[]
	);

	const { attachment, attachmentResolved } = useSelect(
		(select) => {
			if (!id) {
				return { attachment: null, attachmentResolved: true };
			}
			const core = select(coreStore);
			return {
				attachment: core.getEntityRecord('postType', 'attachment', id),
				attachmentResolved: core.hasFinishedResolution(
					'getEntityRecord',
					['postType', 'attachment', id]
				),
			};
		},
		[id]
	);

	const [pickerOpen, setPickerOpen] = useState(false);
	const [libraryMatches, setLibraryMatches] = useState([]);
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState(null);
	const [searched, setSearched] = useState(false);

	const caseAMatches = useMemo(
		() =>
			isLegacyImageUrl(url)
				? findMatchingAttachments(url, attachments)
				: [],
		[url, attachments]
	);
	const isCaseA = caseAMatches.length > 0;
	const isCaseB =
		!isCaseA &&
		isIdSrcFilenameMismatch({
			url,
			id,
			attachment,
			attachmentResolved,
		});

	const performReplacement = useCallback(
		(attachmentData) => {
			const newAttrs = buildReplacementAttrs(attributes, attachmentData);
			replaceBlock(clientId, createBlock('core/image', newAttrs));
			setPickerOpen(false);
			setLibraryMatches([]);
			setSearched(false);
			setError(null);
		},
		[attributes, clientId, replaceBlock]
	);

	const reparentAndReplace = useCallback(
		async (attachmentData) => {
			setBusy(true);
			setError(null);
			try {
				const parent = Number(attachmentData.post_parent);
				if (postId && parent !== Number(postId)) {
					await apiFetch({
						path: `/wp/v2/media/${attachmentData.id}`,
						method: 'POST',
						data: { post: postId },
					});
				}
				performReplacement(attachmentData);
				invalidatePostAttachments(postId);
				return true;
			} catch (err) {
				setError(
					err?.message ||
						__(
							'Failed to attach image to this post.',
							'prc-attachments-inspector'
						)
				);
				return false;
			} finally {
				setBusy(false);
			}
		},
		[performReplacement, postId]
	);

	const searchLibrary = useCallback(async () => {
		setBusy(true);
		setError(null);
		setSearched(false);
		setLibraryMatches([]);
		try {
			const response = await apiFetch({
				path: '/prc-api/v3/image-mismatch/find',
				method: 'POST',
				data: {
					post_id: postId,
					url,
					filename: normalizeFilename(url),
				},
			});
			const matches = Array.isArray(response?.matches)
				? response.matches
				: [];
			setLibraryMatches(matches);
			setSearched(true);
			if (matches.length === 1) {
				const ok = await reparentAndReplace(matches[0]);
				if (!ok) {
					// Surface the reparent error in the picker.
					setPickerOpen(true);
				}
				return;
			}
			// 0 matches → offer import; N matches → picker.
			setPickerOpen(true);
		} catch (err) {
			setError(
				err?.message ||
					__(
						'Media library search failed.',
						'prc-attachments-inspector'
					)
			);
			// Do not mark searched — Import CTA is only for a successful empty find.
			setPickerOpen(true);
		} finally {
			setBusy(false);
		}
	}, [postId, reparentAndReplace, url]);

	const importFromUrl = useCallback(async () => {
		setBusy(true);
		setError(null);
		try {
			const response = await apiFetch({
				path: '/prc-api/v3/image-mismatch/import',
				method: 'POST',
				data: {
					post_id: postId,
					url,
				},
			});
			if (!response?.attachment) {
				throw new Error(
					__(
						'Import succeeded but no attachment was returned.',
						'prc-attachments-inspector'
					)
				);
			}
			performReplacement(response.attachment);
			invalidatePostAttachments(postId);
		} catch (err) {
			setError(
				err?.message ||
					__(
						'Failed to import image from URL.',
						'prc-attachments-inspector'
					)
			);
		} finally {
			setBusy(false);
		}
	}, [performReplacement, postId, url]);

	const handleButtonClick = useCallback(() => {
		if (isCaseA) {
			if (caseAMatches.length === 1) {
				performReplacement(caseAMatches[0]);
			} else if (caseAMatches.length > 1) {
				setLibraryMatches(caseAMatches);
				setSearched(true);
				setPickerOpen((open) => !open);
			}
			return;
		}
		if (isCaseB) {
			if (pickerOpen) {
				setPickerOpen(false);
				return;
			}
			searchLibrary();
		}
	}, [
		caseAMatches,
		isCaseA,
		isCaseB,
		performReplacement,
		pickerOpen,
		searchLibrary,
	]);

	// Case A needs the post-attached media list. Case B uses entity + find/import
	// only, but must wait while loading when the URL is legacy so Case A can win.
	const showToolbar =
		(isCaseA && status !== 'loading') ||
		(isCaseB && (status !== 'loading' || !isLegacyImageUrl(url)));

	if (!showToolbar) {
		return null;
	}

	let buttonLabel = __('Fix Mismatch', 'prc-attachments-inspector');
	if (busy) {
		buttonLabel = __('Working…', 'prc-attachments-inspector');
	} else if (isCaseA && caseAMatches.length > 1) {
		buttonLabel = `${buttonLabel} (${caseAMatches.length})`;
	}

	const pickerMatches = isCaseA ? caseAMatches : libraryMatches;
	const showImport = isCaseB && searched && libraryMatches.length === 0;

	return (
		<>
			<BlockControls group="other">
				<ToolbarGroup>
					<ToolbarButton
						icon={replace}
						label={buttonLabel}
						showTooltip
						onClick={handleButtonClick}
						disabled={busy}
					>
						{buttonLabel}
					</ToolbarButton>
					{pickerOpen && (
						<MatchPicker
							matches={pickerMatches}
							onPick={
								isCaseA
									? performReplacement
									: reparentAndReplace
							}
							onClose={() => setPickerOpen(false)}
							onImport={importFromUrl}
							showImport={showImport}
							busy={busy}
							error={error}
						/>
					)}
				</ToolbarGroup>
			</BlockControls>
		</>
	);
}
