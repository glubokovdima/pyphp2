<?php
// File: /strategies/strategy_ema_trend.php

function analyze_strategy_ema_trend(array $klines, array $params): array {
    $result = [
        'name' => 'EMA Trend',
        'triggered' => false,
        'signal' => 'neutral',
        'details' => 'Not enough data or EMAs not calculated.',
        'confidence_factor' => 0.0,
        'pattern_confirmed' => false,
        'volume_support' => false,
        'divergence' => 'none',
    ];

    $ema_short_period = (int)($params['ema_short'] ?? 12);
    $ema_long_period = (int)($params['ema_long'] ?? 26);
    $base_confidence = (float)($params['base_confidence_from_config'] ?? 0.3);

    if (count($klines) < $ema_long_period) {
        $result['details'] = "Insufficient data for EMA ({$ema_long_period}). Need {$ema_long_period}, have " . count($klines) . ".";
        return $result;
    }

    $close_prices = array_column($klines, 'close');
    if (empty($close_prices) || count($close_prices) !== count($klines)) {
        $result['details'] = "Failed to extract close prices.";
        return $result;
    }
    foreach($close_prices as $idx_cp => $cp) {
        if(!is_numeric($cp)) {
            $result['details'] = "Non-numeric close price at kline index {$idx_cp}.";
            return $result;
        }
    }


    $ema_short_values = calculate_ema($close_prices, $ema_short_period);
    $ema_long_values = calculate_ema($close_prices, $ema_long_period);

    if ($ema_short_values === false || $ema_long_values === false) {
        $result['details'] = "Error calculating EMAs. ShortP:{$ema_short_period}, LongP:{$ema_long_period}.";
        return $result;
    }

    $idx = count($klines) - 1; // Last kline index
    $last_kline = $klines[$idx];

    if (!isset($last_kline['close'], $ema_short_values[$idx], $ema_long_values[$idx]) ||
        !is_numeric($last_kline['close']) || !is_numeric($ema_short_values[$idx]) || !is_numeric($ema_long_values[$idx])) {
        $result['details'] = "Calculated EMA values for the last candle are missing or invalid.";
        return $result;
    }

    $last_close = (float)$last_kline['close'];
    $current_ema_short = (float)$ema_short_values[$idx];
    $current_ema_long = (float)$ema_long_values[$idx];

    $price_vs_short = $last_close > $current_ema_short ? 'above' : ($last_close < $current_ema_short ? 'below' : 'on');
    $short_vs_long = $current_ema_short > $current_ema_long ? 'above' : ($current_ema_short < $current_ema_long ? 'below' : 'on');

    if ($price_vs_short === 'above' && $short_vs_long === 'above') {
        $result['triggered'] = true;
        $result['signal'] = 'buy';
        $result['details'] = sprintf(
            "Price (%.4f) > EMA%d (%.4f) AND EMA%d (%.4f) > EMA%d (%.4f). Uptrend.",
            $last_close, $ema_short_period, $current_ema_short,
            $ema_short_period, $current_ema_short, $ema_long_period, $current_ema_long
        );
        $result['confidence_factor'] = $base_confidence;
    } elseif ($price_vs_short === 'below' && $short_vs_long === 'below') {
        $result['triggered'] = true;
        $result['signal'] = 'sell';
        $result['details'] = sprintf(
            "Price (%.4f) < EMA%d (%.4f) AND EMA%d (%.4f) < EMA%d (%.4f). Downtrend.",
            $last_close, $ema_short_period, $current_ema_short,
            $ema_short_period, $current_ema_short, $ema_long_period, $current_ema_long
        );
        $result['confidence_factor'] = $base_confidence;
    } else {
        $result['details'] = sprintf(
            "No clear EMA trend. Price: %.4f (vs EMA%d %.4f -> %s). EMA%d %.4f (vs EMA%d %.4f -> %s).",
            $last_close, $ema_short_period, $current_ema_short, $price_vs_short,
            $ema_short_period, $current_ema_short, $ema_long_period, $current_ema_long, $short_vs_long
        );
    }
    return $result;
}