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
        return 'signal-NEUTRAL';
    }

    function getConfidenceBar(signal, confidence) {
        const confidencePercentage = Math.max(0, Math.min(100, Math.round(confidence * 100)));
        let barClass = '';
        if (signal && signal.toUpperCase() === 'BUY') barClass = 'bg-green-500';
        else if (signal && signal.toUpperCase() === 'SELL') barClass = 'bg-red-500';
        else barClass = 'bg-gray-400';

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
                        const currentEntryTs = new Date(data.timestamp_utc.replace(' ', 'T') + 'Z').getTime(); // Assuming UTC
                        if (currentEntryTs > latestTimestamp) {
                            latestTimestamp = currentEntryTs;
                        }
                    }

                    const overallConfidence = parseFloat(data.confidence) || 0;
                    if (overallConfidence < filterConfidence) {
                        continue;
                    }
                    dataDisplayed = true;

                    let tfDetailsHtml = '<ul class="list-disc list-inside pl-2 mt-1 text-gray-700">';
                    if (data.debug_strategy_results_by_tf && typeof data.debug_strategy_results_by_tf === 'object') {
                        for (const tf in data.debug_strategy_results_by_tf) {
                            const tfData = data.debug_strategy_results_by_tf[tf];
                            let tfSignalClass = getSignalClass(tfData.trend_signal);
                            let tfConfDisplay = tfData.confidence !== undefined ? (parseFloat(tfData.confidence) * 100).toFixed(0) + '%' : 'N/A';

                            let detailsCombined = [];
                            if(tfData.details && Array.isArray(tfData.details)){
                                detailsCombined = tfData.details.map(d => d.replace(/:\s*/, ': <span class="text-gray-600">') + '</span>');
                            } else if (tfData.error) {
                                detailsCombined.push(`<strong>Ошибка:</strong> ${tfData.error}`);
                            }

                            if (detailsCombined.length === 0) detailsCombined.push('Нет деталей');

                            tfDetailsHtml += `<li class="mb-1"><strong class="${tfSignalClass}">${tf} (${tfData.trend_signal || 'N/A'}, ${tfConfDisplay})</strong>: ${detailsCombined.join('; ')}</li>`;
                        }
                    } else if (data.error) { // Top level error for symbol
                        tfDetailsHtml += `<li><strong>Общая ошибка по символу:</strong> ${data.error}</li>`;
                    } else {
                        tfDetailsHtml += `<li>Нет данных по таймфреймам.</li>`;
                    }
                    tfDetailsHtml += '</ul>';

                    const confidenceVal = data.confidence !== undefined ? parseFloat(data.confidence).toFixed(2) : 'N/A';
                    const confidenceBarHtml = data.confidence !== undefined ? getConfidenceBar(data.signal, data.confidence) : '';

                    const row = `
                        <tr data-symbol="${symbol}">
                            <td class="px-3 py-2 whitespace-nowrap">${data.symbol || symbol}</td>
                            <td class="px-3 py-2 whitespace-nowrap ${getSignalClass(data.signal)}">${data.signal || 'N/A'}</td>
                            <td class="px-3 py-2 whitespace-nowrap">${confidenceVal}${confidenceBarHtml}</td>
                            <td class="px-3 py-2 summary-cell">${data.summary || 'Нет summary'}</td>
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
        setButtonState('refreshOverview', true); // Also disable refresh
        updateStatus('Запущен полный анализ рынка вручную...', 'loading', false);

        try {
            const response = await $.ajax({
                url: window.apiEndpoints.runAnalysis,
                method: 'POST', // Or GET, depending on how run_analysis.php expects to be triggered
                dataType: 'json',
                timeout: 300000 // 5 minutes timeout for full analysis
            });

            if (response.success && response.market_overview) {
                currentMarketOverviewData = response.market_overview;
                displayOverview(currentMarketOverviewData, parseFloat($confidenceFilter.val()));
                updateStatus('Полный анализ рынка завершен. Обзор обновлен.', 'success', true);
                if(response.message) updateStatus(response.message, 'info', true);
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
        setButtonState('manualAnalysis', true); // Also disable manual run
        updateStatus('Загрузка последнего обзора рынка...', 'loading', false);

        try {
            const response = await $.ajax({
                url: window.apiEndpoints.getLatest,
                method: 'GET',
                dataType: 'json',
                data: { format: 'json', type: 'all' }, // Request all data
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

    // Initial display
    if (window.initialMarketOverview) {
        displayOverview(window.initialMarketOverview, parseFloat($confidenceFilter.val()));
        updateStatus('Начальный обзор рынка загружен.', 'info', false);
    } else {
        updateStatus('Нажмите "Обновить обзор" или "Запустить анализ вручную".', 'info', false);
    }
    $confidenceFilterValue.text(parseFloat($confidenceFilter.val()).toFixed(2));
});