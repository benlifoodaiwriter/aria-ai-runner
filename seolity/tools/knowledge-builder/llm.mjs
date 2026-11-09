/**
 * Model access for the SEOlity knowledge builder.
 *
 * Picks the first engine that has credentials (or KB_ENGINE):
 *   api    – Anthropic API (ANTHROPIC_API_KEY), native JSON-schema output
 *   claude – Claude Code with a Claude Pro/Max subscription (CLAUDE_CODE_OAUTH_TOKEN)
 *   codex  – OpenAI Codex with a ChatGPT login (CODEX_AUTH_JSON)
 * so the same workflow runs whether the site owner has an API key or only a subscription.
 */
import { spawn } from 'node:child_process';
import { mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const E = process.env;

function run( bin, args, input, env, timeoutMs = 900000, cwd ) {
	return new Promise( ( resolve ) => {
		let out = '';
		let err = '';
		let killed = false;
		const child = spawn( bin, args, { env, cwd, stdio: [ 'pipe', 'pipe', 'pipe' ] } );
		const timer = setTimeout( () => {
			killed = true;
			child.kill( 'SIGKILL' );
		}, timeoutMs );
		child.stdout.on( 'data', ( d ) => ( out += d ) );
		child.stderr.on( 'data', ( d ) => ( err += d ) );
		child.on( 'error', ( e ) => {
			clearTimeout( timer );
			resolve( { code: -1, out, err: String( e.message ), killed } );
		} );
		child.on( 'close', ( code ) => {
			clearTimeout( timer );
			resolve( { code, out, err, killed } );
		} );
		child.stdin.end( input || '' );
	} );
}

/** First JSON object in a model answer (tolerates ```json fences and chatter). */
export function parseJson( text ) {
	const t = String( text || '' ).replace( /<think>[\s\S]*?<\/think>/g, '' ).trim();
	try {
		return JSON.parse( t );
	} catch ( e ) {}
	const fenced = t.match( /```(?:json)?\s*([\s\S]*?)```/ );
	if ( fenced ) {
		try {
			return JSON.parse( fenced[ 1 ] );
		} catch ( e ) {}
	}
	const a = t.indexOf( '{' );
	const b = t.lastIndexOf( '}' );
	if ( a >= 0 && b > a ) {
		return JSON.parse( t.slice( a, b + 1 ) );
	}
	throw new Error( 'the model did not return JSON' );
}

const schemaNote = ( schema ) => `\n\nRespond ONLY with one JSON object (no markdown fences, no commentary) that validates against this JSON Schema:\n${ JSON.stringify( schema ) }`;

export function pickEngine() {
	const wanted = ( E.KB_ENGINE || 'auto' ).toLowerCase();
	if ( wanted !== 'auto' ) {
		return wanted;
	}
	if ( E.ANTHROPIC_API_KEY ) {
		return 'api';
	}
	if ( E.CLAUDE_CODE_OAUTH_TOKEN ) {
		return 'claude';
	}
	if ( E.CODEX_AUTH_JSON ) {
		return 'codex';
	}
	return '';
}

export async function createEngine( { model, effort } ) {
	const engine = pickEngine();
	const tmp = await mkdtemp( join( tmpdir(), 'seolity-kb-' ) );

	if ( engine === 'api' ) {
		const { default: Anthropic } = await import( '@anthropic-ai/sdk' );
		const client = new Anthropic();
		const fallbacks = /^claude-(opus-5|sonnet-5-5|fable-5-1)/.test( model );
		return {
			name: 'api',
			model,
			async ask( { system, prompt, schema, maxTokens = 8000 } ) {
				const params = { model, max_tokens: maxTokens, system, output_config: { effort, format: { type: 'json_schema', schema } }, messages: [ { role: 'user', content: prompt } ] };
				const res = fallbacks ? await client.beta.messages.create( { ...params, betas: [ 'server-side-fallback-2026-07-01' ], fallbacks: 'default' } ) : await client.messages.create( params );
				if ( res.stop_reason === 'refusal' ) {
					throw new Error( 'model declined the request' );
				}
				if ( res.stop_reason === 'max_tokens' ) {
					throw new Error( 'output truncated (max_tokens)' );
				}
				const block = res.content.find( ( b ) => b.type === 'text' );
				return { data: parseJson( block ? block.text : '' ), usage: { input: res.usage.input_tokens, output: res.usage.output_tokens }, model: res.model };
			},
		};
	}

	if ( engine === 'claude' ) {
		const cliModel = E.KB_CLAUDE_MODEL || 'sonnet';
		return {
			name: 'claude',
			model: cliModel,
			async ask( { system, prompt, schema } ) {
				const sysFile = join( tmp, `sys-${ Date.now() }.txt` );
				await writeFile( sysFile, system + schemaNote( schema ), 'utf8' );
				const env = { ...E, DISABLE_AUTOUPDATER: '1' };
				delete env.ANTHROPIC_API_KEY; // Use the subscription token, never a pay-per-token key.
				delete env.CODEX_AUTH_JSON;
				const r = await run( E.CLAUDE_BIN || 'claude', [ '-p', '--output-format', 'json', '--model', cliModel, '--tools', '', '--max-turns', '2', '--no-session-persistence', '--system-prompt-file', sysFile ], prompt, env );
				if ( r.killed ) {
					throw new Error( 'Claude Code timeout' );
				}
				let out = null;
				try {
					out = JSON.parse( r.out.trim().split( '\n' ).filter( Boolean ).pop() || '' );
				} catch ( e ) {}
				if ( ! out || out.is_error || ( out.subtype && out.subtype !== 'success' ) ) {
					throw new Error( 'Claude Code: ' + String( ( out && out.result ) || r.err || r.out || 'no output' ).slice( 0, 400 ) );
				}
				const u = out.usage || {};
				return { data: parseJson( out.result ), usage: { input: u.input_tokens || 0, output: u.output_tokens || 0 }, model: cliModel };
			},
		};
	}

	if ( engine === 'codex' ) {
		const home = join( tmp, 'codex' );
		await ( await import( 'node:fs/promises' ) ).mkdir( home, { recursive: true } );
		await writeFile( join( home, 'auth.json' ), E.CODEX_AUTH_JSON, { mode: 0o600 } );
		await writeFile( join( home, 'config.toml' ), 'cli_auth_credentials_store = "file"\n' );
		return {
			name: 'codex',
			model: E.KB_CODEX_MODEL || '',
			async ask( { system, prompt, schema } ) {
				const outFile = join( tmp, `out-${ Date.now() }.txt` );
				const args = [ 'exec', '--skip-git-repo-check', '--sandbox', 'read-only', '--ephemeral', '-o', outFile ];
				if ( E.KB_CODEX_MODEL ) {
					args.push( '-m', E.KB_CODEX_MODEL );
				}
				args.push( '-' );
				const env = { ...E, CODEX_HOME: home };
				delete env.OPENAI_API_KEY;
				delete env.CLAUDE_CODE_OAUTH_TOKEN;
				const input = `# Instructions\n${ system }${ schemaNote( schema ) }\n\n# Task\n${ prompt }\n\nReturn only the JSON. Do not run commands or edit files.`;
				const r = await run( E.CODEX_BIN || 'codex', args, input, env, 900000, tmp );
				const text = existsSync( outFile ) ? ( await readFile( outFile, 'utf8' ) ).trim() : '';
				if ( ! text ) {
					throw new Error( 'Codex: ' + String( r.err || r.out || 'no output' ).slice( 0, 400 ) );
				}
				return { data: parseJson( text ), usage: { input: 0, output: 0 }, model: E.KB_CODEX_MODEL || 'codex' };
			},
		};
	}

	return null;
}
