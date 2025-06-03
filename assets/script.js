$(document).ready(function () {
    const $runManualAnalysisButton = $('#runManualAnalysis');
    const $runManualAnalysisButtonText = $('#runManualAnalysisButtonText');
    const $runManualAnalysisButtonSpinner = $('#runManualAnalysisButtonSpinner');

    const $refreshOverviewButton = $('#refreshOverview');
    const $refreshOverviewButtonText = $('#refreshOverviewButtonText');
    const $refreshOverviewButtonSpinner = $('#refreshOverviewButtonSpinner');

    const $statusMessagesContainer = $('#statusMessagesContainer');
    const $marketOverviewTable = $('#marketOverviewTable');
    const $marketOverviewTbody = $marketOverviewTable.find('tbody');
    const $noOverviewDataP = $('#noOverviewData');
    const $lastUpdatedTimestamp = $('#lastUpdatedTimestamp');

    const $rawJsonOutputDiv = $('#rawJsonOutput');
    const $rawJsonOutputPre = $rawJsonOutputDiv.find('pre');
    const $rawJsonOutputToggle = $('#rawJsonOutputToggle');

    const $confidenceFilter = $('#confidenceFilter');
    const $confidenceFilterValue = $('#confidenceFilterValue');

    let currentMarketOverviewData = window.initialMarketOverview || {};

    function updateStatus(message, type = 'info', append = true) {
        if (!append) {
            $statusMessagesContainer.empty();
        }
        let statusClass = 'status-info';
        if (type === 'error') statusClass = 'status-error';
        else if (type === 'success') statusClass = 'status-success';
        else if (type === 'loading') statusClass = 'status-loading';

        const messageDiv = $('<div>').addClass('status-message ' + statusClass).html(message);
        $statusMessagesContainer.append(messageDiv);
        $statusMessagesContainer.scrollTop($statusMessagesContainer[0].scrollHeight);
    }

    function setButtonState(buttonType, isLoading, text) {
        let $button, $buttonText, $spinner;
        if (buttonType === 'manualAnalysis') {
            $button = $runManualAnalysisButton;
            $buttonText = $runManualAnalysisButtonText;
            $spinner = $runManualAnalysisButtonSpinner;
            if (!text) text = 'Запустить анализ вручную';
        } else if (buttonType === 'refreshOverview') {
            $button = $refreshOverviewButton;
            $buttonText = $refreshOverviewButtonText;
            $spinner = $refreshOverviewButtonSpinner;
            if (!text) text = 'Обновить обзор';
        } else {
            return;
        }

        if (isLoading) {
            $button.prop('disabled', true).addClass('opacity-75 cursor-not-allowed');
            $buttonText.text('Обработка...');
            $spinner.removeClass('hidden');
        } else {
            $button.prop('disabled', false).removeClass('opacity-75 cursor-not-allowed');
            $buttonText.text(text);
            $spinner.addClass('hidden');
        }
    }

    function getSignalClass(signal) {
        if (!signal) return 'signal-NEUTRAL';
        signal = signal.toUpperCase();
        if (signal === 'BUY') return 'signal-BUY';
        if (signal === 'SELL') return 'signal-SELL';
        return 'signal-NEUTRAL'; // Covers NEUTRAL, ERROR, etc.
    }

    function getConfidenceBar(signal, confidence) {
        const confidencePercentage = Math.max(0, Math.min(100, Math.round(confidence * 100)));
        let barClass = '';
        if (signal && signal.toUpperCase() === 'BUY') barClass = 'bg-green-500';
        else if (signal && signal.toUpperCase() === 'SELL') barClass = 'bg-red-500';
        else barClass = 'bg-gray-400'; // For NEUTRAL or if confidence is shown for ERROR

        return `<div class="confidence-bar-container">
                    <div class="confidence-bar ${barClass}" style="width: ${confidencePercentage}%;"></div>
                </div>`;
    }

    function displayOverview(marketOverviewData, filterConfidence = 0) {
        $marketOverviewTbody.empty();
        let dataDisplayed = false;
        let latestTimestamp = 0;

        if (marketOverviewData && typeof marketOverviewData === 'object' && Object.keys(marketOverviewData).length > 0) {
            for (const symbol in marketOverviewData) {
                if (marketOverviewData.hasOwnProperty(symbol)) {
                    const data = marketOverviewData[symbol];

                    if (data.timestamp_utc) {
                        const currentEntryTs = new Date(data.timestamp_utc.replace(' ', 'T') + 'Z').getTime();
                        if (currentEntryTs > latestTimestamp) {
                            latestTimestamp = currentEntryTs;
                        }
                    }

                    const overallConfidence = parseFloat(data.confidence) || 0;
                    if (overallConfidence < filterConfidence && data.signal !== 'ERROR') { // Always show errors
                        continue;
                    }
                    dataDisplayed = true;

                    let tfDetailsHtml = '<ul class="list-disc list-inside pl-2 mt-1 text-gray-700">';
                    if (data.debug_strategy_results_by_tf && typeof data.debug_strategy_results_by_tf === 'object') {
                        for (const tf in data.debug_strategy_results_by_tf) {
                            const tfData = data.debug_strategy_results_by_tf[tf];
                            let tfSignalClass = getSignalClass(tfData.trend_signal);
                            // TF confidence is now -1 to 1, convert to 0-1 for display based on signal direction
                            let tfConfDisplayVal = 'N/A';
                            if (tfData.confidence !== undefined && tfData.trend_signal !== 'NEUTRAL' && tfData.trend_signal !== 'ERROR') {
                                tfConfDisplayVal = (Math.abs(parseFloat(tfData.confidence)) * 100).toFixed(0) + '%';
                            } else if (tfData.trend_signal === 'NEUTRAL' && tfData.confidence !== undefined){
                                // For neutral TF, confidence might represent 'strength of neutrality' (0-1 scale from backend)
                                tfConfDisplayVal = (parseFloat(tfData.confidence) * 100).toFixed(0) + '%';
                            }


                            let detailsCombined = [];
                            if (tfData.details && Array.isArray(tfData.details) && tfData.details.length > 0) {
                                detailsCombined = tfData.details.map(d => d.replace(/:\s*/, ': <span class="text-gray-600">') + '</span>');
                            } else if (tfData.error) {
                                detailsCombined.push(`<strong>Ошибка ТФ:</strong> ${tfData.error}`);
                            }

                            if (detailsCombined.length === 0 && tfData.trend_signal !== 'ERROR') detailsCombined.push('Нет деталей по стратегиям.');

                            let confluenceText = '';
                            if (tfData.confluence_matches && tfData.confluence_matches > 0) {
                                confluenceText = ` <span class="text-indigo-600 font-semibold">(Confluence: ${tfData.confluence_matches})</span>`;
                            }

                            tfDetailsHtml += `<li class="mb-1"><strong class="${tfSignalClass}">${tf} (${tfData.trend_signal || 'N/A'}, ${tfConfDisplayVal})${confluenceText}</strong>: ${detailsCombined.join('; ')}</li>`;
                        }
                    } else if (data.error) {
                        tfDetailsHtml += `<li><strong>Общая ошибка по символу:</strong> ${data.error}</li>`;
                    } else {
                        tfDetailsHtml += `<li>Нет данных по таймфреймам.</li>`;
                    }
                    tfDetailsHtml += '</ul>';

                    const confidenceVal = data.confidence !== undefined ? parseFloat(data.confidence).toFixed(2) : 'N/A';
                    // Confidence bar for overall signal (0-1 scale)
                    const confidenceBarHtml = data.confidence !== undefined ? getConfidenceBar(data.signal, data.confidence) : '';

                    let summaryText = data.summary || 'Нет summary';
                    if (data.extra_factors && data.extra_factors.confluence_count && data.extra_factors.confluence_count > 0) {
                        summaryText += ` <span class="text-sm font-medium text-indigo-700">[Общий Confluence: ${data.extra_factors.confluence_count} ТФ]</span>`;
                    }


                    const row = `
                        <tr data-symbol="${symbol}">
                            <td class="px-3 py-2 whitespace-nowrap">${data.symbol || symbol}</td>
                            <td class="px-3 py-2 whitespace-nowrap ${getSignalClass(data.signal)}">${data.signal || 'N/A'}</td>
                            <td class="px-3 py-2 whitespace-nowrap">${confidenceVal}${confidenceBarHtml}</td>
                            <td class="px-3 py-2 summary-cell">${summaryText}</td>
                            <td class="px-3 py-2 details-cell">${tfDetailsHtml}</td>
                        </tr>
                    `;
                    $marketOverviewTbody.append(row);
                }
            }
        }

        if (dataDisplayed) {
            $noOverviewDataP.addClass('hidden');
            $marketOverviewTable.removeClass('hidden');
        } else {
            $noOverviewDataP.removeClass('hidden').text(filterConfidence > 0 ? 'Нет данных, соответствующих фильтру уверенности.' : 'Данные обзора отсутствуют.');
            $marketOverviewTable.addClass('hidden');
        }

        if (latestTimestamp > 0) {
            $lastUpdatedTimestamp.text('Обзор от: ' + new Date(latestTimestamp).toLocaleString());
        } else {
            $lastUpdatedTimestamp.text('Обзор от: N/A');
        }


        if (marketOverviewData) {
            $rawJsonOutputPre.text(JSON.stringify(marketOverviewData, null, 2));
        } else {
            $rawJsonOutputPre.text('');
        }
    }

    $confidenceFilter.on('input', function() {
        const value = parseFloat($(this).val()).toFixed(2);
        $confidenceFilterValue.text(value);
        displayOverview(currentMarketOverviewData, parseFloat(value));
    });

    $rawJsonOutputToggle.click(function() {
        $rawJsonOutputDiv.toggleClass('hidden');
    });

    async function runFullAnalysis() {
        setButtonState('manualAnalysis', true);
        setButtonState('refreshOverview', true);
        updateStatus('Запущен полный анализ рынка вручную...', 'loading', false);

        try {
            const response = await $.ajax({
                url: window.apiEndpoints.runAnalysis,
                method: 'POST',
                dataType: 'json',
                timeout: 300000 // 5 minutes
            });

            if (response.success && response.market_overview) {
                currentMarketOverviewData = response.market_overview;
                displayOverview(currentMarketOverviewData, parseFloat($confidenceFilter.val()));
                updateStatus('Полный анализ рынка завершен. Обзор обновлен.', 'success', true);
                if(response.message) updateStatus(response.message, 'info', true);
                if(response.logs && Array.isArray(response.logs)) {
                    response.logs.forEach(logMsg => updateStatus(logMsg, 'info', true));
                }
            } else {
                throw new Error(response.message || 'Ошибка выполнения анализа на сервере.');
            }
        } catch (error) {
            let errorMsg = 'Неизвестная AJAX ошибка при запуске анализа.';
            if (error instanceof Error) {
                errorMsg = error.message;
            } else if (error.responseJSON && error.responseJSON.message) {
                errorMsg = error.responseJSON.message;
            } else if (error.statusText) {
                errorMsg = error.statusText;
                if(error.responseText && error.responseText.length < 300) errorMsg += `: ${error.responseText}`;
            }
            updateStatus(`Ошибка ручного анализа: ${errorMsg}`, 'error', true);
            console.error('Error running manual analysis:', error, error.responseText || '');
        } finally {
            setButtonState('manualAnalysis', false);
            setButtonState('refreshOverview', false);
        }
    }

    async function refreshMarketOverview() {
        setButtonState('refreshOverview', true);
        setButtonState('manualAnalysis', true);
        updateStatus('Загрузка последнего обзора рынка...', 'loading', false);

        try {
            const response = await $.ajax({
                url: window.apiEndpoints.getLatest,
                method: 'GET',
                dataType: 'json',
                data: { format: 'json', type: 'all' },
                timeout: 30000
            });

            if (response.success && response.data) {
                currentMarketOverviewData = response.data;
                displayOverview(currentMarketOverviewData, parseFloat($confidenceFilter.val()));
                updateStatus('Обзор рынка успешно обновлен.', 'success', true);
            } else {
                throw new Error(response.message || 'Не удалось получить данные обзора.');
            }
        } catch (error) {
            let errorMsg = 'Неизвестная AJAX ошибка при обновлении обзора.';
            if (error instanceof Error) {
                errorMsg = error.message;
            } else if (error.responseJSON && error.responseJSON.message) {
                errorMsg = error.responseJSON.message;
            } else if (error.statusText) {
                errorMsg = error.statusText;
                if(error.responseText && error.responseText.length < 300) errorMsg += `: ${error.responseText}`;
            }
            updateStatus(`Ошибка обновления обзора: ${errorMsg}`, 'error', true);
            console.error('Error refreshing market overview:', error, error.responseText || '');
        } finally {
            setButtonState('refreshOverview', false);
            setButtonState('manualAnalysis', false);
        }
    }

    $runManualAnalysisButton.click(runFullAnalysis);
    $refreshOverviewButton.click(refreshMarketOverview);

    if (window.initialMarketOverview) {
        displayOverview(window.initialMarketOverview, parseFloat($confidenceFilter.val()));
        updateStatus('Начальный обзор рынка загружен.', 'info', false);
    } else {
        updateStatus('Нажмите "Обновить обзор" или "Запустить анализ вручную".', 'info', false);
    }
    $confidenceFilterValue.text(parseFloat($confidenceFilter.val()).toFixed(2));
});