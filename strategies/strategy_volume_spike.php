<?php
// File: /strategies/strategy_volume_spike.php

function analyze_strategy_volume_spike(array $klines, array $params): array {
    $result = [
        'name' => 'Volume Spike',
        'triggered' => false,
        'signal' => 'neutral',
        'details' => 'Not enough data or volume SMA not calculated.',
        'confidence_factor' => 0.0,
        'pattern_confirmed' => false,
        'volume_support' => false, 
        'divergence' => 'none',
    ];

    $sma_period = (int)($params['volume_sma_period'] ?? 20);
    $spike_factor_config = (float)($params['volume_spike_factor'] ?? $params['spike_factor_default'] ?? 2.0);
    $candles_to_check_config = (int)($params['candles_to_check'] ?? 1);
    $base_confidence = (float)($params['base_confidence_from_config'] ?? 0.15);

    if (count($klines) < $sma_period + $candles_to_check_config) {
        $result['details'] = "Insufficient data for Volume Spike (SMA{$sma_period}, Check {$candles_to_check_config}). Need " . ($sma_period + $candles_to_check_config) . ", have " . count($klines) . ".";
        return $result;
    }

    $volumes = array_column($klines, 'volume');
    if (empty($volumes) || count($volumes) !== count($klines)) {
        $result['details'] = "Failed to extract volumes."; return $result;
    }
    foreach($volumes as $idx_v => $v) { if(!is_numeric($v) || $v < 0) { $result['details'] = "Non-numeric or negative volume at kline index {$idx_v}."; return $result; }}


    $average_volumes = calculate_average_volume($volumes, $sma_period);
    if ($average_volumes === false) {
        $result['details'] = "Error calculating average volume (SMA{$sma_period}).";
        return $result;
    }

    $spike_detected = false;
    $spike_details_text = "";

    for ($i = 1; $i <= $candles_to_check_config; $i++) {
        $idx = count($klines) - $i; 
        if ($idx < 0) break; 

        if (!isset($klines[$idx], $volumes[$idx], $average_volumes[$idx]) ||
            !is_numeric($volumes[$idx]) || !is_numeric($average_volumes[$idx]) || $average_volumes[$idx] == 0) {
            continue;
        }

        $current_vol = (float)$volumes[$idx];
        $avg_vol = (float)$average_volumes[$idx];
        
        if ($current_vol > ($avg_vol * $spike_factor_config)) {
            $spike_detected = true;
            $actual_factor = round($current_vol / $avg_vol, 1);
            $spike_candle = $klines[$idx];
            $candle_direction_char = ($spike_candle['close'] > $spike_candle['open']) ? '▲' : (($spike_candle['close'] < $spike_candle['open']) ? '▼' : '▬');
            
            $spike_details_text = "Volume spike on candle #{$i} from end. Vol: " . number_format($current_vol) . " ({$actual_factor}x avg " . number_format($avg_vol) . "). Candle: {$candle_direction_char}.";
            
            $result['volume_support'] = true; 
            $result['triggered'] = true;
            $result['confidence_factor'] = $base_confidence;

            if ($spike_candle['close'] > $spike_candle['open']) {
                $result['signal'] = 'buy';
            } elseif ($spike_candle['close'] < $spike_candle['open']) {
                $result['signal'] = 'sell';
            }
            break; 
        }
    }

    if ($spike_detected) {
        $result['details'] = $spike_details_text;
    } else {
        $last_avg_vol_val = null; $k = count($average_volumes)-1; while($k>=0 && ($average_volumes[$k]===null || !is_numeric($average_volumes[$k]))) $k--;
        if($k>=0 && isset($average_volumes[$k])) $last_avg_vol_val=(float)$average_volumes[$k];
        $last_vol_val = (count($volumes)>0 && is_numeric(end($volumes))) ? (float)end($volumes) : null;

        $result['details'] = "No significant volume spikes (>{$spike_factor_config}x avg) on last {$candles_to_check_config} candle(s). Last Vol: " . ($last_vol_val!==null ? number_format($last_vol_val) : 'N/A') . ", Last AvgVol (SMA{$sma_period}): " . ($last_avg_vol_val!==null ? number_format($last_avg_vol_val) : 'N/A') . ".";
    }
    return $result;
}