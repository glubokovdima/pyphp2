<?php
mb_internal_encoding("UTF-8");

/**
 * 🔧 Общие параметры индикаторов (по умолчанию)
 */
$default_symbol_params = [
    'rsi_period' => 14,
    'ema_short' => 12,
    'ema_long' => 26,
    'macd_signal_period' => 9,
    'volume_sma_period' => 20,          // Existing, used by new strategies
    'volume_spike_factor' => 2.0,
    'fibo_pivot_lookback' => 3,
    'sup_res_pivot_lookback' => 7,      // Existing, used by Pin Bar
    'atr_period' => 14,                 // Existing, used by ADX
    'supertrend_multiplier' => 3.0,
    'keltner_ema_period' => 20,
    'keltner_atr_multiplier' => 2.0,
    'bollinger_period' => 20,
    'bollinger_std_dev' => 2.0,
    'macd_divergence_period' => 30,
    'breakout_retest_proximity_percent' => 0.15,
    'breakout_min_candles_for_level' => 20,
    'breakout_retest_window' => 5,
    'oi_surge_threshold_percent' => 10.0,
    'oi_candles_to_check' => 3,
    'cvd_divergence_period' => 20,
    'hunt_wick_body_ratio' => 2.0,
    'hunt_pierce_lookback' => 5,

    // New parameters for added strategies
    'stoch_rsi_stoch_period' => 14,     // For StochRSI
    'stoch_rsi_k_period' => 3,          // For StochRSI %K smoothing
    'stoch_rsi_d_period' => 3,          // For StochRSI %D smoothing
    'stoch_rsi_oversold' => 20,         // For StochRSI
    'stoch_rsi_overbought' => 80,        // For StochRSI
    'pin_bar_wick_body_ratio' => 2.0,   // For Pin Bar Hunt
    'pin_bar_body_range_ratio' => 0.33, // For Pin Bar Hunt (body < 1/3 of range)
    'pin_bar_level_proximity_percent' => 0.2, // For Pin Bar Hunt (proximity to S/R)
    'oi_compression_candles' => 5,      // For OI+Vol Compression
    'volume_compression_factor_vs_sma' => 0.8, // For OI+Vol Compression
    'oi_stability_threshold_percent' => 5.0, // For OI+Vol Compression
    'breakout_volume_factor_vs_sma' => 1.5, // For OI+Vol Compression Breakout
    'adx_period' => 14,                 // For ADX+DI strategy
    'adx_trend_threshold' => 20,        // For ADX+DI strategy (ADX value to consider trending)
];

/**
 * 🧬 Символы + индивидуальные параметры для некоторых
 */
$specific_params_sol = array_merge($default_symbol_params, [
    'volume_spike_factor' => 1.8, 'atr_period' => 10, 'supertrend_multiplier' => 2.5,
    'sup_res_pivot_lookback' => 5,
    'adx_period' => 10, 'adx_trend_threshold' => 22, // Example override for SOL
]);

$specific_params_meme = array_merge($default_symbol_params, [
    'ema_short' => 9, 'ema_long' => 21, 'volume_spike_factor' => 2.5,
    'fibo_pivot_lookback' => 2, 'hunt_wick_body_ratio' => 2.5,
    'stoch_rsi_k_period' => 5, 'stoch_rsi_d_period' => 5, // Faster StochRSI for memes
]);

return [
    'api_provider' => 'bybit',
    'bybit_api_settings' => [
        'base_url' => 'https://api.bybit.com',
        'klines_endpoint' => '/v5/market/kline',
        'oi_endpoint' => '/v5/market/open-interest',
        'recent_trades_endpoint' => '/v5/market/recent-trade',
        'funding_rate_endpoint' => '/v5/market/funding-rate', // Added for potential future strategy
    ],

    'symbols' => [
        'BTCUSDT' => $default_symbol_params, 'ETHUSDT' => $default_symbol_params,
        'SOLUSDT' => $specific_params_sol,   'BNBUSDT' => $default_symbol_params,
        'XRPUSDT' => $default_symbol_params, 'ADAUSDT' => $default_symbol_params,
        'DOGEUSDT' => $specific_params_meme, 'LINKUSDT' => $default_symbol_params,
        'AVAXUSDT' => $default_symbol_params, 'DOTUSDT' => $default_symbol_params,
        'TRXUSDT' => $default_symbol_params, 'ATOMUSDT' => $default_symbol_params,
        'NEARUSDT' => $default_symbol_params, 'UNIUSDT' => $default_symbol_params,
        'LTCUSDT' => $default_symbol_params,  'BCHUSDT' => $default_symbol_params,
        'AAVEUSDT' => $default_symbol_params, 'APTUSDT' => $default_symbol_params,
        'ARBUSDT' => $default_symbol_params,  'OPUSDT' => $default_symbol_params,
        'SUIUSDT' => $default_symbol_params,  'INJUSDT' => $default_symbol_params,
        'ICPUSDT' => $default_symbol_params,  '1000PEPEUSDT' => $specific_params_meme,
        'WIFUSDT' => $specific_params_meme,
    ],

    'klines_limit_per_tf' => 200, // Consider increasing if ADX needs more (e.g., 250-300)

    'timeframes_settings' => [
        '15m' => ['weight' => 0.25, 'label' => '15 минут'],
        '1h' => ['weight' => 0.35, 'label' => '1 час'],
        '4h' => ['weight' => 0.40, 'label' => '4 часа'],
    ],

    'active_strategies' => [
        'strategy_ema_trend' => ['function_name' => 'analyze_strategy_ema_trend', 'base_confidence' => 0.6],
        'strategy_rsi_zone' => ['function_name' => 'analyze_strategy_rsi_zone', 'base_confidence' => 0.5, 'params' => ['rsi_oversold' => 30, 'rsi_overbought' => 70]],
        'strategy_price_action' => ['function_name' => 'analyze_strategy_price_action', 'base_confidence' => 0.6],
        'strategy_macd_cross_divergence' => ['function_name' => 'analyze_strategy_macd_cross_divergence', 'base_confidence' => 0.55],
        'strategy_volume_spike' => ['function_name' => 'analyze_strategy_volume_spike', 'base_confidence' => 0.4, 'params' => ['candles_to_check' => 2]],
        'strategy_fibo_pullback' => ['function_name' => 'analyze_strategy_fibo_pullback', 'base_confidence' => 0.55, 'params' => ['fibo_levels_of_interest' => [38.2, 50.0, 61.8]]],
        'strategy_sup_res_bounce' => ['function_name' => 'analyze_strategy_sup_res_bounce', 'base_confidence' => 0.7],
        'strategy_supertrend' => ['function_name' => 'analyze_strategy_supertrend', 'base_confidence' => 0.65],
        'strategy_keltner_channel_bounce' => ['function_name' => 'analyze_strategy_keltner_channel_bounce', 'base_confidence' => 0.45],
        'strategy_bollinger_bands_bounce' => ['function_name' => 'analyze_strategy_bollinger_bands_bounce', 'base_confidence' => 0.45],
        'strategy_breakout_retest' => ['function_name' => 'analyze_strategy_breakout_retest', 'base_confidence' => 0.75],
        'strategy_open_interest_surge' => ['function_name' => 'analyze_strategy_open_interest_surge', 'base_confidence' => 0.3],
        'strategy_cvd_volume_divergence' => ['function_name' => 'analyze_strategy_cvd_volume_divergence', 'base_confidence' => 0.5], // Still placeholder logic
        'strategy_smart_money_hunt' => ['function_name' => 'analyze_strategy_smart_money_hunt', 'base_confidence' => 0.6],

        // New strategies
        'strategy_engulfing_volume' => ['function_name' => 'analyze_strategy_engulfing_volume', 'base_confidence' => 0.65],
        'strategy_ema_crossover_volume' => ['function_name' => 'analyze_strategy_ema_crossover_volume', 'base_confidence' => 0.65],
        'strategy_stoch_rsi_cross' => ['function_name' => 'analyze_strategy_stoch_rsi_cross', 'base_confidence' => 0.5],
        'strategy_pin_bar_hunt' => ['function_name' => 'analyze_strategy_pin_bar_hunt', 'base_confidence' => 0.7],
        'strategy_oi_volume_compression' => ['function_name' => 'analyze_strategy_oi_volume_compression', 'base_confidence' => 0.6],
        'strategy_adx_di' => ['function_name' => 'analyze_strategy_adx_di', 'base_confidence' => 0.55],
    ],

    'strategy_weights' => [
        'strategy_ema_trend' => 0.7,
        'strategy_rsi_zone' => 0.5,
        'strategy_price_action' => 0.6,
        'strategy_macd_cross_divergence' => 0.75,
        'strategy_volume_spike' => 0.4,
        'strategy_fibo_pullback' => 0.6,
        'strategy_sup_res_bounce' => 0.8,
        'strategy_supertrend' => 0.65,
        'strategy_keltner_channel_bounce' => 0.4,
        'strategy_bollinger_bands_bounce' => 0.4,
        'strategy_breakout_retest' => 0.85,
        'strategy_open_interest_surge' => 0.35,
        'strategy_cvd_volume_divergence' => 0.55,
        'strategy_smart_money_hunt' => 0.65,

        // New strategy weights (adjust as needed after testing)
        'strategy_engulfing_volume' => 0.75,       // High confidence if volume confirmed
        'strategy_ema_crossover_volume' => 0.75,  // High confidence if volume confirmed
        'strategy_stoch_rsi_cross' => 0.6,
        'strategy_pin_bar_hunt' => 0.8,           // Potentially strong reversal
        'strategy_oi_volume_compression' => 0.7,  // Good predictor of impulse
        'strategy_adx_di' => 0.65,                // Good for trend confirmation
    ],

    // Parameters for Confluence Meta-Strategy (will be used in run_analysis.php)
    'confluence_settings' => [
        'enabled' => true,
        'min_strategies_match' => 3, // Minimum number of strategies for a confluence signal
        // 'confluence_strategy_weight_boost' => 0.2, // How much to boost overall confidence if confluence is met (0.0 to 1.0)
        // 'participating_strategies_for_confluence' => ['strategy_ema_trend', 'strategy_sup_res_bounce', ...], // Optional: list specific strategies
    ],

    'file_paths' => [
        'base_dir' => __DIR__,
        'results_dir' => __DIR__ . '/results',
        'logs_dir' => __DIR__ . '/logs',
        'strategies_dir' => __DIR__ . '/strategies',
        'scripts_dir' => __DIR__ . '/scripts',
        'klines_cache_dir' => __DIR__ . '/results/klines_cache',
        'market_overview_file' => __DIR__ . '/results/market_overview.json',
        'error_log_file' => __DIR__ . '/logs/errors.log',
        'signals_log_file' => __DIR__ . '/logs/signals.log',
        'info_log_file' => __DIR__ . '/logs/info.log',
    ],

    'cache_lifetime_seconds' => 300,
    'signal_freshness_seconds' => 600,
    'min_confidence_for_strong_signal' => 0.6,
    'debug_mode' => true,
];