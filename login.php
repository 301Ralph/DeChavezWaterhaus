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
                    } elseif ($user['Role'] === 'rider') {
                        header("Location: Rider/rider_dashboard.php");
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

            $stmt = $conn->prepare("INSERT INTO customers (Firstname, Lastname, Email, Contact, Address, Password, Role, two_factor_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, 0)");
            $stmt->bind_param("sssssss", $firstname, $lastname, $email, $phone, $address, $hashed_password, $role);

            if ($stmt->execute()) {
                $success = "Registration successful! Please login.";
            } else {
                $error = "Registration failed. Please try again.";
            }
            $stmt->close();
        }
        $check->close();
    }
}

function sendOTPEmail($email, $otp) {
    $apiKey = BREVO_API_KEY;
    $data = [
        'sender' => ['name' => 'De Chavez Waterhaus', 'email' => 'cocacc202501@gmail.com'],
        'to' => [['email' => $email]],
        'subject' => 'Your Login OTP Code',
        'htmlContent' => "<h2>Your OTP Code: <strong>$otp</strong></h2><p>Expires in 5 minutes.</p>"
    ];
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['accept: application/json', 'api-key: ' . $apiKey, 'content-type: application/json']);
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
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&amp;display=swap">
    <style>
        :root { --primary: #0077B6; --primary-dark: #023E8A; }
        body { font-family: 'Poppins', sans-serif; background: linear-gradient(135deg, #0077B6, #023E8A); min-height: 100vh; }
        .split-container { display: flex; min-height: 100vh; }
        .left-panel { flex: 1; background: url('https://images.unsplash.com/photo-1548839140-29a749e1cf4d?auto=format&fit=crop&w=2070&q=80') center/cover; position: relative; display: flex; align-items: center; justify-content: center; color: white; }
        .left-panel::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 119, 182, 0.85); }
        .left-content { position: relative; z-index: 2; padding: 40px; text-align: center; max-width: 500px; }
        .right-panel { flex: 1; display: flex; align-items: center; justify-content: center; padding: 40px; background: white; }
        .auth-card { width: 100%; max-width: 420px; }
        .nav-tabs .nav-link { color: #6c757d; font-weight: 600; border: none; }
        .nav-tabs .nav-link.active { color: var(--primary); border-bottom: 3px solid var(--primary); }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 0.2rem rgba(0, 119, 182, 0.25); }
        .btn-primary-custom { background: linear-gradient(135deg, #0077B6, #023E8A); border: none; font-weight: 600; padding: 12px; }
        .btn-primary-custom:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(0, 119, 182, 0.3); }
    </style>
</head>
<body>
    <div class="split-container">
        <!-- Left Panel - Website Info -->
        <div class="left-panel d-none d-lg-flex">
            <div class="left-content">
                <img src="images/logo.png" alt="Logo" style="width: 90px; height: 90px; border-radius: 50%; margin-bottom: 30px;">
                <h1 class="fw-bold mb-4" style="font-size: 2.8rem;">De Chavez Waterhaus</h1>
                <p class="lead mb-4">Pure, fresh water delivered straight to your door.</p>
                <div class="d-flex justify-content-center gap-4 mt-5">
                    <div><i class="fas fa-tint fa-2x mb-2"></i><div class="small">5-Gallon</div></div>
                    <div><i class="fas fa-truck fa-2x mb-2"></i><div class="small">Fast Delivery</div></div>
                    <div><i class="fas fa-shield-alt fa-2x mb-2"></i><div class="small">Safe & Pure</div></div>
                </div>
            </div>
        </div>

        <!-- Right Panel - Form -->
        <div class="right-panel">
            <div class="auth-card">
                <div class="text-center mb-4">
                    <img src="images/logo.png" alt="Logo" style="width: 60px; height: 60px; border-radius: 50%;">
                    <h4 class="fw-bold mt-3">Welcome Back</h4>
                    <p class="text-muted small">Sign in to continue</p>
                </div>

                <ul class="nav nav-tabs nav-justified mb-4" id="authTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="login-tab" data-bs-toggle="tab" data-bs-target="#login" type="button">Login</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="register-tab" data-bs-toggle="tab" data-bs-target="#register" type="button">Register</button>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- LOGIN -->
                    <div class="tab-pane fade show active" id="login">
                        <?php if ($error && isset($_POST['login'])): ?><div class="alert alert-danger py-2"><?php echo $error; ?></div><?php endif; ?>

                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Email</label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                            <div class="mb-4">
                                <label class="form-label fw-semibold">Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" name="password" id="login_password" required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('login_password', 'loginEye')">
                                        <i class="fas fa-eye" id="loginEye"></i>
                                    </button>
                                </div>
                            </div>
                            <button type="submit" name="login" class="btn btn-primary-custom w-100 py-3 rounded-pill">Sign In</button>
                        </form>
                        <div class="text-center mt-3">
                            <a href="forgot_password.php" class="text-primary text-decoration-none small">Forgot Password?</a>
                        </div>
                    </div>

                    <!-- REGISTER -->
                    <div class="tab-pane fade" id="register">
                        <?php if ($error && isset($_POST['register'])): ?><div class="alert alert-danger py-2"><?php echo $error; ?></div><?php endif; ?>
                        <?php if ($success): ?><div class="alert alert-success py-2"><?php echo $success; ?></div><?php endif; ?>

                        <form method="POST" id="registerForm">
                            <div class="row g-2 mb-3">
                                <div class="col-6"><input type="text" class="form-control" name="firstname" placeholder="First Name" required></div>
                                <div class="col-6"><input type="text" class="form-control" name="lastname" placeholder="Last Name" required></div>
                            </div>
                            <div class="mb-3"><input type="email" class="form-control" name="email" placeholder="Email Address" required></div>
                            <div class="mb-3"><input type="text" class="form-control" name="phone" placeholder="Phone Number" required></div>
                            <div class="mb-3"><textarea class="form-control" name="address" placeholder="Complete Address" rows="2" required></textarea></div>
                            
                            <div class="mb-3">
                                <div class="input-group">
                                    <input type="password" class="form-control" name="password" id="reg_password" placeholder="Password" required minlength="8">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('reg_password', 'regEye')">
                                        <i class="fas fa-eye" id="regEye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="mb-4">
                                <div class="input-group">
                                    <input type="password" class="form-control" name="confirm_password" id="reg_confirm" placeholder="Confirm Password" required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('reg_confirm', 'confirmEye')">
                                        <i class="fas fa-eye" id="confirmEye"></i>
                                    </button>
                                </div>
                            </div>
                            <button type="submit" name="register" class="btn btn-primary-custom w-100 py-3 rounded-pill" id="registerBtn" disabled>Create Account</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Password validation for registration
        const regPassword = document.getElementById('reg_password');
        const regConfirm = document.getElementById('reg_confirm');
        const registerBtn = document.getElementById('registerBtn');

        function checkPassword() {
            if (!regPassword) return;
            const password = regPassword.value;
            const hasLength = password.length >= 8;
            const hasUpper = /[A-Z]/.test(password);
            const hasNumber = /[0-9]/.test(password);
            const passwordsMatch = password === regConfirm.value && regConfirm.value.length > 0;

            registerBtn.disabled = !(hasLength && hasUpper && hasNumber && passwordsMatch);
        }

        if (regPassword) {
            regPassword.addEventListener('input', checkPassword);
            regConfirm.addEventListener('input', checkPassword);
        }
    </script>
</body>
</html>