_symbol_tf($symbol_key, $tf_key_loop);
        if ($extended_data_<?php
ini_set('display_errors', 0);
error_reporting(E_ALL); loop && !empty($extended_data_loop['klines'])) {
            $all_extended_data_for_symbol_tfs[$tf_key_loop] = $extended_data_loop;
            $
header('Content-Type: application/json');

$config_path = __DIR__ . '/config.php';
has_any_data_for_analysis = true;
        } else { log_message("Could not fetchif (!file_exists($config_path)) {
    http_response_code(500);
/load klines for {$symbol_key} - {$tf_key_loop}.", 'WARNING');}
    }

    echo json_encode(['success' => false, 'message' => 'FATAL: Configuration file missing.']);
        if ($has_any_data_for_analysis) {
        $analysis_result_for_symbolexit;
}
$config = require $config_path;
ini_set('error_log', $ = analyze_symbol_strategies($symbol_key, $all_extended_data_for_symbol_tfsconfig['file_paths']['error_log_file']);

$response = ['success' => false, 'message);
        $overall_market_analysis[$symbol_key] = $analysis_result_for_symbol;' => 'Error retrieving data.', 'data' => null];

$symbol_req_raw = trim($_GET
        if (file_put_contents($symbol_result_path, json_encode($analysis_result_['symbol'] ?? '');
$symbol_req = $symbol_req_raw ? strtoupper(preg_replace("/[^for_symbol, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNa-zA-Z0-9]/", "", $symbol_req_raw)) : '';
$format_ESCAPED_SLASHES)) === false) {
            log_message("Failed to save result for {$symbolreq = strtolower(trim($_GET['format'] ?? 'json'));
$min_confidence_filter = isset_key} to {$symbol_result_path}", 'ERROR');
        } else { log_message("Result($_GET['min_confidence']) ? (float)$_GET['min_confidence'] : null;

$results_dir = for {$symbol_key} saved/updated to {$symbol_result_path}", 'INFO');}
    } else $config['file_paths']['results_dir'];
$market_overview_file = $config['file_ {
        log_message("No kline data for {$symbol_key} across all TFs. Saving error entry.", 'paths']['market_overview_file'];

try {
    if (empty($symbol_req)) { // RequestERROR');
        $error_entry = [ "symbol" => $symbol_key, "timestamp_utc" => gmdate("Y-m-d H:i:s"), "signal" => "ERROR", "confidence" => 0.0, "summary" => "Failed to fetch any kline data for analysis.", "error" => "No for all symbols (market overview)
        if (file_exists($market_overview_file)) {
            $content = @file_get_contents($market_overview_file);
            if ($content === false) {
                $response['message'] = 'Failed to read market overview file.'; http_response_code(500);
            } else {
                $data = json_decode($content, true);
                if (json kline data for any configured timeframe.", "debug_strategy_results_by_tf" => [], "strategies_triggered_details" => [], "extra_factors" => ['divergence'=>'none','volume_support'=>false,'pattern_confirmed'=>false,'liquidity_zone'=>'none']];
        $overall_market_analysis[$symbol_key_last_error() === JSON_ERROR_NONE) {
                    if ($min_confidence_filter !== null && is_array($data)) {
                        $data = array_filter($data, function($item) use ($] = $error_entry;
        @file_put_contents($symbol_result_path, json_min_confidence_filter) {
                            return isset($item['confidence']) && (float)$item['confidence']encode($error_entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNES >= $min_confidence_filter && ($item['signal'] ?? 'NEUTRAL') !== 'NEUTRAL' && ($item['CAPED_SLASHES));
    }
    if(count($symbols_to_analyze) > 1signal'] ?? 'ERROR') !== 'ERROR';
                        });
                    }
                    $response['success'] = && php_sapi_name() === 'cli') { sleep(1); }
}

$market_ true;
                    $response['message'] = 'Market overview retrieved' . ($min_confidence_filter !== null ?overview_path = $config['file_paths']['market_overview_file'];
if (file_put_contents($market_overview_path, json_encode($overall_market_analysis, JSON_PRETTY_PRINT " (filtered by min_confidence {$min_confidence_filter})" : "") . '.';
                    $response['data | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false)'] = $data;
                } else {
                    $response['message'] = 'Error decoding market overview file: ' . {
    log_message("Failed to save market overview to {$market_overview_path}", 'ERROR');
 json_last_error_msg(); http_response_code(500);
                }
            }} else { log_message("Market overview saved to {$market_overview_path}", 'INFO');}

log_message
        } else {
            $response['message'] = 'Market overview file not found. Please run analysis first.';("run_analysis.php finished.", 'INFO');

if ($is_manual_run) {
    if http_response_code(404);
        }
    } else { // Request for a single symbol(!headers_sent()) { header('Content-Type: application/json'); }
    echo json_encode([
        if (!isset($config['symbols'][$symbol_req])) {
             $response['message'] = "
        'success' => true, 'message' => 'Manual analysis run completed.',
        'logs' =>Symbol '{$symbol_req}' is not configured for analysis."; http_response_code(400);
 $config['debug_mode'] ? $collected_http_logs : ['Debug logging is off for HTTP responses.'        } else {
            $symbol_file = "{$results_dir}/{$symbol_req}.json";],
        'market_overview' => $overall_market_analysis
    ]);
}
exit(0);