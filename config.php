<?php
mb_internal_encoding("UTF-8");

$default_symbol_params = [
    'rsi_period' => 14,
    'ema_short' => 12,
    'ema_long' => 26,
    'macd_signal_period' => 9,
    'volume_sma_period' => 20,
    'volume_spike_factor' => 2.0,
    'fibo_pivot_lookback' => 3,
    'sup_res_pivot_lookback' => 5,
    'atr_period' => 14,
    'supertrend_multiplier' => 3.0,
    'keltner_ema_period' => 20,
    'keltner_atr_multiplier' => 2.0,
    'bollinger_period' => 20,
    'bollinger_std_dev' => 2.0,
];

$specific_params_sol = array_merge($default_symbol_params, [
    'volume_spike_factor' => 1.8,
    'atr_period' => 10,
    'supertrend_multiplier' => 2.5,
    'keltner_atr_multiplier' => 1.8
]);

$specific_params_meme = array_merge($default_symbol_params, [
    'ema_short' => 9,
    'ema_long' => 21,
    'volume_spike_factor' => 2.5,
    'fibo_pivot_lookback' => 2,
    'sup_res_pivot_lookback' => 4,
    'atr_period' => 10,
    'supertrend_multiplier' => 2.0,
    'keltner_atr_multiplier' => 1.5
]);

return [
    'api_provider' => 'bybit', // 'bybit' or 'binance_futures'
    'bybit_api_settings' => [
        'base_url' => 'https://api.bybit.com',
        'klines_endpoint' => '/v5/market/kline',
        'oi_endpoint' => '/v5/market/open-interest',
        // Add other endpoints as needed
    ],
    'binance_futures_api_settings' => [
        'base_url' => 'https://fapi.binance.com',
        'klines_endpoint' => '/fapi/v1/klines',
        // Add other endpoints as needed
    ],

    'symbols' => [
        'BTCUSDT'  => $default_symbol_params,
        'ETHUSDT'  => $default_symbol_params,
        'SOLUSDT'  => $specific_params_sol,
        'BNBUSDT'  => $default_symbol_params,
        'XRPUSDT'  => $default_symbol_params,
        'ADAUSDT'  => $default_symbol_params,
        'DOGEUSDT' => $specific_params_meme,
        'MATICUSDT'=> $default_symbol_params,
        'DOTUSDT'  => $default_symbol_params,
        'LINKUSDT' => $default_symbol_params,
        'AVAXUSDT' => $default_symbol_params,
        'TRXUSDT'  => $default_symbol_params,
        'ATOMUSDT' => $default_symbol_params,
        'NEARUSDT' => $default_symbol_params,
        'UNIUSDT'  => $default_symbol_params,
        'LTCUSDT'  => $default_symbol_params,
        'BCHUSDT'  => $default_symbol_params,
        'AAVEUSDT' => $default_symbol_params,
        'APTUSDT'  => $default_symbol_params,
        'ARBUSDT'  => $default_symbol_params,
        'OPUSDT'   => $default_symbol_params,
        'SUIUSDT'  => $default_symbol_params,
        'INJUSDT'  => $default_symbol_params,
        'FTMUSDT'  => $default_symbol_params,
        'ICPUSDT'  => $default_symbol_params,
        'PEPEUSDT' => $specific_params_meme, // Bybit uses PEPEUSDT, Binance may use 1000PEPEUSDT
        'WIFUSDT'  => $specific_params_meme,
    ],
    'klines_limit_per_tf' => 200,
    'default_symbol_indicator_params' => $default_symbol_params,

    'timeframes_settings' => [
        '15m' => ['weight' => 0.2, 'label' => '15 минут'],
        '1h'  => ['weight' => 0.4, 'label' => '1 час'],
        '4h'  => ['weight' => 0.4, 'label' => '4 часа'],
    ],

    'active_strategies' => [
        'strategy_ema_trend' => [
            'function_name' => 'analyze_strategy_ema_trend',
            'base_confidence' => 0.35,
        ],
        'strategy_rsi_zone' => [
            'function_name' => 'analyze_strategy_rsi_zone',
            'base_confidence' => 0.20,
            'params' => ['rsi_oversold' => 30, 'rsi_overbought' => 70]
        ],
        'strategy_price_action' => [
            'function_name' => 'analyze_strategy_price_action',
            'base_confidence' => 0.25,
        ],
        'strategy_macd_crossover'   => [
            'function_name' => 'analyze_strategy_macd_crossover',
            'base_confidence' => 0.30,
        ],
        'strategy_volume_spike'     => [
            'function_name' => 'analyze_strategy_volume_spike',
            'base_confidence' => 0.15,
            'params' => ['candles_to_check' => 1, 'spike_factor_default' => 2.0 ]
        ],
        'strategy_fibo_pullback'    => [
            'function_name' => 'analyze_strategy_fibo_pullback',
            'base_confidence' => 0.30,
            'params' => ['fibo_levels_of_interest' => [50.0, 61.8], 'fibo_wick_touch_allowance_percent' => 0.1]
        ],
        'strategy_sup_res_bounce' => [
            'function_name' => 'analyze_strategy_sup_res_bounce',
            'base_confidence' => 0.40,
            'params' => ['sup_res_level_proximity_percent' => 0.15, 'sup_res_candles_for_reaction' => 1]
        ],
        'strategy_supertrend' => [
            'function_name' => 'analyze_strategy_supertrend',
            'base_confidence' => 0.35,
        ],
        'strategy_keltner_channel_bounce' => [
            'function_name' => 'analyze_strategy_keltner_channel_bounce',
            'base_confidence' => 0.20,
        ],
        'strategy_bollinger_bands_bounce' => [
            'function_name' => 'analyze_strategy_bollinger_bands_bounce',
            'base_confidence' => 0.20,
        ],
    ],

    'file_paths' => [
        'base_dir' => __DIR__,
        'results_dir' => __DIR__ . '/results',
        'logs_dir' => __DIR__ . '/logs',
        'strategies_dir' => __DIR__ . '/strategies',
        'scripts_dir' => __DIR__ . '/scripts',
        'klines_cache_dir' => __DIR__ . '/results/klines_cache', // Cache inside results for simplicity or separate
        'market_overview_file' => __DIR__ . '/results/market_overview.json', // Central overview file
        'error_log_file' => __DIR__ . '/logs/errors.log',
        'signals_log_file' => __DIR__ . '/logs/signals.log',
    ],
    'cache_lifetime_seconds' => 300, // 5 minutes for klines cache
    'min_confidence_for_strong_signal' => 0.6, // For Telegram and signal log filtering
    'debug_mode' => true,
];