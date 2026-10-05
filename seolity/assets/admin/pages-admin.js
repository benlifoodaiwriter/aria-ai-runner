/**
 * SEOlity admin pages: Automation (approval workflow, rules engine, queue,
 * audit log), Knowledge Base, Settings.
 */
( function ( w ) {
	'use strict';
	var SL = w.SL;
	var App = SL.app;
	var t = SL.t;
	var h = SL.h;

	/* ================================================================ Automation */
	App.pages.automation = function ( v ) {
		v.appendChild( App.header( t( 'Automation' ), t( 'Approval workflow, SEO rules engine, background queue and the full audit log.' ) ) );
		v.appendChild( SL.tabs( [
			{ label: t( 'Approval workflow' ), render: approval },
			{ label: t( 'Rules' ), render: rules },
			{ label: t( 'Background queue' ), render: queue },
			{ label: t( 'Audit log' ), render: auditLog },
		] ) );

		function approval() {
			var box = h( 'div' );
			App.load( box, SL.api( 'settings' ), function ( s ) {
				var modes = [
					[ 'manual', t( 'Manual' ), t( 'Every change needs your approval (recommended to start).' ) ],
					[ 'semi', t( 'Semi-Automatic' ), t( 'Low-risk changes (ALT text, meta descriptions, focus keywords) are applied automatically; everything else waits for approval.' ) ],
					[ 'autonomous', t( 'Autonomous' ), t( 'AI acts within your rules: low and medium-risk changes are applied automatically. High-risk changes (content, titles, slugs, noindex, redirects) always need approval.' ) ],
				];
				box.appendChild( h( 'div', { class: 'sl-modes' }, modes.map( function ( m ) {
					return h( 'button', { type: 'button', class: 'sl-mode' + ( s.settings.approval_mode === m[ 0 ] ? ' is-active' : '' ), onClick: function () {
						SL.api( 'settings', { body: { approval_mode: m[ 0 ] } } ).then( function () {
							SL.toast( t( 'Approval mode updated.' ), 'success' );
							App.refreshBoot();
							App.pages.automation( SL.clear( v ) );
						} ).catch( SL.fail );
					} }, h( 'strong', { text: m[ 1 ] } ), h( 'span', { class: 'sl-small', text: m[ 2 ] } ) );
				} ) ) );
				box.appendChild( h( 'p', { class: 'sl-muted sl-small', text: t( 'All changes — automatic or manual — are logged, versioned and can be undone.' ) } ) );
			} );
			return box;
		}

		function rules() {
			var box = h( 'div' );
			function load() {
				App.load( box, SL.api( 'rules' ), function ( r ) {
					var labels = {
						fields: { post_type: t( 'Post type' ), missing_alt: t( 'Has image without ALT' ), meta_description_missing: t( 'Meta description missing' ), title_length_gt: t( 'SEO title length >' ), seo_score_lt: t( 'SEO score <' ), word_count_lt: t( 'Word count <' ), ctr_lt: t( 'CTR % <' ), impressions_gt: t( 'Impressions (28d) >' ), position_gt: t( 'Position >' ), position_lt: t( 'Position <' ), has_faq: t( 'Has FAQ' ), no_short_description: t( 'Product without short description' ), inlinks_lt: t( 'Internal links <' ) },
						actions: { generate_alt: t( 'Generate ALT' ), optimize_meta: t( 'Analyze & rewrite title + meta' ), generate_faq: t( 'Generate FAQ' ), optimize_content: t( 'Optimize content' ), suggest_internal_links: t( 'Suggest internal links' ), add_noindex: t( 'Propose noindex' ), alert: t( 'Send alert' ), recommend: t( 'Create recommendation' ) },
						triggers: { content_published: t( 'Content published' ), content_updated: t( 'Content updated' ), image_uploaded: t( 'Image uploaded' ), daily: t( 'Daily check' ) },
					};
					if ( App.cap( 'manage' ) ) {
						box.appendChild( h( 'div', { class: 'sl-toolbar' }, App.btn( '+ ' + t( 'New rule' ), function () {
							editor( null, r, labels );
						}, 'primary' ), h( 'span', { class: 'sl-muted sl-small', text: t( 'Templates:' ) } ), r.presets.map( function ( p ) {
							return App.btn( p.name, function () {
								editor( p, r, labels );
							}, 'sm' );
						} ) ) );
					}
					box.appendChild( App.table( [
						{ label: t( 'Rule' ), render: function ( x ) {
							return h( 'div', null, h( 'strong', { text: x.name } ), h( 'div', { class: 'sl-small sl-muted' }, t( 'IF' ) + ' ' + ( x.conditions || [] ).map( function ( c ) {
								return ( labels.fields[ c.field ] || c.field ) + ' ' + c.op + ' ' + c.value;
							} ).join( ' & ' ) + ' → ' + t( 'THEN' ) + ' ' + ( x.actions || [] ).map( function ( a ) {
								return labels.actions[ a.type ] || a.type;
							} ).join( ', ' ) ) );
						} },
						{ label: t( 'Trigger' ), render: function ( x ) {
							return labels.triggers[ x.trigger_event ] || x.trigger_event;
						} },
						{ label: t( 'Mode' ), render: function ( x ) {
							return SL.badge( x.mode === 'auto' ? t( 'Auto (within approval mode)' ) : t( 'Suggest only' ), x.mode === 'auto' ? 'warn' : 'default' );
						} },
						{ label: t( 'Runs' ), key: 'run_count' },
						{ label: t( 'Last run' ), render: function ( x ) {
							return SL.date( x.last_run );
						} },
						{ label: '', render: function ( x ) {
							return App.cap( 'manage' ) ? h( 'div', { class: 'sl-row' }, App.btn( t( 'Edit' ), function () {
								editor( x, r, labels );
							}, 'sm' ), App.btn( SL.icon( 'trash', 16 ), function () {
								SL.confirm( t( 'Delete this rule?' ), function () {
									SL.api( 'rules/' + x.id, { method: 'DELETE' } ).then( load ).catch( SL.fail );
								}, true );
							}, 'ghost', { 'aria-label': t( 'Delete' ) } ) ) : null;
						} },
					], r.rules, { empty: t( 'No rules yet' ), emptyText: t( 'Example: IF product has image without ALT THEN generate ALT.' ) } ) );
				} );
			}
			function editor( rule, meta, labels ) {
				var m = SL.modal( { title: rule && rule.id ? t( 'Edit rule' ) : t( 'New rule' ), wide: true } );
				var name = h( 'input', { class: 'sl-input', value: rule ? rule.name : '' } );
				var trig = App.select( meta.triggers.map( function ( x ) {
					return [ x, labels.triggers[ x ] ];
				} ), rule ? rule.trigger_event : 'daily' );
				var mode = App.select( [ [ 'suggest', t( 'Suggest only' ) ], [ 'auto', t( 'Auto (within approval mode)' ) ] ], rule ? rule.mode : 'suggest' );
				var conds = h( 'div', { class: 'sl-stack' } );
				var acts = h( 'div', { class: 'sl-chips' } );
				var chosen = {};
				( rule && rule.actions ? rule.actions : [] ).forEach( function ( a ) {
					chosen[ a.type ] = true;
				} );
				function condRow( c ) {
					var f = App.select( meta.fields.map( function ( x ) {
						return [ x, labels.fields[ x ] ];
					} ), c ? c.field : meta.fields[ 0 ] );
					var op = App.select( [ [ '=', '=' ], [ '!=', '≠' ], [ '<', '<' ], [ '>', '>' ] ], c ? c.op : '=' );
					var val = h( 'input', { class: 'sl-input sl-input--sm', value: c ? c.value : '' } );
					var row = h( 'div', { class: 'sl-row sl-cond' }, f, op, val, App.btn( SL.icon( 'x', 16 ), function () {
						row.remove();
					}, 'ghost', { 'aria-label': t( 'Remove' ) } ) );
					row._get = function () {
						return { field: f.value, op: op.value, value: val.value };
					};
					conds.appendChild( row );
				}
				( rule && rule.conditions ? rule.conditions : [] ).forEach( condRow );
				meta.actions.forEach( function ( a ) {
					var cb = h( 'input', { type: 'checkbox', checked: !! chosen[ a ], onChange: function () {
						chosen[ a ] = cb.checked;
					} } );
					acts.appendChild( h( 'label', { class: 'sl-chip sl-check' }, cb, labels.actions[ a ] ) );
				} );
				m.body.appendChild( h( 'div', { class: 'sl-stack' }, App.field( t( 'Name' ), name ), App.field( t( 'When' ), trig ), h( 'div', null, h( 'div', { class: 'sl-field__label', text: t( 'IF (all conditions)' ) } ), conds, App.btn( '+ ' + t( 'Condition' ), function () {
					condRow( null );
				}, 'sm' ) ), h( 'div', null, h( 'div', { class: 'sl-field__label', text: t( 'THEN' ) } ), acts ), App.field( t( 'Mode' ), mode ) ) );
				m.foot.appendChild( App.abtn( t( 'Save rule' ), function () {
					var body = { name: name.value, trigger_event: trig.value, mode: mode.value, conditions: Array.prototype.map.call( conds.children, function ( r ) {
						return r._get();
					} ), actions: Object.keys( chosen ).filter( function ( k ) {
						return chosen[ k ];
					} ).map( function ( k ) {
						return { type: k };
					} ) };
					var req = rule && rule.id ? SL.api( 'rules/' + rule.id, { method: 'PUT', body: body } ) : SL.api( 'rules', { body: body } );
					return req.then( function () {
						m.close();
						load();
					} );
				}, 'primary' ) );
			}
			load();
			return box;
		}

		function queue() {
			var box = h( 'div' );
			function load() {
				App.load( box, SL.api( 'queue' ), function ( q ) {
					box.appendChild( h( 'div', { class: 'sl-kpis' }, App.kpi( t( 'Pending' ), SL.num( q.pending, 0 ) ), App.kpi( t( 'Running' ), SL.num( q.running, 0 ) ), App.kpi( t( 'Done (3 days)' ), SL.num( q.done, 0 ) ), App.kpi( t( 'Failed' ), SL.num( q.failed, 0 ) ) ) );
					box.appendChild( h( 'div', { class: 'sl-toolbar' }, App.abtn( t( 'Run queue now' ), function () {
						return SL.api( 'queue/run', { method: 'POST' } ).then( function ( r ) {
							SL.toast( t( '%s jobs processed.', r.processed ) );
							load();
						} );
					}, 'primary' ), App.abtn( t( 'Retry failed' ), function () {
						return SL.api( 'queue/retry', { method: 'POST' } ).then( load );
					} ), h( 'span', { class: 'sl-muted sl-small', text: t( 'Next cron tick' ) + ': ' + ( q.next_cron ? new Date( q.next_cron * 1000 ).toLocaleTimeString() : '—' ) } ) ) );
					box.appendChild( h( 'p', { class: 'sl-muted sl-small', text: t( 'Tip: for busy or low-traffic sites, add a real system cron that runs “wp seolity queue run” (or hits wp-cron.php) every minute.' ) } ) );
					box.appendChild( App.table( [ { label: '#', key: 'id' }, { label: t( 'Job' ), key: 'hook' }, { label: t( 'Status' ), render: function ( j ) {
						return SL.badge( t( j.status ), j.status === 'failed' ? 'bad' : j.status === 'done' ? 'good' : 'warn' );
					} }, { label: t( 'Attempts' ), key: 'attempts' }, { label: t( 'Error' ), render: function ( j ) {
						return h( 'span', { class: 'sl-small', text: j.last_error || '' } );
					} }, { label: t( 'Created' ), render: function ( j ) {
						return SL.date( j.created_at );
					} } ], q.recent ) );
				} );
			}
			load();
			return box;
		}

		function auditLog() {
			var box = h( 'div' );
			App.load( box, SL.api( 'audit-log' ), function ( rows ) {
				box.appendChild( App.table( [ { label: t( 'Date' ), render: function ( r ) {
					return SL.date( r.created_at ) + ' ' + String( r.created_at ).slice( 11, 16 );
				} }, { label: t( 'User' ), render: function ( r ) {
					return r.display_name || ( +r.user_id ? '#' + r.user_id : t( 'System' ) );
				} }, { label: t( 'Action' ), key: 'action' }, { label: t( 'Object' ), render: function ( r ) {
					return ( r.object_type || '' ) + ( r.object_id ? ' #' + r.object_id : '' );
				} }, { label: t( 'Details' ), render: function ( r ) {
					return r.details ? h( 'code', { class: 'sl-small', dir: 'ltr', text: JSON.stringify( r.details ).slice( 0, 160 ) } ) : '';
				} } ], rows ) );
			} );
			return box;
		}
	};

	/* ================================================================ Knowledge base */
	App.pages.knowledge = function ( v ) {
		v.appendChild( App.header( t( 'SEO Knowledge Base' ), t( 'Curated Persian & English SEO best practices that ground every AI answer. Synced from GitHub, where a scheduled workflow distils the best Iranian and international SEO documentation.' ), App.cap( 'manage' ) ? [ App.abtn( t( 'Sync from GitHub' ), function () {
			return SL.api( 'knowledge/sync', { method: 'POST' } ).then( function ( r ) {
				SL.toast( r.error ? r.error : t( 'Synced: %1$s bundled, %2$s from GitHub.', r.bundled, r.github ), r.error ? 'error' : 'success' );
				App.pages.knowledge( SL.clear( v ) );
			} );
		}, 'primary' ) ] : null ) );
		var body = h( 'div' );
		v.appendChild( body );
		App.load( body, SL.api( 'knowledge' ), function ( r ) {
			body.appendChild( h( 'p', { class: 'sl-muted sl-small' }, t( 'Repository' ) + ': ', h( 'code', { dir: 'ltr', text: r.repo + '/' + r.path } ), ' · ', t( 'Last sync' ) + ': ', r.synced ? SL.date( new Date( r.synced.time * 1000 ).toISOString() ) : '—' ) );
			var topics = {};
			r.items.forEach( function ( d ) {
				( topics[ d.topic ] = topics[ d.topic ] || [] ).push( d );
			} );
			var grid = h( 'div', { class: 'sl-grid-3' } );
			Object.keys( topics ).sort().forEach( function ( tp ) {
				grid.appendChild( App.card( tp, h( 'ul', { class: 'sl-list' }, topics[ tp ].map( function ( d ) {
					return h( 'li', null, h( 'button', { type: 'button', class: 'sl-link-btn', onClick: function () {
						SL.api( 'knowledge/doc', { query: { source: d.source, path: d.path } } ).then( function ( doc ) {
							var m = SL.modal( { title: doc.title, wide: true } );
							m.body.appendChild( SL.markdown( doc.content ) );
						} ).catch( SL.fail );
					} }, d.title ), ' ', SL.badge( d.lang ), ' ', SL.badge( d.source, d.source === 'github' ? 'brand' : 'default' ) );
				} ) ) ) );
			} );
			body.appendChild( grid );
			if ( App.cap( 'manage' ) ) {
				var title = h( 'input', { class: 'sl-input', placeholder: t( 'Title' ) } );
				var topic = h( 'input', { class: 'sl-input sl-input--sm', placeholder: t( 'topic' ), value: 'general' } );
				var content = h( 'textarea', { class: 'sl-input', rows: 6, dir: 'auto', placeholder: t( 'Your own SEO rule, brand guideline or lesson learned…' ) } );
				var push = h( 'input', { type: 'checkbox' } );
				body.appendChild( App.card( t( 'Add your own knowledge' ), h( 'div', { class: 'sl-stack' }, h( 'div', { class: 'sl-row' }, title, topic ), content, h( 'label', { class: 'sl-check' }, push, t( 'Also commit it to the GitHub knowledge repository' ) ), App.abtn( t( 'Save note' ), function () {
					return SL.api( 'knowledge/note', { body: { title: title.value, topic: topic.value, content: content.value, push: push.checked } } ).then( function () {
						SL.toast( t( 'Knowledge saved.' ), 'success' );
						App.pages.knowledge( SL.clear( v ) );
					} );
				}, 'primary' ) ) ) );
			}
		} );
	};

	/* ================================================================ Settings */
	var LABELS = {
		approval_mode: 'Approval mode', ui_language: 'Interface language', brand_name: 'Brand name (white label)', brand_logo: 'Brand logo URL', brand_color: 'Brand color', frontend_output: 'Output SEO tags on the frontend', title_separator: 'Title separator',
		ai_provider: 'AI provider', ai_model_openai: 'OpenAI model', ai_model_anthropic: 'Anthropic model', ai_model_gemini: 'Gemini model', ai_model_openrouter: 'OpenRouter model', ai_model_ollama: 'Ollama model', ai_model_custom: 'Custom model', ai_effort: 'Reasoning effort (Claude)', ollama_base_url: 'Ollama URL', custom_base_url: 'Custom API base URL', ai_max_tokens: 'Max output tokens', ai_timeout: 'Request timeout (s)', ai_monthly_budget: 'Monthly AI budget (USD, 0 = unlimited)', ai_cache_hours: 'Cache AI answers (hours)', ai_content_language: 'Content language', ai_brand_voice: 'Brand voice & editorial rules', ai_use_knowledge: 'Use the SEO knowledge base in prompts',
		gsc_sync_days: 'Initial import (days)', gsc_row_limit: 'Rows per API call',
		crawl_max_urls: 'Max URLs per crawl', crawl_batch_size: 'Parallel requests per batch', crawl_check_external: 'Check external links', crawl_frequency: 'Full audit frequency', crawl_timeout: 'Request timeout (s)', thin_content_words: 'Thin content threshold (words)', competitor_max_pages: 'Max pages per competitor',
		alert_email: 'Alert email', alert_email_enabled: 'Email high-priority alerts', alert_drop_percent: 'Drop alert threshold (%)', report_frequency: 'Scheduled report', report_recipients: 'Report recipients (one per line)',
		sitemap_enabled: 'Enhance WordPress sitemaps', sitemap_exclude_types: 'Exclude post types from sitemap', robots_override: 'Serve custom robots.txt', redirects_enabled: 'Enable redirects', log_404: 'Log 404 errors', log_404_bots: 'Log 404s from bots', retention_days: 'Data retention (days)',
		schema_enabled: 'Output structured data', org_type: 'Entity type', org_name: 'Organization / person name', org_logo: 'Logo URL', org_phone: 'Phone', org_address: 'Address', org_same_as: 'Social profiles (sameAs, one per line)',
		experiment_window: 'Experiment window (days)',
		kb_repo: 'Knowledge repository (owner/repo)', kb_branch: 'Knowledge branch', kb_path: 'Knowledge folder', kb_auto_sync: 'Sync knowledge daily', kb_contribute: 'Contribute anonymized learnings to GitHub',
		storage_enabled: 'Enable GitHub data storage', storage_repo: 'Data repository (owner/repo, private!)', storage_branch: 'Data branch', storage_path: 'Data folder', storage_archive_days: 'Archive data older than (days)', storage_prune_local: 'Delete archived rows from the WordPress database',
		admin_webfont: 'Load the Vazirmatn font from Google Fonts in the admin',
		anonymize_ip: 'Anonymize IP addresses', delete_on_uninstall: 'Delete all data on uninstall',
		ai_model_sub_claude: 'Claude model (opus, sonnet, haiku or a full model id)', ai_model_sub_codex: 'ChatGPT / Codex model (empty = default)',
		sub_repo: 'Runner repository (owner/name, private)', sub_branch: 'Queue branch', sub_callback: 'Send results directly to this site (faster when the site is public)', sub_wait_minutes: 'Maximum wait per request (minutes)',
	};
	var GROUPS = [
		[ 'general', 'General', [ 'approval_mode', 'ui_language', 'admin_webfont', 'frontend_output', 'title_separator', 'brand_name', 'brand_logo', 'brand_color' ] ],
		[ 'ai', 'AI', [ 'ai_provider', 'ai_model_anthropic', 'ai_model_openai', 'ai_model_gemini', 'ai_model_openrouter', 'ai_model_ollama', 'ollama_base_url', 'ai_model_custom', 'custom_base_url', 'ai_effort', 'ai_content_language', 'ai_brand_voice', 'ai_use_knowledge', 'ai_max_tokens', 'ai_timeout', 'ai_monthly_budget', 'ai_cache_hours' ] ],
		[ 'subscription', 'Subscription AI', [ 'sub_repo', 'ai_model_sub_claude', 'ai_model_sub_codex', 'sub_callback', 'sub_wait_minutes', 'sub_branch' ] ],
		[ 'crawler', 'Crawler & audits', [ 'crawl_frequency', 'crawl_max_urls', 'crawl_batch_size', 'crawl_timeout', 'thin_content_words', 'crawl_check_external', 'competitor_max_pages', 'experiment_window', 'gsc_sync_days', 'gsc_row_limit' ] ],
		[ 'reports', 'Alerts & reports', [ 'alert_email_enabled', 'alert_email', 'alert_drop_percent', 'report_frequency', 'report_recipients' ] ],
		[ 'technical', 'Technical SEO', [ 'sitemap_enabled', 'sitemap_exclude_types', 'redirects_enabled', 'log_404', 'log_404_bots' ] ],
		[ 'schema', 'Schema & entity', [ 'schema_enabled', 'org_type', 'org_name', 'org_logo', 'org_phone', 'org_address', 'org_same_as' ] ],
		[ 'github', 'GitHub (knowledge & storage)', [ 'kb_repo', 'kb_branch', 'kb_path', 'kb_auto_sync', 'kb_contribute', 'storage_enabled', 'storage_repo', 'storage_branch', 'storage_path', 'storage_archive_days', 'storage_prune_local' ] ],
		[ 'privacy', 'Privacy & data', [ 'anonymize_ip', 'retention_days', 'delete_on_uninstall' ] ],
	];
	var GROUP_ICONS = { general: 'settings', ai: 'cpu', subscription: 'sparkles', crawler: 'pulse', reports: 'bell', technical: 'wrench', schema: 'braces', github: 'github', privacy: 'lock' };
	var SECRETS = {
		ai: [ [ 'anthropic_api_key', 'Anthropic API key' ], [ 'openai_api_key', 'OpenAI API key' ], [ 'gemini_api_key', 'Google Gemini API key' ], [ 'openrouter_api_key', 'OpenRouter API key' ], [ 'custom_api_key', 'Custom API key' ] ],
		subscription: [ [ 'claude_oauth_token', 'Claude subscription token (output of: claude setup-token)' ], [ 'codex_auth_json', 'ChatGPT login (contents of ~/.codex/auth.json after: codex login)' ] ],
		crawler: [ [ 'pagespeed_api_key', 'PageSpeed Insights API key (optional)' ], [ 'serp_api_key', 'SERP data API key (optional)' ] ],
		github: [ [ 'github_token', 'GitHub fine-grained token (Contents: read & write on the chosen repos only)' ] ],
	};

	App.pages.settings = function ( v, q ) {
		v.appendChild( App.header( t( 'Settings' ) ) );
		var body = h( 'div' );
		v.appendChild( body );
		App.load( body, SL.api( 'settings' ), function ( data ) {
			var dirty = {};
			var tabs = GROUPS.map( function ( g ) {
				return { label: t( g[ 1 ] ), key: g[ 0 ], render: function () {
					return group( g, data, dirty );
				} };
			} );
			if ( q.tab ) {
				var idx = tabs.findIndex( function ( x ) {
					return x.key === q.tab;
				} );
				if ( idx > 0 ) {
					tabs.unshift( tabs.splice( idx, 1 )[ 0 ] );
				}
			}
			body.appendChild( SL.tabs( tabs ) );
			body.appendChild( h( 'div', { class: 'sl-savebar' }, App.abtn( t( 'Save settings' ), function () {
				return SL.api( 'settings', { body: dirty } ).then( function ( r ) {
					SL.toast( t( 'Settings saved.' ), 'success' );
					data.settings = r.settings;
					dirty = {};
					App.refreshBoot();
				} );
			}, 'primary' ) ) );
		} );

		function input( key, def, value, dirty, providers ) {
			var el;
			if ( def.type === 'bool' ) {
				return App.toggle( t( LABELS[ key ] || key ), value, function ( on ) {
					dirty[ key ] = on;
				} );
			}
			if ( def.type === 'enum' ) {
				var names = {};
				( key === 'ai_provider' && providers ? providers : [] ).forEach( function ( p ) {
					names[ p.id ] = p.label;
				} );
				el = App.select( def.options.map( function ( o ) {
					return [ o, names[ o ] || t( o ) ];
				} ), value, function ( x ) {
					dirty[ key ] = x;
				} );
			} else if ( def.type === 'text' || def.type === 'list' ) {
				el = h( 'textarea', { class: 'sl-input', rows: 3, dir: 'auto' } );
				el.value = Array.isArray( value ) ? value.join( '\n' ) : value || '';
				el.addEventListener( 'input', function () {
					dirty[ key ] = el.value;
				} );
			} else {
				el = h( 'input', { class: 'sl-input', type: def.type === 'int' || def.type === 'float' ? 'number' : key === 'brand_color' ? 'color' : 'text', value: value === null || value === undefined ? '' : value, dir: /url|repo|path|branch|model|email/.test( key ) ? 'ltr' : 'auto', step: def.type === 'float' ? '0.01' : null, min: def.range ? def.range[ 0 ] : null, max: def.range ? def.range[ 1 ] : null } );
				el.addEventListener( 'input', function () {
					dirty[ key ] = el.value;
				} );
			}
			return App.field( t( LABELS[ key ] || key ), el );
		}

		function secretField( key, label, flags ) {
			var el = h( 'input', { class: 'sl-input', type: 'password', autocomplete: 'new-password', dir: 'ltr', placeholder: flags[ key ] ? '•••••••• ' + t( '(saved — enter a new value to replace)' ) : '' } );
			return App.field( t( label ), h( 'div', { class: 'sl-row' }, el, App.abtn( t( 'Save' ), function () {
				return SL.api( 'settings/secret', { body: { key: key, value: el.value } } ).then( function ( r ) {
					flags[ key ] = r.secrets[ key ];
					el.value = '';
					el.placeholder = r.secrets[ key ] ? '•••••••• ' + t( '(saved)' ) : '';
					SL.toast( t( 'Saved securely (encrypted).' ), 'success' );
				} );
			}, 'sm' ), flags[ key ] ? App.abtn( t( 'Remove' ), function () {
				return SL.api( 'settings/secret', { body: { key: key, value: '' } } ).then( function ( r ) {
					flags[ key ] = r.secrets[ key ];
					el.placeholder = '';
					SL.toast( t( 'Removed.' ) );
				} );
			}, 'sm' ) : null, App.guideFor && App.guideFor( key ) ? h( 'a', { class: 'sl-btn sl-btn--sm sl-btn--ghost', href: '#/guide?service=' + App.guideFor( key ), title: t( 'How to get this key' ) }, SL.il( 'lifebuoy', t( 'Guide' ), 15 ) ) : null ) );
		}

		function subscriptionGuide( data, dirty ) {
			var wrap = h( 'div', { class: 'sl-stack' } );
			wrap.appendChild( h( 'div', { class: 'sl-alert sl-alert--info' },
				h( 'strong', { text: t( 'Use the AI subscription you already pay for' ) } ),
				h( 'p', { text: t( 'Instead of a pay-per-token API key, SEOlity can run every AI feature with your Claude Pro/Max or ChatGPT Plus/Pro plan. The official Claude Code / Codex command-line tools run on GitHub Actions in your own private repository; requests and answers are encrypted. Answers take about 1–3 minutes, so SEOlity waits for them automatically.' ) } ),
				h( 'ol', null,
					h( 'li', { text: t( 'GitHub → Settings → Developer settings → create a token with access to the runner repository: Contents, Actions, Secrets and Workflows (read & write) — or a classic token with the "repo" and "workflow" scopes. Save it in the GitHub tab.' ) } ),
					h( 'li', { text: t( 'Enter a repository name below, e.g. your-user/seolity-runner (it is created as a private repository if it does not exist) and save settings.' ) } ),
					h( 'li', { text: t( 'Claude: on your computer run “claude setup-token” (Claude Code CLI) and paste the token below. ChatGPT: run “codex login”, then paste the contents of ~/.codex/auth.json below.' ) } ),
					h( 'li', { text: t( 'Click “Set up GitHub runner”, then choose the subscription provider in the AI tab and press Test.' ) } )
				)
			) );
			var st = h( 'div' );
			var render = function ( s ) {
				SL.clear( st );
				var ok = function ( v ) {
					return SL.yes( !! v, 16 );
				};
				st.appendChild( h( 'ul', { class: 'sl-checklist' },
					h( 'li', { class: 'sl-iconline' }, ok( s.github_token ), h( 'span', { text: t( 'GitHub token' ) } ) ),
					h( 'li', { class: 'sl-iconline' }, ok( s.setup ), h( 'span', { text: t( 'Runner repository set up' ) + ( s.setup ? ' (' + SL.date( s.setup ) + ')' : '' ) } ) ),
					h( 'li', { class: 'sl-iconline' }, ok( s.claude_token ), h( 'span', { text: t( 'Claude subscription token' ) } ) ),
					h( 'li', { class: 'sl-iconline' }, ok( s.codex_auth ), h( 'span', { text: t( 'ChatGPT login (auth.json)' ) } ) )
				) );
				if ( s.repo_url ) {
					st.appendChild( h( 'p', { class: 'sl-small' }, t( 'Repository' ) + ': ', App.ext( s.repo_url, s.repo ), s.files_current ? null : h( 'span', { class: 'sl-muted', text: ' — ' + t( 'runner files will be updated on the next request' ) } ) ) );
				}
				var counts = s.requests_24h || {};
				st.appendChild( h( 'p', { class: 'sl-small sl-muted', text: t( 'Last 24 hours' ) + ': ' + t( 'done' ) + ' ' + ( counts.done || 0 ) + ' · ' + t( 'waiting' ) + ' ' + ( ( counts.queued || 0 ) + ( counts.submitted || 0 ) ) + ' · ' + t( 'failed' ) + ' ' + ( counts.failed || 0 ) } ) );
				if ( Array.isArray( s.runs ) && s.runs.length ) {
					st.appendChild( App.table( [ { label: t( 'Workflow run' ), render: function ( r ) {
						return App.ext( r.url, '#' + r.id );
					} }, { label: t( 'Status' ), render: function ( r ) {
						return SL.badge( r.conclusion || r.status, r.conclusion === 'success' ? 'good' : ( r.conclusion === 'failure' ? 'bad' : 'default' ) );
					} }, { label: t( 'Started' ), render: function ( r ) {
						return SL.date( r.created_at );
					} } ], s.runs ) );
				} else if ( s.runs && s.runs.error ) {
					st.appendChild( h( 'div', { class: 'sl-alert sl-alert--error', text: s.runs.error } ) );
				}
			};
			var actions = h( 'div', { class: 'sl-toolbar' },
				App.abtn( t( 'Set up GitHub runner' ), function () {
					var pending = Object.keys( dirty ).filter( function ( k ) {
						return /^(sub_|ai_model_sub_)/.test( k );
					} );
					var save = pending.length ? SL.api( 'settings', { body: dirty } ).then( function ( r ) {
						data.settings = r.settings;
						pending.forEach( function ( k ) {
							delete dirty[ k ];
						} );
					} ) : Promise.resolve();
					return save.then( function () {
						return SL.api( 'subscription/setup', { method: 'POST' } );
					} ).then( function ( r ) {
						render( r );
						SL.toast( t( 'GitHub runner is ready.' ), 'success' );
					} );
				}, 'primary' ),
				App.abtn( t( 'Start runner now' ), function () {
					return SL.api( 'subscription/run', { method: 'POST' } ).then( function () {
						SL.toast( t( 'Workflow started on GitHub.' ), 'success' );
					} );
				} ),
				App.abtn( t( 'Refresh status' ), function () {
					return SL.api( 'subscription' ).then( render );
				} )
			);
			wrap.appendChild( App.card( t( 'GitHub runner' ), h( 'div', null, actions, st ), { icon: 'github' } ) );
			App.load( st, SL.api( 'subscription' ), render );
			return wrap;
		}

		function group( g, data, dirty ) {
			var box = h( 'div', { class: 'sl-settings' } );
			if ( g[ 0 ] === 'ai' ) {
				var MONO = { anthropic: [ 'AN', '#d97757' ], openai: [ 'OA', '#10a37f' ], gemini: [ 'GE', '#4285f4' ], openrouter: [ 'OR', '#6366f1' ], ollama: [ 'OL', '#0f172a' ], custom: [ 'API', '#64748b' ], sub_claude: [ 'CL', '#d97757' ], sub_codex: [ 'GPT', '#10a37f' ] };
				box.appendChild( h( 'div', { class: 'sl-providers' }, data.providers.map( function ( p ) {
					var mono = MONO[ p.id ] || [ p.id.slice( 0, 2 ).toUpperCase(), '#1D4ED8' ];
					var active = data.settings.ai_provider === p.id;
					return h( 'div', { class: 'sl-provider' + ( p.configured ? ' is-ok' : '' ) + ( active ? ' is-active' : '' ), 'data-active-label': t( 'Active' ) },
						h( 'div', { class: 'sl-provider__head' },
							h( 'span', { class: 'sl-mono', style: { '--c': mono[ 1 ] }, text: mono[ 0 ] } ),
							h( 'div', null, h( 'b', { text: p.label } ), h( 'small', { dir: 'ltr', text: p.model || '—' } ) ),
							h( 'span', { class: 'sl-provider__state', title: p.configured ? t( 'configured' ) : t( 'not configured' ) }, SL.icon( p.configured ? 'check-circle' : 'x-circle', 20 ) )
						),
						h( 'div', { class: 'sl-provider__actions' },
							SL.badge( p.configured ? t( 'configured' ) : t( 'not configured' ), p.configured ? 'good' : 'default' ),
							p.configured ? App.abtn( t( 'Test' ), function () {
								return SL.api( 'settings/test-ai', { body: { provider: p.id } } ).then( function ( r ) {
									SL.toast( t( 'Connection OK — model %s', r.model ), 'success' );
								} );
							}, 'sm' ) : null
						)
					);
				} ) ) );
			}
			if ( g[ 0 ] === 'subscription' ) {
				box.appendChild( subscriptionGuide( data, dirty ) );
			}
			if ( g[ 0 ] === 'privacy' ) {
				box.appendChild( h( 'div', { class: 'sl-alert sl-alert--info', text: t( 'Encryption' ) + ': ' + data.encryption + '. ' + t( 'For maximum security define SEOLITY_ENCRYPTION_KEY in wp-config.php. You can also define any secret as a constant, e.g. SEOLITY_ANTHROPIC_API_KEY.' ) } ) );
			}
			var fields = h( 'div', { class: 'sl-form' } );
			g[ 2 ].forEach( function ( key ) {
				if ( data.schema[ key ] ) {
					fields.appendChild( input( key, data.schema[ key ], data.settings[ key ], dirty, data.providers ) );
				}
			} );
			box.appendChild( App.card( t( g[ 1 ] ), fields, { icon: GROUP_ICONS[ g[ 0 ] ] || 'settings' } ) );
			if ( ( SECRETS[ g[ 0 ] ] || [] ).length ) {
				var keys = h( 'div', { class: 'sl-form' } );
				SECRETS[ g[ 0 ] ].forEach( function ( s ) {
					keys.appendChild( secretField( s[ 0 ], s[ 1 ], data.secrets ) );
				} );
				box.appendChild( App.card( t( 'Keys & tokens' ), keys, { icon: 'key', sub: t( 'Write-only: secrets are encrypted on the server and never sent back to the browser.' ) } ) );
			}
			if ( g[ 0 ] === 'ai' ) {
				var usage = h( 'div' );
				box.appendChild( App.card( t( 'AI usage this month' ), usage, { icon: 'chart' } ) );
				App.load( usage, SL.api( 'ai/usage' ), function ( u ) {
					usage.appendChild( h( 'div', { class: 'sl-kpis sl-kpis--sm' }, App.kpi( t( 'Spend (est.)' ), '$' + SL.num( u.month_spend, 2 ) ), App.kpi( t( 'Budget' ), u.budget ? '$' + SL.num( u.budget, 2 ) : t( 'unlimited' ) ), App.kpi( t( 'Requests (30d)' ), SL.num( u.requests_30d, 0 ) ) ) );
					usage.appendChild( App.table( [ { label: t( 'Task' ), key: 'task' }, { label: t( 'Requests' ), key: 'requests' }, { label: t( 'Tokens in/out' ), render: function ( r ) {
						return SL.num( r.tokens_in, 0 ) + ' / ' + SL.num( r.tokens_out, 0 );
					} }, { label: t( 'Cost' ), render: function ( r ) {
						return '$' + SL.num( r.cost, 3 );
					} } ], u.by_task ) );
				} );
			}
			if ( g[ 0 ] === 'github' ) {
				var st = h( 'div' );
				box.appendChild( App.card( t( 'GitHub data storage' ), st, { icon: 'database' } ) );
				App.load( st, SL.api( 'storage' ), function ( s ) {
					st.appendChild( h( 'p', { class: 'sl-small', text: t( 'Cold data (old Search Console rows, audit logs, reports, change history backups, crawl snapshots, config) is committed to your private GitHub repository and pruned locally, keeping your hosting database small. SEOlity reads it back when needed.' ) } ) );
					st.appendChild( h( 'div', { class: 'sl-kpis sl-kpis--sm' }, App.kpi( t( 'SEOlity tables size' ), s.db_size_mb === null ? '—' : SL.num( s.db_size_mb ) + ' MB' ), App.kpi( t( 'GSC rows (local)' ), SL.num( s.local_rows.gsc_page_query, 0 ) ), App.kpi( t( 'Last archive' ), s.last ? SL.date( new Date( s.last.time * 1000 ).toISOString() ) : '—', null, { sub: s.last ? ( s.last.ok ? t( '%s files', s.last.files ) : s.last.error ) : '' } ) ) );
					st.appendChild( h( 'div', { class: 'sl-toolbar' }, App.abtn( t( 'Test repository access' ), function () {
						return SL.api( 'storage/test', { body: { repo: data.settings.storage_repo } } ).then( function ( r ) {
							SL.toast( r.full_name + ' · ' + ( r.private ? t( 'private' ) : t( 'PUBLIC — use a private repo!' ) ) + ' · ' + ( r.can_push ? t( 'write access' ) : t( 'read only' ) ), r.private && r.can_push ? 'success' : 'error' );
						} );
					} ), App.abtn( t( 'Archive now' ), function () {
						return SL.api( 'storage/run', { method: 'POST' } ).then( function ( r ) {
							SL.toast( t( 'Archived %s files to GitHub.', r.files ), 'success' );
						} );
					} ) ) );
					var start = h( 'input', { class: 'sl-input sl-input--sm', type: 'date' } );
					var end = h( 'input', { class: 'sl-input sl-input--sm', type: 'date' } );
					st.appendChild( h( 'div', { class: 'sl-toolbar' }, h( 'span', { class: 'sl-small', text: t( 'Restore archived Search Console data:' ) } ), start, end, App.abtn( t( 'Restore' ), function () {
						return SL.api( 'storage/restore', { body: { start: start.value, end: end.value } } ).then( function ( r ) {
							SL.toast( t( 'Restored %1$s rows from %2$s days.', r.rows, r.days ), 'success' );
						} );
					} ) ) );
				} );
			}
			if ( g[ 0 ] === 'general' ) {
				box.appendChild( h( 'p', { class: 'sl-muted sl-small', text: t( 'SEO engine detected' ) + ': ' + ( App.state.boot ? App.state.boot.engine : '' ) + ' — ' + t( 'when another SEO plugin is active, SEOlity writes titles/descriptions into it and does not duplicate head tags.' ) } ) );
			}
			return box;
		}
	};
} )( window );
