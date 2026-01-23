/**
 * External Dependencies
 */
import clsx from 'clsx';
import { useKeyPress } from '@prc/hooks';
import { moreVertical, linkOff, edit, replace, addCard, flipVertical } from '@wordpress/icons';

/**
 * WordPress Dependencies
 */
import { __ } from '@wordpress/i18n';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { useState, useEffect, useRef, useMemo } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import {
	BaseControl,
	Tooltip,
	SelectControl,
	Modal,
	DropdownMenu,
	Flex,
	FlexItem,
	FlexBlock,
} from '@wordpress/components';


/**
 * Internal Dependencies
 */
import { useAttachments } from './context';

const IMAGE_SIZES = [
	{ label: '200 Wide', value: '200-wide' },
	{ label: '200 Wide', value: '200-wide' },
	{ label: '260 Wide', value: '260-wide' },
	{ label: '310 Wide', value: '310-wide' },
	{ label: '420 Wide', value: '420-wide' },
	{ label: '640 Wide', value: '640-wide' },
	{ label: '740 Wide', value: '740-wide' },
	{ label: '1400 Wide', value: '1400-wide' },
];

function Image({
	id,
	url,
	title,
	type,
	filename,
	alt,
	caption,
	editLink,
	attachmentLink,
}) {
	const { insertedImageIds, handleImageInsertion, handleImageReplacement, handleImageUnattach, imageBlockCurrentlySelected } =
		useAttachments();
	const { selectBlock, removeBlock } = useDispatch(blockEditorStore);

	const isActive = Object.keys(insertedImageIds).includes(id.toString());
	const [modalActive, toggleModal] = useState(false);
	const shiftKeyPressed = useKeyPress('Shift');
	const optionKeyPressed = useKeyPress('Alt');
	const commandKeyPressed = useKeyPress('Meta');

	const [controls, setControls] = useState([]);

	useEffect(() => {
		const defaults = [
			{
				title: __('Edit in Media Library', 'prc-block-plugins'),
				icon: edit,
				onClick: () => {
					window.open(editLink, '_blank');
				},
			},
			{
				title: __('Unattach from Post', 'prc-block-plugins'),
				icon: linkOff,
				onClick: () => {
					if (window.confirm(__('Are you sure you want to unattach this image?', 'prc-block-plugins'))) {
						handleImageUnattach(id);
					}
				},
			}
		];
		if ( imageBlockCurrentlySelected ) {
			defaults.push({
				title: __('Replace Selection in Editor', 'prc-block-plugins'),
				icon: replace,
				onClick: () => {
					handleImageReplacement(id, url, attachmentLink, alt, caption);
				},
			});
		}
		if ( isActive ) {
			defaults.push({
				title: __('Remove from Editor', 'prc-block-plugins'),
				icon: flipVertical,
				onClick: () => {
					if (isActive) {
						const { clientId } = insertedImageIds[id];
						selectBlock(clientId);
						removeBlock(clientId);
					}
				}
			});
			defaults.push({
				title: __('Select in Editor', 'prc-block-plugins'),
				icon: 'editor-code',
				onClick: () => {
					selectBlock(insertedImageIds[id].clientId);
				},
			});
		} else {
			defaults.push({
				title: __('Insert into Editor', 'prc-block-plugins'),
				icon: addCard,
				onClick: () => {
					toggleModal(true);
				},
			});
		}
		setControls(defaults);
	}, [id, url, alt, caption, editLink, attachmentLink, isActive, insertedImageIds, handleImageInsertion, handleImageReplacement, handleImageUnattach, selectBlock]);

	// const ref = useRef(null);

	return (
		<BaseControl>
			<div className="prc-attachments-list__image-wrapper">
				<button
					type="button"
					key={id}
					className={clsx({
						'prc-attachments-list__image': true,
						'prc-attachments-list__image--in-use': isActive,
					})}
					onClick={() => {
						if (isActive) {
							selectBlock(insertedImageIds[id].clientId);
						} else if (shiftKeyPressed) {
							handleImageInsertion(id, url, '640-wide', alt, caption);
						} else if (optionKeyPressed) {
							handleImageReplacement(id, url, attachmentLink);
						} else if (commandKeyPressed) {
							window.open(editLink, '_blank');
						} else {
							toggleModal(true);
						}
					}}
				>
					<img src={url} alt={alt} />
				</button>
				<Flex justify="space-between" align="center" gap={2}>
					<FlexBlock>{title}</FlexBlock>
					<FlexItem>
						<DropdownMenu
							icon={moreVertical}
							label={__('Image actions', 'prc-block-plugins')}
							controls={controls}
						/>
					</FlexItem>
				</Flex>
			</div>
			{modalActive && (
				<Modal
					title={__('Insert Image Into Editor', 'prc-block-plugins')}
					onRequestClose={() => toggleModal(false)}
				>
					<SelectControl
						label="Select Image Size"
						value={null}
						options={IMAGE_SIZES}
						onChange={(newSize) => {
							handleImageInsertion(
								id,
								url,
								newSize,
								alt,
								caption
							);
							toggleModal(false);
						}}
					/>
				</Modal>
			)}
		</BaseControl>
	);
}

export default Image;
