/**
 * SEOlity admin pages for connections that run outside WordPress:
 *  - Subscription AI wizard (check → GitHub → Cloudflare → account → test),
 *  - GitHub & data (account, runner / knowledge / data repositories in one click),
 *  - Cloudflare relay (deploy the worker from here).
 * Long instructional texts are bilingual data (fa / en); UI chrome uses t().
 */
( function ( w, d ) {
	'use strict';
	var SL = w.SL;
	var App = SL.app;
	var C = SL.config || {};
	var t = SL.t;
	var h = SL.h;
	var FA = !! C.rtl;

	/** Persian or English text for the current admin language. */
	function T( fa, en ) {
		return FA ? fa : en;
	}
	/** Inline code chip. */
	function code( s ) {
		return h( 'code', { dir: 'ltr', text: s } );
	}
	function p() {
		return h.apply( null, [ 'p', null ].concat( [].slice.call( arguments ) ) );
	}
	function span() {
		return h.apply( null, [ 'span', null ].concat( [].slice.call( arguments ) ) );
	}

	var LINKS = {
		ghSignup: 'https://github.com/signup',
		ghClassic: 'https://github.com/settings/tokens/new?scopes=repo,workflow&description=SEOlity',
		ghFine: 'https://github.com/settings/personal-access-tokens/new',
		ghTokens: 'https://github.com/settings/tokens',
		ghNew: 'https://github.com/new',
		ghBilling: 'https://docs.github.com/en/billing/managing-billing-for-your-products/managing-billing-for-github-actions/about-billing-for-github-actions',
		ghPatDocs: 'https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens',
		node: 'https://nodejs.org/en/download',
		claudeToken: 'https://code.claude.com/docs/en/authentication#generate-a-long-lived-token',
		claudeActions: 'https://code.claude.com/docs/en/github-actions',
		codexRepo: 'https://github.com/openai/codex',
		codexAuth: 'https://github.com/openai/codex/blob/main/docs/authentication.md',
		cfSignup: 'https://dash.cloudflare.com/sign-up',
		cfTokens: 'https://dash.cloudflare.com/profile/api-tokens',
		cfTokenDocs: 'https://developers.cloudflare.com/fundamentals/api/get-started/create-token/',
		cfAccountDocs: 'https://developers.cloudflare.com/fundamentals/account/find-account-and-zone-ids/',
		cfPricing: 'https://developers.cloudflare.com/workers/platform/pricing/',
	};

	function faq( items ) {
		return h( 'div', { class: 'sl-faq' }, items.map( function ( x ) {
			return h( 'details', null, h( 'summary', { text: x[ 0 ] } ), h( 'div', null, x[ 1 ] ) );
		} ) );
	}
	function kv( rows ) {
		return h( 'div', { class: 'sl-kv' }, rows.filter( Boolean ).map( function ( r ) {
			return h( 'div', null, h( 'small', { text: r[ 0 ] } ), typeof r[ 1 ] === 'string' ? h( 'span', { dir: 'auto', text: r[ 1 ] } ) : r[ 1 ] );
		} ) );
	}
	function repoLink( repo, url ) {
		return url ? SL.xlink( url, repo ) : h( 'span', { text: repo || '—' } );
	}
	function scrollTo( id ) {
		var el = d.getElementById( id );
		if ( el ) {
			el.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			el.classList.add( 'is-flash' );
			setTimeout( function () {
				el.classList.remove( 'is-flash' );
			}, 1600 );
		}
	}

	/* ================================================================ GitHub token form (shared) */
	function githubConnect( st, onDone ) {
		var acct = st.token.account;
		var box = h( 'div', { class: 'sl-stack' } );
		if ( acct ) {
			box.appendChild( h( 'div', { class: 'sl-connected' },
				h( 'span', { class: 'sl-connected__ic' }, SL.icon( 'github', 22 ) ),
				h( 'div', null,
					h( 'strong', { dir: 'ltr', text: '@' + acct.login + ( acct.name ? ' · ' + acct.name : '' ) } ),
					h( 'div', { class: 'sl-row' },
						SL.badge( acct.type === 'fine-grained' ? T( 'توکن Fine-grained', 'Fine-grained token' ) : T( 'توکن کلاسیک', 'Classic token' ), 'info' ),
						( acct.scopes || [] ).map( function ( s ) {
							return SL.badge( s, 'neutral' );
						} ),
						acct.expires ? SL.badge( T( 'انقضا: ', 'Expires: ' ) + SL.date( acct.expires ), 'warn' ) : SL.badge( T( 'بدون تاریخ انقضا', 'No expiry date' ), 'neutral' )
					)
				)
			) );
			( st.token.warnings || [] ).forEach( function ( x ) {
				box.appendChild( h( 'div', { class: 'sl-alert sl-alert--warn sl-iconline' }, SL.icon( 'alert', 18 ), h( 'span', { text: x } ) ) );
			} );
		} else if ( st.token.error ) {
			box.appendChild( h( 'div', { class: 'sl-alert sl-alert--error sl-iconline' }, SL.icon( 'x-circle', 18 ), h( 'span', { text: st.token.error } ) ) );
		}
		var form = h( 'div', { class: 'sl-grid-2 sl-grid-2--top' } );
		var tok = SL.secretInput( acct ? T( '•••••• (ذخیره شده — برای تعویض، توکن جدید را وارد کنید)', '•••••• (saved — paste a new token to replace it)' ) : 'ghp_… / github_pat_…' );
		form.appendChild( h( 'div', null, SL.steps( [
			span( T( 'حساب GitHub ندارید؟ ', 'No GitHub account? ' ), SL.xlink( LINKS.ghSignup, T( 'رایگان بسازید', 'Create one for free' ) ), '.' ),
			span( SL.xlink( LINKS.ghClassic, T( 'این لینک را باز کنید', 'Open this link' ) ), T( ' — دسترسی‌های لازم (', ' — the required permissions (' ), code( 'repo' ), T( ' و ', ' and ' ), code( 'workflow' ), T( ') از قبل تیک خورده‌اند. فقط مدت اعتبار (Expiration) را انتخاب کنید (مثلاً ۹۰ روز یا No expiration) و پایین صفحه «Generate token» را بزنید.', ') are already ticked. Just choose an Expiration (e.g. 90 days or No expiration) and press “Generate token” at the bottom.' ) ),
			span( T( 'توکنی که با ', 'Copy the token that starts with ' ), code( 'ghp_' ), T( ' شروع می‌شود را همان لحظه کپی کنید (دوباره نمایش داده نمی‌شود) و اینجا بچسبانید.', ' right away (GitHub shows it only once) and paste it here.' ) ),
			T( 'SEOlity توکن را بررسی و رمزنگاری‌شده ذخیره می‌کند؛ از این به بعد مخزن‌ها را با یک کلیک خودش می‌سازد.', 'SEOlity checks the token and stores it encrypted; from then on it creates the repositories for you in one click.' ),
		] ), h( 'details', { class: 'sl-more' }, h( 'summary', { text: T( 'ترجیح می‌دهید توکن محدودتر (Fine-grained) بسازید؟', 'Prefer a more limited fine-grained token?' ) } ), SL.steps( [
			span( T( 'ابتدا در GitHub مخزن‌های خصوصی خالی را بسازید (', 'First create the empty private repositories on GitHub (' ), SL.xlink( LINKS.ghNew, 'github.com/new' ), T( ') با تیک «Add a README»؛ مثلاً ', ') with “Add a README” ticked, e.g. ' ), code( 'seolity-runner' ), '، ', code( 'seolity-data' ), '.' ),
			span( SL.xlink( LINKS.ghFine, T( 'ساخت توکن Fine-grained', 'Create a fine-grained token' ) ), T( ' ← Repository access: «Only select repositories» و همان مخزن‌ها.', ' → Repository access: “Only select repositories” and pick those repositories.' ) ),
			span( T( 'در Repository permissions این‌ها را «Read and write» کنید: ', 'Under Repository permissions set “Read and write” for: ' ), code( 'Contents' ), '، ', code( 'Actions' ), '، ', code( 'Secrets' ), '، ', code( 'Workflows' ), T( ' (Metadata خودکار Read می‌شود).', ' (Metadata becomes Read automatically).' ) ),
			T( 'توکن github_pat_… را اینجا بچسبانید. ساخت خودکار مخزن جدید با این نوع توکن ممکن نیست؛ نام مخزن‌هایی که ساختید را در کارت‌های پایین وارد کنید.', 'Paste the github_pat_… token here. Fine-grained tokens cannot create new repositories, so enter the names of the repositories you created in the cards below.' ),
		] ), p( SL.xlink( LINKS.ghPatDocs, T( 'مستندات رسمی توکن‌های GitHub', 'Official GitHub token documentation' ) ) ) ) ) );
		form.appendChild( h( 'div', { class: 'sl-stack sl-formcol' },
			h( 'label', { class: 'sl-label', text: T( 'توکن GitHub', 'GitHub token' ) } ), tok,
			App.abtn( SL.il( 'link', acct ? T( 'تعویض توکن و بررسی', 'Replace token & verify' ) : T( 'اتصال و بررسی توکن', 'Connect & verify token' ) ), function () {
				return SL.api( 'github/connect', { body: { token: tok.input.value } } ).then( function ( r ) {
					tok.input.value = '';
					SL.toast( T( 'GitHub متصل شد: @', 'GitHub connected: @' ) + r.token.account.login, 'success' );
					App.refreshBoot();
					onDone( r );
				} );
			}, 'primary' ),
			acct ? App.abtn( SL.il( 'x', T( 'قطع اتصال GitHub', 'Disconnect GitHub' ) ), function () {
				return new Promise( function ( resolve ) {
					SL.confirm( T( 'توکن GitHub از این سایت حذف شود؟ مخزن‌ها در GitHub باقی می‌مانند.', 'Remove the GitHub token from this site? Your repositories stay on GitHub.' ), function () {
						SL.api( 'github/disconnect', { method: 'POST' } ).then( function ( r ) {
							App.refreshBoot();
							onDone( r );
							resolve();
						} ).catch( function ( e ) {
							SL.fail( e );
							resolve();
						} );
					} );
				} );
			}, 'ghost' ) : null,
			h( 'p', { class: 'sl-small sl-muted sl-iconline' }, SL.icon( 'lock', 14 ), h( 'span', { text: T( 'توکن رمزنگاری‌شده ذخیره می‌شود و هرگز به مرورگر برنمی‌گردد.', 'The token is stored encrypted and never sent back to the browser.' ) } ) )
		) );
		box.appendChild( form );
		return box;
	}

	/* ================================================================ GitHub & data page */
	App.pages.github = function ( v ) {
		v.appendChild( App.header( t( 'GitHub & data' ), T( 'سرور رایگان و فضای خصوصی SEOlity در GitHub: اجرای هوش مصنوعی اشتراکی، پایگاه دانش اختصاصی و بایگانی داده‌ها. همه مخزن‌ها با یک کلیک ساخته و تنظیم می‌شوند.', 'SEOlity’s free servers and private storage on GitHub: the subscription AI runner, your own knowledge base and the data archive. Every repository is created and configured in one click.' ) ) );
		var body = h( 'div' );
		v.appendChild( body );
		function load( fresh ) {
			App.load( body, SL.api( 'github' + ( fresh ? '?fresh=1' : '' ) ), render );
		}
		function render( st ) {
			SL.clear( body );
			var connected = !! st.token.account;
			body.appendChild( h( 'div', { class: 'sl-flow' },
				[ [ 'github', T( 'حساب GitHub', 'GitHub account' ), T( 'یک توکن، یک بار', 'One token, once' ) ], [ 'server', T( 'مخزن اجراکننده', 'Runner repository' ), T( 'هوش مصنوعی اشتراکی', 'Subscription AI' ) ], [ 'book', T( 'مخزن دانش', 'Knowledge repository' ), T( 'دانش سئوی اختصاصی', 'Your SEO knowledge' ) ], [ 'database', T( 'مخزن داده', 'Data repository' ), T( 'دیتابیس سبک', 'A lighter database' ) ] ].map( function ( x, i ) {
					return [ i ? h( 'i', null, SL.icon( C.rtl ? 'arrow-left' : 'arrow-right', 16 ) ) : null, h( 'div', { class: 'sl-flow__step' }, h( 'span', null, SL.icon( x[ 0 ], 20 ) ), h( 'b', { text: x[ 1 ] } ), h( 'small', { text: x[ 2 ] } ) ) ];
				} )
			) );

			// Step 1 — account.
			body.appendChild( SL.wizStep( { n: 1, id: 'gh-account', done: connected, title: T( 'اتصال حساب GitHub', 'Connect your GitHub account' ), sub: T( 'فقط یک توکن لازم است؛ بقیه کارها خودکار انجام می‌شود.', 'All SEOlity needs is one token; everything else is automatic.' ), state: connected ? [ t( 'Connected' ), 'good' ] : [ t( 'Not set' ), 'neutral' ], body: githubConnect( st, render ) } ) );

			// Step 2 — repositories.
			var grid = h( 'div', { class: 'sl-repos' } );
			grid.appendChild( runnerCard( st, connected, render ) );
			grid.appendChild( knowledgeCard( st, connected, render ) );
			grid.appendChild( storageCard( st, connected, render ) );
			body.appendChild( SL.wizStep( { n: 2, id: 'gh-repos', done: !! ( st.runner.exists && st.runner.setup ) || !! ( st.storage.exists && st.storage.enabled ), title: T( 'مخزن‌ها (هر کدام با یک کلیک)', 'Repositories (one click each)' ), sub: T( 'هر کدام را که لازم دارید بسازید. همه خصوصی ساخته می‌شوند و نام پیش‌فرض قابل تغییر است.', 'Create the ones you need. All are created private; the default names can be changed.' ), body: grid } ) );

			// Advanced settings.
			body.appendChild( advancedCard() );

			// FAQ.
			body.appendChild( App.card( T( 'سؤالات متداول', 'Frequently asked questions' ), faq( [
				[ T( 'هزینه دارد؟', 'Does it cost anything?' ), p( T( 'خیر برای بیشتر سایت‌ها. حساب رایگان GitHub برای مخازن خصوصی سهمیه ماهانه دقیقه Actions دارد (هر درخواست اشتراکی حدود ۱ تا ۳ دقیقه). ذخیره داده‌ها هم فقط چند مگابایت در ماه است. ', 'Not for most sites. A free GitHub account includes monthly Actions minutes for private repositories (each subscription request takes about 1–3 minutes). Archived data is only a few MB per month. ' ), SL.xlink( LINKS.ghBilling, T( 'سهمیه رایگان GitHub Actions', 'GitHub Actions free quota' ) ) ) ],
				[ T( 'امنیت توکن و داده‌ها چطور است؟', 'How safe are my token and data?' ), p( T( 'توکن در وردپرس رمزنگاری‌شده است. همه مخازن خصوصی ساخته می‌شوند و اگر عمومی باشند SEOlity ادامه نمی‌دهد. توکن‌های اشتراک در GitHub Secrets (رمزنگاری‌شده توسط GitHub) قرار می‌گیرند و درخواست‌ها/پاسخ‌های هوش مصنوعی در مخزن با AES-256-GCM رمزنگاری می‌شوند.', 'The token is encrypted in WordPress. All repositories are created private and SEOlity refuses to use a public one. Subscription tokens go into GitHub Secrets (encrypted by GitHub) and AI requests/answers in the repository are AES-256-GCM encrypted.' ) ) ],
				[ T( 'توکن منقضی شد؛ چه کنم؟', 'My token expired — what now?' ), p( T( 'از همان لینک مرحله ۱ یک توکن جدید بسازید و «تعویض توکن» را بزنید. مخزن‌ها و تنظیمات دست نمی‌خورند.', 'Create a new token with the link in step 1 and press “Replace token”. Repositories and settings stay as they are.' ) ) ],
				[ T( 'می‌توانم از Organization استفاده کنم؟', 'Can I use an organization?' ), p( T( 'بله. نام مخزن را به‌شکل ', 'Yes. Enter the repository as ' ), code( 'org-name/seolity-runner' ), T( ' وارد کنید؛ توکن باید اجازه ساخت مخزن در آن Organization را داشته باشد.', '; the token must be allowed to create repositories in that organization.' ) ) ],
				[ T( 'چطور همه‌چیز را حذف کنم؟', 'How do I remove everything?' ), p( T( 'قطع اتصال را بزنید، سپس در GitHub ← Settings هر مخزن ← Delete this repository. توکن را هم از ', 'Press Disconnect, then delete each repository on GitHub (Settings → Delete this repository) and revoke the token at ' ), SL.xlink( LINKS.ghTokens, 'github.com/settings/tokens' ), T( ' باطل کنید.', '.' ) ) ],
			] ), { icon: 'info' } ) );
		}

		function advancedCard() {
			var box = h( 'div' );
			App.load( box, SL.api( 'settings' ), function ( data ) {
				var dirty = {};
				var LBL = { sub_branch: T( 'شاخه صف درخواست‌ها (اجراکننده)', 'Queue branch (runner)' ), kb_branch: T( 'شاخه دانش', 'Knowledge branch' ), kb_path: T( 'پوشه دانش', 'Knowledge folder' ), storage_branch: T( 'شاخه داده', 'Data branch' ), storage_path: T( 'پوشه داده', 'Data folder' ), storage_archive_days: T( 'بایگانی داده‌های قدیمی‌تر از (روز)', 'Archive data older than (days)' ), storage_prune_local: T( 'حذف ردیف‌های بایگانی‌شده از دیتابیس وردپرس', 'Delete archived rows from the WordPress database' ), kb_auto_sync: T( 'همگام‌سازی روزانه دانش', 'Sync knowledge daily' ), kb_contribute: T( 'ارسال یادگیری‌های ناشناس به مخزن دانش', 'Send anonymized learnings to the knowledge repository' ), storage_enabled: T( 'بایگانی خودکار داده‌ها در GitHub', 'Archive data to GitHub automatically' ) };
				var form = h( 'div', { class: 'sl-form' } );
				Object.keys( LBL ).forEach( function ( k ) {
					var def = data.schema[ k ];
					if ( ! def ) {
						return;
					}
					if ( def.type === 'bool' ) {
						form.appendChild( App.toggle( LBL[ k ], data.settings[ k ], function ( on ) {
							dirty[ k ] = on;
						} ) );
					} else {
						var el = h( 'input', { class: 'sl-input', dir: 'ltr', type: def.type === 'int' ? 'number' : 'text', value: data.settings[ k ] } );
						el.addEventListener( 'input', function () {
							dirty[ k ] = el.value;
						} );
						form.appendChild( App.field( LBL[ k ], el ) );
					}
				} );
				box.appendChild( form );
				box.appendChild( h( 'div', { class: 'sl-toolbar' }, App.abtn( SL.il( 'save', t( 'Save settings' ) ), function () {
					return SL.api( 'settings', { body: dirty } ).then( function () {
						dirty = {};
						SL.toast( t( 'Settings saved.' ), 'success' );
					} );
				}, 'primary' ) ) );
			} );
			var det = h( 'details', { class: 'sl-card sl-advanced' }, h( 'summary', null, SL.icon( 'settings', 16 ), h( 'span', { text: T( 'تنظیمات پیشرفته (شاخه‌ها، پوشه‌ها، بایگانی)', 'Advanced settings (branches, folders, archiving)' ) } ) ), h( 'div', { class: 'sl-card__body' }, box ) );
			return det;
		}
		load( false );
	};

	function repoState( r ) {
		if ( ! r.valid || ! r.exists ) {
			return [ T( 'ساخته نشده', 'Not created' ), 'neutral' ];
		}
		if ( r.private === false ) {
			return [ T( 'عمومی!', 'PUBLIC!' ), 'bad' ];
		}
		return [ T( 'آماده', 'Ready' ), 'good' ];
	}

	function repoCard( o ) {
		var name = h( 'input', { class: 'sl-input', dir: 'ltr', value: o.value || o.def, placeholder: o.def } );
		var state = o.state;
		return h( 'section', { class: 'sl-repo' + ( state[ 1 ] === 'good' ? ' is-ok' : '' ) },
			h( 'header', { class: 'sl-repo__head' }, h( 'span', { class: 'sl-card__ic' }, SL.icon( o.icon, 18 ) ), h( 'div', null, h( 'h4', { text: o.title } ), h( 'small', { text: o.purpose } ) ), SL.badge( state[ 0 ], state[ 1 ] ) ),
			o.top || null,
			o.hideName ? null : h( 'div', { class: 'sl-stack' }, h( 'label', { class: 'sl-label', text: T( 'نام مخزن (owner/name)', 'Repository (owner/name)' ) } ), name ),
			h( 'div', { class: 'sl-row' },
				o.action ? App.abtn( SL.il( o.done ? 'refresh' : 'rocket', o.done ? o.updateLabel : o.createLabel ), function () {
					return o.action( name.value.trim() );
				}, o.done ? '' : 'primary' ) : null,
				o.url ? SL.xlink( o.url, T( 'باز کردن در GitHub', 'Open on GitHub' ) ) : null,
				o.url && o.actionsLink ? SL.xlink( o.url + '/actions', 'Actions' ) : null
			),
			o.extra || null,
			o.manual ? h( 'details', { class: 'sl-more' }, h( 'summary', { text: T( 'راه‌اندازی دستی (قدم‌به‌قدم)', 'Manual setup (step by step)' ) } ), o.manual ) : null
		);
	}

	function runnerCard( st, connected, rerender ) {
		var r = st.runner;
		var done = !! ( r.exists && r.setup );
		return repoCard( {
			icon: 'server', title: T( 'مخزن اجراکننده هوش مصنوعی اشتراکی', 'Subscription AI runner' ),
			purpose: T( 'Claude Code یا Codex با اکانت Claude/ChatGPT شما روی GitHub Actions اجرا می‌شود.', 'Runs Claude Code or Codex with your Claude/ChatGPT plan on GitHub Actions.' ),
			state: done ? ( r.private === false ? [ T( 'عمومی!', 'PUBLIC!' ), 'bad' ] : [ T( 'نصب شده', 'Installed' ), 'good' ] ) : repoState( r ),
			value: r.repo, def: st.defaults.runner, url: r.exists ? r.url : '', actionsLink: true, done: done,
			createLabel: T( 'ساخت مخزن و نصب اجراکننده', 'Create repository & install runner' ), updateLabel: T( 'به‌روزرسانی فایل‌ها و Secrets', 'Update files & secrets' ),
			action: connected ? function ( repo ) {
				return SL.api( 'github/runner', { body: { repo: repo } } ).then( function ( res ) {
					SL.toast( T( 'اجراکننده آماده است.', 'The runner is ready.' ), 'success' );
					rerender( res );
				} );
			} : null,
			extra: h( 'div', { class: 'sl-stack' },
				done ? kv( [ [ T( 'نصب', 'Installed' ), SL.date( r.setup ) ], [ T( 'فایل‌ها', 'Files' ), r.files_current ? T( 'به‌روز', 'up to date' ) : T( 'در درخواست بعدی به‌روز می‌شوند', 'updated on the next request' ) ], [ 'Claude', r.claude ? T( 'توکن ثبت شده', 'token saved' ) : '—' ], [ 'ChatGPT', r.codex ? T( 'auth.json ثبت شده', 'auth.json saved' ) : '—' ] ] ) : null,
				h( 'a', { class: 'sl-btn sl-btn--sm sl-btn--ghost', href: '#/subscription' }, SL.il( 'sparkles', T( 'ادامه در «اکانت اشتراکی»', 'Continue in “Subscription AI”' ), 15 ) )
			),
			manual: SL.steps( [
				span( SL.xlink( LINKS.ghNew, T( 'یک مخزن جدید بسازید', 'Create a new repository' ) ), T( ': نام ', ': name ' ), code( 'seolity-runner' ), T( '، حالت ', ', visibility ' ), h( 'b', { text: 'Private' } ), T( '، تیک «Add a README file».', ', tick “Add a README file”.' ) ),
				T( 'نام کامل آن (owner/seolity-runner) را بالا وارد کنید و دکمه را بزنید؛ SEOlity فایل Workflow، اجراکننده، شاخه صف و Secrets را خودش اضافه می‌کند.', 'Enter its full name (owner/seolity-runner) above and press the button; SEOlity adds the workflow, the runner, the queue branch and the secrets itself.' ),
				span( T( 'اگر Actions غیرفعال است: مخزن ← Settings ← Actions ← General ← «Allow all actions».', 'If Actions is disabled: repository → Settings → Actions → General → “Allow all actions”.' ) ),
			] ),
		} );
	}

	function knowledgeCard( st, connected, rerender ) {
		var k = st.knowledge;
		var own = k.mode === 'own';
		var shareKey = h( 'input', { type: 'checkbox', checked: !! k.anthropic_key } );
		var docs = k.docs || {};
		var top = h( 'div', { class: 'sl-segcards' },
			h( 'button', { type: 'button', class: 'sl-segcard' + ( own ? '' : ' is-active' ), onClick: function () {
				if ( own ) {
					SL.confirm( T( 'به پایگاه دانش مشترک SEOlity برگردید؟ مخزن شما در GitHub باقی می‌ماند.', 'Switch back to the shared SEOlity knowledge base? Your repository stays on GitHub.' ), function () {
						SL.api( 'github/knowledge/shared', { method: 'POST' } ).then( rerender ).catch( SL.fail );
					} );
				}
			} }, h( 'b', { text: T( 'دانش مشترک SEOlity', 'Shared SEOlity knowledge' ) } ), h( 'small', { text: T( 'رایگان، فقط‌خواندنی، هر هفته به‌روز می‌شود', 'Free, read-only, updated weekly' ) } ) ),
			h( 'div', { class: 'sl-segcard' + ( own ? ' is-active' : '' ) }, h( 'b', { text: T( 'مخزن دانش اختصاصی شما', 'Your own knowledge repository' ) } ), h( 'small', { text: T( 'خصوصی؛ یادداشت‌ها، یادگیری‌ها و سازنده هفتگی خودتان', 'Private; your notes, learnings and your own weekly builder' ) } ) )
		);
		return repoCard( {
			icon: 'book', title: T( 'پایگاه دانش سئو', 'SEO knowledge base' ),
			purpose: T( 'بهترین روش‌های سئو که پشتوانه همه پاسخ‌های هوش مصنوعی است.', 'The best practices behind every AI answer.' ),
			state: own ? repoState( k ) : [ T( 'مشترک', 'Shared' ), 'info' ],
			value: own ? k.repo : '', def: st.defaults.knowledge, url: k.url, actionsLink: own, done: own && k.exists,
			top: top,
			createLabel: T( 'ساخت مخزن دانش اختصاصی', 'Create my knowledge repository' ), updateLabel: T( 'به‌روزرسانی فایل‌های دانش', 'Update knowledge files' ),
			action: connected ? function ( repo ) {
				return SL.api( 'github/knowledge', { body: { repo: repo, share_key: shareKey.checked } } ).then( function ( res ) {
					SL.toast( T( 'مخزن دانش آماده و همگام شد.', 'Knowledge repository ready and synced.' ), 'success' );
					rerender( res );
				} );
			} : null,
			extra: h( 'div', { class: 'sl-stack' },
				kv( [ [ T( 'اسناد', 'Documents' ), T( 'پیش‌فرض: ', 'bundled: ' ) + ( docs.bundled || 0 ) + ' · GitHub: ' + ( docs.github || 0 ) + ' · ' + T( 'یادداشت: ', 'notes: ' ) + ( docs.user || 0 ) ], [ T( 'آخرین همگام‌سازی', 'Last sync' ), k.last_sync && k.last_sync.time ? SL.date( new Date( k.last_sync.time * 1000 ).toISOString() ) : '—' ], own ? [ T( 'سازنده هفتگی', 'Weekly builder' ), k.builder_key ? T( 'کلید Anthropic در Secrets', 'Anthropic key in Secrets' ) : T( 'بدون کلید (فقط ذخیره یادداشت‌ها)', 'no key (stores notes only)' ) ] : null ] ),
				k.anthropic_key ? h( 'label', { class: 'sl-check' }, shareKey, h( 'span', { text: T( 'کلید Anthropic این سایت برای سازنده هفتگی دانش در Secrets مخزن ذخیره شود (هزینه هر اجرا از همان کلید کم می‌شود).', 'Store this site’s Anthropic key in the repository Secrets for the weekly knowledge builder (each run is billed to that key).' ) } ) ) : null,
				h( 'div', { class: 'sl-row' },
					App.abtn( SL.il( 'refresh', T( 'همگام‌سازی دانش', 'Sync knowledge' ), 15 ), function () {
						return SL.api( 'github/knowledge/sync', { method: 'POST' } ).then( function ( s ) {
							SL.toast( T( 'همگام شد: ', 'Synced: ' ) + ( s.github || 0 ) + ' GitHub · ' + ( s.bundled || 0 ) + ' ' + T( 'پیش‌فرض', 'bundled' ) + ( s.error ? ' · ' + s.error : '' ), s.error ? 'error' : 'success' );
						} );
					}, 'sm' ),
					own && k.anthropic_key && ! k.builder_key ? App.abtn( SL.il( 'key', T( 'ارسال کلید به سازنده', 'Give the builder my key' ), 15 ), function () {
						return SL.api( 'github/knowledge/key', { method: 'POST' } ).then( function () {
							SL.toast( T( 'کلید در Secrets ذخیره شد.', 'Key stored in Secrets.' ), 'success' );
						} );
					}, 'sm' ) : null,
					own ? App.abtn( SL.il( 'play', T( 'اجرای سازنده دانش', 'Run knowledge builder' ), 15 ), function () {
						return SL.api( 'github/knowledge/build', { method: 'POST' } ).then( function () {
							SL.toast( T( 'سازنده در GitHub Actions شروع شد.', 'The builder started on GitHub Actions.' ), 'success' );
						} );
					}, 'sm' ) : null
				)
			),
			manual: SL.steps( [
				T( 'دکمه بالا یک مخزن خصوصی می‌سازد و پوشه‌های knowledge، سازنده دانش و Workflow هفتگی را در آن قرار می‌دهد، سپس SEOlity را به آن وصل می‌کند.', 'The button creates a private repository with the knowledge folders, the knowledge builder and a weekly workflow, then switches SEOlity to it.' ),
				span( T( 'برای یادگیری هفتگی خودکار، در مخزن ← Settings ← Secrets and variables ← Actions یک Secret با نام ', 'For weekly self-learning add a repository secret (Settings → Secrets and variables → Actions) named ' ), code( 'ANTHROPIC_API_KEY' ), T( ' بسازید (یا تیک بالا را بزنید).', ' (or tick the box above).' ) ),
				span( T( 'منابع دلخواه را در ', 'Add your own sources in ' ), code( 'seolity/tools/knowledge-builder/sources.json' ), T( ' اضافه کنید.', '.' ) ),
				T( 'یادداشت‌هایی که در «پایگاه دانش» با گزینه «ارسال به GitHub» ذخیره می‌کنید در همین مخزن قرار می‌گیرند.', 'Notes you save in “Knowledge Base” with “Push to GitHub” are stored in this repository.' ),
			] ),
		} );
	}

	function storageCard( st, connected, rerender ) {
		var s = st.storage;
		var a = s.archive || {};
		var done = !! ( s.exists && s.enabled );
		var start = h( 'input', { class: 'sl-input sl-input--sm', type: 'date' } );
		var end = h( 'input', { class: 'sl-input sl-input--sm', type: 'date' } );
		return repoCard( {
			icon: 'database', title: T( 'مخزن داده (بایگانی)', 'Data repository (archive)' ),
			purpose: T( 'داده‌های قدیمی سرچ کنسول، گزارش‌ها و تاریخچه تغییرات به GitHub می‌روند تا دیتابیس هاست سبک بماند.', 'Old Search Console rows, reports and change history move to GitHub so your hosting database stays small.' ),
			state: done ? ( s.private === false ? [ T( 'عمومی!', 'PUBLIC!' ), 'bad' ] : [ T( 'فعال', 'Active' ), 'good' ] ) : repoState( s ),
			value: s.repo, def: st.defaults.storage, url: s.exists ? s.url : '', done: done,
			createLabel: T( 'ساخت مخزن و فعال‌سازی بایگانی', 'Create repository & enable archive' ), updateLabel: T( 'بررسی دوباره دسترسی', 'Check access again' ),
			action: connected ? function ( repo ) {
				return SL.api( 'github/storage', { body: { repo: repo } } ).then( function ( res ) {
					SL.toast( T( 'بایگانی داده فعال شد.', 'Data archive enabled.' ), 'success' );
					rerender( res );
				} );
			} : null,
			extra: h( 'div', { class: 'sl-stack' },
				kv( [ [ T( 'حجم جداول SEOlity', 'SEOlity tables size' ), a.db_size_mb === null || a.db_size_mb === undefined ? '—' : SL.num( a.db_size_mb ) + ' MB' ], [ T( 'ردیف‌های سرچ کنسول (محلی)', 'GSC rows (local)' ), SL.num( a.local_rows ? a.local_rows.gsc_page_query : 0, 0 ) ], [ T( 'آخرین بایگانی', 'Last archive' ), a.last ? SL.date( new Date( a.last.time * 1000 ).toISOString() ) + ( a.last.ok ? ' · ' + a.last.files + T( ' فایل', ' files' ) : ' · ' + ( a.last.error || '' ) ) : '—' ] ] ),
				done ? h( 'div', { class: 'sl-row' }, App.abtn( SL.il( 'upload', T( 'بایگانی همین حالا', 'Archive now' ), 15 ), function () {
					return SL.api( 'storage/run', { method: 'POST' } ).then( function ( r ) {
						SL.toast( T( 'فایل‌های بایگانی‌شده: ', 'Files archived: ' ) + ( r.files || 0 ), 'success' );
					} );
				}, 'sm' ) ) : null,
				done ? h( 'div', { class: 'sl-row' }, h( 'span', { class: 'sl-small', text: T( 'بازیابی داده سرچ کنسول:', 'Restore Search Console data:' ) } ), start, end, App.abtn( SL.il( 'download', T( 'بازیابی', 'Restore' ), 15 ), function () {
					return SL.api( 'storage/restore', { body: { start: start.value, end: end.value } } ).then( function ( r ) {
						SL.toast( T( 'ردیف‌های بازیابی‌شده: ', 'Rows restored: ' ) + r.rows, 'success' );
					} );
				}, 'sm' ) ) : null
			),
			manual: SL.steps( [
				span( SL.xlink( LINKS.ghNew, T( 'یک مخزن خصوصی بسازید', 'Create a private repository' ) ), T( ' (مثلاً ', ' (e.g. ' ), code( 'seolity-data' ), T( ') با README.', ') with a README.' ) ),
				T( 'نام کامل را بالا وارد و دکمه را بزنید: SEOlity یک فایل تست می‌نویسد و بایگانی روزانه را روشن می‌کند.', 'Enter the full name above and press the button: SEOlity writes a test file and turns on the daily archive.' ),
				T( 'هر سایت پوشه خودش را دارد (نام دامنه)، پس یک مخزن برای چند سایت هم کافی است.', 'Each site writes to its own folder (its domain), so one repository can serve several sites.' ),
			] ),
		} );
	}

	/* ================================================================ Cloudflare relay page */
	App.pages.cloudflare = function ( v ) {
		v.appendChild( App.header( t( 'Cloudflare relay' ), T( 'ورکر رایگان کلادفلر روی حساب خودتان، مستقیماً از همین صفحه ساخته و به‌روز می‌شود: پاسخ‌های هوش مصنوعی اشتراکی سریع‌تر می‌رسند (حتی برای سایت لوکال) و صف پس‌زمینه SEOlity سر وقت اجرا می‌شود.', 'A free Cloudflare worker on your own account, deployed and updated from this page: subscription AI answers arrive faster (even on a local site) and SEOlity’s background queue runs on time.' ) ) );
		var body = h( 'div' );
		v.appendChild( body );
		function load() {
			App.load( body, SL.api( 'cloudflare' ), render );
		}
		function render( st ) {
			SL.clear( body );
			body.appendChild( h( 'div', { class: 'sl-flow' },
				[ [ 'server', 'GitHub Actions', T( 'پاسخ آماده می‌شود', 'the answer is ready' ) ], [ 'cloud', T( 'ورکر کلادفلر', 'Cloudflare worker' ), T( 'صندوق پیام + زمان‌بند', 'mailbox + scheduler' ) ], [ 'globe', T( 'سایت شما', 'Your site' ), st.mode === 'push' ? T( 'پاسخ فوراً تحویل می‌شود', 'gets it instantly' ) : T( 'پاسخ را برمی‌دارد', 'collects it' ) ] ].map( function ( x, i ) {
					return [ i ? h( 'i', null, SL.icon( C.rtl ? 'arrow-left' : 'arrow-right', 16 ) ) : null, h( 'div', { class: 'sl-flow__step' + ( i === 1 ? ' is-accent' : '' ) }, h( 'span', null, SL.icon( x[ 0 ], 20 ) ), h( 'b', { text: x[ 1 ] } ), h( 'small', { text: x[ 2 ] } ) ) ];
				} )
			) );
			body.appendChild( h( 'div', { class: 'sl-alert sl-alert--info sl-iconline' }, SL.icon( 'info', 18 ), h( 'span', { text: T( 'اختیاری است. بدون کلادفلر هم همه‌چیز کار می‌کند (پاسخ‌ها مستقیم یا از GitHub دریافت می‌شوند). پلن رایگان کلادفلر کافی است.', 'Optional. Everything works without Cloudflare too (answers arrive directly or are read from GitHub). The free Cloudflare plan is enough.' ) } ) ) );
			if ( ! st.site_public ) {
				body.appendChild( h( 'div', { class: 'sl-alert sl-alert--warn sl-iconline' }, SL.icon( 'alert', 18 ), h( 'span', { text: T( 'آدرس این سایت از اینترنت در دسترس نیست (لوکال یا شبکه خصوصی). حالت «اتصال معکوس» خودکار انتخاب می‌شود: وردپرس خودش پاسخ‌ها را از ورکر برمی‌دارد.', 'This site’s address is not reachable from the internet (local or private network). “Pull” mode is chosen automatically: WordPress collects answers from the worker itself.' ) } ) ) );
			}

			// Step 1 — account.
			var tok = SL.secretInput( st.token ? T( '•••••• (ذخیره شده)', '•••••• (saved)' ) : T( 'توکن API کلادفلر', 'Cloudflare API token' ) );
			var acc = h( 'input', { class: 'sl-input', dir: 'ltr', value: st.account_id, placeholder: T( '۳۲ کاراکتر (خودکار پیدا می‌شود)', '32 characters (found automatically)' ) } );
			var accList = h( 'div' );
			var s1 = h( 'div', { class: 'sl-grid-2 sl-grid-2--top' },
				h( 'div', null, SL.steps( [
					span( T( 'حساب کلادفلر ندارید؟ ', 'No Cloudflare account? ' ), SL.xlink( LINKS.cfSignup, T( 'رایگان بسازید', 'Sign up for free' ) ), T( ' (دامنه لازم نیست).', ' (no domain needed).' ) ),
					span( SL.xlink( LINKS.cfTokens, T( 'صفحه API Tokens', 'API Tokens page' ) ), T( ' را باز کنید ← «Create Token» ← کنار قالب «Edit Cloudflare Workers» دکمه «Use template» را بزنید.', ' → “Create Token” → next to the “Edit Cloudflare Workers” template press “Use template”.' ) ),
					span( T( 'در Account Resources حساب خودتان و در Zone Resources گزینه «All zones» را بگذارید ← «Continue to summary» ← «Create Token».', 'Under Account Resources pick your account and under Zone Resources “All zones” → “Continue to summary” → “Create Token”.' ) ),
					T( 'توکن را کپی و اینجا بچسبانید؛ Account ID خودکار پیدا می‌شود.', 'Copy the token and paste it here; the Account ID is found automatically.' ),
				] ), h( 'details', { class: 'sl-more' }, h( 'summary', { text: T( 'ترجیح می‌دهید توکن سفارشی با کمترین دسترسی بسازید؟', 'Prefer a custom token with the minimum permissions?' ) } ), SL.steps( [
					T( '«Create Custom Token» را بزنید و در بخش Permissions (Account) این‌ها را اضافه کنید:', 'Press “Create Custom Token” and add these Account permissions:' ),
					span( code( 'Workers Scripts : Edit' ), ' · ', code( 'Workers KV Storage : Edit' ), ' · ', code( 'Account Settings : Read' ) ),
					span( T( 'اختیاری برای استفاده از مدل‌های رایگان Workers AI در «API سفارشی»: ', 'Optional, to use the free Workers AI models in “Custom API”: ' ), code( 'Workers AI : Read' ) ),
					T( 'Account Resources: حساب خودتان ← Create Token.', 'Account Resources: your account → Create Token.' ),
				] ), p( SL.xlink( LINKS.cfTokenDocs, T( 'مستندات ساخت توکن', 'Token documentation' ) ), ' · ', SL.xlink( LINKS.cfAccountDocs, T( 'پیدا کردن Account ID', 'Find your Account ID' ) ) ) ) ),
				h( 'div', { class: 'sl-stack sl-formcol' },
					h( 'label', { class: 'sl-label', text: 'API Token' } ), tok,
					h( 'label', { class: 'sl-label', text: 'Account ID' } ), h( 'div', { class: 'sl-row' }, acc, st.token ? App.abtn( SL.il( 'search', T( 'یافتن', 'Find' ), 15 ), function () {
						return SL.api( 'cloudflare/accounts' ).then( function ( list ) {
							SL.clear( accList );
							( list || [] ).forEach( function ( a ) {
								accList.appendChild( h( 'button', { type: 'button', class: 'sl-chip', onClick: function () {
									acc.value = a.id;
								} }, a.name + ' · ' + a.id.slice( 0, 6 ) + '…' ) );
							} );
						} );
					}, 'sm' ) : null ), accList,
					App.abtn( SL.il( 'shield', T( 'ذخیره و بررسی توکن', 'Save & verify token' ) ), function () {
						return SL.api( 'cloudflare/connect', { body: { token: tok.input.value, account_id: acc.value } } ).then( function ( r ) {
							SL.toast( T( 'کلادفلر متصل شد.', 'Cloudflare connected.' ), 'success' );
							render( r );
						} );
					}, 'primary' )
				)
			);
			body.appendChild( SL.wizStep( { n: 1, done: st.configured, title: T( 'اتصال حساب کلادفلر', 'Connect your Cloudflare account' ), state: st.configured ? [ t( 'Connected' ), 'good' ] : [ t( 'Not set' ), 'neutral' ], body: s1 } ) );

			// Step 2 — deploy.
			var settingsBox = h( 'div' );
			App.load( settingsBox, SL.api( 'settings' ), function ( data ) {
				var dirty = {};
				var mode = App.select( [ [ 'auto', T( 'خودکار (پیشنهادی)', 'Automatic (recommended)' ) ], [ 'push', T( 'مستقیم: ورکر به سایت وصل می‌شود', 'Push: the worker calls the site' ) ], [ 'pull', T( 'معکوس: سایت به ورکر وصل می‌شود', 'Pull: the site calls the worker' ) ] ], data.settings.cf_mode, function ( x ) {
					dirty.cf_mode = x;
				} );
				var cron = h( 'input', { class: 'sl-input', type: 'number', min: 5, max: 60, value: data.settings.cf_cron_minutes } );
				cron.addEventListener( 'input', function () {
					dirty.cf_cron_minutes = cron.value;
				} );
				var custom = h( 'input', { class: 'sl-input', dir: 'ltr', value: data.settings.cf_custom_url, placeholder: 'https://relay.example.com' } );
				custom.addEventListener( 'input', function () {
					dirty.cf_custom_url = custom.value;
				} );
				var wpapi = h( 'input', { class: 'sl-input', dir: 'ltr', value: data.settings.cf_wp_api, placeholder: st.wp_api } );
				wpapi.addEventListener( 'input', function () {
					dirty.cf_wp_api = wpapi.value;
				} );
				settingsBox.appendChild( h( 'div', { class: 'sl-form' },
					App.field( T( 'حالت اتصال', 'Connection mode' ), mode, T( 'خودکار: اگر آدرس سایت عمومی باشد «مستقیم»، وگرنه «معکوس». مستقیم زمان‌بند دقیق (Cron) هم اضافه می‌کند.', 'Automatic: “push” when the site address is public, otherwise “pull”. Push also adds a reliable scheduler (Cron).' ) ),
					App.field( T( 'هر چند دقیقه صف SEOlity اجرا شود', 'Run SEOlity’s queue every … minutes' ), cron ),
					App.field( T( 'دامنه اختصاصی ورکر (اختیاری)', 'Custom worker domain (optional)' ), custom, T( 'اگر workers.dev در شبکه سرور شما در دسترس نیست، در کلادفلر یک Custom Domain به ورکر وصل کنید و اینجا بنویسید.', 'If workers.dev is not reachable from your server’s network, attach a Custom Domain to the worker in Cloudflare and enter it here.' ) ),
					App.field( T( 'آدرس REST سایت برای ورکر (اختیاری)', 'Site REST address for the worker (optional)' ), wpapi, T( 'فقط اگر عیب‌یابی گفت (مثلاً نسخه با www یا ?rest_route=).', 'Only if diagnostics tells you to (e.g. the www version or ?rest_route=).' ) )
				) );
				settingsBox._save = function () {
					return Object.keys( dirty ).length ? SL.api( 'settings', { body: dirty } ).then( function () {
						dirty = {};
					} ) : Promise.resolve();
				};
			} );
			var diag = h( 'div' );
			var s2 = h( 'div', { class: 'sl-stack' },
				st.deployed ? kv( [ [ T( 'آدرس ورکر', 'Worker address' ), h( 'code', { dir: 'ltr', text: st.url } ) ], [ T( 'حالت', 'Mode' ), st.mode === 'push' ? T( 'مستقیم', 'Push' ) : T( 'معکوس', 'Pull' ) ], [ T( 'زمان‌بند', 'Scheduler' ), st.cron ? h( 'code', { dir: 'ltr', text: st.cron } ) : T( 'خاموش (حالت معکوس)', 'off (pull mode)' ) ], [ T( 'آخرین ضربان', 'Last heartbeat' ), st.last_heartbeat ? SL.date( st.last_heartbeat ) : '—' ], [ T( 'آخرین دریافت', 'Last pull' ), st.last_pull ? SL.date( st.last_pull ) : '—' ], [ T( 'نسخه', 'Version' ), st.version || '—' ] ] ) : null,
				st.outdated ? h( 'div', { class: 'sl-alert sl-alert--warn', text: T( 'ورکر با نسخه قدیمی‌تر افزونه مستقر شده است؛ «استقرار دوباره» را بزنید.', 'The worker was deployed by an older plugin version; press “Deploy again”.' ) } ) : null,
				st.cron_error ? h( 'div', { class: 'sl-alert sl-alert--warn', text: st.cron_error } ) : null,
				settingsBox,
				h( 'div', { class: 'sl-row' },
					App.abtn( SL.il( 'rocket', st.deployed ? T( 'استقرار دوباره', 'Deploy again' ) : T( 'استقرار ورکر (حدود ۳۰ ثانیه)', 'Deploy worker (about 30 seconds)' ) ), function () {
						return ( settingsBox._save ? settingsBox._save() : Promise.resolve() ).then( function () {
							return SL.api( 'cloudflare/deploy', { method: 'POST' } );
						} ).then( function ( r ) {
							SL.toast( T( 'ورکر مستقر شد: ', 'Worker deployed: ' ) + r.url, 'success' );
							App.refreshBoot();
							render( r );
						} );
					}, 'primary', { disabled: ! st.configured } ),
					st.deployed ? App.abtn( SL.il( 'activity', T( 'عیب‌یابی اتصال', 'Diagnose connection' ) ), function () {
						return SL.api( 'cloudflare/diagnose', { method: 'POST' } ).then( function ( r ) {
							SL.clear( diag );
							var ok = r.status && r.status < 400;
							diag.appendChild( h( 'div', { class: 'sl-alert sl-alert--' + ( ok ? 'success' : 'error' ) },
								h( 'strong', { text: ok ? T( 'کلادفلر به سایت شما دسترسی دارد.', 'Cloudflare can reach your site.' ) : T( 'کلادفلر به سایت شما دسترسی ندارد.', 'Cloudflare cannot reach your site.' ) } ),
								h( 'div', { class: 'sl-small', dir: 'ltr', text: ( r.wp_api || '' ) + ' → ' + ( r.status ? 'HTTP ' + r.status : r.error || '' ) + ( r.ms ? ' · ' + r.ms + 'ms' : '' ) } ),
								ok ? null : h( 'p', { class: 'sl-small', text: T( 'اگر سایت لوکال است حالت «معکوس» را انتخاب کنید. اگر فایروال/امنیت هاست درخواست‌های خارجی به /wp-json را می‌بندد، آن را مجاز کنید یا حالت معکوس را بزنید.', 'If the site is local choose “Pull” mode. If a firewall/security plugin blocks outside requests to /wp-json, allow them or use pull mode.' ) } )
							) );
						} );
					} ) : null,
					st.deployed ? App.abtn( SL.il( 'download', T( 'دریافت پاسخ‌ها همین حالا', 'Collect answers now' ) ), function () {
						return SL.api( 'cloudflare/pull', { method: 'POST' } ).then( function ( r ) {
							SL.toast( T( 'پاسخ‌های دریافت‌شده: ', 'Answers collected: ' ) + r.stored );
						} );
					} ) : null
				),
				diag
			);
			body.appendChild( SL.wizStep( { n: 2, done: st.deployed, title: T( 'استقرار ورکر', 'Deploy the worker' ), sub: T( 'فضای KV، کلید امضا، زمان‌بند و آدرس workers.dev خودکار ساخته می‌شوند.', 'The KV namespace, signing key, scheduler and workers.dev address are created automatically.' ), state: st.deployed ? [ T( 'فعال', 'Active' ), 'good' ] : null, body: s2 } ) );

			// Step 3 — runner link.
			body.appendChild( SL.wizStep( { n: 3, done: st.runner_relay, title: T( 'اتصال به اجراکننده اشتراکی', 'Linked to the subscription runner' ), body: h( 'div', { class: 'sl-stack' },
				p( st.runner_relay ? T( 'اجراکننده GitHub پاسخ‌ها را به این ورکر می‌فرستد.', 'The GitHub runner sends its answers to this worker.' ) : T( 'بعد از استقرار، آدرس ورکر خودکار در Secrets مخزن اجراکننده ثبت می‌شود. اگر هنوز اجراکننده را نساخته‌اید، از «اکانت اشتراکی» شروع کنید.', 'After deploying, the worker address is stored in the runner repository’s secrets automatically. If you have not created the runner yet, start in “Subscription AI”.' ) ),
				h( 'div', { class: 'sl-row' }, h( 'a', { class: 'sl-btn sl-btn--sm', href: '#/subscription' }, SL.il( 'sparkles', t( 'Subscription AI' ), 15 ) ), st.deployed ? App.abtn( SL.il( 'trash', T( 'حذف ورکر', 'Remove worker' ), 15 ), function () {
					return new Promise( function ( resolve ) {
						SL.confirm( T( 'ورکر از کلادفلر حذف شود؟', 'Remove the worker from Cloudflare?' ), function () {
							SL.api( 'cloudflare/remove', { method: 'POST' } ).then( function ( r ) {
								render( r );
								resolve();
							} ).catch( function ( e ) {
								SL.fail( e );
								resolve();
							} );
						} );
					} );
				}, 'sm' ) : null )
			) } ) );

			// Manual deploy + FAQ.
			body.appendChild( h( 'details', { class: 'sl-card sl-advanced' }, h( 'summary', null, SL.icon( 'terminal', 16 ), h( 'span', { text: T( 'استقرار دستی با Wrangler (اختیاری)', 'Manual deploy with Wrangler (optional)' ) } ) ), h( 'div', { class: 'sl-card__body' }, SL.steps( [
				span( T( 'فایل ورکر را دانلود کنید: ', 'Download the worker: ' ), SL.xlink( ( C.pluginUrl || '' ) + 'cloudflare/worker.js', 'worker.js' ) ),
				span( T( 'یک KV با نام دلخواه بسازید و با نام ', 'Create a KV namespace and bind it as ' ), code( 'SEOLITY_KV' ), T( ' به ورکر وصل کنید. متغیرهای ', '. Set the variables ' ), code( 'WP_API' ), ' = ', code( st.wp_api ), '، ', code( 'MODE' ), ' = ', code( st.mode ), T( ' را تنظیم کنید.', '.' ) ),
				span( T( 'کلید امضا را با این دستور به‌عنوان Secret ثبت کنید (مقدار را از «اکانت اشتراکی ← پیشرفته» نمی‌توان دید؛ استقرار خودکار را ترجیح دهید):', 'Store the signing key as a secret (prefer the automatic deploy — the key is never shown in the browser):' ), SL.cmd( 'npx wrangler secret put SEOLITY_SHARED_SECRET' ) ),
				span( T( 'آدرس ورکر را در «دامنه اختصاصی ورکر» بالا وارد و ذخیره کنید.', 'Enter the worker address in “Custom worker domain” above and save.' ) ),
			] ) ) ) );
			body.appendChild( App.card( T( 'سؤالات متداول', 'Frequently asked questions' ), faq( [
				[ T( 'هزینه دارد؟', 'Does it cost anything?' ), p( T( 'خیر. پلن رایگان کلادفلر روزانه ۱۰۰ هزار درخواست ورکر دارد و SEOlity در روز چند صد درخواست هم مصرف نمی‌کند. ', 'No. Cloudflare’s free plan includes 100,000 worker requests per day; SEOlity uses a few hundred at most. ' ), SL.xlink( LINKS.cfPricing, T( 'محدودیت‌های پلن رایگان', 'Free plan limits' ) ) ) ],
				[ T( 'چه داده‌ای از ورکر عبور می‌کند؟', 'What data passes through the worker?' ), p( T( 'فقط پاسخ‌های رمزنگاری‌شده (AES-256-GCM) هوش مصنوعی که کلادفلر نمی‌تواند بخواند، و یک درخواست امضاشده «اجرای صف» به سایت. توکن‌ها و کلیدهای شما به کلادفلر نمی‌رود؛ فقط کلید امضا در Secrets ورکر است.', 'Only AI answers encrypted with AES-256-GCM (Cloudflare cannot read them) and a signed “run the queue” request to your site. Your tokens and keys never go to Cloudflare; only the signing key is stored in the worker’s secrets.' ) ) ],
				[ T( 'مستقیم یا معکوس؟', 'Push or pull?' ), p( T( 'مستقیم: ورکر پاسخ را همان لحظه به سایت می‌دهد و هر چند دقیقه صف SEOlity را اجرا می‌کند (برای سایت‌های کم‌بازدید عالی است). معکوس: برای سایت لوکال یا پشت فایروال؛ وردپرس هنگام باز بودن پنل یا اجرای کران، پاسخ‌ها را برمی‌دارد.', 'Push: the worker hands answers to the site immediately and runs SEOlity’s queue every few minutes (great for low-traffic sites). Pull: for local or firewalled sites; WordPress collects answers while the admin is open or when cron runs.' ) ) ],
				[ T( 'خطای 1016 یا عدم دسترسی در عیب‌یابی', 'Error 1016 or unreachable in diagnostics' ), p( T( 'DNS دامنه شما از دید کلادفلر حل نمی‌شود یا هاست درخواست را می‌بندد. آدرس REST را با/بدون www امتحان کنید یا حالت معکوس را بزنید.', 'Your domain’s DNS does not resolve from Cloudflare or your host blocks the request. Try the REST address with/without www, or use pull mode.' ) ) ],
			] ), { icon: 'info' } ) );
		}
		load();
	};

	/* ================================================================ Subscription AI wizard */
	App.pages.subscription = function ( v, q ) {
		v.appendChild( App.header( t( 'Subscription AI' ), T( 'برای کسانی که اکانت Claude Pro/Max یا ChatGPT Plus/Pro دارند ولی API پولی نمی‌خواهند: همان اکانت، همه امکانات هوش مصنوعی SEOlity را اجرا می‌کند.', 'For people with a Claude Pro/Max or ChatGPT Plus/Pro plan who don’t want a paid API: that same plan runs every SEOlity AI feature.' ) ) );
		var body = h( 'div' );
		v.appendChild( body );
		var engine = q.engine === 'codex' ? 'codex' : null;
		function load() {
			App.load( body, Promise.all( [ SL.api( 'subscription' ), SL.api( 'github' ), SL.api( 'cloudflare' ) ] ), function ( r ) {
				if ( ! engine ) {
					engine = r[ 0 ].provider === 'sub_codex' || ( ! r[ 0 ].claude_token && r[ 0 ].codex_auth ) ? 'codex' : 'claude';
				}
				render( r[ 0 ], r[ 1 ], r[ 2 ] );
			} );
		}
		function render( sub, gh, cf ) {
			SL.clear( body );
			// Hero.
			body.appendChild( h( 'section', { class: 'sl-card sl-hero' }, h( 'div', { class: 'sl-card__body' },
				h( 'h2', { text: T( 'با همان اکانت چتی که خریده‌اید، سئو کنید', 'Do your SEO with the chat plan you already pay for' ) } ),
				p( T( 'SEOlity به‌جای API پولی از ابزارهای رسمی خود سازنده‌ها (Claude Code و OpenAI Codex) استفاده می‌کند که ورود با اکانت اشتراکی را پشتیبانی می‌کنند. این ابزارها روی سرورهای رایگان GitHub Actions در مخزن خصوصی خودتان اجرا می‌شوند.', 'Instead of a paid API, SEOlity uses the vendors’ official tools (Claude Code and OpenAI Codex), which support signing in with a subscription. They run on free GitHub Actions servers in your own private repository.' ) ),
				h( 'div', { class: 'sl-row' }, [ [ 'check-circle', T( 'بدون هزینه اضافه', 'No extra cost' ), 'good' ], [ 'shield', T( 'روش رسمی سازنده‌ها', 'Official vendor tools' ), 'good' ], [ 'key', T( 'بدون نیاز به API', 'No API needed' ), 'info' ], [ 'lock', T( 'رمزنگاری سرتاسری', 'End-to-end encrypted' ), 'info' ] ].map( function ( b ) {
					return h( 'span', { class: 'sl-badge sl-badge--' + b[ 2 ] + ' sl-iconline' }, SL.icon( b[ 0 ], 13 ), h( 'span', { text: b[ 1 ] } ) );
				} ) ),
				h( 'div', { class: 'sl-flow' }, [ [ 'globe', T( 'وردپرس', 'WordPress' ), T( 'درخواست رمزنگاری‌شده', 'encrypted request' ) ], [ 'server', T( 'GitHub Actions (رایگان)', 'GitHub Actions (free)' ), T( 'ماشین موقت', 'temporary machine' ) ], [ 'sparkles', T( 'اکانت شما', 'Your plan' ), engine === 'codex' ? 'Codex · ChatGPT' : 'Claude Code · Claude' ], [ 'cloud', T( 'کلادفلر (اختیاری)', 'Cloudflare (optional)' ), T( 'تحویل سریع', 'fast delivery' ) ], [ 'review', T( 'وردپرس', 'WordPress' ), T( 'پاسخ آماده', 'answer ready' ) ] ].map( function ( x, i ) {
					return [ i ? h( 'i', null, SL.icon( C.rtl ? 'arrow-left' : 'arrow-right', 16 ) ) : null, h( 'div', { class: 'sl-flow__step' + ( i === 2 ? ' is-accent' : '' ) }, h( 'span', null, SL.icon( x[ 0 ], 20 ) ), h( 'b', { text: x[ 1 ] } ), h( 'small', { text: x[ 2 ] } ) ) ];
				} ) )
			) ) );

			// Account pick.
			var pick = function ( id, mono, color, title, desc, saved ) {
				return h( 'button', { type: 'button', class: 'sl-acct' + ( engine === id ? ' is-picked' : '' ), onClick: function () {
					engine = id;
					render( sub, gh, cf );
				} }, h( 'span', { class: 'sl-mono', style: { '--c': color }, text: mono } ), h( 'div', null, h( 'b', { text: title } ), h( 'small', { text: desc } ), h( 'span', { class: 'sl-acct__state sl-iconline' }, SL.icon( saved ? 'check-circle' : 'key', 14, saved ? 'is-good' : '' ), h( 'span', { text: saved ? T( 'ثبت شده', 'saved' ) : T( 'ثبت نشده', 'not saved' ) } ) ) ) );
			};
			body.appendChild( h( 'div', { class: 'sl-accts' },
				pick( 'claude', 'CL', '#d97757', 'Claude Pro / Max', T( 'پیشنهادی · روش رسمی Anthropic برای GitHub Actions با توکن یک‌ساله · فارسی‌نویسی عالی', 'Recommended · Anthropic’s official GitHub Actions method with a one-year token · excellent Persian' ), sub.claude_token ),
				pick( 'codex', 'GPT', '#10a37f', 'ChatGPT Plus / Pro', T( 'از طریق OpenAI Codex با ورود ChatGPT · تمدید ورود خودکار انجام می‌شود', 'Through OpenAI Codex with ChatGPT sign-in · the login is refreshed automatically' ), sub.codex_auth )
			) );

			// Step 0 — readiness check.
			var checkBox = h( 'div' );
			function runCheck() {
				return SL.api( 'subscription/check?engine=' + engine ).then( function ( c ) {
					SL.clear( checkBox );
					checkBox.appendChild( SL.checklist( c.checks, function ( fix ) {
						scrollTo( { github: 'sub-github', runner: 'sub-github', cloudflare: 'sub-cloudflare', account: 'sub-account', activate: 'sub-test' }[ fix ] || 'sub-test' );
					} ) );
					checkBox.appendChild( h( 'div', { class: 'sl-alert sl-alert--' + ( c.ready ? 'success' : 'info' ) + ' sl-iconline' }, SL.icon( c.ready ? 'check-circle' : 'info', 18 ), h( 'span', { text: c.ready ? T( 'همه پیش‌نیازها آماده است. به مرحله ۴ بروید و تست کنید.', 'Everything is ready. Go to step 4 and run the test.' ) : T( 'موارد قرمز را به ترتیب انجام دهید؛ دکمه «رفع» شما را به همان مرحله می‌برد.', 'Fix the red items in order; “Fix” takes you to the right step.' ) } ) ) );
				} );
			}
			body.appendChild( SL.wizStep( { n: 0, id: 'sub-check', title: T( 'بررسی پیش‌نیازها', 'Check the prerequisites' ), sub: T( 'قبل از هر کاری، SEOlity همه چیز را بررسی می‌کند و می‌گوید کدام مرحله مانده است.', 'Before anything else, SEOlity checks everything and tells you which step is left.' ), body: h( 'div', { class: 'sl-stack' }, checkBox, h( 'div', { class: 'sl-row' }, App.abtn( SL.il( 'refresh', T( 'بررسی دوباره', 'Check again' ), 15 ), runCheck, 'sm' ) ) ) } ) );
			checkBox.appendChild( SL.spinner( T( 'در حال بررسی…', 'Checking…' ) ) );
			runCheck().catch( function ( e ) {
				SL.clear( checkBox ).appendChild( h( 'div', { class: 'sl-alert sl-alert--error', text: e.message } ) );
			} );

			// Step 1 — GitHub.
			var ghConnected = !! gh.token.account;
			var runnerName = h( 'input', { class: 'sl-input', dir: 'ltr', value: sub.repo || gh.defaults.runner } );
			var s1 = h( 'div', { class: 'sl-stack' },
				ghConnected ? null : githubConnect( gh, function () {
					load();
				} ),
				ghConnected ? h( 'div', { class: 'sl-connected' }, h( 'span', { class: 'sl-connected__ic' }, SL.icon( 'github', 20 ) ), h( 'div', null, h( 'strong', { dir: 'ltr', text: '@' + gh.token.account.login } ), h( 'small', { class: 'sl-muted' }, ' · ', h( 'a', { href: '#/github' }, T( 'مدیریت در «GitHub و داده»', 'Manage in “GitHub & data”' ) ) ) ) ) : null,
				h( 'div', { class: 'sl-grid-2 sl-grid-2--top' },
					SL.steps( [
						T( 'نام مخزن خصوصی را تأیید کنید (پیش‌فرض مناسب است).', 'Confirm the private repository name (the default is fine).' ),
						T( 'دکمه را بزنید: مخزن خصوصی ساخته می‌شود، فایل اجراکننده و Workflow در آن قرار می‌گیرد، شاخه صف ساخته می‌شود و کلیدها در GitHub Secrets ذخیره می‌شوند.', 'Press the button: the private repository is created, the runner and workflow are added, the queue branch is created and keys are stored in GitHub Secrets.' ),
						T( 'هر بار افزونه به‌روز شود، فایل‌های اجراکننده هم خودکار به‌روز می‌شوند.', 'Whenever the plugin updates, the runner files update automatically.' ),
					] ),
					h( 'div', { class: 'sl-stack sl-formcol' }, h( 'label', { class: 'sl-label', text: T( 'مخزن خصوصی اجراکننده', 'Private runner repository' ) } ), runnerName,
						App.abtn( SL.il( sub.setup ? 'refresh' : 'rocket', sub.setup ? T( 'نصب دوباره / به‌روزرسانی', 'Reinstall / update' ) : T( 'ساخت مخزن و نصب اجراکننده', 'Create repository & install runner' ) ), function () {
							return SL.api( 'github/runner', { body: { repo: runnerName.value.trim() } } ).then( function () {
								SL.toast( T( 'اجراکننده آماده است.', 'The runner is ready.' ), 'success' );
								load();
							} );
						}, 'primary', { disabled: ! ghConnected } ),
						sub.repo_url ? h( 'div', { class: 'sl-row' }, SL.xlink( sub.repo_url, sub.repo ), SL.xlink( sub.repo_url + '/actions', 'Actions' ) ) : null
					)
				)
			);
			body.appendChild( SL.wizStep( { n: 1, id: 'sub-github', done: ghConnected && !! sub.setup, title: T( 'اتصال GitHub و ساخت مخزن اجراکننده', 'Connect GitHub and create the runner repository' ), sub: T( 'سرور رایگانی که کار را انجام می‌دهد.', 'The free server that does the work.' ), body: s1 } ) );

			// Step 2 — Cloudflare (optional).
			body.appendChild( SL.wizStep( { n: 2, id: 'sub-cloudflare', optional: true, done: cf.deployed, title: T( 'کلادفلر (اختیاری، پیشنهادی)', 'Cloudflare (optional, recommended)' ), state: cf.deployed ? [ T( 'فعال', 'Active' ), 'good' ] : [ T( 'اختیاری', 'Optional' ), 'neutral' ], body: h( 'div', { class: 'sl-stack' },
				p( cf.site_public ? T( 'سایت شما عمومی است؛ پاسخ‌ها بدون کلادفلر هم مستقیم می‌رسند. با کلادفلر سریع‌تر و مطمئن‌تر می‌رسند و صف پس‌زمینه سر وقت اجرا می‌شود.', 'Your site is public, so answers arrive directly without Cloudflare too. With Cloudflare they arrive faster and more reliably, and the background queue runs on time.' ) : T( 'سایت شما از اینترنت در دسترس نیست (لوکال). بدون کلادفلر پاسخ‌ها هر ۳۰ ثانیه از GitHub خوانده می‌شوند؛ با ورکر رایگان کلادفلر سریع‌تر می‌رسند.', 'Your site is not reachable from the internet (local). Without Cloudflare answers are read from GitHub every 30 seconds; the free Cloudflare worker makes this faster.' ) ),
				cf.deployed ? kv( [ [ T( 'ورکر', 'Worker' ), h( 'code', { dir: 'ltr', text: cf.url } ) ], [ T( 'حالت', 'Mode' ), cf.mode === 'push' ? T( 'مستقیم', 'Push' ) : T( 'معکوس', 'Pull' ) ] ] ) : null,
				h( 'a', { class: 'sl-btn sl-btn--sm', href: '#/cloudflare' }, SL.il( 'cloud', cf.deployed ? T( 'مدیریت کلادفلر', 'Manage Cloudflare' ) : T( 'راه‌اندازی کلادفلر (۳ دقیقه)', 'Set up Cloudflare (3 minutes)' ), 15 ) )
			) } ) );

			// Step 3 — account.
			body.appendChild( accountStep( sub, ghConnected && !! sub.setup ) );

			// Step 4 — test & activate.
			var out = h( 'div' );
			var active = sub.provider === ( engine === 'codex' ? 'sub_codex' : 'sub_claude' );
			body.appendChild( SL.wizStep( { n: 4, id: 'sub-test', done: active, title: T( 'تست و فعال‌سازی', 'Test and activate' ), sub: T( 'یک درخواست کوچک واقعی فرستاده می‌شود؛ اولین پاسخ معمولاً ۱ تا ۳ دقیقه طول می‌کشد.', 'A small real request is sent; the first answer usually takes 1–3 minutes.' ), body: h( 'div', { class: 'sl-stack' },
				h( 'div', { class: 'sl-row' },
					App.abtn( SL.il( 'zap', T( 'ارسال درخواست تست', 'Send a test request' ) ), function () {
						return SL.api( 'subscription/test', { body: { engine: engine } } ).then( function ( r ) {
							SL.clear( out );
							out.appendChild( h( 'div', { class: 'sl-alert sl-alert--success sl-iconline' }, SL.icon( 'check-circle', 18 ), h( 'span', { text: T( 'کار می‌کند! مدل: ', 'It works! Model: ' ) + ( r.model || '' ) } ) ) );
						} );
					}, 'primary' ),
					active ? SL.badge( T( 'موتور هوش مصنوعی فعلی', 'Current AI engine' ), 'good' ) : App.abtn( SL.il( 'check', T( 'استفاده به‌عنوان موتور هوش مصنوعی', 'Use as AI engine' ) ), function () {
						return SL.api( 'subscription/activate', { body: { engine: engine } } ).then( function () {
							SL.toast( T( 'موتور هوش مصنوعی تغییر کرد.', 'AI engine switched.' ), 'success' );
							App.refreshBoot();
							load();
						} );
					} ),
					App.abtn( SL.il( 'play', T( 'روشن کردن اجراکننده', 'Start runner now' ), 15 ), function () {
						return SL.api( 'subscription/run', { method: 'POST' } ).then( function () {
							SL.toast( T( 'اجرا در GitHub شروع شد.', 'Run started on GitHub.' ), 'success' );
						} );
					}, 'sm' )
				),
				out,
				runsTable( sub )
			) } ) );

			// Costs + FAQ.
			body.appendChild( h( 'div', { class: 'sl-grid-2' },
				App.card( T( 'هزینه و محدودیت‌ها', 'Costs and limits' ), h( 'table', { class: 'sl-info' }, h( 'tbody', null, [
					[ T( 'هوش مصنوعی', 'AI' ), T( 'از سهمیه همان اشتراکی که خریده‌اید (سهمیه چت)', 'From the plan you already pay for (your chat quota)' ) ],
					[ 'GitHub Actions', T( 'رایگان در سهمیه ماهانه؛ هر درخواست حدود ۱ تا ۳ دقیقه', 'Free within the monthly quota; about 1–3 minutes per request' ) ],
					[ T( 'کلادفلر', 'Cloudflare' ), T( 'پلن رایگان (اختیاری)', 'Free plan (optional)' ) ],
					[ T( 'تمدید', 'Renewal' ), T( 'Claude: سالی یک‌بار claude setup-token · ChatGPT: خودکار', 'Claude: run claude setup-token once a year · ChatGPT: automatic' ) ],
					[ T( 'پیشنهاد', 'Tip' ), T( 'کارهای گروهی سنگین را کم‌کم انجام دهید تا سهمیه چت شما آزاد بماند', 'Run heavy bulk jobs gradually so your chat quota stays free' ) ],
				].map( function ( r ) {
					return h( 'tr', null, h( 'th', { text: r[ 0 ] } ), h( 'td', { text: r[ 1 ] } ) );
				} ) ) ), { icon: 'info' } ),
				App.card( T( 'سؤالات متداول', 'Frequently asked questions' ), faq( [
					[ T( 'آیا این روش مجاز است؟', 'Is this allowed?' ), p( T( 'بله. Anthropic در مستند رسمی GitHub Actions ورود با توکن اشتراک (CLAUDE_CODE_OAUTH_TOKEN برای Pro و Max) را پشتیبانی می‌کند و OpenAI هم ورود Codex با اکانت ChatGPT را مستند کرده است. هیچ اسکرپینگی انجام نمی‌شود. ', 'Yes. Anthropic’s official GitHub Actions docs support signing in with a subscription token (CLAUDE_CODE_OAUTH_TOKEN for Pro and Max), and OpenAI documents signing in to Codex with a ChatGPT account. Nothing is scraped. ' ), SL.xlink( LINKS.claudeActions, 'Claude Code GitHub Actions' ), ' · ', SL.xlink( LINKS.codexAuth, 'Codex authentication' ) ) ],
					[ T( 'توکن من کجا ذخیره می‌شود؟', 'Where is my token stored?' ), p( T( 'رمزنگاری‌شده در وردپرس و در Secrets مخزن خصوصی GitHub شما. مخزن حتماً خصوصی است و درخواست‌ها و پاسخ‌ها هم رمزنگاری می‌شوند.', 'Encrypted in WordPress and in the Secrets of your private GitHub repository. The repository is always private and requests/answers are encrypted too.' ) ) ],
					[ T( 'اگر سهمیه اشتراک تمام شود؟', 'What if my plan’s quota runs out?' ), p( T( 'SEOlity پیام «سقف استفاده اشتراک» را نشان می‌دهد؛ صبر کنید تا سهمیه آزاد شود یا موقتاً ارائه‌دهنده دیگری انتخاب کنید.', 'SEOlity reports “subscription usage limit reached”; wait for the quota to reset or switch provider for a while.' ) ) ],
					[ T( 'روی چت روزمره‌ام اثر دارد؟', 'Does it affect my daily chat?' ), p( T( 'مصرف مشترک است. هر درخواست تقریباً مثل یک پیام در claude.ai یا ChatGPT حساب می‌شود.', 'The quota is shared. Each request counts roughly like one message in claude.ai or ChatGPT.' ) ) ],
					[ T( 'اجرا در GitHub شروع نشد؟', 'The GitHub run did not start?' ), p( T( 'جدول اجراها یا صفحه Actions مخزن را ببینید. رایج‌ترین دلایل: توکن GitHub بدون دسترسی workflow، Actions غیرفعال در تنظیمات مخزن، یا توکن اشتراک منقضی/ناقص. «بررسی پیش‌نیازها» دقیقاً می‌گوید کدام است.', 'Look at the runs table or the repository’s Actions page. Common causes: a GitHub token without the workflow permission, Actions disabled in the repository settings, or an expired/incomplete subscription token. “Check the prerequisites” tells you which.' ) ) ],
					[ T( 'چطور کاملاً متوقفش کنم؟', 'How do I stop it completely?' ), p( T( 'ارائه‌دهنده دیگری را در «تنظیمات ← هوش مصنوعی» انتخاب کنید. برای حذف کامل، مخزن اجراکننده را در GitHub پاک کنید و در Claude/ChatGPT نشست‌های فعال را لغو کنید.', 'Choose another provider in Settings → AI. To remove everything, delete the runner repository on GitHub and revoke the sessions in Claude/ChatGPT.' ) ) ],
				] ), { icon: 'book' } )
			) );
			if ( q.step ) {
				setTimeout( function () {
					scrollTo( 'sub-' + q.step );
				}, 200 );
			}
		}

		function accountStep( sub, runnerReady ) {
			var isClaude = engine === 'claude';
			var os = w.navigator && /Win/i.test( w.navigator.platform || '' ) ? 'win' : 'unix';
			var cmdBox = h( 'div' );
			function cmds() {
				SL.clear( cmdBox );
				var win = os === 'win';
				var list = isClaude ? [
					span( SL.xlink( LINKS.node, 'Node.js' ), T( ' (نسخه LTS) را روی کامپیوتر خودتان نصب کنید.', ' (LTS) on your own computer.' ) ),
					span( T( win ? 'PowerShell را باز کنید و Claude Code را نصب کنید:' : 'Terminal را باز کنید و Claude Code را نصب کنید:', win ? 'Open PowerShell and install Claude Code:' : 'Open Terminal and install Claude Code:' ), SL.cmd( 'npm install -g @anthropic-ai/claude-code' ) ),
					span( T( 'این دستور را بزنید؛ مرورگر باز می‌شود. با همان اکانت Claude Pro/Max وارد شوید و «Authorize» را بزنید:', 'Run this; a browser opens. Sign in with your Claude Pro/Max account and press “Authorize”:' ), SL.cmd( 'claude setup-token' ) ),
					span( T( 'توکنی که در ترمینال چاپ می‌شود و با ', 'Copy the token printed in the terminal (it starts with ' ), code( 'sk-ant-oat01-' ), T( ' شروع می‌شود را کامل کپی کنید. این توکن یک سال اعتبار دارد و جایی ذخیره نمی‌شود.', ') completely. It is valid for one year and is not saved anywhere else.' ) ),
				] : [
					span( SL.xlink( LINKS.node, 'Node.js' ), T( ' را نصب کنید، سپس Codex را نصب کنید:', ', then install Codex:' ), SL.cmd( 'npm install -g @openai/codex' ) ),
					span( T( 'یک‌بار تنظیم کنید که ورود در فایل ذخیره شود (نه در Keychain سیستم):', 'Once, make Codex store the login in a file (not the system keychain):' ), SL.cmd( win ? [ 'mkdir $env:USERPROFILE\\.codex -Force', "Add-Content $env:USERPROFILE\\.codex\\config.toml 'cli_auth_credentials_store = \"file\"'" ] : [ 'mkdir -p ~/.codex', "echo 'cli_auth_credentials_store = \"file\"' >> ~/.codex/config.toml" ] ) ),
					span( T( 'وارد شوید و گزینه «Sign in with ChatGPT» را انتخاب کنید:', 'Sign in and choose “Sign in with ChatGPT”:' ), SL.cmd( 'codex login' ) ),
					span( T( 'فایل اعتبارنامه را باز کنید و کل محتوا را (از { تا }) کپی کنید:', 'Open the credentials file and copy everything (from { to }):' ), SL.cmd( win ? 'notepad $env:USERPROFILE\\.codex\\auth.json' : 'cat ~/.codex/auth.json' ) ),
					T( 'بعد از ذخیره در SEOlity، فایل auth.json را از کامپیوتر پاک کنید (logout نزنید). هر نسخه بهتر است فقط روی یک سیستم استفاده شود؛ SEOlity نسخه تمدیدشده را خودش نگه می‌دارد.', 'After saving it in SEOlity, delete auth.json from your computer (do not log out). Each copy should be used on one machine only; SEOlity keeps the refreshed copy itself.' ),
				];
				cmdBox.appendChild( SL.steps( list ) );
			}
			cmds();
			var osTabs = h( 'div', { class: 'sl-seg' }, [ [ 'win', 'Windows' ], [ 'unix', 'macOS / Linux' ] ].map( function ( o ) {
				return h( 'button', { type: 'button', class: os === o[ 0 ] ? 'is-active' : '', onClick: function ( e ) {
					os = o[ 0 ];
					Array.prototype.forEach.call( e.currentTarget.parentNode.children, function ( b ) {
						b.classList.toggle( 'is-active', b === e.currentTarget );
					} );
					cmds();
				} }, o[ 1 ] );
			} ) );
			var saved = isClaude ? sub.claude_token : sub.codex_auth;
			var secret = SL.secretInput( saved ? T( '•••••• (ذخیره شده — برای تعویض، مقدار جدید را وارد کنید)', '•••••• (saved — paste a new value to replace it)' ) : ( isClaude ? 'sk-ant-oat01-…' : '{"auth_mode":"chatgpt","tokens":{…}}' ), ! isClaude );
			var model;
			if ( isClaude ) {
				var cur = ( sub.models && sub.models.claude ) || 'sonnet';
				model = h( 'div', { class: 'sl-seg' }, [ [ 'sonnet', T( 'Sonnet (پیشنهادی)', 'Sonnet (recommended)' ) ], [ 'opus', T( 'Opus (قوی‌تر، سهمیه بیشتر)', 'Opus (stronger, uses more quota)' ) ], [ 'haiku', T( 'Haiku (سریع)', 'Haiku (fast)' ) ] ].map( function ( o ) {
					return h( 'button', { type: 'button', class: cur === o[ 0 ] ? 'is-active' : '', 'data-v': o[ 0 ], onClick: function ( e ) {
						Array.prototype.forEach.call( e.currentTarget.parentNode.children, function ( b ) {
							b.classList.toggle( 'is-active', b === e.currentTarget );
						} );
					} }, o[ 1 ] );
				} ) );
				model.value = function () {
					var a = model.querySelector( '.is-active' );
					return a ? a.getAttribute( 'data-v' ) : 'sonnet';
				};
			} else {
				var mi = h( 'input', { class: 'sl-input', dir: 'ltr', value: ( sub.models && sub.models.codex ) || '', placeholder: T( 'خالی = مدل پیش‌فرض پلن شما', 'empty = your plan’s default model' ) } );
				model = h( 'div', null, mi );
				model.value = function () {
					return mi.value.trim();
				};
			}
			var form = h( 'div', { class: 'sl-stack sl-formcol' },
				h( 'label', { class: 'sl-label', text: isClaude ? T( 'توکن اشتراک Claude', 'Claude subscription token' ) : T( 'محتوای کامل auth.json', 'Entire auth.json' ) } ), secret,
				h( 'label', { class: 'sl-label', text: T( 'مدل', 'Model' ) } ), model,
				App.abtn( SL.il( 'lock', T( 'ذخیره امن در GitHub Secrets', 'Save securely to GitHub Secrets' ) ), function () {
					return SL.api( 'subscription/account', { body: { engine: engine, value: secret.input.value, model: model.value() } } ).then( function () {
						SL.toast( T( 'ذخیره شد و در Secrets اجراکننده قرار گرفت.', 'Saved and stored in the runner’s secrets.' ), 'success' );
						load();
					} );
				}, 'primary' ),
				runnerReady ? null : h( 'p', { class: 'sl-small sl-muted sl-iconline' }, SL.icon( 'info', 14 ), h( 'span', { text: T( 'اگر مرحله ۱ هنوز انجام نشده، توکن اینجا ذخیره می‌شود و هنگام نصب اجراکننده به GitHub منتقل می‌شود.', 'If step 1 is not done yet, the token is saved here and copied to GitHub when the runner is installed.' ) } ) )
			);
			return SL.wizStep( { n: 3, id: 'sub-account', done: saved, title: isClaude ? T( 'اتصال اکانت Claude Pro / Max', 'Connect your Claude Pro / Max account' ) : T( 'اتصال اکانت ChatGPT Plus / Pro', 'Connect your ChatGPT Plus / Pro account' ), sub: T( 'فقط یک‌بار روی کامپیوتر خودتان انجام می‌شود.', 'Done once on your own computer.' ), body: h( 'div', { class: 'sl-grid-2 sl-grid-2--top' },
				h( 'div', null, osTabs, cmdBox, h( 'p', { class: 'sl-small sl-muted sl-iconline' }, SL.icon( 'book', 14 ), span( T( 'مرجع رسمی: ', 'Official reference: ' ), isClaude ? [ SL.xlink( LINKS.claudeToken, 'claude setup-token' ), ' · ', SL.xlink( LINKS.claudeActions, 'Claude Code GitHub Actions' ) ] : [ SL.xlink( LINKS.codexAuth, 'Codex authentication' ), ' · ', SL.xlink( LINKS.codexRepo, 'openai/codex' ) ] ) ) ),
				form
			) } );
		}

		function runsTable( sub ) {
			var counts = sub.requests_24h || {};
			var box = h( 'div', { class: 'sl-stack' },
				h( 'p', { class: 'sl-small sl-muted', text: T( '۲۴ ساعت اخیر: ', 'Last 24 hours: ' ) + T( 'انجام‌شده ', 'done ' ) + ( counts.done || 0 ) + ' · ' + T( 'در انتظار ', 'waiting ' ) + ( ( counts.queued || 0 ) + ( counts.submitted || 0 ) ) + ' · ' + T( 'ناموفق ', 'failed ' ) + ( counts.failed || 0 ) } )
			);
			if ( Array.isArray( sub.runs ) && sub.runs.length ) {
				box.appendChild( App.table( [ { label: T( 'اجرا', 'Run' ), render: function ( r ) {
					return SL.xlink( r.url, '#' + r.id );
				} }, { label: t( 'Status' ), render: function ( r ) {
					return SL.badge( r.conclusion || r.status, r.conclusion === 'success' ? 'good' : ( r.conclusion === 'failure' ? 'bad' : 'default' ) );
				} }, { label: T( 'شروع', 'Started' ), render: function ( r ) {
					return SL.date( r.created_at );
				} } ], sub.runs ) );
			} else if ( sub.runs && sub.runs.error ) {
				box.appendChild( h( 'div', { class: 'sl-alert sl-alert--error', text: sub.runs.error } ) );
			}
			return box;
		}
		load();
	};
} )( window, document );
