<?php
// /market-analyzer/save_overview.php
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';
$response = ['success' => false, 'message' => 'Ошибка инициализации сохранения.'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Неверный метод запроса. Ожидается POST.';
    http_response_code(405); // Method Not Allowed
    echo json_encode($response);
    exit;
}

$overview_file_path = $config['file_paths']['overview_file'];
$data_dir = dirname($overview_file_path);

if (!is_dir($data_dir)) {
    if (!mkdir($data_dir, 0775, true)) {
        $response['message'] = 'Не удалось создать директорию для данных.';
        http_response_code(500);
        echo json_encode($response);
        exit;
    }
}

// Получаем JSON данные из тела POST запроса
$json_data = file_get_contents('php://input');
$market_overview_data = json_decode($json_data, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    $response['message'] = 'Ошибка декодирования JSON данных: ' . json_last_error_msg();
    http_response_code(400); // Bad Request
    echo json_encode($response);
    exit;
}

if (!is_array($market_overview_data) || empty($market_overview_data)) {
    $response['message'] = 'Получены пустые или некорректные данные для сохранения.';
    http_response_code(400);
    echo json_encode($response);
    exit;
}

try {
    if (file_put_contents($overview_file_path, json_encode($market_overview_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
        throw new RuntimeException("Не удалось записать обзор рынка в файл: " . $overview_file_path);
    }
    $response['success'] = true;
    $response['message'] = 'Обзор рынка успешно сохранен.';
} catch (Exception $e) {
    $response['message'] = 'Ошибка при сохранении файла: ' . $e->getMessage();
    error_log("Save Overview Error: " . $e->getMessage());
    http_response_code(500);
}

echo json_encode($response);
?>