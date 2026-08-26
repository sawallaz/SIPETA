<?php

namespace App\Services;

use App\Exceptions\GoogleDriveException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class GoogleDriveOAuthService
{
    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    public function __construct(private SettingsService $settings) {}

    public function redirectUri(?string $customUri = null): string
    {
        if (filled($customUri)) {
            return $customUri;
        }

        $configured = config('services.google_drive.redirect_uri');
        if (filled($configured)) {
            return (string) $configured;
        }

        if (function_exists('route') && Route::has('google-drive.callback')) {
            return route('google-drive.callback');
        }

        return url('/admin/backup/google/callback');
    }

    public function authorizationUrl(string $state, ?string $redirectUri = null): string
    {
        $this->assertConfigured();

        $uri = $this->redirectUri($redirectUri);

        return (string) config('services.google_drive.auth_uri').'?'.http_build_query([
            'client_id' => config('services.google_drive.client_id'),
            'redirect_uri' => $uri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * Exchange a one-time authorization code. The authorization code is never
     * persisted or logged.
     *
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code, ?string $redirectUri = null): array
    {
        $this->assertConfigured();

        $uri = $this->redirectUri($redirectUri);

        try {
            $response = Http::asForm()
                ->timeout((int) config('services.google_drive.timeout', 120))
                ->withOptions(['verify' => base_path('resources/php/cacert.pem')])
                ->post((string) config('services.google_drive.token_uri'), [
                    'code' => $code,
                    'client_id' => config('services.google_drive.client_id'),
                    'client_secret' => config('services.google_drive.client_secret'),
                    'redirect_uri' => $uri,
                    'grant_type' => 'authorization_code',
                ]);
        } catch (ConnectionException $e) {
            throw new GoogleDriveException('Koneksi Google Drive gagal.', 0, $e);
        }

        if ($response->failed()) {
            \Illuminate\Support\Facades\Log::error('Google OAuth token exchange failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'redirect_uri_sent' => $uri,
            ]);
            throw new GoogleDriveException('Otorisasi Google Drive gagal.', $response->status(), null, $response->status());
        }

        $payload = $response->json();
        if (! is_array($payload) || blank($payload['access_token'] ?? null)) {
            throw new GoogleDriveException('Respons token Google Drive tidak valid.');
        }

        return [
            'access_token' => (string) $payload['access_token'],
            'refresh_token' => filled($payload['refresh_token'] ?? null)
                ? (string) $payload['refresh_token']
                : null,
            'expires_at' => now()->addSeconds((int) ($payload['expires_in'] ?? 3600))->toIso8601String(),
            'token_type' => (string) ($payload['token_type'] ?? 'Bearer'),
        ];
    }

    public function accessToken(bool $forceRefresh = false): string
    {
        $credentials = $this->credentials();
        $expiresAt = isset($credentials['expires_at']) ? now()->parse($credentials['expires_at']) : now()->subMinute();

        if (! $forceRefresh && filled($credentials['access_token'] ?? null) && $expiresAt->gt(now()->addSeconds(60))) {
            return (string) $credentials['access_token'];
        }

        if (blank($credentials['refresh_token'] ?? null)) {
            throw new GoogleDriveException('Token Google perlu diotorisasi ulang.');
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('services.google_drive.timeout', 120))
                ->withOptions(['verify' => base_path('resources/php/cacert.pem')])
                ->post((string) config('services.google_drive.token_uri'), [
                    'client_id' => config('services.google_drive.client_id'),
                    'client_secret' => config('services.google_drive.client_secret'),
                    'refresh_token' => $credentials['refresh_token'],
                    'grant_type' => 'refresh_token',
                ]);
        } catch (ConnectionException $e) {
            throw new GoogleDriveException('Koneksi Google Drive gagal.', 0, $e);
        }

        if ($response->failed()) {
            if (in_array($response->status(), [400, 401, 403], true)) {
                throw new GoogleDriveException('Token Google perlu diotorisasi ulang.', $response->status(), null, $response->status());
            }

            throw new GoogleDriveException('Token Google Drive gagal diperbarui.', $response->status(), null, $response->status());
        }

        $payload = $response->json();
        if (! is_array($payload) || blank($payload['access_token'] ?? null)) {
            throw new GoogleDriveException('Respons refresh token Google Drive tidak valid.');
        }

        $credentials['access_token'] = (string) $payload['access_token'];
        $credentials['expires_at'] = now()->addSeconds((int) ($payload['expires_in'] ?? 3600))->toIso8601String();
        $this->settings->updateGoogleDriveCredentials($credentials);

        return $credentials['access_token'];
    }

    /** @return array<string, mixed> */
    public function credentials(): array
    {
        $credentials = $this->settings->get()->google_drive_credentials;

        if (! is_array($credentials)) {
            throw new GoogleDriveException('Google Drive belum terhubung.');
        }

        return $credentials;
    }

    /** @param array<string, mixed> $credentials */
    public function storeCredentials(array $credentials): void
    {
        $this->settings->updateGoogleDriveCredentials($credentials);
    }

    public function disconnect(): void
    {
        $this->settings->disconnectGoogleDrive();
        Log::info('Google Drive disconnected.');
    }

    public function newState(): string
    {
        return Str::random(64);
    }

    private function assertConfigured(): void
    {
        if (blank(config('services.google_drive.client_id')) || blank(config('services.google_drive.client_secret'))) {
            throw new GoogleDriveException('Konfigurasi OAuth Google Drive belum lengkap.');
        }
    }
}
