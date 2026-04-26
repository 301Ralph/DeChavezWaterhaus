<?php
include 'includes/connection.php';
session_start();

$error = '';
$success = '';
$step = $_SESSION['reset_step'] ?? 1;

$email = $_SESSION['reset_email'] ?? '';

// Step 1: Send OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_otp'])) {
    $email = trim($_POST['email']);

    if (empty($email)) {
        $error = "Please enter your email address.";
    } else {
        $stmt = $conn->prepare("SELECT userID, Firstname FROM customers WHERE Email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $otp = rand(100000, 999999);

            $_SESSION['reset_email'] = $email;
            $_SESSION['reset_otp'] = $otp;
            $_SESSION['reset_expiry'] = time() + 300;
            $_SESSION['reset_userID'] = $user['userID'];
            $_SESSION['reset_step'] = 2;
            require_once 'config.php';
            $apiKey = BREVO_API_KEY;
            $data = [
                'sender' => ['name' => 'De Chavez Waterhaus', 'email' => 'cocacc202501@gmail.com'],
                'to' => [['email' => $email]],
                'subject' => 'Password Reset OTP',
                'htmlContent' => "
                    <h2>Password Reset Code</h2>
                    <p>Hi {$user['Firstname']},</p>
                    <p>Your password reset code is: <strong style='font-size: 24px; color: #0077B6;'>$otp</strong></p>
                    <p>This code will expire in <strong>5 minutes</strong>.</p>
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

            $step = 2;
            $success = "OTP sent to your email.";
        } else {
            $error = "No account found with that email.";
        }
        $stmt->close();
    }
}

// Step 2: Verify OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    $entered_otp = trim($_POST['otp']);

    if (time() > ($_SESSION['reset_expiry'] ?? 0)) {
        $error = "OTP expired. Please request a new one.";
        session_unset();
        $step = 1;
    } elseif ((string)$entered_otp !== (string)($_SESSION['reset_otp'] ?? '')) {
        $error = "Invalid OTP. Please try again.";
        $step = 2;
    } else {
        $_SESSION['reset_step'] = 3;
        $step = 3;
        $success = "Code verified! Please set your new password.";
    }
}

// Step 3: Reset Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (strlen($new_password) < 8 || !preg_match('/[A-Z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
        $error = "Password must be at least 8 characters with 1 uppercase and 1 number.";
        $step = 3;
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
        $step = 3;
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $userID = $_SESSION['reset_userID'];

        $updateStmt = $conn->prepare("UPDATE customers SET Password = ? WHERE userID = ?");
        $updateStmt->bind_param("si", $hashed, $userID);
        $updateStmt->execute();
        $updateStmt->close();

        unset($_SESSION['reset_email'], $_SESSION['reset_otp'], $_SESSION['reset_expiry'], $_SESSION['reset_userID'], $_SESSION['reset_step']);

        $success = "Password reset successful! You can now login.";
        $step = 1;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password • De Chavez Waterhaus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&amp;display=swap">
    <style>
        :root { --primary: #0077B6; --primary-dark: #023E8A; }
        body { font-family: 'Poppins', sans-serif; background: linear-gradient(135deg, #0077B6, #023E8A); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .forgot-card { background: white; border-radius: 24px; box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.4); max-width: 420px; width: 100%; padding: 2.5rem; }
    </style>
</head>
<body>
    <div class="forgot-card">
        <div class="text-center mb-4">
            <i class="fas fa-key fa-4x text-primary mb-3"></i>
            <h4 class="fw-bold">Forgot Password</h4>
            <p class="text-muted">We'll send a code to reset your password.</p>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success text-center py-3">
                <?php echo $success; ?><br>
                <?php if ($step == 1): ?>
                    <a href="login.php" class="btn btn-primary mt-3 px-4">Back to Login</a>
                <?php endif; ?>
            </div>
        <?php elseif ($error): ?>
            <div class="alert alert-danger py-2 text-center"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if ($step == 1): ?>
            <!-- Step 1: Enter Email -->
            <form method="POST">
                <div class="mb-4">
                    <label class="form-label fw-semibold">Email Address</label>
                    <input type="email" class="form-control" name="email" placeholder="you@example.com" required>
                </div>
                <button type="submit" name="send_otp" class="btn btn-primary w-100 py-3 rounded-pill">Send Reset Code</button>
            </form>
        <?php elseif ($step == 2): ?>
            <!-- Step 2: Enter OTP -->
            <form method="POST">
                <div class="mb-4">
                    <label class="form-label fw-semibold">Enter 6-digit Code</label>
                    <input type="text" class="form-control text-center" name="otp" maxlength="6" placeholder="000000" required style="font-size: 1.5rem; letter-spacing: 8px;">
                    <div class="form-text">Sent to <?php echo htmlspecialchars($email); ?></div>
                </div>
                <button type="submit" name="verify_otp" class="btn btn-primary w-100 py-3 rounded-pill">Verify Code</button>
            </form>
        <?php elseif ($step == 3): ?>
            <!-- Step 3: New Password -->
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label fw-semibold">New Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" name="new_password" id="new_password" required minlength="8">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('new_password', 'newEye')">
                            <i class="fas fa-eye" id="newEye"></i>
                        </button>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold">Confirm New Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" name="confirm_password" id="confirm_password" required>
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password', 'confirmEye')">
                            <i class="fas fa-eye" id="confirmEye"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" name="reset_password" class="btn btn-primary w-100 py-3 rounded-pill">Reset Password</button>
            </form>
        <?php endif; ?>

        <div class="text-center mt-4">
            <a href="login.php" class="text-muted text-decoration-none small">← Back to Login</a>
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
    </script>
</body>
</html>