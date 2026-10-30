<?php
/**
 * Error codes for the local CLIs, the rules that recognise them in CLI output, and redaction.
 *
 * Order matters: authentication is checked before memory, because a CLI whose login was revoked
 * can also print allocation noise while it shuts down — a revoked token must never be reported as
 * a hosting RAM shortage. "memory allocation of N bytes failed" (Rust) on CloudLinux almost always
 * means the per-process virtual-memory limit (LVE / ulimit -v), not the account's total RAM.
 *
 * @package SEOlity
 */

namespace SEOlity\AI\Remote;

defined( 'ABSPATH' ) || exit;

final class CliErrors {

	/** Code => [ HTTP status, status group for the wizard ]. */
	const CODES = array(
		'NODE_NOT_FOUND'         => array( 424, 'not_installed' ),
		'NODE_PERMISSION_DENIED' => array( 424, 'permission_denied' ),
		'NODE_TOO_OLD'           => array( 424, 'unsupported' ),
		'NODE_BROKEN'            => array( 424, 'runtime_error' ),
		'NPM_NOT_FOUND'          => array( 424, 'not_installed' ),
		'CLI_NOT_FOUND'          => array( 424, 'not_installed' ),
		'CLI_PERMISSION_DENIED'  => array( 424, 'permission_denied' ),
		'PROC_OPEN_DISABLED'     => array( 424, 'unsupported' ),
		'PROC_OPEN_FAILED'       => array( 500, 'runtime_error' ),
		'AUTH_REQUIRED'          => array( 401, 'auth_required' ),
		'AUTH_INVALID'           => array( 401, 'auth_required' ),
		'AUTH_TOKEN_EXPIRED'     => array( 401, 'auth_required' ),
		'AUTH_TOKEN_REVOKED'     => array( 401, 'auth_required' ),
		'AUTH_FORBIDDEN'         => array( 403, 'auth_required' ),
		'RATE_LIMIT'             => array( 429, 'runtime_error' ),
		'PROVIDER_UNAVAILABLE'   => array( 503, 'runtime_error' ),
		'MEMORY_LIMIT'           => array( 507, 'runtime_error' ),
		'PROCESS_KILLED'         => array( 500, 'runtime_error' ),
		'EXECUTION_TIMEOUT'      => array( 504, 'runtime_error' ),
		'INSTALL_BLOCKED'        => array( 424, 'unsupported' ),
		'DISK_FULL'              => array( 507, 'unsupported' ),
		'RUNTIME_ERROR'          => array( 500, 'runtime_error' ),
	);

	/** Ordered rules: [ code, regex ] — first match wins. */
	const RULES = array(
		array( 'NODE_NOT_FOUND', "#(/usr/bin/env: .?node.?: No such file|env: node: No such file|node: (command )?not found|cannot find node)#i" ),
		array( 'AUTH_TOKEN_REVOKED', '#(token_revoked|refresh token (was|has been) revoked|token has been revoked|revoked)#i' ),
		array( 'AUTH_TOKEN_EXPIRED', '#(refresh_token_expired|refresh token (has )?expired|token (has )?expired|OAuth token has expired|session expired|expired_token)#i' ),
		array( 'AUTH_FORBIDDEN', '#(\b403\b(?! bytes)|permission_error|forbidden|not allowed to use|does not have access to|insufficient (permissions|scope))#i' ),
		array( 'AUTH_INVALID', '#(invalid api key|invalid[_ ]token|invalid_grant|authentication_error|invalid x-api-key|could not parse auth|auth\.json.*(invalid|malformed))#i' ),
		array( 'AUTH_REQUIRED', '#(\b401\b(?! bytes)|unauthorized|not logged in|please (log ?in|sign in|run /login)|login required|no credentials|missing (api key|credentials)|run .?codex login)#i' ),
		array( 'MEMORY_LIMIT', '#(JavaScript heap out of memory|memory allocation of \d+ bytes failed|Cannot allocate memory|ENOMEM|out of memory|std::bad_alloc)#i' ),
		array( 'RATE_LIMIT', '#(\b429\b(?! bytes)|rate[_ ]limit|usage limit|too many requests|quota exceeded|you\'ve hit your limit|limit reached)#i' ),
		array( 'PROVIDER_UNAVAILABLE', '#(ENOTFOUND|EAI_AGAIN|ECONNREFUSED|ECONNRESET|ETIMEDOUT|network error|error sending request|stream disconnected|could not resolve|connection (refused|reset|timed out)|failed to connect|\b50[0-4]\b(?! bytes)|overloaded|service unavailable)#i' ),
		array( 'CLI_PERMISSION_DENIED', '#(EACCES|permission denied)#i' ),
		array( 'DISK_FULL', '#(ENOSPC|EDQUOT|no space left|disk quota exceeded)#i' ),
	);

	public static function message( $code ) {
		$m = array(
			'NODE_NOT_FOUND'         => __( 'Node.js was not found. The CLI needs Node.js to run.', 'seolity' ),
			'NODE_PERMISSION_DENIED' => __( 'PHP cannot execute the Node.js program on this server.', 'seolity' ),
			'NODE_TOO_OLD'           => __( 'The Node.js version on this server is too old for this CLI.', 'seolity' ),
			'NODE_BROKEN'            => __( 'Node.js was found but does not start correctly.', 'seolity' ),
			'NPM_NOT_FOUND'          => __( 'npm (the Node.js package installer) was not found next to Node.js.', 'seolity' ),
			'CLI_NOT_FOUND'          => __( 'The selected AI CLI is not installed on this server.', 'seolity' ),
			'CLI_PERMISSION_DENIED'  => __( 'The CLI file exists but PHP is not allowed to execute it.', 'seolity' ),
			'PROC_OPEN_DISABLED'     => __( 'This host does not allow PHP to start programs (proc_open is disabled).', 'seolity' ),
			'PROC_OPEN_FAILED'       => __( 'The host refused to start the program.', 'seolity' ),
			'AUTH_REQUIRED'          => __( 'Please connect your AI account.', 'seolity' ),
			'AUTH_INVALID'           => __( 'The saved login is not valid. Please reconnect your account.', 'seolity' ),
			'AUTH_TOKEN_EXPIRED'     => __( 'Your login has expired. Please reconnect.', 'seolity' ),
			'AUTH_TOKEN_REVOKED'     => __( 'Your login has been revoked. Please reconnect.', 'seolity' ),
			'AUTH_FORBIDDEN'         => __( 'Your account is not allowed to use this model or feature.', 'seolity' ),
			'RATE_LIMIT'             => __( 'The account has reached its usage or rate limit. Try again later.', 'seolity' ),
			'PROVIDER_UNAVAILABLE'   => __( 'The AI provider could not be reached from this server.', 'seolity' ),
			'MEMORY_LIMIT'           => __( 'The process could not get enough memory. On CloudLinux this is usually the per-process memory limit, not the account total.', 'seolity' ),
			'PROCESS_KILLED'         => __( 'The host stopped the process before it finished.', 'seolity' ),
			'EXECUTION_TIMEOUT'      => __( 'The AI process exceeded its time limit.', 'seolity' ),
			'INSTALL_BLOCKED'        => __( 'This server cannot download packages from npm.', 'seolity' ),
			'DISK_FULL'              => __( 'There is not enough disk space or quota.', 'seolity' ),
			'RUNTIME_ERROR'          => __( 'The CLI stopped with an error.', 'seolity' ),
		);
		return isset( $m[ $code ] ) ? $m[ $code ] : $m['RUNTIME_ERROR'];
	}

	/** What the administrator should do next. */
	public static function action( $code, $engine = '' ) {
		switch ( $code ) {
			case 'NODE_NOT_FOUND':
			case 'NODE_TOO_OLD':
				return __( 'In cPanel open “Setup Node.js App”, create an app with the newest Node.js version (this installs it for your account), then press “Refresh diagnostics”.', 'seolity' );
			case 'NODE_PERMISSION_DENIED':
			case 'CLI_PERMISSION_DENIED':
				return __( 'Press “Install / repair”. If it persists, ask the host whether PHP may run programs from your home folder (noexec mount or CageFS rule).', 'seolity' );
			case 'NPM_NOT_FOUND':
				return __( 'Choose another Node.js version in cPanel “Setup Node.js App” (it includes npm).', 'seolity' );
			case 'CLI_NOT_FOUND':
				return __( 'Press “Install / repair”.', 'seolity' );
			case 'PROC_OPEN_DISABLED':
			case 'PROC_OPEN_FAILED':
				return __( 'Ask the host to allow proc_open for your account, or run the subscription through the GitHub route, or use an API key.', 'seolity' );
			case 'AUTH_REQUIRED':
			case 'AUTH_INVALID':
			case 'AUTH_TOKEN_EXPIRED':
			case 'AUTH_TOKEN_REVOKED':
				return 'codex' === $engine ? __( 'Press “Reconnect” and sign in with your ChatGPT account again.', 'seolity' ) : __( 'Press “Reconnect” and paste a new token from “claude setup-token”.', 'seolity' );
			case 'AUTH_FORBIDDEN':
				return __( 'Choose another model or check the subscription plan of this account.', 'seolity' );
			case 'RATE_LIMIT':
				return __( 'Wait until the limit resets, or switch to another engine meanwhile.', 'seolity' );
			case 'PROVIDER_UNAVAILABLE':
				return __( 'Check the server’s outbound connection; in Iran use the Cloudflare or GitHub route.', 'seolity' );
			case 'MEMORY_LIMIT':
			case 'PROCESS_KILLED':
				return __( 'Ask the host to raise the per-process memory (LVE PMEM/VMEM) limit, or use the GitHub route.', 'seolity' );
			case 'EXECUTION_TIMEOUT':
				return __( 'Raise the AI timeout in Settings, or choose a faster model or lower effort.', 'seolity' );
			case 'INSTALL_BLOCKED':
				return __( 'Ask the host to allow outbound HTTPS to registry.npmjs.org. Shortest alternative: run the one install command shown below once over SSH, then press “Refresh diagnostics”.', 'seolity' );
			case 'DISK_FULL':
				return __( 'Free some disk space or ask for a larger quota, then try again.', 'seolity' );
		}
		return __( 'Copy the diagnostic report and send it to support.', 'seolity' );
	}

	public static function group( $code ) {
		return isset( self::CODES[ $code ] ) ? self::CODES[ $code ][1] : 'runtime_error';
	}

	/** WP_Error with the code, a human message, the next step and a redacted detail. */
	public static function error( $code, $detail = '', array $extra = array(), $engine = '' ) {
		$code = isset( self::CODES[ $code ] ) ? $code : 'RUNTIME_ERROR';
		$msg  = self::message( $code );
		$det  = trim( self::redact( (string) $detail ) );
		return new \WP_Error(
			'seolity_cli_' . strtolower( $code ),
			$msg . ( '' !== $det ? ' (' . mb_substr( $det, 0, 300 ) . ')' : '' ),
			array_merge(
				array(
					'status'   => self::CODES[ $code ][0],
					'cli_code' => $code,
					'group'    => self::group( $code ),
					'action'   => self::action( $code, $engine ),
					'engine'   => $engine,
				),
				$extra
			)
		);
	}

	/** Recognise the cause of a failed run. */
	public static function classify( array $r ) {
		if ( ! empty( $r['timed_out'] ) ) {
			return 'EXECUTION_TIMEOUT';
		}
		$text = (string) $r['err'] . "\n" . (string) $r['out'];
		foreach ( self::RULES as $rule ) {
			if ( preg_match( $rule[1], $text ) ) {
				return $rule[0];
			}
		}
		if ( 127 === (int) $r['code'] ) {
			return 'NODE_NOT_FOUND'; // The CLI itself exists (checked before running); its interpreter does not.
		}
		if ( 126 === (int) $r['code'] ) {
			return 'CLI_PERMISSION_DENIED';
		}
		if ( 9 === (int) $r['signal'] || 137 === (int) $r['code'] ) {
			return 'PROCESS_KILLED';
		}
		return 'RUNTIME_ERROR';
	}

	/** Remove anything that could be a credential, and personal paths. */
	public static function redact( $text ) {
		$text = preg_replace( '/eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/', '[jwt]', (string) $text );
		$text = preg_replace( '/sk-[A-Za-z0-9_-]{12,}/', '[key]', $text );
		$text = preg_replace( '/"(access_token|refresh_token|id_token|api_key|OPENAI_API_KEY|token|account_id)"\s*:\s*"[^"]*"/i', '"$1":"[redacted]"', $text );
		$text = preg_replace( '/(Bearer|token=|key=)\s*[A-Za-z0-9._~+\/=-]{12,}/i', '$1[redacted]', $text );
		$text = preg_replace( '/\b[A-Za-z0-9_\-]{48,}\b/', '[redacted]', $text );
		$home = ProcessRunner::user_home();
		if ( $home && strlen( $home ) > 1 ) {
			$text = preg_replace( '#(?<![\w/.-])' . preg_quote( $home, '#' ) . '(?=/|\s|$|["\'])#', '~', $text );
		}
		return preg_replace( "/\x1b\[[0-9;]*[A-Za-z]/", '', $text );
	}
}
