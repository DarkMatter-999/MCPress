import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'fs';
import inquirer from 'inquirer';
import ora from 'ora';
import { dirname, join, resolve } from 'path';
import { fileURLToPath } from 'url';
import { bold, green, red } from './colors.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const TEMPLATE_PATH = resolve(__dirname, './templates/create-tool.template');

/** @typedef { {group: string, name: string}} CreateToolOptions*/

/**
 *
 *
 * @param {CreateToolOptions} options
 */
/**
 * Creates a new tool by generating a PHP class file from a template.
 * Prompts for tool name and group if not provided in options.
 *
 * @param {Object} options         - Options for tool creation.
 * @param {string} [options.name]  - The name of the tool class.
 * @param {string} [options.group] - The group/category for the tool.
 * @return {Promise<void>}
 */
export default async function createTool(options = {}) {
	let { name, group } = options;
	const spinner = ora('Preparing to create tool').start();
	if (!name && !group) {
		spinner.stop();
		const results = await createToolInquire(options);
		name = results.name.replaceAll(' ', '_');
		group = results.group;
	}

	spinner.start('Updating the Template');

	const className = `${name}_Tool`;
	const constName = name.toLowerCase().replace(/[^a-z0-9]/g, '_');
	const fileName = `class-${constName}-tool.php`;
	const dirPath = `./include/classes/tools/${group}`;
	const filePath = join(dirPath, fileName);

	try {
		if (!existsSync(TEMPLATE_PATH)) {
			spinner.fail(red(`Template file not found at: ${TEMPLATE_PATH}`));
			process.exit(1);
		}

		const template = readFileSync(TEMPLATE_PATH, 'utf-8');

		const replacements = {
			'{{CLASS_NAME}}': className,
			'{{CONST_NAME}}': constName,
			'{{GROUP}}': group,
			'{{TOOL_TITLE}}': name.replace(/_/g, ' ')
		};

		const content = Object.entries(replacements).reduce(
			(text, [key, value]) => text.replaceAll(key, value),
			template
		);

		mkdirSync(dirPath, { recursive: true });

		if (existsSync(filePath)) {
			spinner.fail(red(`Tool already exists: ${filePath}`));
			process.exit(1);
		}

		writeFileSync(filePath, content);
		spinner.succeed(green(`Tool created: ${bold(filePath)}`));
	} catch (err) {
		spinner.fail(red(`Error: ${err.message}`));
	}
}

/**
 * Prompts the user for tool class name and group if not provided.
 *
 * @param {Object} params
 * @param {string} [params.name]  - The name of the tool class.
 * @param {string} [params.group] - The group/category for the tool.
 * @return {Promise<CreateToolOptions>} The answers object containing name and group.
 */
async function createToolInquire({ name, group }) {
	const answers = await inquirer.prompt([
		{
			type: 'input',
			name: 'name',
			message: 'Tool class name (e.g., Site_Info):',
			when: () => !name
		},
		{
			type: 'input',
			name: 'group',
			message: 'Tool group (e.g., system):',
			when: () => !group
		}
	]);

	return answers;
}
