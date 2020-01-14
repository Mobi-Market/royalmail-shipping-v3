<?php

declare(strict_types=1);

namespace MobiMarket\RoyalMailShipping\Traits;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Middleware;
use Illuminate\Support\Facades\Log;
use MobiMarket\RoyalMailShipping\Entities\ApiAuth;
use MobiMarket\RoyalMailShipping\Exceptions\RequestFailed;
use MobiMarket\RoyalMailShipping\Exceptions\UnexpectedResponse;
use Psr\Http\Message\ResponseInterface as HttpResponse;
use stdClass;

trait RestApiClient
{
    /**
     * @var HttpClient
     */
    protected $client;

    /**
     * @var ApiAuth
     */
    protected $auth;

    /**
     * Sets up require parameters for the api.
     */
    public function buildClient(
        string $base_uri,
        float $timeout,
        bool $should_log,
        ApiAuth $auth
    ): void {
        $stack = HandlerStack::create();

        if (true === $should_log) {
            $stack->push(
                Middleware::log(
                    Log::getMonolog(),
                    new MessageFormatter('{req_body} - {res_body}')
                )
            );

            $stack->push(
                Middleware::log(
                    Log::getMonolog(),
                    new MessageFormatter('{uri} - {method} - {code}')
                )
            );
        }

        $this->client = new HttpClient([
            // Base URI is used with relative requests
            'base_uri' => $base_uri,
            // You can set any number of default request options.
            'timeout'  => $timeout,
            // handler stack for logging purposes
            'handler' => $stack,
        ]);

        $this->auth = $auth;
        $this->getFreshToken();
    }

    /**
     * Send the request to the API.
     */
    protected function sendAPIRequest(
        string $method,
        string $endpoint,
        ?array $data = null,
        ?array $headers = null,
        bool $dont_refresh = false
    ): ?stdClass {
        $body    = json_encode($data ?? []);
        $headers = $headers ?? [
            'X-IBM-Client-Id'  => $this->auth->client_id,
            'X-RMG-Auth-Token' => $this->auth->token,
        ];

        /**
         * @var HttpResponse
         */
        $response = $this->client->{$method}($endpoint, [
            'body'      => $body,
            'headers'   => [
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ] + $headers,
        ]);

        $code = $response->getStatusCode();

        // Codes from 400 to 5XX are errors
        if ($code >= 400 && $code <= 599) {
            // Refresh + Unauthed
            if (false === $dont_refresh && 401 === $code) {
                $this->getFreshToken();

                // We should have a fresh token, give it another shot.
                // (however we tell it not to continue trying if it fails)
                return $this->sendAPIRequest(
                    $method,
                    $endpoint,
                    $data,
                    $headers,
                    $dont_refresh = true
                );
            }

            throw new RequestFailed($response);
        }

        // Methods either return nothing or json, so the caller
        // should expect an object or null.
        return json_decode((string) $response->getBody());
    }

    protected function sendAPIRequestNotEmpty(
        string $method,
        string $endpoint,
        ?array $data = null,
        ?array $headers = null,
        bool $dont_refresh = false
    ): ?stdClass {
        if ($response = $this->sendAPIRequest($method, $endpoint, $data, $headers, $dont_refresh)) {
        } else {
            throw new UnexpectedResponse('Response is empty');
        }

        return $response;
    }

    /**
     * Send a request to /token to refresh the token for other methods.
     * POST /token.
     */
    protected function getFreshToken(): void
    {
        $headers = [
            'X-IBM-Client-Id'         => $this->auth->client_id,
            'X-IBM-Client-Secret'     => $this->auth->client_secret,
            'X-RMG-Security-Username' => $this->auth->username,
            'X-RMG-Security-Password' => $this->auth->password,
        ];

        // do not refresh, since this IS the refresh request.
        $response = $this->sendAPIRequestNotEmpty('post', 'token', null, $headers, true);

        $this->auth->token = $response->token;
    }
}
