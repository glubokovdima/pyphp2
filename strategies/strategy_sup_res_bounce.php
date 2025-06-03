<?php
// File: /strategies/strategy_sup_res_bounce.php

function analyze_strategy_sup_res_bounce(array $klines, array $params): array {
    $result = [
        'name' => 'S/R Level Bounce',
        'triggered' => false,
        'signal' => 'neutral',
        'details' => 'Not enough data or no recent S/R levels identified.',
        'confidence_factor' => 0.0,
        'extra_factors' => [
            'divergence' => 'none', 
            'volume_support' => false,
            'pattern_confirmed' => false, 
            'liquidity_zone' => 'none',
        ]
    ];

    $pivot_lookback = (int)($params['sup_res_pivot_lookback'] ?? 5);
    $proximity_percent_of_price = (float)($params['sup_res_level_proximity_percent'] ?? 0.15); 
    $base_confidence = (float)($params['base_confidence_from_config'] ?? 0.40);
    $num_recent_pivots_to_consider = 3;

    if (count($klines) < (2 * $pivot_lookback + 1) + 1) {
        $result['details'] = "Insufficient data for S/R analysis (Pivots lookback: {$pivot_lookback}).";
        return $result;
    }

    $pivots = find_pivot_points($klines, $pivot_lookback, true);
    
    $recent_high_pivots = [];
    if(!empty($pivots['highs'])){
        uksort($pivots['highs'], fn($a, $b) => $b - $a); 
        $recent_high_pivots = array_slice($pivots['highs'], 0, $num_recent_pivots_to_consider, true);
        uksort($recent_high_pivots, fn($a, $b) => $a - $b); 
    }

    $recent_low_pivots = [];
     if(!empty($pivots['lows'])){
        uksort($pivots['lows'], fn($a, $b) => $b - $a); 
        $recent_low_pivots = array_slice($pivots['lows'], 0, $num_recent_pivots_to_consider, true);
        uksort($recent_low_pivots, fn($a, $b) => $a - $b); 
    }

    if (empty($recent_high_pivots) && empty($recent_low_pivots)) {
        $result['details'] = "No recent pivot points found to define S/R levels (Lookback: {$pivot_lookback}).";
        return $result;
    }

    $last_candle = $klines[count($klines) - 1]; 
    if(!isset($last_candle['low'], $last_candle['high'], $last_candle['close']) || 
       !is_numeric($last_candle['low']) || !is_numeric($last_candle['high']) || !is_numeric($last_candle['close'])) {
        $result['details'] = "Invalid data for the last candle."; return $result;
    }
    $lc_low = (float)$last_candle['low']; $lc_high = (float)$last_candle['high']; $lc_close = (float)$last_candle['close'];

    foreach ($recent_high_pivots as $ts => $resistance_price) {
        if(!is_numeric($resistance_price)) continue;
        $resistance_price_f = (float)$resistance_price;
        $allowance = ($resistance_price_f * $proximity_percent_of_price) / 100.0;
        
        if ($lc_high >= ($resistance_price_f - $allowance) && $lc_close < ($resistance_price_f + $allowance * 0.2) ) { 
             if ($lc_high <= ($resistance_price_f + $allowance*2) && $lc_close < $resistance_price_f) { 
                $result['triggered'] = true;
                $result['signal'] = 'sell';
                $result['details'] = sprintf(
                    "Potential bounce from resistance ~%.4f (pivot at %s). Last H:%.4f, C:%.4f.",
                    $resistance_price_f, date('Y-m-d H:i', $ts/1000), $lc_high, $lc_close
                );
                $result['confidence_factor'] = $base_confidence;
                $result['extra_factors']['pattern_confirmed'] = true;
                return $result; 
             }
        }
    }

    foreach ($recent_low_pivots as $ts => $support_price) {
         if(!is_numeric($support_price)) continue;
        $support_price_f = (float)$support_price;
        $allowance = ($support_price_f * $proximity_percent_of_price) / 100.0;

        if ($lc_low <= ($support_price_f + $allowance) && $lc_close > ($support_price_f - $allowance*0.2) ) {
            if ($lc_low >= ($support_price_f - $allowance*2) && $lc_close > $support_price_f) { 
                $result['triggered'] = true;
                $result['signal'] = 'buy';
                $result['details'] = sprintf(
                    "Potential bounce from support ~%.4f (pivot at %s). Last L:%.4f, C:%.4f.",
                    $support_price_f, date('Y-m-d H:i', $ts/1000), $lc_low, $lc_close
                );
                $result['confidence_factor'] = $base_confidence;
                $result['extra_factors']['pattern_confirmed'] = true;
                return $result; 
            }
        }
    }
    
    $result['details'] = "No clear S/R bounce on the last candle. Recent Highs: " . count($recent_high_pivots) . ", Lows: " . count($recent_low_pivots) . ". Prox: {$proximity_percent_of_price}%.";
    return $result;
}