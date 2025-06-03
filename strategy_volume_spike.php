<?php
// /market-analyzer/strategies/strategy_volume_spike.php
if (!function_exists('calculate_average_volume')) { // Убедимся, что общие функции подключены
    require_once __DIR__ . '/common_functions.php';
}

/**
 * Анализирует всплески объема.
 * @param array $klines Массив свечей (каждая с ключом 'volume').
 * @param array $params Параметры:
 *              ['volume_sma_period' => 20] (период для SMA объема)
 *              ['spike_factor' => 2.0] (во сколько раз объем должен превышать средний)
 *              ['candles_to_check' => 1] (сколько последних свечей проверять на всплеск)
 * @return array Результат.
 */
function analyze_strategy_volume_spike(array $klines, array $params): array {
    $result = ['name' => 'Volume Spike', 'triggered' => false, 'signal' => 'neutral', 'details' => '', 'confidence_factor' => 0.0];

    $sma_period = $params['volume_sma_period'] ?? 20;
    $spike_factor = $params['spike_factor'] ?? 2.0; // Объем должен быть в X раз выше среднего
    $candles_to_check = $params['candles_to_check'] ?? 1; // Проверяем N последних свечей

    if (count($klines) < $sma_period + $candles_to_check) {
        $result['details'] = "Недостаточно данных для анализа Volume Spike (нужно {$sma_period} + {$candles_to_check} свечей).";
        return $result;
    }

    $volumes = array_column($klines, 'volume');
    $average_volumes = calculate_average_volume($volumes, $sma_period); // Возвращает массив той же длины с null в начале

    if ($average_volumes === false || empty($average_volumes)) {
        $result['details'] = "Ошибка расчета среднего объема.";
        return $result;
    }

    $spike_detected_on_candle = -1; // Индекс свечи, на которой был всплеск (-1 если нет)
    $spike_details = "";

    for ($i = 1; $i <= $candles_to_check; $i++) {
        $candle_index = count($klines) - $i; // Индекс свечи от конца (0 = последняя, 1 = предпоследняя)

        if ($candle_index < 0) break; // Вышли за пределы массива

        $current_volume = $klines[$candle_index]['volume'];
        $avg_volume_for_candle = $average_volumes[$candle_index]; // SMA объема для этой свечи

        if ($avg_volume_for_candle === null || $avg_volume_for_candle == 0) {
            // Не можем сравнить, если нет среднего или оно нулевое
            // $result['details'] .= " [Нет среднего объема для свечи #{$candle_index}]";
            continue;
        }

        if ($current_volume > ($avg_volume_for_candle * $spike_factor)) {
            $spike_detected_on_candle = $candle_index;
            $actual_factor = round($current_volume / $avg_volume_for_candle, 1);
            $spike_details = "Обнаружен всплеск объема на свече #{$candle_index} (от конца): объем {$current_volume} в {$actual_factor}x раз выше среднего ({$avg_volume_for_candle:.2f}).";

            // Определяем направление свечи, на которой был всплеск
            $spike_candle = $klines[$candle_index];
            $direction_signal = 'neutral';
            if ($spike_candle['close'] > $spike_candle['open']) {
                $direction_signal = 'buy'; // Зеленая свеча на всплеске - бычий признак
            } elseif ($spike_candle['close'] < $spike_candle['open']) {
                $direction_signal = 'sell'; // Красная свеча на всплеске - медвежий признак
            }

            $result['signal'] = $direction_signal; // Сигнал в сторону всплеска
            break; // Нашли первый всплеск, выходим (можно изменить логику, если нужно учитывать все)
        }
    }

    if ($spike_detected_on_candle !== -1) {
        $result['triggered'] = true;
        $result['details'] = $spike_details;
        $result['confidence_factor'] = $params['base_confidence'] ?? 0.15; // Всплеск объема сам по себе - слабый сигнал без контекста
    } else {
        $last_avg_vol = end($average_volumes);
        $last_vol = end($volumes);
        $result['details'] = "Значительных всплесков объема на последних {$candles_to_check} свечах не обнаружено. Последний объем: {$last_vol}, средний: " . ($last_avg_vol !== null ? number_format($last_avg_vol,2) : 'N/A') . ".";
    }

    return $result;
}
?>