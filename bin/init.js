const fs = require( 'fs' );
const readline = require( 'readline' );
const path = require( 'path' );

// Helper functions
function slugify( str ) {
	return str
		.toLowerCase()
		.replace( /\s+/g, '-' )
		.replace( /[^a-z0-9\-]/g, '' );
}

function getPrefix( str ) {
	const matches = str.match( /(?:^|[ _])([a-zA-Z])|([A-Z])/g ) || [];

	return matches
		.map( ( match ) =>
			match.length === 1 ? match : match[ match.length - 1 ].toUpperCase()
		)
		.join( '' );
}

function replaceInIncludes() {
	const includesDir = path.join( process.cwd(), 'include' );
	const targetNamespace = 'DarkMatter_Plugin';
	const targetPathConst = 'DMP_PLUGIN_PATH';
	const targetPackage = 'DarkMatter_Package';

	const replacementNamespace = answers.namespace;
	const replacementPathConst = `${ getPrefix(
		answers.pluginName
	) }_PLUGIN_PATH`;
	const replacementPackage = answers.package;

	if ( ! fs.existsSync( includesDir ) ) {
		console.warn( `⚠️ Directory not found: ${ includesDir }` ); // eslint-disable-line no-console
		return;
	}

	function walkDir( dir ) {
		const files = fs.readdirSync( dir );
		for ( const file of files ) {
			const fullPath = path.join( dir, file );
			const stat = fs.statSync( fullPath );

			if ( stat.isDirectory() ) {
				walkDir( fullPath );
			} else if ( file.endsWith( '.php' ) ) {
				const content = fs.readFileSync( fullPath, 'utf8' );
				const updated = content
					.replace(
						new RegExp( targetNamespace, 'g' ),
						replacementNamespace
					)
					.replace(
						new RegExp( targetPathConst, 'g' ),
						replacementPathConst
					)
					.replace(
						new RegExp( targetPackage, 'g' ),
						replacementPackage
					);

				if ( updated !== content ) {
					fs.writeFileSync( fullPath, updated, 'utf8' );
					console.log( `🛠️ Updated: ${ fullPath }` ); // eslint-disable-line no-console
				}
			}
		}
	}

	walkDir( includesDir );
}

// Setup CLI interface
const rl = readline.createInterface( {
	input: process.stdin,
	output: process.stdout,
} );

const questions = [
	{ key: 'pluginName', question: 'Plugin Name', default: 'My Plugin' },
	{ key: 'pluginURI', question: 'Plugin URI', default: '' },
	{
		key: 'description',
		question: 'Plugin Description',
		default: 'A custom WordPress plugin.',
	},
	{ key: 'version', question: 'Version', default: '1.0.0' },
	{
		key: 'requiresWP',
		question: 'Requires at least (WordPress version)',
		default: '6.5',
	},
	{ key: 'requiresPHP', question: 'Requires PHP', default: '7.4' },
	{ key: 'author', question: 'Author Name', default: '' },
	{ key: 'authorURI', question: 'Author URI', default: '' },
	{
		key: 'license',
		question: 'License',
		default: 'GPL v2 or later',
	},
	{
		key: 'licenseURI',
		question: 'License URI',
		default: 'https://www.gnu.org/licenses/gpl-2.0.html',
	},
	{ key: 'category', question: 'Category', default: 'Plugin' },
	{ key: 'package', question: 'Package Name', default: '' },
	{ key: 'email', question: 'Author Email', default: '' },
	{ key: 'namespace', question: 'Namespace', default: 'My_Plugin' },
];

const answers = {};

function askQuestion( index = 0 ) {
	const item = questions[ index ];
	const prompt = !! item.default
		? `${ item.question } [${ item.default }]: `
		: `${ item.question }: `;

	rl.question( prompt, ( input ) => {
		answers[ item.key ] = input.trim() || item.default;
		if ( index + 1 < questions.length ) {
			askQuestion( index + 1 );
		} else {
			rl.close();
			generatePluginFile();
		}
	} );
}

function generatePluginFile() {
	const slug = slugify( answers.pluginName );
	const constPrefix = getPrefix( answers.pluginName );
	const textDomain = slug;
	const domainPath = '/languages';
	const pluginFileName = `${ slug }.php`;

	const content = `<?php
/**
 * Plugin Name:       ${ answers.pluginName }
 * Plugin URI:        ${ answers.pluginURI }
 * Description:       ${ answers.description }
 * Version:           ${ answers.version }
 * Requires at least: ${ answers.requiresWP }
 * Requires PHP:      ${ answers.requiresPHP }
 * Author:            ${ answers.author }
 * Author URI:        ${ answers.authorURI }
 * License:           ${ answers.license }
 * License URI:       ${ answers.licenseURI }
 * Text Domain:       ${ textDomain }
 * Domain Path:       ${ domainPath }
 *
 * @category ${ answers.category }
 * @package  ${ answers.package }
 * @author   ${ answers.author } <${ answers.email }>
 * @license  ${ answers.license } <${ answers.licenseURI }>
 * @link     ${ answers.pluginURI }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Plugin base path and URL.
 */
define( '${ constPrefix }_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( '${ constPrefix }_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ${ constPrefix }_PLUGIN_PATH . 'include/helpers/autoloader.php';

use ${ answers.namespace }\\Plugin;

Plugin::get_instance();
`;

	fs.writeFileSync( path.join( process.cwd(), pluginFileName ), content );

	// eslint-disable-next-line no-console
	console.log(
		`✅ Plugin generated: ${ path.join( process.cwd(), pluginFileName ) }`
	);
	// eslint-disable-next-line no-console
	console.log( `🔧 Constant prefix used: ${ constPrefix }` );
	// eslint-disable-next-line no-console
	console.log( `📦 Plugin slug: ${ slug }` );
	// eslint-disable-next-line no-console
	console.log( `📁 Directory: ${ process.cwd() }` );

	replaceInIncludes();
}

askQuestion();
