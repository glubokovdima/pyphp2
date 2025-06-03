<?php
// /market-analyzer/strategies/common_functions.php

function log_indicator_error($function_name, $message) {
    // In a real app, this might write to a specific indicator log or use a more robust logging system.
    // For now, using error_log for simplicity, assuming it's configured.
    error_log("Indicator Error in {$function_name}: {$message}");
}

function calculate_sma(array $data, int $period): array|false {
    if ($period <= 0) { log_indicator_error(__FUNCTION__, "Invalid period {$period}."); return false; }
    $data_count = count($data);
    if ($data_count < $period) { /* Not an error, just insufficient data */ return false; }

    $sma_output = array_fill(0, $period - 1, null); // Fill initial points with null
    for ($i = $period - 1; $i < $data_count; $i++) {
        $slice_sum = 0;
        for ($j = 0; $j < $period; $j++) {
            $idx_to_check = $i - $j;
            if (!isset($data[$idx_to_check]) || !is_numeric($data[$idx_to_check])) {
                // If any point in slice is non-numeric, this SMA point is invalid
                $slice_sum = null;
                break;
            }
            $slice_sum += $data[$idx_to_check];
        }
        $sma_output[] = ($slice_sum !== null) ? ($slice_sum / $period) : null;
    }
    return $sma_output;
}

function calculate_ema(array $data, int $period): array|false {
    if ($period <= 0) { log_indicator_error(__FUNCTION__, "Invalid period {$period}."); return false; }
    $data_count = count($data);
    if ($data_count < $period) { return false; }

    $ema_values = array_fill(0, $data_count, null);
    $multiplier = 2 / ($period + 1);

    // Calculate initial SMA for the first EMA value
    $initial_slice_sum = 0;
    for ($k = 0; $k < $period; $k++) {
        if (!isset($data[$k]) || !is_numeric($data[$k])) {
            log_indicator_error(__FUNCTION__, "Non-numeric data in initial slice for EMA period {$period} at index {$k}.");
            return $ema_values; // Return partially filled array or false
        }
        $initial_slice_sum += $data[$k];
    }

    $current_ema = $initial_slice_sum / $period;
    $ema_values[$period - 1] = $current_ema;

    for ($i = $period; $i < $data_count; $i++) {
        if (!isset($data[$i]) || !is_numeric($data[$i])) {
            // If current data point is invalid, cannot calculate further EMAs
            log_indicator_error(__FUNCTION__, "Non-numeric data at index {$i} for EMA period {$period}.");
            return $ema_values; // Return what has been calculated so far
        }
        // EMA = (Current Price - Previous EMA) * Multiplier + Previous EMA
        $current_ema = ($data[$i] - $current_ema) * $multiplier + $current_ema;
        $ema_values[$i] = $current_ema;
    }
    return $ema_values;
}

function calculate_rsi(array $data, int $period = 14): array|false {
    if ($period <= 0) { log_indicator_error(__FUNCTION__, "Invalid period {$period}."); return false; }
    $data_count = count($data);
    if ($data_count < $period + 1) { return false; }

    $rsi_output = array_fill(0, $period, null); // RSI needs $period changes, so $period+1 prices

    $changes = [];
    for ($i = 1; $i < $data_count; $i++) {
        if (!isset($data[$i], $data[$i-1]) || !is_numeric($data[$i]) || !is_numeric($data[$i-1])) {
            log_indicator_error(__FUNCTION__, "Non-numeric data for calculating price changes around index " . ($i-1) . ".");
            return false; // Cannot proceed if prices are invalid
        }
        $changes[] = $data[$i] - $data[$i-1];
    }

    if (count($changes) < $period) { return false; } // Not enough changes calculated

    $avg_gain = 0.0; $avg_loss = 0.0;
    for ($k = 0; $k < $period; $k++) {
        if ($changes[$k] > 0) $avg_gain += $changes[$k];
        else $avg_loss += abs($changes[$k]);
    }
    $avg_gain /= $period;
    $avg_loss /= $period;

    if ($avg_loss == 0) $current_rsi = 100.0;
    else { $rs = $avg_gain / $avg_loss; $current_rsi = 100.0 - (100.0 / (1.0 + $rs)); }
    $rsi_output[] = $current_rsi; // This is for data_count[$period] (i.e., index $period)

    for ($k = $period; $k < count($changes); $k++) {
        $current_change = $changes[$k];
        $current_gain = ($current_change > 0) ? $current_change : 0.0;
        $current_loss = ($current_change < 0) ? abs($current_change) : 0.0;

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
    if ($long_period <= $short_period || $data_count < $long_period + $signal_period -1 ) {
        log_indicator_error(__FUNCTION__, "Invalid periods or insufficient data. Data:{$data_count}, Need:" .($long_period + $signal_period -1));
        return false;
    }

    $ema_short_values = calculate_ema($data, $short_period);
    $ema_long_values = calculate_ema($data, $long_period);

    if ($ema_short_values === false || $ema_long_values === false) {
        log_indicator_error(__FUNCTION__, "Failed to calculate underlying EMAs for MACD.");
        return false;
    }

    $macd_line_full = array_fill(0, $data_count, null);
    $valid_macd_points_for_signal_calc = [];

    for ($i = $long_period - 1; $i < $data_count; $i++) {
        if (isset($ema_short_values[$i], $ema_long_values[$i]) && is_numeric($ema_short_values[$i]) && is_numeric($ema_long_values[$i])) {
            $macd_value = $ema_short_values[$i] - $ema_long_values[$i];
            $macd_line_full[$i] = $macd_value;
            $valid_macd_points_for_signal_calc[] = $macd_value; // Collect only valid points for signal line EMA
        } else {
            // If EMAs are null here, MACD line also becomes null
            // No need to add to valid_macd_points_for_signal_calc if underlying EMAs are null
        }
    }

    if (count($valid_macd_points_for_signal_calc) < $signal_period) {
        log_indicator_error(__FUNCTION__, "Not enough valid MACD points (" . count($valid_macd_points_for_signal_calc) . ") to calculate Signal Line of period {$signal_period}.");
        // Still return MACD line if it was partially calculable
        return ['macd' => $macd_line_full, 'signal' => array_fill(0, $data_count, null), 'histogram' => array_fill(0, $data_count, null)];
    }

    $signal_line_raw = calculate_ema($valid_macd_points_for_signal_calc, $signal_period);
    if ($signal_line_raw === false) {
        log_indicator_error(__FUNCTION__, "Failed to calculate Signal Line EMA.");
        return ['macd' => $macd_line_full, 'signal' => array_fill(0, $data_count, null), 'histogram' => array_fill(0, $data_count, null)];
    }

    $signal_line_full = array_fill(0, $data_count, null);
    $histogram_full = array_fill(0, $data_count, null);

    // Align signal line with the main data array
    // Signal line starts after (long_period - 1) for MACD points, then (signal_period - 1) for EMA of MACD
    $signal_line_start_index_in_main_array = ($long_period - 1) + ($signal_period - 1);
    $raw_signal_idx = 0;

    for ($i_main = $signal_line_start_index_in_main_array; $i_main < $data_count; $i_main++) {
        // We need to find the corresponding MACD point that was used for this signal calculation step.
        // This is tricky because calculate_ema on `valid_macd_points_for_signal_calc` doesn't know original indices.
        // The signal line values in $signal_line_raw correspond to the $signal_period-th value of `valid_macd_points_for_signal_calc` onwards.

        // Find the index in $signal_line_raw that corresponds to $macd_line_full[$i_main]
        // This alignment is complex. A simpler way is to iterate $signal_line_raw and place its values
        // starting from the first point where MACD itself was valid and enough points existed for signal EMA.

        if ($macd_line_full[$i_main] !== null) { // Only calculate signal if corresponding MACD is valid
            if (isset($signal_line_raw[$raw_signal_idx]) && is_numeric($signal_line_raw[$raw_signal_idx])) {
                // Need to find the correct index in signal_line_raw.
                // The $raw_signal_idx should start from where calculate_ema($valid_macd_points_for_signal_calc, $signal_period)
                // produces its first non-null value, which is at index $signal_period - 1 of that array.
                $current_signal_value_index = $raw_signal_idx + ($signal_period -1);
                if (isset($signal_line_raw[$current_signal_value_index]) && is_numeric($signal_line_raw[$current_signal_value_index])) {
                    $signal_line_full[$i_main] = $signal_line_raw[$current_signal_value_index];
                    $histogram_full[$i_main] = $macd_line_full[$i_main] - $signal_line_full[$i_main];
                }

            }
            $raw_signal_idx++; // Increment for next valid MACD point
        }
    }
    // A more direct alignment for signal line:
    // The signal line is an EMA of the MACD line. The first value of the signal line
    // corresponds to the MACD value at index (long_period - 1 + signal_period - 1).
    $j = 0; // Index for signal_line_raw
    for ($i = ($long_period - 1) + ($signal_period - 1); $i < $data_count; $i++) {
        if (isset($macd_line_full[$i]) && is_numeric($macd_line_full[$i])) { // Ensure corresponding MACD is valid
            if (isset($signal_line_raw[$j + ($signal_period-1)]) && is_numeric($signal_line_raw[$j + ($signal_period-1)])) {
                $signal_line_full[$i] = $signal_line_raw[$j + ($signal_period-1)];
                $histogram_full[$i] = $macd_line_full[$i] - $signal_line_full[$i];
            }
            $j++;
        } else {
            // If MACD is null, signal and histogram are also null for this point
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

    // First TR is just High - Low
    if (isset($klines[0]['high'], $klines[0]['low']) && is_numeric($klines[0]['high']) && is_numeric($klines[0]['low'])) {
        $tr_values[0] = $klines[0]['high'] - $klines[0]['low'];
    } else {
        log_indicator_error(__FUNCTION__, "Invalid kline data at index 0 for TR.");
        return false; // Cannot calculate if first kline is bad
    }
    if ($data_count == 1) return $tr_values;


    for ($i = 1; $i < $data_count; $i++) {
        if (!isset($klines[$i]['high'], $klines[$i]['low'], $klines[$i-1]['close']) ||
            !is_numeric($klines[$i]['high']) || !is_numeric($klines[$i]['low']) || !is_numeric($klines[$i-1]['close'])) {
            log_indicator_error(__FUNCTION__, "Invalid kline data around index {$i} for TR.");
            // Continue calculating if possible, TR for this point will be null
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
    if ($period <= 0) { log_indicator_error(__FUNCTION__, "Invalid ATR period {$period}."); return false; }
    $tr_values = calculate_true_range($klines);
    if ($tr_values === false) {
        log_indicator_error(__FUNCTION__, "Failed to calculate True Range for ATR.");
        return false;
    }
    // ATR is an EMA of TR values
    return calculate_ema($tr_values, $period);
}

function calculate_supertrend(array $klines, int $atr_period = 10, float $multiplier = 3.0): array|false {
    $data_count = count($klines);
    if ($data_count < $atr_period + 1) { return false; } // Need enough data for ATR and then ST calc

    $atr_values = calculate_atr($klines, $atr_period);
    if ($atr_values === false) { log_indicator_error(__FUNCTION__, "Failed to calculate ATR for Supertrend."); return false; }

    $st_line = array_fill(0, $data_count, null);
    $st_direction = array_fill(0, $data_count, 0); // 0: undecided, 1: up, -1: down

    // Find first valid ATR to start calculation
    $first_valid_atr_idx = -1;
    for($i = 0; $i < $data_count; $i++) {
        if(isset($atr_values[$i]) && is_numeric($atr_values[$i])) {
            $first_valid_atr_idx = $i;
            break;
        }
    }
    if ($first_valid_atr_idx === -1) { log_indicator_error(__FUNCTION__, "No valid ATR values found for Supertrend."); return false; }
    if (!isset($klines[$first_valid_atr_idx]['high'], $klines[$first_valid_atr_idx]['low'], $klines[$first_valid_atr_idx]['close'])) {
        log_indicator_Error(__FUNCTION__, "Missing kline data at first valid ATR index {$first_valid_atr_idx}"); return false;
    }


    // Initial SuperTrend value and direction
    $basic_upper_band = (($klines[$first_valid_atr_idx]['high'] + $klines[$first_valid_atr_idx]['low']) / 2) + ($multiplier * $atr_values[$first_valid_atr_idx]);
    $basic_lower_band = (($klines[$first_valid_atr_idx]['high'] + $klines[$first_valid_atr_idx]['low']) / 2) - ($multiplier * $atr_values[$first_valid_atr_idx]);

    // Initialize first ST value (typically based on close compared to initial bands)
    // This part is simplified; some ST versions have more complex initial state.
    // Let's assume trend is initially up if close > prev ST, else down.
    // For the very first point, we can't use previous ST.
    // A common way: if close > (High+Low)/2, assume initial uptrend for calculation.
    if ($klines[$first_valid_atr_idx]['close'] > $basic_lower_band) { // initial bullish bias if close above lower band
        $st_line[$first_valid_atr_idx] = $basic_lower_band;
        $st_direction[$first_valid_atr_idx] = 1;
    } else {
        $st_line[$first_valid_atr_idx] = $basic_upper_band;
        $st_direction[$first_valid_atr_idx] = -1;
    }


    for ($i = $first_valid_atr_idx + 1; $i < $data_count; $i++) {
        if (!isset($klines[$i]['high'], $klines[$i]['low'], $klines[$i]['close'], $klines[$i-1]['close'], $atr_values[$i]) ||
            !is_numeric($klines[$i]['high']) || !is_numeric($klines[$i]['low']) || !is_numeric($klines[$i]['close']) ||
            !is_numeric($klines[$i-1]['close']) || !is_numeric($atr_values[$i]) || $st_line[$i-1] === null ) {
            // Cannot calculate if current kline, prev close, ATR or prev ST is invalid
            continue;
        }

        $hl2 = ($klines[$i]['high'] + $klines[$i]['low']) / 2;
        $current_upper_band = $hl2 + ($multiplier * $atr_values[$i]);
        $current_lower_band = $hl2 - ($multiplier * $atr_values[$i]);

        $prev_st_val = $st_line[$i-1];
        $prev_st_dir = $st_direction[$i-1];

        // Determine current ST line value
        if ($prev_st_dir == 1) { // Previous trend was up
            $st_line[$i] = max($current_lower_band, $prev_st_val);
        } else { // Previous trend was down
            $st_line[$i] = min($current_upper_band, $prev_st_val);
        }

        // Determine current ST direction
        if ($klines[$i]['close'] > $st_line[$i]) {
            $st_direction[$i] = 1;
            if ($prev_st_dir == -1) { // Trend changed from down to up
                $st_line[$i] = $current_lower_band; // Reset ST line to current lower band
            }
        } elseif ($klines[$i]['close'] < $st_line[$i]) {
            $st_direction[$i] = -1;
            if ($prev_st_dir == 1) { // Trend changed from up to down
                $st_line[$i] = $current_upper_band; // Reset ST line to current upper band
            }
        } else { // Close is exactly on the ST line
            $st_direction[$i] = $prev_st_dir; // Maintain previous direction
        }
    }
    return ['supertrend' => $st_line, 'direction' => $st_direction];
}


function calculate_bollinger_bands(array $data, int $period = 20, float $std_dev_multiplier = 2.0): array|false {
    if ($period <= 0 || $std_dev_multiplier <= 0) { log_indicator_error(__FUNCTION__, "Invalid params."); return false; }
    $data_count = count($data);
    if ($data_count < $period) { return false; }

    $middle_band = calculate_sma($data, $period);
    if ($middle_band === false) { log_indicator_error(__FUNCTION__, "Failed to calculate SMA for BB."); return false; }

    $upper_band = array_fill(0, $data_count, null);
    $lower_band = array_fill(0, $data_count, null);

    for ($i = $period - 1; $i < $data_count; $i++) {
        if ($middle_band[$i] === null) continue; // Cannot calculate if middle band is null

        $slice_for_std_dev_sum_sq_diff = 0;
        $valid_points_in_slice = 0;
        for ($j = 0; $j < $period; $j++) {
            $idx_to_check = $i - $j;
            if(isset($data[$idx_to_check]) && is_numeric($data[$idx_to_check])) {
                $slice_for_std_dev_sum_sq_diff += pow($data[$idx_to_check] - $middle_band[$i], 2);
                $valid_points_in_slice++;
            } else {
                $slice_for_std_dev_sum_sq_diff = null; // Mark as invalid if any point is bad
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
    if ($data_count < max($ema_period, $atr_period +1)) { return false; } // Ensure enough data for both EMA and ATR

    $typical_prices = [];
    foreach ($klines as $k_idx => $k) {
        if (!isset($k['high'], $k['low'], $k['close']) || !is_numeric($k['high']) || !is_numeric($k['low']) || !is_numeric($k['close'])) {
            log_indicator_error(__FUNCTION__, "Invalid kline data for typical price at index {$k_idx}.");
            // Fill with null to maintain array length for EMA calculation, or return false
            $typical_prices[] = null;
            // return false; // More strict: if any kline is bad, fail.
        } else {
            $typical_prices[] = ($k['high'] + $k['low'] + $k['close']) / 3;
        }
    }

    $middle_band_ema = calculate_ema($typical_prices, $ema_period);
    $atr_values = calculate_atr($klines, $atr_period);

    if ($middle_band_ema === false || $atr_values === false) {
        log_indicator_error(__FUNCTION__, "Failed to calculate EMA or ATR for Keltner Channels.");
        return false;
    }

    $upper_band = array_fill(0, $data_count, null);
    $lower_band = array_fill(0, $data_count, null);

    for ($i = 0; $i < $data_count; $i++) {
        if (isset($middle_band_ema[$i], $atr_values[$i]) && is_numeric($middle_band_ema[$i]) && is_numeric($atr_values[$i])) {
            $upper_band[$i] = $middle_band_ema[$i] + ($atr_values[$i] * $atr_multiplier);
            $lower_band[$i] = $middle_band_ema[$i] - ($atr_values[$i] * $atr_multiplier);
        }
    }
    return ['upper' => $upper_band, 'middle' => $middle_band_ema, 'lower' => $lower_band];
}

function find_pivot_points(array $klines, int $pivot_lookback = 2, $strict_inequality = true): array {
    $high_pivots = []; $low_pivots = []; $count = count($klines);
    if ($pivot_lookback <=0) { log_indicator_error(__FUNCTION__, "Pivot lookback must be > 0."); return ['highs' => [], 'lows' => []];}
    if ($count < (2 * $pivot_lookback + 1)) { return ['highs' => [], 'lows' => []]; } // Not enough data

    for ($i = $pivot_lookback; $i < $count - $pivot_lookback; $i++) {
        if (!isset($klines[$i]['high'], $klines[$i]['low'], $klines[$i]['timestamp']) || !is_numeric($klines[$i]['high']) || !is_numeric($klines[$i]['low'])) {
            continue; // Skip if current candle data is invalid
        }
        $current_high = $klines[$i]['high']; $current_low = $klines[$i]['low'];

        $is_pivot_high = true;
        for ($j = 1; $j <= $pivot_lookback; $j++) {
            if (!isset($klines[$i - $j]['high']) || !is_numeric($klines[$i - $j]['high']) ||
                !isset($klines[$i + $j]['high']) || !is_numeric($klines[$i + $j]['high'])) {
                $is_pivot_high = false; break; // Invalid data in lookback window
            }
            if ($strict_inequality) {
                if ($klines[$i - $j]['high'] >= $current_high || $klines[$i + $j]['high'] >= $current_high) {
                    $is_pivot_high = false; break;
                }
            } else { // Allow equals, but not if both sides are equal and higher than something else in between. Simpler: if any is GREATER, then not pivot.
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
                $is_pivot_low = false; break; // Invalid data in lookback window
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
    // Standard Fibonacci levels
    $fibo_ratios = [0.0, 23.6, 38.2, 50.0, 61.8, 78.6, 100.0]; // 0% and 100% are start/end of impulse
    // Optional extension levels: 127.2, 161.8, 261.8 etc. Not included here for retracement.

    $fibo_levels = [];
    $price_diff = $end_price - $start_price;

    if (abs($price_diff) < 0.0000001) { // Effectively zero difference
        log_indicator_error(__FUNCTION__, "Start and end price for Fibonacci are virtually identical.");
        return false;
    }

    foreach ($fibo_ratios as $ratio_percent) {
        $ratio = $ratio_percent / 100.0;
        if ($is_uptrend_impulse) { // Impulse was up, retracement is down
            $fibo_levels[number_format($ratio_percent, 1)] = $end_price - ($price_diff * $ratio);
        } else { // Impulse was down, retracement is up
            $fibo_levels[number_format($ratio_percent, 1)] = $end_price - ($price_diff * $ratio); // Still end_price - (diff * ratio) because diff is negative
        }
    }
    // For uptrend: High - (High-Low)*Ratio
    // For downtrend: Low + (High-Low)*Ratio
    // The above formula is simplified. Let's fix:
    $abs_price_diff = abs($price_diff);
    $fibo_levels = []; // Reset
    foreach ($fibo_ratios as $ratio_percent) {
        $ratio = $ratio_percent / 100.0;
        if ($is_uptrend_impulse) {
            $fibo_levels[number_format($ratio_percent, 1)] = $end_price - ($abs_price_diff * $ratio);
        } else { // Downtrend impulse (start_price > end_price)
            $fibo_levels[number_format($ratio_percent, 1)] = $end_price + ($abs_price_diff * $ratio);
        }
    }
    return $fibo_levels;
}

?>