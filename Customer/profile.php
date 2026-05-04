<?php
include '../includes/connection.php';
include '../config.php';
session_start();

// Security check
if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'customer') {
    echo '<script>alert("Access denied. Customers only."); window.location = "../login.php";</script>';
    exit();
}

$userID   = $_SESSION['userID'];
$userName = $_SESSION['userName'];

// Fetch user profile
$stmt = $conn->prepare("SELECT * FROM customers WHERE userID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Handle Profile Picture Upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_photo'])) {
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
        $target_dir = "../uploads/profilepicturefolder/";
        
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES["profile_picture"]["name"], PATHINFO_EXTENSION));
        $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (in_array($file_extension, $allowed_types)) {
            $new_filename = "profile_" . $userID . "_" . time() . "." . $file_extension;
            $target_file = $target_dir . $new_filename;
            
            if (move_uploaded_file($_FILES["profile_picture"]["tmp_name"], $target_file)) {
                if (!empty($user['profile_picture']) && file_exists($user['profile_picture'])) {
                    unlink($user['profile_picture']);
                }
                
                $db_path = "uploads/profilepicturefolder/" . $new_filename;
                
                $updateStmt = $conn->prepare("UPDATE customers SET profile_picture = ? WHERE userID = ?");
                $updateStmt->bind_param("si", $db_path, $userID);
                $updateStmt->execute();
                $updateStmt->close();
                
                echo '<script>alert("Profile picture updated!"); window.location = "profile.php";</script>';
                exit();
            }
        } else {
            echo '<script>alert("Only JPG, PNG, and GIF files are allowed.");</script>';
        }
    }
}

// Handle Remove Profile Picture
if (isset($_GET['remove_photo'])) {
    if (!empty($user['profile_picture']) && file_exists("../" . $user['profile_picture'])) {
        unlink("../" . $user['profile_picture']);
    }
    $conn->query("UPDATE customers SET profile_picture = NULL WHERE userID = $userID");
    echo '<script>window.location = "profile.php";</script>';
    exit();
}

// Handle ID Verification Upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_id'])) {
    if (isset($_FILES['id_file']) && $_FILES['id_file']['error'] == 0) {
        $target_dir = "../uploads/verification/";
        
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES["id_file"]["name"], PATHINFO_EXTENSION));
        $allowed_types = ['jpg', 'jpeg', 'png', 'pdf'];
        
        if (in_array($file_extension, $allowed_types)) {
            $new_filename = "id_" . $userID . "_" . time() . "." . $file_extension;
            $target_file = $target_dir . $new_filename;
            
            if (move_uploaded_file($_FILES["id_file"]["tmp_name"], $target_file)) {
                if (!empty($user['VerificationFile']) && file_exists("../" . $user['VerificationFile'])) {
                    unlink("../" . $user['VerificationFile']);
                }
                
                $db_path = "uploads/verification/" . $new_filename;
                
                $updateStmt = $conn->prepare("UPDATE customers SET VerificationFile = ?, verification_status = 'pending' WHERE userID = ?");
                $updateStmt->bind_param("si", $db_path, $userID);
                $updateStmt->execute();
                $updateStmt->close();
                
                echo '<script>alert("ID uploaded successfully! Your account is now pending verification."); window.location = "profile.php";</script>';
                exit();
            }
        } else {
            echo '<script>alert("Only JPG, PNG, and PDF files are allowed.");</script>';
        }
    } else {
        echo '<script>alert("Please select a valid ID file to upload.");</script>';
    }
}

// Handle Send Email Verification (OTP Code)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_email_verification'])) {
    if ($user['email_verified'] == 0) {
        $verification_code = sprintf('%06d', mt_rand(0, 999999));
        
        $codeStmt = $conn->prepare("UPDATE customers SET email_verification_token = ? WHERE userID = ?");
        $codeStmt->bind_param("si", $verification_code, $userID);
        $codeStmt->execute();
        $codeStmt->close();
        
        $apiKey = BREVO_API_KEY;
        
        $data = [
            'sender' => ['name' => 'De Chavez Waterhaus', 'email' => 'cocacc202501@gmail.com'],
            'to' => [['email' => $user['Email']]],
            'subject' => 'Your Email Verification Code',
            'htmlContent' => "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                    <h2 style='color: #0077B6;'>De Chavez Waterhaus</h2>
                    <h3>Your Verification Code</h3>
                    <p>Hello {$user['Firstname']},</p>
                    <p>Please use the following 6-digit code to verify your email address:</p>
                    <div style='background: #f8f9fa; padding: 20px; text-align: center; margin: 20px 0; border-radius: 8px;'>
                        <span style='font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #0077B6;'>$verification_code</span>
                    </div>
                    <p><strong>This code will expire in 10 minutes.</strong></p>
                    <p>If you didn't request this code, please ignore this email.</p>
                    <hr style='margin: 20px 0; border: none; border-top: 1px solid #eee;'>
                    <p style='color: #666; font-size: 12px;'>De Chavez Waterhaus - Your Trusted Water Delivery Service</p>
                </div>
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
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 201) {
            echo '<script>alert("Verification code sent to your email! Please check your inbox."); window.location = "profile.php?verify_email=1";</script>';
        } else {
            echo '<script>alert("Failed to send verification code. Please try again later."); window.location = "profile.php";</script>';
        }
    } else {
        echo '<script>alert("Your email is already verified."); window.location = "profile.php";</script>';
    }
    exit();
}

// Handle Email Verification Code Submission
if (isset($_GET['verify_email']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_email_code'])) {
    $entered_code = trim($_POST['email_code']);
    
    $checkStmt = $conn->prepare("SELECT email_verification_token FROM customers WHERE userID = ?");
    $checkStmt->bind_param("i", $userID);
    $checkStmt->execute();
    $result = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();
    
    if ($result && $result['email_verification_token'] == $entered_code) {
        $verifyStmt = $conn->prepare("UPDATE customers SET email_verified = 1, email_verification_token = NULL WHERE userID = ?");
        $verifyStmt->bind_param("i", $userID);
        $verifyStmt->execute();
        $verifyStmt->close();
        
        echo '<script>alert("Email verified successfully!"); window.location = "profile.php";</script>';
    } else {
        echo '<script>alert("Invalid verification code. Please try again."); window.location = "profile.php?verify_email=1";</script>';
    }
    exit();
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $firstname = trim($_POST['firstname']);
    $lastname = trim($_POST['lastname']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $city = trim($_POST['city']);
    $barangay = trim($_POST['barangay']);
    $street = trim($_POST['street']);
    
    $full_address = "$street, Brgy. $barangay, $city, Cavite";

    $errors = [];
    
    if (!preg_match("/^[a-zA-Z\s]+$/", $firstname)) {
        $errors[] = "First name should only contain letters.";
    }
    if (!preg_match("/^[a-zA-Z\s]+$/", $lastname)) {
        $errors[] = "Last name should only contain letters.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }
    if (!empty($phone) && !preg_match("/^[0-9]{10,11}$/", $phone)) {
        $errors[] = "Phone number must be 10-11 digits.";
    }
    if (empty($city) || empty($barangay) || empty($street)) {
        $errors[] = "Please complete all address fields.";
    }

    if (empty($errors)) {
        $updateStmt = $conn->prepare("UPDATE customers SET Firstname = ?, Lastname = ?, Email = ?, Contact = ?, Address = ?, City = ?, Barangay = ?, Street = ? WHERE userID = ?");
        $updateStmt->bind_param("ssssssssi", $firstname, $lastname, $email, $phone, $full_address, $city, $barangay, $street, $userID);
        
        if ($updateStmt->execute()) {
            $_SESSION['userName'] = $firstname;
            echo '<script>alert("Profile updated successfully!"); window.location = "profile.php";</script>';
        } else {
            echo '<script>alert("Error updating profile.");</script>';
        }
    } else {
        $errorMsg = implode("\\n", $errors);
        echo "<script>alert('$errorMsg');</script>";
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
        $otp = rand(100000, 999999);
        $_SESSION['2fa_setup_otp'] = $otp;
        $_SESSION['2fa_setup_expiry'] = time() + 300;

        $apiKey = BREVO_API_KEY;
        $data = [
            'sender' => ['name' => 'De Chavez Waterhaus', 'email' => 'noreply@dechavezwaterhaus.com'],
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
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 201) {
            echo '<script>alert("Verification code sent to your email. Please enter it to enable 2FA."); window.location = "profile.php?verify_2fa=1";</script>';
        } else {
            echo '<script>alert("Failed to send verification code. Please try again."); window.location = "profile.php";</script>';
        }
        exit();
    } else {
        $updateStmt = $conn->prepare("UPDATE customers SET two_factor_enabled = 0 WHERE userID = ?");
        $updateStmt->bind_param("i", $userID);
        $updateStmt->execute();

        echo '<script>alert("Two-Factor Authentication has been disabled."); window.location = "profile.php";</script>';
        exit();
    }
}

// Handle Resend 2FA OTP
if (isset($_GET['resend_2fa'])) {
    if (isset($_SESSION['2fa_setup_otp']) && isset($_SESSION['2fa_setup_expiry'])) {
        $otp = rand(100000, 999999);
        $_SESSION['2fa_setup_otp'] = $otp;
        $_SESSION['2fa_setup_expiry'] = time() + 300;

        $apiKey = BREVO_API_KEY;
        $data = [
            'sender' => ['name' => 'De Chavez Waterhaus', 'email' => 'cocacc202501@gmail.com'],
            'to' => [['email' => $user['Email']]],
            'subject' => 'Enable Two-Factor Authentication (Resend)',
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
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 201) {
            echo '<script>alert("New verification code sent to your email."); window.location = "profile.php?verify_2fa=1";</script>';
        } else {
            echo '<script>alert("Failed to resend verification code."); window.location = "profile.php?verify_2fa=1";</script>';
        }
    } else {
        echo '<script>alert("No active verification session. Please start over."); window.location = "profile.php";</script>';
    }
    exit();
}

// Handle 2FA Verification
if (isset($_GET['verify_2fa']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_2fa_code'])) {
    $entered_otp = trim($_POST['otp_code']);

    if (time() > $_SESSION['2fa_setup_expiry']) {
        echo '<script>alert("Verification code expired. Please try again."); window.location = "profile.php";</script>';
        exit();
    }

    if ((string)$entered_otp === (string)$_SESSION['2fa_setup_otp']) {
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

// Handle Add New Address
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_address'])) {
    $label = htmlspecialchars($_POST['label']);
    $city = htmlspecialchars($_POST['city']);
    $barangay = htmlspecialchars($_POST['barangay']);
    $street = htmlspecialchars($_POST['street']);
    $contact = htmlspecialchars($_POST['contact_number'] ?? '');
    $is_default = isset($_POST['is_default']) ? 1 : 0;

    $full_address = "$street, Brgy. $barangay, $city, Cavite";

    if ($is_default) {
        $conn->query("UPDATE delivery_addresses SET is_default = 0 WHERE userID = $userID");
    }

    $stmt = $conn->prepare("INSERT INTO delivery_addresses (userID, label, full_address, contact_number, is_default) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("isssi", $userID, $label, $full_address, $contact, $is_default);
    $stmt->execute();
    $stmt->close();

    echo '<script>alert("Address added successfully!"); window.location = "profile.php";</script>';
    exit();
}

// Handle Edit Address
$editAddress = null;
if (isset($_GET['edit_address'])) {
    $addrID = intval($_GET['edit_address']);
    $stmt = $conn->prepare("SELECT * FROM delivery_addresses WHERE addressID = ? AND userID = ?");
    $stmt->bind_param("ii", $addrID, $userID);
    $stmt->execute();
    $editAddress = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Handle Update Address
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_address'])) {
    $addrID = intval($_POST['addressID']);
    $label = htmlspecialchars($_POST['label']);
    $city = htmlspecialchars($_POST['city']);
    $barangay = htmlspecialchars($_POST['barangay']);
    $street = htmlspecialchars($_POST['street']);
    $contact = htmlspecialchars($_POST['contact_number'] ?? '');
    $is_default = isset($_POST['is_default']) ? 1 : 0;

    $full_address = "$street, Brgy. $barangay, $city, Cavite";

    if ($is_default) {
        $conn->query("UPDATE delivery_addresses SET is_default = 0 WHERE userID = $userID");
    }

    $stmt = $conn->prepare("UPDATE delivery_addresses SET label = ?, full_address = ?, contact_number = ?, is_default = ? WHERE addressID = ? AND userID = ?");
    $stmt->bind_param("sssiii", $label, $full_address, $contact, $is_default, $addrID, $userID);
    $stmt->execute();
    $stmt->close();

    echo '<script>alert("Address updated successfully!"); window.location = "profile.php";</script>';
    exit();
}

// Handle Set Default Address
if (isset($_GET['set_default'])) {
    $addrID = intval($_GET['set_default']);
    $conn->query("UPDATE delivery_addresses SET is_default = 0 WHERE userID = $userID");
    $conn->query("UPDATE delivery_addresses SET is_default = 1 WHERE addressID = $addrID AND userID = $userID");
    echo '<script>window.location = "profile.php";</script>';
    exit();
}

// Handle Delete Address
if (isset($_GET['delete_address'])) {
    $addrID = intval($_GET['delete_address']);
    $conn->query("DELETE FROM delivery_addresses WHERE addressID = $addrID AND userID = $userID");
    echo '<script>window.location = "profile.php";</script>';
    exit();
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

$notifCount = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE userID = $userID AND is_read = 0")->fetch_assoc()['unread'] ?? 0;
$firstName = explode(' ', $userName)[0];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile • De Chavez Waterhaus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="icon" href="../images/logo.jpg" type="image/x-icon">
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
            --glass-border: rgba(72,202,228,0.18);
            --sidebar-w: 260px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--deep);
            color: var(--white);
            min-height: 100vh;
        }

        /* ── SIDEBAR ── */
        .sidebar {
            position: fixed;
            top: 0; left: 0;
            height: 100vh;
            width: var(--sidebar-w);
            background: var(--abyss);
            border-right: 1px solid var(--glass-border);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s ease;
        }

        .sidebar-logo {
            padding: 24px 22px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid var(--glass-border);
            flex-shrink: 0;
        }

        .sidebar-logo img {
            width: 40px; height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid rgba(0,180,216,0.35);
            box-shadow: 0 0 14px rgba(0,180,216,0.2);
        }

        .sidebar-logo span {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.05rem;
            font-weight: 500;
            color: var(--white);
            line-height: 1.2;
        }

        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 16px 12px 20px;
            scrollbar-width: thin;
            scrollbar-color: rgba(72,202,228,0.15) transparent;
        }

        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(72,202,228,0.15); border-radius: 2px; }

        .nav-section-label {
            font-size: 0.62rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: rgba(202,240,248,0.25);
            padding: 16px 12px 6px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            border-radius: 10px;
            color: rgba(202,240,248,0.5) !important;
            text-decoration: none;
            font-size: 0.87rem;
            font-weight: 500;
            transition: all 0.25s ease;
            margin-bottom: 2px;
            position: relative;
        }

        .nav-link i {
            width: 18px;
            text-align: center;
            font-size: 0.9rem;
            color: rgba(0,180,216,0.4);
            transition: color 0.25s;
        }

        .nav-link:hover {
            background: var(--glass);
            color: var(--foam) !important;
        }

        .nav-link:hover i { color: var(--aqua); }

        .nav-link.active {
            background: linear-gradient(135deg, rgba(0,119,182,0.25), rgba(0,180,216,0.12));
            border: 1px solid rgba(0,180,216,0.2);
            color: var(--aqua) !important;
        }

        .nav-link.active i { color: var(--aqua); }

        .nav-link.active::before {
            content: '';
            position: absolute;
            left: 0; top: 20%; bottom: 20%;
            width: 3px;
            background: var(--aqua);
            border-radius: 0 3px 3px 0;
        }

        .nav-link.danger { color: rgba(252,165,165,0.6) !important; }
        .nav-link.danger i { color: rgba(252,165,165,0.5); }
        .nav-link.danger:hover { background: rgba(248,113,113,0.08); color: #fca5a5 !important; }

        /* ── MAIN ── */
        .main-content {
            margin-left: var(--sidebar-w);
            min-height: 100vh;
            padding: 28px 32px;
            transition: margin-left 0.3s ease;
        }

        /* ── TOP BAR ── */
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }

        .topbar-greeting h4 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.7rem;
            font-weight: 400;
            color: var(--white);
            line-height: 1.1;
        }

        .topbar-greeting p {
            font-size: 0.82rem;
            color: rgba(202,240,248,0.4);
            margin-top: 2px;
        }

        .topbar-actions { display: flex; align-items: center; gap: 12px; }

        .topbar-btn {
            width: 42px; height: 42px;
            border-radius: 50%;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            color: rgba(202,240,248,0.6);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.9rem;
            text-decoration: none;
            transition: all 0.3s;
            position: relative;
        }

        .topbar-btn:hover {
            background: rgba(0,180,216,0.15);
            border-color: var(--aqua);
            color: var(--aqua);
        }

        .topbar-notif-badge {
            position: absolute;
            top: -3px; right: -3px;
            background: var(--gold);
            color: var(--deep);
            font-size: 0.58rem;
            font-weight: 700;
            min-width: 16px;
            height: 16px;
            border-radius: 50px;
            display: flex; align-items: center; justify-content: center;
            padding: 0 4px;
        }

        .avatar-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            border-radius: 50px;
            padding: 6px 14px 6px 6px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .avatar-btn:hover {
            border-color: rgba(0,180,216,0.35);
            background: rgba(0,180,216,0.1);
        }

        .avatar-circle {
            width: 34px; height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            color: var(--deep);
            font-weight: 700;
            font-size: 0.85rem;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }

        .avatar-circle img { width: 100%; height: 100%; object-fit: cover; }

        .avatar-name {
            font-size: 0.82rem;
            font-weight: 500;
            color: var(--white);
        }

        .avatar-role {
            font-size: 0.7rem;
            color: rgba(202,240,248,0.4);
        }

        /* ── PROFILE CARDS ── */
        .profile-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.6), rgba(3,15,30,0.8));
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            padding: 28px;
        }

        .section-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.4rem;
            font-weight: 500;
            color: var(--white);
            margin-bottom: 20px;
        }

        .info-row {
            padding: 14px 0;
            border-bottom: 1px solid var(--glass-border);
        }

        .info-row:last-child { border-bottom: none; }

        .info-label {
            font-weight: 600;
            color: rgba(202,240,248,0.5);
            width: 140px;
            display: inline-block;
        }

        .password-requirements {
            background: rgba(4,30,53,0.5);
            border-radius: 12px;
            padding: 12px 15px;
            font-size: 0.85rem;
            margin-top: 8px;
        }

        .requirement {
            display: flex;
            align-items: center;
            margin-bottom: 4px;
        }

        .requirement i { width: 16px; margin-right: 8px; }
        .requirement.valid { color: #22c55e; }
        .requirement.invalid { color: rgba(202,240,248,0.4); }

        /* Modal */
        .modal-content {
            background: var(--ocean);
            border: 1px solid var(--glass-border);
            border-radius: 18px;
        }

        .modal-header {
            border-bottom: 1px solid var(--glass-border);
            padding: 20px 24px;
        }

        .modal-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.4rem;
            font-weight: 500;
        }

        .modal-footer {
            border-top: 1px solid var(--glass-border);
            padding: 20px 24px;
        }

        .form-control, .form-select {
            background: rgba(4,30,53,0.6);
            border: 1px solid var(--glass-border);
            color: var(--white);
            border-radius: 10px;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--aqua);
            box-shadow: 0 0 0 0.2rem rgba(0,180,216,0.15);
            background: rgba(4,30,53,0.8);
        }

        .form-label {
            color: rgba(202,240,248,0.7);
            font-weight: 500;
        }

        /* Mobile */
        @media (max-width: 991px) {
            .main-content { margin-left: 0; padding: 20px 18px; }
        }

        @media (max-width: 576px) {
            .main-content { padding: 16px 14px; }
            .profile-card { padding: 20px !important; }
        }
    </style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <img src="../images/logo.jpg" alt="Logo">
        <span>De Chavez<br>Waterhaus</span>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="customer_dashboard.php" class="nav-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="products.php" class="nav-link">
            <i class="fas fa-droplet"></i> Products
        </a>
        <a href="order_history.php" class="nav-link">
            <i class="fas fa-history"></i> Order History
        </a>
        <a href="order_tracking.php" class="nav-link">
            <i class="fas fa-map-marker-alt"></i> Track Orders
        </a>
        <a href="recurring_orders.php" class="nav-link">
            <i class="fas fa-redo"></i> Recurring Orders
        </a>

        <div class="nav-section-label">Account</div>
        <a href="support_tickets.php" class="nav-link">
            <i class="fas fa-headset"></i> Support
        </a>
        <a href="notifications.php" class="nav-link">
            <i class="fas fa-bell"></i> Notifications
        </a>
        <a href="profile.php" class="nav-link active">
            <i class="fas fa-user"></i> Profile
        </a>

        <div class="nav-section-label" style="margin-top: 16px;"></div>
        <a href="../logout.php" class="nav-link danger">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </nav>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ── MAIN CONTENT ── -->
<main class="main-content">

    <!-- Top Bar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="mobile-toggle d-lg-none" id="mobileToggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="topbar-greeting">
                <h4>My Profile</h4>
                <p>Manage your account information and settings</p>
            </div>
        </div>

        <div class="topbar-actions">
            <a href="notifications.php" class="topbar-btn">
                <i class="fas fa-bell"></i>
                <?php if ($notifCount > 0): ?>
                    <span class="topbar-notif-badge"><?php echo $notifCount > 9 ? '9+' : $notifCount; ?></span>
                <?php endif; ?>
            </a>

            <div class="dropdown">
                <div class="avatar-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="avatar-circle">
                        <?php if (!empty($user['profile_picture']) && file_exists('../' . $user['profile_picture'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr($userName, 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="d-none d-md-block">
                        <div class="avatar-name"><?php echo htmlspecialchars($userName); ?></div>
                        <div class="avatar-role">Customer</div>
                    </div>
                    <i class="fas fa-chevron-down fa-xs ms-1" style="color:rgba(202,240,248,0.3);"></i>
                </div>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Profile Content -->
    <div class="row g-4">
        <!-- Profile Information -->
        <div class="col-lg-8">
            <div class="profile-card">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="section-title mb-0">Profile Information</h5>
                    <button class="btn btn-primary px-4 rounded-pill" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                        <i class="fas fa-edit me-2"></i> Edit Profile
                    </button>
                </div>

                <!-- Profile Picture -->
                <div class="text-center mb-4">
                    <div class="position-relative d-inline-block">
                        <?php if (!empty($user['profile_picture']) && file_exists("../" . $user['profile_picture'])): ?>
                            <img src="../<?php echo $user['profile_picture']; ?>" 
                                alt="Profile Picture" 
                                class="rounded-circle border border-3 border-primary shadow-sm" 
                                style="width: 120px; height: 120px; object-fit: cover;">
                        <?php else: ?>
                            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center border border-3 border-primary shadow-sm" 
                                style="width: 120px; height: 120px; font-size: 3rem;">
                                <?php echo strtoupper(substr($userName, 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="position-absolute bottom-0 end-0 d-flex gap-2">
                            <form method="POST" enctype="multipart/form-data" class="d-inline">
                                <label class="btn btn-primary btn-sm rounded-pill px-3 py-1 shadow-sm d-flex align-items-center gap-1" style="cursor: pointer; font-size: 0.75rem; white-space: nowrap;">
                                    <i class="fas fa-camera fa-sm"></i>
                                    <span>Change Photo</span>
                                    <input type="file" name="profile_picture" accept="image/*" class="d-none" onchange="this.form.submit()">
                                </label>
                                <input type="hidden" name="upload_photo" value="1">
                            </form>
                            
                            <?php if (!empty($user['profile_picture'])): ?>
                                <a href="profile.php?remove_photo=1" 
                                class="btn btn-danger btn-sm rounded-pill px-3 py-1 shadow-sm d-flex align-items-center gap-1" 
                                style="font-size: 0.75rem; white-space: nowrap;"
                                onclick="return confirm('Remove profile picture?')">
                                    <i class="fas fa-trash fa-sm"></i>
                                    <span>Remove</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="small text-muted mt-2">Click "Change Photo" to update your profile picture</div>
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
                    <button class="btn btn-glass px-4 rounded-pill" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                        <i class="fas fa-key me-2"></i> Change Password
                    </button>
                    
                    <button class="btn btn-danger-glass px-4 rounded-pill ms-2" data-bs-toggle="modal" data-bs-target="#deleteAccountModal">
                        <i class="fas fa-trash me-2"></i> Delete Account
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Security Section -->
        <div class="col-lg-4">
            <div class="profile-card h-100">
                <h5 class="section-title">Security</h5>
                
                <!-- Email Verification -->
                <div class="mb-4 pb-3 border-bottom" style="border-color: var(--glass-border);">
                    <div class="fw-semibold mb-2">Email Verification</div>
                    
                    <?php if ($user['email_verified'] == 1): ?>
                        <div class="alert alert-success py-2 small mb-0">
                            <i class="fas fa-check-circle me-1"></i> <strong>Verified</strong> - Your email is confirmed
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning py-2 small mb-2">
                            <i class="fas fa-exclamation-triangle me-1"></i> <strong>Not Verified</strong> - Please verify your email
                        </div>
                        <form method="POST" class="d-inline">
                            <button type="submit" name="send_email_verification" class="btn btn-warning btn-sm rounded-pill px-3 py-1">
                                <i class="fas fa-envelope me-1"></i> Send Verification Email
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
                
                <!-- ID Verification -->
                <div class="mb-4 pb-3 border-bottom" style="border-color: var(--glass-border);">
                    <div class="fw-semibold mb-2">Account Verification</div>
                    
                    <?php if ($user['verification_status'] == 'approved'): ?>
                        <div class="alert alert-success py-2 small mb-2">
                            <i class="fas fa-check-circle me-1"></i> <strong>Verified</strong> - Your account has been verified
                        </div>
                    <?php elseif ($user['verification_status'] == 'pending'): ?>
                        <div class="alert alert-warning py-2 small mb-2">
                            <i class="fas fa-clock me-1"></i> <strong>Pending Review</strong> - Your ID is being reviewed
                        </div>
                        <?php if (!empty($user['VerificationFile'])): ?>
                            <a href="../<?php echo htmlspecialchars($user['VerificationFile']); ?>" target="_blank" class="btn btn-glass btn-sm">
                                <i class="fas fa-file-alt me-1"></i> View Submitted ID
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-secondary py-2 small mb-2">
                            <i class="fas fa-info-circle me-1"></i> Verify your account for full access
                        </div>
                        <button class="btn btn-warning btn-sm rounded-pill px-3 py-1" data-bs-toggle="modal" data-bs-target="#verifyAccountModal">
                            <i class="fas fa-id-card me-1"></i> Upload ID for Verification
                        </button>
                    <?php endif; ?>
                </div>
                
                <!-- 2FA Toggle -->
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
        
        <!-- Delivery Addresses -->
        <div class="col-lg-8 mt-4">
            <div class="profile-card">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="section-title mb-0">Delivery Addresses</h5>
                    <button class="btn btn-primary btn-sm px-3 rounded-pill" data-bs-toggle="modal" data-bs-target="#addAddressModal">
                        <i class="fas fa-plus me-1"></i> Add New
                    </button>
                </div>
                
                <?php
                $addrStmt = $conn->prepare("SELECT * FROM delivery_addresses WHERE userID = ? ORDER BY is_default DESC, created_at DESC");
                $addrStmt->bind_param("i", $userID);
                $addrStmt->execute();
                $addresses = $addrStmt->get_result();
                $addrStmt->close();
                ?>
                
                <?php if ($addresses->num_rows > 0): ?>
                    <div class="row g-3">
                        <?php while ($addr = $addresses->fetch_assoc()) { ?>
                            <div class="col-md-6">
                                <div class="border rounded-3 p-3 h-100 <?php echo $addr['is_default'] ? 'border-primary' : ''; ?>" style="border-color: var(--glass-border);">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <span class="fw-bold"><?php echo htmlspecialchars($addr['label']); ?></span>
                                            <?php if ($addr['is_default']): ?>
                                                <span class="badge bg-primary ms-2">Default</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-glass" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v"></i></button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <?php if (!$addr['is_default']): ?>
                                                    <li><a class="dropdown-item" href="profile.php?set_default=<?php echo $addr['addressID']; ?>">Set as Default</a></li>
                                                <?php endif; ?>
                                                <li><a class="dropdown-item" href="profile.php?edit_address=<?php echo $addr['addressID']; ?>">Edit</a></li>
                                                <li><a class="dropdown-item text-danger" href="profile.php?delete_address=<?php echo $addr['addressID']; ?>" onclick="return confirm('Delete this address?')">Delete</a></li>
                                            </ul>
                                        </div>
                                    </div>
                                    <div class="mt-2 small" style="color: rgba(202,240,248,0.5);">
                                        <?php echo nl2br(htmlspecialchars($addr['full_address'])); ?>
                                    </div>
                                    <?php if ($addr['contact_number']): ?>
                                        <div class="small mt-1"><i class="fas fa-phone me-1"></i> <?php echo $addr['contact_number']; ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4" style="color: rgba(202,240,248,0.4);">
                        <i class="fas fa-map-marker-alt fa-3x mb-3 opacity-50"></i>
                        <p>No saved addresses yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

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
                    <div class="text-center mt-3">
                        <a href="profile.php?resend_2fa=1" class="btn btn-link btn-sm text-primary">
                            <i class="fas fa-redo me-1"></i> Didn't receive the code? Resend
                        </a>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <a href="profile.php" class="btn btn-glass px-4">Cancel</a>
                    <button type="submit" name="verify_2fa_code" class="btn btn-primary px-5">Enable 2FA</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Email Verification Modal -->
<?php if (isset($_GET['verify_email'])): ?>
<div class="modal fade show" id="verifyEmailModal" tabindex="-1" style="display: block; background: rgba(0,0,0,0.5);">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Verify Your Email</h5>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <p class="text-muted">Enter the 6-digit verification code sent to your email.</p>
                    <input type="text" class="form-control text-center" name="email_code" maxlength="6" placeholder="000000" required style="font-size: 1.5rem; letter-spacing: 8px;">
                    <div class="text-center mt-3">
                        <a href="profile.php?send_email_verification=1" class="btn btn-link btn-sm text-primary">
                            <i class="fas fa-redo me-1"></i> Didn't receive the code? Resend
                        </a>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <a href="profile.php" class="btn btn-glass px-4">Cancel</a>
                    <button type="submit" name="verify_email_code" class="btn btn-primary px-5">Verify Email</button>
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
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
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
                            <input type="tel" class="form-control" name="phone" value="<?php echo $user['Contact']; ?>" 
                                   pattern="[0-9]{10,11}" maxlength="11" oninput="this.value = this.value.replace(/[^0-9]/g, '')" required>
                            <small class="text-muted">10-11 digits only</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">City / Municipality (Cavite)</label>
                            <select class="form-select" name="city" required>
                                <option value="">Select City</option>
                                <option value="Bacoor" <?php echo (($user['City'] ?? '') == 'Bacoor') ? 'selected' : ''; ?>>Bacoor</option>
                                <option value="Imus" <?php echo (($user['City'] ?? '') == 'Imus') ? 'selected' : ''; ?>>Imus</option>
                                <option value="Dasmariñas" <?php echo (($user['City'] ?? '') == 'Dasmariñas') ? 'selected' : ''; ?>>Dasmariñas</option>
                                <option value="General Trias" <?php echo (($user['City'] ?? '') == 'General Trias') ? 'selected' : ''; ?>>General Trias</option>
                                <option value="Kawit" <?php echo (($user['City'] ?? '') == 'Kawit') ? 'selected' : ''; ?>>Kawit</option>
                                <option value="Noveleta" <?php echo (($user['City'] ?? '') == 'Noveleta') ? 'selected' : ''; ?>>Noveleta</option>
                                <option value="Rosario" <?php echo (($user['City'] ?? '') == 'Rosario') ? 'selected' : ''; ?>>Rosario</option>
                                <option value="Tanza" <?php echo (($user['City'] ?? '') == 'Tanza') ? 'selected' : ''; ?>>Tanza</option>
                                <option value="Trece Martires" <?php echo (($user['City'] ?? '') == 'Trece Martires') ? 'selected' : ''; ?>>Trece Martires</option>
                                <option value="Amadeo" <?php echo (($user['City'] ?? '') == 'Amadeo') ? 'selected' : ''; ?>>Amadeo</option>
                                <option value="General Emilio Aguinaldo" <?php echo (($user['City'] ?? '') == 'General Emilio Aguinaldo') ? 'selected' : ''; ?>>General Emilio Aguinaldo</option>
                                <option value="Indang" <?php echo (($user['City'] ?? '') == 'Indang') ? 'selected' : ''; ?>>Indang</option>
                                <option value="Magallanes" <?php echo (($user['City'] ?? '') == 'Magallanes') ? 'selected' : ''; ?>>Magallanes</option>
                                <option value="Maragondon" <?php echo (($user['City'] ?? '') == 'Maragondon') ? 'selected' : ''; ?>>Maragondon</option>
                                <option value="Mendez" <?php echo (($user['City'] ?? '') == 'Mendez') ? 'selected' : ''; ?>>Mendez</option>
                                <option value="Naic" <?php echo (($user['City'] ?? '') == 'Naic') ? 'selected' : ''; ?>>Naic</option>
                                <option value="Silang" <?php echo (($user['City'] ?? '') == 'Silang') ? 'selected' : ''; ?>>Silang</option>
                                <option value="Tagaytay" <?php echo (($user['City'] ?? '') == 'Tagaytay') ? 'selected' : ''; ?>>Tagaytay</option>
                                <option value="Ternate" <?php echo (($user['City'] ?? '') == 'Ternate') ? 'selected' : ''; ?>>Ternate</option>
                                <option value="Alfonso" <?php echo (($user['City'] ?? '') == 'Alfonso') ? 'selected' : ''; ?>>Alfonso</option>
                                <option value="General Mariano Alvarez" <?php echo (($user['City'] ?? '') == 'General Mariano Alvarez') ? 'selected' : ''; ?>>General Mariano Alvarez (GMA)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Barangay</label>
                            <input type="text" class="form-control" name="barangay" value="<?php echo $user['Barangay'] ?? ''; ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">House/Unit No. & Street</label>
                            <input type="text" class="form-control" name="street" value="<?php echo $user['Street'] ?? ''; ?>" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-glass px-4" data-bs-dismiss="modal">Cancel</button>
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
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
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
                <div class="modal-footer">
                    <button type="button" class="btn btn-glass px-4" data-bs-dismiss="modal">Cancel</button>
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
                    <button type="submit" name="delete_account" class="btn btn-danger w-100 py-2" disabled>
                        <i class="fas fa-trash me-2"></i> Permanently Delete My Account
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Verify Account Modal (ID Upload) -->
<div class="modal fade" id="verifyAccountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-id-card me-2"></i> Verify Your Account
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body p-4">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Why verify?</strong> Verified accounts get priority support and access to all features.
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Upload Valid ID</label>
                        <input type="file" class="form-control" name="id_file" accept=".jpg,.jpeg,.png,.pdf" required>
                        <small class="text-muted">Accepted: JPG, PNG, PDF (Max 5MB)</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">ID Type</label>
                        <select class="form-select" name="id_type" required>
                            <option value="">Select ID Type</option>
                            <option value="Driver's License">Driver's License</option>
                            <option value="Passport">Passport</option>
                            <option value="National ID">National ID (PhilID)</option>
                            <option value="SSS ID">SSS ID</option>
                            <option value="GSIS ID">GSIS ID</option>
                            <option value="Postal ID">Postal ID</option>
                            <option value="Voter's ID">Voter's ID</option>
                            <option value="PRC ID">PRC ID</option>
                        </select>
                    </div>
                    
                    <div class="alert alert-warning py-2 small">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        Please ensure your ID is clear and all details are visible. Blurry uploads will be rejected.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-glass px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="upload_id" class="btn btn-warning px-5">
                        <i class="fas fa-upload me-2"></i> Upload & Submit for Verification
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Address Modal -->
<?php if ($editAddress): ?>
<div class="modal fade show" id="editAddressModal" tabindex="-1" style="display: block; background: rgba(0,0,0,0.5);">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Edit Delivery Address</h5>
                <a href="profile.php" class="btn-close btn-close-white"></a>
            </div>
            <form method="POST">
                <input type="hidden" name="addressID" value="<?php echo $editAddress['addressID']; ?>">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Label</label>
                        <select class="form-select" name="label" required>
                            <option value="Home" <?php echo ($editAddress['label'] == 'Home') ? 'selected' : ''; ?>>Home</option>
                            <option value="Office" <?php echo ($editAddress['label'] == 'Office') ? 'selected' : ''; ?>>Office</option>
                            <option value="Parents" <?php echo ($editAddress['label'] == 'Parents') ? 'selected' : ''; ?>>Parents</option>
                            <option value="Relative" <?php echo ($editAddress['label'] == 'Relative') ? 'selected' : ''; ?>>Relative</option>
                            <option value="Friend" <?php echo ($editAddress['label'] == 'Friend') ? 'selected' : ''; ?>>Friend</option>
                            <option value="Other" <?php echo ($editAddress['label'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">City / Municipality (Cavite)</label>
                        <select class="form-select" name="city" required>
                            <option value="Bacoor" <?php echo ($editAddress['full_address'] && strpos($editAddress['full_address'], 'Bacoor') !== false) ? 'selected' : ''; ?>>Bacoor</option>
                            <option value="Imus" <?php echo ($editAddress['full_address'] && strpos($editAddress['full_address'], 'Imus') !== false) ? 'selected' : ''; ?>>Imus</option>
                            <option value="Dasmariñas" <?php echo ($editAddress['full_address'] && strpos($editAddress['full_address'], 'Dasmariñas') !== false) ? 'selected' : ''; ?>>Dasmariñas</option>
                            <option value="General Trias" <?php echo ($editAddress['full_address'] && strpos($editAddress['full_address'], 'General Trias') !== false) ? 'selected' : ''; ?>>General Trias</option>
                            <option value="Kawit" <?php echo ($editAddress['full_address'] && strpos($editAddress['full_address'], 'Kawit') !== false) ? 'selected' : ''; ?>>Kawit</option>
                            <option value="Noveleta" <?php echo ($editAddress['full_address'] && strpos($editAddress['full_address'], 'Noveleta') !== false) ? 'selected' : ''; ?>>Noveleta</option>
                            <option value="Rosario" <?php echo ($editAddress['full_address'] && strpos($editAddress['full_address'], 'Rosario') !== false) ? 'selected' : ''; ?>>Rosario</option>
                            <option value="Tanza" <?php echo ($editAddress['full_address'] && strpos($editAddress['full_address'], 'Tanza') !== false) ? 'selected' : ''; ?>>Tanza</option>
                            <option value="Trece Martires" <?php echo ($editAddress['full_address'] && strpos($editAddress['full_address'], 'Trece Martires') !== false) ? 'selected' : ''; ?>>Trece Martires</option>
                            <option value="Amadeo" <?php echo ($editAddress['full_address'] && strpos($editAddress['full_address'], 'Amadeo') !== false) ? 'selected' : ''; ?>>Amadeo</option>
                            <option value="General Emilio Aguinaldo" <?php echo ($editAddress['full_address'] && strpos($editAddress['full_address'], 'General Emilio Aguinaldo') !== false) ? 'selected' : ''; ?>>General Emilio Aguinaldo</option>
                            <option value="Indang" <?php echo ($editAddress['full_address'] && strpos($editAddress['full_address'], 'Indang') !== false) ? 'selected' : ''; ?>>Indang</option>
                            <option value="Magallanes" <?php echo ($editAddress['full_address'] && strpos($editAddress['full_address'], 'Magallanes') !== false) ? 'selected' : ''; ?>>Magallanes</option>
                            <option value="Maragondon" <?php echo ($editAddress['full_address'] && strpos($editAddress['full_address'], 'Maragondon') !== false) ? 'selected' : ''; ?>>Maragondon</option>
                            <option value="Mendez" <?php echo ($editAddress['full_address'] && strpos($editAddress['full_address'], 'Mendez') !== false) ? 'selected' : ''; ?>>Mendez</option>
                            <option value="Naic" <?php echo ($editAddress['full_address'] && strpos($editAddress['full_address'], 'Naic') !== false) ? 'selected' : ''; ?>>Naic</option>
                            <option value="Silang" <?php echo ($editAddress['full_address'] && strpos($editAddress['full_address'], 'Silang') !== false) ? 'selected' : ''; ?>>Silang</option>
                            <option value="Tagaytay" <?php echo ($editAddress['full_address'] && strpos($editAddress['full_address'], 'Tagaytay') !== false) ? 'selected' : ''; ?>>Tagaytay</option>
                            <option value="Ternate" <?php echo ($editAddress['full_address'] && strpos($editAddress['full_address'], 'Ternate') !== false) ? 'selected' : ''; ?>>Ternate</option>
                            <option value="Alfonso" <?php echo ($editAddress['full_address'] && strpos($editAddress['full_address'], 'Alfonso') !== false) ? 'selected' : ''; ?>>Alfonso</option>
                            <option value="General Mariano Alvarez" <?php echo ($editAddress['full_address'] && strpos($editAddress['full_address'], 'General Mariano Alvarez') !== false) ? 'selected' : ''; ?>>General Mariano Alvarez (GMA)</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Barangay</label>
                        <input type="text" class="form-control" name="barangay" value="<?php 
                            preg_match('/Brgy\. ([^,]+)/', $editAddress['full_address'], $matches); 
                            echo $matches[1] ?? ''; 
                        ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">House/Unit No. & Street</label>
                        <input type="text" class="form-control" name="street" value="<?php 
                            $parts = explode(', Brgy.', $editAddress['full_address']); 
                            echo trim($parts[0] ?? ''); 
                        ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Contact Number (Optional)</label>
                        <input type="text" class="form-control" name="contact_number" value="<?php echo $editAddress['contact_number']; ?>">
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_default" id="editIsDefault" <?php echo $editAddress['is_default'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="editIsDefault">Set as default delivery address</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="profile.php" class="btn btn-glass px-4">Cancel</a>
                    <button type="submit" name="update_address" class="btn btn-primary px-5">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Add New Address Modal -->
<div class="modal fade" id="addAddressModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Add New Delivery Address</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Label</label>
                        <select class="form-select" name="label" required>
                            <option value="">Select Label</option>
                            <option value="Home">Home</option>
                            <option value="Office">Office</option>
                            <option value="Parents">Parents</option>
                            <option value="Relative">Relative</option>
                            <option value="Friend">Friend</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">City / Municipality (Cavite)</label>
                        <select class="form-select" name="city" required>
                            <option value="">Select City</option>
                            <option value="Bacoor">Bacoor</option>
                            <option value="Imus">Imus</option>
                            <option value="Dasmariñas">Dasmariñas</option>
                            <option value="General Trias">General Trias</option>
                            <option value="Kawit">Kawit</option>
                            <option value="Noveleta">Noveleta</option>
                            <option value="Rosario">Rosario</option>
                            <option value="Tanza">Tanza</option>
                            <option value="Trece Martires">Trece Martires</option>
                            <option value="Amadeo">Amadeo</option>
                            <option value="General Emilio Aguinaldo">General Emilio Aguinaldo</option>
                            <option value="Indang">Indang</option>
                            <option value="Magallanes">Magallanes</option>
                            <option value="Maragondon">Maragondon</option>
                            <option value="Mendez">Mendez</option>
                            <option value="Naic">Naic</option>
                            <option value="Silang">Silang</option>
                            <option value="Tagaytay">Tagaytay</option>
                            <option value="Ternate">Ternate</option>
                            <option value="Alfonso">Alfonso</option>
                            <option value="General Mariano Alvarez">General Mariano Alvarez (GMA)</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Barangay</label>
                        <input type="text" class="form-control" name="barangay" placeholder="Enter Barangay" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">House/Unit No. & Street</label>
                        <input type="text" class="form-control" name="street" placeholder="e.g. Block 5 Lot 12, Rose Street" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Contact Number</label>
                        <input type="text" class="form-control" name="contact_number" placeholder="0912 345 6789" required>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_default" id="isDefault">
                        <label class="form-check-label" for="isDefault">Set as default delivery address</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-glass px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_address" class="btn btn-primary px-5">Save Address</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Mobile Sidebar
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggle = document.getElementById('mobileToggle');

    function openSidebar() { sidebar.classList.add('show'); overlay.classList.add('show'); }
    function closeSidebar() { sidebar.classList.remove('show'); overlay.classList.remove('show'); }

    if (toggle) toggle.addEventListener('click', openSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);

    sidebar.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth < 992) closeSidebar();
        });
    });

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