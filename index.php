<?php $body_class = 'page-home';
require_once 'includes/header.php'; ?>

<style>
@import url('https://cdn.jsdelivr.net/npm/swiper@10/swiper-bundle.min.css');

/* ===== HERO (full-page) ===== */
.hero {
    min-height: 100vh;
    min-height: 100dvh;
    height: 100vh;
    height: 100dvh;
    margin-top: 0;
    padding-top: var(--nav-height);
    box-sizing: border-box;
    position: relative;
    overflow: hidden;
    background: #1a1a2e;
}

.hero .swiper {
    width: 100%;
    height: 100%;
}

.hero .swiper-slide {
    background-size: cover;
    background-position: center;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    padding-left: 8%;
    text-align: left;
    color: white;
}

.hero .swiper-slide::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background:
        linear-gradient(to bottom, rgba(0, 0, 0, 0.25) 0%, rgba(0, 0, 0, 0.05) 35%, rgba(0, 0, 0, 0.45) 100%),
        linear-gradient(115deg, rgba(0, 0, 0, 0.55) 0%, rgba(79, 70, 229, 0.18) 45%, transparent 100%);
    z-index: 1;
}

.hero-content {
    max-width: 780px;
    padding: 2rem 0;
    animation: fadeIn 1.5s ease;
    position: relative;
    z-index: 2;
}

.hero h1 {
    font-family: 'Outfit', sans-serif;
    font-size: clamp(2.5rem, 5.5vw, 4rem);
    margin-bottom: 1.25rem;
    color: #fff;
    text-shadow: 0 2px 32px rgba(0, 0, 0, 0.6);
    font-weight: 800;
    letter-spacing: -0.03em;
    line-height: 1.12;
}

.hero h1 .text-gradient {
    background: linear-gradient(135deg, #818cf8, #34d399);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.hero p {
    font-size: clamp(1rem, 2vw, 1.2rem);
    margin-bottom: 2rem;
    color: rgba(255, 255, 255, 0.92);
    line-height: 1.8;
    max-width: 34rem;
    text-shadow: 0 1px 12px rgba(0, 0, 0, 0.4);
}

/* Hero buttons */
.hero-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 0.85rem;
    margin-bottom: 0;
}

.hero-btn {
    padding: 0.8rem 1.6rem;
    font-size: 0.9rem;
    display: inline-block;
    border-radius: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    min-width: 160px;
    text-align: center;
    border: 2px solid transparent;
    text-decoration: none;
}

.hero .btn-primary.hero-btn {
    background: linear-gradient(135deg, #6366f1, #4338ca) !important;
    color: #fff !important;
    box-shadow: 0 8px 28px rgba(99, 102, 241, 0.5);
}

.hero .btn-primary.hero-btn:hover {
    transform: translateY(-3px) scale(1.03);
    box-shadow: 0 12px 36px rgba(99, 102, 241, 0.6);
}

.hero .btn-secondary.hero-btn {
    background: rgba(255, 255, 255, 0.1) !important;
    color: #fff !important;
    border-color: rgba(255, 255, 255, 0.35) !important;
    backdrop-filter: blur(10px);
}

.hero .btn-secondary.hero-btn:hover {
    background: rgba(255, 255, 255, 0.2) !important;
    transform: translateY(-3px);
}

/* Hero stats */
.hero-stats {
    display: flex;
    gap: 2rem;
    margin-top: 2.5rem;
    flex-wrap: wrap;
}

.hero-stat {
    background: rgba(255, 255, 255, 0.08);
    backdrop-filter: blur(12px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 14px;
    padding: 1rem 1.5rem;
    text-align: center;
    min-width: 120px;
    transition: transform 0.3s, background 0.3s;
}

.hero-stat:hover {
    transform: translateY(-4px);
    background: rgba(255, 255, 255, 0.14);
}

.hero-stat__number {
    font-family: 'Outfit', sans-serif;
    font-size: 1.75rem;
    font-weight: 800;
    display: block;
    background: linear-gradient(135deg, #818cf8, #34d399);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.hero-stat__label {
    font-size: 0.78rem;
    color: rgba(255, 255, 255, 0.6);
    text-transform: uppercase;
    letter-spacing: 0.1em;
    font-weight: 600;
    margin-top: 0.2rem;
}

/* Floating shapes */
.hero-shapes {
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    z-index: 1;
    pointer-events: none;
    overflow: hidden;
}

.hero-shape {
    position: absolute;
    border-radius: 50%;
    opacity: 0.08;
}

.hero-shape--1 {
    width: 400px; height: 400px;
    background: radial-gradient(circle, #6366f1, transparent 70%);
    top: -10%; right: -5%;
    animation: float 8s ease-in-out infinite;
}

.hero-shape--2 {
    width: 300px; height: 300px;
    background: radial-gradient(circle, #10b981, transparent 70%);
    bottom: -8%; left: -3%;
    animation: float 10s ease-in-out infinite 2s;
}

.hero-shape--3 {
    width: 200px; height: 200px;
    background: radial-gradient(circle, #f97316, transparent 70%);
    top: 30%; right: 20%;
    animation: float 12s ease-in-out infinite 4s;
}

/* Scroll indicator */
.hero-scroll-indicator {
    position: absolute;
    bottom: 2rem;
    left: 50%;
    transform: translateX(-50%);
    z-index: 3;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.4rem;
    color: rgba(255, 255, 255, 0.5);
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.15em;
    font-weight: 500;
    animation: fadeIn 2s ease 1.5s both;
}

.hero-scroll-indicator__mouse {
    width: 24px; height: 38px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-radius: 12px;
    position: relative;
}

.hero-scroll-indicator__wheel {
    width: 4px; height: 8px;
    background: rgba(255, 255, 255, 0.55);
    border-radius: 2px;
    position: absolute;
    top: 6px; left: 50%;
    transform: translateX(-50%);
    animation: scrollWheel 2s ease-in-out infinite;
}

@keyframes scrollWheel {
    0%, 100% { opacity: 1; top: 6px; }
    50% { opacity: 0.3; top: 18px; }
}

/* Swiper controls */
.swiper-button-next, .swiper-button-prev {
    color: #fff !important;
    text-shadow: 0 2px 8px rgba(0,0,0,0.6);
    transition: transform 0.3s;
}

.swiper-button-next:hover, .swiper-button-prev:hover { transform: scale(1.15); }

.swiper-pagination-bullet {
    background: rgba(255,255,255,0.35) !important;
    opacity: 1 !important;
    width: 10px !important; height: 10px !important;
    transition: all 0.3s !important;
}

.swiper-pagination-bullet-active {
    background: #fff !important;
    box-shadow: 0 0 14px rgba(99, 102, 241, 0.7);
    width: 28px !important; border-radius: 5px !important;
}

/* ===== GEO / MAP SECTION ===== */
.geo-section {
    padding: 5rem 5%;
    background: linear-gradient(180deg, #f0f4ff 0%, #e0e7ff 100%);
    border-top: none;
}

.geo-section__grid {
    max-width: 1200px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: 1fr 1.6fr;
    gap: 2.5rem;
    align-items: start;
}

.geo-card {
    background: #fff;
    border-radius: 20px;
    padding: 2.25rem;
    box-shadow: 0 12px 40px rgba(15, 23, 42, 0.08);
    border: 1px solid rgba(15, 23, 42, 0.04);
}

.geo-card h3 {
    font-family: 'Outfit', sans-serif;
    font-size: 1.35rem;
    margin-bottom: 0.85rem;
    color: var(--text-main);
}

.geo-card p {
    color: var(--text-muted);
    line-height: 1.75;
    margin-bottom: 1.25rem;
    font-size: 0.95rem;
}

.geo-card--map {
    padding: 0;
    overflow: hidden;
}

#live-map {
    width: 100%;
    height: 420px;
    border-radius: 20px;
    z-index: 1;
}

.map-controls {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    padding: 1.25rem 1.5rem;
    border-top: 1px solid rgba(0, 0, 0, 0.06);
}

.map-controls .btn {
    font-size: 0.85rem;
    padding: 0.6rem 1.2rem;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
}

.map-status {
    font-size: 0.82rem;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 0.4rem;
    margin-left: auto;
}

.map-status__dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: #94a3b8;
    display: inline-block;
    transition: background 0.3s;
}

.map-status__dot.active {
    background: var(--secondary-bright);
    box-shadow: 0 0 8px var(--secondary-glow);
    animation: pulse-ring 2s ease-out infinite;
}

.geo-info-row {
    display: flex;
    gap: 1rem;
    margin-top: 1rem;
}

.geo-info-badge {
    flex: 1;
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.08), rgba(16, 185, 129, 0.08));
    border-radius: 12px;
    padding: 0.85rem 1rem;
    text-align: center;
}

.geo-info-badge__value {
    font-family: 'Outfit', sans-serif;
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--primary-color);
    display: block;
}

.geo-info-badge__label {
    font-size: 0.72rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-weight: 600;
    margin-top: 0.15rem;
}

@media (max-width: 768px) {
    .geo-section__grid { grid-template-columns: 1fr; }
    #live-map { height: 300px; }
    .hero-stats { gap: 1rem; }
    .hero-stat { min-width: 100px; padding: 0.8rem 1rem; }
}

/* ===== FEATURES (homepage overrides) ===== */
.features-section {
    padding: 6rem 5%;
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.03), rgba(16, 185, 129, 0.03));
}

.features-grid {
    margin-top: 3rem;
}

.features-section .feature-card {
    position: relative;
    overflow: hidden;
}

/* ===== WHY CHOOSE (homepage) ===== */
.why-choose-section {
    padding: 6rem 5%;
}

</style>

<!-- ===== HERO ===== -->
<div class="hero">
    <div class="hero-shapes" aria-hidden="true">
        <div class="hero-shape hero-shape--1"></div>
        <div class="hero-shape hero-shape--2"></div>
        <div class="hero-shape hero-shape--3"></div>
    </div>

    <div class="swiper myHeroSwiper">
        <div class="swiper-wrapper">
            <!-- Slide 1 -->
            <div class="swiper-slide" style="background-image: url('assets/images/photo1.avif');">
                <div class="hero-content" data-swiper-parallax="-300">
                    <h1>Every Life Deserves a <span class="text-gradient">Second Chance</span></h1>
                    <p>Join RescueNet and be part of an intelligent community saving animals in need. We use AI-driven priority assignment and geolocated rescuer matching to ensure no call goes unanswered.</p>
                    <div class="hero-buttons">
                        <?php if (!isset($_SESSION['user_id'])): ?>
                            <a href="register.php" class="btn btn-primary hero-btn">Join as Rescuer</a>
                        <?php
endif; ?>
                        <a href="user_dashboard.php" class="btn btn-secondary hero-btn">Report a Rescue</a>
                    </div>
                    <div class="hero-stats">
                        <div class="hero-stat">
                            <span class="hero-stat__number">2,500+</span>
                            <span class="hero-stat__label">Rescues</span>
                        </div>
                        <div class="hero-stat">
                            <span class="hero-stat__number">180+</span>
                            <span class="hero-stat__label">Cities</span>
                        </div>
                        <div class="hero-stat">
                            <span class="hero-stat__number">850+</span>
                            <span class="hero-stat__label">Rescuers</span>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Slide 2 -->
            <div class="swiper-slide" style="background-image: url('assets/images/cats.avif');">
                <div class="hero-content" data-swiper-parallax="-300">
                    <h1>Rapid <span class="text-gradient">AI-Powered</span> Response</h1>
                    <p>Upload a photo of an injured animal and let our advanced AI determine the severity and priority of the case instantly. The closest heroes get notified immediately.</p>
                    <div class="hero-buttons">
                        <?php if (!isset($_SESSION['user_id'])): ?>
                            <a href="register.php" class="btn btn-primary hero-btn">Get Started Now</a>
                        <?php
endif; ?>
                        <a href="about.php" class="btn btn-secondary hero-btn">Learn More</a>
                    </div>
                    <div class="hero-stats">
                        <div class="hero-stat">
                            <span class="hero-stat__number">< 3 min</span>
                            <span class="hero-stat__label">Avg Response</span>
                        </div>
                        <div class="hero-stat">
                            <span class="hero-stat__number">95%</span>
                            <span class="hero-stat__label">AI Accuracy</span>
                        </div>
                        <div class="hero-stat">
                            <span class="hero-stat__number">24/7</span>
                            <span class="hero-stat__label">Active</span>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Slide 3 -->
            <div class="swiper-slide" style="background-image: url('assets/images/panda.jpg');
background-position: center right; background-repeat: no-repeat; background-color: #1e293b;"> 
                <div class="hero-content" data-swiper-parallax="-300">
                    <h1>Adopt. Foster. <span class="text-gradient">Love.</span></h1>
                    <p>We use Machine Learning to predict adoption probabilities for every rescued animal, empowering local shelters to optimize their foster networks beautifully.</p>
                    <div class="hero-buttons">
                        <?php if (!isset($_SESSION['user_id'])): ?>
                            <a href="register.php" class="btn btn-primary hero-btn">Become a Hero</a>
                        <?php
endif; ?>
                        <a href="about.php" class="btn btn-secondary hero-btn">Our Mission</a>
                    </div>
                    <div class="hero-stats">
                        <div class="hero-stat">
                            <span class="hero-stat__number">1,200+</span>
                            <span class="hero-stat__label">Adoptions</span>
                        </div>
                        <div class="hero-stat">
                            <span class="hero-stat__number">400+</span>
                            <span class="hero-stat__label">Shelters</span>
                        </div>
                        <div class="hero-stat">
                            <span class="hero-stat__number">98%</span>
                            <span class="hero-stat__label">Success Rate</span>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Slide 4 -->
            <div class="swiper-slide" style="background-image: url('assets/images/bird.avif');">
                <div class="hero-content" data-swiper-parallax="-300">
                    <h1>Protecting <span class="text-gradient">All Species</span></h1>
                    <p>From stray cats and dogs to injured birds and wildlife — our network of trained rescuers is equipped to handle every kind of animal emergency across the country.</p>
                    <div class="hero-buttons">
                        <?php if (!isset($_SESSION['user_id'])): ?>
                            <a href="register.php" class="btn btn-primary hero-btn">Start Reporting</a>
                        <?php
endif; ?>
                        <a href="about.php" class="btn btn-secondary hero-btn">How It Works</a>
                    </div>
                    <div class="hero-stats">
                        <div class="hero-stat">
                            <span class="hero-stat__number">50+</span>
                            <span class="hero-stat__label">Species Helped</span>
                        </div>
                        <div class="hero-stat">
                            <span class="hero-stat__number">3,000+</span>
                            <span class="hero-stat__label">Volunteers</span>
                        </div>
                        <div class="hero-stat">
                            <span class="hero-stat__number">100%</span>
                            <span class="hero-stat__label">Free Service</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="swiper-pagination"></div>
        <div class="swiper-button-next"></div>
        <div class="swiper-button-prev"></div>
    </div>

    <!-- Scroll indicator -->
    <div class="hero-scroll-indicator" aria-hidden="true">
        <div class="hero-scroll-indicator__mouse">
            <div class="hero-scroll-indicator__wheel"></div>
        </div>
        <span>Scroll</span>
    </div>
</div>

<!-- ===== GEOLOCATION & LIVE MAP ===== -->
<section class="geo-section reveal" aria-labelledby="geo-heading">
    <h2 id="geo-heading" class="section-title">Geolocation & Live Tracking</h2>
    <p class="section-subtitle">Real-time GPS tracking connects reporters with the nearest available rescuer — ensuring the fastest possible response.</p>
    <div class="geo-section__grid">
        <!-- Info card -->
        <div class="geo-card">
            <h3><i class="fa-solid fa-satellite-dish" style="color:#6366f1;"></i> How It Works</h3>
            <p>Reporters and rescuers share GPS coordinates so our dispatch logic matches the nearest available rescuer. Enable <strong>live location</strong> to keep your position updated during an active rescue.</p>
            <p><a href="user_dashboard.php" class="btn btn-primary" style="margin-right:0.5rem; font-size:0.85rem;">Reporter Dashboard</a><a href="rescuer_dashboard.php" class="btn btn-secondary" style="font-size:0.85rem;">Rescuer Dashboard</a></p>

            <div class="geo-info-row">
                <div class="geo-info-badge">
                    <span class="geo-info-badge__value" id="geo-lat">—</span>
                    <span class="geo-info-badge__label">Latitude</span>
                </div>
                <div class="geo-info-badge">
                    <span class="geo-info-badge__value" id="geo-lon">—</span>
                    <span class="geo-info-badge__label">Longitude</span>
                </div>
                <div class="geo-info-badge">
                    <span class="geo-info-badge__value" id="geo-acc">—</span>
                    <span class="geo-info-badge__label">Accuracy</span>
                </div>
            </div>
        </div>

        <!-- Map card -->
        <div class="geo-card geo-card--map">
            <div id="live-map"></div>
            <div class="map-controls">
                <button type="button" class="btn btn-primary" id="map-locate-btn">
                    <i class="fa-solid fa-location-crosshairs"></i> Show My Position
                </button>
                <button type="button" class="btn btn-secondary" id="map-track-btn">
                    <i class="fa-solid fa-satellite-dish"></i> Start Live Tracking
                </button>
                <div class="map-status">
                    <span class="map-status__dot" id="map-status-dot"></span>
                    <span id="map-status-text">Inactive</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ===== KEY FEATURES ===== -->
<section class="features-section reveal">
    <h2 class="section-title">Key Features</h2>
    <p class="section-subtitle">A comprehensive toolkit for rapid animal rescue — from reporting to adoption.</p>
    <div class="features-grid">
        <div class="feature-card">
            <div class="feature-icon"><i class="fa-solid fa-image"></i></div>
            <h3>Easy Animal Reporting</h3>
            <p>Upload images, add descriptions, and pinpoint locations with simple, intuitive reporting tools.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon"><i class="fa-solid fa-brain"></i></div>
            <h3>AI-Based Injury Detection</h3>
            <p>Intelligent algorithms analyze images to assess injury severity and assign priority levels automatically.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon"><i class="fa-solid fa-location-dot"></i></div>
            <h3>Nearest Rescuer Matching</h3>
            <p>Smart algorithms identify and connect the closest available rescuer to minimize response time.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon"><i class="fa-solid fa-chart-line"></i></div>
            <h3>Adoption Rate Prediction</h3>
            <p>Machine learning predicts adoption potential to improve visibility and care for rescued animals.</p>
        </div>
    </div>
</section>

<!-- ===== WHY CHOOSE US ===== -->
<section class="why-choose-section reveal">
    <h2 class="section-title">Why Choose Our System?</h2>
    <p class="section-subtitle">Built with care, powered by technology, driven by community.</p>
    <div class="why-choose-content">
        <div class="why-choose-card">
            <div class="why-icon"><i class="fa-solid fa-heart"></i></div>
            <h3>Technology Meets Compassion</h3>
            <p>We combine cutting-edge technology with genuine care for animal welfare, ensuring every rescue is handled with urgency and kindness.</p>
        </div>
        <div class="why-choose-card">
            <div class="why-icon"><i class="fa-solid fa-bolt"></i></div>
            <h3>Fast & Efficient</h3>
            <p>Our intelligent dispatch system ensures critical cases receive immediate attention, reducing response times and improving rescue outcomes.</p>
        </div>
        <div class="why-choose-card">
            <div class="why-icon"><i class="fa-solid fa-circle-check"></i></div>
            <h3>Smart Management</h3>
            <p>From reporting to adoption, our AI-powered system efficiently manages every step of the rescue process with transparency.</p>
        </div>
        <div class="why-choose-card">
            <div class="why-icon"><i class="fa-solid fa-users"></i></div>
            <h3>Community-Driven</h3>
            <p>A unified platform connecting compassionate citizens, dedicated rescuers, and administrators working together for animal welfare.</p>
        </div>
    </div>
</section>

<!-- ===== SCRIPTS ===== -->
<script src="https://cdn.jsdelivr.net/npm/swiper@10/swiper-bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {

    /* ---------- Swiper ---------- */
    var swiper = new Swiper(".myHeroSwiper", {
        speed: 1200,
        parallax: true,
        loop: true,
        effect: "fade",
        fadeEffect: { crossFade: true },
        autoplay: { delay: 5500, disableOnInteraction: false },
        navigation: { nextEl: ".swiper-button-next", prevEl: ".swiper-button-prev" },
        pagination: { el: ".swiper-pagination", clickable: true }
    });

    /* ---------- Leaflet map ---------- */
    var map = L.map("live-map", { zoomControl: true, scrollWheelZoom: false }).setView([27.7172, 85.3240], 13);

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a>'
    }).addTo(map);

    var userMarker = null;
    var accuracyCircle = null;
    var watchId = null;
    var isTracking = false;

    var userIcon = L.divIcon({
        className: "user-live-marker",
        html: '<div style="width:18px;height:18px;background:linear-gradient(135deg,#6366f1,#4338ca);border-radius:50%;border:3px solid #fff;box-shadow:0 0 14px rgba(99,102,241,0.6),0 0 30px rgba(99,102,241,0.3);"></div>' +
              '<div style="position:absolute;top:-3px;left:-3px;width:24px;height:24px;border-radius:50%;border:2px solid rgba(99,102,241,0.5);animation:pulse-ring 2s ease-out infinite;"></div>',
        iconSize: [18, 18],
        iconAnchor: [9, 9]
    });

    var rescuerIcon = L.divIcon({
        className: "rescuer-marker",
        html: '<div style="width:14px;height:14px;background:linear-gradient(135deg,#10b981,#059669);border-radius:50%;border:2.5px solid #fff;box-shadow:0 0 10px rgba(16,185,129,0.5);"></div>',
        iconSize: [14, 14],
        iconAnchor: [7, 7]
    });

    function updatePosition(pos) {
        var lat = pos.coords.latitude;
        var lon = pos.coords.longitude;
        var acc = Math.round(pos.coords.accuracy);

        document.getElementById("geo-lat").textContent = lat.toFixed(6);
        document.getElementById("geo-lon").textContent = lon.toFixed(6);
        document.getElementById("geo-acc").textContent = "~" + acc + " m";

        var latlng = [lat, lon];

        if (userMarker) {
            userMarker.setLatLng(latlng);
        } else {
            userMarker = L.marker(latlng, { icon: userIcon }).addTo(map).bindPopup("<strong>Your Location</strong>");
        }

        if (accuracyCircle) {
            accuracyCircle.setLatLng(latlng).setRadius(acc);
        } else {
            accuracyCircle = L.circle(latlng, {
                radius: acc,
                color: "rgba(99, 102, 241, 0.3)",
                fillColor: "rgba(99, 102, 241, 0.08)",
                fillOpacity: 0.5,
                weight: 1
            }).addTo(map);
        }

        map.setView(latlng, Math.max(map.getZoom(), 14));

        /* Fetch nearby rescuers */
        fetchNearbyRescuers(lat, lon);
    }

    /* Show my position (one-shot) */
    document.getElementById("map-locate-btn").addEventListener("click", function () {
        if (!navigator.geolocation) { alert("Geolocation is not supported."); return; }
        navigator.geolocation.getCurrentPosition(
            updatePosition,
            function () { alert("Could not read location. Check browser permissions."); },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
        );
    });

    /* Live tracking toggle */
    var trackBtn = document.getElementById("map-track-btn");
    var statusDot = document.getElementById("map-status-dot");
    var statusText = document.getElementById("map-status-text");

    trackBtn.addEventListener("click", function () {
        if (isTracking) {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
            isTracking = false;
            trackBtn.innerHTML = '<i class="fa-solid fa-satellite-dish"></i> Start Live Tracking';
            statusDot.classList.remove("active");
            statusText.textContent = "Inactive";
        } else {
            if (!navigator.geolocation) { alert("Geolocation is not supported."); return; }
            watchId = navigator.geolocation.watchPosition(
                function (pos) {
                    updatePosition(pos);
                    statusDot.classList.add("active");
                    statusText.textContent = "Tracking";
                },
                function () {
                    statusDot.classList.remove("active");
                    statusText.textContent = "Error";
                },
                { enableHighAccuracy: true, maximumAge: 8000, timeout: 20000 }
            );
            isTracking = true;
            trackBtn.innerHTML = '<i class="fa-solid fa-stop"></i> Stop Tracking';
            statusText.textContent = "Locating…";
        }
    });

    /* Fetch nearby rescuers */
    var rescuerMarkers = [];
    function fetchNearbyRescuers(lat, lon) {
        fetch("backend/get_nearby_rescuers.php?lat=" + lat + "&lon=" + lon + "&radius=25")
            .then(function (r) { return r.json(); })
            .then(function (data) {
                /* Clear old markers */
                rescuerMarkers.forEach(function (m) { map.removeLayer(m); });
                rescuerMarkers = [];
                if (data.ok && data.rescuers) {
                    data.rescuers.forEach(function (r) {
                        var m = L.marker([r.latitude, r.longitude], { icon: rescuerIcon })
                            .addTo(map)
                            .bindPopup("<strong>" + r.name + "</strong><br>Rescuer — " + r.distance_km.toFixed(1) + " km away");
                        rescuerMarkers.push(m);
                    });
                }
            })
            .catch(function () { /* silently fail */ });
    }

    /* Fix Leaflet tile rendering after scroll reveal */
    setTimeout(function () { map.invalidateSize(); }, 800);
    window.addEventListener("scroll", function () {
        var mapEl = document.getElementById("live-map");
        if (mapEl && mapEl.getBoundingClientRect().top < window.innerHeight) {
            map.invalidateSize();
        }
    }, { passive: true });
});
</script>

<?php require_once 'includes/footer.php'; ?>
