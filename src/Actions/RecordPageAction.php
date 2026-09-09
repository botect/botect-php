<?php

declare(strict_types=1);

namespace Botect\Actions;

use Botect\Configuration;
use Botect\Contracts\Dispatcher;
use Botect\Delivery;
use Botect\Operation;
use Botect\Page;
use Throwable;

final readonly class RecordPageAction
{
    private const HEADERS = ['accept', 'accept-language', 'accept-encoding', 'cache-control', 'connection', 'cookie', 'host', 'pragma', 'referer', 'sec-ch-ua', 'sec-ch-ua-mobile', 'sec-ch-ua-platform', 'sec-fetch-dest', 'sec-fetch-mode', 'sec-fetch-site', 'sec-fetch-user', 'upgrade-insecure-requests', 'user-agent'];

    public function __construct(private Configuration $configuration, private Dispatcher $dispatcher) {}

    /** Header names are best-effort PHP observation order, never original wire order.
     * @param  list<string>  $headerNames
     */
    public function execute(Page $page, string $method, string $path, array $headerNames = [], string $acceptLanguage = '', int $durationMs = 0): bool
    {
        if (! $this->configuration->serverIngestEnabled) {
            return false;
        }
        try {
            $method = strtoupper($method);
            if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true)) {
                $method = 'OTHER';
            }
            $headers = [];
            foreach ($headerNames as $name) {
                $name = strtolower($name);
                if (in_array($name, self::HEADERS, true) && ! in_array($name, $headers, true)) {
                    $headers[] = $name;
                }
            }
            $languages = [];
            foreach (explode(',', substr($acceptLanguage, 0, 256)) as $item) {
                $language = strtolower(trim(explode(';', $item, 2)[0]));
                if (preg_match('/^[a-z]{2,3}(?:-[a-z]{2}|-[0-9]{3})?$/D', $language)) {
                    $languages[] = $language;
                }
            }
            $path = explode('#', explode('?', $path, 2)[0], 2)[0];
            $payload = [
                'schema_version' => 1,
                'page_id' => $page->id,
                'session_token' => $page->sessionToken,
                'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', $page->issuedAt),
                'method' => $method,
                'path_hash' => hash_hmac('sha256', $path, (string) $this->configuration->privateKey),
                'header_names' => $headers,
                'languages' => array_slice(array_values(array_unique($languages)), 0, 10),
                'had_session_cookie' => $page->hadSessionCookie,
                'duration_ms' => max(0, min(86400000, $durationMs)),
                'ja4' => null,
            ];

            return $this->dispatcher->dispatch(Delivery::make(Operation::RecordPage, $page->sessionToken, $payload, $page->id));
        } catch (Throwable) {
            return false;
        }
    }
}
