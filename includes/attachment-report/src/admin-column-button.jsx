/**
 * External Dependencies
 */
import styled from '@emotion/styled';
import classNames from 'classnames';

/**
 * WordPress Dependencies
 */
import { Fragment, useMemo, useState } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal Dependencies
 */
import { useAttachments } from './context';
import AttachmentsModal from './modal';

const Button = styled.button`
	cursor: pointer !important;
	width: 100%;
	&.disabled {
		opacity: 0.5;
	}
`;

export default function AdminColumnButton({ initialized, handleHover }) {
	const [active, setActive] = useState(false);
	const toggleActive = () => setActive(!active);
	const { attachments, loading } = useAttachments();

	const disabledButton = useMemo(() => {
		return initialized && (loading || attachments.length === 0);
	}, [initialized, loading, attachments]);

	const buttonText = useMemo(() => {
		if (loading) {
			return (
				<Fragment>
					{__('Loading…', 'prc-attachments-inspector')} <Spinner />
				</Fragment>
			);
		}
		if (initialized && attachments.length === 0) {
			return __('No Attachments or Charts', 'prc-attachments-inspector');
		}
		return __('View Attachments & Charts', 'prc-attachments-inspector');
	}, [initialized, loading, attachments]);

	return (
		<Fragment>
			<Button
				className={classNames('button button-small button-secondary', {
					disabled: disabledButton,
				})}
				alt={__(
					"View this post's attachments and charts report",
					'prc-attachments-inspector'
				)}
				type="button"
				onMouseEnter={handleHover}
				onClick={() => {
					toggleActive();
				}}
			>
				{buttonText}
			</Button>
			{active && <AttachmentsModal onClose={() => setActive(false)} />}
		</Fragment>
	);
}
