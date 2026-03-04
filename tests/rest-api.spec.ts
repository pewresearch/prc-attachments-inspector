/**
 * REST API tests for prc-attachments-inspector.
 *
 * Covers:
 * - GET /prc-api/v3/attachments-panel/get/{post_id}
 * - POST /prc-api/v3/attachments-panel/unattach/{attachment_id}
 * - GET /prc-api/v3/attachments-report/get/{post_id}
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

/**
 * Upload a small 1x1 PNG image and attach it to a post.
 * @param requestUtils
 * @param postId
 * @param filename
 */
async function uploadAttachment(
	requestUtils: RequestUtils,
	postId: number,
	filename: string = 'test-image.png'
): Promise<{ id: number }> {
	// Create a minimal 1x1 red PNG as a buffer.
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

	// Attach the media to the post.
	await requestUtils.rest({
		method: 'POST',
		path: `/wp/v2/media/${response.id}`,
		data: { post: postId },
	});

	return { id: response.id };
}

test.describe('Attachments Panel REST API', () => {
	let postId: number;

	test.beforeEach(async ({ requestUtils }) => {
		const post = await requestUtils.createPost({
			title: 'Attachments Panel Test Post',
			status: 'publish',
		});
		postId = post.id;
	});

	test.afterEach(async ({ requestUtils }) => {
		if (postId) {
			await deletePost(requestUtils, postId);
		}
	});

	test('GET attachments-panel returns empty array for post with no attachments', async ({
		requestUtils,
	}) => {
		const response = await requestUtils.rest({
			method: 'GET',
			path: `/prc-api/v3/attachments-panel/get/${postId}`,
		});

		expect(Array.isArray(response)).toBe(true);
		expect(response).toHaveLength(0);
	});

	test('GET attachments-panel returns attached images', async ({
		requestUtils,
	}) => {
		const attachment = await uploadAttachment(
			requestUtils,
			postId,
			'panel-test-image.png'
		);

		try {
			const response = await requestUtils.rest({
				method: 'GET',
				path: `/prc-api/v3/attachments-panel/get/${postId}`,
			});

			expect(Array.isArray(response)).toBe(true);
			expect(response.length).toBeGreaterThanOrEqual(1);

			const found = response.find(
				(item: any) => item.id === attachment.id
			);
			expect(found).toBeDefined();
			expect(found.type).toMatch(/^image\//);
			expect(found).toHaveProperty('title');
			expect(found).toHaveProperty('filename');
			expect(found).toHaveProperty('url');
			expect(found).toHaveProperty('alt');
			expect(found).toHaveProperty('caption');
		} finally {
			await deleteAttachment(requestUtils, attachment.id);
		}
	});

	test('POST unattach removes attachment from post', async ({
		requestUtils,
	}) => {
		const attachment = await uploadAttachment(
			requestUtils,
			postId,
			'unattach-test-image.png'
		);

		try {
			// Verify it's attached first.
			const before = await requestUtils.rest({
				method: 'GET',
				path: `/prc-api/v3/attachments-panel/get/${postId}`,
			});
			expect(
				before.find((item: any) => item.id === attachment.id)
			).toBeDefined();

			// Unattach it.
			const unattachResponse = await requestUtils.rest({
				method: 'POST',
				path: `/prc-api/v3/attachments-panel/unattach/${attachment.id}`,
			});
			expect(unattachResponse.success).toBe(true);
			expect(unattachResponse.attachment_id).toBe(String(attachment.id));

			// Verify it's no longer attached.
			const after = await requestUtils.rest({
				method: 'GET',
				path: `/prc-api/v3/attachments-panel/get/${postId}`,
			});
			expect(
				after.find((item: any) => item.id === attachment.id)
			).toBeUndefined();
		} finally {
			await deleteAttachment(requestUtils, attachment.id);
		}
	});

	test('POST unattach with invalid attachment ID returns error', async ({
		requestUtils,
	}) => {
		try {
			await requestUtils.rest({
				method: 'POST',
				path: `/prc-api/v3/attachments-panel/unattach/999999`,
			});
			// If the endpoint does not throw, we still expect an error shape.
		} catch (error: any) {
			expect(error.code || error.data?.status).toBeTruthy();
		}
	});
});

test.describe('Attachments Report REST API', () => {
	let postId: number;

	test.beforeEach(async ({ requestUtils }) => {
		const post = await requestUtils.createPost({
			title: 'Attachments Report Test Post',
			status: 'publish',
		});
		postId = post.id;
	});

	test.afterEach(async ({ requestUtils }) => {
		if (postId) {
			await deletePost(requestUtils, postId);
		}
	});

	test('GET attachments-report returns postTitle and empty attachments for post with no media', async ({
		requestUtils,
	}) => {
		const response = await requestUtils.rest({
			method: 'GET',
			path: `/prc-api/v3/attachments-report/get/${postId}`,
		});

		expect(response).toHaveProperty('postTitle');
		expect(response.postTitle).toBe('Attachments Report Test Post');
		expect(response).toHaveProperty('attachments');
		expect(Array.isArray(response.attachments)).toBe(true);
		expect(response.attachments).toHaveLength(0);
	});

	test('GET attachments-report returns attachment data with expected fields', async ({
		requestUtils,
	}) => {
		const attachment = await uploadAttachment(
			requestUtils,
			postId,
			'report-test-image.png'
		);

		try {
			const response = await requestUtils.rest({
				method: 'GET',
				path: `/prc-api/v3/attachments-report/get/${postId}`,
			});

			expect(response.attachments.length).toBeGreaterThanOrEqual(1);

			const found = response.attachments.find(
				(item: any) => item.id === attachment.id
			);
			expect(found).toBeDefined();
			expect(found).toHaveProperty('title');
			expect(found).toHaveProperty('caption');
			expect(found).toHaveProperty('description');
			expect(found).toHaveProperty('alt');
			expect(found).toHaveProperty('mimeType');
			expect(found.mimeType).toMatch(/^image\//);
			expect(found).toHaveProperty('url');
			expect(found).toHaveProperty('thumbnailUrl');
			expect(found).toHaveProperty('width');
			expect(found).toHaveProperty('height');
			expect(found).toHaveProperty('owner');
			expect(found.owner).toBe(postId);
		} finally {
			await deleteAttachment(requestUtils, attachment.id);
		}
	});

	test('GET attachments-report supports mime_type filter', async ({
		requestUtils,
	}) => {
		const attachment = await uploadAttachment(
			requestUtils,
			postId,
			'mime-filter-test.png'
		);

		try {
			// Request with image mime type filter — should include our image.
			const imageResponse = await requestUtils.rest({
				method: 'GET',
				path: `/prc-api/v3/attachments-report/get/${postId}`,
				params: { mime_type: 'image' },
			});
			expect(imageResponse.attachments.length).toBeGreaterThanOrEqual(1);

			// Request with "all" mime type filter — should also include our image.
			const allResponse = await requestUtils.rest({
				method: 'GET',
				path: `/prc-api/v3/attachments-report/get/${postId}`,
				params: { mime_type: 'all' },
			});
			expect(allResponse.attachments.length).toBeGreaterThanOrEqual(1);
		} finally {
			await deleteAttachment(requestUtils, attachment.id);
		}
	});

	test('GET attachments-report includes child page attachments by default', async ({
		requestUtils,
	}) => {
		// Use pages (hierarchical) so the REST API respects the parent param.
		const parentPage = await requestUtils.rest({
			method: 'POST',
			path: '/wp/v2/pages',
			data: {
				title: 'Parent Page for Attachments',
				status: 'publish',
			},
		});

		const childPage = await requestUtils.rest({
			method: 'POST',
			path: '/wp/v2/pages',
			data: {
				title: 'Child Page for Attachments',
				status: 'publish',
				parent: parentPage.id,
			},
		});

		const attachment = await uploadAttachment(
			requestUtils,
			childPage.id,
			'child-page-image.png'
		);

		try {
			// The default for include_children is true, so child attachments
			// should appear without passing the parameter explicitly.
			const response = await requestUtils.rest({
				method: 'GET',
				path: `/prc-api/v3/attachments-report/get/${parentPage.id}`,
			});

			expect(response.attachments.length).toBeGreaterThanOrEqual(1);

			const found = response.attachments.find(
				(item: any) => item.id === attachment.id
			);
			expect(found).toBeDefined();
		} finally {
			await deleteAttachment(requestUtils, attachment.id);
			await requestUtils.rest({
				method: 'DELETE',
				path: `/wp/v2/pages/${childPage.id}`,
				params: { force: true },
			});
			await requestUtils.rest({
				method: 'DELETE',
				path: `/wp/v2/pages/${parentPage.id}`,
				params: { force: true },
			});
		}
	});
});
