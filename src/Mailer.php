<?php
declare(strict_types=1);

namespace SportCard101;

/**
 * Tiny zero-dependency mailer. Sends via authenticated SMTP when SMTP settings
 * are configured (reliable inbox delivery, e.g. a Hostinger mailbox), otherwise
 * falls back to PHP mail(). Reads all config from the settings table.
 *
 * Settings keys: smtp_host, smtp_port, smtp_secure (tls|ssl|none),
 * smtp_user, smtp_pass, notify_from, notify_from_name.
 */
final class Mailer
{
    public static ?string $lastError = null;

    /**
     * Send an email. Pass $html to send a multipart/alternative message where
     * $body is the plain-text fallback and $html is the rich version.
     */
    public static function send(string $to, string $subject, string $body, ?string $html = null): bool
    {
        self::$lastError = null;
        $from     = trim((string) \setting('notify_from', '')) ?: 'alerts@sportcard101.com';
        $fromName = trim((string) \setting('notify_from_name', '')) ?: 'SportCard101';
        $host     = trim((string) \setting('smtp_host', ''));

        if ($host === '') {
            return self::viaMail($to, $subject, $body, $html, $from, $fromName);
        }
        $port   = (int) (\setting('smtp_port', '587') ?: 587);
        $secure = strtolower((string) \setting('smtp_secure', 'tls'));
        $user   = (string) \setting('smtp_user', '');
        $pass   = (string) \setting('smtp_pass', '');

        return self::viaSmtp($host, $port, $secure, $user, $pass, $from, $fromName, $to, $subject, $body, $html);
    }

    /**
     * Content headers + message body: plain text only, or multipart/alternative
     * (text fallback + HTML) when an HTML version is provided.
     *
     * @return array{0:string,1:string} [content headers (CRLF-terminated), body]
     */
    private static function mimeParts(string $body, ?string $html): array
    {
        if ($html === null) {
            return ["Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n", $body];
        }
        // Quoted-printable keeps every line under the 998-byte SMTP limit
        // (the generated HTML is otherwise one long line) and makes the
        // non-ASCII text (·, —, accented names) 7-bit safe.
        $qp       = fn (string $s): string => quoted_printable_encode(preg_replace('/\r\n?|\n/', "\r\n", $s) ?? $s);
        $boundary = 'sc101-' . bin2hex(random_bytes(12));
        $mixed =
            "--{$boundary}\r\n" .
            "Content-Type: text/plain; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: quoted-printable\r\n\r\n" .
            $qp($body) . "\r\n\r\n" .
            "--{$boundary}\r\n" .
            "Content-Type: text/html; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: quoted-printable\r\n\r\n" .
            $qp($html) . "\r\n\r\n" .
            "--{$boundary}--";
        return ["Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n", $mixed];
    }

    private static function viaMail(string $to, string $subject, string $body, ?string $html, string $from, string $fromName): bool
    {
        [$typeHeaders, $payload] = self::mimeParts($body, $html);
        $headers = 'From: ' . self::encHeader($fromName) . ' <' . $from . ">\r\n"
                 . 'Reply-To: ' . $from . "\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . $typeHeaders;
        $ok = @\mail($to, $subject, $payload, $headers);
        if (!$ok) {
            self::$lastError = 'PHP mail() returned false (host may block it — configure SMTP).';
        }
        return $ok;
    }

    private static function viaSmtp(string $host, int $port, string $secure, string $user, string $pass, string $from, string $fromName, string $to, string $subject, string $body, ?string $html = null): bool
    {
        $transport = $secure === 'ssl' ? "ssl://{$host}:{$port}" : "{$host}:{$port}";
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $fp = @stream_socket_client($transport, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            self::$lastError = "connect failed: {$errstr} ({$errno})";
            return false;
        }
        stream_set_timeout($fp, 20);

        $read = function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $ok = fn (string $resp, $codes): bool => in_array((int)substr($resp, 0, 3), (array)$codes, true);
        $say = function (string $c) use ($fp): void { fwrite($fp, $c . "\r\n"); };

        $ehlo = $_SERVER['SERVER_NAME'] ?? 'sportcard101.com';

        if (!$ok($read(), 220)) { self::$lastError = 'no greeting'; fclose($fp); return false; }
        $say("EHLO {$ehlo}");
        if (!$ok($read(), 250)) { self::$lastError = 'EHLO rejected'; fclose($fp); return false; }

        if ($secure === 'tls') {
            $say('STARTTLS');
            if (!$ok($read(), 220)) { self::$lastError = 'STARTTLS rejected'; fclose($fp); return false; }
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) { $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT; }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) { $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT; }
            if (!@stream_socket_enable_crypto($fp, true, $crypto)) { self::$lastError = 'TLS negotiation failed'; fclose($fp); return false; }
            $say("EHLO {$ehlo}");
            if (!$ok($read(), 250)) { self::$lastError = 'EHLO after TLS rejected'; fclose($fp); return false; }
        }

        if ($user !== '') {
            $say('AUTH LOGIN');
            if (!$ok($read(), 334)) { self::$lastError = 'AUTH LOGIN not accepted'; fclose($fp); return false; }
            $say(base64_encode($user));
            if (!$ok($read(), 334)) { self::$lastError = 'username rejected'; fclose($fp); return false; }
            $say(base64_encode($pass));
            if (!$ok($read(), 235)) { self::$lastError = 'authentication failed — check SMTP user/password'; fclose($fp); return false; }
        }

        $say("MAIL FROM:<{$from}>");
        if (!$ok($read(), 250)) { self::$lastError = 'MAIL FROM rejected'; fclose($fp); return false; }
        $say("RCPT TO:<{$to}>");
        if (!$ok($read(), [250, 251])) { self::$lastError = 'recipient rejected'; fclose($fp); return false; }
        $say('DATA');
        if (!$ok($read(), 354)) { self::$lastError = 'DATA rejected'; fclose($fp); return false; }

        $date  = gmdate('D, d M Y H:i:s') . ' +0000';
        $msgid = '<' . bin2hex(random_bytes(10)) . '@' . $ehlo . '>';
        [$typeHeaders, $mimeBody] = self::mimeParts($body, $html);
        $headers =
            "Date: {$date}\r\n" .
            'From: ' . self::encHeader($fromName) . " <{$from}>\r\n" .
            "To: <{$to}>\r\n" .
            'Subject: ' . self::encHeader($subject) . "\r\n" .
            "Message-ID: {$msgid}\r\n" .
            "MIME-Version: 1.0\r\n" .
            $typeHeaders;
        $payload = $headers . "\r\n" . self::dotStuff($mimeBody) . "\r\n.\r\n";
        fwrite($fp, $payload);
        if (!$ok($read(), 250)) { self::$lastError = 'message not accepted'; fclose($fp); return false; }

        $say('QUIT');
        fclose($fp);
        return true;
    }

    /** Normalise line endings to CRLF and dot-stuff lines beginning with a dot. */
    private static function dotStuff(string $body): string
    {
        $b = preg_replace('/\r\n?|\n/', "\r\n", $body) ?? $body;
        return preg_replace('/^\./m', '..', $b) ?? $b;
    }

    /** RFC 2047 encode a header value only if it contains non-ASCII. */
    private static function encHeader(string $v): string
    {
        if (preg_match('/[^\x20-\x7E]/', $v)) {
            return '=?UTF-8?B?' . base64_encode($v) . '?=';
        }
        return $v;
    }
}
