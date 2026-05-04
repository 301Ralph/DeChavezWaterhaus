<?php
session_start();
include 'includes/connection.php';

// Fetch available products
$productsQuery = "SELECT * FROM product";
$productsResult = $conn->query($productsQuery);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>De Chavez Waterhaus • Pure Water Delivered</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="icon" href="images/logo.jpg" type="image/x-icon">
    <style>
        :root {
            --deep: #020d18;
            --abyss: #030f1e;
            --ocean: #041e35;
            --navy: #0a2d4a;
            --teal: #0077b6;
            --aqua: #00b4d8;
            --cyan: #48cae4;
            --glow: #90e0ef;
            --foam: #caf0f8;
            --white: #f0f9ff;
            --gold: #f4c842;
            --glass: rgba(0, 180, 216, 0.08);
            --glass-border: rgba(72, 202, 228, 0.2);
            --glass-strong: rgba(0, 119, 182, 0.15);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        html { scroll-behavior: smooth; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--deep);
            color: var(--white);
            overflow-x: hidden;
        }

        h1, h2, h3, h4 {
            font-family: 'Cormorant Garamond', serif;
        }

        /* ─── PARTICLE CANVAS ─── */
        #particles {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            z-index: 0;
            pointer-events: none;
        }

        /* ─── NAVBAR ─── */
        .navbar {
            background: rgba(2, 13, 24, 0.85) !important;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--glass-border);
            padding: 12px 0;
            transition: all 0.3s ease;
        }

        .navbar.scrolled {
            background: rgba(2, 13, 24, 0.97) !important;
            box-shadow: 0 4px 30px rgba(0, 180, 216, 0.1);
        }

        .navbar-brand img {
            width: 48px; height: 48px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--aqua);
            box-shadow: 0 0 15px rgba(0, 180, 216, 0.4);
        }

        .navbar-brand span {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--white) !important;
            letter-spacing: 0.03em;
        }

        .nav-link {
            color: rgba(202, 240, 248, 0.75) !important;
            font-size: 0.85rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            font-weight: 500;
            padding: 6px 14px !important;
            position: relative;
            transition: color 0.3s;
        }

        .nav-link::after {
            content: '';
            position: absolute;
            bottom: 0; left: 50%;
            transform: translateX(-50%) scaleX(0);
            width: 60%;
            height: 1px;
            background: var(--aqua);
            transition: transform 0.3s ease;
        }

        .nav-link:hover {
            color: var(--aqua) !important;
        }

        .nav-link:hover::after { transform: translateX(-50%) scaleX(1); }

        .btn-cart {
            background: var(--glass);
            border: 1px solid var(--glass-border);
            color: var(--aqua);
            border-radius: 50px;
            padding: 8px 16px;
            font-size: 0.85rem;
            backdrop-filter: blur(10px);
            transition: all 0.3s;
        }

        .btn-cart:hover {
            background: rgba(0, 180, 216, 0.2);
            border-color: var(--aqua);
            box-shadow: 0 0 20px rgba(0, 180, 216, 0.3);
            color: var(--white);
        }

        .btn-signin {
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            border: none;
            color: var(--deep) !important;
            border-radius: 50px;
            padding: 8px 22px;
            font-size: 0.82rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            transition: all 0.3s;
            box-shadow: 0 4px 20px rgba(0, 180, 216, 0.3);
        }

        .btn-signin:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0, 180, 216, 0.5);
        }

        .cart-badge {
            background: var(--gold) !important;
            color: var(--deep) !important;
            font-weight: 700;
            font-size: 0.65rem;
        }

        /* ─── HERO ─── */
        .hero-section {
            min-height: 100vh;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: var(--deep);
        }

        .hero-bg {
            position: absolute;
            inset: 0;
            background: 
                radial-gradient(ellipse 80% 60% at 50% 80%, rgba(0, 119, 182, 0.18) 0%, transparent 70%),
                radial-gradient(ellipse 40% 40% at 20% 30%, rgba(0, 180, 216, 0.1) 0%, transparent 60%),
                radial-gradient(ellipse 50% 50% at 80% 20%, rgba(72, 202, 228, 0.07) 0%, transparent 60%);
            z-index: 1;
        }

        .hero-water-img {
            position: absolute;
            inset: 0;
            background: url('https://images.unsplash.com/photo-1523362628745-0c100150b504?auto=format&fit=crop&w=1800&q=80') center/cover no-repeat;
            opacity: 0.08;
            z-index: 0;
        }

        .hero-content {
            position: relative;
            z-index: 2;
            text-align: center;
            padding: 0 20px;
        }

        .hero-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            backdrop-filter: blur(10px);
            padding: 8px 20px;
            border-radius: 50px;
            font-size: 0.78rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--aqua);
            margin-bottom: 36px;
            animation: fadeInDown 0.8s ease both;
        }

        .hero-eyebrow .dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--aqua);
            animation: pulse-dot 2s ease-in-out infinite;
        }

        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(0.7); }
        }

        .hero-title {
            font-size: clamp(3.5rem, 9vw, 8rem);
            font-weight: 300;
            line-height: 0.95;
            letter-spacing: -0.02em;
            color: var(--white);
            margin-bottom: 12px;
            animation: fadeInUp 0.8s 0.2s ease both;
        }

        .hero-title .accent {
            font-style: italic;
            background: linear-gradient(135deg, var(--aqua), var(--glow));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-sub {
            font-family: 'DM Sans', sans-serif;
            font-size: clamp(1rem, 2vw, 1.2rem);
            color: rgba(202, 240, 248, 0.6);
            max-width: 520px;
            margin: 0 auto 48px;
            font-weight: 300;
            letter-spacing: 0.02em;
            animation: fadeInUp 0.8s 0.35s ease both;
        }

        .hero-cta-group {
            display: flex;
            gap: 16px;
            justify-content: center;
            flex-wrap: wrap;
            animation: fadeInUp 0.8s 0.5s ease both;
        }

        .btn-primary-hero {
            background: linear-gradient(135deg, var(--teal) 0%, var(--aqua) 100%);
            color: var(--deep);
            border: none;
            padding: 16px 40px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.23, 1, 0.32, 1);
            box-shadow: 0 8px 40px rgba(0, 180, 216, 0.4), inset 0 1px 0 rgba(255,255,255,0.3);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-primary-hero:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 16px 60px rgba(0, 180, 216, 0.6);
            color: var(--deep);
        }

        .btn-ghost-hero {
            background: transparent;
            color: var(--foam);
            border: 1px solid var(--glass-border);
            padding: 15px 38px;
            border-radius: 50px;
            font-size: 0.9rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-ghost-hero:hover {
            border-color: var(--aqua);
            color: var(--aqua);
            background: var(--glass);
        }

        .hero-stats {
            display: flex;
            gap: 48px;
            justify-content: center;
            margin-top: 80px;
            animation: fadeInUp 0.8s 0.65s ease both;
        }

        .hero-stat {
            text-align: center;
        }

        .hero-stat-num {
            font-family: 'Cormorant Garamond', serif;
            font-size: 2.2rem;
            font-weight: 600;
            color: var(--aqua);
            line-height: 1;
        }

        .hero-stat-label {
            font-size: 0.72rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: rgba(202, 240, 248, 0.45);
            margin-top: 4px;
        }

        .hero-stat-divider {
            width: 1px;
            background: var(--glass-border);
        }

        /* ─── WAVES ─── */
        .wave-section {
            position: relative;
            overflow: hidden;
        }

        .wave-top svg, .wave-bottom svg {
            display: block;
            width: 100%;
        }

        /* ─── SECTION HEADERS ─── */
        .section-tag {
            display: inline-block;
            font-size: 0.7rem;
            letter-spacing: 0.25em;
            text-transform: uppercase;
            color: var(--aqua);
            padding: 6px 16px;
            border: 1px solid rgba(0, 180, 216, 0.3);
            border-radius: 50px;
            margin-bottom: 20px;
        }

        .section-title {
            font-size: clamp(2.2rem, 5vw, 3.8rem);
            font-weight: 300;
            line-height: 1.1;
            letter-spacing: -0.02em;
        }

        .section-lead {
            font-size: 1.05rem;
            color: rgba(202, 240, 248, 0.55);
            font-weight: 300;
            max-width: 560px;
        }

        /* ─── PRODUCTS SECTION ─── */
        #products {
            background: linear-gradient(180deg, var(--ocean) 0%, var(--abyss) 100%);
            padding: 100px 0;
            position: relative;
        }

        .search-wrapper {
            position: relative;
            max-width: 480px;
            margin: 0 auto;
        }

        .search-wrapper input {
            background: var(--glass);
            border: 1px solid var(--glass-border);
            color: var(--white);
            border-radius: 50px;
            padding: 14px 20px 14px 50px;
            width: 100%;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.9rem;
            backdrop-filter: blur(10px);
            transition: all 0.3s;
        }

        .search-wrapper input::placeholder { color: rgba(202, 240, 248, 0.35); }

        .search-wrapper input:focus {
            outline: none;
            border-color: var(--aqua);
            box-shadow: 0 0 30px rgba(0, 180, 216, 0.2);
            background: rgba(0, 180, 216, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 18px; top: 50%;
            transform: translateY(-50%);
            color: rgba(0, 180, 216, 0.5);
            font-size: 0.9rem;
        }

        /* ─── PRODUCT CARDS ─── */
        .product-card {
            background: linear-gradient(145deg, rgba(10, 45, 74, 0.8), rgba(3, 15, 30, 0.9));
            border: 1px solid var(--glass-border) !important;
            border-radius: 20px !important;
            overflow: hidden;
            backdrop-filter: blur(20px);
            transition: all 0.4s cubic-bezier(0.23, 1, 0.32, 1);
            cursor: pointer;
            position: relative;
        }

        .product-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--aqua), transparent);
            opacity: 0;
            transition: opacity 0.4s;
        }

        .product-card:hover {
            transform: translateY(-10px);
            border-color: rgba(0, 180, 216, 0.4) !important;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.4), 0 0 40px rgba(0, 180, 216, 0.1);
        }

        .product-card:hover::before { opacity: 1; }

        .product-img-wrap {
            position: relative;
            overflow: hidden;
            height: 220px;
        }

        .product-img-wrap img {
            width: 100%; height: 100%;
            object-fit: cover;
            transition: transform 0.6s cubic-bezier(0.23, 1, 0.32, 1);
        }

        .product-card:hover .product-img-wrap img {
            transform: scale(1.08);
        }

        .product-img-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(to top, rgba(2,13,24,0.7) 0%, transparent 60%);
        }

        .product-badge {
            position: absolute;
            top: 14px; right: 14px;
            background: rgba(2, 13, 24, 0.75);
            backdrop-filter: blur(10px);
            color: var(--aqua);
            border: 1px solid var(--glass-border);
            border-radius: 50px;
            padding: 4px 12px;
            font-size: 0.72rem;
            letter-spacing: 0.1em;
            font-weight: 500;
        }

        .product-card .card-body {
            padding: 24px;
            background: transparent;
        }

        .card-title {
            font-family: 'Cormorant Garamond', serif !important;
            font-size: 1.5rem !important;
            font-weight: 500 !important;
            color: var(--white) !important;
            margin-bottom: 8px !important;
            letter-spacing: 0.01em;
        }

        .card-text {
            color: rgba(202, 240, 248, 0.5) !important;
            font-size: 0.85rem !important;
            line-height: 1.6;
        }

        .price-display {
            font-family: 'Cormorant Garamond', serif;
            font-size: 2rem;
            font-weight: 600;
            color: var(--aqua);
            letter-spacing: -0.02em;
        }

        .in-stock {
            font-size: 0.75rem;
            color: #4ade80;
            letter-spacing: 0.08em;
        }

        .btn-details {
            background: transparent;
            border: 1px solid var(--glass-border);
            color: var(--foam);
            border-radius: 50px;
            padding: 9px 20px;
            font-size: 0.8rem;
            letter-spacing: 0.08em;
            transition: all 0.3s;
        }

        .btn-details:hover {
            border-color: var(--aqua);
            color: var(--aqua);
            background: var(--glass);
        }

        .btn-add-cart {
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            border: none;
            color: var(--deep);
            border-radius: 50px;
            padding: 9px 22px;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(0, 180, 216, 0.3);
        }

        .btn-add-cart:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 180, 216, 0.5);
        }

        /* ─── WHY US ─── */
        #why-us {
            background: var(--deep);
            padding: 100px 0;
        }

        .feature-card {
            background: linear-gradient(145deg, rgba(10, 45, 74, 0.5), rgba(3, 15, 30, 0.7));
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 40px 32px;
            text-align: center;
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
        }

        .feature-card::after {
            content: '';
            position: absolute;
            bottom: -50%;
            left: 50%;
            transform: translateX(-50%);
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(0,180,216,0.15), transparent 70%);
            transition: all 0.4s;
        }

        .feature-card:hover {
            transform: translateY(-8px);
            border-color: rgba(0, 180, 216, 0.35);
            box-shadow: 0 20px 50px rgba(0,0,0,0.3);
        }

        .feature-card:hover::after {
            bottom: -20%;
            width: 150px;
            height: 150px;
        }

        .feature-icon {
            width: 76px; height: 76px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            font-size: 1.6rem;
            color: var(--deep);
            box-shadow: 0 8px 25px rgba(0,180,216,0.4);
            position: relative;
            z-index: 1;
        }

        .feature-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.35rem;
            font-weight: 500;
            color: var(--white);
            margin-bottom: 12px;
        }

        .feature-text {
            color: rgba(202, 240, 248, 0.5);
            font-size: 0.88rem;
            line-height: 1.7;
        }

        /* ─── TESTIMONIALS ─── */
        #testimonials {
            background: linear-gradient(180deg, var(--abyss) 0%, var(--ocean) 100%);
            padding: 100px 0;
        }

        .testimonial-card {
            background: linear-gradient(145deg, rgba(10,45,74,0.6), rgba(3,15,30,0.8));
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 36px 32px;
            transition: all 0.3s ease;
            height: 100%;
        }

        .testimonial-card:hover {
            transform: translateY(-6px);
            border-color: rgba(0,180,216,0.3);
        }

        .testimonial-avatar {
            width: 52px; height: 52px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--glass-border);
        }

        .testimonial-name {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.1rem;
            font-weight: 500;
            color: var(--white);
        }

        .testimonial-loc {
            font-size: 0.75rem;
            color: rgba(202,240,248,0.4);
            letter-spacing: 0.05em;
        }

        .stars { color: var(--gold); font-size: 0.85rem; }

        .testimonial-text {
            font-size: 0.9rem;
            color: rgba(202,240,248,0.65);
            line-height: 1.75;
            font-style: italic;
            margin-top: 16px;
        }

        .quote-mark {
            font-family: 'Cormorant Garamond', serif;
            font-size: 5rem;
            line-height: 0.5;
            color: var(--teal);
            opacity: 0.4;
            float: left;
            margin-right: 8px;
        }

        /* ─── ABOUT ─── */
        #about {
            background: var(--deep);
            padding: 100px 0;
        }

        .about-img {
            border-radius: 20px;
            width: 100%;
            max-height: 460px;
            object-fit: cover;
            border: 1px solid var(--glass-border);
            box-shadow: 0 30px 80px rgba(0,0,0,0.5), 0 0 60px rgba(0,119,182,0.15);
        }

        .about-badge-float {
            position: absolute;
            bottom: 24px;
            left: -20px;
            background: linear-gradient(135deg, var(--ocean), var(--navy));
            border: 1px solid var(--glass-border);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            padding: 16px 24px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.4);
        }

        .about-badge-icon {
            font-size: 1.8rem;
            color: var(--gold);
        }

        .about-badge-text strong {
            display: block;
            font-size: 0.85rem;
            color: var(--white);
        }

        .about-badge-text small {
            font-size: 0.72rem;
            color: rgba(202,240,248,0.5);
        }

        .info-row {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            padding: 20px 0;
            border-bottom: 1px solid var(--glass-border);
        }

        .info-row:last-child { border-bottom: none; }

        .info-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--aqua);
            flex-shrink: 0;
            font-size: 0.95rem;
        }

        .info-label {
            font-size: 0.72rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(202,240,248,0.4);
        }

        .info-value {
            font-size: 0.92rem;
            color: var(--white);
            margin-top: 2px;
        }

        /* ─── FAQ ─── */
        #faq {
            background: linear-gradient(180deg, var(--abyss) 0%, var(--deep) 100%);
            padding: 100px 0;
        }

        .accordion-item {
            background: linear-gradient(145deg, rgba(10,45,74,0.5), rgba(3,15,30,0.7)) !important;
            border: 1px solid var(--glass-border) !important;
            border-radius: 14px !important;
            margin-bottom: 10px;
            overflow: hidden;
        }

        .accordion-button {
            background: transparent !important;
            color: var(--white) !important;
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.1rem;
            font-weight: 500;
            letter-spacing: 0.01em;
            padding: 20px 24px;
            box-shadow: none !important;
        }

        .accordion-button:not(.collapsed) {
            color: var(--aqua) !important;
        }

        .accordion-button::after {
            filter: invert(0.8) sepia(1) saturate(3) hue-rotate(175deg);
        }

        .accordion-body {
            background: transparent !important;
            color: rgba(202,240,248,0.6);
            font-size: 0.9rem;
            line-height: 1.75;
            padding: 0 24px 24px;
        }

        /* ─── FOOTER ─── */
        footer {
            background: var(--abyss);
            border-top: 1px solid var(--glass-border);
            padding: 80px 0 40px;
        }

        .footer-brand {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.6rem;
            font-weight: 500;
            color: var(--white);
        }

        .footer-desc {
            color: rgba(202,240,248,0.4);
            font-size: 0.87rem;
            line-height: 1.7;
            max-width: 300px;
        }

        .footer-social a {
            width: 38px; height: 38px;
            border-radius: 50%;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: rgba(202,240,248,0.5);
            text-decoration: none;
            margin-right: 8px;
            transition: all 0.3s;
            font-size: 0.85rem;
        }

        .footer-social a:hover {
            background: var(--glass-strong);
            border-color: var(--aqua);
            color: var(--aqua);
            transform: translateY(-3px);
        }

        .footer-heading {
            font-size: 0.75rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: var(--aqua);
            margin-bottom: 20px;
        }

        .footer-links a {
            display: block;
            color: rgba(202,240,248,0.4);
            text-decoration: none;
            font-size: 0.88rem;
            padding: 5px 0;
            transition: all 0.2s;
        }

        .footer-links a:hover {
            color: var(--aqua);
            padding-left: 6px;
        }

        .footer-contact li {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            color: rgba(202,240,248,0.45);
            font-size: 0.87rem;
            padding: 6px 0;
        }

        .footer-contact i { color: var(--aqua); margin-top: 2px; min-width: 14px; }

        .footer-cta-box {
            background: linear-gradient(135deg, rgba(0,119,182,0.2), rgba(0,180,216,0.1));
            border: 1px solid rgba(0,180,216,0.25);
            border-radius: 20px;
            padding: 32px;
        }

        .footer-divider {
            border-color: var(--glass-border) !important;
            margin: 40px 0 30px;
        }

        .footer-bottom {
            color: rgba(202,240,248,0.25);
            font-size: 0.8rem;
            text-align: center;
        }

        /* ─── MODALS ─── */
        .modal-content {
            background: linear-gradient(145deg, var(--ocean), var(--abyss)) !important;
            border: 1px solid var(--glass-border) !important;
            border-radius: 20px !important;
        }

        .modal-header {
            border-bottom: 1px solid var(--glass-border) !important;
        }

        .modal-footer {
            border-top: 1px solid var(--glass-border) !important;
        }

        .modal-title { color: var(--white) !important; }
        .btn-close { filter: invert(1) opacity(0.6); }
        .btn-close:hover { filter: invert(1) opacity(1); }

        /* ─── TOAST ─── */
        .toast {
            background: linear-gradient(135deg, var(--navy), var(--ocean)) !important;
            border: 1px solid var(--glass-border) !important;
            border-radius: 14px !important;
        }

        /* ─── ANIMATIONS ─── */
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .reveal {
            opacity: 0;
            transform: translateY(40px);
            transition: all 0.7s cubic-bezier(0.23, 1, 0.32, 1);
        }

        .reveal.visible {
            opacity: 1;
            transform: translateY(0);
        }

        /* ─── SCROLL INDICATOR ─── */
        .scroll-cue {
            position: absolute;
            bottom: 40px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 3;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .scroll-cue span {
            font-size: 0.65rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: rgba(202,240,248,0.35);
        }

        .scroll-line {
            width: 1px;
            height: 50px;
            background: linear-gradient(to bottom, rgba(0,180,216,0.6), transparent);
            animation: scrollLine 1.8s ease-in-out infinite;
        }

        @keyframes scrollLine {
            0% { transform: scaleY(0); transform-origin: top; }
            50% { transform: scaleY(1); transform-origin: top; }
            51% { transform: scaleY(1); transform-origin: bottom; }
            100% { transform: scaleY(0); transform-origin: bottom; }
        }

        /* ─── RESPONSIVE ─── */
        @media (max-width: 768px) {
            .hero-stats { gap: 28px; }
            .about-badge-float { left: 10px; }
        }
    </style>
</head>
<body>

    <canvas id="particles"></canvas>

    <!-- ─── NAVBAR ─── -->
    <nav class="navbar navbar-expand-lg sticky-top" id="mainNav">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-3" href="#">
                <img src="images/logo.jpg" alt="De Chavez Waterhaus Logo">
                <span>De Chavez Waterhaus</span>
            </a>

            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
                    style="color: var(--aqua);">
                <i class="fas fa-bars"></i>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">
                    <li class="nav-item"><a class="nav-link" href="#home">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="#products">Products</a></li>
                    <li class="nav-item"><a class="nav-link" href="#why-us">Why Us</a></li>
                    <li class="nav-item"><a class="nav-link" href="#about">About</a></li>
                    <li class="nav-item"><a class="nav-link" href="#faq">FAQ</a></li>

                    <li class="nav-item ms-lg-3">
                        <button class="btn-cart position-relative" onclick="showCartModal()">
                            <i class="fas fa-shopping-bag me-1"></i> Cart
                            <span id="cart-count" class="position-absolute top-0 start-100 translate-middle badge rounded-pill cart-badge" style="font-size:0.6rem;">0</span>
                        </button>
                    </li>

                    <li class="nav-item ms-lg-2">
                        <a class="btn-signin" href="login.php">
                            <i class="fas fa-user me-1"></i> Sign In
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- ─── HERO ─── -->
    <section id="home" class="hero-section">
        <div class="hero-water-img"></div>
        <div class="hero-bg"></div>

        <div class="hero-content">
            <div class="hero-eyebrow">
                <span class="dot"></span>
                Trusted since 2015 · Noveleta, Cavite
            </div>

            <h1 class="hero-title">
                Pure Water.<br><span class="accent">Delivered.</span>
            </h1>

            <p class="hero-sub">
                Experience the cleanest, healthiest water in Cavite — sourced with care, delivered with pride, straight to your door.
            </p>

            <div class="hero-cta-group">
                <button class="btn-primary-hero"
                        onclick="window.location.href='<?php echo isset($_SESSION['userID']) ? ($_SESSION['role'] === 'admin' ? 'Admin/admin_dashboard.php' : 'Customer/order.php') : 'login.php'; ?>'">
                    <i class="fas fa-shopping-bag"></i> Order Now
                </button>
                <a href="#products" class="btn-ghost-hero">
                    <i class="fas fa-droplet"></i> View Products
                </a>
            </div>

            <div class="hero-stats">
                <div class="hero-stat">
                    <div class="hero-stat-num">2,500+</div>
                    <div class="hero-stat-label">Happy Families</div>
                </div>
                <div class="hero-stat-divider"></div>
                <div class="hero-stat">
                    <div class="hero-stat-num">4</div>
                    <div class="hero-stat-label">Water Types</div>
                </div>
                <div class="hero-stat-divider"></div>
                <div class="hero-stat">
                    <div class="hero-stat-num">10yr+</div>
                    <div class="hero-stat-label">In Business</div>
                </div>
            </div>
        </div>

        <a href="#products" class="scroll-cue">
            <span>Scroll</span>
            <div class="scroll-line"></div>
        </a>
    </section>

    <!-- ─── PRODUCTS ─── -->
    <section id="products" style="background: linear-gradient(180deg, var(--ocean) 0%, var(--abyss) 100%); padding: 100px 0;">
        <div class="container">
            <div class="text-center mb-5 reveal">
                <div class="section-tag">Fresh &amp; Pure</div>
                <h2 class="section-title">Our Premium<br><em>Water Collection</em></h2>
                <p class="section-lead mx-auto mt-3">Purified, distilled, mineral &amp; alkaline water in convenient 5-gallon containers.</p>
            </div>

            <div class="mb-5 reveal">
                <div class="search-wrapper">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="productSearch" placeholder="Search by type (e.g. Alkaline, Purified)…">
                </div>
            </div>

            <div class="row g-4" id="products-grid">
                <?php
                $defaultImages = [
                    '1' => 'images/waterdispenser.jpg',
                    '2' => 'images/default-upload.jpg'
                ];
                $productIndex = 1;
                while ($product = $productsResult->fetch_assoc()) {
                    $imageURL = $product['ImageURL'];
                    $productID = $product['ProductID'];
                    if (!file_exists($imageURL) || empty($imageURL)) {
                        $imageURL = $defaultImages[$productIndex];
                    }
                ?>
                    <div class="col-md-6 col-lg-4 reveal">
                        <div class="card product-card h-100 border-0"
                             data-id="<?php echo $productID; ?>"
                             data-name="<?php echo htmlspecialchars($product['ProductName']); ?>"
                             data-price="<?php echo $product['Price']; ?>"
                             data-image="<?php echo $imageURL; ?>">

                            <div class="product-img-wrap">
                                <img src="<?php echo $imageURL; ?>" alt="<?php echo $product['ProductName']; ?>"
                                     onerror="this.src='<?php echo $defaultImages[$productIndex]; ?>';">
                                <div class="product-img-overlay"></div>
                                <span class="product-badge">5 Gallon</span>
                            </div>

                            <div class="card-body d-flex flex-column">
                                <h5 class="card-title"><?php echo $product['ProductName']; ?></h5>
                                <p class="card-text flex-grow-1"><?php echo $product['Description']; ?></p>

                                <div class="mt-auto pt-3">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <span class="price-display">₱<?php echo number_format($product['Price'], 2); ?></span>
                                        <span class="in-stock"><i class="fas fa-circle me-1" style="font-size:0.5rem;"></i> In Stock</span>
                                    </div>

                                    <div class="d-flex gap-2">
                                        <button class="btn-details flex-fill" onclick="viewProduct(this)">
                                            <i class="fas fa-eye me-1"></i> Details
                                        </button>
                                        <button class="btn-add-cart flex-fill" onclick="addToCartFromCard(this)">
                                            <i class="fas fa-cart-plus me-1"></i> Add
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php
                    $productIndex = ($productIndex % 2) + 1;
                } ?>
            </div>

            <div class="text-center mt-5 reveal">
                <p style="color: rgba(202,240,248,0.4); font-size:0.88rem;">
                    Need bulk orders or dispenser rental?
                    <a href="#faq" style="color: var(--aqua); text-decoration:none; border-bottom: 1px solid rgba(0,180,216,0.4);">Contact us</a>
                </p>
            </div>
        </div>
    </section>

    <!-- ─── WHY US ─── -->
    <section id="why-us" style="background: var(--deep); padding: 100px 0;">
        <div class="container">
            <div class="text-center mb-5 reveal">
                <div class="section-tag">Our Promise</div>
                <h2 class="section-title">Why Families Choose<br><em>De Chavez Waterhaus</em></h2>
                <p class="section-lead mx-auto mt-3">More than just water — it's peace of mind in every drop.</p>
            </div>

            <div class="row g-4">
                <div class="col-md-4 reveal">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-shield-alt"></i></div>
                        <h5 class="feature-title">Multi-Stage Purification</h5>
                        <p class="feature-text">Advanced filtration, UV sterilization, and mineral balancing for the purest taste and health benefits.</p>
                    </div>
                </div>
                <div class="col-md-4 reveal">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-truck"></i></div>
                        <h5 class="feature-title">Same-Day Delivery</h5>
                        <p class="feature-text">Order before 2 PM and receive fresh water at your doorstep the same day across Noveleta and nearby areas.</p>
                    </div>
                </div>
                <div class="col-md-4 reveal">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="fas fa-recycle"></i></div>
                        <h5 class="feature-title">Eco-Friendly Refills</h5>
                        <p class="feature-text">Reusable 5-gallon containers reduce plastic waste. We sanitize and refill — good for you and the planet.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ─── TESTIMONIALS ─── -->
    <section id="testimonials" style="background: linear-gradient(180deg, var(--abyss), var(--ocean)); padding: 100px 0;">
        <div class="container">
            <div class="text-center mb-5 reveal">
                <div class="section-tag">Community Love</div>
                <h2 class="section-title">Loved by Families<br><em>Across Cavite</em></h2>
            </div>

            <div class="row g-4">
                <div class="col-md-4 reveal">
                    <div class="testimonial-card">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <img src="https://i.pravatar.cc/60?img=28" class="testimonial-avatar" alt="Maria Santos">
                            <div>
                                <div class="testimonial-name">Maria Santos</div>
                                <div class="testimonial-loc">Noveleta · 3 years customer</div>
                            </div>
                        </div>
                        <div class="stars mb-2">★★★★★</div>
                        <p class="testimonial-text"><span class="quote-mark">"</span>The water tastes so clean and fresh. My kids love it and I feel safe giving it to them every day. Delivery is always on time!</p>
                    </div>
                </div>
                <div class="col-md-4 reveal">
                    <div class="testimonial-card">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <img src="https://i.pravatar.cc/60?img=32" class="testimonial-avatar" alt="Juan Dela Cruz">
                            <div>
                                <div class="testimonial-name">Juan Dela Cruz</div>
                                <div class="testimonial-loc">Imus · 2 years customer</div>
                            </div>
                        </div>
                        <div class="stars mb-2">★★★★<span style="color:rgba(244,200,66,0.4);">★</span></div>
                        <p class="testimonial-text"><span class="quote-mark">"</span>Best decision for our office. The alkaline water gives us more energy. The team is professional and the dispenser rental is very affordable.</p>
                    </div>
                </div>
                <div class="col-md-4 reveal">
                    <div class="testimonial-card">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <img src="https://i.pravatar.cc/60?img=47" class="testimonial-avatar" alt="Ana Reyes">
                            <div>
                                <div class="testimonial-name">Ana Reyes</div>
                                <div class="testimonial-loc">General Trias · 1 year customer</div>
                            </div>
                        </div>
                        <div class="stars mb-2">★★★★★</div>
                        <p class="testimonial-text"><span class="quote-mark">"</span>I switched from another supplier and never looked back. The mineral water is perfect for my family's daily hydration. Highly recommended!</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ─── ABOUT ─── -->
    <section id="about" style="background: var(--deep); padding: 100px 0;">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-6 reveal">
                    <div class="position-relative">
                        <img src="https://images.unsplash.com/photo-1581092160607-ee22621dd758?auto=format&fit=crop&w=800&q=80"
                             class="about-img" alt="De Chavez Waterhaus facility">
                        <div class="about-badge-float">
                            <div class="about-badge-icon"><i class="fas fa-award"></i></div>
                            <div class="about-badge-text">
                                <strong>5-Star Rated Service</strong>
                                <small>by 2,500+ happy customers</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 reveal">
                    <div class="section-tag">Est. 2015</div>
                    <h2 class="section-title mt-3">Your Trusted Partner<br><em>for Clean Water</em></h2>
                    <p style="color: rgba(202,240,248,0.55); font-size:0.95rem; line-height:1.8; margin: 24px 0;">
                        De Chavez Waterhaus is a family-owned business committed to delivering the highest quality drinking water to homes and offices across Noveleta, Imus, General Trias, and nearby communities.
                    </p>

                    <div class="info-row">
                        <div class="info-icon"><i class="fas fa-map-marker-alt"></i></div>
                        <div>
                            <div class="info-label">Address</div>
                            <div class="info-value">072 Nawasa, Sta. Rosa 1, Noveleta, Cavite<br><small style="color:rgba(202,240,248,0.4);">Near Tramo Road</small></div>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-icon"><i class="fas fa-phone"></i></div>
                        <div>
                            <div class="info-label">Phone</div>
                            <div class="info-value">Tel. No. 438-6311</div>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-icon"><i class="fas fa-clock"></i></div>
                        <div>
                            <div class="info-label">Hours</div>
                            <div class="info-value">Monday – Saturday &nbsp;·&nbsp; 8:00 AM – 6:00 PM</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ─── FAQ ─── -->
    <section id="faq" style="background: linear-gradient(180deg, var(--abyss), var(--deep)); padding: 100px 0;">
        <div class="container">
            <div class="text-center mb-5 reveal">
                <div class="section-tag">Got Questions?</div>
                <h2 class="section-title">Frequently Asked<br><em>Questions</em></h2>
            </div>

            <div class="accordion" id="faqAccordion" style="max-width: 760px; margin: 0 auto;">
                <div class="accordion-item reveal">
                    <h2 class="accordion-header">
                        <button class="accordion-button fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                            What types of water do you offer?
                        </button>
                    </h2>
                    <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            We offer four premium options: <strong style="color:var(--aqua);">Purified Water</strong>, <strong style="color:var(--aqua);">Distilled Water</strong>, <strong style="color:var(--aqua);">Mineral Water</strong>, and <strong style="color:var(--aqua);">Alkaline Water</strong> — all in hygienic 5-gallon containers.
                        </div>
                    </div>
                </div>
                <div class="accordion-item reveal">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                            How can I place an order?
                        </button>
                    </h2>
                    <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            Click the <strong style="color:var(--aqua);">"Order Now"</strong> button, sign in or create an account, and select your preferred water type and quantity. You can also call us directly at 438-6311.
                        </div>
                    </div>
                </div>
                <div class="accordion-item reveal">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                            What are your operating and delivery hours?
                        </button>
                    </h2>
                    <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            We are open <strong style="color:var(--aqua);">Monday to Saturday, 8:00 AM – 6:00 PM</strong>. Same-day delivery is available for orders placed before 2:00 PM within our service area.
                        </div>
                    </div>
                </div>
                <div class="accordion-item reveal">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                            Do you offer water dispenser rentals?
                        </button>
                    </h2>
                    <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            Yes! We offer affordable monthly rental plans for hot &amp; cold water dispensers. Perfect for homes and offices. Ask us for current rates when you place your first order.
                        </div>
                    </div>
                </div>
                <div class="accordion-item reveal">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faq5">
                            How do I contact customer service?
                        </button>
                    </h2>
                    <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body">
                            Call us at <strong style="color:var(--aqua);">438-6311</strong> or visit our station at 072 Nawasa, Sta. Rosa 1, Noveleta, Cavite. We also respond quickly on our Facebook page.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ─── FOOTER ─── -->
    <footer>
        <div class="container">
            <div class="row g-5">
                <div class="col-lg-4">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <i class="fas fa-tint" style="color:var(--aqua); font-size:1.4rem;"></i>
                        <span class="footer-brand">De Chavez Waterhaus</span>
                    </div>
                    <p class="footer-desc">Bringing pure, safe, and refreshing water to every home and workplace in Cavite since 2015.</p>
                    <div class="footer-social mt-4">
                        <a href="#"><i class="fab fa-facebook-f"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                        <a href="#"><i class="fab fa-tiktok"></i></a>
                    </div>
                </div>

                <div class="col-6 col-lg-2">
                    <div class="footer-heading">Quick Links</div>
                    <div class="footer-links">
                        <a href="#home">Home</a>
                        <a href="#products">Products</a>
                        <a href="#why-us">Why Us</a>
                        <a href="#about">About</a>
                        <a href="#faq">FAQ</a>
                    </div>
                </div>

                <div class="col-6 col-lg-3">
                    <div class="footer-heading">Contact</div>
                    <ul class="list-unstyled footer-contact">
                        <li><i class="fas fa-phone"></i><span>438-6311</span></li>
                        <li><i class="fas fa-map-marker-alt"></i><span>072 Nawasa, Sta. Rosa 1<br>Noveleta, Cavite</span></li>
                        <li><i class="fas fa-clock"></i><span>Mon–Sat: 8AM – 6PM</span></li>
                    </ul>
                </div>

                <div class="col-lg-3">
                    <div class="footer-cta-box">
                        <div class="footer-heading mb-2">Ready to hydrate?</div>
                        <p style="color:rgba(202,240,248,0.5); font-size:0.85rem; margin-bottom:20px;">Join thousands of happy customers today.</p>
                        <button class="btn-primary-hero" style="padding: 12px 28px; font-size:0.8rem;"
                                onclick="window.location.href='<?php echo isset($_SESSION['userID']) ? ($_SESSION['role'] === 'admin' ? 'Admin/admin_dashboard.php' : 'Customer/order.php') : 'login.php'; ?>'">
                            <i class="fas fa-droplet"></i> Start Your Order
                        </button>
                    </div>
                </div>
            </div>

            <hr class="footer-divider">
            <div class="footer-bottom">© 2026 De Chavez Waterhaus · All rights reserved · Designed with ❤️ for Cavite families</div>
        </div>
    </footer>

    <!-- ─── PRODUCT MODAL ─── -->
    <div class="modal fade" id="productModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header px-4 py-3">
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <img id="modalImage" class="img-fluid rounded-3 w-100" style="max-height:360px; object-fit:cover; border:1px solid var(--glass-border);" alt="">
                        </div>
                        <div class="col-md-6 d-flex flex-column justify-content-center">
                            <span class="section-tag d-inline-block mb-3">5 Gallon Container</span>
                            <h3 id="modalName" style="font-family:'Cormorant Garamond',serif; font-size:2rem; color:var(--white);"></h3>
                            <div class="my-3">
                                <span id="modalPrice" style="font-family:'Cormorant Garamond',serif; font-size:2.5rem; color:var(--aqua); font-weight:600;"></span>
                            </div>
                            <p id="modalDesc" style="color:rgba(202,240,248,0.55); font-size:0.9rem; line-height:1.7;"></p>

                            <div class="my-3">
                                <div class="d-flex justify-content-between" style="font-size:0.78rem; color:rgba(202,240,248,0.4); margin-bottom:6px;">
                                    <span>Availability</span>
                                    <span style="color:#4ade80;"><i class="fas fa-check-circle me-1"></i>In Stock · Ready to Deliver</span>
                                </div>
                                <div style="height:4px; background:rgba(202,240,248,0.1); border-radius:2px;">
                                    <div style="width:95%; height:100%; background:linear-gradient(90deg,var(--teal),var(--aqua)); border-radius:2px;"></div>
                                </div>
                            </div>

                            <button class="btn-primary-hero mt-2" style="justify-content:center;" onclick="addCurrentProductToCart()">
                                <i class="fas fa-cart-plus"></i> Add to Cart
                            </button>
                            <div class="text-center mt-3" style="font-size:0.78rem; color:rgba(202,240,248,0.3);">Free delivery on orders above ₱500</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── CART MODAL ─── -->
    <div class="modal fade" id="cartModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header px-4 py-3">
                    <h5 class="modal-title" style="font-family:'Cormorant Garamond',serif; font-size:1.5rem;">
                        <i class="fas fa-shopping-bag me-2" style="color:var(--aqua);"></i> Your Cart
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <div id="cartItems" class="p-4" style="max-height: 420px; overflow-y: auto;"></div>
                    <div class="p-4" style="border-top: 1px solid var(--glass-border); background: rgba(4,30,53,0.5);">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <span style="font-family:'Cormorant Garamond',serif; font-size:1.3rem; color:var(--white);">Total</span>
                            <span id="cartTotal" style="font-family:'Cormorant Garamond',serif; font-size:2rem; color:var(--aqua);">₱0.00</span>
                        </div>
                        <div class="d-grid gap-2">
                            <button class="btn-primary-hero" style="justify-content:center;" onclick="checkout()">
                                <i class="fas fa-check-circle"></i> Proceed to Checkout
                            </button>
                            <button class="btn-ghost-hero" style="justify-content:center; border-radius:50px;" data-bs-dismiss="modal">
                                Continue Shopping
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── TOAST ─── -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:9999;">
        <div id="liveToast" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body fw-semibold" id="toastMessage" style="color:var(--white);"></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ─── PARTICLE CANVAS ───
        (function() {
            const canvas = document.getElementById('particles');
            const ctx = canvas.getContext('2d');
            let particles = [];
            let W, H;

            function resize() {
                W = canvas.width = window.innerWidth;
                H = canvas.height = window.innerHeight;
            }

            function Particle() {
                this.x = Math.random() * W;
                this.y = Math.random() * H;
                this.r = Math.random() * 1.5 + 0.3;
                this.vx = (Math.random() - 0.5) * 0.3;
                this.vy = -(Math.random() * 0.5 + 0.1);
                this.alpha = Math.random() * 0.5 + 0.1;
            }

            function init() {
                resize();
                particles = Array.from({ length: 90 }, () => new Particle());
            }

            function draw() {
                ctx.clearRect(0, 0, W, H);
                particles.forEach(p => {
                    ctx.beginPath();
                    ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
                    ctx.fillStyle = `rgba(72, 202, 228, ${p.alpha})`;
                    ctx.fill();
                    p.x += p.vx;
                    p.y += p.vy;
                    if (p.y < -5) { p.y = H + 5; p.x = Math.random() * W; }
                    if (p.x < 0) p.x = W;
                    if (p.x > W) p.x = 0;
                });
                requestAnimationFrame(draw);
            }

            window.addEventListener('resize', resize);
            init();
            draw();
        })();

        // ─── NAVBAR SCROLL ───
        window.addEventListener('scroll', () => {
            document.getElementById('mainNav').classList.toggle('scrolled', window.scrollY > 50);
        });

        // ─── SCROLL REVEAL ───
        const observer = new IntersectionObserver(entries => {
            entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); } });
        }, { threshold: 0.1 });
        document.querySelectorAll('.reveal').forEach(el => observer.observe(el));

        // ─── CART ───
        let cart = [];

        function loadCart() {
            const saved = localStorage.getItem('waterhausCart');
            if (saved) { cart = JSON.parse(saved); updateCartCount(); }
        }

        function saveCart() {
            localStorage.setItem('waterhausCart', JSON.stringify(cart));
            updateCartCount();
        }

        function updateCartCount() {
            const count = cart.reduce((s, i) => s + i.qty, 0);
            const badge = document.getElementById('cart-count');
            if (badge) badge.textContent = count;
        }

        function showToast(message, type = 'success') {
            const el = document.getElementById('liveToast');
            const body = document.getElementById('toastMessage');
            body.textContent = message;
            el.style.background = type === 'success'
                ? 'linear-gradient(135deg, rgba(10,45,74,0.95), rgba(3,15,30,0.98))'
                : 'linear-gradient(135deg, rgba(74,10,10,0.95), rgba(30,3,3,0.98))';
            el.style.borderColor = type === 'success' ? 'rgba(0,180,216,0.3)' : 'rgba(248,113,113,0.3)';
            new bootstrap.Toast(el, { delay: 2500 }).show();
        }

        function addToCart(product) {
            const i = cart.findIndex(item => item.id == product.id);
            if (i !== -1) cart[i].qty++;
            else cart.push({ ...product, qty: 1 });
            saveCart();
            showToast(`✓ ${product.name} added to cart`, 'success');
        }

        function addToCartFromCard(btn) {
            const card = btn.closest('.product-card');
            addToCart({
                id: card.dataset.id,
                name: card.dataset.name,
                price: parseFloat(card.dataset.price),
                image: card.dataset.image || card.querySelector('img').src
            });
        }

        let currentProduct = null;

        function viewProduct(btn) {
            const card = btn.closest('.product-card');
            currentProduct = {
                id: card.dataset.id,
                name: card.dataset.name,
                price: parseFloat(card.dataset.price),
                image: card.dataset.image || card.querySelector('img').src,
                desc: card.querySelector('.card-text').textContent
            };
            document.getElementById('modalImage').src = currentProduct.image;
            document.getElementById('modalName').textContent = currentProduct.name;
            document.getElementById('modalPrice').textContent = `₱${currentProduct.price.toFixed(2)}`;
            document.getElementById('modalDesc').textContent = currentProduct.desc;
            new bootstrap.Modal(document.getElementById('productModal')).show();
        }

        function addCurrentProductToCart() {
            if (currentProduct) {
                addToCart(currentProduct);
                const m = bootstrap.Modal.getInstance(document.getElementById('productModal'));
                if (m) m.hide();
            }
        }

        function changeCartQty(index, delta) {
            cart[index].qty = Math.max(1, cart[index].qty + delta);
            renderCartItems();
            saveCart();
        }

        function removeFromCart(index) {
            const name = cart[index].name;
            cart.splice(index, 1);
            renderCartItems();
            saveCart();
            showToast(`${name} removed`, 'danger');
        }

        function renderCartItems() {
            const container = document.getElementById('cartItems');
            const totalEl = document.getElementById('cartTotal');
            if (!container || !totalEl) return;

            if (cart.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-5">
                        <i class="fas fa-shopping-bag fa-3x mb-3" style="color:rgba(0,180,216,0.3);"></i>
                        <h5 style="color:rgba(202,240,248,0.4); font-family:'Cormorant Garamond',serif;">Your cart is empty</h5>
                        <p style="color:rgba(202,240,248,0.25); font-size:0.85rem;">Start adding some refreshing water!</p>
                    </div>`;
                totalEl.textContent = '₱0.00';
                return;
            }

            let html = '', total = 0;
            cart.forEach((item, index) => {
                const itemTotal = item.price * item.qty;
                total += itemTotal;
                html += `
                    <div class="d-flex align-items-center mb-4 pb-3" style="border-bottom: 1px solid var(--glass-border);">
                        <img src="${item.image}" class="rounded-3" style="width:65px; height:65px; object-fit:cover; border:1px solid var(--glass-border);">
                        <div class="flex-grow-1 ms-3">
                            <div style="font-family:'Cormorant Garamond',serif; font-size:1.1rem; color:var(--white);">${item.name}</div>
                            <div style="font-size:0.78rem; color:rgba(202,240,248,0.4);">₱${item.price.toFixed(2)} each</div>
                            <div class="d-flex align-items-center mt-2 gap-2">
                                <button class="btn-details" style="padding:4px 12px; font-size:0.8rem;" onclick="changeCartQty(${index}, -1)">−</button>
                                <span style="color:var(--white); min-width:20px; text-align:center;">${item.qty}</span>
                                <button class="btn-details" style="padding:4px 12px; font-size:0.8rem;" onclick="changeCartQty(${index}, 1)">+</button>
                            </div>
                        </div>
                        <div class="text-end">
                            <div style="font-family:'Cormorant Garamond',serif; font-size:1.2rem; color:var(--aqua);">₱${itemTotal.toFixed(2)}</div>
                            <button style="background:none; border:none; color:rgba(248,113,113,0.7); font-size:0.8rem; cursor:pointer; margin-top:6px;" onclick="removeFromCart(${index})">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    </div>`;
            });

            container.innerHTML = html;
            totalEl.textContent = `₱${total.toFixed(2)}`;
        }

        function showCartModal() {
            renderCartItems();
            new bootstrap.Modal(document.getElementById('cartModal')).show();
        }

        function checkout() {
            if (cart.length === 0) return;
            const total = cart.reduce((s, i) => s + (i.price * i.qty), 0);
            const m = bootstrap.Modal.getInstance(document.getElementById('cartModal'));
            if (m) m.hide();
            setTimeout(() => {
                showToast(`🎉 Order placed! Total: ₱${total.toFixed(2)}. We'll contact you for delivery.`, 'success');
                cart = [];
                saveCart();
            }, 600);
        }

        // ─── SEARCH ───
        document.addEventListener('DOMContentLoaded', () => {
            const input = document.getElementById('productSearch');
            if (!input) return;
            input.addEventListener('input', function () {
                const term = this.value.toLowerCase();
                document.querySelectorAll('#products-grid .col-md-6').forEach(col => {
                    const title = col.querySelector('.card-title').textContent.toLowerCase();
                    const desc = col.querySelector('.card-text').textContent.toLowerCase();
                    col.style.display = (!term || title.includes(term) || desc.includes(term)) ? '' : 'none';
                });
            });
            input.addEventListener('keydown', e => {
                if (e.key === 'Escape') {
                    input.value = '';
                    document.querySelectorAll('#products-grid .col-md-6').forEach(c => c.style.display = '');
                }
            });
        });

        // ─── INIT ───
        window.onload = () => {
            loadCart();
            document.addEventListener('keydown', e => {
                if (e.key.toLowerCase() === 'c' && document.activeElement.tagName === 'BODY') {
                    e.preventDefault();
                    showCartModal();
                }
            });
        };

        document.addEventListener('hidden.bs.modal', e => {
            if (e.target.id === 'productModal') currentProduct = null;
        });
    </script>
</body>
</html>