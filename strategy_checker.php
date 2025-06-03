<?php
// /market-analyzer/strategy_checker.php
ini_set('display_errors', 0);
error_reporting(E_ALL);

// --- НАЧАЛО ОТЛАДКИ ЗАГРУЗКИ КОНФИГА ---
// error_log("--- strategy_checker.php EXECUTING ---");
$config_path_checker = __DIR__ . '/config.php';
if (!file_exists($config_path_checker)) { /* ... (обработка как в fetch_data.php) ... */ exit; }
$config = require $config_path_checker;
if (!is_array($config)) { /* ... (обработка как в fetch_data.php) ... */ exit; }
// error_log("strategy_checker.php: config.php loaded.");
// --- КОНЕЦ ОТЛАДКИ ЗАГРУЗКИ КОНФИГА ---

if (!headers_sent()) {
    header('Content-Type: application/json');
}

$response = ['success' => false, 'message' => 'Ошибка инициализации чекера стратегий.', 'analysis_result' => null];

$symbol_from_get_raw = trim($_GET['symbol'] ?? '');
$symbol_from_get = strtoupper(preg_replace("/[^a-zA-Z0-9]/", "", $symbol_from_get_raw));

$symbol_analysis_result = [
    "symbol" => htmlspecialchars($symbol_from_get ?: 'UNKNOWN'), "timestamp_utc" => gmdate("Y-m-d H:i:s"),
    "overall_trend_signal" => "ERROR", "overall_confidence" => 0,
    "summary" => "Ошибка инициализации обработки символа.", "timeframes_analysis" => [],
    "triggered_strategies_summary" => [], "debug_details" => ['errors' => [], 'info' => []]
];

try {
    // error_log("strategy_checker.php: Processing symbol_raw '{$symbol_from_get_raw}', processed '{$symbol_from_get}'");
    if (empty($symbol_from_get)) {
        throw new InvalidArgumentException('Символ не предоставлен или пуст для strategy_checker.');
    }
    if (!isset($config['api_settings']['symbols'][$symbol_from_get])) {
        throw new InvalidArgumentException('Неверный/неподдерживаемый символ для анализа в strategy_checker: ' . htmlspecialchars($symbol_from_get_raw));
    }
    $symbol = $symbol_from_get;
    $symbol_analysis_result['symbol'] = $symbol;

    $default_indicator_params = $config['default_symbol_indicator_params'] ?? [];
    $symbol_specific_overrides = $config['api_settings']['symbols'][$symbol];
    $final_symbol_indicator_params = array_merge($default_indicator_params, $symbol_specific_overrides);

    $timeframes_config = $config['timeframes_settings'] ?? [];
    $active_strategies_list_config = $config['active_strategies'] ?? [];
    $klines_cache_dir = $config['file_paths']['klines_cache_dir'];
    $strategies_dir = $config['file_paths']['strategies_dir'];

    if (empty($timeframes_config)) throw new RuntimeException("Конфигурация 'timeframes_settings' пуста.");
    if (empty($active_strategies_list_config)) throw new RuntimeException("Конфигурация 'active_strategies' пуста.");

    $final_buy_score = 0.0; $final_sell_score = 0.0; $total_tf_weight_processed = 0.0;
    $distinct_triggered_summary_entries = [];

    foreach ($timeframes_config as $tf_key => $tf_settings) {
        $current_tf_weight = $tf_settings['weight'] ?? 1.0;
        $total_tf_weight_processed += $current_tf_weight;
        $symbol_analysis_result['timeframes_analysis'][$tf_key] = [
            'trend_signal' => 'NEUTRAL', 'confidence' => 0, 'details' => [], 'strategy_results' => []
        ];
        $symbol_analysis_result['debug_details'][$tf_key]['info'][] = "Обработка ТФ: {$tf_key} (вес: {$current_tf_weight})";

        $cache_file = "{$klines_cache_dir}/{$symbol}_{$tf_key}.json";
        if (!file_exists($cache_file) || !is_readable($cache_file)) {
            $error_msg = "Кеш для {$tf_key} не найден/нечитаем.";
            $symbol_analysis_result['timeframes_analysis'][$tf_key]['error'] = $error_msg;
            $symbol_analysis_result['debug_details'][$tf_key]['errors'][] = $error_msg;
            // error_log("strategy_checker.php for {$symbol}_{$tf_key}: Cache file error - {$error_msg}");
            continue;
        }
        $klines_json = file_get_contents($cache_file);
        $klines = json_decode($klines_json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($klines) || empty($klines)) {
            $error_msg = "Ошибка/пустые данные кеша {$tf_key}. JSON Err: " . json_last_error_msg();
            $symbol_analysis_result['timeframes_analysis'][$tf_key]['error'] = $error_msg;
            $symbol_analysis_result['debug_details'][$tf_key]['errors'][] = $error_msg;
            // error_log("strategy_checker.php for {$symbol}_{$tf_key}: Klines data error - {$error_msg}");
            continue;
        }
        // error_log("strategy_checker.php for {$symbol}_{$tf_key}: Loaded " . count($klines) . " klines from cache.");

        $tf_cumulative_buy_confidence = 0.0; $tf_cumulative_sell_confidence = 0.0;
        $max_possible_confidence_sum_for_tf = 0.0;

        foreach ($active_strategies_list_config as $strategy_file_name => $strategy_entry_config) {
            $strategy_path = "{$strategies_dir}/{$strategy_file_name}.php";
            $function_to_call = "analyze_" . $strategy_file_name;
            $strategy_base_conf = $strategy_entry_config['base_confidence'] ?? 0.0;
            $strategy_display_name = ucfirst(str_replace(['strategy_', '_'], ['', ' '], $strategy_file_name));
            $max_possible_confidence_sum_for_tf += $strategy_base_conf;
            // error_log("strategy_checker.php for {$symbol}_{$tf_key}: Processing strategy '{$strategy_display_name}'");


            if (!file_exists($strategy_path)) {
                $symbol_analysis_result['timeframes_analysis'][$tf_key]['strategy_results'][$strategy_display_name]['error'] = "Файл стратегии не найден: {$strategy_file_name}.php";
                $symbol_analysis_result['debug_details'][$tf_key]['errors'][] = "Файл стратегии {$strategy_file_name}.php не найден.";
                continue;
            }
            require_once $strategy_path;
            if (!function_exists($function_to_call)) {
                $symbol_analysis_result['timeframes_analysis'][$tf_key]['strategy_results'][$strategy_display_name]['error'] = "Функция {$function_to_call} не найдена.";
                $symbol_analysis_result['debug_details'][$tf_key]['errors'][] = "Функция {$function_to_call} для {$strategy_file_name} не найдена.";
                continue;
            }

            $params_for_strategy_call = $final_symbol_indicator_params;
            if (isset($strategy_entry_config['params']) && is_array($strategy_entry_config['params'])) {
                $params_for_strategy_call = array_merge($params_for_strategy_call, $strategy_entry_config['params']);
            }
            $params_for_strategy_call['base_confidence_from_config'] = $strategy_base_conf;

            $run_result = $function_to_call($klines, $params_for_strategy_call);
            $log_strat_name = $run_result['name'] ?? $strategy_display_name;
            $symbol_analysis_result['timeframes_analysis'][$tf_key]['strategy_results'][$log_strat_name] = $run_result;

            if (isset($run_result['triggered']) && $run_result['triggered']) {
                $actual_conf = $run_result['confidence_factor'] ?? $strategy_base_conf;
                if (!empty($run_result['details'])) $symbol_analysis_result['timeframes_analysis'][$tf_key]['details'][] = "{$log_strat_name}: {$run_result['details']}";
                $distinct_triggered_summary_entries[] = "{$log_strat_name} ({$tf_key})";
                if ($run_result['signal'] === 'buy') $tf_cumulative_buy_confidence += $actual_conf;
                elseif ($run_result['signal'] === 'sell') $tf_cumulative_sell_confidence += $actual_conf;
            }
        }

        $net_tf_conf = $tf_cumulative_buy_confidence - $tf_cumulative_sell_confidence;
        if ($net_tf_conf > 0) $symbol_analysis_result['timeframes_analysis'][$tf_key]['trend_signal'] = 'BUY';
        elseif ($net_tf_conf < 0) $symbol_analysis_result['timeframes_analysis'][$tf_key]['trend_signal'] = 'SELL';
        $symbol_analysis_result['timeframes_analysis'][$tf_key]['confidence'] = ($max_possible_confidence_sum_for_tf > 0) ? round(($net_tf_conf / $max_possible_confidence_sum_for_tf) * 100) : 0;
        $symbol_analysis_result['timeframes_analysis'][$tf_key]['confidence'] = min(100, max(-100, (int)$symbol_analysis_result['timeframes_analysis'][$tf_key]['confidence']));

        if ($symbol_analysis_result['timeframes_analysis'][$tf_key]['trend_signal'] === 'BUY') {
            $final_buy_score += abs($symbol_analysis_result['timeframes_analysis'][$tf_key]['confidence']) * $current_tf_weight;
        } elseif ($symbol_analysis_result['timeframes_analysis'][$tf_key]['trend_signal'] === 'SELL') {
            $final_sell_score += abs($symbol_analysis_result['timeframes_analysis'][$tf_key]['confidence']) * $current_tf_weight;
        }
    }

    $overall_net_score = $final_buy_score - $final_sell_score;
    $max_overall_score = ($total_tf_weight_processed > 0) ? ($total_tf_weight_processed * 100) : 100;

    if ($overall_net_score > 0.01 * $max_overall_score) { // Порог > 1% от макс. возможного для определения направления
        $symbol_analysis_result['overall_trend_signal'] = 'BUY';
        $symbol_analysis_result['overall_confidence'] = round(($final_buy_score / $max_overall_score) * 100);
    } elseif ($overall_net_score < -0.01 * $max_overall_score) {
        $symbol_analysis_result['overall_trend_signal'] = 'SELL';
        $symbol_analysis_result['overall_confidence'] = round(($final_sell_score / $max_overall_score) * 100);
    } else {
        $symbol_analysis_result['overall_trend_signal'] = 'NEUTRAL';
        $strongest_directional_abs_score = max($final_buy_score, $final_sell_score);
        $symbol_analysis_result['overall_confidence'] = ($max_overall_score > 0) ? (100 - round(($strongest_directional_abs_score / $max_overall_score) * 100)) : 100;
    }
    $symbol_analysis_result['overall_confidence'] = min(100, max(0, (int)$symbol_analysis_result['overall_confidence']));
    $symbol_analysis_result['triggered_strategies_summary'] = array_values(array_unique($distinct_triggered_summary_entries));

    $summary_parts = []; $overall_sig_word = strtolower($symbol_analysis_result['overall_trend_signal']);
    if ($overall_sig_word !== 'error') $summary_parts[] = ucfirst(strtolower($symbol)) . " -> " . strtoupper($overall_sig_word) . " (Уверенность: {$symbol_analysis_result['overall_confidence']}%).";
    $key_obs_summary = [];
    foreach ($symbol_analysis_result['timeframes_analysis'] as $tf_k_sum => $tf_a_sum_data) {
        if (!empty($tf_a_sum_data['details'])) {
            $unique_tf_dets = array_unique($tf_a_sum_data['details']);
            if(!empty($unique_tf_dets)) $key_obs_summary[] = "{$tf_k_sum}:[" . implode(' | ', $unique_tf_dets) . "]";
        }
    }
    if (!empty($key_obs_summary)) $summary_parts[] = "Ключевые наблюдения: " . implode('; ', $key_obs_summary) . ".";
    elseif ($overall_sig_word !== 'error' && empty($distinct_triggered_summary_entries)) $summary_parts[] = "Ни одна из стратегий не дала явных сигналов.";
    elseif (!empty($distinct_triggered_summary_entries) && $overall_sig_word === 'neutral') $summary_parts[] = "Сработавшие сигналы не сформировали явного направления.";
    $symbol_analysis_result['summary'] = implode(" ", $summary_parts);
    if (empty(trim($symbol_analysis_result['summary'])) && $overall_sig_word !== 'error') $symbol_analysis_result['summary'] = ucfirst(strtolower($symbol)) . ": нет достаточных данных или сигналов.";

    $response['success'] = true; $response['message'] = "Анализ для {$symbol} завершен."; $response['analysis_result'] = $symbol_analysis_result;

} catch (InvalidArgumentException $e) {
    $response['message'] = "Ошибка входных данных для " . htmlspecialchars($symbol_from_get_raw) . ": " . $e->getMessage();
    $symbol_analysis_result['summary'] = $response['message']; $response['analysis_result'] = $symbol_analysis_result;
    if(!headers_sent()) http_response_code(400);
} catch (RuntimeException $e) {
    $response['message'] = "Ошибка выполнения при анализе " . htmlspecialchars($symbol_from_get_raw) . ": " . $e->getMessage();
    error_log("MarketAnalyzer - Strategy Checker Runtime Error for {$symbol_from_get}: " . $e->getMessage());
    $symbol_analysis_result['summary'] = $response['message']; $response['analysis_result'] = $symbol_analysis_result;
    if(!headers_sent()) http_response_code(500);
} catch (Throwable $t) {
    $response['message'] = "Критическая ошибка в strategy_checker для " . htmlspecialchars($symbol_from_get_raw) . ": " . $t->getMessage();
    error_log("MarketAnalyzer - КРИТИЧЕСКАЯ ОШИБКА в strategy_checker.php: " . get_class($t) . ": " . $t->getMessage() . " in " . $t->getFile() . ":" . $t->getLine());
    $symbol_analysis_result['summary'] = $response['message'];
    if (isset($config['debug_mode']) && $config['debug_mode'] === true) {
        $symbol_analysis_result['debug_details']['critical_error'] = get_class($t) . ": " . $t->getFile() . ":" . $t->getLine();
    }
    $response['analysis_result'] = $symbol_analysis_result;
    if(!headers_sent()) http_response_code(500);
}

if (!headers_sent()) { header('Content-Type: application/json'); }
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
?>