<?php

namespace Algorithmia;

class HttpClient {
    const USER_AGENT_SUFFIX = "algorithmia-php-client";

    const CONTENT_TYPE_JSON = 'application/json';
    const CONTENT_TYPE_TEXT = "application/text";
    const CONTENT_TYPE_OCTET_STREAM = "application/octet-stream";

    const DEBUG = false;

     /**
     * Guzzle http client configured with json headers
     * @var \GuzzleHttp\Client $json_http
     */
    private $json_http;
    
    /**
     * Guzzle http client configured with binary headers
     * @var \GuzzleHttp\Client $bin_http
     */
    private $bin_http;


    /**
     * Options that can be configured for the client. If the value is null, it is ignored
     * otherwise the parameter will be sent along to the server as a query param.
     * @var array
     */
    private $options = array(
        'timeout' => 300, //default timeout is 300s = 5 minutes
        'server' => null,
        'agent' => self::USER_AGENT_SUFFIX,
        'key' => null,
        'debug' => self::DEBUG,
        'stdout' => null,
        'output' => null,
        'sink' => null
    );

    /**
     * Any options in this list will be sent to the server as query params (if not null in $options).
     */
    private $query_param_options = ['stdout','output']; 

    public function __construct(array $in_options = array()){
        $this->setOptions($in_options);
    }

    public function getOptions(){
        return $this->options;
    }

    /**
     * Set options for the Algo Client
     * @param array Array of parameters:  ['timeout' => 120, 'server' => 'https://api.algorithmia.com']
     * @return self
     */
    public function setOptions(array $in_options = array()) {

        $this->options = array_merge($this->options, $in_options);

        //setting the options needs to drop the cached clients so they will be recreated
        $this->json_http = null;
        $this->bin_http = null;

        return $this;
    }

    public function get($in_url, $in_content_type){
        $client = $this->getClientForType($in_content_type);
       
        try
        {
            $promise = $client->getAsync($in_url, $this->getQueryParamArray());

            if($this->options['output']=='void'){
                return $promise;
            }
    
            return $promise->wait();
    
        }
        catch(\Exception $e)
        {
            throw new AlgoException($this->extractExceptionMessage($e));
        }
    }

    /**
     * @param $in_url string of URL to call with PUT
     * @param $in_input mixed payload to deliver to api endpoint. Can be a string or an object.
     * @return \Psr\Http\Message\ResponseInterface Object
     */
    public function put($in_url, $in_input, $in_content_type){

        $client = $this->getClientForType($in_content_type);
        $body_name = $this->getBodyNameForType($in_content_type);
        
        try
        {
            return $client->put($in_url, $this->getQueryParamArray([$body_name => $in_input]));
        }
        catch(\Exception $e)
        {
            throw new AlgoException($this->extractExceptionMessage($e)." for " .$in_url);
        }

    }

    /**
     * @param $in_url string of URL to call with POST
     * @param $in_input mixed payload to deliver to api endpoint. Can be a string or an object.
     * @return \Psr\Http\Message\ResponseInterface Object
     */
    public function post($in_url, $in_input, $in_content_type, $in_async=false){
        $client = $this->getClientForType($in_content_type);
        $body_name = $this->getBodyNameForType($in_content_type);
        
        try{
            $promise = $client->postAsync($in_url, $this->getQueryParamArray([$body_name => $in_input]));
            
            if($this->options['output']=='void' || $in_async){
                return $promise;
            }
    
            return $promise->wait();
        }
        catch(\Exception $e)
        {
            throw new AlgoException($this->extractExceptionMessage($e));
        }
    }

    public function delete($in_url, $in_content_type) {
        $client = $this->getClientForType($in_content_type);

        try{
            return $client->delete($in_url);
        }
        catch(\Exception $e)
        {
            throw new AlgoException($this->extractExceptionMessage($e));
        }
    }

    public function getClientForType($in_content_type) {
        if($in_content_type == self::CONTENT_TYPE_JSON)
        {
            $client = $this->getJsonHttpClient();
        }
        else if($in_content_type == self::CONTENT_TYPE_OCTET_STREAM) {
            $client = $this->getBinaryHttpClient();
        }
        return $client;
    }

    public function getBodyNameForType($in_content_type) {
        if($in_content_type == self::CONTENT_TYPE_JSON)
        {
            $body_name = 'json';
        }
        else if($in_content_type == self::CONTENT_TYPE_OCTET_STREAM) {
            $body_name = 'body';
        }
        return $body_name;
    }

    public function getBinaryHttpClient()
    {
        if(null === $this->bin_http) {
            $this->bin_http = $this->createBinaryHttpClient();
        }

        return $this->bin_http;
    }

    public function getJsonHttpClient()
    {
        if(null === $this->json_http) {
            $this->json_http = $this->createJsonHttpClient();
        }

        return $this->json_http;
    }

    private function createJsonHttpClient()
    {
        return new \GuzzleHttp\Client($this->getGuzzleHttpOptions(self::CONTENT_TYPE_JSON));
    }

    private function createBinaryHttpClient()
    {
        return new \GuzzleHttp\Client($this->getGuzzleHttpOptions(self::CONTENT_TYPE_OCTET_STREAM));
    }

    private function getGuzzleHttpOptions($in_content_type){
        $header_options = [
            'base_uri' => $this->options['server'],
            'headers' => ['Content-Type' => $in_content_type]
        ];

        if(!is_null($this->options['key'])){
            $header_options['headers']['Authorization'] = 'Simple '.$this->options['key'];
        }

        return $header_options;
    }

    //merges the incoming array with the query param options
    private function getQueryParamArray($in_array = array()){

        $common_params = array('debug' => $this->options['debug'], 'timeout' => $this->options['timeout']);

        //allow for direct streaming to particular filename if they provide the filesink they want to use
        if($this->options['sink'])
            $common_params['sink'] = $this->options['sink'];

        $query_param_array = array_merge($common_params, $in_array);
        
        $params = array();

        //if there is a allowed option set via setOptions AND it isn't null then add it to the query params
        foreach($this->options as $key => $value){
            if(in_array($key, $this->query_param_options) && !is_null($value)){
                $params[$key] = $value;
            }
        }

        if(count($params)>0){
            $query_param_array['query'] = $params;
        }

        return $query_param_array;
    }

    private function extractExceptionMessage(\GuzzleHttp\Exception\RequestException $e)
    {
        if ($e->getResponse() === null) {
            return $e->getMessage();
        }

        $responseContent = $e->getResponse()->getBody()->getContents();
        if (!empty($responseContent)) {
            $responseObject = json_decode($responseContent);

            if (json_last_error() == JSON_ERROR_NONE && isset($responseObject->error->message)) {
                return $responseObject->error->message;
            }
        }

        return $e->getMessage();
    }
}
