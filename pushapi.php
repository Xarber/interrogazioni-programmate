<?php
$body = json_decode(file_get_contents("php://input"), true);

$url = 'http://localhost:3000'.($body["path"]);

// Convert data array to JSON
$data_json = json_encode($body);

// Create a stream context
$options = array(
    'http' => array(
        'header'  => "Content-type: application/json\r\n",
        'method'  => 'POST',
        'content' => $data_json,
    ),
);

$context  = stream_context_create($options);

// Send the request and get the response
$response = file_get_contents($url, false, $context);

if ($response === FALSE) {
    die('Error occurred');
}

// Process the response
echo $response;