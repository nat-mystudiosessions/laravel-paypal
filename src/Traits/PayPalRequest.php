<?php

namespace Srmklive\PayPal\Traits;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\BadResponseException as HttpBadResponseException;
use GuzzleHttp\Exception\ClientException as HttpClientException;
use GuzzleHttp\Exception\ServerException as HttpServerException;
use Illuminate\Support\Collection;

trait PayPalRequest
{
    /**
     * Http Client class object.
     *
     * @var HttpClient
     */
    private $client;

    /**
     * Http Client configuration.
     *
     * @var array
     */
    private $httpClientConfig;

    /**
     * PayPal API Certificate data for authentication.
     *
     * @var string
     */
    private $certificate;

    /**
     * PayPal API mode to be used.
     *
     * @var string
     */
    public $mode;

    /**
     * Request data to be sent to PayPal.
     *
     * @var \Illuminate\Support\Collection
     */
    protected $post;

    /**
     * PayPal API configuration.
     *
     * @var array
     */
    private $config;

    /**
     * Default currency for PayPal.
     *
     * @var string
     */
    private $currency;

    /**
     * Additional options for PayPal API request.
     *
     * @var array
     */
    private $options;

    /**
     * Default payment action for PayPal.
     *
     * @var string
     */
    private $paymentAction;

    /**
     * Default locale for PayPal.
     *
     * @var string
     */
    private $locale;

    /**
     * PayPal API Endpoint.
     *
     * @var string
     */
    private $apiUrl;

    /**
     * IPN notification url for PayPal.
     *
     * @var string
     */
    private $notifyUrl;

    /**
     * Http Client request body parameter name.
     *
     * @var string
     */
    private $httpBodyParam;

    /**
     * Validate SSL details when creating HTTP client.
     *
     * @var bool
     */
    private $validateSSL;

    /**
     * Function To Set PayPal API Configuration.
     *
     * @param array $config
     *
     * @throws \Exception
     */
    private function setConfig(array $config = [])
    {
        // Set Api Credentials
        if (function_exists('config')) {
            $this->setApiCredentials(
                config('paypal')
            );
        } elseif (!empty($config)) {
            $this->setApiCredentials($config);
        }

        $this->setRequestData();
    }

    /**
     * Set default values for configuration.
     *
     * @return void
     */
    private function setDefaultValues()
    {
        // Set default payment action.
        if (empty($this->paymentAction)) {
            $this->paymentAction = 'Sale';
        }

        // Set default locale.
        if (empty($this->locale)) {
            $this->locale = 'en_US';
        }

        // Set default value for SSL validation.
        if (empty($this->validateSSL)) {
            $this->validateSSL = false;
        }
    }

    /**
     * Function to initialize Http Client.
     *
     * @return void
     */
    protected function setClient()
    {
        $this->client = new HttpClient([
            'curl' => $this->httpClientConfig,
        ]);
    }

    /**
     * Function to set Http Client configuration.
     *
     * @return void
     */
    protected function setHttpClientConfiguration()
    {
        $this->httpClientConfig = [
            CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_SSL_VERIFYPEER => $this->validateSSL,
        ];

        if (!empty($this->certificate)) {
            $this->httpClientConfig[CURLOPT_SSLCERT] = $this->certificate;
        }
    }

    /**
     * Set PayPal API Credentials.
     *
     * @param array $credentials
     *
     * @throws \Exception
     *
     * @return void
     */
    public function setApiCredentials($credentials)
    {
        // Setting Default PayPal Mode If not set
        $this->setApiEnvironment($credentials);

        // Set API configuration for the PayPal provider
        $this->setApiProviderConfiguration($credentials);

        // Set default currency.
        $this->setCurrency($credentials['currency']);

        // Set Http Client configuration.
        $this->setHttpClientConfiguration();

        // Initialize Http Client
        $this->setClient();

        // Set default values.
        $this->setDefaultValues();

        // Set PayPal API Endpoint.
        $this->apiUrl = $this->config['api_url'];

        // Set PayPal IPN Notification URL
        $this->notifyUrl = $this->config['notify_url'];
    }

    /**
     * Set API environment to be used by PayPal.
     *
     * @param array $credentials
     *
     * @return void
     */
    private function setApiEnvironment($credentials)
    {
        if (empty($credentials['mode']) || !in_array($credentials['mode'], ['sandbox', 'live'])) {
            $this->mode = 'live';
        } else {
            $this->mode = $credentials['mode'];
        }
    }

    /**
     * Set configuration details for the provider.
     *
     * @param array $credentials
     *
     * @throws \Exception
     *
     * @return void
     */
    private function setApiProviderConfiguration($credentials)
    {
        // Setting PayPal API Credentials
        collect($credentials[$this->mode])->map(function ($value, $key) {
            $this->config[$key] = $value;
        });

        // Setup PayPal API Signature value to use.
        $this->config['signature'] = empty($this->config['certificate']) ?
            $this->config['secret'] : file_get_contents($this->config['certificate']);

        $this->paymentAction = $this->config['payment_action'];

        $this->locale = $this->config['locale'];

        $this->certificate = file_get_contents($this->config['certificate']);

        $this->validateSSL = $credentials['validate_ssl'];

        $this->setApiProvider($credentials);
    }

    /**
     * Determines which API provider should be used.
     *
     * @param array $credentials
     *
     * @throws \Exception
     */
    private function setApiProvider($credentials)
    {
        if ($this instanceof \Srmklive\PayPal\Services\AdaptivePayments) {
            $this->setAdaptivePaymentsOptions();
        } elseif ($this instanceof \Srmklive\PayPal\Services\ExpressCheckout) {
            $this->setExpressCheckoutOptions($credentials);
        } else {
            throw new \Exception('Invalid api credentials provided for PayPal!. Please provide the right api credentials.');
        }
    }

    /**
     * Setup request data to be sent to PayPal.
     *
     * @param array $data
     *
     * @return \Illuminate\Support\Collection
     */
    protected function setRequestData(array $data = [])
    {
        if (($this->post instanceof Collection) && (!$this->post->isEmpty())) {
            unset($this->post);
        }

        $this->post = new Collection($data);

        return $this->post;
    }

    /**
     * Set other/override PayPal API parameters.
     *
     * @param array $options
     *
     * @return $this
     */
    public function addOptions(array $options)
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Function to set currency.
     *
     * @param string $currency
     *
     * @throws \Exception
     *
     * @return $this
     */
    public function setCurrency($currency = 'USD')
    {
        $allowedCurrencies = ['AUD', 'BRL', 'CAD', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR', 'MXN', 'NOK', 'NZD', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'TWD', 'THB', 'USD', 'RUB'];

        // Check if provided currency is valid.
        if (!in_array($currency, $allowedCurrencies)) {
            throw new \Exception('Currency is not supported by PayPal.');
        }

        $this->currency = $currency;

        return $this;
    }

    /**
     * Retrieve PayPal IPN Response.
     *
     * @param array $post
     *
     * @return array
     */
    public function verifyIPN($post)
    {
        $this->setRequestData($post);

        $this->apiUrl = $this->config['gateway_url'].'/cgi-bin/webscr';

        return $this->doPayPalRequest('verifyipn');
    }

    /**
     * Create request payload to be sent to PayPal.
     *
     * @param string $method
     */
    private function createRequestPayload($method)
    {
        $config = array_merge([
            'USER'      => $this->config['username'],
            'PWD'       => $this->config['password'],
            'SIGNATURE' => $this->config['signature'],
            'VERSION'   => 123,
            'METHOD'    => $method,
        ], $this->options);

        $this->post = $this->post->merge($config);
        if ($method === 'verifyipn') {
            $this->post->forget('METHOD');
        }
    }

    /**
     * Perform PayPal API request & return response.
     *
     * @throws \Exception
     *
     * @return \Psr\Http\Message\StreamInterface
     */
    private function makeHttpRequest()
    {
        try {
            return $this->client->post($this->apiUrl, [
                $this->httpBodyParam => $this->post->toArray(),
            ])->getBody();
        } catch (HttpClientException $e) {
            throw new \Exception($e->getRequest().' '.$e->getResponse());
        } catch (HttpServerException $e) {
            throw new \Exception($e->getRequest().' '.$e->getResponse());
        } catch (HttpBadResponseException $e) {
            throw new \Exception($e->getRequest().' '.$e->getResponse());
        }
    }

    /**
     * Function To Perform PayPal API Request.
     *
     * @param string $method
     *
     * @throws \Exception
     *
     * @return array|\Psr\Http\Message\StreamInterface
     */
    private function doPayPalRequest($method)
    {
        // Setup PayPal API Request Payload
        $this->createRequestPayload($method);

        try {
            // Perform PayPal HTTP API request.
            $response = $this->makeHttpRequest();

            return $this->retrieveData($method, $response);
        } catch (\Exception $e) {
            $message = collect($e->getTrace())->implode('\n');
        }

        return [
            'type'    => 'error',
            'message' => $message,
        ];
    }

    /**
     * Parse PayPal NVP Response.
     *
     * @param string                                  $method
     * @param array|\Psr\Http\Message\StreamInterface $response
     *
     * @return array
     */
    private function retrieveData($method, $response)
    {
        if ($method === 'verifyipn') {
            return $response;
        }

        parse_str($response, $output);

        return $output;
    }
}
