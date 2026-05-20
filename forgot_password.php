<?php
include 'includes/connection.php';
session_start();

$error   = '';
$success = '';
$step    = $_SESSION['reset_step'] ?? 1;
$email   = $_SESSION['reset_email'] ?? '';

// Step 1: Send OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_otp'])) {
    $email = trim($_POST['email']);
    if (empty($email)) {
        $error = "Please enter your email address.";
    } else {
        $stmt = $conn->prepare("SELECT userID, Firstname FROM customers WHERE Email=?");
        $stmt->bind_param("s", $email); $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $otp  = rand(100000, 999999);
            $_SESSION['reset_email']  = $email;
            $_SESSION['reset_otp']    = $otp;
            $_SESSION['reset_expiry'] = time() + 300;
            $_SESSION['reset_userID'] = $user['userID'];
            $_SESSION['reset_step']   = 2;
            require_once 'config.php';
            $data = [
                'sender'      => ['name'=>'De Chavez Waterhaus','email'=>'cocacc202501@gmail.com'],
                'to'          => [['email'=>$email]],
                'subject'     => 'Password Reset OTP',
                'htmlContent' => "<h2>Password Reset Code</h2><p>Hi {$user['Firstname']},</p><p>Your code: <strong style='font-size:24px;color:#0077B6;'>$otp</strong></p><p>Expires in <strong>5 minutes</strong>.</p>"
            ];
            $ch = curl_init('https://api.brevo.com/v3/smtp/email');
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_HTTPHEADER=>['accept: application/json','api-key: '.BREVO_API_KEY,'content-type: application/json']]);
            curl_exec($ch); curl_close($ch);
            $step    = 2;
            $success = "A 6-digit code has been sent to your email.";
        } else {
            $error = "No account found with that email address.";
        }
        $stmt->close();
    }
}

// Step 2: Verify OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    $entered = trim($_POST['otp']);
    if (time() > ($_SESSION['reset_expiry'] ?? 0)) {
        $error = "Code expired. Please request a new one.";
        session_unset(); $step = 1;
    } elseif ((string)$entered !== (string)($_SESSION['reset_otp'] ?? '')) {
        $error = "Invalid code. Please try again.";
        $step  = 2;
    } else {
        $_SESSION['reset_step'] = 3;
        $step    = 3;
        $success = "Code verified! Set your new password below.";
    }
}

// Step 3: Reset Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $new  = $_POST['new_password'];
    $conf = $_POST['confirm_password'];
    if (strlen($new)<8 || !preg_match('/[A-Z]/',$new) || !preg_match('/[0-9]/',$new)) {
        $error = "Password must be 8+ chars with at least 1 uppercase and 1 number.";
        $step  = 3;
    } elseif ($new !== $conf) {
        $error = "Passwords do not match.";
        $step  = 3;
    } else {
        $hashed = password_hash($new, PASSWORD_DEFAULT);
        $uid    = $_SESSION['reset_userID'];
        $s      = $conn->prepare("UPDATE customers SET Password=? WHERE userID=?");
        $s->bind_param("si",$hashed,$uid); $s->execute(); $s->close();
        unset($_SESSION['reset_email'],$_SESSION['reset_otp'],$_SESSION['reset_expiry'],$_SESSION['reset_userID'],$_SESSION['reset_step']);
        $success = "Password reset successful! You can now log in.";
        $step    = 4; // done state
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
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="icon" href="images/logo.jpg" type="image/x-icon">
<style>
:root{
    --deep:#020d18; --abyss:#030f1e; --ocean:#041e35; --navy:#0a2d4a;
    --teal:#0077b6; --aqua:#00b4d8; --cyan:#48cae4;
    --foam:#caf0f8; --white:#f0f9ff; --gold:#f4c842;
    --green:#4ade80; --red:#f87171;
    --glass:rgba(0,180,216,0.08); --glass-border:rgba(72,202,228,0.18);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--deep);min-height:100vh;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;}

/* background glow */
body::before{content:'';position:absolute;inset:0;background:radial-gradient(circle at 30% 25%,rgba(0,180,216,.09) 0%,transparent 55%),radial-gradient(circle at 70% 75%,rgba(72,202,228,.06) 0%,transparent 55%);z-index:0;animation:orb 8s ease-in-out infinite alternate;}
@keyframes orb{from{opacity:.7;transform:scale(1);}to{opacity:1;transform:scale(1.04);}}

/* rings */
.ring{position:absolute;border-radius:50%;border:1px solid rgba(72,202,228,.07);animation:expand 6s ease-out infinite;z-index:1;}
.ring:nth-child(1){width:220px;height:220px;animation-delay:0s;}
.ring:nth-child(2){width:380px;height:380px;animation-delay:1.8s;}
.ring:nth-child(3){width:540px;height:540px;animation-delay:3.6s;}
@keyframes expand{0%{transform:scale(.8);opacity:.55;}100%{transform:scale(1.35);opacity:0;}}

/* card */
.forgot-card{background:linear-gradient(145deg,rgba(10,45,74,.88),rgba(3,15,30,.96));border:1px solid var(--glass-border);border-radius:26px;box-shadow:0 30px 60px -12px rgba(0,0,0,.6);max-width:430px;width:100%;padding:2.8rem 2.6rem;position:relative;z-index:2;backdrop-filter:blur(16px);}

/* logo */
.card-logo{width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid rgba(0,180,216,.3);box-shadow:0 0 18px rgba(0,180,216,.2);margin-bottom:1.2rem;}

/* step icon */
.step-icon{width:78px;height:78px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.4rem;font-size:1.9rem;position:relative;}
.step-icon.key  {background:linear-gradient(135deg,var(--teal),var(--aqua));color:var(--deep);box-shadow:0 10px 28px rgba(0,180,216,.4);}
.step-icon.mail {background:linear-gradient(135deg,#0a2d4a,var(--teal));color:var(--aqua);box-shadow:0 10px 28px rgba(0,119,182,.3);}
.step-icon.lock {background:linear-gradient(135deg,#1e3a5f,var(--aqua));color:var(--deep);box-shadow:0 10px 28px rgba(0,180,216,.4);}
.step-icon.done {background:linear-gradient(135deg,#15803d,var(--green));color:var(--deep);box-shadow:0 10px 28px rgba(74,222,128,.35);}
.step-icon::after{content:'';position:absolute;inset:-6px;border-radius:50%;border:2px solid rgba(0,180,216,.2);animation:pring 2.6s ease-out infinite;}
@keyframes pring{0%{transform:scale(1);opacity:.5;}100%{transform:scale(1.4);opacity:0;}}

/* heading */
.card-title{font-family:'Cormorant Garamond',serif;font-size:1.75rem;font-weight:400;color:var(--white);margin-bottom:.35rem;letter-spacing:.02em;}
.card-sub{font-size:.85rem;color:rgba(202,240,248,.42);line-height:1.6;margin-bottom:1.6rem;}

/* step progress */
.step-bar{display:flex;align-items:center;gap:0;margin-bottom:1.8rem;}
.step-dot{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;flex-shrink:0;transition:all .35s;}
.step-dot.done-s{background:linear-gradient(135deg,var(--teal),var(--aqua));color:var(--deep);}
.step-dot.active-s{background:var(--glass);border:2px solid var(--aqua);color:var(--aqua);}
.step-dot.future-s{background:rgba(4,30,53,.6);border:1px solid rgba(72,202,228,.15);color:rgba(202,240,248,.25);}
.step-line{flex:1;height:2px;background:rgba(72,202,228,.12);}
.step-line.filled{background:linear-gradient(90deg,var(--teal),var(--aqua));}

/* fields */
.field-label{display:block;font-size:.7rem;letter-spacing:.12em;text-transform:uppercase;color:rgba(202,240,248,.45);margin-bottom:7px;}
.field-input{width:100%;background:rgba(4,30,53,.7);border:1px solid var(--glass-border);color:var(--white);font-family:'DM Sans',sans-serif;font-size:.92rem;padding:12px 16px;border-radius:12px;outline:none;transition:all .3s;}
.field-input::placeholder{color:rgba(202,240,248,.22);}
.field-input:focus{border-color:var(--aqua);background:rgba(0,180,216,.07);box-shadow:0 0 0 3px rgba(0,180,216,.1);}
.field-hint{font-size:.72rem;color:rgba(202,240,248,.28);margin-top:5px;}
.field-group{margin-bottom:18px;}
.field-group:last-of-type{margin-bottom:0;}

/* OTP input special */
.otp-input{font-family:'Cormorant Garamond',serif;font-size:2rem;letter-spacing:.35em;text-align:center;padding:14px 16px;}

/* pw wrap */
.pw-wrap{position:relative;display:flex;gap:8px;}
.pw-wrap .field-input{flex:1;}
.btn-pw{background:var(--glass);border:1px solid var(--glass-border);color:rgba(202,240,248,.4);border-radius:10px;padding:0 14px;cursor:pointer;transition:all .25s;font-size:.85rem;flex-shrink:0;}
.btn-pw:hover{border-color:var(--aqua);color:var(--aqua);}

/* pw hints */
.pw-hints{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;}
.pw-hint{display:inline-flex;align-items:center;gap:4px;font-size:.7rem;color:rgba(202,240,248,.28);padding:3px 9px;border-radius:50px;border:1px solid rgba(202,240,248,.08);transition:all .3s;}
.pw-hint.met{color:var(--green);border-color:rgba(74,222,128,.28);background:rgba(74,222,128,.05);}

/* submit btn */
.btn-submit{width:100%;padding:14px;background:linear-gradient(135deg,var(--teal),var(--aqua));border:none;border-radius:50px;color:var(--deep);font-family:'DM Sans',sans-serif;font-size:.88rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;cursor:pointer;transition:all .3s;box-shadow:0 6px 20px rgba(0,180,216,.3);margin-top:20px;}
.btn-submit:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(0,180,216,.5);}
.btn-submit:active{transform:translateY(0);}

/* alerts */
.alert-box{padding:12px 16px;border-radius:12px;font-size:.84rem;margin-bottom:18px;display:flex;align-items:flex-start;gap:9px;}
.alert-success{background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.22);color:var(--green);}
.alert-error{background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.22);color:var(--red);}
.alert-box i{margin-top:2px;flex-shrink:0;}

/* back link */
.back-link{display:inline-flex;align-items:center;gap:6px;color:rgba(202,240,248,.4);text-decoration:none;font-size:.82rem;transition:all .25s;padding:6px 14px;border-radius:50px;border:1px solid transparent;margin-top:16px;}
.back-link:hover{color:var(--aqua);border-color:rgba(0,180,216,.2);background:rgba(0,180,216,.06);}

.divider{height:1px;background:var(--glass-border);margin:20px 0;}

/* success state */
.success-state{text-align:center;padding:10px 0;}
.btn-login-now{display:inline-flex;align-items:center;gap:8px;padding:13px 32px;background:linear-gradient(135deg,var(--teal),var(--aqua));border:none;border-radius:50px;color:var(--deep);font-family:'DM Sans',sans-serif;font-size:.88rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;cursor:pointer;text-decoration:none;transition:all .3s;box-shadow:0 6px 20px rgba(0,180,216,.3);margin-top:20px;}
.btn-login-now:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(0,180,216,.5);color:var(--deep);}
</style>
</head>
<body>
<div class="ring"></div>
<div class="ring"></div>
<div class="ring"></div>

<div class="forgot-card">
    <div class="text-center">
        <img src="images/logo.jpg" alt="De Chavez Waterhaus" class="card-logo">
    </div>

    <?php
    // Step icon + title
    $icons  = [1=>'key',    2=>'mail',        3=>'lock',          4=>'done'];
    $iconI  = [1=>'fa-key', 2=>'fa-envelope', 3=>'fa-lock',       4=>'fa-check'];
    $titles = [1=>'Forgot Password', 2=>'Check Your Email', 3=>'New Password', 4=>'All Done!'];
    $subs   = [
        1 => "Enter the email address linked to your account and we'll send a reset code.",
        2 => "Enter the 6-digit code we sent to <strong style='color:var(--aqua);'>".htmlspecialchars($email)."</strong>",
        3 => "Choose a strong password for your account.",
        4 => "Your password has been updated. You can now sign in."
    ];
    $si = $step <= 4 ? $step : 1;
    ?>

    <div class="text-center">
        <div class="step-icon <?php echo $icons[$si];?>">
            <i class="fas <?php echo $iconI[$si];?>"></i>
        </div>
        <h3 class="card-title"><?php echo $titles[$si];?></h3>
        <p class="card-sub"><?php echo $subs[$si];?></p>
    </div>

    <!-- Step Progress Bar (steps 1–3) -->
    <?php if($step < 4): ?>
    <div class="step-bar">
        <?php
        $states = [];
        for($i=1;$i<=3;$i++){
            if($i < $step)       $states[$i]='done-s';
            elseif($i === $step) $states[$i]='active-s';
            else                 $states[$i]='future-s';
        }
        ?>
        <div class="step-dot <?php echo $states[1];?>">
            <?php echo $step > 1 ? '<i class="fas fa-check" style="font-size:.62rem;"></i>' : '1'; ?>
        </div>
        <div class="step-line <?php echo $step>1?'filled':'';?>"></div>
        <div class="step-dot <?php echo $states[2];?>">
            <?php echo $step > 2 ? '<i class="fas fa-check" style="font-size:.62rem;"></i>' : '2'; ?>
        </div>
        <div class="step-line <?php echo $step>2?'filled':'';?>"></div>
        <div class="step-dot <?php echo $states[3];?>">3</div>
    </div>
    <?php endif; ?>

    <!-- Alerts -->
    <?php if($error): ?>
    <div class="alert-box alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo htmlspecialchars($error);?>
    </div>
    <?php endif; ?>
    <?php if($success && $step !== 4): ?>
    <div class="alert-box alert-success">
        <i class="fas fa-check-circle"></i>
        <?php echo htmlspecialchars($success);?>
    </div>
    <?php endif; ?>

    <!-- ── STEP 1: Email ── -->
    <?php if($step == 1): ?>
    <form method="POST">
        <div class="field-group">
            <label class="field-label">Email Address</label>
            <input type="email" class="field-input" name="email" placeholder="you@example.com" required autocomplete="email">
        </div>
        <button type="submit" name="send_otp" class="btn-submit">
            <i class="fas fa-paper-plane me-2"></i> Send Reset Code
        </button>
    </form>

    <!-- ── STEP 2: OTP ── -->
    <?php elseif($step == 2): ?>
    <form method="POST">
        <div class="field-group">
            <label class="field-label">6-Digit Code</label>
            <input type="text" class="field-input otp-input" name="otp" maxlength="6" placeholder="000000" required autocomplete="one-time-code" inputmode="numeric">
            <div class="field-hint">Code expires in 5 minutes · <a href="forgot_password.php" style="color:var(--aqua);text-decoration:none;">Resend code</a></div>
        </div>
        <button type="submit" name="verify_otp" class="btn-submit">
            <i class="fas fa-check me-2"></i> Verify Code
        </button>
    </form>

    <!-- ── STEP 3: New Password ── -->
    <?php elseif($step == 3): ?>
    <form method="POST" id="resetForm">
        <div class="field-group">
            <label class="field-label">New Password</label>
            <div class="pw-wrap">
                <input type="password" class="field-input" name="new_password" id="newPw" required minlength="8" placeholder="Min 8 chars">
                <button type="button" class="btn-pw" onclick="togglePw('newPw',this)"><i class="fas fa-eye"></i></button>
            </div>
            <div class="pw-hints">
                <span class="pw-hint" id="h-len"><i class="fas fa-circle" style="font-size:.4rem;"></i> 8+ chars</span>
                <span class="pw-hint" id="h-upper"><i class="fas fa-circle" style="font-size:.4rem;"></i> Uppercase</span>
                <span class="pw-hint" id="h-num"><i class="fas fa-circle" style="font-size:.4rem;"></i> Number</span>
            </div>
        </div>
        <div class="field-group">
            <label class="field-label">Confirm Password</label>
            <div class="pw-wrap">
                <input type="password" class="field-input" name="confirm_password" id="confPw" required placeholder="Re-enter password">
                <button type="button" class="btn-pw" onclick="togglePw('confPw',this)"><i class="fas fa-eye"></i></button>
            </div>
            <div class="pw-hints">
                <span class="pw-hint" id="h-match"><i class="fas fa-circle" style="font-size:.4rem;"></i> Passwords match</span>
            </div>
        </div>
        <button type="submit" name="reset_password" class="btn-submit" id="resetBtn">
            <i class="fas fa-shield-halved me-2"></i> Reset Password
        </button>
    </form>

    <!-- ── STEP 4: Done ── -->
    <?php elseif($step == 4): ?>
    <div class="success-state">
        <div style="background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.22);border-radius:14px;padding:20px 18px;text-align:left;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                <i class="fas fa-check-circle" style="color:var(--green);font-size:1.1rem;"></i>
                <span style="font-weight:600;color:var(--green);font-size:.9rem;">Password Updated Successfully</span>
            </div>
            <p style="font-size:.82rem;color:rgba(202,240,248,.45);line-height:1.6;margin:0;">Your account password has been reset. Use your new password the next time you log in.</p>
        </div>
        <a href="login.php" class="btn-login-now">
            <i class="fas fa-sign-in-alt"></i> Go to Login
        </a>
    </div>
    <?php endif; ?>

    <!-- Back link (not on done state) -->
    <?php if($step != 4): ?>
    <div class="text-center">
        <a href="login.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Login</a>
    </div>
    <?php endif; ?>

</div><!-- end forgot-card -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePw(id, btn) {
    const inp  = document.getElementById(id);
    const icon = btn.querySelector('i');
    inp.type   = inp.type === 'password' ? 'text' : 'password';
    icon.className = inp.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
}

const newPw  = document.getElementById('newPw');
const confPw = document.getElementById('confPw');

function updateHints() {
    const pw = newPw?.value ?? '';
    const cf = confPw?.value ?? '';
    setHint('h-len',   pw.length >= 8);
    setHint('h-upper', /[A-Z]/.test(pw));
    setHint('h-num',   /[0-9]/.test(pw));
    setHint('h-match', pw === cf && pw.length > 0);
}
function setHint(id, met) {
    const el = document.getElementById(id);
    if(el) el.classList.toggle('met', met);
}
newPw?.addEventListener('input',  updateHints);
confPw?.addEventListener('input', updateHints);

// OTP: auto-submit when 6 digits entered
const otpInput = document.querySelector('.otp-input');
if(otpInput){
    otpInput.addEventListener('input', function(){
        this.value = this.value.replace(/\D/g,'').slice(0,6);
        if(this.value.length === 6) this.closest('form').submit();
    });
}
</script>
</body>
</html>