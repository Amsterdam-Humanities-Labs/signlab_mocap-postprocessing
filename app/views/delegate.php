<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Capture Toewijzingen - Motion Capture Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <?php require_once __DIR__ . '/partials/header.php'; ?>

    <div class="container mx-auto px-4 py-4">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-gray-800">Capture Toewijzingen</h2>
            <p class="text-gray-600 mt-1">Wijs capture datums toe aan gebruikers</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Assignment Form -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold mb-4">Nieuwe toewijzing</h3>
                <form id="assignForm">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Gebruiker</label>
                        <select name="username" id="userSelect" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" required>
                            <option value="">-- Kies gebruiker --</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo htmlspecialchars($user['user']); ?>">
                                    <?php echo htmlspecialchars($user['user']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Capture datums</label>
                        <div class="max-h-64 overflow-y-auto border border-gray-200 rounded-md p-3 space-y-1">
                            <?php if (empty($availableDates)): ?>
                                <p class="text-gray-500 text-sm">Geen datums beschikbaar</p>
                            <?php else: ?>
                                <label class="flex items-center text-sm mb-2 pb-2 border-b">
                                    <input type="checkbox" id="selectAllDates" class="mr-2">
                                    <span class="font-medium">Alles selecteren</span>
                                </label>
                                <?php foreach ($availableDates as $d): ?>
                                    <label class="flex items-center justify-between text-sm date-label" data-date="<?php echo htmlspecialchars($d); ?>">
                                        <span>
                                            <input type="checkbox" name="dates[]" value="<?php echo htmlspecialchars($d); ?>" class="date-cb mr-2">
                                            <?php echo date('F j, Y', strtotime($d)); ?>
                                        </span>
                                        <span class="date-info text-gray-400 text-xs"><?php echo $dateCounts[$d] ?? 0; ?> files</span>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-2 px-4 rounded">
                        Toewijzen
                    </button>
                </form>
                <div id="assignMsg" class="mt-3 hidden text-sm rounded p-2"></div>
            </div>

            <!-- Current Assignments -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold mb-4">Huidige toewijzingen</h3>
                <div id="assignmentsList">
                    <?php if (empty($assignments)): ?>
                        <p class="text-gray-500">Nog geen toewijzingen</p>
                    <?php else: ?>
                        <?php foreach ($assignments as $username => $userAssignments): ?>
                            <?php $userTotal = array_sum(array_column($userAssignments, 'file_count')); ?>
                            <div class="mb-4 border-b pb-4 last:border-b-0" data-user="<?php echo htmlspecialchars($username); ?>">
                                <h4 class="font-semibold text-gray-800 mb-2">
                                    <?php echo htmlspecialchars($username); ?>
                                    <span class="text-sm font-normal text-gray-500">(<?php echo $userTotal; ?> files totaal)</span>
                                </h4>
                                <div class="space-y-1">
                                    <?php foreach ($userAssignments as $a): ?>
                                        <div class="flex items-center justify-between text-sm bg-gray-50 rounded px-3 py-2" data-assignment-id="<?php echo $a['id']; ?>">
                                            <span>
                                                <?php echo date('F j, Y', strtotime($a['capture_date'])); ?>
                                                <span class="text-gray-400 ml-2">(<?php echo $a['file_count']; ?> files)</span>
                                            </span>
                                            <button onclick="unassign(<?php echo $a['id']; ?>)" class="text-red-500 hover:text-red-700 font-bold" title="Verwijderen">&times;</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Build lookup: date → assigned username (max 1 user per date)
        const dateAssignedTo = <?php
            $dateLookup = [];
            foreach ($assignments as $u => $uAssignments) {
                foreach ($uAssignments as $a) {
                    $dateLookup[$a['capture_date']] = $u;
                }
            }
            echo json_encode($dateLookup, JSON_UNESCAPED_SLASHES);
        ?>;
        const dateCounts = <?php echo json_encode($dateCounts); ?>;

        // Disable dates that are already assigned to any user
        function updateDateStates() {
            document.querySelectorAll('.date-label').forEach(label => {
                const date = label.dataset.date;
                const cb = label.querySelector('.date-cb');
                const info = label.querySelector('.date-info');
                const assignedTo = dateAssignedTo[date];

                if (assignedTo) {
                    cb.disabled = true;
                    cb.checked = false;
                    label.classList.add('opacity-40');
                    info.textContent = assignedTo;
                    info.className = 'date-info text-xs text-green-600 font-medium';
                } else {
                    cb.disabled = false;
                    label.classList.remove('opacity-40');
                    info.textContent = (dateCounts[date] || 0) + ' files';
                    info.className = 'date-info text-gray-400 text-xs';
                }
            });

            const selectAll = document.getElementById('selectAllDates');
            if (selectAll) selectAll.checked = false;
        }

        updateDateStates();

        document.getElementById('selectAllDates')?.addEventListener('change', function() {
            document.querySelectorAll('.date-cb').forEach(cb => {
                if (!cb.disabled) cb.checked = this.checked;
            });
        });

        document.getElementById('assignForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'assign');

            fetch('delegate.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    const msg = document.getElementById('assignMsg');
                    msg.textContent = data.message;
                    msg.className = 'mt-3 text-sm rounded p-2 ' + (data.success ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800');
                    msg.classList.remove('hidden');
                    if (data.success) {
                        setTimeout(() => location.reload(), 1000);
                    }
                })
                .catch(() => alert('Fout bij toewijzen'));
        });

        function unassign(id) {
            if (!confirm('Weet u zeker dat u deze toewijzing wilt verwijderen?')) return;

            const formData = new FormData();
            formData.append('action', 'unassign');
            formData.append('id', id);

            fetch('delegate.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const el = document.querySelector(`[data-assignment-id="${id}"]`);
                        if (el) el.remove();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(() => alert('Fout bij verwijderen'));
        }
    </script>
</body>
</html>
