<?php
include '../includes/connection.php';
session_start();

// Security check
if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'customer') {
    echo '<script>alert("Access denied. Customers only."); window.location = "../login.php";</script>';
    exit();
}

$userID = $_SESSION['userID'];
$userName = $_SESSION['userName'];

// Fetch user profile
$stmt = $conn->prepare("SELECT * FROM customers WHERE userID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $firstname = htmlspecialchars($_POST['firstname']);
    $lastname = htmlspecialchars($_POST['lastname']);
    $email = htmlspecialchars($_POST['email']);
    $phone = htmlspecialchars($_POST['phone']);
    $address = htmlspecialchars($_POST['address']);

    $updateStmt = $conn->prepare("UPDATE customers SET Firstname = ?, Lastname = ?, Email = ?, Contact = ?, Address = ? WHERE userID = ?");
    $updateStmt->bind_param("sssssi", $firstname, $lastname, $email, $phone, $address, $userID);
    
    if ($updateStmt->execute()) {
        $_SESSION['userName'] = $firstname;
        echo '<script>alert("Profile updated successfully!"); window.location = "profile.php";</script>';
    } else {
        echo '<script>alert("Error updating profile.");</script>';
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (password_verify($current_password, $user['Password'])) {
        if (strlen($new_password) < 8 || !preg_match('/[A-Z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
            echo '<script>alert("Password must be at least 8 characters with 1 uppercase and 1 number.");</script>';
        } elseif ($new_password !== $confirm_password) {
            echo '<script>alert("Passwords do not match.");</script>';
        } else {
            $new_password_hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $passwordStmt = $conn->prepare("UPDATE customers SET Password = ? WHERE userID = ?");
            $passwordStmt->bind_param("si", $new_password_hashed, $userID);
            
            if ($passwordStmt->execute()) {
                echo '<script>alert("Password changed successfully!"); window.location = "profile.php";</script>';
            } else {
                echo '<script>alert("Error changing password.");</script>';
            }
        }
    } else {
        echo '<script>alert("Current password is incorrect.");</script>';
    }
}

// Handle 2FA Enable/Disable
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toggle_2fa'])) {
    $new_status = $_POST['new_2fa_status'];

    if ($new_status == 1) {
        // Enable 2FA - Send OTP for verification
        $otp = rand(100000, 999999);
        $_SESSION['2fa_setup_otp'] = $otp;
        $_SESSION['2fa_setup_expiry'] = time() + 300;

        // Send OTP via Brevo
        $apiKey = 'YOUR_BREVO_API_KEY_HERE';
        $data = [
            'sender' => ['name' => 'De Chavez Waterhaus', 'email' => 'yourgmail@gmail.com'],
            'to' => [['email' => $user['Email']]],
            'subject' => 'Enable Two-Factor Authentication',
            'htmlContent' => "<h2>Your 2FA Setup Code: <strong>$otp</strong></h2><p>Expires in 5 minutes.</p>"
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

        echo '<script>alert("Verification code sent to your email. Please enter it to enable 2FA."); window.location = "profile.php?verify_2fa=1";</script>';
        exit();
    } else {
        // Disable 2FA
        $updateStmt = $conn->prepare("UPDATE customers SET two_factor_enabled = 0 WHERE userID = ?");
        $updateStmt->bind_param("i", $userID);
        $updateStmt->execute();

        echo '<script>alert("Two-Factor Authentication has been disabled."); window.location = "profile.php";</script>';
        exit();
    }
}

// Handle 2FA Verification
if (isset($_GET['verify_2fa']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_2fa_code'])) {
    $entered_otp = trim($_POST['otp_code']);

    if (time() > $_SESSION['2fa_setup_expiry']) {
        echo '<script>alert("Verification code expired. Please try again."); window.location = "profile.php";</script>';
        exit();
    }

    if ((string)$entered_otp === (string)$_SESSION['2fa_setup_otp']) {
        // Enable 2FA
        $updateStmt = $conn->prepare("UPDATE customers SET two_factor_enabled = 1 WHERE userID = ?");
        $updateStmt->bind_param("i", $userID);
        $updateStmt->execute();

        unset($_SESSION['2fa_setup_otp'], $_SESSION['2fa_setup_expiry']);

        echo '<script>alert("Two-Factor Authentication enabled successfully!"); window.location = "profile.php";</script>';
        exit();
    } else {
        echo '<script>alert("Invalid verification code."); window.location = "profile.php?verify_2fa=1";</script>';
        exit();
    }
}

// Handle account deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_account'])) {
    $confirm_password = $_POST['confirm_delete_password'];
    
    if (password_verify($confirm_password, $user['Password'])) {
        $deleteStmt = $conn->prepare("DELETE FROM customers WHERE userID = ?");
        $deleteStmt->bind_param("i", $userID);
        
        if ($deleteStmt->execute()) {
            session_destroy();
            echo '<script>alert("Your account has been deleted successfully."); window.location = "../index.php";</script>';
            exit();
        } else {
            echo '<script>alert("Error deleting account.");</script>';
        }
    } else {
        echo '<script>alert("Incorrect password. Account deletion failed.");</script>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile • De Chavez Waterhaus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&amp;display=swap">
    <style>
        :root { --primary: #0077B6; --primary-dark: #023E8A; }
        body { font-family: 'Poppins', sans-serif; background-color: #f8f9fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: 260px; background: white; box-shadow: 2px 0 15px rgba(0,0,0,0.05); z-index: 1000; transition: all 0.3s ease; }
        .sidebar.collapsed { width: 80px; }
        .sidebar .logo { padding: 25px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #eee; }
        .sidebar .logo img { width: 42px; height: 42px; border-radius: 50%; }
        .sidebar .nav-link { color: #495057; padding: 14px 22px; display: flex; align-items: center; gap: 14px; font-weight: 500; transition: all 0.3s ease; border-radius: 12px; margin: 4px 10px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background-color: #f0f7ff; color: var(--primary); }
        .sidebar .nav-link i { width: 22px; font-size: 1.1rem; }
        .main-content { margin-left: 260px; padding: 30px; transition: margin-left 0.3s ease; }
        .sidebar.collapsed ~ .main-content { margin-left: 80px; }
        .profile-card { background: white; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #f0f0f0; }
        .section-title { font-weight: 700; color: #1e293b; margin-bottom: 20px; }
        .info-row { padding: 12px 0; border-bottom: 1px solid #f0f0f0; }
        .info-row:last-child { border-bottom: none; }
        .info-label { font-weight: 600; color: #475569; width: 140px; display: inline-block; }
        .password-requirements { background: #f8f9fa; border-radius: 12px; padding: 12px 15px; font-size: 0.85rem; margin-top: 8px; }
        .requirement { display: flex; align-items: center; margin-bottom: 4px; }
        .requirement i { width: 16px; margin-right: 8px; }
        .requirement.valid { color: #198754; }
        .requirement.invalid { color: #6c757d; }
        .btn-danger-custom { background: linear-gradient(135deg, #dc3545, #b02a37); border: none; color: white; font-weight: 600; }
        .btn-danger-custom:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(220, 53, 69, 0.3); }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="logo">
            <img src="../images/logo.png" alt="Logo">
            <span class="fw-bold fs-5">De Chavez Waterhaus</span>
        </div>
        
        <div class="px-3 mt-2">
            <ul class="nav flex-column">
                <li class="nav-item"><a href="customer_dashboard.php" class="nav-link"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                <li class="nav-item"><a href="products.php" class="nav-link"><i class="fas fa-box"></i> <span>Products</span></a></li>
                <li class="nav-item"><a href="order_history.php" class="nav-link"><i class="fas fa-history"></i> <span>Order History</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link active"><i class="fas fa-user"></i> <span>Profile</span></a></li>
                <li class="nav-item mt-4"><a href="../logout.php" class="nav-link text-danger"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navbar -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-0">My Profile</h4>
                <p class="text-muted mb-0">Manage your account information</p>
            </div>
            
            <div class="dropdown">
                <button class="btn btn-light d-flex align-items-center gap-2 px-3 py-2 rounded-pill shadow-sm" data-bs-toggle="dropdown">
                    <div class="bg-primary text-white rounded-circle d-flex align-items: center; justify-content: center" style="width: 38px; height: 38px;">
                        <span class="fw-bold fs-6"><?php echo strtoupper(substr($userName, 0, 1)); ?></span>
                    </div>
                    <div class="text-start d-none d-md-block">
                        <div class="fw-semibold"><?php echo htmlspecialchars($userName); ?></div>
                        <small class="text-muted">Customer</small>
                    </div>
                    <i class="fas fa-chevron-down fa-sm text-muted ms-1"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow">
                    <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>

        <!-- Profile Information -->
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="profile-card p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="section-title mb-0">Profile Information</h5>
                        <button class="btn btn-primary px-4 rounded-pill" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                            <i class="fas fa-edit me-2"></i> Edit Profile
                        </button>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-row"><span class="info-label">Full Name</span> <span class="fw-semibold"><?php echo htmlspecialchars($user['Firstname'] . ' ' . $user['Lastname']); ?></span></div>
                            <div class="info-row"><span class="info-label">Email</span> <span class="fw-semibold"><?php echo htmlspecialchars($user['Email']); ?></span></div>
                            <div class="info-row"><span class="info-label">Phone</span> <span class="fw-semibold"><?php echo htmlspecialchars($user['Contact']); ?></span></div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row"><span class="info-label">Address</span> <span class="fw-semibold"><?php echo htmlspecialchars($user['Address']); ?></span></div>
                            <div class="info-row"><span class="info-label">Joined</span> <span class="fw-semibold"><?php echo date("F j, Y", strtotime($user['created_at'])); ?></span></div>
                            <div class="info-row"><span class="info-label">Status</span> 
                                <?php if ($user['verification_status'] == 'approved'): ?>
                                    <span class="badge bg-success px-3 py-2">Verified</span>
                                <?php elseif ($user['verification_status'] == 'pending'): ?>
                                    <span class="badge bg-warning text-dark px-3 py-2">Verification Pending</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary px-3 py-2">Not Verified</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4 pt-3 border-top">
                        <button class="btn btn-outline-primary px-4 rounded-pill" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                            <i class="fas fa-key me-2"></i> Change Password
                        </button>
                        
                        <button class="btn btn-outline-danger px-4 rounded-pill ms-2" data-bs-toggle="modal" data-bs-target="#deleteAccountModal">
                            <i class="fas fa-trash me-2"></i> Delete Account
                        </button>
                        
                        <?php if ($user['verification_status'] != 'approved' && $user['verification_status'] != 'pending'): ?>
                            <button class="btn btn-warning px-4 rounded-pill ms-2" data-bs-toggle="modal" data-bs-target="#verifyAccountModal">
                                <i class="fas fa-id-card me-2"></i> Verify Account
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- 2FA Section -->
            <div class="col-lg-4">
                <div class="profile-card p-4 h-100">
                    <h5 class="section-title">Security</h5>
                    
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <div class="fw-semibold">Two-Factor Authentication</div>
                            <small class="text-muted">Extra layer of security</small>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="2faToggle" 
                                   <?php echo ($user['two_factor_enabled'] == 1) ? 'checked' : ''; ?>
                                   onchange="toggle2FA(this.checked)">
                        </div>
                    </div>
                    
                    <div class="alert alert-info py-2 small mb-0">
                        <?php if ($user['two_factor_enabled'] == 1): ?>
                            <i class="fas fa-check-circle text-success me-1"></i> 2FA is currently <strong>enabled</strong>
                        <?php else: ?>
                            <i class="fas fa-info-circle me-1"></i> Enable 2FA for better security
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2FA Verification Modal -->
    <?php if (isset($_GET['verify_2fa'])): ?>
    <div class="modal fade show" id="verify2FAModal" tabindex="-1" style="display: block; background: rgba(0,0,0,0.5);">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Verify 2FA Setup</h5>
                </div>
                <form method="POST">
                    <div class="modal-body p-4">
                        <p class="text-muted">Enter the 6-digit code sent to your email to enable 2FA.</p>
                        <input type="text" class="form-control text-center" name="otp_code" maxlength="6" placeholder="000000" required style="font-size: 1.5rem; letter-spacing: 8px;">
                    </div>
                    <div class="modal-footer border-0 p-4 pt-0">
                        <a href="profile.php" class="btn btn-light px-4">Cancel</a>
                        <button type="submit" name="verify_2fa_code" class="btn btn-primary px-5">Enable 2FA</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Edit Profile Modal -->
    <div class="modal fade" id="editProfileModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Edit Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">First Name</label>
                                <input type="text" class="form-control" name="firstname" value="<?php echo $user['Firstname']; ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Last Name</label>
                                <input type="text" class="form-control" name="lastname" value="<?php echo $user['Lastname']; ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Email</label>
                                <input type="email" class="form-control" name="email" value="<?php echo $user['Email']; ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Phone Number</label>
                                <input type="text" class="form-control" name="phone" value="<?php echo $user['Contact']; ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Complete Address</label>
                                <textarea class="form-control" name="address" rows="3" required><?php echo $user['Address']; ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4 pt-0">
                        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_profile" class="btn btn-primary px-5">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="modal fade" id="changePasswordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Change Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="changePasswordForm">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Current Password</label>
                            <input type="password" class="form-control" name="current_password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('new_password', 'newPassEye')">
                                    <i class="fas fa-eye" id="newPassEye"></i>
                                </button>
                            </div>
                            <div class="password-requirements mt-2" id="passwordRequirements">
                                <div class="requirement" id="req-length"><i class="fas fa-times-circle"></i> <span>At least 8 characters</span></div>
                                <div class="requirement" id="req-uppercase"><i class="fas fa-times-circle"></i> <span>At least 1 uppercase (A-Z)</span></div>
                                <div class="requirement" id="req-number"><i class="fas fa-times-circle"></i> <span>At least 1 number (0-9)</span></div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Confirm New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password', 'confirmPassEye')">
                                    <i class="fas fa-eye" id="confirmPassEye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 p-4 pt-0">
                        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="change_password" class="btn btn-primary px-5" id="changePasswordBtn" disabled>Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Account Modal -->
    <div class="modal fade" id="deleteAccountModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-danger">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-exclamation-triangle me-2"></i>Delete Account</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-danger"><strong>Warning:</strong> This action cannot be undone. All your data will be permanently deleted.</div>
                    
                    <form method="POST" id="deleteAccountForm">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Enter your password to confirm deletion</label>
                            <input type="password" class="form-control" name="confirm_delete_password" required>
                        </div>
                        <div class="form-check mb-4">
                            <input class="form-check-input" type="checkbox" id="confirmDelete" required>
                            <label class="form-check-label text-danger" for="confirmDelete">I understand this action is permanent and cannot be undone.</label>
                        </div>
                        <button type="submit" name="delete_account" class="btn btn-danger-custom w-100 py-2" disabled>
                            <i class="fas fa-trash me-2"></i> Permanently Delete My Account
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar toggle
        const sidebar = document.getElementById('sidebar');
        const toggleBtn = document.createElement('button');
        toggleBtn.className = 'btn btn-light position-fixed d-lg-none shadow-sm';
        toggleBtn.style.cssText = 'top: 22px; left: 22px; z-index: 1100; border-radius: 12px;';
        toggleBtn.innerHTML = '<i class="fas fa-bars"></i>';
        document.body.appendChild(toggleBtn);
        
        toggleBtn.addEventListener('click', () => sidebar.classList.toggle('collapsed'));
        if (window.innerWidth < 992) sidebar.classList.add('collapsed');

        // 2FA Toggle
        function toggle2FA(isEnabled) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'profile.php';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'toggle_2fa';
            input.value = '1';
            
            const statusInput = document.createElement('input');
            statusInput.type = 'hidden';
            statusInput.name = 'new_2fa_status';
            statusInput.value = isEnabled ? '1' : '0';
            
            form.appendChild(input);
            form.appendChild(statusInput);
            document.body.appendChild(form);
            form.submit();
        }

        // Password toggle
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

        // Password requirements
        const newPasswordInput = document.getElementById('new_password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const changePasswordBtn = document.getElementById('changePasswordBtn');

        function checkPasswordRequirements() {
            if (!newPasswordInput) return;
            const password = newPasswordInput.value;
            const hasLength = password.length >= 8;
            const hasUppercase = /[A-Z]/.test(password);
            const hasNumber = /[0-9]/.test(password);
            const passwordsMatch = password === confirmPasswordInput.value && confirmPasswordInput.value.length > 0;

            updateRequirement('req-length', hasLength);
            updateRequirement('req-uppercase', hasUppercase);
            updateRequirement('req-number', hasNumber);

            changePasswordBtn.disabled = !(hasLength && hasUppercase && hasNumber && passwordsMatch);
        }

        function updateRequirement(id, isValid) {
            const element = document.getElementById(id);
            if (!element) return;
            const icon = element.querySelector('i');
            if (isValid) {
                element.classList.add('valid');
                element.classList.remove('invalid');
                icon.classList.remove('fa-times-circle');
                icon.classList.add('fa-check-circle');
            } else {
                element.classList.add('invalid');
                element.classList.remove('valid');
                icon.classList.remove('fa-check-circle');
                icon.classList.add('fa-times-circle');
            }
        }

        if (newPasswordInput) {
            newPasswordInput.addEventListener('input', checkPasswordRequirements);
            confirmPasswordInput.addEventListener('input', checkPasswordRequirements);
        }

        // Delete account validation
        const deleteForm = document.getElementById('deleteAccountForm');
        if (deleteForm) {
            const confirmCheckbox = document.getElementById('confirmDelete');
            const deleteBtn = deleteForm.querySelector('button[type="submit"]');
            confirmCheckbox.addEventListener('change', function() {
                deleteBtn.disabled = !this.checked;
            });
        }
    </script>
</body>
</html>