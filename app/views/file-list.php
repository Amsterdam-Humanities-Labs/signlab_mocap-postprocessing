<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Motion Capture File Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 m-0">
    <?php require_once __DIR__ . '/partials/header.php'; ?>

    <div id="appLayout" style="display: flex; flex-direction: row; height: calc(100vh - 64px); overflow: hidden;">
    <!-- Main content -->
    <div id="mainContent" style="flex: 1; overflow-y: auto; padding: 1rem;">
        <div class="mb-6 flex gap-4">
            <div class="bg-blue-100 px-4 py-2 rounded">
                <span class="text-blue-800 font-semibold">Unprocessed: <?php echo $unprocessedCount; ?></span>
            </div>
            <div class="bg-green-100 px-4 py-2 rounded">
                <span class="text-green-800 font-semibold">Processed: <?php echo $processedCount; ?></span>
            </div>
        </div>

        <?php if (!empty($noAssignments)): ?>
            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-8 text-center">
                <p class="text-yellow-800 text-lg font-medium">Geen captures aan u toegewezen.</p>
                <p class="text-yellow-700 mt-2">Neem contact op met een beheerder.</p>
            </div>
        <?php else: ?>

        <div class="mb-6 space-y-4">
            <!-- Filter Controls -->
            <div class="bg-white rounded-lg shadow p-4">
                <form method="GET" action="index.php" class="flex flex-wrap gap-4 items-end">
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"
                               placeholder="Search by filename..."
                               class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    </div>
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
                        <label class="block text-sm font-medium text-gray-700 mb-1">Label</label>
                        <?php $selectedLabel = $_GET['label'] ?? 'all'; ?>
                        <select name="label" class="border border-gray-300 rounded-md px-3 py-2 text-sm">
                            <option value="all" <?php echo $selectedLabel === 'all' ? 'selected' : ''; ?>>All Labels</option>
                            <option value="bak" <?php echo $selectedLabel === 'bak' ? 'selected' : ''; ?>>BAK</option>
                            <option value="znn" <?php echo $selectedLabel === 'znn' ? 'selected' : ''; ?>>ZNN</option>
                            <option value="hh"  <?php echo $selectedLabel === 'hh'  ? 'selected' : ''; ?>>HH</option>
                            <option value="sencity" <?php echo $selectedLabel === 'sencity' ? 'selected' : ''; ?>>Sencity</option>
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
                    <?php if ($selectedStatus === 'processed' || $selectedStatus === 'all'): ?>
                    <?php $selectedReview = $_GET['review'] ?? 'all'; ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Review Status</label>
                        <select name="review" class="border border-gray-300 rounded-md px-3 py-2 text-sm">
                            <option value="all" <?php echo $selectedReview === 'all' ? 'selected' : ''; ?>>All Reviews</option>
                            <option value="pending" <?php echo $selectedReview === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $selectedReview === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $selectedReview === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            <option value="needs_review" <?php echo $selectedReview === 'needs_review' ? 'selected' : ''; ?>>Needs Review</option>
                        </select>
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded text-sm">
                        Apply Filter
                    </button>
                    <?php if (!empty($_GET['search'])): ?>
                    <a href="index.php" class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded text-sm">
                        Clear Search
                    </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Action Buttons -->
            <div class="flex gap-4">
                <?php if ($selectedStatus === 'unprocessed' || $selectedStatus === 'all'): ?>
                <button onclick="downloadSelected()" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded disabled:opacity-50" id="downloadBtn" disabled>
                    Download Selected
                </button>
                <?php endif; ?>
                <button onclick="downloadSelectedEaf()" class="bg-purple-600 hover:bg-purple-800 text-white font-bold py-2 px-4 rounded disabled:opacity-50" id="downloadEafBtn" disabled>
                    Download Selected (EAF)
                </button>
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
                                        <th class="pb-3">
                                            <input type="checkbox" class="date-checkbox" data-date="<?php echo $date; ?>">
                                        </th>
                                        <th class="pb-3">Filename</th>
                                        <?php if ($selectedStatus === 'processed' || $selectedStatus === 'all'): ?>
                                        <th class="pb-3">Processed Filename</th>
                                        <th class="pb-3">Processed Date</th>
                                        <?php endif; ?>
                                        <th class="pb-3">Status</th>
                                        <th class="pb-3">Review</th>
                                        <th class="pb-3">Last Activity</th>
                                        <th class="pb-3">Comment</th>
                                        <th class="pb-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($files as $file): ?>
                                        <tr class="border-t">
                                            <td class="py-3">
                                                <input type="checkbox" name="selected_files[]" value="<?php echo $file['id']; ?>" class="file-checkbox" data-date="<?php echo $date; ?>">
                                            </td>
                                            <td class="py-3 font-mono text-sm">
                                                <?php echo htmlspecialchars($file['filename']); ?>
                                                <?php if (!empty($fileGlosses[$file['id']])): ?>
                                                    <div class="mt-1 text-indigo-700 font-semibold text-sm break-words max-w-[260px]"
                                                         title="<?php echo htmlspecialchars($fileGlosses[$file['id']]); ?>">
                                                        <?php echo htmlspecialchars($fileGlosses[$file['id']]); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($fileLabels[$file['id']])): ?>
                                                    <div class="mt-1 flex flex-wrap gap-1">
                                                        <?php foreach ($fileLabels[$file['id']] as $lbl): ?>
                                                            <?php
                                                                $bg = $lbl['color'] ?: '#6b7280';
                                                                $short = $lbl['label'] === 'Basiswoordenlijst Amsterdamse Kleuters' ? 'BAK' : $lbl['label'];
                                                            ?>
                                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold text-white"
                                                                  style="background-color: <?php echo htmlspecialchars($bg); ?>"
                                                                  title="<?php echo htmlspecialchars($lbl['label']); ?>">
                                                                <?php echo htmlspecialchars($short); ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php
                                                    $mcp = $fileMcpStatus[$file['id']] ?? ['pp' => null, 'ta' => null, 'klaar' => false];
                                                    $ppLabel = $mcp['pp'] === '1' ? 'Klaar' : ($mcp['pp'] === '2' ? 'Check nodig' : ($mcp['pp'] === null || $mcp['pp'] === '' ? 'leeg' : $mcp['pp']));
                                                    $taLabel = ($mcp['ta'] === null || $mcp['ta'] === '') ? 'leeg' : $mcp['ta'];
                                                    if ($mcp['klaar']) {
                                                        $mcpText  = 'MCP Klaar';
                                                        $mcpClass = 'bg-green-100 text-green-800';
                                                    } else {
                                                        $blocking = [];
                                                        if ($mcp['pp'] !== '1') $blocking[] = 'PP';
                                                        if ($mcp['ta'] !== 'Klaar') $blocking[] = 'TA';
                                                        $mcpText  = implode(' + ', $blocking) . ' niet klaar';
                                                        $mcpClass = 'bg-gray-100 text-gray-600';
                                                    }
                                                ?>
                                                <div class="mt-1">
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium <?php echo $mcpClass; ?>"
                                                          title="MCP postprocessing: <?php echo htmlspecialchars($ppLabel); ?> — MCP tijd annotatie: <?php echo htmlspecialchars($taLabel); ?>">
                                                        <?php echo htmlspecialchars($mcpText); ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <?php if ($selectedStatus === 'processed' || $selectedStatus === 'all'): ?>
                                            <td class="py-3 font-mono text-sm text-green-700"><?php echo htmlspecialchars($file['filename_pp'] ?? 'N/A'); ?></td>
                                            <td class="py-3 text-sm text-gray-600">
                                                <?php echo $file['datetime_pp'] ? date('M j, Y H:i', strtotime($file['datetime_pp'])) : 'N/A'; ?>
                                            </td>
                                            <?php endif; ?>
                                            <td class="py-3">
                                                <?php $isDownloaded = !empty($downloadedFiles[$file['id']]); ?>
                                                <?php if ($file['is_pp'] == 1): ?>
                                                    <span class="status-badge inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">Processed</span>
                                                <?php elseif ($isDownloaded): ?>
                                                    <span class="status-badge inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">Downloaded</span>
                                                <?php else: ?>
                                                    <span class="status-badge inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">Unprocessed</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-3">
                                                <?php if ($file['is_pp'] == 1): ?>
                                                    <?php $reviewStatus = $file['review_status'] ?? 'pending'; ?>
                                                    <div class="flex gap-1" data-file-id="<?php echo $file['id']; ?>">
                                                        <button onclick="setReviewStatus(<?php echo $file['id']; ?>, 'approved')"
                                                                class="review-btn w-8 h-8 rounded text-lg <?php echo $reviewStatus === 'approved' ? 'bg-green-500 text-white' : 'bg-gray-200 hover:bg-green-200'; ?>"
                                                                data-status="approved" title="Approved">&#10003;</button>
                                                        <button onclick="setReviewStatus(<?php echo $file['id']; ?>, 'rejected')"
                                                                class="review-btn w-8 h-8 rounded text-lg <?php echo $reviewStatus === 'rejected' ? 'bg-red-500 text-white' : 'bg-gray-200 hover:bg-red-200'; ?>"
                                                                data-status="rejected" title="Rejected">&#10007;</button>
                                                        <button onclick="setReviewStatus(<?php echo $file['id']; ?>, 'needs_review')"
                                                                class="review-btn w-8 h-8 rounded text-lg <?php echo $reviewStatus === 'needs_review' ? 'bg-yellow-500 text-white' : 'bg-gray-200 hover:bg-yellow-200'; ?>"
                                                                data-status="needs_review" title="Needs Review">?</button>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-gray-400 text-sm">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-3 text-gray-600 text-sm">
                                                <?php
                                                $activity = $fileActivities[$file['id']] ?? null;
                                                if ($activity):
                                                    $actionLabels = [
                                                        'original' => 'downloaded',
                                                        'processed' => 'downloaded',
                                                        'bulk' => 'downloaded',
                                                        'upload' => 'uploaded',
                                                        'mark_processed' => 'processed',
                                                        'mark_unprocessed' => 'reverted',
                                                    ];
                                                    $actionLabel = $actionLabels[$activity['action']] ?? $activity['action'];
                                                ?>
                                                    <?php echo $actionLabel; ?> by <strong><?php echo htmlspecialchars($activity['username']); ?></strong><br>
                                                    <span class="text-xs"><?php echo date('M j, Y H:i', strtotime($activity['downloaded_at'])); ?></span>
                                                <?php else: ?>
                                                    <span class="text-xs"><?php echo date('M j, Y H:i', strtotime($file['last_modified'])); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-3">
                                                <div class="comment-cell relative" data-file-id="<?php echo $file['id']; ?>">
                                                    <?php $fileComment = $file['comment'] ?? ''; $commentBy = $file['comment_by'] ?? ''; ?>
                                                    <div class="comment-display cursor-pointer text-sm text-gray-600 min-w-[120px] max-w-[200px] truncate hover:bg-gray-50 rounded px-1"
                                                         onclick="editComment(<?php echo $file['id']; ?>, this)"
                                                         title="<?php echo htmlspecialchars($fileComment); ?><?php echo $commentBy ? "\n— " . htmlspecialchars($commentBy) : ''; ?>">
                                                        <?php echo $fileComment ? htmlspecialchars($fileComment) : '<span class=&quot;text-gray-300 italic&quot;>Add comment...</span>'; ?>
                                                    </div>
                                                    <textarea class="comment-edit hidden w-full text-sm border border-blue-300 rounded px-2 py-1 min-w-[150px]"
                                                              rows="2"
                                                              onblur="saveComment(<?php echo $file['id']; ?>, this)"
                                                              onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();this.blur();}"
                                                    ><?php echo htmlspecialchars($fileComment); ?></textarea>
                                                </div>
                                            </td>
                                            <td class="py-3">
                                                <?php
                                                    $baseGlos = preg_replace('/\\.fbx$/i', '', $file['filename']);
                                                    // glb_path points at /fbx/<name>.glb — the full glassesGuy/Mixamo
                                                    // export, whose bone names (Hips/Spine/Neck) DON'T match the CC
                                                    // preview avatar, so retargeting yields 0 matches and the avatar
                                                    // sits frozen. The retargetable animation is the CC export at
                                                    // /fbx/CC/<name>.glb. Prefer it; fall back to glb_path if absent.
                                                    $glbDisk = !empty($file['glb_path']) ? $file['glb_path'] : '';
                                                    $ccDisk  = $glbDisk ? preg_replace('#/fbx/(?!CC/)#', '/fbx/CC/', $glbDisk, 1) : '';
                                                    $animDisk = ($ccDisk && is_file($ccDisk)) ? $ccDisk : $glbDisk;
                                                    $glbUrl = $animDisk ? str_replace('/web/', '/', $animDisk) : '';
                                                    $videoUrl = $previewVideos[$file['id']] ?? '';
                                                    $videoParam = $videoUrl ? '&video=' . urlencode($videoUrl) : '';
                                                    // Cache-bust on the viewer's mtime: the dist/ viewer is served
                                                    // without a Cache-Control header, so browsers heuristically cache
                                                    // it and the iframe can keep serving a stale build. Tying ?v= to
                                                    // index.html's mtime forces a fresh load whenever the viewer changes.
                                                    static $viewerVer = null;
                                                    if ($viewerVer === null) {
                                                        $viewerVer = @filemtime(__DIR__ . '/../../babyloncc/dist/index.html') ?: '0';
                                                    }
                                                    $verParam = '&v=' . $viewerVer;
                                                    $previewUrl = $glbUrl ? '/animMIDI/babyloncc/dist/?anim=' . urlencode($glbUrl) . $videoParam . $verParam : '';
                                                ?>
                                                <?php if ($file['is_pp'] == 1): ?>
                                                    <a href="/animMIDI/babyloncc/dist/compare.html?file=<?php echo urlencode($baseGlos); ?>"
                                                       target="_blank" class="text-purple-600 hover:text-purple-800 mr-2">Compare</a>
                                                    <a href="download.php?id=<?php echo $file['id']; ?>&type=processed" class="text-green-600 hover:text-green-800">Download Processed</a>
                                                    <br>
                                                    <a href="download.php?id=<?php echo $file['id']; ?>&type=original" class="text-blue-600 hover:text-blue-800 text-sm">Download Original</a>
                                                <?php else: ?>
                                                    <a href="download.php?id=<?php echo $file['id']; ?>" class="text-blue-600 hover:text-blue-800">Download Original</a>
                                                <?php endif; ?>
                                                <br>
                                                <button onclick="toggleProcessed(<?php echo $file['id']; ?>, <?php echo $file['is_pp'] ? "'unprocess'" : "'process'"; ?>, this)"
                                                        class="text-sm mt-1 <?php echo $file['is_pp'] ? 'text-yellow-600 hover:text-yellow-800' : 'text-green-600 hover:text-green-800'; ?>">
                                                    <?php echo $file['is_pp'] ? 'Revert to unprocessed' : 'Process as correct animation'; ?>
                                                </button>
                                                <?php if ($previewUrl): ?>
                                                    <br>
                                                    <button type="button" onclick="openPreview('<?php echo $previewUrl; ?>', '<?php echo htmlspecialchars($file['filename']); ?>', <?php echo htmlspecialchars(json_encode($fileGlosses[$file['id']] ?? ''), ENT_QUOTES); ?>)" class="text-orange-600 hover:text-orange-800 text-sm bg-transparent border-0 cursor-pointer p-0">Preview Animation</button>
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
                        <a href="?status=<?php echo urlencode($selectedStatus); ?>&date=<?php echo urlencode($selectedDate); ?>&limit=<?php echo $limit; ?>&page=<?php echo $page - 1; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>&label=<?php echo urlencode($selectedLabel); ?>"
                           class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-500 hover:bg-gray-50">Previous</a>
                    <?php endif; ?>
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="px-3 py-2 bg-blue-500 text-white rounded-md text-sm font-medium"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?status=<?php echo urlencode($selectedStatus); ?>&date=<?php echo urlencode($selectedDate); ?>&limit=<?php echo $limit; ?>&page=<?php echo $i; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>&label=<?php echo urlencode($selectedLabel); ?>"
                               class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-500 hover:bg-gray-50"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?status=<?php echo urlencode($selectedStatus); ?>&date=<?php echo urlencode($selectedDate); ?>&limit=<?php echo $limit; ?>&page=<?php echo $page + 1; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>&label=<?php echo urlencode($selectedLabel); ?>"
                           class="px-3 py-2 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-500 hover:bg-gray-50">Next</a>
                    <?php endif; ?>
                </nav>
            </div>
        <?php endif; ?>

        <div class="mt-4 text-center text-gray-600 text-sm">
            <?php if ($selectedDate === 'all'): ?>
                Showing page <?php echo $page; ?> of <?php echo $totalPages; ?>
                (<?php echo $totalFiles; ?> total <?php echo $selectedStatus; ?> files, <?php echo $limit; ?> per page)
            <?php else: ?>
                Showing <?php echo $totalFiles; ?> <?php echo $selectedStatus; ?> files for <?php echo date('F j, Y', strtotime($selectedDate)); ?>
            <?php endif; ?>
        </div>

        <?php endif; /* end noAssignments check */ ?>

    <!-- Loading overlay (shown on filter submit / pagination click) -->
    <div id="loadingOverlay"
         class="fixed inset-0 bg-black bg-opacity-40 hidden flex items-center justify-center z-50"
         aria-hidden="true" role="status">
        <div class="w-16 h-16 border-4 border-white border-t-transparent rounded-full animate-spin"></div>
    </div>

    <script>
        // ── Loading overlay ──
        (function() {
            var overlay = document.getElementById('loadingOverlay');
            function show() {
                if (overlay) overlay.classList.remove('hidden');
            }
            // Filter form submit
            document.querySelectorAll('form').forEach(function(f) {
                f.addEventListener('submit', show);
            });
            // Pagination + filter-reset anchors within the main content
            document.querySelectorAll('nav a, a.bg-red-500, a.bg-gray-500').forEach(function(a) {
                a.addEventListener('click', function(e) {
                    // ignore modifier clicks (open-in-new-tab etc.)
                    if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
                    show();
                });
            });
            // Hide when navigating back via browser history
            window.addEventListener('pageshow', function() {
                if (overlay) overlay.classList.add('hidden');
            });
        })();

        function toggleProcessed(fileId, action, btn) {
            btn.disabled = true;
            const origText = btn.textContent;
            btn.textContent = '...';

            fetch('mark-processed.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `id=${fileId}&action=${action}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // Toggle button state inline
                    if (data.status === 'processed') {
                        btn.textContent = 'Revert to unprocessed';
                        btn.className = 'text-sm mt-1 text-yellow-600 hover:text-yellow-800';
                        btn.setAttribute('onclick', `toggleProcessed(${fileId}, 'unprocess', this)`);
                    } else {
                        btn.textContent = 'Process as correct animation';
                        btn.className = 'text-sm mt-1 text-green-600 hover:text-green-800';
                        btn.setAttribute('onclick', `toggleProcessed(${fileId}, 'process', this)`);
                    }
                    // Update status badge in same row
                    const row = btn.closest('tr');
                    const statusCell = row.querySelector('.status-badge');
                    if (statusCell) {
                        if (data.status === 'processed') {
                            statusCell.className = 'status-badge inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800';
                            statusCell.textContent = 'Processed';
                        } else {
                            statusCell.className = 'status-badge inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800';
                            statusCell.textContent = 'Unprocessed';
                        }
                    }
                } else {
                    alert(data.message || 'Failed');
                    btn.textContent = origText;
                }
                btn.disabled = false;
            })
            .catch(() => {
                alert('Error updating status');
                btn.textContent = origText;
                btn.disabled = false;
            });
        }

        function setReviewStatus(fileId, status) {
            const container = document.querySelector(`[data-file-id="${fileId}"]`);
            const buttons = container.querySelectorAll('.review-btn');
            buttons.forEach(btn => btn.disabled = true);

            fetch('review-status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `id=${fileId}&status=${status}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    buttons.forEach(btn => {
                        const btnStatus = btn.dataset.status;
                        btn.className = 'review-btn w-8 h-8 rounded text-lg ';
                        if (btnStatus === status) {
                            if (status === 'approved') btn.className += 'bg-green-500 text-white';
                            else if (status === 'rejected') btn.className += 'bg-red-500 text-white';
                            else if (status === 'needs_review') btn.className += 'bg-yellow-500 text-white';
                        } else {
                            if (btnStatus === 'approved') btn.className += 'bg-gray-200 hover:bg-green-200';
                            else if (btnStatus === 'rejected') btn.className += 'bg-gray-200 hover:bg-red-200';
                            else if (btnStatus === 'needs_review') btn.className += 'bg-gray-200 hover:bg-yellow-200';
                        }
                    });
                } else {
                    alert('Failed to update review status');
                }
            })
            .catch(() => alert('Error updating review status'))
            .finally(() => buttons.forEach(btn => btn.disabled = false));
        }

        // Auto-submit filter form when any dropdown (status / date / limit / review)
        // changes — the Apply Filter button is only needed for the text search field.
        document.querySelectorAll('form[action="index.php"] select').forEach(function(sel) {
            sel.addEventListener('change', function() { this.form.submit(); });
        });

        document.querySelectorAll('.date-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const date = this.dataset.date;
                document.querySelectorAll(`.file-checkbox[data-date="${date}"]`).forEach(cb => cb.checked = this.checked);
                updateDownloadButton();
            });
        });

        document.querySelectorAll('.file-checkbox').forEach(cb => cb.addEventListener('change', updateDownloadButton));

        function updateDownloadButton() {
            const anyChecked = document.querySelectorAll('.file-checkbox:checked').length > 0;
            const btn = document.getElementById('downloadBtn');
            if (btn) btn.disabled = !anyChecked;
            const eafBtn = document.getElementById('downloadEafBtn');
            if (eafBtn) eafBtn.disabled = !anyChecked;
        }

        function downloadSelected() {
            const fileIds = Array.from(document.querySelectorAll('.file-checkbox:checked')).map(cb => cb.value);
            if (fileIds.length === 0) return;
            if (fileIds.length === 1) {
                window.location.href = 'download.php?id=' + fileIds[0];
            } else {
                window.location.href = 'download.php?bulk=' + fileIds.join(',');
            }
        }
        function downloadSelectedEaf() {
            const fileIds = Array.from(document.querySelectorAll('.file-checkbox:checked')).map(cb => cb.value);
            if (fileIds.length === 0) return;
            window.location.href = 'download-eaf.php?bulk=' + fileIds.join(',');
        }
        function editComment(fileId, displayEl) {
            const cell = displayEl.closest('.comment-cell');
            const textarea = cell.querySelector('.comment-edit');
            displayEl.classList.add('hidden');
            textarea.classList.remove('hidden');
            textarea.focus();
        }

        function saveComment(fileId, textarea) {
            const cell = textarea.closest('.comment-cell');
            const display = cell.querySelector('.comment-display');
            const comment = textarea.value.trim();

            fetch('update-comment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `id=${fileId}&comment=${encodeURIComponent(comment)}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    display.innerHTML = comment
                        ? `<span>${comment.replace(/</g, '&lt;')}</span>`
                        : '<span class="text-gray-300 italic">Add comment...</span>';
                    display.title = comment + (data.comment_by ? '\n\u2014 ' + data.comment_by : '');
                }
            })
            .catch(() => {});

            textarea.classList.add('hidden');
            display.classList.remove('hidden');
        }
    </script>

    </div><!-- end mainContent -->

    <!-- Resize handle -->
    <div id="resizeHandle" style="display: none; width: 6px; cursor: col-resize; background: #d1d5db; flex-shrink: 0;"></div>

    <!-- Preview sidebar -->
    <div id="previewSidebar" style="display: none; width: 50%; flex-shrink: 0; flex-direction: column; background: #fff; border-left: 1px solid #d1d5db;">
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
            <span id="previewTitle" style="font-size: 14px; font-weight: 500; color: #374151; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"></span>
            <button onclick="closePreview()" style="color: #6b7280; font-size: 20px; font-weight: bold; padding: 0 8px; cursor: pointer; border: none; background: none;" title="Close">&times;</button>
        </div>
        <iframe id="previewFrame" style="flex: 1; width: 100%; border: none;"></iframe>
    </div>

    </div><!-- end appLayout -->

    <script>
    // Preview sidebar
    function openPreview(url, filename, glos) {
        const sidebar = document.getElementById('previewSidebar');
        const handle = document.getElementById('resizeHandle');
        const frame = document.getElementById('previewFrame');
        const title = document.getElementById('previewTitle');

        frame.src = url;
        title.textContent = glos ? (filename + ' — ' + glos) : filename;
        title.title = title.textContent;
        sidebar.style.display = 'flex';
        handle.style.display = 'block';
    }

    function closePreview() {
        const sidebar = document.getElementById('previewSidebar');
        const handle = document.getElementById('resizeHandle');
        const frame = document.getElementById('previewFrame');

        sidebar.style.display = 'none';
        handle.style.display = 'none';
        frame.src = '';
    }

    // Resize logic
    (function() {
        const handle = document.getElementById('resizeHandle');
        const sidebar = document.getElementById('previewSidebar');
        let isResizing = false;

        handle.addEventListener('mousedown', function(e) {
            isResizing = true;
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';
            // Prevent iframe from eating mouse events
            document.getElementById('previewFrame').style.pointerEvents = 'none';
            e.preventDefault();
        });

        document.addEventListener('mousemove', function(e) {
            if (!isResizing) return;
            const newWidth = window.innerWidth - e.clientX;
            const minWidth = 300;
            const maxWidth = window.innerWidth - 400;
            sidebar.style.width = Math.max(minWidth, Math.min(maxWidth, newWidth)) + 'px';
        });

        document.addEventListener('mouseup', function() {
            if (isResizing) {
                isResizing = false;
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
                document.getElementById('previewFrame').style.pointerEvents = '';
            }
        });
    })();
    </script>
</body>
</html>
