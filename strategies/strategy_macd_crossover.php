<?php
// File: /strategies/strategy_macd_crossover.php

function analyze_strategy_macd_crossover(array $klines, array $params): array {
    $result = [
        'name' => 'MACD Crossover/Divergence',
        'triggered' => false,
        'signal' => 'neutral',
        'details' => 'Not enough data or MACD not calculated.',
        'confidence_factor' => 0.0,
        'pattern_confirmed' => false,
        'volume_support' => false,
        'divergence' => 'none', 
    ];

    $short_period = (int)($params['ema_short'] ?? 12); 
    $long_period = (int)($params['ema_long'] ?? 26);
    $signal_period = (int)($params['macd_signal_period'] ?? 9);
    $base_confidence = (float)($params['base_confidence_from_config'] ?? 0.30);

    $min_klines_needed = $long_period + $signal_period + 5; 
    if (count($klines) < $min_klines_needed) {
        $result['details'] = "Insufficient data for MACD ({$short_period},{$long_period},{$signal_period}). Need {$min_klines_needed}, have " . count($klines) . ".";
        return $result;
    }

    $close_prices = array_column($klines, 'close');
    if (empty($close_prices) || count($close_prices) !== count($klines)) {
        $result['details'] = "Failed to extract close prices for MACD."; return $result;
    }
    foreach($close_prices as $idx_cp => $cp) { if(!is_numeric($cp)) { $result['details'] = "Non-numeric close price at kline index {$idx_cp} for MACD."; return $result; }}

    $macd_data = calculate_macd($close_prices, $short_period, $long_period, $signal_period);

    if ($macd_data === false || !is_array($macd_data) || !isset($macd_data['macd'], $macd_data['signal'], $macd_data['histogram'])) {
        $result['details'] = "Error calculating MACD data."; return $result;
    }

    $macd_line = $macd_data['macd'];
    $signal_line = $macd_data['signal'];

    $current_macd_val = null; $prev_macd_val = null;
    $current_signal_val = null; $prev_signal_val = null;
    $points_found = 0;

    for ($i = count($macd_line) - 1; $i >= 0; $i--) {
        if (isset($macd_line[$i], $signal_line[$i]) && is_numeric($macd_line[$i]) && is_numeric($signal_line[$i])) {
            if ($points_found === 0) {
                $current_macd_val = (float)$macd_line[$i];
                $current_signal_val = (float)$signal_line[$i];
            } elseif ($points_found === 1) {
                $prev_macd_val = (float)$macd_line[$i];
                $prev_signal_val = (float)$signal_line[$i];
            }
            $points_found++;
            if ($points_found >= 2) break;
        }
    }

    if ($current_macd_val === null || $prev_macd_val === null || $current_signal_val === null || $prev_signal_val === null) {
        $result['details'] = "Not enough valid MACD/Signal points for crossover analysis on latest candles.";
        return $result;
    }
    
    $cmf = number_format($current_macd_val, 5); $csf = number_format($current_signal_val, 5);
    $pmf = number_format($prev_macd_val, 5); $psf = number_format($prev_signal_val, 5);

    if ($prev_macd_val < $prev_signal_val && $current_macd_val > $current_signal_val) {
        $result['triggered'] = true;
        $result['signal'] = 'buy';
        $result['details'] = "Bullish MACD Crossover: MACD ({$cmf}) crossed above Signal ({$csf}). Prev: MACD ({$pmf}) < Signal ({$psf}).";
        $result['confidence_factor'] = $base_confidence;
        $result['pattern_confirmed'] = true;
    }
    elseif ($prev_macd_val > $prev_signal_val && $current_macd_val < $current_signal_val) {
        $result['triggered'] = true;
        $result['signal'] = 'sell';
        $result['details'] = "Bearish MACD Crossover: MACD ({$cmf}) crossed below Signal ({$csf}). Prev: MACD ({$pmf}) > Signal ({$psf}).";
        $result['confidence_factor'] = $base_confidence;
        $result['pattern_confirmed'] = true;
    } else {
        $result['details'] = "No MACD crossover. Current MACD: {$cmf}, Signal: {$csf}. Prev MACD: {$pmf}, Signal: {$psf}.";
    }
    return $result;
}