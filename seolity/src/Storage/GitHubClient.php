<?php
/**
 * Minimal GitHub REST client (Contents + Git Data APIs).
 *
 * Security: uses a fine-grained Personal Access Token limited to the chosen
 * repository with "Contents: Read and write" only. The token is encrypted at
 * rest and never exposed to the browser. Public knowledge repositories can be
 * read without a token.
 *
 * @package SEOlity
 */

namespace SEOlity\Storage;

use SEOlity\Core\Http;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class GitHubClient {

	const API = 'https://api.github.com';

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public static function valid_repo( $repo ) {
		return (bool) preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', (string) $repo );
	}

	private function headers() {
		$h     = array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		$token = $this->plugin->settings->secret( 'github_token' );
		if ( $token ) {
			$h['Authorization'] = 'Bearer ' . $token;
		}
		return $h;
	}

	public function has_token() {
		return (bool) $this->plugin->settings->secret( 'github_token' );
	}

	/** @return array|\WP_Error */
	public function request( $method, $path, $body = null ) {
		$res = Http::json( $method, self::API . $path, $body, $this->headers(), 45 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['status'] >= 400 ) {
			$msg = isset( $res['body']['message'] ) ? $res['body']['message'] : 'HTTP ' . $res['status'];
			return new \WP_Error( 'seolity_github', 'GitHub: ' . $msg, array( 'status' => $res['status'] ) );
		}
		return is_array( $res['body'] ) ? $res['body'] : array();
	}

	private static function enc_path( $path ) {
		return implode( '/', array_map( 'rawurlencode', explode( '/', trim( $path, '/' ) ) ) );
	}

	public function repo( $repo ) {
		return $this->request( 'GET', '/repos/' . $repo );
	}

	/** Recursive tree filtered by path prefix. @return array<int, array{path:string, sha:string, size:int}>|\WP_Error */
	public function tree( $repo, $branch, $prefix = '', $ext = '.md' ) {
		$res = $this->request( 'GET', '/repos/' . $repo . '/git/trees/' . rawurlencode( $branch ) . '?recursive=1' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$prefix = trim( $prefix, '/' );
		$out    = array();
		foreach ( (array) ( isset( $res['tree'] ) ? $res['tree'] : array() ) as $node ) {
			if ( 'blob' !== $node['type'] ) {
				continue;
			}
			if ( $prefix && 0 !== strpos( $node['path'], $prefix . '/' ) ) {
				continue;
			}
			if ( $ext && substr( $node['path'], -strlen( $ext ) ) !== $ext ) {
				continue;
			}
			$out[] = array( 'path' => $node['path'], 'sha' => $node['sha'], 'size' => isset( $node['size'] ) ? (int) $node['size'] : 0 );
		}
		return $out;
	}

	/** @return array{content:string, sha:string}|\WP_Error */
	public function get_file( $repo, $path, $ref = 'main' ) {
		$res = $this->request( 'GET', '/repos/' . $repo . '/contents/' . self::enc_path( $path ) . '?ref=' . rawurlencode( $ref ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( isset( $res['content'] ) && 'base64' === $res['encoding'] ) {
			return array( 'content' => (string) base64_decode( str_replace( "\n", '', $res['content'] ) ), 'sha' => $res['sha'] ); // phpcs:ignore
		}
		if ( isset( $res['download_url'] ) ) { // Files > 1 MB.
			$raw = wp_remote_get( $res['download_url'], array( 'timeout' => 60, 'headers' => $this->headers() ) );
			if ( is_wp_error( $raw ) ) {
				return $raw;
			}
			return array( 'content' => (string) wp_remote_retrieve_body( $raw ), 'sha' => $res['sha'] );
		}
		return new \WP_Error( 'seolity_github', 'Unexpected content response' );
	}

	public function blob( $repo, $sha ) {
		$res = $this->request( 'GET', '/repos/' . $repo . '/git/blobs/' . $sha );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return (string) base64_decode( str_replace( "\n", '', $res['content'] ) ); // phpcs:ignore
	}

	/** Create/update one file (Contents API). */
	public function put_file( $repo, $path, $content, $message, $branch = 'main' ) {
		$existing = $this->request( 'GET', '/repos/' . $repo . '/contents/' . self::enc_path( $path ) . '?ref=' . rawurlencode( $branch ) );
		$body     = array(
			'message' => $message,
			'content' => base64_encode( $content ), // phpcs:ignore
			'branch'  => $branch,
		);
		if ( ! is_wp_error( $existing ) && isset( $existing['sha'] ) ) {
			$body['sha'] = $existing['sha'];
		}
		return $this->request( 'PUT', '/repos/' . $repo . '/contents/' . self::enc_path( $path ), $body );
	}

	/**
	 * Commit many files atomically (Git Data API): blobs → tree → commit → ref.
	 * A null content deletes the path. Retries when the branch moved meanwhile
	 * (e.g. the subscription runner pushed at the same moment).
	 *
	 * @param array<string,string|null> $files path => content (null = delete).
	 * @return array{commit:string}|\WP_Error
	 */
	public function commit_files( $repo, $branch, array $files, $message ) {
		$blobs = array();
		foreach ( $files as $path => $content ) {
			if ( null === $content ) {
				$blobs[ $path ] = null;
				continue;
			}
			$blob = $this->request( 'POST', '/repos/' . $repo . '/git/blobs', array( 'content' => base64_encode( $content ), 'encoding' => 'base64' ) ); // phpcs:ignore
			if ( is_wp_error( $blob ) ) {
				return $blob;
			}
			$blobs[ $path ] = $blob['sha'];
		}
		$last = null;
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$ref = $this->request( 'GET', '/repos/' . $repo . '/git/ref/heads/' . rawurlencode( $branch ) );
			if ( is_wp_error( $ref ) ) {
				return $ref;
			}
			$parent = $ref['object']['sha'];
			$commit = $this->request( 'GET', '/repos/' . $repo . '/git/commits/' . $parent );
			if ( is_wp_error( $commit ) ) {
				return $commit;
			}
			$tree = array();
			foreach ( $blobs as $path => $sha ) {
				$tree[] = array( 'path' => trim( $path, '/' ), 'mode' => '100644', 'type' => 'blob', 'sha' => $sha );
			}
			$new_tree = $this->request( 'POST', '/repos/' . $repo . '/git/trees', array( 'base_tree' => $commit['tree']['sha'], 'tree' => $tree ) );
			if ( is_wp_error( $new_tree ) ) {
				return $new_tree;
			}
			$new_commit = $this->request( 'POST', '/repos/' . $repo . '/git/commits', array( 'message' => $message, 'tree' => $new_tree['sha'], 'parents' => array( $parent ) ) );
			if ( is_wp_error( $new_commit ) ) {
				return $new_commit;
			}
			$upd = $this->request( 'PATCH', '/repos/' . $repo . '/git/refs/heads/' . rawurlencode( $branch ), array( 'sha' => $new_commit['sha'], 'force' => false ) );
			if ( ! is_wp_error( $upd ) ) {
				return array( 'commit' => $new_commit['sha'] );
			}
			$last = $upd;
			$data = $upd->get_error_data();
			if ( ! is_array( $data ) || 422 !== (int) ( isset( $data['status'] ) ? $data['status'] : 0 ) ) {
				return $upd; // Not a fast-forward race: give up.
			}
		}
		return $last;
	}

	/**
	 * Who owns the token, what it may do and when it expires.
	 *
	 * Classic tokens report their scopes in X-OAuth-Scopes; fine-grained tokens
	 * have none (their permissions are checked per repository instead).
	 *
	 * @return array|\WP_Error
	 */
	public function whoami( $token = null ) {
		$headers = $this->headers();
		if ( null !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$res = Http::json( 'GET', self::API . '/user', null, $headers, 30 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['status'] >= 400 ) {
			$msg = isset( $res['body']['message'] ) ? $res['body']['message'] : 'HTTP ' . $res['status'];
			return new \WP_Error( 'seolity_github', 'GitHub: ' . $msg, array( 'status' => 401 === $res['status'] ? 400 : $res['status'] ) );
		}
		$h      = $res['headers'];
		$scopes = isset( $h['x-oauth-scopes'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $h['x-oauth-scopes'] ) ) ) : null;
		$tok    = null !== $token ? $token : (string) $this->plugin->settings->secret( 'github_token' );
		$type   = 0 === strpos( $tok, 'github_pat_' ) ? 'fine-grained' : ( 0 === strpos( $tok, 'ghp_' ) ? 'classic' : ( null !== $scopes ? 'classic' : 'other' ) );
		return array(
			'login'     => (string) $res['body']['login'],
			'name'      => isset( $res['body']['name'] ) ? (string) $res['body']['name'] : '',
			'avatar'    => isset( $res['body']['avatar_url'] ) ? esc_url_raw( $res['body']['avatar_url'] ) : '',
			'type'      => $type,
			'scopes'    => null === $scopes ? null : array_values( $scopes ),
			'expires'   => isset( $h['github-authentication-token-expiration'] ) && strtotime( (string) $h['github-authentication-token-expiration'] ) ? gmdate( 'c', strtotime( (string) $h['github-authentication-token-expiration'] ) ) : null,
			'rate_left' => isset( $h['x-ratelimit-remaining'] ) ? (int) $h['x-ratelimit-remaining'] : null,
		);
	}

	/** Login of the token owner. */
	public function login() {
		$u = $this->request( 'GET', '/user' );
		return is_wp_error( $u ) ? $u : (string) $u['login'];
	}

	/** Create a private repository (user or organization) with an initial commit. */
	public function create_repo( $repo, $description = '' ) {
		list( $owner, $name ) = explode( '/', $repo, 2 );
		$login = $this->login();
		if ( is_wp_error( $login ) ) {
			return $login;
		}
		$path = 0 === strcasecmp( $owner, $login ) ? '/user/repos' : '/orgs/' . rawurlencode( $owner ) . '/repos';
		return $this->request( 'POST', $path, array( 'name' => $name, 'private' => true, 'auto_init' => true, 'description' => $description ) );
	}

	/**
	 * Repository facts for the setup screens (never fails: errors are reported in the array).
	 */
	public function inspect( $repo ) {
		$out = array( 'repo' => (string) $repo, 'valid' => self::valid_repo( $repo ), 'exists' => false, 'private' => null, 'can_push' => null, 'url' => self::valid_repo( $repo ) ? 'https://github.com/' . $repo : '', 'error' => null );
		if ( ! $out['valid'] || ! $this->has_token() ) {
			return $out;
		}
		$r = $this->repo( $repo );
		if ( is_wp_error( $r ) ) {
			$data          = $r->get_error_data();
			$out['error']  = 404 === (int) ( is_array( $data ) && isset( $data['status'] ) ? $data['status'] : 0 ) ? null : $r->get_error_message();
			return $out;
		}
		$out['exists']         = true;
		$out['private']        = ! empty( $r['private'] );
		$out['can_push']       = ! empty( $r['permissions']['push'] );
		$out['default_branch'] = (string) $r['default_branch'];
		$out['pushed_at']      = isset( $r['pushed_at'] ) ? (string) $r['pushed_at'] : null;
		return $out;
	}

	/** Create the repository when it does not exist yet and wait until it is readable. */
	public function ensure_repo( $repo, $description ) {
		$info = $this->repo( $repo );
		if ( ! is_wp_error( $info ) ) {
			return $info;
		}
		$created = $this->create_repo( $repo, $description );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		for ( $i = 0; $i < 4; $i++ ) {
			sleep( 1 + $i ); // GitHub needs a moment before the initial commit is readable.
			$info = $this->repo( $repo );
			if ( ! is_wp_error( $info ) && ! is_wp_error( $this->request( 'GET', '/repos/' . $repo . '/git/ref/heads/' . rawurlencode( $info['default_branch'] ) ) ) ) {
				return $info;
			}
		}
		return $info;
	}

	/** Make sure a branch exists (created from $from when missing). */
	public function ensure_branch( $repo, $branch, $from ) {
		$ref = $this->request( 'GET', '/repos/' . $repo . '/git/ref/heads/' . rawurlencode( $branch ) );
		if ( ! is_wp_error( $ref ) ) {
			return true;
		}
		$base = $this->request( 'GET', '/repos/' . $repo . '/git/ref/heads/' . rawurlencode( $from ) );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$res = $this->request( 'POST', '/repos/' . $repo . '/git/refs', array( 'ref' => 'refs/heads/' . $branch, 'sha' => $base['object']['sha'] ) );
		return is_wp_error( $res ) ? $res : true;
	}

	/** List a directory (empty array when it does not exist). */
	public function list_dir( $repo, $path, $ref ) {
		$res = $this->request( 'GET', '/repos/' . $repo . '/contents/' . self::enc_path( $path ) . '?ref=' . rawurlencode( $ref ) );
		if ( is_wp_error( $res ) ) {
			$data = $res->get_error_data();
			return is_array( $data ) && 404 === (int) ( isset( $data['status'] ) ? $data['status'] : 0 ) ? array() : $res;
		}
		return isset( $res[0] ) || array() === $res ? $res : array();
	}

	/** Store an encrypted GitHub Actions secret (libsodium sealed box, as GitHub requires). */
	public function put_secret( $repo, $name, $value ) {
		if ( ! function_exists( 'sodium_crypto_box_seal' ) ) {
			return new \WP_Error( 'seolity_github', __( 'The PHP sodium extension is required to store GitHub secrets.', 'seolity' ) );
		}
		$key = $this->request( 'GET', '/repos/' . $repo . '/actions/secrets/public-key' );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$sealed = sodium_crypto_box_seal( (string) $value, base64_decode( $key['key'] ) ); // phpcs:ignore
		$res    = $this->request( 'PUT', '/repos/' . $repo . '/actions/secrets/' . rawurlencode( $name ), array( 'encrypted_value' => base64_encode( $sealed ), 'key_id' => $key['key_id'] ) ); // phpcs:ignore
		return is_wp_error( $res ) ? $res : true;
	}

	/** Start a workflow_dispatch run. */
	public function dispatch( $repo, $workflow, $ref, array $inputs ) {
		$res = $this->request( 'POST', '/repos/' . $repo . '/actions/workflows/' . rawurlencode( $workflow ) . '/dispatches', array( 'ref' => $ref, 'inputs' => $inputs ) );
		return is_wp_error( $res ) ? $res : true;
	}

	/** Recent runs of a workflow. */
	public function runs( $repo, $workflow, $limit = 8 ) {
		$res = $this->request( 'GET', '/repos/' . $repo . '/actions/workflows/' . rawurlencode( $workflow ) . '/runs?per_page=' . (int) $limit );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$out = array();
		foreach ( isset( $res['workflow_runs'] ) ? (array) $res['workflow_runs'] : array() as $run ) {
			$out[] = array(
				'id'         => (int) $run['id'],
				'status'     => (string) $run['status'],
				'conclusion' => (string) $run['conclusion'],
				'created_at' => (string) $run['created_at'],
				'updated_at' => (string) $run['updated_at'],
				'url'        => esc_url_raw( $run['html_url'] ),
			);
		}
		return $out;
	}

	/** Connection test for the Settings screen. */
	public function test( $repo ) {
		if ( ! self::valid_repo( $repo ) ) {
			return new \WP_Error( 'seolity_github', __( 'Repository must look like owner/name.', 'seolity' ), array( 'status' => 400 ) );
		}
		$r = $this->repo( $repo );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return array(
			'full_name' => $r['full_name'],
			'private'   => (bool) $r['private'],
			'default_branch' => $r['default_branch'],
			'can_push'  => ! empty( $r['permissions']['push'] ),
			'token'     => $this->has_token(),
		);
	}
}
