<?php
declare(strict_types=1);

function waitlist_mail_settings(array $config, string $email): void
{
    if (empty($config['resend_api_key']) || empty($config['resend_from'])) {
        throw new WaitlistError(503, 'mail_unavailable', 'Email verification is temporarily unavailable. Your details are saved. Please try again shortly.');
    }
    $restricted = strtolower(trim($config['resend_test_recipient'] ?? ''));
    if ($restricted !== '' && !hash_equals($restricted, $email)) {
        throw new WaitlistError(503, 'mail_test_mode', 'This preview sends verification emails only to the configured test inbox.');
    }
}

function waitlist_verification_payload(array $config, string $email, string $code): array
{
    $payload = [
        'from' => $config['resend_from'], 'to' => [$email],
        'subject' => 'Your Magasaka verification code',
    ];
    if (!empty($config['resend_verification_template'])) {
        $payload['template'] = ['id' => $config['resend_verification_template'],
            'variables' => ['CODE' => $code, 'EXPIRES_MINUTES' => 10]];
    } else {
        // Plain-text fallback works with a sending-only key. Published HTML lives in Resend.
        $payload['text'] = "Your Magasaka verification code is: $code\n\n"
            . "Enter this code on the waitlist page to confirm your email and get your card. "
            . "It expires 10 minutes after it was requested.\n\n"
            . "If you did not request this code, you can ignore this email. Do not share the code.\n\n"
            . "Magasaka";
    }
    return $payload;
}

function waitlist_resend_retry_delay(?\Psr\Http\Message\ResponseInterface $response): ?int
{
    $value = $response?->getHeaderLine('Retry-After') ?? '';
    if ($value === '') { return null; }
    return ctype_digit($value) ? (int) $value : max(0, (strtotime($value) ?: time()) - time());
}

function waitlist_resend_client(string $key, ?callable $handler = null): \Resend\Client
{
    require_once __DIR__ . '/../vendor/autoload.php';
    $stack = \GuzzleHttp\HandlerStack::create($handler);
    $stack->push(\GuzzleHttp\Middleware::retry(
        static function (int $retries, $request, $response = null, $error = null): bool {
            if ($retries >= 2) { return false; }
            // Longer provider waits are left for a later request, never retried early.
            if ((waitlist_resend_retry_delay($response) ?? 0) > 5) { return false; }
            return $error instanceof \GuzzleHttp\Exception\ConnectException
                || ($response && ($response->getStatusCode() === 429 || $response->getStatusCode() >= 500));
        },
        static function (int $retries, $response = null): int {
            return max((int) (500 * (2 ** ($retries - 1))),
                (waitlist_resend_retry_delay($response) ?? 0) * 1000);
        }
    ));
    $http = new \GuzzleHttp\Client(['handler' => $stack, 'timeout' => 6, 'connect_timeout' => 3]);
    // Pin the provider host: browser input and environment cannot redirect the API secret.
    return new \Resend\Client(new \Resend\Transporters\HttpTransporter($http,
        \Resend\ValueObjects\Transporter\BaseUri::from('api.resend.com'),
        \Resend\ValueObjects\Transporter\Headers::withAuthorization(\Resend\ValueObjects\ApiKey::from($key))));
}

function waitlist_send_verification_email(array $config, array $payload, string $requestId): string
{
    try {
        $mail = waitlist_resend_client($config['resend_api_key'])->emails->send($payload,
            ['idempotency_key' => 'waitlist-verification/' . $requestId]);
        if (!is_string($mail->id) || $mail->id === '') { throw new RuntimeException('Missing provider ID'); }
        return $mail->id;
    } catch (Throwable $error) {
        // No addresses, codes, payloads, credentials or provider response bodies in logs.
        $code = $error instanceof \Resend\Exceptions\ErrorException ? $error->getErrorCode() : 0;
        error_log('Waitlist email failed: ' . get_class($error) . ' [' . $code . ']');
        throw new WaitlistError(503, 'mail_unavailable',
            'We could not send your code. Your details are saved. Please wait a minute and try again.');
    }
}
