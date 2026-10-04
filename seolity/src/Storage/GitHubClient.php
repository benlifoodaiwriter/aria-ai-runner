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
	 *
	 * @param array<string,string> $files path => content.
	 * @return array{commit:string}|\WP_Error
	 */
	public function commit_files( $repo, $branch, array $files, $message ) {
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
		foreach ( $files as $path => $content ) {
			$blob = $this->request( 'POST', '/repos/' . $repo . '/git/blobs', array( 'content' => base64_encode( $content ), 'encoding' => 'base64' ) ); // phpcs:ignore
			if ( is_wp_error( $blob ) ) {
				return $blob;
			}
			$tree[] = array( 'path' => trim( $path, '/' ), 'mode' => '100644', 'type' => 'blob', 'sha' => $blob['sha'] );
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
		return is_wp_error( $upd ) ? $upd : array( 'commit' => $new_commit['sha'] );
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
