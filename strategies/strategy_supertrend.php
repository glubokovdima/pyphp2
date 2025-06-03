<?php
// File: /strategies/strategy_supertrend.php

function analyze_strategy_supertrend(array $klines, array $params): array {
    $result = [
        'name' => 'Supertrend Filter',
        'triggered' => false,
        'signal' => 'neutral',
        'details' => 'Not enough data or Supertrend not calculated.',
        'confidence_factor' => 0.0,
        'pattern_confirmed' => false, 
        'volume_support' => false,
        'divergence' => 'none',
    ];

    $atr_period = (int)($params['atr_period'] ?? $params['supertrend_period'] ?? 10); 
    $multiplier = (float)($params['supertrend_multiplier'] ?? $params['atr_multiplier'] ?? 3.0);
    $base_confidence = (float)($params['base_confidence_from_config'] ?? 0.35);

    if (count($klines) < $atr_period + 2) {
        $result['details'] = "Insufficient data for Supertrend (ATR P:{$atr_period}, Mult:{$multiplier}). Need " . ($atr_period + 2) . ".";
        return $result;
    }

    $supertrend_data = calculate_supertrend($klines, $atr_period, $multiplier);

    if ($supertrend_data === false || !is_array($supertrend_data) || 
        !isset($supertrend_data['direction']) || !isset($supertrend_data['supertrend']) ||
        empty($supertrend_data['direction'])) {
        $result['details'] = "Error calculating Supertrend data.";
        return $result;
    }

    $st_directions = $supertrend_data['direction'];
    $st_values = $supertrend_data['supertrend'];
    
    $last_direction = null;
    $last_st_value = null;
    $last_close = null;

    for ($i = count($st_directions) - 1; $i >= 0; $i--) {
        if (isset($st_directions[$i]) && is_numeric($st_directions[$i]) && $st_directions[$i] !== 0 &&
            isset($st_values[$i]) && is_numeric($st_values[$i]) &&
            isset($klines[$i]['close']) && is_numeric($klines[$i]['close'])) {
            $last_direction = (int)$st_directions[$i];
            $last_st_value = (float)$st_values[$i];
            $last_close = (float)$klines[$i]['close'];
            break;
        }
    }

    if ($last_direction === null || $last_st_value === null || $last_close === null) {
        $result['details'] = "Could not retrieve a valid current Supertrend state.";
        return $result;
    }
    
    $st_val_f = number_format($last_st_value, 4);

    if ($last_direction == 1) { 
        $result['triggered'] = true;
        $result['signal'] = 'buy';
        $result['details'] = "Supertrend (P:{$atr_period}, M:{$multiplier}) indicates UPTREND. ST Line: {$st_val_f}. Last Close: {$last_close}.";
        $result['confidence_factor'] = $base_confidence;
        $result['pattern_confirmed'] = true; 
    } elseif ($last_direction == -1) { 
        $result['triggered'] = true;
        $result['signal'] = 'sell';
        $result['details'] = "Supertrend (P:{$atr_period}, M:{$multiplier}) indicates DOWNTREND. ST Line: {$st_val_f}. Last Close: {$last_close}.";
        $result['confidence_factor'] = $base_confidence;
        $result['pattern_confirmed'] = true;
    } else { 
        $result['details'] = "Supertrend (P:{$atr_period}, M:{$multiplier}) is neutral or undecided. ST Line: {$st_val_f}.";
    }
    return $result;
}