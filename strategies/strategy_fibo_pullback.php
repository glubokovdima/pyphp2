<?php
// File: /strategies/strategy_fibo_pullback.php

function analyze_strategy_fibo_pullback(array $klines, array $params): array {
    $result = [
        'name' => 'Fibonacci Pullback',
        'triggered' => false,
        'signal' => 'neutral',
        'details' => 'Not enough data or no valid impulse for Fibo.',
        'confidence_factor' => 0.0,
        'pattern_confirmed' => false, 
        'volume_support' => false,
        'divergence' => 'none',
    ];

    $pivot_lookback = (int)($params['fibo_pivot_lookback'] ?? 3);
    $levels_of_interest_percent = $params['fibo_levels_of_interest'] ?? [38.2, 50.0, 61.8]; 
    $touch_allowance_percent_of_range = (float)($params['fibo_wick_touch_allowance_percent'] ?? 0.1); 
    $base_confidence = (float)($params['base_confidence_from_config'] ?? 0.30);
    $min_candles_for_pivots = (2 * $pivot_lookback + 1);
    $min_candles_after_impulse = 1; 

    if (count($klines) < $min_candles_for_pivots + $min_candles_after_impulse + 5) { 
        $result['details'] = "Insufficient data. Need ~" . ($min_candles_for_pivots + $min_candles_after_impulse + 5) . " candles.";
        return $result;
    }

    $pivots = find_pivot_points($klines, $pivot_lookback, true); 
    $high_pivots = $pivots['highs']; 
    $low_pivots = $pivots['lows'];

    if (empty($high_pivots) || empty($low_pivots)) {
        $result['details'] = "Not enough high/low pivot points found with lookback {$pivot_lookback}.";
        return $result;
    }
    
    uksort($high_pivots, fn($a, $b) => $a - $b);
    uksort($low_pivots, fn($a, $b) => $a - $b);
    
    $last_high_ts_arr = !empty($high_pivots) ? array_keys($high_pivots) : [];
    $last_low_ts_arr = !empty($low_pivots) ? array_keys($low_pivots) : [];

    if(empty($last_high_ts_arr) || empty($last_low_ts_arr)) {
         $result['details'] = "Not enough distinct high/low pivots."; return $result;
    }

    $last_high_ts = end($last_high_ts_arr);
    $last_low_ts = end($last_low_ts_arr);


    $start_price = 0.0; $end_price = 0.0;
    $is_uptrend_impulse = false; $impulse_start_ts = 0; $impulse_end_ts = 0;

    if ($last_high_ts > $last_low_ts) { 
        $potential_start_lows_ts = array_filter(array_keys($low_pivots), fn($ts_val) => $ts_val < $last_high_ts);
        if (empty($potential_start_lows_ts)) { $result['details'] = "No preceding low pivot for the last high pivot."; return $result;}
        // Corrected logic: find the LOWEST pivot price (strongest start of impulse) that occurred *before* last_high_ts, but among RECENT lows.
        // Simpler: Find the most recent low pivot *before* the last high pivot.
        $impulse_start_ts = max($potential_start_lows_ts); 
        
        if(!isset($low_pivots[$impulse_start_ts], $high_pivots[$last_high_ts])) { $result['details'] = "Data error for uptrend impulse pivots."; return $result;}
        $start_price = (float)$low_pivots[$impulse_start_ts];
        $end_price = (float)$high_pivots[$last_high_ts];
        $impulse_end_ts = $last_high_ts;
        $is_uptrend_impulse = true;
    } else { 
        $potential_start_highs_ts = array_filter(array_keys($high_pivots), fn($ts_val) => $ts_val < $last_low_ts);
        if (empty($potential_start_highs_ts)) { $result['details'] = "No preceding high pivot for the last low pivot."; return $result;}
        $impulse_start_ts = max($potential_start_highs_ts);

        if(!isset($high_pivots[$impulse_start_ts], $low_pivots[$last_low_ts])) { $result['details'] = "Data error for downtrend impulse pivots."; return $result;}
        $start_price = (float)$high_pivots[$impulse_start_ts];
        $end_price = (float)$low_pivots[$last_low_ts];
        $impulse_end_ts = $last_low_ts;
        $is_uptrend_impulse = false;
    }

    if (abs($start_price - $end_price) < 0.000001 || $impulse_start_ts == 0 || $impulse_end_ts == 0 || $impulse_start_ts >= $impulse_end_ts) {
        $result['details'] = "Invalid impulse: zero range or invalid timestamps. S:{$start_price}, E:{$end_price}, STS:{$impulse_start_ts}, ETS:{$impulse_end_ts}";
        return $result;
    }

    $fibo_levels = calculate_fibo_retracement_levels($start_price, $end_price, $is_uptrend_impulse);
    if ($fibo_levels === false) {
        $result['details'] = "Error calculating Fibonacci levels for impulse {$start_price}-{$end_price}.";
        return $result;
    }

    $last_candle_overall = end($klines);
    if ($last_candle_overall['timestamp'] <= $impulse_end_ts) {
        $result['details'] = "No candles available after the identified Fibo impulse (ended at " . date('Y-m-d H:i',$impulse_end_ts/1000) . ").";
        return $result;
    }
    
    $candle_to_check = $last_candle_overall;
    $lc_low = (float)$candle_to_check['low']; $lc_high = (float)$candle_to_check['high'];
    $lc_close = (float)$candle_to_check['close'];

    $fibo_price_range = abs($end_price - $start_price);
    $allowance_value = ($fibo_price_range * $touch_allowance_percent_of_range) / 100.0;

    foreach ($levels_of_interest_percent as $level_percent) {
        $level_key = number_format($level_percent, 1); 
        if (!isset($fibo_levels[$level_key])) continue;

        $fibo_price = (float)$fibo_levels[$level_key];
        $touched_level = false;

        if ($is_uptrend_impulse) { 
            if ($lc_low <= ($fibo_price + $allowance_value) && $lc_close > ($fibo_price - $allowance_value * 0.5)) {
                $touched_level = true;
            }
        } else { 
            if ($lc_high >= ($fibo_price - $allowance_value) && $lc_close < ($fibo_price + $allowance_value * 0.5)) {
                $touched_level = true;
            }
        }

        if ($touched_level) {
            $result['triggered'] = true;
            $result['signal'] = $is_uptrend_impulse ? 'buy' : 'sell';
            $result['details'] = sprintf(
                "Pullback reaction at Fibo %s%% (%.4f) of impulse [%.4f (ts %s) to %.4f (ts %s)].",
                $level_key, $fibo_price, $start_price, date('Y-m-d H:i',$impulse_start_ts/1000), $end_price, date('Y-m-d H:i',$impulse_end_ts/1000)
            );
            $result['confidence_factor'] = $base_confidence + (($level_percent == 50.0 || $level_percent == 61.8) ? 0.05 : 0.0);
            $result['pattern_confirmed'] = true;
            break; 
        }
    }

    if (!$result['triggered']) {
        $fibo_levels_str_arr = [];
        foreach($levels_of_interest_percent as $lvl_p) {
            $lk = number_format($lvl_p, 1);
            if(isset($fibo_levels[$lk])) $fibo_levels_str_arr[] = "{$lk}% (".number_format($fibo_levels[$lk],4).")";
        }
        $fibo_levels_str = implode(', ', $fibo_levels_str_arr);
        $result['details'] = "No pullback reaction at key Fibo levels ({$fibo_levels_str}) of impulse {$start_price}-{$end_price}. Last candle L:{$lc_low}, H:{$lc_high}, C:{$lc_close}. Allowance: {$allowance_value}";
    }
    return $result;
}