<?php
defined( 'ABSPATH' ) || exit;

/**
 * Voice search — Google Cloud Speech-to-Text proxy.
 *
 * The browser records audio via MediaRecorder, base64-encodes it, and POSTs it
 * to this endpoint. The endpoint forwards it to Google STT and returns only the
 * transcript string. The STT API key never reaches the browser.
 *
 * REST route: POST /wp-json/asc/v1/transcribe
 * Body (JSON): { audio_base64: string, language: string, mime_type: string }
 * Response:    { transcript: string, confidence: int } | { error: string }
 *
 * STT choice rationale (vs Gemini Audio):
 * - Google STT with the `latest_long` model has explicit he-IL support.
 * - Purpose-built for transcription → lower latency and cleaner failure surface.
 * - Cost is per-minute, not per-token; predictable at volume.
 * - Separation of concerns: STT converts audio → text; Gemini then understands
 *   that text as intent. Routing audio through Gemini would create a redundant
 *   understanding step and risk hallucinated "corrections" to the transcript.
 */
class ASC_Voice {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/* ---------------------------------------------------------------- */
	/* REST route                                                        */
	/* ---------------------------------------------------------------- */

	public function register_routes(): void {
		register_rest_route( 'asc/v1', '/transcribe', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_transcribe' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function handle_transcribe( WP_REST_Request $request ): WP_REST_Response {
		$settings = ASC_Settings::instance();
		$stt_key  = trim( (string) $settings->get( 'stt_api_key', '' ) );

		if ( empty( $stt_key ) ) {
			return new WP_REST_Response( [ 'error' => 'not_configured' ], 503 );
		}

		$body      = $request->get_json_params() ?: [];
		$audio_b64 = $body['audio_base64'] ?? '';
		$language  = sanitize_text_field( $body['language']  ?? 'he-IL' );
		$mime_type = sanitize_text_field( $body['mime_type'] ?? 'audio/webm;codecs=opus' );

		// Guard: audio present and not oversized (~2 MB ≈ 90 s at 24 kbps webm/opus)
		if ( empty( $audio_b64 ) || strlen( $audio_b64 ) > 2097152 ) {
			return new WP_REST_Response( [ 'error' => 'invalid_audio' ], 400 );
		}

		// Allowlist language codes to prevent injection
		$allowed_langs = [ 'he-IL', 'he', 'en-US', 'ar' ];
		if ( ! in_array( $language, $allowed_langs, true ) ) {
			$language = 'he-IL';
		}

		// Map browser MIME type → Google STT encoding
		$encoding = $this->mime_to_encoding( $mime_type );
		if ( ! $encoding ) {
			return new WP_REST_Response( [ 'error' => 'unsupported_format' ], 400 );
		}

		$stt_url  = 'https://speech.googleapis.com/v1/speech:recognize?key=' . rawurlencode( $stt_key );
		$stt_body = [
			'config' => [
				'encoding'                   => $encoding,
				'languageCode'               => $language,
				'alternativeLanguageCodes'   => [ 'he' ],
				'model'                      => 'latest_long',
				'enableAutomaticPunctuation' => true,
				'useEnhanced'                => true,
			],
			'audio'  => [ 'content' => $audio_b64 ],
		];

		$response = wp_remote_post( $stt_url, [
			'timeout' => 15,
			'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
			'body'    => wp_json_encode( $stt_body ),
		] );

		if ( is_wp_error( $response ) ) {
			return new WP_REST_Response( [ 'error' => 'service_unavailable' ], 503 );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			error_log( 'ASC Voice: STT returned HTTP ' . $code );
			return new WP_REST_Response( [ 'error' => 'service_unavailable' ], 503 );
		}

		$data    = json_decode( wp_remote_retrieve_body( $response ), true );
		$results = $data['results'] ?? [];

		if ( empty( $results ) ) {
			// STT returned OK but nothing heard — silence / noise floor
			return new WP_REST_Response( [ 'transcript' => '', 'confidence' => 0 ], 200 );
		}

		$best = $results[0]['alternatives'][0] ?? [];
		return new WP_REST_Response( [
			'transcript' => $best['transcript'] ?? '',
			'confidence' => isset( $best['confidence'] ) ? (int) round( $best['confidence'] * 100 ) : 0,
		], 200 );
	}

	/* ---------------------------------------------------------------- */
	/* Helpers                                                           */
	/* ---------------------------------------------------------------- */

	private function mime_to_encoding( string $mime ): string {
		// Normalize: lowercase, remove spaces around semicolons
		$normalized = preg_replace( '/\s*;\s*/', ';', strtolower( trim( $mime ) ) );

		$map = [
			'audio/webm'             => 'WEBM_OPUS',
			'audio/webm;codecs=opus' => 'WEBM_OPUS',
			'audio/ogg'              => 'OGG_OPUS',
			'audio/ogg;codecs=opus'  => 'OGG_OPUS',
		];

		return $map[ $normalized ] ?? '';
	}
}
