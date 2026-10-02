<?php
/**
 * Lightweight Resend email sender (no Composer dependency).
 *
 * The API key is read from the RESEND_API_KEY environment variable (set this on
 * Railway). For local development, create a git-ignored config/mail.local.php
 * that defines RESEND_API_KEY_LOCAL (and optionally MAIL_FROM_LOCAL).
 *
 * Never hardcode the key in a committed file.
 */

if (!function_exists('alke_mail_config')) {
    // Load the local key file once (dev only; git-ignored, never deployed).
    $__mailLocal = __DIR__ . '/../config/mail.local.php';
    if (is_file($__mailLocal)) {
        require_once $__mailLocal;
    }
    unset($__mailLocal);

    function alke_resend_key(): string
    {
        $k = getenv('RESEND_API_KEY');
        if (is_string($k) && trim($k) !== '') {
            return trim($k);
        }
        if (defined('RESEND_API_KEY_LOCAL') && RESEND_API_KEY_LOCAL !== '') {
            return (string)RESEND_API_KEY_LOCAL;
        }
        return '';
    }

    function alke_mail_from(): string
    {
        $f = getenv('MAIL_FROM');
        if (is_string($f) && trim($f) !== '') {
            return trim($f);
        }
        if (defined('MAIL_FROM_LOCAL') && MAIL_FROM_LOCAL !== '') {
            return (string)MAIL_FROM_LOCAL;
        }
        // Resend's shared test sender. Note: it can ONLY deliver to the email
        // that owns the Resend account until you verify your own domain.
        return 'Alke <onboarding@resend.dev>';
    }

    /** Marker so the function block isn't redefined. */
    function alke_mail_config(): bool { return true; }
}

if (!function_exists('alke_send_email')) {
    /**
     * Send an HTML email via Resend. Returns true on success.
     * Fails gracefully (logs, returns false) — never throws into page flow.
     *
     * @param string|string[] $to
     */
    function alke_send_email($to, string $subject, string $html, ?string $from = null, ?string $replyTo = null): bool
    {
        $key = alke_resend_key();
        if ($key === '') {
            error_log('Resend: RESEND_API_KEY is not configured — email skipped.');
            return false;
        }

        $data = [
            'from'    => $from ?: alke_mail_from(),
            'to'      => is_array($to) ? array_values($to) : [$to],
            'subject' => $subject,
            'html'    => $html,
        ];
        // Where customer replies go. Override per-call or via MAIL_REPLY_TO env.
        $reply = $replyTo ?: (getenv('MAIL_REPLY_TO') ?: '');
        if ($reply !== '') {
            $data['reply_to'] = $reply;
        }
        $payload = json_encode($data);

        // Prefer curl; fall back to a stream context if curl isn't available.
        if (function_exists('curl_init')) {
            $ch = curl_init('https://api.resend.com/emails');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $key,
                    'Content-Type: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
            ]);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($resp === false) {
                error_log('Resend curl error: ' . $err);
                return false;
            }
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => "Authorization: Bearer {$key}\r\nContent-Type: application/json\r\n",
                    'content'       => $payload,
                    'timeout'       => 15,
                    'ignore_errors' => true,
                ],
            ]);
            $resp = @file_get_contents('https://api.resend.com/emails', false, $ctx);
            $code = 0;
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                $code = (int)$m[1];
            }
            if ($resp === false) {
                error_log('Resend stream error sending email.');
                return false;
            }
        }

        if ($code >= 200 && $code < 300) {
            return true;
        }
        error_log('Resend send failed (HTTP ' . $code . '): ' . $resp);
        return false;
    }
}
