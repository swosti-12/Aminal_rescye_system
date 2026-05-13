"""
PawAlert — Flask ML API
========================
Endpoints:
  POST /analyze              → YOLOv8 animal detection + OpenCV injury cues
  POST /api/v1/animal-check  → Animal gate (YOLO), requires X-API-KEY
  POST /api/v1/injury-check  → Injury heuristic (OpenCV), requires X-API-KEY
  POST /predict-adoption     → Adoption probability prediction (Decision Tree)
  GET  /health               → API health check

Core deps: flask pillow scikit-learn numpy joblib
Image AI deps: opencv-python-headless ultralytics torch (see requirements-ai.txt)
"""

import os
import logging
import math
from flask import Flask, request, jsonify
from flask_cors import CORS
import numpy as np
from sklearn.tree import DecisionTreeClassifier
from sklearn.preprocessing import LabelEncoder
import joblib

app = Flask(__name__)
CORS(app)  # Enable CORS for all routes
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# ============================================================
# CONFIG
# ============================================================
MODEL_PATH    = "models/adoption_model.pkl"
ENCODER_PATH  = "models/label_encoder.pkl"
MAX_FILE_SIZE = 10 * 1024 * 1024   # 10 MB
ALLOWED_EXTS  = {'jpg', 'jpeg', 'png', 'webp'}
API_KEY       = os.environ.get("ANIMAL_RESCUE_API_KEY", "rescue-local-key-2026")

# Ensure artifact directories exist (annotated outputs, optional uploads)
for _d in ("uploads", "outputs", "models"):
    os.makedirs(_d, exist_ok=True)

try:
    from utils.pipeline import run_pipeline
    from utils.preprocessing import prepare_bgr_for_inference
    from utils.animal_detector import detect_animals
    from utils.injury_detector import detect_injury_opencv, injury_confidence_for_api
    from utils.config import PIPELINE_IMAGE_SIZE, GAUSSIAN_KERNEL

    _IMAGE_AI_AVAILABLE = True
except ImportError as _e:
    _IMAGE_AI_AVAILABLE = False
    _IMAGE_AI_IMPORT_ERROR = str(_e)
else:
    _IMAGE_AI_IMPORT_ERROR = ""

# ============================================================
# TRAIN / LOAD ADOPTION MODEL
# ============================================================
def train_adoption_model() -> tuple:
    """
    Train a Decision Tree on synthetic adoption dataset.
    Features:
        age_years       (float)  — estimated age
        gender_code     (int)    — 0=unknown,1=male,2=female
        health_score    (int)    — 1-10
        injury_score    (int)    — 1-5 (healed severity)
        breed_popularity(int)    — 1-5
        location_demand (int)    — 1-5

    Target: adoption_category (low=0, medium=1, high=2)
    """
    np.random.seed(42)
    n = 500

    # Synthetic training data
    age           = np.random.uniform(0.5, 12, n)
    gender        = np.random.randint(0, 3, n)
    health        = np.random.randint(1, 11, n)
    injury        = np.random.randint(1, 6, n)
    breed_pop     = np.random.randint(1, 6, n)
    loc_demand    = np.random.randint(1, 6, n)

    X = np.column_stack([age, gender, health, injury, breed_pop, loc_demand])

    # Rule-based ground truth for training
    score = (
        (10 - np.clip(age, 0, 10)) * 3          +   # younger = higher adoption
        health * 4                                +   # better health = higher
        (6 - injury) * 3                          +   # less injury history = higher
        breed_pop * 2                             +
        loc_demand * 1
    )
    score = (score - score.min()) / (score.max() - score.min()) * 100
    noise = np.random.normal(0, 5, n)
    score = np.clip(score + noise, 0, 100)

    y = np.where(score >= 65, 2, np.where(score >= 35, 1, 0))  # high/medium/low

    model = DecisionTreeClassifier(
        max_depth=6,
        min_samples_split=10,
        min_samples_leaf=5,
        random_state=42
    )
    model.fit(X, y)

    le = LabelEncoder()
    le.fit(['low', 'medium', 'high'])

    os.makedirs("models", exist_ok=True)
    joblib.dump(model, MODEL_PATH)
    joblib.dump(le, ENCODER_PATH)
    logger.info("Adoption model trained and saved.")
    return model, le


@app.route("/")
def home():
    return "PawAlert API is running 🚀"

def load_or_train_model() -> tuple:
    if os.path.exists(MODEL_PATH) and os.path.exists(ENCODER_PATH):
        try:
            return joblib.load(MODEL_PATH), joblib.load(ENCODER_PATH)
        except Exception:
            pass
    return train_adoption_model()


adoption_model, label_encoder = load_or_train_model()


# ============================================================
# IMAGE ANALYSIS — implemented in utils/ (YOLOv8 + OpenCV)
# ============================================================


def is_api_key_valid(req) -> bool:
    token = req.headers.get("X-API-KEY", "")
    return bool(token) and token == API_KEY


# ============================================================
# ROUTE: /analyze  — image analysis
# ============================================================
@app.route("/analyze", methods=["POST"])
def analyze():
    """
    Public demo endpoint (no API key). Same pipeline as internal checks.
    Query: ?save_viz=1 → write annotated image to outputs/
    """
    if not _IMAGE_AI_AVAILABLE:
        return jsonify({
            "error": "Image AI module not available",
            "detail": _IMAGE_AI_IMPORT_ERROR or "Install requirements-ai.txt",
        }), 503

    if "image" not in request.files:
        return jsonify({"error": "No image file provided"}), 400

    file = request.files["image"]
    if file.filename == "":
        return jsonify({"error": "Empty filename"}), 400

    ext = file.filename.rsplit(".", 1)[-1].lower()
    if ext not in ALLOWED_EXTS:
        return jsonify({"error": f"Unsupported format. Use: {sorted(ALLOWED_EXTS)}"}), 400

    try:
        img_bytes = file.read()
        if len(img_bytes) > MAX_FILE_SIZE:
            return jsonify({"error": "File too large (max 10MB)"}), 400

        save_viz = request.args.get("save_viz", "0") in ("1", "true", "yes")
        result = run_pipeline(img_bytes, save_visualization=save_viz)
        logger.info(
            "Analyzed image → %s %s status=%s",
            result.get("animal_type"),
            result.get("confidence"),
            result.get("status"),
        )
        return jsonify(result), 200

    except ValueError as ve:
        logger.warning("Image decode failed: %s", ve)
        return jsonify({"error": "Invalid image data", "detail": str(ve)}), 400
    except Exception as e:
        logger.exception("Image analysis error: %s", e)
        return jsonify({"error": "Image processing failed", "detail": str(e)}), 500


@app.route("/api/v1/animal-check", methods=["POST"])
def animal_check():
    """
    Validates whether the image likely contains an animal (multipart field `image`).
    Used by PHP before injury / dispatch logic.
    """
    if not _IMAGE_AI_AVAILABLE:
        return jsonify({
            "error": "Image AI module not available",
            "detail": _IMAGE_AI_IMPORT_ERROR or "Install requirements-ai.txt",
        }), 503

    if not is_api_key_valid(request):
        return jsonify({"error": "Unauthorized"}), 401

    if "image" not in request.files:
        return jsonify({"error": "No image file provided"}), 400

    file = request.files["image"]
    if file.filename == "":
        return jsonify({"error": "Empty filename"}), 400

    ext = file.filename.rsplit(".", 1)[-1].lower()
    if ext not in ALLOWED_EXTS:
        return jsonify({"error": f"Unsupported format. Use: {sorted(ALLOWED_EXTS)}"}), 400

    try:
        img_bytes = file.read()
        if len(img_bytes) > MAX_FILE_SIZE:
            return jsonify({"error": "File too large (max 10MB)"}), 400

        resized, _ = prepare_bgr_for_inference(
            img_bytes, PIPELINE_IMAGE_SIZE, GAUSSIAN_KERNEL
        )
        animal = detect_animals(resized)
        out = {
            "contains_animal": bool(animal["animal_detected"]),
            "confidence": round(float(animal["best_confidence"]), 4),
        }
        return jsonify(out), 200
    except ValueError as ve:
        logger.warning("Animal check decode failed: %s", ve)
        return jsonify({"error": "Invalid image data", "detail": str(ve)}), 400
    except Exception as e:
        logger.exception("Animal check error: %s", e)
        return jsonify({"error": "Image processing failed", "detail": str(e)}), 500


@app.route("/api/v1/injury-check", methods=["POST"])
def injury_check():
    """
    Secure endpoint used by PHP backend for accept/reject automation.
    Expects multipart/form-data with `image`.
    """
    if not _IMAGE_AI_AVAILABLE:
        return jsonify({
            "error": "Image AI module not available",
            "detail": _IMAGE_AI_IMPORT_ERROR or "Install requirements-ai.txt",
        }), 503

    if not is_api_key_valid(request):
        return jsonify({"error": "Unauthorized"}), 401

    if "image" not in request.files:
        return jsonify({"error": "No image file provided"}), 400

    file = request.files["image"]
    if file.filename == "":
        return jsonify({"error": "Empty filename"}), 400

    ext = file.filename.rsplit(".", 1)[-1].lower()
    if ext not in ALLOWED_EXTS:
        return jsonify({"error": f"Unsupported format. Use: {sorted(ALLOWED_EXTS)}"}), 400

    try:
        img_bytes = file.read()
        if len(img_bytes) > MAX_FILE_SIZE:
            return jsonify({"error": "File too large (max 10MB)"}), 400

        _, blurred = prepare_bgr_for_inference(
            img_bytes, PIPELINE_IMAGE_SIZE, GAUSSIAN_KERNEL
        )
        inj = detect_injury_opencv(blurred)
        detected = bool(inj["injury_detected"])
        score = float(inj["injury_score"])
        prediction = {
            "result": "injured" if detected else "not injured",
            "confidence": injury_confidence_for_api(detected, score),
            "injury_score": round(score, 4),
            "injury_detected": detected,
        }
        return jsonify(prediction), 200
    except ValueError as ve:
        logger.warning("Injury check decode failed: %s", ve)
        return jsonify({"error": "Invalid image data", "detail": str(ve)}), 400
    except Exception as e:
        logger.exception("Injury check error: %s", e)
        return jsonify({"error": "Image processing failed", "detail": str(e)}), 500


# ============================================================
# ROUTE: /predict-adoption — adoption probability
# ============================================================
@app.route("/predict-adoption", methods=["POST"])
def predict_adoption():
    """
    JSON body:
    {
        "age_years":        2.5,
        "gender":           "female",   // male/female/unknown
        "health_score":     8,          // 1-10
        "injury_score":     2,          // 1-5
        "breed_popularity": 4,          // 1-5
        "location_demand":  3           // 1-5
    }
    """
    data = request.get_json()
    if not data:
        return jsonify({"error": "JSON body required"}), 400

    try:
        age       = float(data.get("age_years", 2))
        gender    = data.get("gender", "unknown")
        health    = int(data.get("health_score", 5))
        injury    = int(data.get("injury_score", 1))
        breed_pop = int(data.get("breed_popularity", 3))
        loc_dem   = int(data.get("location_demand", 3))

        gender_code = {"male": 1, "female": 2, "unknown": 0}.get(gender, 0)

        X = np.array([[age, gender_code, health, injury, breed_pop, loc_dem]])
        pred_class = adoption_model.predict(X)[0]
        proba      = adoption_model.predict_proba(X)[0]

        # Map class index to label
        categories = ["low", "medium", "high"]
        category   = categories[int(pred_class)]
        probability = round(float(proba.max()) * 100, 2)

        # Detailed probabilities
        class_probs = {categories[i]: round(float(p) * 100, 2) for i, p in enumerate(proba)}

        logger.info(f"Adoption prediction → {category} ({probability}%)")
        return jsonify({
            "adoption_probability": probability,
            "adoption_category":    category,
            "class_probabilities":  class_probs,
            "model":                "DecisionTree v1.0",
            "features_used": {
                "age_years": age, "gender": gender, "health_score": health,
                "injury_score": injury, "breed_popularity": breed_pop,
                "location_demand": loc_dem
            }
        }), 200

    except Exception as e:
        logger.error(f"Adoption prediction error: {e}")
        return jsonify({"error": "Prediction failed", "detail": str(e)}), 500


# ============================================================
# ROUTE: /priority  — priority score calculation
# ============================================================
@app.route("/priority", methods=["POST"])
def calculate_priority():
    data = request.get_json()
    injury_severity = data.get("injury_severity", "none")
    user_urgency    = data.get("user_urgency", "unknown")
    wait_minutes    = int(data.get("wait_minutes", 0))

    severity_score = {"critical":90, "severe":75, "moderate":50, "minor":25, "none":10}.get(injury_severity, 30)
    urgency_boost  = {"critical":10, "high":7, "medium":4, "low":0}.get(user_urgency, 3)
    wait_boost     = min(15, wait_minutes // 10)   # +1 per 10 min, max +15

    score = min(100, severity_score + urgency_boost + wait_boost)
    label = "critical" if score >= 80 else "high" if score >= 55 else "medium" if score >= 30 else "low"

    return jsonify({
        "priority_label": label,
        "priority_score": score,
        "breakdown": {
            "severity_score": severity_score,
            "urgency_boost": urgency_boost,
            "wait_boost": wait_boost
        }
    }), 200


# ============================================================
# ROUTE: /haversine — distance calculation
# ============================================================
@app.route("/haversine", methods=["POST"])
def haversine_route():
    data = request.get_json()
    lat1 = float(data["lat1"]); lon1 = float(data["lon1"])
    lat2 = float(data["lat2"]); lon2 = float(data["lon2"])

    R    = 6371.0
    dLat = math.radians(lat2 - lat1)
    dLon = math.radians(lon2 - lon1)
    a    = math.sin(dLat/2)**2 + math.cos(math.radians(lat1)) * math.cos(math.radians(lat2)) * math.sin(dLon/2)**2
    dist = R * 2 * math.asin(math.sqrt(a))

    return jsonify({"distance_km": round(dist, 4)}), 200


# ============================================================
# ROUTE: /update-location — rescuer live GPS tracking
# ============================================================
@app.route("/update-location", methods=["POST"])
def update_location():
    """
    Receives rescuer GPS coordinates via JSON.
    Body: { "rescuer_id": 5, "latitude": 27.7172, "longitude": 85.3240, "action": "start|update|stop" }
    Returns: { "ok": true, "status": "active|inactive", "latitude": ..., "longitude": ... }
    """
    data = request.get_json()
    if not data:
        return jsonify({"ok": False, "error": "JSON body required"}), 400

    rescuer_id = data.get("rescuer_id")
    action = data.get("action", "update")
    lat = data.get("latitude")
    lon = data.get("longitude")

    if not rescuer_id:
        return jsonify({"ok": False, "error": "rescuer_id is required"}), 400

    if action != "stop":
        # Validate coordinates
        try:
            lat = float(lat)
            lon = float(lon)
        except (TypeError, ValueError):
            return jsonify({"ok": False, "error": "Invalid coordinates"}), 400

        if lat < -90 or lat > 90 or lon < -180 or lon > 180:
            return jsonify({"ok": False, "error": "Coordinates out of range"}), 400

    if action == "stop":
        logger.info(f"Rescuer #{rescuer_id} stopped sharing location")
        return jsonify({
            "ok": True,
            "status": "inactive",
            "rescuer_id": rescuer_id,
        }), 200

    # Log the location update
    logger.info(f"Rescuer #{rescuer_id} location: {lat}, {lon} (action={action})")

    return jsonify({
        "ok": True,
        "status": "active",
        "rescuer_id": rescuer_id,
        "latitude": lat,
        "longitude": lon,
    }), 200


# ============================================================
# ROUTE: /health
# ============================================================
@app.route("/health", methods=["GET"])
def health():
    return jsonify({
        "status": "ok",
        "model_loaded": adoption_model is not None,
        "image_ai_ready": _IMAGE_AI_AVAILABLE,
        "endpoints": [
            "/analyze",
            "/api/v1/animal-check",
            "/api/v1/injury-check",
            "/predict-adoption",
            "/priority",
            "/haversine",
            "/update-location",
            "/health",
        ],
    }), 200


# ============================================================
# MAIN
# ============================================================
if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000, debug=False)