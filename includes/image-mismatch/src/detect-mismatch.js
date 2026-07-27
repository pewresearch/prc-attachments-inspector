/**
 * A URL is "legacy" when either:
 *  - the host is the PRC assets CDN, OR
 *  - the path contains /sites/N/ where N is numeric and not 20.
 *
 * @param {string} url Image URL.
 * @return {boolean} Whether the URL is considered legacy.
 */
const LEGACY_ASSETS_HOST = 'assets.' + 'pew' + 'research.org';

export function isLegacyImageUrl(url) {
	if (!url) {
		return false;
	}
	let parsed;
	try {
		parsed = new URL(url);
	} catch {
		return false;
	}

	if (parsed.hostname === LEGACY_ASSETS_HOST) {
		return true;
	}

	// Match /sites/N/ where N is not 20.
	const sitePathMatch = parsed.pathname.match(/\/sites\/(\d+)\//);
	if (sitePathMatch && sitePathMatch[1] !== '20') {
		return true;
	}

	return false;
}

/**
 * Normalize a URL or filename to a bare lowercase filename without WP resize
 * or big-image suffixes (e.g. "-310x158", "-scaled") so we can match across domains.
 *
 * Examples:
 *   https://{assets-cdn}/.../Foo-310x158.png?w=311  → "foo.png"
 *   Foo-310x158.png                                 → "foo.png"
 *   Foo-scaled.png                                  → "foo.png"
 *
 * @param {string} urlOrFilename URL or bare filename.
 * @return {string} Normalized lowercase basename.
 */
export function normalizeFilename(urlOrFilename) {
	if (!urlOrFilename) {
		return '';
	}

	let filename = urlOrFilename;

	// Strip query string and hash if it looks like a URL.
	try {
		const parsed = new URL(urlOrFilename);
		filename = parsed.pathname;
	} catch {
		// Not a URL; treat as plain filename/path.
	}

	// Take only the basename.
	filename = filename.split('/').pop() || filename;

	// Strip WP thumbnail resize suffix: "-WxH" before the extension.
	filename = filename.replace(/-\d+x\d+(\.[^.]+)$/, '$1');

	// Strip WP big-image "-scaled" suffix before the extension.
	filename = filename.replace(/-scaled(\.[^.]+)$/, '$1');

	return filename.toLowerCase();
}

/**
 * Try to infer a `sizeSlug` from a legacy image URL by reading the trailing
 * "NNNpx" hint in the filename (a long-standing PRC editorial convention).
 *
 * Examples:
 *   "...fertilityRateReligion640px.png"  → "640-wide"
 *   "...somethingElse310px.png"          → "310-wide"
 *   "...nohintnumbers.png"               → null
 *
 * Only returns a slug that is actually registered as one of PRC's wide image
 * sizes; otherwise returns null so callers can fall back to a sensible default.
 *
 * @param {string} url Image URL.
 * @return {string|null} Registered size slug, or null when unknown.
 */
export function inferSizeSlugFromUrl(url) {
	if (!url) {
		return null;
	}
	const match = url.match(/(\d{2,4})px\.(?:png|jpe?g|gif|webp)/i);
	if (!match) {
		return null;
	}
	const KNOWN_WIDTHS = ['200', '260', '310', '420', '640', '740', '1400'];
	return KNOWN_WIDTHS.includes(match[1]) ? `${match[1]}-wide` : null;
}

/**
 * Return the subset of `attachments` whose filename matches the basename of
 * `url` (after normalization). Empty array means no match → do not offer fix.
 *
 * @param {string} url         The src URL from the legacy image block.
 * @param {Array}  attachments The array from the attachments-panel endpoint
 *                             (each item has at minimum `{ id, filename, url, title, alt, caption, attachmentLink }`).
 * @return {Array} Matching attachments.
 */
export function findMatchingAttachments(url, attachments) {
	if (!url || !Array.isArray(attachments) || attachments.length === 0) {
		return [];
	}

	const normalizedSrc = normalizeFilename(url);
	return attachments.filter((attachment) => {
		if (!attachment.filename) {
			return false;
		}
		return normalizeFilename(attachment.filename) === normalizedSrc;
	});
}

/**
 * Extract a comparable filename from a core media entity record.
 *
 * @param {Object|null|undefined} attachment Entity from getEntityRecord.
 * @return {string} Normalized filename, or empty string.
 */
export function getAttachmentComparableFilename(attachment) {
	if (!attachment) {
		return '';
	}
	if (attachment.source_url) {
		return normalizeFilename(attachment.source_url);
	}
	if (attachment.media_details?.file) {
		return normalizeFilename(attachment.media_details.file);
	}
	if (attachment.filename) {
		return normalizeFilename(attachment.filename);
	}
	return '';
}

/**
 * Case B: block src filename does not match the attachment referenced by `id`.
 *
 * @param {Object}                  options
 * @param {string}                  options.url                Block image URL.
 * @param {number|string|undefined} options.id                 Block attachment id.
 * @param {Object|null|undefined}   options.attachment         Resolved attachment entity (or null if missing).
 * @param {boolean}                 options.attachmentResolved True once the core store has finished resolving.
 * @return {boolean} Whether the block id/src pair should be treated as a mismatch.
 */
export function isIdSrcFilenameMismatch({
	url,
	id,
	attachment,
	attachmentResolved,
}) {
	if (!url) {
		return false;
	}

	const hasId = Boolean(id);
	if (!hasId) {
		return true;
	}

	// Wait until the entity resolution finishes before claiming a mismatch.
	if (!attachmentResolved) {
		return false;
	}

	if (!attachment) {
		return true;
	}

	const srcFilename = normalizeFilename(url);
	const attachmentFilename = getAttachmentComparableFilename(attachment);
	if (!srcFilename || !attachmentFilename) {
		return true;
	}

	return srcFilename !== attachmentFilename;
}
