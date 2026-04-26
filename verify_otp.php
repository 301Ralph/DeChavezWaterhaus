<?php
session_start();

$error = '';
$success = '';

// Security check
if (!isset($_SESSION['otp']) || !isset($_SESSION['otp_userID'])) {
    header("Location: login.php");
    exit();
}

$email = $_SESSION['otp_email'];
$expiry = $_SESSION['otp_expiry'];
$time_left = max(0, $expiry - time());

// Handle OTP verification (only when form is submitted)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    $entered_otp = trim($_POST['otp']);

    if (time() > $expiry) {
        $error = "OTP has expired. Please login again.";
        session_unset();
        session_destroy();
    } elseif ((string)$entered_otp === (string)$_SESSION['otp']) {
        // OTP is correct!
        include 'includes/connection.php';

        $userID = $_SESSION['otp_userID'];
        $stmt = $conn->prepare("SELECT * FROM customers WHERE userID = ?");
        $stmt->bind_param("i", $userID);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Login the user
        $_SESSION['userID'] = $user['userID'];
        $_SESSION['userName'] = $user['Firstname'] . ' ' . $user['Lastname'];
        $_SESSION['role'] = $user['Role'];

        // Clear OTP data
        unset($_SESSION['otp'], $_SESSION['otp_email'], $_SESSION['otp_userID'], $_SESSION['otp_expiry']);

        // Redirect
        if ($user['Role'] === 'admin') {
            header("Location: Admin/admin_dashboard.php");
        } elseif ($user['Role'] === 'rider') {
            header("Location: Rider/rider_dashboard.php");
        } else {
            header("Location: Customer/customer_dashboard.php");
        }
        exit();
    } else {
        $error = "Invalid OTP. Please try again.";
    }
}

// Handle Resend (only when ?resend=1)
if (isset($_GET['resend']) && $_GET['resend'] == 1) {
    include 'includes/connection.php';

    $new_otp = rand(100000, 999999);
    $_SESSION['otp'] = $new_otp;
    $_SESSION['otp_expiry'] = time() + 300;
    require_once 'config.php';
    // Send email via Brevo
    $apiKey = BREVO_API_KEY;

    $data = [
        'sender' => ['name' => 'De Chavez Waterhaus', 'email' => 'cocacc202501@gmail.com'],
        'to' => [['email' => $email]],
        'subject' => 'Your New Login OTP Code',
        'htmlContent' => "<h2>Your New OTP: <strong>$new_otp</strong></h2><p>Expires in 5 minutes.</p>"
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

    $success = "New OTP has been sent to your email.";
    $time_left = 300;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP • De Chavez Waterhaus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { font-family: 'Poppins', sans-serif; background: linear-gradient(135deg, #0077B6, #023E8A); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .otp-card { background: white; border-radius: 24px; box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.4); max-width: 420px; width: 100%; padding: 2.5rem; }
        .otp-input { font-size: 2rem; text-align: center; letter-spacing: 12px; font-weight: 700; }
        .btn-verify { background: linear-gradient(135deg, #0077B6, #023E8A); border: none; font-weight: 600; padding: 14px; }
    </style>
</head>
<body>
    <div class="otp-card text-center">
        <div class="mb-4">
            <i class="fas fa-shield-alt fa-4x text-primary mb-3"></i>
            <h4 class="fw-bold">Two-Factor Authentication</h4>
            <p class="text-muted">Enter the 6-digit code sent to<br><strong><?php echo htmlspecialchars($email); ?></strong></p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success py-2"><?php echo $success; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-4">
                <input type="text" class="form-control otp-input" name="otp" maxlength="6" placeholder="000000" required autofocus>
            </div>
            <button type="submit" name="verify_otp" class="btn btn-verify w-100 py-3 text-white rounded-pill">Verify Code</button>
        </form>

        <div class="mt-4">
            <p class="text-muted small mb-2">Didn't receive the code?</p>
            <a href="?resend=1" class="text-primary text-decoration-none fw-semibold">Resend OTP</a>
        </div>

        <div class="mt-4 pt-3 border-top">
            <a href="login.php" class="text-muted text-decoration-none small">← Back to Login</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>