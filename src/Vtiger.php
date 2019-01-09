<?php

namespace Tzendos\Vtiger;

use DB;
use Cache;
use Config;
use GuzzleHttp\Client;
use InvalidArgumentException;
use Illuminate\Database\Query\Builder;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Laravel wrapper for the VTgier API
 *
 * Class Vtiger
 * @package Tzendos\Vtiger
 */
class Vtiger
{
    /** @var VtigerErrorElement[] */
    private $vTigerErrors;

    /** @var string */
    protected $url;

    /** @var string */
    protected $username;

    /** @var string */
    protected $accessKey;

    /** @var string */
    protected $persistConnection;

    /** @var Client */
    protected $guzzleClient;

    /** @var int */
    protected $maxRetries;

    /**
     * Vtiger constructor.
     *
     * @throws VtigerError
     */
    public function __construct()
    {
        // set the API url and username
        $this->url = Config::get('vtiger.url');
        $this->username = Config::get('vtiger.username');
        $this->accessKey = Config::get('vtiger.accesskey');
        $this->persistConnection = Config::get('vtiger.persistconnection');
        $this->maxRetries = Config::get('vtiger.max_retries');

        $this->vTigerErrors = [
            0 => new VtigerErrorElement('Error received back from the API - ', 0),
            1 => new VtigerErrorElement('API request did not complete correctly - Response code: ', 1),
            2 => new VtigerErrorElement('Success property not set on VTiger response', 2),
            3 => new VtigerErrorElement('Error property not set on VTiger response when success is false', 3),
            4 => new VtigerErrorElement('There are no search fields in the array', 4),
            5 => new VtigerErrorElement('Could not complete login request within ' . $this->maxRetries . ' tries', 5),
            6 => new VtigerErrorElement(
                'Could not complete get token request within ' . $this->maxRetries . ' tries',
                6
            ),
            7 => new VtigerErrorElement('Guzzle ran into problems - ', 7),
            8 => new VtigerErrorElement('Laravel Cache problem', 8),
        ];

        try {
            $this->guzzleClient = new Client(['http_errors' => false, 'verify' => false]); //GuzzleHttp\Client
        } catch (InvalidArgumentException $e) {
            throw VtigerError::init($this->vTigerErrors, 7, $e->getMessage());
        }
    }

    /**
     * Call this function if you wish to override the default connection
     *
     * @param string $url
     * @param string $username
     * @param string $accessKey
     *
     * @return $this
     */
    public function connection($url, $username, $accessKey)
    {
        $this->url = $url;
        $this->username = $username;
        $this->accessKey = $accessKey;

        return $this;
    }

    /**
     * Get the session id for a login either from a stored session id or fresh from the API
     *
     * @return string
     * @throws VtigerError
     */
    protected function sessionId()
    {
        // Get the sessionData from the cache
        $sessionData = json_decode(Cache::get('tzendos_vtiger'));

        if (isset($sessionData)) {
            if (isset($sessionData) &&
                property_exists($sessionData, 'expireTime') &&
                property_exists($sessionData, 'token')) {
                if ($sessionData->expireTime < time() || empty($sessionData->token)) {
                    $sessionData = $this->storeSession();
                }
            } else {
                $sessionData = $this->storeSession();
            }
        } else {
            $sessionData = $this->storeSession();
        }

        if (isset($sessionData->sessionid)) {
            $sessionId = $sessionData->sessionid;
        } else {
            $sessionId = $this->login($sessionData);
        }

        return $sessionId;
    }

    /**
     * Login to the VTiger API to get a new session
     *
     * @param \stdClass $sessionData
     *
     * @return string
     * @throws VtigerError
     */
    protected function login($sessionData)
    {
        $sessionId = null;
        $token = $sessionData->token;

        // Create unique key using combination of challengetoken and accesskey
        $generatedKey = md5($token . $this->accessKey);

        $tryCounter = 1;

        do {
            try {
                // login using username and accesskey
                /** @var ResponseInterface $response */
                $response = $this->guzzleClient->request('POST', $this->url, [
                    'form_params' => [
                        'operation' => 'login',
                        'username' => $this->username,
                        'accessKey' => $generatedKey,
                    ],
                ]);
            } catch (GuzzleException $e) {
                throw VtigerError::init($this->vTigerErrors, 7, $e->getMessage());
            }

            // decode the response
            $loginResult = $this->_processResponse($response);
            $tryCounter++;
        } while (!isset($loginResult->success) && $tryCounter <= $this->maxRetries);

        if ($tryCounter >= $this->maxRetries) {
            throw new VtigerError('Could not complete login request within ' . $this->maxRetries . ' tries', 5);
        }

        // If api login failed
        if ($response->getStatusCode() !== 200 || !$loginResult->success) {
            if (!$loginResult->success) {
                if ($loginResult->error->code == 'INVALID_USER_CREDENTIALS' || $loginResult->error->code == 'INVALID_SESSIONID') {
                    if (!Cache::has('tzendos_vtiger')) {
                        throw VtigerError::init($this->vTigerErrors, 8, 'Nothing to delete for index tzendos_vtiger');
                    } else {
                        Cache::forget('tzendos_vtiger');
                    }
                } else {
                    $this->_processResult($response);
                }
            } else {
                $this->_checkResponseStatusCode($response);
            }
        } else {
            // login ok so get sessionid and update our session
            $sessionId = $loginResult->result->sessionName;

            if (Cache::has('tzendos_vtiger')) {
                $json = json_decode(Cache::pull('tzendos_vtiger'));
                $json->sessionid = $sessionId;
                Cache::forever('tzendos_vtiger', json_encode($json));
            } else {
                throw VtigerError::init($this->vTigerErrors, 8, 'There is no key for index tzendos_vtiger.');
            }
        }

        return $sessionId;
    }

    /**
     * Store a new session if needed
     *
     * @return \stdClass
     * @throws VtigerError
     */
    protected function storeSession()
    {
        $updated = $this->getToken();

        $output = (object)$updated;
        Cache::forever('tzendos_vtiger', json_encode($output));

        return $output;
    }

    /**
     * Get a new access token from the VTiger API
     *
     * @return array
     * @throws VtigerError
     */
    protected function getToken()
    {
        // perform API GET request
        $tryCounter = 1;
        do {
            try {
                $response = $this->guzzleClient->request('GET', $this->url, [
                    'query' => [
                        'operation' => 'getchallenge',
                        'username' => $this->username,
                    ],
                ]);
            } catch (GuzzleException $e) {
                throw VtigerError::init($this->vTigerErrors, 7, $e->getMessage());
            }

            $tryCounter++;
        } while (!isset($this->_processResponse($response)->success) && $tryCounter <= $this->maxRetries);

        if ($tryCounter >= $this->maxRetries) {
            throw new VtigerError('Could not complete get token request within ' . $this->maxRetries . ' tries', 6);
        }

        // decode the response
        $challenge = $this->_processResult($response);

        // Everything ok so create a token from response
        $output = [
            'token' => $challenge->result->token,
            'expireTime' => $challenge->result->expireTime,
        ];

        return $output;
    }

    /**
     * Logout from the VTiger API
     *
     * @param string $sessionId
     *
     * @return \stdClass|bool
     * @throws VtigerError
     */
    protected function close($sessionId)
    {
        if ($this->persistConnection) {
            return true;
        }

        try {
            // send a request to close current connection
            $response = $this->guzzleClient->request(
                'POST',
                $this->url,
                [
                    'query' => [
                        'operation' => 'logout',
                        'sessionName' => $sessionId,
                    ],
                ]
            );
        } catch (GuzzleException $e) {
            throw VtigerError::init($this->vTigerErrors, 7, $e->getMessage());
        }

        return $this->_processResult($response);
    }

    /**
     * Query the VTiger API with the given query string
     *
     * @param string $query
     *
     * @param array $params
     * @return \stdClass
     * @throws VtigerError
     */
    public function query($query, $params = [])
    {
        $sessionId = $this->sessionId();

        try {
            // send a request using a database query to get back any matching records
            $response = $this->guzzleClient->request('GET', $this->url, [
                'query' => array_merge([
                    'operation' => 'query',
                    'sessionName' => $sessionId,
                    'query' => $query,
                ], $params),
            ]);
        } catch (GuzzleException $e) {
            throw VtigerError::init($this->vTigerErrors, 7, $e->getMessage());
        }

        $this->close($sessionId);

        return $this->_processResult($response);
    }

    /**
     * Search entity by params
     *
     * @param array $params
     * @return \stdClass
     * @throws VtigerError
     */
    public function searchByParams($params)
    {
        $sessionId = $this->sessionId();

        try {
            $response = $this->guzzleClient->request(
                'GET',
                $this->url,
                [
                    'query' => array_merge([
                        'sessionName' => $sessionId,
                    ], $params),
                ]
            );
        } catch (GuzzleException $e) {
            throw VtigerError::init($this->vTigerErrors, 7, $e->getMessage());
        }

        $this->close($sessionId);

        return $this->_processResult($response);
    }

    /**
     * @param Builder $query
     * @param bool $quote
     * @return \stdClass
     * @throws VtigerError
     */
    public function searchByQuery($query, $params = [], $quote = true)
    {
        $bindings = $query->getBindings();
        $queryString = $query->toSQL();

        foreach ($bindings as $binding) {
            if ($quote) {
                $queryString = preg_replace('/\?/', DB::connection()->getPdo()->quote($binding), $queryString, 1);
            } else {
                $queryString = preg_replace('/\?/', $binding, $queryString, 1);
            }
        }

        //In the event there is a offset, append it to the front of the limit
        //Vtiger does not support the offset keyword
        $matchOffset = [];
        $matchLimit = [];
        if (preg_match('/(\s[o][f][f][s][e][t]) (\d*)/', $queryString, $matchOffset) && preg_match('/(\s[l][i][m][i][t]) (\d*)/', $queryString, $matchLimit)) {
            $queryString = preg_replace('/(\s[o][f][f][s][e][t]) (\d*)/', '', $queryString);
            $queryString = preg_replace('/(\s[l][i][m][i][t]) (\d*)/', '', $queryString);
            $queryString = $queryString . ' limit ' . $matchOffset[2] . ',' . $matchLimit[2];
        }

        //Remove the backticks and add simicolon
        $queryString = str_replace('`', '', $queryString) . ';';

        return $this->query($queryString, $params);
    }

    /**
     * Retreive a record from the VTiger API
     * Format of id must be {moudler_code}x{item_id}, e.g 4x12
     *
     * @param string $id
     *
     * @return \stdClass
     * @throws VtigerError
     */
    public function retrieve($id)
    {
        $sessionId = $this->sessionId();

        try {
            // send a request to retrieve a record
            $response = $this->guzzleClient->request('GET', $this->url, [
                'query' => [
                    'operation' => 'retrieve',
                    'sessionName' => $sessionId,
                    'id' => $id,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw VtigerError::init($this->vTigerErrors, 7, $e->getMessage());
        }

        $this->close($sessionId);

        return $this->_processResult($response);
    }

    /**
     * Create a new entry in the VTiger API
     *
     * To insert a record into the CRM, first create an array of data to insert.
     * Don't forget the added the id of the `assigned_user_id` (i.e. '4x12') otherwise the insert will fail
     * as `assigned_user_id` is a mandatory field.
     *
     * $data = array(
     *     'assigned_user_id' => '',
     * );
     *
     * To do the actual insert, pass the module name along with the json encoded array.
     *
     * @param string $elem
     * @param string $data
     *
     * @return \stdClass
     * @throws VtigerError
     */
    public function create($elem, $data)
    {
        $sessionId = $this->sessionId();

        try {
            // send a request to create a record
            $response = $this->guzzleClient->request('POST', $this->url, [
                'form_params' => [
                    'operation' => 'create',
                    'sessionName' => $sessionId,
                    'element' => $data,
                    'elementType' => $elem,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw VtigerError::init($this->vTigerErrors, 7, $e->getMessage());
        }

        $this->close($sessionId);

        return $this->_processResult($response);
    }

    /**
     * Update an entry in the database from the given object
     *
     * The object should be an object retreived from the database and then altered
     *
     * @param \stdClass $object
     *
     * @return \stdClass
     * @throws VtigerError
     */
    public function update($object)
    {
        $sessionId = $this->sessionId();

        try {
            // send a request to update a record
            $response = $this->guzzleClient->request('POST', $this->url, [
                'form_params' => [
                    'operation' => 'update',
                    'sessionName' => $sessionId,
                    'element' => json_encode($object),
                ],
            ]);
        } catch (GuzzleException $e) {
            throw VtigerError::init($this->vTigerErrors, 7, $e->getMessage());
        }

        $this->close($sessionId);

        return $this->_processResult($response);
    }

    /**
     * Delete from the database using the given id
     * Format of id must be {moudler_code}x{item_id}, e.g 4x12
     *
     * @param string $id
     *
     * @return \stdClass
     * @throws VtigerError
     */
    public function delete($id)
    {
        $sessionId = $this->sessionId();

        try {
            // send a request to delete a record
            $response = $this->guzzleClient->request('GET', $this->url, [
                'query' => [
                    'operation' => 'delete',
                    'sessionName' => $sessionId,
                    'id' => $id,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw VtigerError::init($this->vTigerErrors, 7, $e->getMessage());
        }

        $this->close($sessionId);

        return $this->_processResult($response);
    }

    /**
     * Describe an element from the vTiger API from the given element name
     *
     * @param string $elementType
     *
     * @return \stdClass
     * @throws VtigerError
     */
    public function describe($elementType)
    {
        $sessionId = $this->sessionId();

        try {
            // send a request to describe a module (which returns a list of available fields) for a Vtiger module
            $response = $this->guzzleClient->request(
                'GET',
                $this->url,
                [
                    'query' => [
                        'operation' => 'describe',
                        'sessionName' => $sessionId,
                        'elementType' => $elementType,
                    ],
                ]
            );
        } catch (GuzzleException $e) {
            throw VtigerError::init($this->vTigerErrors, 7, $e->getMessage());
        }

        $this->close($sessionId);

        return $this->_processResult($response);
    }

    /**
     * @param $elementType
     * @param int $modifiedTime
     * @param string $syncType
     * @return \stdClass
     * @throws VtigerError
     */
    public function sync($elementType, $modifiedTime = null, $syncType = null)
    {
        $sessionId = $this->sessionId();

        try {
            $options = [
                'elementType' => $elementType,
            ];

            if (!empty($modifiedTime)) {
                $options['modifiedTime'] = $modifiedTime;
            }

            if (!empty($syncType)) {
                $options['syncType'] = $syncType;
            }

            $response = $this->guzzleClient->request(
                'GET',
                $this->url,
                [
                    'query' => array_merge([
                        'operation' => 'sync',
                        'sessionName' => $sessionId,
                    ], $options),
                ]
            );
        } catch (GuzzleException $e) {
            throw VtigerError::init($this->vTigerErrors, 7, $e->getMessage());
        }

        $this->close($sessionId);

        return $this->_processResult($response);
    }


    /**
     * Process the response from the API for errors
     *
     * @param mixed|ResponseInterface $response
     *
     * @return \stdClass
     * @throws VtigerError
     */
    protected function _processResult($response)
    {
        $this->_checkResponseStatusCode($response);

        $data = $this->_processResponse($response);

        if (!isset($data->success)) {
            throw VtigerError::init($this->vTigerErrors, 2);
        }

        if ($data->success == false) {
            $this->_processResponseError($data);
        }

        return $data;
    }

    /**
     * Get the json decoded response from either the body or the contents
     *
     * @param ResponseInterface $response
     *
     * @return \stdClass
     */
    protected function _processResponse($response)
    {
        // decode the response
        if (!empty($response->getBody()->getContents())) {
            $response->getBody()->rewind();
            $data = json_decode($response->getBody()->getContents());
        } else {
            $data = json_decode($response->getBody());
        }

        return $data;
    }

    /**
     * Check the response code to make sure it isn't anything but 200
     *
     * @param mixed|ResponseInterface $response
     *
     * @throws VtigerError
     */
    protected function _checkResponseStatusCode($response)
    {
        if ($response->getStatusCode() !== 200) {
            throw VtigerError::init($this->vTigerErrors, 1);
        }
    }

    /**
     * Process any errors that we have got back
     *
     * @param \stdClass $processedData
     *
     * @throws VtigerError
     */
    protected function _processResponseError($processedData)
    {
        if (!isset($processedData->error)) {
            throw VtigerError::init($this->vTigerErrors, 3);
        }
        throw VtigerError::init($this->vTigerErrors, 0, $processedData->error->message);
    }
}
