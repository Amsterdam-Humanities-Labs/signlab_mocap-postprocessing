<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Motion Capture File Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <header class="mb-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">Motion Capture File Manager</h1>
            <div class="flex gap-4">
                <div class="bg-blue-100 px-4 py-2 rounded">
                    <span class="text-blue-800 font-semibold">Unprocessed: <?php echo $unprocessedCount; ?></span>
                </div>
                <div class="bg-green-100 px-4 py-2 rounded">
                    <span class="text-green-800 font-semibold">Processed: <?php echo $processedCount; ?></span>
                </div>
            </div>
        </header>

        <div class="mb-6 space-y-4">
            <!-- Filter Controls -->
            <div class="bg-white rounded-lg shadow p-4">
                <form method="GET" action="index.php" class="flex flex-wrap gap-4 items-end">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Processing Status</label>
                        <select name="status" class="border border-gray-300 rounded-md px-3 py-2 text-sm">
                            <option value="unprocessed" <?php echo $selectedStatus === 'unprocessed' ? 'selected' : ''; ?>>Unprocessed Only</option>
                            <option value="processed" <?php echo $selectedStatus === 'processed' ? 'selected' : ''; ?>>Processed Only</option>
                            <option value="all" <?php echo $selectedStatus === 'all' ? 'selected' : ''; ?>>All Files</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Filter by Date</label>
                        <select name="date" class="border border-gray-300 rounded-md px-3 py-2 text-sm">
                            <option value="all" <?php echo $selectedDate === 'all' ? 'selected' : ''; ?>>All Dates</option>
                            <?php foreach ($availableDates as $date): ?>
                                <option value="<?php echo htmlspecialchars($date); ?>" <?php echo $selectedDate === $date ? 'selected' : ''; ?>>
                                    <?php echo date('F j, Y', strtotime($date)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Files per page</label>
                        <select name="limit" class="border border-gray-300 rounded-md px-3 py-2 text-sm">
                            <option value="25" <?php echo $limit === 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo $limit === 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo $limit === 100 ? 'selected' : ''; ?>>100</option>
                            <option value="200" <?php echo $limit === 200 ? 'selected' : ''; ?>>200</option>
                        </select>
                    </div>
                    <button type="submit" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded text-sm">
                        Apply Filter
                    </button>
                </form>
            </div>
            
            <!-- Action Buttons -->
            <div class="flex gap-4">
                <?php if ($selectedStatus === 'unprocessed' || $selectedStatus === 'all'): ?>
                <button onclick="downloadSelected()" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded disabled:opacity-50" id="downloadBtn" disabled>
                    Download Selected
                </button>
                <?php endif; ?>
                <a href="upload.php" class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded inline-block">
                    Upload Processed Files
                </a>
            </div>
        </div>


        <div class="bg-white rounded-lg shadow">
            <?php if (empty($filesGroupedByDate)): ?>
                <div class="p-8 text-center text-gray-500">
                    No <?php echo $selectedStatus; ?> files found.
                </div>
            <?php else: ?>
                <?php foreach ($filesGroupedByDate as $date => $files): ?>
                    <div class="border-b last:border-b-0">
                        <div class="bg-gray-50 px-6 py-3">
                            <h3 class="font-semibold text-gray-700">
                                <?php echo date('F j, Y', strtotime($date)); ?>
                                <span class="text-sm font-normal text-gray-500">(<?php echo count($files); ?> files)</span>
                            </h3>
                        </div>
                        <div class="p-6">
                            <table class="w-full">
                                <thead>
                                    <tr class="text-left text-gray-600 text-sm">
                                        <?php if ($selectedStatus === 'unprocessed' || $selectedStatus === 'all'): ?>
                                        <th class="pb-3">
                                            <input type="checkbox" class="date-checkbox" data-date="<?php echo $date; ?>">
                                        </th>
                                        <?php endif; ?>
                                        <th class="pb-3">Glos</th>
                                        <th class="pb-3">Original Filename</th>
                                        <?php if ($selectedStatus === 'processed' || $selectedStatus === 'all'): ?>
                                        <th class="pb-3">Processed Filename</th>
                                        <th class="pb-3">Processed Date</th>
                                        <?php endif; ?>
                                        <th class="pb-3">Status</th>
                                        <th class="pb-3">Time</th>
                                        <th class="pb-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($files as $file): ?>
                                        <tr class="border-t">
                                            <?php if ($selectedStatus === 'unprocessed' || $selectedStatus === 'all'): ?>
                                            <td class="py-3">
                                                <?php if ($file['is_pp'] == 0): ?>
                                                <input type="checkbox" name="selected_files[]" value="<?php echo $file['id']; ?>" class="file-checkbox" data-date="<?php echo $date; ?>">
                                                <?php else: ?>
                                                <!-- Empty cell for processed files to maintain column alignment -->
                                                <?php endif; ?>
                                            </td>
                                            <?php endif; ?>
                                            <td class="py-3"><?php echo htmlspecialchars($file['glos']); ?></td>
                                            <td class="py-3 font-mono text-sm"><?php echo htmlspecialchars($file['filename']); ?></td>
                                            <?php if ($selectedStatus === 'processed' || $selectedStatus === 'all'): ?>
                                            <td class="py-3 font-mono text-sm text-green-700"><?php echo htmlspecialchars($file['filename_pp'] ?? 'N/A'); ?></td>
                                            <td class="py-3 text-sm text-gray-600">
                                                <?php echo $file['datetime_pp'] ? date('M j, Y H:i', strtotime($file['datetime_pp'])) : 'N/A'; ?>
                                            </td>
                                            <?php endif; ?>
                                            <td class="py-3">
                                                <?php if ($file['is_pp'] == 1): ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                        Processed
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                                        Unprocessed
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-3 text-gray-600"><?php echo date('H:i:s', strtotime($file['datetime'])); ?></td>
                                            <td class="py-3">
                                                <?php if ($file['is_pp'] == 1): ?>
                                                    <a href="download.php?id=<?php echo $file['id']; ?>&type=processed" class="text-green-600 hover:text-green-800">
                                                        Download Processed
                                                    </a>
                                                    <br>
                                                    <a href="download.php?id=<?php echo $file['id']; ?>&type=original" class="text-blue-600 hover:text-blue-800 text-sm">
                                                        Download Original
                                                    </a>
                                                <?php else: ?>
                                                    <a href="download.php?id=<?php echo $file['id']; ?>" class="text-blue-600 hover:text-blue-800">
                                                        Download Original
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="mt-6 flex justify-center">
                <nav class="flex space-x-2">
                    <?php if ($page > 1): ?>
                        <a href="?status=<?php echo urlencode($selectedStatus); ?>&date=<?php echo urlencode($selectedDate); ?>&limit=<?php echo $limit; ?>&page=<?php echo $page - 1; ?>" 
                           class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-500 hover:bg-gray-50">
                            Previous
                        </a>
                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="px-3 py-2 bg-blue-500 text-white rounded-md text-sm font-medium">
                                <?php echo $i; ?>
                            </span>
                        <?php else: ?>
                            <a href="?status=<?php echo urlencode($selectedStatus); ?>&date=<?php echo urlencode($selectedDate); ?>&limit=<?php echo $limit; ?>&page=<?php echo $i; ?>" 
                               class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-500 hover:bg-gray-50">
                                <?php echo $i; ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?status=<?php echo urlencode($selectedStatus); ?>&date=<?php echo urlencode($selectedDate); ?>&limit=<?php echo $limit; ?>&page=<?php echo $page + 1; ?>" 
                           class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-500 hover:bg-gray-50">
                            Next
                        </a>
                    <?php endif; ?>
                </nav>
            </div>
        <?php endif; ?>

        <!-- Results Info -->
        <div class="mt-4 text-center text-gray-600 text-sm">
            <?php if ($selectedDate === 'all'): ?>
                Showing page <?php echo $page; ?> of <?php echo $totalPages; ?> 
                (<?php echo $totalFiles; ?> total <?php echo $selectedStatus; ?> files, <?php echo $limit; ?> per page)
            <?php else: ?>
                Showing <?php echo $totalFiles; ?> <?php echo $selectedStatus; ?> files for <?php echo date('F j, Y', strtotime($selectedDate)); ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Auto-submit form when dropdown changes
        document.querySelector('select[name="status"]').addEventListener('change', function() {
            this.form.submit();
        });
        
        document.querySelector('select[name="date"]').addEventListener('change', function() {
            this.form.submit();
        });
        
        document.querySelector('select[name="limit"]').addEventListener('change', function() {
            this.form.submit();
        });

        // Handle checkbox selection
        document.querySelectorAll('.date-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const date = this.dataset.date;
                const fileCheckboxes = document.querySelectorAll(`.file-checkbox[data-date="${date}"]`);
                fileCheckboxes.forEach(cb => cb.checked = this.checked);
                updateDownloadButton();
            });
        });

        document.querySelectorAll('.file-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', updateDownloadButton);
        });

        function updateDownloadButton() {
            const checkedBoxes = document.querySelectorAll('.file-checkbox:checked');
            const downloadBtn = document.getElementById('downloadBtn');
            downloadBtn.disabled = checkedBoxes.length === 0;
        }

        function downloadSelected() {
            const checkedBoxes = document.querySelectorAll('.file-checkbox:checked');
            const fileIds = Array.from(checkedBoxes).map(cb => cb.value);
            
            if (fileIds.length === 0) {
                return; // Do nothing if no files selected
            }
            
            if (fileIds.length === 1) {
                window.location.href = 'download.php?id=' + fileIds[0];
            } else {
                window.location.href = 'download.php?bulk=' + fileIds.join(',');
            }
        }
    </script>
</body>
</html>