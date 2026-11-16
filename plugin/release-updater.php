<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signed updates from the plugin's public GitHub releases (docs/UPDATES.md), the same scheme as Histeon's updater and Lingua Bridge's.
 *
 * - On by default, for the standalone plugin only: as a module inside Histeon, Histeon's own release updates it.
 *   `STATIC_BRIDGE_UPDATES` false in wp-config.php switches it off; `STATIC_BRIDGE_UPDATE_REPOSITORY` and
 *   `STATIC_BRIDGE_UPDATE_PUBLIC_KEY` replace the repository and the release key.
 * - One anonymous request to GitHub's public API at most every 12 hours: nothing about the site is sent.
 * - A release counts only if it is not a draft or a pre-release, its tag is vX.Y.Z and it has
 *   static-bridge-X.Y.Z.zip with static-bridge-X.Y.Z.zip.minisig next to it, both from https://github.com/.
 * - Before WordPress installs the ZIP, it is downloaded with its signature and checked with the release key
 *   (minisign: Ed25519 over BLAKE2b-512 of the file, and over the trusted comment), and the signed file name must be
 *   exactly that asset. Anything that does not hold stops the update: an unsigned package is never installed.
 */
final class Static_Bridge_Release_Updater {
	const CACHE      = 'static_bridge_release';
	const REPOSITORY = 'Seventhose/histeon-static-bridge';
	const PUBLIC_KEY = 'RWQSTenArP5FclHgI0qEErGDYUEQ6gL4cEhpgec49VKtqKfgl2TIAn5W';
	const ASSET      = 'static-bridge';
	const MAX_ZIP    = 62914560;
	const MAX_SIG    = 4096;

	public static function init() {
		if ( ! self::standalone() ) {
			return;
		}
		add_filter( 'pre_set_site_transient_update_plugins', array( self::class, 'offer' ) );
		add_filter( 'upgrader_pre_download', array( self::class, 'download' ), 10, 4 );
	}

	/** The plugin's main file, next to this one. */
	public static function file() {
		return __DIR__ . '/static-bridge.php';
	}

	/** The plugin as WordPress knows it: plugins/<folder>/static-bridge.php, not a copy inside another plugin. */
	public static function basename() {
		return plugin_basename( Static_Bridge_Release_Updater::file() );
	}

	public static function standalone() {
		return function_exists( 'plugin_basename' ) && 1 === substr_count( self::basename(), '/' );
	}

	/** @return array{enabled: bool, repository: string, key: string} */
	public static function settings() {
		$repository = defined( 'STATIC_BRIDGE_UPDATE_REPOSITORY' ) ? (string) STATIC_BRIDGE_UPDATE_REPOSITORY : self::REPOSITORY;
		$key        = defined( 'STATIC_BRIDGE_UPDATE_PUBLIC_KEY' ) ? (string) STATIC_BRIDGE_UPDATE_PUBLIC_KEY : self::PUBLIC_KEY;
		$on         = ! defined( 'STATIC_BRIDGE_UPDATES' ) || false !== STATIC_BRIDGE_UPDATES;
		return array(
			'enabled'    => $on && 1 === preg_match( '#^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})/[A-Za-z0-9._-]{1,100}$#', $repository ) && '' !== $key,
			'repository' => $repository,
			'key'        => $key,
		);
	}

	/**
	 * The newest signed release on GitHub, cached 12 hours.
	 *
	 * @return array{version: string, zip: string, sig: string, url: string}|null
	 */
	public static function latest() {
		$settings = self::settings();
		if ( ! $settings['enabled'] ) {
			return null;
		}
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) && isset( $cached['repository'] ) && $cached['repository'] === $settings['repository'] ) {
			return isset( $cached['release'] ) && is_array( $cached['release'] ) ? $cached['release'] : null;
		}
		$response = wp_safe_remote_get(
			'https://api.github.com/repos/' . $settings['repository'] . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'Static-Bridge-updater' ),
			)
		);
		$release = null;
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$release = self::parse( json_decode( wp_remote_retrieve_body( $response ), true ) );
		}
		set_site_transient( self::CACHE, array( 'repository' => $settings['repository'], 'release' => $release ), 12 * HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * A GitHub release as the updater needs it, or null when anything is missing.
	 *
	 * @param mixed $data The decoded API answer.
	 * @return array{version: string, zip: string, sig: string, url: string}|null
	 */
	public static function parse( $data ) {
		if ( ! is_array( $data ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}
		if ( 1 !== preg_match( '/^v?(\d+\.\d+\.\d+)$/', isset( $data['tag_name'] ) ? (string) $data['tag_name'] : '', $m ) ) {
			return null;
		}
		$assets = array();
		foreach ( isset( $data['assets'] ) && is_array( $data['assets'] ) ? $data['assets'] : array() as $asset ) {
			$name = is_array( $asset ) && isset( $asset['name'] ) ? (string) $asset['name'] : '';
			$url  = is_array( $asset ) && isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
			if ( 0 === strpos( $url, 'https://github.com/' ) ) {
				$assets[ $name ] = $url;
			}
		}
		$name = self::ASSET . '-' . $m[1] . '.zip';
		if ( ! isset( $assets[ $name ], $assets[ $name . '.minisig' ] ) ) {
			return null;
		}
		$page = isset( $data['html_url'] ) ? (string) $data['html_url'] : '';
		return array(
			'version' => $m[1],
			'zip'     => $assets[ $name ],
			'sig'     => $assets[ $name . '.minisig' ],
			'url'     => 0 === strpos( $page, 'https://github.com/' ) ? $page : '',
		);
	}

	/**
	 * WordPress's update check: a newer signed release becomes an update of this plugin.
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public static function offer( $transient ) {
		if ( ! $transient instanceof \stdClass ) {
			return $transient;
		}
		$release = self::latest();
		if ( null === $release || ! version_compare( $release['version'], Static_Bridge_Plugin::VERSION, '>' ) ) {
			return $transient;
		}
		$plugin              = self::basename();
		$transient->response = isset( $transient->response ) && is_array( $transient->response ) ? $transient->response : array();
		$transient->response[ $plugin ] = (object) array(
			'slug'        => dirname( $plugin ),
			'plugin'      => $plugin,
			'new_version' => $release['version'],
			'package'     => $release['zip'],
			'url'         => $release['url'],
		);
		return $transient;
	}

	/**
	 * Before WordPress installs this plugin's ZIP: download it and its signature, and verify. Other packages are left
	 * to WordPress; an update of this plugin whose package is no longer the latest signed release is refused.
	 *
	 * @param mixed $reply
	 * @param mixed $package
	 * @param mixed $upgrader
	 * @param mixed $hook_extra
	 * @return mixed
	 */
	public static function download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		if ( false !== $reply || ! is_string( $package ) ) {
			return $reply;
		}
		$ours    = is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && self::basename() === $hook_extra['plugin'];
		$release = self::latest();
		if ( null === $release || $package !== $release['zip'] ) {
			return $ours
				? new \WP_Error( 'static_bridge_update_signature', __( 'Aggiornamento di Static Bridge fermato: il pacchetto non è più l’ultima release firmata. Controlla di nuovo gli aggiornamenti.', 'static-bridge' ) )
				: $reply;
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$zip = download_url( $package, 120 );
		if ( is_wp_error( $zip ) ) {
			return $zip;
		}
		try {
			if ( (int) filesize( $zip ) > self::MAX_ZIP ) {
				throw new \InvalidArgumentException( __( 'Pacchetto troppo grande.', 'static-bridge' ) );
			}
			$sig = wp_safe_remote_get( $release['sig'], array( 'timeout' => 20, 'limit_response_size' => self::MAX_SIG ) );
			if ( is_wp_error( $sig ) || 200 !== wp_remote_retrieve_response_code( $sig ) ) {
				throw new \InvalidArgumentException( __( 'Firma della release non scaricata.', 'static-bridge' ) );
			}
			$comment = self::verify( $zip, (string) wp_remote_retrieve_body( $sig ), self::settings()['key'] );
			$signed  = preg_match( '/(?:^|\t)file:([^\t]+)/', $comment, $m ) ? $m[1] : '';
			$asset   = rawurldecode( basename( (string) wp_parse_url( $package, PHP_URL_PATH ) ) );
			if ( '' === $signed || $signed !== $asset || self::ASSET . '-' . $release['version'] . '.zip' !== $signed ) {
				throw new \InvalidArgumentException( __( 'La firma è di un altro pacchetto.', 'static-bridge' ) );
			}
		} catch ( \InvalidArgumentException $e ) {
			wp_delete_file( $zip );
			/* translators: %s: reason */
			return new \WP_Error( 'static_bridge_update_signature', sprintf( __( 'Aggiornamento di Static Bridge fermato: %s', 'static-bridge' ), $e->getMessage() ) );
		}
		return $zip;
	}

	/**
	 * Whether the file is signed by the minisign public key with this .minisig text; returns the trusted comment.
	 *
	 * @throws \InvalidArgumentException With the reason.
	 */
	public static function verify( $file, $signature, $public_key ) {
		$raw = base64_decode( trim( (string) $public_key ), true );
		if ( false === $raw || 42 !== strlen( $raw ) || 'Ed' !== substr( $raw, 0, 2 ) ) {
			throw new \InvalidArgumentException( __( 'Chiave pubblica minisign non valida.', 'static-bridge' ) );
		}
		$id  = substr( $raw, 2, 8 );
		$key = substr( $raw, 10, 32 );
		if ( strlen( $signature ) > self::MAX_SIG ) {
			throw new \InvalidArgumentException( __( 'Firma troppo lunga.', 'static-bridge' ) );
		}
		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", str_replace( "\r", '', $signature ) ) ), 'strlen' ) );
		if ( 4 !== count( $lines ) || 0 !== strpos( $lines[0], 'untrusted comment:' ) || 0 !== strpos( $lines[2], 'trusted comment: ' ) ) {
			throw new \InvalidArgumentException( __( 'Firma minisign illeggibile.', 'static-bridge' ) );
		}
		$sig    = base64_decode( $lines[1], true );
		$global = base64_decode( $lines[3], true );
		if ( false === $sig || 74 !== strlen( $sig ) || false === $global || 64 !== strlen( $global ) ) {
			throw new \InvalidArgumentException( __( 'Firma minisign illeggibile.', 'static-bridge' ) );
		}
		if ( 'ED' !== substr( $sig, 0, 2 ) ) {
			throw new \InvalidArgumentException( __( 'Algoritmo di firma sconosciuto.', 'static-bridge' ) );
		}
		if ( ! hash_equals( $id, substr( $sig, 2, 8 ) ) ) {
			throw new \InvalidArgumentException( __( 'Firmato con un’altra chiave.', 'static-bridge' ) );
		}
		$ed25519 = substr( $sig, 10, 64 );
		if ( ! sodium_crypto_sign_verify_detached( $ed25519, self::blake2b( $file ), $key ) ) {
			throw new \InvalidArgumentException( __( 'La firma non corrisponde al file.', 'static-bridge' ) );
		}
		$comment = substr( $lines[2], strlen( 'trusted comment: ' ) );
		if ( ! sodium_crypto_sign_verify_detached( $global, $ed25519 . $comment, $key ) ) {
			throw new \InvalidArgumentException( __( 'Il commento firmato è stato alterato.', 'static-bridge' ) );
		}
		return $comment;
	}

	/** BLAKE2b-512 of a file, read in pieces. */
	private static function blake2b( $file ) {
		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			throw new \InvalidArgumentException( __( 'File da verificare non leggibile.', 'static-bridge' ) );
		}
		$state = sodium_crypto_generichash_init( '', 64 );
		try {
			while ( ! feof( $handle ) ) {
				$chunk = fread( $handle, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( false === $chunk ) {
					break;
				}
				sodium_crypto_generichash_update( $state, $chunk );
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return sodium_crypto_generichash_final( $state, 64 );
	}
}
