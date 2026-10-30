/**
 * ESLint config for the theme.
 *
 * `wp-scripts lint-js` only looks for a flat config in the theme directory and
 * otherwise falls back to its own defaults, so re-export the generated root config.
 */
module.exports = require( '../../../../eslint.config.js' );
