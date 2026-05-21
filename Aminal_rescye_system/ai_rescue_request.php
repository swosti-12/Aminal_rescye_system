<?php
require_once 'backend/auth.php';
require_login();
require_role('user');
require_once 'includes/header.php';
?>

<div class="container" style="max-width: 760px; margin: 110px auto 40px;">
    <div class="glass-panel" style="padding: 2rem;">
        <h2 style="margin-bottom: 1rem;">AI Rescue Request</h2>
        <p style="color:#6b7280; margin-bottom: 1.5rem;">
            Upload an animal image, add a short description and location. The AI service will automatically accept/reject the case.
        </p>

        <form id="ai-rescue-form" enctype="multipart/form-data">
            <div class="form-group">
                <label for="description">Description</label>
                <textarea class="form-control" id="description" name="description" rows="4" required></textarea>
            </div>

            <div class="form-group">
                <label for="location">Location</label>
                <input class="form-control" id="location" name="location" placeholder="e.g. Baneshwor, Kathmandu" required>
            </div>

            <div class="form-group">
                <label for="image">Animal Image</label>
                <input class="form-control" id="image" name="image" type="file" accept="image/png,image/jpeg,image/webp" required>
                <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;margin-top:0.5rem;">
                    <button type="button" class="btn btn-secondary" id="ai-open-camera" aria-label="Open camera"><i class="fa-solid fa-camera" aria-hidden="true"></i> Take photo</button>
                    <small style="color:#6b7280;">Allowed: JPG, PNG, WEBP | Max: 5MB. On phones use Take photo or Choose file → Camera.</small>
                </div>
                <input type="file" id="ai-camera-only" style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;" accept="image/*" capture="environment" tabindex="-1" aria-hidden="true">
            </div>

            <button class="btn btn-primary" type="submit">Submit AI Request</button>
        </form>

        <div id="result-box" style="margin-top:1.5rem; display:none;"></div>
    </div>
</div>

<script>
(function () {
    var main = document.getElementById('image');
    var camBtn = document.getElementById('ai-open-camera');
    var cam = document.getElementById('ai-camera-only');
    if (camBtn && cam && main) {
        camBtn.addEventListener('click', function () { cam.click(); });
        cam.addEventListener('change', function () {
            var f = cam.files && cam.files[0];
            if (f) {
                var dt = new DataTransfer();
                dt.items.add(f);
                main.files = dt.files;
                main.dispatchEvent(new Event('change', { bubbles: true }));
            }
            cam.value = '';
        });
    }
})();
document.getElementById('ai-rescue-form').addEventListener('submit', async function (e) {
    e.preventDefault();

    const resultBox = document.getElementById('result-box');
    resultBox.style.display = 'block';
    resultBox.innerHTML = 'Submitting request...';

    try {
        const formData = new FormData(e.target);
        const response = await fetch('backend/submit_rescue_request_ai.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (!data.ok) {
            resultBox.innerHTML = '<div style="color:#b91c1c;">' + (data.message || 'Request failed') + '</div>';
            return;
        }

        resultBox.innerHTML = `
            <div style="padding:1rem; border:1px solid #d1d5db; border-radius:10px;">
                <p><strong>Request ID:</strong> ${data.request_id}</p>
                <p><strong>AI Result:</strong> ${data.ai.result}</p>
                <p><strong>Confidence:</strong> ${(Number(data.ai.confidence) * 100).toFixed(2)}%</p>
                <p><strong>Status:</strong> ${data.decision.status}</p>
                <p><strong>Priority:</strong> ${data.decision.priority}</p>
                <p><strong>Rescuer Assigned:</strong> ${data.rescuer_assigned ? 'Yes' : 'No'}</p>
            </div>
        `;

        e.target.reset();
    } catch (err) {
        resultBox.innerHTML = '<div style="color:#b91c1c;">Network error while submitting request.</div>';
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
