<?php
$config = require __DIR__ . '/config.php';
$symbols_for_js = array_keys($config['symbols'] ?? []);
$market_overview_file_path = $config['file_paths']['market_overview_file'];
$initial_market_overview = null;
$last_overview_timestamp_str = 'N/A';

if (file_exists($market_overview_file_path)) {
    $overview_content = file_get_contents($market_overview_file_path);
    if ($overview_content) {
        $decoded_overview = json_decode($overview_content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_overview)) {
            $initial_market_overview = $decoded_overview;
            $latest_ts = 0;
            foreach ($decoded_overview as $symbol_data_item) {
                if (isset($symbol_data_item['timestamp_utc'])) {
                    $current_ts_val = strtotime($symbol_data_item['timestamp_utc']);
                    if ($current_ts_val > $latest_ts) {
                        $latest_ts = $current_ts_val;
                    }
                }
            }
            if ($latest_ts > 0) {
                $last_overview_timestamp_str = date('Y-m-d H:i:s T', $latest_ts);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Market Analyzer</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .spinner { border: 4px solid rgba(0, 0, 0, .1); border-left-color: #4f46e5; border-radius: 50%; width: 20px; height: 20px; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .details-cell { max-width: 350px; white-space: normal; word-break: break-word; font-size: 0.75rem; line-height: 1.3; }
        .summary-cell { max-width: 450px; white-space: normal; word-break: break-word; font-size: 0.8rem; }
        .signal-BUY { color: #10B981; font-weight: 600; }
        .signal-SELL { color: #EF4444; font-weight: 600; }
        .signal-NEUTRAL, .signal-ERROR { color: #6B7280; }
        #statusMessagesContainer .status-message { padding: 0.3rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; margin-bottom: 0.25rem; }
        #statusMessagesContainer .status-error { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5;}
        #statusMessagesContainer .status-success { background-color: #d1fae5; color: #047857; border: 1px solid #6ee7b7;}
        #statusMessagesContainer .status-info { background-color: #e0e7ff; color: #3730a3; border: 1px solid #a5b4fc;}
        #statusMessagesContainer .status-loading { background-color: #e0f2fe; color: #075985; border: 1px solid #7dd3fc;}
        .confidence-bar-container { width: 60px; height: 10px; background-color: #e5e7eb; border-radius: 3px; overflow: hidden; display: inline-block; margin-left: 5px; vertical-align: middle;}
        .confidence-bar { height: 100%; transition: width 0.3s ease-in-out; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen p-2 md:p-6">
<div class="container mx-auto bg-white shadow-xl rounded-lg p-4 md:p-8">
    <header class="mb-6 text-center">
        <h1 class="text-2xl md:text-3xl font-bold text-gray-800">Market Analyzer</h1>
        <p class="text-sm text-gray-600">Анализатор рыночных трендов</p>
        <p id="lastUpdatedTimestamp" class="text-xs text-gray-500 mt-1">Обзор от: <?php echo $last_overview_timestamp_str; ?></p>
    </header>
    <main>
        <div class="flex flex-col sm:flex-row justify-between items-center mb-6 space-y-4 sm:space-y-0 sm:space-x-4">
            <button id="runManualAnalysis"
                    class="w-full sm:w-auto bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 px-6 rounded-md shadow-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition duration-150 ease-in-out flex items-center justify-center">
                <span id="runManualAnalysisButtonText">Запустить анализ вручную</span>
                <div id="runManualAnalysisButtonSpinner" class="spinner ml-2 hidden"></div>
            </button>
            <button id="refreshOverview"
                    class="w-full sm:w-auto bg-green-500 hover:bg-green-600 text-white font-semibold py-2 px-6 rounded-md shadow-md focus:outline-none focus:ring-2 focus:ring-green-400 focus:ring-offset-2 transition duration-150 ease-in-out flex items-center justify-center">
                <span id="refreshOverviewButtonText">Обновить обзор</span>
                <div id="refreshOverviewButtonSpinner" class="spinner ml-2 hidden"></div>
            </button>
        </div>

        <div id="statusMessagesContainer" class="mb-4 max-h-48 overflow-y-auto border border-gray-300 p-2 rounded-md bg-gray-50">
            <div class="status-message status-info">Ожидание команд или загрузка данных...</div>
        </div>

        <div class="flex justify-between items-center mb-3 mt-6">
            <h2 class="text-xl font-semibold text-gray-700">Обзор Рынка:</h2>
            <div class="flex items-center space-x-2">
                <label for="confidenceFilter" class="text-sm text-gray-600">Min Confidence:</label>
                <input type="range" id="confidenceFilter" name="confidenceFilter" min="0" max="1" step="0.05" value="0" class="w-24 sm:w-32">
                <span id="confidenceFilterValue" class="text-sm text-gray-700 font-medium">0.00</span>
            </div>
        </div>
        <div id="marketOverviewTableContainer" class="overflow-x-auto shadow-md rounded-lg">
            <table id="marketOverviewTable" class="min-w-full divide-y divide-gray-200 hidden">
                <thead class="bg-gray-100">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase tracking-wider">Символ</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase tracking-wider">Сигнал</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase tracking-wider">Уверенность</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase tracking-wider summary-cell">Summary</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase tracking-wider details-cell">Детали по ТФ</th>
                </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200 text-sm"></tbody>
            </table>
            <p id="noOverviewData" class="text-gray-500 p-4 text-center">Данные обзора пока отсутствуют или не соответствуют фильтру.</p>
        </div>

        <div id="rawJsonOutputToggle" class="mt-6 text-sm text-indigo-600 hover:text-indigo-800 cursor-pointer font-medium">
            Показать/скрыть Raw JSON последнего обзора
        </div>
        <div id="rawJsonOutput" class="mt-2 hidden">
            <h3 class="font-semibold text-lg mb-2">Raw JSON Output (market_overview.json):</h3>
            <pre class="bg-gray-900 text-sm text-green-400 p-4 rounded-md whitespace-pre-wrap overflow-x-auto max-h-96"></pre>
        </div>
    </main>
    <footer class="mt-8 text-center text-xs text-gray-400">
        <p>© <?php echo date("Y"); ?> Market Analyzer</p>
    </footer>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    window.symbolsToAnalyzeFromPHP = <?php echo json_encode($symbols_for_js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    window.initialMarketOverview = <?php echo $initial_market_overview ? json_encode($initial_market_overview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'null'; ?>;
    window.apiEndpoints = {
        runAnalysis: 'scripts/run_analysis.php',
        getLatest: 'get_latest.php'
    };
</script>
<script src="assets/script.js" defer></script>
</body>
</html>