<?php
// /market-analyzer/strategies/strategy_rsi.php
if (!function_exists('calculate_rsi')) {
    require_once __DIR__ . '/common_functions.php';
}

/**
 * Анализирует сигнал по RSI.
 * Использует 'rsi_period', 'rsi_oversold', 'rsi_overbought' из $params.
 */
function analyze_strategy_rsi(array $klines, array $params): array {
    $result = ['name' => 'RSI Zone', 'triggered' => false, 'signal' => 'neutral', 'details' => '', 'confidence_factor' => 0.0];

    $rsi_period = $params['rsi_period'] ?? 14;
    $oversold_level = $params['rsi_oversold'] ?? 30;
    $overbought_level = $params['rsi_overbought'] ?? 70;
    $base_confidence = $params['base_confidence_from_config'] ?? 0.25;

    if (count($klines) < $rsi_period + 1) {
        $result['details'] = "Недостаточно данных для RSI({$rsi_period}). Нужно " .($rsi_period+1).", есть ".count($klines).".";
        return $result;
    }

    $close_prices = array_column($klines, 'close');
    foreach($close_prices as $key => $price) { if(!is_numeric($price)) { $result['details'] = "Нечисловые данные в ценах закрытия для RSI (idx {$key})."; return $result;}}

    $rsi_values = calculate_rsi($close_prices, $rsi_period);

    if ($rsi_values === false) { $result['details'] = "Ошибка расчета RSI."; return $result; }

    $current_rsi = null;
    for($i = count($rsi_values) - 1; $i >=0; $i--) { if(is_numeric($rsi_values[$i])) {$current_rsi = $rsi_values[$i]; break;} }


    if ($current_rsi === null) {
        $result['details'] = "Не удалось получить текущее значение RSI."; return $result;
    }
    $current_rsi_formatted = number_format($current_rsi, 2);

    if ($current_rsi < $oversold_level) {
        $result['triggered'] = true; $result['signal'] = 'buy';
        $result['details'] = "RSI({$rsi_period}) = {$current_rsi_formatted} в зоне перепроданности (< {$oversold_level}).";
        $result['confidence_factor'] = $base_confidence;
    } elseif ($current_rsi > $overbought_level) {
        $result['triggered'] = true; $result['signal'] = 'sell';
        $result['details'] = "RSI({$rsi_period}) = {$current_rsi_formatted} в зоне перекупленности (> {$overbought_level}).";
        $result['confidence_factor'] = $base_confidence;
    } else {
        $result['details'] = "RSI({$rsi_period}) = {$current_rsi_formatted}. Нейтральная зона.";
    }
    return $result;
}
?>