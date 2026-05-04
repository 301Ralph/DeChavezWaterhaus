<?php
include '../includes/connection.php';
session_start();

// Include Brevo config
if (file_exists('../config.php')) {
    include '../config.php';
}

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'admin') {
    echo '<script>alert("Access denied. Admins only."); window.location = "../login.php";</script>';
    exit();
}

$adminID = $_SESSION['userID'];
$adminName = $_SESSION['userName'] ?? 'Admin';

// Create uploads directory if not exists
$uploadDir = '../uploads/profile_pictures/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Fetch admin data
$admin = $conn->query("SELECT * FROM customers WHERE userID = $adminID")->fetch_assoc();

// ==================== HANDLE SEND VERIFICATION CODE (for login email) ====================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_verification'])) {
    $loginEmail = trim($admin['Email'] ?? '');
    
    if (empty($loginEmail)) {
        echo '<script>alert("No email found on your account."); window.location = "profile.php";</script>';
        exit();
    }
    
    // Generate 6-digit OTP (same as forgot_password.php)
    $otp = rand(100000, 999999);
    
    // Store in session
    $_SESSION['verify_email'] = $loginEmail;
    $_SESSION['verify_otp'] = $otp;
    $_SESSION['verify_expiry'] = time() + 300; // 5 minutes
    $_SESSION['verify_userID'] = $adminID;
    
    // Send via Brevo (same style as forgot_password.php)
    $apiKey = BREVO_API_KEY;
    $data = [
        'sender' => ['name' => 'De Chavez Waterhaus', 'email' => 'cocacc202501@gmail.com'],
        'to' => [['email' => $loginEmail]],
        'subject' => 'Verify Your Email - De Chavez Waterhaus',
        'htmlContent' => "
            <h2>Email Verification Code</h2>
            <p>Hi {$adminName},</p>
            <p>Your verification code for your login email is: <strong style='font-size: 24px; color: #0077B6;'>$otp</strong></p>
            <p>This code will expire in <strong>5 minutes</strong>.</p>
            <p>Go to your Admin Profile to enter this code and verify your email.</p>
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

    echo '<script>alert("✅ Verification code sent to ' . htmlspecialchars($loginEmail) . '"); window.location = "profile.php";</script>';
    exit();
}

// ==================== HANDLE VERIFY CODE ====================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['verify_code'])) {
    $entered_otp = trim($_POST['verification_code'] ?? '');
    
    if (time() > ($_SESSION['verify_expiry'] ?? 0)) {
        echo '<script>alert("❌ Code expired. Please request a new one."); window.location = "profile.php";</script>';
        exit();
    } elseif ((string)$entered_otp !== (string)($_SESSION['verify_otp'] ?? '')) {
        echo '<script>alert("❌ Invalid code. Please try again."); window.location = "profile.php";</script>';
        exit();
    } else {
        // Mark login email as verified
        $stmt = $conn->prepare("UPDATE customers SET email_verified = 1, email_verification_token = NULL WHERE userID = ?");
        $stmt->bind_param("i", $adminID);
        $stmt->execute();
        $stmt->close();
        
        // Clear session
        unset($_SESSION['verify_email'], $_SESSION['verify_otp'], $_SESSION['verify_expiry'], $_SESSION['verify_userID']);
        
        echo '<script>alert("🎉 Email verified successfully! Your login email can now be used for password recovery."); window.location = "profile.php";</script>';
        exit();
    }
}

// ==================== HANDLE PROFILE UPDATE ====================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $firstname = htmlspecialchars($_POST['firstname']);
    $lastname = htmlspecialchars($_POST['lastname']);
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $contact = htmlspecialchars($_POST['contact']);
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo '<script>alert("Invalid email format."); window.location = "profile.php";</script>';
        exit();
    }
    
    // Check email uniqueness
    $emailCheck = $conn->prepare("SELECT userID FROM customers WHERE Email = ? AND userID != ?");
    $emailCheck->bind_param("si", $email, $adminID);
    $emailCheck->execute();
    if ($emailCheck->get_result()->num_rows > 0) {
        echo '<script>alert("Email already in use by another account."); window.location = "profile.php";</script>';
        exit();
    }
    $emailCheck->close();
    
    // Handle profile picture
    $profilePicture = $admin['profile_picture'] ?? '';
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
        if (in_array($_FILES['profile_picture']['type'], $allowedTypes)) {
            $fileName = 'admin_' . $adminID . '_' . time() . '.' . pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $targetPath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $targetPath)) {
                if (!empty($profilePicture) && file_exists('../' . $profilePicture)) unlink('../' . $profilePicture);
                $profilePicture = 'uploads/profile_pictures/' . $fileName;
            }
        }
    }
    
    // Update profile (no recovery_email)
    $stmt = $conn->prepare("UPDATE customers SET Firstname = ?, Lastname = ?, Email = ?, Contact = ?, profile_picture = ? WHERE userID = ?");
    $stmt->bind_param("sssssi", $firstname, $lastname, $email, $contact, $profilePicture, $adminID);
    
    if ($stmt->execute()) {
        // Reset email verification when email is changed (must verify the new email)
        $conn->query("UPDATE customers SET email_verified = 0 WHERE userID = $adminID");
        
        $_SESSION['userName'] = $firstname . ' ' . $lastname;
        echo '<script>alert("Profile updated successfully! Please verify your new email address."); window.location = "profile.php";</script>';
    } else {
        echo '<script>alert("Error updating profile."); window.location = "profile.php";</script>';
    }
    $stmt->close();
}

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    if (strlen($newPassword) < 8 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
        echo '<script>alert("Password must be at least 8 characters with 1 uppercase letter and 1 number."); window.location = "profile.php";</script>';
        exit();
    }
    
    if ($newPassword !== $confirmPassword) {
        echo '<script>alert("New passwords do not match."); window.location = "profile.php";</script>';
        exit();
    }
    
    if (!password_verify($currentPassword, $admin['Password'])) {
        echo '<script>alert("Current password is incorrect."); window.location = "profile.php";</script>';
        exit();
    }
    
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE customers SET Password = ? WHERE userID = ?");
    $stmt->bind_param("si", $hashedPassword, $adminID);
    
    if ($stmt->execute()) {
        echo '<script>alert("Password changed successfully!"); window.location = "profile.php";</script>';
    } else {
        echo '<script>alert("Error changing password."); window.location = "profile.php";</script>';
    }
    $stmt->close();
}

// Handle Remove Photo
if (isset($_GET['remove_photo'])) {
    if (!empty($admin['profile_picture']) && file_exists('../' . $admin['profile_picture'])) {
        unlink('../' . $admin['profile_picture']);
    }
    $stmt = $conn->prepare("UPDATE customers SET profile_picture = NULL WHERE userID = ?");
    $stmt->bind_param("i", $adminID);
    $stmt->execute();
    $stmt->close();
    echo '<script>window.location = "profile.php";</script>';
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile • Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&amp;display=swap">
    <link rel="icon" href="../images/logo.jpg" type="image/x-icon">
    <style>
        :root { --primary: #0077B6; --primary-dark: #023E8A; }
        body { font-family: 'Poppins', sans-serif; background-color: #f8f9fa; }
        
        .sidebar { 
            position: fixed; top: 0; left: 0; height: 100vh; width: 260px; 
            background: white; box-shadow: 2px 0 15px rgba(0,0,0,0.05); z-index: 1000; 
            transition: all 0.3s ease; 
            display: flex; flex-direction: column;
        }
        .sidebar .nav-menu { flex: 1; overflow-y: auto; padding-bottom: 20px; }
        .sidebar .logout-section { padding: 15px 10px; border-top: 1px solid #eee; background: white; }
        .sidebar .logo { padding: 25px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #eee; }
        .sidebar .logo img { width: 42px; height: 42px; border-radius: 50%; object-fit: cover; }
        .sidebar .nav-link { 
            color: #495057; padding: 14px 22px; display: flex; align-items: center; gap: 14px; 
            font-weight: 500; transition: all 0.3s ease; border-radius: 12px; margin: 4px 10px;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { 
            background-color: #f0f7ff; color: var(--primary); 
        }
        .sidebar .nav-link i { width: 22px; font-size: 1.1rem; }
        
        .main-content { margin-left: 260px; padding: 30px; transition: margin-left 0.3s ease; }
        
        .section-title { font-weight: 700; color: #1e293b; margin-bottom: 20px; }
        
        .sidebar .nav-link { padding: 12px 18px; margin: 2px 8px; border-radius: 10px; }
        .sidebar::-webkit-scrollbar { width: 6px; }
        .sidebar::-webkit-scrollbar-thumb { background: #ccc; border-radius: 3px; }

        @media (max-width: 991.98px) {
            .main-content { margin-left: 0; padding: 20px; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
        }
        
        .profile-avatar { width: 150px; height: 150px; border-radius: 50%; object-fit: cover; border: 5px solid #fff; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        .profile-avatar-placeholder { width: 150px; height: 150px; border-radius: 50%; background: linear-gradient(135deg, #0077B6, #023E8A); display: flex; align-items: center; justify-content: center; color: white; font-size: 3rem; font-weight: bold; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        
        .verify-box { background: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%); border-left: 5px solid #0077B6; border-radius: 12px; padding: 20px; }
        .verified-badge { background: #10b981; color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; }
    </style>
</head>
<body>
    <!-- Sidebar (UNCHANGED) -->
    <div class="sidebar" id="sidebar">
        <div class="logo p-4 d-flex align-items-center gap-3 border-bottom">
            <img src="../images/logo.jpg" alt="Logo" style="width: 42px; height: 42px; border-radius: 50%; object-fit: cover;">
            <div>
                <span class="fw-bold fs-5">De Chavez Waterhaus</span>
                <small class="d-block text-muted">Admin Panel</small>
            </div>
        </div>
        
        <div class="nav-menu px-3 mt-2">
            <ul class="nav flex-column">
                <li class="nav-item"><a href="admin_dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt me-3"></i> <span>Dashboard</span></a></li>
                <li class="nav-item"><a href="manage_products.php" class="nav-link"><i class="fas fa-box me-3"></i> <span>Manage Products</span></a></li>
                <li class="nav-item"><a href="manage_orders.php" class="nav-link"><i class="fas fa-shopping-cart me-3"></i> <span>Manage Orders</span></a></li>
                <li class="nav-item"><a href="manage_users.php" class="nav-link"><i class="fas fa-users me-3"></i> <span>Manage Users</span></a></li>
                <li class="nav-item"><a href="manage_employees.php" class="nav-link"><i class="fas fa-users me-3"></i> <span>Manage Employees</span></a></li>
                <li class="nav-item"><a href="attendance_management.php" class="nav-link"><i class="fas fa-clock me-3"></i> <span>Attendance</span></a></li>
                <li class="nav-item"><a href="payroll_management.php" class="nav-link"><i class="fas fa-money-bill me-3"></i> <span>Payroll</span></a></li>
                <li class="nav-item"><a href="support_tickets.php" class="nav-link"><i class="fas fa-headset me-3"></i> <span>Support Tickets</span></a></li>
                <li class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-chart-bar me-3"></i> <span>Reports & Analytics</span></a></li>
                <li class="nav-item"><a href="profile.php" class="nav-link active"><i class="fas fa-user me-3"></i> <span>My Profile</span></a></li>
            </ul>
        </div>
        
        <div class="logout-section">
            <ul class="nav flex-column">
                <li class="nav-item"><a href="../logout.php" class="nav-link text-danger"><i class="fas fa-sign-out-alt me-3"></i> <span>Logout</span></a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-0">My Profile</h4>
                <p class="text-muted mb-0">Manage your account information</p>
            </div>
            
            <div class="dropdown">
                <button class="btn btn-light d-flex align-items-center gap-2 px-3 py-2 rounded-pill shadow-sm" data-bs-toggle="dropdown">
                    <?php if (!empty($admin['profile_picture']) && file_exists('../' . $admin['profile_picture'])): ?>
                        <img src="../<?php echo $admin['profile_picture']; ?>" alt="Profile" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover;">
                    <?php else: ?>
                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                            <span class="fw-bold fs-6"><?php echo strtoupper(substr($adminName, 0, 1)); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="text-start d-none d-md-block">
                        <div class="fw-semibold"><?php echo htmlspecialchars($adminName); ?></div>
                        <small class="text-muted">Administrator</small>
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

        <div class="row g-4">
            <!-- Profile Overview -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body p-4 text-center">
                        <div class="mb-4">
                            <?php if (!empty($admin['profile_picture']) && file_exists('../' . $admin['profile_picture'])): ?>
                                <img src="../<?php echo $admin['profile_picture']; ?>" alt="Profile" class="profile-avatar">
                            <?php else: ?>
                                <div class="profile-avatar-placeholder">
                                    <?php echo strtoupper(substr($adminName, 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($admin['Firstname'] . ' ' . $admin['Lastname']); ?></h5>
                        <span class="badge bg-primary px-3 py-2 mb-3">Administrator</span>
                        
                        <div class="d-grid gap-2 mt-4">
                            <?php if (!empty($admin['profile_picture'])): ?>
                                <a href="profile.php?remove_photo=1" class="btn btn-outline-danger btn-sm" onclick="return confirm('Remove profile picture?')">
                                    <i class="fas fa-trash me-1"></i> Remove Photo
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit Profile Form -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="section-title mb-0">Edit Profile Information</h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" enctype="multipart/form-data">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">First Name</label>
                                    <input type="text" class="form-control" name="firstname" value="<?php echo htmlspecialchars($admin['Firstname']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Last Name</label>
                                    <input type="text" class="form-control" name="lastname" value="<?php echo htmlspecialchars($admin['Lastname']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Login Email</label>
                                    <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($admin['Email']); ?>" required>
                                    <small class="text-muted">This email is used for login and password recovery</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Phone Number</label>
                                    <input type="tel" class="form-control" name="contact" value="<?php echo htmlspecialchars($admin['Contact'] ?? ''); ?>">
                                </div>
                                
                                <!-- EMAIL VERIFICATION (for login email only) -->
                                <div class="col-12">
                                    <div class="verify-box">
                                        <label class="form-label fw-semibold text-primary mb-1">
                                            <i class="fas fa-envelope me-2"></i> Verify Your Login Email
                                        </label>
                                        
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <span class="text-muted"><?php echo htmlspecialchars($admin['Email']); ?></span>
                                            
                                            <?php 
                                            $isVerified = !empty($admin['email_verified']) && $admin['email_verified'] == 1;
                                            $hasPendingCode = !empty($_SESSION['verify_otp']) && !empty($_SESSION['verify_email']);
                                            ?>
                                            
                                            <?php if ($isVerified): ?>
                                                <span class="verified-badge"><i class="fas fa-check me-1"></i> Verified</span>
                                            <?php else: ?>
                                                <button type="submit" name="send_verification" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-paper-plane me-1"></i> Send Verification Code
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <small class="text-muted d-block mb-2">
                                            Verify your login email so it can be used for password recovery on the Forgot Password page.
                                        </small>
                                        
                                        <!-- Verification Code Input -->
                                        <?php if ($hasPendingCode && !$isVerified): ?>
                                            <div class="mt-3 p-3 bg-white rounded border">
                                                <label class="form-label fw-semibold small mb-1">Enter the 6-digit code sent to <strong><?php echo htmlspecialchars($_SESSION['verify_email']); ?></strong>:</label>
                                                <div class="d-flex gap-2">
                                                    <input type="text" name="verification_code" class="form-control form-control-sm" 
                                                           placeholder="123456" maxlength="6" style="max-width: 160px; font-size: 1.1rem; letter-spacing: 4px;" required>
                                                    <button type="submit" name="verify_code" class="btn btn-success btn-sm px-4">
                                                        <i class="fas fa-check me-1"></i> Verify
                                                    </button>
                                                </div>
                                                <small class="text-muted">Code expires in 5 minutes.</small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Profile Picture</label>
                                    <input type="file" class="form-control" name="profile_picture" accept="image/jpeg,image/png,image/jpg,image/webp">
                                    <small class="text-muted">JPG, PNG, WebP • Max 2MB</small>
                                </div>
                            </div>
                            
                            <div class="mt-4 pt-3 border-top">
                                <button type="submit" name="update_profile" class="btn btn-primary px-5">
                                    <i class="fas fa-save me-2"></i> Save All Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Change Password -->
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="section-title mb-0">Change Password</h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" id="passwordForm">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Current Password</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" name="current_password" id="current_password" required>
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('current_password')"><i class="fas fa-eye"></i></button>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">New Password</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" name="new_password" id="new_password" required minlength="8">
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('new_password')"><i class="fas fa-eye"></i></button>
                                    </div>
                                    <small class="text-muted">Min 8 chars + 1 uppercase + 1 number</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Confirm New Password</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" name="confirm_password" id="confirm_password" required>
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password')"><i class="fas fa-eye"></i></button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-4 pt-3 border-top">
                                <button type="submit" name="change_password" class="btn btn-warning px-5">
                                    <i class="fas fa-key me-2"></i> Change Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="mt-4 p-3 bg-light rounded-3 border">
            <small class="text-muted">
                <i class="fas fa-info-circle me-1"></i> 
                <strong>Note:</strong> Verifying your login email allows you to use the Forgot Password page to reset your password securely.
            </small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile sidebar toggle
        const sidebar = document.getElementById('sidebar');
        const mobileToggle = document.createElement('button');
        mobileToggle.className = 'btn btn-light d-lg-none position-fixed shadow-sm';
        mobileToggle.style.cssText = 'top: 22px; left: 22px; z-index: 1100; border-radius: 12px;';
        mobileToggle.innerHTML = '<i class="fas fa-bars"></i>';
        document.body.appendChild(mobileToggle);
        mobileToggle.addEventListener('click', () => sidebar.classList.toggle('show'));

        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const btn = event.currentTarget || event.target.closest('button');
            const icon = btn ? btn.querySelector('i') : null;
            
            if (field.type === 'password') {
                field.type = 'text';
                if (icon) { icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash'); }
            } else {
                field.type = 'password';
                if (icon) { icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye'); }
            }
        }

        // Password form validation
        document.getElementById('passwordForm').addEventListener('submit', function(e) {
            const newPass = document.getElementById('new_password').value;
            const confirmPass = document.getElementById('confirm_password').value;
            
            if (newPass !== confirmPass) {
                e.preventDefault();
                alert('New passwords do not match!');
                return false;
            }
            if (newPass.length < 8 || !/[A-Z]/.test(newPass) || !/[0-9]/.test(newPass)) {
                e.preventDefault();
                alert('Password must be at least 8 characters with 1 uppercase letter and 1 number!');
                return false;
            }
        });
    </script>
</body>
</html>