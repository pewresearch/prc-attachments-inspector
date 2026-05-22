/**
 * WordPress Dependencies
 */
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { store as editorStore } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';

/**
 * Module-level cache: keyed by postId, value is { attachments: [], status: 'loading'|'ready'|'error' }.
 * This avoids duplicate network requests when multiple image blocks are on screen simultaneously,
 * and avoids refetching when the sidebar is also open.
 */
const cache = {};
const listeners = {};

function notifyListeners(postId) {
	if (listeners[postId]) {
		listeners[postId].forEach((fn) => fn(cache[postId]));
	}
}

function subscribe(postId, fn) {
	if (!listeners[postId]) {
		listeners[postId] = new Set();
	}
	listeners[postId].add(fn);
	return () => listeners[postId].delete(fn);
}

function fetchAttachments(postId) {
	if (!postId) {
		return;
	}
	if (cache[postId] && cache[postId].status !== 'error') {
		return;
	}

	cache[postId] = { attachments: [], status: 'loading' };
	notifyListeners(postId);

	apiFetch({ path: `/prc-api/v3/attachments-panel/get/${postId}` })
		.then((data) => {
			cache[postId] = {
				attachments: Array.isArray(data) ? data : [],
				status: 'ready',
			};
			notifyListeners(postId);
		})
		.catch(() => {
			cache[postId] = { attachments: [], status: 'error' };
			notifyListeners(postId);
		});
}

/**
 * Hook — returns { attachments, status } for the current post.
 * Fetches once and caches at module level so all HOC instances share one request.
 *
 * @return {{ attachments: Array, status: 'loading'|'ready'|'error' }}
 */
export default function usePostAttachments() {
	const postId = useSelect(
		(select) => select(editorStore).getCurrentPostId(),
		[]
	);

	const [state, setState] = useState(
		cache[postId] || { attachments: [], status: 'loading' }
	);

	useEffect(() => {
		if (!postId) {
			return;
		}
		// Seed state with whatever is already cached.
		if (cache[postId]) {
			setState(cache[postId]);
		}
		// Trigger network request if not already in flight.
		fetchAttachments(postId);
		// Subscribe to future updates (covers the in-flight → ready transition).
		return subscribe(postId, setState);
	}, [postId]);

	return state;
}
