<?php
// Developer mode parameter check removed since access buttons are now active publicly
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <title>Edulincore | Student Results Management System</title>
    <meta name="description" content="Edulincore Technologies is a Zambian technology company based in Mansa, Luapula province, developing a secure and easy-to-use Student Results Management System for primary and secondary schools in Zambia.">
    
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://edulincore.site/">

    <meta property="og:type" content="website">
    <meta property="og:url" content="https://edulincore.site/">
    <meta property="og:title" content="Edulincore | Student Results Management System">
    <meta property="og:description" content="Secure and easy-to-use student results management platform for primary and secondary schools in Zambia.">

    <style>
        :root {
            --primary: #1e3a8a;
            --primary-dark: #1e293b;
            --accent: #3b82f6;
            --bg-color: #f8fafc;
            --text-main: #1e293b;
            --text-muted: #475569;
            --border-color: #e2e8f0;
            --white: #ffffff;
            --alert-bg: #eff6ff;
            --alert-border: #93c5fd;
            --alert-text: #1d4ed8;
        }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            -webkit-text-size-adjust: 100%;
        }

        .mockup-header {
            background: var(--white);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .brand-logo {
            font-weight: 800;
            font-size: 1.25rem;
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .brand-logo-img {
            height: 38px;
            width: auto;
            max-height: 100%;
            object-fit: contain;
            display: block;
        }

        .mockup-nav {
            display: flex;
            gap: 1.25rem;
            flex-wrap: wrap;
        }

        .mockup-nav a {
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: color 0.2s;
        }

        .mockup-nav a:hover {
            color: var(--primary);
        }

        .main-wrapper {
            flex: 1;
            max-width: 1000px;
            width: 100%;
            margin: 0 auto;
            padding: 2rem 1rem;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .content-section {
            display: none;
            width: 100%;
            animation: fadeIn 0.3s ease-in-out;
        }

        .content-section.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .mockup-footer {
            background: var(--white);
            border-top: 1px solid var(--border-color);
            padding: 1.25rem 1rem;
            text-align: center;
            font-size: 0.8rem;
            color: var(--text-muted);
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .mockup-footer a {
            color: var(--text-muted);
            text-decoration: none;
        }

        .mockup-footer a:hover {
            color: var(--primary);
            text-decoration: underline;
        }

        .company-header {
            font-size: 2.25rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 0.25rem;
            line-height: 1.2;
            text-align: center;
            width: 100%;
        }

        .company-slogan {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 1.25rem;
            text-align: center;
            width: 100%;
        }

        .hero-title {
            font-size: 1.65rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 0.75rem;
            line-height: 1.3;
        }

        .hero-subtitle {
            font-size: 1rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
            max-width: 650px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.5;
            padding: 0 0.5rem;
        }

        /* Partners Marquee Styles */
        .partners-container {
            width: 100%;
            background: var(--white);
            border: 1px solid var(--border-color);
            border-radius: 0.75rem;
            padding: 1.25rem 0;
            margin-bottom: 2rem;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
            text-align: left;
        }

        .partners-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0 1.25rem 0.75rem 1.25rem;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .marquee-wrapper {
            overflow-x: auto;
            width: 100%;
            position: relative;
            display: flex;
            scroll-behavior: smooth;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            cursor: grab;
        }

        .marquee-wrapper:active {
            cursor: grabbing;
        }

        .marquee-wrapper::-webkit-scrollbar {
            display: none;
        }

        .marquee-track {
            display: flex;
            gap: 1.5rem;
            white-space: nowrap;
            will-change: transform;
            animation: scrollPartners 35s linear infinite;
            padding: 0.25rem 1.25rem;
        }

        .marquee-track:hover {
            animation-play-state: paused;
        }

        .partner-card {
            background: var(--bg-color);
            border: 1px solid var(--border-color);
            padding: 0.65rem 1rem;
            border-radius: 0.5rem;
            display: inline-flex;
            flex-direction: column;
            gap: 0.15rem;
            min-width: 220px;
            box-sizing: border-box;
            user-select: none;
        }

        .partner-name {
            font-weight: 700;
            color: var(--primary);
            font-size: 0.85rem;
            text-transform: uppercase;
        }

        .partner-location {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        @keyframes scrollPartners {
            0% { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .section-desc {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
            padding: 0 0.5rem;
        }

        .hero-actions {
            margin-bottom: 2rem; 
            display: flex; 
            gap: 0.75rem; 
            justify-content: center; 
            flex-wrap: wrap;
            width: 100%;
        }

        .btn-primary {
            background-color: var(--primary);
            color: var(--white);
            padding: 0.75rem 1.5rem;
            border-radius: 0.375rem;
            font-weight: 600;
            font-size: 0.95rem;
            text-decoration: none;
            transition: background-color 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 1;
            min-width: 200px;
            max-width: 280px;
            box-sizing: border-box;
            box-shadow: 0 2px 4px rgba(30, 58, 138, 0.2);
        }

        .btn-primary:hover {
            background-color: #172554;
        }

        .btn-outline {
            background-color: var(--white);
            color: var(--primary);
            border: 1.5px solid var(--primary);
            padding: 0.75rem 1.5rem;
            border-radius: 0.375rem;
            font-weight: 600;
            font-size: 0.95rem;
            text-decoration: none;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 1;
            min-width: 200px;
            max-width: 280px;
            box-sizing: border-box;
        }

        .btn-outline:hover {
            background-color: #eff6ff;
        }

        .feature-grid-2x2 {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            width: 100%;
            margin-top: 1rem;
            box-sizing: border-box;
        }

        .feature-box {
            background: var(--white);
            border: 1px solid var(--border-color);
            padding: 1rem;
            border-radius: 0.5rem;
            text-align: left;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            box-sizing: border-box;
        }

        .feature-icon {
            font-size: 1.4rem;
            background: #eff6ff;
            padding: 0.5rem;
            border-radius: 0.375rem;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 38px;
            min-height: 38px;
            box-sizing: border-box;
        }

        @media (min-width: 768px) {
            .mockup-header {
                padding: 1rem 2rem;
            }
            .main-wrapper {
                padding: 3rem 1.5rem;
            }
            .company-header {
                font-size: 2.75rem;
            }
            .company-slogan {
                font-size: 1.2rem;
            }
            .hero-title {
                font-size: 2rem;
            }
            .feature-grid-2x2 {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 1.25rem;
            }
            .feature-box {
                padding: 1.25rem;
            }
            .btn-primary, .btn-outline {
                flex: unset;
                min-width: 220px;
                max-width: none;
            }
        }
    </style>
</head>
<body>
    
    <header class="mockup-header">
        <a onclick="switchTab('home')" class="brand-logo" style="cursor: pointer;">
            <img src="<?= BASE_URL ?>/logo.png" alt="Edulincore Logo" class="brand-logo-img">
        </a>
        <nav class="mockup-nav">
            <a onclick="switchTab('home')" style="cursor: pointer;">Home</a>
            <a onclick="switchTab('features')" style="cursor: pointer;">Features</a>
            <a onclick="switchTab('about')" style="cursor: pointer;">About</a>
            <a onclick="switchTab('contact')" style="cursor: pointer;">Contact</a>
        </nav>
    </header>

    <main class="main-wrapper">
        
        <section id="sec-home" class="content-section active">
            <h1 class="company-header">Edulincore Technologies</h1>
            <div class="company-slogan">Innovating your digital future</div>
            
            <h2 class="hero-title">Student Results Management System</h2>
            
            <p class="hero-subtitle">
                Make school management easy and keep student records organized with Edulincore.
            </p>

            <!-- Active Login Buttons -->
            <div class="hero-actions">
                <a href="<?= BASE_URL ?>/auth/school_login" class="btn-primary">
                    School Login &rarr;
                </a>
                <a href="<?= BASE_URL ?>/auth/admin_login" class="btn-outline">
                    Admin Login &rarr;
                </a>
            </div>

            <!-- Partner Schools Marquee Section -->
            <div class="partners-container">
                <div class="partners-title">
                    <span>&#127891;</span> Proudly Empowering Partner Schools Across Zambia
                </div>
                <div class="marquee-wrapper" id="marqueeWrapper">
                    <div class="marquee-track" id="marqueeTrack">
                        <!-- Original List -->
                        <div class="partner-card"><div class="partner-name">Mansa Basic School</div><div class="partner-location">Mansa District, Luapula Province</div></div>
                        <div class="partner-card"><div class="partner-name">Batoka Day Secondary School</div><div class="partner-location">Choma District, Southern Province</div></div>
                        <div class="partner-card"><div class="partner-name">Matanda Secondary School</div><div class="partner-location">Mansa District, Luapula Province</div></div>
                        <div class="partner-card"><div class="partner-name">Chanama Day Secondary School</div><div class="partner-location">Mafinga District, Muchinga Province</div></div>
                        <div class="partner-card"><div class="partner-name">Kombaniya Secondary School</div><div class="partner-location">Mansa District, Luapula Province</div></div>
                        <div class="partner-card"><div class="partner-name">Kalaba Secondary School</div><div class="partner-location">Mansa District, Luapula Province</div></div>
                        <div class="partner-card"><div class="partner-name">Ipusukilo Secondary School</div><div class="partner-location">Mufulira District, Copperbelt Province</div></div>
                        <div class="partner-card"><div class="partner-name">Mansa School of Continuity</div><div class="partner-location">Mansa District, Luapula Province</div></div>
                        <div class="partner-card"><div class="partner-name">Don Bosco Secondary School</div><div class="partner-location">Mansa District, Luapula Province</div></div>
                        
                        <!-- Duplicate List for Seamless Infinite Scrolling Loop -->
                        <div class="partner-card"><div class="partner-name">Mansa Basic School</div><div class="partner-location">Mansa District, Luapula Province</div></div>
                        <div class="partner-card"><div class="partner-name">Batoka Day Secondary School</div><div class="partner-location">Choma District, Southern Province</div></div>
                        <div class="partner-card"><div class="partner-name">Matanda Secondary School</div><div class="partner-location">Mansa District, Luapula Province</div></div>
                        <div class="partner-card"><div class="partner-name">Chanama Day Secondary School</div><div class="partner-location">Mafinga District, Muchinga Province</div></div>
                        <div class="partner-card"><div class="partner-name">Kombaniya Secondary School</div><div class="partner-location">Mansa District, Luapula Province</div></div>
                        <div class="partner-card"><div class="partner-name">Kalaba Secondary School</div><div class="partner-location">Mansa District, Luapula Province</div></div>
                        <div class="partner-card"><div class="partner-name">Ipusukilo Secondary School</div><div class="partner-location">Mufulira District, Copperbelt Province</div></div>
                        <div class="partner-card"><div class="partner-name">Mansa School of Continuity</div><div class="partner-location">Mansa District, Luapula Province</div></div>
                        <div class="partner-card"><div class="partner-name">Don Bosco Secondary School</div><div class="partner-location">Mansa District, Luapula Province</div></div>
                    </div>
                </div>
            </div>

            <div class="feature-grid-2x2">
                <div class="feature-box">
                    <div class="feature-icon">&#128202;</div>
                    <div>
                        <div style="font-weight: 700; color: #1e3a8a; font-size: 0.95rem; margin-bottom: 0.15rem;">Quick Reports</div>
                        <div style="font-size: 0.825rem; color: #475569; line-height: 1.4;">Check grades and performance instantly</div>
                    </div>
                </div>

                <div class="feature-box">
                    <div class="feature-icon">&#128737;</div>
                    <div>
                        <div style="font-weight: 700; color: #1e3a8a; font-size: 0.95rem; margin-bottom: 0.15rem;">Safe & Secure</div>
                        <div style="font-size: 0.825rem; color: #475569; line-height: 1.4;">Keep school data safe with secure logins</div>
                    </div>
                </div>

                <div class="feature-box">
                    <div class="feature-icon">&#128101;</div>
                    <div>
                        <div style="font-weight: 700; color: #1e3a8a; font-size: 0.95rem; margin-bottom: 0.15rem;">Report Cards</div>
                        <div style="font-size: 0.825rem; color: #475569; line-height: 1.4;">Generate and print clean report cards easily</div>
                    </div>
                </div>

                <div class="feature-box">
                    <div class="feature-icon">&#9881;</div>
                    <div>
                        <div style="font-weight: 700; color: #1e3a8a; font-size: 0.95rem; margin-bottom: 0.15rem;">Easy Setup</div>
                        <div style="font-size: 0.825rem; color: #475569; line-height: 1.4;">Manage classes, subjects, and teachers smoothly</div>
                    </div>
                </div>
            </div>
        </section>

        <section id="sec-features" class="content-section">
            <h2 class="section-title">What the System Can Do</h2>
            <p class="section-desc">Built to take away the stress of manual calculations and paperwork so your school runs smoothly.</p>
            
            <div class="feature-grid-2x2" style="margin-top: 0.5rem;">
                <div class="feature-box">
                    <div class="feature-icon">&#9881;</div>
                    <div>
                        <div style="font-weight: 700; color: #1e3a8a; font-size: 0.95rem; margin-bottom: 0.15rem;">Class Setup</div>
                        <div style="font-size: 0.825rem; color: #475569; line-height: 1.4;">Set up grade levels, classes, and assign teachers with no hassle.</div>
                    </div>
                </div>

                <div class="feature-box">
                    <div class="feature-icon">&#128202;</div>
                    <div>
                        <div style="font-weight: 700; color: #1e3a8a; font-size: 0.95rem; margin-bottom: 0.15rem;">Mark Entry</div>
                        <div style="font-size: 0.825rem; color: #475569; line-height: 1.4;">Teachers can enter and update student scores quickly.</div>
                    </div>
                </div>

                <div class="feature-box">
                    <div class="feature-icon">&#128737;</div>
                    <div>
                        <div style="font-weight: 700; color: #1e3a8a; font-size: 0.95rem; margin-bottom: 0.15rem;">IT Admin Controls</div>
                        <div style="font-size: 0.825rem; color: #475569; line-height: 1.4;">A central dashboard for school IT staff to manage everything.</div>
                    </div>
                </div>

                <div class="feature-box">
                    <div class="feature-icon">&#128101;</div>
                    <div>
                        <div style="font-weight: 700; color: #1e3a8a; font-size: 0.95rem; margin-bottom: 0.15rem;">Reliable Database</div>
                        <div style="font-size: 0.825rem; color: #475569; line-height: 1.4;">Powered by stable PHP technology to handle heavy workloads.</div>
                    </div>
                </div>
            </div>
        </section>

        <section id="sec-about" class="content-section">
            <h2 class="section-title">About Edulincore Technologies</h2>
            <p class="section-desc" style="max-width: 850px; text-align: left; background: rgba(255,255,255,0.9); padding: 1.25rem; border-radius: 0.75rem; border: 1px solid #e2e8f0; line-height: 1.6; box-sizing: border-box;">
                <strong>Edulincore Technologies</strong> is a Zambian technology company based in Mansa, Luapula province. We are the developers of the Student Results Management System—a secure and easy-to-use platform for primary and secondary schools in Zambia. Our system helps to manage student data, capture term tests, generate report cards automatically, and publish them in real time. More other products that we offer will be shared soon; for now, Edulincore Technologies is serving you with innovating your digital future.
            </p>
        </section>

        <section id="sec-contact" class="content-section">
            <h2 class="section-title">Contact Support</h2>
            <p class="section-desc">Reach out to us for system setup or technical help.</p>
            
            <div style="background: rgba(255,255,255,0.95); padding: 1.25rem 1.5rem; border-radius: 0.75rem; border: 1px solid #e2e8f0; max-width: 600px; width: 100%; text-align: left; box-shadow: 0 4px 12px rgba(0,0,0,0.02); box-sizing: border-box;">
                <div style="margin-bottom: 0.8rem; font-size: 0.9rem; color: #1e3a8a;"><strong>Direct Phone Line:</strong> 0977323918</div>
                <div style="margin-bottom: 0.8rem; font-size: 0.9rem; color: #1e3a8a;"><strong>Support Email:</strong> support@edulincore.com</div>
                <div style="font-size: 0.9rem; color: #1e3a8a;"><strong>Helpdesk Hours:</strong> Monday – Friday, 08:00 – 17:00 CAT</div>
            </div>
        </section>

    </main>

    <footer class="mockup-footer">
        <span>Edulincore Technologies &reg; <?= date('Y'); ?>. All Rights Reserved.</span>
        <span style="color: #334155;">|</span>
        <span>Hotline: 0977323918</span>
        <span style="color: #334155; display: inline-block;">|</span>
        <a onclick="switchTab('about')" style="cursor: pointer;">Privacy Policy</a>
        <span style="color: #334155;">|</span>
        <a onclick="switchTab('about')" style="cursor: pointer;">Terms of Service</a>
        <span style="color: #334155;">|</span>
        <a onclick="switchTab('contact')" style="cursor: pointer;">Helpdesk</a>
    </footer>

    <script>
        function switchTab(tabId) {
            const sections = document.querySelectorAll('.content-section');
            sections.forEach(sec => sec.classList.remove('active'));
            
            const target = document.getElementById('sec-' + tabId);
            if (target) {
                target.classList.add('active');
            }
            history.replaceState(null, null, '#' + tabId);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        window.addEventListener('DOMContentLoaded', () => {
            const hash = window.location.hash.substring(1);
            if (hash && document.getElementById('sec-' + hash)) {
                switchTab(hash);
            }
        });

        // Marquee interaction: Drag-to-scroll, touch swipe, and pause on hover
        const track = document.getElementById('marqueeTrack');
        const wrapper = document.getElementById('marqueeWrapper');

        if (track && wrapper) {
            let isDown = false;
            let startX;
            let scrollLeft;

            track.addEventListener('mouseenter', () => {
                track.style.animationPlayState = 'paused';
            });
            track.addEventListener('mouseleave', () => {
                if (!isDown) track.style.animationPlayState = 'running';
            });

            track.addEventListener('mousedown', (e) => {
                isDown = true;
                track.style.animationPlayState = 'paused';
                startX = e.pageX - wrapper.offsetLeft;
                scrollLeft = wrapper.scrollLeft;
            });

            window.addEventListener('mouseup', () => {
                isDown = false;
                track.style.animationPlayState = 'running';
            });

            wrapper.addEventListener('mousemove', (e) => {
                if (!isDown) return;
                e.preventDefault();
                const x = e.pageX - wrapper.offsetLeft;
                const walk = (x - startX) * 2;
                wrapper.scrollLeft = scrollLeft - walk;
            });

            track.addEventListener('touchstart', () => {
                track.style.animationPlayState = 'paused';
            });

            track.addEventListener('touchend', () => {
                track.style.animationPlayState = 'running';
            });
        }
    </script>
</body>
</html>