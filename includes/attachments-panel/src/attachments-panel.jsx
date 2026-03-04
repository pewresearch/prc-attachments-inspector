/* eslint-disable max-len */
// A panel that uses filters to allow adding additional panels.
// https://github.com/WordPress/gutenberg/tree/d5915916abc45e6682f4bdb70888aa41e98aa395/packages/components/src/higher-order/with-filters

/**
 * WordPress Dependencies
 */
import { __ } from '@wordpress/i18n';
import { useCommand } from '@wordpress/commands';
import { useDispatch } from '@wordpress/data';
import { store as editPostStore, PluginSidebar } from '@wordpress/edit-post';
import { media } from '@wordpress/icons';
import { withFilters } from '@wordpress/components';

/**
 * Internal Dependencies
 */
import './style.scss';
import { ProvideAttachments } from './context';
import AttachmentsList from './attachments-list';

const HOOK_NAME = 'prc-platform.attachments-panel';
const PLUGIN_NAME = 'prc-platform-attachment-panel';
const SIDEBAR_NAME = 'prc-platform-attachments-panel';
// With this hook other plugins can add their own panels to the attachments panel. For example, Chart Builder could potentially show it's chart exports. The entire idea of this plugin is to provide a central universe of all media assets for a post/page.

function AttachmentsPanelComponent() {
	return (
		<ProvideAttachments>
			<AttachmentsList />
		</ProvideAttachments>
	);
}

export default function AttachmentsPanel() {
	const { openGeneralSidebar } = useDispatch(editPostStore);

	useCommand({
		name: 'prc/show-attachments',
		label: __('Show Attachment Inspector', 'prc-attachments-inspector'),
		icon: media,
		category: 'view',
		keywords: ['attachments', 'media', 'inspector', 'upload'],
		callback: ({ close }) => {
			openGeneralSidebar(`${PLUGIN_NAME}/${SIDEBAR_NAME}`);
			close();
		},
	});

	const AttachmentsPanelHook = withFilters(HOOK_NAME)(
		AttachmentsPanelComponent
	);
	return (
		<PluginSidebar
			name={SIDEBAR_NAME}
			title="Attachments"
			icon="admin-media"
		>
			<AttachmentsPanelHook />
		</PluginSidebar>
	);
}
