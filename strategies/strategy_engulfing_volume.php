<?php

function analyze_strategy_engulfing_volume(array $klines, array $params): array {
    $result = [
        'name' => 'Engulfing Pattern + Volume',
        'triggered' => false,
        'signal' => 'neutral',
        'details' => 'Not enough data or conditions not met.',
        'confidence_factor' => 0.0,
        'extra_factors' => ['volume_support' => false, 'pattern_confirmed' => false]
    ];

    $num_klines = count($klines);
    $volume_sma_period = (int)($params['volume_sma_period'] ?? 20);
    $base_confidence = (float)($params['base_confidence_from_config'] ?? 0.3);

    if ($num_klines < max(2, $volume_sma_period)) {
        $result['details'] = "Insufficient data. Need at least " . max(2, $volume_sma_period) . " klines.";
        return $result;
    }

    $last_candle = $klines[$num_klines - 1];
    $prev_candle = $klines[$num_klines - 2];
    $volumes = array_column($klines, 'volume');
    $average_volumes = calculate_average_volume($volumes, $volume_sma_period);

    if ($average_volumes === false || !isset($average_volumes[$num_klines - 1])) {
        $result['details'] = "Could not calculate average volume.";
        return $result;
    }

    $required_keys_candle = ['open', 'close', 'high', 'low', 'volume'];
    foreach ([$last_candle, $prev_candle] as $candle_check) {
        foreach ($required_keys_candle as $key) {
            if (!isset($candle_check[$key]) || !is_numeric($candle_check[$key])) {
                $result['details'] = "Invalid candle data.";
                return $result;
            }
        }
    }

    $lc_open = (float)$last_candle['open']; $lc_close = (float)$last_candle['close'];
    $pc_open = (float)$prev_candle['open']; $pc_close = (float)$prev_candle['close'];
    $lc_volume = (float)$last_candle['volume'];
    $avg_vol = (float)$average_volumes[$num_klines - 1];

    $volume_confirmed = ($avg_vol > 0 && $lc_volume > $avg_vol * 1.2); // Example: 20% above average

    $bullish_engulfing = $pc_close < $pc_open && $lc_close > $lc_open && $lc_close > $pc_open && $lc_open < $pc_close;
    $bearish_engulfing = $pc_close > $pc_open && $lc_close < $lc_open && $lc_close < $pc_open && $lc_open > $pc_close;

    if ($bullish_engulfing) {
        $result['pattern_confirmed'] = true;
        $result['details'] = sprintf("Bullish Engulfing (PC O:%.4f C:%.4f; LC O:%.4f C:%.4f).", $pc_open, $pc_close, $lc_open, $lc_close);
        if ($volume_confirmed) {
            $result['triggered'] = true;
            $result['signal'] = 'buy';
            $result['details'] .= sprintf(" Volume confirmed (LC Vol:%.2f > AvgVol:%.2f).", $lc_volume, $avg_vol);
            $result['confidence_factor'] = $base_confidence + 0.1;
            $result['extra_factors']['volume_support'] = true;
        } else {
            $result['details'] .= sprintf(" Volume not confirmed (LC Vol:%.2f vs AvgVol:%.2f).", $lc_volume, $avg_vol);
            $result['confidence_factor'] = $base_confidence * 0.5;
        }
    } elseif ($bearish_engulfing) {
        $result['pattern_confirmed'] = true;
        $result['details'] = sprintf("Bearish Engulfing (PC O:%.4f C:%.4f; LC O:%.4f C:%.4f).", $pc_open, $pc_close, $lc_open, $lc_close);
        if ($volume_confirmed) {
            $result['triggered'] = true;
            $result['signal'] = 'sell';
            $result['details'] .= sprintf(" Volume confirmed (LC Vol:%.2f > AvgVol:%.2f).", $lc_volume, $avg_vol);
            $result['confidence_factor'] = $base_confidence + 0.1;
            $result['extra_factors']['volume_support'] = true;
        } else {
            $result['details'] .= sprintf(" Volume not confirmed (LC Vol:%.2f vs AvgVol:%.2f).", $lc_volume, $avg_vol);
            $result['confidence_factor'] = $base_confidence * 0.5;
        }
    } else {
        $result['details'] = "No Engulfing pattern on last two candles.";
    }
    if($result['pattern_confirmed']) $result['extra_factors']['pattern_confirmed'] = true;
    return $result;
}