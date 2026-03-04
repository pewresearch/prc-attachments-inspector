/**
 * Frontend Attachment Report tests for prc-attachments-inspector.
 *
 * Verifies:
 * - The `?attachmentsReport=true` query var triggers the report view.
 * - The report container renders with the correct post ID and type attributes.
 * - Normal post content is replaced by the report container.
 * - The report enqueues its script and style assets on the frontend.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

async function deletePost(
	requestUtils: RequestUtils,
	postId: number
): Promise<void> {
	await requestUtils.rest({
		method: 'DELETE',
		path: `/wp/v2/posts/${postId}`,
		params: { force: true },
	});
}

test.describe('Frontend Attachment Report', () => {
	let postId: number;

	test.beforeEach(async ({ requestUtils }) => {
		const post = await requestUtils.createPost({
			title: 'Frontend Report Test Post',
			content:
				'<!-- wp:paragraph --><p>Original post content that should be replaced.</p><!-- /wp:paragraph -->',
			status: 'publish',
			date_gmt: new Date().toISOString(),
		});
		postId = post.id;
	});

	test.afterEach(async ({ requestUtils }) => {
		if (postId) {
			await deletePost(requestUtils, postId);
		}
	});

	test('normal post view does not contain the report container', async ({
		page,
	}) => {
		await page.goto(`/?p=${postId}`);

		const reportContainer = page.locator(
			'#js-prc-attachments-report-frontend'
		);
		await expect(reportContainer).not.toBeVisible();

		// The original content should be present.
		await expect(
			page.getByText('Original post content that should be replaced')
		).toBeVisible();
	});

	test('attachmentsReport=true query var renders the report container', async ({
		page,
	}) => {
		await page.goto(`/?p=${postId}&attachmentsReport=true`);

		// The container is an empty div that React renders into, so it may
		// have zero dimensions. Use toBeAttached() to confirm it's in the DOM.
		const reportContainer = page.locator(
			'#js-prc-attachments-report-frontend'
		);
		await expect(reportContainer).toBeAttached();

		// The report container should carry the correct data attributes.
		await expect(reportContainer).toHaveAttribute(
			'data-postid',
			String(postId)
		);
		await expect(reportContainer).toHaveAttribute('data-posttype', 'post');
	});

	test('attachmentsReport=true replaces normal post content', async ({
		page,
	}) => {
		await page.goto(`/?p=${postId}&attachmentsReport=true`);

		// The original paragraph content should not be visible.
		await expect(
			page.getByText('Original post content that should be replaced')
		).not.toBeVisible();

		// Instead the report container should be present in the DOM.
		const reportContainer = page.locator(
			'#js-prc-attachments-report-frontend'
		);
		await expect(reportContainer).toBeAttached();
	});

	test('attachmentsReport view enqueues the report script', async ({
		page,
	}) => {
		await page.goto(`/?p=${postId}&attachmentsReport=true`);

		// Check that the attachment report script handle is enqueued.
		const script = page.locator(
			'script[id="prc-platform-attachment-report-js"]'
		);
		await expect(script).toBeAttached();
	});

	test('attachmentsReport view enqueues the report stylesheet', async ({
		page,
	}) => {
		await page.goto(`/?p=${postId}&attachmentsReport=true`);

		// Check that the attachment report CSS handle is enqueued.
		const style = page.locator(
			'link[id="prc-platform-attachment-report-css"]'
		);
		await expect(style).toBeAttached();
	});
});
