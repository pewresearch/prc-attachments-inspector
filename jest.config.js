/**
 * Jest configuration for prc-attachments-inspector unit tests.
 * Unit tests live under monorepo root tests/prc-attachments-inspector/unit/.
 */
const path = require('path');

const unitRoot = path.resolve(
	__dirname,
	'../../tests/prc-attachments-inspector/unit'
);

module.exports = {
	...require('@wordpress/scripts/config/jest-unit.config'),
	rootDir: __dirname,
	roots: [unitRoot],
	testMatch: ['**/*.test.js'],
};
