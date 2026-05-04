<?php
include 'includes/connection.php';
session_start();

$error = '';
$success = '';

// Login logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        $stmt = $conn->prepare("SELECT userID, Firstname, Lastname, Password, Role, two_factor_enabled FROM customers WHERE Email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user['Password'])) {
                if ($user['two_factor_enabled'] == 1) {
                    $otp = rand(100000, 999999);
                    $_SESSION['otp'] = $otp;
                    $_SESSION['otp_email'] = $email;
                    $_SESSION['otp_userID'] = $user['userID'];
                    $_SESSION['otp_expiry'] = time() + 300;
                    sendOTPEmail($email, $otp);
                    header("Location: verify_otp.php");
                    exit();
                } else {
                    $_SESSION['userID'] = $user['userID'];
                    $_SESSION['userName'] = $user['Firstname'] . ' ' . $user['Lastname'];
                    $_SESSION['role'] = $user['Role'];

                    if ($user['Role'] === 'admin') {
                        header("Location: Admin/admin_dashboard.php");
                    } elseif ($user['Role'] === 'employee') {
                        header("Location: Employee/employee_dashboard.php");
                    } else {
                        header("Location: Customer/customer_dashboard.php");
                    }
                    exit();
                }
            } else {
                $error = "Invalid email or password.";
            }
        } else {
            $error = "Invalid email or password.";
        }
        $stmt->close();
    }
}

// Registration logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $firstname = trim($_POST['firstname']);
    $lastname = trim($_POST['lastname']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($firstname) || empty($lastname) || empty($email) || empty($phone) || empty($address) || empty($password)) {
        $error = "All fields are required.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $error = "Password must be at least 8 characters with 1 uppercase and 1 number.";
    } else {
        $check = $conn->prepare("SELECT userID FROM customers WHERE Email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "Email already registered.";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $role = 'customer';
            $verification_token = bin2hex(random_bytes(32));

            $stmt = $conn->prepare("INSERT INTO customers (Firstname, Lastname, Email, Contact, Address, Password, Role, two_factor_enabled, email_verification_token) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)");
            $stmt->bind_param("ssssssss", $firstname, $lastname, $email, $phone, $address, $hashed_password, $role, $verification_token);

            if ($stmt->execute()) {
                $verificationLink = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/verify_email.php?token=" . $verification_token;

                require_once 'config.php';
                $apiKey = BREVO_API_KEY;
                $data = [
                    'sender' => ['name' => 'De Chavez Waterhaus', 'email' => 'cocacc202501@gmail.com'],
                    'to' => [['email' => $email]],
                    'subject' => 'Verify Your Email Address',
                    'htmlContent' => "
                        <h2>Welcome to De Chavez Waterhaus!</h2>
                        <p>Hi $firstname,</p>
                        <p>Please verify your email address by clicking the button below:</p>
                        <p style='margin: 20px 0;'>
                            <a href='$verificationLink' style='background: #0077B6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block;'>Verify Email</a>
                        </p>
                        <p>This link will expire in 24 hours.</p>
                    "
                ];

                $ch = curl_init('https://api.brevo.com/v3/smtp/email');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'accept: application/json',
                    'api-key: ' . $apiKey,
                    'content-type: application/json'
                ]);
                curl_exec($ch);
                curl_close($ch);

                $success = "Registration successful! Please check your email to verify your account.";
            } else {
                $error = "Registration failed. Please try again.";
            }
            $stmt->close();
        }
        $check->close();
    }
}

function sendOTPEmail(string $email, string $otp) {
    require_once 'config.php';
    $apiKey = BREVO_API_KEY;
    $data = [
        'sender' => ['name' => 'De Chavez Waterhaus', 'email' => 'cocacc202501@gmail.com'],
        'to' => [['email' => $email]],
        'subject' => 'Your Login OTP Code',
        'htmlContent' => "
            <h2>Your OTP Code</h2>
            <p style='font-size: 24px; font-weight: bold; color: #0077B6;'>$otp</p>
            <p>This code will expire in 5 minutes.</p>
        "
    ];
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'accept: application/json',
        'api-key: ' . $apiKey,
        'content-type: application/json'
    ]);
    curl_exec($ch);
    curl_close($ch);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login • De Chavez Waterhaus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="icon" href="images/logo.jpg" type="image/x-icon">
    <style>
        :root {
            --deep:  #020d18;
            --abyss: #030f1e;
            --ocean: #041e35;
            --navy:  #0a2d4a;
            --teal:  #0077b6;
            --aqua:  #00b4d8;
            --cyan:  #48cae4;
            --glow:  #90e0ef;
            --foam:  #caf0f8;
            --white: #f0f9ff;
            --gold:  #f4c842;
            --glass: rgba(0,180,216,0.08);
            --glass-border: rgba(72,202,228,0.2);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--deep);
            color: var(--white);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* ── LAYOUT ── */
        .auth-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 100vh;
        }

        @media (max-width: 991px) {
            .auth-layout { grid-template-columns: 1fr; }
            .brand-panel  { display: none !important; }
        }

        /* ── BRAND PANEL (left) ── */
        .brand-panel {
            position: relative;
            background: var(--ocean);
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .brand-panel-bg {
            position: absolute;
            inset: 0;
            background: url('https://images.unsplash.com/photo-1548839140-29a749e1cf4d?auto=format&fit=crop&w=1400&q=80') center/cover no-repeat;
            opacity: 0.07;
        }

        .brand-glow {
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse 70% 60% at 50% 90%, rgba(0,119,182,0.35) 0%, transparent 70%),
                radial-gradient(ellipse 50% 40% at 20% 10%, rgba(0,180,216,0.12) 0%, transparent 60%);
        }

        /* Animated water rings */
        .water-rings {
            position: absolute;
            bottom: -60px;
            left: 50%;
            transform: translateX(-50%);
        }

        .ring {
            position: absolute;
            border-radius: 50%;
            border: 1px solid rgba(0,180,216,0.15);
            transform: translate(-50%, -50%);
            animation: expand 6s ease-out infinite;
        }
        .ring:nth-child(1) { width: 300px; height: 300px; animation-delay: 0s; }
        .ring:nth-child(2) { width: 500px; height: 500px; animation-delay: 1.5s; }
        .ring:nth-child(3) { width: 700px; height: 700px; animation-delay: 3s; }
        .ring:nth-child(4) { width: 900px; height: 900px; animation-delay: 4.5s; }

        @keyframes expand {
            0%   { opacity: 0; transform: translate(-50%,-50%) scale(0.3); }
            30%  { opacity: 1; }
            100% { opacity: 0; transform: translate(-50%,-50%) scale(1); }
        }

        .brand-content {
            position: relative;
            z-index: 2;
            text-align: center;
            padding: 60px 50px;
            max-width: 480px;
        }

        .brand-logo {
            width: 88px; height: 88px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(0,180,216,0.4);
            box-shadow: 0 0 40px rgba(0,180,216,0.25), 0 0 80px rgba(0,119,182,0.15);
            margin-bottom: 32px;
            animation: logoGlow 3s ease-in-out infinite alternate;
        }

        @keyframes logoGlow {
            from { box-shadow: 0 0 30px rgba(0,180,216,0.2), 0 0 60px rgba(0,119,182,0.1); }
            to   { box-shadow: 0 0 50px rgba(0,180,216,0.45), 0 0 100px rgba(0,119,182,0.25); }
        }

        .brand-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 3.2rem;
            font-weight: 300;
            line-height: 1.05;
            color: var(--white);
            margin-bottom: 8px;
            letter-spacing: -0.02em;
        }

        .brand-title em {
            font-style: italic;
            background: linear-gradient(135deg, var(--aqua), var(--glow));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .brand-tagline {
            font-size: 0.95rem;
            color: rgba(202,240,248,0.5);
            font-weight: 300;
            line-height: 1.7;
            margin-top: 16px;
            max-width: 360px;
        }

        .brand-divider {
            width: 40px;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--aqua), transparent);
            margin: 32px auto;
        }

        .brand-features {
            display: flex;
            justify-content: center;
            gap: 40px;
        }

        .brand-feat {
            text-align: center;
        }

        .brand-feat-icon {
            width: 52px; height: 52px;
            border-radius: 14px;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-size: 1.1rem;
            color: var(--aqua);
            backdrop-filter: blur(10px);
        }

        .brand-feat-label {
            font-size: 0.72rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(202,240,248,0.45);
            line-height: 1.4;
        }

        .brand-trust {
            margin-top: 48px;
            padding-top: 32px;
            border-top: 1px solid rgba(72,202,228,0.1);
            font-size: 0.78rem;
            color: rgba(202,240,248,0.3);
            letter-spacing: 0.1em;
        }

        /* ── FORM PANEL (right) ── */
        .form-panel {
            background: var(--abyss);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 32px;
            position: relative;
            overflow-y: auto;
        }

        .form-panel::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--glass-border), transparent);
        }

        /* back link */
        .back-link {
            position: absolute;
            top: 28px; left: 28px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: rgba(202,240,248,0.4);
            text-decoration: none;
            font-size: 0.82rem;
            letter-spacing: 0.08em;
            transition: all 0.3s;
        }

        .back-link:hover {
            color: var(--aqua);
            gap: 12px;
        }

        /* card */
        .auth-card {
            width: 100%;
            max-width: 420px;
            animation: cardIn 0.6s cubic-bezier(0.23,1,0.32,1) both;
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translateY(24px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .auth-header {
            text-align: center;
            margin-bottom: 36px;
        }

        .auth-header-logo {
            width: 54px; height: 54px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid var(--glass-border);
            margin-bottom: 16px;
        }

        .auth-header h2 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.9rem;
            font-weight: 400;
            color: var(--white);
            letter-spacing: -0.01em;
        }

        .auth-header p {
            font-size: 0.83rem;
            color: rgba(202,240,248,0.4);
            margin-top: 4px;
        }

        /* tabs */
        .auth-tabs {
            display: flex;
            background: rgba(4,30,53,0.6);
            border: 1px solid var(--glass-border);
            border-radius: 50px;
            padding: 4px;
            margin-bottom: 32px;
            gap: 4px;
        }

        .auth-tab {
            flex: 1;
            padding: 10px 20px;
            border-radius: 50px;
            border: none;
            background: transparent;
            color: rgba(202,240,248,0.4);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.83rem;
            font-weight: 500;
            letter-spacing: 0.06em;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .auth-tab.active {
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            color: var(--deep);
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(0,180,216,0.3);
        }

        .auth-tab:not(.active):hover {
            color: var(--foam);
        }

        /* tab panes */
        .tab-pane { display: none; }
        .tab-pane.active { display: block; animation: paneIn 0.35s ease both; }

        @keyframes paneIn {
            from { opacity: 0; transform: translateX(10px); }
            to   { opacity: 1; transform: translateX(0); }
        }

        /* alerts */
        .auth-alert {
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 0.83rem;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            border: 1px solid;
        }

        .auth-alert.error {
            background: rgba(248,113,113,0.08);
            border-color: rgba(248,113,113,0.25);
            color: #fca5a5;
        }

        .auth-alert.success {
            background: rgba(74,222,128,0.08);
            border-color: rgba(74,222,128,0.25);
            color: #86efac;
        }

        /* field groups */
        .field-group {
            margin-bottom: 18px;
        }

        .field-label {
            display: block;
            font-size: 0.73rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(202,240,248,0.45);
            margin-bottom: 8px;
        }

        .field-wrap {
            position: relative;
            display: flex;
        }

        .field-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(0,180,216,0.45);
            font-size: 0.85rem;
            pointer-events: none;
            z-index: 1;
        }

        .field-input {
            width: 100%;
            background: rgba(4,30,53,0.7);
            border: 1px solid var(--glass-border);
            color: var(--white);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.9rem;
            padding: 13px 16px 13px 42px;
            border-radius: 12px;
            transition: all 0.3s;
            outline: none;
            --webkit-appearance: none;
        }

        .field-input::placeholder { color: rgba(202,240,248,0.2); }

        .field-input:focus {
            border-color: var(--aqua);
            background: rgba(0,180,216,0.07);
            box-shadow: 0 0 0 3px rgba(0,180,216,0.1);
        }

        .field-input.no-icon { padding-left: 16px; }

        textarea.field-input { resize: none; padding-top: 12px; }

        .toggle-pw {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: rgba(202,240,248,0.3);
            cursor: pointer;
            font-size: 0.85rem;
            padding: 4px;
            transition: color 0.2s;
        }
        .toggle-pw:hover { color: var(--aqua); }

        /* two-col row */
        .field-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        /* password strength */
        .pw-hints {
            margin-top: 8px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .pw-hint {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.72rem;
            color: rgba(202,240,248,0.3);
            padding: 4px 10px;
            border-radius: 50px;
            border: 1px solid rgba(202,240,248,0.1);
            transition: all 0.3s;
        }

        .pw-hint.met {
            color: #4ade80;
            border-color: rgba(74,222,128,0.3);
            background: rgba(74,222,128,0.06);
        }

        /* extras row (remember me + forgot) */
        .form-extras {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .check-label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 0.82rem;
            color: rgba(202,240,248,0.45);
        }

        .check-label input[type="checkbox"] {
            width: 16px; height: 16px;
            accent-color: var(--aqua);
            cursor: pointer;
        }

        .forgot-link {
            font-size: 0.82rem;
            color: rgba(0,180,216,0.7);
            text-decoration: none;
            transition: color 0.2s;
        }
        .forgot-link:hover { color: var(--aqua); }

        /* submit button */
        .btn-submit {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            border: none;
            border-radius: 50px;
            color: var(--deep);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.88rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.23,1,0.32,1);
            box-shadow: 0 6px 25px rgba(0,180,216,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-submit:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 14px 40px rgba(0,180,216,0.5);
        }

        .btn-submit:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            transform: none;
        }

        .form-note {
            text-align: center;
            margin-top: 16px;
            font-size: 0.75rem;
            color: rgba(202,240,248,0.25);
        }
    </style>
</head>
<body>
<div class="auth-layout">

    <!-- ── BRAND PANEL ── -->
    <div class="brand-panel">
        <div class="brand-panel-bg"></div>
        <div class="brand-glow"></div>
        <div class="water-rings">
            <div class="ring"></div>
            <div class="ring"></div>
            <div class="ring"></div>
            <div class="ring"></div>
        </div>

        <div class="brand-content">
            <img src="images/logo.jpg" alt="De Chavez Waterhaus" class="brand-logo">

            <h1 class="brand-title">De Chavez<br><em>Waterhaus</em></h1>
            <p class="brand-tagline">
                Pure, fresh water delivered straight to your door across Noveleta and nearby communities in Cavite.
            </p>

            <div class="brand-divider"></div>

            <div class="brand-features">
                <div class="brand-feat">
                    <div class="brand-feat-icon"><i class="fas fa-shield-alt"></i></div>
                    <div class="brand-feat-label">Multi-Stage<br>Purified</div>
                </div>
                <div class="brand-feat">
                    <div class="brand-feat-icon"><i class="fas fa-truck"></i></div>
                    <div class="brand-feat-label">Same-Day<br>Delivery</div>
                </div>
                <div class="brand-feat">
                    <div class="brand-feat-icon"><i class="fas fa-recycle"></i></div>
                    <div class="brand-feat-label">Eco-Friendly<br>Refills</div>
                </div>
            </div>

            <div class="brand-trust">Trusted by 2,500+ families since 2015</div>
        </div>
    </div>

    <!-- ── FORM PANEL ── -->
    <div class="form-panel">
        <a href="index.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Homepage
        </a>

        <div class="auth-card">
            <!-- Header -->
            <div class="auth-header">
                <img src="images/logo.jpg" alt="Logo" class="auth-header-logo">
                <h2>Welcome Back</h2>
                <p>Sign in to your account or create a new one</p>
            </div>

            <!-- Tabs -->
            <div class="auth-tabs" role="tablist">
                <button class="auth-tab active" id="tab-login" onclick="switchTab('login')" role="tab">
                    <i class="fas fa-sign-in-alt me-1"></i> Sign In
                </button>
                <button class="auth-tab" id="tab-register" onclick="switchTab('register')" role="tab">
                    <i class="fas fa-user-plus me-1"></i> Register
                </button>
            </div>

            <!-- LOGIN PANE -->
            <div class="tab-pane active" id="pane-login">
                <?php if ($error && isset($_POST['login'])): ?>
                    <div class="auth-alert error">
                        <i class="fas fa-exclamation-circle mt-1"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" autocomplete="on">
                    <div class="field-group">
                        <label class="field-label">Email Address</label>
                        <div class="field-wrap">
                            <i class="fas fa-envelope field-icon"></i>
                            <input type="email" name="email" class="field-input" placeholder="you@example.com" required autocomplete="email">
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="field-label">Password</label>
                        <div class="field-wrap">
                            <i class="fas fa-lock field-icon"></i>
                            <input type="password" name="password" class="field-input" id="login_password" placeholder="••••••••" required autocomplete="current-password">
                            <button type="button" class="toggle-pw" onclick="togglePw('login_password','loginEye')" tabindex="-1">
                                <i class="fas fa-eye" id="loginEye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-extras">
                        <label class="check-label">
                            <input type="checkbox" id="rememberMe">
                            Remember me
                        </label>
                        <a href="forgot_password.php" class="forgot-link">Forgot password?</a>
                    </div>

                    <button type="submit" name="login" class="btn-submit">
                        <i class="fas fa-sign-in-alt"></i> Sign In
                    </button>
                </form>
            </div>

            <!-- REGISTER PANE -->
            <div class="tab-pane" id="pane-register">
                <?php if ($error && isset($_POST['register'])): ?>
                    <div class="auth-alert error">
                        <i class="fas fa-exclamation-circle mt-1"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="auth-alert success">
                        <i class="fas fa-check-circle mt-1"></i>
                        <span><?php echo htmlspecialchars($success); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" id="registerForm" autocomplete="on">
                    <div class="field-row">
                        <div class="field-group">
                            <label class="field-label">First Name</label>
                            <div class="field-wrap">
                                <input type="text" name="firstname" class="field-input no-icon" placeholder="Juan" required autocomplete="given-name">
                            </div>
                        </div>
                        <div class="field-group">
                            <label class="field-label">Last Name</label>
                            <div class="field-wrap">
                                <input type="text" name="lastname" class="field-input no-icon" placeholder="Dela Cruz" required autocomplete="family-name">
                            </div>
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="field-label">Email Address</label>
                        <div class="field-wrap">
                            <i class="fas fa-envelope field-icon"></i>
                            <input type="email" name="email" class="field-input" placeholder="you@example.com" required autocomplete="email">
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="field-label">Phone Number</label>
                        <div class="field-wrap">
                            <i class="fas fa-mobile-alt field-icon"></i>
                            <input type="text" name="phone" class="field-input" placeholder="09XX XXX XXXX" required autocomplete="tel">
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="field-label">Complete Address</label>
                        <div class="field-wrap">
                            <i class="fas fa-map-marker-alt field-icon" style="top:16px; transform:none;"></i>
                            <textarea name="address" class="field-input" rows="2" placeholder="House No., Street, Barangay, City" required autocomplete="street-address"></textarea>
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="field-label">Password</label>
                        <div class="field-wrap">
                            <i class="fas fa-lock field-icon"></i>
                            <input type="password" name="password" class="field-input" id="reg_password" placeholder="Min. 8 characters" required minlength="8" autocomplete="new-password">
                            <button type="button" class="toggle-pw" onclick="togglePw('reg_password','regEye')" tabindex="-1">
                                <i class="fas fa-eye" id="regEye"></i>
                            </button>
                        </div>
                        <div class="pw-hints">
                            <span class="pw-hint" id="hint-len"><i class="fas fa-circle" style="font-size:0.45rem;"></i> 8+ chars</span>
                            <span class="pw-hint" id="hint-upper"><i class="fas fa-circle" style="font-size:0.45rem;"></i> Uppercase</span>
                            <span class="pw-hint" id="hint-num"><i class="fas fa-circle" style="font-size:0.45rem;"></i> Number</span>
                        </div>
                    </div>

                    <div class="field-group" style="margin-bottom: 28px;">
                        <label class="field-label">Confirm Password</label>
                        <div class="field-wrap">
                            <i class="fas fa-lock field-icon"></i>
                            <input type="password" name="confirm_password" class="field-input" id="reg_confirm" placeholder="Repeat password" required autocomplete="new-password">
                            <button type="button" class="toggle-pw" onclick="togglePw('reg_confirm','confirmEye')" tabindex="-1">
                                <i class="fas fa-eye" id="confirmEye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" name="register" class="btn-submit" id="registerBtn" disabled>
                        <i class="fas fa-user-plus"></i> Create Account
                    </button>

                    <div class="form-note">By registering, you agree to our Terms &amp; Privacy Policy.</div>
                </form>
            </div>
        </div><!-- /.auth-card -->
    </div><!-- /.form-panel -->
</div><!-- /.auth-layout -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // ── TAB SWITCHING ──
    function switchTab(tab) {
        document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
        document.getElementById('tab-' + tab).classList.add('active');
        document.getElementById('pane-' + tab).classList.add('active');
    }

    // ── PASSWORD TOGGLE ──
    function togglePw(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon  = document.getElementById(iconId);
        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        icon.className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
    }

    // ── PASSWORD STRENGTH ──
    const regPw      = document.getElementById('reg_password');
    const regConfirm = document.getElementById('reg_confirm');
    const regBtn     = document.getElementById('registerBtn');

    function updateHints() {
        const pw = regPw ? regPw.value : '';
        const hasLen   = pw.length >= 8;
        const hasUpper = /[A-Z]/.test(pw);
        const hasNum   = /[0-9]/.test(pw);
        const match    = pw === (regConfirm ? regConfirm.value : '') && pw.length > 0;

        setHint('hint-len',   hasLen);
        setHint('hint-upper', hasUpper);
        setHint('hint-num',   hasNum);

        if (regBtn) regBtn.disabled = !(hasLen && hasUpper && hasNum && match);
    }

    function setHint(id, met) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.toggle('met', met);
    }

    if (regPw)      regPw.addEventListener('input', updateHints);
    if (regConfirm) regConfirm.addEventListener('input', updateHints);

    // ── AUTO-OPEN REGISTER TAB IF PHP REGISTER ERROR/SUCCESS ──
    <?php if (isset($_POST['register'])): ?>
    switchTab('register');
    <?php endif; ?>

    // ── FOCUS FIRST INPUT ──
    window.addEventListener('DOMContentLoaded', () => {
        const first = document.querySelector('#pane-login .field-input');
        if (first) setTimeout(() => first.focus(), 300);
    });
</script>
</body>
</html>