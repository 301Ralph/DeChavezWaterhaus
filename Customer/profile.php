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
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $file_extension = strtolower(pathinfo($_FILES["profile_picture"]["name"], PATHINFO_EXTENSION));
        if (in_array($file_extension, ['jpg','jpeg','png','gif'])) {
            $new_filename = "profile_" . $userID . "_" . time() . "." . $file_extension;
            $target_file  = $target_dir . $new_filename;
            if (move_uploaded_file($_FILES["profile_picture"]["tmp_name"], $target_file)) {
                if (!empty($user['profile_picture']) && file_exists($user['profile_picture'])) unlink($user['profile_picture']);
                $db_path = "uploads/profilepicturefolder/" . $new_filename;
                $updateStmt = $conn->prepare("UPDATE customers SET profile_picture = ? WHERE userID = ?");
                $updateStmt->bind_param("si", $db_path, $userID);
                $updateStmt->execute(); $updateStmt->close();
                echo '<script>alert("Profile picture updated!"); window.location = "profile.php";</script>'; exit();
            }
        } else { echo '<script>alert("Only JPG, PNG, and GIF files are allowed.");</script>'; }
    }
}

// Handle Remove Profile Picture
if (isset($_GET['remove_photo'])) {
    if (!empty($user['profile_picture']) && file_exists("../" . $user['profile_picture'])) unlink("../" . $user['profile_picture']);
    $conn->query("UPDATE customers SET profile_picture = NULL WHERE userID = $userID");
    echo '<script>window.location = "profile.php";</script>'; exit();
}

// Handle ID Verification Upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_id'])) {
    if (isset($_FILES['id_file']) && $_FILES['id_file']['error'] == 0) {
        $target_dir = "../uploads/verification/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $file_extension = strtolower(pathinfo($_FILES["id_file"]["name"], PATHINFO_EXTENSION));
        if (in_array($file_extension, ['jpg','jpeg','png','pdf'])) {
            $new_filename = "id_" . $userID . "_" . time() . "." . $file_extension;
            $target_file  = $target_dir . $new_filename;
            if (move_uploaded_file($_FILES["id_file"]["tmp_name"], $target_file)) {
                if (!empty($user['VerificationFile']) && file_exists("../" . $user['VerificationFile'])) unlink("../" . $user['VerificationFile']);
                $db_path = "uploads/verification/" . $new_filename;
                $updateStmt = $conn->prepare("UPDATE customers SET VerificationFile = ?, verification_status = 'pending' WHERE userID = ?");
                $updateStmt->bind_param("si", $db_path, $userID);
                $updateStmt->execute(); $updateStmt->close();
                echo '<script>alert("ID uploaded successfully! Your account is now pending verification."); window.location = "profile.php";</script>'; exit();
            }
        } else { echo '<script>alert("Only JPG, PNG, and PDF files are allowed.");</script>'; }
    } else { echo '<script>alert("Please select a valid ID file to upload.");</script>'; }
}

// Handle Send Email Verification
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_email_verification'])) {
    if ($user['email_verified'] == 0) {
        $verification_code = sprintf('%06d', mt_rand(0, 999999));
        $codeStmt = $conn->prepare("UPDATE customers SET email_verification_token = ? WHERE userID = ?");
        $codeStmt->bind_param("si", $verification_code, $userID);
        $codeStmt->execute(); $codeStmt->close();
        $apiKey = BREVO_API_KEY;
        $data = [
            'sender' => ['name' => 'De Chavez Waterhaus', 'email' => 'cocacc202501@gmail.com'],
            'to' => [['email' => $user['Email']]],
            'subject' => 'Your Email Verification Code',
            'htmlContent' => "<h2 style='color:#0077B6;'>Your Verification Code</h2><p>Hi {$user['Firstname']},</p><div style='font-size:32px;font-weight:bold;letter-spacing:8px;color:#0077B6;padding:20px;background:#f8f9fa;border-radius:8px;text-align:center;'>$verification_code</div><p>Expires in 10 minutes.</p>"
        ];
        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($data), CURLOPT_HTTPHEADER=>['accept: application/json','api-key: '.$apiKey,'content-type: application/json']]);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        echo ($httpCode == 201)
            ? '<script>alert("Verification code sent!"); window.location = "profile.php?verify_email=1";</script>'
            : '<script>alert("Failed to send code. Please try again."); window.location = "profile.php";</script>';
    } else { echo '<script>alert("Your email is already verified."); window.location = "profile.php";</script>'; }
    exit();
}

// Handle Email Verification Code
if (isset($_GET['verify_email']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_email_code'])) {
    $entered_code = trim($_POST['email_code']);
    $checkStmt = $conn->prepare("SELECT email_verification_token FROM customers WHERE userID = ?");
    $checkStmt->bind_param("i", $userID); $checkStmt->execute();
    $result = $checkStmt->get_result()->fetch_assoc(); $checkStmt->close();
    if ($result && $result['email_verification_token'] == $entered_code) {
        $verifyStmt = $conn->prepare("UPDATE customers SET email_verified = 1, email_verification_token = NULL WHERE userID = ?");
        $verifyStmt->bind_param("i", $userID); $verifyStmt->execute(); $verifyStmt->close();
        echo '<script>alert("Email verified successfully!"); window.location = "profile.php";</script>';
    } else { echo '<script>alert("Invalid code. Please try again."); window.location = "profile.php?verify_email=1";</script>'; }
    exit();
}

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $firstname = trim($_POST['firstname']); $lastname = trim($_POST['lastname']);
    $email = trim($_POST['email']); $phone = trim($_POST['phone']);
    $city = trim($_POST['city']); $barangay = trim($_POST['barangay']); $street = trim($_POST['street']);
    $full_address = "$street, Brgy. $barangay, $city, Cavite";
    $errors = [];
    if (!preg_match("/^[a-zA-Z\s]+$/", $firstname)) $errors[] = "First name should only contain letters.";
    if (!preg_match("/^[a-zA-Z\s]+$/", $lastname))  $errors[] = "Last name should only contain letters.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))  $errors[] = "Please enter a valid email address.";
    if (!empty($phone) && !preg_match("/^[0-9]{10,11}$/", $phone)) $errors[] = "Phone number must be 10-11 digits.";
    if (empty($city) || empty($barangay) || empty($street)) $errors[] = "Please complete all address fields.";
    if (empty($errors)) {
        $updateStmt = $conn->prepare("UPDATE customers SET Firstname=?,Lastname=?,Email=?,Contact=?,Address=?,City=?,Barangay=?,Street=? WHERE userID=?");
        $updateStmt->bind_param("ssssssssi", $firstname, $lastname, $email, $phone, $full_address, $city, $barangay, $street, $userID);
        if ($updateStmt->execute()) { $_SESSION['userName'] = $firstname; echo '<script>alert("Profile updated!"); window.location = "profile.php";</script>'; }
        else { echo '<script>alert("Error updating profile.");</script>'; }
    } else { $errorMsg = implode("\\n", $errors); echo "<script>alert('$errorMsg');</script>"; }
}

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password']; $new = $_POST['new_password']; $confirm = $_POST['confirm_password'];
    if (password_verify($current, $user['Password'])) {
        if (strlen($new) < 8 || !preg_match('/[A-Z]/', $new) || !preg_match('/[0-9]/', $new)) echo '<script>alert("Password must be at least 8 characters with 1 uppercase and 1 number.");</script>';
        elseif ($new !== $confirm) echo '<script>alert("Passwords do not match.");</script>';
        else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $passwordStmt = $conn->prepare("UPDATE customers SET Password = ? WHERE userID = ?");
            $passwordStmt->bind_param("si", $hash, $userID);
            if ($passwordStmt->execute()) echo '<script>alert("Password changed successfully!"); window.location = "profile.php";</script>';
            else echo '<script>alert("Error changing password.");</script>';
        }
    } else { echo '<script>alert("Current password is incorrect.");</script>'; }
}

// Handle 2FA Toggle
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toggle_2fa'])) {
    $new_status = $_POST['new_2fa_status'];
    if ($new_status == 1) {
        $otp = rand(100000, 999999);
        $_SESSION['2fa_setup_otp'] = $otp; $_SESSION['2fa_setup_expiry'] = time() + 300;
        $apiKey = BREVO_API_KEY;
        $data = ['sender'=>['name'=>'De Chavez Waterhaus','email'=>'cocacc202501@gmail.com'],'to'=>[['email'=>$user['Email']]],'subject'=>'Enable Two-Factor Authentication','htmlContent'=>"<h2>Your 2FA Code: <strong>$otp</strong></h2><p>Expires in 5 minutes.</p>"];
        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_HTTPHEADER=>['accept: application/json','api-key: '.$apiKey,'content-type: application/json']]);
        curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        echo ($httpCode == 201)
            ? '<script>alert("Verification code sent to your email."); window.location = "profile.php?verify_2fa=1";</script>'
            : '<script>alert("Failed to send code."); window.location = "profile.php";</script>';
    } else {
        $updateStmt = $conn->prepare("UPDATE customers SET two_factor_enabled = 0 WHERE userID = ?");
        $updateStmt->bind_param("i", $userID); $updateStmt->execute();
        echo '<script>alert("2FA has been disabled."); window.location = "profile.php";</script>';
    }
    exit();
}

// Handle Resend 2FA OTP
if (isset($_GET['resend_2fa'])) {
    $otp = rand(100000,999999); $_SESSION['2fa_setup_otp'] = $otp; $_SESSION['2fa_setup_expiry'] = time()+300;
    $apiKey = BREVO_API_KEY;
    $data = ['sender'=>['name'=>'De Chavez Waterhaus','email'=>'cocacc202501@gmail.com'],'to'=>[['email'=>$user['Email']]],'subject'=>'2FA Setup Code (Resend)','htmlContent'=>"<h2>Your 2FA Code: <strong>$otp</strong></h2><p>Expires in 5 minutes.</p>"];
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_HTTPHEADER=>['accept: application/json','api-key: '.$apiKey,'content-type: application/json']]);
    curl_exec($ch); $httpCode=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    echo ($httpCode==201) ? '<script>alert("New code sent."); window.location="profile.php?verify_2fa=1";</script>' : '<script>alert("Failed to resend."); window.location="profile.php?verify_2fa=1";</script>';
    exit();
}

// Handle 2FA Verification
if (isset($_GET['verify_2fa']) && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['verify_2fa_code'])) {
    $entered = trim($_POST['otp_code']);
    if (time() > $_SESSION['2fa_setup_expiry']) { echo '<script>alert("Code expired. Please try again."); window.location="profile.php";</script>'; exit(); }
    if ((string)$entered === (string)$_SESSION['2fa_setup_otp']) {
        $updateStmt = $conn->prepare("UPDATE customers SET two_factor_enabled=1 WHERE userID=?");
        $updateStmt->bind_param("i",$userID); $updateStmt->execute();
        unset($_SESSION['2fa_setup_otp'],$_SESSION['2fa_setup_expiry']);
        echo '<script>alert("2FA enabled successfully!"); window.location="profile.php";</script>';
    } else { echo '<script>alert("Invalid code."); window.location="profile.php?verify_2fa=1";</script>'; }
    exit();
}

// Handle Address operations
if ($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['add_address'])) {
    $label=$_POST['label']; $city=$_POST['city']; $barangay=$_POST['barangay']; $street=$_POST['street'];
    $contact=$_POST['contact_number']??''; $is_default=isset($_POST['is_default'])?1:0;
    $full_address="$street, Brgy. $barangay, $city, Cavite";
    if ($is_default) $conn->query("UPDATE delivery_addresses SET is_default=0 WHERE userID=$userID");
    $stmt=$conn->prepare("INSERT INTO delivery_addresses (userID,label,full_address,contact_number,is_default) VALUES (?,?,?,?,?)");
    $stmt->bind_param("isssi",$userID,$label,$full_address,$contact,$is_default); $stmt->execute(); $stmt->close();
    echo '<script>alert("Address added!"); window.location="profile.php";</script>'; exit();
}

$editAddress = null;
if (isset($_GET['edit_address'])) {
    $addrID=intval($_GET['edit_address']);
    $stmt=$conn->prepare("SELECT * FROM delivery_addresses WHERE addressID=? AND userID=?");
    $stmt->bind_param("ii",$addrID,$userID); $stmt->execute();
    $editAddress=$stmt->get_result()->fetch_assoc(); $stmt->close();
}

if ($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['update_address'])) {
    $addrID=intval($_POST['addressID']); $label=$_POST['label']; $city=$_POST['city'];
    $barangay=$_POST['barangay']; $street=$_POST['street']; $contact=$_POST['contact_number']??'';
    $is_default=isset($_POST['is_default'])?1:0;
    $full_address="$street, Brgy. $barangay, $city, Cavite";
    if ($is_default) $conn->query("UPDATE delivery_addresses SET is_default=0 WHERE userID=$userID");
    $stmt=$conn->prepare("UPDATE delivery_addresses SET label=?,full_address=?,contact_number=?,is_default=? WHERE addressID=? AND userID=?");
    $stmt->bind_param("sssiii",$label,$full_address,$contact,$is_default,$addrID,$userID); $stmt->execute(); $stmt->close();
    echo '<script>alert("Address updated!"); window.location="profile.php";</script>'; exit();
}

if (isset($_GET['set_default'])) { $addrID=intval($_GET['set_default']); $conn->query("UPDATE delivery_addresses SET is_default=0 WHERE userID=$userID"); $conn->query("UPDATE delivery_addresses SET is_default=1 WHERE addressID=$addrID AND userID=$userID"); echo '<script>window.location="profile.php";</script>'; exit(); }
if (isset($_GET['delete_address'])) { $addrID=intval($_GET['delete_address']); $conn->query("DELETE FROM delivery_addresses WHERE addressID=$addrID AND userID=$userID"); echo '<script>window.location="profile.php";</script>'; exit(); }

// Handle Account Deletion
if ($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['delete_account'])) {
    if (password_verify($_POST['confirm_delete_password'], $user['Password'])) {
        $deleteStmt=$conn->prepare("DELETE FROM customers WHERE userID=?"); $deleteStmt->bind_param("i",$userID);
        if ($deleteStmt->execute()) { session_destroy(); echo '<script>alert("Account deleted."); window.location="../index.php";</script>'; exit(); }
        else echo '<script>alert("Error deleting account.");</script>';
    } else echo '<script>alert("Incorrect password.");</script>';
}

$notifCount = $conn->query("SELECT COUNT(*) as unread FROM notifications WHERE userID=$userID AND is_read=0")->fetch_assoc()['unread'] ?? 0;
$firstName  = explode(' ', $userName)[0];

// Fetch delivery addresses
$addrStmt = $conn->prepare("SELECT * FROM delivery_addresses WHERE userID=? ORDER BY is_default DESC, created_at DESC");
$addrStmt->bind_param("i",$userID); $addrStmt->execute();
$addresses = $addrStmt->get_result(); $addrStmt->close();

$caviteCities = ['Bacoor','Imus','Dasmariñas','General Trias','Kawit','Noveleta','Rosario','Tanza','Trece Martires','Amadeo','General Emilio Aguinaldo','Indang','Magallanes','Maragondon','Mendez','Naic','Silang','Tagaytay','Ternate','Alfonso','General Mariano Alvarez'];
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
            --deep:  #020d18;  --abyss: #030f1e;  --ocean: #041e35;  --navy:  #0a2d4a;
            --teal:  #0077b6;  --aqua:  #00b4d8;  --cyan:  #48cae4;  --glow:  #90e0ef;
            --foam:  #caf0f8;  --white: #f0f9ff;  --gold:  #f4c842;
            --glass: rgba(0,180,216,0.08);  --glass-border: rgba(72,202,228,0.18);
            --sidebar-w: 260px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body { font-family: 'DM Sans', sans-serif; background: var(--deep); color: var(--white); min-height: 100vh; }

        /* ── SIDEBAR ── */
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-w); background: var(--abyss); border-right: 1px solid var(--glass-border); z-index: 1000; display: flex; flex-direction: column; transition: transform 0.3s ease; }
        .sidebar-logo { padding: 24px 22px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid var(--glass-border); flex-shrink: 0; }
        .sidebar-logo img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 1px solid rgba(0,180,216,0.35); box-shadow: 0 0 14px rgba(0,180,216,0.2); }
        .sidebar-logo span { font-family: 'Cormorant Garamond', serif; font-size: 1.05rem; font-weight: 500; color: var(--white); line-height: 1.2; }
        .sidebar-nav { flex: 1; overflow-y: auto; padding: 16px 12px 20px; scrollbar-width: thin; scrollbar-color: rgba(72,202,228,0.15) transparent; }
        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(72,202,228,0.15); border-radius: 2px; }
        .nav-section-label { font-size: 0.62rem; letter-spacing: 0.2em; text-transform: uppercase; color: rgba(202,240,248,0.25); padding: 16px 12px 6px; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 11px 14px; border-radius: 10px; color: rgba(202,240,248,0.5) !important; text-decoration: none; font-size: 0.87rem; font-weight: 500; transition: all 0.25s ease; margin-bottom: 2px; position: relative; }
        .nav-link i { width: 18px; text-align: center; font-size: 0.9rem; color: rgba(0,180,216,0.4); transition: color 0.25s; }
        .nav-link:hover { background: var(--glass); color: var(--foam) !important; }
        .nav-link:hover i { color: var(--aqua); }
        .nav-link.active { background: linear-gradient(135deg, rgba(0,119,182,0.25), rgba(0,180,216,0.12)); border: 1px solid rgba(0,180,216,0.2); color: var(--aqua) !important; }
        .nav-link.active i { color: var(--aqua); }
        .nav-link.active::before { content: ''; position: absolute; left: 0; top: 20%; bottom: 20%; width: 3px; background: var(--aqua); border-radius: 0 3px 3px 0; }
        .nav-link.danger { color: rgba(252,165,165,0.6) !important; }
        .nav-link.danger i { color: rgba(252,165,165,0.5); }
        .nav-link.danger:hover { background: rgba(248,113,113,0.08); color: #fca5a5 !important; }
        .notif-dot { margin-left: auto; background: var(--gold); color: var(--deep); font-size: 0.62rem; font-weight: 700; padding: 1px 6px; border-radius: 50px; min-width: 18px; text-align: center; }

        /* ── MAIN ── */
        .main-content { margin-left: var(--sidebar-w); min-height: 100vh; padding: 28px 32px; }

        /* ── TOP BAR ── */
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; }
        .topbar-greeting h4 { font-family: 'Cormorant Garamond', serif; font-size: 1.7rem; font-weight: 400; color: var(--white); line-height: 1.1; }
        .topbar-greeting p { font-size: 0.82rem; color: rgba(202,240,248,0.4); margin-top: 2px; }
        .topbar-actions { display: flex; align-items: center; gap: 12px; }
        .topbar-btn { width: 42px; height: 42px; border-radius: 50%; background: var(--glass); border: 1px solid var(--glass-border); color: rgba(202,240,248,0.6); display: flex; align-items: center; justify-content: center; font-size: 0.9rem; text-decoration: none; transition: all 0.3s; position: relative; }
        .topbar-btn:hover { background: rgba(0,180,216,0.15); border-color: var(--aqua); color: var(--aqua); }
        .topbar-notif-badge { position: absolute; top: -3px; right: -3px; background: var(--gold); color: var(--deep); font-size: 0.58rem; font-weight: 700; min-width: 16px; height: 16px; border-radius: 50px; display: flex; align-items: center; justify-content: center; padding: 0 4px; }
        .avatar-btn { display: flex; align-items: center; gap: 10px; background: var(--glass); border: 1px solid var(--glass-border); border-radius: 50px; padding: 6px 14px 6px 6px; cursor: pointer; transition: all 0.3s; }
        .avatar-btn:hover { border-color: rgba(0,180,216,0.35); background: rgba(0,180,216,0.1); }
        .avatar-circle { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
        .avatar-circle img { width: 100%; height: 100%; object-fit: cover; }
        .avatar-name { font-size: 0.82rem; font-weight: 500; color: var(--white); }
        .avatar-role { font-size: 0.7rem; color: rgba(202,240,248,0.4); }
        .dropdown-menu { background: var(--ocean) !important; border: 1px solid var(--glass-border) !important; border-radius: 14px !important; padding: 8px !important; box-shadow: 0 20px 50px rgba(0,0,0,0.5) !important; }
        .dropdown-item { color: rgba(202,240,248,0.65) !important; border-radius: 8px !important; padding: 9px 14px !important; font-size: 0.84rem !important; transition: all 0.2s !important; }
        .dropdown-item:hover { background: var(--glass) !important; color: var(--aqua) !important; }
        .dropdown-item.text-danger { color: rgba(252,165,165,0.7) !important; }
        .dropdown-item.text-danger:hover { background: rgba(248,113,113,0.08) !important; color: #fca5a5 !important; }
        .dropdown-divider { border-color: var(--glass-border) !important; margin: 4px 0 !important; }

        /* ── SECTION CARDS ── */
        .dash-card { background: linear-gradient(145deg, rgba(10,45,74,0.6), rgba(3,15,30,0.82)); border: 1px solid var(--glass-border); border-radius: 18px; padding: 28px; }
        .card-title { font-family: 'Cormorant Garamond', serif; font-size: 1.25rem; font-weight: 500; color: var(--white); }

        /* ── AVATAR HERO ── */
        .avatar-hero { position: relative; display: flex; flex-direction: column; align-items: center; padding: 32px 0 24px; }
        .avatar-hero-img { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 3px solid rgba(0,180,216,0.4); box-shadow: 0 0 30px rgba(0,180,216,0.2); }
        .avatar-hero-initial { width: 100px; height: 100px; border-radius: 50%; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); font-family: 'Cormorant Garamond', serif; font-size: 2.8rem; font-weight: 300; display: flex; align-items: center; justify-content: center; border: 3px solid rgba(0,180,216,0.4); box-shadow: 0 0 30px rgba(0,180,216,0.2); }
        .avatar-hero-name { font-family: 'Cormorant Garamond', serif; font-size: 1.5rem; font-weight: 500; color: var(--white); margin-top: 14px; }
        .avatar-hero-role { font-size: 0.78rem; letter-spacing: 0.15em; text-transform: uppercase; color: rgba(202,240,248,0.35); margin-top: 3px; }
        .avatar-photo-actions { display: flex; gap: 8px; margin-top: 14px; }
        .btn-photo { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 50px; font-size: 0.75rem; font-weight: 600; cursor: pointer; transition: all 0.25s; }
        .btn-photo-change { background: rgba(0,119,182,0.2); border: 1px solid rgba(0,180,216,0.3); color: var(--aqua); }
        .btn-photo-change:hover { background: rgba(0,180,216,0.2); color: var(--white); }
        .btn-photo-remove { background: rgba(248,113,113,0.1); border: 1px solid rgba(248,113,113,0.25); color: #fca5a5; text-decoration: none; }
        .btn-photo-remove:hover { background: rgba(248,113,113,0.2); color: #fca5a5; }

        /* ── INFO ROWS ── */
        .info-row { display: flex; align-items: flex-start; gap: 12px; padding: 14px 0; border-bottom: 1px solid rgba(72,202,228,0.07); }
        .info-row:last-child { border-bottom: none; }
        .info-icon { width: 34px; height: 34px; border-radius: 9px; background: var(--glass); border: 1px solid var(--glass-border); display: flex; align-items: center; justify-content: center; color: var(--aqua); font-size: 0.8rem; flex-shrink: 0; }
        .info-label { font-size: 0.68rem; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(202,240,248,0.35); margin-bottom: 2px; }
        .info-value { font-size: 0.9rem; color: var(--foam); font-weight: 500; }

        /* ── STATUS CHIPS ── */
        .status-chip { display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px; border-radius: 50px; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.06em; }
        .chip-verified  { background: rgba(74,222,128,0.1);  color: #4ade80;  border: 1px solid rgba(74,222,128,0.25); }
        .chip-pending   { background: rgba(244,200,66,0.12); color: #f4c842;  border: 1px solid rgba(244,200,66,0.25); }
        .chip-unverified{ background: rgba(148,163,184,0.1); color: #94a3b8;  border: 1px solid rgba(148,163,184,0.2); }
        .chip-danger    { background: rgba(248,113,113,0.1); color: #fca5a5;  border: 1px solid rgba(248,113,113,0.25); }

        /* ── SECURITY ITEMS ── */
        .sec-item { padding: 18px 0; border-bottom: 1px solid rgba(72,202,228,0.07); }
        .sec-item:last-child { border-bottom: none; }
        .sec-item-title { font-size: 0.88rem; font-weight: 600; color: var(--white); margin-bottom: 3px; }
        .sec-item-sub   { font-size: 0.75rem; color: rgba(202,240,248,0.35); }

        /* toggle switch */
        .toggle-wrap { display: flex; align-items: center; gap: 10px; }
        .toggle-track { width: 46px; height: 26px; border-radius: 13px; background: rgba(72,202,228,0.1); border: 1px solid var(--glass-border); cursor: pointer; position: relative; transition: background 0.3s; }
        .toggle-track.on { background: linear-gradient(135deg, var(--teal), var(--aqua)); border-color: var(--aqua); }
        .toggle-thumb { width: 20px; height: 20px; border-radius: 50%; background: rgba(202,240,248,0.4); position: absolute; top: 2px; left: 2px; transition: all 0.3s; }
        .toggle-track.on .toggle-thumb { left: 22px; background: var(--deep); }
        .toggle-label { font-size: 0.75rem; color: rgba(202,240,248,0.4); }
        .toggle-track.on + .toggle-label { color: var(--aqua); font-weight: 600; }

        /* sec buttons */
        .btn-sec { display: inline-flex; align-items: center; gap: 6px; padding: 7px 16px; border-radius: 50px; font-size: 0.78rem; font-weight: 600; cursor: pointer; transition: all 0.25s; }
        .btn-sec-primary { background: rgba(0,119,182,0.2); border: 1px solid rgba(0,180,216,0.25); color: var(--aqua); }
        .btn-sec-primary:hover { background: rgba(0,180,216,0.2); color: var(--white); }
        .btn-sec-warning { background: rgba(244,200,66,0.1); border: 1px solid rgba(244,200,66,0.25); color: var(--gold); }
        .btn-sec-warning:hover { background: rgba(244,200,66,0.2); }
        .btn-sec-danger { background: rgba(248,113,113,0.1); border: 1px solid rgba(248,113,113,0.22); color: #fca5a5; }
        .btn-sec-danger:hover { background: rgba(248,113,113,0.2); }

        /* ── ADDRESS CARDS ── */
        .addr-card { background: rgba(4,30,53,0.6); border: 1px solid var(--glass-border); border-radius: 14px; padding: 18px; transition: all 0.3s; }
        .addr-card:hover { border-color: rgba(0,180,216,0.25); }
        .addr-card.is-default { border-color: rgba(0,180,216,0.35); background: rgba(0,119,182,0.1); }
        .addr-label { font-weight: 600; color: var(--white); font-size: 0.9rem; }
        .addr-default-badge { background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); font-size: 0.62rem; font-weight: 700; padding: 2px 8px; border-radius: 50px; letter-spacing: 0.08em; }
        .addr-full { font-size: 0.82rem; color: rgba(202,240,248,0.45); line-height: 1.6; margin-top: 6px; }
        .addr-contact { font-size: 0.78rem; color: rgba(202,240,248,0.35); margin-top: 4px; }
        .addr-actions { display: flex; gap: 6px; margin-top: 12px; flex-wrap: wrap; }
        .btn-addr { padding: 5px 12px; border-radius: 50px; font-size: 0.72rem; font-weight: 600; cursor: pointer; transition: all 0.2s; text-decoration: none; border: none; display: inline-flex; align-items: center; gap: 4px; }
        .btn-addr-default { background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); }
        .btn-addr-edit    { background: rgba(0,119,182,0.15); border: 1px solid rgba(0,180,216,0.2); color: var(--aqua); }
        .btn-addr-delete  { background: rgba(248,113,113,0.08); border: 1px solid rgba(248,113,113,0.2); color: #fca5a5; }

        /* ── ACTION BUTTONS ── */
        .btn-primary-action { background: linear-gradient(135deg,var(--teal),var(--aqua)); border: none; color: var(--deep); padding: 10px 24px; border-radius: 50px; font-size: 0.82rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; cursor: pointer; transition: all 0.3s; box-shadow: 0 5px 16px rgba(0,180,216,0.25); display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary-action:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(0,180,216,0.45); }
        .btn-glass { display: inline-flex; align-items: center; gap: 6px; background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); padding: 9px 18px; border-radius: 50px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .btn-glass:hover { background: rgba(0,180,216,0.15); color: var(--foam); }
        .btn-danger-glass { display: inline-flex; align-items: center; gap: 6px; background: rgba(248,113,113,0.08); border: 1px solid rgba(248,113,113,0.22); color: #fca5a5; padding: 9px 18px; border-radius: 50px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .btn-danger-glass:hover { background: rgba(248,113,113,0.18); }

        /* ── MODAL ── */
        .modal-content { background: var(--ocean) !important; border: 1px solid var(--glass-border) !important; border-radius: 20px !important; }
        .modal-header { border-bottom: 1px solid var(--glass-border) !important; padding: 22px 26px !important; }
        .modal-footer { border-top: 1px solid var(--glass-border) !important; padding: 18px 26px !important; }
        .modal-body { padding: 26px !important; }
        .modal-title { font-family: 'Cormorant Garamond', serif !important; font-size: 1.35rem !important; font-weight: 500 !important; color: var(--white) !important; }
        .btn-close { filter: invert(0.7) opacity(0.7); }
        .btn-close:hover { filter: invert(1); }

        /* modal fields */
        .field-group { margin-bottom: 16px; }
        .field-label { display: block; font-size: 0.7rem; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(202,240,248,0.45); margin-bottom: 7px; }
        .field-input, .field-select { width: 100%; background: rgba(4,30,53,0.7); border: 1px solid var(--glass-border); color: var(--white); font-family: 'DM Sans', sans-serif; font-size: 0.9rem; padding: 11px 15px; border-radius: 11px; outline: none; transition: all 0.3s; }
        .field-input::placeholder { color: rgba(202,240,248,0.2); }
        .field-input:focus, .field-select:focus { border-color: var(--aqua); background: rgba(0,180,216,0.07); box-shadow: 0 0 0 3px rgba(0,180,216,0.08); }
        .field-select option { background: var(--ocean); }
        .field-hint { font-size: 0.72rem; color: rgba(202,240,248,0.3); margin-top: 5px; }

        /* pw requirements */
        .pw-hints { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
        .pw-hint { display: inline-flex; align-items: center; gap: 5px; font-size: 0.72rem; color: rgba(202,240,248,0.3); padding: 4px 10px; border-radius: 50px; border: 1px solid rgba(202,240,248,0.1); transition: all 0.3s; }
        .pw-hint.met { color: #4ade80; border-color: rgba(74,222,128,0.3); background: rgba(74,222,128,0.06); }

        /* otp input */
        .otp-input { font-size: 1.8rem !important; letter-spacing: 12px !important; text-align: center; font-family: 'Cormorant Garamond', serif !important; font-weight: 600 !important; }

        /* modal overlay for inline modals */
        .inline-modal-bg { position: fixed; inset: 0; background: rgba(2,13,24,0.75); z-index: 1050; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .inline-modal-box { background: var(--ocean); border: 1px solid var(--glass-border); border-radius: 20px; width: 100%; max-width: 440px; overflow: hidden; animation: cardIn 0.35s ease both; }

        @keyframes cardIn { from{opacity:0;transform:scale(0.95)} to{opacity:1;transform:scale(1)} }

        /* ── EMPTY STATE ── */
        .empty-state-sm { text-align: center; padding: 36px 20px; color: rgba(202,240,248,0.3); font-size: 0.86rem; }
        .empty-state-sm i { font-size: 2rem; margin-bottom: 12px; display: block; color: rgba(0,180,216,0.2); }

        /* ── MOBILE ── */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(2,13,24,0.7); z-index: 999; backdrop-filter: blur(3px); }
        .mobile-toggle { background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); width: 40px; height: 40px; border-radius: 10px; display: none; align-items: center; justify-content: center; cursor: pointer; font-size: 0.9rem; }

        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); box-shadow: 4px 0 40px rgba(0,0,0,0.5); }
            .sidebar.show { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; padding: 20px 18px; }
            .mobile-toggle { display: flex; }
        }
        @media (max-width: 576px) { .main-content { padding: 16px 14px; } .dash-card { padding: 20px; } }
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
        <a href="customer_dashboard.php" class="nav-link"><i class="fas fa-home"></i> Dashboard</a>
        <a href="products.php"           class="nav-link"><i class="fas fa-droplet"></i> Products</a>
        <a href="order_history.php"      class="nav-link"><i class="fas fa-history"></i> Order History</a>
        <a href="order_tracking.php"     class="nav-link"><i class="fas fa-map-marker-alt"></i> Track Orders</a>
        <a href="recurring_orders.php"   class="nav-link"><i class="fas fa-redo"></i> Recurring Orders</a>
        <div class="nav-section-label">Account</div>
        <a href="support_tickets.php"    class="nav-link"><i class="fas fa-headset"></i> Support</a>
        <a href="notifications.php"      class="nav-link">
            <i class="fas fa-bell"></i> Notifications
            <?php if($notifCount>0): ?><span class="notif-dot"><?php echo $notifCount>9?'9+':$notifCount;?></span><?php endif; ?>
        </a>
        <a href="profile.php"            class="nav-link active"><i class="fas fa-user"></i> Profile</a>
        <div class="nav-section-label" style="margin-top:16px;"></div>
        <a href="../logout.php"          class="nav-link danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ── MAIN ── -->
<main class="main-content">

    <!-- Top Bar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="mobile-toggle" id="mobileToggle"><i class="fas fa-bars"></i></button>
            <div class="topbar-greeting">
                <h4>My Profile</h4>
                <p>Manage your account information and settings</p>
            </div>
        </div>

        <div class="topbar-actions">
            <a href="notifications.php" class="topbar-btn">
                <i class="fas fa-bell"></i>
                <?php if($notifCount>0): ?><span class="topbar-notif-badge"><?php echo $notifCount>9?'9+':$notifCount;?></span><?php endif; ?>
            </a>
            <div class="dropdown">
                <div class="avatar-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="avatar-circle">
                        <?php if(!empty($user['profile_picture'])&&file_exists('../'.$user['profile_picture'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['profile_picture']);?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr($userName,0,1));?>
                        <?php endif; ?>
                    </div>
                    <div class="d-none d-md-block">
                        <div class="avatar-name"><?php echo htmlspecialchars($userName);?></div>
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

    <div class="row g-4">

        <!-- ── LEFT: Profile Info ── -->
        <div class="col-lg-4">
            <div class="dash-card">

                <!-- Avatar hero -->
                <div class="avatar-hero">
                    <?php if(!empty($user['profile_picture'])&&file_exists('../'.$user['profile_picture'])): ?>
                        <img src="../<?php echo htmlspecialchars($user['profile_picture']);?>" class="avatar-hero-img" alt="">
                    <?php else: ?>
                        <div class="avatar-hero-initial"><?php echo strtoupper(substr($userName,0,1));?></div>
                    <?php endif; ?>
                    <div class="avatar-hero-name"><?php echo htmlspecialchars($user['Firstname'].' '.$user['Lastname']);?></div>
                    <div class="avatar-hero-role">Customer · Member since <?php echo date('Y', strtotime($user['created_at']));?></div>

                    <div class="avatar-photo-actions">
                        <form method="POST" enctype="multipart/form-data" class="d-inline">
                            <input type="hidden" name="upload_photo" value="1">
                            <label class="btn-photo btn-photo-change">
                                <i class="fas fa-camera"></i> Change Photo
                                <input type="file" name="profile_picture" accept="image/*" class="d-none" onchange="this.form.submit()">
                            </label>
                        </form>
                        <?php if(!empty($user['profile_picture'])): ?>
                            <a href="profile.php?remove_photo=1" class="btn-photo btn-photo-remove"
                               onclick="return confirm('Remove profile picture?')">
                                <i class="fas fa-trash"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Info rows -->
                <div style="border-top:1px solid var(--glass-border); padding-top:16px;">
                    <div class="info-row">
                        <div class="info-icon"><i class="fas fa-envelope"></i></div>
                        <div>
                            <div class="info-label">Email</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['Email']);?></div>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-icon"><i class="fas fa-phone"></i></div>
                        <div>
                            <div class="info-label">Phone</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['Contact']??'—');?></div>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-icon"><i class="fas fa-map-marker-alt"></i></div>
                        <div>
                            <div class="info-label">Address</div>
                            <div class="info-value" style="font-size:0.83rem;"><?php echo htmlspecialchars($user['Address']??'—');?></div>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-icon"><i class="fas fa-shield-alt"></i></div>
                        <div>
                            <div class="info-label">Account Status</div>
                            <div class="info-value mt-1">
                                <?php if($user['verification_status']=='approved'): ?>
                                    <span class="status-chip chip-verified"><i class="fas fa-check-circle"></i> Verified</span>
                                <?php elseif($user['verification_status']=='pending'): ?>
                                    <span class="status-chip chip-pending"><i class="fas fa-clock"></i> Pending</span>
                                <?php else: ?>
                                    <span class="status-chip chip-unverified"><i class="fas fa-circle-xmark"></i> Unverified</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action buttons -->
                <div class="d-flex flex-column gap-2 mt-4 pt-3" style="border-top:1px solid var(--glass-border);">
                    <button class="btn-primary-action w-100 justify-content-center" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                        <i class="fas fa-pen"></i> Edit Profile
                    </button>
                    <button class="btn-glass w-100 justify-content-center" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                        <i class="fas fa-key"></i> Change Password
                    </button>
                    <button class="btn-danger-glass w-100 justify-content-center" data-bs-toggle="modal" data-bs-target="#deleteAccountModal">
                        <i class="fas fa-trash"></i> Delete Account
                    </button>
                </div>
            </div>
        </div>

        <!-- ── RIGHT: Security + Addresses ── -->
        <div class="col-lg-8">
            <div class="row g-4">

                <!-- Security Card -->
                <div class="col-12">
                    <div class="dash-card">
                        <div class="card-title mb-2">Security & Verification</div>
                        <p style="font-size:0.8rem;color:rgba(202,240,248,0.35);margin-bottom:16px;">Protect your account and verify your identity.</p>

                        <!-- Email Verification -->
                        <div class="sec-item">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                <div>
                                    <div class="sec-item-title"><i class="fas fa-envelope me-2" style="color:var(--aqua);font-size:0.85rem;"></i>Email Verification</div>
                                    <div class="sec-item-sub"><?php echo htmlspecialchars($user['Email']);?></div>
                                </div>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <?php if($user['email_verified']==1): ?>
                                        <span class="status-chip chip-verified"><i class="fas fa-check-circle"></i> Verified</span>
                                    <?php else: ?>
                                        <span class="status-chip chip-danger"><i class="fas fa-xmark-circle"></i> Not Verified</span>
                                        <form method="POST" class="d-inline">
                                            <button type="submit" name="send_email_verification" class="btn-sec btn-sec-warning">
                                                <i class="fas fa-paper-plane"></i> Send Code
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- ID Verification -->
                        <div class="sec-item">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                <div>
                                    <div class="sec-item-title"><i class="fas fa-id-card me-2" style="color:var(--aqua);font-size:0.85rem;"></i>Account Verification</div>
                                    <div class="sec-item-sub">Upload a government-issued ID</div>
                                </div>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <?php if($user['verification_status']=='approved'): ?>
                                        <span class="status-chip chip-verified"><i class="fas fa-check-circle"></i> Approved</span>
                                    <?php elseif($user['verification_status']=='pending'): ?>
                                        <span class="status-chip chip-pending"><i class="fas fa-clock"></i> Under Review</span>
                                        <?php if(!empty($user['VerificationFile'])): ?>
                                            <a href="../<?php echo htmlspecialchars($user['VerificationFile']);?>" target="_blank" class="btn-sec btn-sec-primary">
                                                <i class="fas fa-file"></i> View ID
                                            </a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <button class="btn-sec btn-sec-warning" data-bs-toggle="modal" data-bs-target="#verifyAccountModal">
                                            <i class="fas fa-upload"></i> Upload ID
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- 2FA -->
                        <div class="sec-item">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div>
                                    <div class="sec-item-title"><i class="fas fa-lock me-2" style="color:var(--aqua);font-size:0.85rem;"></i>Two-Factor Authentication</div>
                                    <div class="sec-item-sub">Require a code at every login</div>
                                </div>
                                <div class="toggle-wrap" onclick="toggle2FA(<?php echo $user['two_factor_enabled']==1 ? 'false' : 'true';?>)">
                                    <div class="toggle-track <?php echo $user['two_factor_enabled']==1 ? 'on' : '';?>">
                                        <div class="toggle-thumb"></div>
                                    </div>
                                    <span class="toggle-label"><?php echo $user['two_factor_enabled']==1 ? 'Enabled' : 'Disabled';?></span>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Delivery Addresses -->
                <div class="col-12">
                    <div class="dash-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="card-title">Delivery Addresses</div>
                            <button class="btn-sec btn-sec-primary" data-bs-toggle="modal" data-bs-target="#addAddressModal">
                                <i class="fas fa-plus"></i> Add New
                            </button>
                        </div>

                        <?php if($addresses->num_rows > 0): ?>
                        <div class="row g-3">
                            <?php while($addr = $addresses->fetch_assoc()): ?>
                            <div class="col-md-6">
                                <div class="addr-card <?php echo $addr['is_default']?'is-default':'';?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="addr-label">
                                            <i class="fas fa-location-dot me-1" style="color:var(--aqua);font-size:0.8rem;"></i>
                                            <?php echo htmlspecialchars($addr['label']);?>
                                        </span>
                                        <?php if($addr['is_default']): ?>
                                            <span class="addr-default-badge">DEFAULT</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="addr-full"><?php echo htmlspecialchars($addr['full_address']);?></div>
                                    <?php if($addr['contact_number']): ?>
                                        <div class="addr-contact"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($addr['contact_number']);?></div>
                                    <?php endif; ?>
                                    <div class="addr-actions">
                                        <?php if(!$addr['is_default']): ?>
                                            <a href="profile.php?set_default=<?php echo $addr['addressID'];?>" class="btn-addr btn-addr-default">
                                                <i class="fas fa-check"></i> Set Default
                                            </a>
                                        <?php endif; ?>
                                        <a href="profile.php?edit_address=<?php echo $addr['addressID'];?>" class="btn-addr btn-addr-edit">
                                            <i class="fas fa-pen"></i> Edit
                                        </a>
                                        <a href="profile.php?delete_address=<?php echo $addr['addressID'];?>" class="btn-addr btn-addr-delete"
                                           onclick="return confirm('Delete this address?')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        </div>
                        <?php else: ?>
                        <div class="empty-state-sm">
                            <i class="fas fa-map-marker-alt"></i>
                            No saved addresses yet. Add one to speed up checkout!
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

    </div><!-- /.row -->
</main>

<!-- ── INLINE MODALS (2FA / Email verify / Edit address) ── -->
<?php if(isset($_GET['verify_2fa'])): ?>
<div class="inline-modal-bg">
    <div class="inline-modal-box">
        <div class="modal-header">
            <h5 class="modal-title"><i class="fas fa-lock me-2" style="color:var(--aqua);"></i>Verify 2FA Setup</h5>
        </div>
        <form method="POST">
            <div class="modal-body">
                <p style="color:rgba(202,240,248,0.55);font-size:0.88rem;margin-bottom:16px;">Enter the 6-digit code sent to your email to enable 2FA.</p>
                <input type="text" class="field-input otp-input" name="otp_code" maxlength="6" placeholder="000000" required autocomplete="one-time-code">
                <div class="text-center mt-3">
                    <a href="profile.php?resend_2fa=1" style="color:var(--aqua);font-size:0.8rem;text-decoration:none;">
                        <i class="fas fa-rotate-right me-1"></i> Resend Code
                    </a>
                </div>
            </div>
            <div class="modal-footer d-flex gap-2 justify-content-end">
                <a href="profile.php" class="btn-glass">Cancel</a>
                <button type="submit" name="verify_2fa_code" class="btn-primary-action">Enable 2FA</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if(isset($_GET['verify_email'])): ?>
<div class="inline-modal-bg">
    <div class="inline-modal-box">
        <div class="modal-header">
            <h5 class="modal-title"><i class="fas fa-envelope me-2" style="color:var(--aqua);"></i>Verify Your Email</h5>
        </div>
        <form method="POST">
            <div class="modal-body">
                <p style="color:rgba(202,240,248,0.55);font-size:0.88rem;margin-bottom:16px;">Enter the 6-digit code sent to <strong style="color:var(--aqua);"><?php echo htmlspecialchars($user['Email']);?></strong>.</p>
                <input type="text" class="field-input otp-input" name="email_code" maxlength="6" placeholder="000000" required autocomplete="one-time-code">
            </div>
            <div class="modal-footer d-flex gap-2 justify-content-end">
                <a href="profile.php" class="btn-glass">Cancel</a>
                <button type="submit" name="verify_email_code" class="btn-primary-action">Verify Email</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if($editAddress): ?>
<div class="inline-modal-bg">
    <div class="inline-modal-box" style="max-width:520px;max-height:90vh;overflow-y:auto;">
        <div class="modal-header">
            <h5 class="modal-title"><i class="fas fa-pen me-2" style="color:var(--aqua);"></i>Edit Address</h5>
        </div>
        <form method="POST">
            <input type="hidden" name="addressID" value="<?php echo $editAddress['addressID'];?>">
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="field-group">
                            <label class="field-label">Label</label>
                            <select class="field-select" name="label" required>
                                <?php foreach(['Home','Office','Parents','Relative','Friend','Other'] as $lbl): ?>
                                    <option value="<?php echo $lbl;?>" <?php echo $editAddress['label']==$lbl?'selected':'';?>><?php echo $lbl;?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="field-group">
                            <label class="field-label">City / Municipality</label>
                            <select class="field-select" name="city" required>
                                <?php foreach($caviteCities as $c): ?>
                                    <option value="<?php echo $c;?>" <?php echo strpos($editAddress['full_address'],$c)!==false?'selected':'';?>>
                                        <?php echo $c==='General Mariano Alvarez'?'General Mariano Alvarez (GMA)':$c;?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="field-group">
                            <label class="field-label">Barangay</label>
                            <input type="text" class="field-input" name="barangay" value="<?php preg_match('/Brgy\. ([^,]+)/',$editAddress['full_address'],$m); echo $m[1]??'';?>" required>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="field-group">
                            <label class="field-label">House / Street</label>
                            <input type="text" class="field-input" name="street" value="<?php $p=explode(', Brgy.',$editAddress['full_address']); echo trim($p[0]??'');?>" required>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="field-group">
                            <label class="field-label">Contact Number</label>
                            <input type="text" class="field-input" name="contact_number" value="<?php echo htmlspecialchars($editAddress['contact_number']);?>">
                        </div>
                    </div>
                    <div class="col-12">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.84rem;color:rgba(202,240,248,0.5);">
                            <input type="checkbox" name="is_default" style="accent-color:var(--aqua);" <?php echo $editAddress['is_default']?'checked':'';?>>
                            Set as default address
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer d-flex gap-2 justify-content-end">
                <a href="profile.php" class="btn-glass">Cancel</a>
                <button type="submit" name="update_address" class="btn-primary-action">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ── EDIT PROFILE MODAL ── -->
<div class="modal fade" id="editProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-pen me-2" style="color:var(--aqua);"></i>Edit Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="field-group">
                                <label class="field-label">First Name</label>
                                <input type="text" class="field-input" name="firstname" value="<?php echo htmlspecialchars($user['Firstname']);?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label class="field-label">Last Name</label>
                                <input type="text" class="field-input" name="lastname" value="<?php echo htmlspecialchars($user['Lastname']);?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label class="field-label">Email Address</label>
                                <input type="email" class="field-input" name="email" value="<?php echo htmlspecialchars($user['Email']);?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label class="field-label">Phone Number</label>
                                <input type="tel" class="field-input" name="phone" value="<?php echo htmlspecialchars($user['Contact']??'');?>"
                                       pattern="[0-9]{10,11}" maxlength="11" oninput="this.value=this.value.replace(/[^0-9]/g,'')" required>
                                <div class="field-hint">10–11 digits only</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label class="field-label">City / Municipality</label>
                                <select class="field-select" name="city" required>
                                    <option value="">Select City</option>
                                    <?php foreach($caviteCities as $c): ?>
                                        <option value="<?php echo $c;?>" <?php echo(($user['City']??'')==$c)?'selected':'';?>>
                                            <?php echo $c==='General Mariano Alvarez'?'General Mariano Alvarez (GMA)':$c;?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label class="field-label">Barangay</label>
                                <input type="text" class="field-input" name="barangay" value="<?php echo htmlspecialchars($user['Barangay']??'');?>" required>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="field-group">
                                <label class="field-label">House / Unit No. & Street</label>
                                <input type="text" class="field-input" name="street" value="<?php echo htmlspecialchars($user['Street']??'');?>" required>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-glass" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_profile" class="btn-primary-action">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── CHANGE PASSWORD MODAL ── -->
<div class="modal fade" id="changePasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-key me-2" style="color:var(--aqua);"></i>Change Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="changePasswordForm">
                <div class="modal-body">
                    <div class="field-group">
                        <label class="field-label">Current Password</label>
                        <input type="password" class="field-input" name="current_password" required>
                    </div>
                    <div class="field-group">
                        <label class="field-label">New Password</label>
                        <div style="position:relative;">
                            <input type="password" class="field-input" id="new_password" name="new_password" required minlength="8" style="padding-right:44px;">
                            <button type="button" onclick="togglePw('new_password','eye1')" style="position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;color:rgba(202,240,248,0.35);cursor:pointer;">
                                <i class="fas fa-eye" id="eye1"></i>
                            </button>
                        </div>
                        <div class="pw-hints mt-2">
                            <span class="pw-hint" id="hint-len"><i class="fas fa-circle" style="font-size:0.45rem;"></i> 8+ chars</span>
                            <span class="pw-hint" id="hint-upper"><i class="fas fa-circle" style="font-size:0.45rem;"></i> Uppercase</span>
                            <span class="pw-hint" id="hint-num"><i class="fas fa-circle" style="font-size:0.45rem;"></i> Number</span>
                            <span class="pw-hint" id="hint-match"><i class="fas fa-circle" style="font-size:0.45rem;"></i> Match</span>
                        </div>
                    </div>
                    <div class="field-group">
                        <label class="field-label">Confirm New Password</label>
                        <div style="position:relative;">
                            <input type="password" class="field-input" id="confirm_password" name="confirm_password" required style="padding-right:44px;">
                            <button type="button" onclick="togglePw('confirm_password','eye2')" style="position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;color:rgba(202,240,248,0.35);cursor:pointer;">
                                <i class="fas fa-eye" id="eye2"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-glass" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="change_password" class="btn-primary-action" id="changePasswordBtn" disabled>Update Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── DELETE ACCOUNT MODAL ── -->
<div class="modal fade" id="deleteAccountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-color:rgba(248,113,113,0.3) !important;">
            <div class="modal-header" style="border-bottom-color:rgba(248,113,113,0.2) !important;">
                <h5 class="modal-title" style="color:#fca5a5 !important;">
                    <i class="fas fa-triangle-exclamation me-2"></i>Delete Account
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="deleteAccountForm">
                <div class="modal-body">
                    <div style="background:rgba(248,113,113,0.07);border:1px solid rgba(248,113,113,0.2);border-radius:12px;padding:14px 16px;margin-bottom:20px;">
                        <div style="color:#fca5a5;font-size:0.85rem;display:flex;gap:10px;">
                            <i class="fas fa-exclamation-circle mt-1 flex-shrink-0"></i>
                            <span>This action is <strong>permanent and irreversible</strong>. All your data, orders, and addresses will be deleted.</span>
                        </div>
                    </div>
                    <div class="field-group">
                        <label class="field-label">Enter your password to confirm</label>
                        <input type="password" class="field-input" name="confirm_delete_password" required>
                    </div>
                    <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:0.84rem;color:rgba(252,165,165,0.7);">
                        <input type="checkbox" id="confirmDeleteCheck" style="accent-color:#ef4444;margin-top:2px;flex-shrink:0;">
                        I understand this is permanent and cannot be undone.
                    </label>
                </div>
                <div class="modal-footer d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-glass" data-bs-dismiss="modal">Keep Account</button>
                    <button type="submit" name="delete_account" id="deleteBtn"
                            style="background:#ef4444;border:none;color:white;padding:10px 22px;border-radius:50px;font-size:0.82rem;font-weight:700;cursor:pointer;opacity:0.4;pointer-events:none;"
                            disabled>
                        <i class="fas fa-trash me-1"></i> Delete My Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── VERIFY ACCOUNT (ID UPLOAD) MODAL ── -->
<div class="modal fade" id="verifyAccountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-id-card me-2" style="color:var(--aqua);"></i>Upload ID for Verification</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div style="background:rgba(0,180,216,0.07);border:1px solid rgba(0,180,216,0.18);border-radius:12px;padding:14px;margin-bottom:20px;font-size:0.84rem;color:rgba(202,240,248,0.6);">
                        <i class="fas fa-info-circle me-2" style="color:var(--aqua);"></i>
                        Verified accounts get priority support and access to all features.
                    </div>
                    <div class="field-group">
                        <label class="field-label">ID Type</label>
                        <select class="field-select" name="id_type" required>
                            <option value="">Select ID Type</option>
                            <?php foreach(["Driver's License","Passport","National ID (PhilID)","SSS ID","GSIS ID","Postal ID","Voter's ID","PRC ID"] as $idType): ?>
                                <option value="<?php echo $idType;?>"><?php echo $idType;?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field-group">
                        <label class="field-label">Upload Valid ID</label>
                        <input type="file" class="field-input" name="id_file" accept=".jpg,.jpeg,.png,.pdf" required style="padding:10px;">
                        <div class="field-hint">JPG, PNG, PDF · Max 5MB · Ensure details are clearly visible</div>
                    </div>
                </div>
                <div class="modal-footer d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-glass" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="upload_id" class="btn-primary-action">
                        <i class="fas fa-upload"></i> Submit for Verification
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── ADD ADDRESS MODAL ── -->
<div class="modal fade" id="addAddressModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2" style="color:var(--aqua);"></i>Add Delivery Address</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="field-group">
                                <label class="field-label">Label</label>
                                <select class="field-select" name="label" required>
                                    <option value="">Select</option>
                                    <?php foreach(['Home','Office','Parents','Relative','Friend','Other'] as $lbl): ?>
                                        <option value="<?php echo $lbl;?>"><?php echo $lbl;?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label class="field-label">City / Municipality</label>
                                <select class="field-select" name="city" required>
                                    <option value="">Select City</option>
                                    <?php foreach($caviteCities as $c): ?>
                                        <option value="<?php echo $c;?>"><?php echo $c==='General Mariano Alvarez'?'GMA':$c;?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="field-group">
                                <label class="field-label">Barangay</label>
                                <input type="text" class="field-input" name="barangay" placeholder="e.g. Brgy. Poblacion" required>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="field-group">
                                <label class="field-label">House / Unit No. & Street</label>
                                <input type="text" class="field-input" name="street" placeholder="e.g. Block 5 Lot 12, Rose Street" required>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="field-group">
                                <label class="field-label">Contact Number</label>
                                <input type="text" class="field-input" name="contact_number" placeholder="09XX XXX XXXX" required>
                            </div>
                        </div>
                        <div class="col-12">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.84rem;color:rgba(202,240,248,0.5);">
                                <input type="checkbox" name="is_default" style="accent-color:var(--aqua);">
                                Set as default delivery address
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex gap-2 justify-content-end">
                    <button type="button" class="btn-glass" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_address" class="btn-primary-action">Save Address</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // ── SIDEBAR ──
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggle  = document.getElementById('mobileToggle');
    function openSidebar()  { sidebar.classList.add('show'); overlay.classList.add('show'); }
    function closeSidebar() { sidebar.classList.remove('show'); overlay.classList.remove('show'); }
    if (toggle)  toggle.addEventListener('click', openSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);
    sidebar.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', () => { if(window.innerWidth<992) closeSidebar(); }));

    // ── 2FA TOGGLE ──
    function toggle2FA(enable) {
        const form = document.createElement('form');
        form.method = 'POST'; form.action = 'profile.php';
        ['toggle_2fa','1'].forEach((v,i) => {
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = i===0 ? 'toggle_2fa' : 'new_2fa_status';
            inp.value = i===0 ? '1' : (enable ? '1' : '0');
            form.appendChild(inp);
        });
        document.body.appendChild(form);
        form.submit();
    }

    // ── PASSWORD TOGGLE ──
    function togglePw(id, iconId) {
        const input = document.getElementById(id);
        const icon  = document.getElementById(iconId);
        input.type  = input.type === 'password' ? 'text' : 'password';
        icon.className = input.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
    }

    // ── PASSWORD HINTS ──
    const newPw  = document.getElementById('new_password');
    const conPw  = document.getElementById('confirm_password');
    const pwBtn  = document.getElementById('changePasswordBtn');

    function updateHints() {
        const pw = newPw ? newPw.value : '';
        const hasLen   = pw.length >= 8;
        const hasUpper = /[A-Z]/.test(pw);
        const hasNum   = /[0-9]/.test(pw);
        const hasMatch = pw === (conPw ? conPw.value : '') && pw.length > 0;
        setHint('hint-len',   hasLen);
        setHint('hint-upper', hasUpper);
        setHint('hint-num',   hasNum);
        setHint('hint-match', hasMatch);
        if (pwBtn) pwBtn.disabled = !(hasLen && hasUpper && hasNum && hasMatch);
    }

    function setHint(id, met) {
        const el = document.getElementById(id);
        if (el) el.classList.toggle('met', met);
    }

    if (newPw) { newPw.addEventListener('input', updateHints); conPw.addEventListener('input', updateHints); }

    // ── DELETE ACCOUNT CONFIRMATION ──
    const deleteCheck = document.getElementById('confirmDeleteCheck');
    const deleteBtn   = document.getElementById('deleteBtn');
    if (deleteCheck) {
        deleteCheck.addEventListener('change', function() {
            deleteBtn.disabled = !this.checked;
            deleteBtn.style.opacity = this.checked ? '1' : '0.4';
            deleteBtn.style.pointerEvents = this.checked ? 'auto' : 'none';
        });
    }
</script>
</body>
</html>