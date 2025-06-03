<?php
// File: /strategies/strategy_bollinger_bands_bounce.php

function analyze_strategy_bollinger_bands_bounce(array $klines, array $params): array {
    $result = [
        'name' => 'Bollinger Bands Bounce',
        'triggered' => false,
        'signal' => 'neutral',
        'details' => 'Not enough data or Bollinger Bands not calculated.',
        'confidence_factor' => 0.0,
        'pattern_confirmed' => false,
        'volume_support' => false,
        'divergence' => 'none',
    ];

    $bb_period = (int)($params['bollinger_period'] ?? 20);
    $bb_std_dev = (float)($params['bollinger_std_dev'] ?? 2.0);
    $base_confidence = (float)($params['base_confidence_from_config'] ?? 0.20);

    if (count($klines) < $bb_period) {
        $result['details'] = "Insufficient data for Bollinger Bands (P:{$bb_period}, SD:{$bb_std_dev}). Need {$bb_period}.";
        return $result;
    }

    $close_prices = array_column($klines, 'close');
     if (empty($close_prices) || count($close_prices) !== count($klines)) {
        $result['details'] = "Failed to extract close prices for BB."; return $result;
    }
    foreach($close_prices as $idx_cp => $cp) { if(!is_numeric($cp)) { $result['details'] = "Non-numeric close price at kline index {$idx_cp} for BB."; return $result; }}


    $bb_data = calculate_bollinger_bands($close_prices, $bb_period, $bb_std_dev);

    if ($bb_data === false || !is_array($bb_data) || 
        !isset($bb_data['upper'], $bb_data['lower'], $bb_data['middle']) ||
        empty($bb_data['upper']) || empty($bb_data['lower'])) {
        $result['details'] = "Error calculating Bollinger Bands data.";
        return $result;
    }

    $idx = count($klines) - 1; 
    $last_candle = $klines[$idx];

    if (!isset($bb_data['upper'][$idx], $bb_data['lower'][$idx], $bb_data['middle'][$idx],
               $last_candle['low'], $last_candle['high'], $last_candle['close']) ||
        !is_numeric($bb_data['upper'][$idx]) || !is_numeric($bb_data['lower'][$idx]) ||
        !is_numeric($last_candle['low']) || !is_numeric($last_candle['high']) || !is_numeric($last_candle['close'])) {
        $result['details'] = "Bollinger Bands values or last candle data are missing/invalid for the last point.";
        return $result;
    }

    $upper_band_val = (float)$bb_data['upper'][$idx];
    $lower_band_val = (float)$bb_data['lower'][$idx];
    $middle_band_val = (float)$bb_data['middle'][$idx];

    $lc_low = (float)$last_candle['low']; $lc_high = (float)$last_candle['high']; $lc_close = (float)$last_candle['close'];
    
    if ($lc_low <= $lower_band_val && $lc_close > $lower_band_val) {
        $result['triggered'] = true;
        $result['signal'] = 'buy';
        $result['details'] = sprintf(
            "Bounce from Bollinger Lower Band (%.4f). Last Low: %.4f, Close: %.4f.",
            $lower_band_val, $lc_low, $lc_close
        );
        $result['confidence_factor'] = $base_confidence;
        $result['pattern_confirmed'] = true;
    } 
    elseif ($lc_high >= $upper_band_val && $lc_close < $upper_band_val) {
        $result['triggered'] = true;
        $result['signal'] = 'sell';
        $result['details'] = sprintf(
            "Rejection from Bollinger Upper Band (%.4f). Last High: %.4f, Close: %.4f.",
            $upper_band_val, $lc_high, $lc_close
        );
        $result['confidence_factor'] = $base_confidence;
        $result['pattern_confirmed'] = true;
    } else {
        $result['details'] = sprintf(
            "No clear Bollinger Bands bounce. Price C:%.4f. Bands L:%.4f, M:%.4f, U:%.4f.",
            $lc_close, $lower_band_val, $middle_band_val, $upper_band_val
        );
    }
    return $result;
}