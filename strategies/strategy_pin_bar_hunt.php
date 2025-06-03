<?php

function analyze_strategy_pin_bar_hunt(array $klines, array $params): array {
    $result = [
        'name' => 'Pin Bar Hunt',
        'triggered' => false,
        'signal' => 'neutral',
        'details' => 'Not enough data or no Pin Bar near S/R.',
        'confidence_factor' => 0.0,
        'extra_factors' => ['pattern_confirmed' => false, 'liquidity_zone' => 'none']
    ];

    $pin_bar_wick_body_ratio = (float)($params['pin_bar_wick_body_ratio'] ?? 2.0);
    $pin_bar_body_range_ratio = (float)($params['pin_bar_body_range_ratio'] ?? 0.33); // Body should be less than 1/3 of total range
    $sup_res_pivot_lookback = (int)($params['sup_res_pivot_lookback'] ?? 5);
    $level_proximity_percent = (float)($params['pin_bar_level_proximity_percent'] ?? 0.2);
    $base_confidence = (float)($params['base_confidence_from_config'] ?? 0.35);
    $num_klines = count($klines);

    if ($num_klines < $sup_res_pivot_lookback * 2 + 2) {
        $result['details'] = "Insufficient data. Need at least " . ($sup_res_pivot_lookback * 2 + 2) . " klines.";
        return $result;
    }

    $last_candle = $klines[$num_klines - 1];
    $pivots = find_pivot_points($klines, $sup_res_pivot_lookback);

    $lc_open = (float)$last_candle['open']; $lc_close = (float)$last_candle['close'];
    $lc_high = (float)$last_candle['high']; $lc_low = (float)$last_candle['low'];
    $lc_body = abs($lc_open - $lc_close);
    $lc_range = $lc_high - $lc_low;
    $lc_upper_wick = $lc_high - max($lc_open, $lc_close);
    $lc_lower_wick = min($lc_open, $lc_close) - $lc_low;

    if ($lc_range == 0) { $result['details'] = "Last candle has zero range."; return $result; }

    $is_pin_bar_shape = ($lc_body / $lc_range) < $pin_bar_body_range_ratio;
    $is_bullish_pin = $is_pin_bar_shape && ($lc_lower_wick > $lc_body * $pin_bar_wick_body_ratio) && ($lc_upper_wick < $lc_body);
    $is_bearish_pin = $is_pin_bar_shape && ($lc_upper_wick > $lc_body * $pin_bar_wick_body_ratio) && ($lc_lower_wick < $lc_body);

    if (!$is_bullish_pin && !$is_bearish_pin) {
        $result['details'] = "Last candle is not a clear Pin Bar shape.";
        return $result;
    }

    $level_found = false;
    $level_type = "";
    $level_price_hit = 0.0;

    if ($is_bullish_pin) {
        foreach (array_slice($pivots['lows'], -3, 3, true) as $ts => $support_price) {
            if ($ts >= $last_candle['timestamp']) continue; // Pivot must be before or at the pin bar candle
            $allowance = $support_price * ($level_proximity_percent / 100.0);
            if ($lc_low <= ($support_price + $allowance) && $lc_low >= ($support_price - $allowance)) {
                $level_found = true; $level_type = "support"; $level_price_hit = $support_price;
                break;
            }
        }
        if ($level_found) {
            $result['triggered'] = true; $result['signal'] = 'buy';
            $result['details'] = sprintf("Bullish Pin Bar (L:%.4f) rejected support ~%.4f.", $lc_low, $level_price_hit);
            $result['confidence_factor'] = $base_confidence;
            $result['extra_factors']['pattern_confirmed'] = true;
            $result['extra_factors']['liquidity_zone'] = 'below';
        }
    } elseif ($is_bearish_pin) {
        foreach (array_slice($pivots['highs'], -3, 3, true) as $ts => $resistance_price) {
            if ($ts >= $last_candle['timestamp']) continue;
            $allowance = $resistance_price * ($level_proximity_percent / 100.0);
            if ($lc_high >= ($resistance_price - $allowance) && $lc_high <= ($resistance_price + $allowance)) {
                $level_found = true; $level_type = "resistance"; $level_price_hit = $resistance_price;
                break;
            }
        }
        if ($level_found) {
            $result['triggered'] = true; $result['signal'] = 'sell';
            $result['details'] = sprintf("Bearish Pin Bar (H:%.4f) rejected resistance ~%.4f.", $lc_high, $level_price_hit);
            $result['confidence_factor'] = $base_confidence;
            $result['extra_factors']['pattern_confirmed'] = true;
            $result['extra_factors']['liquidity_zone'] = 'above';
        }
    }

    if (!$result['triggered']) {
        $result['details'] = "Pin bar shape detected, but not near recent S/R.";
        if ($is_bullish_pin) $result['details'] .= " (Bullish shape L:{$lc_low}, H:{$lc_high}, C:{$lc_close})";
        if ($is_bearish_pin) $result['details'] .= " (Bearish shape L:{$lc_low}, H:{$lc_high}, C:{$lc_close})";
    }
    return $result;
}