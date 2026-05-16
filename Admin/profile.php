<?php
include '../includes/connection.php';
session_start();

if (file_exists('../config.php')) include '../config.php';

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'admin') {
    echo '<script>alert("Access denied. Admins only."); window.location = "../login.php";</script>';
    exit();
}

$adminID   = $_SESSION['userID'];
$adminName = $_SESSION['userName'] ?? 'Admin';

$uploadDir = '../uploads/profile_pictures/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$admin = $conn->query("SELECT * FROM customers WHERE userID = $adminID")->fetch_assoc();

// Send verification code
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_verification'])) {
    $loginEmail = trim($admin['Email'] ?? '');
    if (empty($loginEmail)) {
        echo '<script>alert("No email found on your account."); window.location="profile.php";</script>'; exit();
    }
    $otp = rand(100000, 999999);
    $_SESSION['verify_email']  = $loginEmail;
    $_SESSION['verify_otp']    = $otp;
    $_SESSION['verify_expiry'] = time() + 300;
    $_SESSION['verify_userID'] = $adminID;

    if (defined('BREVO_API_KEY')) {
        $data = [
            'sender'      => ['name' => 'De Chavez Waterhaus', 'email' => 'cocacc202501@gmail.com'],
            'to'          => [['email' => $loginEmail]],
            'subject'     => 'Verify Your Email - De Chavez Waterhaus',
            'htmlContent' => "<h2>Email Verification Code</h2><p>Hi {$adminName},</p><p>Your code: <strong style='font-size:24px;color:#0077B6;'>$otp</strong></p><p>Expires in 5 minutes.</p>"
        ];
        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($data), CURLOPT_HTTPHEADER=>['accept: application/json','api-key: '.BREVO_API_KEY,'content-type: application/json']]);
        curl_exec($ch); curl_close($ch);
    }
    echo '<script>alert("Verification code sent to '.htmlspecialchars($loginEmail).'"); window.location="profile.php";</script>'; exit();
}

// Verify code
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['verify_code'])) {
    $entered = trim($_POST['verification_code'] ?? '');
    if (time() > ($_SESSION['verify_expiry'] ?? 0)) {
        echo '<script>alert("Code expired. Please request a new one."); window.location="profile.php";</script>'; exit();
    } elseif ((string)$entered !== (string)($_SESSION['verify_otp'] ?? '')) {
        echo '<script>alert("Invalid code. Please try again."); window.location="profile.php";</script>'; exit();
    } else {
        $s = $conn->prepare("UPDATE customers SET email_verified=1, email_verification_token=NULL WHERE userID=?");
        $s->bind_param("i", $adminID); $s->execute(); $s->close();
        unset($_SESSION['verify_email'], $_SESSION['verify_otp'], $_SESSION['verify_expiry'], $_SESSION['verify_userID']);
        echo '<script>alert("Email verified successfully!"); window.location="profile.php";</script>'; exit();
    }
}

// Update profile
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $firstname = htmlspecialchars($_POST['firstname']);
    $lastname  = htmlspecialchars($_POST['lastname']);
    $email     = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $contact   = htmlspecialchars($_POST['contact']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo '<script>alert("Invalid email format."); window.location="profile.php";</script>'; exit();
    }
    $ck = $conn->prepare("SELECT userID FROM customers WHERE Email=? AND userID!=?");
    $ck->bind_param("si", $email, $adminID); $ck->execute();
    if ($ck->get_result()->num_rows > 0) {
        echo '<script>alert("Email already in use."); window.location="profile.php";</script>'; exit();
    }
    $ck->close();

    $profilePicture = $admin['profile_picture'] ?? '';
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
        $allowed = ['image/jpeg','image/png','image/jpg','image/webp'];
        if (in_array($_FILES['profile_picture']['type'], $allowed)) {
            $fileName   = 'admin_'.$adminID.'_'.time().'.'.pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $targetPath = $uploadDir.$fileName;
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $targetPath)) {
                if (!empty($profilePicture) && file_exists('../'.$profilePicture)) unlink('../'.$profilePicture);
                $profilePicture = 'uploads/profile_pictures/'.$fileName;
            }
        }
    }

    $s = $conn->prepare("UPDATE customers SET Firstname=?,Lastname=?,Email=?,Contact=?,profile_picture=? WHERE userID=?");
    $s->bind_param("sssssi", $firstname, $lastname, $email, $contact, $profilePicture, $adminID);
    if ($s->execute()) {
        $conn->query("UPDATE customers SET email_verified=0 WHERE userID=$adminID");
        $_SESSION['userName'] = $firstname.' '.$lastname;
        echo '<script>alert("Profile updated! Please re-verify your email."); window.location="profile.php";</script>';
    } else {
        echo '<script>alert("Error updating profile."); window.location="profile.php";</script>';
    }
    $s->close(); exit();
}

// Change password
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'];
    $new     = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];

    if (strlen($new)<8 || !preg_match('/[A-Z]/',$new) || !preg_match('/[0-9]/',$new)) {
        echo '<script>alert("Password must be 8+ chars with 1 uppercase and 1 number."); window.location="profile.php";</script>'; exit();
    }
    if ($new !== $confirm) {
        echo '<script>alert("New passwords do not match."); window.location="profile.php";</script>'; exit();
    }
    if (!password_verify($current, $admin['Password'])) {
        echo '<script>alert("Current password is incorrect."); window.location="profile.php";</script>'; exit();
    }

    $hashed = password_hash($new, PASSWORD_DEFAULT);
    $s = $conn->prepare("UPDATE customers SET Password=? WHERE userID=?");
    $s->bind_param("si", $hashed, $adminID);
    echo $s->execute()
        ? '<script>alert("Password changed successfully!"); window.location="profile.php";</script>'
        : '<script>alert("Error changing password."); window.location="profile.php";</script>';
    $s->close(); exit();
}

// Remove photo
if (isset($_GET['remove_photo'])) {
    if (!empty($admin['profile_picture']) && file_exists('../'.$admin['profile_picture'])) unlink('../'.$admin['profile_picture']);
    $s = $conn->prepare("UPDATE customers SET profile_picture=NULL WHERE userID=?");
    $s->bind_param("i", $adminID); $s->execute(); $s->close();
    echo '<script>window.location="profile.php";</script>'; exit();
}

$notifCount  = $conn->query("SELECT COUNT(*) as u FROM notifications WHERE userID=$adminID AND is_read=0")->fetch_assoc()['u'] ?? 0;
$isVerified  = !empty($admin['email_verified']) && $admin['email_verified'] == 1;
$hasPending  = !empty($_SESSION['verify_otp']) && !empty($_SESSION['verify_email']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile • Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="icon" href="../images/logo.jpg" type="image/x-icon">
    <style>
        :root {
            --deep:  #020d18;  --abyss: #030f1e;  --ocean: #041e35;  --navy:  #0a2d4a;
            --teal:  #0077b6;  --aqua:  #00b4d8;  --cyan:  #48cae4;
            --foam:  #caf0f8;  --white: #f0f9ff;  --gold:  #f4c842;
            --green: #4ade80;  --red: #f87171;     --violet: #a78bfa;
            --glass: rgba(0,180,216,0.08);  --glass-border: rgba(72,202,228,0.18);
            --sidebar-w: 260px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--deep); color: var(--white); min-height: 100vh; }

        /* ── SIDEBAR ── */
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-w); background: var(--abyss); border-right: 1px solid var(--glass-border); z-index: 1000; display: flex; flex-direction: column; transition: transform 0.3s ease; }
        .sidebar-logo { padding: 22px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid var(--glass-border); flex-shrink: 0; }
        .sidebar-logo img { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 1px solid rgba(0,180,216,0.35); }
        .sidebar-logo-text { font-family: 'Cormorant Garamond', serif; font-size: 1rem; font-weight: 500; color: var(--white); line-height: 1.2; }
        .sidebar-logo-sub  { font-size: 0.65rem; color: rgba(202,240,248,0.3); letter-spacing: 0.1em; text-transform: uppercase; }
        .sidebar-nav { flex: 1; overflow-y: auto; padding: 12px 10px; scrollbar-width: thin; scrollbar-color: rgba(72,202,228,0.15) transparent; }
        .sidebar-nav::-webkit-scrollbar { width: 3px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(72,202,228,0.15); border-radius: 2px; }
        .nav-section-label { font-size: 0.58rem; letter-spacing: 0.2em; text-transform: uppercase; color: rgba(202,240,248,0.22); padding: 14px 10px 5px; }
        .nav-link { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 9px; color: rgba(202,240,248,0.48) !important; text-decoration: none; font-size: 0.84rem; font-weight: 500; transition: all 0.22s ease; margin-bottom: 1px; position: relative; }
        .nav-link i { width: 16px; text-align: center; font-size: 0.85rem; color: rgba(0,180,216,0.38); transition: color 0.22s; }
        .nav-link:hover { background: var(--glass); color: var(--foam) !important; }
        .nav-link:hover i { color: var(--aqua); }
        .nav-link.active { background: linear-gradient(135deg, rgba(0,119,182,0.25), rgba(0,180,216,0.12)); border: 1px solid rgba(0,180,216,0.2); color: var(--aqua) !important; }
        .nav-link.active i { color: var(--aqua); }
        .nav-link.active::before { content: ''; position: absolute; left: 0; top: 22%; bottom: 22%; width: 3px; background: var(--aqua); border-radius: 0 3px 3px 0; }
        .nav-link.danger { color: rgba(252,165,165,0.6) !important; }
        .nav-link.danger i { color: rgba(252,165,165,0.5); }
        .nav-link.danger:hover { background: rgba(248,113,113,0.08); color: #fca5a5 !important; }
        .sidebar-footer { padding: 12px 10px; border-top: 1px solid var(--glass-border); flex-shrink: 0; }

        /* ── MAIN ── */
        .main-content { margin-left: var(--sidebar-w); min-height: 100vh; padding: 26px 30px; }

        /* ── TOP BAR ── */
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .topbar-left h4 { font-family: 'Cormorant Garamond', serif; font-size: 1.65rem; font-weight: 400; color: var(--white); line-height: 1.1; }
        .topbar-left p { font-size: 0.8rem; color: rgba(202,240,248,0.4); margin-top: 2px; }
        .topbar-right { display: flex; align-items: center; gap: 10px; }
        .topbar-btn { width: 40px; height: 40px; border-radius: 50%; background: var(--glass); border: 1px solid var(--glass-border); color: rgba(202,240,248,0.6); display: flex; align-items: center; justify-content: center; font-size: 0.88rem; text-decoration: none; transition: all 0.3s; position: relative; cursor: pointer; }
        .topbar-btn:hover { background: rgba(0,180,216,0.15); border-color: var(--aqua); color: var(--aqua); }
        .topbar-notif-badge { position: absolute; top: -3px; right: -3px; background: var(--gold); color: var(--deep); font-size: 0.55rem; font-weight: 700; min-width: 15px; height: 15px; border-radius: 50px; display: flex; align-items: center; justify-content: center; padding: 0 3px; }
        .avatar-btn { display: flex; align-items: center; gap: 9px; background: var(--glass); border: 1px solid var(--glass-border); border-radius: 50px; padding: 5px 12px 5px 5px; cursor: pointer; transition: all 0.3s; }
        .avatar-btn:hover { border-color: rgba(0,180,216,0.35); background: rgba(0,180,216,0.1); }
        .avatar-circle { width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); font-weight: 700; font-size: 0.82rem; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
        .avatar-circle img { width: 100%; height: 100%; object-fit: cover; }
        .avatar-name { font-size: 0.8rem; font-weight: 500; color: var(--white); }
        .avatar-role { font-size: 0.68rem; color: rgba(202,240,248,0.4); }
        .dropdown-menu { background: var(--ocean) !important; border: 1px solid var(--glass-border) !important; border-radius: 13px !important; padding: 7px !important; box-shadow: 0 18px 48px rgba(0,0,0,0.5) !important; }
        .dropdown-item { color: rgba(202,240,248,0.65) !important; border-radius: 7px !important; padding: 8px 13px !important; font-size: 0.83rem !important; transition: all 0.2s !important; }
        .dropdown-item:hover { background: var(--glass) !important; color: var(--aqua) !important; }
        .dropdown-item.text-danger { color: rgba(252,165,165,0.7) !important; }
        .dropdown-item.text-danger:hover { background: rgba(248,113,113,0.08) !important; color: #fca5a5 !important; }
        .dropdown-divider { border-color: var(--glass-border) !important; margin: 4px 0 !important; }

        /* ── AVATAR CARD ── */
        .avatar-card { background: linear-gradient(145deg, rgba(10,45,74,0.6), rgba(3,15,30,0.82)); border: 1px solid var(--glass-border); border-radius: 18px; overflow: hidden; }
        .avatar-hero { background: linear-gradient(135deg, rgba(0,119,182,0.3), rgba(0,180,216,0.15)); border-bottom: 1px solid rgba(0,180,216,0.15); padding: 36px 24px; text-align: center; position: relative; overflow: hidden; }
        .avatar-hero::before { content: ''; position: absolute; top: -50px; right: -50px; width: 140px; height: 140px; border-radius: 50%; background: rgba(0,180,216,0.07); }
        .avatar-hero-img { width: 96px; height: 96px; border-radius: 50%; object-fit: cover; border: 3px solid rgba(0,180,216,0.4); box-shadow: 0 0 28px rgba(0,180,216,0.2); margin-bottom: 14px; position: relative; z-index: 1; }
        .avatar-hero-initial { width: 96px; height: 96px; border-radius: 50%; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); font-family: 'Cormorant Garamond', serif; font-size: 2.6rem; font-weight: 300; display: flex; align-items: center; justify-content: center; border: 3px solid rgba(0,180,216,0.4); box-shadow: 0 0 28px rgba(0,180,216,0.2); margin: 0 auto 14px; position: relative; z-index: 1; }
        .avatar-hero-name { font-family: 'Cormorant Garamond', serif; font-size: 1.35rem; font-weight: 500; color: var(--white); margin-bottom: 6px; position: relative; z-index: 1; }
        .admin-badge { display: inline-flex; align-items: center; gap: 5px; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); padding: 4px 14px; border-radius: 50px; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; position: relative; z-index: 1; }

        .info-rows { padding: 16px 20px; }
        .info-row  { display: flex; justify-content: space-between; align-items: center; padding: 11px 0; border-bottom: 1px solid rgba(72,202,228,0.07); }
        .info-row:last-child { border-bottom: none; }
        .info-label { font-size: 0.75rem; color: rgba(202,240,248,0.38); }
        .info-value { font-size: 0.86rem; font-weight: 500; color: var(--foam); }

        .btn-remove-photo { display: inline-flex; align-items: center; gap: 5px; background: rgba(248,113,113,0.08); border: 1px solid rgba(248,113,113,0.22); color: var(--red); padding: 7px 16px; border-radius: 50px; font-size: 0.76rem; font-weight: 600; text-decoration: none; transition: all 0.25s; margin: 14px 20px 16px; }
        .btn-remove-photo:hover { background: rgba(248,113,113,0.18); color: var(--red); }

        /* ── FORM CARDS ── */
        .form-card { background: linear-gradient(145deg, rgba(10,45,74,0.55), rgba(3,15,30,0.78)); border: 1px solid var(--glass-border); border-radius: 18px; overflow: hidden; margin-bottom: 20px; }
        .form-card:last-child { margin-bottom: 0; }
        .form-card-head { padding: 18px 24px; border-bottom: 1px solid var(--glass-border); display: flex; align-items: center; gap: 10px; }
        .form-card-head-icon { width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, var(--teal), var(--aqua)); color: var(--deep); display: flex; align-items: center; justify-content: center; font-size: 0.85rem; flex-shrink: 0; }
        .form-card-title { font-family: 'Cormorant Garamond', serif; font-size: 1.1rem; font-weight: 500; color: var(--white); }
        .form-card-body { padding: 24px; }

        /* fields */
        .field-group { margin-bottom: 18px; }
        .field-group:last-child { margin-bottom: 0; }
        .field-label { display: block; font-size: 0.7rem; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(202,240,248,0.45); margin-bottom: 7px; }
        .field-input { width: 100%; background: rgba(4,30,53,0.7); border: 1px solid var(--glass-border); color: var(--white); font-family: 'DM Sans', sans-serif; font-size: 0.9rem; padding: 11px 14px; border-radius: 11px; outline: none; transition: all 0.3s; }
        .field-input::placeholder { color: rgba(202,240,248,0.2); }
        .field-input:focus { border-color: var(--aqua); background: rgba(0,180,216,0.07); box-shadow: 0 0 0 3px rgba(0,180,216,0.08); }
        .field-hint { font-size: 0.72rem; color: rgba(202,240,248,0.28); margin-top: 5px; }

        .field-file { width: 100%; background: rgba(4,30,53,0.5); border: 1px dashed rgba(72,202,228,0.25); color: rgba(202,240,248,0.5); font-family: 'DM Sans', sans-serif; font-size: 0.85rem; padding: 10px 14px; border-radius: 11px; outline: none; cursor: pointer; }
        .field-file::-webkit-file-upload-button { background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); border-radius: 6px; padding: 5px 12px; cursor: pointer; font-size: 0.8rem; margin-right: 10px; }

        /* pw wrap */
        .pw-wrap { position: relative; display: flex; gap: 8px; }
        .pw-wrap .field-input { flex: 1; padding-right: 44px; }
        .btn-pw-toggle { background: var(--glass); border: 1px solid var(--glass-border); color: rgba(202,240,248,0.4); border-radius: 10px; padding: 0 14px; cursor: pointer; transition: all 0.25s; font-size: 0.85rem; flex-shrink: 0; }
        .btn-pw-toggle:hover { border-color: var(--aqua); color: var(--aqua); }

        /* pw hints */
        .pw-hints { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
        .pw-hint { display: inline-flex; align-items: center; gap: 4px; font-size: 0.7rem; color: rgba(202,240,248,0.28); padding: 3px 9px; border-radius: 50px; border: 1px solid rgba(202,240,248,0.08); transition: all 0.3s; }
        .pw-hint.met { color: var(--green); border-color: rgba(74,222,128,0.28); background: rgba(74,222,128,0.05); }

        .form-divider { height: 1px; background: var(--glass-border); margin: 20px 0; }

        /* verify box */
        .verify-box { background: linear-gradient(135deg, rgba(0,119,182,0.12), rgba(0,180,216,0.06)); border: 1px solid rgba(0,180,216,0.2); border-left: 4px solid var(--aqua); border-radius: 12px; padding: 18px 20px; }
        .verify-box-title { font-size: 0.85rem; font-weight: 600; color: var(--aqua); margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }
        .verify-box-sub { font-size: 0.78rem; color: rgba(202,240,248,0.42); margin-bottom: 12px; }

        .verified-chip { display: inline-flex; align-items: center; gap: 5px; background: rgba(74,222,128,0.1); border: 1px solid rgba(74,222,128,0.28); color: var(--green); padding: 4px 12px; border-radius: 50px; font-size: 0.74rem; font-weight: 700; }
        .pending-chip  { display: inline-flex; align-items: center; gap: 5px; background: rgba(244,200,66,0.1); border: 1px solid rgba(244,200,66,0.25); color: var(--gold);  padding: 4px 12px; border-radius: 50px; font-size: 0.74rem; font-weight: 700; }

        .btn-send-code { display: inline-flex; align-items: center; gap: 6px; background: linear-gradient(135deg, var(--teal), var(--aqua)); border: none; border-radius: 50px; color: var(--deep); font-family: 'DM Sans', sans-serif; font-size: 0.78rem; font-weight: 700; letter-spacing: 0.06em; padding: 8px 18px; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 14px rgba(0,180,216,0.22); }
        .btn-send-code:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(0,180,216,0.4); }

        .otp-input { background: rgba(4,30,53,0.7); border: 1px solid var(--glass-border); color: var(--white); font-family: 'Cormorant Garamond', serif; font-size: 1.6rem; letter-spacing: 0.25em; padding: 10px 16px; border-radius: 11px; outline: none; width: 160px; text-align: center; transition: all 0.3s; }
        .otp-input:focus { border-color: var(--aqua); background: rgba(0,180,216,0.07); box-shadow: 0 0 0 3px rgba(0,180,216,0.08); }

        .btn-verify { display: inline-flex; align-items: center; gap: 5px; background: rgba(74,222,128,0.1); border: 1px solid rgba(74,222,128,0.25); color: var(--green); border-radius: 50px; font-family: 'DM Sans', sans-serif; font-size: 0.82rem; font-weight: 700; padding: 10px 20px; cursor: pointer; transition: all 0.25s; }
        .btn-verify:hover { background: rgba(74,222,128,0.2); transform: translateY(-1px); }

        /* action buttons */
        .btn-save { display: inline-flex; align-items: center; gap: 8px; padding: 12px 28px; background: linear-gradient(135deg, var(--teal), var(--aqua)); border: none; border-radius: 50px; color: var(--deep); font-family: 'DM Sans', sans-serif; font-size: 0.83rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; cursor: pointer; transition: all 0.3s; box-shadow: 0 5px 16px rgba(0,180,216,0.25); }
        .btn-save:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(0,180,216,0.45); }
        .btn-save-pwd { display: inline-flex; align-items: center; gap: 8px; padding: 12px 28px; background: rgba(244,200,66,0.15); border: 1px solid rgba(244,200,66,0.3); color: var(--gold); border-radius: 50px; font-family: 'DM Sans', sans-serif; font-size: 0.83rem; font-weight: 700; cursor: pointer; transition: all 0.3s; }
        .btn-save-pwd:hover { background: rgba(244,200,66,0.25); transform: translateY(-2px); }
        .btn-save-pwd:disabled { opacity: 0.4; cursor: not-allowed; transform: none; }

        /* info note */
        .info-note { background: rgba(0,180,216,0.06); border: 1px solid rgba(0,180,216,0.15); border-radius: 12px; padding: 12px 16px; font-size: 0.8rem; color: rgba(202,240,248,0.5); display: flex; align-items: flex-start; gap: 8px; margin-top: 20px; }
        .info-note i { color: var(--aqua); margin-top: 2px; flex-shrink: 0; }

        /* ── MOBILE ── */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(2,13,24,0.7); z-index: 999; backdrop-filter: blur(3px); }
        .mobile-toggle { background: var(--glass); border: 1px solid var(--glass-border); color: var(--aqua); width: 38px; height: 38px; border-radius: 9px; display: none; align-items: center; justify-content: center; cursor: pointer; font-size: 0.88rem; }

        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); box-shadow: 4px 0 40px rgba(0,0,0,0.5); }
            .sidebar.show { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; padding: 18px 16px; }
            .mobile-toggle { display: flex; }
        }
    </style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <img src="../images/logo.jpg" alt="Logo">
        <div>
            <div class="sidebar-logo-text">De Chavez Waterhaus</div>
            <div class="sidebar-logo-sub">Admin Panel</div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="admin_dashboard.php"   class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="manage_products.php"   class="nav-link"><i class="fas fa-box"></i> Products</a>
        <a href="manage_orders.php"     class="nav-link"><i class="fas fa-shopping-cart"></i> Orders</a>
        <a href="manage_users.php"      class="nav-link"><i class="fas fa-users"></i> Users</a>
        <a href="manage_employees.php"  class="nav-link"><i class="fas fa-user-tie"></i> Employees</a>
        <div class="nav-section-label">Operations</div>
        <a href="attendance_management.php" class="nav-link"><i class="fas fa-clock"></i> Attendance</a>
        <a href="payroll_management.php"    class="nav-link"><i class="fas fa-money-bill"></i> Payroll</a>
        <a href="generate_payslip.php"      class="nav-link"><i class="fas fa-file-pdf"></i> Generate Payslip</a>
        <a href="leave_management.php"      class="nav-link"><i class="fas fa-calendar-alt"></i> Manage Leave</a>
        <div class="nav-section-label">Support & Reports</div>
        <a href="support_tickets.php"   class="nav-link"><i class="fas fa-headset"></i> Support Tickets</a>
        <a href="reports.php"           class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a>
        <div class="nav-section-label" style="margin-top:14px;"></div>
        <a href="profile.php"           class="nav-link active"><i class="fas fa-user"></i> My Profile</a>
        <a href="../logout.php"         class="nav-link danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ── MAIN ── -->
<main class="main-content">

    <!-- Top Bar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="mobile-toggle" id="mobileToggle"><i class="fas fa-bars"></i></button>
            <div class="topbar-left">
                <h4>My Profile</h4>
                <p>Manage your admin account information</p>
            </div>
        </div>
        <div class="topbar-right">
            <a href="notifications.php" class="topbar-btn">
                <i class="fas fa-bell"></i>
                <?php if($notifCount>0): ?><span class="topbar-notif-badge"><?php echo min($notifCount,9).($notifCount>9?'+':'');?></span><?php endif; ?>
            </a>
            <div class="dropdown">
                <div class="avatar-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="avatar-circle">
                        <?php if(!empty($admin['profile_picture'])&&file_exists('../'.$admin['profile_picture'])): ?>
                            <img src="../<?php echo htmlspecialchars($admin['profile_picture']);?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr($adminName,0,1));?>
                        <?php endif; ?>
                    </div>
                    <div class="d-none d-md-block">
                        <div class="avatar-name"><?php echo htmlspecialchars($adminName);?></div>
                        <div class="avatar-role">Administrator</div>
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

        <!-- Left: Avatar Card -->
        <div class="col-lg-4">
            <div class="avatar-card">
                <div class="avatar-hero">
                    <?php if(!empty($admin['profile_picture'])&&file_exists('../'.$admin['profile_picture'])): ?>
                        <img src="../<?php echo htmlspecialchars($admin['profile_picture']);?>" class="avatar-hero-img" alt="">
                    <?php else: ?>
                        <div class="avatar-hero-initial"><?php echo strtoupper(substr($adminName,0,1));?></div>
                    <?php endif; ?>
                    <div class="avatar-hero-name"><?php echo htmlspecialchars($admin['Firstname'].' '.$admin['Lastname']);?></div>
                    <div class="admin-badge"><i class="fas fa-shield-halved" style="font-size:0.65rem;"></i> Administrator</div>
                </div>

                <div class="info-rows">
                    <div class="info-row">
                        <span class="info-label">User ID</span>
                        <span class="info-value">#<?php echo str_pad($adminID,5,'0',STR_PAD_LEFT);?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Email</span>
                        <span class="info-value" style="font-size:0.8rem;"><?php echo htmlspecialchars($admin['Email']);?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Phone</span>
                        <span class="info-value"><?php echo htmlspecialchars($admin['Contact']??'—');?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Email Status</span>
                        <?php if($isVerified): ?>
                            <span class="verified-chip"><i class="fas fa-check" style="font-size:0.6rem;"></i> Verified</span>
                        <?php else: ?>
                            <span class="pending-chip"><i class="fas fa-clock" style="font-size:0.6rem;"></i> Unverified</span>
                        <?php endif; ?>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Member Since</span>
                        <span class="info-value"><?php echo date('F Y', strtotime($admin['created_at']));?></span>
                    </div>
                </div>

                <?php if(!empty($admin['profile_picture'])): ?>
                <a href="profile.php?remove_photo=1" class="btn-remove-photo" onclick="return confirm('Remove your profile photo?')">
                    <i class="fas fa-trash"></i> Remove Photo
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right: Forms -->
        <div class="col-lg-8">

            <!-- Profile Information -->
            <div class="form-card">
                <div class="form-card-head">
                    <div class="form-card-head-icon"><i class="fas fa-user"></i></div>
                    <div class="form-card-title">Profile Information</div>
                </div>
                <div class="form-card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="field-group">
                                    <label class="field-label">First Name</label>
                                    <input type="text" class="field-input" name="firstname" value="<?php echo htmlspecialchars($admin['Firstname']);?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="field-group">
                                    <label class="field-label">Last Name</label>
                                    <input type="text" class="field-input" name="lastname" value="<?php echo htmlspecialchars($admin['Lastname']);?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="field-group">
                                    <label class="field-label">Login Email</label>
                                    <input type="email" class="field-input" name="email" value="<?php echo htmlspecialchars($admin['Email']);?>" required>
                                    <div class="field-hint">Used for login and password recovery</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="field-group">
                                    <label class="field-label">Phone Number</label>
                                    <input type="tel" class="field-input" name="contact" value="<?php echo htmlspecialchars($admin['Contact']??'');?>" placeholder="09XX XXX XXXX">
                                </div>
                            </div>

                            <!-- Email Verification Box -->
                            <div class="col-12">
                                <div class="verify-box">
                                    <div class="verify-box-title">
                                        <i class="fas fa-envelope-circle-check"></i>
                                        Verify Login Email
                                    </div>
                                    <div class="verify-box-sub">Verify your email to enable password recovery via the Forgot Password page.</div>

                                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                        <span style="font-size:0.84rem;color:rgba(202,240,248,0.55);"><?php echo htmlspecialchars($admin['Email']);?></span>
                                        <?php if($isVerified): ?>
                                            <span class="verified-chip"><i class="fas fa-check" style="font-size:0.6rem;"></i> Verified</span>
                                        <?php else: ?>
                                            <button type="submit" name="send_verification" class="btn-send-code">
                                                <i class="fas fa-paper-plane"></i> Send Code
                                            </button>
                                        <?php endif; ?>
                                    </div>

                                    <?php if($hasPending && !$isVerified): ?>
                                    <div style="margin-top:16px;background:rgba(4,30,53,0.5);border:1px solid var(--glass-border);border-radius:12px;padding:16px;">
                                        <div style="font-size:0.76rem;color:rgba(202,240,248,0.4);margin-bottom:10px;">Enter the 6-digit code sent to <strong style="color:var(--aqua);"><?php echo htmlspecialchars($_SESSION['verify_email']);?></strong>:</div>
                                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                            <input type="text" name="verification_code" class="otp-input" placeholder="000000" maxlength="6" required>
                                            <button type="submit" name="verify_code" class="btn-verify">
                                                <i class="fas fa-check"></i> Verify Code
                                            </button>
                                        </div>
                                        <div class="field-hint" style="margin-top:8px;">Code expires in 5 minutes.</div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="field-group mb-0">
                                    <label class="field-label">Profile Picture</label>
                                    <input type="file" class="field-file" name="profile_picture" accept="image/jpeg,image/png,image/jpg,image/webp">
                                    <div class="field-hint">JPG, PNG, WebP · Max 2MB · Leave empty to keep current</div>
                                </div>
                            </div>
                        </div>

                        <div class="form-divider"></div>
                        <button type="submit" name="update_profile" class="btn-save">
                            <i class="fas fa-check"></i> Save Changes
                        </button>
                    </form>
                </div>
            </div>

            <!-- Change Password -->
            <div class="form-card">
                <div class="form-card-head">
                    <div class="form-card-head-icon"><i class="fas fa-lock"></i></div>
                    <div class="form-card-title">Change Password</div>
                </div>
                <div class="form-card-body">
                    <form method="POST" id="pwForm">
                        <div class="field-group">
                            <label class="field-label">Current Password</label>
                            <div class="pw-wrap">
                                <input type="password" class="field-input" name="current_password" id="curPw" required>
                                <button type="button" class="btn-pw-toggle" onclick="togglePw('curPw',this)"><i class="fas fa-eye"></i></button>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="field-group">
                                    <label class="field-label">New Password</label>
                                    <div class="pw-wrap">
                                        <input type="password" class="field-input" name="new_password" id="newPw" required minlength="8">
                                        <button type="button" class="btn-pw-toggle" onclick="togglePw('newPw',this)"><i class="fas fa-eye"></i></button>
                                    </div>
                                    <div class="pw-hints">
                                        <span class="pw-hint" id="h-len"><i class="fas fa-circle" style="font-size:0.42rem;"></i> 8+ chars</span>
                                        <span class="pw-hint" id="h-upper"><i class="fas fa-circle" style="font-size:0.42rem;"></i> Uppercase</span>
                                        <span class="pw-hint" id="h-num"><i class="fas fa-circle" style="font-size:0.42rem;"></i> Number</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="field-group">
                                    <label class="field-label">Confirm Password</label>
                                    <div class="pw-wrap">
                                        <input type="password" class="field-input" name="confirm_password" id="confPw" required>
                                        <button type="button" class="btn-pw-toggle" onclick="togglePw('confPw',this)"><i class="fas fa-eye"></i></button>
                                    </div>
                                    <div class="pw-hints">
                                        <span class="pw-hint" id="h-match"><i class="fas fa-circle" style="font-size:0.42rem;"></i> Passwords match</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-divider"></div>
                        <button type="submit" name="change_password" id="pwBtn" class="btn-save-pwd" disabled style="opacity:0.4;cursor:not-allowed;">
                            <i class="fas fa-key"></i> Update Password
                        </button>
                    </form>
                </div>
            </div>

            <!-- Info Note -->
            <div class="info-note">
                <i class="fas fa-info-circle"></i>
                Verifying your email allows you to use the Forgot Password feature to securely recover access to your admin account.
            </div>

        </div>
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // ── SIDEBAR ──
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggle  = document.getElementById('mobileToggle');
    function openSidebar()  { sidebar.classList.add('show'); overlay.classList.add('show'); }
    function closeSidebar() { sidebar.classList.remove('show'); overlay.classList.remove('show'); }
    if(toggle)  toggle.addEventListener('click', openSidebar);
    if(overlay) overlay.addEventListener('click', closeSidebar);
    sidebar.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', () => { if(window.innerWidth<992) closeSidebar(); }));

    // ── PW TOGGLE ──
    function togglePw(id, btn) {
        const inp  = document.getElementById(id);
        const icon = btn.querySelector('i');
        inp.type   = inp.type === 'password' ? 'text' : 'password';
        icon.className = inp.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
    }

    // ── PW HINTS ──
    const newPw  = document.getElementById('newPw');
    const confPw = document.getElementById('confPw');
    const pwBtn  = document.getElementById('pwBtn');

    function updateHints() {
        const pw  = newPw?.value ?? '';
        const ok  = pw.length >= 8 && /[A-Z]/.test(pw) && /[0-9]/.test(pw) && pw === (confPw?.value ?? '') && pw.length > 0;
        setHint('h-len',   pw.length >= 8);
        setHint('h-upper', /[A-Z]/.test(pw));
        setHint('h-num',   /[0-9]/.test(pw));
        setHint('h-match', pw === (confPw?.value ?? '') && pw.length > 0);
        if(pwBtn) { pwBtn.disabled = !ok; pwBtn.style.opacity = ok ? '1' : '0.4'; pwBtn.style.cursor = ok ? 'pointer' : 'not-allowed'; }
    }

    function setHint(id, met) {
        const el = document.getElementById(id);
        if(el) el.classList.toggle('met', met);
    }

    newPw?.addEventListener('input', updateHints);
    confPw?.addEventListener('input', updateHints);
</script>
</body>
</html>