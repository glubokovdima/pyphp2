<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';

$response = ['success' => false, 'message' => 'Error retrieving data.', 'data' => null];

$symbol_req = strtoupper(trim($_GET['symbol'] ?? ''));
$format_req = strtolower(trim($_GET['format'] ?? 'json')); // 'json' or 'html' (html not implemented yet)
$type_req = strtolower(trim($_GET['type'] ?? 'all')); // 'all' or 'single' if symbol is provided

$results_dir = $config['file_paths']['results_dir'];
$market_overview_file = $config['file_paths']['market_overview_file'];

try {
    if ($type_req === 'all' || empty($symbol_req)) {
        if (file_exists($market_overview_file)) {
            $content = file_get_contents($market_overview_file);
            $data = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $response['success'] = true;
                $response['message'] = 'Market overview retrieved.';
                $response['data'] = $data;
            } else {
                $response['message'] = 'Error decoding market overview file.';
                http_response_code(500);
            }
        } else {
            $response['message'] = 'Market overview file not found.';
            http_response_code(404);
        }
    } elseif ($type_req === 'single' && !empty($symbol_req)) {
        if (!preg_match('/^[A-Z0-9]+$/', $symbol_req)) {
            $response['message'] = 'Invalid symbol format.';
            http_response_code(400);
        } else {
            $symbol_file = "{$results_dir}/{$symbol_req}.json";
            if (file_exists($symbol_file)) {
                $content = file_get_contents($symbol_file);
                $data = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $response['success'] = true;
                    $response['message'] = "Data for {$symbol_req} retrieved.";
                    $response['data'] = $data;
                } else {
                    $response['message'] = "Error decoding data for {$symbol_req}.";
                    http_response_code(500);
                }
            } else {
                $response['message'] = "Data file for {$symbol_req} not found.";
                http_response_code(404);
            }
        }
    } else {
        $response['message'] = 'Invalid request parameters. Use ?type=all or ?type=single&symbol=BTCUSDT.';
        http_response_code(400);
    }

} catch (Exception $e) {
    $response['message'] = 'Server error: ' . $e->getMessage();
    error_log("Get Latest Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine(), 3, $config['file_paths']['error_log_file']);
    http_response_code(500);
}

// HTML format is not implemented yet.
// if ($format_req === 'html' && $response['success']) {
//    // Convert $response['data'] to HTML
// }

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;