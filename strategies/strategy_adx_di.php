<?php

function analyze_strategy_adx_di(array $klines, array $params): array {
    $result = [
        'name' => 'ADX + DI Strategy',
        'triggered' => false,
        'signal' => 'neutral',
        'details' => 'Not enough data or ADX/DI not calculated.',
        'confidence_factor' => 0.0,
        'extra_factors' => ['pattern_confirmed' => false]
    ];

    $adx_period = (int)($params['adx_period'] ?? 14);
    $adx_trend_threshold = (float)($params['adx_trend_threshold'] ?? 20);
    $base_confidence = (float)($params['base_confidence_from_config'] ?? 0.3);
    $num_klines = count($klines);

    if ($num_klines < $adx_period * 2) { // ADX calculation can be complex and require more data
        $result['details'] = "Insufficient data for ADX/DI. Need at least " . ($adx_period * 2) . " klines.";
        return $result;
    }

    $adx_data = calculate_adx_di($klines, $adx_period);

    if ($adx_data === false || !isset($adx_data['adx'], $adx_data['plus_di'], $adx_data['minus_di']) ||
        empty($adx_data['adx']) || empty($adx_data['plus_di']) || empty($adx_data['minus_di'])) {
        $result['details'] = "Error calculating ADX/DI data.";
        return $result;
    }

    $last_adx = null; $last_plus_di = null; $last_minus_di = null;

    for($i = count($adx_data['adx']) - 1; $i >= 0; $i--) {
        if(isset($adx_data['adx'][$i], $adx_data['plus_di'][$i], $adx_data['minus_di'][$i]) &&
            is_numeric($adx_data['adx'][$i]) && is_numeric($adx_data['plus_di'][$i]) && is_numeric($adx_data['minus_di'][$i])) {
            $last_adx = (float)$adx_data['adx'][$i];
            $last_plus_di = (float)$adx_data['plus_di'][$i];
            $last_minus_di = (float)$adx_data['minus_di'][$i];
            break;
        }
    }

    if ($last_adx === null) {
        $result['details'] = "Could not retrieve valid last ADX/DI values.";
        return $result;
    }

    $adxf = number_format($last_adx, 2);
    $pdif = number_format($last_plus_di, 2);
    $mdif = number_format($last_minus_di, 2);

    if ($last_adx >= $adx_trend_threshold) {
        if ($last_plus_di > $last_minus_di) {
            $result['triggered'] = true;
            $result['signal'] = 'buy';
            $result['details'] = sprintf("Trending market (ADX:%.2f >= %.2f). +DI(%.2f) > -DI(%.2f). Bullish.", $last_adx, $adx_trend_threshold, $last_plus_di, $last_minus_di);
            $result['confidence_factor'] = $base_confidence + min(0.2, ($last_adx - $adx_trend_threshold) / 50); // Boost for stronger ADX
            $result['extra_factors']['pattern_confirmed'] = true;
        } elseif ($last_minus_di > $last_plus_di) {
            $result['triggered'] = true;
            $result['signal'] = 'sell';
            $result['details'] = sprintf("Trending market (ADX:%.2f >= %.2f). -DI(%.2f) > +DI(%.2f). Bearish.", $last_adx, $adx_trend_threshold, $last_minus_di, $last_plus_di);
            $result['confidence_factor'] = $base_confidence + min(0.2, ($last_adx - $adx_trend_threshold) / 50);
            $result['extra_factors']['pattern_confirmed'] = true;
        } else {
            $result['details'] = sprintf("Trending market (ADX:%.2f >= %.2f), but +DI(%.2f) and -DI(%.2f) are equal. Indecision.", $last_adx, $adx_trend_threshold, $last_plus_di, $last_minus_di);
        }
    } else {
        $result['details'] = sprintf("Market is not trending or trend is weak (ADX:%.2f < %.2f). +DI:%.2f, -DI:%.2f.", $last_adx, $adx_trend_threshold, $last_plus_di, $last_minus_di);
    }
    return $result;
}