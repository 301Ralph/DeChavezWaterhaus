<?php
include 'includes/connection.php';
session_start();

$error = '';
$success = '';
$showForm = false;
$token = $_GET['token'] ?? '';

if (empty($token)) {
    header("Location: login.php");
    exit();
}

// Verify token
$stmt = $conn->prepare("SELECT pr.userID, pr.expiry, c.Email, c.Firstname 
                        FROM password_resets pr 
                        JOIN customers c ON pr.userID = c.userID 
                        WHERE pr.token = ?");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $reset = $result->fetch_assoc();
    
    if (strtotime($reset['expiry']) < time()) {
        $error = "This reset link has expired. Please request a new one.";
    } else {
        $showForm = true;
        $userID = $reset['userID'];
        $email = $reset['Email'];
        $firstname = $reset['Firstname'];
    }
} else {
    $error = "Invalid reset link.";
}
$stmt->close();

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password']) && $showForm) {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (strlen($new_password) < 8 || !preg_match('/[A-Z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
        $error = "Password must be at least 8 characters with 1 uppercase and 1 number.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        // Update password
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $updateStmt = $conn->prepare("UPDATE customers SET Password = ? WHERE userID = ?");
        $updateStmt->bind_param("si", $hashed, $userID);
        $updateStmt->execute();
        $updateStmt->close();

        // Delete used token
        $conn->query("DELETE FROM password_resets WHERE token = '$token'");

        $success = "Password reset successful! You can now login with your new password.";
        $showForm = false;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password • De Chavez Waterhaus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&amp;display=swap">
    <style>
        :root { --primary: #0077B6; --primary-dark: #023E8A; }
        body { font-family: 'Poppins', sans-serif; background: linear-gradient(135deg, #0077B6, #023E8A); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .reset-card { background: white; border-radius: 24px; box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.4); max-width: 420px; width: 100%; padding: 2.5rem; }
    </style>
</head>
<body>
    <div class="reset-card">
        <div class="text-center mb-4">
            <i class="fas fa-lock fa-4x text-primary mb-3"></i>
            <h4 class="fw-bold">Set New Password</h4>
            <p class="text-muted">Enter your new password below.</p>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success text-center py-3">
                <?php echo $success; ?><br>
                <a href="login.php" class="btn btn-primary mt-3 px-4">Go to Login</a>
            </div>
        <?php elseif ($error): ?>
            <div class="alert alert-danger py-2 text-center"><?php echo $error; ?></div>
            <div class="text-center mt-3">
                <a href="forgot_password.php" class="btn btn-outline-primary">Request New Reset Link</a>
            </div>
        <?php elseif ($showForm): ?>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label fw-semibold">New Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" name="new_password" id="new_password" required minlength="8">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('new_password', 'newEye')">
                            <i class="fas fa-eye" id="newEye"></i>
                        </button>
                    </div>
                    <div class="password-requirements mt-2 small" style="background:#f8f9fa; padding:8px 12px; border-radius:8px;">
                        <div id="req-length"><i class="fas fa-times-circle text-muted"></i> At least 8 characters</div>
                        <div id="req-uppercase"><i class="fas fa-times-circle text-muted"></i> At least 1 uppercase (A-Z)</div>
                        <div id="req-number"><i class="fas fa-times-circle text-muted"></i> At least 1 number (0-9)</div>
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

        // Password requirements live check
        const newPass = document.getElementById('new_password');
        const confirmPass = document.getElementById('confirm_password');

        function checkReqs() {
            if (!newPass) return;
            const val = newPass.value;
            const hasLen = val.length >= 8;
            const hasUp = /[A-Z]/.test(val);
            const hasNum = /[0-9]/.test(val);

            document.getElementById('req-length').innerHTML = `<i class="fas fa-${hasLen ? 'check' : 'times'}-circle ${hasLen ? 'text-success' : 'text-muted'}"></i> At least 8 characters`;
            document.getElementById('req-uppercase').innerHTML = `<i class="fas fa-${hasUp ? 'check' : 'times'}-circle ${hasUp ? 'text-success' : 'text-muted'}"></i> At least 1 uppercase (A-Z)`;
            document.getElementById('req-number').innerHTML = `<i class="fas fa-${hasNum ? 'check' : 'times'}-circle ${hasNum ? 'text-success' : 'text-muted'}"></i> At least 1 number (0-9)`;
        }

        if (newPass) {
            newPass.addEventListener('input', checkReqs);
            confirmPass.addEventListener('input', checkReqs);
        }
    </script>
</body>
</html>