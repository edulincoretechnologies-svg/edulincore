<?php
/**
 * School IT: Teacher Mapping / Assignments View
 * Path: app/views/it/teachers/assignments.php
 */
require_once APP_PATH . '/controllers/it/TeacherAssignmentController.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Teacher Mapping | IT Officer Portal - EduLinCore</title>
    
    <script>
        window.addEventListener('pageshow', function(event) {
            if (event.persisted || (performance.navigation && performance.navigation.type === 2)) {
                window.location.reload();
            }
        });
    </script>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- Fixed lowercase CSS Asset Path -->
    <link rel="stylesheet" href="<?= rtrim(BASE_URL, '/') ?>/public/assets/css/it_portal.css">
    
    <style>
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background: #f1f5f9; 
            color: #000000; 
            padding-bottom: 180px; 
        }

        input[type="checkbox"]:checked + label { 
            background: #0284c7 !important; 
            color: #ffffff !important; 
            border-color: #0284c7 !important; 
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.2);
        }
        #finalSaveBtn { display: none; }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        @media (max-width: 1024px) {
            aside { transform: translateX(-100%); transition: transform 0.3s ease; }
            aside.mobile-open { transform: translateX(0); }
            .main-content-wrapper { margin-left: 0 !important; }
            .footer-bar { left: 0 !important; }
        }
    </style>
</head>
<body class="bg-[#f1f5f9]">

<aside id="sidebar" class="w-[280px] bg-white border-r border-slate-200 fixed top-0 left-0 h-screen flex flex-col z-50 shadow-sm overflow-y-auto">
    <div class="p-6 border-b border-slate-200 flex items-center justify-between bg-white">
        <span class="text-xl font-black tracking-tight text-black">EduLin<span class="text-[#0284c7]">C</span>ore</span>
        <button onclick="toggleMobileMenu()" class="lg:hidden text-slate-500 hover:text-black"><i class="fa-solid fa-xmark text-lg"></i></button>
    </div>
    
    <div class="p-4 mx-4 my-4 bg-slate-100 rounded-2xl border border-slate-200 flex items-center gap-3">
        <div class="w-10 h-10 bg-[#0284c7] text-white rounded-xl flex items-center justify-center font-black text-sm shadow-sm">
            <?= substr($username, 0, 1) ?>
        </div>
        <div class="overflow-hidden">
            <div class="font-bold text-xs text-black truncate"><?= htmlspecialchars($username) ?></div>
            <div class="text-[10px] text-slate-500 font-bold uppercase tracking-wider">School IT Officer</div>
        </div>
    </div>
    
    <nav class="px-3 space-y-1.5 flex-1 pb-6">
        <a href="<?= BASE_URL ?>/it/dashboard" class="flex items-center gap-3 px-4 py-3 rounded-xl text-xs font-semibold text-slate-700 hover:bg-slate-100 hover:text-black transition">
            <i class="fa-solid fa-house w-5 text-center"></i> Dashboard
        </a>

        <div class="nav-group active">
            <div class="flex items-center justify-between px-4 py-3 rounded-xl text-xs font-semibold text-black bg-slate-100 cursor-pointer transition" onclick="toggleSubMenu(this)">
                <div class="flex items-center gap-3"><i class="fa-solid fa-chalkboard-user w-5 text-center text-[#0284c7]"></i> Teachers</div>
                <i class="fa-solid fa-chevron-down text-[10px] opacity-70"></i>
            </div>
            <div class="pl-6 space-y-1 mt-1 block">
                <a href="<?= BASE_URL ?>/it/teachers/register" class="block px-4 py-2.5 rounded-xl text-xs font-medium text-slate-600 hover:bg-slate-100 hover:text-black transition">Add New Teacher</a>
                <a href="<?= BASE_URL ?>/it/teachers" class="block px-4 py-2.5 rounded-xl text-xs font-medium text-slate-600 hover:bg-slate-100 hover:text-black transition">Manage Teachers</a>
                <a href="<?= BASE_URL ?>/it/teachers/assignments" class="block px-4 py-2.5 rounded-xl text-xs font-bold bg-[#0284c7] text-white shadow-md transition">Define Assignment</a>
            </div>
        </div>

        <div class="nav-group">
            <div class="flex items-center justify-between px-4 py-3 rounded-xl text-xs font-semibold text-slate-700 hover:bg-slate-100 hover:text-black cursor-pointer transition" onclick="toggleSubMenu(this)">
                <div class="flex items-center gap-3"><i class="fa-solid fa-school w-5 text-center"></i> School Setup</div>
                <i class="fa-solid fa-chevron-down text-[10px] opacity-70"></i>
            </div>
            <div class="pl-6 space-y-1 mt-1 hidden">
                <a href="<?= BASE_URL ?>/it/setup/grades" class="block px-4 py-2.5 rounded-xl text-xs font-medium text-slate-600 hover:bg-slate-100 hover:text-black transition">Manage Grades</a>
                <a href="<?= BASE_URL ?>/it/setup/classes" class="block px-4 py-2.5 rounded-xl text-xs font-medium text-slate-600 hover:bg-slate-100 hover:text-black transition">Manage Classes</a>
                <a href="<?= BASE_URL ?>/it/setup/subjects" class="block px-4 py-2.5 rounded-xl text-xs font-medium text-slate-600 hover:bg-slate-100 hover:text-black transition">Manage Subjects</a>
            </div>
        </div>

        <div class="nav-group">
            <div class="flex items-center justify-between px-4 py-3 rounded-xl text-xs font-semibold text-slate-700 hover:bg-slate-100 hover:text-black cursor-pointer transition" onclick="toggleSubMenu(this)">
                <div class="flex items-center gap-3"><i class="fa-solid fa-file-lines w-5 text-center"></i> Exams & Reports</div>
                <i class="fa-solid fa-chevron-down text-[10px] opacity-70"></i>
            </div>
            <div class="pl-6 space-y-1 mt-1 hidden">
                <a href="<?= BASE_URL ?>/it/reports/cards" class="block px-4 py-2.5 rounded-xl text-xs font-medium text-slate-600 hover:bg-slate-100 hover:text-black transition">Report Cards</a>
                <a href="<?= BASE_URL ?>/it/reports/scoresheets" class="block px-4 py-2.5 rounded-xl text-xs font-medium text-slate-600 hover:bg-slate-100 hover:text-black transition">Score Sheets</a>
            </div>
        </div>

        <div class="pt-4 mt-4 border-t border-slate-200">
            <a href="<?= BASE_URL ?>/auth/logout" class="flex items-center gap-3 px-4 py-3 rounded-xl text-xs font-semibold text-red-600 hover:bg-red-50 transition">
                <i class="fa-solid fa-power-off w-5 text-center"></i> Secure Logout
            </a>
        </div>
    </nav>
</aside>

<div class="main-content-wrapper ml-[280px] flex flex-col min-h-screen">
    <header class="bg-[#0284c7] text-white px-6 lg:px-8 py-4 flex justify-between items-center shadow-md">
        <div class="flex items-center gap-3">
            <button onclick="toggleMobileMenu()" class="lg:hidden text-white focus:outline-none"><i class="fa-solid fa-bars text-lg"></i></button>
            <span class="font-extrabold text-sm tracking-wide text-white">EduLinCore IT Portal</span>
        </div>
        <div class="hidden sm:flex items-center gap-2 bg-white/15 px-4 py-2 rounded-xl border border-white/20 text-xs font-bold text-white">
            <i class="fa-solid fa-school text-white"></i>
            <span>SCHOOL IT MANAGEMENT</span>
        </div>
    </header>

    <div class="p-4 sm:p-8 lg:p-10 flex-1">
        <main class="max-w-6xl mx-auto">
            
            <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm mb-8 flex flex-col md:flex-row md:items-center justify-between gap-6">
                <div class="w-full md:w-1/2">
                    <label class="text-[11px] font-black text-slate-500 uppercase tracking-widest block mb-2">Select Teacher for Mapping</label>
                    <select onchange="location.href='?teacher_id='+this.value" class="w-full p-4 bg-slate-50 border border-slate-300 rounded-2xl font-bold text-black shadow-inner outline-none text-sm transition focus:border-[#0284c7] focus:bg-white">
                        <option value="">-- Choose Teacher Name --</option>
                        <?php foreach($teachers as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= $selected_teacher_id == $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars(strtoupper($t['full_name'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if($selected_teacher_id): ?>
                <div class="flex items-center">
                    <button onclick="confirmCancel()" class="w-full md:w-auto bg-red-50 text-red-600 border border-red-200 px-6 py-4 rounded-2xl font-extrabold text-xs uppercase tracking-wider hover:bg-red-600 hover:text-white transition shadow-sm">
                        <i class="fa-solid fa-xmark mr-2 text-sm"></i> Cancel Session
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($selected_teacher_id): ?>
            
            <?php if(!empty($current_summary)): ?>
            <div class="mb-10">
                <h3 class="text-xs font-black text-slate-500 uppercase tracking-widest mb-4 flex items-center gap-2">
                    <i class="fa-solid fa-list-check text-[#0284c7]"></i> Active Workload Assignments
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                    <?php foreach($current_summary as $row): ?>
                        <div class="p-5 bg-white border border-slate-200 rounded-2xl flex justify-between items-center shadow-sm border-l-4 border-l-[#0284c7] hover:shadow-md transition">
                            <div>
                                <p class="text-[11px] font-extrabold text-black tracking-wide uppercase mb-1"><?= htmlspecialchars($row['subject_name']) ?></p>
                                <p class="text-xs font-bold text-slate-700 bg-slate-100 px-2.5 py-1 rounded-lg inline-block"><?= htmlspecialchars($row['grade_level']) ?> &bull; <?= htmlspecialchars($row['class_name']) ?></p>
                            </div>
                            <a href="javascript:confirmAssignmentDelete(<?= $row['id'] ?>)" class="w-10 h-10 rounded-xl bg-slate-100 text-slate-500 hover:bg-red-50 hover:text-red-600 flex items-center justify-center transition">
                                <i class="fa-solid fa-trash-can text-sm"></i>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="teacher_id" value="<?= $selected_teacher_id ?>">
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-32">
                    
                    <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                        <h4 class="text-[11px] font-black text-slate-500 uppercase tracking-widest mb-4 flex items-center gap-2">
                            <i class="fa-solid fa-layer-group text-slate-500"></i> Select Grade Level
                        </h4>
                        <div class="space-y-2">
                            <?php foreach($grades as $g): ?>
                                <input type="checkbox" name="grades[]" value="<?= htmlspecialchars($g['grade_name']) ?>" id="g_<?= md5($g['grade_name']) ?>" class="hidden">
                                <label for="g_<?= md5($g['grade_name']) ?>" class="block px-4 py-3.5 border border-slate-200 bg-slate-50 rounded-2xl text-xs font-bold text-black cursor-pointer hover:bg-slate-100 transition"><?= htmlspecialchars($g['grade_name']) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                        <h4 class="text-[11px] font-black text-slate-500 uppercase tracking-widest mb-4 flex items-center gap-2">
                            <i class="fa-solid fa-school-flag text-slate-500"></i> Select Class Room
                        </h4>
                        <div class="grid grid-cols-2 gap-2">
                            <?php foreach($classes as $c): ?>
                                <input type="checkbox" name="classes[]" value="<?= htmlspecialchars($c['class_name']) ?>" id="c_<?= md5($c['class_name']) ?>" class="hidden">
                                <label for="c_<?= md5($c['class_name']) ?>" class="block text-center py-3.5 border border-slate-200 bg-slate-50 rounded-2xl text-xs font-bold text-black cursor-pointer hover:bg-slate-100 transition"><?= htmlspecialchars($c['class_name']) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                        <h4 class="text-[11px] font-black text-slate-500 uppercase tracking-widest mb-4 flex items-center gap-2">
                            <i class="fa-solid fa-book text-slate-500"></i> Select Subject(s)
                        </h4>
                        <div class="space-y-2 max-h-[320px] overflow-y-auto pr-1">
                            <?php foreach($subjects as $s): ?>
                                <input type="checkbox" name="subject_ids[]" value="<?= $s['id'] ?>" id="s_<?= $s['id'] ?>" class="hidden">
                                <label for="s_<?= $s['id'] ?>" class="block px-4 py-3.5 border border-slate-200 bg-slate-50 rounded-2xl text-xs font-bold text-black cursor-pointer hover:bg-slate-100 transition"><?= htmlspecialchars($s['subject_name']) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="footer-bar fixed bottom-0 left-[280px] right-0 bg-white/95 backdrop-blur-md border-t border-slate-200 p-5 z-40 shadow-lg">
                    <div class="max-w-5xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-4">
                        <div class="w-full sm:w-auto flex items-center justify-between sm:justify-start gap-4 bg-slate-100 px-5 py-3 rounded-2xl border border-slate-200 <?= empty($current_summary) ? 'opacity-40 pointer-events-none' : '' ?>">
                            <span class="text-xs font-bold text-black">Mapping complete?</span>
                            <div class="flex gap-2">
                                <button type="button" onclick="showSave(true)" class="px-4 py-2 bg-emerald-600 text-white rounded-xl text-[10px] font-black uppercase tracking-wider hover:bg-emerald-700 transition">Yes</button>
                                <button type="button" onclick="showSave(false)" class="px-4 py-2 bg-slate-300 text-black rounded-xl text-[10px] font-black uppercase tracking-wider hover:bg-slate-400 transition">No</button>
                            </div>
                        </div>

                        <div class="flex gap-3 w-full sm:w-auto">
                            <button type="submit" name="action_add" class="flex-1 sm:flex-none bg-[#0284c7] text-white px-8 py-4 rounded-2xl font-extrabold text-xs uppercase tracking-widest hover:bg-sky-700 transition shadow-md">
                                <i class="fa-solid fa-plus mr-2"></i> Add to List
                            </button>
                            <button type="button" id="finalSaveBtn" onclick="executeFinalSave()" class="flex-1 sm:flex-none bg-emerald-600 text-white px-8 py-4 rounded-2xl font-extrabold text-xs uppercase tracking-widest shadow-lg hover:bg-emerald-700 transition">
                                Save & Exit
                            </button>
                        </div>
                    </div>
                </div>
            </form>
            <?php endif; ?>
        </main>
    </div>

    <footer class="py-6 text-center text-xs text-slate-500 border-t border-slate-200 bg-white mt-auto">
        <span>&copy; 2026 EduLinCore School Management Framework. All rights reserved.</span>
    </footer>
</div>

<script>
    function toggleMobileMenu() {
        const sidebar = document.getElementById('sidebar');
        sidebar.classList.toggle('mobile-open');
    }

    function toggleSubMenu(element) {
        const parentGroup = element.closest('.nav-group');
        const submenu = parentGroup.querySelector('div.pl-6');
        if (submenu) {
            submenu.classList.toggle('hidden');
        }
    }

    <?php if($message): ?>
    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: '<?= htmlspecialchars($message, ENT_QUOTES) ?>',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
    });
    <?php endif; ?>

    function showSave(status) {
        const btn = document.getElementById('finalSaveBtn');
        btn.style.display = status ? 'block' : 'none';
        if(status) {
            Swal.fire({ icon: 'success', title: 'Confirmed', text: 'Ready to finalize data.', confirmButtonColor: '#0284c7' });
        }
    }

    function executeFinalSave() {
        Swal.fire({
            title: 'Assignments Saved',
            text: "Work finished. Return to the dashboard?",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#0284c7',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, go to dashboard',
            cancelButtonText: 'Stay here'
        }).then((result) => {
            if(result.isConfirmed) {
                window.location.href = '<?= BASE_URL ?>/it/dashboard';
            }
        });
    }

    function confirmAssignmentDelete(id) {
        Swal.fire({
            title: 'Delete assignment?',
            text: "Teacher will be unlinked from this class record.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: 'Yes, Delete'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Clean Up Marks?',
                    text: "Should we also wipe marks entered by this teacher for this class?",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#ef4444',
                    cancelButtonColor: '#0284c7',
                    confirmButtonText: 'Yes, Wipe Marks',
                    cancelButtonText: 'No, Keep Marks'
                }).then((markResult) => {
                    let url = `?teacher_id=<?= $selected_teacher_id ?>&delete_id=${id}`;
                    if (markResult.isConfirmed) { url += '&clear_marks=1'; }
                    window.location.href = url;
                });
            }
        });
    }

    function confirmCancel() {
        Swal.fire({
            title: 'Discard Session?',
            text: "All unsaved additions in this session will be removed and you will return to the dashboard.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: 'Yes, Discard & Exit'
        }).then((result) => { 
            if (result.isConfirmed) window.location.href = `?teacher_id=<?= $selected_teacher_id ?>&cancel_session=1`; 
        });
    }
</script>

</body>
</html>