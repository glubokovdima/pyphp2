<?php
// File: /strategies/strategy_rsi_zone.php

function analyze_strategy_rsi_zone(array $klines, array $params): array {
    $result = [
        'name' => 'RSI Zone',
        'triggered' => false,
        'signal' => 'neutral',
        'details' => 'Not enough data or RSI not calculated.',
        'confidence_factor' => 0.0,
        'extra_factors' => [
            'divergence' => 'none',
            'volume_support' => false,
            'pattern_confirmed' => false,
            'liquidity_zone' => 'none',
        ]
    ];

    $rsi_period = (int)($params['rsi_period'] ?? 14);
    $oversold_level = (float)($params['rsi_oversold'] ?? 30);
    $overbought_level = (float)($params['rsi_overbought'] ?? 70);
    $base_confidence = (float)($params['base_confidence_from_config'] ?? 0.2);

    if (count($klines) < $rsi_period + 1) {
        $result['details'] = "Insufficient data for RSI({$rsi_period}). Need " . ($rsi_period + 1) . ", have " . count($klines) . ".";
        return $result;
    }

    $close_prices = array_column($klines, 'close');
    if (empty($close_prices) || count($close_prices) !== count($klines)) {
        $result['details'] = "Failed to extract close prices for RSI.";
        return $result;
    }
     foreach($close_prices as $idx_cp => $cp) {
        if(!is_numeric($cp)) {
            $result['details'] = "Non-numeric close price at kline index {$idx_cp} for RSI.";
            return $result;
        }
    }

    $rsi_values = calculate_rsi($close_prices, $rsi_period);

    if ($rsi_values === false) {
        $result['details'] = "Error calculating RSI values.";
        return $result;
    }

    $current_rsi = null;
    for ($i = count($rsi_values) - 1; $i >= 0; $i--) {
        if (isset($rsi_values[$i]) && is_numeric($rsi_values[$i])) {
            $current_rsi = (float)$rsi_values[$i];
            break;
        }
    }

    if ($current_rsi === null) {
        $result['details'] = "Could not retrieve a valid current RSI value.";
        return $result;
    }
    
    $current_rsi_formatted = number_format($current_rsi, 2);

    if ($current_rsi < $oversold_level) {
        $result['triggered'] = true;
        $result['signal'] = 'buy';
        $result['details'] = "RSI({$rsi_period}) = {$current_rsi_formatted} is in oversold zone (< {$oversold_level}). Potential reversal upwards.";
        $result['confidence_factor'] = $base_confidence;
    } elseif ($current_rsi > $overbought_level) {
        $result['triggered'] = true;
        $result['signal'] = 'sell';
        $result['details'] = "RSI({$rsi_period}) = {$current_rsi_formatted} is in overbought zone (> {$overbought_level}). Potential reversal downwards.";
        $result['confidence_factor'] = $base_confidence;
    } else {
        $result['details'] = "RSI({$rsi_period}) = {$current_rsi_formatted}. Neutral zone ({$oversold_level} - {$overbought_level}).";
    }
    return $result;
}