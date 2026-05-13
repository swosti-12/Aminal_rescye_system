<?php
require_once 'includes/header.php';
require_once 'backend/site_settings_helper.php';
$c_addr = get_site_setting($pdo, 'contact_address', 'Rescue Avenue, Kalanki, 44600');
$c_email = get_site_setting($pdo, 'contact_email', 'support@rescuenet.org');
$c_phone = get_site_setting($pdo, 'contact_phone', '+977 9860345678');
?>

<div class="container" style="max-width: 900px; padding: 4rem 2rem;">
    <h1 class="section-title" style="margin-bottom: 2rem;">Contact Us</h1>
    <div class="glass-panel" style="padding: 3rem; display: grid; grid-template-columns: 1fr 1fr; gap: 3rem;">
        
        <div>
            <h2 style="margin-bottom: 1.5rem; color: var(--primary-color);">Get in Touch</h2>
           
            
            <div style="margin-bottom: 1rem; display: flex; gap: 1rem;">
                <i class="fa-solid fa-location-dot text-primary" style="margin-top: 0.3rem;"></i>
                <span><?php echo htmlspecialchars($c_addr); ?></span>
            </div>
            <div style="margin-bottom: 1rem; display: flex; gap: 1rem;">
                <i class="fa-solid fa-envelope text-primary" style="margin-top: 0.3rem;"></i>
                <span><?php echo htmlspecialchars($c_email); ?></span>
            </div>
            <div style="display: flex; gap: 1rem;">
                <i class="fa-solid fa-phone text-primary" style="margin-top: 0.3rem;"></i>
                <span><?php echo htmlspecialchars($c_phone); ?></span>
            </div>
        </div>
        
        <form action="" method="POST" onsubmit="event.preventDefault(); Swal.fire({icon:'success',title:'Message Sent!',text:'Thank you for contacting us. We will get back to you shortly.',confirmButtonColor:'#4F46E5'}); this.reset();">
            <div class="form-group">
                <label>Name</label>
                <input type="text" class="form-control" placeholder="" required>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" class="form-control" placeholder="" required>
            </div>
            <div class="form-group">
                <label>Message</label>
                <textarea class="form-control" rows="5" placeholder="How can we help?" required></textarea>
            </div>
            <button class="btn btn-primary" style="width: 100%;">Send Message</button>
        </form>
        
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
