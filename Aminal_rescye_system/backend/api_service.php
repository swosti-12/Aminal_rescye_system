<?php
// Helper functions to communicate with the Python Flask Machine Learning API
$api_base_url = 'http://127.0.0.1:5000';

function call_ml_api($endpoint, $payload = []) {
    global $api_base_url;
    
    $ch = curl_init($api_base_url . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    if(!empty($payload)){
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpcode == 200) {
        return json_decode($response, true);
    }
    return null;
}
?>
