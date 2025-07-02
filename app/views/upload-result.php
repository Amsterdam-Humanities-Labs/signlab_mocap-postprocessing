<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Results - Motion Capture Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <header class="mb-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">Upload Results</h1>
            <div class="flex gap-4">
                <a href="index.php" class="text-blue-600 hover:text-blue-800">← Back to File List</a>
                <a href="upload.php" class="text-blue-600 hover:text-blue-800">Upload More Files</a>
            </div>
        </header>

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

        <?php if (empty($results['success']) && empty($results['errors'])): ?>
            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4">
                <div class="flex">
                    <div class="ml-3">
                        <p class="text-sm text-yellow-700">
                            No files were processed. Please select files to upload.
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="mt-8 bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold mb-4">Summary</h2>
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-green-100 p-4 rounded">
                    <p class="text-2xl font-bold text-green-800"><?php echo count($results['success']); ?></p>
                    <p class="text-sm text-green-600">Files Processed</p>
                </div>
                <div class="bg-red-100 p-4 rounded">
                    <p class="text-2xl font-bold text-red-800"><?php echo count($results['errors']); ?></p>
                    <p class="text-sm text-red-600">Errors</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>