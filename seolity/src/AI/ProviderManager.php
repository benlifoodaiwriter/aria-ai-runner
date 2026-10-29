<?php
/**
 * AI Provider Manager: the single place that knows which AI connections exist, whether they
 * really work, and which model is used.
 *
 * For every provider it keeps a persisted connection state:
 *   not_connected → (key saved) saved → (models listed by the provider's own API) authenticated
 *   → (a real request with the chosen model succeeded) verified;  any failure → error (+ reason).
 *
 * - Models come from the provider's official models endpoint for that key (never a static list);
 *   for subscriptions, which have no model-list API, the official CLI aliases are offered and a
 *   model only counts as available after a real test run.
 * - Activation stores provider + model in one step and is refused when the test fails, so a
 *   working engine is never replaced by a broken one.
 * - Every real AI call reports back (observe()), so the state shown in the admin stays truthful
 *   (e.g. a key revoked later shows up as "key rejected").
 *
 * @package SEOlity
 */

namespace SEOlity\AI;

use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class ProviderManager {

	const OPTION = 'seolity_ai_connections';

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/* ------------------------------------------------------------------ catalog */

	/**
	 * Provider descriptions for the settings screen. group: api | subscription.
	 */
	public function catalog() {
		$c = array(
			'anthropic'     => array(
				'group'   => 'api',
				'mono'    => array( 'AN', '#d97757' ),
				'key_url' => 'https://console.anthropic.com/settings/keys',
				'docs'    => 'https://docs.anthropic.com/en/api/getting-started',
				'hint'    => 'sk-ant-api03-…',
				'note'    => __( 'Billed per use by Anthropic. A Claude Pro/Max subscription is not an API key — use the “Subscription” section for that.', 'seolity' ),
			),
			'openai'        => array(
				'group'   => 'api',
				'mono'    => array( 'OA', '#10a37f' ),
				'key_url' => 'https://platform.openai.com/api-keys',
				'docs'    => 'https://platform.openai.com/docs/quickstart',
				'hint'    => 'sk-…',
				'note'    => __( 'OpenAI API billing is separate from ChatGPT Plus/Pro: a ChatGPT subscription does not include API credit.', 'seolity' ),
			),
			'gemini'        => array(
				'group'   => 'api',
				'mono'    => array( 'GE', '#4285f4' ),
				'key_url' => 'https://aistudio.google.com/app/apikey',
				'docs'    => 'https://ai.google.dev/gemini-api/docs/api-key',
				'hint'    => 'AIza…',
				'note'    => __( 'Google AI Studio keys include a free tier with rate limits.', 'seolity' ),
			),
			'openrouter'    => array(
				'group'   => 'api',
				'mono'    => array( 'OR', '#6366f1' ),
				'key_url' => 'https://openrouter.ai/settings/keys',
				'docs'    => 'https://openrouter.ai/docs/quickstart',
				'hint'    => 'sk-or-v1-…',
				'note'    => __( 'One key for hundreds of models from many companies.', 'seolity' ),
			),
			'custom'        => array(
				'group'   => 'api',
				'mono'    => array( 'API', '#64748b' ),
				'key_url' => '',
				'docs'    => '',
				'hint'    => '',
				'note'    => __( 'DeepSeek, Groq, Mistral, xAI, Azure OpenAI, Cloudflare Workers AI or any OpenAI/Anthropic-compatible service.', 'seolity' ),
			),
			'ollama'        => array(
				'group'   => 'api',
				'mono'    => array( 'OL', '#0f172a' ),
				'key_url' => 'https://ollama.com/download',
				'docs'    => 'https://github.com/ollama/ollama/blob/main/docs/api.md',
				'hint'    => '',
				'note'    => __( 'Models running on your own computer or server (no key).', 'seolity' ),
			),
			'sub_claude'    => array(
				'group'   => 'subscription',
				'mono'    => array( 'CL', '#d97757' ),
				'plans'   => array( 'Claude Pro', 'Claude Max' ),
				'key_url' => 'https://docs.anthropic.com/en/docs/claude-code/setup',
				'docs'    => 'https://docs.anthropic.com/en/docs/claude-code/overview',
				'note'    => __( 'Anthropic lets a Claude Pro/Max plan be used programmatically only through its official Claude Code program, signed in with a token from “claude setup-token”. SEOlity therefore runs Claude Code for you — on your private GitHub runner (nothing to install) or, optionally, on this server (VPS with Node.js). A direct connection from WordPress without Claude Code is not offered by Anthropic for subscriptions.', 'seolity' ),
				'methods' => array( 'runner', 'cli' ),
			),
			'sub_codex'     => array(
				'group'   => 'subscription',
				'mono'    => array( 'GPT', '#10a37f' ),
				'plans'   => array( 'ChatGPT Plus', 'ChatGPT Pro', 'ChatGPT Business' ),
				'key_url' => 'https://developers.openai.com/codex/cli',
				'docs'    => 'https://developers.openai.com/codex/auth',
				'note'    => __( 'OpenAI lets a ChatGPT plan be used programmatically only through its official Codex program (“Sign in with ChatGPT”, saved in auth.json). SEOlity runs Codex for you — on your private GitHub runner or, optionally, on this server. The OpenAI API does not accept ChatGPT logins.', 'seolity' ),
				'methods' => array( 'runner', 'cli' ),
			),
			'github_models' => array(
				'group'   => 'subscription',
				'mono'    => array( 'GH', '#24292f' ),
				'plans'   => array( 'GitHub Free', 'Copilot Pro', 'Copilot Pro+', 'Copilot Business / Enterprise' ),
				'key_url' => 'https://github.com/settings/personal-access-tokens/new?description=SEOlity+AI&name=SEOlity+AI',
				'docs'    => 'https://docs.github.com/en/github-models',
				'hint'    => 'github_pat_…',
				'note'    => __( 'GitHub’s official AI model API, called directly from WordPress — no CLI, no runner. Every GitHub account gets free, rate-limited use; Copilot plans raise the limits. Create a fine-grained token with the permission “Models: Read”.', 'seolity' ),
				'methods' => array( 'direct' ),
			),
		);
		foreach ( $c as $id => $d ) {
			$p = ProviderRegistry::get( $this->plugin, $id );
			if ( ! $p ) {
				unset( $c[ $id ] );
				continue;
			}
			$c[ $id ]['id']    = $id;
			$c[ $id ]['label'] = $p->label();
		}
		return $c;
	}

	/* ------------------------------------------------------------------ state */

	private function store() {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	private function save_state( $id, array $changes ) {
		$all        = $this->store();
		$all[ $id ] = array_merge( isset( $all[ $id ] ) ? (array) $all[ $id ] : array(), $changes );
		update_option( self::OPTION, $all, false );
		return $all[ $id ];
	}

	/** Forget what we know about a provider (its key changed or was removed). */
	public function forget( $id ) {
		$all = $this->store();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
	}

	/** Secret changed somewhere (settings, wizard): the old verification no longer applies. */
	public function on_secret_changed( $key ) {
		foreach ( ProviderRegistry::all( $this->plugin ) as $id => $p ) {
			if ( method_exists( $p, 'key_name' ) && $p->key_name() === $key ) {
				$this->forget( $id );
			}
		}
	}

	/** Full state of one provider for the UI. */
	public function state( $id ) {
		$p = ProviderRegistry::get( $this->plugin, $id );
		if ( ! $p ) {
			return null;
		}
		$st      = $this->store();
		$st      = isset( $st[ $id ] ) ? (array) $st[ $id ] : array();
		$has_key = method_exists( $p, 'has_key' ) ? $p->has_key() : true;
		$model   = $p->model();
		$checked = isset( $st['checked'][ $model ] ) ? $st['checked'][ $model ] : null;
		$status  = isset( $st['status'] ) ? $st['status'] : '';
		if ( ! $has_key && 'ollama' !== $id && 'custom' !== $id ) {
			$status = 'not_connected';
		} elseif ( '' === $status ) {
			$status = 'saved';
		}
		if ( 'error' !== $status && $checked && ! empty( $checked['ok'] ) ) {
			$status = 'verified';
		}
		$out = array(
			'id'         => $id,
			'label'      => $p->label(),
			'active'     => (string) $this->plugin->settings->get( 'ai_provider' ) === $id,
			'has_key'    => $has_key,
			'configured' => $p->is_configured(),
			'model'      => $model,
			'status'     => $status,
			'error'      => isset( $st['error'] ) ? $st['error'] : null,
			'models'     => isset( $st['models'] ) ? (array) $st['models'] : array(),
			'models_at'  => isset( $st['models_at'] ) ? $st['models_at'] : null,
			'account'    => isset( $st['account'] ) ? $st['account'] : null,
			'checked'    => isset( $st['checked'] ) ? (array) $st['checked'] : array(),
			'pending'    => isset( $st['pending'] ) ? $st['pending'] : null,
			'effort'     => \SEOlity\AI\Effort::describe( $id ),
		);
		if ( 'ollama' === $id ) {
			$out['base_url'] = (string) $this->plugin->settings->get( 'ollama_base_url' );
		}
		if ( 0 === strpos( $id, 'sub_' ) ) {
			$engine           = 'sub_claude' === $id ? 'claude' : 'codex';
			$out['method']    = $this->plugin->subscription->method( $engine );
			$out['method_setting'] = (string) $this->plugin->settings->get( 'sub_method_' . $engine );
			$out['missing']   = $this->plugin->subscription->missing( $engine );
			$out['cli']       = $this->plugin->localcli->status();
			$out['runner']    = array( 'ready' => ! $this->plugin->subscription->missing( 'http' ), 'repo' => (string) $this->plugin->settings->get( 'sub_repo' ) );
		}
		return $out;
	}

	public function all() {
		$out = array( 'api' => array(), 'subscription' => array() );
		foreach ( $this->catalog() as $id => $d ) {
			$out[ $d['group'] ][] = array_merge( $d, $this->state( $id ) );
		}
		$out['active']  = $this->summary();
		$out['general'] = array(
			'route' => $this->plugin->gateway->mode(),
		);
		return $out;
	}

	/** What the top bar / engine card shows. */
	public function summary() {
		$id = (string) $this->plugin->settings->get( 'ai_provider' );
		$p  = ProviderRegistry::get( $this->plugin, $id );
		if ( ! $p ) {
			return array( 'provider' => $id, 'ready' => false, 'status' => 'not_connected', 'reason' => __( 'Choose an AI connection in Settings → AI.', 'seolity' ) );
		}
		$st     = $this->state( $id );
		$reason = '';
		if ( ! $st['configured'] ) {
			if ( ! empty( $st['missing'] ) ) {
				/* translators: %s: list */
				$reason = sprintf( __( 'Missing: %s', 'seolity' ), implode( ', ', $st['missing'] ) );
			} elseif ( ! $st['has_key'] ) {
				$reason = __( 'No API key saved for the selected provider.', 'seolity' );
			} else {
				$reason = __( 'No model selected yet — load the models and pick one.', 'seolity' );
			}
		} elseif ( 'error' === $st['status'] && $st['error'] ) {
			$reason = $st['error']['message'];
		}
		return array(
			'provider' => $id,
			'label'    => $p->label(),
			'model'    => $st['model'],
			'ready'    => $st['configured'],
			'status'   => $st['configured'] ? $st['status'] : 'not_configured',
			'reason'   => $reason,
		);
	}

	/* ------------------------------------------------------------------ actions */

	/**
	 * Save a key (optional) and load the models the provider offers to it.
	 *
	 * @param array $extra base_url (Ollama).
	 */
	public function connect( $id, $key = null, array $extra = array() ) {
		$p = ProviderRegistry::get( $this->plugin, $id );
		if ( ! $p ) {
			return new \WP_Error( 'seolity_ai_provider', __( 'Unknown AI provider.', 'seolity' ), array( 'status' => 404 ) );
		}
		if ( 'ollama' === $id && ! empty( $extra['base_url'] ) ) {
			$this->plugin->settings->update( array( 'ollama_base_url' => esc_url_raw( (string) $extra['base_url'] ) ) );
		}
		if ( null !== $key && '' !== trim( (string) $key ) && method_exists( $p, 'key_name' ) && '' !== $p->key_name() ) {
			$this->plugin->settings->set_secret( $p->key_name(), trim( (string) $key ) );
			$this->forget( $id );
		}
		if ( method_exists( $p, 'has_key' ) && ! $p->has_key() && ! in_array( $id, array( 'ollama', 'custom' ), true ) ) {
			return new \WP_Error( 'seolity_ai_key', __( 'Enter the API key first.', 'seolity' ), array( 'status' => 400 ) );
		}
		return $this->discover( $id );
	}

	/** Ask the provider which models this connection can use. */
	public function discover( $id ) {
		$p = ProviderRegistry::get( $this->plugin, $id );
		if ( ! $p ) {
			return new \WP_Error( 'seolity_ai_provider', __( 'Unknown AI provider.', 'seolity' ), array( 'status' => 404 ) );
		}
		$act    = $this->plugin->activity;
		$aid    = $act->start( 'ai', sprintf( /* translators: %s: provider */ __( 'Connecting %s: loading the models of your account', 'seolity' ), $p->label() ), array( 'step' => __( 'Asking the provider for its model list…', 'seolity' ) ) );
		$this->plugin->gateway->reset_last();
		$models = $p->list_models();
		$route  = $this->route_of( $id );
		if ( is_wp_error( $models ) ) {
			$err = $this->classify( $models );
			$this->save_state( $id, array( 'status' => 'error', 'error' => $err, 'checked_at' => gmdate( 'c' ) ) );
			$act->finish( $aid, 'failed', $err['message'], array( 'error' => $err['kind'] ), array( 'route' => $route ) );
			return new \WP_Error( 'seolity_ai_connect', $err['message'] . ( $err['fix'] ? ' ' . $err['fix'] : '' ), array( 'status' => 400, 'kind' => $err['kind'], 'state' => $this->state( $id ) ) );
		}
		$chat = array_values(
			array_filter(
				$models,
				static function ( $m ) {
					return ! isset( $m['chat'] ) || $m['chat'];
				}
			)
		);
		$this->save_state(
			$id,
			array(
				'status'     => 0 === strpos( $id, 'sub_' ) ? 'saved' : 'authenticated',
				'error'      => null,
				'models'     => $chat,
				'all_models' => count( $models ),
				'models_at'  => gmdate( 'c' ),
				'checked_at' => gmdate( 'c' ),
			)
		);
		/* translators: %d: number of models */
		$act->finish( $aid, 'done', sprintf( __( '%d models available to this connection', 'seolity' ), count( $chat ) ), array(), array( 'route' => $route ) );
		return $this->state( $id );
	}

	/**
	 * Prove that a model works for this connection with one small real request.
	 *
	 * @return array|\WP_Error State; for the GitHub runner the result may arrive later ("pending").
	 */
	public function verify( $id, $model ) {
		$p = ProviderRegistry::get( $this->plugin, $id );
		if ( ! $p ) {
			return new \WP_Error( 'seolity_ai_provider', __( 'Unknown AI provider.', 'seolity' ), array( 'status' => 404 ) );
		}
		$model = trim( (string) $model );
		$p->override_model( $model );
		$act = $this->plugin->activity;
		$aid = $act->start( 'ai', sprintf( /* translators: 1 provider 2 model */ __( 'Connection test: %1$s · %2$s', 'seolity' ), $p->label(), '' !== $model ? $model : __( 'default model', 'seolity' ) ), array( 'step' => __( 'Sending a short test request…', 'seolity' ) ) );
		$this->plugin->gateway->reset_last();
		$this->plugin->subscription->reset_route();
		$t0 = microtime( true );
		try {
			$res = $p->complete(
				array(
					'system'      => 'This is a connection test. Reply with the single word OK.',
					'messages'    => array( array( 'role' => 'user', 'content' => 'Reply with OK.' ) ),
					'max_tokens'  => 512,
					'schema'      => null,
					'schema_name' => 'connection_test',
				)
			);
		} catch ( Deferred $d ) {
			$act->step( $aid, __( 'Waiting for the answer from the GitHub runner…', 'seolity' ), null, array( 'status' => 'waiting', 'route' => 'github' ) );
			$this->save_state( $id, array( 'pending' => array( 'model' => $model, 'job' => $d->job_id(), 'since' => gmdate( 'c' ) ) ) );
			$p->override_model( null );
			$st            = $this->state( $id );
			$st['waiting'] = true;
			return $st;
		} catch ( \Throwable $e ) {
			$res = new \WP_Error( 'seolity_ai_error', $e->getMessage() );
		}
		$p->override_model( null );
		$ms    = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		$route = $this->route_of( $id );
		$all   = $this->store();
		$check = isset( $all[ $id ]['checked'] ) ? (array) $all[ $id ]['checked'] : array();
		if ( is_wp_error( $res ) ) {
			$err             = $this->classify( $res );
			$check[ $model ] = array( 'ok' => false, 'at' => gmdate( 'c' ), 'ms' => $ms, 'error' => $err['message'], 'kind' => $err['kind'], 'route' => $route );
			$this->save_state( $id, array( 'checked' => $check, 'pending' => null ) + ( in_array( $err['kind'], array( 'auth', 'billing' ), true ) ? array( 'status' => 'error', 'error' => $err ) : array() ) );
			$act->finish( $aid, 'failed', $err['message'], array( 'error' => $err['kind'] ), array( 'route' => $route ) );
			return new \WP_Error( 'seolity_ai_verify', $err['message'] . ( $err['fix'] ? ' ' . $err['fix'] : '' ), array( 'status' => 400, 'kind' => $err['kind'], 'state' => $this->state( $id ) ) );
		}
		$check[ $model ] = array( 'ok' => true, 'at' => gmdate( 'c' ), 'ms' => $ms, 'answered_by' => isset( $res['model'] ) ? (string) $res['model'] : $model, 'route' => $route );
		$this->save_state( $id, array( 'checked' => $check, 'status' => 'authenticated', 'error' => null, 'pending' => null ) );
		/* translators: 1 model 2 milliseconds */
		$act->finish( $aid, 'done', sprintf( __( 'Model %1$s answered in %2$s ms', 'seolity' ), isset( $res['model'] ) ? $res['model'] : $model, number_format_i18n( $ms ) ), array( 'model' => isset( $res['model'] ) ? $res['model'] : $model ), array( 'route' => $route ) );
		return $this->state( $id );
	}

	/**
	 * Use a provider + model for every AI feature. Refused when the model test fails, so a
	 * working configuration is never replaced by a broken one (subscription runner: may be pending).
	 */
	public function activate( $id, $model ) {
		$p = ProviderRegistry::get( $this->plugin, $id );
		if ( ! $p ) {
			return new \WP_Error( 'seolity_ai_provider', __( 'Unknown AI provider.', 'seolity' ), array( 'status' => 404 ) );
		}
		$model = trim( (string) $model );
		if ( '' === $model && ! in_array( $id, array( 'sub_codex' ), true ) ) {
			return new \WP_Error( 'seolity_ai_model', __( 'Choose a model first.', 'seolity' ), array( 'status' => 400 ) );
		}
		$st      = $this->state( $id );
		$checked = isset( $st['checked'][ $model ] ) ? $st['checked'][ $model ] : null;
		$fresh   = $checked && ! empty( $checked['ok'] ) && strtotime( $checked['at'] ) > time() - DAY_IN_SECONDS;
		if ( ! $fresh ) {
			$v = $this->verify( $id, $model );
			if ( is_wp_error( $v ) ) {
				return $v;
			}
			if ( empty( $v['waiting'] ) && empty( $v['checked'][ $model ]['ok'] ) ) {
				return new \WP_Error( 'seolity_ai_verify', __( 'The model test did not succeed.', 'seolity' ), array( 'status' => 400 ) );
			}
		}
		$res = $this->plugin->settings->update(
			array(
				'ai_model_' . $id => $model,
				'ai_provider'     => $id,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$this->plugin->audit_log->log( 'ai.activated', 'site', null, array( 'provider' => $id, 'model' => $model ) );
		return array( 'state' => $this->state( $id ), 'summary' => $this->summary() );
	}

	/** Remove the key (and what we know) of a provider. */
	public function disconnect( $id ) {
		$p = ProviderRegistry::get( $this->plugin, $id );
		if ( ! $p ) {
			return new \WP_Error( 'seolity_ai_provider', __( 'Unknown AI provider.', 'seolity' ), array( 'status' => 404 ) );
		}
		if ( method_exists( $p, 'key_name' ) && '' !== $p->key_name() ) {
			$this->plugin->settings->set_secret( $p->key_name(), '' );
		}
		$this->forget( $id );
		$this->plugin->audit_log->log( 'ai.disconnected', 'site', null, array( 'provider' => $id ) );
		return $this->state( $id );
	}

	/**
	 * Real AI calls report their outcome, so the shown state follows reality
	 * (a key revoked later shows up as "key rejected"; a working model shows as verified).
	 */
	public function observe( $id, $model, $error = null ) {
		$all = $this->store();
		$cur = isset( $all[ $id ] ) ? (array) $all[ $id ] : array();
		if ( null === $error ) {
			if ( ! empty( $cur['checked'][ $model ]['ok'] ) && strtotime( $cur['checked'][ $model ]['at'] ) > time() - HOUR_IN_SECONDS && 'error' !== ( isset( $cur['status'] ) ? $cur['status'] : '' ) ) {
				return; // Fresh enough; avoid writing the option on every call.
			}
			$check           = isset( $cur['checked'] ) ? (array) $cur['checked'] : array();
			$check[ $model ] = array( 'ok' => true, 'at' => gmdate( 'c' ), 'source' => 'usage' );
			$this->save_state( $id, array( 'checked' => $check, 'status' => 'authenticated', 'error' => null ) );
			return;
		}
		$err = $this->classify( $error );
		if ( in_array( $err['kind'], array( 'auth', 'billing', 'model' ), true ) ) {
			$check = isset( $cur['checked'] ) ? (array) $cur['checked'] : array();
			if ( 'model' === $err['kind'] ) {
				$check[ $model ] = array( 'ok' => false, 'at' => gmdate( 'c' ), 'error' => $err['message'], 'kind' => 'model' );
				$this->save_state( $id, array( 'checked' => $check ) );
			} else {
				$this->save_state( $id, array( 'status' => 'error', 'error' => $err ) );
			}
		}
	}

	/** Route the last request took (for the activity log). */
	private function route_of( $id ) {
		if ( 0 === strpos( $id, 'sub_' ) ) {
			$r = $this->plugin->subscription->last_route();
			return $r ? $r : '';
		}
		$last = $this->plugin->gateway->last();
		return isset( $last['route'] ) ? $last['route'] : 'direct';
	}

	/**
	 * Turn a provider error into a kind + a sentence that tells the owner what to do.
	 *
	 * @param \WP_Error $e Error.
	 * @return array{kind:string,message:string,fix:string}
	 */
	public function classify( \WP_Error $e ) {
		$msg  = $e->get_error_message();
		$code = $e->get_error_code();
		$data = $e->get_error_data();
		$http = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		$low  = strtolower( $msg );
		$kind = 'other';
		$fix  = '';
		if ( 'seolity_ai_auth' === $code || in_array( $http, array( 401, 403 ), true ) || preg_match( '/invalid[ _-]?(api[ _-]?)?key|api key not valid|incorrect api key|authentication|unauthori[sz]ed|permission|x-api-key header/', $low ) ) {
			$kind = 'auth';
			$fix  = __( 'The key was rejected: check that it was copied completely, has not been revoked, and belongs to this provider — or create a new one.', 'seolity' );
		} elseif ( preg_match( '/credit balance|insufficient[_ ]quota|billing|payment|exceeded your current quota|purchase credits/', $low ) ) {
			$kind = 'billing';
			$fix  = __( 'The key works but the account has no usable credit or quota. Add credit / a payment method in the provider’s console.', 'seolity' );
		} elseif ( 404 === $http || preg_match( '/model[_ ]not[_ ]found|does not exist|not found for api version|is not supported|unknown model|no endpoints found|not available/', $low ) ) {
			$kind = 'model';
			$fix  = __( 'This model is not available to your key. Pick another model from the list loaded from your account.', 'seolity' );
		} elseif ( 'seolity_ai_rate_limited' === $code || 429 === $http || false !== strpos( $low, 'rate limit' ) ) {
			$kind = 'rate';
			$fix  = __( 'The provider is limiting requests right now. Wait a minute and try again.', 'seolity' );
		} elseif ( in_array( $code, array( 'seolity_route_failed', 'seolity_ai_http', 'http_request_failed' ), true ) ) {
			$kind = 'network';
			$fix  = __( 'This server could not reach the provider. Check Connections → Server & speed (servers in Iran need the Cloudflare or GitHub relay).', 'seolity' );
		} elseif ( 'seolity_local_cli' === $code || 'seolity_ai_not_configured' === $code ) {
			$kind = 'setup';
		}
		return array( 'kind' => $kind, 'message' => $msg, 'fix' => $fix, 'at' => gmdate( 'c' ) );
	}
}
