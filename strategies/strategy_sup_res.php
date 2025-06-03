<?php
// /market-analyzer/strategies/strategy_sup_res.php
if (!function_exists('find_pivot_points')) {
    require_once __DIR__ . '/common_functions.php';
}

function analyze_strategy_sup_res(array $klines, array $params): array {
    $result = ['name' => 'S/R Bounce', 'triggered' => false, 'signal' => 'neutral', 'details' => '', 'confidence_factor' => 0.0];

    $pivot_lookback = $params['sup_res_pivot_lookback'] ?? 5;
    $proximity_percent = $params['sup_res_level_proximity_percent'] ?? 0.2;
    $base_confidence = $params['base_confidence_from_config'] ?? 0.35;

    if (count($klines) < (2 * $pivot_lookback + 1) + 1) { // +1 для последней свечи
        $result['details'] = "Недостаточно данных для S/R."; return $result;
    }

    $pivots = find_pivot_points($klines, $pivot_lookback);
    $recent_high_pivots = array_slice($pivots['highs'], -3, 3, true);
    $recent_low_pivots = array_slice($pivots['lows'], -3, 3, true);

    if (empty($recent_high_pivots) && empty($recent_low_pivots)) {
        $result['details'] = "Нет недавних пивотов для S/R."; return $result;
    }

    $last_candle = $klines[count($klines) - 1];
    if(!isset($last_candle['low'], $last_candle['high'], $last_candle['close']) || !is_numeric($last_candle['low']) || !is_numeric($last_candle['high']) || !is_numeric($last_candle['close'])) {
        $result['details'] = "Некорректные данные последней свечи."; return $result;
    }
    $last_close = $last_candle['close']; $last_low = $last_candle['low']; $last_high = $last_candle['high'];

    foreach ($recent_high_pivots as $ts => $level_price) {
        if(!is_numeric($level_price)) continue;
        $allowance = ($level_price * $proximity_percent) / 100.0;
        if ($last_high >= ($level_price - $allowance) && $last_high <= ($level_price + $allowance) && $last_close < $level_price) {
            $result['triggered'] = true; $result['signal'] = 'sell';
            $result['details'] = "Отбой от сопротивления ~" . number_format($level_price, 2) . " (макс. " . gmdate("d.m H:i", $ts/1000) . ").";
            $result['confidence_factor'] = $base_confidence; return $result;
        }
    }
    foreach ($recent_low_pivots as $ts => $level_price) {
        if(!is_numeric($level_price)) continue;
        $allowance = ($level_price * $proximity_percent) / 100.0;
        if ($last_low <= ($level_price + $allowance) && $last_low >= ($level_price - $allowance) && $last_close > $level_price) {
            $result['triggered'] = true; $result['signal'] = 'buy';
            $result['details'] = "Отбой от поддержки ~" . number_format($level_price, 2) . " (мин. " . gmdate("d.m H:i", $ts/1000) . ").";
            $result['confidence_factor'] = $base_confidence; return $result;
        }
    }
    if (!$result['triggered']) $result['details'] = "Нет отбоев от недавних S/R.";
    return $result;
}
?>