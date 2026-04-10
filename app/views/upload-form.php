<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Processed Files - Motion Capture Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <?php require_once __DIR__ . '/partials/header.php'; ?>
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-2">Upload Processed Files</h2>
            <a href="index.php" class="text-blue-600 hover:text-blue-800">&larr; Back to File List</a>
        </div>

        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4">Upload Individual Files</h2>
            <form action="upload.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Select FBX files to upload
                    </label>
                    <input type="file" name="files[]" multiple accept=".fbx" class="block w-full text-sm text-gray-500
                        file:mr-4 file:py-2 file:px-4
                        file:rounded-full file:border-0
                        file:text-sm file:font-semibold
                        file:bg-blue-50 file:text-blue-700
                        hover:file:bg-blue-100">
                    <p class="mt-1 text-sm text-gray-500">
                        Select multiple .fbx files that have been post-processed
                    </p>
                </div>
                <div>
                    <label class="flex items-center">
                        <input type="checkbox" name="allow_overwrite" value="1" class="mr-2">
                        <span class="text-sm text-gray-700">Allow overwriting of already processed files</span>
                    </label>
                </div>
                <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                    Upload Files
                </button>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold mb-4">Upload ZIP File</h2>
            <form action="upload.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Select ZIP file containing processed files
                    </label>
                    <input type="file" name="zipfile" accept=".zip" class="block w-full text-sm text-gray-500
                        file:mr-4 file:py-2 file:px-4
                        file:rounded-full file:border-0
                        file:text-sm file:font-semibold
                        file:bg-green-50 file:text-green-700
                        hover:file:bg-green-100">
                    <p class="mt-1 text-sm text-gray-500">
                        The ZIP file should contain a 'post_processed' folder with the processed .fbx files
                    </p>
                </div>
                <div>
                    <label class="flex items-center">
                        <input type="checkbox" name="allow_overwrite" value="1" class="mr-2">
                        <span class="text-sm text-gray-700">Allow overwriting of already processed files</span>
                    </label>
                </div>
                <button type="submit" class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded">
                    Upload ZIP
                </button>
            </form>
        </div>

        <div class="mt-6 bg-blue-50 border-l-4 border-blue-400 p-4">
            <div class="flex">
                <div class="ml-3">
                    <p class="text-sm text-blue-700">
                        <strong>Note:</strong> Files will be automatically matched by their filename. 
                        By default, already processed files will be skipped. Check "Allow overwriting" to replace existing processed files.
                    </p>
                </div>
            </div>
        </div>
    </div>
    <script>
        // Add confirmation when overwrite is not checked
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const overwriteCheckbox = this.querySelector('input[name="allow_overwrite"]');
                if (!overwriteCheckbox.checked) {
                    const confirmMessage = 'Files that are already processed will be skipped. If you want to overwrite them, check the "Allow overwriting" option.\n\nDo you want to continue?';
                    if (!confirm(confirmMessage)) {
                        e.preventDefault();
                    }
                }
            });
        });
    </script>
</body>
</html>