    <!-- Animated gradient divider -->
    <div class="footer-gradient-divider" aria-hidden="true"></div>

    <footer class="site-footer">
        <div class="site-footer__inner">
            <!-- Brand -->
            <div class="site-footer__brand">
                <a href="index.php" class="site-footer__logo"><i class="fa-solid fa-paw"></i> RescueNet</a>
                <p class="site-footer__tagline">Connecting compassionate citizens with trained rescuers through AI-assisted dispatch and live geolocation. Every life matters.</p>
                <div class="site-footer__social">
                    <a href="#" class="site-footer__social-link" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
                    <a href="#" class="site-footer__social-link" aria-label="X"><i class="fa-brands fa-x-twitter"></i></a>
                    <a href="#" class="site-footer__social-link" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
                    <a href="#" class="site-footer__social-link" aria-label="GitHub"><i class="fa-brands fa-github"></i></a>
                </div>
            </div>

            <!-- Explore -->
            <div class="site-footer__col">
                <h3 class="site-footer__heading">Explore</h3>
                <ul class="site-footer__list">
                    <li><a href="index.php">Home</a></li>
                    <li><a href="about.php">About Us</a></li>
                    <li><a href="contact.php">Contact</a></li>
                </ul>
            </div>

            <!-- Account -->
            <div class="site-footer__col">
                <h3 class="site-footer__heading">Account</h3>
                <ul class="site-footer__list">
                    <li><a href="login.php">Login</a></li>
                    <li><a href="register.php">Register</a></li>
                    <?php
                    if (!class_exists('SessionManager', false)) {
                        require_once __DIR__ . '/../backend/SessionManager.php';
                        SessionManager::bootstrap();
                    }
                    $footerSlots = SessionManager::getActiveRoles();
                    ?>
                    <?php if ($footerSlots !== []): ?>
                        <?php foreach ($footerSlots as $footerRole): ?>
                            <li>
                                <a href="<?php
                                    echo $footerRole === 'admin' ? 'admin_dashboard.php' : ($footerRole === 'rescuer' ? 'rescuer_dashboard.php' : 'user_dashboard.php');
                                ?>"><?php echo htmlspecialchars(SessionManager::getSessionLabel($footerRole)); ?> Dashboard</a>
                            </li>
                        <?php endforeach; ?>
                        <li><a href="logout.php?role=all">Logout all roles</a></li>
                    <?php elseif (isset($_SESSION['user_id'])): ?>
                        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                            <li><a href="admin_dashboard.php">Admin Dashboard</a></li>
                        <?php elseif (($_SESSION['role'] ?? '') === 'rescuer'): ?>
                            <li><a href="rescuer_dashboard.php">Rescuer Dashboard</a></li>
                        <?php else: ?>
                            <li><a href="user_dashboard.php">User Dashboard</a></li>
                        <?php endif; ?>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Resources -->
            <div class="site-footer__col">
                <h3 class="site-footer__heading">Resources</h3>
                <ul class="site-footer__list">
                    <li><a href="#">FAQ</a></li>
                    <li><a href="#">Privacy Policy</a></li>
                    <li><a href="#">Terms of Service</a></li>
                    <li><a href="#">API Documentation</a></li>
                </ul>
            </div>

            <!-- Newsletter -->
            <div class="site-footer__col footer-newsletter">
                <h3 class="site-footer__heading">Stay Updated</h3>
                <p style="font-size:0.88rem; color:rgba(248,250,252,0.6); line-height:1.65; margin-bottom:0.75rem;">Get the latest rescue stories and platform updates delivered to your inbox.</p>
                <div class="footer-newsletter__input-group">
                    <input type="email" class="footer-newsletter__input" placeholder="Your email address" aria-label="Email for newsletter">
                    <button type="button" class="footer-newsletter__btn"><i class="fa-solid fa-paper-plane"></i></button>
                </div>
            </div>
        </div>

        <div class="site-footer__bottom">
            <p>&copy; <?php echo date('Y'); ?> RescueNet. All rights reserved.</p>
            <div class="site-footer__bottom-links">
                <a href="#">Privacy</a>
                <a href="#">Terms</a>
                <a href="#">Cookies</a>
            </div>
        </div>
    </footer>

    <!-- Back to top -->
    <button type="button" class="back-to-top" id="back-to-top" aria-label="Back to top">
        <i class="fa-solid fa-arrow-up"></i>
    </button>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/ars-session.js"></script>
    <script src="assets/js/main.js"></script>
    <?php if (isset($extra_scripts) && is_array($extra_scripts) && $extra_scripts !== []): ?>
        <?php foreach ($extra_scripts as $_js_src): ?>
            <script src="<?php echo htmlspecialchars((string) $_js_src, ENT_QUOTES, 'UTF-8'); ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
