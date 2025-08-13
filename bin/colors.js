/**
 * Wraps a string in ANSI escape codes to display green text in the terminal.
 * @param {string} str - The string to color.
 * @return {string} The colored string.
 */
export const green = str => `\x1b[32m${str}\x1b[0m`;

/**
 * Wraps a string in ANSI escape codes to display red text in the terminal.
 * @param {string} str - The string to color.
 * @return {string} The colored string.
 */
export const red = str => `\x1b[31m${str}\x1b[0m`;

/**
 * Wraps a string in ANSI escape codes to display yellow text in the terminal.
 * @param {string} str - The string to color.
 * @return {string} The colored string.
 */
export const yellow = str => `\x1b[33m${str}\x1b[0m`;

/**
 * Wraps a string in ANSI escape codes to display bold text in the terminal.
 * @param {string} str - The string to format.
 * @return {string} The formatted string.
 */
export const bold = str => `\x1b[1m${str}\x1b[0m`;
