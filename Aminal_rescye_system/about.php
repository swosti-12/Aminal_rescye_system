<?php
require_once 'includes/header.php';
require_once 'backend/site_settings_helper.php';
$about_intro_text = get_site_setting(
    $pdo,
    'about_intro',
    'The Animal Rescue System is a web-based platform designed to connect compassionate individuals, dedicated rescuers, and administrators to work together for the welfare of injured and stray animals.'
);
?>

<!-- Hero Section -->
<div class="hero" >
    <div class="hero-content">
        <h1 style="font-size: 3.5rem; color: #200864; text-align:center; margin-bottom: 1rem;">ABOUT US</h1>
        <p style="font-size: 1.1rem; color: #4b5563; text-align: justify; max-width: 800px;"><?php echo nl2br(htmlspecialchars($about_intro_text)); ?></p>
    </div>
</div>

<!-- Main Content -->
<div class="about-container">
    
    <!-- Vision & Mission Section -->
    <section class="vision-mission-section">
        <div class="vision-card">
            <div class="card-icon">
                <i class="fa-solid fa-star"></i>
            </div>
            <h2>Our Vision</h2>
            <p>To create a smart and responsive digital ecosystem where every injured or stray animal can receive timely help and a better chance at life through technology-driven solutions.</p>
        </div>

        <div class="mission-card">
            <div class="card-icon">
                <i class="fa-solid fa-bullseye"></i>
            </div>
            <h2>Our Mission</h2>
            <ul>
                <li>To simplify the process of reporting and rescuing animals</li>
                <li>To use AI for faster and more accurate decision-making</li>
                <li>To connect users, rescuers, and administrators efficiently</li>
                <li>To improve adoption rates through predictive analysis</li>
            </ul>
        </div>
    </section>

    <!-- Key Features Section -->
    <section class="features-section">
        <h2 class="section-title">Key Features</h2>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fa-solid fa-image"></i>
                </div>
                <h3>Easy Animal Reporting</h3>
                <p>Upload images, add descriptions, and pinpoint locations with simple, intuitive reporting tools.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fa-solid fa-brain"></i>
                </div>
                <h3>AI-Based animal Detection</h3>
                <p>Intelligent algorithms analyze images to assess injury severity and assign priority levels automatically.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fa-solid fa-location-dot"></i>
                </div>
                <h3>Nearest Rescuer Identification</h3>
                <p>Smart algorithms identify and connect the closest available rescuer to minimize response time.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fa-solid fa-radar"></i>
                </div>
                <h3>Real-Time Rescue Tracking</h3>
                <p>Monitor rescue operations in real-time with live updates and comprehensive status tracking.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fa-solid fa-chart-line"></i>
                </div>
                <h3>Adoption Rate Prediction</h3>
                <p>Machine learning predicts adoption potential to improve visibility and care for rescued animals.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fa-solid fa-shield"></i>
                </div>
                <h3>Verified Network</h3>
                <p>Trusted rescuers and administrators working together in a secure, verified platform.</p>
            </div>
        </div>
    </section>

    <!-- Why Choose Us Section -->
    <section class="why-choose-section">
        <h2 class="section-title">Why Choose Our System?</h2>
        <div class="why-choose-content">
            <div class="why-choose-card">
                <div class="why-icon">
                    <i class="fa-solid fa-heart"></i>
                </div>
                <h3>Technology Meets Compassion</h3>
                <p>We combine cutting-edge technology with genuine care for animal welfare, ensuring every rescue is handled with the urgency and kindness it deserves.</p>
            </div>

            <div class="why-choose-card">
                <div class="why-icon">
                    <i class="fa-solid fa-zap"></i>
                </div>
                <h3>Fast & Efficient</h3>
                <p>Our intelligent dispatch system ensures critical cases receive immediate attention, reducing response times and improving rescue outcomes.</p>
            </div>

            <div class="why-choose-card">
                <div class="why-icon">
                    <i class="fa-solid fa-check-circle"></i>
                </div>
                <h3>Smart Management</h3>
                <p>From reporting to adoption, our AI-powered system efficiently manages every step of the rescue process with transparency and accuracy.</p>
            </div>

            <div class="why-choose-card">
                <div class="why-icon">
                    <i class="fa-solid fa-users"></i>
                </div>
                <h3>Community-Driven</h3>
                <p>A unified platform connecting compassionate citizens, dedicated rescuers, and administrators working together for animal welfare.</p>
            </div>
        </div>
    </section>

    <!-- How It Works Section -->
    <section class="how-it-works-section">
        <h2 class="section-title">How It Works</h2>
        <div class="steps-container">
            <div class="step-card">
                <div class="step-number">1</div>
                <h3>Report an Animal</h3>
                <p>Upload an image, add a description and location of the injured or stray animal you've found.</p>
            </div>

            <div class="step-arrow"><i class="fa-solid fa-arrow-right"></i></div>

            <div class="step-card">
                <div class="step-number">2</div>
                <h3>AI Analysis</h3>
                <p>Our AI analyzes the image to assess injury severity and automatically assign priority levels.</p>
            </div>

            <div class="step-arrow"><i class="fa-solid fa-arrow-right"></i></div>

            <div class="step-card">
                <div class="step-number">3</div>
                <h3>Dispatch Rescuer</h3>
                <p>The nearest available rescuer is instantly notified and dispatched to the location.</p>
            </div>

            <div class="step-arrow"><i class="fa-solid fa-arrow-right"></i></div>

            <div class="step-card">
                <div class="step-number">4</div>
                <h3>Track & Rehabilitate</h3>
                <p>Monitor the rescue in real-time and track the animal's journey through rehabilitation to adoption.</p>
            </div>
        </div>
    </section>

</div>

<?php require_once 'includes/footer.php'; ?>
