<?php

namespace SAPb1;

/**
 * Encapsulates an SAP B1 HTTP request.
 */
class Request{
    
    protected $url;
    protected $sslOptions = [];
    protected $method = 'GET';
    protected $postParams = null;
    protected $files = [];
    protected $cookies = [];
    protected $headers = [];
    
    /**
     * Initializes a new instance of Request.
     */
    public function __construct(string $url, array $sslOptions = []){
        $this->url = $url;
        $this->sslOptions = $sslOptions;
    }
    
    /**
     * Sets the request method.
     */
    public function setMethod(string $method) : Request{
        $this->method = $method;
        return $this;
    }
    
    /**
     * Sets the request post data.
     */
    public function setPost($postParams) : Request{
        $this->postParams = $postParams;
        return $this;
    }

    /**
     * Sets the files data.
     */
    public function setFiles(array $files) : Request{
        $this->files = $files;
        return $this;
    }
    
    /**
     * Sets the request cookie data.
     */
    public function setCookies(array $cookies) : Request{
        $this->cookies = $cookies;
        return $this;
    }

    /**
     * Sets the request headers.
     */
    public function setHeaders(array $headers) : Request{
        $this->headers = $headers;
        return $this;
    }

    /**
     * Executes the request and gets the response.
     */
    public function getResponse() : Response{
        if(!empty($this->files)) {
            $boundary = 'WebKitFormBoundaryUmZoXOtOBNCTLyxT';
            $this->headers['Content-Type'] = 'multipart/form-data; boundary='.$boundary;

            $postData = '';
            foreach ($this->files as $file) {
                $fileName = basename($file);
                $mimeType = mime_content_type($file);

                $postData = "--{$boundary}\r\n";
                $postData .= "Content-Disposition: form-data; name=\"{$fileName}\"; filename=\"{$fileName}\"\r\n";
                $postData .= "Content-Type: {$mimeType}\r\n\r\n";
                $postData .= file_get_contents($file)."\r\n";
            }
            $postData .= "--{$boundary}--";
        } else {
            $postData = (null != $this->postParams) ? json_encode($this->postParams) : '';
            $this->headers['Content-Type'] = 'application/json';
        }

        $this->headers['Content-Length'] = strlen($postData);
        
        if(count($this->cookies) > 0){
            $cookies = '';
            foreach($this->cookies as $name => $value){
                $cookies.= $name .'='. $value . ';';
            }

            $this->headers['Cookie'] = $cookies;
        }

        $header = '';
        if(count($this->headers)){
            foreach($this->headers as $name => $value){
                $header.= $name .': '. $value . "\r\n";
            }
        }

        $options = array( 
            'http' => array(
                'ignore_errors' => true,
                'method'  => $this->method,
                'content' => $postData,
                'header'  => $header,
            ),
            "ssl" => $this->sslOptions
        );

        // Set the error handler to change warnings to exceptions.
        set_error_handler(
            function ($severity, $message, $file, $line) {
                throw new \ErrorException($message, $severity, $severity, $file, $line);
            }
        );
        
        // Call the rest API.
        $body = file_get_contents($this->url, false, stream_context_create($options));
        
        // Create the response object.
        $response = $this->createResponse($body, $http_response_header);
        
        // Restore the error handler.
        restore_error_handler();

        return $response;
    }
    
    private function createResponse($body, $responseHeaders) : Response{
        
        $statusCode = 0;
        $headers = [];
        $cookies = [];

        foreach($responseHeaders as $idx => $header){

            if($idx == 0){
                // First line of the header.
                // Get the status code.
                $array = explode(' ', $header);
                $statusCode = $array[1];
                continue;
            }

            // Split the headers.
            $array = explode(':', $header, 2);

            if(count($array) == 2){

                // Collection of cookies.
                $cookie = [];
                
                //Header key.
                $key = $array[0];
                
                //Header value.
                $value = $array[1];

                // If the header already exists, just add to it.
                if(array_key_exists($key, $headers)){
                    $prevValue = $headers[$key];

                    if(is_string($prevValue)){
                        $headers[$key] = [$prevValue, $value];
                    }
                    if(is_array($prevValue)){
                        $headers[$key][] = $value;
                    }
                    continue;
                }
                
                if($key == 'Content-Type'){ 
                    // Extract the Content Type.
                    $contentParts = explode(';', $value);
                    $headers['Content-Type'] = trim($contentParts[0]);
                }
                elseif($key == 'Set-Cookie'){
                    // Extract cookie data from the header 
                    // and add it to a $cookies array.
                    parse_str(strtr($value, array('&' => '%26', '+' => '%2B', ';' => '&')), $cookie); 
                    $cookies[key($cookie)] = reset($cookie);
                }
                else{
                    $headers[$key] = $value;
                }
            }
        }

        // Return a new instance of Response.
        return new Response($statusCode, $headers, $cookies, $body);
    }
}