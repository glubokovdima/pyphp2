<?php
// File: /strategies/strategy_price_action.php

function analyze_strategy_price_action(array $klines, array $params): array {
    $result = [
        'name' => 'Price Action (Engulfing/Hammer/Doji)',
        'triggered' => false,
        'signal' => 'neutral',
        'details' => 'Not enough data for PA.',
        'confidence_factor' => 0.0,
        'extra_factors' => [
            'divergence' => 'none',
            'volume_support' => false,    
            'pattern_confirmed' => false, 
            'liquidity_zone' => 'none',
        ]
    ];
    $base_confidence = (float)($params['base_confidence_from_config'] ?? 0.25);

    $num_klines = count($klines);
    if ($num_klines < 2) { 
        return $result;
    }

    $last_candle = $klines[$num_klines - 1];
    $prev_candle = ($num_klines >= 2) ? $klines[$num_klines - 2] : null;

    $required_keys = ['open', 'close', 'high', 'low'];
    foreach ([$last_candle, $prev_candle] as $candle_check) {
        if ($candle_check === null) continue;
        foreach ($required_keys as $key) {
            if (!isset($candle_check[$key]) || !is_numeric($candle_check[$key])) {
                $result['details'] = "Invalid candle data for Price Action analysis.";
                return $result;
            }
        }
    }
    
    $lc_open = (float)$last_candle['open']; $lc_close = (float)$last_candle['close'];
    $lc_high = (float)$last_candle['high']; $lc_low = (float)$last_candle['low'];
    $lc_body = abs($lc_open - $lc_close);
    $lc_range = $lc_high - $lc_low;
    $lc_upper_wick = $lc_high - max($lc_open, $lc_close);
    $lc_lower_wick = min($lc_open, $lc_close) - $lc_low;

    if ($prev_candle) {
        $pc_open = (float)$prev_candle['open']; $pc_close = (float)$prev_candle['close'];
        $pc_body = abs($pc_open - $pc_close);

        if ($pc_close < $pc_open && $lc_close > $lc_open && 
            $lc_close > $pc_open && $lc_open < $pc_close && $lc_body > $pc_body * 0.9 && $pc_body > 0.000001) { 
            $result['triggered'] = true; $result['signal'] = 'buy';
            $result['details'] = "Bullish Engulfing pattern detected.";
            $result['confidence_factor'] = $base_confidence;
            $result['extra_factors']['pattern_confirmed'] = true;
            return $result; 
        }
        if ($pc_close > $pc_open && $lc_close < $lc_open && 
            $lc_close < $pc_open && $lc_open > $pc_close && $lc_body > $pc_body * 0.9 && $pc_body > 0.000001) {
            $result['triggered'] = true; $result['signal'] = 'sell';
            $result['details'] = "Bearish Engulfing pattern detected.";
            $result['confidence_factor'] = $base_confidence;
            $result['extra_factors']['pattern_confirmed'] = true;
            return $result; 
        }
    }

    if ($lc_body > 0.00001 && $lc_range > 0.00001) { 
        if ($lc_lower_wick > $lc_body * 2 && $lc_upper_wick < $lc_body * 0.7) {
            $result['triggered'] = true; $result['signal'] = 'buy'; 
            $result['details'] = "Hammer pattern detected (potential bullish).";
            $result['confidence_factor'] = $base_confidence * 0.8; 
            $result['extra_factors']['pattern_confirmed'] = true;
            return $result;
        }
    }
    
    if ($lc_range > 0.00001 && $lc_body / $lc_range < 0.1) { 
        $result['triggered'] = true; 
        $result['signal'] = 'neutral';
        $result['details'] = "Doji pattern detected (indecision).";
        $result['confidence_factor'] = $base_confidence * 0.3; 
        $result['extra_factors']['pattern_confirmed'] = true;
        return $result;
    }

    if (!$result['triggered']) {
        $result['details'] = "No distinct Price Action patterns (Engulfing, Hammer, Doji) found on the last candle(s).";
    }

    return $result;
}