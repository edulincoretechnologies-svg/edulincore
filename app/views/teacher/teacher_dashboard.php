<?php
/**
 * Teacher Dashboard View
 * Path: app/views/teacher/teacher_dashboard.php
 */

// Determine the correct mark entry URL based on the teacher's operating level
$markEntryUrl = (isset($operating_level) && strtolower($operating_level) === 'secondary') 
    ? BASE_URL . '/teacher/secondary_marks' 
    : BASE_URL . '/teacher/primary_marks';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard - <?= htmlspecialchars($school_name ?? 'EduLinCore') ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-50 font-sans antialiased text-slate-700">

    <!-- App Layout Container -->
    <div class="min-h-screen flex flex-col">

        <!-- Inner Wrapper for Sidebar and Main Content -->
        <div class="flex-1 flex min-h-0">

            <!-- Sidebar Navigation (Balanced & Collapsible) -->
            <aside id="sidebar" class="fixed md:static inset-y-0 left-0 z-50 w-64 bg-white border-r border-slate-200 transform -translate-x-full md:translate-x-0 transition-all duration-300 ease-in-out flex flex-col shadow-sm md:shadow-none flex-shrink-0">
                <!-- Sidebar Header / School Branding -->
                <div class="h-16 flex items-center justify-between px-5 border-b border-slate-100">
                    <div class="flex items-center space-x-2.5 truncate">
                        <div class="w-8 h-8 bg-indigo-50 text-indigo-600 rounded-lg flex items-center justify-center text-sm font-bold flex-shrink-0">
                            <i class="fa-solid fa-graduation-cap"></i>
                        </div>
                        <span class="font-bold text-slate-800 text-xs tracking-tight truncate" title="<?= htmlspecialchars($school_name ?? 'EduLinCore') ?>">
                            <?= htmlspecialchars($school_name ?? 'EduLinCore') ?>
                        </span>
                    </div>
                    <!-- Close Button for Mobile -->
                    <button id="sidebar-close" class="md:hidden text-slate-400 hover:text-slate-600 p-1">
                        <i class="fa-solid fa-xmark text-lg"></i>
                    </button>
                </div>

                <!-- Sidebar Menu Links -->
                <div class="px-3 py-5 flex-1 space-y-1 overflow-y-auto">
                    <p class="px-3 text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Main Menu</p>
                    
                    <a href="<?= BASE_URL ?>/teacher/dashboard" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl bg-indigo-50 text-indigo-600 font-semibold text-xs transition">
                        <i class="fa-solid fa-house w-4 text-center"></i>
                        <span>Dashboard</span>
                    </a>

                    <a href="<?= $markEntryUrl ?>" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-slate-900 font-medium text-xs transition">
                        <i class="fa-solid fa-pen-to-square w-4 text-center"></i>
                        <span>Mark Entry Portal</span>
                    </a>

                    <a href="<?= BASE_URL ?>/teacher_view_report" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-slate-900 font-medium text-xs transition">
                        <i class="fa-solid fa-file-lines w-4 text-center"></i>
                        <span>Progress Sheets</span>
                    </a>

                    <a href="<?= BASE_URL ?>/teacher_analysis_report" class="flex items-center space-x-3 px-3 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-slate-900 font-medium text-xs transition">
                        <i class="fa-solid fa-chart-pie w-4 text-center"></i>
                        <span>Performance Analysis</span>
                    </a>
                </div>

                <!-- Sidebar User Profile Footer -->
                <div class="p-3.5 border-t border-slate-100 bg-slate-50/50">
                    <div class="flex items-center justify-between">
                        <div class="truncate pr-2">
                            <p class="text-xs font-bold text-slate-800 truncate"><?= htmlspecialchars($full_name ?? 'Teacher') ?></p>
                            <p class="text-[9px] text-slate-400 uppercase font-semibold"><?= htmlspecialchars($operating_level ?? 'Primary') ?> Level</p>
                        </div>
                        <a href="<?= BASE_URL ?>/auth/logout" title="Sign Out" class="text-rose-500 hover:text-rose-600 p-2 rounded-lg hover:bg-rose-50 transition flex-shrink-0">
                            <i class="fa-solid fa-right-from-bracket text-xs"></i>
                        </a>
                    </div>
                </div>
            </aside>

            <!-- Main Wrapper Container -->
            <div id="main-content-wrapper" class="flex-1 flex flex-col min-w-0">
                
                <!-- Top Navigation Bar -->
                <header class="bg-white border-b border-slate-200 sticky top-0 z-30">
                    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <!-- Sidebar Toggle Button (Works on both Desktop & Mobile) -->
                            <button id="sidebar-toggle" class="text-slate-600 hover:text-slate-900 focus:outline-none p-2 rounded-lg hover:bg-slate-100 transition" title="Toggle Sidebar">
                                <i class="fa-solid fa-bars text-base"></i>
                            </button>
                            <div>
                                <h1 class="font-bold text-slate-800 text-sm sm:text-base leading-tight">Teacher Portal</h1>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-3">
                            <a href="<?= $markEntryUrl ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold transition shadow-xs flex items-center space-x-2">
                                <i class="fa-solid fa-pen-to-square"></i>
                                <span>Enter Marks</span>
                            </a>
                            <a href="<?= BASE_URL ?>/auth/logout" class="bg-white hover:bg-slate-50 text-slate-600 hover:text-rose-600 px-3 py-2 rounded-xl text-xs sm:text-sm font-medium transition flex items-center space-x-1.5 border border-slate-200">
                                <i class="fa-solid fa-right-from-bracket"></i>
                                <span class="hidden sm:inline">Sign Out</span>
                            </a>
                        </div>
                    </div>
                </header>

                <!-- Main Content Container -->
                <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
                    
                    <!-- Welcome Banner -->
                    <div class="bg-white rounded-2xl shadow-xs border border-slate-200 p-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div class="space-y-1">
                            <h2 class="text-lg sm:text-xl font-bold text-slate-800 tracking-tight">Welcome back, <?= htmlspecialchars($full_name) ?>!</h2>
                            <p class="text-slate-500 text-xs sm:text-sm">Here is an overview of your assigned classes and academic recording tools for this term.</p>
                        </div>
                        <div class="flex-shrink-0">
                            <span class="inline-flex items-center px-3 py-1.5 rounded-xl text-xs font-bold bg-indigo-50 text-indigo-700 border border-indigo-100 uppercase tracking-wide">
                                <?= htmlspecialchars($operating_level ?? 'Primary') ?> Level Mode
                            </span>
                        </div>
                    </div>

                    <!-- Stats Grid -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                        <div class="bg-white rounded-2xl p-5 shadow-xs border border-slate-200 flex items-center space-x-4">
                            <div class="w-11 h-11 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center text-lg font-bold flex-shrink-0">
                                <i class="fa-solid fa-book-open"></i>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Total Subject Allocations</p>
                                <h3 class="text-xl font-bold text-slate-800 mt-0.5"><?= (int)($totalSubjects ?? 0) ?></h3>
                            </div>
                        </div>

                        <div class="bg-white rounded-2xl p-5 shadow-xs border border-slate-200 flex items-center space-x-4">
                            <div class="w-11 h-11 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center text-lg font-bold flex-shrink-0">
                                <i class="fa-solid fa-school"></i>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Operating Level</p>
                                <h3 class="text-xl font-bold text-slate-800 uppercase mt-0.5"><?= htmlspecialchars($operating_level ?? 'Primary') ?></h3>
                            </div>
                        </div>

                        <div class="bg-white rounded-2xl p-5 shadow-xs border border-slate-200 flex items-center space-x-4 sm:col-span-2 lg:col-span-1">
                            <div class="w-11 h-11 bg-amber-50 text-amber-600 rounded-xl flex items-center justify-center text-lg font-bold flex-shrink-0">
                                <i class="fa-solid fa-chart-line"></i>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">System Status</p>
                                <h3 class="text-sm font-bold text-emerald-600 mt-1 flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span> Active Online
                                </h3>
                            </div>
                        </div>
                    </div>

                    <!-- Assignments & Quick Links Section -->
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        
                        <!-- Class / Subject Assignments Table -->
                        <div class="lg:col-span-2 bg-white rounded-2xl shadow-xs border border-slate-200 overflow-hidden flex flex-col">
                            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                                <h3 class="font-bold text-slate-800 text-base">Your Class & Subject Allocations</h3>
                            </div>
                            
                            <div class="overflow-x-auto flex-1">
                                <table class="w-full text-left border-collapse">
                                    <thead>
                                        <tr class="bg-slate-50/70 text-slate-400 text-[11px] uppercase tracking-wider font-bold">
                                            <th class="px-6 py-3">Grade / Level</th>
                                            <th class="px-6 py-3">Class Name</th>
                                            <th class="px-6 py-3">Subject</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 text-xs sm:text-sm text-slate-600">
                                        <?php if (!empty($assignments)): ?>
                                            <?php foreach ($assignments as $assignment): ?>
                                                <tr class="hover:bg-slate-50/50 transition">
                                                    <td class="px-6 py-3.5 font-semibold text-slate-800"><?= htmlspecialchars($assignment['grade_level']) ?></td>
                                                    <td class="px-6 py-3.5"><?= htmlspecialchars($assignment['class_name']) ?></td>
                                                    <td class="px-6 py-3.5 font-semibold text-indigo-600"><?= htmlspecialchars($assignment['subject_name']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="3" class="px-6 py-8 text-center text-slate-400">
                                                    No active subject or class assignments found linked to your account.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Quick Navigation Shortcuts -->
                        <div class="bg-white rounded-2xl shadow-xs border border-slate-200 p-5 flex flex-col">
                            <h3 class="font-bold text-slate-800 text-base mb-4">Quick Shortcuts</h3>
                            <div class="space-y-2.5">
                                <a href="<?= $markEntryUrl ?>" class="w-full flex items-center justify-between p-3 rounded-xl border border-slate-100 hover:border-indigo-100 hover:bg-indigo-50/30 text-slate-700 transition">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center text-xs">
                                            <i class="fa-solid fa-pen-clip"></i>
                                        </div>
                                        <span class="font-medium text-xs sm:text-sm">Mark Entry Portal</span>
                                    </div>
                                    <i class="fa-solid fa-chevron-right text-[10px] text-slate-300"></i>
                                </a>

                                <a href="<?= BASE_URL ?>/teacher_view_report" class="w-full flex items-center justify-between p-3 rounded-xl border border-slate-100 hover:border-indigo-100 hover:bg-indigo-50/30 text-slate-700 transition">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-xs">
                                            <i class="fa-solid fa-file-lines"></i>
                                        </div>
                                        <span class="font-medium text-xs sm:text-sm">Progress Sheet Reports</span>
                                    </div>
                                    <i class="fa-solid fa-chevron-right text-[10px] text-slate-300"></i>
                                </a>

                                <a href="<?= BASE_URL ?>/teacher_analysis_report" class="w-full flex items-center justify-between p-3 rounded-xl border border-slate-100 hover:border-indigo-100 hover:bg-indigo-50/30 text-slate-700 transition">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-8 h-8 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center text-xs">
                                            <i class="fa-solid fa-chart-pie"></i>
                                        </div>
                                        <span class="font-medium text-xs sm:text-sm">Performance Analysis</span>
                                    </div>
                                    <i class="fa-solid fa-chevron-right text-[10px] text-slate-300"></i>
                                </a>
                            </div>
                        </div>

                    </div>
                </main>

                <!-- Page Footer -->
                <footer class="bg-white border-t border-slate-200 py-4 px-6 text-center text-xs text-slate-500 flex flex-wrap items-center justify-center gap-2">
                    <span>Edulincore Technologies &reg; <?= date('Y'); ?>. All Rights Reserved.</span>
                    <span style="color: #334155;">|</span>
                    <span>Hotline: 0977323918</span>
                    <span style="color: #334155; display: inline-block;">|</span>
                    <a onclick="switchTab('about')" style="cursor: pointer;" class="hover:text-indigo-600 transition">Privacy Policy</a>
                    <span style="color: #334155;">|</span>
                    <a onclick="switchTab('about')" style="cursor: pointer;" class="hover:text-indigo-600 transition">Terms of Service</a>
                    <span style="color: #334155;">|</span>
                    <a onclick="switchTab('contact')" style="cursor: pointer;" class="hover:text-indigo-600 transition">Helpdesk</a>
                </footer>

            </div>
        </div>
    </div>

    <!-- Toggle & Auto-Hide Sidebar Script -->
    <script>
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebar-toggle');
        const sidebarClose = document.getElementById('sidebar-close');
        const mainContentWrapper = document.getElementById('main-content-wrapper');

        function toggleSidebar(e) {
            if (e) e.stopPropagation();
            if (window.innerWidth >= 768) {
                // Desktop toggle: slide completely out of view or back in
                sidebar.classList.toggle('md:-ml-64');
                sidebar.classList.toggle('-translate-x-full');
            } else {
                // Mobile toggle: slide drawer
                sidebar.classList.toggle('-translate-x-full');
            }
        }

        sidebarToggle.addEventListener('click', toggleSidebar);
        sidebarClose.addEventListener('click', toggleSidebar);

        // Auto-hide when clicking anywhere on the main page wrapper on mobile devices
        mainContentWrapper.addEventListener('click', () => {
            if (window.innerWidth < 768) {
                if (!sidebar.classList.contains('-translate-x-full')) {
                    sidebar.classList.add('-translate-x-full');
                }
            }
        });
    </script>

</body>
</html>