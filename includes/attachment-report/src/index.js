/**
 * External Dependencies
 */

/**
 * WordPress Dependencies
 */
import domReady from '@wordpress/dom-ready';
import { createRoot, useMemo, useState } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { media } from '@wordpress/icons';

/**
 * Internal Dependencies
 */
import { ProvideAttachments } from './context';
import AdminColumnButton from './admin-column-button';
import AttachmentsModal from './modal';

import './style.scss';

function openAttachmentsReport(item) {
	const postId = item?.id;
	const postType =
		item?.post_type || window?.prcWpAdminDataview?.postType || 'post';
	if (!postId) {
		return;
	}

	const mount = document.createElement('div');
	mount.className = 'prc-attachments-report-dataview-mount';
	document.body.appendChild(mount);
	const root = createRoot(mount);

	const close = () => {
		root.unmount();
		mount.remove();
	};

	root.render(
		<ProvideAttachments postId={postId} postType={postType} enabled={true}>
			<AttachmentsModal onClose={close} />
		</ProvideAttachments>
	);
}

// Always register — do not gate on window.prcWpAdminDataview at parse time.
// This script must load after the shell (see PHP script dependency).
addFilter(
	'prcWpAdminDataview.actions',
	'prc-attachments-inspector/attachments-report',
	(actions) => [
		...actions,
		{
			id: 'attachments-report',
			label: __('View Attachments', 'prc-attachments-inspector'),
			icon: media,
			callback: ([item]) => openAttachmentsReport(item),
			isEligible: (item) => !!item?.id,
		},
	]
);

const AdminColumnAttachmentsReport = ({ postId, postType }) => {
	const [hovered, setIsHovered] = useState(false);

	const handleHover = () => {
		if (!hovered) {
			setIsHovered(true);
		}
	};

	const initialized = useMemo(() => {
		return hovered;
	}, [hovered]);

	return (
		<ProvideAttachments
			postId={postId}
			postType={postType}
			enabled={initialized}
		>
			<AdminColumnButton
				initialized={initialized}
				handleHover={handleHover}
			/>
		</ProvideAttachments>
	);
};

const FrontendAttachmentsReport = ({ postId, postType }) => {
	return (
		<ProvideAttachments postId={postId} postType={postType} enabled={true}>
			<AttachmentsModal
				onClose={() => {
					// Redirect to the current url but remove the ?attachmentsReport=true from the end
					window.location = window.location.href.replace(
						'?attachmentsReport=true',
						''
					);
				}}
			/>
		</ProvideAttachments>
	);
};

function initFrontend() {
	const attach = document.getElementById(
		'js-prc-attachments-report-frontend'
	);
	if (attach) {
		const { posttype, postid } = attach.dataset;
		const postType = posttype;
		const postId = postid;
		createRoot(attach).render(
			<FrontendAttachmentsReport postId={postId} postType={postType} />
		);
	}
}

function initButtons() {
	const buttons = document.querySelectorAll(
		'.prc-view-attachments-report-button'
	);
	buttons.forEach((button) => {
		const { posttype, postid } = button.dataset;
		const postType = posttype;
		const postId = postid;
		createRoot(button).render(
			<AdminColumnAttachmentsReport postId={postId} postType={postType} />
		);
	});
}

domReady(() => {
	if (document.querySelector('.prc-view-attachments-report-button')) {
		initButtons();
	}
	if (document.getElementById('js-prc-attachments-report-frontend')) {
		initFrontend();
	}
});
