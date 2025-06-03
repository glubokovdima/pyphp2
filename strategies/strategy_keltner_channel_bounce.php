<?php
// File: /strategies/strategy_keltner_channel_bounce.php

function analyze_strategy_keltner_channel_bounce(array $klines, array $params): array {
    $result = [
        'name' => 'Keltner Channel Bounce',
        'triggered' => false,
        'signal' => 'neutral',
        'details' => 'Not enough data or Keltner Channels not calculated.',
        'confidence_factor' => 0.0,
        'pattern_confirmed' => false,
        'volume_support' => false,
        'divergence' => 'none',
    ];

    $ema_period = (int)($params['keltner_ema_period'] ?? 20);
    $atr_period = (int)($params['atr_period'] ?? $params['keltner_atr_period'] ?? 10); 
    $atr_multiplier = (float)($params['keltner_atr_multiplier'] ?? $params['atr_multiplier'] ?? 2.0);
    $base_confidence = (float)($params['base_confidence_from_config'] ?? 0.20);

    if (count($klines) < max($ema_period, $atr_period + 1) + 1) { 
        $result['details'] = "Insufficient data for Keltner Channels (EMA P:{$ema_period}, ATR P:{$atr_period}, M:{$atr_multiplier}).";
        return $result;
    }

    $keltner_data = calculate_keltner_channels($klines, $ema_period, $atr_period, $atr_multiplier);

    if ($keltner_data === false || !is_array($keltner_data) || 
        !isset($keltner_data['upper'], $keltner_data['lower'], $keltner_data['middle']) ||
        empty($keltner_data['upper']) || empty($keltner_data['lower'])) {
        $result['details'] = "Error calculating Keltner Channel data.";
        return $result;
    }

    $idx = count($klines) - 1; 
    $last_candle = $klines[$idx];

    if (!isset($keltner_data['upper'][$idx], $keltner_data['lower'][$idx], $keltner_data['middle'][$idx],
               $last_candle['low'], $last_candle['high'], $last_candle['close']) ||
        !is_numeric($keltner_data['upper'][$idx]) || !is_numeric($keltner_data['lower'][$idx]) ||
        !is_numeric($last_candle['low']) || !is_numeric($last_candle['high']) || !is_numeric($last_candle['close'])) {
        $result['details'] = "Keltner Channel values or last candle data are missing/invalid for the last point.";
        return $result;
    }

    $upper_band_val = (float)$keltner_data['upper'][$idx];
    $lower_band_val = (float)$keltner_data['lower'][$idx];
    $middle_band_val = (float)($keltner_data['middle'][$idx] ?? ($upper_band_val + $lower_band_val)/2); 

    $lc_low = (float)$last_candle['low']; $lc_high = (float)$last_candle['high']; $lc_close = (float)$last_candle['close'];
    
    if ($lc_low <= $lower_band_val && $lc_close > $lower_band_val) {
        $result['triggered'] = true;
        $result['signal'] = 'buy';
        $result['details'] = sprintf(
            "Bounce from Keltner Lower Band (%.4f). Last Low: %.4f, Close: %.4f.",
            $lower_band_val, $lc_low, $lc_close
        );
        $result['confidence_factor'] = $base_confidence;
        $result['pattern_confirmed'] = true;
    } 
    elseif ($lc_high >= $upper_band_val && $lc_close < $upper_band_val) {
        $result['triggered'] = true;
        $result['signal'] = 'sell';
        $result['details'] = sprintf(
            "Rejection from Keltner Upper Band (%.4f). Last High: %.4f, Close: %.4f.",
            $upper_band_val, $lc_high, $lc_close
        );
        $result['confidence_factor'] = $base_confidence;
        $result['pattern_confirmed'] = true;
    } else {
        $result['details'] = sprintf(
            "No clear Keltner bounce. Price C:%.4f. Bands L:%.4f, M:%.4f, U:%.4f.",
            $lc_close, $lower_band_val, $middle_band_val, $upper_band_val
        );
    }
    return $result;
}