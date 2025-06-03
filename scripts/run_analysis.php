<?php
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);
set_time_limit(0);
ignore_user_abort(true);

$collected_http_logs = [];

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
if (isset($config['file_paths']['error_log_file']) && ini_get('error_log') !== $config['file_paths']['error_log_file']) {
    ini_set('error_log', $config['file_paths']['error_log_file']);
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
    global $config, $is_manual_run, $collected_http_logs;
    $timestamp = gmdate("Y-m-d H:i:s \U\T\C");
    $log_entry_msg_only = "[{$level}] {$message}";
    $log_entry_file = "[{$timestamp}] [{$level}] {$message}\n";

    if (php_sapi_name() === 'cli') {
        echo $log_entry_file;
    } elseif ($is_manual_run && ($config['debug_mode'] ?? false)) { // Check if debug_mode is set
        $collected_http_logs[] = $log_entry_msg_only;
    }

    $log_dir = $config['file_paths']['logs_dir'] ?? (__DIR__ . '/../logs');

    if (!is_dir($log_dir)) {
        if(!@mkdir($log_dir, 0775, true) && !is_dir($log_dir)) {
            $warn_msg = "[WARNING] Failed to create log directory: {$log_dir}";
            if (php_sapi_name() === 'cli') echo $warn_msg . "\n";
            elseif ($is_manual_run && ($config['debug_mode'] ?? false)) $collected_http_logs[] = $warn_msg;
            return;
        }
    }

    $log_file_path = null;
    $effective_debug_mode = ($config['debug_mode'] ?? false); // Default to false if not set

    switch (strtoupper($level)) {
        case 'ERROR': $log_file_path = $config['file_paths']['error_log_file'] ?? ($log_dir . '/errors.log'); break;
        case 'SIGNAL': $log_file_path = $config['file_paths']['signals_log_file'] ?? ($log_dir . '/signals.log'); break;
        case 'INFO':
        case 'DEBUG': // Treat DEBUG as INFO for file logging based on debug_mode
            if ($effective_debug_mode) $log_file_path = $config['file_paths']['info_log_file'] ?? ($log_dir . '/info.log');
            break;
        default:
            if ($effective_debug_mode) {
                $log_entry_file = "[{$timestamp}] [NOTICE] Unknown log level '{$level}': {$message}\n";
                $log_file_path = $config['file_paths']['error_log_file'] ?? ($log_dir . '/errors.log');
            }
            break;
    }

    if ($log_file_path) {
        @error_log($log_entry_file, 3, $log_file_path);
    }
}

function fetch_ohlcv_data_for_symbol_tf($symbol, $tf_key) {
    global $config;
    log_message("Fetching klines for {$symbol} - {$tf_key}...", 'DEBUG');
    $api_provider = $config['api_provider'] ?? 'bybit'; // Default to bybit if not set
    $api_conf_key = $api_provider . '_api_settings';
    if (!isset($config[$api_conf_key])) {
        log_message("API provider '{$api_provider}' configuration not found for symbol {$symbol}, TF {$tf_key}.", 'ERROR');
        return null;
    }
    $api_settings = $config[$api_conf_key];
    $klines_limit = $config['klines_limit_per_tf'] ?? 200;
    $cache_dir = $config['file_paths']['klines_cache_dir'] ?? (__DIR__ . '/../results/klines_cache');
    $cache_lifetime = $config['cache_lifetime_seconds'] ?? 300;

    if (!is_dir($cache_dir)) {
        if (!@mkdir($cache_dir, 0775, true) && !is_dir($cache_dir)) {
            log_message("Failed to create klines cache directory: {$cache_dir}", 'ERROR');
            return null;
        }
    }
    $cache_file = "{$cache_dir}/{$symbol}_{$tf_key}_klines.json";
    if (file_exists($cache_file) && (time() - filemtime($cache_file) < $cache_lifetime)) {
        $cached_data_json = @file_get_contents($cache_file);
        if ($cached_data_json) {
            $klines_for_tf = json_decode($cached_data_json, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($klines_for_tf) && !empty($klines_for_tf)) {
                log_message("Klines for {$symbol} - {$tf_key} loaded from klines cache (" . count($klines_for_tf) . " candles).", 'INFO');
                return $klines_for_tf;
            } else { log_message("Cache for {$symbol} - {$tf_key} (klines) is invalid. Error: ".json_last_error_msg().". Fetching from API.", 'INFO');}
        } else { log_message("Cache file for {$symbol} - {$tf_key} (klines) is empty. Fetching from API.", 'INFO');}
    } else { log_message( (file_exists($cache_file) ? "Cache for {$symbol} - {$tf_key} (klines) expired. " : "Cache for {$symbol} - {$tf_key} (klines) not found. ") . "Fetching from API.", 'INFO');}

    $url = '';
    if ($api_provider === 'bybit') {
        $interval_map = ['1m'=>'1', '3m'=>'3', '5m'=>'5', '15m'=>'15', '30m'=>'30', '1h'=>'60', '2h'=>'120', '4h'=>'240', '6h'=>'360', '12h'=>'720', '1d'=>'D', '1w'=>'W', '1M'=>'M'];
        $api_interval = $interval_map[$tf_key] ?? $tf_key;
        $query_params = http_build_query(['category' => 'linear', 'symbol' => $symbol, 'interval' => $api_interval, 'limit' => $klines_limit]);
        $url = ($api_settings['base_url'] ?? '') . ($api_settings['klines_endpoint'] ?? '') . '?' . $query_params;
    } else { log_message("API provider '{$api_provider}' klines fetching not implemented for OHLCV.", 'ERROR'); return null; }

    if (empty($api_settings['base_url']) || empty($api_settings['klines_endpoint'])) {
        log_message("API base_url or klines_endpoint not configured for provider '{$api_provider}'.", 'ERROR'); return null;
    }
    log_message("Klines API URL: {$url}", "DEBUG");

    $context_options = ['http' => ['method' => 'GET', 'timeout' => 20, 'ignore_errors' => true, 'header' => "User-Agent: MarketAnalyzer/1.0\r\nAccept: application/json\r\n"]];
    $context = stream_context_create($context_options);
    $api_response_json = @file_get_contents($url, false, $context);

    if ($api_response_json === false) { $last_error = error_get_last(); log_message("Failed to fetch klines from API for {$symbol} - {$tf_key}. Error: " . ($last_error['message'] ?? 'Unknown'), 'ERROR'); return null; }
    log_message("Raw klines API response for {$symbol} - {$tf_key} (first 300 chars): ". substr($api_response_json,0,300), "DEBUG");

    $api_data = json_decode($api_response_json, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($api_data)) { log_message("Error decoding klines API response for {$symbol} - {$tf_key}. JSON Error: ".json_last_error_msg(), 'ERROR'); return null; }

    $formatted_klines = [];
    if ($api_provider === 'bybit') {
        if (!isset($api_data['result']['list']) || ($api_data['retCode'] ?? -1) !== 0) { log_message("API Error (Bybit klines) for {$symbol} - {$tf_key}: " . ($api_data['retMsg'] ?? 'Unknown error structure or retCode not 0'), 'ERROR'); return null; }
        $kline_list_from_api = array_reverse((array)($api_data['result']['list'] ?? []));
        foreach ($kline_list_from_api as $k_item) { if (is_array($k_item) && count($k_item) >= 7) { // Bybit provides 7 fields: ts,o,h,l,c,v,turnover
            $formatted_klines[] = ['timestamp'=>(int)$k_item[0],'open'=>(float)$k_item[1],'high'=>(float)$k_item[2],'low'=>(float)$k_item[3],'close'=>(float)$k_item[4],'volume'=>(float)$k_item[5]];
        }}
    }

    if (!empty($formatted_klines)) {
        if (@file_put_contents($cache_file, json_encode($formatted_klines, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) { log_message("Failed to write klines to cache for {$symbol} - {$tf_key}.", 'ERROR'); }
        else { log_message("Fetched and cached klines for {$symbol} - {$tf_key} (" . count($formatted_klines) . " candles).", 'INFO'); }
        return $formatted_klines;
    }
    log_message("No klines formatted for {$symbol} - {$tf_key} from API.", 'WARNING');
    return null;
}

function fetch_extended_data_for_symbol_tf($symbol, $tf_key) {
    global $config;
    log_message("Fetching all data for {$symbol} - {$tf_key}...", 'DEBUG');
    $klines = fetch_ohlcv_data_for_symbol_tf($symbol, $tf_key);
    if (!$klines) {
        log_message("Failed to get klines, cannot fetch extended data for {$symbol} - {$tf_key}.", 'WARNING');
        return null;
    }

    $extended_data_payload = ['klines' => $klines];
    $api_provider = $config['api_provider'] ?? 'bybit';
    $api_settings = $config[$api_provider . '_api_settings'] ?? null;

    if (!$api_settings) { log_message("API settings for {$api_provider} not found. Skipping OI/CVD/Funding.", "WARNING"); return $extended_data_payload; }

    // --- Fetch Open Interest (Bybit example) ---
    if (isset($api_settings['oi_endpoint']) && $api_provider === 'bybit' && !empty($klines)) {
        $oi_interval_map_bybit = ['1m'=>'1min','3m'=>'3min','5m'=>'5min', '15m'=>'15min', '30m'=>'30min', '1h'=>'1H', '2h'=>'2H','4h'=>'4H', '6h'=>'6H', '12h'=>'12H', '1d'=>'1D'];
        $api_oi_interval = $oi_interval_map_bybit[$tf_key] ?? null;

        if ($api_oi_interval) {
            // Bybit OI limit is 200. We may need to make multiple requests if klines_limit > 200.
            // For simplicity, fetch OI for the same number of periods as klines, up to Bybit's limit for a single OI request.
            // For more historical OI, pagination or a different approach would be needed.
            $oi_limit = min(count($klines), 200);
            $query_params_oi = http_build_query(['category' => 'linear', 'symbol' => $symbol, 'intervalTime' => $api_oi_interval, 'limit' => $oi_limit]);
            $url_oi = ($api_settings['base_url'] ?? '') . ($api_settings['oi_endpoint'] ?? '') . '?' . $query_params_oi;
            log_message("Fetching OI from: {$url_oi}", "DEBUG");

            $context_oi = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 10, 'ignore_errors' => true, 'header' => "User-Agent: MarketAnalyzer/1.0\r\n"]]);
            $api_response_oi_json = @file_get_contents($url_oi, false, $context_oi);
            if ($api_response_oi_json) {
                $api_oi_data = json_decode($api_response_oi_json, true);
                if (isset($api_oi_data['retCode']) && $api_oi_data['retCode'] === 0 && isset($api_oi_data['result']['list']) && !empty($api_oi_data['result']['list'])) {
                    $oi_map_by_ts = [];
                    // Bybit OI data is newest first, klines are oldest first (after our reverse)
                    $api_oi_list_reversed = array_reverse((array)$api_oi_data['result']['list']);
                    foreach ($api_oi_list_reversed as $oi_entry) {
                        if (isset($oi_entry['timestamp'], $oi_entry['openInterest'])) {
                            $oi_map_by_ts[(int)$oi_entry['timestamp']] = (float)$oi_entry['openInterest'];
                        }
                    }

                    foreach($extended_data_payload['klines'] as $k_idx => $kline_val) {
                        if (isset($oi_map_by_ts[$kline_val['timestamp']])) {
                            $extended_data_payload['klines'][$k_idx]['oi'] = $oi_map_by_ts[$kline_val['timestamp']];
                        } else {
                            // If no exact match, try to find closest *previous* OI. This is complex.
                            // For now, only exact timestamp matches. Or fill forward previous OI if available.
                            // Let's keep it simple: if not matched, it will be 'null' or not set.
                            $extended_data_payload['klines'][$k_idx]['oi'] = null; // Explicitly null if not found
                        }
                    }
                    log_message("Fetched and processed OI for {$symbol} - {$tf_key}.", 'INFO');
                } else { log_message("API Error or no OI data for {$symbol} - {$tf_key}: " . ($api_oi_data['retMsg'] ?? 'Empty list or JSON error'), 'WARNING');}
            } else { log_message("Failed to fetch OI JSON for {$symbol} - {$tf_key}.", 'WARNING');}
        } else { log_message("OI interval not mapped for TF: {$tf_key} (Provider: {$api_provider}). Skipping OI.", 'DEBUG');}
    } else {
        foreach($extended_data_payload['klines'] as $k_idx => $kline_val) { // Ensure 'oi' key exists even if not fetched
            $extended_data_payload['klines'][$k_idx]['oi'] = null;
        }
    }

    // --- Fetch Funding Rate (Bybit example) ---
    // Funding rate is typically for a single point in time or limited history.
    // We can fetch the most recent one and attach it to the last kline, or a few recent ones.
    if (isset($api_settings['funding_rate_endpoint']) && $api_provider === 'bybit' && !empty($klines)) {
        $query_params_fr = http_build_query(['category' => 'linear', 'symbol' => $symbol, 'limit' => 1]); // Fetch latest
        $url_fr = ($api_settings['base_url'] ?? '') . ($api_settings['funding_rate_endpoint'] ?? '') . '?' . $query_params_fr;
        log_message("Fetching Funding Rate from: {$url_fr}", "DEBUG");
        // ... (similar AJAX call as for OI) ...
        // For now, let's assume we attach it to the last kline if fetched.
        // $extended_data_payload['klines'][count($klines)-1]['funding_rate'] = $fetched_funding_rate;
        // For this example, we'll just ensure the key exists as null.
    }
    foreach($extended_data_payload['klines'] as $k_idx => $kline_val) {
        $extended_data_payload['klines'][$k_idx]['funding_rate'] = null; // Placeholder
        $extended_data_payload['klines'][$k_idx]['cvd'] = null; // Placeholder for CVD
    }

    return $extended_data_payload;
}

function analyze_symbol_strategies($symbol, $all_extended_data_for_symbol_tfs) {
    global $config;
    log_message("Analyzing strategies for {$symbol}...", 'INFO');
    $current_time_utc_str = gmdate("Y-m-d H:i:s");
    $symbol_analysis_result = [
        "symbol" => $symbol, "timestamp_utc" => $current_time_utc_str,
        "signal" => "NEUTRAL", "confidence" => 0.0,
        "summary" => "Analysis initialized.", "strategies_triggered_details" => [],
        "debug_strategy_results_by_tf" => [], "error" => null,
        "extra_factors" => ['divergence' => 'none', 'volume_support' => false, 'pattern_confirmed' => false, 'liquidity_zone' => 'none', 'confluence_count' => 0]
    ];

    $default_indicator_params = $config['default_symbol_params'] ?? []; // Renamed from 'default_symbol_indicator_params'
    $symbol_specific_overrides = $config['symbols'][$symbol] ?? [];
    $final_symbol_indicator_params = array_merge($default_indicator_params, $symbol_specific_overrides);

    $timeframes_config = $config['timeframes_settings'] ?? [];
    $active_strategies_config = $config['active_strategies'] ?? [];
    $strategy_weights_map = $config['strategy_weights'] ?? [];
    $confluence_settings = $config['confluence_settings'] ?? ['enabled' => false, 'min_strategies_match' => 3];


    if (empty($timeframes_config)) { $symbol_analysis_result['error'] = "Timeframes config empty."; log_message($symbol_analysis_result['error'], 'ERROR'); return $symbol_analysis_result; }
    if (empty($active_strategies_config)) { $symbol_analysis_result['error'] = "Active strategies config empty."; log_message($symbol_analysis_result['error'], 'ERROR'); return $symbol_analysis_result; }

    $overall_weighted_buy_score_agg = 0.0;
    $overall_weighted_sell_score_agg = 0.0;
    $total_tf_weights_sum_for_norm = 0.0;
    $all_tf_signals_for_confluence = []; // For overall confluence calculation

    foreach ($timeframes_config as $tf_key => $tf_settings) {
        if (!isset($all_extended_data_for_symbol_tfs[$tf_key]['klines']) || empty($all_extended_data_for_symbol_tfs[$tf_key]['klines'])) {
            $symbol_analysis_result['debug_strategy_results_by_tf'][$tf_key] = ['error' => 'No kline data.', 'trend_signal' => 'ERROR', 'confidence' => 0.0, 'details'=>[], 'strategy_results'=>[], 'confluence_matches' => 0];
            continue;
        }
        $current_tf_klines_data = $all_extended_data_for_symbol_tfs[$tf_key]['klines'];
        $current_tf_weight = (float)($tf_settings['weight'] ?? 1.0);
        $total_tf_weights_sum_for_norm += $current_tf_weight;

        $tf_analysis_results_holder = ['trend_signal' => 'NEUTRAL', 'confidence' => 0.0, 'details' => [], 'strategy_results' => [], 'confluence_matches' => 0];
        $tf_cumulative_weighted_buy_conf = 0.0;
        $tf_cumulative_weighted_sell_conf = 0.0;
        $tf_max_possible_weighted_conf_sum_this_tf = 0.00001;
        $tf_buy_signals_count = 0; $tf_sell_signals_count = 0;


        foreach ($active_strategies_config as $strategy_id => $strategy_conf_entry) {
            $func_to_call = $strategy_conf_entry['function_name'] ?? ('analyze_' . $strategy_id);
            $strat_overall_weight = (float)($strategy_weights_map[$strategy_id] ?? 1.0);

            if ($strat_overall_weight <= 0) { log_message("Strategy '{$strategy_id}' has <=0 weight, skipping.", 'DEBUG'); continue; }
            $tf_max_possible_weighted_conf_sum_this_tf += (1.0 * $strat_overall_weight);

            if (!function_exists($func_to_call)) { log_message("Func {$func_to_call} for {$strategy_id} not found.", 'ERROR'); $tf_analysis_results_holder['strategy_results'][$strategy_id] = ['error' => "Function {$func_to_call} not found."]; continue; }

            $strat_params = $final_symbol_indicator_params;
            if (isset($strategy_conf_entry['params']) && is_array($strategy_conf_entry['params'])) {
                $strat_params = array_merge($strat_params, $strategy_conf_entry['params']);
            }
            $strat_params['base_confidence_from_config'] = (float)($strategy_conf_entry['base_confidence'] ?? 0.1);

            $strategy_run_output = $func_to_call($current_tf_klines_data, $strat_params);
            $tf_analysis_results_holder['strategy_results'][$strategy_id] = $strategy_run_output;

            if (isset($strategy_run_output['triggered']) && $strategy_run_output['triggered']) {
                $strat_details = $strategy_run_output['details'] ?? 'Triggered';
                $strat_name_display = $strategy_run_output['name'] ?? $strategy_id;
                $tf_analysis_results_holder['details'][] = "{$strat_name_display}: {$strat_details}";

                $strat_signal = strtolower($strategy_run_output['signal'] ?? 'neutral');
                if($strat_signal === 'buy' || $strat_signal === 'sell') {
                    $symbol_analysis_result['strategies_triggered_details'][] = "[{$tf_key}] {$strat_name_display}: {$strat_details}";
                    if ($strat_signal === 'buy') $tf_buy_signals_count++;
                    if ($strat_signal === 'sell') $tf_sell_signals_count++;
                }

                $reported_strat_conf_factor = (float)($strategy_run_output['confidence_factor'] ?? 0.0);
                $weighted_strat_conf = $reported_strat_conf_factor * $strat_overall_weight;

                if ($strat_signal === 'buy') {
                    $tf_cumulative_weighted_buy_conf += $weighted_strat_conf;
                } elseif ($strat_signal === 'sell') {
                    $tf_cumulative_weighted_sell_conf += $weighted_strat_conf;
                }

                if (isset($strategy_run_output['extra_factors']) && is_array($strategy_run_output['extra_factors'])) {
                    foreach($strategy_run_output['extra_factors'] as $ef_key_loop => $ef_val_loop) {
                        if (isset($symbol_analysis_result['extra_factors'][$ef_key_loop]) && $ef_val_loop && $ef_val_loop !== 'none' && $ef_val_loop !== false) {
                            if (($ef_key_loop === 'divergence' || $ef_key_loop === 'liquidity_zone') && $symbol_analysis_result['extra_factors'][$ef_key_loop] === 'none') {
                                $symbol_analysis_result['extra_factors'][$ef_key_loop] = $ef_val_loop;
                            } elseif (is_bool($symbol_analysis_result['extra_factors'][$ef_key_loop])) {
                                $symbol_analysis_result['extra_factors'][$ef_key_loop] = true;
                            }
                        }
                    }
                }
            }
        }

        // TF Confluence Check
        if ($confluence_settings['enabled'] ?? false) {
            if ($tf_buy_signals_count >= ($confluence_settings['min_strategies_match'] ?? 3)) {
                $tf_analysis_results_holder['confluence_matches'] = $tf_buy_signals_count;
                $tf_analysis_results_holder['details'][] = "Confluence BUY: {$tf_buy_signals_count} strategies.";
            } elseif ($tf_sell_signals_count >= ($confluence_settings['min_strategies_match'] ?? 3)) {
                $tf_analysis_results_holder['confluence_matches'] = $tf_sell_signals_count;
                $tf_analysis_results_holder['details'][] = "Confluence SELL: {$tf_sell_signals_count} strategies.";
            }
        }


        $net_tf_weighted_conf = $tf_cumulative_weighted_buy_conf - $tf_cumulative_weighted_sell_conf;
        if ($net_tf_weighted_conf > 0.0001) $tf_analysis_results_holder['trend_signal'] = 'BUY';
        elseif ($net_tf_weighted_conf < -0.0001) $tf_analysis_results_holder['trend_signal'] = 'SELL';

        if ($tf_max_possible_weighted_conf_sum_this_tf > 0) {
            $tf_analysis_results_holder['confidence'] = round($net_tf_weighted_conf / $tf_max_possible_weighted_conf_sum_this_tf, 3);
        } else {
            $tf_analysis_results_holder['confidence'] = 0.0;
        }
        $tf_analysis_results_holder['confidence'] = min(1.0, max(-1.0, $tf_analysis_results_holder['confidence'])); // Confidence is now signed for TF

        $symbol_analysis_result['debug_strategy_results_by_tf'][$tf_key] = $tf_analysis_results_holder;
        $all_tf_signals_for_confluence[$tf_key] = ['signal' => $tf_analysis_results_holder['trend_signal'], 'confluence_matches' => $tf_analysis_results_holder['confluence_matches']];


        // Adjust overall score based on TF signal and its *absolute* confidence * TF weight
        if ($tf_analysis_results_holder['trend_signal'] === 'BUY') {
            $overall_weighted_buy_score_agg += abs($tf_analysis_results_holder['confidence']) * $current_tf_weight;
        } elseif ($tf_analysis_results_holder['trend_signal'] === 'SELL') {
            $overall_weighted_sell_score_agg += abs($tf_analysis_results_holder['confidence']) * $current_tf_weight;
        }
    }

    // Overall Confluence (simple count of TFs agreeing with dominant signal)
    $dominant_buy_tfs = 0; $dominant_sell_tfs = 0; $confluence_triggered_on_overall = false;
    foreach($all_tf_signals_for_confluence as $tf_data_conf) {
        if ($tf_data_conf['signal'] === 'BUY' && $tf_data_conf['confluence_matches'] >= ($confluence_settings['min_strategies_match'] ?? 3)) $dominant_buy_tfs++;
        if ($tf_data_conf['signal'] === 'SELL' && $tf_data_conf['confluence_matches'] >= ($confluence_settings['min_strategies_match'] ?? 3)) $dominant_sell_tfs++;
    }
    if ($dominant_buy_tfs > $dominant_sell_tfs && $dominant_buy_tfs >= (count($timeframes_config) > 1 ? 2 : 1) ) { // Example: at least 2 TFs show strong buy confluence
        $symbol_analysis_result['extra_factors']['confluence_count'] = $dominant_buy_tfs;
        $confluence_triggered_on_overall = true;
    } elseif ($dominant_sell_tfs > $dominant_buy_tfs && $dominant_sell_tfs >= (count($timeframes_config) > 1 ? 2 : 1) ) {
        $symbol_analysis_result['extra_factors']['confluence_count'] = $dominant_sell_tfs;
        $confluence_triggered_on_overall = true;
    }


    $net_overall_final_score_agg = $overall_weighted_buy_score_agg - $overall_weighted_sell_score_agg;
    $max_possible_final_score_denominator = $total_tf_weights_sum_for_norm > 0 ? $total_tf_weights_sum_for_norm : 1.0;

    if ($net_overall_final_score_agg > (0.01 * $max_possible_final_score_denominator)) {
        $symbol_analysis_result['signal'] = 'BUY';
        $symbol_analysis_result['confidence'] = round($overall_weighted_buy_score_agg / $max_possible_final_score_denominator, 3);
    } elseif ($net_overall_final_score_agg < (-0.01 * $max_possible_final_score_denominator)) {
        $symbol_analysis_result['signal'] = 'SELL';
        $symbol_analysis_result['confidence'] = round($overall_weighted_sell_score_agg / $max_possible_final_score_denominator, 3);
    } else {
        $symbol_analysis_result['signal'] = 'NEUTRAL';
        // For NEUTRAL, confidence is how close it is to 0, inverse of strongest directional pull
        $strongest_directional_abs_score_normalized = max($overall_weighted_buy_score_agg, $overall_weighted_sell_score_agg) / $max_possible_final_score_denominator;
        $symbol_analysis_result['confidence'] = round(max(0.0, 1.0 - $strongest_directional_abs_score_normalized), 3);
    }
    $symbol_analysis_result['confidence'] = min(1.0, max(0.0, (float)$symbol_analysis_result['confidence']));

    // Boost confidence if overall confluence matches signal direction
    if ($confluence_triggered_on_overall &&
        (($symbol_analysis_result['signal'] === 'BUY' && $dominant_buy_tfs > $dominant_sell_tfs) ||
            ($symbol_analysis_result['signal'] === 'SELL' && $dominant_sell_tfs > $dominant_buy_tfs))) {
        $symbol_analysis_result['confidence'] = min(1.0, $symbol_analysis_result['confidence'] + ($config['confluence_settings']['confluence_overall_boost'] ?? 0.15) );
        $symbol_analysis_result['strategies_triggered_details'][] = "Overall Confluence: {$symbol_analysis_result['extra_factors']['confluence_count']} TFs confirm {$symbol_analysis_result['signal']}.";
    }


    $summary_parts = [];
    $summary_parts[] = "{$symbol} signal: {$symbol_analysis_result['signal']} (Confidence: " . number_format($symbol_analysis_result['confidence']*100, 0) . "%).";
    $ef_summary = []; foreach($symbol_analysis_result['extra_factors'] as $efk => $efv) { if ($efv && $efv !== 'none' && $efv !== false && $efv !== 0) { $ef_summary[] = (is_bool($efv) ? $efk : "{$efk}: {$efv}"); }}
    if(!empty($ef_summary)) $summary_parts[] = "Key factors: " . implode(', ', $ef_summary) . ".";

    $unique_triggered_details = array_unique($symbol_analysis_result['strategies_triggered_details']);
    if (!empty($unique_triggered_details)) { $summary_parts[] = "Details: " . implode('; ', array_slice($unique_triggered_details,0,3));} // Limit summary length
    elseif ($symbol_analysis_result['signal'] !== 'ERROR') { $summary_parts[] = "No strategies strongly indicated a direction."; }
    $symbol_analysis_result['summary'] = implode(" ", $summary_parts);

    if ($symbol_analysis_result['confidence'] >= ($config['min_confidence_for_strong_signal'] ?? 0.6) && ($symbol_analysis_result['signal'] === 'BUY' || $symbol_analysis_result['signal'] === 'SELL')) {
        log_message("STRONG SIGNAL: {$symbol_analysis_result['summary']}", 'SIGNAL');
    }
    return $symbol_analysis_result;
}

// --- Main execution flow ---
log_message("run_analysis.php started" . ($is_manual_run ? " (manual HTTP run)" : ""), 'INFO');
$overall_market_analysis = [];
$symbols_to_analyze = array_keys($config['symbols'] ?? []);
$current_run_time = time();

if (empty($symbols_to_analyze)) { log_message("No symbols configured.", 'ERROR'); if ($is_manual_run) { if(!headers_sent()) header('Content-Type: application/json'); echo json_encode(['success'=>false, 'message'=>'No symbols configured.', 'logs'=>$collected_http_logs]); } exit(1); }
$results_dir = $config['file_paths']['results_dir'] ?? (__DIR__ . '/../results');
if (!is_dir($results_dir)) { if(!@mkdir($results_dir, 0775, true) && !is_dir($results_dir)) { log_message("Failed to create results dir: {$results_dir}", 'ERROR'); if ($is_manual_run) { if(!headers_sent()) header('Content-Type: application/json', true, 500); echo json_encode(['success'=>false, 'message'=>"Failed to create results dir", 'logs'=>$collected_http_logs]); } exit(1); } }

$strategies_base_dir = $config['file_paths']['strategies_dir'] ?? (__DIR__ . '/../strategies');
foreach (array_keys($config['active_strategies'] ?? []) as $strategy_id_to_load) {
    // Function name is defined in config, so we just need to ensure file exists
    $strategy_file_to_load = "{$strategies_base_dir}/{$strategy_id_to_load}.php";
    if (file_exists($strategy_file_to_load)) {
        require_once $strategy_file_to_load;
        log_message("Loaded strategy file: {$strategy_file_to_load}", "DEBUG");
    } else {
        log_message("Configured strategy file not found: {$strategy_file_to_load}. It will be skipped.", "ERROR");
    }
}

foreach ($symbols_to_analyze as $symbol_key) {
    log_message("Processing symbol: {$symbol_key}", 'INFO');
    $symbol_result_path = ($config['file_paths']['results_dir'] ?? $results_dir) . "/{$symbol_key}.json";
    $perform_full_analysis = true;
    $signal_freshness_seconds = $config['signal_freshness_seconds'] ?? 600;

    if (file_exists($symbol_result_path) && !$is_manual_run) { // Always re-analyze on manual run
        $file_mod_time = @filemtime($symbol_result_path);
        if ($file_mod_time !== false && ($current_run_time - $file_mod_time) < $signal_freshness_seconds) {
            $existing_content = @file_get_contents($symbol_result_path);
            if ($existing_content) {
                $existing_data = json_decode($existing_content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($existing_data) && isset($existing_data['timestamp_utc'])) {
                    log_message("Existing result for {$symbol_key} is fresh. Using existing data for overview.", 'INFO');
                    $overall_market_analysis[$symbol_key] = $existing_data;
                    $perform_full_analysis = false;
                } else { log_message("Could not decode existing fresh file for {$symbol_key}. Re-analyzing.", 'WARNING'); }
            } else { log_message("Could not read existing fresh file for {$symbol_key}. Re-analyzing.", 'WARNING'); }
        }
    }

    if ($perform_full_analysis) {
        $all_extended_data_for_symbol_tfs = [];
        $has_any_data_for_analysis = false;
        foreach (array_keys($config['timeframes_settings'] ?? []) as $tf_key_loop) {
            $extended_data_loop = fetch_extended_data_for_symbol_tf($symbol_key, $tf_key_loop);
            if ($extended_data_loop && !empty($extended_data_loop['klines'])) {
                $all_extended_data_for_symbol_tfs[$tf_key_loop] = $extended_data_loop;
                $has_any_data_for_analysis = true;
            } else { log_message("Could not get klines in extended_data for {$symbol_key} - {$tf_key_loop}.", 'WARNING'); }
        }

        if ($has_any_data_for_analysis) {
            $analysis_result_for_symbol = analyze_symbol_strategies($symbol_key, $all_extended_data_for_symbol_tfs);
            $overall_market_analysis[$symbol_key] = $analysis_result_for_symbol;
            if (file_put_contents($symbol_result_path, json_encode($analysis_result_for_symbol, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
                log_message("Failed to save result for {$symbol_key} to {$symbol_result_path}", 'ERROR');
            } else { log_message("Result for {$symbol_key} analyzed and saved to {$symbol_result_path}", 'INFO'); }
        } else {
            log_message("No kline data for {$symbol_key} across all TFs. Saving error entry.", 'ERROR');
            $error_entry = [ "symbol" => $symbol_key, "timestamp_utc" => gmdate("Y-m-d H:i:s"), "signal" => "ERROR", "confidence" => 0.0, "summary" => "Failed to fetch any kline data for analysis.", "error" => "No kline data for any configured timeframe.", "debug_strategy_results_by_tf" => [], "strategies_triggered_details" => [], "extra_factors" => ['divergence'=>'none','volume_support'=>false,'pattern_confirmed'=>false,'liquidity_zone'=>'none', 'confluence_count' => 0]];
            $overall_market_analysis[$symbol_key] = $error_entry;
            @file_put_contents($symbol_result_path, json_encode($error_entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }
    if(count($symbols_to_analyze) > 1 && php_sapi_name() === 'cli') { if(function_exists('sleep')) sleep(1); }
}

$market_overview_path = $config['file_paths']['market_overview_file'] ?? ($results_dir . '/market_overview.json');
if (file_put_contents($market_overview_path, json_encode($overall_market_analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
    log_message("Failed to save market overview to {$market_overview_path}", 'ERROR');
} else {
    log_message("Market overview saved to {$market_overview_path}", 'INFO');
}

log_message("run_analysis.php finished.", 'INFO');

if ($is_manual_run) {
    if(!headers_sent()) { header('Content-Type: application/json'); }
    echo json_encode([
        'success' => true, 'message' => 'Manual analysis run completed.',
        'logs' => (($config['debug_mode'] ?? false) ? $collected_http_logs : ['Debug logging for HTTP response is off. Check file logs.']),
        'market_overview' => $overall_market_analysis
    ]);
}
exit(0);