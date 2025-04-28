<?php
declare(strict_types=1);
/**
 * PHP REST Client
 * https://github.com/tcdent/php-restclient
 * (c) 2013-2022 Travis Dent <tcdent@gmail.com>
 * (c) 2025 Mathz Franzen <mathz@mdwebb.se>
 */
use RestClientException as RestClientException;

class RestClient implements Iterator, ArrayAccess {
    
    public $options;
    public $handle; // cURL resource handle.
    public $url;
    
    // Populated after execution:
    public $response; // Response body.
    public $headers; // Parsed reponse header object.
    public $info; // Response info object.
    public $error; // Response error string.
    public $responseStatusLines; // indexed array of raw HTTP response status lines.
    
    // Populated as-needed.
    public $decodedResponse; // Decoded response body. 
    
    public function __construct(array $options=[]){
        $defaultOptions = [
            'headers' => [], 
            'parameters' => [], 
            'curl_options' => [], 
            'build_indexed_queries' => FALSE, 
            'user_agent' => "PHP RestClient/0.1.8", 
            'base_url' => NULL, 
            'format' => NULL, 
            'format_regex' => "/(\w+)\/(\w+)(;[.+])?/",
            'decoders' => [
                'json' => 'json_decode', 
                'php' => 'unserialize'
            ], 
            'username' => NULL, 
            'password' => NULL
        ];
        
        $this->options = array_merge($defaultOptions, $options);
        if(array_key_exists('decoders', $options))
            $this->options['decoders'] = array_merge(
                $defaultOptions['decoders'], $options['decoders']);
    }
    
    public function setOption($key, $value) : void {
        $this->options[$key] = $value;
    }
    
    public function registerDecoder(string $format, callable $method) : void {
        // Decoder callbacks must adhere to the following pattern:
        //   array my_decoder(string $data)
        $this->options['decoders'][$format] = $method;
    }
    
    // Iterable methods:
    public function rewind() : void {
        $this->decodeResponse();
        reset($this->decodedResponse);
    }
    
    public function current() : mixed {
        return current($this->decodedResponse);
    }
    
    public function key() : mixed {
        return key($this->decodedResponse);
    }
    
    public function next() : void {
        next($this->decodedResponse);
    }
    
    public function valid() : bool {
        return is_array($this->decodedResponse)
            && (key($this->decodedResponse) !== NULL);
    }
    
    // ArrayAccess methods:
    public function offsetExists($key) : bool {
        $this->decodeResponse();
        return is_array($this->decodedResponse)?
            isset($this->decodedResponse[$key]) : isset($this->decodedResponse->{$key});
    }
    
    public function offsetGet($key) : mixed {
        $this->decodeResponse();
        if(!$this->offsetExists($key))
            return NULL;
        
        return is_array($this->decodedResponse)?
            $this->decodedResponse[$key] : $this->decodedResponse->{$key};
    }
    
    public function offsetSet($key, $value) : void {
        throw new RestClientException("Decoded response data is immutable.");
    }
    
    public function offsetUnset($key) : void {
        throw new RestClientException("Decoded response data is immutable.");
    }
    
    // Request methods:
    public function get(string $url, $parameters=[], array $headers=[]) : RestClient {
        return $this->execute($url, 'GET', $parameters, $headers);
    }
    
    public function post(string $url, $parameters=[], array $headers=[]) : RestClient {
        return $this->execute($url, 'POST', $parameters, $headers);
    }
    
    public function put(string $url, $parameters=[], array $headers=[]) : RestClient {
        return $this->execute($url, 'PUT', $parameters, $headers);
    }
    
    public function patch(string $url, $parameters=[], array $headers=[]) : RestClient {
        return $this->execute($url, 'PATCH', $parameters, $headers);
    }
    
    public function delete(string $url, $parameters=[], array $headers=[]) : RestClient {
        return $this->execute($url, 'DELETE', $parameters, $headers);
    }
    
    public function head(string $url, $parameters=[], array $headers=[]) : RestClient {
        return $this->execute($url, 'HEAD', $parameters, $headers);
    }
    
    public function execute(string $url, string $method='GET', $parameters=[], array $headers=[]) : RestClient {
        $client = clone $this;
        $client->url = $url;
        $client->handle = curl_init();
        $curlopt = [
            CURLOPT_HEADER => TRUE, 
            CURLOPT_RETURNTRANSFER => TRUE, 
            CURLOPT_USERAGENT => $client->options['user_agent'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ];
        
        if($client->options['username'] && $client->options['password'])
            $curlopt[CURLOPT_USERPWD] = sprintf("%s:%s", 
                $client->options['username'], $client->options['password']);
        
        if(count($client->options['headers']) || count($headers)){
            $curlopt[CURLOPT_HTTPHEADER] = [];
            $headers = array_merge($client->options['headers'], $headers);
            foreach($headers as $key => $values){
                foreach(is_array($values)? $values : [$values] as $value){
                    $curlopt[CURLOPT_HTTPHEADER][] = sprintf("%s: %s", $key, $value);
                }
            }
        }
        
      /*  if($client->options['format'])
            $client->url .= '.'.$client->options['format'];*/
        
        // Allow passing parameters as a pre-encoded string (or something that
        // allows casting to a string). Parameters passed as strings will not be
        // merged with parameters specified in the default options.
        if(is_array($parameters)){
            $parameters = array_merge($client->options['parameters'], $parameters);
            $parameters_string = http_build_query($parameters);
            
            // http_build_query automatically adds an array index to repeated
            // parameters which is not desirable on most systems. This hack
            // reverts "key[0]=foo&key[1]=bar" to "key[]=foo&key[]=bar"
            if(!$client->options['build_indexed_queries'])
                $parameters_string = preg_replace(
                    "/%5B[0-9]+%5D=/simU", "%5B%5D=", $parameters_string);
        }
        if(!is_array($parameters)){
            $parameters_string = (string) $parameters;
        }
        
        if(strtoupper($method) === 'POST'){
            $curlopt[CURLOPT_POST] = TRUE;
            $curlopt[CURLOPT_POSTFIELDS] = $parameters_string;
        }
        if(!in_array(strtoupper($method),['GET', 'POST'])){
            $curlopt[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
            $curlopt[CURLOPT_POSTFIELDS] = $parameters_string;
        }
        if(strtoupper($method) === 'GET' && $parameters_string){
            $client->url .= strpos($client->url, '?')? '&' : '?';
            $client->url .= $parameters_string;
        }
        
        if($client->options['base_url']){
            $client->url = sprintf("%s%s",
                (string) $client->options['base_url'], 
                ltrim((string) $client->url, '/'));
        }
        $curlopt[CURLOPT_URL] = $client->url;
        
        if($client->options['curl_options']){
            // array_merge would reset our numeric keys.
            foreach($client->options['curl_options'] as $key => $value){
                $curlopt[$key] = $value;
            }
        }
        curl_setopt_array($client->handle, $curlopt);
        
        $response = curl_exec($client->handle);
        if($response !== FALSE)
            $client->parseResponse($response);
        $client->info = (object) curl_getinfo($client->handle);
        $client->error = curl_error($client->handle);
        
        curl_close($client->handle);
        return $client;
    }
    
    public function parseResponse($response) : void {
        $headers = [];
        $this->responseStatusLines = [];
        $line = strtok($response, "\n");
        do {
            if(strlen(trim($line)) == 0){
                // Since we tokenize on \n, use the remaining \r to detect empty lines.
                if(count($headers) > 0) break; // Must be the newline after headers, move on to response body
            }
            if(strpos($line, 'HTTP') === 0){
                // One or more HTTP status lines
                $this->responseStatusLines[] = trim($line);
            }
            if(strlen(trim($line)) != 0 && strpos($line, 'HTTP') !== 0) { 
                // Has to be a header
                [$key, $value] = explode(':', $line, 2);
                $key = strtolower(trim(str_replace('-', '_', $key)));
                $value = trim($value);
                
                if(empty($headers[$key]))
                    $headers[$key] = $value;
                elseif(is_array($headers[$key]))
                    $headers[$key][] = $value;
                else
                    $headers[$key] = [$headers[$key], $value];
            }
        } while($line = strtok("\n"));
        
        $this->headers = (object) $headers;
        $this->response = strtok("");
    }
    
    public function getResponseFormat() : string {
        if(!$this->response)
            throw new RestClientException(
                "A response must exist before it can be decoded.");
        
        // User-defined format. 
        if(!empty($this->options['format']))
            return $this->options['format'];
        
        // Extract format from response content-type header. 
        if(!empty($this->headers->content_type))
        if(preg_match($this->options['format_regex'], $this->headers->content_type, $matches))
            return $matches[2];
        
        throw new RestClientException(
            "Response format could not be determined.");
    }
    
    public function decodeResponse(){
        if(empty($this->decodedResponse)){
            $format = $this->getResponseFormat();
            if(!array_key_exists($format, $this->options['decoders']))
                throw new RestClientException("'{$format}' is not a supported ".
                    "format, register a decoder to handle this response.");
            
            $this->decodedResponse = call_user_func(
                $this->options['decoders'][$format], $this->response);
        }
        
        return $this->decodedResponse;
    }
}
