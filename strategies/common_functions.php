<?php
// /market-analyzer/strategies/common_functions.php

function log_indicator_error($function_name, $message) {
    // In a real app, this might write to a specific indicator log or use a more robust logging system.
    // For now, using error_log for simplicity, assuming it's configured.
    // error_log("Indicator Error in {$function_name}: {$message}"); // Keep commented unless debugging specific indicator
}

function calculate_sma(array $data, int $period): array|false {
    if ($period <= 0) { /* log_indicator_error(__FUNCTION__, "Invalid period {$period}."); */ return false; }
    $data_count = count($data);
    if ($data_count < $period) { /* Not an error, just insufficient data */ return false; }

    $sma_output = array_fill(0, $period - 1, null); // Fill initial points with null
    for ($i = $period - 1; $i < $data_count; $i++) {
        $slice_sum = 0;
        $valid_points_in_slice = 0; // <-- Эта переменная была добавлена в одной из версий
        for ($j = 0; $j < $period; $j++) {
            $idx_to_check = $i - $j;
            if (!isset($data[$idx_to_check]) || !is_numeric($data[$idx_to_check])) {
                $slice_sum = null;
                break;
            }
            $slice_sum += $data[$idx_to_check];
            $valid_points_in_slice++; // <-- Строка 28 (если считать от начала функции log_indicator_error)
        } // <-- Закрывающая скобка для внутреннего цикла for ($j...) - ЭТО БЫЛА СТРОКА 29

        // Проверка была такая:
        // if ($slice_sum !== null && $valid_points_in_slice === $period) {
        //      $sma_output[] = $slice_sum / $period;
        // } else {
        //      $sma_output[] = null;
        // }
        // Но если $slice_sum стал null, то $valid_points_in_slice может быть не равен $period,
        // и условие $slice_sum !== null уже покрывает это.
        // Упрощенная версия:
        if ($slice_sum !== null) { // Если сумма валидна (не было нечисловых) и цикл $j завершился
            if ($valid_points_in_slice === $period) { // И мы действительно просуммировали нужное количество точек
                $sma_output[] = $slice_sum / $period;
            } else {
                // Этого не должно произойти, если $slice_sum !== null, так как break должен был сработать.
                // Но на всякий случай:
                $sma_output[] = null;
            }
        } else {
            $sma_output[] = null;
        }
    }
    return $sma_output;
}

function calculate_ema(array $data, int $period): array|false {
    if ($period <= 0) { /* log_indicator_error(__FUNCTION__, "Invalid period {$period}."); */ return false; }
    $data_count = count($data);
    if ($data_count < $period) { return false; }

    $ema_values = array_fill(0, $data_count, null);
    $multiplier = 2 / ($period + 1);

    $initial_slice_sum = 0;
    $valid_initial_points = 0;
    for ($k = 0; $k < $period; $k++) {
        if (!isset($data[$k]) || !is_numeric($data[$k])) {
            // log_indicator_error(__FUNCTION__, "Non-numeric data in initial slice for EMA period {$period} at index {$k}.");
            if ($k < $period -1 && $data_count - ($k+1) < $period - ($k+1) ) return $ema_values;
            continue;
        }
        $initial_slice_sum += $data[$k];
        $valid_initial_points++;
    }

    if ($valid_initial_points < 1) {
        return $ema_values;
    }

    $actual_initial_period = $valid_initial_points > 0 ? $valid_initial_points : $period;
    if ($actual_initial_period == 0) return $ema_values;

    $current_ema = $initial_slice_sum / $actual_initial_period;

    $first_ema_idx = -1;
    for($k_find=0; $k_find < $period; $k_find++){ if(is_numeric($data[$k_find])) {$first_ema_idx = $period-1; break;} }
    if($first_ema_idx === -1) $first_ema_idx = $period-1;


    $ema_values[$first_ema_idx] = $current_ema;


    for ($i = $first_ema_idx + 1; $i < $data_count; $i++) {
        if (!isset($data[$i]) || !is_numeric($data[$i])) {
            continue;
        }
        if ($current_ema === null) {
            continue;
        }
        $current_ema = ($data[$i] - $current_ema) * $multiplier + $current_ema;
        $ema_values[$i] = $current_ema;
    }
    return $ema_values;
}

function calculate_rsi(array $data, int $period = 14): array|false {
    if ($period <= 0) { /* log_indicator_error(__FUNCTION__, "Invalid period {$period}."); */ return false; }
    $data_count = count($data);
    if ($data_count < $period + 1) { return false; }

    $rsi_output = array_fill(0, $period, null);

    $changes = [];
    for ($i = 1; $i < $data_count; $i++) {
        if (!isset($data[$i], $data[$i-1]) || !is_numeric($data[$i]) || !is_numeric($data[$i-1])) {
            $changes[] = null;
            continue;
        }
        $changes[] = $data[$i] - $data[$i-1];
    }

    if (count($changes) < $period) { return false; }

    $avg_gain = 0.0; $avg_loss = 0.0;
    $valid_initial_changes = 0;
    for ($k = 0; $k < $period; $k++) {
        if ($changes[$k] === null) {
            continue;
        }
        if ($changes[$k] > 0) $avg_gain += $changes[$k];
        else $avg_loss += abs($changes[$k]);
        $valid_initial_changes++;
    }

    if ($valid_initial_changes < 1) {
        array_push($rsi_output, ...array_fill(0, count($changes) - $period +1 , null)); // +1 because rsi_output already has $period nulls
        return $rsi_output;
    }

    $actual_initial_period_for_avg = $valid_initial_changes > 0 ? $valid_initial_changes : $period;
    if ($actual_initial_period_for_avg == 0) {
        array_push($rsi_output, ...array_fill(0, count($changes) - $period +1, null));
        return $rsi_output;
    }

    $avg_gain /= $actual_initial_period_for_avg;
    $avg_loss /= $actual_initial_period_for_avg;

    $current_rsi = null;
    if ($avg_loss == 0) $current_rsi = 100.0;
    else { $rs = $avg_gain / $avg_loss; $current_rsi = 100.0 - (100.0 / (1.0 + $rs)); }

    $rsi_output[] = ($valid_initial_changes > 0) ? $current_rsi : null;

    for ($k = $period; $k < count($changes); $k++) {
        if ($changes[$k] === null || $current_rsi === null) {
            $rsi_output[] = null;
            $current_rsi = null;
            continue;
        }
        $current_change_val = $changes[$k];
        $current_gain = ($current_change_val > 0) ? $current_change_val : 0.0;
        $current_loss = ($current_change_val < 0) ? abs($current_change_val) : 0.0;

        $avg_gain = (($avg_gain * ($period - 1)) + $current_gain) / $period;
        $avg_loss = (($avg_loss * ($period - 1)) + $current_loss) / $period;

        if ($avg_loss == 0) $current_rsi = 100.0;
        else { $rs = $avg_gain / $avg_loss; $current_rsi = 100.0 - (100.0 / (1.0 + $rs)); }
        $rsi_output[] = $current_rsi;
    }
    return $rsi_output;
}

function calculate_macd(array $data, int $short_period = 12, int $long_period = 26, int $signal_period = 9): array|false {
    $data_count = count($data);
    if ($long_period <= $short_period || $data_count < $long_period ) {
        return false;
    }

    $ema_short_values = calculate_ema($data, $short_period);
    $ema_long_values = calculate_ema($data, $long_period);

    if ($ema_short_values === false || $ema_long_values === false) {
        return false;
    }

    $macd_line_full = array_fill(0, $data_count, null);
    $valid_macd_points_for_signal_calc = [];
    $first_valid_macd_idx_original = -1;


    for ($i = 0; $i < $data_count; $i++) {
        if (isset($ema_short_values[$i], $ema_long_values[$i]) && is_numeric($ema_short_values[$i]) && is_numeric($ema_long_values[$i])) {
            $macd_value = $ema_short_values[$i] - $ema_long_values[$i];
            $macd_line_full[$i] = $macd_value;
            if ($first_valid_macd_idx_original === -1) $first_valid_macd_idx_original = $i;
            $valid_macd_points_for_signal_calc[] = $macd_value;
        } else {
            if ($first_valid_macd_idx_original !== -1) { // If we had valid points then a null, add null to maintain structure for EMA
                $valid_macd_points_for_signal_calc[] = null;
            }
        }
    }

    if (count(array_filter($valid_macd_points_for_signal_calc, 'is_numeric')) < $signal_period) {
        return ['macd' => $macd_line_full, 'signal' => array_fill(0, $data_count, null), 'histogram' => array_fill(0, $data_count, null)];
    }

    $signal_line_raw = calculate_ema($valid_macd_points_for_signal_calc, $signal_period);
    if ($signal_line_raw === false) {
        return ['macd' => $macd_line_full, 'signal' => array_fill(0, $data_count, null), 'histogram' => array_fill(0, $data_count, null)];
    }

    $signal_line_full = array_fill(0, $data_count, null);
    $histogram_full = array_fill(0, $data_count, null);

    $raw_signal_idx = 0;
    if($first_valid_macd_idx_original !== -1){
        for ($i = $first_valid_macd_idx_original; $i < $data_count; $i++) {
            if ($macd_line_full[$i] !== null) { // Only try to place signal if MACD itself is valid
                if (isset($signal_line_raw[$raw_signal_idx]) && is_numeric($signal_line_raw[$raw_signal_idx])) {
                    $signal_line_full[$i] = $signal_line_raw[$raw_signal_idx];
                    $histogram_full[$i] = $macd_line_full[$i] - $signal_line_full[$i];
                }
                $raw_signal_idx++;
            }
            if($raw_signal_idx >= count($signal_line_raw)) break;
        }
    }
    return ['macd' => $macd_line_full, 'signal' => $signal_line_full, 'histogram' => $histogram_full];
}

function calculate_average_volume(array $volumes, int $period = 20): array|false {
    return calculate_sma($volumes, $period);
}

function calculate_true_range(array $klines): array|false {
    $data_count = count($klines);
    if ($data_count < 1) { return false; }

    $tr_values = array_fill(0, $data_count, null);

    if (isset($klines[0]['high'], $klines[0]['low']) && is_numeric($klines[0]['high']) && is_numeric($klines[0]['low'])) {
        $tr_values[0] = $klines[0]['high'] - $klines[0]['low'];
    } else {
        return false;
    }
    if ($data_count == 1) return $tr_values;

    for ($i = 1; $i < $data_count; $i++) {
        if (!isset($klines[$i]['high'], $klines[$i]['low'], $klines[$i-1]['close']) ||
            !is_numeric($klines[$i]['high']) || !is_numeric($klines[$i]['low']) || !is_numeric($klines[$i-1]['close'])) {
            continue;
        }
        $tr1 = $klines[$i]['high'] - $klines[$i]['low'];
        $tr2 = abs($klines[$i]['high'] - $klines[$i-1]['close']);
        $tr3 = abs($klines[$i]['low'] - $klines[$i-1]['close']);
        $tr_values[$i] = max($tr1, $tr2, $tr3);
    }
    return $tr_values;
}

function calculate_atr(array $klines, int $period = 14): array|false {
    if ($period <= 0) { /* log_indicator_error(__FUNCTION__, "Invalid ATR period {$period}."); */ return false; }
    $tr_values = calculate_true_range($klines);
    if ($tr_values === false) {
        return false;
    }
    // Use Wilder's Smoothing for ATR
    $data_count_tr = count($tr_values);
    if ($data_count_tr < $period) return false;
    $atr_wilder = array_fill(0, $data_count_tr, null);

    $sum_first_tr = 0; $valid_tr_initial = 0; $first_valid_tr_idx = -1;

    for ($k=0; $k < $data_count_tr; $k++){
        if(is_numeric($tr_values[$k])){
            if($first_valid_tr_idx === -1) $first_valid_tr_idx = $k;
            if($valid_tr_initial < $period && $k >= $first_valid_tr_idx && $k < $first_valid_tr_idx + $period){
                $sum_first_tr += $tr_values[$k];
                $valid_tr_initial++;
            }
        }
        if($valid_tr_initial === $period) break;
    }

    if ($valid_tr_initial < $period || $first_valid_tr_idx === -1) return false;

    $atr_wilder[$first_valid_tr_idx + $period - 1] = $sum_first_tr / $period;

    for ($i = $first_valid_tr_idx + $period; $i < $data_count_tr; $i++) {
        if (!isset($tr_values[$i]) || !is_numeric($tr_values[$i]) || $atr_wilder[$i-1] === null) {
            continue;
        }
        $atr_wilder[$i] = (($atr_wilder[$i-1] * ($period - 1)) + $tr_values[$i]) / $period;
    }
    return $atr_wilder;
}

function calculate_supertrend(array $klines, int $atr_period = 10, float $multiplier = 3.0): array|false {
    $data_count = count($klines);
    if ($data_count < $atr_period + 1) { return false; }

    $atr_values = calculate_atr($klines, $atr_period);
    if ($atr_values === false) { /* log_indicator_error(__FUNCTION__, "Failed to calculate ATR for Supertrend."); */ return false; }

    $st_line = array_fill(0, $data_count, null);
    $st_direction = array_fill(0, $data_count, 0);

    $first_valid_idx = -1;
    for($i = 0; $i < $data_count; $i++) {
        if(isset($atr_values[$i], $klines[$i]['high'], $klines[$i]['low'], $klines[$i]['close']) &&
            is_numeric($atr_values[$i]) && is_numeric($klines[$i]['high']) &&
            is_numeric($klines[$i]['low']) && is_numeric($klines[$i]['close'])) {
            $first_valid_idx = $i;
            break;
        }
    }
    if ($first_valid_idx === -1) { /* log_indicator_error(__FUNCTION__, "No valid ATR/kline data to start Supertrend."); */ return false; }

    $basic_upper_band = (($klines[$first_valid_idx]['high'] + $klines[$first_valid_idx]['low']) / 2) + ($multiplier * $atr_values[$first_valid_idx]);
    $basic_lower_band = (($klines[$first_valid_idx]['high'] + $klines[$first_valid_idx]['low']) / 2) - ($multiplier * $atr_values[$first_valid_idx]);

    if ($klines[$first_valid_idx]['close'] > $basic_lower_band) {
        $st_line[$first_valid_idx] = $basic_lower_band;
        $st_direction[$first_valid_idx] = 1;
    } else {
        $st_line[$first_valid_idx] = $basic_upper_band;
        $st_direction[$first_valid_idx] = -1;
    }

    for ($i = $first_valid_idx + 1; $i < $data_count; $i++) {
        if (!isset($klines[$i]['high'], $klines[$i]['low'], $klines[$i]['close'], $klines[$i-1]['close'], $atr_values[$i]) ||
            !is_numeric($klines[$i]['high']) || !is_numeric($klines[$i]['low']) || !is_numeric($klines[$i]['close']) ||
            !is_numeric($klines[$i-1]['close']) || !is_numeric($atr_values[$i]) || $st_line[$i-1] === null ) {
            continue;
        }

        $hl2 = ($klines[$i]['high'] + $klines[$i]['low']) / 2;
        $current_upper_band = $hl2 + ($multiplier * $atr_values[$i]);
        $current_lower_band = $hl2 - ($multiplier * $atr_values[$i]);

        $prev_st_val = $st_line[$i-1];
        $prev_st_dir = $st_direction[$i-1];

        if ($prev_st_dir == 1) {
            $st_line[$i] = max($current_lower_band, $prev_st_val);
        } else {
            $st_line[$i] = min($current_upper_band, $prev_st_val);
        }

        if ($klines[$i]['close'] > $st_line[$i]) {
            $st_direction[$i] = 1;
            if ($prev_st_dir == -1) {
                $st_line[$i] = $current_lower_band;
            }
        } elseif ($klines[$i]['close'] < $st_line[$i]) {
            $st_direction[$i] = -1;
            if ($prev_st_dir == 1) {
                $st_line[$i] = $current_upper_band;
            }
        } else {
            $st_direction[$i] = $prev_st_dir;
        }
    }
    return ['supertrend' => $st_line, 'direction' => $st_direction];
}

function calculate_bollinger_bands(array $data, int $period = 20, float $std_dev_multiplier = 2.0): array|false {
    if ($period <= 0 || $std_dev_multiplier <= 0) { /* log_indicator_error(__FUNCTION__, "Invalid params."); */ return false; }
    $data_count = count($data);
    if ($data_count < $period) { return false; }

    $middle_band = calculate_sma($data, $period);
    if ($middle_band === false) { /* log_indicator_error(__FUNCTION__, "Failed to calculate SMA for BB."); */ return false; }

    $upper_band = array_fill(0, $data_count, null);
    $lower_band = array_fill(0, $data_count, null);

    for ($i = $period - 1; $i < $data_count; $i++) {
        if ($middle_band[$i] === null) continue;

        $slice_for_std_dev_sum_sq_diff = 0;
        $valid_points_in_slice = 0;
        for ($j = 0; $j < $period; $j++) {
            $idx_to_check = $i - $j;
            if(isset($data[$idx_to_check]) && is_numeric($data[$idx_to_check])) {
                $slice_for_std_dev_sum_sq_diff += pow($data[$idx_to_check] - $middle_band[$i], 2);
                $valid_points_in_slice++;
            } else {
                $slice_for_std_dev_sum_sq_diff = null;
                break;
            }
        }

        if ($slice_for_std_dev_sum_sq_diff !== null && $valid_points_in_slice === $period && $period > 0) {
            $std_dev = sqrt($slice_for_std_dev_sum_sq_diff / $period);
            $upper_band[$i] = $middle_band[$i] + ($std_dev_multiplier * $std_dev);
            $lower_band[$i] = $middle_band[$i] - ($std_dev_multiplier * $std_dev);
        }
    }
    return ['upper' => $upper_band, 'middle' => $middle_band, 'lower' => $lower_band];
}

function calculate_keltner_channels(array $klines, int $ema_period = 20, int $atr_period = 10, float $atr_multiplier = 2.0): array|false {
    $data_count = count($klines);
    if ($data_count < max($ema_period, $atr_period +1)) { return false; }

    $typical_prices = [];
    foreach ($klines as $k_idx => $k) {
        if (!isset($k['high'], $k['low'], $k['close']) || !is_numeric($k['high']) || !is_numeric($k['low']) || !is_numeric($k['close'])) {
            $typical_prices[] = null;
        } else {
            $typical_prices[] = ($k['high'] + $k['low'] + $k['close']) / 3;
        }
    }

    $middle_band_ema = calculate_ema($typical_prices, $ema_period);
    $atr_values = calculate_atr($klines, $atr_period);

    if ($middle_band_ema === false || $atr_values === false) {
        return false;
    }

    $upper_band = array_fill(0, $data_count, null);
    $lower_band = array_fill(0, $data_count, null);
    $middle_band_aligned = array_fill(0, $data_count, null);


    for ($i = 0; $i < $data_count; $i++) {
        if (isset($middle_band_ema[$i], $atr_values[$i]) && is_numeric($middle_band_ema[$i]) && is_numeric($atr_values[$i])) {
            $upper_band[$i] = $middle_band_ema[$i] + ($atr_values[$i] * $atr_multiplier);
            $middle_band_aligned[$i] = $middle_band_ema[$i];
            $lower_band[$i] = $middle_band_ema[$i] - ($atr_values[$i] * $atr_multiplier);
        }
    }
    return ['upper' => $upper_band, 'middle' => $middle_band_aligned, 'lower' => $lower_band];
}

function find_pivot_points(array $klines, int $pivot_lookback = 2, $strict_inequality = true): array {
    $high_pivots = []; $low_pivots = []; $count = count($klines);
    if ($pivot_lookback <=0) { /* log_indicator_error(__FUNCTION__, "Pivot lookback must be > 0."); */ return ['highs' => [], 'lows' => []];}
    if ($count < (2 * $pivot_lookback + 1)) { return ['highs' => [], 'lows' => []]; }

    for ($i = $pivot_lookback; $i < $count - $pivot_lookback; $i++) {
        if (!isset($klines[$i]['high'], $klines[$i]['low'], $klines[$i]['timestamp']) || !is_numeric($klines[$i]['high']) || !is_numeric($klines[$i]['low'])) {
            continue;
        }
        $current_high = $klines[$i]['high']; $current_low = $klines[$i]['low'];

        $is_pivot_high = true;
        for ($j = 1; $j <= $pivot_lookback; $j++) {
            if (!isset($klines[$i - $j]['high']) || !is_numeric($klines[$i - $j]['high']) ||
                !isset($klines[$i + $j]['high']) || !is_numeric($klines[$i + $j]['high'])) {
                $is_pivot_high = false; break;
            }
            if ($strict_inequality) {
                if ($klines[$i - $j]['high'] >= $current_high || $klines[$i + $j]['high'] >= $current_high) {
                    $is_pivot_high = false; break;
                }
            } else {
                if ($klines[$i - $j]['high'] > $current_high || $klines[$i + $j]['high'] > $current_high) {
                    $is_pivot_high = false; break;
                }
            }
        }
        if ($is_pivot_high) { $high_pivots[$klines[$i]['timestamp']] = $current_high; }

        $is_pivot_low = true;
        for ($j = 1; $j <= $pivot_lookback; $j++) {
            if (!isset($klines[$i - $j]['low']) || !is_numeric($klines[$i - $j]['low']) ||
                !isset($klines[$i + $j]['low']) || !is_numeric($klines[$i + $j]['low'])) {
                $is_pivot_low = false; break;
            }
            if ($strict_inequality) {
                if ($klines[$i - $j]['low'] <= $current_low || $klines[$i + $j]['low'] <= $current_low) {
                    $is_pivot_low = false; break;
                }
            } else {
                if ($klines[$i - $j]['low'] < $current_low || $klines[$i + $j]['low'] < $current_low) {
                    $is_pivot_low = false; break;
                }
            }
        }
        if ($is_pivot_low) { $low_pivots[$klines[$i]['timestamp']] = $current_low; }
    }
    return ['highs' => $high_pivots, 'lows' => $low_pivots];
}

function calculate_fibo_retracement_levels(float $start_price, float $end_price, bool $is_uptrend_impulse): array|false {
    $fibo_ratios = [0.0, 23.6, 38.2, 50.0, 61.8, 78.6, 100.0];
    $fibo_levels = [];
    $price_diff = $end_price - $start_price;

    if (abs($price_diff) < 0.0000001) {
        return false;
    }

    $abs_price_diff = abs($price_diff);
    foreach ($fibo_ratios as $ratio_percent) {
        $ratio = $ratio_percent / 100.0;
        if ($is_uptrend_impulse) {
            $fibo_levels[number_format($ratio_percent, 1)] = $end_price - ($abs_price_diff * $ratio);
        } else {
            $fibo_levels[number_format($ratio_percent, 1)] = $end_price + ($abs_price_diff * $ratio);
        }
    }
    return $fibo_levels;
}

function calculate_stoch_rsi(array $rsi_values, int $stoch_period = 14, int $k_period = 3, int $d_period = 3): array|false {
    $rsi_count = count($rsi_values);
    if ($rsi_count < $stoch_period) {
        return false;
    }

    $stoch_rsi_raw = array_fill(0, $rsi_count, null);

    for ($i = $stoch_period - 1; $i < $rsi_count; $i++) {
        if ($rsi_values[$i] === null) continue;

        $slice_rsi_numeric = [];
        $can_calculate_slice = true;
        for ($j=0; $j < $stoch_period; $j++){
            if(!isset($rsi_values[$i-$j]) || !is_numeric($rsi_values[$i-$j])){
                $can_calculate_slice = false;
                break;
            }
            $slice_rsi_numeric[] = $rsi_values[$i-$j];
        }
        if(!$can_calculate_slice || count($slice_rsi_numeric) !== $stoch_period) continue;

        $lowest_rsi_in_period = min($slice_rsi_numeric);
        $highest_rsi_in_period = max($slice_rsi_numeric);
        $current_rsi_val = $rsi_values[$i];

        if (($highest_rsi_in_period - $lowest_rsi_in_period) == 0) {
            $stoch_rsi_raw[$i] = 50.0;
        } else {
            $stoch_rsi_raw[$i] = (($current_rsi_val - $lowest_rsi_in_period) / ($highest_rsi_in_period - $lowest_rsi_in_period)) * 100;
        }
    }

    $k_line_full_temp = calculate_sma($stoch_rsi_raw, $k_period);
    if ($k_line_full_temp === false) $k_line_full_temp = array_fill(0, $rsi_count, null);

    $d_line_full_temp = calculate_sma($k_line_full_temp, $d_period);
    if ($d_line_full_temp === false) $d_line_full_temp = array_fill(0, $rsi_count, null);

    $k_line_final = array_slice(array_pad($k_line_full_temp, $rsi_count, null),0,$rsi_count);
    $d_line_final = array_slice(array_pad($d_line_full_temp, $rsi_count, null),0,$rsi_count);

    return ['k' => $k_line_final, 'd' => $d_line_final];
}

function calculate_ema_wilder(array $data, int $period): array|false {
    if ($period <= 0) return false;
    $data_count = count($data);
    if ($data_count == 0) return [];

    $smoothed_values = array_fill(0, $data_count, null);

    $first_valid_data_idx = -1;
    for($k_find_valid=0; $k_find_valid < $data_count; $k_find_valid++) {
        if(isset($data[$k_find_valid]) && is_numeric($data[$k_find_valid])) {
            $first_valid_data_idx = $k_find_valid;
            break;
        }
    }
    if($first_valid_data_idx === -1) return $smoothed_values;

    if ($data_count < $first_valid_data_idx + $period) return $smoothed_values;


    $initial_sum = 0;
    $valid_points_for_initial_sma = 0;
    for ($k_sum = 0; $k_sum < $period; $k_sum++) {
        $current_idx_for_sum = $first_valid_data_idx + $k_sum;
        if ($current_idx_for_sum >= $data_count) break;
        if (isset($data[$current_idx_for_sum]) && is_numeric($data[$current_idx_for_sum])) {
            $initial_sum += $data[$current_idx_for_sum];
            $valid_points_for_initial_sma++;
        } else { // If even one point in the initial sum window is bad, we can't start SMA
            return $smoothed_values; // Or handle more gracefully
        }
    }

    if ($valid_points_for_initial_sma < $period) return $smoothed_values;

    $first_smoothed_value_idx = $first_valid_data_idx + $period - 1;
    if ($first_smoothed_value_idx >= $data_count) return $smoothed_values;


    $smoothed_values[$first_smoothed_value_idx] = $initial_sum / $period; // First value is SMA

    for ($i = $first_smoothed_value_idx + 1; $i < $data_count; $i++) {
        if (isset($data[$i]) && is_numeric($data[$i]) && $smoothed_values[$i-1] !== null) {
            $smoothed_values[$i] = (($smoothed_values[$i-1] * ($period - 1)) + $data[$i]) / $period;
        }  else {
            $smoothed_values[$i] = null;
        }
    }
    return $smoothed_values;
}

function calculate_adx_di(array $klines, int $period = 14): array|false {
    $data_count = count($klines);
    if ($data_count < $period * 2) {
        return false;
    }

    $highs = array_column($klines, 'high');
    $lows = array_column($klines, 'low');

    $true_range_values = calculate_true_range($klines);
    if ($true_range_values === false) { return false; }

    $plus_dm_values = array_fill(0, $data_count, 0.0);
    $minus_dm_values = array_fill(0, $data_count, 0.0);

    for ($i = 1; $i < $data_count; $i++) {
        if (!isset($highs[$i], $highs[$i-1], $lows[$i], $lows[$i-1]) ||
            !is_numeric($highs[$i]) || !is_numeric($highs[$i-1]) ||
            !is_numeric($lows[$i]) || !is_numeric($lows[$i-1])) {
            $plus_dm_values[$i] = null; $minus_dm_values[$i] = null; // Mark as null if source data is bad
            continue;
        }
        $up_move = $highs[$i] - $highs[$i-1];
        $down_move = $lows[$i-1] - $lows[$i];

        if ($up_move > $down_move && $up_move > 0) $plus_dm_values[$i] = $up_move;
        else $plus_dm_values[$i] = 0.0;

        if ($down_move > $up_move && $down_move > 0) $minus_dm_values[$i] = $down_move;
        else $minus_dm_values[$i] = 0.0;
    }

    $smoothed_true_range = calculate_ema_wilder($true_range_values, $period);
    $smoothed_plus_dm = calculate_ema_wilder($plus_dm_values, $period);
    $smoothed_minus_dm = calculate_ema_wilder($minus_dm_values, $period);

    if($smoothed_true_range === false || $smoothed_plus_dm === false || $smoothed_minus_dm === false){
        return false;
    }

    $plus_di_values = array_fill(0, $data_count, null);
    $minus_di_values = array_fill(0, $data_count, null);
    $dx_values = array_fill(0, $data_count, null);

    for ($i = 0; $i < $data_count; $i++) {
        if (isset($smoothed_true_range[$i], $smoothed_plus_dm[$i], $smoothed_minus_dm[$i]) &&
            is_numeric($smoothed_true_range[$i]) && $smoothed_true_range[$i] != 0 &&
            is_numeric($smoothed_plus_dm[$i]) && is_numeric($smoothed_minus_dm[$i])) {

            $plus_di_values[$i] = ($smoothed_plus_dm[$i] / $smoothed_true_range[$i]) * 100;
            $minus_di_values[$i] = ($smoothed_minus_dm[$i] / $smoothed_true_range[$i]) * 100;

            if (($plus_di_values[$i] + $minus_di_values[$i]) != 0) {
                $dx_values[$i] = (abs($plus_di_values[$i] - $minus_di_values[$i]) / ($plus_di_values[$i] + $minus_di_values[$i])) * 100;
            } else {
                $dx_values[$i] = 0.0;
            }
        }
    }

    $adx_line = calculate_ema_wilder($dx_values, $period);
    if($adx_line === false) $adx_line = array_fill(0, $data_count, null);

    return ['adx' => $adx_line, 'plus_di' => $plus_di_values, 'minus_di' => $minus_di_values];
}
?>