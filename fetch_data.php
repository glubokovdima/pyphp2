<?php
// /market-analyzer/fetch_data.php
ini_set('display_errors', 0);
error_reporting(E_ALL);

// --- НАЧАЛО ОТЛАДКИ ЗАГРУЗКИ КОНФИГА ---
// error_log("--- fetch_data.php EXECUTING ---");
$config_path = __DIR__ . '/config.php';
if (!file_exists($config_path)) {
    $error_msg = "fetch_data.php: FATAL - config.php not found at {$config_path}";
    error_log($error_msg);
    if (!headers_sent()) { header('Content-Type: application/json'); http_response_code(500); }
    echo json_encode(['success' => false, 'message' => 'Критическая ошибка сервера: файл конфигурации отсутствует.']);
    exit;
}
$config = require $config_path;
if (!is_array($config)) {
    $error_msg = "fetch_data.php: FATAL - config.php did not return an array.";
    error_log($error_msg);
    if (!headers_sent()) { header('Content-Type: application/json'); http_response_code(500); }
    echo json_encode(['success' => false, 'message' => 'Критическая ошибка сервера: файл конфигурации некорректен.']);
    exit;
}
// error_log("fetch_data.php: config.php loaded. Timeframes_settings count: " . (isset($config['timeframes_settings']) ? count($config['timeframes_settings']) : 'NOT SET'));
// --- КОНЕЦ ОТЛАДКИ ЗАГРУЗКИ КОНФИГА ---


if (!headers_sent()) {
    header('Content-Type: application/json');
}

$response = [
    'success' => false,
    'message' => 'Ошибка инициализации fetch_data.',
    'symbol' => '',
    'debug_info' => [
        'config_timeframes_count' => 0,
        'config_timeframes_list' => [],
        'tf_status' => [],
        'api_responses' => []
    ]
];

try {
    $symbol_from_get_raw = trim($_GET['symbol'] ?? '');
    $symbol_from_get = strtoupper(preg_replace("/[^a-zA-Z0-9]/", "", $symbol_from_get_raw));
    $response['symbol'] = $symbol_from_get; // Записываем для отладки, какой символ пришел

    // error_log("fetch_data.php: Received symbol_raw: '{$symbol_from_get_raw}', processed: '{$symbol_from_get}'");

    if (empty($symbol_from_get)) {
        throw new InvalidArgumentException('Символ не предоставлен или пуст.');
    }
    if (!isset($config['api_settings']['symbols'][$symbol_from_get])) {
        throw new InvalidArgumentException('Неверный или неподдерживаемый символ: ' . htmlspecialchars($symbol_from_get_raw) . " (обработан как: " . $symbol_from_get . ")");
    }
    $symbol = $symbol_from_get;

    if (isset($config['timeframes_settings']) && is_array($config['timeframes_settings'])) {
        $timeframes_to_iterate = array_keys($config['timeframes_settings']);
    } else {
        throw new RuntimeException('Конфигурация timeframes_settings не найдена или некорректна в config.php.');
    }
    $response['debug_info']['config_timeframes_count'] = count($timeframes_to_iterate);
    $response['debug_info']['config_timeframes_list'] = $timeframes_to_iterate;
    // error_log("fetch_data.php for {$symbol}: Timeframes to iterate: " . implode(', ', $timeframes_to_iterate));


    if (empty($timeframes_to_iterate)) {
        throw new RuntimeException('Список таймфреймов для обработки пуст (проверьте config.php).');
    }

    $api_conf = $config['api_settings'];
    $klines_limit = $api_conf['klines_limit_per_tf'];
    $cache_dir = $config['file_paths']['klines_cache_dir'];
    $cache_lifetime = $config['cache_lifetime_seconds'];

    if (!is_dir($cache_dir)) {
        if (!mkdir($cache_dir, 0775, true)) {
            throw new RuntimeException('Не удалось создать директорию для кеша свечей: ' . $cache_dir);
        }
    }
    if (!is_writable($cache_dir)) {
        throw new RuntimeException('Директория для кеша свечей недоступна для записи: ' . $cache_dir);
    }

    $all_klines_data = [];
    $errors_occurred_during_tf_processing = false;
    $tf_successfully_processed_count = 0;

    foreach ($timeframes_to_iterate as $tf) {
        $tf_log_key = "{$symbol}_{$tf}";
        $current_tf_status_message = "Начало обработки {$tf}...";
        $cache_file = "{$cache_dir}/{$symbol}_{$tf}.json";
        $klines_for_tf = null;

        // error_log("fetch_data.php for {$symbol}: Processing TF {$tf}");

        if (file_exists($cache_file) && (time() - filemtime($cache_file) < $cache_lifetime)) {
            $cached_data_json = @file_get_contents($cache_file);
            if ($cached_data_json) {
                $klines_for_tf = json_decode($cached_data_json, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($klines_for_tf)) {
                    if (!empty($klines_for_tf)) {
                        $all_klines_data[$tf] = $klines_for_tf;
                        $current_tf_status_message = "Данные для {$tf} загружены из кеша (" . count($klines_for_tf) . " свечей).";
                        $response['debug_info']['tf_status'][$tf_log_key] = $current_tf_status_message;
                        $tf_successfully_processed_count++;
                        // error_log("fetch_data.php for {$symbol}_{$tf}: Loaded from cache.");
                        continue;
                    } else {
                        $current_tf_status_message = "Кеш для {$tf} пуст, загрузка с API.";
                    }
                } else {
                    $current_tf_status_message = "Ошибка декодирования кеша для {$tf}: " . json_last_error_msg() . ". Загрузка с API.";
                    error_log("MarketAnalyzer: Ошибка декодирования кеша для {$symbol}_{$tf}: " . json_last_error_msg());
                }
            } else {
                $current_tf_status_message = "Файл кеша для {$tf} пуст или не читается. Загрузка с API.";
            }
        } else {
            $current_tf_status_message = (file_exists($cache_file) ? "Кеш для {$tf} устарел. " : "Кеш для {$tf} не найден. ") . "Загрузка с API.";
        }
        $response['debug_info']['tf_status'][$tf_log_key] = $current_tf_status_message;
        // error_log("fetch_data.php for {$symbol}_{$tf}: Cache status - " . $current_tf_status_message);


        $query_params = http_build_query(['symbol' => $symbol, 'interval' => $tf, 'limit' => $klines_limit]);
        $url = $api_conf['base_url'] . $api_conf['klines_endpoint'] . '?' . $query_params;
        $response['debug_info']['tf_status'][$tf_log_key] .= " URL: " . $url;
        // error_log("fetch_data.php for {$symbol}_{$tf}: Fetching from API URL: {$url}");


        $context_options = ['http' => ['method' => 'GET', 'timeout' => 20, 'ignore_errors' => true, 'header' => "User-Agent: MarketAnalyzer/1.0\r\nAccept: application/json\r\n"]];
        $context = stream_context_create($context_options);
        $api_response_json = @file_get_contents($url, false, $context);

        $response['debug_info']['api_responses'][$tf_log_key] = $api_response_json === false ? "file_get_contents_failed_check_php_error_log" : substr($api_response_json, 0, 300) . (strlen($api_response_json) > 300 ? "..." : "");

        if ($api_response_json === false) {
            $fgc_error = error_get_last()['message'] ?? 'неизвестная ошибка file_get_contents';
            $response['debug_info']['tf_status'][$tf_log_key] .= " Ошибка запроса к API: " . $fgc_error;
            $errors_occurred_during_tf_processing = true;
            error_log("MarketAnalyzer: Ошибка file_get_contents для {$symbol}_{$tf} URL: {$url}. Ошибка: " . $fgc_error);
            continue;
        }

        $http_status_code = 0; $http_response_header_local = $http_response_header ?? []; // Локальная копия, т.к. $http_response_header - магическая
        if (isset($http_response_header_local[0])) {
            preg_match('{HTTP\/\S*\s(\d{3})}', $http_response_header_local[0], $match_status);
            $http_status_code = isset($match_status[1]) ? (int)$match_status[1] : 0;
        }
        $response['debug_info']['tf_status'][$tf_log_key] .= " HTTP: {$http_status_code}.";
        // error_log("fetch_data.php for {$symbol}_{$tf}: API HTTP Status: {$http_status_code}");


        $api_data = json_decode($api_response_json, true);
        $json_error_code = json_last_error();
        $json_error_msg = json_last_error_msg();

        if ($http_status_code !== 200 || $json_error_code !== JSON_ERROR_NONE || !is_array($api_data)) {
            $api_error_detail = "Не удалось получить/распарсить данные.";
            if ($http_status_code !== 200 && $http_status_code !== 0) $api_error_detail = "API вернуло HTTP {$http_status_code}.";
            elseif ($json_error_code !== JSON_ERROR_NONE) $api_error_detail = "Ошибка JSON: " . $json_error_msg;
            elseif (!is_array($api_data)) $api_error_detail = "Ответ API не является массивом (" . gettype($api_data) . ").";

            if (is_array($api_data) && isset($api_data['msg'])) $api_error_detail .= " Сообщение API: " . $api_data['msg'];

            $response['debug_info']['tf_status'][$tf_log_key] .= " Ошибка API: " . $api_error_detail;
            $errors_occurred_during_tf_processing = true;
            error_log("MarketAnalyzer: Ошибка API для {$symbol}_{$tf} (HTTP: {$http_status_code}): " . $api_error_detail . ". URL: {$url}.");
            continue;
        }

        $formatted_klines = [];
        foreach ($api_data as $kline_item) {
            if (is_array($kline_item) && count($kline_item) >= 6) {
                $formatted_klines[] = [
                    'timestamp' => (int)$kline_item[0], 'open' => (float)$kline_item[1],
                    'high' => (float)$kline_item[2], 'low' => (float)$kline_item[3],
                    'close' => (float)$kline_item[4], 'volume' => (float)$kline_item[5]
                ];
            }
        }
        $response['debug_info']['tf_status'][$tf_log_key] .= " Получено с API и отформатировано: " . count($formatted_klines) . " свечей.";
        // error_log("fetch_data.php for {$symbol}_{$tf}: Formatted " . count($formatted_klines) . " klines.");

        if (!empty($formatted_klines)) {
            $all_klines_data[$tf] = $formatted_klines;
            $tf_successfully_processed_count++;
            if (@file_put_contents($cache_file, json_encode($formatted_klines, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
                error_log("MarketAnalyzer: Не удалось записать кеш для {$symbol}_{$tf}. Проверьте права на папку {$cache_dir}.");
                $response['debug_info']['tf_status'][$tf_log_key] .= " Ошибка записи кеша.";
            } else {
                $response['debug_info']['tf_status'][$tf_log_key] .= " Закешировано.";
            }
        } else {
            $response['debug_info']['tf_status'][$tf_log_key] .= " API вернуло 0 свечей или данные не удалось отформатировать.";
            // error_log("fetch_data.php for {$symbol}_{$tf}: API returned 0 klines or formatting failed.");
        }
    } // end foreach $timeframes_to_iterate

    // Финальная оценка ответа
    if ($tf_successfully_processed_count > 0 && !empty($all_klines_data)) {
        $response['success'] = true;
        $response['message'] = "Данные для {$symbol} по " . $tf_successfully_processed_count . " таймфрейм(ам) (" . implode(', ', array_keys($all_klines_data)) . ") успешно подготовлены.";
    } elseif ($errors_occurred_during_tf_processing) {
        $response['message'] = "При загрузке данных для {$symbol} произошли ошибки хотя бы для одного ТФ. См. debug_info.tf_status.";
        // error_log("fetch_data.php for {$symbol}: Errors occurred during TF processing. Current response message: " . $response['message']);
    } else {
        $response['message'] = "Нет данных для {$symbol} после обработки (API могло вернуть 0 свечей для всех ТФ, или проблемы с кешем).";
        // error_log("fetch_data.php for {$symbol}: No data after processing. Current response message: " . $response['message']);
    }
    // error_log("fetch_data.php for {$symbol}: Final response: " . json_encode($response));


} catch (InvalidArgumentException $e) {
    $response['message'] = "Ошибка входных данных: " . $e->getMessage();
    if(!headers_sent()) { http_response_code(400); }
    error_log("MarketAnalyzer - InvalidArgumentException in fetch_data.php: " . $e->getMessage());
} catch (RuntimeException $e) {
    $response['message'] = "Ошибка выполнения: " . $e->getMessage();
    if(!headers_sent()) { http_response_code(500); }
    error_log("MarketAnalyzer - RuntimeException in fetch_data.php: " . $e->getMessage());
} catch (JsonException $e) { // Маловероятно здесь, т.к. JSON ошибки обрабатываются выше
    $response['message'] = "Ошибка JSON: " . $e->getMessage();
    if(!headers_sent()) { http_response_code(500); }
    error_log("MarketAnalyzer - JsonException in fetch_data.php: " . $e->getMessage());
} catch (Throwable $e) { // Ловим все остальное
    $response['message'] = "Критическая ошибка в fetch_data: " . $e->getMessage();
    error_log("MarketAnalyzer - КРИТИЧЕСКАЯ ОШИБКА в fetch_data.php: " . get_class($e) . ": " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    if(!headers_sent()) { http_response_code(500); }
    if (isset($config['debug_mode']) && $config['debug_mode'] === true) {
        $response['debug_info']['critical_error'] = get_class($e) . ": " . $e->getFile() . ":" . $e->getLine();
    }
}

// Гарантируем, что заголовок установлен и ответ в JSON
if (!headers_sent()) {
    header('Content-Type: application/json');
}
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
?>