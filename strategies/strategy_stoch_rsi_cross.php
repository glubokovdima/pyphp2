<?php

function analyze_strategy_stoch_rsi_cross(array $klines, array $params): array {
    $result = [
        'name' => 'Stochastic RSI Cross',
        'triggered' => false,
        'signal' => 'neutral',
        'details' => 'Not enough data or conditions not met.',
        'confidence_factor' => 0.0,
        'extra_factors' => ['pattern_confirmed' => false]
    ];

    $rsi_period = (int)($params['rsi_period'] ?? 14);
    $stoch_period = (int)($params['stoch_rsi_stoch_period'] ?? 14); // Stochastic period for RSI
    $k_period = (int)($params['stoch_rsi_k_period'] ?? 3); // %K smoothing for StochRSI
    $d_period = (int)($params['stoch_rsi_d_period'] ?? 3); // %D smoothing for StochRSI
    $oversold = (float)($params['stoch_rsi_oversold'] ?? 20);
    $overbought = (float)($params['stoch_rsi_overbought'] ?? 80);
    $base_confidence = (float)($params['base_confidence_from_config'] ?? 0.25);
    $num_klines = count($klines);

    if ($num_klines < $rsi_period + $stoch_period + $d_period) {
        $result['details'] = "Insufficient data for StochRSI. Need at least " . ($rsi_period + $stoch_period + $d_period) . " klines.";
        return $result;
    }

    $close_prices = array_column($klines, 'close');
    $rsi_values = calculate_rsi($close_prices, $rsi_period);

    if ($rsi_values === false) {
        $result['details'] = "Failed to calculate RSI.";
        return $result;
    }

    $stoch_rsi_data = calculate_stoch_rsi($rsi_values, $stoch_period, $k_period, $d_period);

    if ($stoch_rsi_data === false || !isset($stoch_rsi_data['k'], $stoch_rsi_data['d']) ||
        count($stoch_rsi_data['k']) < 2 || count($stoch_rsi_data['d']) < 2) {
        $result['details'] = "Failed to calculate StochRSI K or D lines or not enough points.";
        return $result;
    }

    $k_line = $stoch_rsi_data['k'];
    $d_line = $stoch_rsi_data['d'];
    $k_count = count($k_line);

    $current_k = null; $prev_k = null;
    $current_d = null; $prev_d = null;
    $valid_points = 0;

    for ($i = $k_count - 1; $i >= 0; $i--) {
        if (isset($k_line[$i], $d_line[$i]) && is_numeric($k_line[$i]) && is_numeric($d_line[$i])) {
            if ($valid_points === 0) {
                $current_k = (float)$k_line[$i];
                $current_d = (float)$d_line[$i];
            } elseif ($valid_points === 1) {
                $prev_k = (float)$k_line[$i];
                $prev_d = (float)$d_line[$i];
            }
            $valid_points++;
            if ($valid_points >= 2) break;
        }
    }

    if ($current_k === null || $prev_k === null || $current_d === null || $prev_d === null) {
        $result['details'] = "Not enough valid StochRSI K/D points for crossover.";
        return $result;
    }

    $ckf = number_format($current_k, 2); $cdf = number_format($current_d, 2);
    $pkf = number_format($prev_k, 2); $pdf = number_format($prev_d, 2);

    if ($prev_k <= $prev_d && $current_k > $current_d) {
        $result['triggered'] = true;
        $result['signal'] = 'buy';
        $result['details'] = sprintf("StochRSI Bullish Cross: K(%.2f) > D(%.2f). Prev K(%.2f) <= D(%.2f).", $current_k, $current_d, $prev_k, $prev_d);
        $result['confidence_factor'] = $base_confidence;
        if ($current_k < $oversold + 10) $result['confidence_factor'] += 0.1; // Boost if cross from oversold
        $result['extra_factors']['pattern_confirmed'] = true;

    } elseif ($prev_k >= $prev_d && $current_k < $current_d) {
        $result['triggered'] = true;
        $result['signal'] = 'sell';
        $result['details'] = sprintf("StochRSI Bearish Cross: K(%.2f) < D(%.2f). Prev K(%.2f) >= D(%.2f).", $current_k, $current_d, $prev_k, $prev_d);
        $result['confidence_factor'] = $base_confidence;
        if ($current_k > $overbought - 10) $result['confidence_factor'] += 0.1; // Boost if cross from overbought
        $result['extra_factors']['pattern_confirmed'] = true;
    } else {
        $result['details'] = sprintf("No StochRSI cross. Current K:%.2f, D:%.2f.", $current_k, $current_d);
    }
    return $result;
}