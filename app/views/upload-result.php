<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Results - Motion Capture Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <?php require_once __DIR__ . '/partials/header.php'; ?>
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <?php
        $successCount = count($results['success']);
        $errorCount = count($results['errors']);
        $totalCount = $successCount + $errorCount;
        $allSuccess = $errorCount === 0 && $successCount > 0;
        $allFailed = $successCount === 0 && $errorCount > 0;
        $partial = $successCount > 0 && $errorCount > 0;
        ?>

        <!-- Prominent Summary Banner -->
        <?php if ($allSuccess): ?>
        <div class="bg-green-500 text-white rounded-lg shadow-lg p-8 mb-8 text-center">
            <div class="text-6xl mb-4">&#10003;</div>
            <h1 class="text-3xl font-bold mb-2"><?php echo $successCount; ?> of <?php echo $totalCount; ?> files uploaded successfully</h1>
            <p class="text-green-100 text-lg">All files matched and processed</p>
        </div>
        <?php elseif ($allFailed): ?>
        <div class="bg-red-500 text-white rounded-lg shadow-lg p-8 mb-8 text-center">
            <div class="text-6xl mb-4">&#10007;</div>
            <h1 class="text-3xl font-bold mb-2">0 of <?php echo $totalCount; ?> files uploaded</h1>
            <p class="text-red-100 text-lg">All files had errors (see details below)</p>
        </div>
        <?php elseif ($partial): ?>
        <div class="bg-yellow-500 text-white rounded-lg shadow-lg p-8 mb-8 text-center">
            <div class="text-6xl mb-4">&#9888;</div>
            <h1 class="text-3xl font-bold mb-2"><?php echo $successCount; ?> of <?php echo $totalCount; ?> files uploaded</h1>
            <p class="text-yellow-100 text-lg"><?php echo $errorCount; ?> file<?php echo $errorCount > 1 ? 's' : ''; ?> had errors (see details below)</p>
        </div>
        <?php else: ?>
        <div class="bg-gray-500 text-white rounded-lg shadow-lg p-8 mb-8 text-center">
            <div class="text-6xl mb-4">?</div>
            <h1 class="text-3xl font-bold mb-2">No files processed</h1>
            <p class="text-gray-100 text-lg">Please select files to upload</p>
        </div>
        <?php endif; ?>

        <div class="flex gap-4 mb-8 justify-center">
            <a href="index.php" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded">
                &#8592; Back to File List
            </a>
            <a href="upload.php" class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-6 rounded">
                Upload More Files
            </a>
        </div>

        <?php if (!empty($results['success'])): ?>
            <div class="bg-green-50 border-l-4 border-green-400 p-4 mb-6">
                <div class="flex">
                    <div class="ml-3">
                        <h3 class="text-lg font-medium text-green-800 mb-2">Successfully Processed</h3>
                        <ul class="list-disc list-inside text-sm text-green-700">
                            <?php foreach ($results['success'] as $message): ?>
                                <li><?php echo htmlspecialchars($message); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($results['errors'])): ?>
            <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-6">
                <div class="flex">
                    <div class="ml-3">
                        <h3 class="text-lg font-medium text-red-800 mb-2">Errors</h3>
                        <ul class="list-disc list-inside text-sm text-red-700">
                            <?php foreach ($results['errors'] as $message): ?>
                                <li><?php echo htmlspecialchars($message); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>
</body>
</html>