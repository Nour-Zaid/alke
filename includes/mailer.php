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

if (!function_exists('alke_email_template')) {
    /**
     * Wrap email body content in Alke's branded, email-client-safe layout.
     * Uses tables + inline styles only (no <style>, no fl/grid) so it renders
     * consistently in Gmail, Outlook, Apple Mail, etc.
     *
     * @param string $heading    Big heading shown at the top of the card.
     * @param string $bodyHtml   Inner HTML (paragraphs, tables, etc.).
     * @param string $preheader  Hidden inbox-preview text (optional).
     */
    function alke_email_template(string $heading, string $bodyHtml, string $preheader = ''): string
    {
        $accent = '#b8916a';
        $ink    = '#0f0f0f';
        $muted  = '#8a8a8a';
        $border = '#ececec';
        $year   = date('Y');
        $pre    = $preheader !== '' ? htmlspecialchars($preheader) : '';

        return '<!DOCTYPE html><html lang="en"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light only"></head>'
            . '<body style="margin:0;padding:0;background:#f4f4f5;">'
            // Hidden preheader (inbox preview snippet)
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $pre . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;">'
            . '<tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid ' . $border . ';border-radius:10px;overflow:hidden;">'
            // Header
            . '<tr><td style="background:' . $ink . ';padding:26px 32px;text-align:center;">'
            . '<span style="font-family:Georgia,\'Times New Roman\',serif;font-size:26px;letter-spacing:7px;color:#ffffff;font-weight:700;">ALKE</span>'
            . '<div style="height:2px;width:40px;background:' . $accent . ';margin:10px auto 0;"></div>'
            . '</td></tr>'
            // Body
            . '<tr><td style="padding:32px;font-family:Arial,Helvetica,sans-serif;color:#2a2a2a;font-size:15px;line-height:1.6;">'
            . '<h1 style="margin:0 0 16px;font-family:Georgia,\'Times New Roman\',serif;font-size:22px;color:' . $ink . ';font-weight:700;">' . $heading . '</h1>'
            . $bodyHtml
            . '</td></tr>'
            // Footer
            . '<tr><td style="padding:22px 32px;border-top:1px solid ' . $border . ';font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.7;color:' . $muted . ';text-align:center;">'
            . 'Questions? Just reply to this email and we\'ll help.<br>'
            . '<a href="https://alkejo.com" style="color:' . $accent . ';text-decoration:none;">alkejo.com</a>'
            . ' &nbsp;·&nbsp; &copy; ' . $year . ' Alke'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
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
