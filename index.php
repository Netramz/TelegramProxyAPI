<?php
/**
 * Telegram API Proxy
 * -------------------
 * Acts as a middle layer between the client and Telegram Bot API.
 * Intercepts incoming HTTP requests and forwards them to the Telegram servers,
 * optionally logging request/response details for debugging or analytics.
 */

class TelegramApiProxy {
    /** @var string Fully qualified API request URL */
    private $url;

    /** @var resource cURL handler */
    private $ch;

    /** @var bool Enable/disable request logging */
    private $log = false;

    /**
     * Constructor:
     * - Detects target URL
     * - Initializes cURL session
     */
    public function __construct() {
        $this->getUrl();
        $this->initCurl();
    }

    /**
     * Enable or disable logging.
     * @param bool $log
     */
    public function setLog(bool $log) {
        $this->log = $log;
    }

    /**
     * Append message to log file if logging is enabled.
     * @param string $m
     */
    private function log(string $m) {
        if (!$this->log) return;
        file_put_contents('proxy.log', $m . PHP_EOL, FILE_APPEND);
    }

    /**
     * Main request handler:
     * - Logs initialization
     * - Prepares and sends the request
     * - Handles the response
     */
    public function start() {
        $this->log('[' . date('Y-m-d H:i:s') . '] Query init. URL: ' . $this->url);
        $this->sendRequest();

        $response = curl_exec($this->ch);
        $debug = curl_getinfo($this->ch);

        $this->log(sprintf(
            'Response headers: code=%d; content_type=%s; size_upload=%d; size_download=%d;',
            $debug['http_code'] ?? 0,
            $debug['content_type'] ?? '',
            $debug['size_upload'] ?? 0,
            $debug['size_download'] ?? 0
        ));

        $this->log('Response body: ' . $response);
        $this->log(str_repeat('-', 50));

        $this->sendResponse($response, $debug['content_type'] ?? 'application/json', $debug['http_code'] ?? 200);
    }

    /**
     * Send the HTTP response back to client with correct headers.
     * @param string $response
     * @param string $type
     * @param int $code
     */
    private function sendResponse(string $response, string $type, int $code) {
        http_response_code($code);
        header('Content-Type: ' . $type);
        echo $response;
        exit;
    }

    /**
     * Construct full Telegram API URL based on incoming request URI.
     * @return string
     */
    public function getUrl() {
        $dir = dirname($_SERVER['SCRIPT_NAME']); // Detect script directory
        if (strpos($_SERVER['REQUEST_URI'], $dir) === 0) {
            $uri = substr($_SERVER['REQUEST_URI'], strlen($dir));
        } else {
            $uri = $_SERVER['REQUEST_URI'];
        }

        if (substr($uri, 0, 1) !== '/') {
            $uri = '/' . $uri;
        }

        return $this->url = "https://api.telegram.org" . $uri;
    }

    /**
     * Initialize cURL session for the prepared URL.
     * @return resource
     */
    private function initCurl() {
        $this->ch = curl_init($this->url);
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        return $this->ch;
    }

    /**
     * Prepare and send the HTTP request to Telegram API.
     */
    private function sendRequest() {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->log('HTTP Request: ' . $method);
        $rawInput = file_get_contents('php://input');
        $this->log('HTTP Raw Input: ' . $rawInput);

        if ($method === 'POST' || $rawInput !== '' || !empty($_POST)) {
            curl_setopt($this->ch, CURLOPT_POST, true);

            if (!empty($_FILES)) {
                // Prepare file uploads
                $post = [];
                foreach ($_FILES as $name => $file) {
                    $post[$name] = new CURLFile($file['tmp_name'], $file['type'], $file['name']);
                }
                foreach ($_POST as $name => $value) {
                    $post[$name] = $value;
                }
            } else {
                // Forward raw POST body (e.g., JSON payload)
                $post = $rawInput;
                $ct = $_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? 'application/json';
                curl_setopt($this->ch, CURLOPT_HTTPHEADER, ['Content-Type: ' . $ct]);
            }

            $this->log('Send To Telegram HTTP Request body: ' . json_encode($post, JSON_UNESCAPED_UNICODE));
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, $post);
        } else {
            curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $method);
        }
    }
}

// -------------------------------------------------------------
// Bootstrap
// -------------------------------------------------------------
$proxy = new TelegramApiProxy();
$proxy->setLog(false); // Enable (true) only for debugging
$proxy->start();