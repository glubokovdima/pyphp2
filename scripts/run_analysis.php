<?php
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);
set_time_limit(0);
ignore_user_abort(true);

$collected_http_logs = []; // Массив для сбора логов при HTTP запуске

$config_path = __DIR__ . '/../config.php';
if (!file_exists($config_path)) {
    $error_msg = "FATAL ERROR: config.php not found at {$config_path}. Script cannot continue.";
    if (php_sapi_name() === 'cli') { echo $error_msg . "\n"; }
    else {
        if(!headers_sent()) header('Content-Type: application/json', true, 500);
        echo json_encode(['success' => false, 'message' => $error_msg, 'logs' => [$error_msg]]);
    }
    exit(1);
}
$config = require $config_path;

if (php_sapi_name() !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    if (isset($config['debug_mode']) && !$config['debug_mode']) {
        ini_set('display_errors', 0);
    }
}

$common_functions_path = __DIR__ . '/../strategies/common_functions.php';
if (!file_exists($common_functions_path)) {
    $error_msg = "FATAL ERROR: common_functions.php not found at {$common_functions_path}. Script cannot continue.";
    if (php_sapi_name() === 'cli') { echo $error_msg . "\n"; }
    else {
        if(!headers_sent()) header('Content-Type: application/json', true, 500);
        echo json_encode(['success' => false, 'message' => $error_msg, 'logs' => [$error_msg]]);
    }
    exit(1);
}
require_once $common_functions_path;

$is_manual_run = php_sapi_name() !== 'cli' && isset($_SERVER['REQUEST_METHOD']);

function log_message($message, $level = 'INFO') {
    global $config, $is_manual_run, $collected_http_logs; // Добавили $collected_http_logs
    $timestamp = gmdate("Y-m-d H:i:s \U\T\C");
    $log_entry_msg_only = "[{$level}] {$message}"; // Сообщение без timestamp для HTTP лога
    $log_entry_file = "[{$timestamp}] [{$level}] {$message}\n"; // Полное сообщение для файла

    if (php_sapi_name() === 'cli') {
        echo $log_entry_file; // Вывод в консоль для CLI
    } elseif ($is_manual_run) {
        $collected_http_logs[] = $log_entry_msg_only; // Собираем логи для HTTP ответа
    }

    // Логирование в файлы остается как было
    $log_file_path = null;
    $log_dir = $config['file_paths']['logs_dir'];

    if (!is_dir($log_dir)) {
        if(!@mkdir($log_dir, 0775, true) && !is_dir($log_dir)) {
            if (php_sapi_name() === 'cli' || $is_manual_run) {
                // Если $is_manual_run, это сообщение тоже пойдет в $collected_http_logs
                if ($is_manual_run) $collected_http_logs[] = "[WARNING] Failed to create log directory: {$log_dir}";
                else echo "[WARNING] Failed to create log directory: {$log_dir}\n";
            }
            return;
        }
    }

    switch (strtoupper($level)) {
        case 'ERROR': $log_file_path = $config['file_paths']['error_log_file']; break;
        case 'SIGNAL': $log_file_path = $config['file_paths']['signals_log_file']; break;
        case 'INFO': case 'DEBUG':
        if ($config['debug_mode']) { $log_file_path = $config['file_paths']['error_log_file']; }
        break;
        default:
            if ($config['debug_mode']) {
                $log_entry_file = "[{$timestamp}] [NOTICE] Unknown log level '{$level}': {$message}\n";
                $log_file_path = $config['file_paths']['error_log_file'];
            }
            break;
    }

    if ($log_file_path) {
        @error_log($log_entry_file, 3, $log_file_path);
    }
}

// ... (остальные функции fetch_ohlcv_data_for_symbol_tf и analyze_symbol_strategies ОСТАЮТСЯ БЕЗ ИЗМЕНЕНИЙ) ...
// Копипастить их сюда заново не буду для краткости, они остаются такими же, как в предыдущем полном варианте.
// Убедитесь, что они на месте.

// --- Копипаст функций fetch_ohlcv_data_for_symbol_tf и analyze_symbol_strategies из предыдущего ответа ---
function fetch_ohlcv_data_for_symbol_tf($symbol, $tf_key) {
    global $config;
    log_message("Fetching klines for {$symbol} - {$tf_key}...", 'DEBUG');

    $api_provider = $config['api_provider'];
    $api_conf_key = $api_provider . '_api_settings';
    if (!isset($config[$api_conf_key])) {
        log_message("API provider '{$api_provider}' configuration not found for symbol {$symbol}, TF {$tf_key}.", 'ERROR');
        return null;
    }
    $api_settings = $config[$api_conf_key];
    $klines_limit = $config['klines_limit_per_tf'];
    $cache_dir = $config['file_paths']['klines_cache_dir'];
    $cache_lifetime = $config['cache_lifetime_seconds'];

    if (!is_dir($cache_dir)) {
        if (!@mkdir($cache_dir, 0775, true) && !is_dir($cache_dir)) {
            log_message("Failed to create klines cache directory: {$cache_dir}", 'ERROR');
            return null;
        }
    }

    $cache_file = "{$cache_dir}/{$symbol}_{$tf_key}.json";
    if (file_exists($cache_file) && (time() - filemtime($cache_file) < $cache_lifetime)) {
        $cached_data_json = @file_get_contents($cache_file);
        if ($cached_data_json) {
            $klines_for_tf = json_decode($cached_data_json, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($klines_for_tf) && !empty($klines_for_tf)) {
                log_message("Klines for {$symbol} - {$tf_key} loaded from cache (" . count($klines_for_tf) . " candles).", 'INFO');
                return $klines_for_tf;
            } else {
                log_message("Cache for {$symbol} - {$tf_key} is invalid. Error: ".json_last_error_msg().". Fetching from API.", 'INFO');
            }
        } else {
            log_message("Cache file for {$symbol} - {$tf_key} is empty or unreadable. Fetching from API.", 'INFO');
        }
    } else {
        log_message( (file_exists($cache_file) ? "Cache for {$symbol} - {$tf_key} expired. " : "Cache for {$symbol} - {$tf_key} not found. ") . "Fetching from API.", 'INFO');
    }

    $url = '';
    if ($api_provider === 'bybit') {
        $interval_map = ['1m'=>'1', '3m'=>'3', '5m'=>'5', '15m'=>'15', '30m'=>'30', '1h'=>'60', '2h'=>'120', '4h'=>'240', '6h'=>'360', '12h'=>'720', '1d'=>'D', '1w'=>'W', '1M'=>'M'];
        $api_interval = $interval_map[$tf_key] ?? $tf_key;
        $query_params = http_build_query(['category' => 'linear', 'symbol' => $symbol, 'interval' => $api_interval, 'limit' => $klines_limit]);
        $url = $api_settings['base_url'] . $api_settings['klines_endpoint'] . '?' . $query_params;
    } elseif ($api_provider === 'binance_futures') {
        $query_params = http_build_query(['symbol' => $symbol, 'interval' => $tf_key, 'limit' => $klines_limit]);
        $url = $api_settings['base_url'] . $api_settings['klines_endpoint'] . '?' . $query_params;
    } else {
        log_message("API provider '{$api_provider}' fetching logic not implemented yet for {$symbol}, {$tf_key}.", 'ERROR');
        return null;
    }
    log_message("API URL: {$url}", "DEBUG");

    $context_options = ['http' => ['method' => 'GET', 'timeout' => 20, 'ignore_errors' => true, 'header' => "User-Agent: MarketAnalyzer/1.0\r\nAccept: application/json\r\n"]];
    $context = stream_context_create($context_options);
    $api_response_json = @file_get_contents($url, false, $context);

    if ($api_response_json === false) {
        $last_error = error_get_last();
        log_message("Failed to fetch data from API for {$symbol} - {$tf_key}. URL: {$url}. Error: " . ($last_error['message'] ?? 'Unknown file_get_contents error'), 'ERROR');
        return null;
    }
    log_message("Raw API response for {$symbol} - {$tf_key} (first 300 chars): ". substr($api_response_json,0,300), "DEBUG");

    $api_data = json_decode($api_response_json, true);
    $http_status_line = $http_response_header[0] ?? 'N/A';

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($api_data)) {
        log_message("Error decoding API response or unexpected structure for {$symbol} - {$tf_key}. HTTP Status: {$http_status_line}. JSON Error: ".json_last_error_msg().". Response: " . substr($api_response_json, 0, 300), 'ERROR');
        return null;
    }

    $formatted_klines = [];
    if ($api_provider === 'bybit') {
        if (!isset($api_data['result']['list']) || $api_data['retCode'] !== 0) {
            log_message("API Error (Bybit) for {$symbol} - {$tf_key}: " . ($api_data['retMsg'] ?? 'Unknown API error') . " (Code: {$api_data['retCode']})", 'ERROR');
            return null;
        }
        $kline_list_from_api = array_reverse((array)$api_data['result']['list']);
        foreach ($kline_list_from_api as $kline_item) {
            if (is_array($kline_item) && count($kline_item) >= 6) {
                $formatted_klines[] = [
                    'timestamp' => (int)$kline_item[0], 'open' => (float)$kline_item[1],
                    'high' => (float)$kline_item[2], 'low' => (float)$kline_item[3],
                    'close' => (float)$kline_item[4], 'volume' => (float)$kline_item[5]
                ];
            }
        }
    } elseif ($api_provider === 'binance_futures') {
        if (isset($api_data['code']) && isset($api_data['msg'])) {
            log_message("API Error (Binance) for {$symbol} - {$tf_key}: {$api_data['msg']} (Code: {$api_data['code']})", 'ERROR');
            return null;
        }
        if (empty($api_data) || !is_array(current($api_data))) {
            log_message("Unexpected API data format (Binance) for {$symbol} - {$tf_key}. Expected array of arrays.", 'ERROR');
            return null;
        }

        foreach ($api_data as $kline_item) {
            if (is_array($kline_item) && count($kline_item) >= 6) {
                $formatted_klines[] = [
                    'timestamp' => (int)$kline_item[0], 'open' => (float)$kline_item[1],
                    'high' => (float)$kline_item[2], 'low' => (float)$kline_item[3],
                    'close' => (float)$kline_item[4], 'volume' => (float)$kline_item[5]
                ];
            }
        }
    }

    if (!empty($formatted_klines)) {
        if (@file_put_contents($cache_file, json_encode($formatted_klines, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            log_message("Failed to write klines to cache for {$symbol} - {$tf_key}.", 'ERROR');
        } else {
            log_message("Fetched and cached " . count($formatted_klines) . " klines for {$symbol} - {$tf_key}.", 'INFO');
        }
        return $formatted_klines;
    }
    log_message("No klines formatted for {$symbol} - {$tf_key} after API call.", 'WARNING');
    return null;
}

function analyze_symbol_strategies($symbol, $all_klines_for_symbol) {
    global $config;
    log_message("Analyzing strategies for {$symbol}...", 'INFO');

    $symbol_analysis_result = [
        "symbol" => $symbol, "timestamp_utc" => gmdate("Y-m-d H:i:s"),
        "signal" => "NEUTRAL", "confidence" => 0.0,
        "summary" => "Analysis pending.", "strategies_triggered_details" => [],
        "debug_strategy_results_by_tf" => [], "error" => null
    ];

    $default_indicator_params = $config['default_symbol_indicator_params'] ?? [];
    $symbol_specific_overrides = $config['symbols'][$symbol] ?? [];
    $final_symbol_indicator_params = array_merge($default_indicator_params, $symbol_specific_overrides);

    $timeframes_config = $config['timeframes_settings'] ?? [];
    $active_strategies_config = $config['active_strategies'] ?? [];
    // $strategies_dir = $config['file_paths']['strategies_dir']; // Not needed if pre-loaded

    if (empty($timeframes_config) || empty($active_strategies_config)) {
        $symbol_analysis_result['error'] = "Timeframes or strategies configuration is empty for {$symbol}.";
        log_message($symbol_analysis_result['error'], 'ERROR');
        $symbol_analysis_result['summary'] = $symbol_analysis_result['error'];
        return $symbol_analysis_result;
    }

    $overall_buy_score_weighted = 0.0; $overall_sell_score_weighted = 0.0;
    $total_tf_weight_sum_for_normalization = 0.0;

    foreach ($timeframes_config as $tf_key => $tf_settings) {
        if (!isset($all_klines_for_symbol[$tf_key]) || empty($all_klines_for_symbol[$tf_key])) {
            log_message("No kline data for {$symbol} on {$tf_key} to analyze.", 'WARNING');
            $symbol_analysis_result['debug_strategy_results_by_tf'][$tf_key] = ['error' => 'No kline data available.', 'trend_signal' => 'ERROR', 'confidence' => 0.0, 'details'=>[], 'strategy_results'=>[]];
            continue;
        }
        $klines = $all_klines_for_symbol[$tf_key];
        $current_tf_weight = (float)($tf_settings['weight'] ?? 1.0);
        $total_tf_weight_sum_for_normalization += $current_tf_weight;

        $tf_analysis_data = ['trend_signal' => 'NEUTRAL', 'confidence' => 0.0, 'details' => [], 'strategy_results' => []];
        $tf_buy_confidence_sum_unweighted = 0.0; $tf_sell_confidence_sum_unweighted = 0.0;
        $max_possible_tf_base_confidence_sum = 0.0;

        foreach ($active_strategies_config as $strategy_id => $strategy_conf) {
            $function_to_call = $strategy_conf['function_name'] ?? ('analyze_' . $strategy_id);
            $base_strategy_confidence_param = (float)($strategy_conf['base_confidence'] ?? 0.1);
            $max_possible_tf_base_confidence_sum += $base_strategy_confidence_param;
            $strategy_display_name = $strategy_conf['display_name'] ?? ucfirst(str_replace(['strategy_', '_'], ['', ' '], $strategy_id));

            // Files are pre-loaded now, just check if function exists
            if (!function_exists($function_to_call)) {
                log_message("Strategy function not found (should have been pre-loaded): {$function_to_call} for strategy ID {$strategy_id} on {$symbol}_{$tf_key}", 'ERROR');
                $tf_analysis_data['strategy_results'][$strategy_display_name] = ['error' => "Function {$function_to_call} not found."];
                continue;
            }

            $params_for_strategy = $final_symbol_indicator_params;
            if (isset($strategy_conf['params']) && is_array($strategy_conf['params'])) {
                $params_for_strategy = array_merge($params_for_strategy, $strategy_conf['params']);
            }
            $params_for_strategy['base_confidence_from_config'] = $base_strategy_confidence_param;

            $strategy_run_result = $function_to_call($klines, $params_for_strategy);
            $tf_analysis_data['strategy_results'][$strategy_display_name] = $strategy_run_result;

            if (isset($strategy_run_result['triggered']) && $strategy_run_result['triggered']) {
                $signal_details_text = $strategy_run_result['details'] ?? 'Triggered';
                $tf_analysis_data['details'][] = "{$strategy_display_name}: {$signal_details_text}";
                if(isset($strategy_run_result['signal']) && ($strategy_run_result['signal'] === 'buy' || $strategy_run_result['signal'] === 'sell')) {
                    $symbol_analysis_result['strategies_triggered_details'][] = "[{$tf_key}] {$strategy_display_name}: {$signal_details_text}";
                }

                $actual_strategy_confidence_factor = (float)($strategy_run_result['confidence_factor'] ?? $base_strategy_confidence_param);

                if ($strategy_run_result['signal'] === 'buy') {
                    $tf_buy_confidence_sum_unweighted += $actual_strategy_confidence_factor;
                } elseif ($strategy_run_result['signal'] === 'sell') {
                    $tf_sell_confidence_sum_unweighted += $actual_strategy_confidence_factor;
                }
            }
        }

        $net_tf_confidence_unweighted = $tf_buy_confidence_sum_unweighted - $tf_sell_confidence_sum_unweighted;
        if ($net_tf_confidence_unweighted > 0.0001) $tf_analysis_data['trend_signal'] = 'BUY';
        elseif ($net_tf_confidence_unweighted < -0.0001) $tf_analysis_data['trend_signal'] = 'SELL';

        if ($max_possible_tf_base_confidence_sum > 0) {
            $tf_analysis_data['confidence'] = round($net_tf_confidence_unweighted / $max_possible_tf_base_confidence_sum, 3);
        } else {
            $tf_analysis_data['confidence'] = 0.0;
        }
        $tf_analysis_data['confidence'] = min(1.0, max(-1.0, $tf_analysis_data['confidence']));

        $symbol_analysis_result['debug_strategy_results_by_tf'][$tf_key] = $tf_analysis_data;

        if ($tf_analysis_data['trend_signal'] === 'BUY') {
            $overall_buy_score_weighted += abs($tf_analysis_data['confidence']) * $current_tf_weight;
        } elseif ($tf_analysis_data['trend_signal'] === 'SELL') {
            $overall_sell_score_weighted += abs($tf_analysis_data['confidence']) * $current_tf_weight;
        }
    }

    $net_overall_weighted_score = $overall_buy_score_weighted - $overall_sell_score_weighted;
    $max_abs_overall_weighted_score = $total_tf_weight_sum_for_normalization > 0 ? $total_tf_weight_sum_for_normalization : 1.0;

    if ($net_overall_weighted_score > (0.01 * $max_abs_overall_weighted_score)) {
        $symbol_analysis_result['signal'] = 'BUY';
        $symbol_analysis_result['confidence'] = round($overall_buy_score_weighted / $max_abs_overall_weighted_score, 3);
    } elseif ($net_overall_weighted_score < (-0.01 * $max_abs_overall_weighted_score)) {
        $symbol_analysis_result['signal'] = 'SELL';
        $symbol_analysis_result['confidence'] = round($overall_sell_score_weighted / $max_abs_overall_weighted_score, 3);
    } else {
        $symbol_analysis_result['signal'] = 'NEUTRAL';
        $strongest_directional_abs_score = max($overall_buy_score_weighted, $overall_sell_score_weighted);
        $symbol_analysis_result['confidence'] = round((1.0 - ($strongest_directional_abs_score / $max_abs_overall_weighted_score)),3) ;
    }
    $symbol_analysis_result['confidence'] = min(1.0, max(0.0, (float)$symbol_analysis_result['confidence']));

    $summary_parts = [];
    $summary_parts[] = "{$symbol} signal: {$symbol_analysis_result['signal']} (Confidence: " . number_format($symbol_analysis_result['confidence'], 2) . ").";
    if (!empty($symbol_analysis_result['strategies_triggered_details'])) {
        $summary_parts[] = "Supporting signals: " . implode('; ', array_unique($symbol_analysis_result['strategies_triggered_details']));
    } else {
        $summary_parts[] = "No strong directional strategies triggered across TFs.";
    }
    $symbol_analysis_result['summary'] = implode(" ", $summary_parts);

    if ($symbol_analysis_result['confidence'] >= $config['min_confidence_for_strong_signal'] &&
        ($symbol_analysis_result['signal'] === 'BUY' || $symbol_analysis_result['signal'] === 'SELL')) {
        log_message("STRONG SIGNAL: {$symbol_analysis_result['summary']}", 'SIGNAL');
    }

    return $symbol_analysis_result;
}


// --- Main execution flow ---
log_message("run_analysis.php started" . ($is_manual_run ? " (manual HTTP run)" : ""), 'INFO');

$overall_market_analysis = [];
$symbols_to_analyze = array_keys($config['symbols'] ?? []);

if (empty($symbols_to_analyze)) {
    log_message("No symbols configured for analysis. Exiting.", 'ERROR');
    if ($is_manual_run) {
        if(!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'No symbols configured.', 'logs' => $collected_http_logs]);
    }
    exit(1);
}

$results_dir = $config['file_paths']['results_dir'];
if (!is_dir($results_dir)) {
    if(!@mkdir($results_dir, 0775, true) && !is_dir($results_dir)) {
        log_message("Failed to create results directory: {$results_dir}", 'ERROR');
        if ($is_manual_run) {
            if(!headers_sent()) header('Content-Type: application/json', true, 500);
            echo json_encode(['success' => false, 'message' => "Failed to create results directory: {$results_dir}", 'logs' => $collected_http_logs]);
        }
        exit(1);
    }
}

// Pre-load all strategy files
$strategies_base_dir = $config['file_paths']['strategies_dir'];
foreach (array_keys($config['active_strategies'] ?? []) as $strategy_id_to_load) {
    $strategy_file_to_load = "{$strategies_base_dir}/{$strategy_id_to_load}.php";
    if (file_exists($strategy_file_to_load)) {
        require_once $strategy_file_to_load;
        log_message("Successfully loaded strategy file: {$strategy_file_to_load}", 'DEBUG');
    } else {
        log_message("Strategy file defined in config but not found: {$strategy_file_to_load}. This strategy will be skipped.", "ERROR");
    }
}


foreach ($symbols_to_analyze as $symbol_key) {
    log_message("Processing symbol: {$symbol_key}", 'INFO');
    $all_klines_for_symbol_tfs = [];
    $has_any_data_for_analysis = false;

    foreach (array_keys($config['timeframes_settings']) as $tf_key_loop) {
        $klines_data_loop = fetch_ohlcv_data_for_symbol_tf($symbol_key, $tf_key_loop);
        if ($klines_data_loop) {
            $all_klines_for_symbol_tfs[$tf_key_loop] = $klines_data_loop;
            $has_any_data_for_analysis = true;
        } else {
            log_message("Could not fetch/load klines for {$symbol_key} - {$tf_key_loop}. This TF will be skipped in analysis.", 'WARNING');
        }
    }

    if ($has_any_data_for_analysis) {
        $analysis_result_for_symbol = analyze_symbol_strategies($symbol_key, $all_klines_for_symbol_tfs);
        $overall_market_analysis[$symbol_key] = $analysis_result_for_symbol;

        $symbol_result_path = $config['file_paths']['results_dir'] . "/{$symbol_key}.json";
        if (file_put_contents($symbol_result_path, json_encode($analysis_result_for_symbol, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
            log_message("Failed to save result for {$symbol_key} to {$symbol_result_path}", 'ERROR');
        } else {
            log_message("Result for {$symbol_key} saved to {$symbol_result_path}", 'INFO');
        }
    } else {
        log_message("No kline data available for {$symbol_key} across all TFs. Skipping analysis.", 'ERROR');
        $error_entry = [
            "symbol" => $symbol_key, "timestamp_utc" => gmdate("Y-m-d H:i:s"),
            "signal" => "ERROR", "confidence" => 0.0,
            "summary" => "Failed to fetch any kline data for analysis.",
            "error" => "No kline data available for any configured timeframe.",
            "debug_strategy_results_by_tf" => [], "strategies_triggered_details" => []
        ];
        $overall_market_analysis[$symbol_key] = $error_entry;
        $symbol_error_path = $config['file_paths']['results_dir'] . "/{$symbol_key}.json";
        if (file_put_contents($symbol_error_path, json_encode($error_entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
            log_message("Failed to save error entry for {$symbol_key} to {$symbol_error_path}", 'ERROR');
        }
    }
    if(count($symbols_to_analyze) > 1 && php_sapi_name() === 'cli') {
        sleep(1);
    }
}

$market_overview_path = $config['file_paths']['market_overview_file'];
if (file_put_contents($market_overview_path, json_encode($overall_market_analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
    log_message("Failed to save market overview to {$market_overview_path}", 'ERROR');
} else {
    log_message("Market overview saved to {$market_overview_path}", 'INFO');
}

log_message("run_analysis.php finished.", 'INFO');

if ($is_manual_run) {
    if(!headers_sent()) {
        header('Content-Type: application/json');
    }
    // Теперь $collected_http_logs содержит все сообщения, которые ранее выводились через echo
    echo json_encode([
        'success' => true,
        'message' => 'Manual analysis run completed.', // Краткое сообщение
        'logs' => $collected_http_logs, // Массив логов для отображения в UI, если нужно
        'market_overview' => $overall_market_analysis
    ]);
}
exit(0);