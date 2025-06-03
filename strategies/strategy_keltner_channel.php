<?php
// /market-analyzer/strategies/strategy_keltner_channel.php
if (!function_exists('calculate_keltner_channels')) {
    require_once __DIR__ . '/common_functions.php';
}

function analyze_strategy_keltner_channel(array $klines, array $params): array {
    $result = ['name' => 'Keltner Channel Bounce', 'triggered' => false, 'signal' => 'neutral', 'details' => '', 'confidence_factor' => 0.0];

    $ema_period = $params['keltner_ema_period'] ?? 20;
    $atr_period = $params['keltner_atr_period'] ?? $params['atr_period'] ?? 10;
    $atr_multiplier = $params['keltner_atr_multiplier'] ?? $params['atr_multiplier'] ?? 2.0;
    $base_confidence = $params['base_confidence_from_config'] ?? 0.2;

    if (count($klines) < max($ema_period, $atr_period + 1) +1) {
        $result['details'] = "Недостаточно данных для Keltner Channels."; return $result;
    }

    $keltner_data = calculate_keltner_channels($klines, $ema_period, $atr_period, $atr_multiplier);
    if ($keltner_data === false || !is_array($keltner_data) || !isset($keltner_data['upper'], $keltner_data['lower'], $keltner_data['middle'])) {
        $result['details'] = "Ошибка расчета Keltner Channels."; return $result;
    }

    $idx = count($klines) - 1;
    if ($idx < 0) { $result['details'] = "Нет свечей для анализа."; return $result; }
    $last_candle = $klines[$idx];

    if (!isset($keltner_data['upper'][$idx], $keltner_data['lower'][$idx], $keltner_data['middle'][$idx], $last_candle['low'], $last_candle['high'], $last_candle['close']) ||
        !is_numeric($keltner_data['upper'][$idx]) || !is_numeric($keltner_data['lower'][$idx]) || !is_numeric($keltner_data['middle'][$idx]) ||
        !is_numeric($last_candle['low']) || !is_numeric($last_candle['high']) || !is_numeric($last_candle['close'])) {
        $result['details'] = "Значения Keltner/свечи недоступны/некорректны."; return $result;
    }

    $upper_b = (float)$keltner_data['upper'][$idx]; $lower_b = (float)$keltner_data['lower'][$idx];
    $middle_b = (float)$keltner_data['middle'][$idx];
    $low_p = (float)$last_candle['low']; $high_p = (float)$last_candle['high']; $close_p = (float)$last_candle['close'];

    if ($low_p <= $lower_b && $close_p > $lower_b) {
        $result['triggered'] = true; $result['signal'] = 'buy';
        $result['details'] = sprintf("Отбой от нижней границы Keltner (%.2f -> %.2f).", $lower_b, $close_p);
        $result['confidence_factor'] = $base_confidence;
    } elseif ($high_p >= $upper_b && $close_p < $upper_b) {
        $result['triggered'] = true; $result['signal'] = 'sell';
        $result['details'] = sprintf("Отбой от верхней границы Keltner (%.2f -> %.2f).", $upper_b, $close_p);
        $result['confidence_factor'] = $base_confidence;
    } else {
        $result['details'] = sprintf("Нет отбоя от границ Keltner. L:%.2f M:%.2f U:%.2f C:%.2f", $lower_b, $middle_b, $upper_b, $close_p);
    }
    return $result;
}
?>