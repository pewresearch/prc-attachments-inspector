/**
 * WordPress Dependencies
 */
import { dispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

const TYPE_ELEMENTS = [
	{ value: 'image', label: __('Image', 'prc-attachments-inspector') },
	{ value: 'chart', label: __('Chart', 'prc-attachments-inspector') },
];

export const DEFAULT_VIEW = {
	type: 'grid',
	page: 1,
	perPage: 50,
	search: '',
	filters: [],
	sort: {
		field: 'title',
		direction: 'asc',
	},
	titleField: 'title',
	mediaField: 'media',
	fields: ['title', 'type', 'alt', 'mimeType'],
	layout: {
		mediaField: 'media',
		primaryField: 'title',
		previewSize: 230,
	},
};

export const DEFAULT_LAYOUTS = {
	grid: {
		layout: {
			mediaField: 'media',
			primaryField: 'title',
			previewSize: 230,
		},
	},
	table: {
		layout: {
			primaryField: 'title',
		},
	},
};

export function getReportFields() {
	return [
		{
			id: 'media',
			label: __('Preview', 'prc-attachments-inspector'),
			type: 'media',
			enableSorting: false,
			enableGlobalSearch: false,
			enableHiding: false,
			getValue: ({ item }) =>
				item.squareUrl || item.thumbnailUrl || item.url,
			render: ({ item }) => {
				const src = item.squareUrl || item.thumbnailUrl || item.url;
				if (!src) {
					return null;
				}

				return (
					<img
						src={src}
						alt={item.alt || item.title || ''}
						style={{
							display: 'block',
							maxWidth: '100%',
							height: 'auto',
						}}
					/>
				);
			},
		},
		{
			id: 'title',
			label: __('Title', 'prc-attachments-inspector'),
			type: 'text',
			enableSorting: true,
			enableGlobalSearch: true,
			getValue: ({ item }) => item.title,
		},
		{
			id: 'type',
			label: __('Type', 'prc-attachments-inspector'),
			type: 'text',
			elements: TYPE_ELEMENTS,
			filterBy: {
				operators: ['isAny'],
			},
			enableSorting: true,
			enableGlobalSearch: false,
			getValue: ({ item }) => item.type,
			render: ({ item }) =>
				'chart' === item.type
					? __('Chart', 'prc-attachments-inspector')
					: __('Image', 'prc-attachments-inspector'),
		},
		{
			id: 'alt',
			label: __('Alt Text', 'prc-attachments-inspector'),
			type: 'text',
			enableSorting: false,
			enableGlobalSearch: true,
			getValue: ({ item }) => item.alt,
		},
		{
			id: 'caption',
			label: __('Caption', 'prc-attachments-inspector'),
			type: 'text',
			enableSorting: false,
			enableGlobalSearch: true,
			getValue: ({ item }) => item.caption,
		},
		{
			id: 'mimeType',
			label: __('MIME Type', 'prc-attachments-inspector'),
			type: 'text',
			enableSorting: true,
			enableGlobalSearch: false,
			getValue: ({ item }) => item.mimeType,
		},
	];
}

function copyText(value, successMessage) {
	if (!value || !window.navigator?.clipboard) {
		return;
	}

	window.navigator.clipboard
		.writeText(value)
		.then(() => {
			dispatch(noticesStore).createSuccessNotice(successMessage, {
				type: 'snackbar',
			});
		})
		.catch(() => {
			dispatch(noticesStore).createErrorNotice(
				__('Could not copy to clipboard.', 'prc-attachments-inspector'),
				{ type: 'snackbar' }
			);
		});
}

export function getReportActions() {
	return [
		{
			id: 'open-item',
			label: __('Open', 'prc-attachments-inspector'),
			isPrimary: true,
			callback: (items) => {
				items.forEach((item) => {
					const targetUrl =
						'chart' === item.type
							? item.chartUrl || item.editUrl
							: item.url;
					if (targetUrl) {
						window.open(targetUrl, '_blank', 'noopener,noreferrer');
					}
				});
			},
		},
		{
			id: 'copy-url',
			label: __('Copy URL', 'prc-attachments-inspector'),
			callback: (items) => {
				const item = items[0];
				const value =
					'chart' === item.type
						? item.chartUrl || item.url
						: item.url;
				void copyText(
					value,
					__('URL copied to clipboard.', 'prc-attachments-inspector')
				);
			},
		},
		{
			id: 'copy-title',
			label: __('Copy Title', 'prc-attachments-inspector'),
			isEligible: (item) => !!item?.title,
			callback: (items) => {
				void copyText(
					items[0]?.title,
					__(
						'Title copied to clipboard.',
						'prc-attachments-inspector'
					)
				);
			},
		},
		{
			id: 'copy-alt',
			label: __('Copy Alt Text', 'prc-attachments-inspector'),
			isEligible: (item) => !!item?.alt,
			callback: (items) => {
				void copyText(
					items[0]?.alt,
					__(
						'Alt text copied to clipboard.',
						'prc-attachments-inspector'
					)
				);
			},
		},
	];
}

export function getItemOpenUrl(item) {
	if ('chart' === item.type) {
		return item.chartUrl || item.editUrl || item.url;
	}

	return item.url;
}
