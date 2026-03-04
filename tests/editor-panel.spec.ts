/**
 * Editor Panel tests for prc-attachments-inspector.
 *
 * Verifies:
 * - The "Attachments" PluginSidebar is registered and accessible.
 * - The panel contains the filter text control and drag-and-drop zone label.
 * - The "Edit Attachments" button appears when attachments exist.
 * - Images appear in the panel when attached to the post.
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

async function deleteAttachment(
	requestUtils: RequestUtils,
	attachmentId: number
): Promise<void> {
	await requestUtils.rest({
		method: 'DELETE',
		path: `/wp/v2/media/${attachmentId}`,
		params: { force: true },
	});
}

async function uploadAttachment(
	requestUtils: RequestUtils,
	postId: number,
	filename: string = 'test-image.png'
): Promise<{ id: number }> {
	const pngBase64 =
		'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==';
	const buffer = Buffer.from(pngBase64, 'base64');

	const response = await requestUtils.rest({
		method: 'POST',
		path: '/wp/v2/media',
		headers: {
			'Content-Disposition': `attachment; filename="${filename}"`,
			'Content-Type': 'image/png',
		},
		data: buffer,
	});

	await requestUtils.rest({
		method: 'POST',
		path: `/wp/v2/media/${response.id}`,
		data: { post: postId },
	});

	return { id: response.id };
}

/**
 * Open the "Attachments" plugin sidebar in the post editor.
 * @param page
 */
async function openAttachmentsSidebar(page: any): Promise<void> {
	// Look for the Attachments pinned item or the "more menu" to activate it.
	const sidebarButton = page.getByRole('button', {
		name: 'Attachments',
		exact: false,
	});

	// If already visible, click it.
	if (await sidebarButton.first().isVisible()) {
		await sidebarButton.first().click();
		return;
	}

	// Otherwise try the "Plugins" section in the more options menu.
	const moreMenuButton = page.getByRole('button', {
		name: 'Options',
		exact: false,
	});
	if (await moreMenuButton.isVisible()) {
		await moreMenuButton.click();
		const pluginMenuItem = page.getByRole('menuitemcheckbox', {
			name: 'Attachments',
			exact: false,
		});
		if (await pluginMenuItem.isVisible()) {
			await pluginMenuItem.click();
		}
	}
}

test.describe('Editor Attachments Panel', () => {
	test('the Attachments sidebar plugin is registered and visible', async ({
		admin,
		page,
	}) => {
		await admin.createNewPost();

		await openAttachmentsSidebar(page);

		// The sidebar should now display with the "Attachments" heading.
		const sidebarHeading = page.getByRole('heading', {
			name: 'Attachments',
		});
		await expect(sidebarHeading).toBeVisible();

		// The panel should contain the filter input.
		const filterInput = page.getByLabel('Filter Attachments');
		await expect(filterInput).toBeVisible();
	});

	test('the panel shows the drag-and-drop zone label', async ({
		admin,
		page,
	}) => {
		await admin.createNewPost();

		await openAttachmentsSidebar(page);

		// Check that the help text for the drag and drop zone is present.
		const dropzoneLabel = page.getByText(
			'Drag and drop files to attach them to this post',
			{ exact: false }
		);
		await expect(dropzoneLabel).toBeVisible();
	});

	test('the panel shows the Images section', async ({ admin, page }) => {
		await admin.createNewPost();

		await openAttachmentsSidebar(page);

		// The Images panel body should be present.
		const imagesPanel = page.getByRole('button', {
			name: 'Images',
			exact: false,
		});
		await expect(imagesPanel).toBeVisible();
	});

	test('the panel displays Edit Attachments button when attachments exist', async ({
		admin,
		page,
		requestUtils,
	}) => {
		const post = await requestUtils.createPost({
			title: 'Editor Panel Attachment Test',
			status: 'draft',
		});
		const attachment = await uploadAttachment(
			requestUtils,
			post.id,
			'editor-panel-test.png'
		);

		try {
			await admin.visitAdminPage(
				'post.php',
				`post=${post.id}&action=edit`
			);

			await openAttachmentsSidebar(page);

			// Wait for the attachments API call to resolve and the list to render.
			const editButton = page.getByRole('button', {
				name: 'Edit Attachments',
			});
			await expect(editButton).toBeVisible({ timeout: 15000 });
		} finally {
			await deleteAttachment(requestUtils, attachment.id);
			await deletePost(requestUtils, post.id);
		}
	});

	test('the panel lists images attached to the post', async ({
		admin,
		page,
		requestUtils,
	}) => {
		const post = await requestUtils.createPost({
			title: 'Editor Panel Image List Test',
			status: 'draft',
		});
		const attachment = await uploadAttachment(
			requestUtils,
			post.id,
			'listed-image.png'
		);

		try {
			await admin.visitAdminPage(
				'post.php',
				`post=${post.id}&action=edit`
			);

			await openAttachmentsSidebar(page);

			// Wait for images to load in the panel.
			const image = page.locator('.prc-attachments-list__image img');
			await expect(image.first()).toBeVisible({ timeout: 15000 });
		} finally {
			await deleteAttachment(requestUtils, attachment.id);
			await deletePost(requestUtils, post.id);
		}
	});

	test('the filter input narrows the displayed attachments', async ({
		admin,
		page,
		requestUtils,
	}) => {
		const post = await requestUtils.createPost({
			title: 'Editor Panel Filter Test',
			status: 'draft',
		});
		const attachment1 = await uploadAttachment(
			requestUtils,
			post.id,
			'alpha-image.png'
		);
		const attachment2 = await uploadAttachment(
			requestUtils,
			post.id,
			'beta-image.png'
		);

		try {
			await admin.visitAdminPage(
				'post.php',
				`post=${post.id}&action=edit`
			);

			await openAttachmentsSidebar(page);

			// Wait for images to appear.
			const images = page.locator('.prc-attachments-list__image');
			await expect(images.first()).toBeVisible({ timeout: 15000 });

			// Get count before filtering.
			const countBefore = await images.count();
			expect(countBefore).toBeGreaterThanOrEqual(2);

			// Type a filter term that matches only one attachment title.
			const filterInput = page.getByLabel('Filter Attachments');
			await filterInput.fill('alpha');

			// Wait for debounce (500ms) plus a small buffer.
			await page.waitForTimeout(800);

			// Count should be reduced (filtered).
			const countAfter = await images.count();
			expect(countAfter).toBeLessThan(countBefore);
		} finally {
			await deleteAttachment(requestUtils, attachment1.id);
			await deleteAttachment(requestUtils, attachment2.id);
			await deletePost(requestUtils, post.id);
		}
	});
});
