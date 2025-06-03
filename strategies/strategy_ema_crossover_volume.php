<?php

function analyze_strategy_ema_crossover_volume(array $klines, array $params): array {
    $result = [
        'name' => 'EMA Crossover + Volume',
        'triggered' => false,
        'signal' => 'neutral',
        'details' => 'Not enough data or conditions not met.',
        'confidence_factor' => 0.0,
        'extra_factors' => ['volume_support' => false, 'pattern_confirmed' => false]
    ];

    $ema_short_period = (int)($params['ema_short'] ?? 12);
    $ema_long_period = (int)($params['ema_long'] ?? 26);
    $volume_sma_period = (int)($params['volume_sma_period'] ?? 20);
    $base_confidence = (float)($params['base_confidence_from_config'] ?? 0.3);
    $num_klines = count($klines);

    if ($num_klines < max($ema_long_period, $volume_sma_period)) {
        $result['details'] = "Insufficient data. Need at least " . max($ema_long_period, $volume_sma_period) . " klines.";
        return $result;
    }

    $close_prices = array_column($klines, 'close');
    $volumes = array_column($klines, 'volume');

    $ema_short_values = calculate_ema($close_prices, $ema_short_period);
    $ema_long_values = calculate_ema($close_prices, $ema_long_period);
    $average_volumes = calculate_average_volume($volumes, $volume_sma_period);

    if ($ema_short_values === false || $ema_long_values === false || $average_volumes === false ||
        !isset($ema_short_values[$num_klines - 1], $ema_long_values[$num_klines - 1], $average_volumes[$num_klines - 1],
            $ema_short_values[$num_klines - 2], $ema_long_values[$num_klines - 2])) {
        $result['details'] = "Error calculating EMAs or average volume for the last points.";
        return $result;
    }

    $current_ema_short = (float)$ema_short_values[$num_klines - 1];
    $current_ema_long = (float)$ema_long_values[$num_klines - 1];
    $prev_ema_short = (float)$ema_short_values[$num_klines - 2];
    $prev_ema_long = (float)$ema_long_values[$num_klines - 2];
    $current_volume = (float)$volumes[$num_klines - 1];
    $current_avg_volume = (float)$average_volumes[$num_klines - 1];

    $volume_confirmed = ($current_avg_volume > 0 && $current_volume > $current_avg_volume * 1.1); // Volume 10% above SMA

    $bullish_cross = $prev_ema_short <= $prev_ema_long && $current_ema_short > $current_ema_long;
    $bearish_cross = $prev_ema_short >= $prev_ema_long && $current_ema_short < $current_ema_long;

    $cross_details = "";
    if ($bullish_cross) {
        $result['extra_factors']['pattern_confirmed'] = true;
        $cross_details = sprintf("Bullish EMA Cross (S%.4f > L%.4f). Prev (S%.4f <= L%.4f).", $current_ema_short, $current_ema_long, $prev_ema_short, $prev_ema_long);
        if ($volume_confirmed) {
            $result['triggered'] = true;
            $result['signal'] = 'buy';
            $result['details'] = $cross_details . sprintf(" Volume confirmed (Vol:%.2f > AvgVol:%.2f).", $current_volume, $current_avg_volume);
            $result['confidence_factor'] = $base_confidence + 0.1;
            $result['extra_factors']['volume_support'] = true;
        } else {
            $result['details'] = $cross_details . sprintf(" Volume NOT confirmed (Vol:%.2f vs AvgVol:%.2f).", $current_volume, $current_avg_volume);
            $result['confidence_factor'] = $base_confidence * 0.6; // Still a cross, but less confident
        }
    } elseif ($bearish_cross) {
        $result['extra_factors']['pattern_confirmed'] = true;
        $cross_details = sprintf("Bearish EMA Cross (S%.4f < L%.4f). Prev (S%.4f >= L%.4f).", $current_ema_short, $current_ema_long, $prev_ema_short, $prev_ema_long);
        if ($volume_confirmed) {
            $result['triggered'] = true;
            $result['signal'] = 'sell';
            $result['details'] = $cross_details . sprintf(" Volume confirmed (Vol:%.2f > AvgVol:%.2f).", $current_volume, $current_avg_volume);
            $result['confidence_factor'] = $base_confidence + 0.1;
            $result['extra_factors']['volume_support'] = true;
        } else {
            $result['details'] = $cross_details . sprintf(" Volume NOT confirmed (Vol:%.2f vs AvgVol:%.2f).", $current_volume, $current_avg_volume);
            $result['confidence_factor'] = $base_confidence * 0.6;
        }
    } else {
        $result['details'] = sprintf("No EMA cross. Current S:%.4f, L:%.4f.", $current_ema_short, $current_ema_long);
    }
    return $result;
}