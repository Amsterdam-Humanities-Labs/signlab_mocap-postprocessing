<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistieken - Motion Capture Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-100">
    <?php require_once __DIR__ . '/partials/header.php'; ?>

    <div class="container mx-auto px-4 py-4">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-gray-800">Statistieken</h2>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-5 text-center">
                <div class="text-3xl font-bold text-blue-600"><?php echo number_format($summary['downloaded']); ?></div>
                <div class="text-sm text-gray-500 mt-1">Downloaded</div>
            </div>
            <div class="bg-white rounded-lg shadow p-5 text-center">
                <div class="text-3xl font-bold text-green-600"><?php echo number_format($summary['uploaded']); ?></div>
                <div class="text-sm text-gray-500 mt-1">Uploaded</div>
            </div>
            <div class="bg-white rounded-lg shadow p-5 text-center">
                <div class="text-3xl font-bold text-orange-600"><?php echo number_format($summary['processed']); ?></div>
                <div class="text-sm text-gray-500 mt-1">Processed</div>
            </div>
            <div class="bg-white rounded-lg shadow p-5 text-center">
                <div class="text-3xl font-bold text-purple-600"><?php echo number_format($summary['active_users']); ?></div>
                <div class="text-sm text-gray-500 mt-1">Active Users</div>
            </div>
        </div>

        <!-- Chart + Controls -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-800">Activity Over Time</h3>
                <form method="GET" action="stats.php" class="flex items-center gap-2">
                    <?php if ($filterUser): ?>
                        <input type="hidden" name="user" value="<?php echo htmlspecialchars($filterUser); ?>">
                    <?php endif; ?>
                    <label class="text-sm text-gray-600">Period:</label>
                    <select name="days" onchange="this.form.submit()" class="border border-gray-300 rounded px-2 py-1 text-sm">
                        <option value="7" <?php echo $days === 7 ? 'selected' : ''; ?>>7 days</option>
                        <option value="14" <?php echo $days === 14 ? 'selected' : ''; ?>>14 days</option>
                        <option value="30" <?php echo $days === 30 ? 'selected' : ''; ?>>30 days</option>
                        <option value="60" <?php echo $days === 60 ? 'selected' : ''; ?>>60 days</option>
                        <option value="90" <?php echo $days === 90 ? 'selected' : ''; ?>>90 days</option>
                        <option value="365" <?php echo $days === 365 ? 'selected' : ''; ?>>1 year</option>
                    </select>
                </form>
            </div>
            <canvas id="activityChart" height="100"></canvas>
        </div>

        <!-- User Breakdown -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Activity Per User (last <?php echo $days; ?> days)</h3>
            <?php if (empty($userActivity)): ?>
                <p class="text-gray-500">No activity in this period.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600 border-b">
                                <th class="pb-2">User</th>
                                <th class="pb-2 text-center">Downloaded</th>
                                <th class="pb-2 text-center">Uploaded</th>
                                <th class="pb-2 text-center">Processed</th>
                                <th class="pb-2 text-center">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($userActivity as $ua): ?>
                                <tr class="border-t">
                                    <td class="py-2 font-medium"><?php echo htmlspecialchars($ua['username']); ?></td>
                                    <td class="py-2 text-center text-blue-600"><?php echo $ua['downloads']; ?></td>
                                    <td class="py-2 text-center text-green-600"><?php echo $ua['uploads']; ?></td>
                                    <td class="py-2 text-center text-orange-600"><?php echo $ua['processed']; ?></td>
                                    <td class="py-2 text-center font-semibold"><?php echo $ua['total']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Activity Log -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-800">Activity Log</h3>
                <form method="GET" action="stats.php" class="flex items-center gap-2">
                    <input type="hidden" name="days" value="<?php echo $days; ?>">
                    <label class="text-sm text-gray-600">Filter:</label>
                    <select name="user" onchange="this.form.submit()" class="border border-gray-300 rounded px-2 py-1 text-sm">
                        <option value="">All users</option>
                        <?php foreach ($logUsers as $u): ?>
                            <option value="<?php echo htmlspecialchars($u); ?>" <?php echo $filterUser === $u ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <?php if (empty($activityLog)): ?>
                <p class="text-gray-500">No activity logged yet.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600 border-b">
                                <th class="pb-2">User</th>
                                <th class="pb-2">Filename</th>
                                <th class="pb-2">Action</th>
                                <th class="pb-2">Date/Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $actionLabels = [
                                'original' => ['downloaded', 'bg-blue-100 text-blue-800'],
                                'processed' => ['downloaded (pp)', 'bg-blue-100 text-blue-800'],
                                'bulk' => ['downloaded (bulk)', 'bg-blue-100 text-blue-800'],
                                'upload' => ['uploaded', 'bg-green-100 text-green-800'],
                                'mark_processed' => ['processed', 'bg-orange-100 text-orange-800'],
                                'mark_unprocessed' => ['reverted', 'bg-yellow-100 text-yellow-800'],
                            ];
                            ?>
                            <?php foreach ($activityLog as $log): ?>
                                <?php $label = $actionLabels[$log['download_type']] ?? [$log['download_type'], 'bg-gray-100 text-gray-800']; ?>
                                <tr class="border-t">
                                    <td class="py-2"><?php echo htmlspecialchars($log['username']); ?></td>
                                    <td class="py-2 font-mono text-xs"><?php echo htmlspecialchars($log['filename']); ?></td>
                                    <td class="py-2">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?php echo $label[1]; ?>">
                                            <?php echo $label[0]; ?>
                                        </span>
                                    </td>
                                    <td class="py-2 text-gray-600"><?php echo date('M j, Y H:i:s', strtotime($log['downloaded_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($logTotalPages > 1): ?>
                    <div class="mt-4 flex justify-center">
                        <nav class="flex space-x-2">
                            <?php if ($page > 1): ?>
                                <a href="?days=<?php echo $days; ?>&user=<?php echo urlencode($filterUser ?? ''); ?>&page=<?php echo $page - 1; ?>"
                                   class="px-3 py-1 bg-white border border-gray-300 rounded text-sm hover:bg-gray-50">Previous</a>
                            <?php endif; ?>
                            <span class="px-3 py-1 text-sm text-gray-600">Page <?php echo $page; ?> of <?php echo $logTotalPages; ?></span>
                            <?php if ($page < $logTotalPages): ?>
                                <a href="?days=<?php echo $days; ?>&user=<?php echo urlencode($filterUser ?? ''); ?>&page=<?php echo $page + 1; ?>"
                                   class="px-3 py-1 bg-white border border-gray-300 rounded text-sm hover:bg-gray-50">Next</a>
                            <?php endif; ?>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const chartData = <?php echo json_encode($dailyActivity); ?>;

        const labels = chartData.map(d => d.date);
        const downloads = chartData.map(d => parseInt(d.downloads));
        const uploads = chartData.map(d => parseInt(d.uploads));
        const processed = chartData.map(d => parseInt(d.processed));

        new Chart(document.getElementById('activityChart'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Downloads',
                        data: downloads,
                        borderColor: '#3B82F6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        fill: true,
                        tension: 0.3,
                    },
                    {
                        label: 'Uploads',
                        data: uploads,
                        borderColor: '#10B981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        fill: true,
                        tension: 0.3,
                    },
                    {
                        label: 'Processed',
                        data: processed,
                        borderColor: '#F59E0B',
                        backgroundColor: 'rgba(245, 158, 11, 0.1)',
                        fill: true,
                        tension: 0.3,
                    },
                ],
            },
            options: {
                responsive: true,
                interaction: {
                    intersect: false,
                    mode: 'index',
                },
                scales: {
                    x: {
                        grid: { display: false },
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 },
                    },
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                },
            },
        });
    </script>
</body>
</html>
