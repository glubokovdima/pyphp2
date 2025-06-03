<?php

function analyze_strategy_oi_volume_compression(array $klines, array $params): array {
    $result = [
        'name' => 'OI+Volume Compression Breakout',
        'triggered' => false,
        'signal' => 'neutral',
        'details' => 'Not enough data or conditions not met.',
        'confidence_factor' => 0.0,
        'extra_factors' => ['volume_support' => false, 'pattern_confirmed' => false]
    ];

    $compression_candles = (int)($params['oi_compression_candles'] ?? 5);
    $vol_compress_factor = (float)($params['volume_compression_factor_vs_sma'] ?? 0.8); // Vol below 0.8 * SMA
    $vol_sma_period = (int)($params['volume_sma_period'] ?? 20);
    $oi_stability_thresh = (float)($params['oi_stability_threshold_percent'] ?? 5.0); // OI change within +/- 5%
    $breakout_vol_factor = (float)($params['breakout_volume_factor_vs_sma'] ?? 1.5); // Breakout vol > 1.5 * SMA
    $base_confidence = (float)($params['base_confidence_from_config'] ?? 0.4);
    $num_klines = count($klines);

    if ($num_klines < $vol_sma_period + $compression_candles + 1) {
        $result['details'] = "Insufficient data. Need " . ($vol_sma_period + $compression_candles + 1) . " klines.";
        return $result;
    }
    if (!isset($klines[0]['oi']) || !isset($klines[0]['volume'])) {
        $result['details'] = "OI or Volume data missing in klines.";
        return $result;
    }

    $volumes = array_column($klines, 'volume');
    $open_interests = array_column($klines, 'oi');
    $average_volumes = calculate_average_volume($volumes, $vol_sma_period);

    if ($average_volumes === false) {
        $result['details'] = "Could not calculate average volume.";
        return $result;
    }

    $in_compression = true;
    $compression_start_idx = $num_klines - 1 - $compression_candles;
    $oi_at_compression_start = null;

    for ($i = 0; $i < $compression_candles; $i++) {
        $idx = $num_klines - 1 - $compression_candles + $i;
        if ($idx < 0 || !isset($volumes[$idx], $average_volumes[$idx], $open_interests[$idx]) ||
            !is_numeric($volumes[$idx]) || !is_numeric($average_volumes[$idx]) || $average_volumes[$idx] == 0 || !is_numeric($open_interests[$idx])) {
            $in_compression = false; break;
        }
        if ($volumes[$idx] > ($average_volumes[$idx] * $vol_compress_factor)) {
            $in_compression = false; break;
        }
        if ($i === 0) $oi_at_compression_start = (float)$open_interests[$idx];

        if ($oi_at_compression_start > 0) {
            $oi_change_percent = abs(((float)$open_interests[$idx] - $oi_at_compression_start) / $oi_at_compression_start) * 100;
            if ($oi_change_percent > $oi_stability_thresh) {
                // OI not stable enough, but could be rising which is ok. Let's allow OI rise.
                if ((float)$open_interests[$idx] < $oi_at_compression_start * (1 - $oi_stability_thresh/100) ) {
                    $in_compression = false; break; // OI dropped too much
                }
            }
        }
    }

    if (!$in_compression) {
        $result['details'] = "No clear volume/OI compression in the last {$compression_candles} candles.";
        return $result;
    }

    $breakout_candle_idx = $num_klines - 1;
    $breakout_candle = $klines[$breakout_candle_idx];
    $breakout_volume = (float)$volumes[$breakout_candle_idx];
    $breakout_avg_volume = (float)$average_volumes[$breakout_candle_idx];

    if ($breakout_avg_volume > 0 && $breakout_volume > ($breakout_avg_volume * $breakout_vol_factor)) {
        $result['extra_factors']['volume_support'] = true;
        $result['extra_factors']['pattern_confirmed'] = true;
        $result['triggered'] = true;
        $result['confidence_factor'] = $base_confidence;

        $compression_high = 0; $compression_low = PHP_FLOAT_MAX;
        for ($i=0; $i < $compression_candles; $i++) {
            $idx = $num_klines - 1 - $compression_candles + $i;
            if (isset($klines[$idx]['high']) && $klines[$idx]['high'] > $compression_high) $compression_high = $klines[$idx]['high'];
            if (isset($klines[$idx]['low']) && $klines[$idx]['low'] < $compression_low) $compression_low = $klines[$idx]['low'];
        }

        if ($breakout_candle['close'] > $breakout_candle['open'] && $breakout_candle['close'] > $compression_high) {
            $result['signal'] = 'buy';
            $result['details'] = sprintf("Bullish breakout after %d candles of Vol/OI compression. Breakout Vol:%.2f > AvgVol:%.2f.", $compression_candles, $breakout_volume, $breakout_avg_volume);
        } elseif ($breakout_candle['close'] < $breakout_candle['open'] && $breakout_candle['close'] < $compression_low) {
            $result['signal'] = 'sell';
            $result['details'] = sprintf("Bearish breakout after %d candles of Vol/OI compression. Breakout Vol:%.2f > AvgVol:%.2f.", $compression_candles, $breakout_volume, $breakout_avg_volume);
        } else {
            $result['details'] = sprintf("Volume/OI compression detected, but breakout candle direction is unclear or not breaking range. Breakout Vol:%.2f > AvgVol:%.2f.", $compression_candles, $breakout_volume, $breakout_avg_volume);
            $result['triggered'] = false; // No clear directional breakout
            $result['confidence_factor'] = 0.0;
        }
    } else {
        $result['details'] = "Volume/OI compression detected, but no breakout volume spike on the last candle.";
    }
    return $result;
}