<?php declare(strict_types=1);

namespace Pecunia;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\GuzzleException;
use Pecunia\Exceptions\ApiException;
use Pecunia\Exceptions\BadRequestException;
use Pecunia\Exceptions\NotFoundException;
use Pecunia\Exceptions\TooManyRequestsException;
use Pecunia\Exceptions\UnauthorizedException;
use Pecunia\Models\Invoice;
use Pecunia\Models\InvoiceRef;
use Pecunia\Models\ItemList;
use Pecunia\Models\Settings;
use Pecunia\Models\Currency;
use Pecunia\Models\Transaction;
use Psr\Http\Message\ResponseInterface;

class Client {
    private Guzzle $http;
    private string $token;
    private string $baseUri;
    public function __construct(
        string $token,
        string $baseUri = 'https://pecuniawallet.com/api/',
        array $guzzleOptions = []
    ) {
        $this->token = $token;
        $this->baseUri = $baseUri;
        $this->http = new Guzzle(array_merge([
            'base_uri' => $this->baseUri,
            'timeout' => 10.0,
            'http_errors' => false
        ], $guzzleOptions));
    }

    private function request(string $method, string $path, array $options = [], int $retries = 1): ResponseInterface {
    $opts = $options;
    $opts['headers'] = array_merge($opts['headers'] ?? [], [
        'X-Api-Token' => $this->token,
        'Accept' => 'application/json'
    ]);
    $attempt = 0;
    while (true) {
        try {
            $res = $this->http->request($method, ltrim($path, '/'), $opts);
            $status = $res->getStatusCode();
            if ($status >= 400) {
                $payload = $this->decodeJson($res);
                $this->throwForStatus($status, $res, $payload);
            }
            return $res;
        } catch (GuzzleException $e) {
            if ($attempt < $retries) {
                $attempt++;
                usleep(100_000 * $attempt);
                continue;
            }
            throw new ApiException('Network error: ' . $e->getMessage(), 0);
        }
    }
    }

    private function decodeJson(ResponseInterface $res): ?array {
        $body = (string)$res->getBody();
        if ($body === '') return null;
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }
    private function throwForStatus(int $status, ResponseInterface $res, ?array $payload = null): void {
        $msg = $payload[0]['message'] ?? ($payload['message'] ?? 'API error');
        if ($status === 400) throw new BadRequestException($msg, 400, $res, $payload);
        if ($status === 401 || $status === 403) throw new UnauthorizedException($msg, $status, $res, $payload);
        if ($status === 404) throw new NotFoundException($msg, 404, $res, $payload);
        if ($status === 429) throw new TooManyRequestsException($msg, 429, $res, $payload);
        throw new ApiException($msg, $status, $res, $payload);
    }

    public function getAllInvoices(
        ?string $search = null,
        ?string $filter = null,
        int $pageSize = 20,
        int $pageNum = 0,
        ?string $sort = null,
        ?string $order = null
    ): ItemList
    {
        $query = array_filter([
            'search' => $search,
            'filter' => $filter,
            'pageSize' => $pageSize,
            'pageNum' => $pageNum,
            'sort' => $sort,
            'order' => $order
        ], fn($v) => $v !== null && $v !== '');

        $res = $this->request('GET', '/invoices', ['query' => $query], 3);
        $payload = json_decode((string)$res->getBody(), true) ?: [];
        $items = [];
        foreach ($payload as $item) {
            $items[] = Invoice::fromArray($item);
        }
        $total = (int)($res->getHeaderLine('X-Total-Count') ?: 0);
        $remaining = (int)($res->getHeaderLine('X-Items-Remaining') ?: 0);

        return new ItemList($items, $total, $remaining);
    }

    public function createInvoice(array $body): InvoiceRef
    {
        $res = $this->request('POST', '/invoices', ['json' => $body], 0);
        return InvoiceRef::fromArray(json_decode((string)$res->getBody(), true) ?: []);
    }

    public function getInvoice(string $id, ?bool $includeMeta = true, ?string $txView = null): Invoice
    {
        $query = array_filter(['includeMeta' => $includeMeta ? 'true' : 'false', 'txView' => $txView], fn($v) => $v !== null);
        $res = $this->request('GET', "/invoices/{$id}", ['query' => $query], 3);
        $payload = json_decode((string)$res->getBody(), true) ?: [];
        return Invoice::fromArray($payload);
    }

    public function getBalances(
        ?string $query = null,
        ?string $mask = null,
    ): array
    {
        $params = array_filter([
            'coin' => $query,
            'mask' => $mask,
        ], fn($v) => $v !== null && $v !== '');

        $res = $this->request('GET', '/balances', ['query' => $params], 3);
        return json_decode((string)$res->getBody(), true) ?: [];
    }

    public function getAllTransactions(string $coin, ?string $mask = null, ?string $sort = null, ?string $order = null, int $pageSize = 50, int $pageNum = 0): array
    {
        $query = array_filter([
            'coin' => $coin,
            'mask' => $mask,
            'sort' => $sort,
            'order' => $order,
            'pageSize' => $pageSize,
            'pageNum' => $pageNum
        ], fn($v) => $v !== null && $v !== '');
        $res = $this->request('GET', '/transactions', ['query' => $query], 3);

        return json_decode((string)$res->getBody(), true) ?: [];
    }

    public function createTransaction(string $coin, array $body): array
    {
        $res = $this->request('POST', '/transactions', ['query' => ['coin' => $coin], 'json' => $body], 1);
        return json_decode((string)$res->getBody(), true) ?: [];
    }

    public function getTransaction(string $id, string $coin, ?string $mask = null): array
    {
        $query = array_filter(['coin' => $coin, 'mask' => $mask], fn($v) => $v !== null);
        $res = $this->request('GET', "/transactions/{$id}", ['query' => $query], 3);
        return json_decode((string)$res->getBody(), true) ?: [];
    }

    public function getSettings(?array $query = null): Settings
    {
        if ($query) {
            $query = join(',', $query);
        }
        $params = array_filter(['query' => $query], fn($v) => $v !== null);
        $res = $this->request('GET', '/settings', ['query' => $params], 3);
        $payload = json_decode((string)$res->getBody(), true) ?: [];
        return Settings::fromArray($payload);
    }

    public function updateSettings(array $patch): Settings
    {
        $res = $this->request('PATCH', '/settings', ['json' => $patch], 1);
        $payload = json_decode((string)$res->getBody(), true) ?: [];
        return Settings::fromArray($payload);
    }

    public function replaceSettings(array $body): void
    {
        $this->request('PUT', '/settings', ['json' => $body], 1);
    }

    public function getAllCurrencies(?string $type = null, ?string $model = null): array
    {
        $query = array_filter(['type' => $type, 'model' => $model], fn($v) => $v !== null);
        $res = $this->request('GET', '/currencies', ['query' => $query], 3);
        $payload = json_decode((string)$res->getBody(), true) ?: [];
        $out = [];
        foreach ($payload as $c) {
            $out[] = Currency::fromArray($c);
        }
        return $out;
    }

    public function findCurrencies(array $filters = []): array
    {
        $res = $this->request('GET', '/currencies/search', ['query' => $filters], 3);
        $payload = json_decode((string)$res->getBody(), true) ?: [];
        $out = [];
        foreach ($payload as $c) {
            $out[] = Currency::fromArray($c);
        }
        return $out;
    }
}
