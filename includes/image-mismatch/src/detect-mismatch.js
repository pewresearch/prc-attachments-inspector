/**
 * A URL is "legacy" when either:
 *  - the host is assets.pewresearch.org, OR
 *  - the path contains /sites/N/ where N is numeric and not 20.
 *
 * @param {string} url
 * @return {boolean}
 */
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

	if (parsed.hostname === 'assets.pewresearch.org') {
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
 * suffixes (e.g. "-310x158") so we can match across domains.
 *
 * Examples:
 *   https://assets.pewresearch.org/.../Foo-310x158.png?w=311  → "foo.png"
 *   Foo-310x158.png                                           → "foo.png"
 *
 * @param {string} urlOrFilename
 * @return {string}
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
 * @param {string} url
 * @return {string|null}
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
 * @param {string}   url         The src URL from the legacy image block.
 * @param {Array}    attachments The array from the attachments-panel endpoint
 *                               (each item has at minimum `{ id, filename, url, title, alt, caption, attachmentLink }`).
 * @return {Array}
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
