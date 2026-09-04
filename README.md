# PRC Attachments Inspector

> Canonical docs: [docs/plugins/prc-attachments-inspector/](../../docs/plugins/prc-attachments-inspector/)

Provides two tools for inspecting and managing media attached to posts: a block editor sidebar panel for editors to view, upload, and detach files during editing, and a frontend/admin attachment report for reviewing image metadata across a post and its children.

## What it does

- Registers a **Attachments** plugin sidebar in the block editor listing all media attached to the current post, with drag-and-drop upload support
- Exposes an **Attachments Report** view on the frontend via `?attachmentsReport=1` query var — replaces post content with a searchable image grid showing title, alt text, caption, and dimensions
- Adds an **Attachments Report** custom column to the Posts admin list (requires Admin Columns Pro) with an inline button that opens the report in a modal
- Registers REST endpoints for fetching attachments (panel and report shapes) and for detaching an attachment from its parent post
- Attachment report endpoint traverses child posts/pages (up to 25) and supports mime type filtering
- The editor panel's sidebar is extensible via the `prc-platform.attachments-panel` JS filter hook, allowing other plugins to inject their own panels
- Provides an **image mismatch** editor toolbar on `core/image` blocks:
  - **Case A:** legacy `src` (PRC assets CDN or `/sites/N/` where `N ≠ 20`) with a matching filename among post-attached media → **Fix Mismatch** rewires the block to that attachment
  - **Case B:** block `id` is present but invalid, or the attachment filename does not match the `src` filename → search the media library by filename (reparent on pick) or **Import from URL** to sideload a new attachment. A missing `id` is not a mismatch.
- Frontend admin-bar alert for Case A and Case B (combined count + breakdown tooltip; links to the post editor)
- Shared PHP scanner powers the admin bar, a WP-CLI report (`wp prc attachments-inspector mismatch-report`), and a WP Ability (`prc-attachments-inspector/scan-image-mismatches`) that page through posts 50 at a time

## Key files

| File                                                     | Purpose                                                                                                                                                                              |
| -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `prc-attachments-inspector.php`                          | Plugin entry point; defines constants, registers activation/deactivation hooks                                                                                                       |
| `includes/class-plugin.php`                              | Bootstraps and wires `Attachment_Report` and `Attachments_Panel` via the loader                                                                                                      |
| `includes/class-loader.php`                              | Hook registration queue; called via `Plugin::run()`                                                                                                                                  |
| `includes/attachment-report/class-attachment-report.php` | Registers the frontend report query var, enqueues assets conditionally, injects the report container via `the_content`, registers the REST report endpoint, and wires the ACP column |
| `includes/attachment-report/class-acp-column.php`        | Admin Columns Pro column class rendering the report trigger button in the post list                                                                                                  |
| `includes/attachment-report/src/modal.jsx`               | Admin/ACP report modal with image grid and search by title, alt, caption                                                                                                             |
| `includes/attachments-panel/class-attachments-panel.php` | Registers the editor sidebar script/style and the panel's REST endpoints                                                                                                             |
| `includes/attachments-panel/src/attachments-panel.jsx`   | `PluginSidebar` component; exposes the `prc-platform.attachments-panel` JS filter hook for extensibility                                                                             |
| `includes/attachments-panel/src/drag-and-drop-zone.jsx`  | `DropZone` component within the panel for uploading files directly to the post                                                                                                       |
| `includes/image-mismatch/class-image-mismatch-scanner.php` | Shared Case A/B detection: `scan_post()`, `scan_batch()` (cursor, 50/batch), cached admin-bar summary |
| `includes/image-mismatch/class-image-mismatch.php`       | Admin-bar presentation; Case B find/import REST; editor asset enqueue                                                                                                              |
| `includes/image-mismatch/class-cli.php`                  | WP-CLI `prc attachments-inspector mismatch-report`                                                                                                                                |
| `includes/image-mismatch/class-ability.php`              | WP Ability `prc-attachments-inspector/scan-image-mismatches`                                                                                                                      |
| `includes/image-mismatch/class-ability-categories.php`   | Ability category `attachments-inspector`                                                                                                                                          |
| `includes/image-mismatch/src/fix-mismatch-toolbar.jsx` | Block toolbar **Fix Mismatch** UI (Case A immediate replace; Case B library search / import)                                                                                         |
| `tests/prc-attachments-inspector/e2e/editor-panel.spec.ts`                             | Playwright e2e tests for the editor sidebar                                                                                                                                          |
| `tests/prc-attachments-inspector/e2e/frontend-report.spec.ts`                          | Playwright e2e tests for the frontend report view                                                                                                                                    |
| `tests/prc-attachments-inspector/e2e/rest-api.spec.ts`                                 | Playwright e2e tests for all REST endpoints                                                                                                                                          |

## REST API endpoints

All routes are under the `prc-api/v3` namespace, registered directly on `rest_api_init`.

| Method | Route                                                    | Auth required | Description                                                                                                                                                                                                                                                                                                                                                                          |
| ------ | -------------------------------------------------------- | ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `GET`  | `/prc-api/v3/attachments-panel/get/{post_id}`            | `edit_posts`  | Returns attached media for a post: `id`, `title`, `type`, `filename`, `editLink`, `attachmentLink`, `url`, `alt`, `caption`                                                                                                                                                                                                                                                          |
| `POST` | `/prc-api/v3/attachments-panel/unattach/{attachment_id}` | `edit_posts`  | Sets `post_parent` to `0`, detaching the attachment from its post                                                                                                                                                                                                                                                                                                                    |
| `POST` | `/prc-api/v3/image-mismatch/find`                        | `edit_posts` (+ `edit_post` on target) | Body: `{ post_id, url?, filename? }`. Resolves via `attachment_url_to_postid` then filename search on `_wp_attached_file`. Returns `{ filename, matches[] }` (panel-shaped attachment payloads, capped). |
| `POST` | `/prc-api/v3/image-mismatch/import`                      | `edit_posts` (+ `edit_post` on target) | Body: `{ post_id, url }`. Allowlisted host sideload (`download_url` → `media_handle_sideload`) parented to the post. Returns `{ success, attachment }`. |
| `GET`  | `/prc-api/v3/attachments-report/get/{post_id}`           | Public        | Returns `{ postTitle, attachments[] }`. Each attachment includes `id`, `title`, `caption`, `description`, `alt`, `mimeType`, `url`, `thumbnailUrl`, `squareUrl`, `width`, `height`, `owner`. Supports `mime_type` (`image`, `all`, etc.) and `include_children` (boolean, default `true`) query params. Automatically resolves child post ID to parent if a child post ID is passed. |

## Filters / hooks

### PHP

| Hook                          | Type   | Description                                                                                                                       |
| ----------------------------- | ------ | --------------------------------------------------------------------------------------------------------------------------------- |
| `query_vars`                  | Filter | Registers the `attachmentsReport` query var used to trigger the frontend report                                                   |
| `the_content`                 | Filter | Replaces post content with the report mount div (`#js-prc-attachments-report-frontend`) when `attachmentsReport` query var is set |
| `wp_enqueue_scripts`          | Action | Enqueues attachment report script/style on the frontend when `attachmentsReport` is active                                        |
| `admin_enqueue_scripts`       | Action | Registers attachment report script/style for admin use (ACP column)                                                               |
| `enqueue_block_editor_assets` | Action | Enqueues the attachments panel sidebar script/style in the block editor (skipped on site editor screen)                           |
| `rest_api_init`               | Action | Registers the panel and report REST routes directly via `register_rest_route()`                                                   |
| `ac/ready`                    | Action | Registers the `PRC_ATTACHMENTS_COLUMN` column type with Admin Columns Pro                                                         |

### JavaScript

| Hook                             | Type          | Description                                                                                                                                                    |
| -------------------------------- | ------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `prc-platform.attachments-panel` | `withFilters` | Allows other plugins to wrap or extend the `AttachmentsPanelComponent` in the editor sidebar. Pass a higher-order component via `addFilter` on this hook name. |

## Usage

### Image mismatch report (WP-CLI)

Scan published posts in batches of 50 (resumable via `--start-id`):

```bash
wp prc attachments-inspector mismatch-report
wp prc attachments-inspector mismatch-report --start-id=39700 --limit=100 --format=csv
wp prc attachments-inspector mismatch-report --post_type=post --batch-size=50 --format=json
```

Options: `--post_type` (default `post`), `--batch-size` (max 50), `--start-id`, `--limit` (0 = all), `--format` (`table|csv|json|count`). The command logs `last_id` so long runs can resume.

### Image mismatch scan (WP Ability)

Ability id: `prc-attachments-inspector/scan-image-mismatches`

Input: `{ cursor?: int, batch_size?: int (max 50), post_type?: string }`  
Output: `{ results: [...affected posts], last_id, scanned, done }`

Call repeatedly with `cursor = last_id` until `done` is true. Requires `edit_posts`. Exposed via REST (`show_in_rest`) and MCP.

### Frontend attachment report

Append `?attachmentsReport=1` to any post URL. The standard post content is replaced with the report UI. Assets are only enqueued when this query var is present.

```
https://example.org/my-post/?attachmentsReport=1
```

### Extending the editor panel

Other plugins can inject content into the Attachments sidebar using the WordPress `withFilters` hook:

```js
import { addFilter } from '@wordpress/hooks';

addFilter(
	'prc-platform.attachments-panel',
	'my-plugin/attachments-panel-extension',
	(WrappedComponent) => (props) => (
		<>
			<WrappedComponent {...props} />
			<MyCustomSection />
		</>
	)
);
```

## Dependencies

- `prc-platform-core` — required plugin; provides shared platform hooks and utilities
- Admin Columns Pro (`ac/ready` action) — optional; the ACP column only registers when the plugin is active

## Development

Build targets live inside each `includes/` subdirectory with their own `package.json` and `webpack.config.js`. Build from the repo root:

```bash
npm run build -w @prc/attachments-inspector
```

Run Playwright tests (from monorepo root; VIP dev-env and Playwright are centralized):

```bash
npm run vip:start
npm test -- tests/prc-attachments-inspector/e2e/
```
