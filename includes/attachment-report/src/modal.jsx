/**
 * External Dependencies
 */
import styled from '@emotion/styled';

/**
 * WordPress Dependencies
 */
import {
	useCallback,
	useMemo,
	useState,
	createPortal,
} from '@wordpress/element';
import { Modal as WPComModal, Spinner } from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { __, sprintf } from '@wordpress/i18n';
import { SnackbarNotices } from '@wordpress/notices';

/**
 * Internal Dependencies
 */
import { useAttachments } from './context';
import {
	DEFAULT_LAYOUTS,
	DEFAULT_VIEW,
	getItemOpenUrl,
	getReportActions,
	getReportFields,
} from './fields';

const Modal = styled(WPComModal)`
	* {
		font-family: 'Open Sans', sans-serif;
	}
`;

const ModalInner = styled.div`
	width: 80vw;
	min-height: 70vh;
	max-height: 80vh;
	overflow: auto;
`;

export default function AttachmentsModal({ onClose }) {
	const { attachments, postTitle, loading } = useAttachments();
	const [view, setView] = useState(DEFAULT_VIEW);

	const fields = useMemo(() => getReportFields(), []);
	const actions = useMemo(() => getReportActions(), []);

	const { data: processedData, paginationInfo } = useMemo(
		() =>
			filterSortAndPaginate(attachments, view, fields, {
				defaultSort: DEFAULT_VIEW.sort,
			}),
		[attachments, view, fields]
	);

	const handleChangeView = useCallback((newView) => {
		setView(newView);
	}, []);

	const modalTitle = useMemo(() => {
		if (postTitle) {
			return sprintf(
				/* translators: %s: post title */
				__(
					'Attachments & Charts Report: "%s"',
					'prc-attachments-inspector'
				),
				postTitle
			);
		}

		return __('Attachments & Charts Report', 'prc-attachments-inspector');
	}, [postTitle]);

	return (
		<>
			<Modal
				title={modalTitle}
				onRequestClose={onClose}
				className="prc-attachments-report-modal"
			>
				<ModalInner>
					{loading && (
						<p>
							{__('Loading…', 'prc-attachments-inspector')}{' '}
							<Spinner />
						</p>
					)}
					{!loading && (
						<DataViews
							data={processedData}
							fields={fields}
							view={view}
							onChangeView={handleChangeView}
							defaultLayouts={DEFAULT_LAYOUTS}
							actions={actions}
							paginationInfo={paginationInfo}
							isLoading={loading}
							search
							searchLabel={__(
								'Search attachments and charts…',
								'prc-attachments-inspector'
							)}
							getItemId={(item) => `${item.type}-${item.id}`}
							isItemClickable={() => true}
							onClickItem={(item) => {
								const targetUrl = getItemOpenUrl(item);
								if (targetUrl) {
									window.open(
										targetUrl,
										'_blank',
										'noopener,noreferrer'
									);
								}
							}}
						/>
					)}
					{!loading && processedData.length === 0 && (
						<p>
							{__(
								'No attachments or charts found.',
								'prc-attachments-inspector'
							)}
						</p>
					)}
				</ModalInner>
			</Modal>
			{createPortal(
				<SnackbarNotices className="prc-attachments-report-modal__snackbar" />,
				document.body
			)}
		</>
	);
}
