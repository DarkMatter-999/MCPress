#!/usr/bin/env node

import { Command } from 'commander';
import createTool from './create-tools.js';

const program = new Command();

program.name('mcpress').description('CLI for MCPress WordPress tooling').version('1.0.0');

//
// create-tool command
//
program
	.command('create-tool')
	.description('Create a new LLM tool class')
	.option('-n, --name <name>', 'Tool name (e.g., Site_Info)')
	.option('-g, --group <group>', 'Tool group (e.g., system, user, etc.)')
	.action(createTool);
program.parse(process.argv);
