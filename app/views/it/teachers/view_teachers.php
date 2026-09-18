<?php
/**
 * EduLinCore | IT Officer Portal
 * Staff Workloads Hub View (Path: app/Views/it/teachers/view_teachers.php)
 */
require_once APP_PATH . '/Controllers/IT/ViewTeachersController.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Staff Workloads Hub | EduLinCore IT Portal</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f8fafc; color: #1e293b; }
        .sidebar { width: 280px; height: 100vh; position: fixed; left: 0; top: 0; background: #003366; z-index: 50; transition: all 0.4s ease; }
        .content-main { margin-left: 280px; min-height: 100vh; transition: all 0.4s ease; }
        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .content-main { margin-left: 0; }
        }
    </style>
</head>
<body class="bg-slate-50">

<!-- Sidebar Menu -->
<aside class="sidebar shadow-2xl" id="sidebar">
    <div class="p-8 h-full flex flex-col">
        <div class="flex items-center gap-3 mb-12">
            <div class="w-10 h-10 bg-white/10 rounded-xl flex items-center justify-center border border-white/20">
                <i class="fa-solid fa-microchip text-[#ff9800]"></i>
            </div>
            <span class="text-white font-extrabold text-xl tracking-tighter">EduLin<span class="text-[#ff9800]">C</span>ore</span>
        </div>

        <nav class="space-y-3 flex-grow">
            <p class="text-white/30 text-[10px] font-black uppercase tracking-[0.2em] mb-4 ml-4">IT Systems Menu</p>
            <a href="<?= BASE_URL ?>/it/dashboard" class="flex items-center gap-4 px-6 py-4 text-white/60 hover:text-white hover:bg-white/5 rounded-2xl transition-all font-bold text-sm">
                <i class="fa-solid fa-house"></i> Home Dashboard
            </a>
            <a href="<?= BASE_URL ?>/it/teachers" class="flex items-center gap-4 px-6 py-4 text-white bg-white/10 rounded-2xl font-bold text-sm">
                <i class="fa-solid fa-user-gear text-[#ff9800]"></i> Staff Workloads
            </a>
            <a href="<?= BASE_URL ?>/it/teachers/register" class="flex items-center gap-4 px-6 py-4 text-white/60 hover:text-white hover:bg-white/5 rounded-2xl transition-all font-bold text-sm">
                <i class="fa-solid fa-user-plus"></i> Register Teacher
            </a>
        </nav>

        <div class="pt-6 border-t border-white/10">
            <a href="<?= BASE_URL ?>/auth/logout" class="flex items-center gap-4 px-6 py-4 text-red-400 hover:bg-red-500/10 rounded-2xl transition-all font-bold text-sm">
                <i class="fa-solid fa-power-off"></i> Terminate Session
            </a>
        </div>
    </div>
</aside>

<div class="content-main flex flex-col min-h-screen">
    <!-- Header -->
    <header class="bg-white/80 backdrop-blur-md border-b border-slate-200 sticky top-0 z-40 px-6 py-4">
        <div class="max-w-7xl mx-auto flex justify-between items-center">
            <div class="flex items-center gap-4">
                <button onclick="document.getElementById('sidebar').classList.toggle('open')" class="lg:hidden w-10 h-10 flex items-center justify-center bg-slate-100 rounded-xl text-[#003366]">
                    <i class="fa-solid fa-bars-staggered"></i>
                </button>
                <div>
                    <h2 class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none mb-1">IT Officer Portal</h2>
                    <p class="text-xs font-bold text-[#003366]">Staff & Workload Management Directory</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right hidden md:block">
                    <p class="text-[9px] font-black text-slate-400 uppercase">Administrator</p>
                    <p class="text-xs font-bold text-slate-700"><?= htmlspecialchars($username) ?></p>
                </div>
                <div class="h-10 w-10 bg-gradient-to-tr from-[#003366] to-[#005bb5] text-white rounded-2xl flex items-center justify-center font-black text-sm shadow-lg">
                    <?= substr($username, 0, 1) ?>
                </div>
            </div>
        </div>
    </header>

    <!-- Content Body -->
    <main class="p-6 lg:p-10 max-w-7xl mx-auto flex-grow w-full">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
            <div>
                <h1 class="text-2xl font-black text-slate-800 tracking-tight">Registered Teachers</h1>
                <p class="text-xs text-slate-500 font-medium">Manage teacher workloads, subject allocations, and mappings.</p>
            </div>
            <a href="<?= BASE_URL ?>/it/teachers/register" class="bg-[#003366] text-white px-6 py-3.5 rounded-2xl font-black text-xs uppercase tracking-wider shadow-lg hover:bg-[#002244] transition-all flex items-center gap-2">
                <i class="fa-solid fa-plus text-[#ff9800]"></i> Add New Teacher
            </a>
        </div>

        <!-- Teachers Table / Card Grid -->
        <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100 text-[10px] font-black uppercase tracking-wider text-slate-400">
                            <th class="p-6">Teacher Name</th>
                            <th class="p-6">Contact Details</th>
                            <th class="p-6 text-center">Active Workload Modules</th>
                            <th class="p-6 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-xs font-bold text-slate-700">
                        <?php if (empty($teachers)): ?>
                            <tr>
                                <td colspan="4" class="p-12 text-center text-slate-400 font-medium italic">
                                    No teachers registered in this school hub yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($teachers as $t): ?>
                                <tr class="hover:bg-slate-50/80 transition-colors">
                                    <td class="p-6">
                                        <div class="font-extrabold text-slate-900 text-sm"><?= htmlspecialchars($t['full_name']) ?></div>
                                        <div class="text-[10px] font-medium text-slate-400">ID: #<?= $t['id'] ?></div>
                                    </td>
                                    <td class="p-6">
                                        <div class="text-slate-600"><i class="fa-solid fa-phone text-slate-400 mr-2 text-[10px]"></i><?= htmlspecialchars($t['phone_number'] ?? 'N/A') ?></div>
                                    </td>
                                    <td class="p-6 text-center">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-[10px] font-black bg-blue-50 text-blue-600 border border-blue-100">
                                            <?= $t['assignment_count'] ?> Subject/Class Mapping(s)
                                        </span>
                                    </td>
                                    <td class="p-6 text-right space-x-2">
                                        <a href="<?= BASE_URL ?>/it/teacher-assignments?teacher_id=<?= $t['id'] ?>" class="inline-flex items-center gap-1.5 bg-amber-50 text-amber-600 px-4 py-2.5 rounded-xl font-black text-[10px] uppercase tracking-wider hover:bg-amber-500 hover:text-white transition-all shadow-sm">
                                            <i class="fa-solid fa-pen-to-square"></i> Assign Workload
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="py-6 text-center text-slate-400 text-[11px] font-medium border-t border-slate-100">
        EduLinCore Zambia &bull; Engineered for Modern School Administration
    </footer>
</div>

</body>
</html>