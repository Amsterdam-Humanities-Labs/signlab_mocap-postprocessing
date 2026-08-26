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

        <!-- Drop Zone -->
        <div id="dropZone" class="bg-white rounded-lg shadow p-12 mb-6 text-center border-2 border-dashed border-gray-300 transition-all cursor-pointer"
             onclick="document.getElementById('fileInput').click()">
            <div id="dropDefault">
                <div class="text-5xl text-gray-300 mb-4">&#8681;</div>
                <p class="text-lg text-gray-600 font-medium">Drop .fbx or .zip files here</p>
                <p class="text-sm text-gray-400 mt-2">or click to browse</p>
            </div>
            <div id="dropHover" class="hidden">
                <div class="text-5xl text-blue-500 mb-4">&#8681;</div>
                <p class="text-xl text-blue-600 font-bold">Drop here to upload...</p>
            </div>
            <input type="file" id="fileInput" multiple accept=".fbx,.zip" class="hidden">
        </div>

        <div class="flex items-center gap-4 mb-6">
            <label class="flex items-center text-sm">
                <input type="checkbox" id="allowOverwrite" class="mr-2">
                <span class="text-gray-700">Allow overwriting of already processed files</span>
            </label>
        </div>

        <!-- Upload Progress -->
        <div id="uploadProgress" class="hidden mb-6">
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center gap-3">
                    <div class="animate-spin w-5 h-5 border-2 border-blue-500 border-t-transparent rounded-full"></div>
                    <span id="progressText" class="text-gray-700">Uploading...</span>
                </div>
            </div>
        </div>

        <!-- Results -->
        <div id="results" class="hidden space-y-4">
            <div id="successList" class="hidden bg-green-50 border-l-4 border-green-400 p-4 rounded">
                <h3 class="text-lg font-medium text-green-800 mb-2">Successfully Processed</h3>
                <ul id="successItems" class="list-disc list-inside text-sm text-green-700 space-y-1"></ul>
            </div>
            <div id="errorList" class="hidden bg-red-50 border-l-4 border-red-400 p-4 rounded">
                <h3 class="text-lg font-medium text-red-800 mb-2">Errors</h3>
                <ul id="errorItems" class="list-disc list-inside text-sm text-red-700 space-y-1"></ul>
            </div>
        </div>
    </div>

    <script>
    var dropZone = document.getElementById("dropZone");
    var fileInput = document.getElementById("fileInput");

    // Drag visual feedback
    ["dragenter", "dragover"].forEach(function(evt) {
        dropZone.addEventListener(evt, function(e) {
            e.preventDefault();
            document.getElementById("dropDefault").classList.add("hidden");
            document.getElementById("dropHover").classList.remove("hidden");
            dropZone.classList.remove("border-gray-300");
            dropZone.classList.add("border-blue-500", "bg-blue-50");
        });
    });

    ["dragleave", "drop"].forEach(function(evt) {
        dropZone.addEventListener(evt, function(e) {
            e.preventDefault();
            document.getElementById("dropDefault").classList.remove("hidden");
            document.getElementById("dropHover").classList.add("hidden");
            dropZone.classList.add("border-gray-300");
            dropZone.classList.remove("border-blue-500", "bg-blue-50");
        });
    });

    // Handle drop
    dropZone.addEventListener("drop", function(e) {
        e.preventDefault();
        if (e.dataTransfer.files.length) uploadFiles(e.dataTransfer.files);
    });

    // Handle file input
    fileInput.addEventListener("change", function(e) {
        if (e.target.files.length) uploadFiles(e.target.files);
    });

    // Also handle drag on whole page
    document.addEventListener("dragover", function(e) { e.preventDefault(); });
    document.addEventListener("drop", function(e) { e.preventDefault(); });

    // PHP's max_file_uploads (default 20) silently drops files beyond the limit
    // when sent in a single request. Send .fbx uploads in batches safely under it
    // so large selections (e.g. 50 files) all get through.
    var BATCH_SIZE = 15;

    function uploadFiles(files) {
        var fileArr = Array.prototype.slice.call(files);
        var overwrite = document.getElementById("allowOverwrite").checked;
        var zip = fileArr.filter(function(f) { return f.name.toLowerCase().endsWith(".zip"); })[0];
        var fbx = fileArr.filter(function(f) { return !f.name.toLowerCase().endsWith(".zip"); });

        document.getElementById("uploadProgress").classList.remove("hidden");
        document.getElementById("results").classList.add("hidden");

        var green = [], red = [];

        function sendBatch(batch, isZip) {
            var fd = new FormData();
            if (isZip) {
                fd.append("zipfile", batch[0]);
            } else {
                batch.forEach(function(f) { fd.append("files[]", f); });
            }
            if (overwrite) { fd.append("allow_overwrite", "1"); }
            return fetch("upload.php", { method: "POST", body: fd })
                .then(function(r) { return r.text(); })
                .then(function(html) {
                    var doc = new DOMParser().parseFromString(html, "text/html");
                    doc.querySelectorAll(".bg-green-50 li").forEach(function(li) { green.push(li.textContent.trim()); });
                    doc.querySelectorAll(".bg-red-50 li").forEach(function(li) { red.push(li.textContent.trim()); });
                });
        }

        // Build the ordered list of batches (a zip goes as one request; fbx are chunked).
        var batches = [];
        if (zip) { batches.push({ files: [zip], isZip: true }); }
        for (var i = 0; i < fbx.length; i += BATCH_SIZE) {
            batches.push({ files: fbx.slice(i, i + BATCH_SIZE), isZip: false });
        }

        var total = zip ? 1 : fbx.length;
        var done = 0;

        function runNext(idx) {
            if (idx >= batches.length) {
                document.getElementById("uploadProgress").classList.add("hidden");
                renderResults(green, red);
                return;
            }
            var b = batches[idx];
            document.getElementById("progressText").textContent = b.isZip
                ? "Uploading zip..."
                : ("Uploading " + (done + 1) + "–" + (done + b.files.length) + " of " + total + "...");
            sendBatch(b.files, b.isZip)
                .catch(function(e) { red.push("Batch failed: " + e.message); })
                .then(function() { done += b.files.length; runNext(idx + 1); });
        }

        runNext(0);
    }

    function renderResults(green, red) {
        var results = document.getElementById("results");
        var successList = document.getElementById("successList");
        var errorList = document.getElementById("errorList");
        var successItems = document.getElementById("successItems");
        var errorItems = document.getElementById("errorItems");

        successItems.innerHTML = "";
        errorItems.innerHTML = "";
        successList.classList.add("hidden");
        errorList.classList.add("hidden");

        if (green.length) {
            successList.classList.remove("hidden");
            successList.querySelector("h3").textContent = "Successfully Processed (" + green.length + ")";
            green.forEach(function(t) { var el = document.createElement("li"); el.textContent = t; successItems.appendChild(el); });
        }
        if (red.length) {
            errorList.classList.remove("hidden");
            errorList.querySelector("h3").textContent = "Errors / Skipped (" + red.length + ")";
            red.forEach(function(t) { var el = document.createElement("li"); el.textContent = t; errorItems.appendChild(el); });
        }
        results.classList.remove("hidden");
    }
    </script>
</body>
</html>
