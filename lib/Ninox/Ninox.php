<?php
/**
 * This libary allows you to quickly and easily perform REST actions on the ninox backend using PHP.
 *
 * @author    Bertram Buchardt <support@4leads.de>
 * @copyright 2020 4leads GmbH
 * @license   https://opensource.org/licenses/MIT The MIT License
 */

namespace Ninox;

use stdClass;

/**
 * Interface to the Ninox REST-API
 *
 */
class Ninox
{
    const VERSION = '1.0.2';
    const TEAM_ID_VAR = "{TEAM_ID}";
    const DATABASE_ID_VAR = "{DATABASE_ID}";

    //NINOX QUERY-Params list--->
    const QUERY_PAGE = "page";
    const QUERY_PER_PAGE = "perPage";
    const QUERY_ORDER = "order"; //field to order by
    const QUERY_DESC = "desc"; //if true DESC, else ASC
    const QUERY_NEW = "new"; //if true show newest first --> no order
    const QUERY_UPDATED = "updated";
    const QUERY_SINCE_ID = "sinceId"; //id larger than
    const QUERY_SINCE_SQ = "sinceSq"; // sequence number lager than
    const QUERY_IDS = "ids";
    const QUERY_CHOICE_TYPE = "choice style";
    const QUERY_FILTERS = "filters";
    //<---END NINOX QUERY-Params list

    //NINOX field-types -->
    const FIELD_TEXT = "text";
    const FIELD_NUMBER = "number";
    const FIELD_DATE = "date";
    const FIELD_DATETIME = "datetime";
    const FIELD_TIMEINTERVAL = "timeinterval";
    const FIELD_TIME = "time";
    const FIELD_APPOINTMENT = "appointment";
    const FIELD_BOOLEAN = "boolean";
    const FIELD_CHOICE = "choice";
    const FIELD_MULTI = "multi"; //not in docs
    const FIELD_URL = "url";
    const FIELD_EMAIL = "email";
    const FIELD_PHONE = "phone";
    const FIELD_LOCATION = "location";
    const FIELD_HTML = "html";

    //<--- END Ninox field-types

    //Needed Record names
    const METHOD_GET = "GET";
    const METHOD_POST = "POST";
    const METHOD_DELETE = "DELETE";

    //Client properties
    /**
     * @var string
     */
    protected $host;

    /**
     * @var array
     */
    protected $headers;

    /**
     * @var string
     */
    protected $version;

    /**
     * @var array
     */
    protected $path;

    /**
     * @var string|null
     */
    protected static $team_id;

    /**
     * @var string|null
     */
    protected static $database_id;

    /**
     * @var array
     */
    protected $curlOptions;

    /**
     * @var string|null
     */
    private $_team_id;
    /**
     * @var string|null
     */
    private $_databse_id;

    /**
     * @var bool
     */
    protected $isPrivateCloud;
    //END Client properties


    /**
     * Setup the HTTP Client
     * @param array $options an array of options, currently only "host" and "curl" are implemented.
     * @param string $apiKey your 4leads API Key.
     * @param null|string $team_id set a fixed team_id optionally for all requests
     */
    public function __construct($apiKey, ?array $options = [], ?string $team_id = null)
    {
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'User-Agent: 4leads-ninox-api-client/' . self::VERSION . ';php',
            'Accept: application/json',
        ];

        $publicHost = 'api.ninoxdb.de';
        $host = isset($options['host']) ? $options['host'] : $publicHost;

        //detect private cloud systems or on premise systems
        $this->isPrivateCloud = strpos($host, $publicHost) === false;
        $this->filterHost($host);

        //override if set otherwise keep current global
        self::$team_id = $team_id ? $team_id : self::$team_id;

        $curlOptions = isset($options['curl']) ? $options['curl'] : null;
        $this->setupClient($host, $headers, isset($options['version']) ? $options['version'] : null, null, $curlOptions);
    }

    protected function filterHost(string &$host): string
    {
        $host = trim($host, '/ ');
        $host = preg_replace('/\/v1$/', "", $host); //remove version (should be in options)
        if ($this->isPrivateCloud) {
            preg_match('/\/([a-zA-Z0-9]+)\/api(\/v1)?/', $host, $matches);
            if (count($matches) > 1) {
                $host = str_replace($matches[0], "", $host);
                //read teamid and set globally if given
                self::setFixTeam($matches[1]);
            }
        }
        if (strpos($host, 'http') !== 0) {
            //fallback for missing protocol
            $host = "https://" . $host;
        }
        return $host;
    }

    /**
     * Initialize the client
     *
     * @param string $host the base url (e.g. https://api.ninoxdb.com)
     * @param array $headers global request headers
     * @param string $version api version (configurable) - this is specific to the 4leads API
     * @param array $path holds the segments of the url path
     * @param array $curlOptions extra options to set during curl initialization
     */
    protected function setupClient($host, $headers = null, $version = null, $path = null, $curlOptions = null)
    {
        $this->host = $host;
        $this->headers = $headers ?: [];
        $this->version = $version ? "/" . trim($version, "/ ") : "/v1";
        $this->path = $path ?: [];
        $this->curlOptions = $curlOptions ?: [];
    }


    /**
     * Build the final URL to be passed
     * @param string $path $the relative Path inside the api
     * @param array $queryParams an array of all the query parameters
     * @return string
     */
    public function buildUrl($path, $queryParams = null)
    {
        if (isset($queryParams) && is_array($queryParams) && count($queryParams)) {
            $path .= '?' . http_build_query($queryParams);
        }
        //fix privateCloud/on-premise
        if ($this->isPrivateCloud) {
            $path = str_replace("/teams/" . self::TEAM_ID_VAR, "/" . self::TEAM_ID_VAR . "/api" . $this->version, $path);
        }

        //replace fixed or current team and database
        $team_id = strlen($this->_team_id) ? $this->_team_id : self::$team_id;
        $database_id = strlen($this->_databse_id) ? $this->_databse_id : self::$database_id;
        $path = strlen($team_id) ? str_replace(self::TEAM_ID_VAR, urlencode($team_id), $path) : $path;
        $path = strlen($database_id) ? str_replace(self::DATABASE_ID_VAR, urlencode($database_id), $path) : $path;

        if ($this->isPrivateCloud) {
            //version included in path
            return sprintf('%s%s', $this->host, $path);
        } else {
            return sprintf('%s%s%s', $this->host, $this->version, $path);
        }
    }

    /**
     * Make the API call and return the response.
     * This is separated into it's own function, so we can mock it easily for testing.
     *
     * @param string $method the HTTP verb
     * @param string $url the final url to call
     * @param stdClass $body request body
     * @param array $headers any additional request headers
     *
     * @return NinoxResponse|stdClass object
     */
    public function makeRequest($method, $url, $body = null, $headers = null, ?array $requestOptions = null): NinoxResponse
    {
        $channel = curl_init($url);

        $options = $this->createCurlOptions($method, $body, $headers);
        if ($requestOptions) {
            $options += $requestOptions;
        }
        curl_setopt_array($channel, $options);
        $content = curl_exec($channel);

        $response = $this->parseResponse($channel, $content);

        curl_close($channel);

        if (strlen($response->responseBody)) {
            $response->responseBody = json_decode($response->responseBody);
        }

        //clean temporary set team and database id | prevent unwanted reuse in any case
        $this->_team_id = null;
        $this->_databse_id = null;

        return $response;
    }

    /**
     * Creates curl options for a request
     * this function does not mutate any private variables
     *
     * @param string $method
     * @param stdClass $body
     * @param array $headers
     *
     * @return array
     */
    private function createCurlOptions($method, $body = null, $headers = null)
    {
        $options = [
                CURLOPT_HEADER => true,
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_FAILONERROR => false,
                CURLOPT_USERAGENT => '4leads-ninox-php-client,v' . self::VERSION,
            ] + $this->curlOptions
            + [
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_RETURNTRANSFER => true,
            ];

        if (isset($headers)) {
            $headers = array_merge($this->headers, $headers);
        } else {
            $headers = $this->headers;
        }

        if (isset($body)) {
            $encodedBody = json_encode($body);
            $options[CURLOPT_POSTFIELDS] = $encodedBody;
            $headers = array_merge($headers, ['Content-Type: application/json']);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;

        return $options;
    }

    /**
     * Prepare response object.
     *
     * @param resource $channel the curl resource
     * @param string $content
     *
     * @return NinoxResponse|stdClass  response object
     */
    private function parseResponse($channel, $content): NinoxResponse
    {
        $response = new NinoxResponse();
        $response->headerSize = curl_getinfo($channel, CURLINFO_HEADER_SIZE);
        $response->statusCode = curl_getinfo($channel, CURLINFO_HTTP_CODE);

        $response->responseBody = substr($content, $response->headerSize);

        $headString = substr($content, 0, $response->headerSize);
        $response->responseHeaders = explode("\n", $headString);
        $response->responseHeaders = array_map('trim', $response->responseHeaders);

        return $response;
    }

    /**
     * @return NinoxResponse|stdClass
     */
    public function listTeams()
    {
        $path = "/teams";
        $url = $this->buildUrl($path, []);
        return $this->makeRequest(self::METHOD_GET, $url);
    }

    /**
     * @param string|null $team_id
     * @return NinoxResponse|stdClass
     */
    public function listDatabases(?string $team_id = null): NinoxResponse
    {
        $this->_team_id = $team_id;
        $path = "/teams/" . self::TEAM_ID_VAR . "/databases";
        $url = $this->buildUrl($path, []);
        return $this->makeRequest(self::METHOD_GET, $url);
    }

    /**
     * @param string|null $database_id
     * @param string|null $team_id
     * @return NinoxResponse
     */
    public function listTables(?string $database_id = null, ?string $team_id = null): NinoxResponse
    {
        $this->_databse_id = $database_id;
        $this->_team_id = $team_id;
        $path = "/teams/" . self::TEAM_ID_VAR . "/databases/" . self::DATABASE_ID_VAR . "/tables";
        $url = $this->buildUrl($path, []);
        return $this->makeRequest(self::METHOD_GET, $url);
    }

    /**
     * @param $table_id
     * @param string|null $database_id
     * @param string|null $team_id
     * @return NinoxResponse
     */
    public function getTableInfo($table_id, ?string $database_id = null, ?string $team_id = null): NinoxResponse
    {
        $this->_databse_id = $database_id;
        $this->_team_id = $team_id;
        $path = "/teams/" . self::TEAM_ID_VAR . "/databases/" . self::DATABASE_ID_VAR . "/tables/" . urlencode($table_id);
        $url = $this->buildUrl($path, []);
        return $this->makeRequest(self::METHOD_GET, $url);
    }

    /**
     * @param $table_id
     * @param array|null $queryParams
     * @param stdClass|null $filters
     * @param string|null $database_id
     * @param string|null $team_id
     * @return NinoxResponse
     */
    public function queryRecords($table_id, ?array $queryParams = [], ?stdClass $filters = null, ?string $database_id = null, ?string $team_id = null): NinoxResponse
    {
        $this->_databse_id = $database_id;
        $this->_team_id = $team_id;
        if ($filters) {
            $queryParams[self::QUERY_FILTERS] = json_encode($filters);
        }
        $this->filterQueryParams($queryParams);
        $path = "/teams/" . self::TEAM_ID_VAR . "/databases/" . self::DATABASE_ID_VAR . "/tables/" . urlencode($table_id) . "/records";
        $url = $this->buildUrl($path, $queryParams ? $queryParams : []);
        return $this->makeRequest(self::METHOD_GET, $url);
    }

    protected function filterQueryParams(array &$queryParams)
    {
        //boolean keys
        $booleans = [
            "desc",
            "new",
            "updated",
            "ids",
        ];
        //cast true or false LIKE values to "true" or "false" string representation
        foreach ($booleans as $key) {
            if (array_key_exists($key, $queryParams)) {
                $queryParams[$key] = $queryParams[$key] ? "true" : "false";
            }
        }
    }

    /**
     * Get a single Record from a Table by id
     * @param $tableId
     * @param $recordId
     * @param string|null $database_id
     * @param string|null $team_id
     * @return NinoxResponse
     */
    public function getRecord($tableId, $recordId, ?string $database_id = null, ?string $team_id = null): NinoxResponse
    {
        $this->_databse_id = $database_id;
        $this->_team_id = $team_id;
        $path = "/teams/" . self::TEAM_ID_VAR . "/databases/" . self::DATABASE_ID_VAR . "/tables/" . urlencode($tableId) . "/records/" . urlencode($recordId);
        $url = $this->buildUrl($path, []);
        return $this->makeRequest(self::METHOD_GET, $url);
    }


    /**
     * @param $tableId
     * @param $recordId
     * @param stdClass $fields
     * @param string|null $database_id
     * @param string|null $team_id
     * @return NinoxResponse
     */
    public function updateRecord($tableId, $recordId, stdClass $fields, ?string $database_id = null, ?string $team_id = null): NinoxResponse
    {
        $upsert = new stdClass();
        $upsert->id = $recordId;
        $upsert->fields = $fields;
        return $this->upsertRecords($tableId, [$upsert], $database_id, $team_id);
    }

    /**
     * WITH GREAT POWER COMES GREAT RESPONSIBILITY
     * @param $queryString
     * @param string|null $database_id
     * @param string|null $team_id
     * @return NinoxResponse
     */
    public function evalQuery($queryString, ?string $database_id = null, ?string $team_id = null): NinoxResponse
    {
        $this->_databse_id = $database_id;
        $this->_team_id = $team_id;
        $path = "/teams/" . self::TEAM_ID_VAR . "/databases/" . self::DATABASE_ID_VAR . "/query";
        $url = $this->buildUrl($path, []);
        //build the evil query object
        $evilObj = new stdClass();
        $evilObj->query = $queryString;
        return $this->makeRequest(self::METHOD_POST, $url, $evilObj);
    }

    /**
     * Get a list of files associated to the record
     * @param $tableId
     * @param $recordId
     * @param string|null $database_id
     * @param string|null $team_id
     * @return NinoxResponse
     */
    public function listRecordFiles($tableId, $recordId, ?string $database_id = null, ?string $team_id = null): NinoxResponse
    {
        $this->_databse_id = $database_id;
        $this->_team_id = $team_id;
        $path = "/teams/" . self::TEAM_ID_VAR . "/databases/" . self::DATABASE_ID_VAR . "/tables/" . urlencode($tableId) . "/records/" . urlencode($recordId) . "/files";
        $url = $this->buildUrl($path, []);
        return $this->makeRequest(self::METHOD_GET, $url);
    }

    /**
     * @param $tableId
     * @param $recordId
     * @param string $filename
     * @param int $curlTimeout
     * @param string|null $database_id
     * @param string|null $team_id
     * @return NinoxResponse
     */
    public function deleteFile($tableId, $recordId, string $filename, ?string $database_id = null, ?string $team_id = null): NinoxResponse
    {
        $this->_databse_id = $database_id;
        $this->_team_id = $team_id;
        $path = "/teams/" . self::TEAM_ID_VAR . "/databases/" . self::DATABASE_ID_VAR . "/tables/" . urlencode($tableId) . "/records/" . urlencode($recordId) . "/files/" . urlencode($filename);
        $url = $this->buildUrl($path, []);
        return $this->makeRequest(self::METHOD_DELETE, $url);
    }

    /**
     * Only tested with small files
     * @param $tableId
     * @param $recordId
     * @param string $filename
     * @param string $outputFile absolute filename or resource point to write file content to
     * @param int $curlTimeout
     * @param string|null $database_id
     * @param string|null $team_id
     * @return NinoxResponse
     * @throws NinoxException
     */
    public function downloadFile($tableId, $recordId, string $filename, string $outputFile, int $curlTimeout = 30, ?string $database_id = null, ?string $team_id = null): NinoxResponse
    {
        $this->_databse_id = $database_id;
        $this->_team_id = $team_id;
        $fp = fopen($outputFile, "w");
        if (!get_resource_type($fp) == 'file' && get_resource_type($fp) == 'stream') {
            throw new NinoxException("outputFile is not writable");
        }
        $path = "/teams/" . self::TEAM_ID_VAR . "/databases/" . self::DATABASE_ID_VAR . "/tables/" . urlencode($tableId) . "/records/" . urlencode($recordId) . "/files/" . urlencode($filename);
        $url = $this->buildUrl($path, []);
        $result = $this->makeRequest(self::METHOD_GET, $url, null, null, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => $curlTimeout,
        ]);
        fclose($fp);
        return $result;
    }

    /**
     * Only tested with small files
     * @param $tableId
     * @param $recordId
     * @param string $filename
     * @param int $curlTimeout
     * @param string|null $database_id
     * @param string|null $team_id
     * @return NinoxResponse
     * @throws NinoxException
     */
    public function uploadFile($tableId, $recordId, \CURLFile $file, int $curlTimeout = 60, ?string $database_id = null, ?string $team_id = null): NinoxResponse
    {
        $this->_databse_id = $database_id;
        $this->_team_id = $team_id;

        $path = "/teams/" . self::TEAM_ID_VAR . "/databases/" . self::DATABASE_ID_VAR . "/tables/" . urlencode($tableId) . "/records/" . urlencode($recordId) . "/files";
        $url = $this->buildUrl($path, []);
        //hacky way to overwrite content type (important to use null on body here)
        $overwrite = $this->createCurlOptions(self::METHOD_DELETE, null, ['Content-Type: multipart/form-data']);
        $overwrite[CURLOPT_TIMEOUT] = $curlTimeout;
        $overwrite[CURLOPT_POSTFIELDS] = ["file" => $file];
        $result = $this->makeRequest(self::METHOD_POST, $url, null, [], $overwrite);
        return $result;
    }

    /**
     * Delete a single Record from a Table by id
     * @param $tableId
     * @param $recordId
     * @param string|null $database_id
     * @param string|null $team_id
     * @return NinoxResponse
     */
    public function deleteRecord($tableId, $recordId, ?string $database_id = null, ?string $team_id = null): NinoxResponse
    {
        $this->_databse_id = $database_id;
        $this->_team_id = $team_id;
        $path = "/teams/" . self::TEAM_ID_VAR . "/databases/" . self::DATABASE_ID_VAR . "/tables/" . urlencode($tableId) . "/records/" . urlencode($recordId);
        $url = $this->buildUrl($path, []);
        return $this->makeRequest(self::METHOD_DELETE, $url);
    }

    /**
     * Insert or Update (see Ninox Docs) multiple Records as part of an array, cast for single stdclass object possible.
     * @param $tableId
     * @param array|stdClass $upserts
     * @param string|null $database_id
     * @param string|null $team_id
     * @return NinoxResponse
     */
    public function upsertRecords($tableId, $upserts, ?string $database_id = null, ?string $team_id = null): NinoxResponse
    {
        $this->_databse_id = $database_id;
        $this->_team_id = $team_id;
        if ($upserts instanceof stdClass) {
            //cast as array of itself
            $upserts = [$upserts];
        }
        if (!is_array($upserts)) {
            //make sure its array in any case
            $upserts = [];
        }
        $path = "/teams/" . self::TEAM_ID_VAR . "/databases/" . self::DATABASE_ID_VAR . "/tables/" . urlencode($tableId) . "/records";
        $url = $this->buildUrl($path, []);
        return $this->makeRequest(self::METHOD_POST, $url, $upserts);
    }

    /**
     * Test the API-KEY
     * @return bool
     */
    public function validateKey()
    {
        if ($this->isPrivateCloud) {
            return $this->listDatabases()->isOK();
        }
        return $this->listTeams()->isOK();
    }

    /**
     * If value is set all requests (except requests without team assoziated) run on this team
     * @param string|null $team_id
     */
    public static function setFixTeam(?string $team_id): void
    {
        self::$team_id = $team_id;
    }

    /**
     * If value is set all requests (except requests without database assoziated) run on this database
     * @param string|null $database_id
     */
    public static function setFixDatabase(?string $database_id): void
    {
        self::$database_id = $database_id;
    }

    /**
     * @return string
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * @return array
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * @return string|null
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * @return array
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * @return array
     */
    public function getCurlOptions()
    {
        return $this->curlOptions;
    }

    /**
     * Set extra options to set during curl initialization
     *
     * @param array $options
     *
     * @return Ninox
     */
    public function setCurlOptions(array $options)
    {
        $this->curlOptions = $options;

        return $this;
    }
}