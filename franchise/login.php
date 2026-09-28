<?php
/**
 * Franchise Login Portal - Modern Premium Design
 */
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/franchise.php';

// If already logged in, redirect to dashboard
if (!empty($_SESSION['franchise_id'])) {
    session_enforce_idle('franchise', 'login.php');
    header('Location: index.php');
    exit;
}

$error = '';
$flash = get_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($login === '' || $password === '') {
        $error = 'Please enter your Franchise Code or Username and Password.';
    } else {
        // Find franchise by franchisee_code OR username
        $stmt = $pdo->prepare('
            SELECT f.*, t.name AS type_name
            FROM franchisees f
            LEFT JOIN franchisee_types t ON t.id = f.type_id
            WHERE (f.franchisee_code = ? OR f.username = ?)
            LIMIT 1
        ');
        $stmt->execute([$login, $login]);
        $franchise = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!feature_enabled('feature_franchise_enabled')) {
            $error = 'Franchise portal is currently disabled by administrator.';
        } elseif ($franchise && !empty($franchise['password']) && password_verify($password, $franchise['password'])) {
            if (($franchise['status'] ?? '') !== 'active') {
                $error = 'Your franchise account is currently inactive. Please contact administrator.';
            } else {
                $_SESSION['franchise_id'] = (int) $franchise['id'];
                $_SESSION['franchise_code'] = $franchise['franchisee_code'];
                $_SESSION['franchise_name'] = $franchise['name'];
                $_SESSION['franchise_type'] = $franchise['type_name'] ?? 'Franchise';
                session_touch('franchise');

                log_activity('franchise_login', "Franchisee {$franchise['franchisee_code']} logged in");
                header('Location: index.php');
                exit;
            }
        } else {
            $error = 'Invalid Franchise Code / Username or Password.';
        }
    }
}

$company = setting('company_name', 'Binary MLM');
$logoUrl = company_logo_url();
$favUrl = company_favicon_url();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Franchise Terminal Login | <?= e($company) ?></title>
    <?php if ($favUrl): ?><link rel="icon" href="<?= e($favUrl) ?>"><?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0284c7;
            --primary-light: #38bdf8;
            --primary-dark: #0369a1;
            --accent: #10b981;
            --dark-bg: #070e1e;
            --card-surface: #ffffff;
            --text-dark: #0f172a;
            --text-slate: #334155;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --ring: rgba(2, 132, 199, 0.25);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body.fr-portal-body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--dark-bg);
            background-image: 
                radial-gradient(at 0% 0%, rgba(2, 132, 199, 0.28) 0px, transparent 50%),
                radial-gradient(at 100% 0%, rgba(16, 185, 129, 0.22) 0px, transparent 50%),
                radial-gradient(at 50% 100%, rgba(99, 102, 241, 0.18) 0px, transparent 50%);
            padding: 2rem 1.25rem;
            position: relative;
            overflow-x: hidden;
        }

        /* Ambient floating lights */
        .ambient-glow {
            position: absolute;
            border-radius: 50%;
            filter: blur(100px);
            pointer-events: none;
            z-index: 1;
        }
        .glow-1 {
            width: 500px;
            height: 500px;
            background: linear-gradient(135deg, rgba(2, 132, 199, 0.35), rgba(56, 189, 248, 0.2));
            top: -150px;
            left: -150px;
        }
        .glow-2 {
            width: 450px;
            height: 450px;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.3), rgba(6, 182, 212, 0.2));
            bottom: -120px;
            right: -120px;
        }

        /* Container Layout */
        .fr-showcase-wrap {
            position: relative;
            z-index: 10;
            max-width: 1020px;
            width: 100%;
            background: rgba(255, 255, 255, 0.98);
            border-radius: 28px;
            box-shadow: 
                0 30px 60px -15px rgba(0, 0, 0, 0.45),
                0 0 0 1px rgba(255, 255, 255, 0.4);
            display: grid;
            grid-template-columns: 1.05fr 1fr;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.8);
        }

        /* Left Side: Brand Showcase */
        .fr-showcase-hero {
            background: linear-gradient(145deg, #091a32 0%, #0d274c 50%, #0a1f3d 100%);
            padding: 3.5rem 3rem;
            color: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }
        .fr-showcase-hero::before {
            content: '';
            position: absolute;
            top: 0; right: 0; bottom: 0; left: 0;
            background-image: radial-gradient(rgba(255, 255, 255, 0.08) 1px, transparent 1px);
            background-size: 24px 24px;
            opacity: 0.6;
            pointer-events: none;
        }
        .hero-top {
            position: relative;
            z-index: 2;
        }
        .hero-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 2rem;
        }
        .hero-logo {
            max-height: 44px;
            max-width: 160px;
            object-fit: contain;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.3));
        }
        .hero-brand-name {
            font-family: 'Outfit', sans-serif;
            font-size: 1.35rem;
            font-weight: 700;
            letter-spacing: -0.01em;
            color: #ffffff;
        }
        .hero-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            background: rgba(2, 132, 199, 0.2);
            border: 1px solid rgba(56, 189, 248, 0.35);
            color: #7dd3fc;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.35rem 0.8rem;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 1.25rem;
        }
        .hero-tag .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #38bdf8;
            box-shadow: 0 0 8px #38bdf8;
        }
        .hero-title {
            font-family: 'Outfit', sans-serif;
            font-size: 2.15rem;
            font-weight: 800;
            line-height: 1.2;
            color: #ffffff;
            margin-bottom: 0.85rem;
            letter-spacing: -0.02em;
        }
        .hero-title span {
            background: linear-gradient(135deg, #38bdf8 0%, #34d399 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .hero-desc {
            color: #94a3b8;
            font-size: 0.95rem;
            line-height: 1.55;
            margin-bottom: 2rem;
        }

        /* Features List */
        .hero-features {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 1.15rem;
            position: relative;
            z-index: 2;
        }
        .hero-feat-item {
            display: flex;
            align-items: center;
            gap: 0.9rem;
            font-size: 0.9rem;
            color: #e2e8f0;
            font-weight: 500;
        }
        .feat-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: #38bdf8;
        }
        .feat-icon svg {
            width: 18px;
            height: 18px;
        }

        .hero-footer {
            margin-top: 2.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.82rem;
            color: #64748b;
            position: relative;
            z-index: 2;
        }
        .hero-footer span {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        /* Right Side: Form */
        .fr-showcase-form {
            padding: 3.5rem 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: #ffffff;
        }
        .form-header {
            margin-bottom: 2rem;
        }
        .form-header h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.85rem;
            font-weight: 700;
            color: var(--text-dark);
            letter-spacing: -0.02em;
        }
        .form-header p {
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-top: 0.35rem;
        }

        /* Alerts */
        .fr-alert {
            padding: 0.85rem 1rem;
            border-radius: 12px;
            font-size: 0.88rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.65rem;
            line-height: 1.4;
        }
        .fr-alert-success {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }
        .fr-alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        /* Inputs */
        .form-field {
            margin-bottom: 1.35rem;
        }
        .form-field label {
            display: block;
            font-size: 0.84rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 0.45rem;
        }
        .input-group {
            position: relative;
        }
        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            width: 19px;
            height: 19px;
            color: #94a3b8;
            pointer-events: none;
            transition: color 0.15s ease;
        }
        .form-input {
            width: 100%;
            padding: 0.8rem 1rem 0.8rem 2.85rem;
            font-size: 0.94rem;
            font-family: inherit;
            color: var(--text-dark);
            background: #f8fafc;
            border: 1.5px solid var(--border);
            border-radius: 12px;
            outline: none;
            transition: all 0.2s ease;
        }
        .form-input:focus {
            background: #ffffff;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--ring);
        }
        .form-input:focus + .input-icon,
        .input-group:focus-within .input-icon {
            color: var(--primary);
        }

        .pass-toggle-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 0.3rem;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.15s;
        }
        .pass-toggle-btn:hover {
            color: #475569;
        }
        .pass-toggle-btn svg {
            width: 19px;
            height: 19px;
        }

        /* Form Options: Remember & Forgot */
        .form-options {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            font-size: 0.85rem;
        }
        .remember-wrap {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            color: #475569;
            cursor: pointer;
            user-select: none;
        }
        .remember-wrap input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--primary);
            cursor: pointer;
        }
        .forgot-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 700;
            transition: color 0.15s;
        }
        .forgot-link:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        /* Submit Button */
        .btn-portal-submit {
            width: 100%;
            padding: 0.88rem 1.5rem;
            font-size: 1rem;
            font-weight: 700;
            font-family: inherit;
            color: #ffffff;
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            border: none;
            border-radius: 12px;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.35);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
        }
        .btn-portal-submit:hover {
            background: linear-gradient(135deg, #0369a1 0%, #075985 100%);
            box-shadow: 0 8px 22px rgba(2, 132, 199, 0.45);
            transform: translateY(-1px);
        }
        .btn-portal-submit:active {
            transform: translateY(0);
        }
        .btn-portal-submit svg {
            width: 18px;
            height: 18px;
            transition: transform 0.2s ease;
        }
        .btn-portal-submit:hover svg {
            transform: translateX(3px);
        }

        /* Card Bottom Navigation */
        .form-bottom-links {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.86rem;
            color: var(--text-muted);
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .form-bottom-links a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }
        .form-bottom-links a:hover {
            text-decoration: underline;
        }

        /* Responsive Breakpoints */
        @media (max-width: 900px) {
            .fr-showcase-wrap {
                grid-template-columns: 1fr;
                max-width: 480px;
                border-radius: 20px;
            }
            .fr-showcase-hero {
                padding: 2.25rem 2rem;
            }
            .hero-title {
                font-size: 1.75rem;
            }
            .hero-desc {
                margin-bottom: 1.25rem;
            }
            .hero-features {
                display: none;
            }
            .hero-footer {
                display: none;
            }
            .fr-showcase-form {
                padding: 2.5rem 2rem;
            }
        }
    </style>
</head>
<body class="fr-portal-body">

<div class="ambient-glow glow-1"></div>
<div class="ambient-glow glow-2"></div>

<div class="fr-showcase-wrap">
    <!-- Left Hero Showcase -->
    <div class="fr-showcase-hero">
        <div class="hero-top">
            <div class="hero-brand">
                <?php if ($logoUrl): ?>
                    <img src="<?= e($logoUrl) ?>" alt="<?= e($company) ?>" class="hero-logo">
                <?php else: ?>
                    <span class="hero-brand-name"><?= e($company) ?></span>
                <?php endif; ?>
            </div>

            <div class="hero-tag">
                <span class="dot"></span>
                Authorized Franchise Portal
            </div>

            <h1 class="hero-title">
                Smart Operations.<br>
                <span>Live Franchise Terminal.</span>
            </h1>

            <p class="hero-desc">
                Streamline member billing, monitor real-time stock allocation, and generate instant GST-compliant invoices.
            </p>

            <ul class="hero-features">
                <li class="hero-feat-item">
                    <div class="feat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                    </div>
                    <span>Instant Member Lookup & Product Billing</span>
                </li>
                <li class="hero-feat-item">
                    <div class="feat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                    </div>
                    <span>Real-time Company Stock Inventory Sync</span>
                </li>
                <li class="hero-feat-item">
                    <div class="feat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <span>Professional Retail Invoices with GST</span>
                </li>
            </ul>
        </div>

        <div class="hero-footer">
            <span>
                <svg style="width:14px;height:14px;color:#10b981" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                256-bit SSL Encrypted Access
            </span>
            <span>v2.4 Live</span>
        </div>
    </div>

    <!-- Right Login Form -->
    <div class="fr-showcase-form">
        <div class="form-header">
            <h2>Franchise Sign In</h2>
            <p>Enter your franchise credentials to access your dashboard</p>
        </div>

        <?php if ($flash && !empty($flash['message'])): ?>
            <div class="fr-alert fr-alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>">
                <svg style="width:18px;height:18px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span><?= e($flash['message']) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="fr-alert fr-alert-error">
                <svg style="width:18px;height:18px;flex-shrink:0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="post" action="login.php" id="loginForm" autocomplete="on">
            <div class="form-field">
                <label for="loginInput">Franchise Code or Username</label>
                <div class="input-group">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <input type="text" id="loginInput" name="login" class="form-input" value="<?= e($_POST['login'] ?? '') ?>" placeholder="e.g. FR0001 or Username" required autofocus>
                </div>
            </div>

            <div class="form-field">
                <label for="passwordInput">Password</label>
                <div class="input-group">
                    <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <input type="password" id="passwordInput" name="password" class="form-input" placeholder="••••••••" required>
                    <button type="button" class="pass-toggle-btn" id="togglePasswordBtn" title="Toggle password visibility">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>

            <div class="form-options">
                <label class="remember-wrap">
                    <input type="checkbox" name="remember" id="rememberMe">
                    <span>Remember Code</span>
                </label>
                <a href="forgot-password.php" class="forgot-link">Forgot Password?</a>
            </div>

            <button type="submit" class="btn-portal-submit">
                <span>Sign In to Terminal</span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
        </form>

        <div class="form-bottom-links">
            <span>Are you an MLM member? <a href="../user/login.php">Member Login →</a></span>
            <a href="../index.php">← Back to Home</a>
        </div>
    </div>
</div>

<script>
// Password show/hide toggle
var passInput = document.getElementById('passwordInput');
var toggleBtn = document.getElementById('togglePasswordBtn');
if (toggleBtn && passInput) {
    toggleBtn.addEventListener('click', function () {
        if (passInput.type === 'password') {
            passInput.type = 'text';
            toggleBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
        } else {
            passInput.type = 'password';
            toggleBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
        }
    });
}

// Remember login code in localStorage
var loginInput = document.getElementById('loginInput');
var rememberCheckbox = document.getElementById('rememberMe');
var savedCode = localStorage.getItem('fr_login_code');
if (savedCode && loginInput && !loginInput.value) {
    loginInput.value = savedCode;
    if (rememberCheckbox) rememberCheckbox.checked = true;
}
document.getElementById('loginForm').addEventListener('submit', function () {
    if (rememberCheckbox && rememberCheckbox.checked && loginInput.value) {
        localStorage.setItem('fr_login_code', loginInput.value.trim());
    } else {
        localStorage.removeItem('fr_login_code');
    }
});
</script>

</body>
</html>
