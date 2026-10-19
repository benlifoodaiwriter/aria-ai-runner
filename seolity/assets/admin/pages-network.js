/**
 * SEOlity "Server & speed" page: where the host is (Iran / abroad), how AI and Google
 * requests travel (direct, Cloudflare, GitHub), a live speed test, the warm GitHub runner
 * and the GitHub data storage that keeps the host database small.
 * Long explanations are bilingual data (fa / en); UI chrome uses t().
 */
( function ( w, d ) {
	'use strict';
	var SL = w.SL;
	var App = SL.app;
	var C = SL.config || {};
	var t = SL.t;
	var h = SL.h;
	var FA = !! C.rtl;

	function T( fa, en ) {
		return FA ? fa : en;
	}
	function secs( ms ) {
		if ( ms === null || ms === undefined ) {
			return '—';
		}
		return ms < 1000 ? SL.num( ms, 0 ) + ' ms' : SL.num( ms / 1000, ms < 10000 ? 1 : 0 ) + ' s';
	}

	var ROUTES = {
		direct: { icon: 'zap', title: function () {
			return t( 'Direct' );
		}, text: function () {
			return T( 'سرور شما خودش به هوش مصنوعی وصل می‌شود و هیچ پیامی به گیت‌هاب نمی‌رود. کلید API مستقیم صدا زده می‌شود؛ اشتراک Claude/ChatGPT با ابزار رسمی (CLI) روی همین سرور اجرا می‌شود. سریع‌ترین راه، وقتی سرور اجازه دارد (معمولاً سرورهای خارج از ایران).', 'Your server talks to the AI itself and nothing goes to GitHub. An API key is called directly; a Claude/ChatGPT subscription runs with the official CLI on this server. The fastest way when the server is allowed to (usually servers outside Iran).' );
		} },
		cloudflare: { icon: 'cloud', title: function () {
			return 'Cloudflare';
		}, text: function () {
			return T( 'ورکر رایگان کلادفلر خودتان درخواست را می‌فرستد و جواب را فوراً برمی‌گرداند (معمولاً کمتر از یک ثانیه تأخیر). بهترین گزینه برای ایران.', 'Your own free Cloudflare worker sends the request and returns the answer at once (usually under a second of extra time). The best option for Iran.' );
		}, setup: 'cloudflare' },
		github: { icon: 'github', title: function () {
			return 'GitHub';
		}, text: function () {
			return T( 'رانر خصوصی گیت‌هاب شما درخواست را اجرا می‌کند. اگر رانر گرم باشد چند ثانیه، وگرنه حدود ۴۰ ثانیه طول می‌کشد. وقتی کلادفلر در دسترس نیست، پشتیبان مطمئنی است.', 'Your private GitHub runner sends the request. A few seconds when the runner is warm, about 40 seconds when GitHub has to start a machine. A reliable backup when Cloudflare is not reachable.' );
		}, setup: 'github' },
	};

	App.pages.network = function ( v ) {
		v.appendChild( App.header( t( 'Server & speed' ), T( 'مشخص کنید هاست شما کجاست تا SEOlity پیام‌های هوش مصنوعی و سرویس‌های گوگل را از سریع‌ترین مسیری که کار می‌کند بفرستد، و داده‌ها را در گیت‌هاب نگه دارد تا فضای هاست پر نشود.', 'Tell SEOlity where your host is, so AI messages and Google services take the fastest route that works, and data lives on GitHub instead of filling your host.' ) ) );
		var box = h( 'div', { class: 'sl-stack' } );
		v.appendChild( box );
		function load() {
			App.load( box, Promise.all( [ SL.api( 'network' ), SL.api( 'storage' ).catch( function () {
				return null;
			} ) ] ), function ( r ) {
				render( r[ 0 ], r[ 1 ] );
			} );
		}
		var cur = null;
		// Quiet saves (route, toggles) update the cards in place; only a region change re-tests and reloads.
		function save( values, probe ) {
			return SL.api( 'network', { body: values } ).then( function ( st ) {
				if ( probe ) {
					return SL.api( 'network/probe', { method: 'POST' } ).then( load );
				}
				SL.toast( t( 'Saved' ), 'success' );
				refresh( st );
			} ).catch( function ( e ) {
				SL.toast( e.message, 'error' );
				if ( cur ) {
					refresh( cur ); // Undo the optimistic selection.
				}
			} );
		}
		function swap( node, next ) {
			if ( node && node.parentNode ) {
				node.parentNode.replaceChild( next, node );
			}
			return next;
		}
		var routeNode = null;
		var localNode = null;
		function refresh( st ) {
			cur = st;
			routeNode = swap( routeNode, routeCard( st, save ) );
			localNode = swap( localNode, localCard( st, save ) );
		}
		function render( st, storage ) {
			cur = st;
			routeNode = routeCard( st, save );
			localNode = localCard( st, save );
			box.appendChild( regionCard( st, save ) );
			box.appendChild( routeNode );
			box.appendChild( localNode );
			box.appendChild( speedCard( st, load ) );
			box.appendChild( runnerCard( st, save, load ) );
			if ( storage ) {
				box.appendChild( storageCard( storage, load ) );
			}
		}
		load();
	};

	/* ------------------------------------------------------------------ 1. Where is the host? */
	function regionCard( st, save ) {
		var pick = function ( id, icon, title, sub ) {
			var on = st.region_set && st.region === id;
			return h( 'button', { type: 'button', class: 'sl-choice' + ( on ? ' is-on' : '' ), 'aria-pressed': on ? 'true' : 'false', onClick: function () {
				save( { host_region: id }, true );
			} },
				h( 'span', { class: 'sl-choice__ic' }, SL.icon( icon, 22 ) ),
				h( 'span', { class: 'sl-choice__text' }, h( 'b', { text: title } ), h( 'small', { text: sub } ) ),
				on ? h( 'span', { class: 'sl-choice__check' }, SL.icon( 'check', 16 ) ) : null
			);
		};
		var body = h( 'div', { class: 'sl-stack' },
			! st.region_set ? h( 'div', { class: 'sl-alert sl-alert--warn' }, SL.icon( 'info', 16 ), h( 'span', { text: T( 'هنوز انتخاب نکرده‌اید. حدس ما: ', 'Not chosen yet. Our guess: ' ) + ( st.guess === 'iran' ? T( 'ایران', 'Iran' ) : T( 'خارج از ایران', 'outside Iran' ) ) + T( ' — لطفاً یکی را انتخاب کنید.', ' — please pick one.' ) } ) ) : null,
			h( 'div', { class: 'sl-choices' },
				pick( 'iran', 'map', T( 'ایران', 'Iran' ), T( 'هاست در دیتاسنتر ایران است', 'The host is in a data center in Iran' ) ),
				pick( 'abroad', 'globe', T( 'خارج از ایران', 'Outside Iran' ), T( 'مثلاً آلمان، هلند، فنلاند، آمریکا', 'e.g. Germany, the Netherlands, Finland, the US' ) )
			),
			h( 'details', { class: 'sl-explain', open: st.region === 'iran' ? '' : null },
				h( 'summary', { text: T( 'چرا این مهم است؟', 'Why does this matter?' ) } ),
				st.region === 'iran' ? h( 'div', { class: 'sl-explain__body' },
					h( 'p', { text: T( 'در ایران بیشتر سرویس‌های هوش مصنوعی — Claude (Anthropic)، ChatGPT (OpenAI)، Gemini (گوگل)، OpenRouter — به‌خاطر تحریم، درخواست‌هایی را که از IP ایران می‌آیند رد می‌کنند و بعضی هم فیلتر هستند. سرچ کنسول، PageSpeed و ورود با گوگل هم از سرورهای ایران معمولاً کار نمی‌کنند.', 'In Iran most AI services — Claude (Anthropic), ChatGPT (OpenAI), Gemini (Google), OpenRouter — refuse requests coming from Iranian IP addresses because of sanctions, and some are filtered. Search Console, PageSpeed and Google sign-in usually do not work from Iranian servers either.' ) } ),
					h( 'p', { text: T( 'برای همین SEOlity درخواست را رمزنگاری‌شده (AES-256-GCM و امضای HMAC) به واسطی می‌فرستد که مال خود شماست: ورکر کلادفلر شما (هم‌زمان و سریع) یا رانر خصوصی گیت‌هاب شما (ناهم‌زمان). در حالت «خودکار» هر بار سریع‌ترین مسیری که کار می‌کند انتخاب می‌شود و اگر یکی قطع باشد، خودکار سراغ دیگری می‌رود. هر دو رایگان‌اند و کلید API شما هیچ‌وقت به‌صورت متن ساده جایی ذخیره نمی‌شود.', 'So SEOlity sends the request encrypted (AES-256-GCM and an HMAC signature) to a relay that belongs to you: your Cloudflare worker (synchronous and fast) or your private GitHub runner (asynchronous). In "Auto" mode the fastest working route is used every time, and if one is down the next one takes over automatically. Both are free, and your API key is never stored in clear text anywhere.' ) } ),
					h( 'p', { class: 'sl-muted', text: T( 'نکته: دامنه workers.dev گاهی در ایران فیلتر است. اگر آزمون سرعت نشان داد کلادفلر از سرور شما در دسترس نیست، در صفحه کلادفلر یک دامنه/زیردامنه اختصاصی (Custom URL) برای ورکر بگذارید.', 'Tip: the workers.dev domain is sometimes filtered in Iran. If the speed test shows Cloudflare is not reachable from your server, give the worker your own (sub)domain on the Cloudflare page (Custom URL).' ) } )
				) : h( 'div', { class: 'sl-explain__body' },
					h( 'p', { text: T( 'روی سرورهای خارج از ایران (مثلاً آلمان) اتصال مستقیم سریع‌ترین و ساده‌ترین راه است و به کلادفلر یا گیت‌هاب نیازی نیست. در حالت «خودکار» واسط فقط وقتی استفاده می‌شود که اتصال مستقیم برقرار نشود؛ اگر بخواهید، می‌توانید مسیر را دستی روی کلادفلر یا گیت‌هاب بگذارید.', 'On servers outside Iran (e.g. Germany) a direct connection is the fastest and simplest way, and Cloudflare or GitHub are not needed. In "Auto" mode a relay is used only when the direct connection fails; you can still set the route to Cloudflare or GitHub yourself.' ) } )
				)
			)
		);
		return App.card( T( 'هاست شما کجاست؟', 'Where is your host?' ), body, { icon: 'server', sub: T( 'کشور سرور (نه کشور بازدیدکنندگان)', 'The server’s country (not your visitors’)' ) } );
	}

	/* ------------------------------------------------------------------ 2. Route */
	function routeCard( st, save ) {
		var opts = [ [ 'auto', 'sparkles', T( 'خودکار (پیشنهادی)', 'Auto (recommended)' ), st.region === 'iran' ? T( 'سریع‌ترین واسطی که کار می‌کند (کلادفلر یا گیت‌هاب)؛ هرگز مستقیم.', 'The fastest working relay (Cloudflare or GitHub); never direct.' ) : T( 'مستقیم؛ اگر وصل نشد، سریع‌ترین واسط.', 'Direct; the fastest relay if direct fails.' ) ] ].concat( [ 'direct', 'cloudflare', 'github' ].map( function ( id ) {
			return [ id, ROUTES[ id ].icon, ROUTES[ id ].title(), ROUTES[ id ].text() ];
		} ) );
		var list = h( 'div', { class: 'sl-choices sl-choices--routes' }, opts.map( function ( o ) {
			var avail = o[ 0 ] === 'auto' || st.available[ o[ 0 ] ];
			var on = st.mode === o[ 0 ];
			return h( 'button', { type: 'button', class: 'sl-choice' + ( on ? ' is-on' : '' ) + ( avail ? '' : ' is-off' ), 'aria-pressed': on ? 'true' : 'false', onClick: function ( e ) {
				e.preventDefault();
				if ( on ) {
					return;
				}
				if ( ! avail ) {
					// Not set up yet: say so here instead of jumping to another page.
					var m = SL.modal( { title: ROUTES[ o[ 0 ] ].title() } );
					m.body.appendChild( h( 'p', { text: T( 'این مسیر هنوز راه‌اندازی نشده و برای همین نمی‌شود انتخابش کرد. اول آن را راه‌اندازی کنید (چند دقیقه، رایگان)، بعد همین‌جا انتخابش کنید.', 'This route is not set up yet, so it cannot be selected. Set it up first (a few minutes, free), then select it here.' ) } ) );
					m.foot.appendChild( h( 'button', { class: 'sl-btn', onClick: m.close }, t( 'Cancel' ) ) );
					m.foot.appendChild( h( 'button', { class: 'sl-btn sl-btn--primary', onClick: function () {
						m.close();
						App.go( ROUTES[ o[ 0 ] ].setup );
					} }, T( 'راه‌اندازی', 'Set it up' ) ) );
					return;
				}
				// Optimistic: show the choice at once, save in the background.
				list.querySelectorAll( '.sl-choice' ).forEach( function ( b ) {
					b.classList.remove( 'is-on' );
					b.setAttribute( 'aria-pressed', 'false' );
				} );
				this.classList.add( 'is-on', 'is-busy' );
				this.setAttribute( 'aria-pressed', 'true' );
				save( { ai_route: o[ 0 ] }, false );
			} },
				h( 'span', { class: 'sl-choice__ic' }, SL.icon( o[ 1 ], 20 ) ),
				h( 'span', { class: 'sl-choice__text' }, h( 'b', { text: o[ 2 ] } ), h( 'small', { text: o[ 3 ] } ), ! avail ? h( 'small', { class: 'sl-choice__warn', text: T( 'راه‌اندازی نشده — برای راه‌اندازی بزنید', 'Not set up — click to set it up' ) } ) : null ),
				on ? h( 'span', { class: 'sl-choice__check' }, SL.icon( 'check', 16 ) ) : null
			);
		} ) );
		var order = st.order.map( function ( r, i ) {
			return h( 'span', { class: 'sl-route-step' + ( i === 0 ? ' is-first' : '' ) }, SL.icon( ROUTES[ r ].icon, 14 ), h( 'span', { text: ROUTES[ r ].title() } ) );
		} );
		var sub = st.provider.indexOf( 'sub_' ) === 0
			? ( st.mode === 'direct'
				? T( 'موتور فعلی اشتراک Claude/ChatGPT است. در حالت «مستقیم» هیچ پیامی به گیت‌هاب نمی‌رود و پیام‌ها با ابزار رسمی (CLI) روی همین سرور اجرا می‌شوند — کارت پایین را ببینید.', 'The current engine is a Claude/ChatGPT subscription. In Direct mode nothing goes to GitHub: messages run with the official CLI on this server — see the card below.' )
				: T( 'موتور فعلی اشتراک Claude/ChatGPT است. اگر ابزار رسمی (CLI) روی همین سرور نصب باشد مستقیم از سرور اجرا می‌شود؛ وگرنه از رانر گیت‌هاب.', 'The current engine is a Claude/ChatGPT subscription. If the official CLI is installed on this server it runs here directly; otherwise on the GitHub runner.' ) )
			: st.provider === 'ollama' ? T( 'مدل محلی (Ollama) همیشه مستقیم است.', 'A local model (Ollama) is always direct.' ) : '';
		var body = h( 'div', { class: 'sl-stack' },
			list,
			h( 'div', { class: 'sl-route-order' }, h( 'span', { class: 'sl-muted', text: T( 'ترتیب استفاده الان:', 'Order in use now:' ) } ), order ),
			sub ? h( 'p', { class: 'sl-muted', text: sub } ) : null,
			App.toggle( T( 'سرویس‌های گوگل هم از کلادفلر بروند', 'Send Google services through Cloudflare too' ), st.route_google, function ( on ) {
				save( { route_google: on ? 1 : 0 }, false );
			}, T( 'سرچ کنسول، PageSpeed، ورود با گوگل و SerpApi — وقتی مسیر اول کلادفلر است.', 'Search Console, PageSpeed, Google sign-in and SerpApi — when Cloudflare is the first route.' ) )
		);
		return App.card( T( 'مسیر درخواست‌ها', 'Request route' ), body, { icon: 'link', sub: T( 'برای هر دو حالت ایران و خارج قابل تغییر است', 'Can be changed for both Iran and abroad' ) } );
	}

	/* ------------------------------------------------------------------ 2b. Subscription CLI on this server */
	function localCard( st, save ) {
		var l = st.local || {};
		var isSub = st.provider.indexOf( 'sub_' ) === 0;
		var engine = st.provider === 'sub_codex' ? 'codex' : 'claude';
		var row = function ( id, name, install ) {
			var path = l[ id ];
			var input = h( 'input', { type: 'text', class: 'sl-input', dir: 'ltr', value: l[ 'path_' + id ] || '', placeholder: path || '/usr/local/bin/' + id } );
			return h( 'div', { class: 'sl-local' },
				h( 'div', { class: 'sl-local__head' },
					h( 'b', { text: name } ),
					path ? SL.badge( T( 'پیدا شد', 'Found' ), 'good' ) : SL.badge( T( 'نصب نیست', 'Not installed' ) ),
					path ? h( 'code', { dir: 'ltr', text: path } ) : null
				),
				! path ? h( 'p', { class: 'sl-muted' }, T( 'نصب روی سرور (SSH): ', 'Install on the server (SSH): ' ), h( 'code', { dir: 'ltr', text: install } ) ) : null,
				h( 'label', { class: 'sl-row' }, h( 'span', { text: T( 'مسیر کامل (اختیاری):', 'Full path (optional):' ) } ), input,
					App.btn( t( 'Save' ), function () {
						var v = {};
						v[ 'sub_cli_' + id ] = input.value.trim();
						save( v, false );
					} ),
					App.abtn( T( 'آزمایش', 'Test' ), function () {
						return SL.api( 'network/local-test', { method: 'POST', body: { engine: id } } ).then( function ( r ) {
							SL.toast( T( 'کار می‌کند', 'It works' ) + ' · ' + secs( r.ms ) + ' · ' + r.text, 'success' );
						} ).catch( function ( e ) {
							SL.toast( e.message, 'error' );
						} );
					} )
				)
			);
		};
		var body = h( 'div', { class: 'sl-stack' },
			h( 'p', { text: T( 'برای اینکه اشتراک Claude Pro/Max یا ChatGPT Plus/Pro بدون گیت‌هاب و مستقیم از سایت شما کار کند، ابزار رسمی همان سرویس باید روی همین سرور نصب باشد (معمولاً روی سرور مجازی/VPS؛ هاست اشتراکی اجازه اجرای برنامه نمی‌دهد). SEOlity آن را خودکار پیدا می‌کند و پیام‌ها را مستقیم و هم‌زمان جواب می‌دهد.', 'For a Claude Pro/Max or ChatGPT Plus/Pro subscription to work directly from your site without GitHub, that service’s official CLI must be installed on this server (usually a VPS; shared hosting does not allow running programs). SEOlity finds it automatically and answers messages directly and at once.' ) } ),
			l.can_exec
				? h( 'div', { class: 'sl-alert sl-alert--success' }, SL.icon( 'check', 16 ), h( 'span', { text: T( 'این هاست اجازه اجرای برنامه را می‌دهد.', 'This host allows starting programs.' ) } ) )
				: h( 'div', { class: 'sl-alert sl-alert--warn' }, SL.icon( 'info', 16 ), h( 'span', { text: T( 'این هاست اجرای برنامه را بسته است (proc_open غیرفعال است). اینجا اشتراک فقط از طریق گیت‌هاب کار می‌کند؛ برای حالت مستقیم از کلید API استفاده کنید.', 'This host blocks starting programs (proc_open is disabled). Here a subscription can only run through GitHub; use an API key for Direct mode.' ) } ) ),
			isSub && st.mode === 'direct' && ! l[ engine ] ? h( 'div', { class: 'sl-alert sl-alert--error' }, SL.icon( 'info', 16 ), h( 'span', { text: T( 'حالت «مستقیم» انتخاب شده ولی ابزار رسمی روی سرور نیست؛ پیام‌های هوش مصنوعی خطا می‌دهند (به گیت‌هاب فرستاده نمی‌شوند). ابزار را نصب کنید، کلید API بگذارید یا مسیر «خودکار» را انتخاب کنید.', 'Direct is selected but the official CLI is not on this server, so AI messages will fail (they are not sent to GitHub). Install the CLI, use an API key or pick the Auto route.' ) } ) ) : null,
			l.can_exec ? row( 'claude', 'Claude Code', 'npm install -g @anthropic-ai/claude-code' ) : null,
			l.can_exec ? row( 'codex', 'OpenAI Codex', 'npm install -g @openai/codex' ) : null
		);
		return App.card( T( 'اشتراک روی همین سرور', 'Subscription on this server' ), body, { icon: 'server', sub: T( 'فقط برای اشتراک Claude/ChatGPT لازم است', 'Only needed for a Claude/ChatGPT subscription' ) } );
	}

	/* ------------------------------------------------------------------ 3. Speed test */
	function speedCard( st, reload ) {
		var probe = st.probe;
		var fastest = null;
		if ( probe && probe.routes ) {
			Object.keys( probe.routes ).forEach( function ( k ) {
				var r = probe.routes[ k ];
				if ( r.ok && ( fastest === null || r.estimate_ms < probe.routes[ fastest ].estimate_ms ) ) {
					fastest = k;
				}
			} );
		}
		var rows = [ 'direct', 'cloudflare', 'github' ].map( function ( id ) {
			var r = probe && probe.routes ? probe.routes[ id ] : null;
			var state = ! r ? SL.badge( t( 'Not tested' ) ) : r.ok ? SL.badge( t( 'Works' ), 'good' ) : r.setup ? SL.badge( t( 'Not set up' ) ) : SL.badge( t( 'Not reachable' ), 'bad' );
			return h( 'tr', { class: id === fastest ? 'is-best' : '' },
				h( 'td', null, h( 'span', { class: 'sl-row' }, SL.icon( ROUTES[ id ].icon, 16 ), h( 'b', { text: ROUTES[ id ].title() } ) ), id === fastest ? SL.badge( t( 'Fastest' ), 'brand' ) : null ),
				h( 'td', null, state ),
				h( 'td', { dir: 'ltr', text: r && r.ok ? secs( r.ms ) : '—' } ),
				h( 'td', { dir: 'ltr', text: r && r.ok ? secs( r.estimate_ms ) : '—' } ),
				h( 'td', { class: 'sl-muted' }, r ? h( 'span', { dir: 'auto', text: r.detail || '' } ) : null, r && r.setup ? h( 'a', { href: '#/' + ROUTES[ id ].setup, class: 'sl-btn sl-btn--sm', text: t( 'Set up' ) } ) : null )
			);
		} );
		var table = h( 'div', { class: 'sl-table-wrap' }, h( 'table', { class: 'sl-table sl-speed' },
			h( 'thead', null, h( 'tr', null, [ t( 'Route' ), t( 'Status' ), t( 'Ping' ), t( 'Extra time per answer' ), t( 'Details' ) ].map( function ( x ) {
				return h( 'th', { text: x } );
			} ) ) ),
			h( 'tbody', null, rows )
		) );
		var run = App.abtn( t( 'Run speed test' ), function () {
			return SL.api( 'network/probe', { method: 'POST' } ).then( reload );
		}, 'primary' );
		var body = h( 'div', { class: 'sl-stack' },
			probe ? h( 'p', { class: 'sl-muted', text: t( 'Tested against %1$s · %2$s', probe.target || '—', SL.date( new Date( probe.at * 1000 ).toISOString() ) ) } ) : h( 'p', { class: 'sl-muted', text: T( 'هنوز آزمون نشده. یک بار اجرا کنید تا SEOlity سریع‌ترین مسیر را بشناسد (هر روز هم خودکار تکرار می‌شود).', 'Not tested yet. Run it once so SEOlity knows the fastest route (it also repeats daily).' ) } ),
			table
		);
		return App.card( T( 'آزمون سرعت مسیرها', 'Route speed test' ), body, { icon: 'activity', actions: run } );
	}

	/* ------------------------------------------------------------------ 4. Warm GitHub runner */
	function runnerCard( st, save, reload ) {
		var run = st.runner;
		var minutes = h( 'input', { type: 'number', class: 'sl-input sl-input--sm', min: 1, max: 30, value: st.warm_minutes, dir: 'ltr' } );
		var body = h( 'div', { class: 'sl-stack' },
			h( 'p', { text: T( 'گیت‌هاب برای هر اجرای تازه حدود ۳۰ تا ۴۰ ثانیه وقت می‌گذارد تا ماشین را روشن و ابزار را نصب کند. SEOlity رانر را بعد از هر کار چند دقیقه «گرم» نگه می‌دارد تا درخواست‌های بعدی (مثل پیام‌های بعدی چت) در چند ثانیه جواب بگیرند. با باز کردن چت یا دکمه بهینه‌سازی، رانر از قبل روشن می‌شود.', 'GitHub needs about 30–40 seconds to start a machine and install the tools for a fresh run. SEOlity keeps the runner "warm" for a few minutes after each job so follow-up requests (like the next chat message) are answered in seconds. Opening the chat or the optimize button starts the runner ahead of time.' ) } ),
			h( 'div', { class: 'sl-row' },
				h( 'span', null, SL.icon( 'server', 16 ) ),
				run ? SL.badge( run.status === 'in_progress' ? t( 'Runner is warm' ) : t( 'Runner is starting' ), 'good' ) : SL.badge( t( 'Runner is off' ) ),
				run && run.url ? h( 'a', { href: run.url, target: '_blank', rel: 'noopener', class: 'sl-link', text: t( 'View on GitHub' ) } ) : null
			),
			h( 'label', { class: 'sl-row' }, h( 'span', { text: T( 'گرم ماندن بعد از آخرین کار (دقیقه):', 'Stay warm after the last job (minutes):' ) } ), minutes, App.btn( t( 'Save' ), function () {
				save( { sub_warm_minutes: Number( minutes.value ) || 10 }, false );
			}, 'sm' ) ),
			h( 'p', { class: 'sl-muted', text: T( 'هر دقیقه گرم ماندن از سهمیه رایگان GitHub Actions (۲۰۰۰ دقیقه در ماه برای مخزن خصوصی) کم می‌کند؛ ۱۰ دقیقه برای بیشتر سایت‌ها مناسب است.', 'Each warm minute uses the free GitHub Actions quota (2,000 minutes a month for private repositories); 10 minutes suits most sites.' ) } )
		);
		var warm = App.abtn( t( 'Warm up now' ), function () {
			return SL.api( 'ai/warm', { method: 'POST', noWait: true } ).then( function ( r ) {
				SL.toast( r.warm ? t( 'The runner is starting on GitHub.' ) : r.reason === 'not_needed' ? t( 'Not needed: AI requests do not use GitHub right now.' ) : t( 'Set up the GitHub runner first.' ), r.warm ? 'success' : 'info' );
				setTimeout( reload, 1500 );
			} );
		} );
		return App.card( T( 'رانر گرم گیت‌هاب (سرعت هوش مصنوعی اشتراکی)', 'Warm GitHub runner (subscription AI speed)' ), body, { icon: 'rocket', actions: warm } );
	}

	/* ------------------------------------------------------------------ 5. Storage on GitHub */
	function storageCard( s, reload ) {
		var total = ( s.tables || [] ).reduce( function ( a, x ) {
			return a + x.mb;
		}, 0 );
		var max = Math.max.apply( null, ( s.tables || [] ).map( function ( x ) {
			return x.mb;
		} ).concat( [ 0.01 ] ) );
		var bars = h( 'div', { class: 'sl-bars' }, ( s.tables || [] ).map( function ( x ) {
			return h( 'div', { class: 'sl-bars__row' },
				h( 'span', { class: 'sl-bars__label', dir: 'ltr', text: x.table } ),
				h( 'span', { class: 'sl-bars__track' }, h( 'span', { class: 'sl-bars__fill', style: 'width:' + Math.max( 2, Math.round( 100 * x.mb / max ) ) + '%' } ) ),
				h( 'span', { class: 'sl-bars__val', dir: 'ltr', text: SL.num( x.mb, 2 ) + ' MB' } )
			);
		} ) );
		var last = s.last && s.last.time ? ( s.last.ok ? t( 'Last archive: %s', new Date( s.last.time * 1000 ).toLocaleString() ) : t( 'Last archive failed: %s', s.last.error || '' ) ) : t( 'Not archived yet' );
		var body = h( 'div', { class: 'sl-stack' },
			h( 'p', { text: T( 'در هر دو حالت (ایران و خارج) داده‌های قدیمی سرچ کنسول، گزارش‌ها، لاگ هوش مصنوعی، گفتگوها، لاگ ۴۰۴، تاریخچه تغییرات، یادگیری‌ها، یادداشت‌های دانش، تصویر رقبا و پشتیبان تنظیمات هر روز در مخزن خصوصی گیت‌هاب شما ذخیره می‌شوند و از دیتابیس هاست پاک می‌شوند. فقط داده‌های اخیر (برای سرعت داشبورد) روی هاست می‌ماند. دانش سئو هم منبع اصلی‌اش گیت‌هاب است و روی هاست فقط یک فهرست جست‌وجوی کوچک دارد.', 'For both Iran and abroad, old Search Console data, reports, AI logs, chats, 404 logs, change history, learnings, knowledge notes, competitor snapshots and a settings backup are saved daily to your private GitHub repository and removed from the host database. Only recent data (for fast dashboards) stays on the host. SEO knowledge also lives on GitHub; the host keeps only a small search index.' ) } ),
			h( 'div', { class: 'sl-row' },
				s.ready ? SL.badge( t( 'Active' ), 'good' ) : SL.badge( t( 'Not set up' ), 'warn' ),
				s.repo ? h( 'code', { dir: 'ltr', text: s.repo + ( s.branch ? ' @ ' + s.branch : '' ) } ) : null,
				h( 'span', { class: 'sl-muted', text: last } )
			),
			h( 'p', { class: 'sl-muted', text: t( 'Kept on the host: %1$s days of logs, %2$s days of Search Console data · SEOlity tables now: %3$s MB', s.keep_days, s.gsc_days, SL.num( total, 2 ) ) } ),
			bars
		);
		var actions = s.ready ? App.abtn( t( 'Archive now' ), function () {
			return SL.api( 'storage/run', { method: 'POST' } ).then( function () {
				SL.toast( t( 'Archived to GitHub' ), 'success' );
				reload();
			} );
		} ) : h( 'a', { href: '#/github', class: 'sl-btn sl-btn--primary', text: t( 'Set up GitHub' ) } );
		return App.card( T( 'ذخیره همه‌چیز در گیت‌هاب', 'Everything stored on GitHub' ), body, { icon: 'database', actions: actions } );
	}
} )( window, document );
