<?php require_once 'includes/header.php'; ?>

<style>
.analyze-container {
    max-width: 900px;
    margin: 120px auto 60px;
    background: white;
    padding: 2rem;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.analyze-container h2 {
    text-align: center;
    margin-bottom: 1.5rem;
}

.upload-box {
    border: 2px dashed #4F46E5;
    padding: 2rem;
    text-align: center;
    border-radius: 10px;
    cursor: pointer;
    transition: 0.3s;
}

.upload-box:hover {
    background: rgba(79,70,229,0.05);
}

.upload-box input {
    display: none;
}

.preview {
    margin-top: 1rem;
    text-align: center;
}

.preview img {
    max-width: 300px;
    border-radius: 10px;
}

.btn-analyze {
    display: block;
    margin: 20px auto;
    padding: 10px 25px;
    background: #4F46E5;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
}

.result-box {
    margin-top: 2rem;
    padding: 1.5rem;
    background: #f9fafb;
    border-radius: 10px;
    display: none;
}

.result-item {
    margin-bottom: 10px;
    font-size: 1.1rem;
}

.loader {
    text-align: center;
    display: none;
}
</style>

<div class="analyze-container">
    <h2>🐾 AI Animal Analysis</h2>

    <!-- Upload -->
    <label class="upload-box">
        <p>Click to upload animal image</p>
        <input type="file" id="imageInput" accept="image/*">
    </label>

    <!-- Preview -->
    <div class="preview" id="preview"></div>

    <!-- Button -->
    <button class="btn-analyze" onclick="analyzeImage()">Analyze Image</button>

    <!-- Loader -->
    <div class="loader" id="loader">Analyzing... ⏳</div>

    <!-- Result -->
    <div class="result-box" id="resultBox">
        <div class="result-item"><strong>Animal:</strong> <span id="animal"></span></div>
        <div class="result-item"><strong>Confidence:</strong> <span id="confidence"></span>%</div>
        <div class="result-item"><strong>Injury Severity:</strong> <span id="injury"></span></div>
        <div class="result-item"><strong>Injury Confidence:</strong> <span id="injury_conf"></span>%</div>
    </div>
</div>

<script>
let selectedFile = null;

// Preview image
document.getElementById("imageInput").addEventListener("change", function(e) {
    selectedFile = e.target.files[0];

    const preview = document.getElementById("preview");
    preview.innerHTML = "";

    const img = document.createElement("img");
    img.src = URL.createObjectURL(selectedFile);
    preview.appendChild(img);
});

// Call Flask API
function analyzeImage() {
    if (!selectedFile) {
        alert("Please select an image first!");
        return;
    }

    const formData = new FormData();
    formData.append("image", selectedFile);

    document.getElementById("loader").style.display = "block";
    document.getElementById("resultBox").style.display = "none";

    fetch("http://127.0.0.1:5000/analyze", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        document.getElementById("loader").style.display = "none";
        document.getElementById("resultBox").style.display = "block";

        document.getElementById("animal").innerText = data.animal_type;
        document.getElementById("confidence").innerText = data.confidence;
        document.getElementById("injury").innerText = data.injury_severity;
        document.getElementById("injury_conf").innerText = data.injury_confidence;
    })
    .catch(err => {
        document.getElementById("loader").style.display = "none";
        alert("Error analyzing image");
        console.error(err);
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>